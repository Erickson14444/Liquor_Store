<?php
/**
 * ============================================================================
 * Backend Controlador: Proceso 8 - Notas de Débito (NCF B03)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/NotasDebito/procesar_notas_debito.php
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

if (!function_exists('usuarioPuedeAccederNotasDebito') || !usuarioPuedeAccederNotasDebito()) {
    echo json_encode(['status' => 'error', 'message' => 'No posee permisos para operar Notas de Débito.']);
    exit;
}

$accion = trim($_GET['accion'] ?? $_POST['accion'] ?? '');

try {
    switch ($accion) {

        // ====================================================================
        // 1. BUSCADOR DE FACTURAS ORIGEN A MODIFICAR
        // ====================================================================
        case 'buscar_facturas':
            $termino = trim($_GET['termino'] ?? '');
            if (strlen($termino) < 2) {
                echo json_encode(['status' => 'success', 'data' => []]);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT f.id,
                       f.numero_factura,
                       f.tipo_ncf,
                       COALESCE(f.ncf, 'S/C') AS ncf,
                       f.condicion_pago,
                       f.monto_total,
                       TO_CHAR(f.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_fmt,
                       f.cliente_id,
                       COALESCE(NULLIF(f.razon_social_cliente, ''), p.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
                       COALESCE(NULLIF(f.rnc_cedula_cliente, ''), p.documento_identidad, '') AS cliente_doc
                FROM ventas.facturas f
                LEFT JOIN identidad.clientes c ON c.persona_id = f.cliente_id
                LEFT JOIN identidad.personas p ON p.persona_id = c.persona_id
                WHERE f.estado <> 'ANULADA'
                  AND (
                      LOWER(f.numero_factura) LIKE LOWER(?)
                      OR LOWER(f.ncf) LIKE LOWER(?)
                      OR LOWER(p.nombre_razon_social) LIKE LOWER(?)
                      OR LOWER(p.documento_identidad) LIKE LOWER(?)
                      OR LOWER(f.razon_social_cliente) LIKE LOWER(?)
                  )
                ORDER BY f.fecha_emision DESC, f.id DESC
                LIMIT 20
            ");
            $param = "%{$termino}%";
            $stmt->execute([$param, $param, $param, $param, $param]);
            $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Cargar productos de cada factura por si se desea asociar referencia de producto
            $stmtDet = $pdo->prepare("
                SELECT fd.producto_maestro_id, pm.nombre, pm.sku, fd.cantidad_unidades, fd.precio_unitario_his
                FROM ventas.factura_detalle fd
                JOIN inventario.productos_maestro pm ON pm.id = fd.producto_maestro_id
                WHERE fd.factura_id = ?
            ");
            foreach ($facturas as &$f) {
                $f['id'] = (int)$f['id'];
                $f['monto_total'] = (float)$f['monto_total'];
                $stmtDet->execute([$f['id']]);
                $f['detalles'] = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['status' => 'success', 'data' => $facturas]);
            break;

        // ====================================================================
        // 2. EMISIÓN FISCAL DE NOTA DE DÉBITO B03, CARGOS Y REGISTRO EN CXC
        // ====================================================================
        case 'emitir_nota_debito':
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            if (!$data) {
                throw new Exception("Datos de solicitud inválidos.");
            }

            $facturaId       = intval($data['factura_id'] ?? 0);
            $conceptoCargo   = trim($data['concepto_cargo'] ?? '');
            $diasCredito     = intval($data['dias_credito'] ?? 30);
            $lineas          = $data['lineas'] ?? [];
            $supervisorId    = trim($data['supervisor_id'] ?? '');
            $pinAutorizacion = trim($data['pin_autorizacion'] ?? '');

            if ($facturaId <= 0) {
                throw new Exception("Debe seleccionar la factura origen para emitir la Nota de Débito.");
            }
            if ($conceptoCargo === '') {
                throw new Exception("Debe especificar el motivo legal o justificación del cargo (concepto).");
            }
            if (empty($lineas)) {
                throw new Exception("Debe ingresar al menos una línea de recargo o cargo en la Nota de Débito.");
            }

            // Validar supervisor y PIN
            $supValido = validarPinSupervisor($pdo, $supervisorId, $pinAutorizacion);
            $supervisorIdValido = $supValido['usuario_id'];

            $pdo->beginTransaction();

            // 1. Obtener y validar factura origen
            $stmtFac = $pdo->prepare("
                SELECT f.id, f.numero_factura, f.tipo_ncf, f.ncf, f.cliente_id, f.estado,
                       p.nombre_razon_social AS cliente_nombre, p.documento_identidad AS cliente_doc
                FROM ventas.facturas f
                JOIN identidad.clientes cl ON cl.persona_id = f.cliente_id
                JOIN identidad.personas p ON p.persona_id = cl.persona_id
                WHERE f.id = ?
                FOR UPDATE
            ");
            $stmtFac->execute([$facturaId]);
            $factura = $stmtFac->fetch(PDO::FETCH_ASSOC);

            if (!$factura) {
                throw new Exception("La factura origen #{$facturaId} no existe o no tiene cliente vinculado.");
            }
            if ($factura['estado'] === 'ANULADA') {
                throw new Exception("No se puede emitir una Nota de Débito sobre una factura anulada.");
            }

            $ncfFacturaModificada = trim($factura['ncf'] ?? '');
            if ($ncfFacturaModificada === '' || $ncfFacturaModificada === 'S/C') {
                // Si la factura no tenía NCF asignado, usamos su número correlativo
                $ncfFacturaModificada = $factura['numero_factura'];
            }

            $clienteId = $factura['cliente_id'];

            // 2. Consumo concurrente de secuencia activa B03 en secuencias_fiscales_dgii
            $stmtSec = $pdo->prepare("
                SELECT id, secuencia_actual, secuencia_hasta, fecha_vencimiento
                FROM ventas.secuencias_fiscales_dgii
                WHERE tipo_comprobante = 'B03'
                  AND estado = 'ACTIVO'
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmtSec->execute();
            $secRow = $stmtSec->fetch(PDO::FETCH_ASSOC);

            if (!$secRow) {
                throw new Exception("No existe una secuencia fiscal DGII activa para 'B03'. Configure una secuencia en el panel.");
            }

            $secuenciaDgiiId = (int)$secRow['id'];
            $secActual = (int)$secRow['secuencia_actual'];
            $secHasta = (int)$secRow['secuencia_hasta'];

            if ($secActual > $secHasta) {
                $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'AGOTADO' WHERE id = ?")->execute([$secuenciaDgiiId]);
                throw new Exception("La secuencia fiscal B03 de Notas de Débito está agotada.");
            }

            $ncfNotaDebito = 'B03' . str_pad((string)$secActual, 8, '0', STR_PAD_LEFT);

            // Incrementar secuencia
            if ($secActual < $secHasta) {
                $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET secuencia_actual = secuencia_actual + 1 WHERE id = ?")->execute([$secuenciaDgiiId]);
            } else {
                $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'AGOTADO' WHERE id = ?")->execute([$secuenciaDgiiId]);
            }

            // 3. Procesar y calcular líneas de cargos
            $montoSubtotal = 0.00;
            $montoImpuestos = 0.00;
            $montoTotal = 0.00;
            $lineasProcesadas = [];
            $nombresCargosUnicos = [];

            foreach ($lineas as $idx => $lin) {
                $desc = trim($lin['descripcion_cargo'] ?? '');
                $cant = intval($lin['cantidad'] ?? 1);
                $precio = round((float)($lin['precio_unitario'] ?? 0), 2);
                $prodId = !empty($lin['producto_maestro_id']) ? (int)$lin['producto_maestro_id'] : null;
                $llevaItbis = !empty($lin['aplicar_itbis']);

                if ($desc === '') {
                    throw new Exception("La línea #" . ($idx + 1) . " no posee descripción del cargo.");
                }
                if ($cant <= 0) {
                    throw new Exception("La cantidad en '{$desc}' debe ser mayor a 0.");
                }
                if ($precio < 0) {
                    throw new Exception("El precio unitario en '{$desc}' no puede ser negativo.");
                }

                // Evitar duplicado de PK (nota_debito_id, descripcion_cargo)
                if (in_array(strtolower($desc), $nombresCargosUnicos, true)) {
                    $desc .= " (" . ($idx + 1) . ")";
                }
                $nombresCargosUnicos[] = strtolower($desc);

                $subtLinea = round($cant * $precio, 2);
                $itbisLinea = $llevaItbis ? round($subtLinea * 0.18, 2) : 0.00;
                $totLinea = round($subtLinea + $itbisLinea, 2);

                $montoSubtotal += $subtLinea;
                $montoImpuestos += $itbisLinea;
                $montoTotal += $totLinea;

                $lineasProcesadas[] = [
                    'producto_maestro_id' => $prodId,
                    'descripcion_cargo' => $desc,
                    'cantidad' => $cant,
                    'precio_unitario' => $precio,
                    'monto_impuestos' => $itbisLinea,
                    'monto_total' => $totLinea,
                    'lleva_itbis' => $llevaItbis
                ];
            }

            if ($montoTotal <= 0) {
                throw new Exception("El monto total de la Nota de Débito debe ser mayor a 0.00.");
            }

            // 4. Insertar cabecera en ventas.notas_debito
            $seqNdId = (int)$pdo->query("SELECT nextval('ventas.notas_debito_id_seq')")->fetchColumn();

            $stmtInsNd = $pdo->prepare("
                INSERT INTO ventas.notas_debito (
                    id, factura_id, cliente_id, secuencia_dgii_id,
                    ncf_nota_debito, ncf_factura_modificada, concepto_cargo,
                    monto_subtotal, monto_impuestos, monto_total,
                    usuario_emisor_id, fecha_emision, moneda_id, tasa_cambio
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, CURRENT_TIMESTAMP, 1, 1.0000
                )
            ");
            $stmtInsNd->execute([
                $seqNdId,
                $facturaId,
                $clienteId,
                $secuenciaDgiiId,
                $ncfNotaDebito,
                $ncfFacturaModificada,
                $conceptoCargo,
                $montoSubtotal,
                $montoImpuestos,
                $montoTotal,
                $usuarioId
            ]);

            // 5. Insertar detalles en ventas.nota_debito_detalles y tabla de impuestos
            $stmtInsDet = $pdo->prepare("
                INSERT INTO ventas.nota_debito_detalles (
                    nota_debito_id, producto_maestro_id, descripcion_cargo,
                    cantidad, precio_unitario, monto_impuestos, monto_total
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmtInsDetImp = $pdo->prepare("
                INSERT INTO ventas.nota_debito_detalle_impuestos (
                    nota_debito_id, descripcion_cargo, impuesto_id,
                    base_imponible, tipo_calculo_aplicado, tasa_aplicada, monto_impuesto
                ) VALUES (?, ?, 1, ?, 'PORCENTAJE', 0.1800, ?)
            ");

            foreach ($lineasProcesadas as $lp) {
                $stmtInsDet->execute([
                    $seqNdId,
                    $lp['producto_maestro_id'],
                    $lp['descripcion_cargo'],
                    $lp['cantidad'],
                    $lp['precio_unitario'],
                    $lp['monto_impuestos'],
                    $lp['monto_total']
                ]);

                if ($lp['lleva_itbis'] && $lp['monto_impuestos'] > 0) {
                    $stmtInsDetImp->execute([
                        $seqNdId,
                        $lp['descripcion_cargo'],
                        round($lp['cantidad'] * $lp['precio_unitario'], 2),
                        $lp['monto_impuestos']
                    ]);
                }
            }

            // 6. FASE 4: Nacimiento de Deuda en Cuentas por Cobrar (CxC)
            // No entra dinero inmediato en caja; se genera la obligación en CxC vinculando nota_debito_id (con factura_id = NULL)
            $stmtInsCxc = $pdo->prepare("
                INSERT INTO ventas.cuentas_por_cobrar (
                    cliente_id, factura_id, nota_debito_id,
                    monto_original, saldo_pendiente, estado,
                    fecha_emision, fecha_vencimiento, moneda_id, tasa_cambio
                ) VALUES (
                    ?, NULL, ?,
                    ?, ?, 'PENDIENTE',
                    CURRENT_DATE, CURRENT_DATE + (? * INTERVAL '1 day'), 1, 1.0000
                ) RETURNING id
            ");
            $stmtInsCxc->execute([
                $clienteId,
                $seqNdId,
                $montoTotal,
                $montoTotal,
                max(1, $diasCredito)
            ]);
            $cxcIdGenerado = (int)$stmtInsCxc->fetchColumn();

            // 7. Auditoría Forense JSONB
            registrarAuditoria(
                $pdo,
                $usuarioId,
                $supervisorIdValido,
                'EMITIR_NOTA_DEBITO_B03',
                'ventas.notas_debito',
                null,
                [
                    'nota_debito_id' => $seqNdId,
                    'ncf_nota_debito' => $ncfNotaDebito,
                    'ncf_factura_modificada' => $ncfFacturaModificada,
                    'factura_origen_id' => $facturaId,
                    'cliente_id' => $clienteId,
                    'monto_total' => $montoTotal,
                    'cxc_id_generado' => $cxcIdGenerado,
                    'concepto' => $conceptoCargo,
                    'total_lineas' => count($lineasProcesadas)
                ]
            );

            $pdo->commit();

            echo json_encode([
                'status' => 'success',
                'message' => "Nota de Débito {$ncfNotaDebito} emitida exitosamente y deuda asentada en CxC.",
                'nota_debito_id' => $seqNdId,
                'ncf_nota_debito' => $ncfNotaDebito,
                'cxc_id' => $cxcIdGenerado,
                'monto_total' => $montoTotal
            ]);
            break;

        // ====================================================================
        // 3. CONSULTAR DETALLE DE NOTA DE DÉBITO
        // ====================================================================
        case 'obtener_detalle_nd':
            $ndId = intval($_GET['id'] ?? 0);
            if ($ndId <= 0) {
                throw new Exception("ID de Nota de Débito inválido.");
            }

            $stmtNd = $pdo->prepare("
                SELECT nd.id,
                       nd.ncf_nota_debito,
                       nd.ncf_factura_modificada,
                       nd.concepto_cargo,
                       nd.monto_subtotal,
                       nd.monto_impuestos,
                       nd.monto_total,
                       TO_CHAR(nd.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_fmt,
                       p.nombre_razon_social AS cliente_nombre,
                       p.documento_identidad AS cliente_rnc,
                       f.numero_factura AS factura_numero,
                       u.username AS usuario_emisor,
                       cxc.id AS cxc_id,
                       cxc.estado AS cxc_estado,
                       cxc.saldo_pendiente AS cxc_saldo_pendiente
                FROM ventas.notas_debito nd
                JOIN identidad.clientes cl ON cl.persona_id = nd.cliente_id
                JOIN identidad.personas p ON p.persona_id = cl.persona_id
                JOIN ventas.facturas f ON f.id = nd.factura_id
                JOIN seguridad.usuarios u ON u.usuario_id = nd.usuario_emisor_id
                LEFT JOIN ventas.cuentas_por_cobrar cxc ON cxc.nota_debito_id = nd.id
                WHERE nd.id = ?
            ");
            $stmtNd->execute([$ndId]);
            $notaDebito = $stmtNd->fetch(PDO::FETCH_ASSOC);

            if (!$notaDebito) {
                throw new Exception("Nota de Débito #{$ndId} no encontrada.");
            }

            // Consultar líneas
            $stmtLin = $pdo->prepare("
                SELECT ndd.descripcion_cargo,
                       ndd.cantidad,
                       ndd.precio_unitario,
                       ndd.monto_impuestos,
                       ndd.monto_total,
                       pm.nombre AS producto_nombre,
                       pm.sku AS producto_sku
                FROM ventas.nota_debito_detalles ndd
                LEFT JOIN inventario.productos_maestro pm ON pm.id = ndd.producto_maestro_id
                WHERE ndd.nota_debito_id = ?
                ORDER BY ndd.descripcion_cargo ASC
            ");
            $stmtLin->execute([$ndId]);
            $lineas = $stmtLin->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'nota_debito' => $notaDebito,
                    'lineas' => $lineas
                ]
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
