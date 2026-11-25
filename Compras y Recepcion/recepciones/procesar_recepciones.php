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

            $ordenId          = !empty($input['orden_compra_id']) ? (int)$input['orden_compra_id'] : null;
            $proveedorIdInput = !empty($input['proveedor_id']) ? trim((string)$input['proveedor_id']) : null;
            $numFactura       = trim($input['numero_factura_proveedor'] ?? '');
            $ncfProveedor     = trim($input['ncf_proveedor'] ?? '');
            $condicionPago    = strtoupper(trim($input['condicion_pago'] ?? 'CREDITO'));
            $lineasRecibidas  = $input['lineas'] ?? [];

            if (is_string($lineasRecibidas)) {
                $lineasRecibidas = json_decode($lineasRecibidas, true) ?: [];
            }

            // 1. Validaciones de Cabecera Física
            $proveedorId = null;
            if ($ordenId !== null && $ordenId > 0) {
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
                $proveedorId = $orden['proveedor_id'];
            } else {
                // Recepción directa sin orden de compra (orden_compra_id = NULL)
                $ordenId = null;
                if (empty($proveedorIdInput)) {
                    throw new Exception("Debe seleccionar un proveedor para registrar la recepción directa.");
                }
                $stmtProv = $pdo->prepare("SELECT persona_id FROM identidad.proveedores WHERE persona_id = ?");
                $stmtProv->execute([$proveedorIdInput]);
                if (!$stmtProv->fetchColumn()) {
                    throw new Exception("El proveedor seleccionado no existe o es inválido.");
                }
                $proveedorId = $proveedorIdInput;
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
                throw new Exception("No se recibieron datos de productos para ingresar.");
            }

            $dbPendientes = [];
            if ($ordenId !== null) {
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
                foreach ($stmtPendBD->fetchAll(PDO::FETCH_ASSOC) as $pRow) {
                    $dbPendientes[$pRow['producto_maestro_id']] = $pRow;
                }
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

                $prodNombre = 'Producto';
                if ($ordenId !== null) {
                    if ($prodId <= 0 || !isset($dbPendientes[$prodId])) {
                        throw new Exception("El producto ID {$prodId} no pertenece a esta orden de compra.");
                    }
                    $infoProd = $dbPendientes[$prodId];
                    $prodNombre = $infoProd['producto_nombre'];
                } else {
                    if ($prodId <= 0) {
                        throw new Exception("ID de producto inválido.");
                    }
                    $stmtPInfo = $pdo->prepare("SELECT nombre, sku FROM inventario.productos_maestro WHERE id = ?");
                    $stmtPInfo->execute([$prodId]);
                    $pRow = $stmtPInfo->fetch(PDO::FETCH_ASSOC);
                    if (!$pRow) {
                        throw new Exception("El producto ID {$prodId} no existe en catálogo.");
                    }
                    $prodNombre = $pRow['nombre'] ?? $pRow['sku'];
                }

                if (empty($lote)) {
                    throw new Exception("Debe ingresar el número de lote para el producto '{$prodNombre}'.");
                }
                if (strlen($lote) > 50) {
                    throw new Exception("El lote '{$lote}' supera los 50 caracteres permitidos.");
                }

                if (empty($venc)) {
                    throw new Exception("Debe ingresar la fecha de vencimiento para el lote '{$lote}' del producto '{$prodNombre}'.");
                }
                $dtVenc = DateTime::createFromFormat('Y-m-d', $venc);
                if (!$dtVenc || $dtVenc->format('Y-m-d') !== $venc) {
                    throw new Exception("La fecha de vencimiento '{$venc}' del lote '{$lote}' no tiene un formato válido (AAAA-MM-DD).");
                }

                if ($costoFact <= 0) {
                    throw new Exception("El costo unitario facturado para el producto '{$prodNombre}' debe ser mayor a cero.");
                }

                // REGLA: No permitir numero_lote repetido para el mismo producto_maestro_id dentro de la misma recepción
                if (!isset($lotesPorProducto[$prodId])) {
                    $lotesPorProducto[$prodId] = [];
                    $cantidadesPorProducto[$prodId] = 0;
                }

                $loteKey = strtoupper($lote);
                if (in_array($loteKey, $lotesPorProducto[$prodId], true)) {
                    throw new Exception("El número de lote '{$lote}' está repetido para el producto '{$prodNombre}'. Cada sub-lote de un mismo producto debe tener un número de lote único.");
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

            // REGLA: La suma de cantidad_recibida_buena de todos los lotes de un mismo producto NO puede exceder su cantidad_pendiente si viene de una orden
            if ($ordenId !== null) {
                foreach ($cantidadesPorProducto as $prodId => $totalRecibidoProd) {
                    if (isset($dbPendientes[$prodId])) {
                        $infoProd = $dbPendientes[$prodId];
                        $pendienteMax = (int)$infoProd['cantidad_pendiente'];

                        if ($totalRecibidoProd > $pendienteMax) {
                            throw new Exception("La suma recibida ({$totalRecibidoProd} unidades) para el producto '{$infoProd['producto_nombre']}' excede la cantidad pendiente ({$pendienteMax} unidades).");
                        }
                    }
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
                $proveedorId,
                $numFactura,
                $ncfProveedor,
                $montoTotalFactura,
                $usuarioId,
                $condicionPago
            ]);
            $recepcionId = (int)$stmtInsRec->fetchColumn();

            // 1. Sentencias preparadas para recepción, inventario, lotes y Kardex
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

            // Cabecera de Kardex / Movimiento de Inventario (ENTRADA_COMPRA)
            $stmtMov = $pdo->prepare("
                INSERT INTO inventario.movimientos_inventario (
                    tipo_movimiento,
                    justificacion,
                    supervisor_id,
                    documento_referencia_id,
                    fecha_movimiento
                ) VALUES (
                    'ENTRADA_COMPRA',
                    ?,
                    ?,
                    ?,
                    CURRENT_TIMESTAMP
                ) RETURNING id
            ");
            $justificacionMov = "Ingreso físico por Recepción de Compra #{$recepcionId} • Factura {$numFactura} (NCF: {$ncfProveedor})";
            $stmtMov->execute([$justificacionMov, $usuarioId, $recepcionId]);
            $movimientoId = (int)$stmtMov->fetchColumn();

            // Sentencias para búsqueda / actualización / inserción de Lotes FEFO
            $stmtBuscarLote = $pdo->prepare("
                SELECT id, cantidad_actual 
                FROM inventario.lotes 
                WHERE producto_maestro_id = ? AND UPPER(numero_lote) = UPPER(?)
                LIMIT 1
            ");

            $stmtActualizarLote = $pdo->prepare("
                UPDATE inventario.lotes 
                SET cantidad_actual = cantidad_actual + ?,
                    fecha_vencimiento = ?
                WHERE id = ?
            ");

            $stmtInsertarLote = $pdo->prepare("
                INSERT INTO inventario.lotes (
                    producto_maestro_id,
                    numero_lote,
                    fecha_vencimiento,
                    cantidad_actual
                ) VALUES (?, ?, ?, ?)
                RETURNING id
            ");

            // Sentencia para actualización atómica del stock físico en bodega (Almacén principal ID = 1)
            $almacenId = 1;
            $stmtUpsertStock = $pdo->prepare("
                INSERT INTO inventario.inventario_stock (
                    almacen_id,
                    producto_maestro_id,
                    lote_id,
                    stock_fisico_unidades,
                    stock_comprometido
                ) VALUES (?, ?, ?, ?, 0)
                ON CONFLICT (almacen_id, producto_maestro_id, lote_id)
                DO UPDATE SET stock_fisico_unidades = inventario.inventario_stock.stock_fisico_unidades + EXCLUDED.stock_fisico_unidades
            ");

            // Sentencia para renglones del Kardex
            $stmtInsMovDet = $pdo->prepare("
                INSERT INTO inventario.movimiento_inventario_detalles (
                    movimiento_id,
                    producto_maestro_id,
                    lote_id,
                    cantidad_unidades,
                    costo_unitario_referencia
                ) VALUES (?, ?, ?, ?, ?)
            ");

            // Sentencia para recalcular costo promedio ponderado en producto maestro
            $stmtActualizarCostoProm = $pdo->prepare("
                UPDATE inventario.productos_maestro
                SET costo_promedio = ROUND(
                    COALESCE(
                        (costo_promedio * 0.5 + ? * 0.5),
                        ?
                    ), 2)
                WHERE id = ?
            ");

            // Sentencia para amortizar orden de compra
            $stmtActualizarOcDet = $pdo->prepare("
                UPDATE compras.orden_compra_detalles
                SET cantidad_recibida_total = cantidad_recibida_total + ?
                WHERE orden_compra_id = ? AND producto_maestro_id = ?
            ");

            foreach ($lineasValidas as $l) {
                // A. Guardar detalle de recepción
                $stmtInsDet->execute([
                    $recepcionId,
                    $l['producto_maestro_id'],
                    $l['numero_lote'],
                    $l['fecha_vencimiento'],
                    $l['cantidad_recibida_buena'],
                    $l['costo_unitario_facturado'],
                    $ordenId
                ]);

                // B. Registrar o actualizar Lote FEFO
                $stmtBuscarLote->execute([$l['producto_maestro_id'], $l['numero_lote']]);
                $loteExistente = $stmtBuscarLote->fetch(PDO::FETCH_ASSOC);
                $loteId = null;

                if ($loteExistente) {
                    $loteId = (int)$loteExistente['id'];
                    $stmtActualizarLote->execute([
                        $l['cantidad_recibida_buena'],
                        $l['fecha_vencimiento'],
                        $loteId
                    ]);
                } else {
                    $stmtInsertarLote->execute([
                        $l['producto_maestro_id'],
                        $l['numero_lote'],
                        $l['fecha_vencimiento'],
                        $l['cantidad_recibida_buena']
                    ]);
                    $loteId = (int)$stmtInsertarLote->fetchColumn();
                }

                // C. Incrementar existencia física en inventario_stock
                $stmtUpsertStock->execute([
                    $almacenId,
                    $l['producto_maestro_id'],
                    $loteId,
                    $l['cantidad_recibida_buena']
                ]);

                // D. Insertar línea en detalle de Kardex
                $stmtInsMovDet->execute([
                    $movimientoId,
                    $l['producto_maestro_id'],
                    $loteId,
                    $l['cantidad_recibida_buena'],
                    $l['costo_unitario_facturado']
                ]);

                // E. Actualizar Costo Promedio del Catálogo
                $stmtActualizarCostoProm->execute([
                    $l['costo_unitario_facturado'],
                    $l['costo_unitario_facturado'],
                    $l['producto_maestro_id']
                ]);

                // F. Si proviene de Orden de Compra, amortizar cantidad recibida en la orden
                if ($ordenId !== null && $ordenId > 0) {
                    $stmtActualizarOcDet->execute([
                        $l['cantidad_recibida_buena'],
                        $ordenId,
                        $l['producto_maestro_id']
                    ]);
                }
            }

            // 2. Si proviene de Orden de Compra, actualizar el estado de la cabecera de la orden
            if ($ordenId !== null && $ordenId > 0) {
                $stmtCalcPend = $pdo->prepare("
                    SELECT COALESCE(SUM(cantidad_pendiente), 0) AS saldo_pendiente_total
                    FROM compras.orden_compra_detalles
                    WHERE orden_compra_id = ?
                ");
                $stmtCalcPend->execute([$ordenId]);
                $saldoPend = (int)$stmtCalcPend->fetchColumn();

                $nuevoEstadoOrden = ($saldoPend === 0) ? 'COMPLETADA' : 'RECIBIDA_PARCIAL';
                $stmtUpdOc = $pdo->prepare("UPDATE compras.ordenes_compra SET estado = ? WHERE id = ?");
                $stmtUpdOc->execute([$nuevoEstadoOrden, $ordenId]);
            }

            // 3. Generar la Cuenta por Pagar (CxP) comercial
            $stmtProvDias = $pdo->prepare("SELECT dias_credito_habitual FROM identidad.proveedores WHERE persona_id = ?");
            $stmtProvDias->execute([$proveedorId]);
            $diasHab = (int)($stmtProvDias->fetchColumn() ?: 30);

            $diasPlazo = ($condicionPago === 'CONTADO') ? 0 : max(1, $diasHab);
            $fechaVenc = date('Y-m-d', strtotime("+{$diasPlazo} days"));

            $stmtInsCxp = $pdo->prepare("
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
                    ?, ?, ?, ?,
                    CURRENT_DATE, ?, 'PENDIENTE', 1, 1.0000
                ) RETURNING id
            ");
            $stmtInsCxp->execute([
                $proveedorId,
                $recepcionId,
                $montoTotalFactura,
                $montoTotalFactura,
                $fechaVenc
            ]);
            $cxpId = (int)$stmtInsCxp->fetchColumn();

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
