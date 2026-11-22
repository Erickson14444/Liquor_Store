<?php
/**
 * ============================================================================
 * CONTROLADOR BACKEND: PROCESO DE DELIVERY (PEDIDOS EN VIVO, HORARIOS Y ZONAS)
 * Archivo: Ventas y Facturacion/Delivery/procesar_delivery.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas afectadas:
 *   - ventas.pedidos_delivery    (Fases 3 y 4: Despacho, Entrega y Eficiencia)
 *   - ventas.facturas            (EMITIDA -> EN_TRANSITO -> ENTREGADA)
 *   - identidad.repartidores     (estado_logistico: BASE / EN_RUTA_CON_ESPACIO)
 *   - ventas.repartidor_horarios (Horarios de trabajo)
 *   - ventas.repartidor_zonas    (Asignación de zonas de cobertura)
 *   - seguridad.auditoria        (Bitácora forense JSONB y métricas de eficiencia)
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!$pdo) {
    echo json_encode(['status' => 'error', 'message' => 'No hay conexión activa con PostgreSQL.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$usuarioSesionId = requerirSesionActiva(true);

if (function_exists('usuarioPuedeAccederDelivery') && !usuarioPuedeAccederDelivery()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso restringido: no posees permisos para el módulo de Delivery.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Admin y Supervisor pueden configurar horarios/zonas; Cajero además puede ver y operar todos los pedidos del local
$puedeGestionarConfig = (function_exists('usuarioEsAdministrador') && usuarioEsAdministrador())
    || !empty($_SESSION['usuario']['es_supervisor']);

$puedeVerTodosPedidos = $puedeGestionarConfig
    || (function_exists('usuarioTienePermiso') && usuarioTienePermiso('CREAR_FACTURA'));

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? ''));

/**
 * Resuelve el empleado_id del usuario autenticado si es repartidor.
 */
function obtenerMiEmpleadoId(PDO $pdo, string $usuarioId): ?string
{
    $stmt = $pdo->prepare("
        SELECT e.empleado_id
        FROM seguridad.usuarios u
        JOIN identidad.empleados e ON e.persona_id = u.persona_id
        WHERE u.usuario_id = ?
        LIMIT 1
    ");
    $stmt->execute([$usuarioId]);
    $empId = $stmt->fetchColumn();
    return $empId ? (string) $empId : null;
}

/**
 * Sincroniza identidad.repartidores.estado_logistico según pedidos EN_CAMINO y capacidad.
 */
function sincronizarEstadoLogisticoRepartidor(PDO $pdo, string $repartidorId): string
{
    $stmtCap = $pdo->prepare("
        SELECT r.capacidad_pedidos,
               (
                   SELECT COUNT(*)
                   FROM ventas.pedidos_delivery pd
                   WHERE pd.repartidor_id = r.empleado_id
                     AND pd.estado_delivery = 'EN_CAMINO'
               ) AS en_camino
        FROM identidad.repartidores r
        WHERE r.empleado_id = ?
        FOR UPDATE
    ");
    $stmtCap->execute([$repartidorId]);
    $row = $stmtCap->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'BASE';
    }

    $enCamino = intval($row['en_camino']);
    $capacidad = max(1, intval($row['capacidad_pedidos']));

    if ($enCamino <= 0) {
        $pdo->prepare("UPDATE identidad.repartidores SET estado_logistico = 'BASE' WHERE empleado_id = ?")
            ->execute([$repartidorId]);
        return 'BASE';
    }

    if ($enCamino < $capacidad) {
        $pdo->prepare("UPDATE identidad.repartidores SET estado_logistico = 'EN_RUTA_CON_ESPACIO' WHERE empleado_id = ?")
            ->execute([$repartidorId]);
        return 'EN_RUTA_CON_ESPACIO';
    }

    foreach (['EN_RUTA_LLENO', 'EN_RUTA', 'OCUPADO', 'EN_RUTA_CON_ESPACIO'] as $candidato) {
        $pdo->exec("SAVEPOINT sp_rep_estado_log");
        try {
            $pdo->prepare("UPDATE identidad.repartidores SET estado_logistico = ? WHERE empleado_id = ?")
                ->execute([$candidato, $repartidorId]);
            $pdo->exec("RELEASE SAVEPOINT sp_rep_estado_log");
            return $candidato;
        } catch (Throwable $e) {
            $pdo->exec("ROLLBACK TO SAVEPOINT sp_rep_estado_log");
        }
    }

    return 'EN_RUTA_CON_ESPACIO';
}

/**
 * Devuelve la lista actualizada de pedidos de delivery (filtrada solo para rol Repartidor).
 */
function consultarPedidosDelivery(PDO $pdo, bool $verTodos, ?string $miEmpleadoId): array
{
    $where = '';
    $params = [];
    if (!$verTodos) {
        if ($miEmpleadoId === null) {
            return [];
        }
        $where = 'WHERE pd.repartidor_id = ?';
        $params[] = $miEmpleadoId;
    }

    $sql = "
        SELECT pd.id,
               pd.factura_id,
               pd.repartidor_id,
               pd.direccion_destino,
               pd.zona_id,
               pd.estado_delivery,
               TO_CHAR(pd.hora_despacho, 'DD/MM/YYYY HH12:MI AM') AS hora_despacho_fmt,
               TO_CHAR(pd.hora_entrega,  'DD/MM/YYYY HH12:MI AM') AS hora_entrega_fmt,
               CASE
                   WHEN pd.hora_despacho IS NOT NULL AND pd.hora_entrega IS NOT NULL
                       THEN ROUND(EXTRACT(EPOCH FROM (pd.hora_entrega - pd.hora_despacho)) / 60.0, 1)
                   WHEN pd.hora_despacho IS NOT NULL AND pd.estado_delivery = 'EN_CAMINO'
                       THEN ROUND(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - pd.hora_despacho)) / 60.0, 1)
                   ELSE NULL
               END AS minutos_viaje,
               f.numero_factura,
               COALESCE(f.ncf, 'S/C') AS ncf,
               f.condicion_pago,
               f.monto_total,
               f.estado AS estado_factura,
               TO_CHAR(f.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_factura_fmt,
               COALESCE(NULLIF(TRIM(f.razon_social_cliente), ''), pc.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
               COALESCE(pc.telefono_principal, '') AS cliente_telefono,
               z.nombre AS zona_nombre,
               z.tarifa_envio,
               z.tiempo_promedio_min,
               e.codigo_empleado AS repartidor_codigo,
               pr.nombre_razon_social AS repartidor_nombre,
               r.tipo_vehiculo,
               COALESCE(r.placa_vehiculo, '') AS placa_vehiculo,
               r.estado_logistico
        FROM ventas.pedidos_delivery pd
        JOIN ventas.facturas f          ON f.id = pd.factura_id
        LEFT JOIN identidad.personas pc ON pc.persona_id = f.cliente_id
        JOIN ventas.zonas_delivery z    ON z.id = pd.zona_id
        JOIN identidad.repartidores r   ON r.empleado_id = pd.repartidor_id
        JOIN identidad.empleados e      ON e.empleado_id = r.empleado_id
        JOIN identidad.personas pr      ON pr.persona_id = e.persona_id
        {$where}
        ORDER BY CASE pd.estado_delivery
                     WHEN 'PENDIENTE' THEN 0
                     WHEN 'EN_CAMINO' THEN 1
                     WHEN 'ENTREGADO' THEN 2
                     ELSE 3
                 END ASC,
                 pd.id DESC
        LIMIT 150
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

try {
    // =========================================================================
    // CONSULTA GET: DETALLE DE PRODUCTOS Y RUTA DE UN PEDIDO DELIVERY
    // =========================================================================
    if ($metodo === 'GET' && $action === 'detalle_pedido') {
        $pedidoId = intval($_GET['pedido_id'] ?? 0);
        if ($pedidoId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Identificador de pedido inválido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmtCab = $pdo->prepare("
            SELECT pd.id, pd.factura_id, pd.repartidor_id, pd.direccion_destino, pd.estado_delivery,
                   TO_CHAR(pd.hora_despacho, 'DD/MM/YYYY HH12:MI AM') AS hora_despacho_fmt,
                   TO_CHAR(pd.hora_entrega,  'DD/MM/YYYY HH12:MI AM') AS hora_entrega_fmt,
                   CASE
                       WHEN pd.hora_despacho IS NOT NULL AND pd.hora_entrega IS NOT NULL
                           THEN ROUND(EXTRACT(EPOCH FROM (pd.hora_entrega - pd.hora_despacho)) / 60.0, 1)
                       WHEN pd.hora_despacho IS NOT NULL AND pd.estado_delivery = 'EN_CAMINO'
                           THEN ROUND(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - pd.hora_despacho)) / 60.0, 1)
                       ELSE NULL
                   END AS minutos_viaje,
                   f.numero_factura, COALESCE(f.ncf, 'S/C') AS ncf, f.condicion_pago, f.monto_total, f.estado AS estado_factura,
                   TO_CHAR(f.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_factura_fmt,
                   COALESCE(NULLIF(TRIM(f.razon_social_cliente), ''), pc.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
                   COALESCE(pc.telefono_principal, 'No registrado') AS cliente_telefono,
                   z.nombre AS zona_nombre, z.tarifa_envio, z.tiempo_promedio_min,
                   e.codigo_empleado AS repartidor_codigo, pr.nombre_razon_social AS repartidor_nombre,
                   r.tipo_vehiculo, COALESCE(r.placa_vehiculo, '') AS placa_vehiculo
            FROM ventas.pedidos_delivery pd
            JOIN ventas.facturas f          ON f.id = pd.factura_id
            LEFT JOIN identidad.personas pc ON pc.persona_id = f.cliente_id
            JOIN ventas.zonas_delivery z    ON z.id = pd.zona_id
            JOIN identidad.repartidores r   ON r.empleado_id = pd.repartidor_id
            JOIN identidad.empleados e      ON e.empleado_id = r.empleado_id
            JOIN identidad.personas pr      ON pr.persona_id = e.persona_id
            WHERE pd.id = ?
            LIMIT 1
        ");
        $stmtCab->execute([$pedidoId]);
        $cab = $stmtCab->fetch(PDO::FETCH_ASSOC);

        if (!$cab) {
            echo json_encode(['status' => 'error', 'message' => 'Pedido de delivery no encontrado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!$puedeVerTodosPedidos) {
            $miEmp = obtenerMiEmpleadoId($pdo, $usuarioSesionId);
            if ($miEmp === null || $miEmp !== (string) $cab['repartidor_id']) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Solo puedes consultar los pedidos asignados a tu ruta.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $stmtDet = $pdo->prepare("
            SELECT fd.cantidad_presentacion, fd.factor_conversion, fd.cantidad_unidades,
                   fd.precio_unitario_his, fd.monto_total,
                   pm.sku,
                   COALESCE(NULLIF(TRIM(pm.nombre), ''), pcb.descripcion_presentacion, pm.sku) AS producto_nombre,
                   COALESCE(pcb.descripcion_presentacion, 'Unidad Base (x1)') AS presentacion
            FROM ventas.factura_detalle fd
            JOIN inventario.productos_maestro pm ON pm.id = fd.producto_maestro_id
            LEFT JOIN inventario.producto_codigos_barra pcb ON pcb.id = fd.codigo_barra_id
            WHERE fd.factura_id = ?
            ORDER BY pm.sku ASC
        ");
        $stmtDet->execute([intval($cab['factura_id'])]);
        $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'status' => 'success',
            'pedido' => $cab,
            'detalles' => $detalles,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo !== 'POST') {
        echo json_encode(['status' => 'error', 'message' => 'Método no permitido. Use POST.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // FASE 3: DESPACHAR PEDIDO (PENDIENTE -> EN_CAMINO | FACTURA -> EN_TRANSITO)
    // =========================================================================
    if ($action === 'despachar_pedido') {
        $pedidoId = intval($_POST['pedido_id'] ?? 0);
        if ($pedidoId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Pedido inválido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $miEmp = obtenerMiEmpleadoId($pdo, $usuarioSesionId);

        $pdo->beginTransaction();

        $stmtPd = $pdo->prepare("
            SELECT pd.id, pd.factura_id, pd.repartidor_id, pd.estado_delivery,
                   f.numero_factura, f.estado AS estado_factura
            FROM ventas.pedidos_delivery pd
            JOIN ventas.facturas f ON f.id = pd.factura_id
            WHERE pd.id = ?
            FOR UPDATE OF pd, f
        ");
        $stmtPd->execute([$pedidoId]);
        $pd = $stmtPd->fetch(PDO::FETCH_ASSOC);

        if (!$pd) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Pedido de delivery no encontrado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!$puedeVerTodosPedidos && ($miEmp === null || $miEmp !== (string) $pd['repartidor_id'])) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Solo el repartidor asignado, cajero o supervisor puede despachar este pedido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (strtoupper((string) $pd['estado_delivery']) !== 'PENDIENTE') {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => "El pedido ya fue procesado (estado actual: {$pd['estado_delivery']})."], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (strtoupper((string) $pd['estado_factura']) === 'ANULADA') {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => "La factura {$pd['numero_factura']} está ANULADA y no puede despacharse."], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 1. Marcar pedido EN_CAMINO y sellar hora_despacho
        $pdo->prepare("
            UPDATE ventas.pedidos_delivery
            SET estado_delivery = 'EN_CAMINO',
                hora_despacho   = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$pedidoId]);

        // 2. Pasar factura asociada a EN_TRANSITO (bloquea anulaciones mientras está en la calle)
        $pdo->prepare("
            UPDATE ventas.facturas
            SET estado = 'EN_TRANSITO'
            WHERE id = ?
        ")->execute([intval($pd['factura_id'])]);

        // 3. Actualizar estado logístico del motorista
        $estadoRepNuevo = sincronizarEstadoLogisticoRepartidor($pdo, (string) $pd['repartidor_id']);

        registrarAuditoria(
            $pdo,
            $usuarioSesionId,
            $puedeGestionarConfig ? $usuarioSesionId : null,
            'DELIVERY_DESPACHAR_PEDIDO',
            'ventas.pedidos_delivery',
            [
                'estado_delivery' => 'PENDIENTE',
                'estado_factura' => $pd['estado_factura'],
            ],
            [
                'pedido_id' => $pedidoId,
                'factura_id' => intval($pd['factura_id']),
                'numero_factura' => $pd['numero_factura'],
                'repartidor_id' => $pd['repartidor_id'],
                'estado_delivery' => 'EN_CAMINO',
                'estado_factura' => 'EN_TRANSITO',
                'estado_logistico' => $estadoRepNuevo,
            ]
        );

        $pdo->commit();

        $pedidosActualizados = consultarPedidosDelivery($pdo, $puedeVerTodosPedidos, $miEmp);

        echo json_encode([
            'status' => 'success',
            'message' => "Pedido de {$pd['numero_factura']} despachado (EN_CAMINO). Factura bloqueada en EN_TRANSITO.",
            'pedidos' => $pedidosActualizados,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // FASE 4: MARCAR ENTREGADO (EN_CAMINO -> ENTREGADO + AUDITORÍA EFICIENCIA)
    // =========================================================================
    if ($action === 'marcar_entregado') {
        $pedidoId = intval($_POST['pedido_id'] ?? 0);
        if ($pedidoId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Pedido inválido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $miEmp = obtenerMiEmpleadoId($pdo, $usuarioSesionId);

        $pdo->beginTransaction();

        $stmtPd = $pdo->prepare("
            SELECT pd.id, pd.factura_id, pd.repartidor_id, pd.estado_delivery, pd.hora_despacho,
                   f.numero_factura, f.estado AS estado_factura,
                   z.nombre AS zona_nombre, z.tiempo_promedio_min
            FROM ventas.pedidos_delivery pd
            JOIN ventas.facturas f       ON f.id = pd.factura_id
            JOIN ventas.zonas_delivery z ON z.id = pd.zona_id
            WHERE pd.id = ?
            FOR UPDATE OF pd, f
        ");
        $stmtPd->execute([$pedidoId]);
        $pd = $stmtPd->fetch(PDO::FETCH_ASSOC);

        if (!$pd) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Pedido de delivery no encontrado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!$puedeVerTodosPedidos && ($miEmp === null || $miEmp !== (string) $pd['repartidor_id'])) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Solo el repartidor asignado, cajero o supervisor puede cerrar esta entrega.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (strtoupper((string) $pd['estado_delivery']) !== 'EN_CAMINO') {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => "Solo se pueden marcar como entregados los pedidos EN_CAMINO (actual: {$pd['estado_delivery']})."], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 1. Sellar hora_entrega y calcular minutos reales del viaje en PostgreSQL
        $stmtEnt = $pdo->prepare("
            UPDATE ventas.pedidos_delivery
            SET estado_delivery = 'ENTREGADO',
                hora_despacho   = COALESCE(hora_despacho, CURRENT_TIMESTAMP),
                hora_entrega    = CURRENT_TIMESTAMP
            WHERE id = ?
            RETURNING ROUND(EXTRACT(EPOCH FROM (hora_entrega - hora_despacho)) / 60.0, 1) AS minutos_reales
        ");
        $stmtEnt->execute([$pedidoId]);
        $minutosReales = floatval($stmtEnt->fetchColumn() ?: 0);

        $metaMin = max(1, intval($pd['tiempo_promedio_min'] ?? 30));
        $diferenciaMin = round($minutosReales - $metaMin, 1);
        $cumplioMeta = ($minutosReales <= $metaMin);
        $estadoEfic = $cumplioMeta ? 'A_TIEMPO' : 'RETRASO';

        // 2. Pasar factura asociada a ENTREGADA
        $pdo->prepare("
            UPDATE ventas.facturas
            SET estado = 'ENTREGADA'
            WHERE id = ?
        ")->execute([intval($pd['factura_id'])]);

        // 3. Liberar espacio de carga en el repartidor
        $estadoRepNuevo = sincronizarEstadoLogisticoRepartidor($pdo, (string) $pd['repartidor_id']);

        // 4. Auditoría Invisible de Eficiencia (para reportes gerenciales)
        registrarAuditoria(
            $pdo,
            $usuarioSesionId,
            $puedeGestionarConfig ? $usuarioSesionId : null,
            $cumplioMeta ? 'DELIVERY_ENTREGA_A_TIEMPO' : 'DELIVERY_ENTREGA_CON_RETRASO',
            'ventas.pedidos_delivery',
            [
                'estado_delivery' => 'EN_CAMINO',
                'estado_factura' => $pd['estado_factura'],
            ],
            [
                'pedido_id' => $pedidoId,
                'factura_id' => intval($pd['factura_id']),
                'numero_factura' => $pd['numero_factura'],
                'repartidor_id' => $pd['repartidor_id'],
                'zona_nombre' => $pd['zona_nombre'],
                'estado_delivery' => 'ENTREGADO',
                'estado_factura' => 'ENTREGADA',
                'estado_logistico' => $estadoRepNuevo,
                'tiempo_promedio_min' => $metaMin,
                'minutos_reales' => $minutosReales,
                'diferencia_min' => $diferenciaMin,
                'eficiencia' => $estadoEfic,
            ]
        );

        $pdo->commit();

        $pedidosActualizados = consultarPedidosDelivery($pdo, $puedeVerTodosPedidos, $miEmp);

        $msgEficiencia = $cumplioMeta
            ? "A tiempo ({$minutosReales} min vs meta de {$metaMin} min)."
            : "Con retraso de +{$diferenciaMin} min ({$minutosReales} min vs meta de {$metaMin} min).";

        echo json_encode([
            'status' => 'success',
            'message' => "Entrega de {$pd['numero_factura']} confirmada. {$msgEficiencia}",
            'pedidos' => $pedidosActualizados,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // ACCIONES DE PARAMETRIZACIÓN (HORARIOS Y ZONAS — SOLO ADMIN / SUPERVISOR)
    // =========================================================================
    if (!$puedeGestionarConfig) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Solo Supervisores y Administradores pueden modificar horarios y zonas de repartidores.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------------------
    // 1. GUARDAR / EDITAR HORARIO DE TRABAJO (SOPORTA UNO O MÚLTIPLES DÍAS)
    // -------------------------------------------------------------------------
    if ($action === 'guardar_horario') {
        $repartidorId = trim($_POST['repartidor_id'] ?? '');
        $horaInicio = trim($_POST['hora_inicio'] ?? '');
        $horaFin = trim($_POST['hora_fin'] ?? '');

        $diasRaw = $_POST['dias_semana'] ?? ($_POST['dia_semana'] ?? '');
        $diasInput = is_array($diasRaw) ? $diasRaw : explode(',', (string) $diasRaw);

        $diasSeleccionados = [];
        foreach ($diasInput as $d) {
            $dInt = intval(trim((string) $d));
            if ($dInt >= 1 && $dInt <= 7 && !in_array($dInt, $diasSeleccionados, true)) {
                $diasSeleccionados[] = $dInt;
            }
        }
        sort($diasSeleccionados);

        $origRepartidorId = trim($_POST['orig_repartidor_id'] ?? '');
        $origDiaSemana = intval($_POST['orig_dia_semana'] ?? 0);
        $origHoraInicio = trim($_POST['orig_hora_inicio'] ?? '');

        if ($repartidorId === '' || empty($diasSeleccionados) || $horaInicio === '' || $horaFin === '') {
            echo json_encode(['status' => 'error', 'message' => 'Seleccione el Repartidor, al menos un Día de la semana, la Hora de Inicio y la Hora de Fin.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (strtotime($horaFin) <= strtotime($horaInicio)) {
            echo json_encode(['status' => 'error', 'message' => "La Hora de Fin ({$horaFin}) debe ser posterior a la Hora de Inicio ({$horaInicio})."], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $esEdicion = ($origRepartidorId !== '' && $origDiaSemana >= 1 && $origDiaSemana <= 7 && $origHoraInicio !== '');
        $diasNombres = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
        $conflictos = [];

        foreach ($diasSeleccionados as $dia) {
            $sqlCheck = "
                SELECT dia_semana, TO_CHAR(hora_inicio, 'HH24:MI') AS ini, TO_CHAR(hora_fin, 'HH24:MI') AS fin
                FROM ventas.repartidor_horarios
                WHERE repartidor_id = :rep AND dia_semana = :dia
            ";
            $paramsCheck = [':rep' => $repartidorId, ':dia' => $dia];

            if ($esEdicion) {
                $sqlCheck .= " AND NOT (repartidor_id = :orig_rep AND dia_semana = :orig_dia AND TO_CHAR(hora_inicio, 'HH24:MI') = :orig_ini)";
                $paramsCheck[':orig_rep'] = $origRepartidorId;
                $paramsCheck[':orig_dia'] = $origDiaSemana;
                $paramsCheck[':orig_ini'] = substr($origHoraInicio, 0, 5);
            }

            $stmtCheck = $pdo->prepare($sqlCheck);
            $stmtCheck->execute($paramsCheck);
            foreach ($stmtCheck->fetchAll(PDO::FETCH_ASSOC) as $ex) {
                $nomDia = $diasNombres[$ex['dia_semana']] ?? ('Día ' . $ex['dia_semana']);
                $conflictos[] = "{$nomDia} ({$ex['ini']} - {$ex['fin']})";
            }
        }

        if (!empty($conflictos)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'El repartidor ya tiene un horario existente para: ' . implode(', ', array_unique($conflictos)) . '.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->beginTransaction();

        if ($esEdicion) {
            $pdo->prepare("
                DELETE FROM ventas.repartidor_horarios
                WHERE repartidor_id = :orig_rep
                  AND dia_semana    = :orig_dia
                  AND TO_CHAR(hora_inicio, 'HH24:MI') = :orig_ini
            ")->execute([
                        ':orig_rep' => $origRepartidorId,
                        ':orig_dia' => $origDiaSemana,
                        ':orig_ini' => substr($origHoraInicio, 0, 5),
                    ]);
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO ventas.repartidor_horarios (repartidor_id, dia_semana, hora_inicio, hora_fin)
            VALUES (:rep, :dia, :ini, :fin)
        ");
        foreach ($diasSeleccionados as $dia) {
            $stmtIns->execute([':rep' => $repartidorId, ':dia' => $dia, ':ini' => $horaInicio, ':fin' => $horaFin]);
        }

        $pdo->commit();

        $horariosActualizados = $pdo->query("
            SELECT h.repartidor_id,
                   h.dia_semana,
                   TO_CHAR(h.hora_inicio, 'HH24:MI') AS hora_inicio,
                   TO_CHAR(h.hora_fin, 'HH24:MI')    AS hora_fin,
                   r.tipo_vehiculo,
                   r.placa_vehiculo,
                   r.estado_logistico,
                   e.codigo_empleado,
                   p.nombre_razon_social
            FROM ventas.repartidor_horarios h
            JOIN identidad.repartidores r ON r.empleado_id = h.repartidor_id
            JOIN identidad.empleados e    ON e.empleado_id = r.empleado_id
            JOIN identidad.personas p     ON p.persona_id = e.persona_id
            ORDER BY h.dia_semana ASC, h.hora_inicio ASC, p.nombre_razon_social ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $cantDias = count($diasSeleccionados);
        echo json_encode([
            'status' => 'success',
            'message' => $esEdicion
                ? 'Horario de trabajo actualizado correctamente.'
                : ($cantDias > 1 ? "Se asignaron {$cantDias} días de horario correctamente." : 'Horario de trabajo asignado correctamente.'),
            'horarios' => $horariosActualizados,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------------------
    // 2. ELIMINAR HORARIO DE TRABAJO
    // -------------------------------------------------------------------------
    if ($action === 'eliminar_horario') {
        $repartidorId = trim($_POST['repartidor_id'] ?? '');
        $diaSemana = intval($_POST['dia_semana'] ?? 0);
        $horaInicio = trim($_POST['hora_inicio'] ?? '');

        if ($repartidorId === '' || $diaSemana < 1 || $diaSemana > 7 || $horaInicio === '') {
            echo json_encode(['status' => 'error', 'message' => 'Parámetros incompletos para eliminar el horario.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->prepare("
            DELETE FROM ventas.repartidor_horarios
            WHERE repartidor_id = :rep AND dia_semana = :dia AND TO_CHAR(hora_inicio, 'HH24:MI') = :ini
        ")->execute([':rep' => $repartidorId, ':dia' => $diaSemana, ':ini' => substr($horaInicio, 0, 5)]);

        echo json_encode(['status' => 'success', 'message' => 'Horario eliminado correctamente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------------------
    // 3. GUARDAR / EDITAR ASIGNACIÓN DE ZONA
    // -------------------------------------------------------------------------
    if ($action === 'guardar_zona') {
        $repartidorId = trim($_POST['repartidor_id'] ?? '');
        $zonaId = intval($_POST['zona_id'] ?? 0);
        $esRutaPrincipal = filter_var($_POST['es_ruta_principal'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $origRepartidorId = trim($_POST['orig_repartidor_id'] ?? '');
        $origZonaId = intval($_POST['orig_zona_id'] ?? 0);

        if ($repartidorId === '' || $zonaId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Seleccione tanto el Repartidor como la Zona de Delivery.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->beginTransaction();
        $esEdicion = ($origRepartidorId !== '' && $origZonaId > 0);

        if ($esEdicion) {
            if ($origRepartidorId !== $repartidorId || $origZonaId !== $zonaId) {
                $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM ventas.repartidor_zonas WHERE repartidor_id = :rep AND zona_id = :zon");
                $stmtDup->execute([':rep' => $repartidorId, ':zon' => $zonaId]);
                if ((int) $stmtDup->fetchColumn() > 0) {
                    $pdo->rollBack();
                    echo json_encode(['status' => 'error', 'message' => 'Este repartidor ya tiene asignada esa zona de delivery.'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }

            $stmtUpd = $pdo->prepare("
                UPDATE ventas.repartidor_zonas
                SET repartidor_id = :rep, zona_id = :zon, es_ruta_principal = :prin
                WHERE repartidor_id = :orig_rep AND zona_id = :orig_zon
            ");
            $stmtUpd->bindValue(':rep', $repartidorId, PDO::PARAM_STR);
            $stmtUpd->bindValue(':zon', $zonaId, PDO::PARAM_INT);
            $stmtUpd->bindValue(':prin', $esRutaPrincipal, PDO::PARAM_BOOL);
            $stmtUpd->bindValue(':orig_rep', $origRepartidorId, PDO::PARAM_STR);
            $stmtUpd->bindValue(':orig_zon', $origZonaId, PDO::PARAM_INT);
            $stmtUpd->execute();
        } else {
            $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM ventas.repartidor_zonas WHERE repartidor_id = :rep AND zona_id = :zon");
            $stmtDup->execute([':rep' => $repartidorId, ':zon' => $zonaId]);
            if ((int) $stmtDup->fetchColumn() > 0) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Esta zona ya se encuentra asignada a este repartidor.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmtIns = $pdo->prepare("INSERT INTO ventas.repartidor_zonas (repartidor_id, zona_id, es_ruta_principal) VALUES (:rep, :zon, :prin)");
            $stmtIns->bindValue(':rep', $repartidorId, PDO::PARAM_STR);
            $stmtIns->bindValue(':zon', $zonaId, PDO::PARAM_INT);
            $stmtIns->bindValue(':prin', $esRutaPrincipal, PDO::PARAM_BOOL);
            $stmtIns->execute();
        }

        $stmtRow = $pdo->prepare("
            SELECT rz.repartidor_id, rz.zona_id, rz.es_ruta_principal,
                   r.tipo_vehiculo, r.placa_vehiculo, r.estado_logistico, r.capacidad_pedidos,
                   e.codigo_empleado, p.nombre_razon_social,
                   z.nombre AS zona_nombre, z.tarifa_envio AS zona_tarifa,
                   z.tiempo_promedio_min AS zona_tiempo, z.activa AS zona_activa
            FROM ventas.repartidor_zonas rz
            JOIN identidad.repartidores r ON r.empleado_id = rz.repartidor_id
            JOIN identidad.empleados e    ON e.empleado_id = r.empleado_id
            JOIN identidad.personas p     ON p.persona_id = e.persona_id
            JOIN ventas.zonas_delivery z  ON z.id = rz.zona_id
            WHERE rz.repartidor_id = :rep AND rz.zona_id = :zon
            LIMIT 1
        ");
        $stmtRow->execute([':rep' => $repartidorId, ':zon' => $zonaId]);
        $registro = $stmtRow->fetch(PDO::FETCH_ASSOC);

        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'message' => $esEdicion ? 'Asignación de zona actualizada correctamente.' : 'Zona de delivery asignada al repartidor correctamente.',
            'registro' => $registro,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------------------
    // 4. TOGGLE RUTA PRINCIPAL
    // -------------------------------------------------------------------------
    if ($action === 'toggle_ruta_principal') {
        $repartidorId = trim($_POST['repartidor_id'] ?? '');
        $zonaId = intval($_POST['zona_id'] ?? 0);
        $esRutaPrincipal = filter_var($_POST['es_ruta_principal'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($repartidorId === '' || $zonaId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmtToggle = $pdo->prepare("UPDATE ventas.repartidor_zonas SET es_ruta_principal = :prin WHERE repartidor_id = :rep AND zona_id = :zon");
        $stmtToggle->bindValue(':prin', $esRutaPrincipal, PDO::PARAM_BOOL);
        $stmtToggle->bindValue(':rep', $repartidorId, PDO::PARAM_STR);
        $stmtToggle->bindValue(':zon', $zonaId, PDO::PARAM_INT);
        $stmtToggle->execute();

        echo json_encode([
            'status' => 'success',
            'es_ruta_principal' => $esRutaPrincipal,
            'message' => $esRutaPrincipal ? 'Marcada como Ruta Principal.' : 'Cambiada a Ruta Secundaria.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------------------
    // 5. ELIMINAR ASIGNACIÓN DE ZONA
    // -------------------------------------------------------------------------
    if ($action === 'eliminar_zona') {
        $repartidorId = trim($_POST['repartidor_id'] ?? '');
        $zonaId = intval($_POST['zona_id'] ?? 0);

        if ($repartidorId === '' || $zonaId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Parámetros incompletos.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->prepare("DELETE FROM ventas.repartidor_zonas WHERE repartidor_id = :rep AND zona_id = :zon")
            ->execute([':rep' => $repartidorId, ':zon' => $zonaId]);

        echo json_encode(['status' => 'success', 'message' => 'Asignación de zona eliminada correctamente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Acción no reconocida: ' . htmlspecialchars($action)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'Error en PostgreSQL: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}