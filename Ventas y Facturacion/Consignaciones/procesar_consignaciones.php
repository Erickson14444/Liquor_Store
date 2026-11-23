<?php
/**
 * ============================================================================
 * Backend Controlador: Proceso 5 - Consignaciones (Eventos y Bodas)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/Consignaciones/procesar_consignaciones.php
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/funciones.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $usuarioId = requerirSesionActiva(true);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Sesión expirada o no válida.']);
    exit;
}

if (!function_exists('usuarioPuedeAccederConsignaciones') || !usuarioPuedeAccederConsignaciones()) {
    echo json_encode(['status' => 'error', 'message' => 'No posee permisos para operar en el módulo de Consignaciones.']);
    exit;
}

$accion = trim($_GET['accion'] ?? $_POST['accion'] ?? '');

try {
    switch ($accion) {

        // ====================================================================
        // 1. BUSCADOR DE PRODUCTOS CON STOCK DISPONIBLE Y LOTES FEFO
        // ====================================================================
        case 'buscar_productos':
            $termino = trim($_GET['termino'] ?? '');
            $almacenId = intval($_GET['almacen_id'] ?? 1);

            if ($termino === '') {
                echo json_encode(['status' => 'success', 'data' => []]);
                exit;
            }

            // Consultar producto maestro, códigos de barra, precio base y lotes disponibles
            $sql = "
                SELECT pm.id,
                       pm.sku,
                       pm.nombre,
                       pm.precio_base_unidad,
                       COALESCE(SUM(ist.stock_fisico_unidades), 0) AS stock_fisico,
                       COALESCE(SUM(ist.stock_comprometido), 0) AS stock_comprometido,
                       COALESCE(SUM(ist.stock_fisico_unidades - ist.stock_comprometido), 0) AS stock_disponible
                FROM inventario.productos_maestro pm
                LEFT JOIN inventario.inventario_stock ist 
                       ON ist.producto_maestro_id = pm.id 
                      AND ist.almacen_id = ?
                WHERE pm.id > 0
                  AND (
                      LOWER(pm.nombre) LIKE LOWER(?) 
                      OR LOWER(pm.sku) LIKE LOWER(?)
                      OR EXISTS (
                          SELECT 1 FROM inventario.producto_codigos_barra pcb 
                          WHERE pcb.producto_maestro_id = pm.id 
                            AND LOWER(pcb.codigo_barra) LIKE LOWER(?)
                      )
                  )
                GROUP BY pm.id, pm.sku, pm.nombre, pm.precio_base_unidad
                ORDER BY pm.nombre ASC
                LIMIT 15
            ";
            $termParam = "%{$termino}%";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$almacenId, $termParam, $termParam, $termParam]);
            $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Obtener lotes FEFO disponibles por producto
            $stmtLotes = $pdo->prepare("
                SELECT ist.lote_id,
                       l.numero_lote,
                       l.fecha_vencimiento,
                       ist.stock_fisico_unidades,
                       ist.stock_comprometido,
                       (ist.stock_fisico_unidades - ist.stock_comprometido) AS disponible
                FROM inventario.inventario_stock ist
                JOIN inventario.lotes l ON l.id = ist.lote_id
                WHERE ist.producto_maestro_id = ?
                  AND ist.almacen_id = ?
                  AND (ist.stock_fisico_unidades - ist.stock_comprometido) > 0
                ORDER BY 
                    CASE WHEN ist.lote_id = 0 THEN 1 ELSE 0 END,
                    l.fecha_vencimiento ASC
            ");

            foreach ($productos as &$p) {
                $p['id'] = (int)$p['id'];
                $p['precio_base_unidad'] = (float)$p['precio_base_unidad'];
                $p['stock_fisico'] = (int)$p['stock_fisico'];
                $p['stock_comprometido'] = (int)$p['stock_comprometido'];
                $p['stock_disponible'] = (int)$p['stock_disponible'];

                $stmtLotes->execute([$p['id'], $almacenId]);
                $p['lotes'] = $stmtLotes->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['status' => 'success', 'data' => $productos]);
            break;

        // ====================================================================
        // 2. FASE 1: CREAR CONSIGNACIÓN, FIANZA Y RESERVAR STOCK COMPROMETIDO
        // ====================================================================
        case 'crear_consignacion':
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            if (!$data) {
                throw new Exception("Datos de solicitud inválidos.");
            }

            $clienteId         = trim($data['cliente_id'] ?? '');
            $nombreEvento      = trim($data['nombre_evento'] ?? '');
            $responsableEvento = trim($data['responsable_evento'] ?? '');
            $fechaRetorno      = trim($data['fecha_estimada_retorno'] ?? '');
            $almacenId         = intval($data['almacen_id'] ?? 1);
            $montoGarantia     = round((float)($data['monto_deposito_garantia'] ?? 0), 2);
            $metodoGarantiaId  = intval($data['metodo_garantia_id'] ?? 1); // 1=EFECTIVO por defecto
            $items             = $data['items'] ?? [];
            $supervisorId      = trim($data['supervisor_id'] ?? '');
            $pinAutorizacion   = trim($data['pin_autorizacion'] ?? '');

            if ($clienteId === '') {
                throw new Exception("Debe seleccionar el cliente para la consignación.");
            }
            if ($nombreEvento === '') {
                throw new Exception("Debe especificar el nombre del evento.");
            }
            if ($responsableEvento === '') {
                throw new Exception("Debe especificar el responsable del evento.");
            }
            if ($fechaRetorno === '' || strtotime($fechaRetorno) < strtotime(date('Y-m-d'))) {
                throw new Exception("La fecha estimada de retorno debe ser igual o posterior a la fecha actual.");
            }
            if ($montoGarantia < 0) {
                throw new Exception("El monto de depósito de garantía no puede ser negativo.");
            }
            if (empty($items)) {
                throw new Exception("Debe incluir al menos un artículo para el despacho de la consignación.");
            }

            // Validar autorización con PIN de supervisor
            $supValido = validarPinSupervisor($pdo, $supervisorId, $pinAutorizacion);
            $supervisorIdValido = $supValido['usuario_id'];

            // Verificar si el cajero en sesión tiene caja abierta para fianza en efectivo
            $stmtSes = $pdo->prepare("
                SELECT id FROM ventas.sesiones_caja 
                WHERE cajero_id = ? AND estado = 'ABIERTA' 
                ORDER BY id DESC LIMIT 1
            ");
            $personaIdUsuario = $_SESSION['usuario']['persona_id'] ?? null;
            $stmtSes->execute([$personaIdUsuario]);
            $sesionCajaId = $stmtSes->fetchColumn() ?: null;

            $pdo->beginTransaction();

            // 1. Generar número correlativo de consignación
            $seqId = $pdo->query("SELECT nextval('ventas.consignaciones_id_seq')")->fetchColumn();
            $numConsignacion = 'CSG-' . date('Y') . '-' . str_pad((string)$seqId, 4, '0', STR_PAD_LEFT);

            // 2. Insertar cabecera de consignación
            $stmtInsCsg = $pdo->prepare("
                INSERT INTO ventas.consignaciones (
                    id, numero_consignacion, cliente_id, responsable_evento, nombre_evento,
                    fecha_despacho, fecha_estimada_retorno, monto_deposito_garantia, estado,
                    supervisor_id, sesion_caja_id, moneda_id, tasa_cambio
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    CURRENT_TIMESTAMP, ?, ?, 'DESPACHADA',
                    ?, ?, 1, 1.0000
                )
            ");
            $stmtInsCsg->execute([
                $seqId,
                $numConsignacion,
                $clienteId,
                $responsableEvento,
                $nombreEvento,
                $fechaRetorno,
                $montoGarantia,
                $supervisorIdValido,
                $sesionCajaId
            ]);

            // 3. Registrar Fianza / Depósito de Garantía si es > 0
            if ($montoGarantia > 0) {
                // Insertar en tesoreria.detalles_pago
                $stmtDetPago = $pdo->prepare("
                    INSERT INTO tesoreria.detalles_pago (
                        metodo_pago_id, moneda_id, tasa_cambio, monto_pagado, monto_devuelta,
                        sesion_caja_id, fecha_pago
                    ) VALUES (
                        ?, 1, 1.0000, ?, 0.00,
                        ?, CURRENT_TIMESTAMP
                    ) RETURNING id
                ");
                $sesPago = ($metodoGarantiaId === 1 && $sesionCajaId) ? $sesionCajaId : null;
                $stmtDetPago->execute([$metodoGarantiaId, $montoGarantia, $sesPago]);
                $detallePagoId = (int)$stmtDetPago->fetchColumn();

                // Vincular en puente ventas.consignacion_garantia_pagos
                $stmtGarantiaPago = $pdo->prepare("
                    INSERT INTO ventas.consignacion_garantia_pagos (consignacion_id, detalle_pago_id)
                    VALUES (?, ?)
                ");
                $stmtGarantiaPago->execute([$seqId, $detallePagoId]);

                // Si fue EFECTIVO en mostrador, asentar automáticamente un INGRESO en ventas.movimiento_caja
                // para blindar el dinero físico en el Arqueo Ciego
                if ($metodoGarantiaId === 1 && $sesionCajaId) {
                    $stmtMovCaja = $pdo->prepare("
                        INSERT INTO ventas.movimiento_caja (
                            sesion_caja_id, tipo_movimiento, monto, concepto, supervisor_id, fecha_hora
                        ) VALUES (
                            ?, 'INGRESO', ?, ?, ?, CURRENT_TIMESTAMP
                        )
                    ");
                    $conceptoIngreso = "Fianza de garantía consignación {$numConsignacion} ({$nombreEvento})";
                    $stmtMovCaja->execute([$sesionCajaId, $montoGarantia, $conceptoIngreso, $supervisorIdValido]);
                }
            }

            // 4. Procesar artículos, congelar precio pactado y sumar a stock_comprometido
            $stmtInsDet = $pdo->prepare("
                INSERT INTO ventas.consignaciones_detalle (
                    consignacion_id, producto_maestro_id, lote_id,
                    cantidad_despachada, cantidad_devuelta, precio_pactado_unitario
                ) VALUES (?, ?, ?, ?, 0, ?)
            ");

            $stmtLockStock = $pdo->prepare("
                SELECT id, stock_fisico_unidades, stock_comprometido
                FROM inventario.inventario_stock
                WHERE almacen_id = ? AND producto_maestro_id = ? AND lote_id = ?
                FOR UPDATE
            ");

            $stmtSumComprometido = $pdo->prepare("
                UPDATE inventario.inventario_stock
                SET stock_comprometido = stock_comprometido + ?
                WHERE id = ?
            ");

            $totalArticulosDespachados = 0;

            foreach ($items as $item) {
                $prodId = (int)($item['producto_maestro_id'] ?? 0);
                $loteId = (int)($item['lote_id'] ?? 0); // 0 = Lote comodín
                $cantDespacho = (int)($item['cantidad_despachada'] ?? 0);
                $precioPactado = round((float)($item['precio_pactado_unitario'] ?? 0), 2);

                if ($prodId <= 0 || $cantDespacho <= 0 || $precioPactado < 0) {
                    throw new Exception("Datos de artículo inválidos en el listado de despacho.");
                }

                // Verificar stock y reserva en inventario_stock
                $stmtLockStock->execute([$almacenId, $prodId, $loteId]);
                $stockRow = $stmtLockStock->fetch(PDO::FETCH_ASSOC);

                if (!$stockRow) {
                    // Si es lote 0 comodín y no existe registro de stock previo, intentar consultar lote 0
                    throw new Exception("No existe registro de inventario para el producto #{$prodId} en el almacén seleccionado.");
                }

                $stockFisico = (int)$stockRow['stock_fisico_unidades'];
                $stockComprometido = (int)$stockRow['stock_comprometido'];
                $disponible = $stockFisico - $stockComprometido;

                if ($cantDespacho > $disponible) {
                    throw new Exception("Stock insuficiente para despachar el producto #{$prodId}. Disponible: {$disponible}, Solicitado: {$cantDespacho}.");
                }

                // Insertar detalle
                $stmtInsDet->execute([$seqId, $prodId, $loteId, $cantDespacho, $precioPactado]);

                // Sumar a stock_comprometido (sin restar de stock_fisico_unidades)
                $stmtSumComprometido->execute([$cantDespacho, $stockRow['id']]);

                $totalArticulosDespachados += $cantDespacho;
            }

            // Registrar en auditoría forense
            registrarAuditoria(
                $pdo,
                $usuarioId,
                $supervisorIdValido,
                'CREAR_CONSIGNACION',
                'ventas.consignaciones',
                null,
                [
                    'consignacion_id' => $seqId,
                    'numero_consignacion' => $numConsignacion,
                    'cliente_id' => $clienteId,
                    'nombre_evento' => $nombreEvento,
                    'fecha_estimada_retorno' => $fechaRetorno,
                    'monto_deposito_garantia' => $montoGarantia,
                    'total_articulos_despachados' => $totalArticulosDespachados,
                    'almacen_id' => $almacenId
                ]
            );

            $pdo->commit();

            echo json_encode([
                'status' => 'success',
                'message' => "Consignación {$numConsignacion} creada y despachada exitosamente.",
                'consignacion_id' => $seqId,
                'numero_consignacion' => $numConsignacion
            ]);
            break;

        // ====================================================================
        // 3. CONSULTAR DETALLE COMPLETO DE EXPEDIENTE
        // ====================================================================
        case 'obtener_detalle_consignacion':
            $csgId = intval($_GET['id'] ?? 0);
            if ($csgId <= 0) {
                throw new Exception("ID de consignación inválido.");
            }

            // Cabecera
            $stmtCsg = $pdo->prepare("
                SELECT c.id,
                       c.numero_consignacion,
                       c.cliente_id,
                       c.responsable_evento,
                       c.nombre_evento,
                       c.fecha_despacho,
                       c.fecha_estimada_retorno,
                       c.monto_deposito_garantia,
                       c.estado,
                       c.sesion_caja_id,
                       p.nombre_razon_social AS cliente_nombre,
                       p.documento_identidad AS cliente_rnc,
                       p.telefono_principal AS cliente_telefono,
                       u.username AS supervisor_username,
                       m.simbolo AS moneda_simbolo
                FROM ventas.consignaciones c
                JOIN identidad.clientes cl ON cl.persona_id = c.cliente_id
                JOIN identidad.personas p ON p.persona_id = cl.persona_id
                JOIN tesoreria.monedas m ON m.id = c.moneda_id
                JOIN seguridad.usuarios u ON u.usuario_id = c.supervisor_id
                WHERE c.id = ?
            ");
            $stmtCsg->execute([$csgId]);
            $consignacion = $stmtCsg->fetch(PDO::FETCH_ASSOC);

            if (!$consignacion) {
                throw new Exception("Consignación #{$csgId} no encontrada.");
            }

            // Detalles y artículos con consumo automático generado
            $stmtDet = $pdo->prepare("
                SELECT cd.producto_maestro_id,
                       cd.lote_id,
                       cd.cantidad_despachada,
                       cd.cantidad_devuelta,
                       cd.cantidad_consumida,
                       cd.precio_pactado_unitario,
                       (cd.cantidad_consumida * cd.precio_pactado_unitario) AS subtotal_consumido,
                       (cd.cantidad_despachada * cd.precio_pactado_unitario) AS subtotal_despachado,
                       pm.sku,
                       pm.nombre AS producto_nombre,
                       COALESCE(l.numero_lote, 'GENERICO - SIN LOTE') AS numero_lote,
                       l.fecha_vencimiento
                FROM ventas.consignaciones_detalle cd
                JOIN inventario.productos_maestro pm ON pm.id = cd.producto_maestro_id
                LEFT JOIN inventario.lotes l ON l.id = cd.lote_id
                WHERE cd.consignacion_id = ?
                ORDER BY pm.nombre ASC
            ");
            $stmtDet->execute([$csgId]);
            $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

            // Factura fiscal asociada (si ya fue liquidada)
            $stmtFac = $pdo->prepare("
                SELECT f.id, f.numero_factura, f.tipo_ncf, f.ncf, f.monto_total, f.fecha_emision, f.estado
                FROM ventas.consignacion_facturas cf
                JOIN ventas.facturas f ON f.id = cf.factura_id
                WHERE cf.consignacion_id = ?
                LIMIT 1
            ");
            $stmtFac->execute([$csgId]);
            $factura = $stmtFac->fetch(PDO::FETCH_ASSOC);

            // Depósito de garantía y pagos
            $stmtPagos = $pdo->prepare("
                SELECT dp.id, dp.monto_pagado, dp.fecha_pago, mp.codigo AS metodo_codigo, mp.descripcion AS metodo_nombre
                FROM ventas.consignacion_garantia_pagos cgp
                JOIN tesoreria.detalles_pago dp ON dp.id = cgp.detalle_pago_id
                JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id
                WHERE cgp.consignacion_id = ?
            ");
            $stmtPagos->execute([$csgId]);
            $pagosGarantia = $stmtPagos->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'consignacion' => $consignacion,
                    'detalles' => $detalles,
                    'factura' => $factura,
                    'pagos_garantia' => $pagosGarantia
                ]
            ]);
            break;

        // ====================================================================
        // 4. FASE 2: REGISTRAR RETORNO POST-EVENTO (CONTEO FÍSICO)
        // ====================================================================
        case 'registrar_retorno':
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            if (!$data) {
                throw new Exception("Datos de retorno inválidos.");
            }

            $csgId           = intval($data['consignacion_id'] ?? 0);
            $retornos        = $data['retornos'] ?? []; // [{ producto_maestro_id, lote_id, cantidad_devuelta }]
            $supervisorId    = trim($data['supervisor_id'] ?? '');
            $pinAutorizacion = trim($data['pin_autorizacion'] ?? '');

            if ($csgId <= 0 || empty($retornos)) {
                throw new Exception("Debe especificar la consignación y las cantidades de retorno.");
            }

            // Validar PIN del supervisor que audita el retorno físico
            $supValido = validarPinSupervisor($pdo, $supervisorId, $pinAutorizacion);
            $supervisorIdValido = $supValido['usuario_id'];

            $pdo->beginTransaction();

            $stmtLock = $pdo->prepare("
                SELECT id, estado, numero_consignacion 
                FROM ventas.consignaciones 
                WHERE id = ? FOR UPDATE
            ");
            $stmtLock->execute([$csgId]);
            $csg = $stmtLock->fetch(PDO::FETCH_ASSOC);

            if (!$csg) {
                throw new Exception("Consignación #{$csgId} no encontrada.");
            }
            if ($csg['estado'] !== 'DESPACHADA') {
                throw new Exception("Solo se pueden registrar retornos en consignaciones DESPACHADAS (Estado actual: {$csg['estado']}).");
            }

            $stmtUpdRet = $pdo->prepare("
                UPDATE ventas.consignaciones_detalle
                SET cantidad_devuelta = ?
                WHERE consignacion_id = ? 
                  AND producto_maestro_id = ? 
                  AND lote_id = ?
            ");

            $stmtGetDet = $pdo->prepare("
                SELECT cantidad_despachada 
                FROM ventas.consignaciones_detalle
                WHERE consignacion_id = ? AND producto_maestro_id = ? AND lote_id = ?
            ");

            $cambiosRetorno = [];

            foreach ($retornos as $ret) {
                $prodId = (int)($ret['producto_maestro_id'] ?? 0);
                $loteId = (int)($ret['lote_id'] ?? 0);
                $cantDev = (int)($ret['cantidad_devuelta'] ?? 0);

                if ($cantDev < 0) {
                    throw new Exception("La cantidad devuelta no puede ser negativa.");
                }

                $stmtGetDet->execute([$csgId, $prodId, $loteId]);
                $cantDesp = $stmtGetDet->fetchColumn();

                if ($cantDesp === false) {
                    throw new Exception("El producto #{$prodId} con lote #{$loteId} no pertenece a esta consignación.");
                }

                if ($cantDev > (int)$cantDesp) {
                    throw new Exception("La cantidad devuelta ({$cantDev}) no puede ser mayor que la cantidad despachada ({$cantDesp}) para el producto #{$prodId}.");
                }

                $stmtUpdRet->execute([$cantDev, $csgId, $prodId, $loteId]);

                $cambiosRetorno[] = [
                    'producto_maestro_id' => $prodId,
                    'lote_id' => $loteId,
                    'cantidad_devuelta' => $cantDev,
                    'cantidad_consumida' => ((int)$cantDesp - $cantDev)
                ];
            }

            // Auditoría forense
            registrarAuditoria(
                $pdo,
                $usuarioId,
                $supervisorIdValido,
                'REGISTRAR_RETORNO_CONSIGNACION',
                'ventas.consignaciones_detalle',
                null,
                [
                    'consignacion_id' => $csgId,
                    'numero_consignacion' => $csg['numero_consignacion'],
                    'detalles_retorno' => $cambiosRetorno
                ]
            );

            $pdo->commit();

            echo json_encode([
                'status' => 'success',
                'message' => "Conteo físico y retorno de mercancía registrados exitosamente."
            ]);
            break;

        // ====================================================================
        // 5. FASE 3: LIQUIDACIÓN FINANCIERA, FACTURA B01/B02, SPLIT Y LIBERACIÓN DE STOCK
        // ====================================================================
        case 'liquidar_consignacion':
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            if (!$data) {
                throw new Exception("Datos de liquidación inválidos.");
            }

            $csgId           = intval($data['consignacion_id'] ?? 0);
            $tipoNcf         = strtoupper(trim($data['tipo_ncf'] ?? 'B02')); // B02 o B01
            $almacenId       = intval($data['almacen_id'] ?? 1);
            $pagosDiferencia = $data['pagos_diferencia'] ?? []; // Split de tesorería para la diferencia restante
            $supervisorId    = trim($data['supervisor_id'] ?? '');
            $pinAutorizacion = trim($data['pin_autorizacion'] ?? '');

            if ($csgId <= 0) {
                throw new Exception("ID de consignación inválido.");
            }
            if (!in_array($tipoNcf, ['B01', 'B02'], true)) {
                $tipoNcf = 'B02';
            }

            // Validar autorización del supervisor
            $supValido = validarPinSupervisor($pdo, $supervisorId, $pinAutorizacion);
            $supervisorIdValido = $supValido['usuario_id'];

            // Sesión de caja activa del cajero
            $stmtSes = $pdo->prepare("
                SELECT id FROM ventas.sesiones_caja 
                WHERE cajero_id = ? AND estado = 'ABIERTA' 
                ORDER BY id DESC LIMIT 1
            ");
            $personaIdUsuario = $_SESSION['usuario']['persona_id'] ?? null;
            $stmtSes->execute([$personaIdUsuario]);
            $sesionCajaId = $stmtSes->fetchColumn() ?: null;

            $pdo->beginTransaction();

            // 1. Bloquear consignación para evitar concurrencia
            $stmtLock = $pdo->prepare("
                SELECT c.*, p.documento_identidad, p.nombre_razon_social
                FROM ventas.consignaciones c
                JOIN identidad.clientes cl ON cl.persona_id = c.cliente_id
                JOIN identidad.personas p ON p.persona_id = cl.persona_id
                WHERE c.id = ?
                FOR UPDATE
            ");
            $stmtLock->execute([$csgId]);
            $csg = $stmtLock->fetch(PDO::FETCH_ASSOC);

            if (!$csg) {
                throw new Exception("Consignación #{$csgId} no encontrada.");
            }
            if ($csg['estado'] !== 'DESPACHADA') {
                throw new Exception("Esta consignación ya fue liquidada o cancelada previamente (Estado: {$csg['estado']}).");
            }

            $montoFianza = round((float)$csg['monto_deposito_garantia'], 2);
            $clienteId = $csg['cliente_id'];
            $numConsignacion = $csg['numero_consignacion'];

            // 2. Obtener detalles con cantidades consumidas
            $stmtDet = $pdo->prepare("
                SELECT cd.producto_maestro_id,
                       cd.lote_id,
                       cd.cantidad_despachada,
                       cd.cantidad_devuelta,
                       cd.cantidad_consumida,
                       cd.precio_pactado_unitario,
                       pm.nombre AS producto_nombre,
                       pm.costo_promedio
                FROM ventas.consignaciones_detalle cd
                JOIN inventario.productos_maestro pm ON pm.id = cd.producto_maestro_id
                WHERE cd.consignacion_id = ?
            ");
            $stmtDet->execute([$csgId]);
            $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

            if (empty($detalles)) {
                throw new Exception("La consignación no posee artículos registrados.");
            }

            $totalConsumido = 0.00;
            $itemsConsumidos = [];
            $totalBotellasConsumidas = 0;
            $totalBotellasDevueltas = 0;

            foreach ($detalles as $d) {
                $consumidas = (int)$d['cantidad_consumida'];
                $devueltas = (int)$d['cantidad_devuelta'];
                $precio = (float)$d['precio_pactado_unitario'];

                $totalBotellasConsumidas += $consumidas;
                $totalBotellasDevueltas += $devueltas;

                if ($consumidas > 0) {
                    $subt = round($consumidas * $precio, 2);
                    $totalConsumido += $subt;
                    $itemsConsumidos[] = [
                        'producto_maestro_id' => (int)$d['producto_maestro_id'],
                        'lote_id' => (int)$d['lote_id'],
                        'cantidad' => $consumidas,
                        'precio_unitario' => $precio,
                        'costo_promedio' => (float)$d['costo_promedio'],
                        'subtotal' => $subt
                    ];
                }
            }

            $facturaIdGenerada = null;
            $numeroFacturaGenerada = null;
            $ncfGenerado = null;

            // 3. Emisión de Factura Fiscal si hubo consumo
            if ($totalConsumido > 0) {
                // Obtener secuencia fiscal activa para el tipo de NCF solicitado
                $stmtSec = $pdo->prepare("
                    SELECT id, secuencia_actual, secuencia_hasta, fecha_vencimiento
                    FROM ventas.secuencias_fiscales_dgii
                    WHERE tipo_comprobante = ?
                      AND estado = 'ACTIVO'
                    ORDER BY id ASC
                    LIMIT 1
                    FOR UPDATE
                ");
                $stmtSec->execute([$tipoNcf]);
                $secRow = $stmtSec->fetch(PDO::FETCH_ASSOC);

                if (!$secRow) {
                    throw new Exception("No existe una secuencia fiscal DGII activa para {$tipoNcf}. Configure una secuencia en el panel.");
                }

                $secuenciaDgiiId = (int)$secRow['id'];
                $secActual = (int)$secRow['secuencia_actual'];
                $secHasta = (int)$secRow['secuencia_hasta'];

                if ($secActual > $secHasta) {
                    throw new Exception("La secuencia fiscal {$tipoNcf} está agotada.");
                }

                $ncfGenerado = $tipoNcf . str_pad((string)$secActual, 8, '0', STR_PAD_LEFT);

                // Incrementar secuencia actual
                if ($secActual < $secHasta) {
                    $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET secuencia_actual = secuencia_actual + 1 WHERE id = ?")->execute([$secuenciaDgiiId]);
                } else {
                    $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'AGOTADO' WHERE id = ?")->execute([$secuenciaDgiiId]);
                }

                // Generar ID correlativo de factura
                $facturaIdGenerada = (int)$pdo->query("SELECT nextval('ventas.facturas_id_seq')")->fetchColumn();
                $numeroFacturaGenerada = 'FAC-' . date('Ymd') . '-' . str_pad((string)$facturaIdGenerada, 5, '0', STR_PAD_LEFT);

                // Insertar cabecera de factura fiscal
                $stmtInsFac = $pdo->prepare("
                    INSERT INTO ventas.facturas (
                        id, numero_factura, sesion_caja_id, cliente_id, vendedor_id,
                        secuencia_dgii_id, tipo_ncf, ncf, condicion_pago,
                        monto_subtotal, monto_descuento, monto_impuestos, monto_total,
                        estado, fecha_emision, origen_venta, rnc_cedula_cliente, razon_social_cliente,
                        moneda_id, tasa_cambio
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, 'CONTADO',
                        ?, 0.00, 0.00, ?,
                        'EMITIDA', CURRENT_TIMESTAMP, 'CONSIGNACION_LIQUIDADA', ?, ?,
                        1, 1.0000
                    )
                ");
                $stmtInsFac->execute([
                    $facturaIdGenerada,
                    $numeroFacturaGenerada,
                    $sesionCajaId ?: 1, // Si no hay sesión, asociar a caja base
                    $clienteId,
                    $usuarioId,
                    $secuenciaDgiiId,
                    $tipoNcf,
                    $ncfGenerado,
                    $totalConsumido,
                    $totalConsumido,
                    $csg['documento_identidad'] ?: '',
                    $csg['nombre_razon_social'] ?: 'Cliente Consignación'
                ]);

                // Insertar factura_detalle
                $stmtInsFacDet = $pdo->prepare("
                    INSERT INTO ventas.factura_detalle (
                        factura_id, producto_maestro_id, cantidad_unidades, precio_unitario_his,
                        codigo_barra_id, factor_conversion, cantidad_presentacion, costo_unitario_hist,
                        promocion_aplicada, monto_descuento, monto_impuestos, monto_subtotal
                    ) VALUES (
                        ?, ?, ?, ?,
                        NULL, 1.0000, ?, ?,
                        NULL, 0.00, 0.00, ?
                    )
                ");

                foreach ($itemsConsumidos as $ic) {
                    $stmtInsFacDet->execute([
                        $facturaIdGenerada,
                        $ic['producto_maestro_id'],
                        $ic['cantidad'],
                        $ic['precio_unitario'],
                        $ic['cantidad'],
                        $ic['costo_promedio'],
                        $ic['subtotal']
                    ]);
                }

                // Vincular en puente ventas.consignacion_facturas
                $stmtVinFac = $pdo->prepare("
                    INSERT INTO ventas.consignacion_facturas (consignacion_id, factura_id)
                    VALUES (?, ?)
                ");
                $stmtVinFac->execute([$csgId, $facturaIdGenerada]);

                // 4. Aplicación de la Fianza como SALDO_FAVOR
                $fianzaAplicada = min($totalConsumido, $montoFianza);

                if ($fianzaAplicada > 0) {
                    // Registrar pago mediante método SALDO_FAVOR (id = 5)
                    $stmtDetFianza = $pdo->prepare("
                        INSERT INTO tesoreria.detalles_pago (
                            metodo_pago_id, moneda_id, tasa_cambio, monto_pagado, monto_devuelta,
                            sesion_caja_id, fecha_pago
                        ) VALUES (
                            5, 1, 1.0000, ?, 0.00,
                            ?, CURRENT_TIMESTAMP
                        ) RETURNING id
                    ");
                    $stmtDetFianza->execute([$fianzaAplicada, $sesionCajaId]);
                    $detFianzaId = (int)$stmtDetFianza->fetchColumn();

                    // Registrar en tesoreria.pago_saldo_favor
                    $stmtSubSaldo = $pdo->prepare("
                        INSERT INTO tesoreria.pago_saldo_favor (
                            detalle_pago_id, cliente_id, origen_saldo, documento_origen_id
                        ) VALUES (
                            ?, ?, 'CONSIGNACION_GARANTIA', ?
                        )
                    ");
                    $stmtSubSaldo->execute([$detFianzaId, $clienteId, $csgId]);

                    // Vincular en ventas.factura_pagos
                    $stmtFacPago = $pdo->prepare("
                        INSERT INTO ventas.factura_pagos (factura_id, detalle_pago_id)
                        VALUES (?, ?)
                    ");
                    $stmtFacPago->execute([$facturaIdGenerada, $detFianzaId]);
                }

                // 5. Cobro de la diferencia si Consumo > Fianza
                if ($totalConsumido > $montoFianza) {
                    $diferenciaPagar = round($totalConsumido - $montoFianza, 2);
                    $totalMetodosRecibido = 0.00;

                    foreach ($pagosDiferencia as $pDif) {
                        $metodoId = intval($pDif['metodo_pago_id'] ?? 1);
                        $montoP   = round((float)($pDif['monto'] ?? 0), 2);
                        if ($montoP <= 0) continue;

                        $totalMetodosRecibido += $montoP;

                        $sesPagoDif = ($metodoId === 1 && $sesionCajaId) ? $sesionCajaId : null;
                        $stmtInsDif = $pdo->prepare("
                            INSERT INTO tesoreria.detalles_pago (
                                metodo_pago_id, moneda_id, tasa_cambio, monto_pagado, monto_devuelta,
                                sesion_caja_id, fecha_pago
                            ) VALUES (
                                ?, 1, 1.0000, ?, 0.00,
                                ?, CURRENT_TIMESTAMP
                            ) RETURNING id
                        ");
                        $stmtInsDif->execute([$metodoId, $montoP, $sesPagoDif]);
                        $detDifId = (int)$stmtInsDif->fetchColumn();

                        // Vincular en factura_pagos
                        $pdo->prepare("INSERT INTO ventas.factura_pagos (factura_id, detalle_pago_id) VALUES (?, ?)")
                            ->execute([$facturaIdGenerada, $detDifId]);

                        // Subtipo tarjeta o transferencia si aplica
                        if ($metodoId === 2) {
                            $stmtTarj = $pdo->prepare("
                                INSERT INTO tesoreria.pago_tarjeta (
                                    detalle_pago_id, tipo_tarjeta, ultimos_cuatro_digitos, numero_autorizacion, lote_pos
                                ) VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmtTarj->execute([
                                $detDifId,
                                $pDif['tipo_tarjeta'] ?? 'CREDITO',
                                $pDif['ultimos_cuatro'] ?? '0000',
                                $pDif['numero_autorizacion'] ?? 'AUT-POS',
                                $pDif['lote_pos'] ?? 'LOTE-1'
                            ]);
                        } elseif ($metodoId === 3) {
                            $stmtTransf = $pdo->prepare("
                                INSERT INTO tesoreria.pago_transferencia (
                                    detalle_pago_id, cuenta_bancaria_id, numero_referencia
                                ) VALUES (?, ?, ?)
                            ");
                            $cuentaBancId = !empty($pDif['cuenta_bancaria_id']) ? (int)$pDif['cuenta_bancaria_id'] : 1;
                            $stmtTransf->execute([
                                $detDifId,
                                $cuentaBancId,
                                $pDif['numero_referencia'] ?? 'REF-CONSIG'
                            ]);
                        }
                    }

                    if (abs($totalMetodosRecibido - $diferenciaPagar) > 0.05) {
                        throw new Exception("El monto cubierto en métodos de pago (RD$ {$totalMetodosRecibido}) debe cuadrar exactamente con la diferencia a pagar (RD$ {$diferenciaPagar}).");
                    }
                }

                // 6. Si Consumo < Fianza: Devolver excedente al cliente como EGRESO en ventas.movimiento_caja
                if ($totalConsumido < $montoFianza) {
                    $excedenteDevolver = round($montoFianza - $totalConsumido, 2);
                    if ($excedenteDevolver > 0 && $sesionCajaId) {
                        $stmtEgreso = $pdo->prepare("
                            INSERT INTO ventas.movimiento_caja (
                                sesion_caja_id, tipo_movimiento, monto, concepto, supervisor_id, fecha_hora
                            ) VALUES (
                                ?, 'EGRESO', ?, ?, ?, CURRENT_TIMESTAMP
                            )
                        ");
                        $conceptoEgreso = "Devolución excedente fianza consignación {$numConsignacion}";
                        $stmtEgreso->execute([$sesionCajaId, $excedenteDevolver, $conceptoEgreso, $supervisorIdValido]);
                    }
                }

                // 7. Generar Kardex SALIDA_VENTA para los artículos consumidos
                $stmtInsKardex = $pdo->prepare("
                    INSERT INTO inventario.movimientos_inventario (
                        tipo_movimiento, justificacion, supervisor_id, documento_referencia_id
                    ) VALUES (
                        'SALIDA_VENTA', ?, ?, ?
                    ) RETURNING id
                ");
                $justKardex = "Salida por liquidación de consignación {$numConsignacion} (Factura: {$numeroFacturaGenerada})";
                $stmtInsKardex->execute([$justKardex, $supervisorIdValido, $facturaIdGenerada]);
                $kardexId = (int)$stmtInsKardex->fetchColumn();

                $stmtInsKardexDet = $pdo->prepare("
                    INSERT INTO inventario.movimiento_inventario_detalles (
                        movimiento_id, producto_maestro_id, lote_id, cantidad_unidades, costo_unitario_referencia
                    ) VALUES (?, ?, ?, ?, ?)
                ");

                foreach ($itemsConsumidos as $ic) {
                    $stmtInsKardexDet->execute([
                        $kardexId,
                        $ic['producto_maestro_id'],
                        $ic['lote_id'],
                        $ic['cantidad'],
                        $ic['costo_promedio']
                    ]);
                }
            } else {
                // Caso: Total consumido == 0 (Todas las botellas regresaron intactas)
                // Se reembolsa el 100% de la fianza
                if ($montoFianza > 0 && $sesionCajaId) {
                    $stmtEgreso = $pdo->prepare("
                        INSERT INTO ventas.movimiento_caja (
                            sesion_caja_id, tipo_movimiento, monto, concepto, supervisor_id, fecha_hora
                        ) VALUES (
                            ?, 'EGRESO', ?, ?, ?, CURRENT_TIMESTAMP
                        )
                    ");
                    $conceptoEgreso = "Reembolso total de fianza por consignación sin consumo {$numConsignacion}";
                    $stmtEgreso->execute([$sesionCajaId, $montoFianza, $conceptoEgreso, $supervisorIdValido]);
                }
            }

            // 8. DESCARGA Y LIBERACIÓN DE INVENTARIO:
            //   - Para consumidas: se restan de stock_fisico_unidades y de stock_comprometido (y lote si > 0)
            //   - Para devueltas: se restan ÚNICAMENTE de stock_comprometido (quedando liberadas en mostrador)
            $stmtUpdStockConsumido = $pdo->prepare("
                UPDATE inventario.inventario_stock
                SET stock_fisico_unidades = stock_fisico_unidades - ?,
                    stock_comprometido = stock_comprometido - ?
                WHERE almacen_id = ? AND producto_maestro_id = ? AND lote_id = ?
            ");

            $stmtUpdStockDevuelto = $pdo->prepare("
                UPDATE inventario.inventario_stock
                SET stock_comprometido = stock_comprometido - ?
                WHERE almacen_id = ? AND producto_maestro_id = ? AND lote_id = ?
            ");

            $stmtUpdLote = $pdo->prepare("
                UPDATE inventario.lotes
                SET cantidad_actual = cantidad_actual - ?
                WHERE id = ? AND id > 0
            ");

            foreach ($detalles as $d) {
                $prodId = (int)$d['producto_maestro_id'];
                $loteId = (int)$d['lote_id'];
                $consumidas = (int)$d['cantidad_consumida'];
                $devueltas = (int)$d['cantidad_devuelta'];

                if ($consumidas > 0) {
                    // Descuenta físico y comprometido
                    $stmtUpdStockConsumido->execute([$consumidas, $consumidas, $almacenId, $prodId, $loteId]);
                    if ($loteId > 0) {
                        $stmtUpdLote->execute([$consumidas, $loteId]);
                    }
                }

                if ($devueltas > 0) {
                    // Libera de comprometido
                    $stmtUpdStockDevuelto->execute([$devueltas, $almacenId, $prodId, $loteId]);
                }
            }

            // 9. Mutar estado de la consignación a 'LIQUIDADA'
            $stmtFin = $pdo->prepare("
                UPDATE ventas.consignaciones
                SET estado = 'LIQUIDADA'
                WHERE id = ?
            ");
            $stmtFin->execute([$csgId]);

            // 10. Auditoría Forense JSONB
            registrarAuditoria(
                $pdo,
                $usuarioId,
                $supervisorIdValido,
                'LIQUIDAR_CONSIGNACION',
                'ventas.consignaciones',
                ['estado' => 'DESPACHADA'],
                [
                    'consignacion_id' => $csgId,
                    'numero_consignacion' => $numConsignacion,
                    'estado_final' => 'LIQUIDADA',
                    'total_consumido' => $totalConsumido,
                    'monto_fianza' => $montoFianza,
                    'factura_id' => $facturaIdGenerada,
                    'numero_factura' => $numeroFacturaGenerada,
                    'ncf' => $ncfGenerado,
                    'botellas_consumidas' => $totalBotellasConsumidas,
                    'botellas_devueltas' => $totalBotellasDevueltas
                ]
            );

            $pdo->commit();

            echo json_encode([
                'status' => 'success',
                'message' => "Consignación {$numConsignacion} liquidada y cerrada exitosamente.",
                'total_consumido' => $totalConsumido,
                'factura_numero' => $numeroFacturaGenerada,
                'ncf' => $ncfGenerado,
                'factura_id' => $facturaIdGenerada
            ]);
            break;

        default:
            throw new Exception("Acción no reconocida: '{$accion}'.");
    }

} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
