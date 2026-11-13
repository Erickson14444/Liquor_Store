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
    if (!$pdo) {
        throw new Exception("No hay conexión con la base de datos.");
    }

    if ($action === 'guardar') {
        $tipo = $_POST['tipo'] ?? 'categoria'; // 'categoria' o 'marca'
        $id = !empty($_POST['id']) ? intval(str_replace('#', '', $_POST['id'])) : null;
        $nombre = trim($_POST['nombre'] ?? '');
        $table = ($tipo === 'categoria') ? 'inventario.categoria_producto' : 'inventario.marca';
        $etiqueta = ($tipo === 'categoria') ? 'la categoría' : 'la marca';

        if (empty($nombre)) {
            throw new Exception("El nombre no puede estar vacío.");
        }

        // Verificar duplicados preventivamente
        if ($id) {
            $stmtCheck = $pdo->prepare("SELECT id FROM {$table} WHERE LOWER(nombre) = LOWER(?) AND id <> ?");
            $stmtCheck->execute([$nombre, $id]);
        } else {
            $stmtCheck = $pdo->prepare("SELECT id FROM {$table} WHERE LOWER(nombre) = LOWER(?)");
            $stmtCheck->execute([$nombre]);
        }

        if ($stmtCheck->fetch()) {
            throw new Exception("Ya existe {$etiqueta} con el nombre '{$nombre}'. Por favor use un nombre diferente.");
        }

        if ($id) {
            $stmt = $pdo->prepare("UPDATE {$table} SET nombre = ? WHERE id = ?");
            $stmt->execute([$nombre, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO {$table} (nombre, estado) VALUES (?, 'ACTIVO') RETURNING id");
            $stmt->execute([$nombre]);
            $id = $stmt->fetchColumn();
        }

        echo json_encode(['status' => 'success', 'id' => intval($id), 'nombre' => $nombre]);
        exit;
    }

    if ($action === 'toggle_estado') {
        $tipo = $_POST['tipo'] ?? 'categoria';
        $id = intval(str_replace('#', '', $_POST['id'] ?? '0'));
        $activoBool = filter_var($_POST['activo'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $table = ($tipo === 'categoria') ? 'inventario.categoria_producto' : 'inventario.marca';
        $nuevoEstado = $activoBool ? 'ACTIVO' : 'INACTIVO';

        // Soft delete lógico (columna estado VARCHAR)
        $stmt = $pdo->prepare("UPDATE {$table} SET estado = ? WHERE id = ?");
        $stmt->execute([$nuevoEstado, $id]);

        echo json_encode(['status' => 'success', 'id' => $id, 'activo' => $activoBool, 'estado' => $nuevoEstado]);
        exit;
    }

    throw new Exception("Acción no reconocida.");
} catch (PDOException $e) {
    http_response_code(400);
    if ($e->getCode() === '23505' || strpos($e->getMessage(), '23505') !== false) {
        $nombreTxt = !empty($nombre) ? "con el nombre '{$nombre}'" : 'con ese nombre';
        echo json_encode(['status' => 'error', 'message' => "Ya existe un registro {$nombreTxt}. Por favor use un nombre diferente."]);
    } else {
        echo json_encode(['status' => 'error', 'message' => "Error de base de datos: " . $e->getMessage()]);
    }
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
