-- ============================================================================
-- HOUSE & ICE • PROCESO 2: CUENTAS POR PAGAR (CXP)
-- TRIGGERS Y FUNCIONES DE INTEGRIDAD TRANSACCIONAL (POSTGRESQL)
-- ============================================================================

-- Columnas de soporte para Gastos Generales y Servicios Directos (Fase 1: Origen Manual)
ALTER TABLE compras.cuentas_por_pagar ADD COLUMN IF NOT EXISTS numero_factura_servicio character varying(50);
ALTER TABLE compras.cuentas_por_pagar ADD COLUMN IF NOT EXISTS ncf_servicio character varying(13);
ALTER TABLE compras.cuentas_por_pagar ADD COLUMN IF NOT EXISTS concepto_servicio text;

-- ----------------------------------------------------------------------------
-- FASE 1: EL NACIMIENTO DE LA DEUDA
-- Origen Automático por Mercancía: Al confirmar recepción en compras.recepciones_compra,
-- inserta en compras.cuentas_por_pagar con fecha_vencimiento evaluando condicion_pago.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION compras.fn_trg_recepcion_compra_cxp()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_dias_credito INTEGER := 30;
    v_fecha_venc   DATE;
BEGIN
    -- Si es al CONTADO vence el mismo día; si es a CREDITO suma días del proveedor
    IF NEW.condicion_pago = 'CONTADO' THEN
        v_fecha_venc := CURRENT_DATE;
    ELSE
        SELECT COALESCE(dias_credito_habitual, 30) INTO v_dias_credito
        FROM identidad.proveedores
        WHERE persona_id = NEW.proveedor_id;
        
        IF v_dias_credito IS NULL OR v_dias_credito < 0 THEN
            v_dias_credito := 30;
        END IF;
        
        v_fecha_venc := CURRENT_DATE + v_dias_credito;
    END IF;

    -- Inserción inicial con estado 'PENDIENTE' y saldo_pendiente = monto_total_factura
    INSERT INTO compras.cuentas_por_pagar (
        proveedor_id,
        recepcion_compra_id,
        monto_original,
        saldo_pendiente,
        fecha_emision,
        fecha_vencimiento,
        estado,
        moneda_id,
        tasa_cambio
    ) VALUES (
        NEW.proveedor_id,
        NEW.id,
        NEW.monto_total_factura,
        NEW.monto_total_factura,
        CURRENT_DATE,
        v_fecha_venc,
        'PENDIENTE',
        NEW.moneda_id,
        NEW.tasa_cambio
    );

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_recepcion_compra_cxp ON compras.recepciones_compra;
CREATE TRIGGER trg_recepcion_compra_cxp
AFTER INSERT ON compras.recepciones_compra
FOR EACH ROW
EXECUTE FUNCTION compras.fn_trg_recepcion_compra_cxp();


-- ----------------------------------------------------------------------------
-- FASE 3: EJECUCIÓN DEL DESEMBOLSO Y BLINDAJE DE ARQUEO CIEGO
-- Los desembolsos de CxP se liquidan externamente (banco/bóveda),
-- por lo que sesion_caja_id DEBE ser NULL en tesoreria.detalles_pago.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION compras.fn_bloquear_sesion_caja_cxp()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_sesion_id BIGINT;
BEGIN
    SELECT sesion_caja_id
    INTO v_sesion_id
    FROM tesoreria.detalles_pago
    WHERE id = NEW.detalle_pago_id;

    IF v_sesion_id IS NOT NULL THEN
        RAISE EXCEPTION 'Los pagos de Cuentas por Pagar (CxP) se procesan externamente y no pueden asociarse a una sesión de caja POS (detalle_pago_id: %, sesion_caja_id: %). El Arqueo Ciego solo audita facturación, cxc y devoluciones.',
            NEW.detalle_pago_id, v_sesion_id;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_bloquear_sesion_caja_cxp ON compras.egreso_cxp_pagos;
CREATE TRIGGER trg_bloquear_sesion_caja_cxp
BEFORE INSERT OR UPDATE ON compras.egreso_cxp_pagos
FOR EACH ROW
EXECUTE FUNCTION compras.fn_bloquear_sesion_caja_cxp();


-- Protección bidireccional en tesoreria.detalles_pago
CREATE OR REPLACE FUNCTION tesoreria.fn_bloquear_caja_en_pago_cxp()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.sesion_caja_id IS NOT NULL AND EXISTS (
        SELECT 1
        FROM compras.egreso_cxp_pagos ecp
        WHERE ecp.detalle_pago_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'No se puede asignar una sesión de caja POS al detalle_pago_id % porque pertenece a un egreso de Cuentas por Pagar (CxP).',
            NEW.id;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_bloquear_caja_en_pago_cxp ON tesoreria.detalles_pago;
CREATE TRIGGER trg_bloquear_caja_en_pago_cxp
BEFORE UPDATE OF sesion_caja_id ON tesoreria.detalles_pago
FOR EACH ROW
EXECUTE FUNCTION tesoreria.fn_bloquear_caja_en_pago_cxp();


-- ----------------------------------------------------------------------------
-- FASE 3: APLICACIÓN DE PAGOS Y RETENCIONES EN CXP
-- Actualiza de forma atómica saldo_pendiente y estado en compras.cuentas_por_pagar
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION compras.fn_trg_actualizar_saldo_cxp_egreso()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    UPDATE compras.cuentas_por_pagar
    SET 
        saldo_pendiente = NEW.saldo_restante,
        estado = CASE 
            WHEN NEW.saldo_restante <= 0 THEN 'PAGADA'
            ELSE 'PAGADA_PARCIAL'
        END
    WHERE id = NEW.cuenta_por_pagar_id;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_actualizar_saldo_cxp_egreso ON compras.comprobante_egreso_detalles;
CREATE TRIGGER trg_actualizar_saldo_cxp_egreso
AFTER INSERT ON compras.comprobante_egreso_detalles
FOR EACH ROW
EXECUTE FUNCTION compras.fn_trg_actualizar_saldo_cxp_egreso();


-- ----------------------------------------------------------------------------
-- FASE 3: ASIENTO ESPEJO AUTOMÁTICO EN MOVIMIENTOS BANCARIOS (SPLIT TRANSFERENCIA / CHEQUE)
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION tesoreria.fn_trg_asiento_espejo_cxp_transferencia()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_monto        NUMERIC(15,2);
    v_egreso_id    BIGINT;
    v_num_comp     VARCHAR(30);
    v_usuario_id   UUID;
BEGIN
    -- Verificar si este detalle de pago pertenece a un Egreso de CxP
    SELECT ce.id, ce.numero_comprobante, ce.usuario_registro_id
    INTO v_egreso_id, v_num_comp, v_usuario_id
    FROM compras.egreso_cxp_pagos ecp
    JOIN compras.comprobantes_egreso_cxp ce ON ce.id = ecp.comprobante_egreso_id
    WHERE ecp.detalle_pago_id = NEW.detalle_pago_id;

    IF FOUND THEN
        SELECT monto_pagado INTO v_monto
        FROM tesoreria.detalles_pago
        WHERE id = NEW.detalle_pago_id;

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
            'TRANSFERENCIA_SALIDA',
            v_monto,
            COALESCE(NEW.numero_referencia, 'REF-EGRESO-' || v_egreso_id),
            'Desembolso CxP Comprobante #' || v_num_comp || ' (Ref: ' || NEW.numero_referencia || ')',
            NEW.fecha_transferencia,
            v_usuario_id,
            NEW.detalle_pago_id
        );
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_asiento_espejo_cxp_transferencia ON tesoreria.pago_transferencia;
CREATE TRIGGER trg_asiento_espejo_cxp_transferencia
AFTER INSERT ON tesoreria.pago_transferencia
FOR EACH ROW
EXECUTE FUNCTION tesoreria.fn_trg_asiento_espejo_cxp_transferencia();


CREATE OR REPLACE FUNCTION tesoreria.fn_trg_asiento_espejo_cxp_cheque()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_monto        NUMERIC(15,2);
    v_egreso_id    BIGINT;
    v_num_comp     VARCHAR(30);
    v_usuario_id   UUID;
BEGIN
    SELECT ce.id, ce.numero_comprobante, ce.usuario_registro_id
    INTO v_egreso_id, v_num_comp, v_usuario_id
    FROM compras.egreso_cxp_pagos ecp
    JOIN compras.comprobantes_egreso_cxp ce ON ce.id = ecp.comprobante_egreso_id
    WHERE ecp.detalle_pago_id = NEW.detalle_pago_id;

    IF FOUND AND NEW.cuenta_bancaria_id IS NOT NULL THEN
        SELECT monto_pagado INTO v_monto
        FROM tesoreria.detalles_pago
        WHERE id = NEW.detalle_pago_id;

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
            'CHEQUE_SALIDA',
            v_monto,
            COALESCE(NEW.numero_cheque, 'CHQ-EGRESO-' || v_egreso_id),
            'Desembolso CxP Cheque #' || NEW.numero_cheque || ' (Comprobante #' || v_num_comp || ')',
            CURRENT_DATE,
            v_usuario_id,
            NEW.detalle_pago_id
        );
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_asiento_espejo_cxp_cheque ON tesoreria.pago_cheque;
CREATE TRIGGER trg_asiento_espejo_cxp_cheque
AFTER INSERT ON tesoreria.pago_cheque
FOR EACH ROW
EXECUTE FUNCTION tesoreria.fn_trg_asiento_espejo_cxp_cheque();


-- ----------------------------------------------------------------------------
-- FASE 4: AJUSTES, DESCUENTOS Y EXONERACIONES (compras.ajustes_cxp)
-- Actualiza saldo y estado de CxP; si tipo_ajuste es ANULACION, coloca estado ANULADA
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION compras.fn_trg_actualizar_saldo_cxp_ajuste()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    UPDATE compras.cuentas_por_pagar
    SET 
        saldo_pendiente = NEW.saldo_restante,
        estado = CASE 
            WHEN NEW.tipo_ajuste = 'ANULACION' THEN 'ANULADA'
            WHEN NEW.saldo_restante <= 0 THEN 'PAGADA'
            ELSE 'PAGADA_PARCIAL'
        END
    WHERE id = NEW.cuenta_por_pagar_id;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_actualizar_saldo_cxp_ajuste ON compras.ajustes_cxp;
CREATE TRIGGER trg_actualizar_saldo_cxp_ajuste
AFTER INSERT ON compras.ajustes_cxp
FOR EACH ROW
EXECUTE FUNCTION compras.fn_trg_actualizar_saldo_cxp_ajuste();


-- ----------------------------------------------------------------------------
-- FASE 4: IRREVERSIBILIDAD TERMINAL DE CUENTA POR PAGAR ANULADA
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION compras.fn_bloquear_reapertura_cxp()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.estado = 'ANULADA' AND NEW.estado <> OLD.estado THEN
        RAISE EXCEPTION 'La cuenta por pagar #% se encuentra ANULADA y su estado es permanente e irreversible.', OLD.id;
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_irreversibilidad_cxp ON compras.cuentas_por_pagar;
CREATE TRIGGER trg_irreversibilidad_cxp
BEFORE UPDATE ON compras.cuentas_por_pagar
FOR EACH ROW
EXECUTE FUNCTION compras.fn_bloquear_reapertura_cxp();
