<?php
/**
 * ============================================================================
 * CONTROLADOR BACKEND • PROCESO 2: CUENTAS POR PAGAR (CXP) Y RETENCIONES DGII
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/cuentas_por_pagar/procesar_cxp.php
 * ============================================================================
 */

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

function responderCxp(bool $ok, string $mensaje, array $extra = [], int $httpCode = 200): void
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

if (!isset($_SESSION['usuario']['usuario_id'])) {
    responderCxp(false, 'Sesión no iniciada o caducada. Por favor, reingrese al sistema.', [], 401);
}

if (!usuarioPuedeAccederCxp()) {
    responderCxp(false, 'No tiene los permisos requeridos (GESTIONAR_CXP) para operar este módulo.', [], 403);
}

$puedeLiquidarDgii = (function_exists('usuarioEsAdministrador') && usuarioEsAdministrador())
    || (function_exists('usuarioTienePermiso') && (
        usuarioTienePermiso('REGISTRAR_MOVIMIENTOS_BANCARIOS')
        || usuarioTienePermiso('EJECUTAR_CIERRES_FISCALES_PERIODO')
    ));

$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : null;
$datos = is_array($jsonBody) ? array_merge($_GET, $jsonBody) : array_merge($_GET, $_POST);

$accion = trim((string) ($datos['accion'] ?? $_POST['accion'] ?? $_GET['accion'] ?? ''));
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
            $proveedorId = trim((string) ($datos['proveedor_id'] ?? ''));
            $numFactura = trim((string) ($datos['numero_factura_servicio'] ?? ''));
            $ncf = strtoupper(trim((string) ($datos['ncf_servicio'] ?? '')));
            $concepto = trim((string) ($datos['concepto_servicio'] ?? ''));
            $fechaEmision = trim((string) ($datos['fecha_emision'] ?? date('Y-m-d')));
            $condicionPago = strtoupper(trim((string) ($datos['condicion_pago'] ?? 'CONTADO')));
            $diasCredito = max(0, (int) ($datos['dias_credito'] ?? 0));
            $monedaId = max(1, (int) ($datos['moneda_id'] ?? 1));
            $montoTotal = round((float) ($datos['monto_original'] ?? 0), 2);

            if ($proveedorId === '') {
                throw new Exception("Debe seleccionar el proveedor o prestador del servicio.");
            }
            if ($montoTotal <= 0) {
                throw new Exception("El monto total de la obligación debe ser mayor a cero.");
            }
            if ($concepto === '') {
                throw new Exception("Debe especificar el concepto del gasto o servicio.");
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaEmision)) {
                throw new Exception("La fecha de emisión debe tener formato AAAA-MM-DD.");
            }
            if ($ncf !== '' && strlen($ncf) > 13) {
                throw new Exception("El NCF no puede superar los 13 caracteres (formato DGII).");
            }

            $stProv = $pdo->prepare("SELECT persona_id FROM identidad.proveedores WHERE persona_id = ? LIMIT 1");
            $stProv->execute([$proveedorId]);
            if (!$stProv->fetchColumn()) {
                throw new Exception("El proveedor seleccionado no existe.");
            }

            $stMon = $pdo->prepare("SELECT id, tasa_cambio_referencia FROM tesoreria.monedas WHERE id = ? AND activa = TRUE LIMIT 1");
            $stMon->execute([$monedaId]);
            $monRow = $stMon->fetch(PDO::FETCH_ASSOC);
            if (!$monRow) {
                throw new Exception("La moneda seleccionada no existe o está inactiva.");
            }
            $tasaCambio = max(0.0001, (float) $monRow['tasa_cambio_referencia']);

            if ($numFactura !== '') {
                $stDupF = $pdo->prepare("
                    SELECT id FROM compras.cuentas_por_pagar
                    WHERE proveedor_id = ? AND estado <> 'ANULADA'
                      AND UPPER(TRIM(COALESCE(numero_factura_servicio, ''))) = UPPER(?)
                    LIMIT 1
                ");
                $stDupF->execute([$proveedorId, $numFactura]);
                if ($dupId = $stDupF->fetchColumn()) {
                    throw new Exception("Ya existe la obligación #{$dupId} con el N° de factura «{$numFactura}» para este proveedor.");
                }
            }
            if ($ncf !== '') {
                $stDupN = $pdo->prepare("
                    SELECT id FROM compras.cuentas_por_pagar
                    WHERE proveedor_id = ? AND estado <> 'ANULADA'
                      AND UPPER(TRIM(COALESCE(ncf_servicio, ''))) = UPPER(?)
                    LIMIT 1
                ");
                $stDupN->execute([$proveedorId, $ncf]);
                if ($dupId = $stDupN->fetchColumn()) {
                    throw new Exception("Ya existe la obligación #{$dupId} con el NCF «{$ncf}» para este proveedor.");
                }
            }

            $fechaVencimiento = ($condicionPago === 'CONTADO')
                ? $fechaEmision
                : date('Y-m-d', strtotime($fechaEmision . " + {$diasCredito} days"));

            $pdo->beginTransaction();

            $stmtIns = $pdo->prepare("
                INSERT INTO compras.cuentas_por_pagar (
                    proveedor_id, recepcion_compra_id, monto_original, saldo_pendiente,
                    fecha_emision, fecha_vencimiento, estado, moneda_id, tasa_cambio,
                    numero_factura_servicio, ncf_servicio, concepto_servicio
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
                $numFactura !== '' ? $numFactura : null,
                $ncf !== '' ? $ncf : null,
                $concepto
            ]);

            $cxpId = (int) $stmtIns->fetchColumn();

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
                    'moneda_id' => $monedaId,
                    'tasa_cambio' => $tasaCambio,
                    'concepto' => $concepto,
                    'ncf' => $ncf
                ]
            );

            $pdo->commit();

            responderCxp(true, "Obligación #{$cxpId} registrada exitosamente en estado PENDIENTE.", [
                'cxp_id' => $cxpId
            ]);
            break;


        // ====================================================================
        // FASE 2: OBTENER FACTURAS PENDIENTES Y RETENCIONES DE UN PROVEEDOR
        // ====================================================================
        case 'obtener_facturas_proveedor':
            $proveedorId = trim((string) ($datos['proveedor_id'] ?? ''));
            if ($proveedorId === '') {
                throw new Exception("Proveedor no especificado.");
            }

            $stmt = $pdo->prepare("
                SELECT cxp.id, cxp.monto_original, cxp.saldo_pendiente,
                       cxp.fecha_emision, cxp.fecha_vencimiento, cxp.estado,
                       cxp.moneda_id, cxp.tasa_cambio,
                       m.simbolo AS moneda_simbolo, m.codigo_iso AS moneda_iso,
                       COALESCE(rc.numero_factura_proveedor, cxp.numero_factura_servicio, 'S/F') AS numero_factura,
                       COALESCE(rc.ncf_proveedor, cxp.ncf_servicio, 'N/A') AS ncf,
                       COALESCE(NULLIF(TRIM(cxp.concepto_servicio), ''), CASE WHEN rc.id IS NOT NULL THEN 'Mercancía Recepción #' || rc.id ELSE 'Obligación #' || cxp.id END) AS concepto,
                       (CURRENT_DATE - cxp.fecha_vencimiento) AS dias_vencido
                FROM compras.cuentas_por_pagar cxp
                JOIN tesoreria.monedas m ON m.id = cxp.moneda_id
                LEFT JOIN compras.recepciones_compra rc ON rc.id = cxp.recepcion_compra_id
                WHERE cxp.proveedor_id = ?
                  AND cxp.estado IN ('PENDIENTE', 'PAGADA_PARCIAL')
                ORDER BY cxp.fecha_vencimiento ASC, cxp.id ASC
            ");
            $stmt->execute([$proveedorId]);
            $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $retencionesSugeridas = [];
            try {
                $stmtRet = $pdo->prepare("
                    SELECT r.id, r.siglas, r.nombre, r.base_calculo, r.porcentaje
                    FROM identidad.proveedor_retenciones pr
                    JOIN tesoreria.retenciones r ON r.id = pr.retencion_id
                    WHERE pr.proveedor_id = ? AND r.activo = TRUE
                    ORDER BY r.id ASC
                ");
                $stmtRet->execute([$proveedorId]);
                $retencionesSugeridas = $stmtRet->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $retencionesSugeridas = [];
            }

            responderCxp(true, 'OK', [
                'facturas' => $facturas,
                'retenciones_sugeridas' => $retencionesSugeridas
            ]);
            break;


        // ====================================================================
        // FASE 3: EJECUCIÓN DEL DESEMBOLSO, SPLIT Y RETENCIONES FISCALES
        // ====================================================================
        case 'liquidar_desembolso':
            $proveedorId = trim((string) ($datos['proveedor_id'] ?? ''));
            $facturasItems = $datos['facturas'] ?? [];
            $pagosSplit = $datos['pagos'] ?? [];

            if ($proveedorId === '') {
                throw new Exception("Debe especificar el proveedor del desembolso.");
            }
            if (!is_array($facturasItems) || empty($facturasItems)) {
                throw new Exception("Debe seleccionar al menos una factura para liquidar.");
            }
            if (!is_array($pagosSplit) || empty($pagosSplit)) {
                throw new Exception("Debe especificar al menos un método de pago en el split.");
            }

            $stProvNom = $pdo->prepare("SELECT nombre_razon_social FROM identidad.personas WHERE persona_id = ? LIMIT 1");
            $stProvNom->execute([$proveedorId]);
            $proveedorNombre = (string) ($stProvNom->fetchColumn() ?: 'Suplidor');

            $pdo->beginTransaction();

            // 1. Validar y calcular totales de facturas a liquidar
            $totalMontoAplicadoFacturas = 0.00;
            $totalMontoRetencionesFacturas = 0.00;
            $facturasValidadas = [];
            $monedaIdConsolidada = null;
            $tasaCambioConsolidada = 1.0000;
            $monedaSimbolo = 'RD$';

            $stmtCxp = $pdo->prepare("
                SELECT cxp.id, cxp.proveedor_id, cxp.saldo_pendiente, cxp.estado,
                       cxp.moneda_id, cxp.tasa_cambio, m.simbolo, m.codigo_iso
                FROM compras.cuentas_por_pagar cxp
                JOIN tesoreria.monedas m ON m.id = cxp.moneda_id
                WHERE cxp.id = ?
                FOR UPDATE OF cxp
            ");

            $stmtRetCatalogo = $pdo->prepare("
                SELECT id, siglas, base_calculo, porcentaje
                FROM tesoreria.retenciones
                WHERE id = ? AND activo = TRUE
                LIMIT 1
            ");

            foreach ($facturasItems as $fItem) {
                $cxpId = (int) ($fItem['cxp_id'] ?? 0);
                $montoBrutoIn = isset($fItem['monto_bruto']) ? round((float) $fItem['monto_bruto'], 2) : null;
                $montoAplicar = round((float) ($fItem['monto_aplicado'] ?? 0), 2);
                $rets = is_array($fItem['retenciones'] ?? null) ? $fItem['retenciones'] : [];

                if (($montoBrutoIn !== null && $montoBrutoIn <= 0) || ($montoBrutoIn === null && $montoAplicar <= 0)) {
                    continue;
                }

                $stmtCxp->execute([$cxpId]);
                $cxpRow = $stmtCxp->fetch(PDO::FETCH_ASSOC);

                if (!$cxpRow) {
                    throw new Exception("La cuenta por pagar #{$cxpId} no existe.");
                }
                if ($cxpRow['proveedor_id'] !== $proveedorId) {
                    throw new Exception("La cuenta por pagar #{$cxpId} no pertenece al proveedor seleccionado.");
                }
                if (!in_array($cxpRow['estado'], ['PENDIENTE', 'PAGADA_PARCIAL'], true)) {
                    throw new Exception("La cuenta por pagar #{$cxpId} no está pendiente de pago (Estado: {$cxpRow['estado']}).");
                }

                $monedaCxp = (int) $cxpRow['moneda_id'];
                if ($monedaIdConsolidada === null) {
                    $monedaIdConsolidada = $monedaCxp;
                    $tasaCambioConsolidada = max(0.0001, (float) $cxpRow['tasa_cambio']);
                    $monedaSimbolo = (string) ($cxpRow['simbolo'] ?: 'RD$');
                } elseif ($monedaIdConsolidada !== $monedaCxp) {
                    throw new Exception("No se pueden liquidar facturas de monedas distintas en un mismo comprobante de egreso.");
                }

                $saldoAnterior = round((float) $cxpRow['saldo_pendiente'], 2);
                $totalRetFactura = 0.00;
                $retsProcesadas = [];
                $retsVistas = [];

                foreach ($rets as $r) {
                    $retId = (int) ($r['retencion_id'] ?? 0);
                    $montoRet = round((float) ($r['monto_retenido'] ?? 0), 2);
                    $montoBas = round((float) ($r['monto_base'] ?? 0), 2);

                    if ($retId <= 0 || $montoRet <= 0) {
                        continue;
                    }
                    if (in_array($retId, $retsVistas, true)) {
                        throw new Exception("No puede aplicar la misma retención fiscal dos veces sobre la factura #{$cxpId}.");
                    }
                    $retsVistas[] = $retId;

                    $stmtRetCatalogo->execute([$retId]);
                    $catRet = $stmtRetCatalogo->fetch(PDO::FETCH_ASSOC);
                    if (!$catRet) {
                        throw new Exception("La retención fiscal #{$retId} no existe o está inactiva.");
                    }

                    $baseCalculo = strtoupper(trim((string) ($catRet['base_calculo'] ?? 'SUBTOTAL')));
                    $porc = round((float) $catRet['porcentaje'], 2);
                    if ($montoBas <= 0) {
                        $montoBas = ($montoBrutoIn !== null) ? $montoBrutoIn : $saldoAnterior;
                    }

                    $totalRetFactura = round($totalRetFactura + $montoRet, 2);
                    $retsProcesadas[] = [
                        'retencion_id' => $retId,
                        'base_calculo' => $baseCalculo,
                        'monto_base' => $montoBas,
                        'porcentaje' => $porc,
                        'monto_retenido' => $montoRet
                    ];
                }

                if ($montoBrutoIn !== null) {
                    $montoBruto = $montoBrutoIn;
                    $montoAplicar = round($montoBruto - $totalRetFactura, 2);
                } else {
                    if (($montoAplicar + $totalRetFactura) > $saldoAnterior && $montoAplicar <= $saldoAnterior) {
                        $montoAplicar = round($montoAplicar - $totalRetFactura, 2);
                    }
                    $montoBruto = round($montoAplicar + $totalRetFactura, 2);
                }

                if ($montoAplicar < 0) {
                    throw new Exception("Las retenciones ({$monedaSimbolo} " . number_format($totalRetFactura, 2) . ") superan el monto a cancelar en la factura #{$cxpId}.");
                }
                if ($montoBruto > ($saldoAnterior + 0.01)) {
                    throw new Exception("El monto a amortizar ({$monedaSimbolo} " . number_format($montoBruto, 2) . ") excede el saldo pendiente ({$monedaSimbolo} " . number_format($saldoAnterior, 2) . ") en la factura #{$cxpId}.");
                }

                $saldoRestante = round(max(0.0, $saldoAnterior - ($montoAplicar + $totalRetFactura)), 2);

                $totalMontoAplicadoFacturas = round($totalMontoAplicadoFacturas + $montoAplicar, 2);
                $totalMontoRetencionesFacturas = round($totalMontoRetencionesFacturas + $totalRetFactura, 2);

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

            $monedaId = $monedaIdConsolidada ?? 1;
            $tasaCambio = $tasaCambioConsolidada;

            // 2. Validar pagos del Split y referencias bancarias previas (evita choques antes del trigger)
            $totalSplitPagado = 0.00;
            $pagosValidos = [];
            $refsEnEstePago = [];

            $stmtMet = $pdo->prepare("SELECT id, codigo, descripcion FROM ventas.metodos_pago WHERE id = ? AND activo = TRUE LIMIT 1");
            $stmtCta = $pdo->prepare("
                SELECT cb.id, cb.nombre_banco, cb.numero_cuenta, cb.moneda_id, cb.activa,
                       COALESCE(m.codigo_iso, 'DOP') AS codigo_iso
                FROM tesoreria.cuentas_bancarias cb
                LEFT JOIN tesoreria.monedas m ON m.id = cb.moneda_id
                WHERE cb.id = ?
                LIMIT 1
            ");
            $stmtChkRefPrevia = $pdo->prepare("
                SELECT id, TO_CHAR(fecha_movimiento, 'DD/MM/YYYY') AS fecha_fmt
                FROM tesoreria.movimientos_bancarios
                WHERE cuenta_bancaria_id = ?
                  AND UPPER(TRIM(referencia)) = UPPER(?)
                LIMIT 1
            ");

            foreach ($pagosSplit as $idxP => $pago) {
                $numP = $idxP + 1;
                $metodoId = (int) ($pago['metodo_pago_id'] ?? 0);
                $montoP = round((float) ($pago['monto'] ?? 0), 2);
                if ($montoP <= 0) {
                    continue;
                }

                $stmtMet->execute([$metodoId]);
                $metRow = $stmtMet->fetch(PDO::FETCH_ASSOC);
                if (!$metRow) {
                    throw new Exception("Método de pago #{$numP} no válido o inactivo.");
                }
                $codMetodo = strtoupper((string) $metRow['codigo']);
                if (!in_array($codMetodo, ['EFECTIVO', 'TRANSFERENCIA', 'CHEQUE'], true)) {
                    throw new Exception("El método de pago '{$codMetodo}' no está permitido para egresos de CxP.");
                }

                $cuentaRow = null;
                $numRef = '';
                $numCheque = '';

                if (in_array($codMetodo, ['TRANSFERENCIA', 'CHEQUE'], true)) {
                    $cuentaBanc = (int) ($pago['cuenta_bancaria_id'] ?? 0);
                    if ($cuentaBanc <= 0) {
                        throw new Exception("Pago #{$numP} ({$codMetodo}): debe seleccionar una cuenta bancaria.");
                    }
                    $stmtCta->execute([$cuentaBanc]);
                    $cuentaRow = $stmtCta->fetch(PDO::FETCH_ASSOC);
                    if (!$cuentaRow || !in_array($cuentaRow['activa'], [true, 't', 1, '1'], true)) {
                        throw new Exception("Pago #{$numP}: la cuenta bancaria seleccionada no existe o está inactiva.");
                    }
                    if ((int) $cuentaRow['moneda_id'] !== $monedaId) {
                        throw new Exception("Pago #{$numP}: la cuenta «{$cuentaRow['nombre_banco']}» está en {$cuentaRow['codigo_iso']} y no coincide con la moneda de las facturas seleccionadas.");
                    }

                    $refEvaluar = ($codMetodo === 'TRANSFERENCIA')
                        ? trim((string) ($pago['referencia'] ?? ''))
                        : trim((string) ($pago['num_cheque'] ?? $pago['referencia'] ?? ''));

                    if ($refEvaluar === '') {
                        $etiqueta = ($codMetodo === 'TRANSFERENCIA') ? 'el número de referencia bancaria' : 'el número de cheque';
                        throw new Exception("Pago #{$numP} ({$codMetodo}): debe ingresar {$etiqueta}.");
                    }

                    $claveRef = $cuentaBanc . '|' . strtoupper($refEvaluar);
                    if (in_array($claveRef, $refsEnEstePago, true)) {
                        throw new Exception("La referencia «{$refEvaluar}» está repetida en dos líneas del mismo desembolso.");
                    }
                    $refsEnEstePago[] = $claveRef;

                    // Verificar si realmente existía ANTES de esta transacción
                    $stmtChkRefPrevia->execute([$cuentaBanc, $refEvaluar]);
                    if ($dupMov = $stmtChkRefPrevia->fetch(PDO::FETCH_ASSOC)) {
                        throw new Exception("La referencia «{$refEvaluar}» ya existe en {$cuentaRow['nombre_banco']} (Movimiento #{$dupMov['id']} del {$dupMov['fecha_fmt']}).");
                    }

                    if ($codMetodo === 'TRANSFERENCIA') {
                        $numRef = $refEvaluar;
                    } else {
                        $numCheque = $refEvaluar;
                    }
                }

                $totalSplitPagado = round($totalSplitPagado + $montoP, 2);
                $pagosValidos[] = [
                    'metodo_id' => $metodoId,
                    'codigo' => $codMetodo,
                    'monto' => $montoP,
                    'cuenta' => $cuentaRow,
                    'referencia' => $numRef,
                    'num_cheque' => $numCheque,
                    'titular' => trim((string) ($pago['titular'] ?? 'House & Ice')) ?: 'House & Ice',
                ];
            }

            if ($totalMontoAplicadoFacturas > 0 && empty($pagosValidos)) {
                throw new Exception("Debe especificar al menos un método de pago mayor a cero.");
            }

            $diferencia = round(abs($totalSplitPagado - $totalMontoAplicadoFacturas), 2);
            if ($diferencia > 0.02) {
                throw new Exception(
                    "El desglose de pagos (Split: {$monedaSimbolo} " . number_format($totalSplitPagado, 2) . ") " .
                    "no coincide con el total neto a desembolsar ({$monedaSimbolo} " . number_format($totalMontoAplicadoFacturas, 2) . ")."
                );
            }

            // 3. Generar comprobante de egreso
            $seqName = $pdo->query("SELECT pg_get_serial_sequence('compras.comprobantes_egreso_cxp', 'id')")->fetchColumn()
                ?: 'compras.comprobantes_egreso_cxp_id_seq';
            $nextId = (int) $pdo->query("SELECT nextval('{$seqName}')")->fetchColumn();
            $numComprobante = 'EGR-' . date('Y') . '-' . str_pad((string) $nextId, 6, '0', STR_PAD_LEFT);

            // 4. Insertar cabecera de egreso
            $pdo->prepare("
                INSERT INTO compras.comprobantes_egreso_cxp (
                    id, numero_comprobante, proveedor_id, usuario_registro_id,
                    monto_total_pagado, fecha_pago, moneda_id, tasa_cambio
                ) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)
            ")->execute([
                        $nextId,
                        $numComprobante,
                        $proveedorId,
                        $usuarioActualId,
                        $totalMontoAplicadoFacturas,
                        $monedaId,
                        $tasaCambio
                    ]);

            // 5. Insertar detalles de facturas y retenciones (trigger-safe)
            $colsRet = obtenerColumnasTabla($pdo, 'compras', 'comprobante_egreso_retenciones');
            $tieneEstadoRet = isset($colsRet['estado']);

            $stmtInsDet = $pdo->prepare("
                INSERT INTO compras.comprobante_egreso_detalles (
                    comprobante_egreso_id, cuenta_por_pagar_id, monto_aplicado,
                    saldo_anterior, saldo_restante, monto_retenciones
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmtInsRetDet = $tieneEstadoRet
                ? $pdo->prepare("
                    INSERT INTO compras.comprobante_egreso_retenciones (
                        comprobante_egreso_id, cuenta_por_pagar_id, retencion_id,
                        base_calculo_aplicada, monto_base, porcentaje_aplicado, monto_retenido, estado
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDIENTE')
                ")
                : $pdo->prepare("
                    INSERT INTO compras.comprobante_egreso_retenciones (
                        comprobante_egreso_id, cuenta_por_pagar_id, retencion_id,
                        base_calculo_aplicada, monto_base, porcentaje_aplicado, monto_retenido
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

            $stmtVerifSaldo = $pdo->prepare("SELECT saldo_pendiente FROM compras.cuentas_por_pagar WHERE id = ?");
            $stmtUpdCxp = $pdo->prepare("UPDATE compras.cuentas_por_pagar SET saldo_pendiente = ?, estado = ? WHERE id = ?");

            foreach ($facturasValidadas as $fv) {
                $stmtInsDet->execute([
                    $nextId,
                    $fv['cxp_id'],
                    $fv['monto_aplicado'],
                    $fv['saldo_anterior'],
                    $fv['saldo_restante'],
                    $fv['monto_retenciones']
                ]);

                $stmtVerifSaldo->execute([$fv['cxp_id']]);
                $saldoActualBd = round((float) $stmtVerifSaldo->fetchColumn(), 2);
                if (abs($saldoActualBd - $fv['saldo_anterior']) < 0.005 && ($fv['monto_aplicado'] + $fv['monto_retenciones']) > 0) {
                    $nuevoEstadoCxp = ($fv['saldo_restante'] <= 0.00) ? 'PAGADA' : 'PAGADA_PARCIAL';
                    $stmtUpdCxp->execute([$fv['saldo_restante'], $nuevoEstadoCxp, $fv['cxp_id']]);
                }

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

            // 6. Procesar Split de Pagos (sesion_caja_id = NULL) — TRIGGER-SAFE para movimientos_bancarios
            $stmtInsDetPago = $pdo->prepare("
                INSERT INTO tesoreria.detalles_pago (
                    metodo_pago_id, moneda_id, tasa_cambio, monto_pagado, monto_devuelta, sesion_caja_id
                ) VALUES (?, ?, ?, ?, 0.00, NULL)
                RETURNING id
            ");

            $stmtInsEgresoPago = $pdo->prepare("
                INSERT INTO compras.egreso_cxp_pagos (comprobante_egreso_id, detalle_pago_id)
                VALUES (?, ?)
            ");

            $stmtInsTransf = $pdo->prepare("
                INSERT INTO tesoreria.pago_transferencia (
                    detalle_pago_id, banco_origen, cuenta_bancaria_id, numero_referencia, fecha_transferencia, titular_origen
                ) VALUES (?, ?, ?, ?, CURRENT_DATE, ?)
            ");

            $colsCheque = obtenerColumnasTabla($pdo, 'tesoreria', 'pago_cheque');

            // Consultas trigger-safe: detectar si un trigger sobre pago_transferencia/pago_cheque ya creó el movimiento bancario
            $stmtBuscarMovTrg = $pdo->prepare("
                SELECT id
                FROM tesoreria.movimientos_bancarios
                WHERE detalle_pago_id = ?
                   OR (cuenta_bancaria_id = ? AND UPPER(TRIM(referencia)) = UPPER(?))
                ORDER BY id DESC
                LIMIT 1
            ");

            $stmtUpdMovTrg = $pdo->prepare("
                UPDATE tesoreria.movimientos_bancarios
                SET tipo_movimiento = ?,
                    monto           = ?,
                    concepto        = ?,
                    fecha_movimiento = CURRENT_DATE,
                    usuario_id      = ?,
                    detalle_pago_id = ?
                WHERE id = ?
            ");

            $stmtInsMovBanc = $pdo->prepare("
                INSERT INTO tesoreria.movimientos_bancarios (
                    cuenta_bancaria_id, tipo_movimiento, monto, referencia,
                    concepto, fecha_movimiento, usuario_id, detalle_pago_id
                ) VALUES (?, ?, ?, ?, ?, CURRENT_DATE, ?, ?)
            ");

            foreach ($pagosValidos as $pg) {
                $stmtInsDetPago->execute([
                    $pg['metodo_id'],
                    $monedaId,
                    $tasaCambio,
                    $pg['monto']
                ]);
                $detallePagoId = (int) $stmtInsDetPago->fetchColumn();
                $stmtInsEgresoPago->execute([$nextId, $detallePagoId]);

                if ($pg['codigo'] === 'TRANSFERENCIA') {
                    $cuentaId = (int) $pg['cuenta']['id'];
                    $bancoNom = (string) $pg['cuenta']['nombre_banco'];
                    $numRef = $pg['referencia'];

                    $stmtInsTransf->execute([
                        $detallePagoId,
                        $bancoNom,
                        $cuentaId,
                        $numRef,
                        $pg['titular']
                    ]);

                    $conceptoMov = mb_substr("Desembolso CxP ({$proveedorNombre}) • {$numComprobante} • Ref: {$numRef}", 0, 255, 'UTF-8');

                    $stmtBuscarMovTrg->execute([$detallePagoId, $cuentaId, $numRef]);
                    $movExistId = (int) ($stmtBuscarMovTrg->fetchColumn() ?: 0);

                    if ($movExistId > 0) {
                        // El trigger ya insertó el registro en movimientos_bancarios: asegurar tipo SALIDA y concepto CxP
                        $stmtUpdMovTrg->execute([
                            'TRANSFERENCIA_SALIDA',
                            $pg['monto'],
                            $conceptoMov,
                            $usuarioActualId,
                            $detallePagoId,
                            $movExistId
                        ]);
                    } else {
                        $stmtInsMovBanc->execute([
                            $cuentaId,
                            'TRANSFERENCIA_SALIDA',
                            $pg['monto'],
                            $numRef,
                            $conceptoMov,
                            $usuarioActualId,
                            $detallePagoId
                        ]);
                    }
                } elseif ($pg['codigo'] === 'CHEQUE') {
                    $cuentaId = (int) $pg['cuenta']['id'];
                    $bancoNom = (string) $pg['cuenta']['nombre_banco'];
                    $numCheque = $pg['num_cheque'];

                    $chCols = ['detalle_pago_id', 'numero_cheque', 'banco_emisor', 'cuenta_bancaria_id', 'estado_cheque'];
                    $chVals = [$detallePagoId, $numCheque, $bancoNom, $cuentaId, 'RECIBIDO'];
                    if (isset($colsCheque['titular_cheque'])) {
                        $chCols[] = 'titular_cheque';
                        $chVals[] = $proveedorNombre;
                    }
                    if (isset($colsCheque['fecha_emision'])) {
                        $chCols[] = 'fecha_emision';
                        $chVals[] = date('Y-m-d');
                    }
                    $phCh = implode(', ', array_fill(0, count($chCols), '?'));
                    $pdo->prepare("INSERT INTO tesoreria.pago_cheque (" . implode(', ', $chCols) . ") VALUES ({$phCh})")
                        ->execute($chVals);

                    $conceptoMov = mb_substr("Cheque CxP ({$proveedorNombre}) • {$numComprobante} • Cheque #{$numCheque}", 0, 255, 'UTF-8');

                    $stmtBuscarMovTrg->execute([$detallePagoId, $cuentaId, $numCheque]);
                    $movExistId = (int) ($stmtBuscarMovTrg->fetchColumn() ?: 0);

                    if ($movExistId > 0) {
                        $stmtUpdMovTrg->execute([
                            'CHEQUE_SALIDA',
                            $pg['monto'],
                            $conceptoMov,
                            $usuarioActualId,
                            $detallePagoId,
                            $movExistId
                        ]);
                    } else {
                        $stmtInsMovBanc->execute([
                            $cuentaId,
                            'CHEQUE_SALIDA',
                            $pg['monto'],
                            $numCheque,
                            $conceptoMov,
                            $usuarioActualId,
                            $detallePagoId
                        ]);
                    }
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
                    'proveedor' => $proveedorNombre,
                    'total_pagado' => $totalMontoAplicadoFacturas,
                    'total_retenciones' => $totalMontoRetencionesFacturas,
                    'facturas_afectadas' => count($facturasValidadas)
                ]
            );

            $pdo->commit();

            responderCxp(
                true,
                "Comprobante de Egreso #{$numComprobante} generado exitosamente por {$monedaSimbolo} " . number_format($totalMontoAplicadoFacturas, 2) .
                ($totalMontoRetencionesFacturas > 0 ? " (Retenciones DGII: {$monedaSimbolo} " . number_format($totalMontoRetencionesFacturas, 2) . ")" : '') . ".",
                [
                    'comprobante_id' => $nextId,
                    'numero_comprobante' => $numComprobante,
                    'total_desembolsado' => $totalMontoAplicadoFacturas,
                    'total_retenciones' => $totalMontoRetencionesFacturas
                ]
            );
            break;


        // ====================================================================
        // FASE 4: AJUSTES, DESCUENTOS Y ANULACIONES CON PIN DE SUPERVISOR
        // ====================================================================
        case 'registrar_ajuste':
            $cxpId = (int) ($datos['cxp_id'] ?? 0);
            $tipoAjuste = strtoupper(trim((string) ($datos['tipo_ajuste'] ?? '')));
            $montoAjuste = round((float) ($datos['monto_ajuste'] ?? 0), 2);
            $ncfNotaCredito = strtoupper(trim((string) ($datos['ncf_nota_credito_proveedor'] ?? '')));
            $justificacion = trim((string) ($datos['justificacion'] ?? ''));
            $supervisorId = trim((string) ($datos['supervisor_id'] ?? ''));
            $pinSupervisor = trim((string) ($datos['pin_supervisor'] ?? ''));

            if ($cxpId <= 0) {
                throw new Exception("Debe especificar la cuenta por pagar a ajustar.");
            }
            if (!in_array($tipoAjuste, ['DESCUENTO_PRONTO_PAGO', 'EXONERACION_PROVEEDOR', 'NOTA_CREDITO_PROVEEDOR', 'ANULACION'], true)) {
                throw new Exception("Tipo de ajuste no válido: {$tipoAjuste}.");
            }
            if (mb_strlen($justificacion, 'UTF-8') < 4) {
                throw new Exception("Debe ingresar una justificación documentada del ajuste (mínimo 4 caracteres).");
            }
            if ($tipoAjuste === 'NOTA_CREDITO_PROVEEDOR' && $ncfNotaCredito === '') {
                throw new Exception("Debe especificar el NCF de la Nota de Crédito del proveedor (B04) para el Formato 606.");
            }

            $supervisorInfo = validarPinSupervisor($pdo, $supervisorId, $pinSupervisor);

            $pdo->beginTransaction();

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

            $saldoAnterior = round((float) $cxp['saldo_pendiente'], 2);

            if ($tipoAjuste === 'ANULACION') {
                $montoAjuste = $saldoAnterior;
                $saldoRestante = 0.00;
            } else {
                if ($montoAjuste <= 0) {
                    throw new Exception("El monto del ajuste debe ser mayor a cero.");
                }
                if ($montoAjuste > $saldoAnterior) {
                    throw new Exception("El monto del ajuste (RD$ " . number_format($montoAjuste, 2) . ") no puede superar el saldo pendiente (RD$ " . number_format($saldoAnterior, 2) . ").");
                }
                $saldoRestante = round($saldoAnterior - $montoAjuste, 2);
            }

            $stmtInsAj = $pdo->prepare("
                INSERT INTO compras.ajustes_cxp (
                    cuenta_por_pagar_id, tipo_ajuste, ncf_nota_credito_proveedor,
                    moneda_id, tasa_cambio, monto_ajuste, saldo_anterior, saldo_restante,
                    justificacion, supervisor_id, fecha_ajuste
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

            $ajusteId = (int) $stmtInsAj->fetchColumn();

            $stChk = $pdo->prepare("SELECT saldo_pendiente, estado FROM compras.cuentas_por_pagar WHERE id = ?");
            $stChk->execute([$cxpId]);
            $postCxp = $stChk->fetch(PDO::FETCH_ASSOC);
            if ($postCxp && abs((float) $postCxp['saldo_pendiente'] - $saldoAnterior) < 0.005) {
                $nuevoEst = ($tipoAjuste === 'ANULACION')
                    ? 'ANULADA'
                    : (($saldoRestante <= 0.00) ? 'PAGADA' : 'PAGADA_PARCIAL');
                $pdo->prepare("UPDATE compras.cuentas_por_pagar SET saldo_pendiente = ?, estado = ? WHERE id = ?")
                    ->execute([$saldoRestante, $nuevoEst, $cxpId]);
            }

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

            responderCxp(
                true,
                "Ajuste #{$ajusteId} ({$tipoAjuste}) procesado exitosamente por RD$ " . number_format($montoAjuste, 2) . ". Autorizado por: {$supervisorInfo['nombre_completo']}.",
                [
                    'ajuste_id' => $ajusteId,
                    'saldo_restante' => $saldoRestante
                ]
            );
            break;


        // ====================================================================
        // CONSULTAR DETALLES DE UN COMPROBANTE DE EGRESO
        // ====================================================================
        case 'ver_detalle_egreso':
            $egresoId = (int) ($datos['egreso_id'] ?? 0);
            if ($egresoId <= 0) {
                throw new Exception("ID de comprobante no especificado.");
            }

            $stmtCab = $pdo->prepare("
                SELECT ce.*, p.nombre_razon_social AS proveedor_nombre,
                       p.documento_identidad AS proveedor_rnc,
                       u.username AS usuario_registro_nombre,
                       m.nombre AS moneda_nombre, m.simbolo AS moneda_simbolo
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

            $stmtDet = $pdo->prepare("
                SELECT ced.*, cxp.fecha_emision, cxp.fecha_vencimiento,
                       COALESCE(rc.numero_factura_proveedor, cxp.numero_factura_servicio, 'S/F') AS numero_factura,
                       COALESCE(rc.ncf_proveedor, cxp.ncf_servicio, 'N/A') AS ncf,
                       COALESCE(NULLIF(TRIM(cxp.concepto_servicio), ''), 'Mercancía Recepción #' || rc.id) AS concepto
                FROM compras.comprobante_egreso_detalles ced
                JOIN compras.cuentas_por_pagar cxp ON cxp.id = ced.cuenta_por_pagar_id
                LEFT JOIN compras.recepciones_compra rc ON rc.id = cxp.recepcion_compra_id
                WHERE ced.comprobante_egreso_id = ?
            ");
            $stmtDet->execute([$egresoId]);
            $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmtRet = $pdo->prepare("
                SELECT cer.*, r.siglas, r.nombre AS retencion_nombre
                FROM compras.comprobante_egreso_retenciones cer
                JOIN tesoreria.retenciones r ON r.id = cer.retencion_id
                WHERE cer.comprobante_egreso_id = ?
                ORDER BY cer.cuenta_por_pagar_id ASC, r.id ASC
            ");
            $stmtRet->execute([$egresoId]);
            $retenciones = $stmtRet->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
            $pagos = $stmtPag->fetchAll(PDO::FETCH_ASSOC) ?: [];

            responderCxp(true, 'OK', [
                'cabecera' => $cabecera,
                'detalles' => $detalles,
                'retenciones' => $retenciones,
                'pagos' => $pagos
            ]);
            break;


        // ====================================================================
        // FASE 5A: RESUMEN EN VIVO DE RETENCIONES PENDIENTES POR PERÍODO (IT-1 / IR-17)
        // ====================================================================
        case 'resumen_retenciones_dgii':
            $periodo = trim((string) ($datos['periodo_fiscal'] ?? $datos['periodo'] ?? date('Y-m')));
            if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) {
                $periodo = date('Y-m');
            }

            $stRes = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN UPPER(r.siglas) LIKE '%ITBIS%' THEN cer.monto_retenido * COALESCE(ce.tasa_cambio, 1.0) ELSE 0 END), 0) AS itbis_monto,
                    COUNT(CASE WHEN UPPER(r.siglas) LIKE '%ITBIS%' THEN 1 END) AS itbis_count,
                    COALESCE(SUM(CASE WHEN NOT (UPPER(r.siglas) LIKE '%ITBIS%') THEN cer.monto_retenido * COALESCE(ce.tasa_cambio, 1.0) ELSE 0 END), 0) AS isr_monto,
                    COUNT(CASE WHEN NOT (UPPER(r.siglas) LIKE '%ITBIS%') THEN 1 END) AS isr_count,
                    COALESCE(SUM(cer.monto_retenido * COALESCE(ce.tasa_cambio, 1.0)), 0) AS total_monto,
                    COUNT(*) AS total_count
                FROM compras.comprobante_egreso_retenciones cer
                JOIN compras.comprobantes_egreso_cxp ce ON ce.id = cer.comprobante_egreso_id
                JOIN tesoreria.retenciones r ON r.id = cer.retencion_id
                WHERE cer.estado = 'PENDIENTE'
                  AND TO_CHAR(ce.fecha_pago, 'YYYY-MM') = ?
            ");
            $stRes->execute([$periodo]);
            $row = $stRes->fetch(PDO::FETCH_ASSOC) ?: [];

            responderCxp(true, 'OK', [
                'periodo' => $periodo,
                'itbis_monto' => round((float) ($row['itbis_monto'] ?? 0), 2),
                'itbis_count' => (int) ($row['itbis_count'] ?? 0),
                'isr_monto' => round((float) ($row['isr_monto'] ?? 0), 2),
                'isr_count' => (int) ($row['isr_count'] ?? 0),
                'total_monto' => round((float) ($row['total_monto'] ?? 0), 2),
                'total_count' => (int) ($row['total_count'] ?? 0),
            ]);
            break;


        // ====================================================================
        // FASE 5B: LIQUIDACIÓN DE RETENCIONES FISCALES DGII (POR PERÍODO Y FORMULARIO)
        // ====================================================================
        case 'liquidar_retenciones_dgii':
            if (!$puedeLiquidarDgii) {
                responderCxp(
                    false,
                    'Acceso restringido: solo el Administrador o Tesorería (REGISTRAR_MOVIMIENTOS_BANCARIOS / EJECUTAR_CIERRES_FISCALES_PERIODO) puede liquidar retenciones a la DGII.',
                    [],
                    403
                );
            }

            $cuentaBancariaId = (int) ($datos['cuenta_bancaria_id'] ?? 0);
            $metodoPago = strtoupper(trim((string) ($datos['metodo_pago'] ?? 'TRANSFERENCIA')));
            $tipoImpuesto = strtoupper(trim((string) ($datos['tipo_impuesto'] ?? 'AMBOS')));
            $numAutorizacion = strtoupper(trim((string) ($datos['numero_autorizacion'] ?? $datos['referencia_autorizacion'] ?? '')));
            $fechaPago = trim((string) ($datos['fecha_pago'] ?? date('Y-m-d')));
            $periodoFiscal = trim((string) ($datos['periodo_fiscal'] ?? date('Y-m')));

            if (!in_array($tipoImpuesto, ['ITBIS', 'ISR', 'AMBOS'], true)) {
                throw new Exception("Tipo de declaración DGII no válido (debe ser ITBIS, ISR o AMBOS).");
            }
            if (!preg_match('/^\d{4}-\d{2}$/', $periodoFiscal)) {
                throw new Exception("El período fiscal debe tener el formato AAAA-MM.");
            }
            if ($cuentaBancariaId <= 0) {
                throw new Exception("Debe seleccionar una cuenta bancaria institucional activa en DOP.");
            }
            if ($numAutorizacion === '' || mb_strlen($numAutorizacion, 'UTF-8') < 3) {
                throw new Exception("Debe ingresar el Número de Autorización / Comprobante emitido por la DGII.");
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaPago)) {
                throw new Exception("La fecha de liquidación debe tener el formato AAAA-MM-DD.");
            }

            $stmtCta = $pdo->prepare("
                SELECT cb.id, cb.nombre_banco, cb.numero_cuenta, cb.activa,
                       COALESCE(m.codigo_iso, 'DOP') AS codigo_iso,
                       COALESCE(m.es_moneda_base, TRUE) AS es_moneda_base
                FROM tesoreria.cuentas_bancarias cb
                LEFT JOIN tesoreria.monedas m ON m.id = cb.moneda_id
                WHERE cb.id = ?
                LIMIT 1
            ");
            $stmtCta->execute([$cuentaBancariaId]);
            $cta = $stmtCta->fetch(PDO::FETCH_ASSOC);

            if (!$cta || !in_array($cta['activa'], [true, 't', 1, '1'], true)) {
                throw new Exception("La cuenta bancaria seleccionada no existe o está inactiva.");
            }
            $esDop = in_array($cta['es_moneda_base'], [true, 't', 1, '1'], true) || strtoupper((string) $cta['codigo_iso']) === 'DOP';
            if (!$esDop) {
                throw new Exception("Las retenciones a la DGII se liquidan en Pesos Dominicanos (DOP). Seleccione una cuenta en DOP.");
            }

            // Verificar que la referencia DGII no esté repetida en esa cuenta
            $stDupDgii = $pdo->prepare("
                SELECT id, TO_CHAR(fecha_movimiento, 'DD/MM/YYYY') AS fecha_fmt
                FROM tesoreria.movimientos_bancarios
                WHERE cuenta_bancaria_id = ?
                  AND UPPER(TRIM(referencia)) = UPPER(?)
                LIMIT 1
            ");
            $stDupDgii->execute([$cuentaBancariaId, $numAutorizacion]);
            if ($dupD = $stDupDgii->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception("El número de autorización «{$numAutorizacion}» ya fue registrado en {$cta['nombre_banco']} (Mov. #{$dupD['id']} del {$dupD['fecha_fmt']}).");
            }

            $filtroImpSql = '';
            $etiquetaForm = 'IT-1 / IR-17';
            if ($tipoImpuesto === 'ITBIS') {
                $filtroImpSql = " AND UPPER(r.siglas) LIKE '%ITBIS%' ";
                $etiquetaForm = 'ITBIS (IT-1)';
            } elseif ($tipoImpuesto === 'ISR') {
                $filtroImpSql = " AND NOT (UPPER(r.siglas) LIKE '%ITBIS%') ";
                $etiquetaForm = 'ISR (IR-17)';
            }

            $pdo->beginTransaction();

            // Bloquear exclusivamente las retenciones PENDIENTES del período y formulario elegidos
            $stmtRetPend = $pdo->prepare("
                SELECT cer.comprobante_egreso_id, cer.cuenta_por_pagar_id, cer.retencion_id,
                       cer.monto_retenido, COALESCE(ce.tasa_cambio, 1.0000) AS tasa_cambio,
                       r.siglas
                FROM compras.comprobante_egreso_retenciones cer
                JOIN compras.comprobantes_egreso_cxp ce ON ce.id = cer.comprobante_egreso_id
                JOIN tesoreria.retenciones r ON r.id = cer.retencion_id
                WHERE cer.estado = 'PENDIENTE'
                  AND TO_CHAR(ce.fecha_pago, 'YYYY-MM') = ?
                  {$filtroImpSql}
                FOR UPDATE OF cer
            ");
            $stmtRetPend->execute([$periodoFiscal]);
            $retencionesPendientes = $stmtRetPend->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($retencionesPendientes)) {
                throw new Exception("No existen retenciones pendientes de {$etiquetaForm} para el período {$periodoFiscal}.");
            }

            $totalRetenidoDop = 0.00;
            foreach ($retencionesPendientes as $rp) {
                $totalRetenidoDop = round($totalRetenidoDop + ((float) $rp['monto_retenido'] * (float) $rp['tasa_cambio']), 2);
            }

            if ($totalRetenidoDop <= 0) {
                throw new Exception("El monto total acumulado de retenciones debe ser mayor a cero.");
            }

            $tipoMov = ($metodoPago === 'CHEQUE') ? 'CHEQUE_SALIDA' : 'TRANSFERENCIA_SALIDA';
            $conceptoMov = mb_substr("Liquidación Retenciones DGII {$etiquetaForm} • Período {$periodoFiscal} • Aut: {$numAutorizacion}", 0, 255, 'UTF-8');

            // 1. Insertar Egreso en tesoreria.movimientos_bancarios (sin alterar balance_actual ni caja POS)
            $stmtInsMov = $pdo->prepare("
                INSERT INTO tesoreria.movimientos_bancarios (
                    cuenta_bancaria_id, tipo_movimiento, monto, referencia,
                    concepto, fecha_movimiento, usuario_id, detalle_pago_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)
                RETURNING id
            ");
            $stmtInsMov->execute([
                $cuentaBancariaId,
                $tipoMov,
                $totalRetenidoDop,
                $numAutorizacion,
                $conceptoMov,
                $fechaPago,
                $usuarioActualId
            ]);
            $movimientoId = (int) $stmtInsMov->fetchColumn();

            // 2. Marcar como LIQUIDADA únicamente las filas bloqueadas del período y formulario seleccionados
            $stmtUpdRet = $pdo->prepare("
                UPDATE compras.comprobante_egreso_retenciones
                SET estado                 = 'LIQUIDADA',
                    fecha_liquidacion      = ?,
                    movimiento_bancario_id = ?
                WHERE comprobante_egreso_id = ?
                  AND cuenta_por_pagar_id   = ?
                  AND retencion_id          = ?
                  AND estado = 'PENDIENTE'
            ");
            $filasLiquidadas = 0;
            foreach ($retencionesPendientes as $rp) {
                $stmtUpdRet->execute([
                    $fechaPago,
                    $movimientoId,
                    (int) $rp['comprobante_egreso_id'],
                    (int) $rp['cuenta_por_pagar_id'],
                    (int) $rp['retencion_id']
                ]);
                $filasLiquidadas += $stmtUpdRet->rowCount();
            }

            // 3. Auditoría Forense JSONB
            registrarAuditoria(
                $pdo,
                $usuarioActualId,
                null,
                'LIQUIDACION_RETENCIONES_DGII',
                'compras.comprobante_egreso_retenciones',
                [
                    'estado' => 'PENDIENTE',
                    'periodo_fiscal' => $periodoFiscal,
                    'tipo_impuesto' => $tipoImpuesto,
                    'cantidad_filas' => $filasLiquidadas
                ],
                [
                    'movimiento_bancario_id' => $movimientoId,
                    'cuenta_bancaria_id' => $cuentaBancariaId,
                    'banco' => $cta['nombre_banco'],
                    'numero_cuenta' => $cta['numero_cuenta'],
                    'tipo_movimiento' => $tipoMov,
                    'tipo_impuesto' => $tipoImpuesto,
                    'monto_total_liquidado' => $totalRetenidoDop,
                    'referencia_dgii' => $numAutorizacion,
                    'periodo_fiscal' => $periodoFiscal,
                    'fecha_pago' => $fechaPago,
                    'cantidad_retenciones' => $filasLiquidadas
                ]
            );

            $pdo->commit();

            responderCxp(
                true,
                "Liquidación DGII {$etiquetaForm} ({$periodoFiscal}) procesada por RD$ " . number_format($totalRetenidoDop, 2) . " ({$filasLiquidadas} retención(es) • Mov. Bancario #{$movimientoId} en {$cta['nombre_banco']}).",
                [
                    'movimiento_id' => $movimientoId,
                    'monto_liquidado' => $totalRetenidoDop,
                    'filas_liquidadas' => $filasLiquidadas
                ]
            );
            break;

        default:
            throw new Exception("Acción no reconocida: " . htmlspecialchars($accion));
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msgDb = $e->getMessage();
    $msg = 'Error de base de datos al procesar la operación de Cuentas por Pagar.';

    if ($e->getCode() === '23505') {
        if (stripos($msgDb, 'uk_movbanco_ref') !== false) {
            $msg = 'Ese número de referencia bancaria o cheque ya fue registrado en la cuenta seleccionada. Por favor verifique la referencia.';
        } elseif (stripos($msgDb, 'comprobante_egreso_retenciones') !== false) {
            $msg = 'No se puede aplicar la misma retención fiscal dos veces sobre la misma factura.';
        } else {
            $msg = 'Ya existe un registro duplicado para esta operación. Verifique el NCF, número de factura o referencia bancaria.';
        }
    } elseif ($e->getCode() === '23514') {
        $msg = 'Los montos o parámetros enviados no cumplen con las reglas de validación contable de la base de datos.';
    }

    responderCxp(false, $msg, ['detalle_tecnico' => $msgDb], 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderCxp(false, $e->getMessage(), [], 400);
}