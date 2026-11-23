<?php
/**
 * ============================================================================
 * CONTROLADOR BACKEND • PROCESO 2: CUENTAS POR PAGAR (CXP)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/cuentas_por_pagar/procesar_cxp.php
 * ============================================================================
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

if (!isset($_SESSION['usuario'])) {
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'mensaje' => 'Sesión no iniciada o caducada. Por favor, reingrese al sistema.'
    ]);
    exit;
}

if (!usuarioPuedeAccederCxp()) {
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'mensaje' => 'No tiene los permisos requeridos (GESTIONAR_CXP) para operar este módulo.'
    ]);
    exit;
}

$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';
$usuarioActualId = $_SESSION['usuario']['usuario_id'];

try {
    if (!$pdo) {
        throw new Exception("Error crítico: No hay conexión activa a la base de datos PostgreSQL.");
    }

    switch ($accion) {

        // ====================================================================
        // FASE 1: REGISTRO MANUAL DE GASTO / SERVICIO DIRECTO (SIN RECEPCIÓN)
        // ====================================================================
        case 'registrar_gasto_manual':
            $proveedorId    = trim($_POST['proveedor_id'] ?? '');
            $numFactura     = trim($_POST['numero_factura_servicio'] ?? '');
            $ncf            = strtoupper(trim($_POST['ncf_servicio'] ?? ''));
            $concepto       = trim($_POST['concepto_servicio'] ?? '');
            $fechaEmision   = trim($_POST['fecha_emision'] ?? date('Y-m-d'));
            $condicionPago  = strtoupper(trim($_POST['condicion_pago'] ?? 'CONTADO'));
            $diasCredito    = max(0, (int)($_POST['dias_credito'] ?? 0));
            $monedaId       = max(1, (int)($_POST['moneda_id'] ?? 1));
            $tasaCambio     = max(0.0001, (float)($_POST['tasa_cambio'] ?? 1.0000));
            $montoTotal     = round((float)($_POST['monto_original'] ?? 0), 2);

            if (empty($proveedorId)) {
                throw new Exception("Debe seleccionar el proveedor o prestador del servicio.");
            }
            if ($montoTotal <= 0) {
                throw new Exception("El monto total de la obligación debe ser mayor a cero.");
            }
            if (empty($concepto)) {
                throw new Exception("Debe especificar el concepto del gasto o servicio (ej. Alquiler, Luz, Internet).");
            }

            // Calcular fecha de vencimiento según condición de pago
            if ($condicionPago === 'CONTADO') {
                $fechaVencimiento = $fechaEmision;
            } else {
                $fechaVencimiento = date('Y-m-d', strtotime($fechaEmision . " + {$diasCredito} days"));
            }

            $pdo->beginTransaction();

            $stmtIns = $pdo->prepare("
                INSERT INTO compras.cuentas_por_pagar (
                    proveedor_id,
                    recepcion_compra_id,
                    monto_original,
                    saldo_pendiente,
                    fecha_emision,
                    fecha_vencimiento,
                    estado,
                    moneda_id,
                    tasa_cambio,
                    numero_factura_servicio,
                    ncf_servicio,
                    concepto_servicio
                ) VALUES (
                    ?, NULL, ?, ?, ?, ?, 'PENDIENTE', ?, ?, ?, ?, ?
                ) RETURNING id
            ");

            $stmtIns->execute([
                $proveedorId,
                $montoTotal,
                $montoTotal,
                $fechaEmision,
                $fechaVencimiento,
                $monedaId,
                $tasaCambio,
                $numFactura ?: null,
                $ncf ?: null,
                $concepto
            ]);

            $cxpId = (int)$stmtIns->fetchColumn();

            registrarAuditoria(
                $pdo,
                $usuarioActualId,
                null,
                'REGISTRAR_GASTO_SERVICIO_CXP',
                'compras.cuentas_por_pagar',
                null,
                [
                    'cxp_id' => $cxpId,
                    'proveedor_id' => $proveedorId,
                    'monto' => $montoTotal,
                    'concepto' => $concepto,
                    'ncf' => $ncf
                ]
            );

            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'mensaje' => "Obligación #{$cxpId} registrada exitosamente en estado PENDIENTE.",
                'cxp_id' => $cxpId
            ]);
            break;


        // ====================================================================
        // FASE 2: OBTENER FACTURAS PENDIENTES DE UN PROVEEDOR
        // ====================================================================
        case 'obtener_facturas_proveedor':
            $proveedorId = trim($_GET['proveedor_id'] ?? '');
            if (empty($proveedorId)) {
                throw new Exception("Proveedor no especificado.");
            }

            $stmt = $pdo->prepare("
                SELECT cxp.id,
                       cxp.monto_original,
                       cxp.saldo_pendiente,
                       cxp.fecha_emision,
                       cxp.fecha_vencimiento,
                       cxp.estado,
                       cxp.moneda_id,
                       cxp.tasa_cambio,
                       m.simbolo AS moneda_simbolo,
                       COALESCE(rc.numero_factura_proveedor, cxp.numero_factura_servicio, 'S/F') AS numero_factura,
                       COALESCE(rc.ncf_proveedor, cxp.ncf_servicio, 'N/A') AS ncf,
                       COALESCE(cxp.concepto_servicio, 'Mercancía Recepción #' || rc.id) AS concepto,
                       (CURRENT_DATE - cxp.fecha_vencimiento) AS dias_vencido
                FROM compras.cuentas_por_pagar cxp
                JOIN tesoreria.monedas m ON m.id = cxp.moneda_id
                LEFT JOIN compras.recepciones_compra rc ON rc.id = cxp.recepcion_compra_id
                WHERE cxp.proveedor_id = ?
                  AND cxp.estado IN ('PENDIENTE', 'PAGADA_PARCIAL')
                ORDER BY cxp.fecha_vencimiento ASC, cxp.id ASC
            ");
            $stmt->execute([$proveedorId]);
            $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Obtener retenciones preconfiguradas del proveedor
            $stmtRet = $pdo->prepare("
                SELECT r.id, r.siglas, r.nombre, r.base_calculo, r.porcentaje
                FROM identidad.proveedor_retenciones pr
                JOIN tesoreria.retenciones r ON r.id = pr.retencion_id
                WHERE pr.proveedor_id = ? AND r.activo = true
            ");
            $stmtRet->execute([$proveedorId]);
            $retencionesSugeridas = $stmtRet->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'ok' => true,
                'facturas' => $facturas,
                'retenciones_sugeridas' => $retencionesSugeridas
            ]);
            break;


        // ====================================================================
        // FASE 3: EJECUCIÓN DEL DESEMBOLSO, SPLIT Y RETENCIONES FISCALES
        // ====================================================================
        case 'liquidar_desembolso':
            $payloadRaw = file_get_contents('php://input');
            $data = json_decode($payloadRaw, true);
            if (!$data) {
                $data = $_POST;
            }

            $proveedorId    = trim($data['proveedor_id'] ?? '');
            $facturasItems  = $data['facturas'] ?? []; // [{cxp_id, monto_aplicado, retenciones: [{retencion_id, monto_retenido, base, porcentaje}] }]
            $pagosSplit     = $data['pagos'] ?? [];    // [{metodo_pago_id, monto, cuenta_bancaria_id, referencia, banco_origen, num_cheque, banco_emisor}]
            $monedaId       = max(1, (int)($data['moneda_id'] ?? 1));
            $tasaCambio     = max(0.0001, (float)($data['tasa_cambio'] ?? 1.0000));

            if (empty($proveedorId)) {
                throw new Exception("Debe especificar el proveedor del desembolso.");
            }
            if (empty($facturasItems)) {
                throw new Exception("Debe seleccionar al menos una factura para liquidar.");
            }
            if (empty($pagosSplit)) {
                throw new Exception("Debe especificar al menos un método de pago en el split.");
            }

            $pdo->beginTransaction();

            // 1. Validar y calcular totales de facturas a liquidar
            $totalMontoAplicadoFacturas = 0.00;
            $totalMontoRetencionesFacturas = 0.00;
            $facturasValidadas = [];

            foreach ($facturasItems as $fItem) {
                $cxpId = (int)($fItem['cxp_id'] ?? 0);
                $montoAplicar = round((float)($fItem['monto_aplicado'] ?? 0), 2);
                $rets = $fItem['retenciones'] ?? [];

                if ($montoAplicar <= 0) {
                    continue; // Factura sin desembolso
                }

                // Bloquear la cuenta por pagar para evitar concurrencia
                $stmtCxp = $pdo->prepare("
                    SELECT id, proveedor_id, saldo_pendiente, estado
                    FROM compras.cuentas_por_pagar
                    WHERE id = ? FOR UPDATE
                ");
                $stmtCxp->execute([$cxpId]);
                $cxpRow = $stmtCxp->fetch(PDO::FETCH_ASSOC);

                if (!$cxpRow) {
                    throw new Exception("La cuenta por pagar #{$cxpId} no existe.");
                }
                if ($cxpRow['proveedor_id'] !== $proveedorId) {
                    throw new Exception("La cuenta por pagar #{$cxpId} no pertenece al proveedor seleccionado.");
                }
                if (!in_array($cxpRow['estado'], ['PENDIENTE', 'PAGADA_PARCIAL'])) {
                    throw new Exception("La cuenta por pagar #{$cxpId} no está pendiente de pago (Estado: {$cxpRow['estado']}).");
                }

                $saldoAnterior = (float)$cxpRow['saldo_pendiente'];

                // Calcular retenciones de esta factura
                $totalRetFactura = 0.00;
                $retsProcesadas = [];
                foreach ($rets as $r) {
                    $retId = (int)($r['retencion_id'] ?? 0);
                    $montoRet = round((float)($r['monto_retenido'] ?? 0), 2);
                    $baseCalculo = strtoupper(trim($r['base_calculo'] ?? 'SUBTOTAL'));
                    $montoBase = round((float)($r['monto_base'] ?? 0), 2);
                    $porc = round((float)($r['porcentaje'] ?? 0), 2);

                    if ($retId > 0 && $montoRet > 0) {
                        $totalRetFactura += $montoRet;
                        $retsProcesadas[] = [
                            'retencion_id' => $retId,
                            'base_calculo' => $baseCalculo,
                            'monto_base' => $montoBase,
                            'porcentaje' => $porc,
                            'monto_retenido' => $montoRet
                        ];
                    }
                }

                // Determinar el monto neto desembolsado ($montoAplicar)
                // Si el frontend envía monto_bruto (deuda a amortizar), se deduce la retención:
                // monto_aplicado = monto_bruto - retenciones (neto en dinero real)
                if (isset($fItem['monto_bruto'])) {
                    $montoBruto = round((float)$fItem['monto_bruto'], 2);
                    $montoAplicar = round($montoBruto - $totalRetFactura, 2);
                } else {
                    // Si envió monto_aplicado pero (monto_aplicado + retencion) excede el saldo
                    // porque se envió el saldo bruto total, ajustar automáticamente al neto:
                    if (($montoAplicar + $totalRetFactura) > $saldoAnterior && $montoAplicar <= $saldoAnterior) {
                        $montoAplicar = round($montoAplicar - $totalRetFactura, 2);
                    }
                }

                if ($montoAplicar < 0) {
                    throw new Exception("El monto de retenciones (RD$ {$totalRetFactura}) no puede ser mayor que el total a liquidar en la factura #{$cxpId}.");
                }

                if (($montoAplicar + $totalRetFactura) > $saldoAnterior) {
                    throw new Exception("La suma de monto neto a desembolsar (RD$ {$montoAplicar}) y retenciones (RD$ {$totalRetFactura}) excede el saldo pendiente (RD$ {$saldoAnterior}) en la factura #{$cxpId}.");
                }

                $saldoRestante = round($saldoAnterior - ($montoAplicar + $totalRetFactura), 2);

                $totalMontoAplicadoFacturas += $montoAplicar;
                $totalMontoRetencionesFacturas += $totalRetFactura;

                $facturasValidadas[] = [
                    'cxp_id' => $cxpId,
                    'saldo_anterior' => $saldoAnterior,
                    'monto_aplicado' => $montoAplicar,
                    'monto_retenciones' => $totalRetFactura,
                    'saldo_restante' => $saldoRestante,
                    'retenciones' => $retsProcesadas
                ];
            }

            if (empty($facturasValidadas)) {
                throw new Exception("No se especificó ningún monto válido para aplicar.");
            }

            // 2. Validar que el total pagado en el Split coincida con el Total Aplicado Neto
            $totalSplitPagado = 0.00;
            foreach ($pagosSplit as $pago) {
                $montoP = round((float)($pago['monto'] ?? 0), 2);
                if ($montoP > 0) {
                    $totalSplitPagado += $montoP;
                }
            }

            $diferencia = round(abs($totalSplitPagado - $totalMontoAplicadoFacturas), 2);
            if ($diferencia > 0.02) {
                throw new Exception("El desglose de pagos (Split: RD$ {$totalSplitPagado}) no coincide con el total neto aplicado a las facturas (RD$ {$totalMontoAplicadoFacturas}). Diferencia: RD$ {$diferencia}.");
            }

            // 3. Generar número de comprobante de egreso correlativo
            $anioActual = date('Y');
            $stmtSeq = $pdo->query("SELECT nextval('compras.comprobantes_egreso_cxp_id_seq')");
            $nextId = (int)$stmtSeq->fetchColumn();
            $numComprobante = 'EGR-' . $anioActual . '-' . str_pad((string)$nextId, 6, '0', STR_PAD_LEFT);

            // 4. Insertar cabecera de egreso compras.comprobantes_egreso_cxp
            $stmtInsEgr = $pdo->prepare("
                INSERT INTO compras.comprobantes_egreso_cxp (
                    id,
                    numero_comprobante,
                    proveedor_id,
                    usuario_registro_id,
                    monto_total_pagado,
                    fecha_pago,
                    moneda_id,
                    tasa_cambio
                ) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)
            ");
            $stmtInsEgr->execute([
                $nextId,
                $numComprobante,
                $proveedorId,
                $usuarioActualId,
                $totalMontoAplicadoFacturas,
                $monedaId,
                $tasaCambio
            ]);

            // 5. Insertar detalles de facturas compras.comprobante_egreso_detalles y retenciones
            $stmtInsDet = $pdo->prepare("
                INSERT INTO compras.comprobante_egreso_detalles (
                    comprobante_egreso_id,
                    cuenta_por_pagar_id,
                    monto_aplicado,
                    saldo_anterior,
                    saldo_restante,
                    monto_retenciones
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmtInsRetDet = $pdo->prepare("
                INSERT INTO compras.comprobante_egreso_retenciones (
                    comprobante_egreso_id,
                    cuenta_por_pagar_id,
                    retencion_id,
                    base_calculo_aplicada,
                    monto_base,
                    porcentaje_aplicado,
                    monto_retenido
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($facturasValidadas as $fv) {
                $stmtInsDet->execute([
                    $nextId,
                    $fv['cxp_id'],
                    $fv['monto_aplicado'],
                    $fv['saldo_anterior'],
                    $fv['saldo_restante'],
                    $fv['monto_retenciones']
                ]);

                foreach ($fv['retenciones'] as $ret) {
                    $stmtInsRetDet->execute([
                        $nextId,
                        $fv['cxp_id'],
                        $ret['retencion_id'],
                        $ret['base_calculo'],
                        $ret['monto_base'],
                        $ret['porcentaje'],
                        $ret['monto_retenido']
                    ]);
                }
            }

            // 6. Procesar Split de Pagos (tesoreria.detalles_pago con sesion_caja_id = NULL)
            $stmtInsDetPago = $pdo->prepare("
                INSERT INTO tesoreria.detalles_pago (
                    metodo_pago_id,
                    moneda_id,
                    tasa_cambio,
                    monto_pagado,
                    monto_devuelta,
                    sesion_caja_id,
                    fecha_pago
                ) VALUES (?, ?, ?, ?, 0.00, NULL, CURRENT_TIMESTAMP)
                RETURNING id
            ");

            $stmtInsEgresoPago = $pdo->prepare("
                INSERT INTO compras.egreso_cxp_pagos (
                    comprobante_egreso_id,
                    detalle_pago_id
                ) VALUES (?, ?)
            ");

            $stmtInsTransf = $pdo->prepare("
                INSERT INTO tesoreria.pago_transferencia (
                    detalle_pago_id,
                    banco_origen,
                    cuenta_bancaria_id,
                    numero_referencia,
                    fecha_transferencia,
                    titular_origen
                ) VALUES (?, ?, ?, ?, CURRENT_DATE, ?)
            ");

            $stmtInsCheque = $pdo->prepare("
                INSERT INTO tesoreria.pago_cheque (
                    detalle_pago_id,
                    numero_cheque,
                    banco_emisor,
                    cuenta_bancaria_id,
                    estado_cheque
                ) VALUES (?, ?, ?, ?, 'RECIBIDO')
            ");

            foreach ($pagosSplit as $pago) {
                $metodoId = (int)($pago['metodo_pago_id'] ?? 1);
                $montoP   = round((float)($pago['monto'] ?? 0), 2);
                if ($montoP <= 0) continue;

                $stmtInsDetPago->execute([
                    $metodoId,
                    $monedaId,
                    $tasaCambio,
                    $montoP
                ]);
                $detallePagoId = (int)$stmtInsDetPago->fetchColumn();

                $stmtInsEgresoPago->execute([$nextId, $detallePagoId]);

                // Consultar código del método de pago
                $stmtMet = $pdo->prepare("SELECT codigo FROM ventas.metodos_pago WHERE id = ?");
                $stmtMet->execute([$metodoId]);
                $codMetodo = strtoupper((string)$stmtMet->fetchColumn());

                if ($codMetodo === 'TRANSFERENCIA') {
                    $bancoOrig   = trim($pago['banco_origen'] ?? 'Banco Central');
                    $cuentaBanc  = (int)($pago['cuenta_bancaria_id'] ?? 1);
                    $numRef      = trim($pago['referencia'] ?? ('TRF-' . $nextId));
                    $titular     = trim($pago['titular'] ?? 'House & Ice');

                    $stmtInsTransf->execute([
                        $detallePagoId,
                        $bancoOrig,
                        $cuentaBanc,
                        $numRef,
                        $titular
                    ]);
                } elseif ($codMetodo === 'CHEQUE') {
                    $numCheque  = trim($pago['num_cheque'] ?? ('CHQ-' . $nextId));
                    $bancoEmis  = trim($pago['banco_emisor'] ?? 'Banco Popular');
                    $cuentaBanc = (int)($pago['cuenta_bancaria_id'] ?? 1);

                    $stmtInsCheque->execute([
                        $detallePagoId,
                        $numCheque,
                        $bancoEmis,
                        $cuentaBanc
                    ]);
                }
            }

            registrarAuditoria(
                $pdo,
                $usuarioActualId,
                null,
                'LIQUIDAR_EGRESO_CXP',
                'compras.comprobantes_egreso_cxp',
                null,
                [
                    'comprobante_id' => $nextId,
                    'numero_comprobante' => $numComprobante,
                    'total_pagado' => $totalMontoAplicadoFacturas,
                    'total_retenciones' => $totalMontoRetencionesFacturas,
                    'facturas_afectadas' => count($facturasValidadas)
                ]
            );

            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'mensaje' => "Comprobante de Egreso #{$numComprobante} generado exitosamente por RD$ " . number_format($totalMontoAplicadoFacturas, 2),
                'comprobante_id' => $nextId,
                'numero_comprobante' => $numComprobante,
                'total_desembolsado' => $totalMontoAplicadoFacturas,
                'total_retenciones' => $totalMontoRetencionesFacturas
            ]);
            break;


        // ====================================================================
        // FASE 4: AJUSTES, DESCUENTOS Y EXONERACIONES CON PIN DE SUPERVISOR
        // ====================================================================
        case 'registrar_ajuste':
            $cxpId           = (int)($_POST['cxp_id'] ?? 0);
            $tipoAjuste      = strtoupper(trim($_POST['tipo_ajuste'] ?? ''));
            $montoAjuste     = round((float)($_POST['monto_ajuste'] ?? 0), 2);
            $ncfNotaCredito  = strtoupper(trim($_POST['ncf_nota_credito_proveedor'] ?? ''));
            $justificacion   = trim($_POST['justificacion'] ?? '');
            $supervisorId    = trim($_POST['supervisor_id'] ?? '');
            $pinSupervisor   = trim($_POST['pin_supervisor'] ?? '');

            if ($cxpId <= 0) {
                throw new Exception("Debe especificar la cuenta por pagar a ajustar.");
            }
            if (!in_array($tipoAjuste, ['DESCUENTO_PRONTO_PAGO', 'EXONERACION_PROVEEDOR', 'NOTA_CREDITO_PROVEEDOR', 'ANULACION'])) {
                throw new Exception("Tipo de ajuste no válido: {$tipoAjuste}.");
            }
            if (empty($justificacion)) {
                throw new Exception("Debe ingresar la justificación obligatoria del ajuste.");
            }
            if ($tipoAjuste === 'NOTA_CREDITO_PROVEEDOR' && empty($ncfNotaCredito)) {
                throw new Exception("Debe especificar el NCF de la Nota de Crédito del proveedor (B04) para fines del Formato 606.");
            }

            // Validar PIN del supervisor con auditoria_helper
            $supervisorInfo = validarPinSupervisor($pdo, $supervisorId, $pinSupervisor);
            $supervisorPersonaId = $supervisorInfo['persona_id'] ?? $supervisorInfo['usuario_id'];

            $pdo->beginTransaction();

            // Bloquear la cuenta por pagar
            $stmtCxp = $pdo->prepare("
                SELECT id, saldo_pendiente, estado, moneda_id, tasa_cambio
                FROM compras.cuentas_por_pagar
                WHERE id = ? FOR UPDATE
            ");
            $stmtCxp->execute([$cxpId]);
            $cxp = $stmtCxp->fetch(PDO::FETCH_ASSOC);

            if (!$cxp) {
                throw new Exception("La cuenta por pagar #{$cxpId} no existe.");
            }
            if ($cxp['estado'] === 'ANULADA') {
                throw new Exception("La cuenta por pagar #{$cxpId} ya se encuentra ANULADA y su estado es irreversible.");
            }
            if ($cxp['estado'] === 'PAGADA') {
                throw new Exception("La cuenta por pagar #{$cxpId} ya fue totalmente PAGADA. No permite ajustes.");
            }

            $saldoAnterior = (float)$cxp['saldo_pendiente'];

            // Si es ANULACION, el ajuste debe ser exactamente igual al saldo_pendiente restante
            if ($tipoAjuste === 'ANULACION') {
                $montoAjuste = $saldoAnterior;
                $saldoRestante = 0.00;
            } else {
                if ($montoAjuste <= 0) {
                    throw new Exception("El monto del ajuste debe ser mayor a cero.");
                }
                if ($montoAjuste > $saldoAnterior) {
                    throw new Exception("El monto del ajuste (RD$ {$montoAjuste}) no puede superar el saldo pendiente (RD$ {$saldoAnterior}).");
                }
                $saldoRestante = round($saldoAnterior - $montoAjuste, 2);
            }

            $stmtInsAj = $pdo->prepare("
                INSERT INTO compras.ajustes_cxp (
                    cuenta_por_pagar_id,
                    tipo_ajuste,
                    ncf_nota_credito_proveedor,
                    moneda_id,
                    tasa_cambio,
                    monto_ajuste,
                    saldo_anterior,
                    saldo_restante,
                    justificacion,
                    supervisor_id,
                    fecha_ajuste
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                RETURNING id
            ");

            $stmtInsAj->execute([
                $cxpId,
                $tipoAjuste,
                ($tipoAjuste === 'NOTA_CREDITO_PROVEEDOR') ? $ncfNotaCredito : null,
                $cxp['moneda_id'],
                $cxp['tasa_cambio'],
                $montoAjuste,
                $saldoAnterior,
                $saldoRestante,
                $justificacion,
                $supervisorInfo['usuario_id']
            ]);

            $ajusteId = (int)$stmtInsAj->fetchColumn();

            registrarAuditoria(
                $pdo,
                $usuarioActualId,
                $supervisorInfo['usuario_id'],
                'REGISTRAR_AJUSTE_CXP',
                'compras.ajustes_cxp',
                ['estado' => $cxp['estado'], 'saldo_pendiente' => $saldoAnterior],
                [
                    'ajuste_id' => $ajusteId,
                    'cxp_id' => $cxpId,
                    'tipo_ajuste' => $tipoAjuste,
                    'monto_ajuste' => $montoAjuste,
                    'saldo_restante' => $saldoRestante,
                    'justificacion' => $justificacion
                ]
            );

            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'mensaje' => "Ajuste #{$ajusteId} ({$tipoAjuste}) procesado exitosamente por RD$ " . number_format($montoAjuste, 2) . ". Autorizado por: {$supervisorInfo['nombre_completo']}.",
                'ajuste_id' => $ajusteId,
                'saldo_restante' => $saldoRestante
            ]);
            break;


        // ====================================================================
        // CONSULTAR DETALLES DE UN COMPROBANTE DE EGRESO
        // ====================================================================
        case 'ver_detalle_egreso':
            $egresoId = (int)($_GET['egreso_id'] ?? 0);
            if ($egresoId <= 0) {
                throw new Exception("ID de comprobante no especificado.");
            }

            $stmtCab = $pdo->prepare("
                SELECT ce.*,
                       p.nombre_razon_social AS proveedor_nombre,
                       p.documento_identidad AS proveedor_rnc,
                       u.username AS usuario_registro_nombre,
                       m.nombre AS moneda_nombre,
                       m.simbolo AS moneda_simbolo
                FROM compras.comprobantes_egreso_cxp ce
                JOIN identidad.proveedores pr ON pr.persona_id = ce.proveedor_id
                JOIN identidad.personas p ON p.persona_id = pr.persona_id
                JOIN seguridad.usuarios u ON u.usuario_id = ce.usuario_registro_id
                JOIN tesoreria.monedas m ON m.id = ce.moneda_id
                WHERE ce.id = ?
            ");
            $stmtCab->execute([$egresoId]);
            $cabecera = $stmtCab->fetch(PDO::FETCH_ASSOC);

            if (!$cabecera) {
                throw new Exception("Comprobante de egreso no encontrado.");
            }

            // Facturas liquidadas
            $stmtDet = $pdo->prepare("
                SELECT ced.*,
                       cxp.fecha_emision,
                       cxp.fecha_vencimiento,
                       COALESCE(rc.numero_factura_proveedor, cxp.numero_factura_servicio, 'S/F') AS numero_factura,
                       COALESCE(rc.ncf_proveedor, cxp.ncf_servicio, 'N/A') AS ncf,
                       COALESCE(cxp.concepto_servicio, 'Mercancía Recepción #' || rc.id) AS concepto
                FROM compras.comprobante_egreso_detalles ced
                JOIN compras.cuentas_por_pagar cxp ON cxp.id = ced.cuenta_por_pagar_id
                LEFT JOIN compras.recepciones_compra rc ON rc.id = cxp.recepcion_compra_id
                WHERE ced.comprobante_egreso_id = ?
            ");
            $stmtDet->execute([$egresoId]);
            $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

            // Retenciones DGII aplicadas
            $stmtRet = $pdo->prepare("
                SELECT cer.*, r.siglas, r.nombre AS retencion_nombre
                FROM compras.comprobante_egreso_retenciones cer
                JOIN tesoreria.retenciones r ON r.id = cer.retencion_id
                WHERE cer.comprobante_egreso_id = ?
            ");
            $stmtRet->execute([$egresoId]);
            $retenciones = $stmtRet->fetchAll(PDO::FETCH_ASSOC);

            // Pagos en el split
            $stmtPag = $pdo->prepare("
                SELECT dp.*, mp.codigo AS metodo_codigo, mp.descripcion AS metodo_nombre,
                       pt.banco_origen, pt.numero_referencia, cb.nombre_banco, cb.numero_cuenta,
                       pch.numero_cheque, pch.banco_emisor
                FROM compras.egreso_cxp_pagos ecp
                JOIN tesoreria.detalles_pago dp ON dp.id = ecp.detalle_pago_id
                JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id
                LEFT JOIN tesoreria.pago_transferencia pt ON pt.detalle_pago_id = dp.id
                LEFT JOIN tesoreria.cuentas_bancarias cb ON cb.id = pt.cuenta_bancaria_id
                LEFT JOIN tesoreria.pago_cheque pch ON pch.detalle_pago_id = dp.id
                WHERE ecp.comprobante_egreso_id = ?
            ");
            $stmtPag->execute([$egresoId]);
            $pagos = $stmtPag->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'ok' => true,
                'cabecera' => $cabecera,
                'detalles' => $detalles,
                'retenciones' => $retenciones,
                'pagos' => $pagos
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
