<?php
/**
 * Procesador de Presentaciones / Códigos de Barra (Backend Controller)
 * House & Ice • Sistema Administrativo
 * Tablas: inventario.producto_codigos_barra, inventario.productos_maestro
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
        header("Location: presentaciones.php?msg=" . urlencode($mensaje) . "&tipo=" . $tipo);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder('error', 'Método HTTP no permitido.');
}

$accion = $_POST['accion'] ?? $_POST['action'] ?? 'crear';

try {
    if ($accion === 'crear' || $accion === 'editar') {
        $id = !empty($_POST['presentacion_id']) ? intval($_POST['presentacion_id']) : (!empty($_POST['id']) ? intval($_POST['id']) : null);
        $productoMaestroId = intval($_POST['producto_maestro_id'] ?? 0);
        $codigoBarra = trim($_POST['codigo_barra'] ?? '');
        $descripcion = trim($_POST['descripcion_presentacion'] ?? $_POST['descripcion'] ?? '');
        $factor = intval($_POST['factor_multiplicador'] ?? 1);
        $precio = floatval($_POST['precio_venta_presentacion'] ?? $_POST['precio'] ?? 0);

        if ($productoMaestroId <= 0 || empty($codigoBarra) || empty($descripcion) || $factor <= 0) {
            throw new Exception("El producto maestro, código de barra, descripción y factor multiplicador (> 0) son obligatorios.");
        }

        if ($accion === 'crear') {
            // Verificar si el código de barra ya existe
            $stmtCheck = $pdo->prepare("SELECT count(*) FROM inventario.producto_codigos_barra WHERE codigo_barra = ?");
            $stmtCheck->execute([$codigoBarra]);
            if ($stmtCheck->fetchColumn() > 0) {
                throw new Exception("El código de barra '{$codigoBarra}' ya está asignado a otra presentación.");
            }

            $stmtIns = $pdo->prepare("
                INSERT INTO inventario.producto_codigos_barra 
                (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtIns->execute([$productoMaestroId, $codigoBarra, $descripcion, $factor, $precio]);
            responder('success', "Presentación '{$descripcion}' registrada exitosamente.");
        }

        if ($accion === 'editar') {
            if (empty($id)) {
                throw new Exception("Identificador de presentación no proporcionado.");
            }

            $stmtUp = $pdo->prepare("
                UPDATE inventario.producto_codigos_barra 
                SET producto_maestro_id = ?, codigo_barra = ?, descripcion_presentacion = ?, 
                    factor_multiplicador = ?, precio_venta_presentacion = ? 
                WHERE id = ?
            ");
            $stmtUp->execute([$productoMaestroId, $codigoBarra, $descripcion, $factor, $precio, $id]);
            responder('success', "Presentación '{$descripcion}' actualizada exitosamente.");
        }
    }

    if ($accion === 'eliminar' || $accion === 'delete') {
        $id = intval($_POST['id'] ?? $_POST['presentacion_id'] ?? 0);
        if ($id <= 0) {
            throw new Exception("Identificador de presentación inválido.");
        }

        $stmtDel = $pdo->prepare("DELETE FROM inventario.producto_codigos_barra WHERE id = ?");
        $stmtDel->execute([$id]);
        responder('success', "Presentación eliminada del catálogo.");
    }

    throw new Exception("Acción no válida o no especificada.");
} catch (Exception $e) {
    responder('error', $e->getMessage());
}

