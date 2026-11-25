<?php
/**
 * ============================================================================
 * Backend Controlador: Proceso 7 - Cuentas por Cobrar (CxC)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/CuentasPorCobrar/procesar_cxc.php
 * ============================================================================
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/funciones.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';
$usuarioId = $_SESSION['usuario_id'] ?? $_SESSION['user_id'] ?? $_SESSION['usuario']['usuario_id'] ?? null;
$personaId = $_SESSION['persona_id'] ?? $_SESSION['usuario']['persona_id'] ?? null;

// Validar autenticación
if (!$usuarioId) {
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión expirada. Debe iniciar sesión nuevamente.']);
    exit;
}

try {
    switch ($accion) {

        // ====================================================================
        // 1. Obtener deudas pendientes y sesión de caja activa de un cliente
        // ====================================================================
        case 'obtener_deudas_cliente':
            $clienteId = trim($_GET['cliente_id'] ?? $_POST['cliente_id'] ?? '');
            if (empty($clienteId)) {
                throw new Exception("ID de cliente requerido.");
            }

            // Consultar facturas y notas de débito pendientes
            $stmtCxc = $pdo->prepare("
                SELECT cxc.id,
                       cxc.monto_original,
                       cxc.saldo_pendiente,
                       cxc.estado,
                       cxc.fecha_emision,
                       cxc.fecha_vencimiento,
                       cxc.factura_id,
                       cxc.nota_debito_id,
                       COALESCE(f.numero_factura, 'ND-' || nd.ncf_nota_debito) AS numero_documento,
                       COALESCE(f.ncf, nd.ncf_nota_debito) AS ncf,
                       CASE 
                           WHEN cxc.factura_id IS NOT NULL THEN 'Factura Venta a Crédito #' || f.numero_factura
                           ELSE 'Nota de Débito (B03): ' || nd.concepto_cargo
                       END AS concepto,
                       (CURRENT_DATE - cxc.fecha_vencimiento) AS dias_diferencia
                FROM ventas.cuentas_por_cobrar cxc
                LEFT JOIN ventas.facturas f ON f.id = cxc.factura_id
                LEFT JOIN ventas.notas_debito nd ON nd.id = cxc.nota_debito_id
                WHERE cxc.cliente_id = ?
                  AND cxc.estado IN ('PENDIENTE', 'PAGADA_PARCIAL')
                ORDER BY cxc.fecha_vencimiento ASC, cxc.id ASC
            ");
            $stmtCxc->execute([$clienteId]);
            $deudas = $stmtCxc->fetchAll(PDO::FETCH_ASSOC);

            // Consultar sesión de caja activa del usuario
            $stmtCaja = $pdo->prepare("
                SELECT sc.id AS sesion_caja_id, c.nombre_equipo, sc.caja_id
                FROM ventas.sesiones_caja sc
                JOIN ventas.cajas_pos c ON c.id = sc.caja_id
                WHERE sc.cajero_id = ? AND sc.estado = 'ABIERTA'
                ORDER BY sc.id DESC
                LIMIT 1
            ");
            $stmtCaja->execute([$personaId]);
            $sesionActiva = $stmtCaja->fetch(PDO::FETCH_ASSOC);

            // Consultar saldo a favor acumulado del cliente
            $stmtSaldoFavor = $pdo->prepare("
                SELECT COALESCE(SUM(saldo_a_favor_generado), 0) AS total_generado
                FROM ventas.recibos_ingreso_cxc
                WHERE cliente_id = ?
            ");
            $stmtSaldoFavor->execute([$clienteId]);
            $totalSaldoFavor = (float)$stmtSaldoFavor->fetchColumn();

            echo json_encode([
                'ok' => true,
                'deudas' => $deudas,
                'sesion_caja' => $sesionActiva ?: null,
                'saldo_a_favor_disponible' => $totalSaldoFavor
            ]);
            break;


        // ====================================================================
        // FASE 2 & 3: REGISTRO DE RECIBO DE INGRESO CON SPLIT UNIVERSAL
        // ====================================================================
        case 'registrar_recibo_ingreso':
            if (!usuarioPuedeAccederCxc()) {
                throw new Exception("No cuenta con permisos autorizados para cobrar cuentas por cobrar.");
            }

            $payloadRaw = file_get_contents('php://input');
            $data = json_decode($payloadRaw, true) ?: $_POST;

            $clienteId      = trim($data['cliente_id'] ?? '');
            $facturasItems  = $data['facturas'] ?? []; // [{ cxc_id, monto_aplicado }]
            $pagosSplit     = $data['pagos'] ?? [];    // [{ metodo_pago_id, codigo, monto, monto_devuelta, ... }]
            $monedaId       = max(1, (int)($data['moneda_id'] ?? 1));
            $tasaCambio     = max(0.0001, (float)($data['tasa_cambio'] ?? 1.0000));

            if (empty($clienteId)) {
                throw new Exception("Debe seleccionar un cliente corporativo o VIP.");
            }
            if (empty($facturasItems)) {
                throw new Exception("Debe seleccionar al menos una factura para cobrar.");
            }
            if (empty($pagosSplit)) {
                throw new Exception("Debe especificar al menos un instrumento de pago en el Split Universal.");
            }

            // 1. Detectar si hay pagos físicos en mostrador (EFECTIVO o TARJETA)
            $requiereCaja = false;
            foreach ($pagosSplit as $pago) {
                $cod = strtoupper(trim($pago['codigo'] ?? ''));
                if (in_array($cod, ['EFECTIVO', 'TARJETA'])) {
                    $requiereCaja = true;
                    break;
                }
            }

            $sesionCajaId = null;
            if ($requiereCaja) {
                $stmtSes = $pdo->prepare("
                    SELECT id FROM ventas.sesiones_caja
                    WHERE cajero_id = ? AND estado = 'ABIERTA'
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtSes->execute([$personaId]);
                $sesionCajaId = $stmtSes->fetchColumn();

                if (!$sesionCajaId) {
                    throw new Exception("Para registrar cobros físicos en mostrador (Efectivo o Tarjeta), el cajero debe tener un turno de caja abierto en Control de Caja. Si el cobro se realizó por Banco (Transferencia o Cheque), seleccione ese método.");
                }
            }

            $pdo->beginTransaction();

            // 2. Validar facturas y bloquear filas FOR UPDATE
            $totalMontoAplicadoFacturas = 0.00;
            $facturasValidadas = [];

            foreach ($facturasItems as $fItem) {
                $cxcId = (int)($fItem['cxc_id'] ?? 0);
                $montoAplicar = round((float)($fItem['monto_aplicado'] ?? 0), 2);

                if ($montoAplicar <= 0) {
                    continue;
                }

                $stmtCxc = $pdo->prepare("
                    SELECT id, cliente_id, saldo_pendiente, estado, factura_id
                    FROM ventas.cuentas_por_cobrar
                    WHERE id = ? FOR UPDATE
                ");
                $stmtCxc->execute([$cxcId]);
                $cxcRow = $stmtCxc->fetch(PDO::FETCH_ASSOC);

                if (!$cxcRow) {
                    throw new Exception("La cuenta por cobrar #{$cxcId} no existe.");
                }
                if ($cxcRow['cliente_id'] !== $clienteId) {
                    throw new Exception("La cuenta por cobrar #{$cxcId} no pertenece al cliente seleccionado.");
                }
                if (!in_array($cxcRow['estado'], ['PENDIENTE', 'PAGADA_PARCIAL'])) {
                    throw new Exception("La cuenta por cobrar #{$cxcId} ya no está pendiente (Estado: {$cxcRow['estado']}).");
                }

                $saldoAnterior = (float)$cxcRow['saldo_pendiente'];
                if ($montoAplicar > $saldoAnterior) {
                    throw new Exception("El monto aplicado (RD$ {$montoAplicar}) excede el saldo pendiente (RD$ {$saldoAnterior}) en la deuda #{$cxcId}.");
                }

                $saldoRestante = round($saldoAnterior - $montoAplicar, 2);
                $totalMontoAplicadoFacturas += $montoAplicar;

                $facturasValidadas[] = [
                    'cxc_id' => $cxcId,
                    'saldo_anterior' => $saldoAnterior,
                    'monto_aplicado' => $montoAplicar,
                    'saldo_restante' => $saldoRestante
                ];
            }

            if (empty($facturasValidadas)) {
                throw new Exception("No se especificó ningún monto válido para cobrar.");
            }

            // 3. Sumar pagos del Split
            $totalPagadoMetodos = 0.00;
            foreach ($pagosSplit as $pago) {
                $montoP = round((float)($pago['monto'] ?? 0), 2);
                $devuelta = round((float)($pago['monto_devuelta'] ?? 0), 2);
                $netoInstrumento = max(0, $montoP - $devuelta);
                if ($netoInstrumento > 0) {
                    $totalPagadoMetodos += $netoInstrumento;
                }
            }

            // Validar si cubre las facturas
            if ($totalPagadoMetodos < $totalMontoAplicadoFacturas) {
                $faltante = round($totalMontoAplicadoFacturas - $totalPagadoMetodos, 2);
                throw new Exception("Los instrumentos de pago (RD$ {$totalPagadoMetodos}) no cubren el total aplicado a las deudas (RD$ {$totalMontoAplicadoFacturas}). Faltan RD$ {$faltante}.");
            }

            // Escenario D: Pago con Excedente
            $saldoAFavorGenerado = round($totalPagadoMetodos - $totalMontoAplicadoFacturas, 2);

            // 4. Generar correlativo del Recibo de Ingreso
            $anioActual = date('Y');
            $stmtSeq = $pdo->query("SELECT nextval('ventas.recibos_ingreso_cxc_id_seq')");
            $nextId = (int)$stmtSeq->fetchColumn();
            $numRecibo = 'REC-' . $anioActual . '-' . str_pad((string)$nextId, 6, '0', STR_PAD_LEFT);

            // 5. Insertar cabecera en ventas.recibos_ingreso_cxc
            $stmtInsRecibo = $pdo->prepare("
                INSERT INTO ventas.recibos_ingreso_cxc (
                    id,
                    numero_recibo,
                    cliente_id,
                    sesion_caja_id,
                    cajero_id,
                    monto_total_recibido,
                    fecha_recibo,
                    saldo_a_favor_generado,
                    moneda_id,
                    tasa_cambio
                ) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?)
            ");
            $stmtInsRecibo->execute([
                $nextId,
                $numRecibo,
                $clienteId,
                $sesionCajaId,
                $personaId,
                $totalPagadoMetodos,
                $saldoAFavorGenerado,
                $monedaId,
                $tasaCambio
            ]);

            // 6. Insertar líneas en ventas.recibo_ingreso_detalles
            // (El trigger trg_actualizar_saldo_cxc_recibo actualiza saldos y facturas automáticamente)
            $stmtInsDet = $pdo->prepare("
                INSERT INTO ventas.recibo_ingreso_detalles (
                    recibo_ingreso_id,
                    cuenta_por_cobrar_id,
                    monto_aplicado,
                    saldo_anterior,
                    saldo_restante
                ) VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($facturasValidadas as $fv) {
                $stmtInsDet->execute([
                    $nextId,
                    $fv['cxc_id'],
                    $fv['monto_aplicado'],
                    $fv['saldo_anterior'],
                    $fv['saldo_restante']
                ]);
            }

            // 7. Insertar pagos en tesoreria.detalles_pago y sus subtipos (Split Universal)
            $stmtInsDp = $pdo->prepare("
                INSERT INTO tesoreria.detalles_pago (
                    metodo_pago_id,
                    monto_pagado,
                    monto_devuelta,
                    moneda_id,
                    tasa_cambio,
                    sesion_caja_id
                ) VALUES (?, ?, ?, ?, ?, ?)
                RETURNING id
            ");

            $stmtInsPuente = $pdo->prepare("
                INSERT INTO ventas.recibo_cxc_pagos (recibo_ingreso_id, detalle_pago_id)
                VALUES (?, ?)
            ");

            foreach ($pagosSplit as $pago) {
                $metodoId = (int)($pago['metodo_pago_id'] ?? 0);
                $codMet = strtoupper(trim($pago['codigo'] ?? ''));
                $montoP = round((float)($pago['monto'] ?? 0), 2);
                $devuelta = round((float)($pago['monto_devuelta'] ?? 0), 2);

                if ($montoP <= 0) continue;

                // Si es EFECTIVO o TARJETA: asocia sesion_caja_id para Arqueo Ciego
                // Si es TRANSFERENCIA o CHEQUE: sesion_caja_id = NULL
                $sesionPago = in_array($codMet, ['EFECTIVO', 'TARJETA']) ? $sesionCajaId : null;

                $stmtInsDp->execute([
                    $metodoId,
                    $montoP,
                    $devuelta,
                    $monedaId,
                    $tasaCambio,
                    $sesionPago
                ]);
                $dpId = (int)$stmtInsDp->fetchColumn();

                // Enlace puente recibo_cxc_pagos
                $stmtInsPuente->execute([$nextId, $dpId]);

                // Subtipos 1:1
                if ($codMet === 'TARJETA') {
                    $tipoTarj = strtoupper(trim($pago['tipo_tarjeta'] ?? 'CREDITO'));
                    if (!in_array($tipoTarj, ['CREDITO', 'DEBITO'])) $tipoTarj = 'CREDITO';

                    $marcaTarj = strtoupper(trim($pago['marca_tarjeta'] ?? 'VISA'));
                    if (!in_array($marcaTarj, ['VISA', 'MASTERCARD', 'AMEX', 'OTRA'])) $marcaTarj = 'VISA';

                    $ult4 = substr(trim($pago['ultimos_4'] ?? '0000'), -4);
                    $numAut = trim($pago['autorizacion'] ?? 'AUT-CXC');
                    $lote = trim($pago['lote'] ?? '1');

                    $pdo->prepare("
                        INSERT INTO tesoreria.pago_tarjeta (
                            detalle_pago_id, tipo_tarjeta, marca_tarjeta, ultimos_4_digitos, numero_autorizacion, numero_lote
                        ) VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([$dpId, $tipoTarj, $marcaTarj, $ult4, $numAut, $lote]);

                } elseif ($codMet === 'TRANSFERENCIA') {
                    $cuentaBanId = (int)($pago['cuenta_bancaria_id'] ?? 1);
                    $refTrf = trim($pago['referencia'] ?? $numRecibo);
                    $bancoOrig = trim($pago['banco_origen'] ?? 'Banco Cliente');
                    $titularOrig = trim($pago['titular'] ?? 'Cliente Corporativo');

                    $pdo->prepare("
                        INSERT INTO tesoreria.pago_transferencia (
                            detalle_pago_id, banco_origen, cuenta_bancaria_id, numero_referencia, fecha_transferencia, titular_origen
                        ) VALUES (?, ?, ?, ?, CURRENT_DATE, ?)
                    ")->execute([$dpId, $bancoOrig, $cuentaBanId, $refTrf, $titularOrig]);

                } elseif ($codMet === 'CHEQUE') {
                    $numChq = trim($pago['num_cheque'] ?? '0001');
                    $bancoEmisor = trim($pago['banco_emisor'] ?? 'Banco Emisor');
                    $titularChq = trim($pago['titular'] ?? 'Cliente');
                    $cuentaBanId = !empty($pago['cuenta_bancaria_id']) ? (int)$pago['cuenta_bancaria_id'] : null;

                    $pdo->prepare("
                        INSERT INTO tesoreria.pago_cheque (
                            detalle_pago_id, numero_cheque, banco_emisor, titular_cheque, fecha_emision, fecha_deposito, cuenta_bancaria_id, estado_cheque
                        ) VALUES (?, ?, ?, ?, CURRENT_DATE, CURRENT_DATE, ?, 'RECIBIDO')
                    ")->execute([$dpId, $numChq, $bancoEmisor, $titularChq, $cuentaBanId]);

                } elseif ($codMet === 'SALDO_FAVOR') {
                    $pdo->prepare("
                        INSERT INTO tesoreria.pago_saldo_favor (
                            detalle_pago_id, cliente_id, origen_saldo, documento_origen_id
                        ) VALUES (?, ?, 'RECIBO_CXC_ANTERIOR', ?)
                    ")->execute([$dpId, $clienteId, $nextId]);
                }
            }

            // Registro Forense de Auditoría
            registrarAuditoria(
                $pdo,
                $usuarioId,
                null,
                'REGISTRAR_RECIBO_CXC',
                'ventas.recibos_ingreso_cxc',
                null,
                [
                    'recibo_id' => $nextId,
                    'numero_recibo' => $numRecibo,
                    'cliente_id' => $clienteId,
                    'sesion_caja_id' => $sesionCajaId,
                    'cajero_persona_id' => $personaId,
                    'monto_total_recibido' => $totalPagadoMetodos,
                    'saldo_a_favor_generado' => $saldoAFavorGenerado,
                    'facturas_liquidadas' => $facturasValidadas,
                    'pagos_split' => $pagosSplit
                ]
            );

            $pdo->commit();

            $msgExcedente = ($saldoAFavorGenerado > 0)
                ? " Se acreditó un excedente de RD$ " . number_format($saldoAFavorGenerado, 2) . " como saldo a favor del cliente."
                : "";

            echo json_encode([
                'ok' => true,
                'mensaje' => "¡Recibo de Ingreso {$numRecibo} generado exitosamente! Deudas actualizadas.{$msgExcedente}",
                'recibo_id' => $nextId,
                'numero_recibo' => $numRecibo,
                'saldo_a_favor_generado' => $saldoAFavorGenerado
            ]);
            break;


        // ====================================================================
        // FASE 4: AJUSTES, CONDONACIONES E INCOBRABLES CON PIN DE SUPERVISOR
        // ====================================================================
        case 'registrar_ajuste':
            if (!usuarioPuedeAccederCxc()) {
                throw new Exception("No cuenta con permisos autorizados para realizar ajustes de CxC.");
            }

            $cxcId          = (int)($_POST['cxc_id'] ?? 0);
            $tipoAjuste     = trim($_POST['tipo_ajuste'] ?? '');
            $montoAjuste    = round((float)($_POST['monto_ajuste'] ?? 0), 2);
            $justificacion  = trim($_POST['justificacion'] ?? '');
            $pinSupervisor  = trim($_POST['pin_supervisor'] ?? '');
            $supervisorPost = trim($_POST['supervisor_id'] ?? '');

            if ($cxcId <= 0) {
                throw new Exception("ID de cuenta por cobrar inválido.");
            }
            if (!in_array($tipoAjuste, ['DESCUENTO_PRONTO_PAGO', 'CONDONACION', 'INCOBRABLE', 'NOTA_CREDITO_B04'])) {
                throw new Exception("Tipo de ajuste no válido: {$tipoAjuste}");
            }
            if (empty($justificacion) || strlen($justificacion) < 6) {
                throw new Exception("Debe especificar una justificación detallada de al menos 6 caracteres.");
            }
            if (empty($pinSupervisor)) {
                throw new Exception("El PIN de supervisor es obligatorio para autorizar ajustes.");
            }

            // Validar PIN de supervisor usando auditoria_helper
            if (!empty($supervisorPost)) {
                $supervisorInfo = validarPinSupervisor($pdo, $supervisorPost, $pinSupervisor);
            } else {
                // Fallback de búsqueda entre supervisores autorizados si no se envió select explícito
                $supervisores = obtenerSupervisoresAutorizados($pdo);
                $supervisorInfo = null;
                foreach ($supervisores as $s) {
                    try {
                        $supervisorInfo = validarPinSupervisor($pdo, $s['usuario_id'], $pinSupervisor);
                        break;
                    } catch (Throwable $e) {}
                }
                if (!$supervisorInfo) {
                    throw new Exception("El PIN ingresado es inválido o no pertenece a ningún supervisor autorizado.");
                }
            }

            $supervisorId = $supervisorInfo['usuario_id'];

            $pdo->beginTransaction();

            $stmtCxc = $pdo->prepare("
                SELECT id, saldo_pendiente, estado, moneda_id, tasa_cambio
                FROM ventas.cuentas_por_cobrar
                WHERE id = ? FOR UPDATE
            ");
            $stmtCxc->execute([$cxcId]);
            $cxc = $stmtCxc->fetch(PDO::FETCH_ASSOC);

            if (!$cxc) {
                throw new Exception("La cuenta por cobrar #{$cxcId} no existe.");
            }
            if (in_array($cxc['estado'], ['ANULADA', 'INCOBRABLE', 'PAGADA'])) {
                throw new Exception("No se pueden aplicar ajustes a una obligación en estado '{$cxc['estado']}'.");
            }

            $saldoAnterior = (float)$cxc['saldo_pendiente'];

            // Si es INCOBRABLE: se cancela la totalidad del saldo restante
            if ($tipoAjuste === 'INCOBRABLE') {
                $montoAjuste = $saldoAnterior;
            }

            if ($montoAjuste <= 0 || $montoAjuste > $saldoAnterior) {
                throw new Exception("El monto del ajuste (RD$ {$montoAjuste}) debe ser mayor a 0 y no puede exceder el saldo pendiente (RD$ {$saldoAnterior}).");
            }

            $saldoRestante = round($saldoAnterior - $montoAjuste, 2);

            // Insertar en ventas.ajustes_cxc
            // (El trigger trg_actualizar_saldo_cxc_ajuste actualiza saldo_pendiente y estado)
            $stmtInsAj = $pdo->prepare("
                INSERT INTO ventas.ajustes_cxc (
                    cuenta_por_cobrar_id,
                    tipo_ajuste,
                    moneda_id,
                    tasa_cambio,
                    monto_ajuste,
                    saldo_anterior,
                    saldo_restante,
                    justificacion,
                    supervisor_id,
                    fecha_ajuste
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                RETURNING id
            ");
            $stmtInsAj->execute([
                $cxcId,
                $tipoAjuste,
                $cxc['moneda_id'],
                $cxc['tasa_cambio'],
                $montoAjuste,
                $saldoAnterior,
                $saldoRestante,
                $justificacion,
                $supervisorId
            ]);
            $ajusteId = (int)$stmtInsAj->fetchColumn();

            // Registro Forense de Auditoría
            registrarAuditoria(
                $pdo,
                $usuarioId,
                $supervisorId,
                'AJUSTE_CXC_' . $tipoAjuste,
                'ventas.ajustes_cxc',
                [
                    'cxc_id' => $cxcId,
                    'saldo_anterior' => $saldoAnterior,
                    'estado_anterior' => $cxc['estado']
                ],
                [
                    'ajuste_id' => $ajusteId,
                    'tipo_ajuste' => $tipoAjuste,
                    'monto_ajuste' => $montoAjuste,
                    'saldo_restante' => $saldoRestante,
                    'justificacion' => $justificacion,
                    'supervisor_id' => $supervisorId,
                    'supervisor_username' => $supervisorInfo['username']
                ]
            );

            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'mensaje' => "Ajuste de {$tipoAjuste} por RD$ " . number_format($montoAjuste, 2) . " aplicado exitosamente."
            ]);
            break;


        // ====================================================================
        // FASE 1: ORIGEN POR NOTA DE DÉBITO (NCF B03) / CARGO ADICIONAL
        // ====================================================================
        case 'registrar_nota_debito_cargo':
            if (!usuarioPuedeAccederCxc()) {
                throw new Exception("No cuenta con permisos para emitir notas de débito o cargos.");
            }

            $clienteId      = trim($_POST['cliente_id'] ?? '');
            $ncfFacturaMod  = trim($_POST['ncf_factura_modificada'] ?? '');
            $concepto       = trim($_POST['concepto_cargo'] ?? '');
            $montoTotal     = round((float)($_POST['monto_total'] ?? 0), 2);
            $diasCredito    = max(0, (int)($_POST['dias_credito'] ?? 30));

            if (empty($clienteId)) {
                throw new Exception("Debe seleccionar el cliente para el cargo.");
            }
            if (empty($concepto) || strlen($concepto) < 5) {
                throw new Exception("Debe indicar el concepto del cargo (ej. Cheque devuelto, Mora, etc.).");
            }
            if ($montoTotal <= 0) {
                throw new Exception("El monto total del cargo debe ser mayor a 0.00.");
            }

            $pdo->beginTransaction();

            // Buscar si hay una factura del cliente asociada
            $stmtFac = $pdo->prepare("
                SELECT id, ncf, moneda_id, tasa_cambio FROM ventas.facturas
                WHERE cliente_id = ? " . (!empty($ncfFacturaMod) ? "AND (ncf = ? OR numero_factura = ?)" : "") . "
                ORDER BY id DESC LIMIT 1
            ");
            if (!empty($ncfFacturaMod)) {
                $stmtFac->execute([$clienteId, $ncfFacturaMod, $ncfFacturaMod]);
            } else {
                $stmtFac->execute([$clienteId]);
            }
            $facRow = $stmtFac->fetch(PDO::FETCH_ASSOC);

            $facturaId = $facRow ? $facRow['id'] : null;
            $ncfMod = $facRow ? $facRow['ncf'] : ($ncfFacturaMod ?: 'B0200000001');

            // Obtener o generar correlativo B03 para Nota de Débito
            $stmtSeqB03 = $pdo->prepare("
                SELECT id, secuencia_actual, secuencia_hasta
                FROM ventas.secuencias_fiscales_dgii
                WHERE tipo_comprobante = 'B03' AND estado = 'ACTIVO'
                FOR UPDATE
                LIMIT 1
            ");
            $stmtSeqB03->execute();
            $seqRow = $stmtSeqB03->fetch(PDO::FETCH_ASSOC);

            if ($seqRow) {
                $nextNum = (int)$seqRow['secuencia_actual'] + 1;
                $ncfNotaDebito = 'B03' . str_pad((string)$nextNum, 8, '0', STR_PAD_LEFT);
                $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET secuencia_actual = ? WHERE id = ?")->execute([$nextNum, $seqRow['id']]);
                $secuenciaDgiiId = $seqRow['id'];
            } else {
                $stmtSeqId = $pdo->query("SELECT nextval('ventas.notas_debito_id_seq')");
                $ndSeqId = (int)$stmtSeqId->fetchColumn();
                $ncfNotaDebito = 'B03' . str_pad((string)$ndSeqId, 8, '0', STR_PAD_LEFT);
                $secuenciaDgiiId = null;
            }

            // Subtotal e ITBIS (si no se desglosa, monto_total con 0 ITBIS o 18%)
            $subtotal = $montoTotal;
            $impuestos = 0.00;

            // Si facturaId es obligatorio en notas_debito pero no existe, usar la primera factura del sistema o crear referencia
            if (!$facturaId) {
                $facturaId = $pdo->query("SELECT id FROM ventas.facturas ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 1;
            }

            $stmtInsNd = $pdo->prepare("
                INSERT INTO ventas.notas_debito (
                    factura_id, cliente_id, secuencia_dgii_id, ncf_nota_debito, ncf_factura_modificada,
                    concepto_cargo, monto_subtotal, monto_impuestos, monto_total, usuario_emisor_id,
                    fecha_emision, moneda_id, tasa_cambio
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 1, 1.0000)
                RETURNING id
            ");
            $stmtInsNd->execute([
                $facturaId,
                $clienteId,
                $secuenciaDgiiId,
                $ncfNotaDebito,
                $ncfMod,
                $concepto,
                $subtotal,
                $impuestos,
                $montoTotal,
                $personaId
            ]);
            $ndId = (int)$stmtInsNd->fetchColumn();

            // Insertar automáticamente en ventas.cuentas_por_cobrar
            // (Cumple chk_cxc_origen_documento: factura_id = NULL, nota_debito_id = $ndId)
            $fechaVenc = date('Y-m-d', strtotime("+{$diasCredito} days"));
            $stmtInsCxc = $pdo->prepare("
                INSERT INTO ventas.cuentas_por_cobrar (
                    cliente_id,
                    factura_id,
                    nota_debito_id,
                    monto_original,
                    saldo_pendiente,
                    estado,
                    fecha_emision,
                    fecha_vencimiento,
                    moneda_id,
                    tasa_cambio
                ) VALUES (?, NULL, ?, ?, ?, 'PENDIENTE', CURRENT_DATE, ?, 1, 1.0000)
                RETURNING id
            ");
            $stmtInsCxc->execute([
                $clienteId,
                $ndId,
                $montoTotal,
                $montoTotal,
                $fechaVenc
            ]);
            $cxcIdGenerado = (int)$stmtInsCxc->fetchColumn();

            // Registro Forense de Auditoría
            registrarAuditoria(
                $pdo,
                $usuarioId,
                null,
                'EMITIR_NOTA_DEBITO_B03',
                'ventas.notas_debito',
                null,
                [
                    'nota_debito_id' => $ndId,
                    'ncf_nota_debito' => $ncfNotaDebito,
                    'cxc_id' => $cxcIdGenerado,
                    'cliente_id' => $clienteId,
                    'monto_total' => $montoTotal,
                    'concepto_cargo' => $concepto,
                    'ncf_factura_modificada' => $ncfMod,
                    'fecha_vencimiento' => $fechaVenc
                ]
            );

            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'mensaje' => "Nota de Débito ({$ncfNotaDebito}) y cargo en CxC generados exitosamente por RD$ " . number_format($montoTotal, 2) . "."
            ]);
            break;


        // ====================================================================
        // 5. Ver detalle de recibo de ingreso
        // ====================================================================
        case 'ver_detalle_recibo':
            $reciboId = (int)($_GET['recibo_id'] ?? 0);
            if ($reciboId <= 0) {
                throw new Exception("ID de recibo inválido.");
            }

            // Cabecera
            $stmtCab = $pdo->prepare("
                SELECT r.id,
                       r.numero_recibo,
                       r.fecha_recibo,
                       r.monto_total_recibido,
                       r.saldo_a_favor_generado,
                       p.nombre_razon_social AS cliente_nombre,
                       p.documento_identidad AS cliente_rnc,
                       p.telefono_principal AS cliente_telefono,
                       caj_p.nombre_razon_social AS cajero_nombre,
                       u.username AS cajero_username,
                       sc.caja_id,
                       cpos.nombre_equipo AS caja_nombre
                FROM ventas.recibos_ingreso_cxc r
                JOIN identidad.clientes c ON c.persona_id = r.cliente_id
                JOIN identidad.personas p ON p.persona_id = c.persona_id
                JOIN identidad.personas caj_p ON caj_p.persona_id = r.cajero_id
                LEFT JOIN seguridad.usuarios u ON u.persona_id = r.cajero_id
                LEFT JOIN ventas.sesiones_caja sc ON sc.id = r.sesion_caja_id
                LEFT JOIN ventas.cajas_pos cpos ON cpos.id = sc.caja_id
                WHERE r.id = ?
            ");
            $stmtCab->execute([$reciboId]);
            $recibo = $stmtCab->fetch(PDO::FETCH_ASSOC);

            if (!$recibo) {
                throw new Exception("Recibo de ingreso no encontrado.");
            }

            // Facturas canceladas
            $stmtDet = $pdo->prepare("
                SELECT rd.cuenta_por_cobrar_id,
                       rd.monto_aplicado,
                       rd.saldo_anterior,
                       rd.saldo_restante,
                       COALESCE(f.numero_factura, 'ND-' || nd.ncf_nota_debito) AS numero_documento,
                       COALESCE(f.ncf, nd.ncf_nota_debito) AS ncf
                FROM ventas.recibo_ingreso_detalles rd
                JOIN ventas.cuentas_por_cobrar cxc ON cxc.id = rd.cuenta_por_cobrar_id
                LEFT JOIN ventas.facturas f ON f.id = cxc.factura_id
                LEFT JOIN ventas.notas_debito nd ON nd.id = cxc.nota_debito_id
                WHERE rd.recibo_ingreso_id = ?
            ");
            $stmtDet->execute([$reciboId]);
            $facturas = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

            // Pagos del Split
            $stmtPags = $pdo->prepare("
                SELECT dp.id,
                       mp.codigo AS metodo_codigo,
                       mp.descripcion AS metodo_nombre,
                       dp.monto_pagado,
                       dp.monto_devuelta,
                       dp.sesion_caja_id,
                       pt.tipo_tarjeta, pt.marca_tarjeta, pt.ultimos_4_digitos, pt.numero_autorizacion,
                       ptr.banco_origen, ptr.numero_referencia, cb.nombre_banco,
                       pch.numero_cheque, pch.banco_emisor
                FROM ventas.recibo_cxc_pagos rcp
                JOIN tesoreria.detalles_pago dp ON dp.id = rcp.detalle_pago_id
                JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id
                LEFT JOIN tesoreria.pago_tarjeta pt ON pt.detalle_pago_id = dp.id
                LEFT JOIN tesoreria.pago_transferencia ptr ON ptr.detalle_pago_id = dp.id
                LEFT JOIN tesoreria.cuentas_bancarias cb ON cb.id = ptr.cuenta_bancaria_id
                LEFT JOIN tesoreria.pago_cheque pch ON pch.detalle_pago_id = dp.id
                WHERE rcp.recibo_ingreso_id = ?
            ");
            $stmtPags->execute([$reciboId]);
            $pagos = $stmtPags->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'ok' => true,
                'recibo' => $recibo,
                'facturas' => $facturas,
                'pagos' => $pagos
            ]);
            break;

        default:
            throw new Exception("Acción no reconocida: " . htmlspecialchars($accion));
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'mensaje' => $e->getMessage()]);
}
