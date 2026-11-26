<?php
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

requerirSesionActiva(true);

$esAdmin = function_exists('usuarioEsAdministrador') && usuarioEsAdministrador();
$puedeKardex = $esAdmin
    || (function_exists('usuarioPuedeAccederMovimientosInventario') && usuarioPuedeAccederMovimientosInventario())
    || (function_exists('usuarioPuedeAccederLotes') && usuarioPuedeAccederLotes())
    || (function_exists('usuarioTienePermiso') && usuarioTienePermiso('VER_PRODUCTOS'));

function responderKardex(bool $ok, string $mensaje, array $extra = [], int $httpCode = 200): void
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

if (!$puedeKardex) {
    responderKardex(false, 'Acceso restringido: no posees permisos para consultar el Kardex Valorado FEFO.', [], 403);
}
if (!$pdo) {
    responderKardex(false, 'No hay conexión activa con PostgreSQL.', [], 500);
}

$accion = trim((string) ($_GET['accion'] ?? $_POST['accion'] ?? 'resumen_inventario'));

function consultarInventarioConsolidado(PDO $pdo, array $in): array
{
    $almacenId = intval($in['almacen_id'] ?? 0);
    $categoriaId = intval($in['categoria_id'] ?? 0);
    $marcaId = intval($in['marca_id'] ?? 0);
    $soloStock = (($in['solo_con_stock'] ?? '0') === '1');
    $q = trim((string) ($in['q'] ?? ''));

    $params = [];
    $filtroAlm = '';
    if ($almacenId > 0) {
        $filtroAlm = ' AND s.almacen_id = ? ';
        array_push($params, $almacenId, $almacenId, $almacenId); // stock_prod, stock_almacen, prox_venc
    }

    $whereProd = ['pm.id > 0'];
    if ($categoriaId > 0) {
        $whereProd[] = 'pm.categoria_id = ?';
        $params[] = $categoriaId;
    }
    if ($marcaId > 0) {
        $whereProd[] = 'pm.marca_id = ?';
        $params[] = $marcaId;
    }
    if ($q !== '') {
        $like = '%' . $q . '%';
        $whereProd[] = "(
            pm.sku ILIKE ? OR pm.nombre ILIKE ?
            OR COALESCE(cp.nombre, '') ILIKE ? OR COALESCE(m.nombre, '') ILIKE ?
            OR EXISTS (
                SELECT 1 FROM inventario.producto_codigos_barra pcb
                WHERE pcb.producto_maestro_id = pm.id
                  AND (pcb.codigo_barra ILIKE ? OR pcb.descripcion_presentacion ILIKE ?)
            )
        )";
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    $whereOuter = $soloStock ? 'WHERE base.stock_fisico_total > 0' : '';

    $sql = "
        WITH stock_prod AS (
            SELECT s.producto_maestro_id,
                   COALESCE(SUM(s.stock_fisico_unidades), 0) AS stock_fisico_total,
                   COALESCE(SUM(s.stock_comprometido), 0) AS stock_comprometido_total,
                   COALESCE(SUM(s.stock_fisico_unidades - s.stock_comprometido), 0) AS stock_disponible_total,
                   COUNT(DISTINCT CASE WHEN s.stock_fisico_unidades > 0 THEN s.lote_id END) AS lotes_activos
            FROM inventario.inventario_stock s
            WHERE 1=1 {$filtroAlm}
            GROUP BY s.producto_maestro_id
        ),
        stock_almacen AS (
            SELECT t.producto_maestro_id,
                   JSON_AGG(
                       JSON_BUILD_OBJECT(
                           'almacen_id', t.almacen_id,
                           'almacen_nombre', t.almacen_nombre,
                           'fisico', t.fisico,
                           'disponible', t.disponible
                       ) ORDER BY t.almacen_id ASC
                   ) AS almacenes_json
            FROM (
                SELECT s.producto_maestro_id, a.id AS almacen_id, a.nombre AS almacen_nombre,
                       SUM(s.stock_fisico_unidades) AS fisico,
                       SUM(s.stock_fisico_unidades - s.stock_comprometido) AS disponible
                FROM inventario.inventario_stock s
                JOIN inventario.almacenes a ON a.id = s.almacen_id
                WHERE s.stock_fisico_unidades > 0 {$filtroAlm}
                GROUP BY s.producto_maestro_id, a.id, a.nombre
            ) t
            GROUP BY t.producto_maestro_id
        ),
        prox_venc AS (
            SELECT s.producto_maestro_id, MIN(l.fecha_vencimiento) AS proximo_vencimiento
            FROM inventario.inventario_stock s
            JOIN inventario.lotes l ON l.id = s.lote_id
            WHERE s.stock_fisico_unidades > 0 AND l.id > 0 {$filtroAlm}
            GROUP BY s.producto_maestro_id
        ),
        base AS (
            SELECT pm.id AS producto_maestro_id,
                   pm.sku,
                   COALESCE(
                       NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                       (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                       pm.sku
                   ) AS producto_nombre,
                   pm.requiere_lote,
                   pm.costo_promedio,
                   pm.precio_base_unidad,
                   COALESCE(cp.nombre, 'General') AS categoria_nombre,
                   COALESCE(m.nombre, 'Genérica') AS marca_nombre,
                   COALESCE(sp.stock_fisico_total, 0) AS stock_fisico_total,
                   COALESCE(sp.stock_comprometido_total, 0) AS stock_comprometido_total,
                   COALESCE(sp.stock_disponible_total, 0) AS stock_disponible_total,
                   COALESCE(sp.lotes_activos, 0) AS lotes_activos,
                   COALESCE(sa.almacenes_json, '[]'::json) AS almacenes_json,
                   pv.proximo_vencimiento,
                   CASE WHEN pv.proximo_vencimiento IS NULL THEN NULL ELSE (pv.proximo_vencimiento - CURRENT_DATE) END AS dias_para_vencer,
                   ROUND(COALESCE(sp.stock_fisico_total, 0) * pm.costo_promedio, 2) AS valor_costo_total,
                   ROUND(COALESCE(sp.stock_fisico_total, 0) * pm.precio_base_unidad, 2) AS valor_venta_total
            FROM inventario.productos_maestro pm
            LEFT JOIN inventario.categoria_producto cp ON cp.id = pm.categoria_id
            LEFT JOIN inventario.marca m ON m.id = pm.marca_id
            LEFT JOIN stock_prod sp ON sp.producto_maestro_id = pm.id
            LEFT JOIN stock_almacen sa ON sa.producto_maestro_id = pm.id
            LEFT JOIN prox_venc pv ON pv.producto_maestro_id = pm.id
            WHERE " . implode(' AND ', $whereProd) . "
        )
        SELECT * FROM base
        {$whereOuter}
        ORDER BY base.valor_costo_total DESC, base.producto_nombre ASC
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totFis = 0;
    $totDisp = 0;
    $totCosto = 0.0;
    $totVenta = 0.0;
    $conStk = 0;
    $agot = 0;
    foreach ($rows as &$r) {
        $r['stock_fisico_total'] = intval($r['stock_fisico_total']);
        $r['stock_comprometido_total'] = intval($r['stock_comprometido_total']);
        $r['stock_disponible_total'] = intval($r['stock_disponible_total']);
        $r['lotes_activos'] = intval($r['lotes_activos']);
        $r['costo_promedio'] = round(floatval($r['costo_promedio']), 2);
        $r['precio_base_unidad'] = round(floatval($r['precio_base_unidad']), 2);
        $r['valor_costo_total'] = round(floatval($r['valor_costo_total']), 2);
        $r['valor_venta_total'] = round(floatval($r['valor_venta_total']), 2);
        $r['margen_potencial'] = round($r['valor_venta_total'] - $r['valor_costo_total'], 2);
        $r['requiere_lote'] = in_array($r['requiere_lote'], [true, 't', 1, '1'], true);
        $r['dias_para_vencer'] = ($r['dias_para_vencer'] !== null) ? intval($r['dias_para_vencer']) : null;
        $r['almacenes'] = json_decode($r['almacenes_json'] ?? '[]', true) ?: [];
        unset($r['almacenes_json']);

        $totFis += $r['stock_fisico_total'];
        $totDisp += $r['stock_disponible_total'];
        $totCosto = round($totCosto + $r['valor_costo_total'], 2);
        $totVenta = round($totVenta + $r['valor_venta_total'], 2);
        if ($r['stock_fisico_total'] > 0)
            $conStk++;
        else
            $agot++;
    }
    unset($r);

    $pRiesgo = [];
    $sqlRiesgo = "
        SELECT COALESCE(SUM(s.stock_fisico_unidades), 0) AS uds_riesgo,
               COALESCE(ROUND(SUM(s.stock_fisico_unidades * pm.costo_promedio), 2), 0) AS valor_riesgo,
               COUNT(DISTINCT s.lote_id) AS lotes_riesgo
        FROM inventario.inventario_stock s
        JOIN inventario.lotes l ON l.id = s.lote_id
        JOIN inventario.productos_maestro pm ON pm.id = s.producto_maestro_id
        WHERE s.stock_fisico_unidades > 0 AND l.id > 0
          AND l.fecha_vencimiento <= (CURRENT_DATE + INTERVAL '30 days')
    ";
    if ($almacenId > 0) {
        $sqlRiesgo .= " AND s.almacen_id = ?";
        $pRiesgo[] = $almacenId;
    }
    $stR = $pdo->prepare($sqlRiesgo);
    $stR->execute($pRiesgo);
    $rR = $stR->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'items' => $rows,
        'kpis' => [
            'total_skus' => count($rows),
            'skus_con_stock' => $conStk,
            'skus_agotados' => $agot,
            'unidades_fisicas' => $totFis,
            'unidades_disponibles' => $totDisp,
            'valor_costo_total' => $totCosto,
            'valor_venta_total' => $totVenta,
            'margen_bruto_est' => round($totVenta - $totCosto, 2),
            'lotes_riesgo_30d' => intval($rR['lotes_riesgo'] ?? 0),
            'unidades_riesgo_30d' => intval($rR['uds_riesgo'] ?? 0),
            'valor_riesgo_30d' => round(floatval($rR['valor_riesgo'] ?? 0), 2),
        ],
    ];
}

function consultarLibroKardex(PDO $pdo, array $in): array
{
    $productoId = intval($in['producto_id'] ?? 0);
    $tipoMov = strtoupper(trim((string) ($in['tipo_movimiento'] ?? '')));
    $fechaDesde = trim((string) ($in['fecha_desde'] ?? ''));
    $fechaHasta = trim((string) ($in['fecha_hasta'] ?? ''));
    $q = trim((string) ($in['q'] ?? ''));

    $where = ['pm.id > 0'];
    $params = [];

    if ($productoId > 0) {
        $where[] = 'd.producto_maestro_id = ?';
        $params[] = $productoId;
    }
    if (in_array($tipoMov, ['ENTRADA_COMPRA', 'SALIDA_VENTA', 'AJUSTE_ENTRADA', 'AJUSTE_SALIDA', 'DEVOLUCION_CLIENTE', 'MERMA_ROTURA'], true)) {
        $where[] = 'm.tipo_movimiento = ?';
        $params[] = $tipoMov;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) {
        $where[] = 'm.fecha_movimiento::date >= ?';
        $params[] = $fechaDesde;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
        $where[] = 'm.fecha_movimiento::date <= ?';
        $params[] = $fechaHasta;
    }
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(
            pm.sku ILIKE ? OR pm.nombre ILIKE ? OR COALESCE(l.numero_lote, '') ILIKE ?
            OR m.justificacion ILIKE ? OR COALESCE(f.numero_factura, '') ILIKE ?
            OR COALESCE(rc.numero_factura_proveedor, '') ILIKE ? OR COALESCE(dv.ncf_nota_credito, '') ILIKE ?
        )";
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }

    $conSaldoCorrido = ($productoId > 0 && $tipoMov === '' && $q === '');
    $saldoInicialUds = 0;
    $saldoInicialValor = 0.00;

    if ($conSaldoCorrido && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) {
        $stInit = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN m.tipo_movimiento IN ('ENTRADA_COMPRA','AJUSTE_ENTRADA','DEVOLUCION_CLIENTE') THEN d.cantidad_unidades ELSE -d.cantidad_unidades END), 0) AS saldo_uds,
                COALESCE(ROUND(SUM(CASE WHEN m.tipo_movimiento IN ('ENTRADA_COMPRA','AJUSTE_ENTRADA','DEVOLUCION_CLIENTE') THEN d.cantidad_unidades * d.costo_unitario_referencia ELSE -(d.cantidad_unidades * d.costo_unitario_referencia) END), 2), 0) AS saldo_val
            FROM inventario.movimiento_inventario_detalles d
            JOIN inventario.movimientos_inventario m ON m.id = d.movimiento_id
            WHERE d.producto_maestro_id = ? AND m.fecha_movimiento::date < ?
        ");
        $stInit->execute([$productoId, $fechaDesde]);
        $rInit = $stInit->fetch(PDO::FETCH_ASSOC) ?: [];
        $saldoInicialUds = intval($rInit['saldo_uds'] ?? 0);
        $saldoInicialValor = round(floatval($rInit['saldo_val'] ?? 0), 2);
    }

    $orderSql = $conSaldoCorrido ? 'ORDER BY m.fecha_movimiento ASC, m.id ASC' : 'ORDER BY m.fecha_movimiento DESC, m.id DESC';

    $sql = "
        SELECT m.id AS movimiento_id, m.tipo_movimiento, m.justificacion, m.documento_referencia_id,
               TO_CHAR(m.fecha_movimiento, 'DD/MM/YYYY HH12:MI AM') AS fecha_fmt,
               d.producto_maestro_id, d.lote_id, d.cantidad_unidades, d.costo_unitario_referencia,
               ROUND(d.cantidad_unidades * d.costo_unitario_referencia, 2) AS valor_movimiento,
               pm.sku,
               COALESCE(NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'), pm.sku) AS producto_nombre,
               CASE WHEN d.lote_id = 0 THEN 'SIN LOTE' ELSE COALESCE(l.numero_lote, 'LOTE #' || d.lote_id) END AS numero_lote,
               CASE WHEN d.lote_id = 0 THEN NULL ELSE TO_CHAR(l.fecha_vencimiento, 'DD/MM/YYYY') END AS vencimiento_fmt,
               COALESCE(NULLIF(TRIM(ps.nombre_razon_social), ''), us.username, 'Sistema') AS supervisor_nombre,
               CASE
                   WHEN m.tipo_movimiento = 'SALIDA_VENTA' AND f.numero_factura IS NOT NULL THEN f.numero_factura || COALESCE(' (' || f.ncf || ')', '')
                   WHEN m.tipo_movimiento = 'ENTRADA_COMPRA' AND rc.numero_factura_proveedor IS NOT NULL THEN 'Compra #' || rc.id || ' • ' || rc.numero_factura_proveedor
                   WHEN m.tipo_movimiento = 'DEVOLUCION_CLIENTE' AND dv.ncf_nota_credito IS NOT NULL THEN 'B04 • ' || dv.ncf_nota_credito
                   WHEN m.documento_referencia_id IS NOT NULL THEN 'Ref #' || m.documento_referencia_id
                   ELSE 'Manual'
               END AS documento_ref_txt
        FROM inventario.movimiento_inventario_detalles d
        JOIN inventario.movimientos_inventario m ON m.id = d.movimiento_id
        JOIN inventario.productos_maestro pm ON pm.id = d.producto_maestro_id
        LEFT JOIN inventario.lotes l ON l.id = d.lote_id
        LEFT JOIN seguridad.usuarios us ON us.usuario_id = m.supervisor_id
        LEFT JOIN identidad.personas ps ON ps.persona_id = us.persona_id
        LEFT JOIN ventas.facturas f ON m.tipo_movimiento = 'SALIDA_VENTA' AND f.id = m.documento_referencia_id
        LEFT JOIN compras.recepciones_compra rc ON m.tipo_movimiento = 'ENTRADA_COMPRA' AND rc.id = m.documento_referencia_id
        LEFT JOIN ventas.devoluciones dv ON m.tipo_movimiento = 'DEVOLUCION_CLIENTE' AND dv.id = m.documento_referencia_id
        WHERE " . implode(' AND ', $where) . "
        {$orderSql}
        LIMIT 500
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $movs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $entU = 0;
    $entV = 0.0;
    $salU = 0;
    $salV = 0.0;
    $saldoUds = $saldoInicialUds;
    $saldoVal = $saldoInicialValor;

    foreach ($movs as &$mv) {
        $esEnt = in_array($mv['tipo_movimiento'], ['ENTRADA_COMPRA', 'AJUSTE_ENTRADA', 'DEVOLUCION_CLIENTE'], true);
        $cant = intval($mv['cantidad_unidades']);
        $val = round(floatval($mv['valor_movimiento']), 2);

        $mv['es_entrada'] = $esEnt;
        $mv['cantidad_unidades'] = $cant;
        $mv['costo_unitario_referencia'] = round(floatval($mv['costo_unitario_referencia']), 2);
        $mv['valor_movimiento'] = $val;

        if ($esEnt) {
            $entU += $cant;
            $entV = round($entV + $val, 2);
            $saldoUds += $cant;
            $saldoVal = round($saldoVal + $val, 2);
        } else {
            $salU += $cant;
            $salV = round($salV + $val, 2);
            $saldoUds -= $cant;
            $saldoVal = round($saldoVal - $val, 2);
        }
        $mv['saldo_unidades'] = $conSaldoCorrido ? $saldoUds : null;
        $mv['saldo_valor'] = $conSaldoCorrido ? $saldoVal : null;
    }
    unset($mv);

    return [
        'movimientos' => $movs,
        'con_saldo_corrido' => $conSaldoCorrido,
        'saldo_inicial_uds' => $saldoInicialUds,
        'saldo_inicial_val' => $saldoInicialValor,
        'resumen' => [
            'total_movimientos' => count($movs),
            'entradas_uds' => $entU,
            'entradas_val' => $entV,
            'salidas_uds' => $salU,
            'salidas_val' => $salV,
            'saldo_final_uds' => $conSaldoCorrido ? $saldoUds : null,
            'saldo_final_val' => $conSaldoCorrido ? $saldoVal : null,
        ],
    ];
}

function consultarMatrizFefo(PDO $pdo, array $in): array
{
    $almacenId = intval($in['almacen_id'] ?? 0);
    $estadoF = strtoupper(trim((string) ($in['estado_fefo'] ?? '')));
    $q = trim((string) ($in['q'] ?? ''));

    $where = ['pm.id > 0', 's.stock_fisico_unidades > 0'];
    $params = [];

    if ($almacenId > 0) {
        $where[] = 's.almacen_id = ?';
        $params[] = $almacenId;
    }
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(pm.sku ILIKE ? OR pm.nombre ILIKE ? OR l.numero_lote ILIKE ? OR a.nombre ILIKE ?)";
        array_push($params, $like, $like, $like, $like);
    }

    if ($estadoF === 'VENCIDO')
        $where[] = 'l.id > 0 AND l.fecha_vencimiento < CURRENT_DATE';
    elseif ($estadoF === 'CRITICO')
        $where[] = "l.id > 0 AND l.fecha_vencimiento BETWEEN CURRENT_DATE AND (CURRENT_DATE + INTERVAL '15 days')";
    elseif ($estadoF === 'PROXIMO')
        $where[] = "l.id > 0 AND l.fecha_vencimiento > (CURRENT_DATE + INTERVAL '15 days') AND l.fecha_vencimiento <= (CURRENT_DATE + INTERVAL '45 days')";
    elseif ($estadoF === 'VIGENTE')
        $where[] = "l.id > 0 AND l.fecha_vencimiento > (CURRENT_DATE + INTERVAL '45 days')";
    elseif ($estadoF === 'SIN_LOTE')
        $where[] = 'l.id = 0';

    $st = $pdo->prepare("
        SELECT s.almacen_id, a.nombre AS almacen_nombre, s.producto_maestro_id, pm.sku,
               COALESCE(NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'), pm.sku) AS producto_nombre,
               pm.costo_promedio, s.lote_id,
               CASE WHEN l.id = 0 THEN 'SIN LOTE (GENÉRICO)' ELSE l.numero_lote END AS numero_lote,
               CASE WHEN l.id = 0 THEN 'No aplica' ELSE TO_CHAR(l.fecha_vencimiento, 'DD/MM/YYYY') END AS vencimiento_fmt,
               CASE WHEN l.id = 0 THEN NULL ELSE (l.fecha_vencimiento - CURRENT_DATE) END AS dias_restantes,
               s.stock_fisico_unidades, s.stock_comprometido,
               (s.stock_fisico_unidades - s.stock_comprometido) AS stock_disponible,
               ROUND(s.stock_fisico_unidades * pm.costo_promedio, 2) AS valor_lote_costo
        FROM inventario.inventario_stock s
        JOIN inventario.almacenes a ON a.id = s.almacen_id
        JOIN inventario.productos_maestro pm ON pm.id = s.producto_maestro_id
        JOIN inventario.lotes l ON l.id = s.lote_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY CASE WHEN l.id = 0 THEN 1 ELSE 0 END ASC, l.fecha_vencimiento ASC, pm.sku ASC, a.id ASC
        LIMIT 500
    ");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totUds = 0;
    $totVal = 0.0;
    foreach ($rows as &$r) {
        $r['stock_fisico_unidades'] = intval($r['stock_fisico_unidades']);
        $r['stock_comprometido'] = intval($r['stock_comprometido']);
        $r['stock_disponible'] = intval($r['stock_disponible']);
        $r['costo_promedio'] = round(floatval($r['costo_promedio']), 2);
        $r['valor_lote_costo'] = round(floatval($r['valor_lote_costo']), 2);
        $r['dias_restantes'] = ($r['dias_restantes'] !== null) ? intval($r['dias_restantes']) : null;

        if (intval($r['lote_id']) === 0)
            $r['estado_fefo'] = 'SIN_LOTE';
        elseif ($r['dias_restantes'] < 0)
            $r['estado_fefo'] = 'VENCIDO';
        elseif ($r['dias_restantes'] <= 15)
            $r['estado_fefo'] = 'CRITICO';
        elseif ($r['dias_restantes'] <= 45)
            $r['estado_fefo'] = 'PROXIMO';
        else
            $r['estado_fefo'] = 'VIGENTE';

        $totUds += $r['stock_fisico_unidades'];
        $totVal = round($totVal + $r['valor_lote_costo'], 2);
    }
    unset($r);

    return [
        'lotes' => $rows,
        'resumen' => ['total_registros' => count($rows), 'total_unidades' => $totUds, 'total_valor_dop' => $totVal],
    ];
}

try {
    if ($accion === 'resumen_inventario') {
        responderKardex(true, 'OK', consultarInventarioConsolidado($pdo, $_GET));
    }

    if ($accion === 'detalle_producto_stock') {
        $pmId = intval($_GET['producto_id'] ?? 0);
        if ($pmId <= 0)
            responderKardex(false, 'ID de producto inválido.');

        $stP = $pdo->prepare("
            SELECT pm.id, pm.sku,
                   COALESCE(NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'), pm.sku) AS nombre,
                   pm.costo_promedio, pm.precio_base_unidad,
                   COALESCE(cp.nombre, 'General') AS categoria_nombre,
                   COALESCE(m.nombre, 'Genérica') AS marca_nombre
            FROM inventario.productos_maestro pm
            LEFT JOIN inventario.categoria_producto cp ON cp.id = pm.categoria_id
            LEFT JOIN inventario.marca m ON m.id = pm.marca_id
            WHERE pm.id = ? LIMIT 1
        ");
        $stP->execute([$pmId]);
        $prod = $stP->fetch(PDO::FETCH_ASSOC);
        if (!$prod)
            responderKardex(false, 'Producto no encontrado.', [], 404);

        $stS = $pdo->prepare("
            SELECT a.nombre AS almacen_nombre,
                   CASE WHEN l.id = 0 THEN 'SIN LOTE (GENÉRICO)' ELSE l.numero_lote END AS numero_lote,
                   CASE WHEN l.id = 0 THEN 'No aplica' ELSE TO_CHAR(l.fecha_vencimiento, 'DD/MM/YYYY') END AS vencimiento_fmt,
                   CASE WHEN l.id = 0 THEN NULL ELSE (l.fecha_vencimiento - CURRENT_DATE) END AS dias_restantes,
                   s.stock_fisico_unidades, (s.stock_fisico_unidades - s.stock_comprometido) AS stock_disponible,
                   ROUND(s.stock_fisico_unidades * ?, 2) AS valor_costo
            FROM inventario.inventario_stock s
            JOIN inventario.almacenes a ON a.id = s.almacen_id
            JOIN inventario.lotes l ON l.id = s.lote_id
            WHERE s.producto_maestro_id = ? AND s.stock_fisico_unidades > 0
            ORDER BY a.id ASC, CASE WHEN l.id = 0 THEN 1 ELSE 0 END ASC, l.fecha_vencimiento ASC
        ");
        $stS->execute([floatval($prod['costo_promedio']), $pmId]);

        $stPr = $pdo->prepare("SELECT codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = ? ORDER BY factor_multiplicador ASC");
        $stPr->execute([$pmId]);

        responderKardex(true, 'OK', [
            'producto' => $prod,
            'desglose' => $stS->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'presentaciones' => $stPr->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ]);
    }

    if ($accion === 'libro_kardex') {
        responderKardex(true, 'OK', consultarLibroKardex($pdo, $_GET));
    }

    if ($accion === 'matriz_fefo') {
        responderKardex(true, 'OK', consultarMatrizFefo($pdo, $_GET));
    }

    if ($accion === 'exportar_csv') {
        $vista = strtolower(trim((string) ($_GET['vista'] ?? 'inventario')));
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="Kardex_' . strtoupper($vista) . '_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        if ($vista === 'kardex') {
            $data = consultarLibroKardex($pdo, $_GET);
            fputcsv($out, ['Fecha', 'Mov #', 'Tipo', 'Documento', 'SKU', 'Producto', 'Lote', 'Vencimiento', 'Entrada', 'Salida', 'Costo Unit.', 'Valor Mov.', 'Saldo Uds', 'Saldo Valor', 'Supervisor', 'Justificación']);
            foreach ($data['movimientos'] as $m) {
                fputcsv($out, [
                    $m['fecha_fmt'],
                    $m['movimiento_id'],
                    $m['tipo_movimiento'],
                    $m['documento_ref_txt'],
                    $m['sku'],
                    $m['producto_nombre'],
                    $m['numero_lote'],
                    $m['vencimiento_fmt'] ?: 'N/A',
                    $m['es_entrada'] ? $m['cantidad_unidades'] : 0,
                    $m['es_entrada'] ? 0 : $m['cantidad_unidades'],
                    number_format($m['costo_unitario_referencia'], 2, '.', ''),
                    number_format($m['valor_movimiento'], 2, '.', ''),
                    $m['saldo_unidades'] ?? '',
                    $m['saldo_valor'] !== null ? number_format($m['saldo_valor'], 2, '.', '') : '',
                    $m['supervisor_nombre'],
                    $m['justificacion'],
                ]);
            }
        } elseif ($vista === 'fefo') {
            $data = consultarMatrizFefo($pdo, $_GET);
            fputcsv($out, ['Almacén', 'SKU', 'Producto', 'Lote', 'Vencimiento', 'Días', 'Estado FEFO', 'Físico', 'Comprometido', 'Disponible', 'Costo Prom.', 'Valor Lote']);
            foreach ($data['lotes'] as $l) {
                fputcsv($out, [
                    $l['almacen_nombre'],
                    $l['sku'],
                    $l['producto_nombre'],
                    $l['numero_lote'],
                    $l['vencimiento_fmt'],
                    $l['dias_restantes'] ?? 'N/A',
                    $l['estado_fefo'],
                    $l['stock_fisico_unidades'],
                    $l['stock_comprometido'],
                    $l['stock_disponible'],
                    number_format($l['costo_promedio'], 2, '.', ''),
                    number_format($l['valor_lote_costo'], 2, '.', ''),
                ]);
            }
        } else {
            $data = consultarInventarioConsolidado($pdo, $_GET);
            fputcsv($out, ['SKU', 'Producto', 'Categoría', 'Marca', 'Físico Total', 'Comprometido', 'Disponible Total', 'Almacenes', 'Próx. Venc.', 'Costo Prom.', 'PVP Base', 'Valor Costo', 'Valor Venta', 'Margen Potencial']);
            foreach ($data['items'] as $it) {
                $almTxt = implode(' | ', array_map(fn($a) => "{$a['almacen_nombre']}: {$a['fisico']}", $it['almacenes']));
                fputcsv($out, [
                    $it['sku'],
                    $it['producto_nombre'],
                    $it['categoria_nombre'],
                    $it['marca_nombre'],
                    $it['stock_fisico_total'],
                    $it['stock_comprometido_total'],
                    $it['stock_disponible_total'],
                    $almTxt ?: 'Sin stock',
                    $it['proximo_vencimiento'] ?: 'N/A',
                    number_format($it['costo_promedio'], 2, '.', ''),
                    number_format($it['precio_base_unidad'], 2, '.', ''),
                    number_format($it['valor_costo_total'], 2, '.', ''),
                    number_format($it['valor_venta_total'], 2, '.', ''),
                    number_format($it['margen_potencial'], 2, '.', ''),
                ]);
            }
        }
        fclose($out);
        exit;
    }

    responderKardex(false, 'Acción no reconocida.');
} catch (Throwable $e) {
    responderKardex(false, $e->getMessage(), [], 400);
}