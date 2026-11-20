<?php
/**
 * ============================================================================
 * CONTROLADOR BACKEND: PROCESO DE DELIVERY (HORARIOS Y ZONAS DE REPARTO)
 * Archivo: Ventas y Facturacion/Delivery/procesar_delivery.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas afectadas:
 *   - ventas.repartidor_horarios (repartidor_id, dia_semana, hora_inicio, hora_fin)
 *   - ventas.repartidor_zonas    (repartidor_id, zona_id, es_ruta_principal)
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido. Use POST.']);
    exit;
}

if (!$pdo) {
    echo json_encode(['status' => 'error', 'message' => 'No hay conexión activa con PostgreSQL.']);
    exit;
}

$action = trim($_POST['action'] ?? '');

try {
    // =========================================================================
    // 1. GUARDAR / EDITAR HORARIO DE TRABAJO (SOPORTA UNO O MÚLTIPLES DÍAS)
    // =========================================================================
    if ($action === 'guardar_horario') {
        $repartidorId = trim($_POST['repartidor_id'] ?? '');
        $horaInicio   = trim($_POST['hora_inicio'] ?? '');
        $horaFin      = trim($_POST['hora_fin'] ?? '');

        // Recibir uno o varios días (ej. "1,3,5" o array)
        $diasRaw = $_POST['dias_semana'] ?? ($_POST['dia_semana'] ?? '');
        if (is_array($diasRaw)) {
            $diasInput = $diasRaw;
        } else {
            $diasInput = explode(',', (string)$diasRaw);
        }

        $diasSeleccionados = [];
        foreach ($diasInput as $d) {
            $dInt = intval(trim((string)$d));
            if ($dInt >= 1 && $dInt <= 7 && !in_array($dInt, $diasSeleccionados, true)) {
                $diasSeleccionados[] = $dInt;
            }
        }
        sort($diasSeleccionados);

        $origRepartidorId = trim($_POST['orig_repartidor_id'] ?? '');
        $origDiaSemana    = intval($_POST['orig_dia_semana'] ?? 0);
        $origHoraInicio   = trim($_POST['orig_hora_inicio'] ?? '');

        if ($repartidorId === '' || empty($diasSeleccionados) || $horaInicio === '' || $horaFin === '') {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Seleccione el Repartidor, al menos un Día de la semana, la Hora de Inicio y la Hora de Fin.'
            ]);
            exit;
        }

        // Validación: hora_fin > hora_inicio
        if (strtotime($horaFin) <= strtotime($horaInicio)) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'La Hora de Fin (' . $horaFin . ') debe ser posterior a la Hora de Inicio (' . $horaInicio . ').'
            ]);
            exit;
        }

        $esEdicion = ($origRepartidorId !== '' && $origDiaSemana >= 1 && $origDiaSemana <= 7 && $origHoraInicio !== '');

        // Validación: Verificar si ya existe horario para este repartidor en alguno de los días seleccionados
        $diasNombres = [
            1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles',
            4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'
        ];

        $conflictos = [];
        foreach ($diasSeleccionados as $dia) {
            $sqlCheck = "
                SELECT dia_semana, TO_CHAR(hora_inicio, 'HH24:MI') AS ini, TO_CHAR(hora_fin, 'HH24:MI') AS fin
                FROM ventas.repartidor_horarios
                WHERE repartidor_id = :rep
                  AND dia_semana    = :dia
            ";
            $paramsCheck = [
                ':rep' => $repartidorId,
                ':dia' => $dia
            ];

            if ($esEdicion) {
                $sqlCheck .= " AND NOT (repartidor_id = :orig_rep AND dia_semana = :orig_dia AND TO_CHAR(hora_inicio, 'HH24:MI') = :orig_ini)";
                $paramsCheck[':orig_rep'] = $origRepartidorId;
                $paramsCheck[':orig_dia'] = $origDiaSemana;
                $paramsCheck[':orig_ini'] = substr($origHoraInicio, 0, 5);
            }

            $stmtCheck = $pdo->prepare($sqlCheck);
            $stmtCheck->execute($paramsCheck);
            $existentes = $stmtCheck->fetchAll(PDO::FETCH_ASSOC);

            foreach ($existentes as $ex) {
                $nomDia = $diasNombres[$ex['dia_semana']] ?? ('Día ' . $ex['dia_semana']);
                $conflictos[] = "{$nomDia} ({$ex['ini']} - {$ex['fin']})";
            }
        }

        if (!empty($conflictos)) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'El repartidor ya tiene un horario existente para: ' . implode(', ', array_unique($conflictos)) . '. No se permiten horarios repetidos.'
            ]);
            exit;
        }

        $pdo->beginTransaction();

        if ($esEdicion) {
            // Eliminar el registro original antes de insertar los días seleccionados
            $stmtDelOrig = $pdo->prepare("
                DELETE FROM ventas.repartidor_horarios
                WHERE repartidor_id = :orig_rep
                  AND dia_semana    = :orig_dia
                  AND TO_CHAR(hora_inicio, 'HH24:MI') = :orig_ini
            ");
            $stmtDelOrig->execute([
                ':orig_rep' => $origRepartidorId,
                ':orig_dia' => $origDiaSemana,
                ':orig_ini' => substr($origHoraInicio, 0, 5)
            ]);
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO ventas.repartidor_horarios (repartidor_id, dia_semana, hora_inicio, hora_fin)
            VALUES (:rep, :dia, :ini, :fin)
        ");

        foreach ($diasSeleccionados as $dia) {
            $stmtIns->execute([
                ':rep' => $repartidorId,
                ':dia' => $dia,
                ':ini' => $horaInicio,
                ':fin' => $horaFin
            ]);
        }

        $pdo->commit();

        // Consultar la lista completa actualizada de horarios para sincronizar la tabla
        $stmtAll = $pdo->query("
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
        ");
        $horariosActualizados = $stmtAll->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $pdo->commit();

        $cantDias = count($diasSeleccionados);
        echo json_encode([
            'status'    => 'success',
            'message'   => $esEdicion
                ? 'Horario de trabajo actualizado correctamente.'
                : ($cantDias > 1 ? "Se asignaron {$cantDias} días de horario correctamente." : 'Horario de trabajo asignado correctamente.'),
            'horarios'  => $horariosActualizados
        ]);
        exit;
    }

    // =========================================================================
    // 2. ELIMINAR HORARIO DE TRABAJO
    // =========================================================================
    if ($action === 'eliminar_horario') {
        $repartidorId = trim($_POST['repartidor_id'] ?? '');
        $diaSemana    = intval($_POST['dia_semana'] ?? 0);
        $horaInicio   = trim($_POST['hora_inicio'] ?? '');

        if ($repartidorId === '' || $diaSemana < 1 || $diaSemana > 7 || $horaInicio === '') {
            echo json_encode(['status' => 'error', 'message' => 'Parámetros incompletos para eliminar el horario.']);
            exit;
        }

        $stmtDel = $pdo->prepare("
            DELETE FROM ventas.repartidor_horarios
            WHERE repartidor_id = :rep
              AND dia_semana    = :dia
              AND TO_CHAR(hora_inicio, 'HH24:MI') = :ini
        ");
        $stmtDel->execute([
            ':rep' => $repartidorId,
            ':dia' => $diaSemana,
            ':ini' => substr($horaInicio, 0, 5)
        ]);

        echo json_encode([
            'status'  => 'success',
            'message' => 'Horario eliminado correctamente.'
        ]);
        exit;
    }

    // =========================================================================
    // 3. GUARDAR / EDITAR ASIGNACIÓN DE ZONA (ventas.repartidor_zonas)
    // =========================================================================
    if ($action === 'guardar_zona') {
        $repartidorId    = trim($_POST['repartidor_id'] ?? '');
        $zonaId          = intval($_POST['zona_id'] ?? 0);
        $esRutaPrincipal = filter_var($_POST['es_ruta_principal'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $origRepartidorId = trim($_POST['orig_repartidor_id'] ?? '');
        $origZonaId       = intval($_POST['orig_zona_id'] ?? 0);

        if ($repartidorId === '' || $zonaId <= 0) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Seleccione tanto el Repartidor como la Zona de Delivery.'
            ]);
            exit;
        }

        $pdo->beginTransaction();

        $esEdicion = ($origRepartidorId !== '' && $origZonaId > 0);

        if ($esEdicion) {
            $cambioPK = ($origRepartidorId !== $repartidorId || $origZonaId !== $zonaId);

            if ($cambioPK) {
                $stmtDup = $pdo->prepare("
                    SELECT COUNT(*) FROM ventas.repartidor_zonas
                    WHERE repartidor_id = :rep AND zona_id = :zon
                ");
                $stmtDup->execute([':rep' => $repartidorId, ':zon' => $zonaId]);
                if ((int)$stmtDup->fetchColumn() > 0) {
                    $pdo->rollBack();
                    echo json_encode([
                        'status'  => 'error',
                        'message' => 'Este repartidor ya tiene asignada esa zona de delivery.'
                    ]);
                    exit;
                }
            }

            $stmtUpd = $pdo->prepare("
                UPDATE ventas.repartidor_zonas
                SET repartidor_id     = :rep,
                    zona_id           = :zon,
                    es_ruta_principal = :prin
                WHERE repartidor_id = :orig_rep
                  AND zona_id       = :orig_zon
            ");
            $stmtUpd->bindValue(':rep',      $repartidorId,    PDO::PARAM_STR);
            $stmtUpd->bindValue(':zon',      $zonaId,          PDO::PARAM_INT);
            $stmtUpd->bindValue(':prin',     $esRutaPrincipal, PDO::PARAM_BOOL);
            $stmtUpd->bindValue(':orig_rep', $origRepartidorId, PDO::PARAM_STR);
            $stmtUpd->bindValue(':orig_zon', $origZonaId,       PDO::PARAM_INT);
            $stmtUpd->execute();
        } else {
            // Verificar duplicado en PK (repartidor_id, zona_id)
            $stmtDup = $pdo->prepare("
                SELECT COUNT(*) FROM ventas.repartidor_zonas
                WHERE repartidor_id = :rep AND zona_id = :zon
            ");
            $stmtDup->execute([':rep' => $repartidorId, ':zon' => $zonaId]);
            if ((int)$stmtDup->fetchColumn() > 0) {
                $pdo->rollBack();
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Esta zona ya se encuentra asignada a este repartidor.'
                ]);
                exit;
            }

            $stmtIns = $pdo->prepare("
                INSERT INTO ventas.repartidor_zonas (repartidor_id, zona_id, es_ruta_principal)
                VALUES (:rep, :zon, :prin)
            ");
            $stmtIns->bindValue(':rep',  $repartidorId,    PDO::PARAM_STR);
            $stmtIns->bindValue(':zon',  $zonaId,          PDO::PARAM_INT);
            $stmtIns->bindValue(':prin', $esRutaPrincipal, PDO::PARAM_BOOL);
            $stmtIns->execute();
        }

        // Consultar el registro enriquecido para devolverlo al frontend
        $stmtRow = $pdo->prepare("
            SELECT rz.repartidor_id,
                   rz.zona_id,
                   rz.es_ruta_principal,
                   r.tipo_vehiculo,
                   r.placa_vehiculo,
                   r.estado_logistico,
                   r.capacidad_pedidos,
                   e.codigo_empleado,
                   p.nombre_razon_social,
                   z.nombre              AS zona_nombre,
                   z.tarifa_envio        AS zona_tarifa,
                   z.tiempo_promedio_min AS zona_tiempo,
                   z.activa              AS zona_activa
            FROM ventas.repartidor_zonas rz
            JOIN identidad.repartidores r ON r.empleado_id = rz.repartidor_id
            JOIN identidad.empleados e    ON e.empleado_id = r.empleado_id
            JOIN identidad.personas p     ON p.persona_id = e.persona_id
            JOIN ventas.zonas_delivery z  ON z.id = rz.zona_id
            WHERE rz.repartidor_id = :rep
              AND rz.zona_id       = :zon
            LIMIT 1
        ");
        $stmtRow->execute([':rep' => $repartidorId, ':zon' => $zonaId]);
        $registro = $stmtRow->fetch(PDO::FETCH_ASSOC);

        $pdo->commit();

        echo json_encode([
            'status'   => 'success',
            'message'  => $esEdicion ? 'Asignación de zona actualizada correctamente.' : 'Zona de delivery asignada al repartidor correctamente.',
            'registro' => $registro
        ]);
        exit;
    }

    // =========================================================================
    // 4. CAMBIAR RUTA PRINCIPAL (TOGGLE es_ruta_principal EN ventas.repartidor_zonas)
    // =========================================================================
    if ($action === 'toggle_ruta_principal') {
        $repartidorId    = trim($_POST['repartidor_id'] ?? '');
        $zonaId          = intval($_POST['zona_id'] ?? 0);
        $esRutaPrincipal = filter_var($_POST['es_ruta_principal'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($repartidorId === '' || $zonaId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos para actualizar la ruta.']);
            exit;
        }

        $stmtToggle = $pdo->prepare("
            UPDATE ventas.repartidor_zonas
            SET es_ruta_principal = :prin
            WHERE repartidor_id = :rep
              AND zona_id       = :zon
        ");
        $stmtToggle->bindValue(':prin', $esRutaPrincipal, PDO::PARAM_BOOL);
        $stmtToggle->bindValue(':rep',  $repartidorId,    PDO::PARAM_STR);
        $stmtToggle->bindValue(':zon',  $zonaId,          PDO::PARAM_INT);
        $stmtToggle->execute();

        echo json_encode([
            'status'            => 'success',
            'es_ruta_principal' => $esRutaPrincipal,
            'message'           => $esRutaPrincipal ? 'Marcada como Ruta Principal del repartidor.' : 'Cambiada a Ruta Secundaria.'
        ]);
        exit;
    }

    // =========================================================================
    // 5. ELIMINAR ASIGNACIÓN DE ZONA (ventas.repartidor_zonas)
    // =========================================================================
    if ($action === 'eliminar_zona') {
        $repartidorId = trim($_POST['repartidor_id'] ?? '');
        $zonaId       = intval($_POST['zona_id'] ?? 0);

        if ($repartidorId === '' || $zonaId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Parámetros incompletos para desvincular la zona.']);
            exit;
        }

        $stmtDel = $pdo->prepare("
            DELETE FROM ventas.repartidor_zonas
            WHERE repartidor_id = :rep
              AND zona_id       = :zon
        ");
        $stmtDel->execute([
            ':rep' => $repartidorId,
            ':zon' => $zonaId
        ]);

        echo json_encode([
            'status'  => 'success',
            'message' => 'Asignación de zona eliminada correctamente.'
        ]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Acción no reconocida: ' . htmlspecialchars($action)]);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'status'  => 'error',
        'message' => 'Error en PostgreSQL: ' . $e->getMessage()
    ]);
}

