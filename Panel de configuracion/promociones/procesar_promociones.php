<?php
/**
 * Procesador de Promociones y Motor de Reglas (Backend Controller)
 * House & Ice • Sistema Administrativo
 * Tablas: promociones.promociones_campanas, promociones.promociones_detalles
 */

require_once __DIR__ . '/../../config/conexion.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || 
          (isset($_POST['format']) && $_POST['format'] === 'json');

function responder($status, $mensaje, $data = []) {
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['status' => $status, 'message' => $mensaje], $data));
        exit;
    } else {
        $tipo = ($status === 'success') ? 'exito' : 'error';
        header("Location: promociones.php?msg=" . urlencode($mensaje) . "&tipo=" . $tipo);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder('error', 'Método HTTP no permitido.');
}

$accion = $_POST['accion'] ?? $_POST['action'] ?? 'crear';

try {
    if ($accion === 'crear' || $accion === 'editar') {
        $id = !empty($_POST['promocion_id']) ? intval($_POST['promocion_id']) : (!empty($_POST['id']) ? intval($_POST['id']) : null);
        $nombre = trim($_POST['nombre_promocion'] ?? '');
        $tipo = trim($_POST['tipo_promocion'] ?? 'VOLUMEN');
        $fechaInicio = !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : date('Y-m-d');
        $fechaFin = !empty($_POST['fecha_fin']) ? $_POST['fecha_fin'] : date('Y-m-d', strtotime('+30 days'));
        $horaInicio = !empty($_POST['hora_inicio']) ? $_POST['hora_inicio'] : null;
        $horaFin = !empty($_POST['hora_fin']) ? $_POST['hora_fin'] : null;
        $diasSemana = !empty($_POST['dias_semana']) ? (is_array($_POST['dias_semana']) ? implode(',', $_POST['dias_semana']) : $_POST['dias_semana']) : 'TODOS';
        $montoMinimo = floatval($_POST['monto_minimo_factura'] ?? 0);
        $estado = trim($_POST['estado'] ?? 'ACTIVA');

        // Detalles de regla
        $condicionProductoId = !empty($_POST['condicion_producto_id']) ? intval($_POST['condicion_producto_id']) : null;
        $cantidadRequerida = intval($_POST['cantidad_requerida'] ?? 1);
        $beneficioPorcentaje = !empty($_POST['beneficio_porcentaje']) ? floatval($_POST['beneficio_porcentaje']) : null;
        $beneficioMontoFijo = !empty($_POST['beneficio_monto_fijo']) ? floatval($_POST['beneficio_monto_fijo']) : null;

        if (empty($nombre)) {
            throw new Exception("El nombre de la promoción es obligatorio.");
        }

        if (strtotime($fechaFin) < strtotime($fechaInicio)) {
            throw new Exception("La fecha de fin no puede ser anterior a la de inicio.");
        }

        $pdo->beginTransaction();

        if ($accion === 'crear') {
            $stmtCamp = $pdo->prepare("
                INSERT INTO promociones.promociones_campanas 
                (nombre_promocion, tipo_promocion, fecha_inicio, fecha_fin, hora_inicio, hora_fin, dias_semana, monto_minimo_factura, estado) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
                RETURNING id
            ");
            $stmtCamp->execute([$nombre, $tipo, $fechaInicio, $fechaFin, $horaInicio, $horaFin, $diasSemana, $montoMinimo, $estado]);
            $promocionId = $stmtCamp->fetchColumn();

            // Insertar regla de detalle si aplica
            $stmtDet = $pdo->prepare("
                INSERT INTO promociones.promociones_detalles 
                (promocion_id, condicion_producto_id, cantidad_requerida, beneficio_porcentaje, beneficio_monto_fijo) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtDet->execute([$promocionId, $condicionProductoId, $cantidadRequerida, $beneficioPorcentaje, $beneficioMontoFijo]);

            $pdo->commit();
            responder('success', "Campaña de promoción '{$nombre}' creada exitosamente.");
        }

        if ($accion === 'editar') {
            if (empty($id)) {
                throw new Exception("Identificador de promoción no proporcionado.");
            }

            $stmtUp = $pdo->prepare("
                UPDATE promociones.promociones_campanas 
                SET nombre_promocion = ?, tipo_promocion = ?, fecha_inicio = ?, fecha_fin = ?, 
                    hora_inicio = ?, hora_fin = ?, dias_semana = ?, monto_minimo_factura = ?, estado = ? 
                WHERE id = ?
            ");
            $stmtUp->execute([$nombre, $tipo, $fechaInicio, $fechaFin, $horaInicio, $horaFin, $diasSemana, $montoMinimo, $estado, $id]);

            // Actualizar regla de detalle
            $stmtCheckDet = $pdo->prepare("SELECT id FROM promociones.promociones_detalles WHERE promocion_id = ? LIMIT 1");
            $stmtCheckDet->execute([$id]);
            $detId = $stmtCheckDet->fetchColumn();

            if ($detId) {
                $stmtUpDet = $pdo->prepare("
                    UPDATE promociones.promociones_detalles 
                    SET condicion_producto_id = ?, cantidad_requerida = ?, beneficio_porcentaje = ?, beneficio_monto_fijo = ? 
                    WHERE id = ?
                ");
                $stmtUpDet->execute([$condicionProductoId, $cantidadRequerida, $beneficioPorcentaje, $beneficioMontoFijo, $detId]);
            } else {
                $stmtInsDet = $pdo->prepare("
                    INSERT INTO promociones.promociones_detalles 
                    (promocion_id, condicion_producto_id, cantidad_requerida, beneficio_porcentaje, beneficio_monto_fijo) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmtInsDet->execute([$id, $condicionProductoId, $cantidadRequerida, $beneficioPorcentaje, $beneficioMontoFijo]);
            }

            $pdo->commit();
            responder('success', "Campaña de promoción '{$nombre}' actualizada exitosamente.");
        }
    }

    if ($accion === 'cambiar_estado' || $accion === 'toggle_estado') {
        $id = intval($_POST['id'] ?? $_POST['promocion_id'] ?? 0);
        $nuevoEstado = trim($_POST['estado'] ?? '');

        if ($id <= 0) {
            throw new Exception("Identificador de promoción inválido.");
        }

        if (empty($nuevoEstado)) {
            $stmtCurr = $pdo->prepare("SELECT estado FROM promociones.promociones_campanas WHERE id = ?");
            $stmtCurr->execute([$id]);
            $curr = $stmtCurr->fetchColumn();
            $nuevoEstado = ($curr === 'ACTIVA') ? 'INACTIVA' : 'ACTIVA';
        }

        // Soft delete
        $stmt = $pdo->prepare("UPDATE promociones.promociones_campanas SET estado = ? WHERE id = ?");
        $stmt->execute([$nuevoEstado, $id]);
        responder('success', "Estado de la promoción actualizado a {$nuevoEstado}.", ['estado' => $nuevoEstado]);
    }

    throw new Exception("Acción no válida o no especificada.");
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder('error', $e->getMessage());
}

