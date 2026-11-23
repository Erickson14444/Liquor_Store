-- ============================================================================
-- DISPARADORES Y REGLAS DE NEGOCIO PARA CUENTAS POR COBRAR (CXC)
-- House & Ice • Sistema Administrativo (ERP Licorero)
-- Proceso 7: Cuentas por Cobrar, Split Universal, Ajustes y Arqueo Ciego
-- ============================================================================

-- ----------------------------------------------------------------------------
-- FASE 3: APLICACIÓN DE PAGOS EN CUENTAS POR COBRAR Y LIQUIDACIÓN DE FACTURAS
-- Al insertar una línea en ventas.recibo_ingreso_detalles, actualiza
-- saldo_pendiente y estado en ventas.cuentas_por_cobrar de forma atómica.
-- Si la deuda llega a cero, muta también la factura asociada a 'PAGADA'.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ventas.fn_trg_actualizar_saldo_cxc_recibo()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_factura_id BIGINT;
BEGIN
    -- 1. Actualizar saldo pendiente y estado en la cuenta por cobrar
    UPDATE ventas.cuentas_por_cobrar
    SET 
        saldo_pendiente = NEW.saldo_restante,
        estado = CASE 
            WHEN NEW.saldo_restante <= 0 THEN 'PAGADA'
            ELSE 'PAGADA_PARCIAL'
        END
    WHERE id = NEW.cuenta_por_cobrar_id
    RETURNING factura_id INTO v_factura_id;

    -- 2. Escenario A & B (Liquidación Exacta o Masiva):
    -- Si la deuda llega a 0.00 y tiene factura asociada, mutar la factura a 'PAGADA'
    IF NEW.saldo_restante <= 0 AND v_factura_id IS NOT NULL THEN
        UPDATE ventas.facturas
        SET estado = 'PAGADA'
        WHERE id = v_factura_id AND estado = 'EMITIDA';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_actualizar_saldo_cxc_recibo ON ventas.recibo_ingreso_detalles;
CREATE TRIGGER trg_actualizar_saldo_cxc_recibo
AFTER INSERT ON ventas.recibo_ingreso_detalles
FOR EACH ROW
EXECUTE FUNCTION ventas.fn_trg_actualizar_saldo_cxc_recibo();


-- ----------------------------------------------------------------------------
-- FASE 4: AJUSTES ADMINISTRATIVOS, CONDONACIÓN E INCOBRABLES
-- Al insertar un registro en ventas.ajustes_cxc:
-- Actualiza de forma atómica saldo_pendiente y estado en ventas.cuentas_por_cobrar.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ventas.fn_trg_actualizar_saldo_cxc_ajuste()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_factura_id BIGINT;
BEGIN
    -- Actualizar saldo pendiente y estado
    UPDATE ventas.cuentas_por_cobrar
    SET
        saldo_pendiente = NEW.saldo_restante,
        estado = CASE
            WHEN NEW.tipo_ajuste = 'INCOBRABLE' THEN 'INCOBRABLE'
            WHEN NEW.tipo_ajuste = 'ANULACION_FACTURA' THEN 'ANULADA'
            WHEN NEW.saldo_restante <= 0 THEN 'PAGADA'
            ELSE 'PAGADA_PARCIAL'
        END
    WHERE id = NEW.cuenta_por_cobrar_id
    RETURNING factura_id INTO v_factura_id;

    -- Si la cuenta se saldó o condonó por completo, marcar factura como PAGADA
    IF NEW.saldo_restante <= 0 AND NEW.tipo_ajuste NOT IN ('ANULACION_FACTURA', 'INCOBRABLE') AND v_factura_id IS NOT NULL THEN
        UPDATE ventas.facturas
        SET estado = 'PAGADA'
        WHERE id = v_factura_id AND estado = 'EMITIDA';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_actualizar_saldo_cxc_ajuste ON ventas.ajustes_cxc;
CREATE TRIGGER trg_actualizar_saldo_cxc_ajuste
AFTER INSERT ON ventas.ajustes_cxc
FOR EACH ROW
EXECUTE FUNCTION ventas.fn_trg_actualizar_saldo_cxc_ajuste();


-- ----------------------------------------------------------------------------
-- FASE 2: ASIENTO ESPEJO BANCARIO PARA COBROS POR TRANSFERENCIA EN CXC
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION tesoreria.fn_trg_asiento_espejo_cxc_transferencia()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_monto         NUMERIC(15,2);
    v_recibo_id     BIGINT;
    v_num_recibo    VARCHAR(30);
    v_cajero_id     UUID;
    v_tasa          NUMERIC(12,4);
BEGIN
    SELECT rcp.recibo_ingreso_id, r.numero_recibo, r.cajero_id, dp.monto_pagado, dp.tasa_cambio
    INTO v_recibo_id, v_num_recibo, v_cajero_id, v_monto, v_tasa
    FROM ventas.recibo_cxc_pagos rcp
    JOIN ventas.recibos_ingreso_cxc r ON r.id = rcp.recibo_ingreso_id
    JOIN tesoreria.detalles_pago dp ON dp.id = rcp.detalle_pago_id
    WHERE rcp.detalle_pago_id = NEW.detalle_pago_id;

    IF v_recibo_id IS NOT NULL THEN
        INSERT INTO tesoreria.movimientos_bancarios (
            cuenta_bancaria_id,
            tipo_movimiento,
            monto,
            referencia,
            concepto,
            fecha_movimiento,
            usuario_id,
            detalle_pago_id
        ) VALUES (
            NEW.cuenta_bancaria_id,
            'TRANSFERENCIA_ENTRADA',
            ROUND(v_monto * COALESCE(v_tasa, 1.0), 2),
            COALESCE(NEW.numero_referencia, 'TRF-CXC-' || v_recibo_id),
            'Cobro CxC Recibo #' || v_num_recibo,
            COALESCE(NEW.fecha_transferencia, CURRENT_DATE),
            v_cajero_id,
            NEW.detalle_pago_id
        );
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_asiento_espejo_cxc_transferencia ON tesoreria.pago_transferencia;
CREATE TRIGGER trg_asiento_espejo_cxc_transferencia
AFTER INSERT ON tesoreria.pago_transferencia
FOR EACH ROW
EXECUTE FUNCTION tesoreria.fn_trg_asiento_espejo_cxc_transferencia();


-- ----------------------------------------------------------------------------
-- FASE 2: ASIENTO ESPEJO BANCARIO PARA COBROS POR CHEQUE EN CXC
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION tesoreria.fn_trg_asiento_espejo_cxc_cheque()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_monto         NUMERIC(15,2);
    v_recibo_id     BIGINT;
    v_num_recibo    VARCHAR(30);
    v_cajero_id     UUID;
    v_tasa          NUMERIC(12,4);
BEGIN
    SELECT rcp.recibo_ingreso_id, r.numero_recibo, r.cajero_id, dp.monto_pagado, dp.tasa_cambio
    INTO v_recibo_id, v_num_recibo, v_cajero_id, v_monto, v_tasa
    FROM ventas.recibo_cxc_pagos rcp
    JOIN ventas.recibos_ingreso_cxc r ON r.id = rcp.recibo_ingreso_id
    JOIN tesoreria.detalles_pago dp ON dp.id = rcp.detalle_pago_id
    WHERE rcp.detalle_pago_id = NEW.detalle_pago_id;

    IF v_recibo_id IS NOT NULL AND NEW.cuenta_bancaria_id IS NOT NULL THEN
        INSERT INTO tesoreria.movimientos_bancarios (
            cuenta_bancaria_id,
            tipo_movimiento,
            monto,
            referencia,
            concepto,
            fecha_movimiento,
            usuario_id,
            detalle_pago_id
        ) VALUES (
            NEW.cuenta_bancaria_id,
            'CHEQUE_ENTRADA',
            ROUND(v_monto * COALESCE(v_tasa, 1.0), 2),
            COALESCE(NEW.numero_cheque, 'CHQ-CXC-' || v_recibo_id),
            'Cobro CxC Cheque Recibo #' || v_num_recibo || ' (' || NEW.banco_emisor || ')',
            COALESCE(NEW.fecha_emision, CURRENT_DATE),
            v_cajero_id,
            NEW.detalle_pago_id
        );
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_asiento_espejo_cxc_cheque ON tesoreria.pago_cheque;
CREATE TRIGGER trg_asiento_espejo_cxc_cheque
AFTER INSERT ON tesoreria.pago_cheque
FOR EACH ROW
EXECUTE FUNCTION tesoreria.fn_trg_asiento_espejo_cxc_cheque();


-- ----------------------------------------------------------------------------
-- FASE 4: IRREVERSIBILIDAD DE ESTADOS TERMINALES EN CXC
-- Garantiza que una CxC en 'INCOBRABLE' o 'ANULADA' no pueda ser reabierta.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ventas.fn_bloquear_reapertura_cxc()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.estado IN ('INCOBRABLE', 'ANULADA') AND NEW.estado <> OLD.estado THEN
        RAISE EXCEPTION 'La cuenta por cobrar % se encuentra en estado terminal (%) y no es reversible.', OLD.id, OLD.estado;
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_irreversibilidad_cxc ON ventas.cuentas_por_cobrar;
CREATE TRIGGER trg_irreversibilidad_cxc
BEFORE UPDATE ON ventas.cuentas_por_cobrar
FOR EACH ROW
EXECUTE FUNCTION ventas.fn_bloquear_reapertura_cxc();
