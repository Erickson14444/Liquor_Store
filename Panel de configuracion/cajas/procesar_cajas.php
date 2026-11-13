<?php
require_once __DIR__ . '/../../config/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

$action = $_POST['action'] ?? 'guardar';

try {
    if ($action === 'guardar') {
        $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
        $nombre_equipo = trim($_POST['nombre_equipo'] ?? '');
        $estado = $_POST['estado'] ?? 'CERRADA';

        if (empty($nombre_equipo)) {
            throw new Exception("El nombre del equipo/caja es obligatorio.");
        }

        if ($id) {
            $stmt = $pdo->prepare("UPDATE ventas.cajas_pos SET nombre_equipo = ?, estado = ? WHERE id = ?");
            $stmt->execute([$nombre_equipo, $estado, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO ventas.cajas_pos (nombre_equipo, estado) VALUES (?, ?) RETURNING id");
            $stmt->execute([$nombre_equipo, $estado]);
            $id = $stmt->fetchColumn();
        }

        echo json_encode(['status' => 'success', 'id' => intval($id), 'nombre_equipo' => $nombre_equipo, 'estado' => $estado]);
        exit;
    }

    if ($action === 'cambiar_estado') {
        $id = intval($_POST['id']);
        $estado = $_POST['estado'];
        $stmt = $pdo->prepare("UPDATE ventas.cajas_pos SET estado = ? WHERE id = ?");
        $stmt->execute([$estado, $id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    throw new Exception("Acción no reconocida.");
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
