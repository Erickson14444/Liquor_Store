<?php
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

requerirSesionActiva(true);

$esAdmin = function_exists('usuarioEsAdministrador') && usuarioEsAdministrador();
$puedeFiscal = $esAdmin
    || (function_exists('usuarioTienePermiso') && (
        usuarioTienePermiso('EJECUTAR_CIERRES_FISCALES_PERIODO') || usuarioTienePermiso('VER_REPORTES_FINANCIEROS')
    ));

function responderDgii(bool $ok, string $mensaje, array $extra = [], int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'ok' => $ok,
        'status' => $ok ? 'success' : 'error',
        'mensaje' => $mensaje,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$puedeFiscal)
    responderDgii(false, 'Acceso restringido: no posees permisos para Reportes Fiscales DGII.', [], 403);
if (!$pdo)
    responderDgii(false, 'No hay conexión activa con PostgreSQL.', [], 500);

$accion = trim((string) ($_GET['accion'] ?? $_POST['accion'] ?? 'consultar_periodo'));

function resolverPeriodoDgii(array $in): array
{
    $periodo = trim((string) ($in['periodo'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $periodo))
        $periodo = date('Y-m');
    $desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['fecha_desde'] ?? '')) ? $in['fecha_desde'] : ($periodo . '-01');
    $hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['fecha_hasta'] ?? '')) ? $in['fecha_hasta'] : date('Y-m-t', strtotime($desde));
    return ['periodo_ym' => str_replace('-', '', substr($desde, 0, 7)), 'fecha_desde' => $desde, 'fecha_hasta' => $hasta];
}

function tipoIdDgii(?string $tipoDoc, ?string $doc): array
{
    $digits = preg_replace('/\D/', '', (string) $doc);
    $td = strtoupper(trim((string) $tipoDoc));
    if ($digits === '' || $digits === '00000000000')
        return ['codigo' => '3', 'documento' => ''];
    if ($td === 'RNC' || strlen($digits) === 9)
        return ['codigo' => '1', 'documento' => $digits];
    if ($td === 'CEDULA' || strlen($digits) === 11)
        return ['codigo' => '2', 'documento' => $digits];
    return ['codigo' => '3', 'documento' => preg_replace('/[^A-Za-z0-9]/', '', (string) $doc)];
}

function generarDatasetsDgii(PDO $pdo, array $in): array
{
    $per = resolverPeriodoDgii($in);
    $desde = $per['fecha_desde'];
    $hasta = $per['fecha_hasta'];
    $tipoNcf = strtoupper(trim((string) ($in['tipo_ncf'] ?? '')));
    $qLow = mb_strtolower(trim((string) ($in['q'] ?? '')), 'UTF-8');

    // ---------------- FORMATO 607: VENTAS (B01, B02, B03, B04) ----------------
    $registros607 = [];

    $stFac = $pdo->prepare("
        SELECT f.id, f.numero_factura, f.tipo_ncf, f.ncf, f.condicion_pago,
               f.monto_subtotal, f.monto_descuento, f.monto_impuestos, f.monto_total, f.tasa_cambio,
               TO_CHAR(f.fecha_emision, 'YYYYMMDD') AS fecha_dgii,
               TO_CHAR(f.fecha_emision, 'DD/MM/YYYY') AS fecha_fmt,
               COALESCE(NULLIF(TRIM(f.razon_social_cliente), ''), p.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
               COALESCE(NULLIF(TRIM(f.rnc_cedula_cliente), ''), p.documento_identidad, '') AS cliente_doc,
               COALESCE(p.tipo_documento, 'CEDULA') AS tipo_doc,
               COALESCE((SELECT SUM(fdi.monto_impuesto) FROM ventas.factura_detalle_impuestos fdi JOIN tesoreria.impuestos i ON i.id = fdi.impuesto_id WHERE fdi.factura_id = f.id AND UPPER(i.siglas) LIKE 'ITBIS%'), 0) AS itbis_orig,
               COALESCE((SELECT SUM(fdi.monto_impuesto) FROM ventas.factura_detalle_impuestos fdi JOIN tesoreria.impuestos i ON i.id = fdi.impuesto_id WHERE fdi.factura_id = f.id AND UPPER(i.siglas) LIKE 'ISC%'), 0) AS isc_orig,
               COALESCE((SELECT SUM((dp.monto_pagado - COALESCE(dp.monto_devuelta, 0)) * dp.tasa_cambio) FROM ventas.factura_pagos fp JOIN tesoreria.detalles_pago dp ON dp.id = fp.detalle_pago_id JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id WHERE fp.factura_id = f.id AND mp.codigo = 'EFECTIVO'), 0) AS pag_efectivo,
               COALESCE((SELECT SUM(dp.monto_pagado * dp.tasa_cambio) FROM ventas.factura_pagos fp JOIN tesoreria.detalles_pago dp ON dp.id = fp.detalle_pago_id JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id WHERE fp.factura_id = f.id AND mp.codigo IN ('TRANSFERENCIA','CHEQUE')), 0) AS pag_banco,
               COALESCE((SELECT SUM(dp.monto_pagado * dp.tasa_cambio) FROM ventas.factura_pagos fp JOIN tesoreria.detalles_pago dp ON dp.id = fp.detalle_pago_id JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id WHERE fp.factura_id = f.id AND mp.codigo = 'TARJETA'), 0) AS pag_tarjeta,
               COALESCE((SELECT SUM(dp.monto_pagado * dp.tasa_cambio) FROM ventas.factura_pagos fp JOIN tesoreria.detalles_pago dp ON dp.id = fp.detalle_pago_id JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id WHERE fp.factura_id = f.id AND mp.codigo = 'SALDO_FAVOR'), 0) AS pag_bono
        FROM ventas.facturas f
        LEFT JOIN identidad.personas p ON p.persona_id = f.cliente_id
        WHERE f.estado <> 'ANULADA' AND f.tipo_ncf IN ('B01','B02') AND f.ncf IS NOT NULL
          AND f.fecha_emision::date BETWEEN ? AND ?
        ORDER BY f.fecha_emision ASC, f.id ASC
    ");
    $stFac->execute([$desde, $hasta]);
    foreach ($stFac->fetchAll(PDO::FETCH_ASSOC) ?: [] as $f) {
        $tasa = floatval($f['tasa_cambio'] ?: 1.0);
        $base = round(max(0.0, (floatval($f['monto_subtotal']) - floatval($f['monto_descuento'])) * $tasa), 2);
        $itb = round(floatval($f['itbis_orig']) * $tasa, 2);
        $isc = round(floatval($f['isc_orig']) * $tasa, 2);
        $tot = round(floatval($f['monto_total']) * $tasa, 2);
        $id = tipoIdDgii($f['tipo_doc'], $f['cliente_doc']);

        $registros607[] = [
            'documento' => $f['numero_factura'],
            'tipo_ncf' => $f['tipo_ncf'],
            'ncf' => $f['ncf'],
            'ncf_modificado' => '',
            'tipo_ingreso' => '01',
            'cliente_nombre' => $f['cliente_nombre'],
            'rnc_cedula' => $id['documento'],
            'tipo_id' => $id['codigo'],
            'fecha_dgii' => $f['fecha_dgii'],
            'fecha_fmt' => $f['fecha_fmt'],
            'monto_facturado' => $base,
            'itbis_facturado' => $itb,
            'isc_facturado' => $isc,
            'total_dop' => $tot,
            'pag_efectivo' => round(floatval($f['pag_efectivo']), 2),
            'pag_banco' => round(floatval($f['pag_banco']), 2),
            'pag_tarjeta' => round(floatval($f['pag_tarjeta']), 2),
            'pag_credito' => (strtoupper((string) $f['condicion_pago']) === 'CREDITO') ? $tot : 0.00,
            'pag_bono' => round(floatval($f['pag_bono']), 2),
            'signo' => 1,
            'nota_fiscal' => '',
        ];
    }

    // Notas de Débito B03
    try {
        $stNd = $pdo->prepare("
            SELECT nd.id, nd.ncf_nota_debito, nd.ncf_factura_modificada, nd.concepto_cargo,
                   nd.monto_subtotal, nd.monto_impuestos, nd.monto_total, nd.tasa_cambio,
                   TO_CHAR(nd.fecha_emision, 'YYYYMMDD') AS fecha_dgii,
                   TO_CHAR(nd.fecha_emision, 'DD/MM/YYYY') AS fecha_fmt,
                   COALESCE(p.nombre_razon_social, 'Cliente') AS cliente_nombre,
                   COALESCE(p.documento_identidad, '') AS cliente_doc, COALESCE(p.tipo_documento, 'CEDULA') AS tipo_doc,
                   COALESCE((SELECT SUM(ndi.monto_impuesto) FROM ventas.nota_debito_detalle_impuestos ndi JOIN tesoreria.impuestos i ON i.id = ndi.impuesto_id WHERE ndi.nota_debito_id = nd.id AND UPPER(i.siglas) LIKE 'ITBIS%'), nd.monto_impuestos) AS itbis_orig,
                   COALESCE((SELECT SUM(ndi.monto_impuesto) FROM ventas.nota_debito_detalle_impuestos ndi JOIN tesoreria.impuestos i ON i.id = ndi.impuesto_id WHERE ndi.nota_debito_id = nd.id AND UPPER(i.siglas) LIKE 'ISC%'), 0) AS isc_orig
            FROM ventas.notas_debito nd
            LEFT JOIN identidad.personas p ON p.persona_id = nd.cliente_id
            WHERE nd.fecha_emision::date BETWEEN ? AND ?
            ORDER BY nd.fecha_emision ASC, nd.id ASC
        ");
        $stNd->execute([$desde, $hasta]);
        foreach ($stNd->fetchAll(PDO::FETCH_ASSOC) ?: [] as $nd) {
            $tasa = floatval($nd['tasa_cambio'] ?: 1.0);
            $tot = round(floatval($nd['monto_total']) * $tasa, 2);
            $id = tipoIdDgii($nd['tipo_doc'], $nd['cliente_doc']);
            $registros607[] = [
                'documento' => 'ND-' . $nd['id'],
                'tipo_ncf' => 'B03',
                'ncf' => $nd['ncf_nota_debito'],
                'ncf_modificado' => $nd['ncf_factura_modificada'],
                'tipo_ingreso' => '01',
                'cliente_nombre' => $nd['cliente_nombre'],
                'rnc_cedula' => $id['documento'],
                'tipo_id' => $id['codigo'],
                'fecha_dgii' => $nd['fecha_dgii'],
                'fecha_fmt' => $nd['fecha_fmt'],
                'monto_facturado' => round(floatval($nd['monto_subtotal']) * $tasa, 2),
                'itbis_facturado' => round(floatval($nd['itbis_orig']) * $tasa, 2),
                'isc_facturado' => round(floatval($nd['isc_orig']) * $tasa, 2),
                'total_dop' => $tot,
                'pag_efectivo' => 0.00,
                'pag_banco' => 0.00,
                'pag_tarjeta' => 0.00,
                'pag_credito' => $tot,
                'pag_bono' => 0.00,
                'signo' => 1,
                'nota_fiscal' => 'Cargo B03: ' . $nd['concepto_cargo'],
            ];
        }
    } catch (Throwable $e) {
    }

    // Notas de Crédito B04 (regla DGII > 30 días sin deducción de ITBIS)
    try {
        $stDev = $pdo->prepare("
            SELECT d.id, d.ncf_nota_credito, d.ncf_factura_modificada, d.monto_total AS dev_total,
                   d.metodo_reembolso, d.tasa_cambio,
                   TO_CHAR(d.fecha_emision, 'YYYYMMDD') AS fecha_dgii,
                   TO_CHAR(d.fecha_emision, 'DD/MM/YYYY') AS fecha_fmt,
                   (d.fecha_emision::date - f.fecha_emision::date) AS dias_dif,
                   f.numero_factura, f.monto_total AS fac_total,
                   COALESCE(NULLIF(TRIM(f.razon_social_cliente), ''), p.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
                   COALESCE(NULLIF(TRIM(f.rnc_cedula_cliente), ''), p.documento_identidad, '') AS cliente_doc,
                   COALESCE(p.tipo_documento, 'CEDULA') AS tipo_doc,
                   COALESCE((SELECT SUM(fdi.monto_impuesto) FROM ventas.factura_detalle_impuestos fdi JOIN tesoreria.impuestos i ON i.id = fdi.impuesto_id WHERE fdi.factura_id = f.id AND UPPER(i.siglas) LIKE 'ITBIS%'), 0) AS fac_itbis,
                   COALESCE((SELECT SUM(fdi.monto_impuesto) FROM ventas.factura_detalle_impuestos fdi JOIN tesoreria.impuestos i ON i.id = fdi.impuesto_id WHERE fdi.factura_id = f.id AND UPPER(i.siglas) LIKE 'ISC%'), 0) AS fac_isc
            FROM ventas.devoluciones d
            JOIN ventas.facturas f ON f.id = d.factura_id
            LEFT JOIN identidad.personas p ON p.persona_id = f.cliente_id
            WHERE d.fecha_emision::date BETWEEN ? AND ?
            ORDER BY d.fecha_emision ASC, d.id ASC
        ");
        $stDev->execute([$desde, $hasta]);
        foreach ($stDev->fetchAll(PDO::FETCH_ASSOC) ?: [] as $dv) {
            $tasa = floatval($dv['tasa_cambio'] ?: 1.0);
            $devTot = round(floatval($dv['dev_total']) * $tasa, 2);
            $ratio = (floatval($dv['fac_total']) > 0) ? min(1.0, floatval($dv['dev_total']) / floatval($dv['fac_total'])) : 0.0;
            $dias = intval($dv['dias_dif'] ?? 0);
            $itb = ($dias > 30) ? 0.00 : round(floatval($dv['fac_itbis']) * $ratio * $tasa, 2);
            $isc = round(floatval($dv['fac_isc']) * $ratio * $tasa, 2);
            $base = round(max(0.0, $devTot - $itb - $isc), 2);
            $id = tipoIdDgii($dv['tipo_doc'], $dv['cliente_doc']);

            $registros607[] = [
                'documento' => 'NC-' . $dv['id'] . ' (' . $dv['numero_factura'] . ')',
                'tipo_ncf' => 'B04',
                'ncf' => $dv['ncf_nota_credito'],
                'ncf_modificado' => $dv['ncf_factura_modificada'],
                'tipo_ingreso' => '01',
                'cliente_nombre' => $dv['cliente_nombre'],
                'rnc_cedula' => $id['documento'],
                'tipo_id' => $id['codigo'],
                'fecha_dgii' => $dv['fecha_dgii'],
                'fecha_fmt' => $dv['fecha_fmt'],
                'monto_facturado' => $base,
                'itbis_facturado' => $itb,
                'isc_facturado' => $isc,
                'total_dop' => $devTot,
                'pag_efectivo' => ($dv['metodo_reembolso'] === 'EFECTIVO') ? $devTot : 0.00,
                'pag_banco' => 0.00,
                'pag_tarjeta' => ($dv['metodo_reembolso'] === 'TARJETA') ? $devTot : 0.00,
                'pag_credito' => ($dv['metodo_reembolso'] === 'REVERSION_CXC') ? $devTot : 0.00,
                'pag_bono' => ($dv['metodo_reembolso'] === 'SALDO_FAVOR') ? $devTot : 0.00,
                'signo' => -1,
                'nota_fiscal' => ($dias > 30) ? "Emitida a {$dias}d (>30d: ITBIS no deducible)" : 'Nota de Crédito (B04)',
            ];
        }
    } catch (Throwable $e) {
    }

    usort($registros607, fn($a, $b) => strcmp($a['fecha_dgii'] . $a['ncf'], $b['fecha_dgii'] . $b['ncf']));
    if ($tipoNcf !== '' || $qLow !== '') {
        $registros607 = array_values(array_filter($registros607, function ($r) use ($tipoNcf, $qLow) {
            if ($tipoNcf !== '' && $r['tipo_ncf'] !== $tipoNcf)
                return false;
            if ($qLow !== '' && strpos(mb_strtolower($r['ncf'] . ' ' . $r['ncf_modificado'] . ' ' . $r['cliente_nombre'] . ' ' . $r['rnc_cedula'] . ' ' . $r['documento'], 'UTF-8'), $qLow) === false)
                return false;
            return true;
        }));
    }

    $tot607Base = 0.0;
    $tot607Itbis = 0.0;
    $tot607Isc = 0.0;
    $tot607Neto = 0.0;
    foreach ($registros607 as $r) {
        $sg = intval($r['signo']);
        $tot607Base = round($tot607Base + ($sg * $r['monto_facturado']), 2);
        $tot607Itbis = round($tot607Itbis + ($sg * $r['itbis_facturado']), 2);
        $tot607Isc = round($tot607Isc + ($sg * $r['isc_facturado']), 2);
        $tot607Neto = round($tot607Neto + ($sg * $r['total_dop']), 2);
    }

    // ---------------- FORMATO 606: COMPRAS, SERVICIOS Y RETENCIONES ----------------
    $colsCxp = obtenerColumnasTabla($pdo, 'compras', 'cuentas_por_pagar');
    $tieneServCxp = isset($colsCxp['ncf_servicio']) && isset($colsCxp['numero_factura_servicio']);

    $sqlBloqueServ = $tieneServCxp ? "
        UNION ALL
        SELECT 'SERVICIO' AS origen_compra, '02' AS tipo_bienes_servicios,
               'CxP #' || cxp.id || ' • ' || COALESCE(cxp.numero_factura_servicio, 'Servicio') AS documento,
               COALESCE(cxp.ncf_servicio, '') AS ncf, COALESCE(ncp.ncf_modificado, '') AS ncf_modificado,
               cxp.fecha_emision AS fecha_doc,
               TO_CHAR(cxp.fecha_emision, 'YYYYMMDD') AS fecha_dgii,
               TO_CHAR(cxp.fecha_emision, 'DD/MM/YYYY') AS fecha_fmt,
               TO_CHAR(pg.fecha_ultimo_pago, 'YYYYMMDD') AS fecha_pago_dgii,
               TO_CHAR(pg.fecha_ultimo_pago, 'DD/MM/YYYY') AS fecha_pago_fmt,
               0.00::numeric(12,2) AS monto_bienes_dop,
               ROUND(cxp.monto_original * cxp.tasa_cambio, 2) AS monto_servicios_dop,
               ROUND(COALESCE(rt.itbis_retenido, 0) * cxp.tasa_cambio, 2) AS itbis_retenido_dop,
               ROUND(COALESCE(rt.isr_retenido, 0) * cxp.tasa_cambio, 2) AS isr_retenido_dop,
               cxp.estado AS estado_cxp,
               COALESCE(pg.metodos_pago_txt, 'CREDITO') AS forma_pago_txt,
               p.nombre_razon_social AS proveedor_nombre, p.documento_identidad AS proveedor_doc, p.tipo_documento AS tipo_doc
        FROM compras.cuentas_por_pagar cxp
        JOIN identidad.personas p ON p.persona_id = cxp.proveedor_id
        LEFT JOIN pagos_cxp pg ON pg.cuenta_por_pagar_id = cxp.id
        LEFT JOIN retenciones_cxp rt ON rt.cuenta_por_pagar_id = cxp.id
        LEFT JOIN nc_prov ncp ON ncp.cuenta_por_pagar_id = cxp.id
        WHERE cxp.recepcion_compra_id IS NULL AND cxp.estado <> 'ANULADA'
          AND cxp.fecha_emision BETWEEN ? AND ?
    " : "";

    $st606 = $pdo->prepare("
        WITH pagos_cxp AS (
            SELECT ced.cuenta_por_pagar_id, MAX(ce.fecha_pago) AS fecha_ultimo_pago,
                   STRING_AGG(DISTINCT mp.codigo, ' + ' ORDER BY mp.codigo) AS metodos_pago_txt
            FROM compras.comprobante_egreso_detalles ced
            JOIN compras.comprobantes_egreso_cxp ce ON ce.id = ced.comprobante_egreso_id
            LEFT JOIN compras.egreso_cxp_pagos ecp ON ecp.comprobante_egreso_id = ce.id
            LEFT JOIN tesoreria.detalles_pago dp ON dp.id = ecp.detalle_pago_id
            LEFT JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id
            GROUP BY ced.cuenta_por_pagar_id
        ),
        retenciones_cxp AS (
            SELECT cer.cuenta_por_pagar_id,
                   COALESCE(SUM(CASE WHEN UPPER(ret.siglas) LIKE '%ITBIS%' THEN cer.monto_retenido ELSE 0 END), 0) AS itbis_retenido,
                   COALESCE(SUM(CASE WHEN UPPER(ret.siglas) LIKE '%ISR%'   THEN cer.monto_retenido ELSE 0 END), 0) AS isr_retenido
            FROM compras.comprobante_egreso_retenciones cer
            JOIN tesoreria.retenciones ret ON ret.id = cer.retencion_id
            GROUP BY cer.cuenta_por_pagar_id
        ),
        nc_prov AS (
            SELECT cuenta_por_pagar_id, MAX(ncf_nota_credito_proveedor) AS ncf_modificado
            FROM compras.ajustes_cxp
            WHERE ncf_nota_credito_proveedor IS NOT NULL
            GROUP BY cuenta_por_pagar_id
        )
        SELECT 'MERCANCIA' AS origen_compra, '09' AS tipo_bienes_servicios,
               'Recepción #' || rc.id || ' • ' || rc.numero_factura_proveedor AS documento,
               COALESCE(rc.ncf_proveedor, '') AS ncf, COALESCE(ncp.ncf_modificado, '') AS ncf_modificado,
               rc.fecha_recepcion::date AS fecha_doc,
               TO_CHAR(rc.fecha_recepcion, 'YYYYMMDD') AS fecha_dgii,
               TO_CHAR(rc.fecha_recepcion, 'DD/MM/YYYY') AS fecha_fmt,
               TO_CHAR(pg.fecha_ultimo_pago, 'YYYYMMDD') AS fecha_pago_dgii,
               TO_CHAR(pg.fecha_ultimo_pago, 'DD/MM/YYYY') AS fecha_pago_fmt,
               ROUND(rc.monto_total_factura * rc.tasa_cambio, 2) AS monto_bienes_dop,
               0.00::numeric(12,2) AS monto_servicios_dop,
               ROUND(COALESCE(rt.itbis_retenido, 0) * rc.tasa_cambio, 2) AS itbis_retenido_dop,
               ROUND(COALESCE(rt.isr_retenido, 0) * rc.tasa_cambio, 2) AS isr_retenido_dop,
               COALESCE(cxp.estado, 'PENDIENTE') AS estado_cxp,
               COALESCE(pg.metodos_pago_txt, CASE WHEN rc.condicion_pago = 'CREDITO' THEN 'CREDITO' ELSE 'EFECTIVO' END) AS forma_pago_txt,
               p.nombre_razon_social AS proveedor_nombre, p.documento_identidad AS proveedor_doc, p.tipo_documento AS tipo_doc
        FROM compras.recepciones_compra rc
        JOIN identidad.personas p ON p.persona_id = rc.proveedor_id
        LEFT JOIN compras.cuentas_por_pagar cxp ON cxp.recepcion_compra_id = rc.id
        LEFT JOIN pagos_cxp pg ON pg.cuenta_por_pagar_id = cxp.id
        LEFT JOIN retenciones_cxp rt ON rt.cuenta_por_pagar_id = cxp.id
        LEFT JOIN nc_prov ncp ON ncp.cuenta_por_pagar_id = cxp.id
        WHERE COALESCE(cxp.estado, 'PENDIENTE') <> 'ANULADA'
          AND rc.fecha_recepcion::date BETWEEN ? AND ?
        {$sqlBloqueServ}
        ORDER BY fecha_doc ASC, ncf ASC
    ");
    $st606->execute($tieneServCxp ? [$desde, $hasta, $desde, $hasta] : [$desde, $hasta]);

    $registros606 = [];
    $tot606Bienes = 0.0;
    $tot606Serv = 0.0;
    $tot606RetItbis = 0.0;
    $tot606RetIsr = 0.0;
    foreach ($st606->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
        $id = tipoIdDgii($c['tipo_doc'], $c['proveedor_doc']);
        if ($qLow !== '' && strpos(mb_strtolower($c['ncf'] . ' ' . $c['proveedor_nombre'] . ' ' . $id['documento'] . ' ' . $c['documento'], 'UTF-8'), $qLow) === false)
            continue;

        $bienes = round(floatval($c['monto_bienes_dop']), 2);
        $serv = round(floatval($c['monto_servicios_dop']), 2);
        $retItb = round(floatval($c['itbis_retenido_dop']), 2);
        $retIsr = round(floatval($c['isr_retenido_dop']), 2);
        $fp = strtoupper((string) $c['forma_pago_txt']);
        $codPag = (strpos($fp, '+') !== false) ? '07' : (strpos($fp, 'EFECTIVO') !== false ? '01' : ((strpos($fp, 'TRANSFERENCIA') !== false || strpos($fp, 'CHEQUE') !== false) ? '02' : (strpos($fp, 'TARJETA') !== false ? '03' : '04')));

        $registros606[] = [
            'origen_compra' => $c['origen_compra'],
            'tipo_bienes_servicios' => $c['tipo_bienes_servicios'],
            'documento' => $c['documento'],
            'proveedor_nombre' => $c['proveedor_nombre'],
            'rnc_cedula' => $id['documento'],
            'tipo_id' => $id['codigo'],
            'ncf' => $c['ncf'],
            'ncf_modificado' => $c['ncf_modificado'],
            'fecha_dgii' => $c['fecha_dgii'],
            'fecha_fmt' => $c['fecha_fmt'],
            'fecha_pago_dgii' => $c['fecha_pago_dgii'] ?? '',
            'fecha_pago_fmt' => $c['fecha_pago_fmt'] ?? 'Pendiente',
            'monto_bienes_dop' => $bienes,
            'monto_servicios_dop' => $serv,
            'monto_total_dop' => round($bienes + $serv, 2),
            'itbis_retenido_dop' => $retItb,
            'isr_retenido_dop' => $retIsr,
            'estado_cxp' => $c['estado_cxp'],
            'forma_pago_txt' => $c['forma_pago_txt'],
            'cod_forma_pago_dgii' => $codPag,
        ];
        $tot606Bienes = round($tot606Bienes + $bienes, 2);
        $tot606Serv = round($tot606Serv + $serv, 2);
        $tot606RetItbis = round($tot606RetItbis + $retItb, 2);
        $tot606RetIsr = round($tot606RetIsr + $retIsr, 2);
    }

    // ---------------- FORMATO 608: COMPROBANTES ANULADOS ----------------
    $st608 = $pdo->prepare("
        SELECT f.numero_factura, f.tipo_ncf, f.ncf, ROUND(f.monto_total * f.tasa_cambio, 2) AS monto_anulado_dop,
               TO_CHAR(f.fecha_emision, 'YYYYMMDD') AS fecha_dgii,
               TO_CHAR(f.fecha_emision, 'DD/MM/YYYY') AS fecha_fmt,
               COALESCE(NULLIF(TRIM(f.razon_social_cliente), ''), pc.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
               COALESCE(NULLIF(TRIM(ps.nombre_razon_social), ''), us.username, 'Supervisor') AS supervisor_nombre
        FROM ventas.facturas f
        LEFT JOIN identidad.personas pc ON pc.persona_id = f.cliente_id
        LEFT JOIN seguridad.usuarios us ON us.usuario_id = f.supervisor_anulacion_id
        LEFT JOIN identidad.personas ps ON ps.persona_id = us.persona_id
        WHERE f.estado = 'ANULADA' AND f.ncf IS NOT NULL AND f.fecha_emision::date BETWEEN ? AND ?
        ORDER BY f.fecha_emision ASC, f.id ASC
    ");
    $st608->execute([$desde, $hasta]);
    $registros608 = [];
    foreach ($st608->fetchAll(PDO::FETCH_ASSOC) ?: [] as $an) {
        if ($qLow !== '' && strpos(mb_strtolower($an['ncf'] . ' ' . $an['numero_factura'] . ' ' . $an['cliente_nombre'], 'UTF-8'), $qLow) === false)
            continue;
        $an['monto_anulado_dop'] = round(floatval($an['monto_anulado_dop']), 2);
        $an['tipo_anulacion'] = '02 - Errores de Impresión / Facturación';
        $an['cod_anulacion'] = '02';
        $registros608[] = $an;
    }

    return [
        'periodo' => $per,
        'kpis' => [
            'cant_607' => count($registros607),
            'ventas_base_607' => $tot607Base,
            'itbis_607' => $tot607Itbis,
            'isc_607' => $tot607Isc,
            'ventas_netas_607' => $tot607Neto,
            'cant_606' => count($registros606),
            'compras_606_tot' => round($tot606Bienes + $tot606Serv, 2),
            'compras_bienes' => $tot606Bienes,
            'compras_serv' => $tot606Serv,
            'ret_itbis_606' => $tot606RetItbis,
            'ret_isr_606' => $tot606RetIsr,
            'ret_total_606' => round($tot606RetItbis + $tot606RetIsr, 2),
            'cant_608' => count($registros608),
        ],
        'registros_607' => $registros607,
        'registros_606' => $registros606,
        'registros_608' => $registros608,
    ];
}

try {
    if ($accion === 'consultar_periodo') {
        responderDgii(true, 'OK', generarDatasetsDgii($pdo, $_GET));
    }

    if ($accion === 'exportar_csv') {
        $fmt = trim((string) ($_GET['formato'] ?? '607'));
        $data = generarDatasetsDgii($pdo, $_GET);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="DGII_Formato_' . $fmt . '_' . $data['periodo']['periodo_ym'] . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        if ($fmt === '606') {
            fputcsv($out, ['RNC/Cédula', 'Tipo Id', 'Tipo Bien/Serv', 'NCF', 'NCF Modificado', 'Fecha Comp.', 'Fecha Pago', 'Servicios (DOP)', 'Bienes (DOP)', 'Total (DOP)', 'ITBIS Ret.', 'ISR Ret.', 'Forma Pago', 'Proveedor', 'Documento']);
            foreach ($data['registros_606'] as $r) {
                fputcsv($out, [$r['rnc_cedula'], $r['tipo_id'], $r['tipo_bienes_servicios'], $r['ncf'], $r['ncf_modificado'], $r['fecha_dgii'], $r['fecha_pago_dgii'], number_format($r['monto_servicios_dop'], 2, '.', ''), number_format($r['monto_bienes_dop'], 2, '.', ''), number_format($r['monto_total_dop'], 2, '.', ''), number_format($r['itbis_retenido_dop'], 2, '.', ''), number_format($r['isr_retenido_dop'], 2, '.', ''), $r['cod_forma_pago_dgii'] . ' - ' . $r['forma_pago_txt'], $r['proveedor_nombre'], $r['documento']]);
            }
        } elseif ($fmt === '608') {
            fputcsv($out, ['NCF Anulado', 'Fecha', 'Tipo Anulación', 'Factura', 'Cliente', 'Supervisor', 'Monto Original (DOP)']);
            foreach ($data['registros_608'] as $r) {
                fputcsv($out, [$r['ncf'], $r['fecha_dgii'], $r['tipo_anulacion'], $r['numero_factura'], $r['cliente_nombre'], $r['supervisor_nombre'], number_format($r['monto_anulado_dop'], 2, '.', '')]);
            }
        } else {
            fputcsv($out, ['RNC/Cédula', 'Tipo Id', 'NCF', 'NCF Modificado', 'Tipo Ingreso', 'Fecha', 'Base Neta (DOP)', 'ITBIS (DOP)', 'ISC (DOP)', 'Total (DOP)', 'Efectivo', 'Transf/Chq', 'Tarjeta', 'Crédito', 'Saldo B04', 'Cliente', 'Documento', 'Nota Fiscal']);
            foreach ($data['registros_607'] as $r) {
                fputcsv($out, [$r['rnc_cedula'], $r['tipo_id'], $r['ncf'], $r['ncf_modificado'], $r['tipo_ingreso'], $r['fecha_dgii'], number_format($r['monto_facturado'], 2, '.', ''), number_format($r['itbis_facturado'], 2, '.', ''), number_format($r['isc_facturado'], 2, '.', ''), number_format($r['total_dop'], 2, '.', ''), number_format($r['pag_efectivo'], 2, '.', ''), number_format($r['pag_banco'], 2, '.', ''), number_format($r['pag_tarjeta'], 2, '.', ''), number_format($r['pag_credito'], 2, '.', ''), number_format($r['pag_bono'], 2, '.', ''), $r['cliente_nombre'], $r['documento'], $r['nota_fiscal']]);
            }
        }
        fclose($out);
        exit;
    }

    if ($accion === 'exportar_txt_dgii') {
        $fmt = trim((string) ($_GET['formato'] ?? '607'));
        $rnc = preg_replace('/\D/', '', (string) ($_GET['rnc_empresa'] ?? '131000000')) ?: '131000000';
        $data = generarDatasetsDgii($pdo, $_GET);
        $ym = $data['periodo']['periodo_ym'];

        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="DGII_F_' . $fmt . '_' . $rnc . '_' . $ym . '.txt"');

        $lineas = [];
        if ($fmt === '606') {
            $rows = $data['registros_606'];
            $lineas[] = "606|{$rnc}|{$ym}|" . count($rows);
            foreach ($rows as $r) {
                $lineas[] = implode('|', [$r['rnc_cedula'], $r['tipo_id'], $r['tipo_bienes_servicios'], $r['ncf'], $r['ncf_modificado'], $r['fecha_dgii'], $r['fecha_pago_dgii'], number_format($r['monto_servicios_dop'], 2, '.', ''), number_format($r['monto_bienes_dop'], 2, '.', ''), number_format($r['monto_total_dop'], 2, '.', ''), '0.00', number_format($r['itbis_retenido_dop'], 2, '.', ''), '0.00', '0.00', '0.00', '0.00', '', number_format($r['isr_retenido_dop'], 2, '.', ''), '0.00', '0.00', '0.00', '0.00', $r['cod_forma_pago_dgii']]);
            }
        } elseif ($fmt === '608') {
            $rows = $data['registros_608'];
            $lineas[] = "608|{$rnc}|{$ym}|" . count($rows);
            foreach ($rows as $r)
                $lineas[] = implode('|', [$r['ncf'], $r['fecha_dgii'], $r['cod_anulacion']]);
        } else {
            $rows = $data['registros_607'];
            $lineas[] = "607|{$rnc}|{$ym}|" . count($rows);
            foreach ($rows as $r) {
                $lineas[] = implode('|', [$r['rnc_cedula'], $r['tipo_id'], $r['ncf'], $r['ncf_modificado'], $r['tipo_ingreso'], $r['fecha_dgii'], '', number_format($r['monto_facturado'], 2, '.', ''), number_format($r['itbis_facturado'], 2, '.', ''), '0.00', '0.00', '0.00', '0.00', number_format($r['isc_facturado'], 2, '.', ''), '0.00', '0.00', number_format($r['pag_efectivo'], 2, '.', ''), number_format($r['pag_banco'], 2, '.', ''), number_format($r['pag_tarjeta'], 2, '.', ''), number_format($r['pag_credito'], 2, '.', ''), number_format($r['pag_bono'], 2, '.', ''), '0.00', '0.00']);
            }
        }
        echo implode("\r\n", $lineas);
        exit;
    }

    responderDgii(false, 'Acción no reconocida.');
} catch (Throwable $e) {
    responderDgii(false, $e->getMessage(), [], 400);
}