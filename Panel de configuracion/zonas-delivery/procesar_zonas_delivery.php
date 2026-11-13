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
        $nombre = trim($_POST['nombre'] ?? '');
        $tarifa_envio = floatval($_POST['tarifa_envio'] ?? 0);
        $tiempo_promedio_min = intval($_POST['tiempo_promedio_min'] ?? 30);

        if (empty($nombre)) {
            throw new Exception("El nombre del sector/zona es obligatorio.");
        }

        if ($id) {
            $stmt = $pdo->prepare("UPDATE ventas.zonas_delivery SET nombre = ?, tarifa_envio = ?, tiempo_promedio_min = ? WHERE id = ?");
            $stmt->execute([$nombre, $tarifa_envio, $tiempo_promedio_min, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO ventas.zonas_delivery (nombre, tarifa_envio, tiempo_promedio_min, activa) VALUES (?, ?, ?, true) RETURNING id");
            $stmt->execute([$nombre, $tarifa_envio, $tiempo_promedio_min]);
            $id = $stmt->fetchColumn();
        }

        echo json_encode([
            'status' => 'success',
            'id' => intval($id),
            'nombre' => $nombre,
            'tarifa_envio' => $tarifa_envio,
            'tiempo_promedio_min' => $tiempo_promedio_min,
            'activa' => true
        ]);
        exit;
    }

    if ($action === 'toggle_estado') {
        $id = intval($_POST['id']);
        $activa = filter_var($_POST['activa'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        $stmt = $pdo->prepare("UPDATE ventas.zonas_delivery SET activa = $activa WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    throw new Exception("Acción no reconocida.");
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
