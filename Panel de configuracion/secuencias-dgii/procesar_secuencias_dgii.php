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
        $tipo_comprobante = trim($_POST['tipo_comprobante'] ?? '');
        $secuencia_desde = intval($_POST['secuencia_desde'] ?? 1);
        $secuencia_hasta = intval($_POST['secuencia_hasta'] ?? 1000);
        $secuencia_actual = intval($_POST['secuencia_actual'] ?? 1);
        $fecha_vencimiento = $_POST['fecha_vencimiento'] ?? date('Y-12-31');
        $estado = $_POST['estado'] ?? 'ACTIVO';

        if ($id) {
            $stmt = $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET tipo_comprobante = ?, secuencia_desde = ?, secuencia_hasta = ?, secuencia_actual = ?, fecha_vencimiento = ?, estado = ? WHERE id = ?");
            $stmt->execute([$tipo_comprobante, $secuencia_desde, $secuencia_hasta, $secuencia_actual, $fecha_vencimiento, $estado, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO ventas.secuencias_fiscales_dgii (tipo_comprobante, secuencia_desde, secuencia_hasta, secuencia_actual, fecha_vencimiento, estado) VALUES (?, ?, ?, ?, ?, ?) RETURNING id");
            $stmt->execute([$tipo_comprobante, $secuencia_desde, $secuencia_hasta, $secuencia_actual, $fecha_vencimiento, $estado]);
            $id = $stmt->fetchColumn();
        }

        echo json_encode([
            'status' => 'success',
            'id' => intval($id),
            'tipo_comprobante' => $tipo_comprobante,
            'secuencia_desde' => $secuencia_desde,
            'secuencia_hasta' => $secuencia_hasta,
            'secuencia_actual' => $secuencia_actual,
            'fecha_vencimiento' => $fecha_vencimiento,
            'estado' => $estado
        ]);
        exit;
    }

    throw new Exception("Acción no reconocida.");
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
