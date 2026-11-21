<?php
/**
 * ============================================================================
 * Procesador de Movimientos de Inventario y Kardex (Backend Controller)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: inventario.movimientos_inventario, inventario.movimiento_inventario_detalles,
 *         inventario.inventario_stock, inventario.lotes, inventario.productos_maestro,
 *         inventario.producto_codigos_barra, seguridad.auditoria
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

header('Content-Type: application/json; charset=utf-8');

function responderJson(string $status, string $mensaje, array $data = [], int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode(array_merge(['status' => $status, 'message' => $mensaje], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$pdo) {
    responderJson('error', 'No hay conexión activa con PostgreSQL.', [], 500);
}

$usuarioSesionId = requerirSesionActiva(true);
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ============================================================================
// CONSULTAS GET EN VIVO (STOCK/LOTES, CÓDIGO DE BARRAS Y DETALLE DE KARDEX)
// ============================================================================
if ($metodo === 'GET') {
    $accion = $_GET['accion'] ?? '';

    try {
        // 1. Obtener presentaciones y lotes con stock disponible para un producto en un almacén
        if ($accion === 'stock_lotes') {
            $productoId = intval($_GET['producto_id'] ?? 0);
            $almacenId = intval($_GET['almacen_id'] ?? 0);

            if ($productoId <= 0 || $almacenId <= 0) {
                responderJson('error', 'Debes seleccionar un almacén y un producto válidos.', [], 400);
            }

            $stmtProd = $pdo->prepare("
                SELECT pm.id, pm.sku, pm.requiere_lote, pm.costo_promedio, pm.precio_base_unidad,
                       COALESCE(
                           NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                           (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                           pm.sku
                       ) AS nombre
                FROM inventario.productos_maestro pm
                WHERE pm.id = ? AND pm.id > 0
                LIMIT 1
            ");
            $stmtProd->execute([$productoId]);
            $producto = $stmtProd->fetch(PDO::FETCH_ASSOC);

            if (!$producto) {
                responderJson('error', 'Producto no encontrado.', [], 404);
            }

            $requiereLote = ($producto['requiere_lote'] === true || $producto['requiere_lote'] === 't' || $producto['requiere_lote'] === 1);
            $producto['requiere_lote'] = $requiereLote;

            // Presentaciones / Códigos de barra
            $stmtPres = $pdo->prepare("
                SELECT codigo_barra, descripcion_presentacion, factor_multiplicador
                FROM inventario.producto_codigos_barra
                WHERE producto_maestro_id = ?
                ORDER BY factor_multiplicador ASC, descripcion_presentacion ASC
            ");
            $stmtPres->execute([$productoId]);
            $presentaciones = $stmtPres->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Lotes y stock en el almacén seleccionado (ordenados FEFO)
            if ($requiereLote) {
                $stmtLotes = $pdo->prepare("
                    SELECT l.id,
                           l.numero_lote,
                           l.fecha_vencimiento,
                           l.cantidad_actual AS cantidad_global,
                           COALESCE(s.stock_fisico_unidades, 0) AS stock_fisico,
                           COALESCE(s.stock_comprometido, 0) AS stock_comprometido,
                           (COALESCE(s.stock_fisico_unidades, 0) - COALESCE(s.stock_comprometido, 0)) AS stock_disponible
                    FROM inventario.lotes l
                    LEFT JOIN inventario.inventario_stock s
                           ON s.almacen_id = ?
                          AND s.producto_maestro_id = ?
                          AND s.lote_id = l.id
                    WHERE l.producto_maestro_id = ? AND l.id > 0
                    ORDER BY l.fecha_vencimiento ASC NULLS LAST, l.id ASC
                ");
                $stmtLotes->execute([$almacenId, $productoId, $productoId]);
                $lotes = $stmtLotes->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $stmtComodin = $pdo->prepare("
                    SELECT 0 AS id,
                           'SIN LOTE (COMODÍN #0)' AS numero_lote,
                           NULL::date AS fecha_vencimiento,
                           COALESCE(s.stock_fisico_unidades, 0) AS cantidad_global,
                           COALESCE(s.stock_fisico_unidades, 0) AS stock_fisico,
                           COALESCE(s.stock_comprometido, 0) AS stock_comprometido,
                           (COALESCE(s.stock_fisico_unidades, 0) - COALESCE(s.stock_comprometido, 0)) AS stock_disponible
                    FROM (SELECT 1) dummy
                    LEFT JOIN inventario.inventario_stock s
                           ON s.almacen_id = ?
                          AND s.producto_maestro_id = ?
                          AND s.lote_id = 0
                ");
                $stmtComodin->execute([$almacenId, $productoId]);
                $lotes = $stmtComodin->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            responderJson('success', 'OK', [
                'producto' => $producto,
                'presentaciones' => $presentaciones,
                'lotes' => $lotes,
            ]);
        }

        // 2. Búsqueda rápida por código de barras o SKU
        if ($accion === 'buscar_barcode') {
            $codigo = trim($_GET['codigo'] ?? '');
            if ($codigo === '') {
                responderJson('error', 'Ingresa o escanea un código de barras o SKU.', [], 400);
            }

            $stmtBc = $pdo->prepare("
                SELECT cb.producto_maestro_id,
                       cb.codigo_barra,
                       cb.descripcion_presentacion,
                       cb.factor_multiplicador
                FROM inventario.producto_codigos_barra cb
                JOIN inventario.productos_maestro pm ON pm.id = cb.producto_maestro_id
                WHERE UPPER(TRIM(cb.codigo_barra)) = UPPER(TRIM(?))
                  AND pm.id > 0
                LIMIT 1
            ");
            $stmtBc->execute([$codigo]);
            $match = $stmtBc->fetch(PDO::FETCH_ASSOC);

            if (!$match) {
                // Fallback por SKU maestro
                $stmtSku = $pdo->prepare("
                    SELECT id AS producto_maestro_id, sku
                    FROM inventario.productos_maestro
                    WHERE UPPER(TRIM(sku)) = UPPER(TRIM(?)) AND id > 0
                    LIMIT 1
                ");
                $stmtSku->execute([$codigo]);
                $skuRow = $stmtSku->fetch(PDO::FETCH_ASSOC);
                if (!$skuRow) {
                    responderJson('error', "No se encontró ningún producto ni presentación con el código '{$codigo}'.", [], 404);
                }
                $match = [
                    'producto_maestro_id' => intval($skuRow['producto_maestro_id']),
                    'codigo_barra' => '',
                    'descripcion_presentacion' => 'Unidad base (x1)',
                    'factor_multiplicador' => 1,
                ];
            }

            responderJson('success', 'Producto localizado.', ['match' => $match]);
        }

        if ($accion === 'buscar_catalogo') {
            $almacenId = intval($_GET['almacen_id'] ?? 0);
            $q = trim((string) ($_GET['q'] ?? ''));
            $categoriaId = intval($_GET['categoria_id'] ?? 0);
            $marcaId = intval($_GET['marca_id'] ?? 0);
            $soloConStock = ($_GET['solo_con_stock'] ?? '0') === '1';

            if ($almacenId <= 0) {
                responderJson('error', 'Primero selecciona el almacén físico en el Paso 1.', [], 400);
            }

            $params = [$almacenId];
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
                    pm.sku ILIKE ?
                    OR pm.nombre ILIKE ?
                    OR c.nombre ILIKE ?
                    OR m.nombre ILIKE ?
                    OR EXISTS (
                        SELECT 1
                        FROM inventario.producto_codigos_barra cb_f
                        WHERE cb_f.producto_maestro_id = pm.id
                          AND (cb_f.codigo_barra ILIKE ? OR cb_f.descripcion_presentacion ILIKE ?)
                    )
                )";
                array_push($params, $like, $like, $like, $like, $like, $like);
            }

            $filtroStockSql = $soloConStock ? "WHERE base.stock_disponible > 0" : "";

            $sql = "
                WITH base AS (
                    SELECT pm.id,
                           pm.sku,
                           pm.requiere_lote,
                           pm.costo_promedio,
                           COALESCE(
                               NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                               (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                               pm.sku
                           ) AS nombre,
                           COALESCE(c.nombre, 'Sin categoría') AS categoria_nombre,
                           COALESCE(m.nombre, 'Sin marca') AS marca_nombre,
                           COALESCE((
                               SELECT SUM(s.stock_fisico_unidades - s.stock_comprometido)
                               FROM inventario.inventario_stock s
                               WHERE s.almacen_id = ? AND s.producto_maestro_id = pm.id
                           ), 0) AS stock_disponible,
                           COALESCE((
                               SELECT JSON_AGG(
                                   JSON_BUILD_OBJECT(
                                       'codigo_barra', cb.codigo_barra,
                                       'descripcion_presentacion', cb.descripcion_presentacion,
                                       'factor_multiplicador', cb.factor_multiplicador,
                                       'precio_venta_presentacion', cb.precio_venta_presentacion
                                   )
                                   ORDER BY cb.factor_multiplicador ASC, cb.descripcion_presentacion ASC
                               )
                               FROM inventario.producto_codigos_barra cb
                               WHERE cb.producto_maestro_id = pm.id
                           ), '[]'::json) AS presentaciones_json
                    FROM inventario.productos_maestro pm
                    LEFT JOIN inventario.categoria_producto c ON c.id = pm.categoria_id
                    LEFT JOIN inventario.marca m ON m.id = pm.marca_id
                    WHERE " . implode(' AND ', $where) . "
                )
                SELECT *
                FROM base
                {$filtroStockSql}
                ORDER BY base.nombre ASC, base.sku ASC
                LIMIT 100
            ";

            $stmtCat = $pdo->prepare($sql);
            $stmtCat->execute($params);
            $rows = $stmtCat->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as &$r) {
                $r['requiere_lote'] = ($r['requiere_lote'] === true || $r['requiere_lote'] === 't' || $r['requiere_lote'] == 1);
                $r['stock_disponible'] = intval($r['stock_disponible']);
                $r['presentaciones'] = json_decode($r['presentaciones_json'] ?? '[]', true) ?: [];
                unset($r['presentaciones_json']);
            }
            unset($r);

            responderJson('success', 'OK', ['items' => $rows]);
        }

        // 3. Ver detalle completo de un movimiento del Kardex
        if ($accion === 'detalle') {
            $movId = intval($_GET['id'] ?? 0);
            if ($movId <= 0) {
                responderJson('error', 'ID de movimiento inválido.', [], 400);
            }

            $colsMov = obtenerColumnasTabla($pdo, 'inventario', 'movimientos_inventario');
            $tieneAlmacen = isset($colsMov['almacen_id']);
            $tieneUsuario = isset($colsMov['usuario_id']);

            $selectAlmacen = $tieneAlmacen ? "a.nombre AS almacen_nombre," : "NULL::text AS almacen_nombre,";
            $joinAlmacen = $tieneAlmacen ? "LEFT JOIN inventario.almacenes a ON a.id = m.almacen_id" : "";
            $selectUsuario = $tieneUsuario ? "COALESCE(NULLIF(TRIM(pu.nombre_razon_social), ''), uu.username) AS usuario_nombre," : "NULL::text AS usuario_nombre,";
            $joinUsuario = $tieneUsuario ? "LEFT JOIN seguridad.usuarios uu ON uu.usuario_id = m.usuario_id LEFT JOIN identidad.personas pu ON pu.persona_id = uu.persona_id" : "";

            $stmtCab = $pdo->prepare("
                SELECT m.id,
                       m.tipo_movimiento,
                       m.justificacion,
                       m.documento_referencia_id,
                       m.fecha_movimiento,
                       m.supervisor_id,
                       {$selectAlmacen}
                       {$selectUsuario}
                       COALESCE(NULLIF(TRIM(ps.nombre_razon_social), ''), us.username) AS supervisor_nombre
                FROM inventario.movimientos_inventario m
                {$joinAlmacen}
                {$joinUsuario}
                LEFT JOIN seguridad.usuarios us ON us.usuario_id = m.supervisor_id
                LEFT JOIN identidad.personas ps ON ps.persona_id = us.persona_id
                WHERE m.id = ?
                LIMIT 1
            ");
            $stmtCab->execute([$movId]);
            $cabecera = $stmtCab->fetch(PDO::FETCH_ASSOC);

            if (!$cabecera) {
                responderJson('error', 'Movimiento no encontrado.', [], 404);
            }

            $stmtDet = $pdo->prepare("
                SELECT d.producto_maestro_id,
                       d.lote_id,
                       d.cantidad_unidades,
                       d.costo_unitario_referencia,
                       (d.cantidad_unidades * d.costo_unitario_referencia) AS subtotal_costo,
                       pm.sku,
                       pm.requiere_lote,
                       COALESCE(
                           NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                           (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                           pm.sku
                       ) AS producto_nombre,
                       l.numero_lote,
                       l.fecha_vencimiento
                FROM inventario.movimiento_inventario_detalles d
                JOIN inventario.productos_maestro pm ON pm.id = d.producto_maestro_id
                LEFT JOIN inventario.lotes l ON l.id = d.lote_id
                WHERE d.movimiento_id = ?
                ORDER BY pm.sku ASC, d.lote_id ASC
            ");
            $stmtDet->execute([$movId]);
            $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

            responderJson('success', 'OK', [
                'cabecera' => $cabecera,
                'detalles' => $detalles,
            ]);
        }

        responderJson('error', 'Acción GET no reconocida.', [], 400);
    } catch (Throwable $e) {
        responderJson('error', $e->getMessage(), [], 500);
    }
}

// ============================================================================
// REGISTRO TRANSACCIONAL POST (AJUSTE_ENTRADA, AJUSTE_SALIDA, MERMA_ROTURA)
// ============================================================================
if ($metodo !== 'POST') {
    responderJson('error', 'Método HTTP no permitido.', [], 405);
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$accion = $payload['accion'] ?? '';

if ($accion !== 'registrar_ajuste') {
    responderJson('error', 'Acción POST no válida.', [], 400);
}

try {
    $tipoMovimiento = strtoupper(trim((string) ($payload['tipo_movimiento'] ?? '')));
    $almacenId = intval($payload['almacen_id'] ?? 0);
    $justificacion = trim((string) ($payload['justificacion'] ?? ''));
    $supervisorIdIn = trim((string) ($payload['supervisor_id'] ?? ''));
    $pinIngresado = trim((string) ($payload['pin_autorizacion'] ?? ''));
    $lineasRaw = $payload['lineas'] ?? [];

    $tiposPermitidos = ['AJUSTE_ENTRADA', 'AJUSTE_SALIDA', 'MERMA_ROTURA'];
    if (!in_array($tipoMovimiento, $tiposPermitidos, true)) {
        throw new Exception("Tipo de movimiento manual no permitido: '{$tipoMovimiento}'.");
    }
    if ($almacenId <= 0) {
        throw new Exception("Debes seleccionar un almacén físico.");
    }
    if (mb_strlen($justificacion, 'UTF-8') < 5) {
        throw new Exception("La justificación es obligatoria y debe tener al menos 5 caracteres.");
    }
    if (!is_array($lineasRaw) || count($lineasRaw) === 0) {
        throw new Exception("Debes agregar al menos un producto al carrito del movimiento.");
    }

    // Verificar existencia del almacén
    $stmtAlm = $pdo->prepare("SELECT id, nombre FROM inventario.almacenes WHERE id = ? LIMIT 1");
    $stmtAlm->execute([$almacenId]);
    $almacen = $stmtAlm->fetch(PDO::FETCH_ASSOC);
    if (!$almacen) {
        throw new Exception("El almacén seleccionado no existe.");
    }

    // Validación obligatoria de Supervisor + PIN para salidas y mermas
    $esSalida = in_array($tipoMovimiento, ['AJUSTE_SALIDA', 'MERMA_ROTURA'], true);
    $supervisorId = null;
    $supInfo = null;

    if ($esSalida) {
        $supInfo = validarPinSupervisor($pdo, $supervisorIdIn, $pinIngresado);
        $supervisorId = $supInfo['usuario_id'];
    } elseif ($supervisorIdIn !== '' && $pinIngresado !== '') {
        // Si en AJUSTE_ENTRADA enviaron PIN opcional, validarlo también
        $supInfo = validarPinSupervisor($pdo, $supervisorIdIn, $pinIngresado);
        $supervisorId = $supInfo['usuario_id'];
    }

    $pdo->beginTransaction();

    // Confirmar existencia del lote comodín id = 0 (para productos sin caducidad)
    $loteComodinExiste = null;

    // ------------------------------------------------------------------------
    // FASE 1: Resolver cada línea (producto, multiplicador, costo, lote) y
    //         consolidar por clave primaria compuesta (producto_maestro_id, lote_id)
    // ------------------------------------------------------------------------
    $consolidadas = [];

    $stmtProdInfo = $pdo->prepare("
        SELECT id, sku, requiere_lote, costo_promedio,
               COALESCE(NULLIF(TRIM(nombre), ''), sku) AS nombre
        FROM inventario.productos_maestro
        WHERE id = ? AND id > 0
        LIMIT 1
    ");
    $stmtFactorBc = $pdo->prepare("
        SELECT factor_multiplicador
        FROM inventario.producto_codigos_barra
        WHERE producto_maestro_id = ? AND codigo_barra = ?
        LIMIT 1
    ");
    $stmtLoteById = $pdo->prepare("
        SELECT id, producto_maestro_id, numero_lote, fecha_vencimiento
        FROM inventario.lotes
        WHERE id = ?
        LIMIT 1
    ");
    $stmtLoteByNum = $pdo->prepare("
        SELECT id, numero_lote, fecha_vencimiento
        FROM inventario.lotes
        WHERE producto_maestro_id = ? AND UPPER(TRIM(numero_lote)) = UPPER(TRIM(?))
        LIMIT 1
    ");
    $stmtCrearLote = $pdo->prepare("
        INSERT INTO inventario.lotes (producto_maestro_id, numero_lote, fecha_vencimiento, cantidad_actual)
        VALUES (?, ?, ?, 0)
        RETURNING id
    ");

    foreach ($lineasRaw as $idx => $ln) {
        $numLinea = $idx + 1;
        $productoId = intval($ln['producto_maestro_id'] ?? 0);
        $cantPres = intval($ln['cantidad_presentacion'] ?? 0);
        $codigoBarra = trim((string) ($ln['codigo_barra'] ?? ''));
        $modoLote = strtolower(trim((string) ($ln['modo_lote'] ?? 'existente')));

        if ($productoId <= 0 || $cantPres <= 0) {
            throw new Exception("Línea #{$numLinea}: producto o cantidad inválida.");
        }

        $stmtProdInfo->execute([$productoId]);
        $prod = $stmtProdInfo->fetch(PDO::FETCH_ASSOC);
        if (!$prod) {
            throw new Exception("Línea #{$numLinea}: el producto #{$productoId} no existe.");
        }

        // Factor multiplicador verificado en BD si viene de código de barras
        $factor = 1;
        if ($codigoBarra !== '') {
            $stmtFactorBc->execute([$productoId, $codigoBarra]);
            $factorDb = $stmtFactorBc->fetchColumn();
            if ($factorDb === false) {
                throw new Exception("Línea #{$numLinea}: la presentación '{$codigoBarra}' no pertenece al producto {$prod['sku']}.");
            }
            $factor = max(1, intval($factorDb));
        }

        $cantidadUnidades = $cantPres * $factor;
        $requiereLote = ($prod['requiere_lote'] === true || $prod['requiere_lote'] === 't' || $prod['requiere_lote'] === 1);
        $costoRef = round(floatval($prod['costo_promedio'] ?? 0), 2);

        // Resolución del lote (FEFO/existente, creación al vuelo o comodín 0)
        $loteId = 0;
        $numeroLoteTexto = 'COMODIN-0';
        $fechaVencTexto = null;

        if (!$requiereLote) {
            if ($loteComodinExiste === null) {
                $loteComodinExiste = (bool) $pdo->query("SELECT 1 FROM inventario.lotes WHERE id = 0 LIMIT 1")->fetchColumn();
            }
            if (!$loteComodinExiste) {
                throw new Exception("No existe el Lote Comodín (id = 0) en inventario.lotes requerido para productos sin vencimiento.");
            }
            $loteId = 0;
        } else {
            if (!$esSalida && $modoLote === 'nuevo') {
                $numLoteNuevo = strtoupper(trim((string) ($ln['nuevo_numero_lote'] ?? '')));
                $fechaVencNueva = trim((string) ($ln['nueva_fecha_vencimiento'] ?? ''));

                if ($numLoteNuevo === '' || mb_strlen($numLoteNuevo, 'UTF-8') > 50) {
                    throw new Exception("Línea #{$numLinea} ({$prod['sku']}): debes indicar un número de lote válido (máx. 50 caracteres).");
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaVencNueva)) {
                    throw new Exception("Línea #{$numLinea} ({$prod['sku']}): debes indicar una fecha de vencimiento válida (AAAA-MM-DD).");
                }

                // Verificar si el lote ya existe para evitar pisar fecha de vencimiento por accidente
                $stmtLoteByNum->execute([$productoId, $numLoteNuevo]);
                $loteDup = $stmtLoteByNum->fetch(PDO::FETCH_ASSOC);
                if ($loteDup) {
                    if ((string) $loteDup['fecha_vencimiento'] !== $fechaVencNueva) {
                        throw new Exception(
                            "Línea #{$numLinea} ({$prod['sku']}): el lote '{$numLoteNuevo}' ya existe con vencimiento {$loteDup['fecha_vencimiento']}. " .
                            "Selecciónalo como lote existente o usa un código de lote distinto."
                        );
                    }
                    $loteId = intval($loteDup['id']);
                    $numeroLoteTexto = $loteDup['numero_lote'];
                    $fechaVencTexto = $loteDup['fecha_vencimiento'];
                } else {
                    $stmtCrearLote->execute([$productoId, $numLoteNuevo, $fechaVencNueva]);
                    $loteId = intval($stmtCrearLote->fetchColumn());
                    $numeroLoteTexto = $numLoteNuevo;
                    $fechaVencTexto = $fechaVencNueva;
                }
            } else {
                $loteId = intval($ln['lote_id'] ?? 0);
                if ($loteId <= 0) {
                    throw new Exception("Línea #{$numLinea} ({$prod['sku']}): este producto controla vencimiento y requiere un lote válido.");
                }
                $stmtLoteById->execute([$loteId]);
                $loteRow = $stmtLoteById->fetch(PDO::FETCH_ASSOC);
                if (!$loteRow || intval($loteRow['producto_maestro_id']) !== $productoId) {
                    throw new Exception("Línea #{$numLinea} ({$prod['sku']}): el lote #{$loteId} no pertenece a este producto.");
                }
                $numeroLoteTexto = $loteRow['numero_lote'];
                $fechaVencTexto = $loteRow['fecha_vencimiento'];
            }
        }

        $clave = "{$productoId}_{$loteId}";
        if (isset($consolidadas[$clave])) {
            $consolidadas[$clave]['cantidad_unidades'] += $cantidadUnidades;
        } else {
            $consolidadas[$clave] = [
                'producto_maestro_id' => $productoId,
                'sku' => $prod['sku'],
                'nombre' => $prod['nombre'],
                'lote_id' => $loteId,
                'numero_lote' => $numeroLoteTexto,
                'fecha_vencimiento' => $fechaVencTexto,
                'cantidad_unidades' => $cantidadUnidades,
                'costo_unitario_referencia' => $costoRef,
            ];
        }
    }

    // Orden determinista para evitar deadlocks al adquirir bloqueos FOR UPDATE
    uasort($consolidadas, function ($a, $b) {
        return ($a['producto_maestro_id'] <=> $b['producto_maestro_id'])
            ?: ($a['lote_id'] <=> $b['lote_id']);
    });

    // ------------------------------------------------------------------------
    // FASE 2: Insertar Cabecera en inventario.movimientos_inventario
    // ------------------------------------------------------------------------
    $colsMov = obtenerColumnasTabla($pdo, 'inventario', 'movimientos_inventario');
    $camposCab = ['tipo_movimiento', 'justificacion', 'supervisor_id', 'documento_referencia_id'];
    $valoresCab = [$tipoMovimiento, $justificacion, $supervisorId, null];

    if (isset($colsMov['almacen_id'])) {
        $camposCab[] = 'almacen_id';
        $valoresCab[] = $almacenId;
    }
    if (isset($colsMov['usuario_id'])) {
        $camposCab[] = 'usuario_id';
        $valoresCab[] = $usuarioSesionId;
    }

    $phCab = implode(', ', array_fill(0, count($camposCab), '?'));
    $stmtInsCab = $pdo->prepare("
        INSERT INTO inventario.movimientos_inventario (" . implode(', ', $camposCab) . ")
        VALUES ({$phCab})
        RETURNING id
    ");
    $stmtInsCab->execute($valoresCab);
    $movimientoId = intval($stmtInsCab->fetchColumn());

    // ------------------------------------------------------------------------
    // FASE 3: Insertar Detalles y Sincronizar Lotes + Stock con FOR UPDATE
    // ------------------------------------------------------------------------
    $stmtInsDet = $pdo->prepare("
        INSERT INTO inventario.movimiento_inventario_detalles
            (movimiento_id, producto_maestro_id, lote_id, cantidad_unidades, costo_unitario_referencia)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmtLockLote = $pdo->prepare("
        SELECT cantidad_actual
        FROM inventario.lotes
        WHERE id = ?
        FOR UPDATE
    ");
    $stmtLockStock = $pdo->prepare("
        SELECT stock_fisico_unidades, stock_comprometido
        FROM inventario.inventario_stock
        WHERE almacen_id = ? AND producto_maestro_id = ? AND lote_id = ?
        FOR UPDATE
    ");
    $stmtUpsertStockEntrada = $pdo->prepare("
        INSERT INTO inventario.inventario_stock
            (almacen_id, producto_maestro_id, lote_id, stock_fisico_unidades, stock_comprometido)
        VALUES (?, ?, ?, ?, 0)
        ON CONFLICT (almacen_id, producto_maestro_id, lote_id)
        DO UPDATE SET stock_fisico_unidades = inventario.inventario_stock.stock_fisico_unidades + EXCLUDED.stock_fisico_unidades
    ");
    $stmtRestarStock = $pdo->prepare("
        UPDATE inventario.inventario_stock
        SET stock_fisico_unidades = stock_fisico_unidades - ?
        WHERE almacen_id = ? AND producto_maestro_id = ? AND lote_id = ?
    ");
    $stmtSumarLote = $pdo->prepare("
        UPDATE inventario.lotes
        SET cantidad_actual = cantidad_actual + ?
        WHERE id = ?
    ");
    $stmtRestarLote = $pdo->prepare("
        UPDATE inventario.lotes
        SET cantidad_actual = cantidad_actual - ?
        WHERE id = ?
    ");
    $stmtRestarLoteComodin = $pdo->prepare("
        UPDATE inventario.lotes
        SET cantidad_actual = GREATEST(0, cantidad_actual - ?)
        WHERE id = 0
    ");

    $auditAnterior = [];
    $auditNuevo = [];

    foreach ($consolidadas as $item) {
        $prodId = $item['producto_maestro_id'];
        $loteId = $item['lote_id'];
        $qty = $item['cantidad_unidades'];

        // Bloquear fila de lote y fila de stock del almacén
        $stmtLockLote->execute([$loteId]);
        $loteAntes = intval($stmtLockLote->fetchColumn());

        $stmtLockStock->execute([$almacenId, $prodId, $loteId]);
        $stockRow = $stmtLockStock->fetch(PDO::FETCH_ASSOC);
        $fisicoAntes = $stockRow ? intval($stockRow['stock_fisico_unidades']) : 0;
        $comprometidoAnt = $stockRow ? intval($stockRow['stock_comprometido']) : 0;
        $disponibleAntes = $fisicoAntes - $comprometidoAnt;

        if ($esSalida) {
            if (!$stockRow || $disponibleAntes < $qty) {
                throw new Exception(
                    "Stock disponible insuficiente para {$item['sku']} (Lote {$item['numero_lote']}) en {$almacen['nombre']}: " .
                    "disponible {$disponibleAntes} uds. (físico {$fisicoAntes}, comprometido {$comprometidoAnt}), solicitado {$qty} uds."
                );
            }
            if ($loteId > 0 && $loteAntes < $qty) {
                throw new Exception(
                    "Existencia insuficiente en el lote {$item['numero_lote']} de {$item['sku']}: " .
                    "saldo en lote {$loteAntes} uds., solicitado {$qty} uds."
                );
            }
        }

        // Asentar línea en el Kardex
        $stmtInsDet->execute([
            $movimientoId,
            $prodId,
            $loteId,
            $qty,
            $item['costo_unitario_referencia'],
        ]);

        // Impactar saldos en lotes y stock por almacén
        if (!$esSalida) {
            $stmtSumarLote->execute([$qty, $loteId]);
            $stmtUpsertStockEntrada->execute([$almacenId, $prodId, $loteId, $qty]);
            $fisicoDespues = $fisicoAntes + $qty;
            $loteDespues = $loteAntes + $qty;
        } else {
            if ($loteId > 0) {
                $stmtRestarLote->execute([$qty, $loteId]);
                $loteDespues = $loteAntes - $qty;
            } else {
                $stmtRestarLoteComodin->execute([$qty]);
                $loteDespues = max(0, $loteAntes - $qty);
            }
            $stmtRestarStock->execute([$qty, $almacenId, $prodId, $loteId]);
            $fisicoDespues = $fisicoAntes - $qty;
        }

        $auditAnterior[] = [
            'producto_maestro_id' => $prodId,
            'sku' => $item['sku'],
            'lote_id' => $loteId,
            'numero_lote' => $item['numero_lote'],
            'stock_fisico_antes' => $fisicoAntes,
            'stock_comprometido' => $comprometidoAnt,
            'lote_cantidad_antes' => $loteAntes,
        ];

        $auditNuevo[] = [
            'producto_maestro_id' => $prodId,
            'sku' => $item['sku'],
            'lote_id' => $loteId,
            'numero_lote' => $item['numero_lote'],
            'cantidad_movida' => $qty,
            'costo_unitario_ref' => $item['costo_unitario_referencia'],
            'stock_fisico_despues' => $fisicoDespues,
            'lote_cantidad_despues' => $loteDespues,
        ];
    }

    // ------------------------------------------------------------------------
    // FASE 4: Auditoría Forense en seguridad.auditoria (JSONB)
    // ------------------------------------------------------------------------
    registrarAuditoria(
        $pdo,
        $usuarioSesionId,
        $supervisorId,
        'INVENTARIO_' . $tipoMovimiento,
        'inventario.movimientos_inventario',
        [
            'almacen_id' => $almacenId,
            'almacen_nombre' => $almacen['nombre'],
            'saldos_previos' => $auditAnterior,
        ],
        [
            'movimiento_id' => $movimientoId,
            'tipo_movimiento' => $tipoMovimiento,
            'almacen_id' => $almacenId,
            'almacen_nombre' => $almacen['nombre'],
            'justificacion' => $justificacion,
            'supervisor_nombre' => $supInfo['nombre_completo'] ?? null,
            'detalles_nuevos' => $auditNuevo,
        ]
    );

    $pdo->commit();

    responderJson('success', "Movimiento ({$tipoMovimiento}) registrado exitosamente en el Kardex.", [
        'movimiento_id' => $movimientoId,
    ]);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderJson('error', $e->getMessage(), [], 400);
}