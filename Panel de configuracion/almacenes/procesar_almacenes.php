<?php
/**
 * Procesador de Almacenes Físicos (Backend Controller)
 * House & Ice • Sistema Administrativo
 * Tabla: inventario.almacenes
 */

require_once __DIR__ . '/../../config/conexion.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || 
          (isset($_POST['format']) && $_POST['format'] === 'json') ||
          (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

function responder($status, $mensaje, $data = []) {
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        if ($status !== 'success') {
            http_response_code(400);
        }
        echo json_encode(array_merge(['status' => $status, 'message' => $mensaje], $data));
        exit;
    } else {
        $tipo = ($status === 'success') ? 'exito' : 'error';
        header("Location: almacenes.php?msg=" . urlencode($mensaje) . "&tipo=" . $tipo);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder('error', 'Método HTTP no permitido.');
}

$action = $_POST['action'] ?? $_POST['accion'] ?? 'guardar';

try {
    if (!$pdo) {
        throw new Exception("No hay conexión con la base de datos.");
    }

    if ($action === 'guardar' || $action === 'crear' || $action === 'editar') {
        $id = !empty($_POST['id']) ? intval($_POST['id']) : (!empty($_POST['almacen_id']) ? intval($_POST['almacen_id']) : null);
        $nombre = trim($_POST['nombre'] ?? '');
        $ubicacion = trim($_POST['ubicacion'] ?? '');

        if (empty($nombre)) {
            responder('error', 'El nombre del almacén es obligatorio.');
        }

        // Verificar si ya existe otro almacén con el mismo nombre
        if ($id) {
            $stmtCheck = $pdo->prepare("SELECT id FROM inventario.almacenes WHERE LOWER(nombre) = LOWER(?) AND id <> ?");
            $stmtCheck->execute([$nombre, $id]);
        } else {
            $stmtCheck = $pdo->prepare("SELECT id FROM inventario.almacenes WHERE LOWER(nombre) = LOWER(?)");
            $stmtCheck->execute([$nombre]);
        }

        if ($stmtCheck->fetch()) {
            responder('error', "Ya existe un almacén con el nombre '{$nombre}'. Por favor elija un nombre diferente o edite el existente.");
        }

        if ($id) {
            $activoVal = isset($_POST['activo']) ? (filter_var($_POST['activo'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false') : null;
            if ($activoVal !== null) {
                $stmt = $pdo->prepare("UPDATE inventario.almacenes SET nombre = ?, ubicacion = ?, activo = $activoVal WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE inventario.almacenes SET nombre = ?, ubicacion = ? WHERE id = ?");
            }
            $stmt->execute([$nombre, $ubicacion, $id]);
            responder('success', "Almacén '{$nombre}' actualizado correctamente.", [
                'id' => $id,
                'codigo' => 'ALM-' . str_pad($id, 3, '0', STR_PAD_LEFT),
                'nombre' => $nombre,
                'ubicacion' => $ubicacion
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO inventario.almacenes (nombre, ubicacion, activo) VALUES (?, ?, true) RETURNING id");
            $stmt->execute([$nombre, $ubicacion]);
            $newId = $stmt->fetchColumn();
            responder('success', "Almacén '{$nombre}' creado exitosamente.", [
                'id' => intval($newId),
                'codigo' => 'ALM-' . str_pad($newId, 3, '0', STR_PAD_LEFT),
                'nombre' => $nombre,
                'ubicacion' => $ubicacion,
                'activo' => true
            ]);
        }
    }

    if ($action === 'toggle_estado' || $action === 'cambiar_estado') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            responder('error', 'Identificador de almacén no válido.');
        }

        $activo = filter_var($_POST['activo'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        $stmt = $pdo->prepare("UPDATE inventario.almacenes SET activo = $activo WHERE id = ?");
        $stmt->execute([$id]);

        responder('success', 'Estado del almacén actualizado correctamente.', [
            'id' => $id,
            'activo' => ($activo === 'true')
        ]);
    }

    responder('error', 'Acción no reconocida.');
} catch (PDOException $e) {
    if ($e->getCode() === '23505' || strpos($e->getMessage(), 'almacenes_nombre_key') !== false || strpos($e->getMessage(), '23505') !== false) {
        $nombreTxt = !empty($nombre) ? "con el nombre '{$nombre}'" : 'con ese nombre';
        responder('error', "Ya existe un almacén registrado {$nombreTxt}. Por favor use un nombre diferente.");
    }
    responder('error', 'Error en la base de datos: ' . $e->getMessage());
} catch (Throwable $e) {
    responder('error', $e->getMessage());
}
