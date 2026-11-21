<?php
/**
 * ============================================================================
 * Procesador de Traslados Internos entre Almacenes (Backend Controller)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: inventario.traslados_internos, inventario.traslado_detalles,
 *         inventario.inventario_stock, inventario.lotes,
 *         inventario.movimientos_inventario, inventario.movimiento_inventario_detalles,
 *         seguridad.auditoria
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
// CONSULTAS GET (STOCK/LOTES EN ORIGEN, BARCODE, CATÁLOGO Y DETALLE)
// ============================================================================
if ($metodo === 'GET') {
    $accion = $_GET['accion'] ?? '';

    try {
        // 1. Presentaciones y lotes con stock disponible en el almacén origen (FEFO)
        if ($accion === 'stock_lotes') {
            $productoId = intval($_GET['producto_id'] ?? 0);
            $almacenId = intval($_GET['almacen_id'] ?? 0);

            if ($productoId <= 0 || $almacenId <= 0) {
                responderJson('error', 'Debes seleccionar un almacén origen y un producto válidos.', [], 400);
            }

            $stmtProd = $pdo->prepare("
                SELECT pm.id, pm.sku, pm.requiere_lote, pm.costo_promedio,
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

            $requiereLote = ($producto['requiere_lote'] === true || $producto['requiere_lote'] === 't' || $producto['requiere_lote'] == 1);
            $producto['requiere_lote'] = $requiereLote;

            $stmtPres = $pdo->prepare("
                SELECT codigo_barra, descripcion_presentacion, factor_multiplicador
                FROM inventario.producto_codigos_barra
                WHERE producto_maestro_id = ?
                ORDER BY factor_multiplicador ASC, descripcion_presentacion ASC
            ");
            $stmtPres->execute([$productoId]);
            $presentaciones = $stmtPres->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($requiereLote) {
                $stmtLotes = $pdo->prepare("
                    SELECT l.id,
                           l.numero_lote,
                           l.fecha_vencimiento,
                           COALESCE(s.stock_fisico_unidades, 0) AS stock_fisico,
                           COALESCE(s.stock_comprometido, 0) AS stock_comprometido,
                           (COALESCE(s.stock_fisico_unidades, 0) - COALESCE(s.stock_comprometido, 0)) AS stock_disponible
                    FROM inventario.lotes l
                    INNER JOIN inventario.inventario_stock s
                            ON s.almacen_id = ?
                           AND s.producto_maestro_id = ?
                           AND s.lote_id = l.id
                    WHERE l.producto_maestro_id = ?
                      AND l.id > 0
                      AND (s.stock_fisico_unidades - s.stock_comprometido) > 0
                    ORDER BY l.fecha_vencimiento ASC NULLS LAST, l.id ASC
                ");
                $stmtLotes->execute([$almacenId, $productoId, $productoId]);
                $lotes = $stmtLotes->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $stmtComodin = $pdo->prepare("
                    SELECT 0 AS id,
                           'SIN LOTE (COMODÍN #0)' AS numero_lote,
                           NULL::date AS fecha_vencimiento,
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

        // 2. Búsqueda por código de barras o SKU
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
                    'descripcion_presentacion' => 'Unidad Base (x1)',
                    'factor_multiplicador' => 1,
                ];
            }

            responderJson('success', 'Producto localizado.', ['match' => $match]);
        }

        // 3. Buscador filtrado de catálogo y presentaciones con stock en almacén origen
        if ($accion === 'buscar_catalogo') {
            $almacenId = intval($_GET['almacen_id'] ?? 0);
            $q = trim((string) ($_GET['q'] ?? ''));
            $categoriaId = intval($_GET['categoria_id'] ?? 0);
            $marcaId = intval($_GET['marca_id'] ?? 0);
            $soloConStock = ($_GET['solo_con_stock'] ?? '1') === '1';

            if ($almacenId <= 0) {
                responderJson('error', 'Primero selecciona el almacén origen en el Paso 1.', [], 400);
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
                                       'factor_multiplicador', cb.factor_multiplicador
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

        // 4. Detalle completo de un traslado (sirve tanto para Ver Detalle como para el modal de Recepción)
        if ($accion === 'detalle') {
            $trasladoId = intval($_GET['id'] ?? 0);
            if ($trasladoId <= 0) {
                responderJson('error', 'Identificador de traslado inválido.', [], 400);
            }

            $stmtCab = $pdo->prepare("
                SELECT t.id,
                       t.numero_traslado,
                       t.almacen_origen_id,
                       t.almacen_destino_id,
                       ao.nombre AS almacen_origen_nombre,
                       ad.nombre AS almacen_destino_nombre,
                       t.estado,
                       t.fecha_emision,
                       COALESCE(NULLIF(TRIM(pe.nombre_razon_social), ''), ue.username) AS emisor_nombre,
                       COALESCE(NULLIF(TRIM(pr.nombre_razon_social), ''), ur.username) AS receptor_nombre
                FROM inventario.traslados_internos t
                JOIN inventario.almacenes ao ON ao.id = t.almacen_origen_id
                JOIN inventario.almacenes ad ON ad.id = t.almacen_destino_id
                LEFT JOIN seguridad.usuarios ue ON ue.usuario_id = t.usuario_emisor_id
                LEFT JOIN identidad.personas pe ON pe.persona_id = ue.persona_id
                LEFT JOIN seguridad.usuarios ur ON ur.usuario_id = t.usuario_receptor_id
                LEFT JOIN identidad.personas pr ON pr.persona_id = ur.persona_id
                WHERE t.id = ?
                LIMIT 1
            ");
            $stmtCab->execute([$trasladoId]);
            $cabecera = $stmtCab->fetch(PDO::FETCH_ASSOC);

            if (!$cabecera) {
                responderJson('error', 'Traslado interno no encontrado.', [], 404);
            }

            $stmtDet = $pdo->prepare("
                SELECT d.producto_maestro_id,
                       d.lote_id,
                       d.cantidad_enviada,
                       d.cantidad_recibida,
                       pm.sku,
                       pm.costo_promedio,
                       COALESCE(
                           NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                           (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                           pm.sku
                       ) AS producto_nombre,
                       l.numero_lote,
                       l.fecha_vencimiento
                FROM inventario.traslado_detalles d
                JOIN inventario.productos_maestro pm ON pm.id = d.producto_maestro_id
                LEFT JOIN inventario.lotes l ON l.id = d.lote_id
                WHERE d.traslado_id = ?
                ORDER BY pm.sku ASC, d.lote_id ASC
            ");
            $stmtDet->execute([$trasladoId]);
            $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Verificar si este traslado disparó una merma automática al recibirse
            $stmtMerma = $pdo->prepare("
                SELECT m.justificacion,
                       m.fecha_movimiento,
                       COALESCE(NULLIF(TRIM(ps.nombre_razon_social), ''), us.username) AS supervisor_nombre,
                       COALESCE(SUM(md.cantidad_unidades), 0) AS total_merma_unidades,
                       COALESCE(SUM(md.cantidad_unidades * md.costo_unitario_referencia), 0) AS total_merma_costo
                FROM inventario.movimientos_inventario m
                LEFT JOIN inventario.movimiento_inventario_detalles md ON md.movimiento_id = m.id
                LEFT JOIN seguridad.usuarios us ON us.usuario_id = m.supervisor_id
                LEFT JOIN identidad.personas ps ON ps.persona_id = us.persona_id
                WHERE m.tipo_movimiento = 'MERMA_ROTURA'
                  AND m.documento_referencia_id = ?
                GROUP BY m.id, m.justificacion, m.fecha_movimiento, ps.nombre_razon_social, us.username
                LIMIT 1
            ");
            $stmtMerma->execute([$trasladoId]);
            $mermaAsociada = $stmtMerma->fetch(PDO::FETCH_ASSOC) ?: null;

            responderJson('success', 'OK', [
                'cabecera' => $cabecera,
                'detalles' => $detalles,
                'merma' => $mermaAsociada,
            ]);
        }

        responderJson('error', 'Acción GET no reconocida.', [], 400);
    } catch (Throwable $e) {
        responderJson('error', $e->getMessage(), [], 500);
    }
}

// ============================================================================
// OPERACIONES TRANSACCIONALES POST (EMITIR, RECIBIR, CANCELAR)
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

// ----------------------------------------------------------------------------
// ACCIÓN 1: EMITIR TRASLADO (EN_TRANSITO — DESCUENTA ORIGEN AL INSTANTE)
// ----------------------------------------------------------------------------
if ($accion === 'emitir_traslado') {
    try {
        $origenId = intval($payload['almacen_origen_id'] ?? 0);
        $destinoId = intval($payload['almacen_destino_id'] ?? 0);
        $lineasRaw = $payload['lineas'] ?? [];

        if ($origenId <= 0 || $destinoId <= 0) {
            throw new Exception("Debes seleccionar tanto el almacén de origen como el almacén de destino.");
        }
        if ($origenId === $destinoId) {
            throw new Exception("El almacén de origen y el de destino no pueden ser el mismo.");
        }
        if (!is_array($lineasRaw) || count($lineasRaw) === 0) {
            throw new Exception("Debes agregar al menos un producto al carrito del traslado.");
        }

        $stmtAlm = $pdo->prepare("SELECT id, nombre FROM inventario.almacenes WHERE id IN (?, ?)");
        $stmtAlm->execute([$origenId, $destinoId]);
        $almacenesMap = [];
        foreach ($stmtAlm->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $almacenesMap[intval($a['id'])] = $a['nombre'];
        }
        if (!isset($almacenesMap[$origenId]) || !isset($almacenesMap[$destinoId])) {
            throw new Exception("Uno de los almacenes seleccionados no existe en la base de datos.");
        }

        $pdo->beginTransaction();

        // Consolidar líneas por PK compuesta (producto_maestro_id, lote_id)
        $consolidadas = [];
        $stmtProd = $pdo->prepare("
            SELECT id, sku, requiere_lote,
                   COALESCE(NULLIF(TRIM(nombre), ''), sku) AS nombre
            FROM inventario.productos_maestro
            WHERE id = ? AND id > 0
            LIMIT 1
        ");
        $stmtFactor = $pdo->prepare("
            SELECT factor_multiplicador
            FROM inventario.producto_codigos_barra
            WHERE producto_maestro_id = ? AND codigo_barra = ?
            LIMIT 1
        ");
        $stmtLote = $pdo->prepare("
            SELECT id, producto_maestro_id, numero_lote, fecha_vencimiento
            FROM inventario.lotes
            WHERE id = ?
            LIMIT 1
        ");

        foreach ($lineasRaw as $idx => $ln) {
            $numLinea = $idx + 1;
            $productoId = intval($ln['producto_maestro_id'] ?? 0);
            $cantPres = intval($ln['cantidad_presentacion'] ?? 0);
            $codigoBarra = trim((string) ($ln['codigo_barra'] ?? ''));

            if ($productoId <= 0 || $cantPres <= 0) {
                throw new Exception("Línea #{$numLinea}: producto o cantidad inválida.");
            }

            $stmtProd->execute([$productoId]);
            $prod = $stmtProd->fetch(PDO::FETCH_ASSOC);
            if (!$prod) {
                throw new Exception("Línea #{$numLinea}: el producto no existe.");
            }

            $factor = 1;
            if ($codigoBarra !== '') {
                $stmtFactor->execute([$productoId, $codigoBarra]);
                $factorDb = $stmtFactor->fetchColumn();
                if ($factorDb === false) {
                    throw new Exception("Línea #{$numLinea}: la presentación '{$codigoBarra}' no corresponde a {$prod['sku']}.");
                }
                $factor = max(1, intval($factorDb));
            }

            $cantidadUnidades = $cantPres * $factor;
            $requiereLote = ($prod['requiere_lote'] === true || $prod['requiere_lote'] === 't' || $prod['requiere_lote'] == 1);

            if (!$requiereLote) {
                $loteId = 0;
                $numeroLoteTexto = 'COMODIN-0';
            } else {
                $loteId = intval($ln['lote_id'] ?? 0);
                if ($loteId <= 0) {
                    throw new Exception("Línea #{$numLinea} ({$prod['sku']}): debes seleccionar un lote válido.");
                }
                $stmtLote->execute([$loteId]);
                $lRow = $stmtLote->fetch(PDO::FETCH_ASSOC);
                if (!$lRow || intval($lRow['producto_maestro_id']) !== $productoId) {
                    throw new Exception("Línea #{$numLinea} ({$prod['sku']}): el lote seleccionado no pertenece a este producto.");
                }
                $numeroLoteTexto = $lRow['numero_lote'];
            }

            $clave = "{$productoId}_{$loteId}";
            if (isset($consolidadas[$clave])) {
                $consolidadas[$clave]['cantidad_enviada'] += $cantidadUnidades;
            } else {
                $consolidadas[$clave] = [
                    'producto_maestro_id' => $productoId,
                    'sku' => $prod['sku'],
                    'nombre' => $prod['nombre'],
                    'lote_id' => $loteId,
                    'numero_lote' => $numeroLoteTexto,
                    'cantidad_enviada' => $cantidadUnidades,
                ];
            }
        }

        // Orden determinista para prevenir deadlocks
        uasort($consolidadas, function ($a, $b) {
            return ($a['producto_maestro_id'] <=> $b['producto_maestro_id'])
                ?: ($a['lote_id'] <=> $b['lote_id']);
        });

        // Generar ID y número natural único (TRAS-AAAAMM-XXXX)
        $trasladoId = intval($pdo->query("SELECT nextval('inventario.traslados_internos_id_seq')")->fetchColumn());
        $numeroTraslado = 'TRAS-' . date('Ym') . '-' . str_pad((string) $trasladoId, 4, '0', STR_PAD_LEFT);

        $stmtInsCab = $pdo->prepare("
            INSERT INTO inventario.traslados_internos
                (id, numero_traslado, almacen_origen_id, almacen_destino_id, usuario_emisor_id, usuario_receptor_id, estado)
            VALUES (?, ?, ?, ?, ?, NULL, 'EN_TRANSITO')
        ");
        $stmtInsCab->execute([$trasladoId, $numeroTraslado, $origenId, $destinoId, $usuarioSesionId]);

        $stmtInsDet = $pdo->prepare("
            INSERT INTO inventario.traslado_detalles
                (traslado_id, producto_maestro_id, lote_id, cantidad_enviada, cantidad_recibida)
            VALUES (?, ?, ?, ?, 0)
        ");
        $stmtLockStock = $pdo->prepare("
            SELECT stock_fisico_unidades, stock_comprometido
            FROM inventario.inventario_stock
            WHERE almacen_id = ? AND producto_maestro_id = ? AND lote_id = ?
            FOR UPDATE
        ");
        $stmtRestarOrigen = $pdo->prepare("
            UPDATE inventario.inventario_stock
            SET stock_fisico_unidades = stock_fisico_unidades - ?
            WHERE almacen_id = ? AND producto_maestro_id = ? AND lote_id = ?
        ");

        $auditPrevio = [];
        $auditNuevo = [];

        foreach ($consolidadas as $item) {
            $prodId = $item['producto_maestro_id'];
            $loteId = $item['lote_id'];
            $qty = $item['cantidad_enviada'];

            $stmtLockStock->execute([$origenId, $prodId, $loteId]);
            $stockRow = $stmtLockStock->fetch(PDO::FETCH_ASSOC);
            $fisicoAntes = $stockRow ? intval($stockRow['stock_fisico_unidades']) : 0;
            $comprometidoAnt = $stockRow ? intval($stockRow['stock_comprometido']) : 0;
            $disponibleAntes = $fisicoAntes - $comprometidoAnt;

            if (!$stockRow || $disponibleAntes < $qty) {
                throw new Exception(
                    "Stock disponible insuficiente para {$item['sku']} (Lote {$item['numero_lote']}) en {$almacenesMap[$origenId]}: " .
                    "disponible {$disponibleAntes} uds., intentando trasladar {$qty} uds."
                );
            }

            // Descontar de origen al instante (aún no se suma al destino hasta recepción)
            $stmtRestarOrigen->execute([$qty, $origenId, $prodId, $loteId]);
            $stmtInsDet->execute([$trasladoId, $prodId, $loteId, $qty]);

            $auditPrevio[] = [
                'producto_maestro_id' => $prodId,
                'sku' => $item['sku'],
                'lote_id' => $loteId,
                'numero_lote' => $item['numero_lote'],
                'origen_fisico_antes' => $fisicoAntes,
            ];
            $auditNuevo[] = [
                'producto_maestro_id' => $prodId,
                'sku' => $item['sku'],
                'lote_id' => $loteId,
                'numero_lote' => $item['numero_lote'],
                'cantidad_enviada' => $qty,
                'origen_fisico_despues' => $fisicoAntes - $qty,
            ];
        }

        registrarAuditoria(
            $pdo,
            $usuarioSesionId,
            null,
            'INVENTARIO_TRASLADO_EMISION',
            'inventario.traslados_internos',
            [
                'almacen_origen' => $almacenesMap[$origenId],
                'almacen_destino' => $almacenesMap[$destinoId],
                'saldos_origen' => $auditPrevio,
            ],
            [
                'numero_traslado' => $numeroTraslado,
                'estado' => 'EN_TRANSITO',
                'almacen_origen' => $almacenesMap[$origenId],
                'almacen_destino' => $almacenesMap[$destinoId],
                'detalles' => $auditNuevo,
            ]
        );

        $pdo->commit();

        responderJson('success', "Traslado {$numeroTraslado} emitido en estado EN_TRANSITO. Stock descontado de {$almacenesMap[$origenId]}.", [
            'numero_traslado' => $numeroTraslado,
        ]);
    } catch (Throwable $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        responderJson('error', $e->getMessage(), [], 400);
    }
}

// ----------------------------------------------------------------------------
// ACCIÓN 2: CONFIRMAR RECEPCIÓN (RECIBIDO + MERMA AUTOMÁTICA SI HAY FALTANTES)
// ----------------------------------------------------------------------------
if ($accion === 'recibir_traslado') {
    try {
        $trasladoId = intval($payload['traslado_id'] ?? 0);
        $lineasRecibidasRaw = $payload['lineas'] ?? [];
        $justificacionMerma = trim((string) ($payload['justificacion_merma'] ?? ''));
        $supervisorIdIn = trim((string) ($payload['supervisor_id'] ?? ''));
        $pinIngresado = trim((string) ($payload['pin_autorizacion'] ?? ''));

        if ($trasladoId <= 0) {
            throw new Exception("Identificador de traslado no válido.");
        }
        if (!is_array($lineasRecibidasRaw) || count($lineasRecibidasRaw) === 0) {
            throw new Exception("Debes confirmar las cantidades recibidas del traslado.");
        }

        $pdo->beginTransaction();

        // Bloquear cabecera para evitar doble recepción concurrente
        $stmtCab = $pdo->prepare("
            SELECT t.id, t.numero_traslado, t.almacen_origen_id, t.almacen_destino_id, t.estado,
                   ao.nombre AS origen_nombre, ad.nombre AS destino_nombre
            FROM inventario.traslados_internos t
            JOIN inventario.almacenes ao ON ao.id = t.almacen_origen_id
            JOIN inventario.almacenes ad ON ad.id = t.almacen_destino_id
            WHERE t.id = ?
            FOR UPDATE OF t
        ");
        $stmtCab->execute([$trasladoId]);
        $cab = $stmtCab->fetch(PDO::FETCH_ASSOC);

        if (!$cab) {
            throw new Exception("El traslado seleccionado no existe.");
        }
        if (strtoupper((string) $cab['estado']) !== 'EN_TRANSITO') {
            throw new Exception("El traslado {$cab['numero_traslado']} ya fue procesado (estado actual: {$cab['estado']}).");
        }

        // Cargar detalles reales del traslado ordenados determinísticamente
        $stmtDets = $pdo->prepare("
            SELECT d.producto_maestro_id, d.lote_id, d.cantidad_enviada,
                   pm.sku, pm.costo_promedio,
                   COALESCE(NULLIF(TRIM(pm.nombre), ''), pm.sku) AS nombre,
                   COALESCE(l.numero_lote, 'COMODIN-0') AS numero_lote
            FROM inventario.traslado_detalles d
            JOIN inventario.productos_maestro pm ON pm.id = d.producto_maestro_id
            LEFT JOIN inventario.lotes l ON l.id = d.lote_id
            WHERE d.traslado_id = ?
            ORDER BY d.producto_maestro_id ASC, d.lote_id ASC
        ");
        $stmtDets->execute([$trasladoId]);
        $detsDb = $stmtDets->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($detsDb)) {
            throw new Exception("El traslado {$cab['numero_traslado']} no tiene líneas de detalle.");
        }

        // Mapear lo enviado desde el formulario
        $recibidasMap = [];
        foreach ($lineasRecibidasRaw as $lr) {
            $k = intval($lr['producto_maestro_id'] ?? 0) . '_' . intval($lr['lote_id'] ?? 0);
            $recibidasMap[$k] = intval($lr['cantidad_recibida'] ?? -1);
        }

        $planRecepcion = [];
        $lineasFaltantes = [];

        foreach ($detsDb as $d) {
            $prodId = intval($d['producto_maestro_id']);
            $loteId = intval($d['lote_id']);
            $enviada = intval($d['cantidad_enviada']);
            $k = "{$prodId}_{$loteId}";

            if (!array_key_exists($k, $recibidasMap)) {
                throw new Exception("Falta confirmar la cantidad recibida para {$d['sku']} (Lote {$d['numero_lote']}).");
            }

            $recibida = $recibidasMap[$k];
            if ($recibida < 0 || $recibida > $enviada) {
                throw new Exception("La cantidad recibida de {$d['sku']} debe estar entre 0 y {$enviada} unidades.");
            }

            $faltante = $enviada - $recibida;
            $filaPlan = [
                'producto_maestro_id' => $prodId,
                'sku' => $d['sku'],
                'nombre' => $d['nombre'],
                'lote_id' => $loteId,
                'numero_lote' => $d['numero_lote'],
                'costo_promedio' => round(floatval($d['costo_promedio'] ?? 0), 2),
                'cantidad_enviada' => $enviada,
                'cantidad_recibida' => $recibida,
                'faltante' => $faltante,
            ];
            $planRecepcion[] = $filaPlan;

            if ($faltante > 0) {
                $lineasFaltantes[] = $filaPlan;
            }
        }

        // Si hay roturas/faltantes en el trayecto, exigir PIN de supervisor y justificación
        $supervisorId = null;
        $supInfo = null;
        $huboMerma = count($lineasFaltantes) > 0;

        if ($huboMerma) {
            if (mb_strlen($justificacionMerma, 'UTF-8') < 5) {
                throw new Exception("Al existir unidades faltantes o rotas, debes ingresar una justificación de al menos 5 caracteres.");
            }
            $supInfo = validarPinSupervisor($pdo, $supervisorIdIn, $pinIngresado);
            $supervisorId = $supInfo['usuario_id'];
        }

        $destinoId = intval($cab['almacen_destino_id']);

        $stmtUpdDet = $pdo->prepare("
            UPDATE inventario.traslado_detalles
            SET cantidad_recibida = ?
            WHERE traslado_id = ? AND producto_maestro_id = ? AND lote_id = ?
        ");
        $stmtUpsertDestino = $pdo->prepare("
            INSERT INTO inventario.inventario_stock
                (almacen_id, producto_maestro_id, lote_id, stock_fisico_unidades, stock_comprometido)
            VALUES (?, ?, ?, ?, 0)
            ON CONFLICT (almacen_id, producto_maestro_id, lote_id)
            DO UPDATE SET stock_fisico_unidades = inventario.inventario_stock.stock_fisico_unidades + EXCLUDED.stock_fisico_unidades
        ");
        $stmtLockLote = $pdo->prepare("SELECT cantidad_actual FROM inventario.lotes WHERE id = ? FOR UPDATE");
        $stmtRestarLote = $pdo->prepare("UPDATE inventario.lotes SET cantidad_actual = cantidad_actual - ? WHERE id = ?");
        $stmtRestarLoteComodin = $pdo->prepare("UPDATE inventario.lotes SET cantidad_actual = GREATEST(0, cantidad_actual - ?) WHERE id = 0");

        // Aplicar recepción en destino y actualizar traslado_detalles
        foreach ($planRecepcion as $item) {
            $stmtUpdDet->execute([
                $item['cantidad_recibida'],
                $trasladoId,
                $item['producto_maestro_id'],
                $item['lote_id'],
            ]);

            if ($item['cantidad_recibida'] > 0) {
                $stmtUpsertDestino->execute([
                    $destinoId,
                    $item['producto_maestro_id'],
                    $item['lote_id'],
                    $item['cantidad_recibida'],
                ]);
            }
        }

        // Generar asiento automático MERMA_ROTURA si hubo faltantes
        $movimientoMermaId = null;
        if ($huboMerma) {
            $colsMov = obtenerColumnasTabla($pdo, 'inventario', 'movimientos_inventario');
            $justFinal = "Faltante en traslado {$cab['numero_traslado']} ({$cab['origen_nombre']} → {$cab['destino_nombre']}): {$justificacionMerma}";
            $camposCab = ['tipo_movimiento', 'justificacion', 'supervisor_id', 'documento_referencia_id'];
            $valoresCab = ['MERMA_ROTURA', $justFinal, $supervisorId, $trasladoId];

            if (isset($colsMov['almacen_id'])) {
                $camposCab[] = 'almacen_id';
                $valoresCab[] = $destinoId;
            }
            if (isset($colsMov['usuario_id'])) {
                $camposCab[] = 'usuario_id';
                $valoresCab[] = $usuarioSesionId;
            }

            $phCab = implode(', ', array_fill(0, count($camposCab), '?'));
            $stmtInsMov = $pdo->prepare("
                INSERT INTO inventario.movimientos_inventario (" . implode(', ', $camposCab) . ")
                VALUES ({$phCab})
                RETURNING id
            ");
            $stmtInsMov->execute($valoresCab);
            $movimientoMermaId = intval($stmtInsMov->fetchColumn());

            $stmtInsMovDet = $pdo->prepare("
                INSERT INTO inventario.movimiento_inventario_detalles
                    (movimiento_id, producto_maestro_id, lote_id, cantidad_unidades, costo_unitario_referencia)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($lineasFaltantes as $mf) {
                $loteId = $mf['lote_id'];
                $faltante = $mf['faltante'];

                $stmtLockLote->execute([$loteId]);
                $loteActual = intval($stmtLockLote->fetchColumn());

                if ($loteId > 0) {
                    if ($loteActual < $faltante) {
                        throw new Exception("Saldo insuficiente en lote {$mf['numero_lote']} de {$mf['sku']} para asentar la merma ({$faltante} uds.).");
                    }
                    $stmtRestarLote->execute([$faltante, $loteId]);
                } else {
                    $stmtRestarLoteComodin->execute([$faltante]);
                }

                $stmtInsMovDet->execute([
                    $movimientoMermaId,
                    $mf['producto_maestro_id'],
                    $loteId,
                    $faltante,
                    $mf['costo_promedio'],
                ]);
            }
        }

        // Cerrar traslado en estado RECIBIDO
        $stmtCerrar = $pdo->prepare("
            UPDATE inventario.traslados_internos
            SET estado = 'RECIBIDO', usuario_receptor_id = ?
            WHERE id = ?
        ");
        $stmtCerrar->execute([$usuarioSesionId, $trasladoId]);

        registrarAuditoria(
            $pdo,
            $usuarioSesionId,
            $supervisorId,
            $huboMerma ? 'INVENTARIO_TRASLADO_RECEPCION_CON_MERMA' : 'INVENTARIO_TRASLADO_RECEPCION',
            'inventario.traslados_internos',
            [
                'numero_traslado' => $cab['numero_traslado'],
                'estado_anterior' => 'EN_TRANSITO',
            ],
            [
                'numero_traslado' => $cab['numero_traslado'],
                'estado_nuevo' => 'RECIBIDO',
                'almacen_destino' => $cab['destino_nombre'],
                'hubo_merma' => $huboMerma,
                'movimiento_merma_id' => $movimientoMermaId,
                'justificacion_merma' => $huboMerma ? $justificacionMerma : null,
                'supervisor_nombre' => $supInfo['nombre_completo'] ?? null,
                'detalle_recepcion' => $planRecepcion,
            ]
        );

        $pdo->commit();

        $msgExito = $huboMerma
            ? "Traslado {$cab['numero_traslado']} recibido en {$cab['destino_nombre']} con merma automática registrada en Kardex."
            : "Traslado {$cab['numero_traslado']} recibido íntegramente en {$cab['destino_nombre']}.";

        responderJson('success', $msgExito);
    } catch (Throwable $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        responderJson('error', $e->getMessage(), [], 400);
    }
}

// ----------------------------------------------------------------------------
// ACCIÓN 3: CANCELAR TRASLADO (DEVUELVE STOCK AL ORIGEN CON PIN DE SUPERVISOR)
// ----------------------------------------------------------------------------
if ($accion === 'cancelar_traslado') {
    try {
        $trasladoId = intval($payload['traslado_id'] ?? 0);
        $justificacion = trim((string) ($payload['justificacion'] ?? ''));
        $supervisorIdIn = trim((string) ($payload['supervisor_id'] ?? ''));
        $pinIngresado = trim((string) ($payload['pin_autorizacion'] ?? ''));

        if ($trasladoId <= 0) {
            throw new Exception("Identificador de traslado inválido.");
        }
        if (mb_strlen($justificacion, 'UTF-8') < 5) {
            throw new Exception("Debes indicar el motivo de cancelación (mínimo 5 caracteres).");
        }

        $supInfo = validarPinSupervisor($pdo, $supervisorIdIn, $pinIngresado);
        $supervisorId = $supInfo['usuario_id'];

        $pdo->beginTransaction();

        $stmtCab = $pdo->prepare("
            SELECT t.id, t.numero_traslado, t.almacen_origen_id, t.almacen_destino_id, t.estado,
                   ao.nombre AS origen_nombre, ad.nombre AS destino_nombre
            FROM inventario.traslados_internos t
            JOIN inventario.almacenes ao ON ao.id = t.almacen_origen_id
            JOIN inventario.almacenes ad ON ad.id = t.almacen_destino_id
            WHERE t.id = ?
            FOR UPDATE OF t
        ");
        $stmtCab->execute([$trasladoId]);
        $cab = $stmtCab->fetch(PDO::FETCH_ASSOC);

        if (!$cab) {
            throw new Exception("El traslado no existe.");
        }
        if (strtoupper((string) $cab['estado']) !== 'EN_TRANSITO') {
            throw new Exception("Solo se pueden cancelar traslados en estado EN_TRANSITO (actual: {$cab['estado']}).");
        }

        $stmtDets = $pdo->prepare("
            SELECT d.producto_maestro_id, d.lote_id, d.cantidad_enviada, pm.sku
            FROM inventario.traslado_detalles d
            JOIN inventario.productos_maestro pm ON pm.id = d.producto_maestro_id
            WHERE d.traslado_id = ?
            ORDER BY d.producto_maestro_id ASC, d.lote_id ASC
        ");
        $stmtDets->execute([$trasladoId]);
        $dets = $stmtDets->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtDevolverOrigen = $pdo->prepare("
            INSERT INTO inventario.inventario_stock
                (almacen_id, producto_maestro_id, lote_id, stock_fisico_unidades, stock_comprometido)
            VALUES (?, ?, ?, ?, 0)
            ON CONFLICT (almacen_id, producto_maestro_id, lote_id)
            DO UPDATE SET stock_fisico_unidades = inventario.inventario_stock.stock_fisico_unidades + EXCLUDED.stock_fisico_unidades
        ");

        $origenId = intval($cab['almacen_origen_id']);
        foreach ($dets as $d) {
            $stmtDevolverOrigen->execute([
                $origenId,
                intval($d['producto_maestro_id']),
                intval($d['lote_id']),
                intval($d['cantidad_enviada']),
            ]);
        }

        $pdo->prepare("UPDATE inventario.traslados_internos SET estado = 'CANCELADO' WHERE id = ?")
            ->execute([$trasladoId]);

        registrarAuditoria(
            $pdo,
            $usuarioSesionId,
            $supervisorId,
            'INVENTARIO_TRASLADO_CANCELACION',
            'inventario.traslados_internos',
            [
                'numero_traslado' => $cab['numero_traslado'],
                'estado_anterior' => 'EN_TRANSITO',
            ],
            [
                'numero_traslado' => $cab['numero_traslado'],
                'estado_nuevo' => 'CANCELADO',
                'almacen_devuelto' => $cab['origen_nombre'],
                'justificacion' => $justificacion,
                'supervisor_nombre' => $supInfo['nombre_completo'],
                'lineas_devueltas' => $dets,
            ]
        );

        $pdo->commit();

        responderJson('success', "Traslado {$cab['numero_traslado']} cancelado. Mercancía devuelta a {$cab['origen_nombre']}.");
    } catch (Throwable $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        responderJson('error', $e->getMessage(), [], 400);
    }
}

responderJson('error', 'Acción POST no reconocida.', [], 400);