<?php
/**
 * ============================================================================
 * Módulo 4: Ventas • Proceso 2: Cotizaciones (Controlador Backend)
 * Ubicación: Ventas y Facturacion/Cotizaciones/procesar_cotizaciones.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: ventas.cotizaciones, ventas.cotizaciones_detalle,
 *         ventas.cotizacion_detalle_impuestos, seguridad.auditoria
 * Regla de Oro: NUNCA modifica inventario.inventario_stock ni inventario.lotes.
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

header('Content-Type: application/json; charset=utf-8');

function responderCot(bool $ok, string $mensaje, array $extra = [], int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode(array_merge([
        'ok' => $ok,
        'status' => $ok ? 'success' : 'error',
        'mensaje' => $mensaje,
        'message' => $mensaje,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$pdo) {
    responderCot(false, 'No hay conexión activa con PostgreSQL.', [], 500);
}

$vendedorId = requerirSesionActiva(true);

if (function_exists('usuarioPuedeAccederCotizaciones')) {
    requerirAccesoModulo(usuarioPuedeAccederCotizaciones(), true, 'Acceso restringido: no posees permisos para gestionar Cotizaciones.');
}

$puedeDescuentoManual = (function_exists('usuarioEsAdministrador') && usuarioEsAdministrador())
    || !empty($_SESSION['usuario']['es_supervisor'])
    || (function_exists('usuarioTienePermiso') && usuarioTienePermiso('AUTORIZAR_CON_PIN'));

$puedeCancelarCot = (function_exists('usuarioEsAdministrador') && usuarioEsAdministrador())
    || !empty($_SESSION['usuario']['es_supervisor'])
    || (function_exists('usuarioTienePermiso') && usuarioTienePermiso('CANCELAR_COTIZACION'));

$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : null;
$datos = is_array($jsonBody) ? array_merge($_GET, $jsonBody) : array_merge($_GET, $_POST);
$accion = trim((string) ($datos['accion'] ?? ''));

// Fase 2 (Vigilancia del Tiempo): vencer automáticamente cotizaciones expiradas
try {
    $pdo->exec("
        UPDATE ventas.cotizaciones
        SET estado = 'VENCIDA'
        WHERE estado = 'PENDIENTE'
          AND fecha_vencimiento < CURRENT_DATE
    ");
} catch (Throwable $e) {
}

// ============================================================================
// MOTOR DE IMPUESTOS DINÁMICOS Y CONSOLIDACIÓN DE LÍNEAS
// ============================================================================

function obtenerMonedaCot(PDO $pdo, int $monedaId): array
{
    if ($monedaId > 0) {
        $st = $pdo->prepare("
            SELECT id, codigo_iso, nombre, simbolo, tasa_cambio_referencia, es_moneda_base
            FROM tesoreria.monedas
            WHERE id = ? AND activa = TRUE
            LIMIT 1
        ");
        $st->execute([$monedaId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    return $pdo->query("
        SELECT id, codigo_iso, nombre, simbolo, tasa_cambio_referencia, es_moneda_base
        FROM tesoreria.monedas
        WHERE activa = TRUE
        ORDER BY CASE WHEN es_moneda_base = TRUE THEN 0 ELSE 1 END, id ASC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC) ?: [
        'id' => 1,
        'codigo_iso' => 'DOP',
        'nombre' => 'Peso Dominicano',
        'simbolo' => 'RD$',
        'tasa_cambio_referencia' => 1.0000,
        'es_moneda_base' => true,
    ];
}

function obtenerImpuestosProductoCot(PDO $pdo, int $productoId): array
{
    static $cache = [];
    static $fallback = null;

    if (isset($cache[$productoId])) {
        return $cache[$productoId];
    }

    $st = $pdo->prepare("
        SELECT i.id, i.siglas, i.nombre, i.tipo_calculo, i.tasa_o_monto, i.orden_calculo
        FROM inventario.producto_impuestos pi
        JOIN tesoreria.impuestos i ON i.id = pi.impuesto_id
        WHERE pi.producto_maestro_id = ? AND i.activo = TRUE
        ORDER BY i.orden_calculo ASC, i.id ASC
    ");
    $st->execute([$productoId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($rows)) {
        if ($fallback === null) {
            $fallback = $pdo->query("
                SELECT id, siglas, nombre, tipo_calculo, tasa_o_monto, orden_calculo
                FROM tesoreria.impuestos
                WHERE activo = TRUE AND UPPER(siglas) LIKE 'ITBIS%'
                ORDER BY id ASC LIMIT 1
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $rows = $fallback;
    }

    $cache[$productoId] = $rows;
    return $rows;
}

function calcularImpuestosLineaCot(array $impuestosCfg, float $baseNeta, int $unidadesBase): array
{
    $niveles = [];
    foreach ($impuestosCfg as $imp) {
        $ord = intval($imp['orden_calculo'] ?? 1);
        $niveles[$ord][] = $imp;
    }
    ksort($niveles);

    $baseAcum = max(0.0, $baseNeta);
    $desglose = [];
    $totImp = 0.0;

    foreach ($niveles as $grupo) {
        $sumaNivel = 0.0;
        foreach ($grupo as $imp) {
            $tipo = strtoupper((string) $imp['tipo_calculo']);
            $tasa = floatval($imp['tasa_o_monto']);
            $base = round($baseAcum, 2);
            $mto = ($tipo === 'MONTO_FIJO')
                ? round($tasa * $unidadesBase, 2)
                : round($base * ($tasa / 100.0), 2);

            $desglose[] = [
                'impuesto_id' => intval($imp['id']),
                'siglas' => $imp['siglas'],
                'nombre' => $imp['nombre'],
                'tipo_calculo_aplicado' => $tipo,
                'base_imponible' => $base,
                'tasa_aplicada' => $tasa,
                'monto_impuesto' => $mto,
            ];
            $sumaNivel += $mto;
            $totImp += $mto;
        }
        $baseAcum = round($baseAcum + $sumaNivel, 2);
    }

    return [
        'total_impuestos' => round($totImp, 2),
        'desglose' => $desglose,
    ];
}

/**
 * Evalúa y consolida líneas de cotización por producto_maestro_id (PK compuesta).
 * Respeta precios del catálogo, descuentos manuales (solo si el rol lo permite) e impuestos dinámicos.
 */
function evaluarLineasCotizacion(PDO $pdo, array $partidasRaw, bool $permiteDescManual): array
{
    if (empty($partidasRaw)) {
        return [
            'lineas' => [],
            'monto_bruto' => 0.00,
            'monto_descuentos' => 0.00,
            'monto_subtotal' => 0.00,
            'monto_impuestos' => 0.00,
            'monto_total' => 0.00,
            'resumen_impuestos' => [],
        ];
    }

    $stmtProd = $pdo->prepare("
        SELECT pm.id, pm.sku, pm.precio_base_unidad,
               COALESCE(
                   NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                   (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                   pm.sku
               ) AS nombre,
               COALESCE((
                   SELECT SUM(s.stock_fisico_unidades - s.stock_comprometido)
                   FROM inventario.inventario_stock s
                   WHERE s.producto_maestro_id = pm.id
               ), 0) AS stock_global_disponible
        FROM inventario.productos_maestro pm
        WHERE pm.id = ? AND pm.id > 0
        LIMIT 1
    ");

    $stmtPres = $pdo->prepare("
        SELECT id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion
        FROM inventario.producto_codigos_barra
        WHERE id = ? AND producto_maestro_id = ?
        LIMIT 1
    ");

    $consolidadas = [];

    foreach ($partidasRaw as $raw) {
        $pmId = intval($raw['producto_maestro_id'] ?? 0);
        $cant = intval($raw['cantidad'] ?? 0);
        $cbId = !empty($raw['codigo_barra_id']) ? intval($raw['codigo_barra_id']) : null;
        $desc = $permiteDescManual ? round(max(0.0, floatval($raw['monto_descuento'] ?? 0)), 2) : 0.00;

        if ($pmId <= 0 || $cant <= 0) {
            continue;
        }

        $stmtProd->execute([$pmId]);
        $prod = $stmtProd->fetch(PDO::FETCH_ASSOC);
        if (!$prod) {
            throw new Exception("El producto #{$pmId} no existe en el catálogo maestro.");
        }

        $factor = 1;
        $precio = floatval($prod['precio_base_unidad']);
        $descPres = 'Unidad Base (x1)';

        if ($cbId !== null && $cbId > 0) {
            $stmtPres->execute([$cbId, $pmId]);
            $pres = $stmtPres->fetch(PDO::FETCH_ASSOC);
            if ($pres) {
                $factor = max(1, intval($pres['factor_multiplicador']));
                $precio = floatval($pres['precio_venta_presentacion']);
                $descPres = $pres['descripcion_presentacion'];
            } else {
                $cbId = null;
            }
        }

        $bruto = round($cant * $precio, 2);
        $uds = $cant * $factor;

        if (!isset($consolidadas[$pmId])) {
            $consolidadas[$pmId] = [
                'producto_maestro_id' => $pmId,
                'sku' => $prod['sku'],
                'producto_nombre' => $prod['nombre'],
                'codigo_barra_id' => $cbId,
                'descripcion_presentacion' => $descPres,
                'factor_conversion' => $factor,
                'cantidad' => $cant,
                'unidades_base' => $uds,
                'precio_unitario' => round($precio, 2),
                'monto_bruto' => $bruto,
                'monto_descuento' => min($bruto, $desc),
                'stock_global_disponible' => intval($prod['stock_global_disponible']),
                'monto_impuestos' => 0.00,
                'impuestos_detalle' => [],
                'monto_total' => 0.00,
            ];
        } else {
            $c = &$consolidadas[$pmId];
            $c['unidades_base'] += $uds;
            $c['monto_bruto'] = round($c['monto_bruto'] + $bruto, 2);
            $c['monto_descuento'] = min($c['monto_bruto'], round($c['monto_descuento'] + $desc, 2));

            if ($c['codigo_barra_id'] === $cbId && $c['factor_conversion'] === $factor) {
                $c['cantidad'] += $cant;
            } else {
                $c['codigo_barra_id'] = null;
                $c['factor_conversion'] = 1;
                $c['cantidad'] = $c['unidades_base'];
                $c['descripcion_presentacion'] = 'Consolidado (' . $c['unidades_base'] . ' uds. base)';
                $c['precio_unitario'] = round($c['monto_bruto'] / max(1, $c['unidades_base']), 2);
            }
            unset($c);
        }
    }

    if (empty($consolidadas)) {
        throw new Exception('Agrega al menos un producto válido a la cotización.');
    }

    $totBruto = 0.0;
    $totDescuento = 0.0;
    $totSubtotal = 0.0;
    $totImpuestos = 0.0;
    $totGeneral = 0.0;
    $resumenImp = [];

    foreach ($consolidadas as &$ln) {
        $baseNeta = round(max(0.0, $ln['monto_bruto'] - $ln['monto_descuento']), 2);
        $impCfg = obtenerImpuestosProductoCot($pdo, $ln['producto_maestro_id']);
        $resImp = calcularImpuestosLineaCot($impCfg, $baseNeta, $ln['unidades_base']);

        $ln['base_neta'] = $baseNeta;
        $ln['monto_impuestos'] = $resImp['total_impuestos'];
        $ln['impuestos_detalle'] = $resImp['desglose'];
        $ln['monto_total'] = round($baseNeta + $resImp['total_impuestos'], 2);

        $totBruto += $ln['monto_bruto'];
        $totDescuento += $ln['monto_descuento'];
        $totSubtotal += $baseNeta;
        $totImpuestos += $ln['monto_impuestos'];
        $totGeneral += $ln['monto_total'];

        foreach ($resImp['desglose'] as $di) {
            $sig = $di['siglas'];
            if (!isset($resumenImp[$sig])) {
                $resumenImp[$sig] = ['siglas' => $sig, 'nombre' => $di['nombre'], 'monto' => 0.0];
            }
            $resumenImp[$sig]['monto'] = round($resumenImp[$sig]['monto'] + $di['monto_impuesto'], 2);
        }
    }
    unset($ln);

    return [
        'lineas' => array_values($consolidadas),
        'monto_bruto' => round($totBruto, 2),
        'monto_descuentos' => round($totDescuento, 2),
        'monto_subtotal' => round($totSubtotal, 2),
        'monto_impuestos' => round($totImpuestos, 2),
        'monto_total' => round($totGeneral, 2),
        'resumen_impuestos' => array_values($resumenImp),
    ];
}

try {
    // =========================================================================
    // 1. BUSCADOR RÁPIDO POR CÓDIGO DE BARRAS O SKU
    // =========================================================================
    if ($accion === 'buscar_barcode') {
        $codigo = trim((string) ($datos['codigo'] ?? ''));
        if ($codigo === '') {
            responderCot(false, 'Escanea o ingresa un código de barras o SKU.');
        }

        $st = $pdo->prepare("
            SELECT pm.id AS producto_maestro_id, pm.sku,
                   COALESCE(NULLIF(TRIM(pm.nombre), ''), pcb.descripcion_presentacion, pm.sku) AS producto_nombre,
                   pcb.id AS codigo_barra_id, pcb.codigo_barra, pcb.descripcion_presentacion,
                   pcb.factor_multiplicador AS factor_conversion, pcb.precio_venta_presentacion AS precio_venta
            FROM inventario.producto_codigos_barra pcb
            JOIN inventario.productos_maestro pm ON pm.id = pcb.producto_maestro_id
            WHERE UPPER(TRIM(pcb.codigo_barra)) = UPPER(TRIM(?)) AND pm.id > 0
            LIMIT 1
        ");
        $st->execute([$codigo]);
        $match = $st->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            $stSku = $pdo->prepare("
                SELECT pm.id AS producto_maestro_id,
                       pm.sku,
                       COALESCE(NULLIF(TRIM(pm.nombre), ''), pm.sku) AS producto_nombre,
                       (
                           SELECT id
                           FROM inventario.producto_codigos_barra
                           WHERE producto_maestro_id = pm.id AND factor_multiplicador = 1
                           ORDER BY id ASC
                           LIMIT 1
                       ) AS codigo_barra_id,
                       pm.sku AS codigo_barra,
                       'Unidad Base (x1)' AS descripcion_presentacion,
                       1 AS factor_conversion,
                       pm.precio_base_unidad AS precio_venta
                FROM inventario.productos_maestro pm
                WHERE UPPER(TRIM(pm.sku)) = UPPER(TRIM(?)) AND pm.id > 0
                LIMIT 1
            ");
            $stSku->execute([$codigo]);
            $match = $stSku->fetch(PDO::FETCH_ASSOC);
        }

        if (!$match) {
            responderCot(false, "No se encontró producto ni presentación con el código '{$codigo}'.", [], 404);
        }

        responderCot(true, 'Producto localizado.', ['item' => $match]);
    }

    // =========================================================================
    // 2. BUSCADOR FILTRADO DE CATÁLOGO Y PRESENTACIONES (SIN RESTRINGIR STOCK)
    // =========================================================================
    if ($accion === 'buscar_catalogo') {
        $q = trim((string) ($datos['q'] ?? ''));
        $categoriaId = intval($datos['categoria_id'] ?? 0);
        $marcaId = intval($datos['marca_id'] ?? 0);
        $soloConStock = (($datos['solo_con_stock'] ?? '0') === '1');

        $params = [];
        $where = ["pm.id > 0"];

        if ($categoriaId > 0) {
            $where[] = "pm.categoria_id = ?";
            $params[] = $categoriaId;
        }
        if ($marcaId > 0) {
            $where[] = "pm.marca_id = ?";
            $params[] = $marcaId;
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(
                pm.sku ILIKE ? OR pm.nombre ILIKE ? OR cp.nombre ILIKE ? OR m.nombre ILIKE ?
                OR EXISTS (
                    SELECT 1 FROM inventario.producto_codigos_barra cb2
                    WHERE cb2.producto_maestro_id = pm.id
                      AND (cb2.codigo_barra ILIKE ? OR cb2.descripcion_presentacion ILIKE ?)
                )
            )";
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $filtroStock = $soloConStock ? "WHERE base.stock_disponible > 0" : "";

        $sql = "
            WITH base AS (
                SELECT pm.id AS producto_maestro_id,
                       pm.sku,
                       pm.precio_base_unidad,
                       COALESCE(
                           NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                           (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                           pm.sku
                       ) AS producto_nombre,
                       COALESCE(cp.nombre, 'General') AS categoria_nombre,
                       COALESCE(m.nombre, 'Genérica') AS marca_nombre,
                       COALESCE((
                           SELECT SUM(s.stock_fisico_unidades - s.stock_comprometido)
                           FROM inventario.inventario_stock s
                           WHERE s.producto_maestro_id = pm.id
                       ), 0) AS stock_disponible,
                       COALESCE((
                           SELECT JSON_AGG(
                               JSON_BUILD_OBJECT(
                                   'codigo_barra_id', cb.id,
                                   'codigo_barra', cb.codigo_barra,
                                   'descripcion_presentacion', cb.descripcion_presentacion,
                                   'factor_conversion', cb.factor_multiplicador,
                                   'precio_venta', cb.precio_venta_presentacion
                               )
                               ORDER BY cb.factor_multiplicador ASC, cb.descripcion_presentacion ASC
                           )
                           FROM inventario.producto_codigos_barra cb
                           WHERE cb.producto_maestro_id = pm.id
                       ), '[]'::json) AS presentaciones_json
                FROM inventario.productos_maestro pm
                LEFT JOIN inventario.categoria_producto cp ON cp.id = pm.categoria_id
                LEFT JOIN inventario.marca m ON m.id = pm.marca_id
                WHERE " . implode(' AND ', $where) . "
            )
            SELECT * FROM base {$filtroStock}
            ORDER BY base.producto_nombre ASC, base.sku ASC
            LIMIT 100
        ";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$r) {
            $r['stock_disponible'] = intval($r['stock_disponible']);
            $r['presentaciones'] = json_decode($r['presentaciones_json'] ?? '[]', true) ?: [];
            unset($r['presentaciones_json']);
        }
        unset($r);

        responderCot(true, 'OK', ['items' => $rows]);
    }

    // =========================================================================
    // 2B. BUSCADOR EN VIVO DE CLIENTES (NOMBRE, CÉDULA, RNC, CÓDIGO, TELÉFONO)
    // =========================================================================
    if ($accion === 'buscar_clientes') {
        $q = trim((string) ($datos['q'] ?? ''));
        $params = [];
        $where = [
            "p.estado = TRUE",
            "c.codigo_cliente <> 'CLI-000'",
            "p.documento_identidad <> '00000000000'",
        ];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(
                p.nombre_razon_social ILIKE ?
                OR p.documento_identidad ILIKE ?
                OR c.codigo_cliente ILIKE ?
                OR COALESCE(p.telefono_principal, '') ILIKE ?
            )";
            array_push($params, $like, $like, $like, $like);
        }

        $st = $pdo->prepare("
            SELECT c.persona_id AS cliente_id,
                   c.codigo_cliente,
                   c.categoria_cliente,
                   p.tipo_documento,
                   p.documento_identidad,
                   p.nombre_razon_social,
                   COALESCE(p.telefono_principal, '') AS telefono_principal
            FROM identidad.clientes c
            JOIN identidad.personas p ON p.persona_id = c.persona_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.nombre_razon_social ASC
            LIMIT 25
        ");
        $st->execute($params);
        responderCot(true, 'OK', ['clientes' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    // =========================================================================
    // 3. EVALUACIÓN EN VIVO DE LÍNEAS E IMPUESTOS DINÁMICOS
    // =========================================================================
    if ($accion === 'evaluar_cotizacion') {
        $partidasRaw = $datos['partidas'] ?? [];
        $calculo = evaluarLineasCotizacion($pdo, is_array($partidasRaw) ? $partidasRaw : [], $puedeDescuentoManual);
        responderCot(true, 'OK', ['calculo' => $calculo]);
    }

    // =========================================================================
    // 4. CREAR CLIENTE RÁPIDO (+ CLIENTE) SIN SALIR DE COTIZACIONES
    // =========================================================================
    if ($accion === 'crear_cliente_rapido') {
        $tipoDocumento = strtoupper(trim((string) ($datos['tipo_documento'] ?? 'CEDULA')));
        $documento = trim((string) ($datos['documento_identidad'] ?? ''));
        $nombre = trim((string) ($datos['nombre_razon_social'] ?? ''));
        $telefono = trim((string) ($datos['telefono_principal'] ?? ''));
        $email = trim((string) ($datos['email'] ?? ''));
        $categoria = strtoupper(trim((string) ($datos['categoria_cliente'] ?? 'REGULAR')));

        if (!in_array($tipoDocumento, ['CEDULA', 'RNC', 'PASAPORTE'], true))
            $tipoDocumento = 'CEDULA';
        if (!in_array($categoria, ['REGULAR', 'VIP', 'MAYORISTA'], true))
            $categoria = 'REGULAR';
        if ($documento === '' || $nombre === '') {
            throw new Exception('El número de documento y el nombre/razón social son obligatorios.');
        }

        $tipoPersona = ($tipoDocumento === 'RNC') ? 'JURIDICA' : 'FISICA';

        $pdo->beginTransaction();

        $stPer = $pdo->prepare("SELECT persona_id FROM identidad.personas WHERE documento_identidad = ? LIMIT 1");
        $stPer->execute([$documento]);
        $personaId = $stPer->fetchColumn();

        if ($personaId) {
            $stChk = $pdo->prepare("SELECT persona_id FROM identidad.clientes WHERE persona_id = ? LIMIT 1");
            $stChk->execute([$personaId]);
            if ($stChk->fetchColumn()) {
                throw new Exception('Ya existe un cliente registrado con ese documento.');
            }
        } else {
            $stInsPer = $pdo->prepare("
                INSERT INTO identidad.personas
                    (persona_id, tipo_documento, documento_identidad, nombre_razon_social, email, telefono_principal, tipo_persona, estado)
                VALUES (uuid_generate_v4(), ?, ?, ?, ?, ?, ?, TRUE)
                RETURNING persona_id
            ");
            $stInsPer->execute([
                $tipoDocumento,
                $documento,
                $nombre,
                $email !== '' ? $email : null,
                $telefono !== '' ? $telefono : null,
                $tipoPersona,
            ]);
            $personaId = $stInsPer->fetchColumn();
        }

        $nextNum = (int) $pdo->query("
            SELECT COALESCE(MAX(CAST(SUBSTRING(codigo_cliente FROM '[0-9]+$') AS INTEGER)), 0) + 1
            FROM identidad.clientes
            WHERE codigo_cliente ~ '^CLI-[0-9]+$'
        ")->fetchColumn();
        $codigoCliente = 'CLI-' . str_pad((string) max(1, $nextNum), 3, '0', STR_PAD_LEFT);

        $pdo->prepare("
            INSERT INTO identidad.clientes (persona_id, codigo_cliente, categoria_cliente, limite_credito, permite_credito)
            VALUES (?, ?, ?, 0.00, FALSE)
        ")->execute([$personaId, $codigoCliente, $categoria]);

        registrarAuditoria($pdo, $vendedorId, null, 'COTIZACION_CREAR_CLIENTE', 'identidad.clientes', null, [
            'persona_id' => $personaId,
            'codigo_cliente' => $codigoCliente,
            'documento' => $documento,
            'nombre' => $nombre,
        ]);

        $pdo->commit();

        responderCot(true, "Cliente «{$nombre}» creado y seleccionado.", [
            'cliente' => [
                'cliente_id' => $personaId,
                'codigo_cliente' => $codigoCliente,
                'tipo_documento' => $tipoDocumento,
                'documento_identidad' => $documento,
                'nombre_razon_social' => $nombre,
            ],
        ]);
    }

    // =========================================================================
    // 5. GUARDAR NUEVA COTIZACIÓN O ACTUALIZAR PENDIENTE (NO TOCA STOCK)
    // =========================================================================
    if ($accion === 'guardar_cotizacion') {
        $cotizacionId = intval($datos['cotizacion_id'] ?? 0);
        $clienteId = trim((string) ($datos['cliente_id'] ?? ''));
        $fechaVencimiento = trim((string) ($datos['fecha_vencimiento'] ?? ''));
        $monedaIdIn = intval($datos['moneda_id'] ?? 1);
        $observaciones = trim((string) ($datos['observaciones'] ?? ''));
        $partidasRaw = $datos['partidas'] ?? [];

        if ($clienteId === '') {
            throw new Exception('Debes seleccionar un cliente registrado (o crear uno rápido con "+ Cliente").');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaVencimiento)) {
            throw new Exception('Debes indicar una fecha de vencimiento válida (AAAA-MM-DD).');
        }
        if ($fechaVencimiento < date('Y-m-d')) {
            throw new Exception('La fecha de vencimiento no puede ser anterior al día de hoy.');
        }
        if (!is_array($partidasRaw) || empty($partidasRaw)) {
            throw new Exception('Agrega al menos un producto al carrito de la cotización.');
        }

        // Verificar que el cliente existe y está activo
        $stCli = $pdo->prepare("
            SELECT c.persona_id, p.nombre_razon_social
            FROM identidad.clientes c
            JOIN identidad.personas p ON p.persona_id = c.persona_id
            WHERE c.persona_id = ? AND p.estado = TRUE
            LIMIT 1
        ");
        $stCli->execute([$clienteId]);
        $cliRow = $stCli->fetch(PDO::FETCH_ASSOC);
        if (!$cliRow) {
            throw new Exception('El cliente seleccionado no existe o está inactivo.');
        }

        $moneda = obtenerMonedaCot($pdo, $monedaIdIn);
        $monedaId = intval($moneda['id']);
        $tasaCambio = floatval($moneda['tasa_cambio_referencia']);
        $eval = evaluarLineasCotizacion($pdo, $partidasRaw, $puedeDescuentoManual);

        $pdo->beginTransaction();

        $esEdicion = ($cotizacionId > 0);
        $datosAntes = null;

        if ($esEdicion) {
            $stOld = $pdo->prepare("
                SELECT id, numero_cotizacion, estado, monto_total, fecha_vencimiento
                FROM ventas.cotizaciones
                WHERE id = ?
                FOR UPDATE
            ");
            $stOld->execute([$cotizacionId]);
            $oldCot = $stOld->fetch(PDO::FETCH_ASSOC);

            if (!$oldCot) {
                throw new Exception('La cotización que intentas editar no existe.');
            }
            if (strtoupper((string) $oldCot['estado']) !== 'PENDIENTE') {
                throw new Exception("Solo se pueden editar cotizaciones en estado PENDIENTE (estado actual: {$oldCot['estado']}).");
            }

            $numeroCot = $oldCot['numero_cotizacion'];
            $datosAntes = $oldCot;

            $pdo->prepare("
                UPDATE ventas.cotizaciones
                SET cliente_id        = ?,
                    fecha_vencimiento = ?,
                    monto_subtotal    = ?,
                    monto_impuestos   = ?,
                    monto_total       = ?,
                    observaciones     = ?,
                    moneda_id         = ?,
                    tasa_cambio       = ?
                WHERE id = ?
            ")->execute([
                        $clienteId,
                        $fechaVencimiento,
                        $eval['monto_subtotal'],
                        $eval['monto_impuestos'],
                        $eval['monto_total'],
                        $observaciones !== '' ? $observaciones : null,
                        $monedaId,
                        $tasaCambio,
                        $cotizacionId,
                    ]);

            // Limpiar detalles e impuestos previos antes de reinsertar consolidados
            $pdo->prepare("DELETE FROM ventas.cotizacion_detalle_impuestos WHERE cotizacion_id = ?")->execute([$cotizacionId]);
            $pdo->prepare("DELETE FROM ventas.cotizaciones_detalle WHERE cotizacion_id = ?")->execute([$cotizacionId]);
        } else {
            $cotizacionId = intval($pdo->query("SELECT nextval('ventas.cotizaciones_id_seq')")->fetchColumn());
            $numeroCot = 'COT-' . date('Ym') . '-' . str_pad((string) $cotizacionId, 4, '0', STR_PAD_LEFT);

            $pdo->prepare("
                INSERT INTO ventas.cotizaciones (
                    id, numero_cotizacion, cliente_id, vendedor_id,
                    fecha_emision, fecha_vencimiento,
                    monto_subtotal, monto_impuestos, monto_total,
                    estado, factura_generada_id, observaciones, moneda_id, tasa_cambio
                ) VALUES (
                    ?, ?, ?, ?,
                    CURRENT_TIMESTAMP, ?,
                    ?, ?, ?,
                    'PENDIENTE', NULL, ?, ?, ?
                )
            ")->execute([
                        $cotizacionId,
                        $numeroCot,
                        $clienteId,
                        $vendedorId,
                        $fechaVencimiento,
                        $eval['monto_subtotal'],
                        $eval['monto_impuestos'],
                        $eval['monto_total'],
                        $observaciones !== '' ? $observaciones : null,
                        $monedaId,
                        $tasaCambio,
                    ]);
        }

        // Insertar líneas consolidadas e impuestos congelados
        $stInsDet = $pdo->prepare("
            INSERT INTO ventas.cotizaciones_detalle (
                cotizacion_id, producto_maestro_id, codigo_barra_id,
                cantidad, precio_unitario, monto_impuestos, monto_descuento, monto_total
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stInsImp = $pdo->prepare("
            INSERT INTO ventas.cotizacion_detalle_impuestos (
                cotizacion_id, producto_maestro_id, impuesto_id,
                base_imponible, tipo_calculo_aplicado, tasa_aplicada, monto_impuesto
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($eval['lineas'] as $ln) {
            $stInsDet->execute([
                $cotizacionId,
                $ln['producto_maestro_id'],
                $ln['codigo_barra_id'],
                $ln['cantidad'],
                $ln['precio_unitario'],
                $ln['monto_impuestos'],
                $ln['monto_descuento'],
                $ln['monto_total'],
            ]);

            foreach ($ln['impuestos_detalle'] as $di) {
                $stInsImp->execute([
                    $cotizacionId,
                    $ln['producto_maestro_id'],
                    $di['impuesto_id'],
                    $di['base_imponible'],
                    $di['tipo_calculo_aplicado'],
                    $di['tasa_aplicada'],
                    $di['monto_impuesto'],
                ]);
            }
        }

        registrarAuditoria(
            $pdo,
            $vendedorId,
            null,
            $esEdicion ? 'COTIZACION_EDITAR' : 'COTIZACION_CREAR',
            'ventas.cotizaciones',
            $datosAntes,
            [
                'cotizacion_id' => $cotizacionId,
                'numero_cotizacion' => $numeroCot,
                'cliente_nombre' => $cliRow['nombre_razon_social'],
                'fecha_vencimiento' => $fechaVencimiento,
                'monto_subtotal' => $eval['monto_subtotal'],
                'monto_impuestos' => $eval['monto_impuestos'],
                'monto_total' => $eval['monto_total'],
                'total_lineas' => count($eval['lineas']),
            ]
        );

        $pdo->commit();

        responderCot(true, ($esEdicion ? "Cotización {$numeroCot} actualizada." : "Cotización {$numeroCot} creada correctamente (inventario intacto)."), [
            'cotizacion_id' => $cotizacionId,
            'numero_cotizacion' => $numeroCot,
        ]);
    }

    // =========================================================================
    // 6. DETALLE COMPLETO (PARA VER, IMPRIMIR, EDITAR Y CHEQUEO DE STOCK VIVO)
    // =========================================================================
    if ($accion === 'detalle_cotizacion') {
        $cotizacionId = intval($datos['cotizacion_id'] ?? 0);
        if ($cotizacionId <= 0) {
            responderCot(false, 'Identificador de cotización inválido.');
        }

        $stCab = $pdo->prepare("
            SELECT cot.id, cot.numero_cotizacion, cot.cliente_id, cot.estado,
                   cot.fecha_vencimiento, cot.observaciones,
                   cot.monto_subtotal, cot.monto_impuestos, cot.monto_total,
                   cot.moneda_id, cot.tasa_cambio, cot.factura_generada_id,
                   TO_CHAR(cot.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_emision_fmt,
                   TO_CHAR(cot.fecha_vencimiento, 'DD/MM/YYYY')        AS fecha_vencimiento_fmt,
                   m.codigo_iso, m.simbolo,
                   p.nombre_razon_social AS cliente_nombre,
                   p.tipo_documento      AS cliente_tipo_doc,
                   p.documento_identidad AS cliente_doc,
                   COALESCE(p.telefono_principal, '') AS cliente_telefono,
                   COALESCE(p.email, '')              AS cliente_email,
                   COALESCE(NULLIF(TRIM(pv.nombre_razon_social), ''), uv.username) AS vendedor_nombre,
                   f.numero_factura AS factura_generada_numero
            FROM ventas.cotizaciones cot
            JOIN identidad.clientes c        ON c.persona_id = cot.cliente_id
            JOIN identidad.personas p        ON p.persona_id = c.persona_id
            LEFT JOIN tesoreria.monedas m    ON m.id = cot.moneda_id
            LEFT JOIN seguridad.usuarios uv  ON uv.usuario_id = cot.vendedor_id
            LEFT JOIN identidad.personas pv  ON pv.persona_id = uv.persona_id
            LEFT JOIN ventas.facturas f      ON f.id = cot.factura_generada_id
            WHERE cot.id = ?
            LIMIT 1
        ");
        $stCab->execute([$cotizacionId]);
        $cab = $stCab->fetch(PDO::FETCH_ASSOC);

        if (!$cab) {
            responderCot(false, 'Cotización no encontrada.', [], 404);
        }

        $stDet = $pdo->prepare("
            SELECT cd.producto_maestro_id, cd.codigo_barra_id, cd.cantidad,
                   cd.precio_unitario, cd.monto_descuento, cd.monto_impuestos, cd.monto_total,
                   pm.sku,
                   COALESCE(NULLIF(TRIM(pm.nombre), ''), pcb.descripcion_presentacion, pm.sku) AS producto_nombre,
                   COALESCE(pcb.descripcion_presentacion, 'Unidad Base (x1)') AS descripcion_presentacion,
                   COALESCE(pcb.factor_multiplicador, 1) AS factor_conversion,
                   COALESCE((
                       SELECT SUM(s.stock_fisico_unidades - s.stock_comprometido)
                       FROM inventario.inventario_stock s
                       WHERE s.producto_maestro_id = cd.producto_maestro_id
                   ), 0) AS stock_disponible_actual
            FROM ventas.cotizaciones_detalle cd
            JOIN inventario.productos_maestro pm ON pm.id = cd.producto_maestro_id
            LEFT JOIN inventario.producto_codigos_barra pcb ON pcb.id = cd.codigo_barra_id
            WHERE cd.cotizacion_id = ?
            ORDER BY pm.sku ASC
        ");
        $stDet->execute([$cotizacionId]);
        $detalles = $stDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stImp = $pdo->prepare("
            SELECT cdi.producto_maestro_id, i.siglas, i.nombre, cdi.tasa_aplicada, cdi.monto_impuesto
            FROM ventas.cotizacion_detalle_impuestos cdi
            JOIN tesoreria.impuestos i ON i.id = cdi.impuesto_id
            WHERE cdi.cotizacion_id = ?
            ORDER BY i.orden_calculo ASC, i.id ASC
        ");
        $stImp->execute([$cotizacionId]);
        $impRows = $stImp->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $impPorProd = [];
        $resumenImp = [];
        foreach ($impRows as $ir) {
            $pm = intval($ir['producto_maestro_id']);
            $impPorProd[$pm][] = $ir;
            $sig = $ir['siglas'];
            if (!isset($resumenImp[$sig])) {
                $resumenImp[$sig] = ['siglas' => $sig, 'nombre' => $ir['nombre'], 'monto' => 0.0];
            }
            $resumenImp[$sig]['monto'] = round($resumenImp[$sig]['monto'] + floatval($ir['monto_impuesto']), 2);
        }

        $alertasStock = [];
        foreach ($detalles as &$d) {
            $pmId = intval($d['producto_maestro_id']);
            $udsReq = intval($d['cantidad']) * max(1, intval($d['factor_conversion']));
            $disp = intval($d['stock_disponible_actual']);
            $d['unidades_requeridas'] = $udsReq;
            $d['stock_suficiente'] = ($disp >= $udsReq);
            $d['impuestos_detalle'] = $impPorProd[$pmId] ?? [];
            if (!$d['stock_suficiente']) {
                $alertasStock[] = "{$d['sku']} ({$d['producto_nombre']}): disponible {$disp} uds., cotizado {$udsReq} uds.";
            }
        }
        unset($d);

        responderCot(true, 'OK', [
            'cabecera' => $cab,
            'detalles' => $detalles,
            'resumen_impuestos' => array_values($resumenImp),
            'alertas_stock' => $alertasStock,
        ]);
    }

    // =========================================================================
    // 7. CANCELAR COTIZACIÓN PENDIENTE (FASE 4 — BAJA LÓGICA AUDITADA)
    // =========================================================================
    if ($accion === 'cancelar_cotizacion') {
        if (!$puedeCancelarCot) {
            responderCot(false, 'No posees el permiso CANCELAR_COTIZACION para anular presupuestos.', [], 403);
        }

        $cotizacionId = intval($datos['cotizacion_id'] ?? 0);
        $motivo = trim((string) ($datos['motivo'] ?? ''));

        if ($cotizacionId <= 0) {
            throw new Exception('Identificador de cotización inválido.');
        }
        if (mb_strlen($motivo, 'UTF-8') < 4) {
            throw new Exception('Indica brevemente el motivo de cancelación (mínimo 4 caracteres).');
        }

        $pdo->beginTransaction();

        $st = $pdo->prepare("
            SELECT id, numero_cotizacion, estado, observaciones, monto_total
            FROM ventas.cotizaciones
            WHERE id = ?
            FOR UPDATE
        ");
        $st->execute([$cotizacionId]);
        $cot = $st->fetch(PDO::FETCH_ASSOC);

        if (!$cot) {
            throw new Exception('Cotización no encontrada.');
        }
        if (strtoupper((string) $cot['estado']) !== 'PENDIENTE') {
            throw new Exception("Solo se pueden cancelar cotizaciones en estado PENDIENTE (actual: {$cot['estado']}).");
        }

        $obsFinal = trim((string) ($cot['observaciones'] ?? ''));
        $obsFinal = ($obsFinal !== '' ? $obsFinal . ' | ' : '') . 'Cancelada: ' . $motivo;

        $pdo->prepare("
            UPDATE ventas.cotizaciones
            SET estado = 'CANCELADA', observaciones = ?
            WHERE id = ?
        ")->execute([$obsFinal, $cotizacionId]);

        registrarAuditoria(
            $pdo,
            $vendedorId,
            null,
            'COTIZACION_CANCELAR',
            'ventas.cotizaciones',
            ['estado' => 'PENDIENTE', 'numero_cotizacion' => $cot['numero_cotizacion']],
            ['estado' => 'CANCELADA', 'numero_cotizacion' => $cot['numero_cotizacion'], 'motivo' => $motivo]
        );

        $pdo->commit();

        responderCot(true, "Cotización {$cot['numero_cotizacion']} marcada como CANCELADA.");
    }

    responderCot(false, 'Acción no reconocida.');
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderCot(false, $e->getMessage(), [], 400);
}