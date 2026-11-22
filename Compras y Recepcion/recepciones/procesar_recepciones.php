<?php
/**
 * ============================================================================
 * Backend API: Recepciones de Compra (Entregas Parciales y Multi-Lote)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/recepciones/procesar_recepciones.php
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

// Soporte universal para GET, POST form-data y POST JSON (php://input)
$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : null;
$datos = is_array($jsonBody) ? array_merge($_GET, $jsonBody) : array_merge($_GET, $_POST);
$accion = trim((string)($datos['accion'] ?? $_REQUEST['accion'] ?? ''));

try {
    switch ($accion) {

        // ====================================================================
        // 1. Obtener líneas pendientes (cantidad_pendiente > 0) de una orden
        // ====================================================================
        case 'obtener_lineas_orden':
            $ordenId = (int)($_GET['orden_id'] ?? 0);
            if ($ordenId <= 0) {
                throw new Exception("ID de orden de compra inválido.");
            }

            // Consultar datos de cabecera de la orden y proveedor
            $stmtOrd = $pdo->prepare("
                SELECT oc.id,
                       oc.numero_orden,
                       oc.proveedor_id,
                       oc.estado,
                       oc.monto_total_estimado,
                       oc.fecha_emision,
                       oc.fecha_esperada,
                       pr.codigo_proveedor,
                       pr.dias_credito_habitual,
                       p.nombre_razon_social AS proveedor_nombre,
                       p.nombre_comercial AS proveedor_comercial,
                       p.documento_identidad AS proveedor_rnc,
                       p.telefono_principal AS proveedor_telefono
                FROM compras.ordenes_compra oc
                JOIN identidad.proveedores pr ON pr.persona_id = oc.proveedor_id
                JOIN identidad.personas p ON p.persona_id = pr.persona_id
                WHERE oc.id = ?
                LIMIT 1
            ");
            $stmtOrd->execute([$ordenId]);
            $orden = $stmtOrd->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                throw new Exception("La orden de compra no fue encontrada.");
            }

            if (!in_array($orden['estado'], ['PENDIENTE', 'RECIBIDA_PARCIAL'], true)) {
                throw new Exception("Esta orden se encuentra en estado '{$orden['estado']}' y no puede recibir nueva mercancía.");
            }

            // REGLA: Consultar vía AJAX únicamente las líneas donde cantidad_pendiente > 0
            $stmtLineas = $pdo->prepare("
                SELECT d.orden_compra_id,
                       d.producto_maestro_id,
                       d.cantidad_ordenada,
                       d.cantidad_recibida_total,
                       d.cantidad_pendiente,
                       d.costo_pactado_unitario,
                       pm.sku,
                       COALESCE(
                           NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                           (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                           pm.sku
                       ) AS producto_nombre,
                       pm.requiere_lote,
                       pm.costo_promedio
                FROM compras.orden_compra_detalles d
                JOIN inventario.productos_maestro pm ON pm.id = d.producto_maestro_id
                WHERE d.orden_compra_id = ?
                  AND d.cantidad_pendiente > 0
                ORDER BY pm.nombre ASC, d.producto_maestro_id ASC
            ");
            $stmtLineas->execute([$ordenId]);
            $lineasPendientes = $stmtLineas->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($lineasPendientes)) {
                throw new Exception("Esta orden no posee productos con saldo pendiente por recibir.");
            }

            echo json_encode([
                'ok' => true,
                'orden' => $orden,
                'lineas' => $lineasPendientes
            ]);
            break;

        // ====================================================================
        // 2. Obtener detalle de una recepción ya guardada (Historial)
        // ====================================================================
        case 'obtener_detalle_recepcion':
            $recepcionId = (int)($_GET['recepcion_id'] ?? 0);
            if ($recepcionId <= 0) {
                throw new Exception("ID de recepción inválido.");
            }

            // Cabecera
            $stmtRec = $pdo->prepare("
                SELECT rc.id,
                       rc.orden_compra_id,
                       rc.proveedor_id,
                       rc.numero_factura_proveedor,
                       rc.ncf_proveedor,
                       rc.monto_total_factura,
                       rc.usuario_recepcion_id,
                       rc.fecha_recepcion,
                       rc.condicion_pago,
                       rc.moneda_id,
                       rc.tasa_cambio,
                       oc.numero_orden,
                       p.nombre_razon_social AS proveedor_nombre,
                       p.documento_identidad AS proveedor_rnc,
                       u.username AS usuario_receptor,
                       COALESCE(emp_p.nombre_razon_social, u.username) AS receptor_nombre_completo
                FROM compras.recepciones_compra rc
                LEFT JOIN compras.ordenes_compra oc ON oc.id = rc.orden_compra_id
                JOIN identidad.proveedores pr ON pr.persona_id = rc.proveedor_id
                JOIN identidad.personas p ON p.persona_id = pr.persona_id
                JOIN seguridad.usuarios u ON u.usuario_id = rc.usuario_recepcion_id
                LEFT JOIN identidad.personas emp_p ON emp_p.persona_id = u.persona_id
                WHERE rc.id = ?
                LIMIT 1
            ");
            $stmtRec->execute([$recepcionId]);
            $recepcion = $stmtRec->fetch(PDO::FETCH_ASSOC);

            if (!$recepcion) {
                throw new Exception("La recepción solicitada no existe.");
            }

            // Detalle de lotes recibidos
            $stmtDet = $pdo->prepare("
                SELECT rcd.recepcion_id,
                       rcd.producto_maestro_id,
                       rcd.numero_lote,
                       rcd.fecha_vencimiento,
                       rcd.cantidad_recibida_buena,
                       rcd.costo_unitario_facturado,
                       rcd.orden_compra_id,
                       (rcd.cantidad_recibida_buena * rcd.costo_unitario_facturado) AS subtotal_linea,
                       pm.sku,
                       COALESCE(
                           NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                           (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                           pm.sku
                       ) AS producto_nombre
                FROM compras.recepcion_compra_detalles rcd
                JOIN inventario.productos_maestro pm ON pm.id = rcd.producto_maestro_id
                WHERE rcd.recepcion_id = ?
                ORDER BY pm.nombre ASC, rcd.numero_lote ASC
            ");
            $stmtDet->execute([$recepcionId]);
            $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Deuda generada en Cuentas por Pagar
            $stmtCxp = $pdo->prepare("
                SELECT cxp.id,
                       cxp.monto_original,
                       cxp.saldo_pendiente,
                       cxp.fecha_emision,
                       cxp.fecha_vencimiento,
                       cxp.estado
                FROM compras.cuentas_por_pagar cxp
                WHERE cxp.recepcion_compra_id = ?
                LIMIT 1
            ");
            $stmtCxp->execute([$recepcionId]);
            $cxp = $stmtCxp->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'ok' => true,
                'recepcion' => $recepcion,
                'detalles' => $detalles,
                'cxp' => $cxp
            ]);
            break;

        // ====================================================================
        // 3. Registrar Recepción de Mercancía (Entregas Parciales y Multi-Lote)
        // ====================================================================
        case 'registrar_recepcion':
            if (!usuarioPuedeRegistrarRecepcion()) {
                throw new Exception("No cuenta con permisos autorizados (REGISTRAR_RECEPCION_COMPRA) para ingresar recepciones.");
            }

            $input = is_array($jsonBody) ? $jsonBody : $_POST;

            $ordenId          = (int)($input['orden_compra_id'] ?? 0);
            $numFactura       = trim($input['numero_factura_proveedor'] ?? '');
            $ncfProveedor     = trim($input['ncf_proveedor'] ?? '');
            $condicionPago    = strtoupper(trim($input['condicion_pago'] ?? 'CREDITO'));
            $lineasRecibidas  = $input['lineas'] ?? [];

            if (is_string($lineasRecibidas)) {
                $lineasRecibidas = json_decode($lineasRecibidas, true) ?: [];
            }

            // 1. Validaciones de Cabecera Física
            if ($ordenId <= 0) {
                throw new Exception("Debe seleccionar una orden de compra válida.");
            }

            // Validar existencia y estado de la orden
            $stmtOrd = $pdo->prepare("
                SELECT id, proveedor_id, estado, numero_orden
                FROM compras.ordenes_compra
                WHERE id = ?
                LIMIT 1
            ");
            $stmtOrd->execute([$ordenId]);
            $orden = $stmtOrd->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                throw new Exception("La orden de compra seleccionada no existe.");
            }
            if (!in_array($orden['estado'], ['PENDIENTE', 'RECIBIDA_PARCIAL'], true)) {
                throw new Exception("La orden {$orden['numero_orden']} tiene estado '{$orden['estado']}' y no admite recepciones.");
            }

            if (empty($numFactura)) {
                throw new Exception("El número de factura del proveedor es obligatorio.");
            }
            if (strlen($numFactura) > 50) {
                throw new Exception("El número de factura no puede superar 50 caracteres.");
            }

            if (empty($ncfProveedor)) {
                throw new Exception("El NCF del proveedor es obligatorio para efectos fiscales.");
            }
            if (strlen($ncfProveedor) > 13) {
                throw new Exception("El NCF no puede superar los 13 caracteres (formato DGII).");
            }

            if (!in_array($condicionPago, ['CONTADO', 'CREDITO'], true)) {
                throw new Exception("La condición de pago debe ser CONTADO o CREDITO.");
            }

            // 2. Validaciones de Líneas y Multi-Lote
            if (!is_array($lineasRecibidas) || count($lineasRecibidas) === 0) {
                throw new Exception("No se recibieron datos de productos.");
            }

            // Consultar las líneas pendientes actuales de la orden en la base de datos
            $stmtPendBD = $pdo->prepare("
                SELECT d.producto_maestro_id,
                       d.cantidad_pendiente,
                       d.costo_pactado_unitario,
                       pm.sku,
                       COALESCE(
                           NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                           (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                           pm.sku
                       ) AS producto_nombre
                FROM compras.orden_compra_detalles d
                JOIN inventario.productos_maestro pm ON pm.id = d.producto_maestro_id
                WHERE d.orden_compra_id = ?
            ");
            $stmtPendBD->execute([$ordenId]);
            $dbPendientes = [];
            foreach ($stmtPendBD->fetchAll(PDO::FETCH_ASSOC) as $pRow) {
                $dbPendientes[$pRow['producto_maestro_id']] = $pRow;
            }

            // Filtrar solo líneas con cantidad_recibida_buena > 0 (permite entrega parcial con productos en 0)
            $lineasValidas = [];
            $lotesPorProducto = [];
            $cantidadesPorProducto = [];

            foreach ($lineasRecibidas as $idx => $linea) {
                $prodId = (int)($linea['producto_maestro_id'] ?? 0);
                $cantRec = (int)($linea['cantidad_recibida_buena'] ?? 0);
                $costoFact = (float)($linea['costo_unitario_facturado'] ?? 0);
                $lote = trim($linea['numero_lote'] ?? '');
                $venc = trim($linea['fecha_vencimiento'] ?? '');

                // Si viene en 0 o negativo, se ignora (se permite dejar productos en 0)
                if ($cantRec <= 0) {
                    continue;
                }

                if ($prodId <= 0 || !isset($dbPendientes[$prodId])) {
                    throw new Exception("El producto ID {$prodId} no pertenece a esta orden de compra.");
                }

                $infoProd = $dbPendientes[$prodId];

                if (empty($lote)) {
                    throw new Exception("Debe ingresar el número de lote para el producto '{$infoProd['producto_nombre']}'.");
                }
                if (strlen($lote) > 50) {
                    throw new Exception("El lote '{$lote}' supera los 50 caracteres permitidos.");
                }

                if (empty($venc)) {
                    throw new Exception("Debe ingresar la fecha de vencimiento para el lote '{$lote}' del producto '{$infoProd['producto_nombre']}'.");
                }
                $dtVenc = DateTime::createFromFormat('Y-m-d', $venc);
                if (!$dtVenc || $dtVenc->format('Y-m-d') !== $venc) {
                    throw new Exception("La fecha de vencimiento '{$venc}' del lote '{$lote}' no tiene un formato válido (AAAA-MM-DD).");
                }

                if ($costoFact <= 0) {
                    throw new Exception("El costo unitario facturado para el producto '{$infoProd['producto_nombre']}' debe ser mayor a cero.");
                }

                // REGLA: No permitir numero_lote repetido para el mismo producto_maestro_id dentro de la misma recepción
                if (!isset($lotesPorProducto[$prodId])) {
                    $lotesPorProducto[$prodId] = [];
                    $cantidadesPorProducto[$prodId] = 0;
                }

                $loteKey = strtoupper($lote);
                if (in_array($loteKey, $lotesPorProducto[$prodId], true)) {
                    throw new Exception("El número de lote '{$lote}' está repetido para el producto '{$infoProd['producto_nombre']}'. Cada sub-lote de un mismo producto debe tener un número de lote único.");
                }
                $lotesPorProducto[$prodId][] = $loteKey;
                $cantidadesPorProducto[$prodId] += $cantRec;

                $lineasValidas[] = [
                    'producto_maestro_id'      => $prodId,
                    'numero_lote'              => $lote,
                    'fecha_vencimiento'        => $venc,
                    'cantidad_recibida_buena'  => $cantRec,
                    'costo_unitario_facturado' => round($costoFact, 2),
                    'orden_compra_id'          => $ordenId
                ];
            }

            // REGLA: Exigir que al menos una línea tenga cantidad_recibida_buena > 0 para guardar
            if (empty($lineasValidas)) {
                throw new Exception("Debe ingresar al menos una línea con cantidad recibida mayor a cero.");
            }

            // REGLA: La suma de cantidad_recibida_buena de todos los lotes de un mismo producto NO puede exceder su cantidad_pendiente
            foreach ($cantidadesPorProducto as $prodId => $totalRecibidoProd) {
                $infoProd = $dbPendientes[$prodId];
                $pendienteMax = (int)$infoProd['cantidad_pendiente'];

                if ($totalRecibidoProd > $pendienteMax) {
                    throw new Exception("La suma recibida ({$totalRecibidoProd} unidades) para el producto '{$infoProd['producto_nombre']}' excede la cantidad pendiente ({$pendienteMax} unidades).");
                }
            }

            // Calcular Monto Total de la Factura Física
            $montoTotalFactura = 0;
            foreach ($lineasValidas as $lv) {
                $montoTotalFactura += ($lv['cantidad_recibida_buena'] * $lv['costo_unitario_facturado']);
            }
            $montoTotalFactura = round($montoTotalFactura, 2);

            $usuarioId = $_SESSION['usuario']['usuario_id'];

            // INSERCIÓN ATÓMICA EN TRANSACCIÓN PDO
            // La base de datos (PostgreSQL) se encarga de disparar los triggers que actualizan:
            // 1. inventario.lotes e inventario.inventario_stock
            // 2. Kardex (movimientos_inventario y movimiento_inventario_detalles)
            // 3. Recálculo de costo_promedio en productos_maestro
            // 4. Creación de deuda en compras.cuentas_por_pagar
            // 5. compras.orden_compra_detalles.cantidad_recibida_total y estado en ordenes_compra
            $pdo->beginTransaction();

            $stmtInsRec = $pdo->prepare("
                INSERT INTO compras.recepciones_compra (
                    orden_compra_id,
                    proveedor_id,
                    numero_factura_proveedor,
                    ncf_proveedor,
                    monto_total_factura,
                    usuario_recepcion_id,
                    fecha_recepcion,
                    condicion_pago,
                    moneda_id,
                    tasa_cambio
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    CURRENT_TIMESTAMP, ?, 1, 1.0000
                ) RETURNING id
            ");
            $stmtInsRec->execute([
                $ordenId,
                $orden['proveedor_id'],
                $numFactura,
                $ncfProveedor,
                $montoTotalFactura,
                $usuarioId,
                $condicionPago
            ]);
            $recepcionId = (int)$stmtInsRec->fetchColumn();

            $stmtInsDet = $pdo->prepare("
                INSERT INTO compras.recepcion_compra_detalles (
                    recepcion_id,
                    producto_maestro_id,
                    numero_lote,
                    fecha_vencimiento,
                    cantidad_recibida_buena,
                    costo_unitario_facturado,
                    orden_compra_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($lineasValidas as $l) {
                $stmtInsDet->execute([
                    $recepcionId,
                    $l['producto_maestro_id'],
                    $l['numero_lote'],
                    $l['fecha_vencimiento'],
                    $l['cantidad_recibida_buena'],
                    $l['costo_unitario_facturado'],
                    $ordenId
                ]);
            }

            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'mensaje' => "Recepción #{$recepcionId} (Factura: {$numFactura}) procesada exitosamente. Se ha actualizado el inventario, Kardex, lotes y cuenta por pagar.",
                'recepcion_id' => $recepcionId,
                'orden_id' => $ordenId
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
