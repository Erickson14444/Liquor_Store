<?php
/**
 * ============================================================================
 * Módulo 4: Ventas • Reporte: Historial de Ventas (Controlador Backend)
 * Ubicación: Ventas y Facturacion/HistorialVentas/procesar_historial_ventas.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

$usuarioSesionId = requerirSesionActiva(true);

if (function_exists('usuarioPuedeAccederFacturacion')) {
    requerirAccesoModulo(
        usuarioPuedeAccederFacturacion(),
        true,
        'Acceso restringido: no posees permisos para consultar el Historial de Ventas.'
    );
}

$esSupervisorOAdmin = (function_exists('usuarioEsAdministrador') && usuarioEsAdministrador())
    || !empty($_SESSION['usuario']['es_supervisor'])
    || (function_exists('usuarioTienePermiso') && usuarioTienePermiso('VER_REPORTES_FINANCIEROS'));

$accion = trim((string) ($_GET['accion'] ?? $_POST['accion'] ?? 'listar'));

function responderHist(bool $ok, string $mensaje, array $extra = [], int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'ok' => $ok,
        'status' => $ok ? 'success' : 'error',
        'mensaje' => $mensaje,
        'message' => $mensaje,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$pdo) {
    responderHist(false, 'No hay conexión activa con PostgreSQL.', [], 500);
}

/**
 * Construye las cláusulas WHERE y parámetros posicionales (?) compartidos
 * entre el listado, los KPIs y la exportación CSV.
 */
function construirFiltrosHistorial(array $in): array
{
    $where = ['1=1'];
    $params = [];

    $fechaDesde = trim((string) ($in['fecha_desde'] ?? ''));
    $fechaHasta = trim((string) ($in['fecha_hasta'] ?? ''));
    $q = trim((string) ($in['q'] ?? ''));
    $tipoNcf = strtoupper(trim((string) ($in['tipo_ncf'] ?? '')));
    $condicion = strtoupper(trim((string) ($in['condicion_pago'] ?? '')));
    $origen = strtoupper(trim((string) ($in['origen_venta'] ?? '')));
    $estado = strtoupper(trim((string) ($in['estado'] ?? '')));
    $vendedorId = trim((string) ($in['vendedor_id'] ?? ''));
    $metodoId = intval($in['metodo_pago_id'] ?? 0);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) {
        $where[] = 'f.fecha_emision::date >= ?';
        $params[] = $fechaDesde;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
        $where[] = 'f.fecha_emision::date <= ?';
        $params[] = $fechaHasta;
    }
    if (in_array($tipoNcf, ['B01', 'B02', 'CONSUMO_INTERNO'], true)) {
        $where[] = 'f.tipo_ncf = ?';
        $params[] = $tipoNcf;
    }
    if (in_array($condicion, ['CONTADO', 'CREDITO'], true)) {
        $where[] = 'f.condicion_pago = ?';
        $params[] = $condicion;
    }
    if (in_array($origen, ['MOSTRADOR', 'DELIVERY'], true)) {
        $where[] = 'f.origen_venta = ?';
        $params[] = $origen;
    }
    if (in_array($estado, ['PAGADA', 'EMITIDA', 'EN_TRANSITO', 'ENTREGADA', 'ANULADA'], true)) {
        $where[] = 'f.estado = ?';
        $params[] = $estado;
    }
    if ($vendedorId !== '' && preg_match('/^[0-9a-f-]{36}$/i', $vendedorId)) {
        $where[] = 'f.vendedor_id = ?';
        $params[] = $vendedorId;
    }
    if ($metodoId > 0) {
        $where[] = "EXISTS (
            SELECT 1
            FROM ventas.factura_pagos fp2
            JOIN tesoreria.detalles_pago dp2 ON dp2.id = fp2.detalle_pago_id
            WHERE fp2.factura_id = f.id
              AND dp2.metodo_pago_id = ?
        )";
        $params[] = $metodoId;
    }
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(
            f.numero_factura ILIKE ?
            OR COALESCE(f.ncf, '') ILIKE ?
            OR COALESCE(f.razon_social_cliente, '') ILIKE ?
            OR COALESCE(f.rnc_cedula_cliente, '') ILIKE ?
            OR COALESCE(pc.nombre_razon_social, '') ILIKE ?
            OR COALESCE(pc.documento_identidad, '') ILIKE ?
            OR COALESCE(pv.nombre_razon_social, '') ILIKE ?
            OR COALESCE(uv.username, '') ILIKE ?
            OR EXISTS (
                SELECT 1
                FROM ventas.factura_detalle fd2
                JOIN inventario.productos_maestro pm2 ON pm2.id = fd2.producto_maestro_id
                WHERE fd2.factura_id = f.id
                  AND (pm2.sku ILIKE ? OR COALESCE(pm2.nombre, '') ILIKE ?)
            )
        )";
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    return [implode(' AND ', $where), $params];
}

$joinsBase = "
    FROM ventas.facturas f
    LEFT JOIN identidad.personas pc ON pc.persona_id = f.cliente_id
    LEFT JOIN seguridad.usuarios uv ON uv.usuario_id = f.vendedor_id
    LEFT JOIN identidad.personas pv ON pv.persona_id = uv.persona_id
    LEFT JOIN tesoreria.monedas m   ON m.id = f.moneda_id
    LEFT JOIN ventas.sesiones_caja sc ON sc.id = f.sesion_caja_id
    LEFT JOIN ventas.cajas_pos cp     ON cp.id = sc.caja_id
";

try {
    // =========================================================================
    // 1. LISTAR FACTURAS FILTRADAS + KPIs EXACTOS DEL PERIODO
    // =========================================================================
    if ($accion === 'listar') {
        [$whereSql, $params] = construirFiltrosHistorial($_GET);
        $limite = max(25, min(500, intval($_GET['limite'] ?? 200)));

        // A. KPIs agregados sobre el 100% de las filas filtradas (convertidos a DOP con tasa_cambio)
        $sqlKpi = "
            SELECT
                COUNT(*) AS total_registros,
                COUNT(*) FILTER (WHERE f.estado <> 'ANULADA') AS facturas_validas,
                COUNT(*) FILTER (WHERE f.estado = 'ANULADA')  AS facturas_anuladas,
                COALESCE(SUM(f.monto_total * f.tasa_cambio) FILTER (WHERE f.estado <> 'ANULADA'), 0)     AS ventas_brutas_dop,
                COALESCE(SUM(f.monto_subtotal * f.tasa_cambio) FILTER (WHERE f.estado <> 'ANULADA'), 0)  AS subtotal_dop,
                COALESCE(SUM(f.monto_descuento * f.tasa_cambio) FILTER (WHERE f.estado <> 'ANULADA'), 0) AS descuentos_dop,
                COALESCE(SUM(f.monto_impuestos * f.tasa_cambio) FILTER (WHERE f.estado <> 'ANULADA'), 0) AS impuestos_dop,
                COALESCE(SUM(f.monto_total * f.tasa_cambio) FILTER (WHERE f.estado <> 'ANULADA' AND f.condicion_pago = 'CONTADO'), 0) AS contado_dop,
                COALESCE(SUM(f.monto_total * f.tasa_cambio) FILTER (WHERE f.estado <> 'ANULADA' AND f.condicion_pago = 'CREDITO'), 0) AS credito_dop,
                COALESCE(SUM(f.monto_total * f.tasa_cambio) FILTER (WHERE f.estado = 'ANULADA'), 0) AS monto_anulado_dop,
                COALESCE(SUM((
                    SELECT COALESCE(SUM(d.monto_total * d.tasa_cambio), 0)
                    FROM ventas.devoluciones d
                    WHERE d.factura_id = f.id
                )) FILTER (WHERE f.estado <> 'ANULADA'), 0) AS devoluciones_b04_dop,
                COALESCE(SUM((
                    SELECT COALESCE(SUM(fd.cantidad_unidades * fd.costo_unitario_hist), 0)
                    FROM ventas.factura_detalle fd
                    WHERE fd.factura_id = f.id
                )) FILTER (WHERE f.estado <> 'ANULADA'), 0) AS costo_mercancia_dop
            {$joinsBase}
            WHERE {$whereSql}
        ";
        $stKpi = $pdo->prepare($sqlKpi);
        $stKpi->execute($params);
        $k = $stKpi->fetch(PDO::FETCH_ASSOC) ?: [];

        $facturasValidas = intval($k['facturas_validas'] ?? 0);
        $ventasBrutasDop = round(floatval($k['ventas_brutas_dop'] ?? 0), 2);
        $devolucionesDop = round(floatval($k['devoluciones_b04_dop'] ?? 0), 2);
        $ventasNetasDop = round(max(0.0, $ventasBrutasDop - $devolucionesDop), 2);
        $ticketPromedio = ($facturasValidas > 0) ? round($ventasBrutasDop / $facturasValidas, 2) : 0.00;
        $baseNetaSinImp = round(max(0.0, floatval($k['subtotal_dop'] ?? 0) - floatval($k['descuentos_dop'] ?? 0)), 2);
        $costoMercDop = round(floatval($k['costo_mercancia_dop'] ?? 0), 2);
        $utilidadEstDop = round($baseNetaSinImp - $costoMercDop, 2);

        $kpis = [
            'total_registros' => intval($k['total_registros'] ?? 0),
            'facturas_validas' => $facturasValidas,
            'facturas_anuladas' => intval($k['facturas_anuladas'] ?? 0),
            'ventas_brutas_dop' => $ventasBrutasDop,
            'ventas_netas_dop' => $ventasNetasDop,
            'descuentos_dop' => round(floatval($k['descuentos_dop'] ?? 0), 2),
            'impuestos_dop' => round(floatval($k['impuestos_dop'] ?? 0), 2),
            'contado_dop' => round(floatval($k['contado_dop'] ?? 0), 2),
            'credito_dop' => round(floatval($k['credito_dop'] ?? 0), 2),
            'devoluciones_b04_dop' => $devolucionesDop,
            'monto_anulado_dop' => round(floatval($k['monto_anulado_dop'] ?? 0), 2),
            'ticket_promedio_dop' => $ticketPromedio,
            'mostrar_margen' => $esSupervisorOAdmin,
            'costo_mercancia_dop' => $esSupervisorOAdmin ? $costoMercDop : null,
            'utilidad_est_dop' => $esSupervisorOAdmin ? $utilidadEstDop : null,
        ];

        // B. Listado detallado
        $sqlList = "
            SELECT f.id,
                   f.numero_factura,
                   f.tipo_ncf,
                   COALESCE(f.ncf, 'S/C') AS ncf,
                   f.condicion_pago,
                   f.origen_venta,
                   f.estado,
                   f.monto_subtotal,
                   f.monto_descuento,
                   f.monto_impuestos,
                   f.monto_total,
                   f.tasa_cambio,
                   COALESCE(m.codigo_iso, 'DOP') AS codigo_iso,
                   COALESCE(m.simbolo, 'RD$')    AS simbolo,
                   TO_CHAR(f.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_fmt,
                   COALESCE(NULLIF(TRIM(f.razon_social_cliente), ''), pc.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
                   COALESCE(NULLIF(TRIM(f.rnc_cedula_cliente), ''), pc.documento_identidad, '') AS cliente_doc,
                   COALESCE(NULLIF(TRIM(pv.nombre_razon_social), ''), uv.username, '—') AS vendedor_nombre,
                   COALESCE(cp.nombre_equipo, 'Caja') AS caja_nombre,
                   (
                       SELECT STRING_AGG(DISTINCT mp.codigo, ' + ' ORDER BY mp.codigo)
                       FROM ventas.factura_pagos fp
                       JOIN tesoreria.detalles_pago dp ON dp.id = fp.detalle_pago_id
                       JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id
                       WHERE fp.factura_id = f.id
                   ) AS metodos_pago_txt,
                   COALESCE((
                       SELECT SUM(d.monto_total)
                       FROM ventas.devoluciones d
                       WHERE d.factura_id = f.id
                   ), 0) AS monto_devuelto,
                   COALESCE((
                       SELECT COUNT(*)
                       FROM ventas.devoluciones d
                       WHERE d.factura_id = f.id
                   ), 0) AS cant_devoluciones,
                   COALESCE((
                       SELECT SUM(fd.cantidad_unidades)
                       FROM ventas.factura_detalle fd
                       WHERE fd.factura_id = f.id
                   ), 0) AS total_unidades
            {$joinsBase}
            WHERE {$whereSql}
            ORDER BY f.fecha_emision DESC, f.id DESC
            LIMIT {$limite}
        ";
        $stList = $pdo->prepare($sqlList);
        $stList->execute($params);
        $facturas = $stList->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($facturas as &$f) {
            $f['monto_subtotal'] = round(floatval($f['monto_subtotal']), 2);
            $f['monto_descuento'] = round(floatval($f['monto_descuento']), 2);
            $f['monto_impuestos'] = round(floatval($f['monto_impuestos']), 2);
            $f['monto_total'] = round(floatval($f['monto_total']), 2);
            $f['monto_devuelto'] = round(floatval($f['monto_devuelto']), 2);
            $f['cant_devoluciones'] = intval($f['cant_devoluciones']);
            $f['total_unidades'] = intval($f['total_unidades']);
            $f['puede_anular'] = !in_array(strtoupper((string) $f['estado']), ['ANULADA', 'EN_TRANSITO'], true)
                && $f['cant_devoluciones'] === 0;
        }
        unset($f);

        responderHist(true, 'OK', [
            'kpis' => $kpis,
            'facturas' => $facturas,
            'limite' => $limite,
        ]);
    }

    // =========================================================================
    // 2. DETALLE COMPLETO PARA CONSULTA Y REIMPRESIÓN DE COMPROBANTE
    // =========================================================================
    if ($accion === 'detalle_factura') {
        $facturaId = intval($_GET['factura_id'] ?? 0);
        if ($facturaId <= 0) {
            responderHist(false, 'Identificador de factura inválido.');
        }

        $stCab = $pdo->prepare("
            SELECT f.id, f.numero_factura, f.tipo_ncf, COALESCE(f.ncf, 'SIN NCF') AS ncf,
                   f.condicion_pago, f.origen_venta, f.estado, f.sesion_caja_id,
                   f.monto_subtotal, f.monto_descuento, f.monto_impuestos, f.monto_total,
                   f.tasa_cambio,
                   COALESCE(m.codigo_iso, 'DOP') AS codigo_iso,
                   COALESCE(m.simbolo, 'RD$')    AS simbolo,
                   TO_CHAR(f.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_fmt,
                   COALESCE(NULLIF(TRIM(f.razon_social_cliente), ''), pc.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
                   COALESCE(NULLIF(TRIM(f.rnc_cedula_cliente), ''), pc.documento_identidad, '') AS cliente_doc,
                   COALESCE(pc.telefono_principal, '') AS cliente_telefono,
                   COALESCE(NULLIF(TRIM(pv.nombre_razon_social), ''), uv.username, '—') AS vendedor_nombre,
                   COALESCE(NULLIF(TRIM(pa.nombre_razon_social), ''), ua.username, '')  AS supervisor_anulacion_nombre,
                   COALESCE(cp.nombre_equipo, 'Caja') AS caja_nombre,
                   cup.codigo_cupon AS cupon_redimido_codigo,
                   cup.monto_credito AS cupon_redimido_monto,
                   cot.numero_cotizacion AS cotizacion_numero
            FROM ventas.facturas f
            LEFT JOIN tesoreria.monedas m        ON m.id = f.moneda_id
            LEFT JOIN identidad.personas pc      ON pc.persona_id = f.cliente_id
            LEFT JOIN seguridad.usuarios uv      ON uv.usuario_id = f.vendedor_id
            LEFT JOIN identidad.personas pv      ON pv.persona_id = uv.persona_id
            LEFT JOIN seguridad.usuarios ua      ON ua.usuario_id = f.supervisor_anulacion_id
            LEFT JOIN identidad.personas pa      ON pa.persona_id = ua.persona_id
            LEFT JOIN ventas.sesiones_caja sc    ON sc.id = f.sesion_caja_id
            LEFT JOIN ventas.cajas_pos cp        ON cp.id = sc.caja_id
            LEFT JOIN promociones.cupones_descuento cup ON cup.id = f.cupon_aplicado_id
            LEFT JOIN ventas.cotizaciones cot    ON cot.id = f.cotizacion_origen
            WHERE f.id = ?
            LIMIT 1
        ");
        $stCab->execute([$facturaId]);
        $cab = $stCab->fetch(PDO::FETCH_ASSOC);

        if (!$cab) {
            responderHist(false, 'Factura no encontrada.', [], 404);
        }

        // Líneas de factura
        $stDet = $pdo->prepare("
            SELECT fd.producto_maestro_id, fd.cantidad_presentacion, fd.factor_conversion,
                   fd.cantidad_unidades, fd.precio_unitario_his, fd.costo_unitario_hist,
                   fd.monto_subtotal, fd.monto_descuento, fd.monto_impuestos,
                   (fd.monto_subtotal - fd.monto_descuento + fd.monto_impuestos) AS monto_total,
                   pm.sku,
                   COALESCE(NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'), pcb.descripcion_presentacion, pm.sku) AS producto_nombre,
                   COALESCE(pcb.descripcion_presentacion, 'Unidad Base (x1)') AS presentacion,
                   pr.nombre_promocion
            FROM ventas.factura_detalle fd
            JOIN inventario.productos_maestro pm ON pm.id = fd.producto_maestro_id
            LEFT JOIN inventario.producto_codigos_barra pcb ON pcb.id = fd.codigo_barra_id
            LEFT JOIN promociones.promociones_campanas pr ON pr.id = fd.promocion_aplicada
            WHERE fd.factura_id = ?
            ORDER BY pm.sku ASC
        ");
        $stDet->execute([$facturaId]);
        $detalles = $stDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Resumen de impuestos DGII congelados en ventas.factura_detalle_impuestos
        $stImp = $pdo->prepare("
            SELECT i.siglas, i.nombre, ROUND(SUM(fdi.monto_impuesto), 2) AS monto
            FROM ventas.factura_detalle_impuestos fdi
            JOIN tesoreria.impuestos i ON i.id = fdi.impuesto_id
            WHERE fdi.factura_id = ?
            GROUP BY i.id, i.siglas, i.nombre, i.orden_calculo
            ORDER BY i.orden_calculo ASC, i.id ASC
        ");
        $stImp->execute([$facturaId]);
        $resumenImpuestos = $stImp->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Pagos Split Universal
        $stPag = $pdo->prepare("
            SELECT dp.id AS detalle_pago_id, mp.codigo AS metodo_codigo, mp.descripcion AS metodo_desc,
                   dp.monto_pagado, dp.monto_devuelta,
                   pt.tipo_tarjeta, pt.marca_tarjeta, pt.ultimos_4_digitos, pt.numero_autorizacion,
                   ptr.banco_origen, ptr.numero_referencia AS ref_transferencia,
                   pch.numero_cheque, pch.banco_emisor,
                   psf.origen_saldo, psf.documento_origen_id
            FROM ventas.factura_pagos fp
            JOIN tesoreria.detalles_pago dp ON dp.id = fp.detalle_pago_id
            JOIN ventas.metodos_pago mp     ON mp.id = dp.metodo_pago_id
            LEFT JOIN tesoreria.pago_tarjeta pt        ON pt.detalle_pago_id = dp.id
            LEFT JOIN tesoreria.pago_transferencia ptr ON ptr.detalle_pago_id = dp.id
            LEFT JOIN tesoreria.pago_cheque pch        ON pch.detalle_pago_id = dp.id
            LEFT JOIN tesoreria.pago_saldo_favor psf   ON psf.detalle_pago_id = dp.id
            WHERE fp.factura_id = ?
            ORDER BY dp.id ASC
        ");
        $stPag->execute([$facturaId]);
        $pagos = $stPag->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Datos de delivery si aplica
        $stDel = $pdo->prepare("
            SELECT pd.direccion_destino, pd.estado_delivery,
                   TO_CHAR(pd.hora_despacho, 'DD/MM/YYYY HH12:MI AM') AS hora_despacho_fmt,
                   TO_CHAR(pd.hora_entrega,  'DD/MM/YYYY HH12:MI AM') AS hora_entrega_fmt,
                   z.nombre AS zona_nombre, z.tarifa_envio,
                   pr.nombre_razon_social AS repartidor_nombre, e.codigo_empleado
            FROM ventas.pedidos_delivery pd
            JOIN ventas.zonas_delivery z   ON z.id = pd.zona_id
            JOIN identidad.repartidores r  ON r.empleado_id = pd.repartidor_id
            JOIN identidad.empleados e     ON e.empleado_id = r.empleado_id
            JOIN identidad.personas pr     ON pr.persona_id = e.persona_id
            WHERE pd.factura_id = ?
            LIMIT 1
        ");
        $stDel->execute([$facturaId]);
        $delivery = $stDel->fetch(PDO::FETCH_ASSOC) ?: null;

        // Devoluciones B04 asociadas
        $stDev = $pdo->prepare("
            SELECT d.ncf_nota_credito, d.monto_total, d.metodo_reembolso, d.codigo_credito, d.estado_saldo,
                   TO_CHAR(d.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_fmt
            FROM ventas.devoluciones d
            WHERE d.factura_id = ?
            ORDER BY d.id ASC
        ");
        $stDev->execute([$facturaId]);
        $devoluciones = $stDev->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Cupones cashback emitidos por esta venta
        $stCup = $pdo->prepare("
            SELECT codigo_cupon, monto_credito, TO_CHAR(fecha_vencimiento, 'DD/MM/YYYY') AS vence_fmt, estado
            FROM promociones.cupones_descuento
            WHERE factura_origen_id = ?
        ");
        $stCup->execute([$facturaId]);
        $cuponesGenerados = $stCup->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$esSupervisorOAdmin) {
            foreach ($detalles as &$d) {
                unset($d['costo_unitario_hist']);
            }
            unset($d);
        }

        responderHist(true, 'OK', [
            'cabecera' => $cab,
            'detalles' => $detalles,
            'resumen_impuestos' => $resumenImpuestos,
            'pagos' => $pagos,
            'delivery' => $delivery,
            'devoluciones' => $devoluciones,
            'cupones_generados' => $cuponesGenerados,
        ]);
    }

    // =========================================================================
    // 3. EXPORTAR REPORTE FILTRADO A CSV (COMPATIBLE CON EXCEL)
    // =========================================================================
    if ($accion === 'exportar_csv') {
        [$whereSql, $params] = construirFiltrosHistorial($_GET);

        $sqlCsv = "
            SELECT f.numero_factura,
                   TO_CHAR(f.fecha_emision, 'YYYY-MM-DD HH24:MI:SS') AS fecha_emision,
                   f.tipo_ncf,
                   COALESCE(f.ncf, 'SIN NCF') AS ncf,
                   COALESCE(NULLIF(TRIM(f.razon_social_cliente), ''), pc.nombre_razon_social, 'Consumidor Final') AS cliente,
                   COALESCE(NULLIF(TRIM(f.rnc_cedula_cliente), ''), pc.documento_identidad, '') AS documento,
                   COALESCE(NULLIF(TRIM(pv.nombre_razon_social), ''), uv.username, '') AS vendedor,
                   COALESCE(cp.nombre_equipo, 'Caja') AS caja,
                   f.condicion_pago,
                   f.origen_venta,
                   COALESCE((
                       SELECT STRING_AGG(DISTINCT mp.codigo, ' + ' ORDER BY mp.codigo)
                       FROM ventas.factura_pagos fp
                       JOIN tesoreria.detalles_pago dp ON dp.id = fp.detalle_pago_id
                       JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id
                       WHERE fp.factura_id = f.id
                   ), CASE WHEN f.condicion_pago = 'CREDITO' THEN 'CXC' ELSE '' END) AS metodos_pago,
                   COALESCE(m.codigo_iso, 'DOP') AS moneda,
                   f.tasa_cambio,
                   f.monto_subtotal,
                   f.monto_descuento,
                   f.monto_impuestos,
                   f.monto_total,
                   COALESCE((SELECT SUM(d.monto_total) FROM ventas.devoluciones d WHERE d.factura_id = f.id), 0) AS monto_devuelto_b04,
                   f.estado
            {$joinsBase}
            WHERE {$whereSql}
            ORDER BY f.fecha_emision DESC, f.id DESC
            LIMIT 5000
        ";

        $stCsv = $pdo->prepare($sqlCsv);
        $stCsv->execute($params);
        $rows = $stCsv->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $filename = 'Historial_Ventas_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para tildes en Excel

        fputcsv($out, [
            'N° Factura',
            'Fecha Emisión',
            'Tipo NCF',
            'NCF',
            'Cliente',
            'RNC / Cédula',
            'Vendedor / Cajero',
            'Terminal',
            'Condición',
            'Origen',
            'Métodos de Pago',
            'Moneda',
            'Tasa Cambio',
            'Subtotal',
            'Descuento',
            'Impuestos',
            'Total Factura',
            'Devuelto (B04)',
            'Estado'
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['numero_factura'],
                $r['fecha_emision'],
                $r['tipo_ncf'],
                $r['ncf'],
                $r['cliente'],
                $r['documento'],
                $r['vendedor'],
                $r['caja'],
                $r['condicion_pago'],
                $r['origen_venta'],
                $r['metodos_pago'],
                $r['moneda'],
                $r['tasa_cambio'],
                number_format((float) $r['monto_subtotal'], 2, '.', ''),
                number_format((float) $r['monto_descuento'], 2, '.', ''),
                number_format((float) $r['monto_impuestos'], 2, '.', ''),
                number_format((float) $r['monto_total'], 2, '.', ''),
                number_format((float) $r['monto_devuelto_b04'], 2, '.', ''),
                $r['estado'],
            ]);
        }
        fclose($out);
        exit;
    }

    responderHist(false, 'Acción no reconocida.');
} catch (Throwable $e) {
    responderHist(false, $e->getMessage(), [], 400);
}