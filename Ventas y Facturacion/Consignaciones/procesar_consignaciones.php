<?php
/**
 * ============================================================================
 * Backend Controlador: Proceso 5 - Consignaciones (Eventos y Bodas)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/Consignaciones/procesar_consignaciones.php
 * ============================================================================
 */

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/conexion.php';
if (file_exists(__DIR__ . '/../../config/funciones.php')) {
    require_once __DIR__ . '/../../config/funciones.php';
}
require_once __DIR__ . '/../../config/auditoria_helper.php';

function responderCsg(bool $ok, string $mensaje, array $extra = [], int $httpCode = 200): void
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

try {
    $usuarioId = requerirSesionActiva(true);
} catch (Throwable $e) {
    responderCsg(false, 'Sesión expirada o no válida.', [], 401);
}

$esAdmin = function_exists('usuarioEsAdministrador') && usuarioEsAdministrador();
$puedeCsg = $esAdmin || (function_exists('usuarioPuedeAccederConsignaciones') && usuarioPuedeAccederConsignaciones());

if (!$puedeCsg) {
    responderCsg(false, 'No posee permisos para operar en el módulo de Consignaciones.', [], 403);
}

$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : null;
$datos = is_array($jsonBody) ? array_merge($_GET, $jsonBody) : array_merge($_GET, $_POST);
$accion = trim((string) ($datos['accion'] ?? $_POST['accion'] ?? $_GET['accion'] ?? ''));
$personaId = $_SESSION['usuario']['persona_id'] ?? null;

// ============================================================================
// FUNCIONES AUXILIARES (IMPUESTOS DINÁMICOS, BANCOS TRIGGER-SAFE, CAJA)
// ============================================================================

function obtenerImpuestosProductoCsg(PDO $pdo, int $productoId): array
{
    static $cache = [];
    static $fallbackItbis = null;

    if (isset($cache[$productoId])) {
        return $cache[$productoId];
    }

    $st = $pdo->prepare("
        SELECT i.id, i.siglas, i.nombre, i.tipo_calculo, i.tasa_o_monto, i.orden_calculo
        FROM inventario.producto_impuestos pi
        JOIN tesoreria.impuestos i ON i.id = pi.impuesto_id
        WHERE pi.producto_maestro_id = ? AND i.activo = TRUE
        ORDER BY i.orden_calculo ASC, i.id ASC
    ");
    $st->execute([$productoId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($rows)) {
        if ($fallbackItbis === null) {
            $fallbackItbis = $pdo->query("
                SELECT id, siglas, nombre, tipo_calculo, tasa_o_monto, orden_calculo
                FROM tesoreria.impuestos
                WHERE activo = TRUE AND UPPER(siglas) LIKE 'ITBIS%'
                ORDER BY id ASC LIMIT 1
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $rows = $fallbackItbis;
    }

    $cache[$productoId] = $rows;
    return $rows;
}

function calcularImpuestosLineaCsg(array $impuestosCfg, float $baseNeta, int $cantidadUnidades): array
{
    $niveles = [];
    foreach ($impuestosCfg as $imp) {
        $ord = (int) ($imp['orden_calculo'] ?? 1);
        $niveles[$ord][] = $imp;
    }
    ksort($niveles);

    $baseAcum = max(0.0, $baseNeta);
    $desglose = [];
    $totalImp = 0.0;

    foreach ($niveles as $impuestosNivel) {
        $sumaNivel = 0.0;
        foreach ($impuestosNivel as $imp) {
            $tipo = strtoupper((string) $imp['tipo_calculo']);
            $tasa = (float) $imp['tasa_o_monto'];
            $base = round($baseAcum, 2);

            $monto = ($tipo === 'MONTO_FIJO')
                ? round($tasa * $cantidadUnidades, 2)
                : round($base * ($tasa / 100.0), 2);

            $desglose[] = [
                'impuesto_id' => (int) $imp['id'],
                'siglas' => $imp['siglas'],
                'nombre' => $imp['nombre'],
                'orden_calculo' => (int) $imp['orden_calculo'],
                'tipo_calculo_aplicado' => $tipo,
                'base_imponible' => $base,
                'tasa_aplicada' => $tasa,
                'monto_impuesto' => $monto,
            ];

            $sumaNivel += $monto;
            $totalImp += $monto;
        }
        $baseAcum = round($baseAcum + $sumaNivel, 2);
    }

    return [
        'total_impuestos' => round($totalImp, 2),
        'desglose' => $desglose,
    ];
}

function obtenerSesionCajaAbiertaCsg(PDO $pdo, string $usuarioId, ?string $personaId, bool $forUpdate = false): ?int
{
    $lock = $forUpdate ? 'FOR UPDATE' : '';
    $st = $pdo->prepare("
        SELECT id FROM ventas.sesiones_caja
        WHERE cajero_id IN (?, ?) AND estado = 'ABIERTA'
        ORDER BY id DESC LIMIT 1
        {$lock}
    ");
    $st->execute([$usuarioId, $personaId ?: $usuarioId]);
    $id = $st->fetchColumn();
    return $id ? (int) $id : null;
}

function columnaEsNullable(PDO $pdo, string $schema, string $tabla, string $columna): bool
{
    $st = $pdo->prepare("
        SELECT is_nullable
        FROM information_schema.columns
        WHERE table_schema = ? AND table_name = ? AND column_name = ?
        LIMIT 1
    ");
    $st->execute([$schema, $tabla, $columna]);
    return strtoupper((string) $st->fetchColumn()) === 'YES';
}

function validarCuentaBancariaDop(PDO $pdo, int $cuentaId): array
{
    $st = $pdo->prepare("
        SELECT cb.id, cb.nombre_banco, cb.numero_cuenta, cb.activa, cb.moneda_id,
               COALESCE(m.codigo_iso, 'DOP') AS codigo_iso,
               COALESCE(m.es_moneda_base, TRUE) AS es_moneda_base
        FROM tesoreria.cuentas_bancarias cb
        LEFT JOIN tesoreria.monedas m ON m.id = cb.moneda_id
        WHERE cb.id = ? LIMIT 1
    ");
    $st->execute([$cuentaId]);
    $cta = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cta || !in_array($cta['activa'], [true, 't', 1, '1'], true)) {
        throw new Exception('La cuenta bancaria seleccionada no existe o está inactiva.');
    }
    $esDop = in_array($cta['es_moneda_base'], [true, 't', 1, '1'], true) || strtoupper((string) $cta['codigo_iso']) === 'DOP';
    if (!$esDop) {
        throw new Exception("La cuenta «{$cta['nombre_banco']}» está en {$cta['codigo_iso']}. Seleccione una cuenta en DOP (RD$).");
    }
    return $cta;
}

function verificarReferenciaBancariaLibre(PDO $pdo, int $cuentaId, string $referencia, string $bancoNombre): void
{
    $st = $pdo->prepare("
        SELECT id, TO_CHAR(fecha_movimiento, 'DD/MM/YYYY') AS fecha_fmt
        FROM tesoreria.movimientos_bancarios
        WHERE cuenta_bancaria_id = ? AND UPPER(TRIM(referencia)) = UPPER(?)
        LIMIT 1
    ");
    $st->execute([$cuentaId, $referencia]);
    if ($dup = $st->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception("La referencia «{$referencia}» ya existe en {$bancoNombre} (Movimiento #{$dup['id']} del {$dup['fecha_fmt']}).");
    }
}

function registrarMovimientoBancarioTriggerSafe(
    PDO $pdo,
    int $cuentaId,
    string $tipoMov,
    float $monto,
    string $referencia,
    string $concepto,
    string $usuarioId,
    ?int $detallePagoId
): void {
    $movExistId = 0;
    if ($detallePagoId !== null) {
        $stBus = $pdo->prepare("
            SELECT id FROM tesoreria.movimientos_bancarios
            WHERE detalle_pago_id = ?
               OR (cuenta_bancaria_id = ? AND UPPER(TRIM(referencia)) = UPPER(?))
            ORDER BY id DESC LIMIT 1
        ");
        $stBus->execute([$detallePagoId, $cuentaId, $referencia]);
        $movExistId = (int) ($stBus->fetchColumn() ?: 0);
    }

    $conceptoTrim = mb_substr($concepto, 0, 255, 'UTF-8');

    if ($movExistId > 0) {
        $pdo->prepare("
            UPDATE tesoreria.movimientos_bancarios
            SET tipo_movimiento  = ?,
                monto            = ?,
                concepto         = ?,
                fecha_movimiento = CURRENT_DATE,
                usuario_id       = ?,
                detalle_pago_id  = ?
            WHERE id = ?
        ")->execute([$tipoMov, $monto, $conceptoTrim, $usuarioId, $detallePagoId, $movExistId]);
    } else {
        $pdo->prepare("
            INSERT INTO tesoreria.movimientos_bancarios (
                cuenta_bancaria_id, tipo_movimiento, monto, referencia,
                concepto, fecha_movimiento, usuario_id, detalle_pago_id
            ) VALUES (?, ?, ?, ?, ?, CURRENT_DATE, ?, ?)
        ")->execute([$cuentaId, $tipoMov, $monto, $referencia, $conceptoTrim, $usuarioId, $detallePagoId]);
    }
}

/**
 * Calcula totales fiscales dinámicos (consolidados por producto_maestro_id) a partir de las líneas de consignación.
 */
function calcularResumenFiscalConsignacion(PDO $pdo, array $detalles): array
{
    $porProducto = [];
    $totalDesp = 0;
    $totalDev = 0;
    $totalCons = 0;

    foreach ($detalles as $d) {
        $desp = (int) $d['cantidad_despachada'];
        $dev = (int) $d['cantidad_devuelta'];
        $cons = max(0, $desp - $dev);
        $pmId = (int) $d['producto_maestro_id'];
        $prec = round((float) $d['precio_pactado_unitario'], 2);
        $cost = round((float) ($d['costo_promedio'] ?? 0), 2);

        $totalDesp += $desp;
        $totalDev += $dev;
        $totalCons += $cons;

        if ($cons <= 0) {
            continue;
        }

        $subLinea = round($cons * $prec, 2);
        if (!isset($porProducto[$pmId])) {
            $porProducto[$pmId] = [
                'producto_maestro_id' => $pmId,
                'cantidad_unidades' => $cons,
                'monto_subtotal' => $subLinea,
                'costo_unitario_hist' => $cost,
                'precio_unitario_his' => $prec,
            ];
        } else {
            $porProducto[$pmId]['cantidad_unidades'] += $cons;
            $porProducto[$pmId]['monto_subtotal'] = round($porProducto[$pmId]['monto_subtotal'] + $subLinea, 2);
            $porProducto[$pmId]['precio_unitario_his'] = round(
                $porProducto[$pmId]['monto_subtotal'] / max(1, $porProducto[$pmId]['cantidad_unidades']),
                2
            );
        }
    }

    $subtotalTotal = 0.00;
    $impuestosTotal = 0.00;
    $resumenImpuestos = [];
    $lineasFactura = [];

    foreach ($porProducto as $pmId => $ln) {
        $impCfg = obtenerImpuestosProductoCsg($pdo, $pmId);
        $resImp = calcularImpuestosLineaCsg($impCfg, $ln['monto_subtotal'], $ln['cantidad_unidades']);

        $ln['monto_impuestos'] = $resImp['total_impuestos'];
        $ln['impuestos_detalle'] = $resImp['desglose'];
        $ln['monto_total'] = round($ln['monto_subtotal'] + $resImp['total_impuestos'], 2);

        $subtotalTotal = round($subtotalTotal + $ln['monto_subtotal'], 2);
        $impuestosTotal = round($impuestosTotal + $resImp['total_impuestos'], 2);

        foreach ($resImp['desglose'] as $di) {
            $sig = $di['siglas'];
            if (!isset($resumenImpuestos[$sig])) {
                $resumenImpuestos[$sig] = ['siglas' => $sig, 'nombre' => $di['nombre'], 'monto' => 0.00];
            }
            $resumenImpuestos[$sig]['monto'] = round($resumenImpuestos[$sig]['monto'] + $di['monto_impuesto'], 2);
        }

        $lineasFactura[] = $ln;
    }

    return [
        'botellas_despachadas' => $totalDesp,
        'botellas_devueltas' => $totalDev,
        'botellas_consumidas' => $totalCons,
        'subtotal_consumo' => $subtotalTotal,
        'impuestos_consumo' => $impuestosTotal,
        'total_facturar' => round($subtotalTotal + $impuestosTotal, 2),
        'resumen_impuestos' => array_values($resumenImpuestos),
        'lineas_factura' => $lineasFactura,
    ];
}

/**
 * Detecta el almacén de origen de una consignación (columna almacen_id -> auditoría -> inventario_stock comprometido).
 */
function detectarAlmacenOrigenConsignacion(PDO $pdo, array $csg, array $detalles): int
{
    if (!empty($csg['almacen_id'])) {
        return (int) $csg['almacen_id'];
    }

    try {
        $stAud = $pdo->prepare("
            SELECT (datos_nuevos->>'almacen_id')::int
            FROM seguridad.auditoria
            WHERE accion = 'CREAR_CONSIGNACION'
              AND (datos_nuevos->>'consignacion_id')::int = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stAud->execute([(int) $csg['id']]);
        $almAud = (int) ($stAud->fetchColumn() ?: 0);
        if ($almAud > 0) {
            return $almAud;
        }
    } catch (Throwable $e) {
    }

    if (!empty($detalles)) {
        $d0 = $detalles[0];
        $stStk = $pdo->prepare("
            SELECT almacen_id FROM inventario.inventario_stock
            WHERE producto_maestro_id = ? AND lote_id = ? AND stock_comprometido >= ?
            ORDER BY almacen_id ASC LIMIT 1
        ");
        $stStk->execute([(int) $d0['producto_maestro_id'], (int) $d0['lote_id'], (int) $d0['cantidad_despachada']]);
        $almStk = (int) ($stStk->fetchColumn() ?: 0);
        if ($almStk > 0) {
            return $almStk;
        }
    }

    return (int) ($pdo->query("SELECT id FROM inventario.almacenes WHERE activo = TRUE ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);
}

try {
    if (!$pdo) {
        throw new Exception('No hay conexión activa con PostgreSQL.');
    }

    switch ($accion) {

        // ====================================================================
        // 1. BUSCADOR DE PRODUCTOS CON STOCK DISPONIBLE Y LOTES FEFO
        // ====================================================================
        case 'buscar_productos':
            $termino = trim((string) ($datos['termino'] ?? ''));
            $almacenId = (int) ($datos['almacen_id'] ?? 1);

            $params = [$almacenId];
            $where = ['pm.id > 0'];

            if ($termino !== '') {
                $like = "%{$termino}%";
                $where[] = "(
                    pm.nombre ILIKE ? OR pm.sku ILIKE ?
                    OR EXISTS (
                        SELECT 1 FROM inventario.producto_codigos_barra pcb
                        WHERE pcb.producto_maestro_id = pm.id AND pcb.codigo_barra ILIKE ?
                    )
                )";
                array_push($params, $like, $like, $like);
            }

            $sql = "
                SELECT pm.id, pm.sku,
                       COALESCE(NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'), pm.sku) AS nombre,
                       pm.precio_base_unidad,
                       COALESCE(SUM(ist.stock_fisico_unidades), 0) AS stock_fisico,
                       COALESCE(SUM(ist.stock_comprometido), 0) AS stock_comprometido,
                       COALESCE(SUM(ist.stock_fisico_unidades - ist.stock_comprometido), 0) AS stock_disponible
                FROM inventario.productos_maestro pm
                LEFT JOIN inventario.inventario_stock ist
                       ON ist.producto_maestro_id = pm.id AND ist.almacen_id = ?
                WHERE " . implode(' AND ', $where) . "
                GROUP BY pm.id, pm.sku, pm.nombre, pm.precio_base_unidad
                HAVING COALESCE(SUM(ist.stock_fisico_unidades - ist.stock_comprometido), 0) > 0
                ORDER BY pm.nombre ASC
                LIMIT 40
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $productos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmtLotes = $pdo->prepare("
                SELECT ist.lote_id,
                       CASE WHEN ist.lote_id = 0 THEN 'SIN LOTE (GENÉRICO)' ELSE COALESCE(l.numero_lote, 'LOTE #' || ist.lote_id) END AS numero_lote,
                       l.fecha_vencimiento,
                       ist.stock_fisico_unidades,
                       ist.stock_comprometido,
                       (ist.stock_fisico_unidades - ist.stock_comprometido) AS disponible
                FROM inventario.inventario_stock ist
                JOIN inventario.lotes l ON l.id = ist.lote_id
                WHERE ist.producto_maestro_id = ?
                  AND ist.almacen_id = ?
                  AND (ist.stock_fisico_unidades - ist.stock_comprometido) > 0
                ORDER BY CASE WHEN ist.lote_id = 0 THEN 1 ELSE 0 END, l.fecha_vencimiento ASC
            ");

            foreach ($productos as &$p) {
                $p['id'] = (int) $p['id'];
                $p['precio_base_unidad'] = round((float) $p['precio_base_unidad'], 2);
                $p['stock_fisico'] = (int) $p['stock_fisico'];
                $p['stock_comprometido'] = (int) $p['stock_comprometido'];
                $p['stock_disponible'] = (int) $p['stock_disponible'];

                $stmtLotes->execute([$p['id'], $almacenId]);
                $p['lotes'] = $stmtLotes->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            unset($p);

            responderCsg(true, 'OK', ['data' => $productos]);
            break;

        // ====================================================================
        // 2. FASE 1: CREAR CONSIGNACIÓN, FIANZA Y RESERVAR STOCK COMPROMETIDO
        // ====================================================================
        case 'crear_consignacion':
            $clienteId = trim((string) ($datos['cliente_id'] ?? ''));
            $nombreEvento = trim((string) ($datos['nombre_evento'] ?? ''));
            $responsableEvento = trim((string) ($datos['responsable_evento'] ?? ''));
            $fechaRetorno = trim((string) ($datos['fecha_estimada_retorno'] ?? ''));
            $almacenId = (int) ($datos['almacen_id'] ?? 1);
            $montoGarantia = round((float) ($datos['monto_deposito_garantia'] ?? 0), 2);
            $metodoGarantiaId = (int) ($datos['metodo_garantia_id'] ?? 0);
            $fianzaDatos = is_array($datos['fianza_datos'] ?? null) ? $datos['fianza_datos'] : [];
            $items = is_array($datos['items'] ?? null) ? $datos['items'] : [];
            $supervisorId = trim((string) ($datos['supervisor_id'] ?? ''));
            $pinAutorizacion = trim((string) ($datos['pin_autorizacion'] ?? ''));

            if ($clienteId === '')
                throw new Exception('Debe seleccionar un cliente registrado para la consignación.');
            if ($nombreEvento === '')
                throw new Exception('Debe especificar el nombre del evento.');
            if ($responsableEvento === '')
                throw new Exception('Debe especificar el responsable del evento.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaRetorno) || $fechaRetorno < date('Y-m-d')) {
                throw new Exception('La fecha estimada de retorno debe ser igual o posterior a hoy.');
            }
            if ($montoGarantia < 0)
                throw new Exception('El monto de depósito de garantía no puede ser negativo.');
            if (empty($items))
                throw new Exception('Debe incluir al menos un artículo para el despacho.');

            // Validar cliente real (no Consumidor Final CLI-000)
            $stCli = $pdo->prepare("
                SELECT c.persona_id, c.codigo_cliente, p.nombre_razon_social
                FROM identidad.clientes c
                JOIN identidad.personas p ON p.persona_id = c.persona_id
                WHERE c.persona_id = ? LIMIT 1
            ");
            $stCli->execute([$clienteId]);
            $cliRow = $stCli->fetch(PDO::FETCH_ASSOC);
            if (!$cliRow || $cliRow['codigo_cliente'] === 'CLI-000') {
                throw new Exception('Las consignaciones requieren un cliente formal registrado (no Consumidor Final).');
            }
            $clienteNombre = (string) $cliRow['nombre_razon_social'];

            // Validar PIN del supervisor
            $supValido = validarPinSupervisor($pdo, $supervisorId, $pinAutorizacion);
            $supervisorIdValido = $supValido['usuario_id'];

            // Consolidar ítems por (producto_maestro_id, lote_id) y ordenarlos para evitar deadlocks
            $itemsConsolidados = [];
            foreach ($items as $it) {
                $pmId = (int) ($it['producto_maestro_id'] ?? 0);
                $loteId = (int) ($it['lote_id'] ?? 0);
                $cant = (int) ($it['cantidad_despachada'] ?? 0);
                $precio = round((float) ($it['precio_pactado_unitario'] ?? 0), 2);
                if ($pmId <= 0 || $cant <= 0 || $precio < 0) {
                    throw new Exception('Datos de artículo inválidos en el listado de despacho.');
                }
                $key = $pmId . ':' . $loteId;
                if (!isset($itemsConsolidados[$key])) {
                    $itemsConsolidados[$key] = [
                        'producto_maestro_id' => $pmId,
                        'lote_id' => $loteId,
                        'cantidad_despachada' => $cant,
                        'precio_pactado_unitario' => $precio,
                    ];
                } else {
                    $itemsConsolidados[$key]['cantidad_despachada'] += $cant;
                }
            }
            usort($itemsConsolidados, fn($a, $b) => [$a['producto_maestro_id'], $a['lote_id']] <=> [$b['producto_maestro_id'], $b['lote_id']]);

            // Validar método de fianza si montoGarantia > 0
            $codMetodoFianza = '';
            $ctaFianzaRow = null;
            if ($montoGarantia > 0) {
                $stMp = $pdo->prepare("SELECT id, codigo, descripcion FROM ventas.metodos_pago WHERE id = ? AND activo = TRUE LIMIT 1");
                $stMp->execute([$metodoGarantiaId]);
                $mpRow = $stMp->fetch(PDO::FETCH_ASSOC);
                if (!$mpRow) {
                    throw new Exception('Método de pago de fianza no válido o inactivo.');
                }
                $codMetodoFianza = strtoupper((string) $mpRow['codigo']);
                if (!in_array($codMetodoFianza, ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'CHEQUE'], true)) {
                    throw new Exception("El método '{$codMetodoFianza}' no está permitido para recibir fianza de garantía.");
                }

                if ($codMetodoFianza === 'TRANSFERENCIA') {
                    $ctaId = (int) ($fianzaDatos['cuenta_bancaria_id'] ?? 0);
                    $refTrf = trim((string) ($fianzaDatos['numero_referencia'] ?? ''));
                    if ($ctaId <= 0 || $refTrf === '') {
                        throw new Exception('Para fianza por Transferencia debe seleccionar la cuenta bancaria DOP e indicar el número de referencia.');
                    }
                    $ctaFianzaRow = validarCuentaBancariaDop($pdo, $ctaId);
                    verificarReferenciaBancariaLibre($pdo, $ctaId, $refTrf, $ctaFianzaRow['nombre_banco']);
                } elseif ($codMetodoFianza === 'TARJETA') {
                    $ult4 = preg_replace('/\D/', '', (string) ($fianzaDatos['ultimos_4'] ?? ''));
                    $aut = trim((string) ($fianzaDatos['numero_autorizacion'] ?? ''));
                    if (strlen($ult4) !== 4 || $aut === '') {
                        throw new Exception('Para fianza con Tarjeta debe ingresar los últimos 4 dígitos y el N° de autorización del Verifone.');
                    }
                } elseif ($codMetodoFianza === 'CHEQUE') {
                    $numCh = trim((string) ($fianzaDatos['numero_cheque'] ?? ''));
                    $banCh = trim((string) ($fianzaDatos['banco_emisor'] ?? ''));
                    $ctaId = (int) ($fianzaDatos['cuenta_bancaria_id'] ?? 0);
                    if ($numCh === '' || $banCh === '') {
                        throw new Exception('Para fianza con Cheque debe indicar el banco emisor y el número de cheque.');
                    }
                    if ($ctaId > 0) {
                        $ctaFianzaRow = validarCuentaBancariaDop($pdo, $ctaId);
                        verificarReferenciaBancariaLibre($pdo, $ctaId, $numCh, $ctaFianzaRow['nombre_banco']);
                    }
                }
            }

            $pdo->beginTransaction();

            $sesionCajaId = obtenerSesionCajaAbiertaCsg($pdo, $usuarioId, $personaId, false);
            if ($montoGarantia > 0 && in_array($codMetodoFianza, ['EFECTIVO', 'TARJETA'], true) && !$sesionCajaId) {
                throw new Exception('Para recibir una fianza en Efectivo o Tarjeta debes tener un turno de caja ABIERTO en Control de Caja.');
            }

            // 1. Generar correlativo de consignación
            $seqName = $pdo->query("SELECT pg_get_serial_sequence('ventas.consignaciones', 'id')")->fetchColumn()
                ?: 'ventas.consignaciones_id_seq';
            $seqId = (int) $pdo->query("SELECT nextval('{$seqName}')")->fetchColumn();
            $numConsignacion = 'CSG-' . date('Y') . '-' . str_pad((string) $seqId, 4, '0', STR_PAD_LEFT);

            // 2. Insertar cabecera (guarda almacen_id si la columna existe en el esquema)
            $colsCsg = obtenerColumnasTabla($pdo, 'ventas', 'consignaciones');
            $cCols = [
                'id',
                'numero_consignacion',
                'cliente_id',
                'responsable_evento',
                'nombre_evento',
                'fecha_despacho',
                'fecha_estimada_retorno',
                'monto_deposito_garantia',
                'estado',
                'supervisor_id',
                'sesion_caja_id',
                'moneda_id',
                'tasa_cambio'
            ];
            $cPh = ['?', '?', '?', '?', '?', 'CURRENT_TIMESTAMP', '?', '?', "'DESPACHADA'", '?', '?', '1', '1.0000'];
            $cVals = [
                $seqId,
                $numConsignacion,
                $clienteId,
                $responsableEvento,
                $nombreEvento,
                $fechaRetorno,
                $montoGarantia,
                $supervisorIdValido,
                $sesionCajaId
            ];
            if (isset($colsCsg['almacen_id'])) {
                $cCols[] = 'almacen_id';
                $cPh[] = '?';
                $cVals[] = $almacenId;
            }
            $pdo->prepare("INSERT INTO ventas.consignaciones (" . implode(', ', $cCols) . ") VALUES (" . implode(', ', $cPh) . ")")
                ->execute($cVals);

            // 3. Registrar Fianza / Depósito de Garantía con subtipos 1:1 (sin doble conteo en Arqueo Ciego)
            if ($montoGarantia > 0) {
                // Efectivo se asienta en ventas.movimiento_caja (INGRESO), por eso detalles_pago.sesion_caja_id = NULL
                // Tarjeta se controla por vouchers del turno -> sesion_caja_id = $sesionCajaId
                $sesPagoFianza = ($codMetodoFianza === 'TARJETA') ? $sesionCajaId : null;

                $stDp = $pdo->prepare("
                    INSERT INTO tesoreria.detalles_pago (
                        metodo_pago_id, moneda_id, tasa_cambio, monto_pagado, monto_devuelta, sesion_caja_id
                    ) VALUES (?, 1, 1.0000, ?, 0.00, ?)
                    RETURNING id
                ");
                $stDp->execute([$metodoGarantiaId, $montoGarantia, $sesPagoFianza]);
                $detallePagoId = (int) $stDp->fetchColumn();

                $pdo->prepare("INSERT INTO ventas.consignacion_garantia_pagos (consignacion_id, detalle_pago_id) VALUES (?, ?)")
                    ->execute([$seqId, $detallePagoId]);

                if ($codMetodoFianza === 'EFECTIVO') {
                    $pdo->prepare("
                        INSERT INTO ventas.movimiento_caja (sesion_caja_id, tipo_movimiento, monto, concepto, supervisor_id)
                        VALUES (?, 'INGRESO', ?, ?, ?)
                    ")->execute([
                                $sesionCajaId,
                                $montoGarantia,
                                mb_substr("Fianza garantía consignación {$numConsignacion} ({$nombreEvento})", 0, 255, 'UTF-8'),
                                $supervisorIdValido
                            ]);
                } elseif ($codMetodoFianza === 'TARJETA') {
                    $tipoTj = in_array(strtoupper((string) ($fianzaDatos['tipo_tarjeta'] ?? 'CREDITO')), ['CREDITO', 'DEBITO'], true)
                        ? strtoupper((string) $fianzaDatos['tipo_tarjeta']) : 'CREDITO';
                    $marcaTj = in_array(strtoupper((string) ($fianzaDatos['marca_tarjeta'] ?? 'VISA')), ['VISA', 'MASTERCARD', 'AMEX', 'OTRA'], true)
                        ? strtoupper((string) $fianzaDatos['marca_tarjeta']) : 'VISA';
                    $ult4 = preg_replace('/\D/', '', (string) ($fianzaDatos['ultimos_4'] ?? '0000'));
                    $aut = trim((string) ($fianzaDatos['numero_autorizacion'] ?? ''));

                    $pdo->prepare("
                        INSERT INTO tesoreria.pago_tarjeta (
                            detalle_pago_id, tipo_tarjeta, marca_tarjeta, ultimos_4_digitos, numero_autorizacion, numero_lote
                        ) VALUES (?, ?, ?, ?, ?, NULL)
                    ")->execute([$detallePagoId, $tipoTj, $marcaTj, $ult4, $aut]);
                } elseif ($codMetodoFianza === 'TRANSFERENCIA') {
                    $cuentaId = (int) $ctaFianzaRow['id'];
                    $refTrf = trim((string) $fianzaDatos['numero_referencia']);
                    $banOrig = trim((string) ($fianzaDatos['banco_origen'] ?? '')) ?: 'Banco del Cliente';

                    $pdo->prepare("
                        INSERT INTO tesoreria.pago_transferencia (
                            detalle_pago_id, banco_origen, cuenta_bancaria_id, numero_referencia, fecha_transferencia, titular_origen
                        ) VALUES (?, ?, ?, ?, CURRENT_DATE, ?)
                    ")->execute([$detallePagoId, $banOrig, $cuentaId, $refTrf, $clienteNombre]);

                    registrarMovimientoBancarioTriggerSafe(
                        $pdo,
                        $cuentaId,
                        'TRANSFERENCIA_ENTRADA',
                        $montoGarantia,
                        $refTrf,
                        "Fianza Consignación {$numConsignacion} ({$clienteNombre} • {$nombreEvento})",
                        $usuarioId,
                        $detallePagoId
                    );
                } elseif ($codMetodoFianza === 'CHEQUE') {
                    $numChq = trim((string) $fianzaDatos['numero_cheque']);
                    $banEmis = trim((string) $fianzaDatos['banco_emisor']);
                    $cuentaId = $ctaFianzaRow ? (int) $ctaFianzaRow['id'] : null;

                    $colsCh = obtenerColumnasTabla($pdo, 'tesoreria', 'pago_cheque');
                    $chCols = ['detalle_pago_id', 'numero_cheque', 'banco_emisor', 'cuenta_bancaria_id', 'estado_cheque'];
                    $chVals = [$detallePagoId, $numChq, $banEmis, $cuentaId, 'RECIBIDO'];
                    if (isset($colsCh['titular_cheque'])) {
                        $chCols[] = 'titular_cheque';
                        $chVals[] = $clienteNombre;
                    }
                    if (isset($colsCh['fecha_emision'])) {
                        $chCols[] = 'fecha_emision';
                        $chVals[] = date('Y-m-d');
                    }
                    if (isset($colsCh['fecha_deposito'])) {
                        $chCols[] = 'fecha_deposito';
                        $chVals[] = $cuentaId ? date('Y-m-d') : null;
                    }
                    $phCh = implode(', ', array_fill(0, count($chCols), '?'));
                    $pdo->prepare("INSERT INTO tesoreria.pago_cheque (" . implode(', ', $chCols) . ") VALUES ({$phCh})")
                        ->execute($chVals);

                    if ($cuentaId !== null && $cuentaId > 0) {
                        registrarMovimientoBancarioTriggerSafe(
                            $pdo,
                            $cuentaId,
                            'CHEQUE_ENTRADA',
                            $montoGarantia,
                            $numChq,
                            "Cheque Fianza Consignación {$numConsignacion} ({$clienteNombre})",
                            $usuarioId,
                            $detallePagoId
                        );
                    }
                }
            }

            // 4. Reservar stock en stock_comprometido (sin rebajar físico todavía)
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
            $stmtSumComp = $pdo->prepare("
                UPDATE inventario.inventario_stock
                SET stock_comprometido = stock_comprometido + ?
                WHERE id = ?
            ");

            $totalArticulosDespachados = 0;
            foreach ($itemsConsolidados as $item) {
                $stmtLockStock->execute([$almacenId, $item['producto_maestro_id'], $item['lote_id']]);
                $stockRow = $stmtLockStock->fetch(PDO::FETCH_ASSOC);
                if (!$stockRow) {
                    throw new Exception("No existe inventario para el producto #{$item['producto_maestro_id']} (lote #{$item['lote_id']}) en el almacén seleccionado.");
                }
                $disponible = (int) $stockRow['stock_fisico_unidades'] - (int) $stockRow['stock_comprometido'];
                if ($item['cantidad_despachada'] > $disponible) {
                    throw new Exception("Stock insuficiente para producto #{$item['producto_maestro_id']}. Disponible: {$disponible}, Solicitado: {$item['cantidad_despachada']}.");
                }

                $stmtInsDet->execute([
                    $seqId,
                    $item['producto_maestro_id'],
                    $item['lote_id'],
                    $item['cantidad_despachada'],
                    $item['precio_pactado_unitario']
                ]);
                $stmtSumComp->execute([$item['cantidad_despachada'], (int) $stockRow['id']]);
                $totalArticulosDespachados += $item['cantidad_despachada'];
            }

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
                    'metodo_fianza' => $codMetodoFianza ?: 'SIN_FIANZA',
                    'total_articulos_despachados' => $totalArticulosDespachados,
                    'almacen_id' => $almacenId
                ]
            );

            $pdo->commit();

            responderCsg(true, "Consignación {$numConsignacion} despachada y stock comprometido exitosamente.", [
                'consignacion_id' => $seqId,
                'numero_consignacion' => $numConsignacion,
            ]);
            break;

        // ====================================================================
        // 3. CONSULTAR DETALLE COMPLETO + RESUMEN FISCAL DINÁMICO (ITBIS/ISC)
        // ====================================================================
        case 'obtener_detalle_consignacion':
            $csgId = (int) ($datos['id'] ?? 0);
            if ($csgId <= 0) {
                throw new Exception('ID de consignación inválido.');
            }

            $stmtCsg = $pdo->prepare("
                SELECT c.*,
                       p.nombre_razon_social AS cliente_nombre,
                       p.documento_identidad AS cliente_rnc,
                       p.tipo_documento AS cliente_tipo_doc,
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

            $stmtDet = $pdo->prepare("
                SELECT cd.producto_maestro_id, cd.lote_id,
                       cd.cantidad_despachada, cd.cantidad_devuelta, cd.cantidad_consumida,
                       cd.precio_pactado_unitario,
                       (cd.cantidad_consumida * cd.precio_pactado_unitario) AS subtotal_consumido,
                       (cd.cantidad_despachada * cd.precio_pactado_unitario) AS subtotal_despachado,
                       pm.sku, pm.costo_promedio,
                       COALESCE(NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'), pm.sku) AS producto_nombre,
                       CASE WHEN cd.lote_id = 0 THEN 'SIN LOTE (GENÉRICO)' ELSE COALESCE(l.numero_lote, 'LOTE #' || cd.lote_id) END AS numero_lote,
                       l.fecha_vencimiento
                FROM ventas.consignaciones_detalle cd
                JOIN inventario.productos_maestro pm ON pm.id = cd.producto_maestro_id
                LEFT JOIN inventario.lotes l ON l.id = cd.lote_id
                WHERE cd.consignacion_id = ?
                ORDER BY pm.nombre ASC, cd.lote_id ASC
            ");
            $stmtDet->execute([$csgId]);
            $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $resumenFiscal = calcularResumenFiscalConsignacion($pdo, $detalles);
            $almacenOrigenId = detectarAlmacenOrigenConsignacion($pdo, $consignacion, $detalles);

            $stmtFac = $pdo->prepare("
                SELECT f.id, f.numero_factura, f.tipo_ncf, f.ncf, f.monto_subtotal, f.monto_impuestos, f.monto_total, f.fecha_emision, f.estado
                FROM ventas.consignacion_facturas cf
                JOIN ventas.facturas f ON f.id = cf.factura_id
                WHERE cf.consignacion_id = ? LIMIT 1
            ");
            $stmtFac->execute([$csgId]);
            $factura = $stmtFac->fetch(PDO::FETCH_ASSOC) ?: null;

            $stmtPagos = $pdo->prepare("
                SELECT dp.id, dp.monto_pagado, dp.fecha_pago, mp.codigo AS metodo_codigo, mp.descripcion AS metodo_nombre
                FROM ventas.consignacion_garantia_pagos cgp
                JOIN tesoreria.detalles_pago dp ON dp.id = cgp.detalle_pago_id
                JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id
                WHERE cgp.consignacion_id = ?
            ");
            $stmtPagos->execute([$csgId]);
            $pagosGarantia = $stmtPagos->fetchAll(PDO::FETCH_ASSOC) ?: [];

            responderCsg(true, 'OK', [
                'data' => [
                    'consignacion' => $consignacion,
                    'detalles' => $detalles,
                    'resumen_fiscal' => $resumenFiscal,
                    'almacen_origen_id' => $almacenOrigenId,
                    'factura' => $factura,
                    'pagos_garantia' => $pagosGarantia,
                ],
            ]);
            break;

        // ====================================================================
        // 4. FASE 2: REGISTRAR RETORNO POST-EVENTO (CONTEO FÍSICO)
        // ====================================================================
        case 'registrar_retorno':
            $csgId = (int) ($datos['consignacion_id'] ?? 0);
            $retornos = is_array($datos['retornos'] ?? null) ? $datos['retornos'] : [];
            $supervisorId = trim((string) ($datos['supervisor_id'] ?? ''));
            $pinAutorizacion = trim((string) ($datos['pin_autorizacion'] ?? ''));

            if ($csgId <= 0 || empty($retornos)) {
                throw new Exception('Debe especificar la consignación y las cantidades de retorno.');
            }

            $supValido = validarPinSupervisor($pdo, $supervisorId, $pinAutorizacion);
            $supervisorIdValido = $supValido['usuario_id'];

            $pdo->beginTransaction();

            $stmtLock = $pdo->prepare("SELECT id, estado, numero_consignacion FROM ventas.consignaciones WHERE id = ? FOR UPDATE");
            $stmtLock->execute([$csgId]);
            $csg = $stmtLock->fetch(PDO::FETCH_ASSOC);

            if (!$csg)
                throw new Exception("Consignación #{$csgId} no encontrada.");
            if ($csg['estado'] !== 'DESPACHADA') {
                throw new Exception("Solo se pueden registrar retornos en consignaciones DESPACHADAS (Estado actual: {$csg['estado']}).");
            }

            $stmtUpdRet = $pdo->prepare("
                UPDATE ventas.consignaciones_detalle
                SET cantidad_devuelta = ?
                WHERE consignacion_id = ? AND producto_maestro_id = ? AND lote_id = ?
            ");
            $stmtGetDet = $pdo->prepare("
                SELECT cantidad_despachada FROM ventas.consignaciones_detalle
                WHERE consignacion_id = ? AND producto_maestro_id = ? AND lote_id = ?
            ");

            $cambiosRetorno = [];
            foreach ($retornos as $ret) {
                $prodId = (int) ($ret['producto_maestro_id'] ?? 0);
                $loteId = (int) ($ret['lote_id'] ?? 0);
                $cantDev = (int) ($ret['cantidad_devuelta'] ?? 0);

                if ($cantDev < 0)
                    throw new Exception('La cantidad devuelta no puede ser negativa.');

                $stmtGetDet->execute([$csgId, $prodId, $loteId]);
                $cantDesp = $stmtGetDet->fetchColumn();
                if ($cantDesp === false) {
                    throw new Exception("El producto #{$prodId} (lote #{$loteId}) no pertenece a esta consignación.");
                }
                if ($cantDev > (int) $cantDesp) {
                    throw new Exception("La cantidad devuelta ({$cantDev}) no puede superar lo despachado ({$cantDesp}) en producto #{$prodId}.");
                }

                $stmtUpdRet->execute([$cantDev, $csgId, $prodId, $loteId]);
                $cambiosRetorno[] = [
                    'producto_maestro_id' => $prodId,
                    'lote_id' => $loteId,
                    'cantidad_devuelta' => $cantDev,
                    'cantidad_consumida' => ((int) $cantDesp - $cantDev),
                ];
            }

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
                    'detalles_retorno' => $cambiosRetorno,
                ]
            );

            $pdo->commit();

            responderCsg(true, 'Conteo físico y retorno de mercancía asentados correctamente.');
            break;

        // ====================================================================
        // 5. FASE 3: LIQUIDACIÓN FINANCIERA, FACTURA FISCAL (CON IMPUESTOS) Y SPLIT
        // ====================================================================
        case 'liquidar_consignacion':
            $csgId = (int) ($datos['consignacion_id'] ?? 0);
            $tipoNcf = strtoupper(trim((string) ($datos['tipo_ncf'] ?? 'B02')));
            $almacenIdIn = (int) ($datos['almacen_id'] ?? 0);
            $pagosDiferencia = is_array($datos['pagos_diferencia'] ?? null) ? $datos['pagos_diferencia'] : [];
            $reembolsoModo = strtoupper(trim((string) ($datos['reembolso_metodo'] ?? 'EFECTIVO')));
            $reembolsoCtaId = (int) ($datos['reembolso_cuenta_id'] ?? 0);
            $reembolsoRef = trim((string) ($datos['reembolso_referencia'] ?? ''));
            $supervisorId = trim((string) ($datos['supervisor_id'] ?? ''));
            $pinAutorizacion = trim((string) ($datos['pin_autorizacion'] ?? ''));

            if ($csgId <= 0)
                throw new Exception('ID de consignación inválido.');
            if (!in_array($tipoNcf, ['B01', 'B02'], true))
                $tipoNcf = 'B02';

            $supValido = validarPinSupervisor($pdo, $supervisorId, $pinAutorizacion);
            $supervisorIdValido = $supValido['usuario_id'];

            $pdo->beginTransaction();

            // 1. Bloquear consignación
            $stmtLock = $pdo->prepare("
                SELECT c.*, p.documento_identidad, p.nombre_razon_social
                FROM ventas.consignaciones c
                JOIN identidad.clientes cl ON cl.persona_id = c.cliente_id
                JOIN identidad.personas p ON p.persona_id = cl.persona_id
                WHERE c.id = ?
                FOR UPDATE OF c
            ");
            $stmtLock->execute([$csgId]);
            $csg = $stmtLock->fetch(PDO::FETCH_ASSOC);

            if (!$csg)
                throw new Exception("Consignación #{$csgId} no encontrada.");
            if ($csg['estado'] !== 'DESPACHADA') {
                throw new Exception("Esta consignación ya fue liquidada o cancelada (Estado: {$csg['estado']}).");
            }

            $montoFianza = round((float) $csg['monto_deposito_garantia'], 2);
            $clienteId = $csg['cliente_id'];
            $clienteNombre = trim((string) ($csg['nombre_razon_social'] ?: 'Cliente Consignación'));
            $clienteDoc = trim((string) ($csg['documento_identidad'] ?? ''));
            $numConsignacion = $csg['numero_consignacion'];

            // 2. Obtener detalles y calcular impuestos dinámicos (ITBIS / ISC)
            $stmtDet = $pdo->prepare("
                SELECT cd.producto_maestro_id, cd.lote_id,
                       cd.cantidad_despachada, cd.cantidad_devuelta, cd.cantidad_consumida,
                       cd.precio_pactado_unitario,
                       pm.sku, pm.costo_promedio,
                       COALESCE(NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'), pm.sku) AS producto_nombre
                FROM ventas.consignaciones_detalle cd
                JOIN inventario.productos_maestro pm ON pm.id = cd.producto_maestro_id
                WHERE cd.consignacion_id = ?
                ORDER BY cd.producto_maestro_id ASC, cd.lote_id ASC
            ");
            $stmtDet->execute([$csgId]);
            $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (empty($detalles)) {
                throw new Exception('La consignación no posee artículos registrados.');
            }

            $almacenOrigenId = ($almacenIdIn > 0) ? $almacenIdIn : detectarAlmacenOrigenConsignacion($pdo, $csg, $detalles);
            $fiscal = calcularResumenFiscalConsignacion($pdo, $detalles);

            $subtotalConsumo = $fiscal['subtotal_consumo'];
            $impuestosConsumo = $fiscal['impuestos_consumo'];
            $totalFacturar = $fiscal['total_facturar'];

            if ($totalFacturar > 0 && $tipoNcf === 'B01' && $clienteDoc === '') {
                throw new Exception('El comprobante B01 exige que el cliente posea RNC o Cédula registrada.');
            }

            // 3. Validar Split de diferencia si totalFacturar > montoFianza
            $diferenciaPagar = round(max(0.0, $totalFacturar - $montoFianza), 2);
            $excedenteFianza = round(max(0.0, $montoFianza - $totalFacturar), 2);
            $pagosValidos = [];
            $requiereCaja = false;

            if ($diferenciaPagar > 0) {
                $stMet = $pdo->prepare("SELECT id, codigo, descripcion FROM ventas.metodos_pago WHERE id = ? AND activo = TRUE LIMIT 1");
                $refsUsadas = [];
                $totalCubierto = 0.00;

                foreach ($pagosDiferencia as $idxP => $pDif) {
                    $numP = $idxP + 1;
                    $metodoId = (int) ($pDif['metodo_pago_id'] ?? 0);
                    $montoP = round((float) ($pDif['monto'] ?? 0), 2);
                    $devuelta = round(max(0.0, (float) ($pDif['monto_devuelta'] ?? 0)), 2);
                    if ($montoP <= 0)
                        continue;

                    $stMet->execute([$metodoId]);
                    $mpRow = $stMet->fetch(PDO::FETCH_ASSOC);
                    if (!$mpRow)
                        throw new Exception("Pago diferencia #{$numP}: método de pago no válido.");
                    $cod = strtoupper((string) $mpRow['codigo']);
                    if (!in_array($cod, ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'CHEQUE'], true)) {
                        throw new Exception("Método '{$cod}' no permitido para pagar diferencia de consignación.");
                    }

                    if ($cod !== 'EFECTIVO') {
                        $devuelta = 0.00;
                    } elseif ($devuelta >= $montoP) {
                        throw new Exception("Pago #{$numP} (Efectivo): la devuelta no puede ser mayor o igual al monto entregado.");
                    }

                    $neto = round($montoP - $devuelta, 2);
                    if (in_array($cod, ['EFECTIVO', 'TARJETA'], true)) {
                        $requiereCaja = true;
                    }

                    $pgItem = [
                        'metodo_id' => $metodoId,
                        'codigo' => $cod,
                        'monto' => $montoP,
                        'devuelta' => $devuelta,
                        'neto' => $neto,
                        'cuenta' => null,
                        'referencia' => '',
                        'banco_origen' => trim((string) ($pDif['banco_origen'] ?? '')) ?: 'Banco del Cliente',
                        'num_cheque' => '',
                        'banco_emisor' => trim((string) ($pDif['banco_emisor'] ?? '')),
                        'tipo_tarjeta' => 'CREDITO',
                        'marca_tarjeta' => 'VISA',
                        'ultimos_4' => '',
                        'autorizacion' => '',
                        'lote' => trim((string) ($pDif['numero_lote'] ?? '')) ?: null,
                    ];

                    if ($cod === 'TARJETA') {
                        $ult4 = preg_replace('/\D/', '', (string) ($pDif['ultimos_4'] ?? $pDif['ultimos_cuatro'] ?? ''));
                        $aut = trim((string) ($pDif['numero_autorizacion'] ?? ''));
                        if (strlen($ult4) !== 4 || $aut === '') {
                            throw new Exception("Pago #{$numP} (Tarjeta): ingrese los últimos 4 dígitos y el N° de autorización.");
                        }
                        $tipoTj = strtoupper(trim((string) ($pDif['tipo_tarjeta'] ?? 'CREDITO')));
                        $marcaTj = strtoupper(trim((string) ($pDif['marca_tarjeta'] ?? 'VISA')));
                        $pgItem['tipo_tarjeta'] = in_array($tipoTj, ['CREDITO', 'DEBITO'], true) ? $tipoTj : 'CREDITO';
                        $pgItem['marca_tarjeta'] = in_array($marcaTj, ['VISA', 'MASTERCARD', 'AMEX', 'OTRA'], true) ? $marcaTj : 'OTRA';
                        $pgItem['ultimos_4'] = $ult4;
                        $pgItem['autorizacion'] = $aut;
                    } elseif ($cod === 'TRANSFERENCIA') {
                        $ctaId = (int) ($pDif['cuenta_bancaria_id'] ?? 0);
                        $refTrf = trim((string) ($pDif['numero_referencia'] ?? ''));
                        if ($ctaId <= 0 || $refTrf === '') {
                            throw new Exception("Pago #{$numP} (Transferencia): seleccione cuenta bancaria DOP y número de referencia.");
                        }
                        $ctaRow = validarCuentaBancariaDop($pdo, $ctaId);
                        $claveR = $ctaId . '|' . strtoupper($refTrf);
                        if (in_array($claveR, $refsUsadas, true)) {
                            throw new Exception("La referencia «{$refTrf}» está repetida en dos líneas del pago.");
                        }
                        $refsUsadas[] = $claveR;
                        verificarReferenciaBancariaLibre($pdo, $ctaId, $refTrf, $ctaRow['nombre_banco']);
                        $pgItem['cuenta'] = $ctaRow;
                        $pgItem['referencia'] = $refTrf;
                    } elseif ($cod === 'CHEQUE') {
                        $numCh = trim((string) ($pDif['numero_cheque'] ?? $pDif['numero_referencia'] ?? ''));
                        $banCh = trim((string) ($pDif['banco_emisor'] ?? ''));
                        $ctaId = (int) ($pDif['cuenta_bancaria_id'] ?? 0);
                        if ($numCh === '' || $banCh === '') {
                            throw new Exception("Pago #{$numP} (Cheque): indique banco emisor y número de cheque.");
                        }
                        if ($ctaId > 0) {
                            $ctaRow = validarCuentaBancariaDop($pdo, $ctaId);
                            $claveR = $ctaId . '|' . strtoupper($numCh);
                            if (in_array($claveR, $refsUsadas, true)) {
                                throw new Exception("El cheque «{$numCh}» está repetido en el pago.");
                            }
                            $refsUsadas[] = $claveR;
                            verificarReferenciaBancariaLibre($pdo, $ctaId, $numCh, $ctaRow['nombre_banco']);
                            $pgItem['cuenta'] = $ctaRow;
                        }
                        $pgItem['num_cheque'] = $numCh;
                        $pgItem['banco_emisor'] = $banCh;
                    }

                    $totalCubierto = round($totalCubierto + $neto, 2);
                    $pagosValidos[] = $pgItem;
                }

                // Consolidar múltiples líneas de EFECTIVO en una sola
                $consolidados = [];
                $idxEf = null;
                foreach ($pagosValidos as $pg) {
                    if ($pg['codigo'] === 'EFECTIVO') {
                        if ($idxEf === null) {
                            $idxEf = count($consolidados);
                            $consolidados[] = $pg;
                        } else {
                            $consolidados[$idxEf]['monto'] = round($consolidados[$idxEf]['monto'] + $pg['monto'], 2);
                            $consolidados[$idxEf]['devuelta'] = round($consolidados[$idxEf]['devuelta'] + $pg['devuelta'], 2);
                            $consolidados[$idxEf]['neto'] = round($consolidados[$idxEf]['neto'] + $pg['neto'], 2);
                        }
                    } else {
                        $consolidados[] = $pg;
                    }
                }
                $pagosValidos = $consolidados;

                if (abs($totalCubierto - $diferenciaPagar) > 0.02) {
                    throw new Exception(
                        'El total neto en métodos de pago (RD$ ' . number_format($totalCubierto, 2) .
                        ') debe cuadrar exactamente con la diferencia a cobrar (RD$ ' . number_format($diferenciaPagar, 2) . ').'
                    );
                }
            }

            // Validar reembolso de excedente de fianza
            $ctaReembolso = null;
            if ($excedenteFianza > 0) {
                if ($reembolsoModo === 'TRANSFERENCIA') {
                    if ($reembolsoCtaId <= 0 || $reembolsoRef === '') {
                        throw new Exception('Para reembolsar el excedente por Transferencia Bancaria debe elegir la cuenta DOP y la referencia.');
                    }
                    $ctaReembolso = validarCuentaBancariaDop($pdo, $reembolsoCtaId);
                    verificarReferenciaBancariaLibre($pdo, $reembolsoCtaId, $reembolsoRef, $ctaReembolso['nombre_banco']);
                } else {
                    $reembolsoModo = 'EFECTIVO';
                    $requiereCaja = true;
                }
            }

            // Verificar sesión de caja abierta
            $sesionCajaId = obtenerSesionCajaAbiertaCsg($pdo, $usuarioId, $personaId, $requiereCaja);
            $facSesNullable = columnaEsNullable($pdo, 'ventas', 'facturas', 'sesion_caja_id');

            if ($requiereCaja && !$sesionCajaId) {
                throw new Exception('Para cobros o reembolsos en Efectivo/Tarjeta debes tener una sesión de caja ABIERTA en Control de Caja.');
            }
            if ($totalFacturar > 0 && !$sesionCajaId && !$facSesNullable) {
                throw new Exception('Debes tener una sesión de caja ABIERTA en Control de Caja para emitir la factura fiscal de liquidación.');
            }

            $facturaIdGenerada = null;
            $numeroFacturaGenerada = null;
            $ncfGenerado = null;

            // 4. Emisión de Factura Fiscal si hubo consumo
            if ($totalFacturar > 0) {
                $stmtSec = $pdo->prepare("
                    SELECT id, secuencia_actual, secuencia_hasta, fecha_vencimiento
                    FROM ventas.secuencias_fiscales_dgii
                    WHERE tipo_comprobante = ? AND estado = 'ACTIVO'
                    ORDER BY id ASC LIMIT 1 FOR UPDATE
                ");
                $stmtSec->execute([$tipoNcf]);
                $secRow = $stmtSec->fetch(PDO::FETCH_ASSOC);

                if (!$secRow) {
                    throw new Exception("No existe una secuencia fiscal DGII activa para {$tipoNcf}. Configúrala en Secuencias Fiscales.");
                }
                if ($secRow['fecha_vencimiento'] < date('Y-m-d')) {
                    $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'VENCIDO' WHERE id = ?")->execute([$secRow['id']]);
                    throw new Exception("La secuencia fiscal {$tipoNcf} venció el {$secRow['fecha_vencimiento']}.");
                }

                $secuenciaDgiiId = (int) $secRow['id'];
                $secActual = (int) $secRow['secuencia_actual'];
                $secHasta = (int) $secRow['secuencia_hasta'];

                if ($secActual > $secHasta) {
                    $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'AGOTADO' WHERE id = ?")->execute([$secuenciaDgiiId]);
                    throw new Exception("La secuencia fiscal {$tipoNcf} está agotada.");
                }

                $ncfGenerado = $tipoNcf . str_pad((string) $secActual, 8, '0', STR_PAD_LEFT);
                if ($secActual < $secHasta) {
                    $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET secuencia_actual = secuencia_actual + 1 WHERE id = ?")->execute([$secuenciaDgiiId]);
                } else {
                    $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'AGOTADO' WHERE id = ?")->execute([$secuenciaDgiiId]);
                }

                $facSeq = $pdo->query("SELECT pg_get_serial_sequence('ventas.facturas', 'id')")->fetchColumn() ?: 'ventas.facturas_id_seq';
                $facturaIdGenerada = (int) $pdo->query("SELECT nextval('{$facSeq}')")->fetchColumn();
                $numeroFacturaGenerada = 'FAC-' . date('Ymd') . '-' . str_pad((string) $facturaIdGenerada, 5, '0', STR_PAD_LEFT);

                $pdo->prepare("
                    INSERT INTO ventas.facturas (
                        id, numero_factura, sesion_caja_id, cliente_id, vendedor_id,
                        secuencia_dgii_id, tipo_ncf, ncf, condicion_pago,
                        monto_subtotal, monto_descuento, monto_impuestos, monto_total,
                        estado, fecha_emision, origen_venta, rnc_cedula_cliente, razon_social_cliente,
                        moneda_id, tasa_cambio
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, 'CONTADO',
                        ?, 0.00, ?, ?,
                        'PAGADA', CURRENT_TIMESTAMP, 'CONSIGNACION', ?, ?,
                        1, 1.0000
                    )
                ")->execute([
                            $facturaIdGenerada,
                            $numeroFacturaGenerada,
                            $sesionCajaId,
                            $clienteId,
                            $usuarioId,
                            $secuenciaDgiiId,
                            $tipoNcf,
                            $ncfGenerado,
                            $subtotalConsumo,
                            $impuestosConsumo,
                            $totalFacturar,
                            $clienteDoc !== '' ? $clienteDoc : null,
                            $clienteNombre
                        ]);

                // Insertar factura_detalle y factura_detalle_impuestos (consolidados por producto_maestro_id)
                $stFacDet = $pdo->prepare("
                    INSERT INTO ventas.factura_detalle (
                        factura_id, producto_maestro_id, cantidad_unidades, precio_unitario_his,
                        codigo_barra_id, factor_conversion, cantidad_presentacion, costo_unitario_hist,
                        promocion_aplicada, monto_descuento, monto_impuestos, monto_subtotal
                    ) VALUES (?, ?, ?, ?, NULL, 1, ?, ?, NULL, 0.00, ?, ?)
                ");
                $stFacDetImp = $pdo->prepare("
                    INSERT INTO ventas.factura_detalle_impuestos (
                        factura_id, producto_maestro_id, impuesto_id, base_imponible,
                        tipo_calculo_aplicado, tasa_aplicada, monto_impuesto
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($fiscal['lineas_factura'] as $ln) {
                    $stFacDet->execute([
                        $facturaIdGenerada,
                        $ln['producto_maestro_id'],
                        $ln['cantidad_unidades'],
                        $ln['precio_unitario_his'],
                        $ln['cantidad_unidades'],
                        $ln['costo_unitario_hist'],
                        $ln['monto_impuestos'],
                        $ln['monto_subtotal']
                    ]);

                    foreach ($ln['impuestos_detalle'] as $di) {
                        $stFacDetImp->execute([
                            $facturaIdGenerada,
                            $ln['producto_maestro_id'],
                            $di['impuesto_id'],
                            $di['base_imponible'],
                            $di['tipo_calculo_aplicado'],
                            $di['tasa_aplicada'],
                            $di['monto_impuesto']
                        ]);
                    }
                }

                $pdo->prepare("INSERT INTO ventas.consignacion_facturas (consignacion_id, factura_id) VALUES (?, ?)")
                    ->execute([$csgId, $facturaIdGenerada]);

                // 5. Aplicar Fianza como SALDO_FAVOR (sesion_caja_id = NULL)
                $fianzaAplicada = round(min($totalFacturar, $montoFianza), 2);
                if ($fianzaAplicada > 0) {
                    $metodoSaldoFavorId = (int) ($pdo->query("SELECT id FROM ventas.metodos_pago WHERE UPPER(codigo) = 'SALDO_FAVOR' LIMIT 1")->fetchColumn() ?: 5);

                    $stDpF = $pdo->prepare("
                        INSERT INTO tesoreria.detalles_pago (
                            metodo_pago_id, moneda_id, tasa_cambio, monto_pagado, monto_devuelta, sesion_caja_id
                        ) VALUES (?, 1, 1.0000, ?, 0.00, NULL)
                        RETURNING id
                    ");
                    $stDpF->execute([$metodoSaldoFavorId, $fianzaAplicada]);
                    $detFianzaId = (int) $stDpF->fetchColumn();

                    $pdo->prepare("
                        INSERT INTO tesoreria.pago_saldo_favor (detalle_pago_id, cliente_id, origen_saldo, documento_origen_id)
                        VALUES (?, ?, 'CONSIGNACION_GARANTIA', ?)
                    ")->execute([$detFianzaId, $clienteId, $csgId]);

                    $pdo->prepare("INSERT INTO ventas.factura_pagos (factura_id, detalle_pago_id) VALUES (?, ?)")
                        ->execute([$facturaIdGenerada, $detFianzaId]);
                }

                // 6. Registrar pagos de diferencia (Split Universal con subtipos correctos y trigger-safe)
                $colsCh = obtenerColumnasTabla($pdo, 'tesoreria', 'pago_cheque');
                foreach ($pagosValidos as $pg) {
                    $sesPagoDif = in_array($pg['codigo'], ['EFECTIVO', 'TARJETA'], true) ? $sesionCajaId : null;
                    $stInsDif = $pdo->prepare("
                        INSERT INTO tesoreria.detalles_pago (
                            metodo_pago_id, moneda_id, tasa_cambio, monto_pagado, monto_devuelta, sesion_caja_id
                        ) VALUES (?, 1, 1.0000, ?, ?, ?)
                        RETURNING id
                    ");
                    $stInsDif->execute([$pg['metodo_id'], $pg['monto'], $pg['devuelta'], $sesPagoDif]);
                    $detDifId = (int) $stInsDif->fetchColumn();

                    $pdo->prepare("INSERT INTO ventas.factura_pagos (factura_id, detalle_pago_id) VALUES (?, ?)")
                        ->execute([$facturaIdGenerada, $detDifId]);

                    if ($pg['codigo'] === 'TARJETA') {
                        $pdo->prepare("
                            INSERT INTO tesoreria.pago_tarjeta (
                                detalle_pago_id, tipo_tarjeta, marca_tarjeta, ultimos_4_digitos, numero_autorizacion, numero_lote
                            ) VALUES (?, ?, ?, ?, ?, ?)
                        ")->execute([
                                    $detDifId,
                                    $pg['tipo_tarjeta'],
                                    $pg['marca_tarjeta'],
                                    $pg['ultimos_4'],
                                    $pg['autorizacion'],
                                    $pg['lote']
                                ]);
                    } elseif ($pg['codigo'] === 'TRANSFERENCIA') {
                        $ctaId = (int) $pg['cuenta']['id'];
                        $pdo->prepare("
                            INSERT INTO tesoreria.pago_transferencia (
                                detalle_pago_id, banco_origen, cuenta_bancaria_id, numero_referencia, fecha_transferencia, titular_origen
                            ) VALUES (?, ?, ?, ?, CURRENT_DATE, ?)
                        ")->execute([$detDifId, $pg['banco_origen'], $ctaId, $pg['referencia'], $clienteNombre]);

                        registrarMovimientoBancarioTriggerSafe(
                            $pdo,
                            $ctaId,
                            'TRANSFERENCIA_ENTRADA',
                            $pg['neto'],
                            $pg['referencia'],
                            "Liquidación Consignación {$numConsignacion} • {$numeroFacturaGenerada} ({$clienteNombre})",
                            $usuarioId,
                            $detDifId
                        );
                    } elseif ($pg['codigo'] === 'CHEQUE') {
                        $ctaId = $pg['cuenta'] ? (int) $pg['cuenta']['id'] : null;
                        $chCols = ['detalle_pago_id', 'numero_cheque', 'banco_emisor', 'cuenta_bancaria_id', 'estado_cheque'];
                        $chVals = [$detDifId, $pg['num_cheque'], $pg['banco_emisor'], $ctaId, 'RECIBIDO'];
                        if (isset($colsCh['titular_cheque'])) {
                            $chCols[] = 'titular_cheque';
                            $chVals[] = $clienteNombre;
                        }
                        if (isset($colsCh['fecha_emision'])) {
                            $chCols[] = 'fecha_emision';
                            $chVals[] = date('Y-m-d');
                        }
                        if (isset($colsCh['fecha_deposito'])) {
                            $chCols[] = 'fecha_deposito';
                            $chVals[] = $ctaId ? date('Y-m-d') : null;
                        }
                        $phCh = implode(', ', array_fill(0, count($chCols), '?'));
                        $pdo->prepare("INSERT INTO tesoreria.pago_cheque (" . implode(', ', $chCols) . ") VALUES ({$phCh})")
                            ->execute($chVals);

                        if ($ctaId !== null && $ctaId > 0) {
                            registrarMovimientoBancarioTriggerSafe(
                                $pdo,
                                $ctaId,
                                'CHEQUE_ENTRADA',
                                $pg['neto'],
                                $pg['num_cheque'],
                                "Cheque Liquidación Consignación {$numConsignacion} • {$numeroFacturaGenerada}",
                                $usuarioId,
                                $detDifId
                            );
                        }
                    }
                }

                // 7. Kardex SALIDA_VENTA (con almacen_id y usuario_id si existen)
                $colsMov = obtenerColumnasTabla($pdo, 'inventario', 'movimientos_inventario');
                $mCols = ['tipo_movimiento', 'justificacion', 'supervisor_id', 'documento_referencia_id'];
                $mVals = ['SALIDA_VENTA', "Salida por liquidación consignación {$numConsignacion} ({$numeroFacturaGenerada})", $supervisorIdValido, $facturaIdGenerada];
                if (isset($colsMov['almacen_id'])) {
                    $mCols[] = 'almacen_id';
                    $mVals[] = $almacenOrigenId;
                }
                if (isset($colsMov['usuario_id'])) {
                    $mCols[] = 'usuario_id';
                    $mVals[] = $usuarioId;
                }
                $phM = implode(', ', array_fill(0, count($mCols), '?'));
                $stKCab = $pdo->prepare("INSERT INTO inventario.movimientos_inventario (" . implode(', ', $mCols) . ") VALUES ({$phM}) RETURNING id");
                $stKCab->execute($mVals);
                $kardexId = (int) $stKCab->fetchColumn();

                $stKDet = $pdo->prepare("
                    INSERT INTO inventario.movimiento_inventario_detalles (
                        movimiento_id, producto_maestro_id, lote_id, cantidad_unidades, costo_unitario_referencia
                    ) VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($detalles as $d) {
                    $cons = max(0, (int) $d['cantidad_despachada'] - (int) $d['cantidad_devuelta']);
                    if ($cons > 0) {
                        $stKDet->execute([
                            $kardexId,
                            (int) $d['producto_maestro_id'],
                            (int) $d['lote_id'],
                            $cons,
                            round((float) $d['costo_promedio'], 2)
                        ]);
                    }
                }
            }

            // 8. Reembolso de excedente de fianza (tanto si hubo consumo menor como si consumo = 0)
            if ($excedenteFianza > 0) {
                $conceptoReem = ($totalFacturar > 0)
                    ? "Devolución excedente fianza consignación {$numConsignacion} ({$clienteNombre})"
                    : "Reembolso total fianza consignación sin consumo {$numConsignacion} ({$clienteNombre})";

                if ($reembolsoModo === 'TRANSFERENCIA' && $ctaReembolso) {
                    registrarMovimientoBancarioTriggerSafe(
                        $pdo,
                        (int) $ctaReembolso['id'],
                        'TRANSFERENCIA_SALIDA',
                        $excedenteFianza,
                        $reembolsoRef,
                        $conceptoReem,
                        $usuarioId,
                        null
                    );
                } else {
                    $pdo->prepare("
                        INSERT INTO ventas.movimiento_caja (sesion_caja_id, tipo_movimiento, monto, concepto, supervisor_id)
                        VALUES (?, 'EGRESO', ?, ?, ?)
                    ")->execute([$sesionCajaId, $excedenteFianza, mb_substr($conceptoReem, 0, 255, 'UTF-8'), $supervisorIdValido]);
                }
            }

            // 9. Descarga y liberación segura de inventario_stock + lotes (detecta el almacén exacto con stock_comprometido)
            $stLockAlm = $pdo->prepare("
                SELECT id, almacen_id, stock_fisico_unidades, stock_comprometido
                FROM inventario.inventario_stock
                WHERE producto_maestro_id = ? AND lote_id = ?
                  AND stock_comprometido >= ?
                ORDER BY CASE WHEN almacen_id = ? THEN 0 ELSE 1 END, id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stUpdStock = $pdo->prepare("
                UPDATE inventario.inventario_stock
                SET stock_fisico_unidades = stock_fisico_unidades - ?,
                    stock_comprometido    = stock_comprometido - ?
                WHERE id = ?
            ");
            $stUpdLote = $pdo->prepare("
                UPDATE inventario.lotes
                SET cantidad_actual = GREATEST(0, cantidad_actual - ?)
                WHERE id = ? AND id > 0
            ");

            foreach ($detalles as $d) {
                $prodId = (int) $d['producto_maestro_id'];
                $loteId = (int) $d['lote_id'];
                $desp = (int) $d['cantidad_despachada'];
                $dev = (int) $d['cantidad_devuelta'];
                $cons = max(0, $desp - $dev);

                $stLockAlm->execute([$prodId, $loteId, $desp, $almacenOrigenId]);
                $stkRow = $stLockAlm->fetch(PDO::FETCH_ASSOC);

                if (!$stkRow) {
                    throw new Exception("No se encontró el stock comprometido ({$desp} uds.) del producto #{$prodId} (lote #{$loteId}) para liberar/descargar.");
                }
                if ($cons > (int) $stkRow['stock_fisico_unidades']) {
                    throw new Exception("Stock físico insuficiente en almacén #{$stkRow['almacen_id']} para descargar {$cons} uds. del producto #{$prodId}.");
                }

                // Se resta $cons del físico y $desp completo ($cons + $dev) del comprometido
                $stUpdStock->execute([$cons, $desp, (int) $stkRow['id']]);
                if ($cons > 0 && $loteId > 0) {
                    $stUpdLote->execute([$cons, $loteId]);
                }
            }

            // 10. Marcar consignación como LIQUIDADA
            $pdo->prepare("UPDATE ventas.consignaciones SET estado = 'LIQUIDADA' WHERE id = ?")->execute([$csgId]);

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
                    'subtotal_consumo' => $subtotalConsumo,
                    'impuestos_consumo' => $impuestosConsumo,
                    'total_facturado' => $totalFacturar,
                    'monto_fianza' => $montoFianza,
                    'excedente_reembolso' => $excedenteFianza,
                    'reembolso_modo' => $excedenteFianza > 0 ? $reembolsoModo : null,
                    'factura_id' => $facturaIdGenerada,
                    'numero_factura' => $numeroFacturaGenerada,
                    'ncf' => $ncfGenerado,
                    'botellas_consumidas' => $fiscal['botellas_consumidas'],
                    'botellas_devueltas' => $fiscal['botellas_devueltas'],
                ]
            );

            $pdo->commit();

            $msgFin = $facturaIdGenerada
                ? "Consignación {$numConsignacion} liquidada. Factura {$numeroFacturaGenerada} (NCF: {$ncfGenerado}) por RD$ " . number_format($totalFacturar, 2) . "."
                : "Consignación {$numConsignacion} cerrada sin consumo. Inventario liberado y fianza reembolsada.";

            responderCsg(true, $msgFin, [
                'total_consumido' => $totalFacturar,
                'factura_numero' => $numeroFacturaGenerada,
                'ncf' => $ncfGenerado,
                'factura_id' => $facturaIdGenerada,
            ]);
            break;

        default:
            throw new Exception("Acción no reconocida: '{$accion}'.");
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $msgDb = $e->getMessage();
    $msg = 'Error PostgreSQL [' . $e->getCode() . ']: ' . $msgDb;
    if ($e->getCode() === '23505') {
        $msg = (stripos($msgDb, 'uk_movbanco_ref') !== false)
            ? 'Ese número de referencia bancaria o cheque ya existe en la cuenta seleccionada.'
            : 'Registro duplicado detectado en la base de datos.';
    } elseif ($e->getCode() === '23514') {
        $msg = 'Validación CHECK [23514]: ' . $msgDb;
    }
    responderCsg(false, $msg, ['detalle_tecnico' => $msgDb], 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderCsg(false, $e->getMessage(), [], 400);
}