<?php
/**
 * ============================================================================
 * Backend API: Órdenes de Compra
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/ordenes_compra/procesar_ordenes_compra.php
 * ============================================================================
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/conexion.php';

if (empty($_SESSION['usuario']['usuario_id'])) {
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'mensaje' => 'Sesión expirada o no iniciada.'
    ]);
    exit;
}

$accion = $_REQUEST['accion'] ?? '';

try {
    switch ($accion) {

        // ====================================================================
        // 1. Obtener siguiente número sugerido para la orden
        // ====================================================================
        case 'obtener_siguiente_numero':
            $stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM compras.ordenes_compra");
            $nextId = (int)$stmt->fetchColumn();
            $prefix = 'OC-' . date('Ymd') . '-';
            $numeroSugerido = $prefix . str_pad((string)$nextId, 4, '0', STR_PAD_LEFT);

            // Verificar si por alguna razón ya existe
            $checkStmt = $pdo->prepare("SELECT 1 FROM compras.ordenes_compra WHERE numero_orden = ?");
            $checkStmt->execute([$numeroSugerido]);
            if ($checkStmt->fetch()) {
                $numeroSugerido = $prefix . str_pad((string)($nextId + 1), 4, '0', STR_PAD_LEFT);
            }

            echo json_encode([
                'ok' => true,
                'numero_orden' => $numeroSugerido
            ]);
            break;

        // ====================================================================
        // 1.1. Buscar productos en catálogo (escalable para miles de SKUs)
        // ====================================================================
        case 'buscar_productos':
            $q = trim((string)($_GET['q'] ?? ''));
            $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));

            $params = [];
            $where = "WHERE pm.id > 0";
            if ($q !== '') {
                $where .= " AND (
                    pm.nombre ILIKE ? 
                    OR pm.sku ILIKE ? 
                    OR EXISTS (
                        SELECT 1 FROM inventario.producto_codigos_barra pcb 
                        WHERE pcb.producto_maestro_id = pm.id 
                          AND (pcb.codigo_barra ILIKE ? OR pcb.descripcion_presentacion ILIKE ?)
                    )
                )";
                $searchTerm = '%' . $q . '%';
                $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
            }

            $query = "
                SELECT pm.id,
                       pm.sku,
                       COALESCE(
                           NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                           (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                           pm.sku
                       ) AS nombre,
                       COALESCE(pm.costo_promedio, 0) AS costo_promedio,
                       COALESCE(pm.precio_base_unidad, 0) AS precio_base_unidad
                FROM inventario.productos_maestro pm
                {$where}
                ORDER BY pm.nombre ASC, pm.sku ASC
                LIMIT {$limit}
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Conteo total para saber si hay más coincidencias
            $countQuery = "
                SELECT COUNT(*) 
                FROM inventario.productos_maestro pm
                {$where}
            ";
            $stmtCount = $pdo->prepare($countQuery);
            $stmtCount->execute($params);
            $totalCoincidencias = (int)$stmtCount->fetchColumn();

            echo json_encode([
                'ok' => true,
                'productos' => $items,
                'total_mostrados' => count($items),
                'total_coincidencias' => $totalCoincidencias
            ]);
            break;

        // ====================================================================
        // 2. Obtener detalle de orden de compra y seguimiento
        // ====================================================================
        case 'obtener_detalle':
            $ordenId = (int)($_GET['orden_id'] ?? 0);
            if ($ordenId <= 0) {
                throw new Exception("ID de orden de compra inválido.");
            }

            // Cabecera
            $stmtOrd = $pdo->prepare("
                SELECT oc.id,
                       oc.numero_orden,
                       oc.proveedor_id,
                       oc.usuario_id,
                       oc.estado,
                       oc.monto_total_estimado,
                       oc.fecha_emision,
                       oc.fecha_esperada,
                       oc.moneda_id,
                       oc.tasa_cambio,
                       pr.codigo_proveedor,
                       pr.dias_credito_habitual,
                       p.nombre_razon_social AS proveedor_nombre,
                       p.nombre_comercial AS proveedor_comercial,
                       p.documento_identidad AS proveedor_rnc,
                       p.telefono_principal AS proveedor_telefono,
                       p.email AS proveedor_email,
                       u.username AS usuario_creador,
                       COALESCE(emp_p.nombre_razon_social, u.username) AS usuario_nombre_completo
                FROM compras.ordenes_compra oc
                JOIN identidad.proveedores pr ON pr.persona_id = oc.proveedor_id
                JOIN identidad.personas p ON p.persona_id = pr.persona_id
                JOIN seguridad.usuarios u ON u.usuario_id = oc.usuario_id
                LEFT JOIN identidad.personas emp_p ON emp_p.persona_id = u.persona_id
                WHERE oc.id = ?
                LIMIT 1
            ");
            $stmtOrd->execute([$ordenId]);
            $orden = $stmtOrd->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                throw new Exception("La orden de compra solicitada no existe.");
            }

            // Líneas de detalle
            $stmtDet = $pdo->prepare("
                SELECT d.orden_compra_id,
                       d.producto_maestro_id,
                       d.cantidad_ordenada,
                       d.cantidad_recibida_total,
                       d.cantidad_pendiente,
                       d.costo_pactado_unitario,
                       (d.cantidad_ordenada * d.costo_pactado_unitario) AS subtotal_estimado,
                       pm.sku,
                       COALESCE(
                           NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                           (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                           pm.sku
                       ) AS producto_nombre,
                       pm.costo_promedio
                FROM compras.orden_compra_detalles d
                JOIN inventario.productos_maestro pm ON pm.id = d.producto_maestro_id
                WHERE d.orden_compra_id = ?
                ORDER BY pm.nombre ASC, d.producto_maestro_id ASC
            ");
            $stmtDet->execute([$ordenId]);
            $lineas = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Facturas / Recepciones vinculadas a esta orden
            $stmtRec = $pdo->prepare("
                SELECT rc.id AS recepcion_id,
                       rc.numero_factura_proveedor,
                       rc.ncf_proveedor,
                       rc.monto_total_factura,
                       rc.fecha_recepcion,
                       rc.condicion_pago,
                       u.username AS usuario_receptor,
                       COALESCE(p.nombre_razon_social, u.username) AS receptor_nombre_completo,
                       (
                           SELECT COUNT(DISTINCT rcd.producto_maestro_id)
                           FROM compras.recepcion_compra_detalles rcd
                           WHERE rcd.recepcion_id = rc.id
                       ) AS total_items_recibidos,
                       (
                           SELECT COALESCE(SUM(rcd.cantidad_recibida_buena), 0)
                           FROM compras.recepcion_compra_detalles rcd
                           WHERE rcd.recepcion_id = rc.id
                       ) AS total_unidades_recibidas
                FROM compras.recepciones_compra rc
                JOIN seguridad.usuarios u ON u.usuario_id = rc.usuario_recepcion_id
                LEFT JOIN identidad.personas p ON p.persona_id = u.persona_id
                WHERE rc.orden_compra_id = ?
                ORDER BY rc.fecha_recepcion DESC, rc.id DESC
            ");
            $stmtRec->execute([$ordenId]);
            $recepciones = $stmtRec->fetchAll(PDO::FETCH_ASSOC) ?: [];

            echo json_encode([
                'ok' => true,
                'orden' => $orden,
                'detalles' => $lineas,
                'recepciones' => $recepciones
            ]);
            break;

        // ====================================================================
        // 3. Crear nueva orden de compra
        // ====================================================================
        case 'crear_orden':
            if (!usuarioPuedeCrearOrdenCompra()) {
                throw new Exception("No cuenta con permisos autorizados (CREAR_ORDEN_COMPRA) para emitir órdenes de compra.");
            }

            // Captura de datos
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

            $proveedor_id   = trim($input['proveedor_id'] ?? '');
            $numero_orden   = trim($input['numero_orden'] ?? '');
            $fecha_esperada = trim($input['fecha_esperada'] ?? '');
            $detalles       = $input['detalles'] ?? [];

            if (is_string($detalles)) {
                $detalles = json_decode($detalles, true) ?: [];
            }

            // Validaciones de Cabecera
            if (empty($proveedor_id)) {
                throw new Exception("Debe seleccionar un proveedor válido.");
            }

            // Verificar existencia del proveedor
            $stmtPrvCheck = $pdo->prepare("SELECT persona_id FROM identidad.proveedores WHERE persona_id = ?");
            $stmtPrvCheck->execute([$proveedor_id]);
            if (!$stmtPrvCheck->fetch()) {
                throw new Exception("El proveedor seleccionado no existe en el sistema.");
            }

            if (empty($numero_orden)) {
                throw new Exception("El número de orden es obligatorio.");
            }
            if (strlen($numero_orden) > 30) {
                throw new Exception("El número de orden no puede superar los 30 caracteres.");
            }

            // Validar unicidad del número de orden
            $stmtNumCheck = $pdo->prepare("SELECT id FROM compras.ordenes_compra WHERE UPPER(numero_orden) = UPPER(?)");
            $stmtNumCheck->execute([$numero_orden]);
            if ($stmtNumCheck->fetch()) {
                throw new Exception("El número de orden '{$numero_orden}' ya está registrado. Por favor elija otro.");
            }

            $fechaEsperadaValida = null;
            if (!empty($fecha_esperada)) {
                $dt = DateTime::createFromFormat('Y-m-d', $fecha_esperada);
                if (!$dt || $dt->format('Y-m-d') !== $fecha_esperada) {
                    throw new Exception("La fecha esperada tiene un formato no válido (debe ser AAAA-MM-DD).");
                }
                $fechaEsperadaValida = $fecha_esperada;
            }

            // Validaciones de Detalle
            if (!is_array($detalles) || count($detalles) === 0) {
                throw new Exception("Debe incluir al menos un producto en la orden de compra.");
            }

            $productosVistos = [];
            $montoTotalEstimado = 0;
            $lineasValidadas = [];

            foreach ($detalles as $idx => $fila) {
                $numFila = $idx + 1;
                $prodId = (int)($fila['producto_maestro_id'] ?? 0);
                $cant   = (int)($fila['cantidad_ordenada'] ?? 0);
                $costo  = (float)($fila['costo_pactado_unitario'] ?? 0);

                if ($prodId <= 0) {
                    throw new Exception("Fila {$numFila}: Debe seleccionar un producto maestro válido.");
                }

                // REGLA OBLIGATORIA: Impedir producto_maestro_id duplicados en la misma orden
                if (in_array($prodId, $productosVistos, true)) {
                    throw new Exception("Fila {$numFila}: El producto seleccionado ya se encuentra agregado en otra línea de esta orden. No se permiten productos duplicados.");
                }
                $productosVistos[] = $prodId;

                if ($cant <= 0) {
                    throw new Exception("Fila {$numFila}: La cantidad ordenada debe ser mayor a cero.");
                }

                if ($costo <= 0) {
                    throw new Exception("Fila {$numFila}: El costo pactado unitario debe ser mayor a cero.");
                }

                $subtotal = round($cant * $costo, 2);
                $montoTotalEstimado += $subtotal;

                $lineasValidadas[] = [
                    'producto_maestro_id'    => $prodId,
                    'cantidad_ordenada'      => $cant,
                    'costo_pactado_unitario' => round($costo, 2)
                ];
            }

            $montoTotalEstimado = round($montoTotalEstimado, 2);
            $usuarioId = $_SESSION['usuario']['usuario_id'];

            // INSERCIÓN DENTRO DE TRANSACCIÓN ATÓMICA
            $pdo->beginTransaction();

            $stmtInsCab = $pdo->prepare("
                INSERT INTO compras.ordenes_compra (
                    numero_orden,
                    proveedor_id,
                    usuario_id,
                    estado,
                    monto_total_estimado,
                    fecha_emision,
                    fecha_esperada,
                    moneda_id,
                    tasa_cambio
                ) VALUES (
                    ?, ?, ?, 'PENDIENTE', ?,
                    CURRENT_TIMESTAMP, ?, 1, 1.0000
                ) RETURNING id
            ");
            $stmtInsCab->execute([
                $numero_orden,
                $proveedor_id,
                $usuarioId,
                $montoTotalEstimado,
                $fechaEsperadaValida
            ]);
            $ordenId = (int)$stmtInsCab->fetchColumn();

            $stmtInsDet = $pdo->prepare("
                INSERT INTO compras.orden_compra_detalles (
                    orden_compra_id,
                    producto_maestro_id,
                    cantidad_ordenada,
                    cantidad_recibida_total,
                    costo_pactado_unitario
                ) VALUES (?, ?, ?, 0, ?)
            ");

            foreach ($lineasValidadas as $linea) {
                $stmtInsDet->execute([
                    $ordenId,
                    $linea['producto_maestro_id'],
                    $linea['cantidad_ordenada'],
                    $linea['costo_pactado_unitario']
                ]);
            }

            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'mensaje' => "Orden de compra {$numero_orden} creada exitosamente.",
                'orden_id' => $ordenId,
                'numero_orden' => $numero_orden
            ]);
            break;

        // ====================================================================
        // 4. Cancelar Orden de Compra (solo si está PENDIENTE sin recepciones)
        // ====================================================================
        case 'cancelar_orden':
            if (!usuarioPuedeCrearOrdenCompra()) {
                throw new Exception("No cuenta con permisos autorizados para cancelar órdenes.");
            }

            $ordenId = (int)($_POST['orden_id'] ?? 0);
            if ($ordenId <= 0) {
                throw new Exception("ID de orden inválido.");
            }

            $stmtVerif = $pdo->prepare("SELECT estado FROM compras.ordenes_compra WHERE id = ?");
            $stmtVerif->execute([$ordenId]);
            $estadoActual = $stmtVerif->fetchColumn();

            if (!$estadoActual) {
                throw new Exception("La orden de compra no existe.");
            }
            if ($estadoActual !== 'PENDIENTE') {
                throw new Exception("Solo se pueden cancelar órdenes que estén en estado 'PENDIENTE' sin recepciones iniciadas.");
            }

            $stmtCancel = $pdo->prepare("UPDATE compras.ordenes_compra SET estado = 'CANCELADA' WHERE id = ?");
            $stmtCancel->execute([$ordenId]);

            echo json_encode([
                'ok' => true,
                'mensaje' => "La orden de compra #{$ordenId} ha sido cancelada."
            ]);
            break;

        default:
            throw new Exception("Acción no reconocida: " . htmlspecialchars($accion));
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'mensaje' => 'Error de Base de Datos PostgreSQL: ' . $e->getMessage()
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'mensaje' => $e->getMessage()
    ]);
}
