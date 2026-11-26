<?php
/**
 * ============================================================================
 * Backend Controlador: Proceso 7 - Cuentas por Cobrar (CxC)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/CuentasPorCobrar/procesar_cxc.php
 * ============================================================================
 */

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/conexion.php';
if (file_exists(__DIR__ . '/../../config/funciones.php')) {
    require_once __DIR__ . '/../../config/funciones.php';
}
require_once __DIR__ . '/../../config/auditoria_helper.php';

function responderCxc(bool $ok, string $mensaje, array $extra = [], int $httpCode = 200): void
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
    responderCxc(false, 'Sesión expirada. Debe iniciar sesión nuevamente.', [], 401);
}

$esAdmin = function_exists('usuarioEsAdministrador') && usuarioEsAdministrador();
$puedeAcceder = $esAdmin || (function_exists('usuarioPuedeAccederCxc') && usuarioPuedeAccederCxc());
$puedeNotaDeb = $puedeAcceder || (function_exists('usuarioTienePermiso') && usuarioTienePermiso('EMITIR_NOTA_DEBITO'));

if (!$puedeAcceder && !$puedeNotaDeb) {
    responderCxc(false, 'No cuenta con permisos autorizados para operar Cuentas por Cobrar.', [], 403);
}

$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : null;
$datos = is_array($jsonBody) ? array_merge($_GET, $jsonBody) : array_merge($_GET, $_POST);

$accion = trim((string) ($datos['accion'] ?? $_POST['accion'] ?? $_GET['accion'] ?? ''));
$usuarioId = $_SESSION['usuario']['usuario_id'];
$personaId = $_SESSION['usuario']['persona_id'] ?? null;

/**
 * Resuelve automáticamente si una columna FK apunta a seguridad.usuarios o identidad.personas.
 */
function resolverFkUsuarioCxc(PDO $pdo, string $schema, string $tabla, string $columna, string $usuarioId, ?string $personaId): string
{
    static $cache = [];
    $key = "{$schema}.{$tabla}.{$columna}";
    if (!isset($cache[$key])) {
        $st = $pdo->prepare("
            SELECT n2.nspname || '.' || c2.relname AS tabla_destino
            FROM pg_constraint con
            JOIN pg_class c1 ON c1.oid = con.conrelid
            JOIN pg_namespace n1 ON n1.oid = c1.relnamespace
            JOIN pg_attribute a1 ON a1.attrelid = c1.oid AND a1.attnum = ANY(con.conkey)
            JOIN pg_class c2 ON c2.oid = con.confrelid
            JOIN pg_namespace n2 ON n2.oid = c2.relnamespace
            WHERE con.contype = 'f'
              AND n1.nspname = ?
              AND c1.relname = ?
              AND a1.attname = ?
            LIMIT 1
        ");
        $st->execute([$schema, $tabla, $columna]);
        $cache[$key] = (string) ($st->fetchColumn() ?: 'seguridad.usuarios');
    }

    if ($cache[$key] === 'identidad.personas' && !empty($personaId)) {
        return $personaId;
    }
    return $usuarioId;
}

/**
 * Calcula el saldo a favor real disponible de recibos CxC anteriores del cliente (en una moneda).
 */
function obtenerSaldoFavorRecibosCliente(PDO $pdo, string $clienteId, int $monedaId, bool $forUpdate = false): array
{
    $lock = $forUpdate ? 'FOR UPDATE' : '';
    $stGen = $pdo->prepare("
        SELECT id, saldo_a_favor_generado
        FROM ventas.recibos_ingreso_cxc
        WHERE cliente_id = ?
          AND moneda_id = ?
          AND COALESCE(saldo_a_favor_generado, 0) > 0
        ORDER BY id ASC
        {$lock}
    ");
    $stGen->execute([$clienteId, $monedaId]);
    $recibos = $stGen->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totalGenerado = 0.00;
    $primerReciboId = null;
    foreach ($recibos as $r) {
        $totalGenerado = round($totalGenerado + (float) $r['saldo_a_favor_generado'], 2);
        if ($primerReciboId === null) {
            $primerReciboId = (int) $r['id'];
        }
    }

    $stUsado = $pdo->prepare("
        SELECT COALESCE(SUM(dp.monto_pagado), 0)
        FROM tesoreria.pago_saldo_favor psf
        JOIN tesoreria.detalles_pago dp ON dp.id = psf.detalle_pago_id
        WHERE psf.cliente_id = ?
          AND psf.origen_saldo = 'RECIBO_CXC_ANTERIOR'
          AND dp.moneda_id = ?
    ");
    $stUsado->execute([$clienteId, $monedaId]);
    $totalUsado = round((float) $stUsado->fetchColumn(), 2);

    return [
        'disponible' => round(max(0.0, $totalGenerado - $totalUsado), 2),
        'generado' => $totalGenerado,
        'usado' => $totalUsado,
        'recibo_origen_id' => $primerReciboId,
    ];
}

/**
 * Busca y valida una Nota de Crédito B04 vigente (plazo 15 días) para usarla como medio de pago en CxC.
 */
function buscarCreditoB04Cxc(PDO $pdo, string $codigo, int $monedaId, bool $forUpdate = false): array
{
    $colsDev = obtenerColumnasTabla($pdo, 'ventas', 'devoluciones');
    if (!isset($colsDev['saldo_disponible']) || !isset($colsDev['estado_saldo'])) {
        throw new Exception('La tabla ventas.devoluciones no posee columnas de saldo a favor (saldo_disponible / estado_saldo).');
    }

    $cod = strtoupper(trim($codigo));
    if ($cod === '') {
        throw new Exception('Ingresa o escanea el código de crédito o NCF B04.');
    }

    $lock = $forUpdate ? 'FOR UPDATE OF d' : '';
    $st = $pdo->prepare("
        SELECT d.id, d.factura_id, d.ncf_nota_credito, d.codigo_credito,
               d.monto_total, d.saldo_disponible, d.fecha_vencimiento_saldo,
               d.estado_saldo, d.metodo_reembolso, d.moneda_id,
               f.cliente_id
        FROM ventas.devoluciones d
        JOIN ventas.facturas f ON f.id = d.factura_id
        WHERE UPPER(TRIM(COALESCE(d.codigo_credito, ''))) = ?
           OR UPPER(TRIM(COALESCE(d.ncf_nota_credito, ''))) = ?
        LIMIT 1
        {$lock}
    ");
    $st->execute([$cod, $cod]);
    $dev = $st->fetch(PDO::FETCH_ASSOC);

    if (!$dev) {
        throw new Exception("No se encontró ninguna Nota de Crédito B04 con el código «{$cod}».");
    }
    if (strtoupper((string) $dev['metodo_reembolso']) !== 'SALDO_FAVOR') {
        throw new Exception("La Nota de Crédito «{$cod}» fue reembolsada por {$dev['metodo_reembolso']} y no posee saldo canjeable.");
    }
    if (!in_array(strtoupper((string) $dev['estado_saldo']), ['DISPONIBLE', 'USADO_PARCIAL'], true)) {
        throw new Exception("La Nota de Crédito «{$cod}» no está disponible (estado: {$dev['estado_saldo']}).");
    }
    if (empty($dev['fecha_vencimiento_saldo']) || $dev['fecha_vencimiento_saldo'] < date('Y-m-d')) {
        throw new Exception("La Nota de Crédito «{$cod}» venció el {$dev['fecha_vencimiento_saldo']}.");
    }
    if ((float) $dev['saldo_disponible'] <= 0) {
        throw new Exception("La Nota de Crédito «{$cod}» ya agotó el 100% de su saldo.");
    }
    if ($monedaId > 0 && (int) $dev['moneda_id'] !== $monedaId) {
        throw new Exception("La moneda de la Nota de Crédito «{$cod}» no coincide con la moneda de las deudas seleccionadas.");
    }

    return $dev;
}

try {
    if (!$pdo) {
        throw new Exception('No hay conexión activa con PostgreSQL.');
    }

    switch ($accion) {

        // ====================================================================
        // 1. OBTENER DEUDAS, SALDO A FAVOR Y FACTURAS DEL CLIENTE
        // ====================================================================
        case 'obtener_deudas_cliente':
            $clienteId = trim((string) ($datos['cliente_id'] ?? ''));
            $monedaId = max(1, (int) ($datos['moneda_id'] ?? 1));
            if ($clienteId === '') {
                throw new Exception('ID de cliente requerido.');
            }

            $stmtCxc = $pdo->prepare("
                SELECT cxc.id, cxc.monto_original, cxc.saldo_pendiente, cxc.estado,
                       cxc.fecha_emision, cxc.fecha_vencimiento, cxc.factura_id, cxc.nota_debito_id,
                       cxc.moneda_id, cxc.tasa_cambio, m.simbolo AS moneda_simbolo,
                       COALESCE(f.numero_factura, 'ND-' || nd.ncf_nota_debito, 'CXC-' || cxc.id) AS numero_documento,
                       COALESCE(f.ncf, nd.ncf_nota_debito, 'S/C') AS ncf,
                       CASE
                           WHEN cxc.factura_id IS NOT NULL THEN 'Factura Venta a Crédito #' || f.numero_factura
                           ELSE 'Nota de Débito (B03): ' || COALESCE(nd.concepto_cargo, 'Cargo')
                       END AS concepto,
                       (CURRENT_DATE - cxc.fecha_vencimiento) AS dias_diferencia
                FROM ventas.cuentas_por_cobrar cxc
                JOIN tesoreria.monedas m ON m.id = cxc.moneda_id
                LEFT JOIN ventas.facturas f ON f.id = cxc.factura_id
                LEFT JOIN ventas.notas_debito nd ON nd.id = cxc.nota_debito_id
                WHERE cxc.cliente_id = ?
                  AND cxc.estado IN ('PENDIENTE', 'PAGADA_PARCIAL')
                ORDER BY cxc.fecha_vencimiento ASC, cxc.id ASC
            ");
            $stmtCxc->execute([$clienteId]);
            $deudas = $stmtCxc->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmtCaja = $pdo->prepare("
                SELECT sc.id AS sesion_caja_id, c.nombre_equipo, sc.caja_id
                FROM ventas.sesiones_caja sc
                JOIN ventas.cajas_pos c ON c.id = sc.caja_id
                WHERE sc.cajero_id IN (?, ?) AND sc.estado = 'ABIERTA'
                ORDER BY sc.id DESC
                LIMIT 1
            ");
            $stmtCaja->execute([$usuarioId, $personaId ?: $usuarioId]);
            $sesionActiva = $stmtCaja->fetch(PDO::FETCH_ASSOC) ?: null;

            $saldoRec = obtenerSaldoFavorRecibosCliente($pdo, $clienteId, $monedaId, false);

            responderCxc(true, 'OK', [
                'deudas' => $deudas,
                'sesion_caja' => $sesionActiva,
                'saldo_a_favor_disponible' => $saldoRec['disponible'],
            ]);
            break;

        // ====================================================================
        // 1B. FACTURAS DEL CLIENTE (PARA SELECTOR DE NOTA DE DÉBITO B03)
        // ====================================================================
        // ====================================================================
        // 1B. BUSCADOR EN VIVO DE FACTURAS DEL CLIENTE (PARA NOTA DE DÉBITO B03)
        // ====================================================================
        case 'obtener_facturas_cliente':
            $clienteId = trim((string) ($datos['cliente_id'] ?? ''));
            $q = trim((string) ($datos['q'] ?? ''));
            if ($clienteId === '') {
                throw new Exception('ID de cliente requerido.');
            }

            $stTot = $pdo->prepare("
                SELECT COUNT(*)
                FROM ventas.facturas
                WHERE cliente_id = ? AND estado <> 'ANULADA' AND ncf IS NOT NULL
            ");
            $stTot->execute([$clienteId]);
            $totalFacturas = (int) $stTot->fetchColumn();

            $params = [$clienteId];
            $where = ["f.cliente_id = ?", "f.estado <> 'ANULADA'", "f.ncf IS NOT NULL"];

            if ($q !== '') {
                $like = '%' . $q . '%';
                $where[] = "(
                    f.numero_factura ILIKE ?
                    OR COALESCE(f.ncf, '') ILIKE ?
                    OR TO_CHAR(f.fecha_emision, 'DD/MM/YYYY') ILIKE ?
                    OR TO_CHAR(f.fecha_emision, 'YYYY-MM-DD') ILIKE ?
                    OR CAST(f.monto_total AS TEXT) ILIKE ?
                    OR COALESCE(f.condicion_pago, '') ILIKE ?
                )";
                array_push($params, $like, $like, $like, $like, $like, $like);
            }

            $stFacs = $pdo->prepare("
                SELECT f.id, f.numero_factura, COALESCE(f.ncf, '') AS ncf,
                       f.monto_total, f.condicion_pago, f.estado,
                       f.moneda_id, m.simbolo AS moneda_simbolo,
                       TO_CHAR(f.fecha_emision, 'DD/MM/YYYY') AS fecha_fmt
                FROM ventas.facturas f
                JOIN tesoreria.monedas m ON m.id = f.moneda_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY f.fecha_emision DESC, f.id DESC
                LIMIT 30
            ");
            $stFacs->execute($params);

            responderCxc(true, 'OK', [
                'facturas' => $stFacs->fetchAll(PDO::FETCH_ASSOC) ?: [],
                'total_facturas' => $totalFacturas,
            ]);
            break;

        // ====================================================================
        // 1C. VALIDAR NOTA DE CRÉDITO B04 PARA USAR EN COBRO CXC
        // ====================================================================
        case 'validar_credito_b04':
            $codigo = trim((string) ($datos['codigo_credito'] ?? ''));
            $monedaId = max(1, (int) ($datos['moneda_id'] ?? 1));
            $dev = buscarCreditoB04Cxc($pdo, $codigo, $monedaId, false);

            responderCxc(true, "B04 {$dev['ncf_nota_credito']} vigente • Disponible: RD$ " . number_format((float) $dev['saldo_disponible'], 2), [
                'credito' => $dev,
            ]);
            break;

        // ====================================================================
        // FASE 2 & 3: REGISTRO DE RECIBO DE INGRESO CON SPLIT UNIVERSAL
        // ====================================================================
        case 'registrar_recibo_ingreso':
            if (!$puedeAcceder) {
                responderCxc(false, 'No cuenta con permisos autorizados para cobrar cuentas por cobrar.', [], 403);
            }

            $clienteId = trim((string) ($datos['cliente_id'] ?? ''));
            $facturasItems = $datos['facturas'] ?? [];
            $pagosSplit = $datos['pagos'] ?? [];

            if ($clienteId === '') {
                throw new Exception('Debe seleccionar un cliente.');
            }
            if (!is_array($facturasItems) || empty($facturasItems)) {
                throw new Exception('Debe seleccionar al menos una deuda para cobrar.');
            }
            if (!is_array($pagosSplit) || empty($pagosSplit)) {
                throw new Exception('Debe especificar al menos un instrumento de pago en el Split Universal.');
            }

            $stCliNom = $pdo->prepare("SELECT nombre_razon_social FROM identidad.personas WHERE persona_id = ? LIMIT 1");
            $stCliNom->execute([$clienteId]);
            $clienteNombre = (string) ($stCliNom->fetchColumn() ?: 'Cliente');

            $pdo->beginTransaction();

            // 1. Validar facturas y bloquear filas FOR UPDATE
            $totalMontoAplicadoFacturas = 0.00;
            $facturasValidadas = [];
            $monedaIdConsolidada = null;
            $tasaCambioConsolidada = 1.0000;
            $monedaSimbolo = 'RD$';

            $stmtCxc = $pdo->prepare("
                SELECT cxc.id, cxc.cliente_id, cxc.saldo_pendiente, cxc.estado,
                       cxc.factura_id, cxc.moneda_id, cxc.tasa_cambio, m.simbolo, m.codigo_iso
                FROM ventas.cuentas_por_cobrar cxc
                JOIN tesoreria.monedas m ON m.id = cxc.moneda_id
                WHERE cxc.id = ?
                FOR UPDATE OF cxc
            ");

            foreach ($facturasItems as $fItem) {
                $cxcId = (int) ($fItem['cxc_id'] ?? 0);
                $montoAplicar = round((float) ($fItem['monto_aplicado'] ?? 0), 2);
                if ($cxcId <= 0 || $montoAplicar <= 0) {
                    continue;
                }

                $stmtCxc->execute([$cxcId]);
                $cxcRow = $stmtCxc->fetch(PDO::FETCH_ASSOC);

                if (!$cxcRow) {
                    throw new Exception("La cuenta por cobrar #{$cxcId} no existe.");
                }
                if ($cxcRow['cliente_id'] !== $clienteId) {
                    throw new Exception("La cuenta por cobrar #{$cxcId} no pertenece al cliente seleccionado.");
                }
                if (!in_array($cxcRow['estado'], ['PENDIENTE', 'PAGADA_PARCIAL'], true)) {
                    throw new Exception("La cuenta por cobrar #{$cxcId} ya no está pendiente (Estado: {$cxcRow['estado']}).");
                }

                $monCxc = (int) $cxcRow['moneda_id'];
                if ($monedaIdConsolidada === null) {
                    $monedaIdConsolidada = $monCxc;
                    $tasaCambioConsolidada = max(0.0001, (float) $cxcRow['tasa_cambio']);
                    $monedaSimbolo = (string) ($cxcRow['simbolo'] ?: 'RD$');
                } elseif ($monedaIdConsolidada !== $monCxc) {
                    throw new Exception('No se pueden consolidar deudas de monedas distintas en un mismo recibo de ingreso.');
                }

                $saldoAnterior = round((float) $cxcRow['saldo_pendiente'], 2);
                if ($montoAplicar > ($saldoAnterior + 0.01)) {
                    throw new Exception("El monto aplicado ({$monedaSimbolo} " . number_format($montoAplicar, 2) . ") excede el saldo pendiente ({$monedaSimbolo} " . number_format($saldoAnterior, 2) . ") en la deuda #{$cxcId}.");
                }

                $montoAplicar = min($montoAplicar, $saldoAnterior);
                $saldoRestante = round(max(0.0, $saldoAnterior - $montoAplicar), 2);

                $totalMontoAplicadoFacturas = round($totalMontoAplicadoFacturas + $montoAplicar, 2);
                $facturasValidadas[] = [
                    'cxc_id' => $cxcId,
                    'factura_id' => !empty($cxcRow['factura_id']) ? (int) $cxcRow['factura_id'] : null,
                    'saldo_anterior' => $saldoAnterior,
                    'monto_aplicado' => $montoAplicar,
                    'saldo_restante' => $saldoRestante,
                ];
            }

            if (empty($facturasValidadas)) {
                throw new Exception('No se especificó ningún monto válido para cobrar.');
            }

            $monedaId = $monedaIdConsolidada ?? 1;
            $tasaCambio = $tasaCambioConsolidada;

            // 2. Validar instrumentos del Split Universal
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

            $requiereCaja = false;
            $totalPagadoMetodos = 0.00;
            $totalSaldoFavorRec = 0.00;
            $pagosValidos = [];
            $refsEnEsteRecibo = [];

            foreach ($pagosSplit as $idxP => $pago) {
                $numP = $idxP + 1;
                $metodoId = (int) ($pago['metodo_pago_id'] ?? 0);
                $montoP = round((float) ($pago['monto'] ?? 0), 2);
                $devuelta = round(max(0.0, (float) ($pago['monto_devuelta'] ?? 0)), 2);

                if ($montoP <= 0) {
                    continue;
                }

                $stmtMet->execute([$metodoId]);
                $metRow = $stmtMet->fetch(PDO::FETCH_ASSOC);
                if (!$metRow) {
                    throw new Exception("Instrumento #{$numP}: método de pago no válido o inactivo.");
                }
                $codMet = strtoupper((string) $metRow['codigo']);

                if ($codMet !== 'EFECTIVO') {
                    $devuelta = 0.00;
                } elseif ($devuelta >= $montoP) {
                    throw new Exception("Instrumento #{$numP} (Efectivo): la devuelta no puede ser mayor o igual al monto recibido.");
                }

                $netoInstrumento = round($montoP - $devuelta, 2);

                if (in_array($codMet, ['EFECTIVO', 'TARJETA'], true)) {
                    $requiereCaja = true;
                }

                $itemVal = [
                    'metodo_id' => $metodoId,
                    'codigo' => $codMet,
                    'monto' => $montoP,
                    'devuelta' => $devuelta,
                    'neto' => $netoInstrumento,
                    'cuenta' => null,
                    'referencia' => '',
                    'banco_origen' => '',
                    'titular' => trim((string) ($pago['titular'] ?? $clienteNombre)) ?: $clienteNombre,
                    'num_cheque' => '',
                    'banco_emisor' => '',
                    'tipo_tarjeta' => 'CREDITO',
                    'marca_tarjeta' => 'VISA',
                    'ultimos_4' => '',
                    'autorizacion' => '',
                    'lote' => '',
                    'origen_saldo' => 'RECIBO_CXC_ANTERIOR',
                    'codigo_credito' => '',
                ];

                if ($codMet === 'TARJETA') {
                    $tipoTj = strtoupper(trim((string) ($pago['tipo_tarjeta'] ?? 'CREDITO')));
                    $marcaTj = strtoupper(trim((string) ($pago['marca_tarjeta'] ?? 'VISA')));
                    $ult4 = preg_replace('/\D/', '', (string) ($pago['ultimos_4'] ?? $pago['ultimos_4_digitos'] ?? ''));
                    $aut = trim((string) ($pago['autorizacion'] ?? $pago['numero_autorizacion'] ?? ''));
                    $lote = trim((string) ($pago['lote'] ?? $pago['numero_lote'] ?? '')) ?: null;

                    if (strlen($ult4) !== 4) {
                        throw new Exception("Instrumento #{$numP} (Tarjeta): ingrese los últimos 4 dígitos.");
                    }
                    if ($aut === '') {
                        throw new Exception("Instrumento #{$numP} (Tarjeta): ingrese el número de autorización del Verifone.");
                    }

                    $itemVal['tipo_tarjeta'] = in_array($tipoTj, ['CREDITO', 'DEBITO'], true) ? $tipoTj : 'CREDITO';
                    $itemVal['marca_tarjeta'] = in_array($marcaTj, ['VISA', 'MASTERCARD', 'AMEX', 'OTRA'], true) ? $marcaTj : 'OTRA';
                    $itemVal['ultimos_4'] = $ult4;
                    $itemVal['autorizacion'] = $aut;
                    $itemVal['lote'] = $lote;

                } elseif ($codMet === 'TRANSFERENCIA') {
                    $cuentaId = (int) ($pago['cuenta_bancaria_id'] ?? 0);
                    $refTrf = trim((string) ($pago['referencia'] ?? ''));
                    $bancoOrig = trim((string) ($pago['banco_origen'] ?? '')) ?: 'Banco del Cliente';

                    if ($cuentaId <= 0) {
                        throw new Exception("Instrumento #{$numP} (Transferencia): seleccione la cuenta bancaria receptora.");
                    }
                    if ($refTrf === '') {
                        throw new Exception("Instrumento #{$numP} (Transferencia): ingrese el número de referencia.");
                    }

                    $stmtCta->execute([$cuentaId]);
                    $ctaRow = $stmtCta->fetch(PDO::FETCH_ASSOC);
                    if (!$ctaRow || !in_array($ctaRow['activa'], [true, 't', 1, '1'], true)) {
                        throw new Exception("Instrumento #{$numP}: la cuenta bancaria seleccionada no está activa.");
                    }
                    if ((int) $ctaRow['moneda_id'] !== $monedaId) {
                        throw new Exception("Instrumento #{$numP}: la cuenta «{$ctaRow['nombre_banco']}» está en {$ctaRow['codigo_iso']} y no coincide con la moneda de la deuda.");
                    }

                    $claveRef = $cuentaId . '|' . strtoupper($refTrf);
                    if (in_array($claveRef, $refsEnEsteRecibo, true)) {
                        throw new Exception("La referencia «{$refTrf}» está repetida en dos líneas del mismo recibo.");
                    }
                    $refsEnEsteRecibo[] = $claveRef;

                    $stmtChkRefPrevia->execute([$cuentaId, $refTrf]);
                    if ($dupM = $stmtChkRefPrevia->fetch(PDO::FETCH_ASSOC)) {
                        throw new Exception("La referencia «{$refTrf}» ya existe en {$ctaRow['nombre_banco']} (Movimiento #{$dupM['id']} del {$dupM['fecha_fmt']}).");
                    }

                    $itemVal['cuenta'] = $ctaRow;
                    $itemVal['referencia'] = $refTrf;
                    $itemVal['banco_origen'] = $bancoOrig;

                } elseif ($codMet === 'CHEQUE') {
                    $numChq = trim((string) ($pago['num_cheque'] ?? $pago['numero_cheque'] ?? ''));
                    $bancoEmis = trim((string) ($pago['banco_emisor'] ?? ''));
                    $cuentaId = !empty($pago['cuenta_bancaria_id']) ? (int) $pago['cuenta_bancaria_id'] : 0;

                    if ($numChq === '' || $bancoEmis === '') {
                        throw new Exception("Instrumento #{$numP} (Cheque): indique el banco emisor y el número de cheque.");
                    }

                    if ($cuentaId > 0) {
                        $stmtCta->execute([$cuentaId]);
                        $ctaRow = $stmtCta->fetch(PDO::FETCH_ASSOC);
                        if (!$ctaRow || !in_array($ctaRow['activa'], [true, 't', 1, '1'], true)) {
                            throw new Exception("Instrumento #{$numP}: la cuenta bancaria destino del cheque no está activa.");
                        }
                        if ((int) $ctaRow['moneda_id'] !== $monedaId) {
                            throw new Exception("Instrumento #{$numP}: la cuenta destino del cheque está en {$ctaRow['codigo_iso']} y no coincide con la moneda de la deuda.");
                        }

                        $claveRef = $cuentaId . '|' . strtoupper($numChq);
                        if (in_array($claveRef, $refsEnEsteRecibo, true)) {
                            throw new Exception("El cheque «{$numChq}» está repetido en el mismo recibo.");
                        }
                        $refsEnEsteRecibo[] = $claveRef;

                        $stmtChkRefPrevia->execute([$cuentaId, $numChq]);
                        if ($dupM = $stmtChkRefPrevia->fetch(PDO::FETCH_ASSOC)) {
                            throw new Exception("El cheque «{$numChq}» ya existe en {$ctaRow['nombre_banco']} (Movimiento #{$dupM['id']} del {$dupM['fecha_fmt']}).");
                        }
                        $itemVal['cuenta'] = $ctaRow;
                    }

                    $itemVal['num_cheque'] = $numChq;
                    $itemVal['banco_emisor'] = $bancoEmis;

                } elseif ($codMet === 'SALDO_FAVOR') {
                    $origSf = strtoupper(trim((string) ($pago['origen_saldo'] ?? 'RECIBO_CXC_ANTERIOR')));
                    $codB04 = trim((string) ($pago['codigo_credito'] ?? $pago['referencia'] ?? ''));
                    if ($codB04 !== '') {
                        $origSf = 'DEVOLUCION_B04';
                    }
                    if ($origSf === 'RECIBO_CXC_ANTERIOR') {
                        $totalSaldoFavorRec = round($totalSaldoFavorRec + $netoInstrumento, 2);
                    }
                    $itemVal['origen_saldo'] = $origSf;
                    $itemVal['codigo_credito'] = $codB04;
                }

                $totalPagadoMetodos = round($totalPagadoMetodos + $netoInstrumento, 2);
                $pagosValidos[] = $itemVal;
            }

            if (empty($pagosValidos)) {
                throw new Exception('Debe registrar al menos un monto mayor a cero en los instrumentos de pago.');
            }

            // Consolidar múltiples líneas de EFECTIVO en un único asiento de caja
            $pagosConsolidados = [];
            $idxEfectivo = null;
            foreach ($pagosValidos as $pg) {
                if ($pg['codigo'] === 'EFECTIVO') {
                    if ($idxEfectivo === null) {
                        $idxEfectivo = count($pagosConsolidados);
                        $pagosConsolidados[] = $pg;
                    } else {
                        $pagosConsolidados[$idxEfectivo]['monto'] = round($pagosConsolidados[$idxEfectivo]['monto'] + $pg['monto'], 2);
                        $pagosConsolidados[$idxEfectivo]['devuelta'] = round($pagosConsolidados[$idxEfectivo]['devuelta'] + $pg['devuelta'], 2);
                        $pagosConsolidados[$idxEfectivo]['neto'] = round($pagosConsolidados[$idxEfectivo]['neto'] + $pg['neto'], 2);
                    }
                } else {
                    $pagosConsolidados[] = $pg;
                }
            }
            $pagosValidos = $pagosConsolidados;

            // 3. Validar sesión de caja ABIERTA si hay Efectivo o Tarjeta
            $sesionCajaId = null;
            if ($requiereCaja) {
                $stmtSes = $pdo->prepare("
                    SELECT id FROM ventas.sesiones_caja
                    WHERE cajero_id IN (?, ?) AND estado = 'ABIERTA'
                    ORDER BY id DESC LIMIT 1
                    FOR UPDATE
                ");
                $stmtSes->execute([$usuarioId, $personaId ?: $usuarioId]);
                $sesionCajaId = $stmtSes->fetchColumn() ?: null;

                if (!$sesionCajaId) {
                    throw new Exception('Para cobros en mostrador (Efectivo o Tarjeta) debes tener un turno de caja ABIERTO en Control de Caja. Para pagos bancarios utiliza Transferencia o Cheque.');
                }
                $sesionCajaId = (int) $sesionCajaId;
            }

            // 4. Validar saldo a favor de recibos anteriores si fue usado
            $saldoReciboInfo = null;
            if ($totalSaldoFavorRec > 0) {
                $saldoReciboInfo = obtenerSaldoFavorRecibosCliente($pdo, $clienteId, $monedaId, true);
                if ($totalSaldoFavorRec > ($saldoReciboInfo['disponible'] + 0.01) || empty($saldoReciboInfo['recibo_origen_id'])) {
                    throw new Exception(
                        "Saldo a favor de CxC insuficiente para {$clienteNombre}. Disponible: {$monedaSimbolo} " .
                        number_format($saldoReciboInfo['disponible'], 2) . "."
                    );
                }
            }

            // 5. Validar cobertura del total aplicado y excedente (Saldo a Favor)
            if (($totalPagadoMetodos + 0.01) < $totalMontoAplicadoFacturas) {
                $faltante = round($totalMontoAplicadoFacturas - $totalPagadoMetodos, 2);
                throw new Exception(
                    "Los instrumentos ({$monedaSimbolo} " . number_format($totalPagadoMetodos, 2) . ") no cubren el total a cobrar " .
                    "({$monedaSimbolo} " . number_format($totalMontoAplicadoFacturas, 2) . "). Faltan {$monedaSimbolo} " . number_format($faltante, 2) . "."
                );
            }

            $saldoAFavorGenerado = round(max(0.0, $totalPagadoMetodos - $totalMontoAplicadoFacturas), 2);
            if ($saldoAFavorGenerado > 0 && $totalSaldoFavorRec > 0) {
                throw new Exception('El monto aplicado con Saldo a Favor no puede generar un excedente nuevo.');
            }

            // 6. Correlativo seguro del Recibo de Ingreso
            $seqName = $pdo->query("SELECT pg_get_serial_sequence('ventas.recibos_ingreso_cxc', 'id')")->fetchColumn()
                ?: 'ventas.recibos_ingreso_cxc_id_seq';
            $nextId = (int) $pdo->query("SELECT nextval('{$seqName}')")->fetchColumn();
            $numRecibo = 'REC-' . date('Y') . '-' . str_pad((string) $nextId, 6, '0', STR_PAD_LEFT);

            $cajeroFkVal = resolverFkUsuarioCxc($pdo, 'ventas', 'recibos_ingreso_cxc', 'cajero_id', $usuarioId, $personaId);

            // 7. Insertar cabecera de Recibo de Ingreso
            $pdo->prepare("
                INSERT INTO ventas.recibos_ingreso_cxc (
                    id, numero_recibo, cliente_id, sesion_caja_id, cajero_id,
                    monto_total_recibido, fecha_recibo, saldo_a_favor_generado, moneda_id, tasa_cambio
                ) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?)
            ")->execute([
                        $nextId,
                        $numRecibo,
                        $clienteId,
                        $sesionCajaId,
                        $cajeroFkVal,
                        $totalPagadoMetodos,
                        $saldoAFavorGenerado,
                        $monedaId,
                        $tasaCambio
                    ]);

            // 8. Insertar detalles + respaldo trigger-safe de saldo y estado de factura
            $stmtInsDet = $pdo->prepare("
                INSERT INTO ventas.recibo_ingreso_detalles (
                    recibo_ingreso_id, cuenta_por_cobrar_id, monto_aplicado, saldo_anterior, saldo_restante
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $stVerifCxc = $pdo->prepare("SELECT saldo_pendiente FROM ventas.cuentas_por_cobrar WHERE id = ?");
            $stUpdCxc = $pdo->prepare("UPDATE ventas.cuentas_por_cobrar SET saldo_pendiente = ?, estado = ? WHERE id = ?");
            $stUpdFac = $pdo->prepare("UPDATE ventas.facturas SET estado = 'PAGADA' WHERE id = ? AND estado = 'EMITIDA'");

            foreach ($facturasValidadas as $fv) {
                $stmtInsDet->execute([
                    $nextId,
                    $fv['cxc_id'],
                    $fv['monto_aplicado'],
                    $fv['saldo_anterior'],
                    $fv['saldo_restante']
                ]);

                $stVerifCxc->execute([$fv['cxc_id']]);
                $saldoActualBd = round((float) $stVerifCxc->fetchColumn(), 2);
                if (abs($saldoActualBd - $fv['saldo_anterior']) < 0.005 && $fv['monto_aplicado'] > 0) {
                    $nuevoEstado = ($fv['saldo_restante'] <= 0.00) ? 'PAGADA' : 'PAGADA_PARCIAL';
                    $stUpdCxc->execute([$fv['saldo_restante'], $nuevoEstado, $fv['cxc_id']]);
                }

                if ($fv['saldo_restante'] <= 0.00 && !empty($fv['factura_id'])) {
                    $stUpdFac->execute([$fv['factura_id']]);
                }
            }

            // 9. Insertar Split en tesoreria.detalles_pago + subtipos 1:1 + movimientos_bancarios trigger-safe
            $stmtInsDp = $pdo->prepare("
                INSERT INTO tesoreria.detalles_pago (
                    metodo_pago_id, monto_pagado, monto_devuelta, moneda_id, tasa_cambio, sesion_caja_id
                ) VALUES (?, ?, ?, ?, ?, ?)
                RETURNING id
            ");
            $stmtInsPuente = $pdo->prepare("
                INSERT INTO ventas.recibo_cxc_pagos (recibo_ingreso_id, detalle_pago_id)
                VALUES (?, ?)
            ");

            $colsCheque = obtenerColumnasTabla($pdo, 'tesoreria', 'pago_cheque');

            $stmtBuscarMovTrg = $pdo->prepare("
                SELECT id FROM tesoreria.movimientos_bancarios
                WHERE detalle_pago_id = ?
                   OR (cuenta_bancaria_id = ? AND UPPER(TRIM(referencia)) = UPPER(?))
                ORDER BY id DESC LIMIT 1
            ");
            $stmtUpdMovTrg = $pdo->prepare("
                UPDATE tesoreria.movimientos_bancarios
                SET tipo_movimiento  = ?,
                    monto            = ?,
                    concepto         = ?,
                    fecha_movimiento = CURRENT_DATE,
                    usuario_id       = ?,
                    detalle_pago_id  = ?
                WHERE id = ?
            ");
            $stmtInsMovBanc = $pdo->prepare("
                INSERT INTO tesoreria.movimientos_bancarios (
                    cuenta_bancaria_id, tipo_movimiento, monto, referencia,
                    concepto, fecha_movimiento, usuario_id, detalle_pago_id
                ) VALUES (?, ?, ?, ?, ?, CURRENT_DATE, ?, ?)
            ");

            foreach ($pagosValidos as $pg) {
                $sesionPago = in_array($pg['codigo'], ['EFECTIVO', 'TARJETA'], true) ? $sesionCajaId : null;

                $stmtInsDp->execute([
                    $pg['metodo_id'],
                    $pg['monto'],
                    $pg['devuelta'],
                    $monedaId,
                    $tasaCambio,
                    $sesionPago
                ]);
                $dpId = (int) $stmtInsDp->fetchColumn();
                $stmtInsPuente->execute([$nextId, $dpId]);

                if ($pg['codigo'] === 'TARJETA') {
                    $pdo->prepare("
                        INSERT INTO tesoreria.pago_tarjeta (
                            detalle_pago_id, tipo_tarjeta, marca_tarjeta, ultimos_4_digitos, numero_autorizacion, numero_lote
                        ) VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([
                                $dpId,
                                $pg['tipo_tarjeta'],
                                $pg['marca_tarjeta'],
                                $pg['ultimos_4'],
                                $pg['autorizacion'],
                                $pg['lote']
                            ]);

                } elseif ($pg['codigo'] === 'TRANSFERENCIA') {
                    $cuentaId = (int) $pg['cuenta']['id'];
                    $refTrf = $pg['referencia'];

                    $pdo->prepare("
                        INSERT INTO tesoreria.pago_transferencia (
                            detalle_pago_id, banco_origen, cuenta_bancaria_id, numero_referencia, fecha_transferencia, titular_origen
                        ) VALUES (?, ?, ?, ?, CURRENT_DATE, ?)
                    ")->execute([$dpId, $pg['banco_origen'], $cuentaId, $refTrf, $pg['titular']]);

                    $conceptoMov = mb_substr("Cobro CxC ({$clienteNombre}) • {$numRecibo} • Ref: {$refTrf}", 0, 255, 'UTF-8');

                    $stmtBuscarMovTrg->execute([$dpId, $cuentaId, $refTrf]);
                    $movIdTrg = (int) ($stmtBuscarMovTrg->fetchColumn() ?: 0);

                    if ($movIdTrg > 0) {
                        $stmtUpdMovTrg->execute(['TRANSFERENCIA_ENTRADA', $pg['neto'], $conceptoMov, $usuarioId, $dpId, $movIdTrg]);
                    } else {
                        $stmtInsMovBanc->execute([$cuentaId, 'TRANSFERENCIA_ENTRADA', $pg['neto'], $refTrf, $conceptoMov, $usuarioId, $dpId]);
                    }

                } elseif ($pg['codigo'] === 'CHEQUE') {
                    $cuentaId = !empty($pg['cuenta']) ? (int) $pg['cuenta']['id'] : null;
                    $numChq = $pg['num_cheque'];

                    $chCols = ['detalle_pago_id', 'numero_cheque', 'banco_emisor', 'cuenta_bancaria_id', 'estado_cheque'];
                    $chVals = [$dpId, $numChq, $pg['banco_emisor'], $cuentaId, 'RECIBIDO'];
                    if (isset($colsCheque['titular_cheque'])) {
                        $chCols[] = 'titular_cheque';
                        $chVals[] = $pg['titular'];
                    }
                    if (isset($colsCheque['fecha_emision'])) {
                        $chCols[] = 'fecha_emision';
                        $chVals[] = date('Y-m-d');
                    }
                    if (isset($colsCheque['fecha_deposito'])) {
                        $chCols[] = 'fecha_deposito';
                        $chVals[] = ($cuentaId !== null) ? date('Y-m-d') : null;
                    }
                    $phCh = implode(', ', array_fill(0, count($chCols), '?'));
                    $pdo->prepare("INSERT INTO tesoreria.pago_cheque (" . implode(', ', $chCols) . ") VALUES ({$phCh})")
                        ->execute($chVals);

                    if ($cuentaId !== null && $cuentaId > 0) {
                        $conceptoMov = mb_substr("Cheque Cobro CxC ({$clienteNombre}) • {$numRecibo} • Cheque #{$numChq}", 0, 255, 'UTF-8');

                        $stmtBuscarMovTrg->execute([$dpId, $cuentaId, $numChq]);
                        $movIdTrg = (int) ($stmtBuscarMovTrg->fetchColumn() ?: 0);

                        if ($movIdTrg > 0) {
                            $stmtUpdMovTrg->execute(['CHEQUE_ENTRADA', $pg['neto'], $conceptoMov, $usuarioId, $dpId, $movIdTrg]);
                        } else {
                            $stmtInsMovBanc->execute([$cuentaId, 'CHEQUE_ENTRADA', $pg['neto'], $numChq, $conceptoMov, $usuarioId, $dpId]);
                        }
                    }

                } elseif ($pg['codigo'] === 'SALDO_FAVOR') {
                    if ($pg['origen_saldo'] === 'DEVOLUCION_B04') {
                        $devCred = buscarCreditoB04Cxc($pdo, $pg['codigo_credito'], $monedaId, true);
                        $saldoDisp = round((float) $devCred['saldo_disponible'], 2);
                        if ($pg['neto'] > ($saldoDisp + 0.01)) {
                            throw new Exception("El monto a aplicar ({$monedaSimbolo} " . number_format($pg['neto'], 2) . ") supera el saldo disponible ({$monedaSimbolo} " . number_format($saldoDisp, 2) . ") de la B04 {$devCred['ncf_nota_credito']}.");
                        }

                        $pdo->prepare("
                            INSERT INTO tesoreria.pago_saldo_favor (detalle_pago_id, cliente_id, origen_saldo, documento_origen_id)
                            VALUES (?, ?, 'DEVOLUCION_B04', ?)
                        ")->execute([$dpId, $clienteId, (int) $devCred['id']]);

                        $nuevoSaldoB04 = round(max(0.0, $saldoDisp - $pg['neto']), 2);
                        $nuevoEstadoB04 = ($nuevoSaldoB04 <= 0.00) ? 'USADO' : 'USADO_PARCIAL';
                        $pdo->prepare("UPDATE ventas.devoluciones SET saldo_disponible = ?, estado_saldo = ? WHERE id = ?")
                            ->execute([$nuevoSaldoB04, $nuevoEstadoB04, (int) $devCred['id']]);
                    } else {
                        $pdo->prepare("
                            INSERT INTO tesoreria.pago_saldo_favor (detalle_pago_id, cliente_id, origen_saldo, documento_origen_id)
                            VALUES (?, ?, 'RECIBO_CXC_ANTERIOR', ?)
                        ")->execute([$dpId, $clienteId, (int) $saldoReciboInfo['recibo_origen_id']]);
                    }
                }
            }

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
                    'cliente_nombre' => $clienteNombre,
                    'sesion_caja_id' => $sesionCajaId,
                    'monto_total_recibido' => $totalPagadoMetodos,
                    'saldo_a_favor_generado' => $saldoAFavorGenerado,
                    'facturas_liquidadas' => $facturasValidadas,
                ]
            );

            $pdo->commit();

            $msgExcedente = ($saldoAFavorGenerado > 0)
                ? " Se acreditó un excedente de {$monedaSimbolo} " . number_format($saldoAFavorGenerado, 2) . " como saldo a favor."
                : '';

            responderCxc(true, "Recibo de Ingreso {$numRecibo} emitido exitosamente por {$monedaSimbolo} " . number_format($totalPagadoMetodos, 2) . ".{$msgExcedente}", [
                'recibo_id' => $nextId,
                'numero_recibo' => $numRecibo,
                'saldo_a_favor_generado' => $saldoAFavorGenerado,
            ]);
            break;

        // ====================================================================
        // FASE 4: AJUSTES, CONDONACIONES E INCOBRABLES CON PIN DE SUPERVISOR
        // ====================================================================
        case 'registrar_ajuste':
            if (!$puedeAcceder) {
                responderCxc(false, 'No cuenta con permisos autorizados para realizar ajustes de CxC.', [], 403);
            }

            $cxcId = (int) ($datos['cxc_id'] ?? 0);
            $tipoAjuste = strtoupper(trim((string) ($datos['tipo_ajuste'] ?? '')));
            $montoAjuste = round((float) ($datos['monto_ajuste'] ?? 0), 2);
            $justificacion = trim((string) ($datos['justificacion'] ?? ''));
            $pinSupervisor = trim((string) ($datos['pin_supervisor'] ?? ''));
            $supervisorPost = trim((string) ($datos['supervisor_id'] ?? ''));

            if ($cxcId <= 0) {
                throw new Exception('ID de cuenta por cobrar inválido.');
            }
            if (!in_array($tipoAjuste, ['DESCUENTO_PRONTO_PAGO', 'CONDONACION', 'INCOBRABLE', 'NOTA_CREDITO_B04'], true)) {
                throw new Exception("Tipo de ajuste no válido: {$tipoAjuste}.");
            }
            if (mb_strlen($justificacion, 'UTF-8') < 6) {
                throw new Exception('Debe especificar una justificación detallada de al menos 6 caracteres.');
            }
            if ($pinSupervisor === '') {
                throw new Exception('El PIN de supervisor es obligatorio para autorizar ajustes.');
            }

            $supervisorInfo = validarPinSupervisor($pdo, $supervisorPost, $pinSupervisor);
            $supervisorId = $supervisorInfo['usuario_id'];

            $pdo->beginTransaction();

            $stmtCxc = $pdo->prepare("
                SELECT id, saldo_pendiente, estado, moneda_id, tasa_cambio, factura_id
                FROM ventas.cuentas_por_cobrar
                WHERE id = ? FOR UPDATE
            ");
            $stmtCxc->execute([$cxcId]);
            $cxc = $stmtCxc->fetch(PDO::FETCH_ASSOC);

            if (!$cxc) {
                throw new Exception("La cuenta por cobrar #{$cxcId} no existe.");
            }
            if (in_array($cxc['estado'], ['ANULADA', 'INCOBRABLE', 'PAGADA'], true)) {
                throw new Exception("No se pueden aplicar ajustes a una obligación en estado '{$cxc['estado']}'.");
            }

            $saldoAnterior = round((float) $cxc['saldo_pendiente'], 2);
            if ($tipoAjuste === 'INCOBRABLE') {
                $montoAjuste = $saldoAnterior;
            }
            if ($montoAjuste <= 0 || $montoAjuste > $saldoAnterior) {
                throw new Exception('El monto del ajuste debe ser mayor a 0 y no puede exceder el saldo pendiente (RD$ ' . number_format($saldoAnterior, 2) . ').');
            }

            $saldoRestante = round($saldoAnterior - $montoAjuste, 2);

            $stmtInsAj = $pdo->prepare("
                INSERT INTO ventas.ajustes_cxc (
                    cuenta_por_cobrar_id, tipo_ajuste, moneda_id, tasa_cambio,
                    monto_ajuste, saldo_anterior, saldo_restante, justificacion, supervisor_id, fecha_ajuste
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
            $ajusteId = (int) $stmtInsAj->fetchColumn();

            // Respaldo trigger-safe si no existe trigger de ajuste sobre cuentas_por_cobrar
            $stVerif = $pdo->prepare("SELECT saldo_pendiente FROM ventas.cuentas_por_cobrar WHERE id = ?");
            $stVerif->execute([$cxcId]);
            if (abs((float) $stVerif->fetchColumn() - $saldoAnterior) < 0.005) {
                $nuevoEstado = ($tipoAjuste === 'INCOBRABLE')
                    ? 'INCOBRABLE'
                    : (($saldoRestante <= 0.00) ? 'PAGADA' : 'PAGADA_PARCIAL');
                $pdo->prepare("UPDATE ventas.cuentas_por_cobrar SET saldo_pendiente = ?, estado = ? WHERE id = ?")
                    ->execute([$saldoRestante, $nuevoEstado, $cxcId]);
            }

            registrarAuditoria(
                $pdo,
                $usuarioId,
                $supervisorId,
                'AJUSTE_CXC_' . $tipoAjuste,
                'ventas.ajustes_cxc',
                ['cxc_id' => $cxcId, 'saldo_anterior' => $saldoAnterior, 'estado_anterior' => $cxc['estado']],
                [
                    'ajuste_id' => $ajusteId,
                    'tipo_ajuste' => $tipoAjuste,
                    'monto_ajuste' => $montoAjuste,
                    'saldo_restante' => $saldoRestante,
                    'justificacion' => $justificacion,
                    'supervisor_username' => $supervisorInfo['username']
                ]
            );

            $pdo->commit();

            responderCxc(true, "Ajuste {$tipoAjuste} por RD$ " . number_format($montoAjuste, 2) . " aplicado exitosamente. Autorizado por {$supervisorInfo['nombre_completo']}.");
            break;

        // ====================================================================
        // FASE 1: CARGO ADICIONAL POR NOTA DE DÉBITO (NCF B03 DGII)
        // ====================================================================
        case 'registrar_nota_debito_cargo':
            if (!$puedeNotaDeb) {
                responderCxc(false, 'No cuenta con permisos para emitir Notas de Débito (B03).', [], 403);
            }

            $clienteId = trim((string) ($datos['cliente_id'] ?? ''));
            $facturaIdIn = (int) ($datos['factura_id'] ?? 0);
            $ncfFacturaMod = strtoupper(trim((string) ($datos['ncf_factura_modificada'] ?? '')));
            $concepto = trim((string) ($datos['concepto_cargo'] ?? ''));
            $montoBase = round((float) ($datos['monto_total'] ?? 0), 2);
            $aplicaItbis = filter_var($datos['aplica_itbis'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $diasCredito = max(0, (int) ($datos['dias_credito'] ?? 30));

            if ($clienteId === '') {
                throw new Exception('Debe seleccionar el cliente para emitir el cargo.');
            }
            if (mb_strlen($concepto, 'UTF-8') < 5) {
                throw new Exception('Debe indicar el concepto del cargo (mínimo 5 caracteres).');
            }
            if ($montoBase <= 0) {
                throw new Exception('El monto del cargo debe ser mayor a 0.00.');
            }

            $pdo->beginTransaction();

            // 1. Localizar la factura real del cliente que modifica esta Nota de Débito B03
            if ($facturaIdIn > 0) {
                $stmtFac = $pdo->prepare("
                    SELECT id, ncf, moneda_id, tasa_cambio
                    FROM ventas.facturas
                    WHERE id = ? AND cliente_id = ? AND estado <> 'ANULADA'
                    LIMIT 1
                ");
                $stmtFac->execute([$facturaIdIn, $clienteId]);
            } elseif ($ncfFacturaMod !== '') {
                $stmtFac = $pdo->prepare("
                    SELECT id, ncf, moneda_id, tasa_cambio
                    FROM ventas.facturas
                    WHERE cliente_id = ? AND estado <> 'ANULADA'
                      AND (UPPER(COALESCE(ncf, '')) = ? OR UPPER(numero_factura) = ?)
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtFac->execute([$clienteId, $ncfFacturaMod, $ncfFacturaMod]);
            } else {
                $stmtFac = $pdo->prepare("
                    SELECT id, ncf, moneda_id, tasa_cambio
                    FROM ventas.facturas
                    WHERE cliente_id = ? AND estado <> 'ANULADA' AND ncf IS NOT NULL
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtFac->execute([$clienteId]);
            }
            $facRow = $stmtFac->fetch(PDO::FETCH_ASSOC);

            if (!$facRow || empty($facRow['ncf'])) {
                throw new Exception('Para emitir una Nota de Débito (B03), el cliente debe tener al menos una factura fiscal válida emitida.');
            }

            $facturaId = (int) $facRow['id'];
            $ncfMod = (string) $facRow['ncf'];
            $monedaId = (int) $facRow['moneda_id'];
            $tasaCambio = max(0.0001, (float) $facRow['tasa_cambio']);

            // 2. Bloquear secuencia DGII B03 activa (igual que Facturación POS: usa secuencia_actual y luego incrementa)
            $stmtSeqB03 = $pdo->prepare("
                SELECT id, secuencia_actual, secuencia_hasta, fecha_vencimiento
                FROM ventas.secuencias_fiscales_dgii
                WHERE tipo_comprobante = 'B03' AND estado = 'ACTIVO'
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmtSeqB03->execute();
            $seqRow = $stmtSeqB03->fetch(PDO::FETCH_ASSOC);

            if (!$seqRow) {
                throw new Exception('No existe un bloque de secuencias fiscales DGII activo para B03. Configúralo en Secuencias Fiscales.');
            }
            if ($seqRow['fecha_vencimiento'] < date('Y-m-d')) {
                $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'VENCIDO' WHERE id = ?")->execute([$seqRow['id']]);
                throw new Exception("El bloque de secuencias DGII B03 venció el {$seqRow['fecha_vencimiento']}.");
            }

            $secuenciaDgiiId = (int) $seqRow['id'];
            $numActual = (int) $seqRow['secuencia_actual'];
            $numHasta = (int) $seqRow['secuencia_hasta'];

            if ($numActual > $numHasta) {
                $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'AGOTADO' WHERE id = ?")->execute([$secuenciaDgiiId]);
                throw new Exception('La secuencia fiscal B03 está agotada.');
            }

            $ncfNotaDebito = 'B03' . str_pad((string) $numActual, 8, '0', STR_PAD_LEFT);

            if ($numActual < $numHasta) {
                $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET secuencia_actual = secuencia_actual + 1 WHERE id = ?")->execute([$secuenciaDgiiId]);
            } else {
                $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'AGOTADO' WHERE id = ?")->execute([$secuenciaDgiiId]);
            }

            // 3. Calcular Subtotal e ITBIS (si aplica)
            $subtotal = $montoBase;
            $impuestos = 0.00;
            $impRow = null;

            if ($aplicaItbis) {
                $impRow = $pdo->query("
                    SELECT id, tipo_calculo, tasa_o_monto
                    FROM tesoreria.impuestos
                    WHERE activo = TRUE AND UPPER(siglas) LIKE 'ITBIS%'
                    ORDER BY id ASC LIMIT 1
                ")->fetch(PDO::FETCH_ASSOC);

                $tasaItbis = $impRow ? (float) $impRow['tasa_o_monto'] : 18.00;
                $impuestos = round($subtotal * ($tasaItbis / 100.0), 2);
            }
            $montoTotal = round($subtotal + $impuestos, 2);

            $emisorFkVal = resolverFkUsuarioCxc($pdo, 'ventas', 'notas_debito', 'usuario_emisor_id', $usuarioId, $personaId);

            // 4. Insertar Nota de Débito
            $stmtInsNd = $pdo->prepare("
                INSERT INTO ventas.notas_debito (
                    factura_id, cliente_id, secuencia_dgii_id, ncf_nota_debito, ncf_factura_modificada,
                    concepto_cargo, monto_subtotal, monto_impuestos, monto_total, usuario_emisor_id,
                    fecha_emision, moneda_id, tasa_cambio
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)
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
                $emisorFkVal,
                $monedaId,
                $tasaCambio
            ]);
            $ndId = (int) $stmtInsNd->fetchColumn();

            // Registrar desglose de impuesto en nota_debito_detalle_impuestos (para Reporte 607) si existe la tabla
            if ($aplicaItbis && $impRow && $impuestos > 0) {
                $colsNdImp = obtenerColumnasTabla($pdo, 'ventas', 'nota_debito_detalle_impuestos');
                if (!empty($colsNdImp)) {
                    $pdo->prepare("
                        INSERT INTO ventas.nota_debito_detalle_impuestos (
                            nota_debito_id, impuesto_id, base_imponible, tipo_calculo_aplicado, tasa_aplicada, monto_impuesto
                        ) VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([
                                $ndId,
                                (int) $impRow['id'],
                                $subtotal,
                                strtoupper((string) ($impRow['tipo_calculo'] ?: 'PORCENTAJE')),
                                (float) $impRow['tasa_o_monto'],
                                $impuestos
                            ]);
                }
            }

            // 5. Insertar en cuentas_por_cobrar (evitando duplicado si ya existe trigger sobre notas_debito)
            $stChkNdCxc = $pdo->prepare("SELECT id FROM ventas.cuentas_por_cobrar WHERE nota_debito_id = ? LIMIT 1");
            $stChkNdCxc->execute([$ndId]);
            $cxcIdGenerado = (int) ($stChkNdCxc->fetchColumn() ?: 0);
            $fechaVenc = date('Y-m-d', strtotime("+{$diasCredito} days"));

            if ($cxcIdGenerado <= 0) {
                $stmtInsCxc = $pdo->prepare("
                    INSERT INTO ventas.cuentas_por_cobrar (
                        cliente_id, factura_id, nota_debito_id, monto_original, saldo_pendiente,
                        estado, fecha_emision, fecha_vencimiento, moneda_id, tasa_cambio
                    ) VALUES (?, NULL, ?, ?, ?, 'PENDIENTE', CURRENT_DATE, ?, ?, ?)
                    RETURNING id
                ");
                $stmtInsCxc->execute([
                    $clienteId,
                    $ndId,
                    $montoTotal,
                    $montoTotal,
                    $fechaVenc,
                    $monedaId,
                    $tasaCambio
                ]);
                $cxcIdGenerado = (int) $stmtInsCxc->fetchColumn();
            }

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
                    'factura_id' => $facturaId,
                    'ncf_factura_modificada' => $ncfMod,
                    'monto_subtotal' => $subtotal,
                    'monto_impuestos' => $impuestos,
                    'monto_total' => $montoTotal,
                    'concepto_cargo' => $concepto,
                    'fecha_vencimiento' => $fechaVenc
                ]
            );

            $pdo->commit();

            responderCxc(true, "Nota de Débito ({$ncfNotaDebito}) y cargo en CxC generados exitosamente por RD$ " . number_format($montoTotal, 2) . ".");
            break;

        // ====================================================================
        // 5. VER DETALLE DE RECIBO DE INGRESO
        // ====================================================================
        case 'ver_detalle_recibo':
            $reciboId = (int) ($datos['recibo_id'] ?? 0);
            if ($reciboId <= 0) {
                throw new Exception('ID de recibo inválido.');
            }

            $stmtCab = $pdo->prepare("
                SELECT r.id, r.numero_recibo, r.fecha_recibo,
                       r.monto_total_recibido, r.saldo_a_favor_generado,
                       m.simbolo AS moneda_simbolo,
                       p.nombre_razon_social AS cliente_nombre,
                       p.documento_identidad AS cliente_rnc,
                       p.telefono_principal AS cliente_telefono,
                       COALESCE(caj_p.nombre_razon_social, u.username, 'Cajero') AS cajero_nombre,
                       COALESCE(u.username, 'cajero') AS cajero_username,
                       sc.caja_id, cpos.nombre_equipo AS caja_nombre
                FROM ventas.recibos_ingreso_cxc r
                JOIN identidad.clientes c ON c.persona_id = r.cliente_id
                JOIN identidad.personas p ON p.persona_id = c.persona_id
                JOIN tesoreria.monedas m ON m.id = r.moneda_id
                LEFT JOIN seguridad.usuarios u ON (u.usuario_id = r.cajero_id OR u.persona_id = r.cajero_id)
                LEFT JOIN identidad.personas caj_p ON caj_p.persona_id = COALESCE(u.persona_id, r.cajero_id)
                LEFT JOIN ventas.sesiones_caja sc ON sc.id = r.sesion_caja_id
                LEFT JOIN ventas.cajas_pos cpos ON cpos.id = sc.caja_id
                WHERE r.id = ?
                LIMIT 1
            ");
            $stmtCab->execute([$reciboId]);
            $recibo = $stmtCab->fetch(PDO::FETCH_ASSOC);

            if (!$recibo) {
                throw new Exception('Recibo de ingreso no encontrado.');
            }

            $stmtDet = $pdo->prepare("
                SELECT rd.cuenta_por_cobrar_id, rd.monto_aplicado, rd.saldo_anterior, rd.saldo_restante,
                       COALESCE(f.numero_factura, 'ND-' || nd.ncf_nota_debito, 'CXC-' || cxc.id) AS numero_documento,
                       COALESCE(f.ncf, nd.ncf_nota_debito, 'S/C') AS ncf
                FROM ventas.recibo_ingreso_detalles rd
                JOIN ventas.cuentas_por_cobrar cxc ON cxc.id = rd.cuenta_por_cobrar_id
                LEFT JOIN ventas.facturas f ON f.id = cxc.factura_id
                LEFT JOIN ventas.notas_debito nd ON nd.id = cxc.nota_debito_id
                WHERE rd.recibo_ingreso_id = ?
            ");
            $stmtDet->execute([$reciboId]);
            $facturas = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmtPags = $pdo->prepare("
                SELECT dp.id, mp.codigo AS metodo_codigo, mp.descripcion AS metodo_nombre,
                       dp.monto_pagado, dp.monto_devuelta, dp.sesion_caja_id,
                       pt.tipo_tarjeta, pt.marca_tarjeta, pt.ultimos_4_digitos, pt.numero_autorizacion,
                       ptr.banco_origen, ptr.numero_referencia, cb.nombre_banco,
                       pch.numero_cheque, pch.banco_emisor,
                       psf.origen_saldo, psf.documento_origen_id
                FROM ventas.recibo_cxc_pagos rcp
                JOIN tesoreria.detalles_pago dp ON dp.id = rcp.detalle_pago_id
                JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id
                LEFT JOIN tesoreria.pago_tarjeta pt ON pt.detalle_pago_id = dp.id
                LEFT JOIN tesoreria.pago_transferencia ptr ON ptr.detalle_pago_id = dp.id
                LEFT JOIN tesoreria.cuentas_bancarias cb ON cb.id = ptr.cuenta_bancaria_id
                LEFT JOIN tesoreria.pago_cheque pch ON pch.detalle_pago_id = dp.id
                LEFT JOIN tesoreria.pago_saldo_favor psf ON psf.detalle_pago_id = dp.id
                WHERE rcp.recibo_ingreso_id = ?
                ORDER BY dp.id ASC
            ");
            $stmtPags->execute([$reciboId]);
            $pagos = $stmtPags->fetchAll(PDO::FETCH_ASSOC) ?: [];

            responderCxc(true, 'OK', [
                'recibo' => $recibo,
                'facturas' => $facturas,
                'pagos' => $pagos,
            ]);
            break;

        default:
            throw new Exception('Acción no reconocida: ' . htmlspecialchars($accion));
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msgDb = $e->getMessage();
    $msg = 'Error de base de datos al procesar Cuentas por Cobrar.';
    if ($e->getCode() === '23505') {
        if (stripos($msgDb, 'uk_movbanco_ref') !== false) {
            $msg = 'Ese número de referencia bancaria o cheque ya fue registrado en la cuenta seleccionada.';
        } else {
            $msg = 'Registro duplicado detectado en la base de datos.';
        }
    } elseif ($e->getCode() === '23514') {
        $msg = 'Los montos o parámetros enviados no cumplen con las validaciones contables de la base de datos.';
    }

    responderCxc(false, $msg, ['detalle_tecnico' => $msgDb], 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderCxc(false, $e->getMessage(), [], 400);
}