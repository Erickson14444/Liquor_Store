<?php
/**
 * Procesador de Gestión de Promociones y Motor de Reglas (Backend Controller con PDO)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: promociones.promociones_campanas, promociones.promociones_detalles
 */

require_once __DIR__ . '/../config/conexion.php';

// Leer payload JSON enviado desde el Carrito de Reglas (o fallback a $_POST)
$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : null;
$datos    = is_array($jsonBody) ? $jsonBody : $_POST;

$isAjax = is_array($jsonBody) ||
          (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
          (isset($datos['format']) && $datos['format'] === 'json');

function responder($status, $mensaje, $extra = []) {
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['status' => $status, 'message' => $mensaje], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        $tipo = ($status === 'success') ? 'exito' : 'error';
        header("Location: promociones.php?msg=" . urlencode($mensaje) . "&tipo=" . $tipo);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder('error', 'Método HTTP no permitido.');
}

if (!$pdo) {
    responder('error', 'Error de conexión a la base de datos PostgreSQL.');
}

$accion = $datos['accion'] ?? $datos['action'] ?? 'crear';

try {
    if ($accion === 'crear' || $accion === 'editar') {
        $id          = !empty($datos['promocion_id']) ? intval($datos['promocion_id']) : (!empty($datos['id']) ? intval($datos['id']) : null);
        $nombre      = trim($datos['nombre_promocion'] ?? '');
        $tipo        = trim($datos['tipo_promocion'] ?? 'VOLUMEN');
        $fechaInicio = !empty($datos['fecha_inicio']) ? $datos['fecha_inicio'] : date('Y-m-d');
        $fechaFin    = !empty($datos['fecha_fin']) ? $datos['fecha_fin'] : date('Y-m-d', strtotime('+30 days'));
        $horaInicio  = !empty($datos['hora_inicio']) ? $datos['hora_inicio'] : null;
        $horaFin     = !empty($datos['hora_fin']) ? $datos['hora_fin'] : null;
        $diasSemana  = !empty($datos['dias_semana']) ? (is_array($datos['dias_semana']) ? implode(',', $datos['dias_semana']) : trim($datos['dias_semana'])) : 'TODOS';
        $montoMinimo = isset($datos['monto_minimo_factura']) ? max(0, floatval($datos['monto_minimo_factura'])) : 0.00;
        $estado      = trim($datos['estado'] ?? 'ACTIVA');
        $reglas      = isset($datos['reglas']) && is_array($datos['reglas']) ? $datos['reglas'] : [];

        if (empty($nombre)) {
            throw new Exception("El nombre de la campaña promocional es obligatorio.");
        }

        if (strtotime($fechaFin) < strtotime($fechaInicio)) {
            throw new Exception("La fecha de fin no puede ser anterior a la fecha de inicio.");
        }

        if (empty($reglas)) {
            throw new Exception("Debes agregar al menos una regla al Carrito de Reglas antes de guardar la campaña.");
        }

        // 1. INICIO: Abrir Transacción SQL
        $pdo->beginTransaction();

        if ($accion === 'crear') {
            // 2. CABECERA: Insertar campaña principal en promociones_campanas y capturar ID con lastInsertId()
            $stmtCamp = $pdo->prepare("
                INSERT INTO promociones.promociones_campanas
                (nombre_promocion, tipo_promocion, fecha_inicio, fecha_fin, hora_inicio, hora_fin, dias_semana, monto_minimo_factura, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtCamp->execute([
                $nombre,
                $tipo,
                $fechaInicio,
                $fechaFin,
                $horaInicio,
                $horaFin,
                $diasSemana,
                $montoMinimo,
                $estado
            ]);

            $promocionId = intval($pdo->lastInsertId());
        } else {
            if (empty($id)) {
                throw new Exception("Identificador de campaña no proporcionado para la edición.");
            }

            $stmtUp = $pdo->prepare("
                UPDATE promociones.promociones_campanas
                SET nombre_promocion = ?,
                    tipo_promocion = ?,
                    fecha_inicio = ?,
                    fecha_fin = ?,
                    hora_inicio = ?,
                    hora_fin = ?,
                    dias_semana = ?,
                    monto_minimo_factura = ?,
                    estado = ?
                WHERE id = ?
            ");
            $stmtUp->execute([
                $nombre,
                $tipo,
                $fechaInicio,
                $fechaFin,
                $horaInicio,
                $horaFin,
                $diasSemana,
                $montoMinimo,
                $estado,
                $id
            ]);

            $promocionId = $id;

            // Limpiar reglas previas de la campaña para insertar el nuevo conjunto del carrito
            $stmtDelDet = $pdo->prepare("DELETE FROM promociones.promociones_detalles WHERE promocion_id = ?");
            $stmtDelDet->execute([$promocionId]);
        }

        // 3. DETALLES: Recorrer el array de reglas con foreach e insertarlas en promociones_detalles
        $stmtDet = $pdo->prepare("
            INSERT INTO promociones.promociones_detalles
            (promocion_id, condicion_producto_id, condicion_categoria_id, marca_id, cantidad_requerida, beneficio_producto_id, beneficio_porcentaje, beneficio_monto_fijo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($reglas as $regla) {
            // condicion_producto_id es NOT NULL DEFAULT 0 (0 = comodín 'SKU-000' cuando aplica por marca, categoría o catálogo general)
            $condProductoId  = (!empty($regla['condicion_producto_id']) && intval($regla['condicion_producto_id']) > 0)
                               ? intval($regla['condicion_producto_id'])
                               : 0;
            $condCategoriaId = (!empty($regla['condicion_categoria_id']) && intval($regla['condicion_categoria_id']) > 0)
                               ? intval($regla['condicion_categoria_id'])
                               : null;
            $marcaId         = (!empty($regla['marca_id']) && intval($regla['marca_id']) > 0)
                               ? intval($regla['marca_id'])
                               : null;
            $cantRequerida   = max(1, intval($regla['cantidad_requerida'] ?? 1));
            $beneProductoId  = (!empty($regla['beneficio_producto_id']) && intval($regla['beneficio_producto_id']) > 0)
                               ? intval($regla['beneficio_producto_id'])
                               : null;
            $benePorcentaje  = (isset($regla['beneficio_porcentaje']) && $regla['beneficio_porcentaje'] !== '' && $regla['beneficio_porcentaje'] !== null && floatval($regla['beneficio_porcentaje']) > 0)
                               ? floatval($regla['beneficio_porcentaje'])
                               : null;
            if ($benePorcentaje !== null && $benePorcentaje > 0 && $benePorcentaje < 1) {
                $benePorcentaje = round($benePorcentaje * 100, 2);
            }
            $beneMontoFijo   = (isset($regla['beneficio_monto_fijo']) && $regla['beneficio_monto_fijo'] !== '' && $regla['beneficio_monto_fijo'] !== null && floatval($regla['beneficio_monto_fijo']) > 0)
                               ? floatval($regla['beneficio_monto_fijo'])
                               : null;

            $stmtDet->execute([
                $promocionId,
                $condProductoId,
                $condCategoriaId,
                $marcaId,
                $cantRequerida,
                $beneProductoId,
                $benePorcentaje,
                $beneMontoFijo
            ]);
        }

        // 4. SEGURIDAD: Confirmar Transacción SQL
        $pdo->commit();

        $verbo = ($accion === 'crear') ? 'registrada' : 'actualizada';
        responder('success', "Campaña '{$nombre}' {$verbo} exitosamente con " . count($reglas) . " regla(s).", [
            'promocion_id' => $promocionId
        ]);
    }

    if ($accion === 'cambiar_estado' || $accion === 'toggle_estado') {
        $id          = intval($datos['promocion_id'] ?? $datos['id'] ?? 0);
        $nuevoEstado = trim($datos['estado'] ?? '');

        if ($id <= 0) {
            throw new Exception("Identificador de promoción inválido.");
        }

        if (empty($nuevoEstado)) {
            $stmtCurr = $pdo->prepare("SELECT estado FROM promociones.promociones_campanas WHERE id = ?");
            $stmtCurr->execute([$id]);
            $curr = $stmtCurr->fetchColumn();
            $nuevoEstado = ($curr === 'ACTIVA') ? 'INACTIVA' : 'ACTIVA';
        }

        $stmt = $pdo->prepare("UPDATE promociones.promociones_campanas SET estado = ? WHERE id = ?");
        $stmt->execute([$nuevoEstado, $id]);

        responder('success', "Estado de la campaña actualizado a {$nuevoEstado}.", ['estado' => $nuevoEstado]);
    }

    throw new Exception("Acción no válida o no especificada.");
} catch (Throwable $e) {
    // 4. SEGURIDAD: Rollback inmediato ante cualquier error para evitar registros huérfanos
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder('error', $e->getMessage());
}

