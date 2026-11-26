<?php
/**
 * ============================================================================
 * Módulo 6: Tesorería • Movimientos Bancarios y Depósitos de Arqueos
 * Ubicación: Tesoreria/MovimientosBancarios/procesar_movimientos_bancarios.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: tesoreria.movimientos_bancarios, tesoreria.cuentas_bancarias,
 *         ventas.arqueo_detalles, ventas.sesiones_caja, seguridad.auditoria
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

header('Content-Type: application/json; charset=utf-8');

function responderTesoreria(bool $ok, string $mensaje, array $extra = [], int $httpCode = 200): void
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

if (!$pdo) {
    responderTesoreria(false, 'No hay conexión activa con PostgreSQL.', [], 500);
}

$usuarioId = requerirSesionActiva(true);

$esAdmin = function_exists('usuarioEsAdministrador') && usuarioEsAdministrador();
$puedeRegistrarBancos = $esAdmin
    || (function_exists('usuarioTienePermiso') && usuarioTienePermiso('REGISTRAR_MOVIMIENTOS_BANCARIOS'));
$puedeVerBancos = $puedeRegistrarBancos
    || (function_exists('usuarioTienePermiso') && usuarioTienePermiso('VER_REPORTES_FINANCIEROS'));

if (!$puedeVerBancos) {
    responderTesoreria(
        false,
        'Acceso restringido: requiere permiso REGISTRAR_MOVIMIENTOS_BANCARIOS o VER_REPORTES_FINANCIEROS.',
        [],
        403
    );
}

$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : null;
$datos = is_array($jsonBody) ? array_merge($_GET, $jsonBody) : array_merge($_GET, $_POST);
$accion = trim((string) ($datos['accion'] ?? 'listar'));

try {
    // =========================================================================
    // 1. LISTAR BANDEJA DE ARQUEOS PENDIENTES + LIBRO DE MOVIMIENTOS BANCARIOS
    // =========================================================================
    if ($accion === 'listar') {
        $cuentaId = intval($datos['cuenta_bancaria_id'] ?? 0);
        $tipoMov = strtoupper(trim((string) ($datos['tipo_movimiento'] ?? '')));
        $fechaDesde = trim((string) ($datos['fecha_desde'] ?? ''));
        $fechaHasta = trim((string) ($datos['fecha_hasta'] ?? ''));
        $q = trim((string) ($datos['q'] ?? ''));

        // A. Bandeja de Arqueos de Efectivo Pendientes de Depósito (En Bóveda)
        $arqueosPendientes = $pdo->query("
            SELECT ad.sesion_caja_id,
                   ad.metodo_pago_id,
                   ad.monto_declarado_cajero,
                   ad.monto_esperado_sistema,
                   ad.diferencia,
                   ad.monto_retenido_fondo,
                   ROUND(ad.monto_declarado_cajero - ad.monto_retenido_fondo, 2) AS monto_depositar,
                   TO_CHAR(s.fecha_apertura, 'DD/MM/YYYY HH12:MI AM') AS fecha_apertura_fmt,
                   TO_CHAR(s.fecha_cierre,   'DD/MM/YYYY HH12:MI AM') AS fecha_cierre_fmt,
                   cp.nombre_equipo,
                   COALESCE(NULLIF(TRIM(p.nombre_razon_social), ''), u.username, 'Cajero') AS cajero_nombre,
                   COALESCE(e.codigo_empleado, '') AS codigo_empleado
            FROM ventas.arqueo_detalles ad
            JOIN ventas.sesiones_caja s   ON s.id = ad.sesion_caja_id
            JOIN ventas.cajas_pos cp      ON cp.id = s.caja_id
            JOIN ventas.metodos_pago mp   ON mp.id = ad.metodo_pago_id
            JOIN seguridad.usuarios u     ON u.usuario_id = s.cajero_id
            LEFT JOIN identidad.personas p  ON p.persona_id = u.persona_id
            LEFT JOIN identidad.empleados e ON e.persona_id = u.persona_id
            WHERE mp.codigo = 'EFECTIVO'
              AND s.estado = 'CERRADA'
              AND ad.estado_deposito = 'PENDIENTE'
              AND (ad.monto_declarado_cajero - ad.monto_retenido_fondo) > 0
            ORDER BY s.fecha_cierre ASC, ad.sesion_caja_id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totalBovedaDop = 0.00;
        foreach ($arqueosPendientes as &$ap) {
            $ap['monto_declarado_cajero'] = round(floatval($ap['monto_declarado_cajero']), 2);
            $ap['monto_esperado_sistema'] = round(floatval($ap['monto_esperado_sistema']), 2);
            $ap['diferencia'] = round(floatval($ap['diferencia']), 2);
            $ap['monto_retenido_fondo'] = round(floatval($ap['monto_retenido_fondo']), 2);
            $ap['monto_depositar'] = round(floatval($ap['monto_depositar']), 2);
            $totalBovedaDop = round($totalBovedaDop + $ap['monto_depositar'], 2);
        }
        unset($ap);

        // B. Filtros para el Libro de Movimientos Bancarios
        $where = ['1=1'];
        $params = [];

        if ($cuentaId > 0) {
            $where[] = 'mb.cuenta_bancaria_id = ?';
            $params[] = $cuentaId;
        }
        if (in_array($tipoMov, ['DEPOSITO_CAJA', 'TRANSFERENCIA_ENTRADA', 'TRANSFERENCIA_SALIDA', 'CHEQUE_ENTRADA', 'CHEQUE_SALIDA'], true)) {
            $where[] = 'mb.tipo_movimiento = ?';
            $params[] = $tipoMov;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) {
            $where[] = 'mb.fecha_movimiento >= ?';
            $params[] = $fechaDesde;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
            $where[] = 'mb.fecha_movimiento <= ?';
            $params[] = $fechaHasta;
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(
                mb.referencia ILIKE ?
                OR mb.concepto ILIKE ?
                OR cb.nombre_banco ILIKE ?
                OR cb.numero_cuenta ILIKE ?
                OR COALESCE(p.nombre_razon_social, '') ILIKE ?
                OR u.username ILIKE ?
            )";
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);

        $stMovs = $pdo->prepare("
            SELECT mb.id,
                   mb.cuenta_bancaria_id,
                   mb.tipo_movimiento,
                   mb.monto,
                   mb.referencia,
                   mb.concepto,
                   mb.fecha_movimiento,
                   TO_CHAR(mb.fecha_movimiento, 'DD/MM/YYYY') AS fecha_fmt,
                   mb.detalle_pago_id,
                   cb.nombre_banco,
                   cb.numero_cuenta,
                   cb.tipo_cuenta,
                   COALESCE(m.codigo_iso, 'DOP') AS codigo_iso,
                   COALESCE(m.simbolo, 'RD$')    AS simbolo,
                   COALESCE(m.tasa_cambio_referencia, 1.0000) AS tasa_ref,
                   COALESCE(NULLIF(TRIM(p.nombre_razon_social), ''), u.username, 'Usuario') AS usuario_nombre,
                   COALESCE((
                       SELECT COUNT(*)
                       FROM ventas.arqueo_detalles ad2
                       WHERE ad2.movimiento_bancario_id = mb.id
                   ), 0) AS sesiones_vinculadas
            FROM tesoreria.movimientos_bancarios mb
            JOIN tesoreria.cuentas_bancarias cb ON cb.id = mb.cuenta_bancaria_id
            LEFT JOIN tesoreria.monedas m       ON m.id = cb.moneda_id
            JOIN seguridad.usuarios u           ON u.usuario_id = mb.usuario_id
            LEFT JOIN identidad.personas p      ON p.persona_id = u.persona_id
            WHERE {$whereSql}
            ORDER BY mb.fecha_movimiento DESC, mb.id DESC
            LIMIT 250
        ");
        $stMovs->execute($params);
        $movimientos = $stMovs->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $entradasDop = 0.00;
        $salidasDop = 0.00;
        $depositosCajaDop = 0.00;
        $cantDepositos = 0;

        foreach ($movimientos as &$mv) {
            $mv['monto'] = round(floatval($mv['monto']), 2);
            $mv['sesiones_vinculadas'] = intval($mv['sesiones_vinculadas']);
            $tasa = floatval($mv['tasa_ref'] ?: 1.0);
            $montoDop = round($mv['monto'] * $tasa, 2);

            $esEntrada = in_array($mv['tipo_movimiento'], ['DEPOSITO_CAJA', 'TRANSFERENCIA_ENTRADA', 'CHEQUE_ENTRADA'], true);
            $mv['es_entrada'] = $esEntrada;

            if ($esEntrada) {
                $entradasDop = round($entradasDop + $montoDop, 2);
            } else {
                $salidasDop = round($salidasDop + $montoDop, 2);
            }
            if ($mv['tipo_movimiento'] === 'DEPOSITO_CAJA') {
                $depositosCajaDop = round($depositosCajaDop + $montoDop, 2);
                $cantDepositos++;
            }
        }
        unset($mv);

        responderTesoreria(true, 'OK', [
            'arqueos_pendientes' => $arqueosPendientes,
            'movimientos' => $movimientos,
            'kpis' => [
                'pendientes_count' => count($arqueosPendientes),
                'boveda_dop' => $totalBovedaDop,
                'depositos_caja_dop' => $depositosCajaDop,
                'depositos_count' => $cantDepositos,
                'entradas_dop' => $entradasDop,
                'salidas_dop' => $salidasDop,
                'flujo_neto_dop' => round($entradasDop - $salidasDop, 2),
            ],
        ]);
    }

    // =========================================================================
    // 2. REGISTRAR DEPÓSITO BANCARIO DE UNO O VARIOS ARQUEOS (CONSOLIDADO)
    // =========================================================================
    if ($accion === 'registrar_deposito_arqueos') {
        if (!$puedeRegistrarBancos) {
            responderTesoreria(false, 'No posees el permiso REGISTRAR_MOVIMIENTOS_BANCARIOS para registrar depósitos.', [], 403);
        }

        $sesionesRaw = $datos['sesiones_ids'] ?? [];
        $cuentaId = intval($datos['cuenta_bancaria_id'] ?? 0);
        $referencia = strtoupper(trim((string) ($datos['referencia'] ?? '')));
        $fechaMov = trim((string) ($datos['fecha_movimiento'] ?? date('Y-m-d')));
        $observacion = trim((string) ($datos['observacion'] ?? ''));

        if (!is_array($sesionesRaw)) {
            $sesionesRaw = [];
        }
        $sesionesIds = array_values(array_unique(array_filter(
            array_map('intval', $sesionesRaw),
            fn($v) => $v > 0
        )));

        if (empty($sesionesIds)) {
            throw new Exception('Selecciona al menos un arqueo pendiente para incluir en el depósito.');
        }
        if ($cuentaId <= 0) {
            throw new Exception('Selecciona la cuenta bancaria destino.');
        }
        if ($referencia === '' || mb_strlen($referencia, 'UTF-8') < 3) {
            throw new Exception('Ingresa el número de volante o referencia bancaria (mínimo 3 caracteres).');
        }
        if (mb_strlen($referencia, 'UTF-8') > 100) {
            throw new Exception('La referencia bancaria no puede superar los 100 caracteres.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaMov)) {
            throw new Exception('La fecha del depósito debe tener el formato AAAA-MM-DD.');
        }
        if ($fechaMov > date('Y-m-d')) {
            throw new Exception('La fecha del depósito bancario no puede ser futura.');
        }

        // Validar que la cuenta bancaria exista, esté activa y sea en moneda base DOP
        $stCuenta = $pdo->prepare("
            SELECT cb.id, cb.nombre_banco, cb.numero_cuenta, cb.activa,
                   COALESCE(m.codigo_iso, 'DOP') AS codigo_iso,
                   COALESCE(m.es_moneda_base, TRUE) AS es_moneda_base
            FROM tesoreria.cuentas_bancarias cb
            LEFT JOIN tesoreria.monedas m ON m.id = cb.moneda_id
            WHERE cb.id = ?
            LIMIT 1
        ");
        $stCuenta->execute([$cuentaId]);
        $cuenta = $stCuenta->fetch(PDO::FETCH_ASSOC);

        if (!$cuenta || !($cuenta['activa'] === true || $cuenta['activa'] === 't' || $cuenta['activa'] == 1)) {
            throw new Exception('La cuenta bancaria seleccionada no existe o está inactiva.');
        }
        $esBase = ($cuenta['es_moneda_base'] === true || $cuenta['es_moneda_base'] === 't' || $cuenta['es_moneda_base'] == 1);
        if (!$esBase && strtoupper((string) $cuenta['codigo_iso']) !== 'DOP') {
            throw new Exception("Los arqueos de caja se liquidan en Pesos Dominicanos (DOP). Selecciona una cuenta bancaria en DOP (la cuenta elegida está en {$cuenta['codigo_iso']}).");
        }

        // Verificar referencia duplicada en esa cuenta antes de abrir transacción
        $stDupRef = $pdo->prepare("
            SELECT id, TO_CHAR(fecha_movimiento, 'DD/MM/YYYY') AS fecha_fmt
            FROM tesoreria.movimientos_bancarios
            WHERE cuenta_bancaria_id = ?
              AND UPPER(TRIM(referencia)) = UPPER(?)
            LIMIT 1
        ");
        $stDupRef->execute([$cuentaId, $referencia]);
        if ($dup = $stDupRef->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("El número de volante/referencia «{$referencia}» ya fue registrado en {$cuenta['nombre_banco']} (Movimiento #{$dup['id']} del {$dup['fecha_fmt']}). Verifica el volante antes de continuar.");
        }

        $pdo->beginTransaction();

        // Bloquear únicamente las filas de ventas.arqueo_detalles (FOR UPDATE OF ad)
        $placeholders = implode(', ', array_fill(0, count($sesionesIds), '?'));
        $stLock = $pdo->prepare("
            SELECT ad.sesion_caja_id,
                   ad.metodo_pago_id,
                   ad.monto_declarado_cajero,
                   ad.monto_retenido_fondo,
                   ad.estado_deposito,
                   s.estado AS sesion_estado,
                   cp.nombre_equipo
            FROM ventas.arqueo_detalles ad
            JOIN ventas.sesiones_caja s ON s.id = ad.sesion_caja_id
            JOIN ventas.cajas_pos cp    ON cp.id = s.caja_id
            JOIN ventas.metodos_pago mp ON mp.id = ad.metodo_pago_id
            WHERE mp.codigo = 'EFECTIVO'
              AND ad.sesion_caja_id IN ({$placeholders})
            ORDER BY ad.sesion_caja_id ASC
            FOR UPDATE OF ad
        ");
        $stLock->execute($sesionesIds);
        $filasBloqueadas = $stLock->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (count($filasBloqueadas) !== count($sesionesIds)) {
            throw new Exception('Uno o más arqueos seleccionados no existen o no corresponden a un cierre de efectivo válido.');
        }

        $montoTotalDeposito = 0.00;
        $sesionesConfirmadas = [];
        $detalleAuditoria = [];

        foreach ($filasBloqueadas as $fl) {
            $sid = intval($fl['sesion_caja_id']);
            if (strtoupper((string) $fl['sesion_estado']) !== 'CERRADA') {
                throw new Exception("La sesión #{$sid} todavía no está CERRADA.");
            }
            if (strtoupper((string) $fl['estado_deposito']) !== 'PENDIENTE') {
                throw new Exception("La sesión #{$sid} ya no está pendiente de depósito (estado actual: {$fl['estado_deposito']}). Recarga la bandeja.");
            }
            $neto = round(floatval($fl['monto_declarado_cajero']) - floatval($fl['monto_retenido_fondo']), 2);
            if ($neto <= 0) {
                throw new Exception("La sesión #{$sid} no tiene monto neto disponible para depositar.");
            }

            $montoTotalDeposito = round($montoTotalDeposito + $neto, 2);
            $sesionesConfirmadas[] = $sid;
            $detalleAuditoria[] = [
                'sesion_caja_id' => $sid,
                'caja' => $fl['nombre_equipo'],
                'declarado' => round(floatval($fl['monto_declarado_cajero']), 2),
                'retenido' => round(floatval($fl['monto_retenido_fondo']), 2),
                'depositado' => $neto,
            ];
        }

        // Construir concepto descriptivo (máximo 255 caracteres)
        $listaTxt = '#' . implode(', #', $sesionesConfirmadas);
        $concepto = 'Depósito efectivo de caja • Sesiones ' . $listaTxt;
        if ($observacion !== '') {
            $concepto .= ' | ' . $observacion;
        }
        $concepto = mb_substr($concepto, 0, 255, 'UTF-8');

        // 1. Insertar movimiento bancario DEPOSITO_CAJA
        $stInsMov = $pdo->prepare("
            INSERT INTO tesoreria.movimientos_bancarios (
                cuenta_bancaria_id, tipo_movimiento, monto, referencia,
                concepto, fecha_movimiento, usuario_id, detalle_pago_id
            ) VALUES (
                ?, 'DEPOSITO_CAJA', ?, ?,
                ?, ?, ?, NULL
            )
            RETURNING id
        ");
        $stInsMov->execute([
            $cuentaId,
            $montoTotalDeposito,
            $referencia,
            $concepto,
            $fechaMov,
            $usuarioId,
        ]);
        $movimientoBancarioId = intval($stInsMov->fetchColumn());

        // 2. Marcar los arqueos seleccionados como DEPOSITADO y vincular movimiento_bancario_id
        $stUpdArq = $pdo->prepare("
            UPDATE ventas.arqueo_detalles
            SET estado_deposito        = 'DEPOSITADO',
                movimiento_bancario_id = ?
            WHERE sesion_caja_id = ?
              AND metodo_pago_id = ?
              AND estado_deposito = 'PENDIENTE'
        ");
        foreach ($filasBloqueadas as $fl) {
            $stUpdArq->execute([
                $movimientoBancarioId,
                intval($fl['sesion_caja_id']),
                intval($fl['metodo_pago_id']),
            ]);
            if ($stUpdArq->rowCount() !== 1) {
                throw new Exception("No se pudo actualizar el estado de depósito de la sesión #{$fl['sesion_caja_id']}.");
            }
        }

        // 3. Auditoría Forense JSONB
        registrarAuditoria(
            $pdo,
            $usuarioId,
            null,
            'TESORERIA_DEPOSITO_ARQUEOS',
            'tesoreria.movimientos_bancarios',
            ['estado_deposito_anterior' => 'PENDIENTE', 'sesiones' => $sesionesConfirmadas],
            [
                'movimiento_bancario_id' => $movimientoBancarioId,
                'cuenta_bancaria' => $cuenta['nombre_banco'] . ' (' . $cuenta['numero_cuenta'] . ')',
                'referencia' => $referencia,
                'fecha_movimiento' => $fechaMov,
                'monto_total_dop' => $montoTotalDeposito,
                'arqueos_depositados' => $detalleAuditoria,
            ]
        );

        $pdo->commit();

        $cantSes = count($sesionesConfirmadas);
        responderTesoreria(
            true,
            "Depósito #{$movimientoBancarioId} registrado en {$cuenta['nombre_banco']} por RD$ " . number_format($montoTotalDeposito, 2) . " ({$cantSes} turno(s) liquidado(s) • Volante: {$referencia}).",
            [
                'movimiento_bancario_id' => $movimientoBancarioId,
                'monto_total' => $montoTotalDeposito,
                'sesiones_depositadas' => $sesionesConfirmadas,
            ]
        );
    }

    // =========================================================================
    // 3. DETALLE DE UN MOVIMIENTO BANCARIO (CON ARQUEOS CONSOLIDADOS)
    // =========================================================================
    if ($accion === 'detalle_movimiento') {
        $movId = intval($datos['movimiento_id'] ?? 0);
        if ($movId <= 0) {
            responderTesoreria(false, 'ID de movimiento bancario inválido.');
        }

        $stCab = $pdo->prepare("
            SELECT mb.id, mb.tipo_movimiento, mb.monto, mb.referencia, mb.concepto,
                   TO_CHAR(mb.fecha_movimiento, 'DD/MM/YYYY') AS fecha_fmt,
                   cb.nombre_banco, cb.numero_cuenta, cb.tipo_cuenta,
                   COALESCE(m.codigo_iso, 'DOP') AS codigo_iso,
                   COALESCE(m.simbolo, 'RD$')    AS simbolo,
                   COALESCE(NULLIF(TRIM(p.nombre_razon_social), ''), u.username, 'Usuario') AS usuario_nombre
            FROM tesoreria.movimientos_bancarios mb
            JOIN tesoreria.cuentas_bancarias cb ON cb.id = mb.cuenta_bancaria_id
            LEFT JOIN tesoreria.monedas m       ON m.id = cb.moneda_id
            JOIN seguridad.usuarios u           ON u.usuario_id = mb.usuario_id
            LEFT JOIN identidad.personas p      ON p.persona_id = u.persona_id
            WHERE mb.id = ?
            LIMIT 1
        ");
        $stCab->execute([$movId]);
        $cab = $stCab->fetch(PDO::FETCH_ASSOC);

        if (!$cab) {
            responderTesoreria(false, 'Movimiento bancario no encontrado.', [], 404);
        }

        // Si corresponde a un depósito de caja, listar todos los arqueos respaldados por este volante
        $stArqs = $pdo->prepare("
            SELECT ad.sesion_caja_id,
                   ad.monto_declarado_cajero,
                   ad.monto_esperado_sistema,
                   ad.diferencia,
                   ad.monto_retenido_fondo,
                   ROUND(ad.monto_declarado_cajero - ad.monto_retenido_fondo, 2) AS monto_depositado,
                   TO_CHAR(s.fecha_apertura, 'DD/MM/YYYY HH12:MI AM') AS fecha_apertura_fmt,
                   TO_CHAR(s.fecha_cierre,   'DD/MM/YYYY HH12:MI AM') AS fecha_cierre_fmt,
                   cp.nombre_equipo,
                   COALESCE(NULLIF(TRIM(p.nombre_razon_social), ''), u.username, 'Cajero') AS cajero_nombre,
                   COALESCE(e.codigo_empleado, '') AS codigo_empleado
            FROM ventas.arqueo_detalles ad
            JOIN ventas.sesiones_caja s   ON s.id = ad.sesion_caja_id
            JOIN ventas.cajas_pos cp      ON cp.id = s.caja_id
            JOIN seguridad.usuarios u     ON u.usuario_id = s.cajero_id
            LEFT JOIN identidad.personas p  ON p.persona_id = u.persona_id
            LEFT JOIN identidad.empleados e ON e.persona_id = u.persona_id
            WHERE ad.movimiento_bancario_id = ?
            ORDER BY ad.sesion_caja_id ASC
        ");
        $stArqs->execute([$movId]);
        $arqueos = $stArqs->fetchAll(PDO::FETCH_ASSOC) ?: [];

        responderTesoreria(true, 'OK', [
            'cabecera' => $cab,
            'arqueos' => $arqueos,
        ]);
    }

    responderTesoreria(false, 'Acción no reconocida.');
} catch (PDOException $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $msg = 'No se pudo completar la operación bancaria en la base de datos.';
    if ($e->getCode() === '23505' && stripos($e->getMessage(), 'uk_movbanco_ref') !== false) {
        $msg = 'Ya existe un movimiento registrado en esa cuenta bancaria con el mismo número de volante o referencia.';
    }
    responderTesoreria(false, $msg, [], 400);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderTesoreria(false, $e->getMessage(), [], 400);
}