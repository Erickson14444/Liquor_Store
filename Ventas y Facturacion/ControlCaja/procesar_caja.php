<?php
/**
 * ============================================================================
 * Módulo 4: Ventas • Proceso 1: Control de Caja (Controlador Backend)
 * Ubicación: Ventas y Facturacion/ControlCaja/procesar_caja.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: ventas.sesiones_caja, ventas.cajas_pos, ventas.arqueo_detalles,
 *         ventas.movimiento_caja, seguridad.auditoria
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

header('Content-Type: application/json; charset=utf-8');

function responderJson(string $status, string $mensaje, array $extra = [], int $httpCode = 200): void
{
    http_response_code($httpCode);
    $isSuccess = ($status === 'success');
    echo json_encode(array_merge([
        'status' => $status,
        'success' => $isSuccess,
        'ok' => $isSuccess,
        'message' => $mensaje,
        'mensaje' => $mensaje,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$pdo) {
    responderJson('error', 'No hay conexión con la base de datos PostgreSQL.', [], 500);
}

$usuarioSesionId = requerirSesionActiva(true);

$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : null;
$datos = is_array($jsonBody) ? array_merge($_GET, $jsonBody) : array_merge($_GET, $_POST);
$accion = trim((string) ($datos['accion'] ?? $datos['action'] ?? ''));

/**
 * Consulta oficial del Arqueo Ciego (solo Facturación, CxC, Consignaciones,
 * Movimiento de Caja y Devoluciones — excluye explícitamente egreso_cxp_pagos).
 */
function calcularEsperadoArqueoOficial(PDO $pdo, int $sesionId): array
{
    $sql = "
        WITH ingresos_pos AS (
            SELECT
                mp.id AS metodo_pago_id,
                mp.codigo AS metodo_codigo,
                SUM((dp.monto_pagado - COALESCE(dp.monto_devuelta, 0)) * dp.tasa_cambio) AS total_ingresos_dop
            FROM tesoreria.detalles_pago dp
            JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id
            WHERE dp.sesion_caja_id = :sesion_caja_id
              AND mp.codigo IN ('EFECTIVO', 'TARJETA')
              AND (
                  EXISTS (SELECT 1 FROM ventas.factura_pagos fp WHERE fp.detalle_pago_id = dp.id)
                  OR EXISTS (SELECT 1 FROM ventas.recibo_cxc_pagos rcp WHERE rcp.detalle_pago_id = dp.id)
                  OR EXISTS (SELECT 1 FROM ventas.consignacion_garantia_pagos cgp WHERE cgp.detalle_pago_id = dp.id)
              )
            GROUP BY mp.id, mp.codigo
        ),
        movimientos_efectivo AS (
            SELECT
                sc.monto_inicial_efectivo
                + COALESCE(SUM(CASE WHEN mc.tipo_movimiento = 'INGRESO' THEN mc.monto ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN mc.tipo_movimiento = 'EGRESO'  THEN mc.monto ELSE 0 END), 0) AS neto_movimientos_dop
            FROM ventas.sesiones_caja sc
            LEFT JOIN ventas.movimiento_caja mc ON mc.sesion_caja_id = sc.id
            WHERE sc.id = :sesion_caja_id
            GROUP BY sc.id, sc.monto_inicial_efectivo
        ),
        devoluciones_tarjeta AS (
            SELECT
                COALESCE(SUM(d.monto_total * d.tasa_cambio), 0) AS total_dev_tarjeta_dop
            FROM ventas.devoluciones d
            WHERE d.sesion_caja_id = :sesion_caja_id
              AND d.metodo_reembolso = 'TARJETA'
        )
        SELECT
            mp.id AS metodo_pago_id,
            mp.codigo,
            CASE
                WHEN mp.codigo = 'EFECTIVO' THEN
                    COALESCE(me.neto_movimientos_dop, 0) + COALESCE(ip.total_ingresos_dop, 0)
                WHEN mp.codigo = 'TARJETA' THEN
                    COALESCE(ip.total_ingresos_dop, 0) - COALESCE(dt.total_dev_tarjeta_dop, 0)
            END AS monto_esperado_sistema
        FROM ventas.metodos_pago mp
        LEFT JOIN ingresos_pos ip ON ip.metodo_pago_id = mp.id
        CROSS JOIN movimientos_efectivo me
        CROSS JOIN devoluciones_tarjeta dt
        WHERE mp.codigo IN ('EFECTIVO', 'TARJETA')
        ORDER BY mp.id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':sesion_caja_id' => $sesionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $res = [
        'efectivo_esperado' => 0.00,
        'tarjeta_esperada' => 0.00,
        'metodo_ef_id' => 1,
        'metodo_tj_id' => 2,
    ];

    foreach ($rows as $r) {
        if ($r['codigo'] === 'EFECTIVO') {
            $res['efectivo_esperado'] = round(floatval($r['monto_esperado_sistema'] ?? 0), 2);
            $res['metodo_ef_id'] = intval($r['metodo_pago_id']);
        } elseif ($r['codigo'] === 'TARJETA') {
            $res['tarjeta_esperada'] = round(floatval($r['monto_esperado_sistema'] ?? 0), 2);
            $res['metodo_tj_id'] = intval($r['metodo_pago_id']);
        }
    }

    return $res;
}

/**
 * Resuelve un identificador de supervisor (UUID o username) a su UUID real
 * antes de enviarlo a validarPinSupervisor().
 */
function resolverUsuarioIdSupervisor(PDO $pdo, string $identificador): string
{
    $identificador = trim($identificador);
    if ($identificador === '') {
        return '';
    }
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identificador)) {
        return $identificador;
    }

    $stmt = $pdo->prepare("
        SELECT usuario_id
        FROM seguridad.usuarios
        WHERE LOWER(TRIM(username)) = LOWER(TRIM(?))
        LIMIT 1
    ");
    $stmt->execute([$identificador]);
    $uid = $stmt->fetchColumn();

    if (!$uid) {
        throw new Exception("El usuario supervisor '{$identificador}' no existe en el sistema.");
    }

    return (string) $uid;
}

try {
    // =========================================================================
    // 1. ASIGNAR CAJERO Y ABRIR TURNO DE CAJA (FASE 1 Y FASE 5)
    // =========================================================================
    if ($accion === 'abrir_turno') {
        $puedeAbrir = function_exists('usuarioPuedeAccederControlCaja') && usuarioPuedeAccederControlCaja();
        $puedeAuditar = function_exists('usuarioPuedeAuditarCaja') && usuarioPuedeAuditarCaja();

        if (!$puedeAbrir) {
            responderJson('error', 'No tienes permiso para abrir turnos de caja.', [], 403);
        }

        $cajaId = intval($datos['caja_id'] ?? 0);
        $cajeroId = trim((string) ($datos['cajero_id'] ?? ''));
        $montoInicial = round(floatval($datos['monto_inicial_efectivo'] ?? 0.00), 2);
        $supervisorAutorizadorId = $usuarioSesionId;

        // Si quien abre el turno es un Cajero (sin rol Supervisor/Admin):
        // solo puede abrir para sí mismo y exige autorización con Usuario + PIN del Supervisor
        if (!$puedeAuditar) {
            $cajeroId = $usuarioSesionId;
            $supervisorInput = trim((string) ($datos['supervisor_id'] ?? $datos['usuario_supervisor'] ?? ''));
            $pinSupervisor = trim((string) ($datos['pin_supervisor'] ?? $datos['pin_autorizacion'] ?? ''));

            if ($supervisorInput === '' || $pinSupervisor === '') {
                responderJson('error', 'Para abrir tu turno de caja un Supervisor debe ingresar su usuario y PIN de autorización.');
            }

            $supUuid = resolverUsuarioIdSupervisor($pdo, $supervisorInput);
            $supAut = validarPinSupervisor($pdo, $supUuid, $pinSupervisor);
            $supervisorAutorizadorId = $supAut['usuario_id'];
        }

        if ($cajaId <= 0 || $cajeroId === '') {
            responderJson('error', 'Debe seleccionar tanto la Caja Registradora como el Cajero.');
        }
        if ($montoInicial < 0) {
            responderJson('error', 'El fondo inicial (menudo) no puede ser negativo.');
        }

        $pdo->beginTransaction();

        // Bloquear la terminal física para evitar aperturas simultáneas
        $stmtCaja = $pdo->prepare("SELECT id, nombre_equipo, estado FROM ventas.cajas_pos WHERE id = ? FOR UPDATE");
        $stmtCaja->execute([$cajaId]);
        $cajaRow = $stmtCaja->fetch(PDO::FETCH_ASSOC);
        if (!$cajaRow) {
            $pdo->rollBack();
            responderJson('error', 'La caja registradora seleccionada no existe.');
        }

        // Control de Concurrencia: la caja no puede tener turno ABIERTA ni EN_CUADRE
        $stmtChkCaja = $pdo->prepare("
            SELECT id, estado
            FROM ventas.sesiones_caja
            WHERE caja_id = ? AND estado IN ('ABIERTA', 'EN_CUADRE')
            LIMIT 1
        ");
        $stmtChkCaja->execute([$cajaId]);
        $sesCajaExist = $stmtChkCaja->fetch(PDO::FETCH_ASSOC);
        if ($sesCajaExist) {
            $pdo->rollBack();
            responderJson('error', "La caja '{$cajaRow['nombre_equipo']}' ya posee un turno activo en estado {$sesCajaExist['estado']}.");
        }

        // Control de Concurrencia: el cajero no puede tener otro turno ABIERTA ni EN_CUADRE
        $stmtChkCajero = $pdo->prepare("
            SELECT id, estado
            FROM ventas.sesiones_caja
            WHERE cajero_id = ? AND estado IN ('ABIERTA', 'EN_CUADRE')
            LIMIT 1
        ");
        $stmtChkCajero->execute([$cajeroId]);
        $sesCajeroExist = $stmtChkCajero->fetch(PDO::FETCH_ASSOC);
        if ($sesCajeroExist) {
            $pdo->rollBack();
            responderJson('error', "El cajero seleccionado ya tiene un turno activo (estado: {$sesCajeroExist['estado']}) en otra terminal.");
        }

        // Insertar la sesión de caja
        $stmtIns = $pdo->prepare("
            INSERT INTO ventas.sesiones_caja
                (caja_id, cajero_id, monto_inicial_efectivo, estado, fecha_apertura)
            VALUES (?, ?, ?, 'ABIERTA', CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $stmtIns->execute([$cajaId, $cajeroId, $montoInicial]);
        $sesionId = intval($stmtIns->fetchColumn());

        // Actualizar terminal física a ABIERTA
        $pdo->prepare("UPDATE ventas.cajas_pos SET estado = 'ABIERTA' WHERE id = ?")
            ->execute([$cajaId]);

        registrarAuditoria(
            $pdo,
            $usuarioSesionId,
            $usuarioSesionId,
            'CAJA_ABRIR_TURNO',
            'ventas.sesiones_caja',
            ['caja_estado_anterior' => $cajaRow['estado']],
            [
                'sesion_id' => $sesionId,
                'caja_id' => $cajaId,
                'caja_nombre' => $cajaRow['nombre_equipo'],
                'cajero_id' => $cajeroId,
                'monto_inicial_efectivo' => $montoInicial,
                'estado' => 'ABIERTA',
            ]
        );

        $pdo->commit();

        responderJson('success', "Turno abierto exitosamente en {$cajaRow['nombre_equipo']} con fondo de RD$ " . number_format($montoInicial, 2) . ".", [
            'sesion_id' => $sesionId,
            'caja_id' => $cajaId,
        ]);
    }

    // =========================================================================
    // 2. BLOQUEO DE CAJA E INICIO DE ARQUEO CIEGO (FASE 3)
    // =========================================================================
    if ($accion === 'iniciar_cuadre') {
        $sesionId = intval($datos['sesion_id'] ?? 0);
        if ($sesionId <= 0) {
            responderJson('error', 'ID de sesión inválido.');
        }

        $pdo->beginTransaction();

        $stmtSes = $pdo->prepare("SELECT id, caja_id, cajero_id, estado FROM ventas.sesiones_caja WHERE id = ? FOR UPDATE");
        $stmtSes->execute([$sesionId]);
        $ses = $stmtSes->fetch(PDO::FETCH_ASSOC);

        if (!$ses || $ses['estado'] !== 'ABIERTA') {
            $pdo->rollBack();
            responderJson('error', 'La sesión no se encuentra en estado ABIERTA para iniciar el arqueo.');
        }

        $puedeSupervisar = function_exists('usuarioPuedeAccederControlCaja') && usuarioPuedeAccederControlCaja();
        if (!$puedeSupervisar && (string) $ses['cajero_id'] !== (string) $usuarioSesionId) {
            $pdo->rollBack();
            responderJson('error', 'Solo el cajero titular del turno o un supervisor puede iniciar el arqueo de esta caja.', [], 403);
        }

        $pdo->prepare("UPDATE ventas.sesiones_caja SET estado = 'EN_CUADRE' WHERE id = ?")
            ->execute([$sesionId]);

        $pdo->prepare("UPDATE ventas.cajas_pos SET estado = 'EN_ARQUEO' WHERE id = ?")
            ->execute([intval($ses['caja_id'])]);

        registrarAuditoria(
            $pdo,
            $usuarioSesionId,
            $puedeSupervisar ? $usuarioSesionId : null,
            'CAJA_INICIAR_ARQUEO',
            'ventas.sesiones_caja',
            ['estado' => 'ABIERTA'],
            ['sesion_id' => $sesionId, 'estado' => 'EN_CUADRE']
        );

        $pdo->commit();

        responderJson('success', "Terminal bloqueada para Arqueo Ciego. La sesión #{$sesionId} pasó a estado EN CUADRE.");
    }

    // =========================================================================
    // 3. PROCESAR ARQUEO CIEGO Y EMITIR VEREDICTO (FASE 3 Y FASE 4)
    // =========================================================================
    if ($accion === 'procesar_arqueo') {
        $sesionId = intval($datos['sesion_id'] ?? 0);
        $efectivoDeclarado = round(max(0.0, floatval($datos['efectivo_declarado'] ?? 0)), 2);
        $tarjetaDeclarada = round(max(0.0, floatval($datos['tarjeta_declarada'] ?? 0)), 2);
        $cantVouchers = max(0, intval($datos['cantidad_vouchers'] ?? 0));
        $montoRetenido = round(max(0.0, floatval($datos['monto_retenido_fondo'] ?? 0)), 2);
        $estadoDeposito = strtoupper(trim((string) ($datos['estado_deposito'] ?? 'PENDIENTE')));

        if ($sesionId <= 0) {
            responderJson('error', 'ID de sesión de caja no válido.');
        }

        if (!in_array($estadoDeposito, ['PENDIENTE', 'DEPOSITADO', 'RETENIDO', 'NO_APLICA'], true)) {
            $estadoDeposito = 'PENDIENTE';
        }

        $pdo->beginTransaction();

        $stmtSes = $pdo->prepare("
            SELECT s.id, s.caja_id, s.cajero_id, s.monto_inicial_efectivo, s.estado, s.fecha_apertura
            FROM ventas.sesiones_caja s
            WHERE s.id = ?
            FOR UPDATE
        ");
        $stmtSes->execute([$sesionId]);
        $sesion = $stmtSes->fetch(PDO::FETCH_ASSOC);

        if (!$sesion) {
            $pdo->rollBack();
            responderJson('error', 'Sesión de caja no encontrada.');
        }
        if ($sesion['estado'] === 'CERRADA') {
            $pdo->rollBack();
            responderJson('error', 'Esta sesión ya se encuentra CERRADA y liquidada.');
        }

        // Un cajero solo puede cerrar su propia sesión; supervisores/admins pueden cerrar cualquiera
        $puedeSupervisar = function_exists('usuarioPuedeAccederControlCaja') && usuarioPuedeAccederControlCaja();
        if (!$puedeSupervisar && (string) $sesion['cajero_id'] !== (string) $usuarioSesionId) {
            $pdo->rollBack();
            responderJson('error', 'No tienes autorización para cerrar el turno de otro cajero.', [], 403);
        }

        // Calcular montos esperados mediante la consulta oficial del Arqueo Ciego
        $calc = calcularEsperadoArqueoOficial($pdo, $sesionId);
        $efectivoEsperado = $calc['efectivo_esperado'];
        $tarjetaEsperada = $calc['tarjeta_esperada'];
        $metodoEfId = $calc['metodo_ef_id'];
        $metodoTjId = $calc['metodo_tj_id'];

        $descuadreEfectivo = round($efectivoDeclarado - $efectivoEsperado, 2);
        $descuadreTarjeta = round($tarjetaDeclarada - $tarjetaEsperada, 2);

        // Si el 100% del efectivo se retiene como fondo, ajustar coherencia con chk_arqueo_retenido_valido
        $montoRetenidoFinal = min($montoRetenido, $efectivoDeclarado);
        if ($estadoDeposito === 'RETENIDO' && $montoRetenidoFinal <= 0) {
            $montoRetenidoFinal = $efectivoDeclarado;
        }

        // Reemplazar detalles de arqueo de esta sesión
        $pdo->prepare("DELETE FROM ventas.arqueo_detalles WHERE sesion_caja_id = ?")
            ->execute([$sesionId]);

        // Detalle EFECTIVO (usa el estado_deposito seleccionado por el usuario)
        $pdo->prepare("
            INSERT INTO ventas.arqueo_detalles
                (sesion_caja_id, metodo_pago_id, monto_declarado_cajero, monto_esperado_sistema, cantidad_vouchers, estado_deposito, monto_retenido_fondo)
            VALUES (?, ?, ?, ?, 0, ?, ?)
        ")->execute([
                    $sesionId,
                    $metodoEfId,
                    $efectivoDeclarado,
                    $efectivoEsperado,
                    $estadoDeposito,
                    $montoRetenidoFinal,
                ]);

        // Detalle TARJETA (los vouchers POS no requieren depósito físico de efectivo -> NO_APLICA)
        $pdo->prepare("
            INSERT INTO ventas.arqueo_detalles
                (sesion_caja_id, metodo_pago_id, monto_declarado_cajero, monto_esperado_sistema, cantidad_vouchers, estado_deposito, monto_retenido_fondo)
            VALUES (?, ?, ?, ?, ?, 'NO_APLICA', 0.00)
        ")->execute([
                    $sesionId,
                    $metodoTjId,
                    $tarjetaDeclarada,
                    $tarjetaEsperada,
                    $cantVouchers,
                ]);

        // Cerrar sesión de caja
        $pdo->prepare("
            UPDATE ventas.sesiones_caja
            SET estado                   = 'CERRADA',
                fecha_cierre             = CURRENT_TIMESTAMP,
                monto_efectivo_esperado  = ?,
                monto_efectivo_declarado = ?,
                diferencia_descuadre     = ?
            WHERE id = ?
        ")->execute([$efectivoEsperado, $efectivoDeclarado, $descuadreEfectivo, $sesionId]);

        // Liberar la terminal física
        $pdo->prepare("UPDATE ventas.cajas_pos SET estado = 'CERRADA' WHERE id = ?")
            ->execute([intval($sesion['caja_id'])]);

        registrarAuditoria(
            $pdo,
            $usuarioSesionId,
            $puedeSupervisar ? $usuarioSesionId : null,
            'CAJA_ARQUEO_CIERRE',
            'ventas.sesiones_caja',
            ['estado' => $sesion['estado']],
            [
                'sesion_id' => $sesionId,
                'caja_id' => intval($sesion['caja_id']),
                'estado' => 'CERRADA',
                'efectivo_esperado' => $efectivoEsperado,
                'efectivo_declarado' => $efectivoDeclarado,
                'descuadre_efectivo' => $descuadreEfectivo,
                'tarjeta_esperada' => $tarjetaEsperada,
                'tarjeta_declarada' => $tarjetaDeclarada,
                'descuadre_tarjeta' => $descuadreTarjeta,
                'cantidad_vouchers' => $cantVouchers,
                'estado_deposito' => $estadoDeposito,
                'monto_retenido_fondo' => $montoRetenidoFinal,
            ]
        );

        $pdo->commit();

        if ($descuadreEfectivo === 0.00) {
            $veredicto = "🟢 CUADRADO EXACTO: El conteo coincide al centavo con el sistema.";
        } elseif ($descuadreEfectivo < 0) {
            $veredicto = "🔴 FALTANTE EN CAJA: Faltaron RD$ " . number_format(abs($descuadreEfectivo), 2) . " para completar el turno.";
        } else {
            $veredicto = "🟡 SOBRANTE EN CAJA: Se contó un excedente de RD$ " . number_format($descuadreEfectivo, 2) . ".";
        }

        responderJson('success', "Arqueo procesado exitosamente. {$veredicto}", [
            'sesion_id' => $sesionId,
            'efectivo_esperado' => $efectivoEsperado,
            'efectivo_declarado' => $efectivoDeclarado,
            'descuadre_efectivo' => $descuadreEfectivo,
            'diferencia' => $descuadreEfectivo,
            'tarjeta_esperada' => $tarjetaEsperada,
            'tarjeta_declarada' => $tarjetaDeclarada,
            'descuadre_tarjeta' => $descuadreTarjeta,
            'veredicto' => $veredicto,
        ]);
    }

    // =========================================================================
    // 4. REGISTRAR MOVIMIENTO DE CAJA CHICA (INGRESO / EGRESO CON PIN)
    // =========================================================================
    if ($accion === 'movimiento_caja_chica') {
        $sesionId = intval($datos['sesion_id'] ?? 0);
        $tipo = strtoupper(trim((string) ($datos['tipo_movimiento'] ?? 'EGRESO')));
        $monto = round(floatval($datos['monto'] ?? 0), 2);
        $concepto = trim((string) ($datos['concepto'] ?? ''));
        $supervisorInput = trim((string) ($datos['supervisor_id'] ?? $datos['usuario_supervisor'] ?? ''));
        $pin = trim((string) ($datos['pin_supervisor'] ?? $datos['pin_autorizacion'] ?? ''));

        if ($sesionId <= 0 || $monto <= 0 || mb_strlen($concepto, 'UTF-8') < 3) {
            responderJson('error', 'Complete un monto mayor a 0 y el concepto obligatorio del movimiento.');
        }
        if (!in_array($tipo, ['INGRESO', 'EGRESO'], true)) {
            responderJson('error', 'Tipo de movimiento no válido (debe ser INGRESO o EGRESO).');
        }
        if ($supervisorInput === '') {
            responderJson('error', 'Debe indicar el usuario del Supervisor que autoriza la operación.');
        }
        if ($pin === '') {
            responderJson('error', 'Se requiere el PIN de autorización del Supervisor.');
        }

        // Resolver username o UUID -> UUID real y validar permiso + PIN BCrypt
        $supervisorUuid = resolverUsuarioIdSupervisor($pdo, $supervisorInput);
        $supAut = validarPinSupervisor($pdo, $supervisorUuid, $pin);
        $supervisorValidoId = $supAut['usuario_id'];

        $pdo->beginTransaction();

        $chkSes = $pdo->prepare("SELECT estado FROM ventas.sesiones_caja WHERE id = ? FOR UPDATE");
        $chkSes->execute([$sesionId]);
        if ($chkSes->fetchColumn() !== 'ABIERTA') {
            $pdo->rollBack();
            responderJson('error', 'La sesión de caja no se encuentra en estado ABIERTA.');
        }

        $stmtInsMov = $pdo->prepare("
            INSERT INTO ventas.movimiento_caja
                (sesion_caja_id, tipo_movimiento, monto, concepto, supervisor_id, fecha_hora)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $stmtInsMov->execute([$sesionId, $tipo, $monto, $concepto, $supervisorValidoId]);
        $movId = intval($stmtInsMov->fetchColumn());

        registrarAuditoria(
            $pdo,
            $usuarioSesionId,
            $supervisorValidoId,
            'CAJA_MOVIMIENTO_' . $tipo,
            'ventas.movimiento_caja',
            null,
            [
                'movimiento_id' => $movId,
                'sesion_caja_id' => $sesionId,
                'tipo_movimiento' => $tipo,
                'monto' => $monto,
                'concepto' => $concepto,
                'supervisor_nombre' => $supAut['nombre_completo'],
            ]
        );

        $pdo->commit();

        responderJson('success', "Movimiento de {$tipo} por RD$ " . number_format($monto, 2) . " autorizado por {$supAut['nombre_completo']}.", [
            'movimiento_id' => $movId,
        ]);
    }

    // =========================================================================
    // 5. CONSULTAR DESGLOSE COMPLETO DE AUDITORÍA DE ARQUEO
    // =========================================================================
    if ($accion === 'consultar_desglose') {
        $sesionId = intval($datos['sesion_id'] ?? 0);
        if ($sesionId <= 0) {
            responderJson('error', 'ID de sesión no proporcionado.');
        }

        // 1. Cabecera de la sesión
        $stmtSes = $pdo->prepare("
            SELECT s.id,
                   s.caja_id,
                   s.cajero_id,
                   s.monto_inicial_efectivo,
                   s.estado,
                   TO_CHAR(s.fecha_apertura, 'DD/MM/YYYY HH12:MI AM') AS fecha_apertura_fmt,
                   TO_CHAR(s.fecha_cierre, 'DD/MM/YYYY HH12:MI AM')   AS fecha_cierre_fmt,
                   s.monto_efectivo_esperado,
                   s.monto_efectivo_declarado,
                   s.diferencia_descuadre,
                   cp.nombre_equipo,
                   cp.ubicacion,
                   p.nombre_razon_social AS cajero_nombre,
                   e.codigo_empleado
            FROM ventas.sesiones_caja s
            JOIN ventas.cajas_pos cp ON s.caja_id = cp.id
            JOIN seguridad.usuarios u ON s.cajero_id = u.usuario_id
            JOIN identidad.personas p ON u.persona_id = p.persona_id
            LEFT JOIN identidad.empleados e ON e.persona_id = p.persona_id
            WHERE s.id = ?
            LIMIT 1
        ");
        $stmtSes->execute([$sesionId]);
        $sesion = $stmtSes->fetch(PDO::FETCH_ASSOC);

        if (!$sesion) {
            responderJson('error', 'Sesión de caja no encontrada.', [], 404);
        }

        // 2. Consulta oficial del Arqueo Ciego
        $calc = calcularEsperadoArqueoOficial($pdo, $sesionId);
        $esperadoEf = $calc['efectivo_esperado'];
        $esperadoTj = $calc['tarjeta_esperada'];

        // 3. Desglose analítico de Ventas POS en Efectivo y Tarjeta
        $stmtVentas = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN mp.codigo = 'EFECTIVO' THEN (dp.monto_pagado - COALESCE(dp.monto_devuelta, 0)) * dp.tasa_cambio ELSE 0 END), 0) AS ventas_efectivo,
                COALESCE(SUM(CASE WHEN mp.codigo = 'TARJETA'  THEN dp.monto_pagado * dp.tasa_cambio ELSE 0 END), 0) AS ventas_tarjeta,
                COUNT(DISTINCT fp.factura_id) AS total_facturas
            FROM tesoreria.detalles_pago dp
            JOIN ventas.metodos_pago mp ON dp.metodo_pago_id = mp.id
            JOIN ventas.factura_pagos fp ON fp.detalle_pago_id = dp.id
            WHERE dp.sesion_caja_id = ?
              AND mp.codigo IN ('EFECTIVO', 'TARJETA')
        ");
        $stmtVentas->execute([$sesionId]);
        $ventas = $stmtVentas->fetch(PDO::FETCH_ASSOC) ?: ['ventas_efectivo' => 0, 'ventas_tarjeta' => 0, 'total_facturas' => 0];

        // 4. Cobros de CxC en Efectivo y Tarjeta
        $cxcEfectivo = 0.00;
        $cxcTarjeta = 0.00;
        $stmtCxc = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN mp.codigo = 'EFECTIVO' THEN (dp.monto_pagado - COALESCE(dp.monto_devuelta, 0)) * dp.tasa_cambio ELSE 0 END), 0) AS cxc_efectivo,
                COALESCE(SUM(CASE WHEN mp.codigo = 'TARJETA'  THEN dp.monto_pagado * dp.tasa_cambio ELSE 0 END), 0) AS cxc_tarjeta
            FROM tesoreria.detalles_pago dp
            JOIN ventas.metodos_pago mp ON dp.metodo_pago_id = mp.id
            JOIN ventas.recibo_cxc_pagos rcp ON rcp.detalle_pago_id = dp.id
            WHERE dp.sesion_caja_id = ?
              AND mp.codigo IN ('EFECTIVO', 'TARJETA')
        ");
        $stmtCxc->execute([$sesionId]);
        $rowCxc = $stmtCxc->fetch(PDO::FETCH_ASSOC);
        if ($rowCxc) {
            $cxcEfectivo = round(floatval($rowCxc['cxc_efectivo']), 2);
            $cxcTarjeta = round(floatval($rowCxc['cxc_tarjeta']), 2);
        }

        // 5. Fianzas de Consignación en Efectivo y Tarjeta
        $consignacionesEf = 0.00;
        $consignacionesTj = 0.00;
        $stmtCg = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN mp.codigo = 'EFECTIVO' THEN (dp.monto_pagado - COALESCE(dp.monto_devuelta, 0)) * dp.tasa_cambio ELSE 0 END), 0) AS cg_efectivo,
                COALESCE(SUM(CASE WHEN mp.codigo = 'TARJETA'  THEN dp.monto_pagado * dp.tasa_cambio ELSE 0 END), 0) AS cg_tarjeta
            FROM tesoreria.detalles_pago dp
            JOIN ventas.metodos_pago mp ON dp.metodo_pago_id = mp.id
            JOIN ventas.consignacion_garantia_pagos cgp ON cgp.detalle_pago_id = dp.id
            WHERE dp.sesion_caja_id = ?
              AND mp.codigo IN ('EFECTIVO', 'TARJETA')
        ");
        $stmtCg->execute([$sesionId]);
        $rowCg = $stmtCg->fetch(PDO::FETCH_ASSOC);
        if ($rowCg) {
            $consignacionesEf = round(floatval($rowCg['cg_efectivo']), 2);
            $consignacionesTj = round(floatval($rowCg['cg_tarjeta']), 2);
        }

        // 6. Movimientos de Caja Chica (JOIN corregido por seguridad.usuarios)
        $stmtMov = $pdo->prepare("
            SELECT mc.id,
                   mc.tipo_movimiento,
                   mc.monto,
                   mc.concepto,
                   TO_CHAR(mc.fecha_hora, 'HH12:MI AM') AS hora_fmt,
                   COALESCE(NULLIF(TRIM(p.nombre_razon_social), ''), u.username, 'Supervisor') AS supervisor_nombre
            FROM ventas.movimiento_caja mc
            LEFT JOIN seguridad.usuarios u ON mc.supervisor_id = u.usuario_id
            LEFT JOIN identidad.personas p ON u.persona_id = p.persona_id
            WHERE mc.sesion_caja_id = ?
            ORDER BY mc.id ASC
        ");
        $stmtMov->execute([$sesionId]);
        $movimientos = $stmtMov->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ingresosCajaChica = 0.00;
        $egresosCajaChica = 0.00;
        foreach ($movimientos as $m) {
            if ($m['tipo_movimiento'] === 'INGRESO') {
                $ingresosCajaChica = round($ingresosCajaChica + floatval($m['monto']), 2);
            } elseif ($m['tipo_movimiento'] === 'EGRESO') {
                $egresosCajaChica = round($egresosCajaChica + floatval($m['monto']), 2);
            }
        }

        // 7. Devoluciones de Tarjeta (las de efectivo ya entran como EGRESO en ventas.movimiento_caja)
        $stmtDevTj = $pdo->prepare("
            SELECT COALESCE(SUM(d.monto_total * d.tasa_cambio), 0)
            FROM ventas.devoluciones d
            WHERE d.sesion_caja_id = ? AND d.metodo_reembolso = 'TARJETA'
        ");
        $stmtDevTj->execute([$sesionId]);
        $devTarjeta = round(floatval($stmtDevTj->fetchColumn() ?: 0), 2);

        // 8. Detalles guardados en ventas.arqueo_detalles
        $stmtDetArq = $pdo->prepare("
            SELECT ad.metodo_pago_id, mp.codigo, mp.descripcion,
                   ad.monto_declarado_cajero, ad.monto_esperado_sistema, ad.diferencia,
                   ad.cantidad_vouchers, ad.estado_deposito, ad.monto_retenido_fondo
            FROM ventas.arqueo_detalles ad
            JOIN ventas.metodos_pago mp ON ad.metodo_pago_id = mp.id
            WHERE ad.sesion_caja_id = ?
            ORDER BY mp.id ASC
        ");
        $stmtDetArq->execute([$sesionId]);
        $arqueoDetalles = $stmtDetArq->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $fondoInicial = round(floatval($sesion['monto_inicial_efectivo'] ?? 0), 2);
        $ventasEf = round(floatval($ventas['ventas_efectivo'] ?? 0), 2);
        $ventasTj = round(floatval($ventas['ventas_tarjeta'] ?? 0), 2);

        responderJson('success', 'Desglose de auditoría obtenido correctamente.', [
            'data' => [
                'sesion' => $sesion,
                'partidas_efectivo' => [
                    'fondo_inicial' => $fondoInicial,
                    'ventas_efectivo' => $ventasEf,
                    'cxc_efectivo' => $cxcEfectivo,
                    'consignaciones_efectivo' => $consignacionesEf,
                    'ingresos_caja_chica' => $ingresosCajaChica,
                    'devoluciones_efectivo' => 0.00,
                    'egresos_caja_chica' => $egresosCajaChica,
                    'total_esperado' => $esperadoEf,
                    'total_declarado' => round(floatval($sesion['monto_efectivo_declarado'] ?? 0), 2),
                    'diferencia' => round(floatval($sesion['diferencia_descuadre'] ?? 0), 2),
                ],
                'partidas_tarjeta' => [
                    'ventas_tarjeta' => $ventasTj,
                    'cxc_tarjeta' => $cxcTarjeta,
                    'consignaciones_tarjeta' => $consignacionesTj,
                    'devoluciones_tarjeta' => $devTarjeta,
                    'total_esperado' => $esperadoTj,
                ],
                'movimientos_caja_chica' => $movimientos,
                'arqueo_detalles' => $arqueoDetalles,
                'total_facturas' => intval($ventas['total_facturas'] ?? 0),
            ],
        ]);
    }

    responderJson('error', 'Acción no implementada o no reconocida.', [], 400);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderJson('error', $e->getMessage(), [], 400);
}