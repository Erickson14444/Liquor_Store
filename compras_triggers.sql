-- ============================================================================
-- House & Ice • Sistema Administrativo (ERP Licorero)
-- Triggers automáticos para Compras y Recepción de Mercancía
-- ============================================================================

-- 1. Trigger para compras.recepciones_compra: Generar Cuentas por Pagar (CxP)
CREATE OR REPLACE FUNCTION compras.fn_trg_recepcion_compra_cxp()
RETURNS TRIGGER AS $$
DECLARE
    v_dias_credito INTEGER := 30;
    v_fecha_venc DATE;
BEGIN
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
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_recepcion_compra_cxp ON compras.recepciones_compra;
CREATE TRIGGER trg_recepcion_compra_cxp
AFTER INSERT ON compras.recepciones_compra
FOR EACH ROW
EXECUTE FUNCTION compras.fn_trg_recepcion_compra_cxp();


-- 2. Trigger para compras.recepcion_compra_detalles:
--    - Actualiza / inserta en inventario.lotes
--    - Suma stock en inventario.inventario_stock
--    - Registra ENTRADA_COMPRA en Kardex (movimientos_inventario y detalles)
--    - Recalcula costo_promedio en productos_maestro
--    - Actualiza cantidad_recibida_total en orden_compra_detalles y estado en ordenes_compra
CREATE OR REPLACE FUNCTION compras.fn_trg_recepcion_compra_detalles()
RETURNS TRIGGER AS $$
DECLARE
    v_lote_id BIGINT;
    v_almacen_id INTEGER;
    v_stock_id BIGINT;
    v_movimiento_id BIGINT;
    v_usuario_id UUID;
    v_costo_actual NUMERIC(12,2);
    v_stock_actual_total INTEGER;
    v_stock_anterior INTEGER;
    v_nuevo_costo NUMERIC(12,2);
    v_pendientes_total INTEGER;
    v_recibidas_total INTEGER;
    v_num_factura VARCHAR(50);
BEGIN
    -- A. Lote (inventario.lotes)
    SELECT id INTO v_lote_id 
    FROM inventario.lotes 
    WHERE producto_maestro_id = NEW.producto_maestro_id 
      AND numero_lote = NEW.numero_lote;
    
    IF FOUND THEN
        UPDATE inventario.lotes
        SET cantidad_actual = cantidad_actual + NEW.cantidad_recibida_buena,
            fecha_vencimiento = NEW.fecha_vencimiento
        WHERE id = v_lote_id;
    ELSE
        INSERT INTO inventario.lotes (
            producto_maestro_id,
            numero_lote,
            fecha_vencimiento,
            cantidad_actual
        ) VALUES (
            NEW.producto_maestro_id,
            NEW.numero_lote,
            NEW.fecha_vencimiento,
            NEW.cantidad_recibida_buena
        ) RETURNING id INTO v_lote_id;
    END IF;

    -- B. Almacén principal de entrada
    SELECT id INTO v_almacen_id 
    FROM inventario.almacenes 
    WHERE activo = true 
    ORDER BY id ASC 
    LIMIT 1;

    IF v_almacen_id IS NULL THEN
        v_almacen_id := 1;
    END IF;

    -- C. Inventario Stock
    SELECT id INTO v_stock_id
    FROM inventario.inventario_stock
    WHERE almacen_id = v_almacen_id 
      AND producto_maestro_id = NEW.producto_maestro_id 
      AND lote_id = v_lote_id;
    
    IF FOUND THEN
        UPDATE inventario.inventario_stock
        SET stock_fisico_unidades = stock_fisico_unidades + NEW.cantidad_recibida_buena
        WHERE id = v_stock_id;
    ELSE
        INSERT INTO inventario.inventario_stock (
            almacen_id,
            producto_maestro_id,
            lote_id,
            stock_fisico_unidades,
            stock_comprometido
        ) VALUES (
            v_almacen_id,
            NEW.producto_maestro_id,
            v_lote_id,
            NEW.cantidad_recibida_buena,
            0
        );
    END IF;

    -- D. Kardex: Cabecera y Detalle de Movimiento
    SELECT usuario_recepcion_id, numero_factura_proveedor 
    INTO v_usuario_id, v_num_factura
    FROM compras.recepciones_compra 
    WHERE id = NEW.recepcion_id;

    SELECT id INTO v_movimiento_id
    FROM inventario.movimientos_inventario
    WHERE tipo_movimiento = 'ENTRADA_COMPRA' 
      AND documento_referencia_id = NEW.recepcion_id
    LIMIT 1;

    IF v_movimiento_id IS NULL THEN
        INSERT INTO inventario.movimientos_inventario (
            tipo_movimiento,
            justificacion,
            supervisor_id,
            documento_referencia_id,
            fecha_movimiento
        ) VALUES (
            'ENTRADA_COMPRA',
            'Entrada de mercancía por Recepción Factura ' || COALESCE(v_num_factura, NEW.recepcion_id::text),
            v_usuario_id,
            NEW.recepcion_id,
            CURRENT_TIMESTAMP
        ) RETURNING id INTO v_movimiento_id;
    END IF;

    INSERT INTO inventario.movimiento_inventario_detalles (
        movimiento_id,
        producto_maestro_id,
        lote_id,
        cantidad_unidades,
        costo_unitario_referencia
    ) VALUES (
        v_movimiento_id,
        NEW.producto_maestro_id,
        v_lote_id,
        NEW.cantidad_recibida_buena,
        NEW.costo_unitario_facturado
    );

    -- E. Recálculo de Costo Promedio Ponderado
    SELECT COALESCE(costo_promedio, 0) INTO v_costo_actual
    FROM inventario.productos_maestro
    WHERE id = NEW.producto_maestro_id;

    SELECT COALESCE(SUM(stock_fisico_unidades), 0) INTO v_stock_actual_total
    FROM inventario.inventario_stock
    WHERE producto_maestro_id = NEW.producto_maestro_id;

    v_stock_anterior := v_stock_actual_total - NEW.cantidad_recibida_buena;

    IF v_stock_anterior <= 0 THEN
        v_nuevo_costo := NEW.costo_unitario_facturado;
    ELSE
        v_nuevo_costo := ROUND(((v_stock_anterior * v_costo_actual) + (NEW.cantidad_recibida_buena * NEW.costo_unitario_facturado)) / v_stock_actual_total, 2);
    END IF;

    UPDATE inventario.productos_maestro
    SET costo_promedio = v_nuevo_costo
    WHERE id = NEW.producto_maestro_id;

    -- F. Actualización de Orden de Compra y Backorders
    IF NEW.orden_compra_id IS NOT NULL THEN
        UPDATE compras.orden_compra_detalles
        SET cantidad_recibida_total = cantidad_recibida_total + NEW.cantidad_recibida_buena
        WHERE orden_compra_id = NEW.orden_compra_id 
          AND producto_maestro_id = NEW.producto_maestro_id;

        SELECT 
            COALESCE(SUM(cantidad_pendiente), 0),
            COALESCE(SUM(cantidad_recibida_total), 0)
        INTO v_pendientes_total, v_recibidas_total
        FROM compras.orden_compra_detalles
        WHERE orden_compra_id = NEW.orden_compra_id;

        IF v_pendientes_total <= 0 THEN
            UPDATE compras.ordenes_compra
            SET estado = 'COMPLETADA'
            WHERE id = NEW.orden_compra_id;
        ELSIF v_recibidas_total > 0 THEN
            UPDATE compras.ordenes_compra
            SET estado = 'RECIBIDA_PARCIAL'
            WHERE id = NEW.orden_compra_id;
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_recepcion_compra_detalles ON compras.recepcion_compra_detalles;
CREATE TRIGGER trg_recepcion_compra_detalles
AFTER INSERT ON compras.recepcion_compra_detalles
FOR EACH ROW
EXECUTE FUNCTION compras.fn_trg_recepcion_compra_detalles();
