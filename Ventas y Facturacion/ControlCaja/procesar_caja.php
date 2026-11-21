<?php
/**
 * ============================================================================
 * Módulo 4: Ventas • Proceso 1: Control de Caja (Controlador Backend)
 * Ubicación: c:\xampp\htdocs\Liquor_Store\Ventas y Facturacion\ControlCaja\procesar_caja.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: ventas.sesiones_caja, ventas.cajas_pos, ventas.arqueo_detalles, ventas.movimiento_caja
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';

header('Content-Type: application/json; charset=utf-8');

function responderJson($status, $mensaje, $extra = []) {
    $isSuccess = ($status === 'success');
    echo json_encode(array_merge([
        'status'  => $status,
        'success' => $isSuccess,
        'message' => $mensaje,
        'mensaje' => $mensaje
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$pdo) {
    responderJson('error', 'No hay conexión con la base de datos PostgreSQL.');
}

$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : null;
$datos    = is_array($jsonBody) ? array_merge($_GET, $jsonBody) : array_merge($_GET, $_POST);

$accion   = $datos['accion'] ?? $datos['action'] ?? '';

try {
    // =========================================================================
    // 1. ASIGNAR CAJERO Y ABRIR TURNO DE CAJA (FASE 1)
    // =========================================================================
    if ($accion === 'abrir_turno') {
        $cajaId       = intval($datos['caja_id'] ?? 0);
        $cajeroId     = trim($datos['cajero_id'] ?? '');
        $montoInicial = floatval($datos['monto_inicial_efectivo'] ?? 0.00);

        if ($cajaId <= 0 || empty($cajeroId)) {
            responderJson('error', 'Debe seleccionar tanto la Caja Registradora como el Cajero.');
        }

        if ($montoInicial < 0) {
            responderJson('error', 'El fondo inicial (menudo) no puede ser negativo.');
        }

        // Control de Concurrencia uk_caja_abierta: Verificar que la caja no tenga turno abierto
        $stmtChkCaja = $pdo->prepare("SELECT id FROM ventas.sesiones_caja WHERE caja_id = ? AND estado = 'ABIERTA'");
        $stmtChkCaja->execute([$cajaId]);
        if ($stmtChkCaja->fetch()) {
            responderJson('error', 'La caja seleccionada ya tiene un turno ABIERTO actualmente.');
        }

        // Control de Concurrencia uk_cajero_abierto: Verificar que el cajero no tenga otro turno abierto
        $stmtChkCajero = $pdo->prepare("SELECT id FROM ventas.sesiones_caja WHERE cajero_id = ? AND estado = 'ABIERTA'");
        $stmtChkCajero->execute([$cajeroId]);
        if ($stmtChkCajero->fetch()) {
            responderJson('error', 'El cajero seleccionado ya tiene un turno ABIERTO en otra terminal.');
        }

        $pdo->beginTransaction();

        // Insertar la sesión de caja
        $stmtIns = $pdo->prepare("
            INSERT INTO ventas.sesiones_caja 
            (caja_id, cajero_id, monto_inicial_efectivo, estado, fecha_apertura)
            VALUES (?, ?, ?, 'ABIERTA', CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $stmtIns->execute([$cajaId, $cajeroId, $montoInicial]);
        $sesionId = $stmtIns->fetchColumn();

        // Actualizar el estado de la terminal física a ABIERTA
        $stmtUpdCaja = $pdo->prepare("UPDATE ventas.cajas_pos SET estado = 'ABIERTA' WHERE id = ?");
        $stmtUpdCaja->execute([$cajaId]);

        $pdo->commit();

        responderJson('success', "Turno abierto exitosamente. Sesión #{$sesionId} iniciada con fondo de RD$ " . number_format($montoInicial, 2), [
            'sesion_id' => $sesionId,
            'caja_id'   => $cajaId
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

        $stmtSes = $pdo->prepare("SELECT caja_id, estado FROM ventas.sesiones_caja WHERE id = ? FOR UPDATE");
        $stmtSes->execute([$sesionId]);
        $ses = $stmtSes->fetch(PDO::FETCH_ASSOC);

        if (!$ses || $ses['estado'] !== 'ABIERTA') {
            $pdo->rollBack();
            responderJson('error', 'La sesión no se encuentra en estado ABIERTA para iniciar el arqueo.');
        }

        // Cambiar sesión a EN_CUADRE
        $stmtUpdSes = $pdo->prepare("UPDATE ventas.sesiones_caja SET estado = 'EN_CUADRE' WHERE id = ?");
        $stmtUpdSes->execute([$sesionId]);

        // Cambiar terminal física a EN_ARQUEO
        $stmtUpdCaja = $pdo->prepare("UPDATE ventas.cajas_pos SET estado = 'EN_ARQUEO' WHERE id = ?");
        $stmtUpdCaja->execute([$ses['caja_id']]);

        $pdo->commit();

        responderJson('success', "Terminal bloqueada para Arqueo Ciego. La sesión #{$sesionId} pasó a estado EN CUADRE.");
    }

    // =========================================================================
    // 3. PROCESAR ARQUEO CIEGO Y EMITIR VEREDICTO (FASE 3 Y 4)
    // =========================================================================
    if ($accion === 'procesar_arqueo') {
        $sesionId          = intval($datos['sesion_id'] ?? 0);
        $efectivoDeclarado = max(0, floatval($datos['efectivo_declarado'] ?? 0));
        $tarjetaDeclarada  = max(0, floatval($datos['tarjeta_declarada'] ?? 0));
        $cantVouchers      = max(0, intval($datos['cantidad_vouchers'] ?? 0));
        $montoRetenido     = max(0, floatval($datos['monto_retenido_fondo'] ?? 0));
        $estadoDeposito    = trim($datos['estado_deposito'] ?? 'PENDIENTE');

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
            WHERE s.id = ? FOR UPDATE
        ");
        $stmtSes->execute([$sesionId]);
        $sesion = $stmtSes->fetch(PDO::FETCH_ASSOC);

        if (!$sesion) {
            $pdo->rollBack();
            responderJson('error', 'Sesión no encontrada.');
        }

        if ($sesion['estado'] === 'CERRADA') {
            $pdo->rollBack();
            responderJson('error', 'Esta sesión ya se encuentra CERRADA y liquidada.');
        }

        // =========================================================================
        // CONSULTA OFICIAL DEL ARQUEO CIEGO (ARQUITECTURA OPERATIVA)
        // Solo Facturación, CxC, Consignaciones y Devoluciones (sin egreso_cxp_pagos)
        // =========================================================================
        $sqlArqueoOficial = "
            WITH ingresos_pos AS (
                -- Solo pagos físicos de los 3 módulos de ventas en la sesión activa
                SELECT
                    mp.id AS metodo_pago_id,
                    mp.codigo AS metodo_codigo,
                    SUM((dp.monto_pagado - dp.monto_devuelta) * dp.tasa_cambio) AS total_ingresos_dop
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
                -- Fondo inicial + ingresos/egresos manuales (caja chica y devoluciones en efectivo)
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
                -- Reversiones de tarjeta en la sesión (las de efectivo ya restan vía movimiento_caja)
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
        $stmtOficial = $pdo->prepare($sqlArqueoOficial);
        $stmtOficial->execute([':sesion_caja_id' => $sesionId]);
        $rowsEsperado = $stmtOficial->fetchAll(PDO::FETCH_ASSOC);

        $efectivoEsperado = 0.00;
        $tarjetaEsperada  = 0.00;
        $metodoEfId       = 1;
        $metodoTjId       = 2;

        foreach ($rowsEsperado as $r) {
            if ($r['codigo'] === 'EFECTIVO') {
                $efectivoEsperado = round(floatval($r['monto_esperado_sistema'] ?? 0), 2);
                $metodoEfId       = intval($r['metodo_pago_id']);
            } elseif ($r['codigo'] === 'TARJETA') {
                $tarjetaEsperada = round(floatval($r['monto_esperado_sistema'] ?? 0), 2);
                $metodoTjId      = intval($r['metodo_pago_id']);
            }
        }

        $descuadreEfectivo = round($efectivoDeclarado - $efectivoEsperado, 2);
        $descuadreTarjeta  = round($tarjetaDeclarada - $tarjetaEsperada, 2);

        // Guardar detalle de arqueo en ventas.arqueo_detalles (Efectivo)
        // Por defecto estado_deposito = 'PENDIENTE' (en custodia en bóveda local a la espera de envío al banco)
        $stmtDelArq = $pdo->prepare("DELETE FROM ventas.arqueo_detalles WHERE sesion_caja_id = ?");
        $stmtDelArq->execute([$sesionId]);

        $stmtInsArqEf = $pdo->prepare("
            INSERT INTO ventas.arqueo_detalles 
            (sesion_caja_id, metodo_pago_id, monto_declarado_cajero, monto_esperado_sistema, cantidad_vouchers, estado_deposito, monto_retenido_fondo)
            VALUES (?, ?, ?, ?, 0, 'PENDIENTE', ?)
        ");
        $stmtInsArqEf->execute([$sesionId, $metodoEfId, $efectivoDeclarado, $efectivoEsperado, min($montoRetenido, $efectivoDeclarado)]);

        // Guardar detalle de arqueo en ventas.arqueo_detalles (Tarjeta)
        $stmtInsArqTj = $pdo->prepare("
            INSERT INTO ventas.arqueo_detalles 
            (sesion_caja_id, metodo_pago_id, monto_declarado_cajero, monto_esperado_sistema, cantidad_vouchers, estado_deposito, monto_retenido_fondo)
            VALUES (?, ?, ?, ?, ?, 'PENDIENTE', 0.00)
        ");
        $stmtInsArqTj->execute([$sesionId, $metodoTjId, $tarjetaDeclarada, $tarjetaEsperada, $cantVouchers]);

        // Actualizar cabecera en ventas.sesiones_caja y cerrar sesión
        $stmtUpdSesion = $pdo->prepare("
            UPDATE ventas.sesiones_caja 
            SET estado                   = 'CERRADA',
                fecha_cierre             = CURRENT_TIMESTAMP,
                monto_efectivo_esperado  = ?,
                monto_efectivo_declarado = ?,
                diferencia_descuadre     = ?
            WHERE id = ?
        ");
        $stmtUpdSesion->execute([$efectivoEsperado, $efectivoDeclarado, $descuadreEfectivo, $sesionId]);

        // Liberar la terminal física cambiando estado a CERRADA
        $stmtLibCaja = $pdo->prepare("UPDATE ventas.cajas_pos SET estado = 'CERRADA' WHERE id = ?");
        $stmtLibCaja->execute([$sesion['caja_id']]);

        $pdo->commit();

        // Determinar mensaje del veredicto
        $veredicto = '';
        if ($descuadreEfectivo === 0.00) {
            $veredicto = "🟢 CUADRADO EXACTO: El conteo coincide al centavo con el sistema.";
        } elseif ($descuadreEfectivo < 0) {
            $veredicto = "🔴 FALTANTE EN CAJA: Faltaron RD$ " . number_format(abs($descuadreEfectivo), 2) . " para completar el turno.";
        } else {
            $veredicto = "🟡 SOBRANTE EN CAJA: Se contó un excedente de RD$ " . number_format($descuadreEfectivo, 2) . ".";
        }

        responderJson('success', "Arqueo procesado exitosamente. {$veredicto}", [
            'sesion_id'          => $sesionId,
            'efectivo_esperado'  => $efectivoEsperado,
            'efectivo_declarado' => $efectivoDeclarado,
            'descuadre_efectivo' => $descuadreEfectivo,
            'diferencia'         => $descuadreEfectivo,
            'tarjeta_esperada'   => $tarjetaEsperada,
            'tarjeta_declarada'  => $tarjetaDeclarada,
            'descuadre_tarjeta'  => $descuadreTarjeta,
            'veredicto'          => $veredicto
        ]);
    }

    // =========================================================================
    // 4. REGISTRAR MOVIMIENTO DE CAJA CHICA (INGRESO / EGRESO CON PIN)
    // =========================================================================
    if ($accion === 'movimiento_caja_chica') {
        $sesionId            = intval($datos['sesion_id'] ?? 0);
        $tipo                = strtoupper(trim($datos['tipo_movimiento'] ?? 'EGRESO'));
        $monto               = floatval($datos['monto'] ?? 0);
        $concepto            = trim($datos['concepto'] ?? '');
        $supervisorUsuarioId = trim($datos['supervisor_id'] ?? $datos['usuario_supervisor'] ?? '');
        $pin                 = trim($datos['pin_supervisor'] ?? '');

        if ($sesionId <= 0 || $monto <= 0 || empty($concepto)) {
            responderJson('error', 'Complete el monto mayor a 0 y el concepto obligatorio del movimiento.');
        }

        if (!in_array($tipo, ['INGRESO', 'EGRESO'], true)) {
            responderJson('error', 'Tipo de movimiento no válido (debe ser INGRESO o EGRESO).');
        }

        // Validar sesión activa
        $chkSes = $pdo->prepare("SELECT estado FROM ventas.sesiones_caja WHERE id = ?");
        $chkSes->execute([$sesionId]);
        if ($chkSes->fetchColumn() !== 'ABIERTA') {
            responderJson('error', 'La sesión de caja no está ABIERTA.');
        }

        // Validación estricta: Siempre se requiere USUARIO SUPERVISOR y su PIN
        if (empty($supervisorUsuarioId)) {
            responderJson('error', 'Debe seleccionar el usuario del Supervisor que autoriza la operación.');
        }
        if (empty($pin)) {
            responderJson('error', 'Se requiere el PIN de autorización del Supervisor seleccionado.');
        }

        require_once __DIR__ . '/../../config/auditoria_helper.php';
        try {
            $supAut = validarPinSupervisor($pdo, $supervisorUsuarioId, $pin);
            $supervisorValidoId = $supAut['usuario_id'];
        } catch (Throwable $e) {
            responderJson('error', $e->getMessage());
        }

        $stmtInsMov = $pdo->prepare("
            INSERT INTO ventas.movimiento_caja (sesion_caja_id, tipo_movimiento, monto, concepto, supervisor_id, fecha_hora)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $stmtInsMov->execute([$sesionId, $tipo, $monto, $concepto, $supervisorValidoId]);
        $movId = $stmtInsMov->fetchColumn();

        responderJson('success', "Movimiento de {$tipo} por RD$ " . number_format($monto, 2) . " autorizado correctamente por {$supAut['nombre_completo']}.", [
            'movimiento_id' => $movId
        ]);
    }

    // =========================================================================
    // 5. CONSULTAR DESGLOSE COMPLETO DE AUDITORÍA DE ARQUEO
    // =========================================================================
    if ($accion === 'consultar_desglose') {
        $sesionId = intval($datos['sesion_id'] ?? ($_GET['sesion_id'] ?? 0));
        if ($sesionId <= 0) {
            responderJson('error', 'ID de sesión no proporcionado.');
        }

        // 1. Datos principales de la sesión
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
        ");
        $stmtSes->execute([$sesionId]);
        $sesion = $stmtSes->fetch(PDO::FETCH_ASSOC);

        if (!$sesion) {
            responderJson('error', 'Sesión de caja no encontrada.');
        }

        // 2. Ejecutar la Consulta Oficial del Arqueo Ciego
        $sqlArqueoOficial = "
            WITH ingresos_pos AS (
                -- Solo pagos físicos de los 3 módulos de ventas en la sesión activa
                SELECT
                    mp.id AS metodo_pago_id,
                    mp.codigo AS metodo_codigo,
                    SUM((dp.monto_pagado - dp.monto_devuelta) * dp.tasa_cambio) AS total_ingresos_dop
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
                -- Fondo inicial + ingresos/egresos manuales (caja chica y devoluciones en efectivo)
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
                -- Reversiones de tarjeta en la sesión (las de efectivo ya restan vía movimiento_caja)
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
        $stmtOficial = $pdo->prepare($sqlArqueoOficial);
        $stmtOficial->execute([':sesion_caja_id' => $sesionId]);
        $rowsEsperado = $stmtOficial->fetchAll(PDO::FETCH_ASSOC);

        $esperadoEf = 0.00;
        $esperadoTj = 0.00;
        foreach ($rowsEsperado as $r) {
            if ($r['codigo'] === 'EFECTIVO') $esperadoEf = round(floatval($r['monto_esperado_sistema'] ?? 0), 2);
            if ($r['codigo'] === 'TARJETA')  $esperadoTj = round(floatval($r['monto_esperado_sistema'] ?? 0), 2);
        }

        // 3. Desglose analítico de Ventas POS en Efectivo y Tarjeta
        $stmtVentas = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN mp.codigo = 'EFECTIVO' THEN (dp.monto_pagado - COALESCE(dp.monto_devuelta, 0)) * dp.tasa_cambio ELSE 0 END), 0) AS ventas_efectivo,
                COALESCE(SUM(CASE WHEN mp.codigo = 'TARJETA' THEN dp.monto_pagado * dp.tasa_cambio ELSE 0 END), 0) AS ventas_tarjeta,
                COUNT(DISTINCT fp.factura_id) AS total_facturas
            FROM tesoreria.detalles_pago dp
            JOIN ventas.metodos_pago mp ON dp.metodo_pago_id = mp.id
            JOIN ventas.factura_pagos fp ON fp.detalle_pago_id = dp.id
            WHERE dp.sesion_caja_id = ?
        ");
        $stmtVentas->execute([$sesionId]);
        $ventas = $stmtVentas->fetch(PDO::FETCH_ASSOC) ?: ['ventas_efectivo' => 0, 'ventas_tarjeta' => 0, 'total_facturas' => 0];

        // 4. Cobros de CxC en Efectivo y Tarjeta
        $cxcEfectivo = 0.00;
        $cxcTarjeta  = 0.00;
        try {
            $stmtCxc = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN mp.codigo = 'EFECTIVO' THEN (dp.monto_pagado - COALESCE(dp.monto_devuelta, 0)) * dp.tasa_cambio ELSE 0 END), 0) AS cxc_efectivo,
                    COALESCE(SUM(CASE WHEN mp.codigo = 'TARJETA' THEN dp.monto_pagado * dp.tasa_cambio ELSE 0 END), 0) AS cxc_tarjeta
                FROM tesoreria.detalles_pago dp
                JOIN ventas.metodos_pago mp ON dp.metodo_pago_id = mp.id
                JOIN ventas.recibo_cxc_pagos rcp ON rcp.detalle_pago_id = dp.id
                WHERE dp.sesion_caja_id = ?
            ");
            $stmtCxc->execute([$sesionId]);
            $rowCxc = $stmtCxc->fetch(PDO::FETCH_ASSOC);
            if ($rowCxc) {
                $cxcEfectivo = floatval($rowCxc['cxc_efectivo']);
                $cxcTarjeta  = floatval($rowCxc['cxc_tarjeta']);
            }
        } catch (Throwable $e) {}

        // 5. Fianzas de Consignación en Efectivo y Tarjeta
        $consignacionesEf = 0.00;
        $consignacionesTj = 0.00;
        try {
            $stmtCg = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN mp.codigo = 'EFECTIVO' THEN (dp.monto_pagado - COALESCE(dp.monto_devuelta, 0)) * dp.tasa_cambio ELSE 0 END), 0) AS cg_efectivo,
                    COALESCE(SUM(CASE WHEN mp.codigo = 'TARJETA' THEN dp.monto_pagado * dp.tasa_cambio ELSE 0 END), 0) AS cg_tarjeta
                FROM tesoreria.detalles_pago dp
                JOIN ventas.metodos_pago mp ON dp.metodo_pago_id = mp.id
                JOIN ventas.consignacion_garantia_pagos cgp ON cgp.detalle_pago_id = dp.id
                WHERE dp.sesion_caja_id = ?
            ");
            $stmtCg->execute([$sesionId]);
            $rowCg = $stmtCg->fetch(PDO::FETCH_ASSOC);
            if ($rowCg) {
                $consignacionesEf = floatval($rowCg['cg_efectivo']);
                $consignacionesTj = floatval($rowCg['cg_tarjeta']);
            }
        } catch (Throwable $e) {}

        // 6. Movimientos de Caja Chica (Egresos e Ingresos menores con PIN)
        $stmtMov = $pdo->prepare("
            SELECT mc.id, mc.tipo_movimiento, mc.monto, mc.concepto,
                   TO_CHAR(mc.fecha_hora, 'HH12:MI AM') AS hora_fmt,
                   COALESCE(p.nombre_razon_social, 'Supervisor') AS supervisor_nombre
            FROM ventas.movimiento_caja mc
            LEFT JOIN identidad.personas p ON mc.supervisor_id = p.persona_id
            WHERE mc.sesion_caja_id = ?
            ORDER BY mc.id ASC
        ");
        $stmtMov->execute([$sesionId]);
        $movimientos = $stmtMov->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ingresosCajaChica = 0.00;
        $egresosCajaChica  = 0.00;
        foreach ($movimientos as $m) {
            if ($m['tipo_movimiento'] === 'INGRESO') $ingresosCajaChica += floatval($m['monto']);
            if ($m['tipo_movimiento'] === 'EGRESO')  $egresosCajaChica  += floatval($m['monto']);
        }

        // 7. Devoluciones de Tarjeta
        $devTarjeta = 0.00;
        try {
            $stmtDevTj = $pdo->prepare("
                SELECT COALESCE(SUM(d.monto_total * d.tasa_cambio), 0)
                FROM ventas.devoluciones d
                WHERE d.sesion_caja_id = ? AND d.metodo_reembolso = 'TARJETA'
            ");
            $stmtDevTj->execute([$sesionId]);
            $devTarjeta = floatval($stmtDevTj->fetchColumn() ?: 0);
        } catch (Throwable $e) {}

        // 8. Arqueos de detalles registrados (Efectivo y Tarjeta)
        $stmtDetArq = $pdo->prepare("
            SELECT ad.metodo_pago_id, mp.codigo, mp.descripcion, ad.monto_declarado_cajero, 
                   ad.monto_esperado_sistema, ad.diferencia, ad.cantidad_vouchers, ad.estado_deposito, ad.monto_retenido_fondo
            FROM ventas.arqueo_detalles ad
            JOIN ventas.metodos_pago mp ON ad.metodo_pago_id = mp.id
            WHERE ad.sesion_caja_id = ?
        ");
        $stmtDetArq->execute([$sesionId]);
        $arqueoDetalles = $stmtDetArq->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $fondoInicial = floatval($sesion['monto_inicial_efectivo'] ?? 0);
        $ventasEf     = floatval($ventas['ventas_efectivo'] ?? 0);
        $ventasTj     = floatval($ventas['ventas_tarjeta'] ?? 0);

        responderJson('success', 'Desglose de auditoría obtenido correctamente.', [
            'sesion'               => $sesion,
            'partidas_efectivo'    => [
                'fondo_inicial'          => $fondoInicial,
                'ventas_efectivo'        => $ventasEf,
                'cxc_efectivo'           => $cxcEfectivo,
                'consignaciones_efectivo'=> $consignacionesEf,
                'ingresos_caja_chica'    => $ingresosCajaChica,
                'devoluciones_efectivo'  => 0.00,
                'egresos_caja_chica'     => $egresosCajaChica,
                'total_esperado'         => $esperadoEf,
                'total_declarado'        => floatval($sesion['monto_efectivo_declarado'] ?? 0),
                'diferencia'             => floatval($sesion['diferencia_descuadre'] ?? 0)
            ],
            'partidas_tarjeta'     => [
                'ventas_tarjeta'         => $ventasTj,
                'cxc_tarjeta'            => $cxcTarjeta,
                'consignaciones_tarjeta' => $consignacionesTj,
                'devoluciones_tarjeta'   => $devTarjeta,
                'total_esperado'         => $esperadoTj
            ],
            'movimientos_caja_chica' => $movimientos,
            'arqueo_detalles'        => $arqueoDetalles,
            'total_facturas'         => intval($ventas['total_facturas'] ?? 0)
        ]);
    }

    responderJson('error', 'Acción no implementada o no reconocida.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderJson('error', 'Error en el servidor: ' . $e->getMessage());
}
