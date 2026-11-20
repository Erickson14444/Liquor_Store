<?php
require_once __DIR__ . '/../../config/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!usuarioPuedeAccederMetodosPago()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: Solo el rol Administrador tiene permiso para gestionar los Métodos de Pago.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

$action = $_POST['action'] ?? 'guardar';

try {
    if ($action === 'guardar') {
        $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
        $descripcion = trim($_POST['descripcion'] ?? '');
        $requiere_referencia = filter_var($_POST['requiere_referencia'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';

        if (empty($codigo) || empty($descripcion)) {
            throw new Exception("Código y descripción son obligatorios.");
        }

        if ($id) {
            $stmt = $pdo->prepare("UPDATE ventas.metodos_pago SET codigo = ?, descripcion = ?, requiere_referencia = $requiere_referencia WHERE id = ?");
            $stmt->execute([$codigo, $descripcion, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO ventas.metodos_pago (codigo, descripcion, requiere_referencia, activo) VALUES (?, ?, $requiere_referencia, true) RETURNING id");
            $stmt->execute([$codigo, $descripcion]);
            $id = $stmt->fetchColumn();
        }

        echo json_encode([
            'status' => 'success',
            'id' => intval($id),
            'codigo' => $codigo,
            'descripcion' => $descripcion,
            'requiere_referencia' => $requiere_referencia === 'true',
            'activo' => true
        ]);
        exit;
    }

    if ($action === 'toggle_estado') {
        $id = intval($_POST['id']);
        $activo = filter_var($_POST['activo'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        $stmt = $pdo->prepare("UPDATE ventas.metodos_pago SET activo = $activo WHERE id = ?");
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
