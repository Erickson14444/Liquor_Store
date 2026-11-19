<?php
/**
 * Procesador de Presentaciones / Códigos de Barra (Backend Controller)
 * House & Ice • Sistema Administrativo
 * Tablas: inventario.producto_codigos_barra, inventario.productos_maestro
 */

require_once __DIR__ . '/../../config/conexion.php';

// Búsqueda GET en vivo por Código de Barra
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['codigo_barra'])) {
    header('Content-Type: application/json; charset=utf-8');
    $cb = preg_replace('/\D/', '', trim($_GET['codigo_barra'] ?? ''));
    if ($cb === '' || !$pdo) {
        echo json_encode(['encontrado' => false]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion
            FROM inventario.producto_codigos_barra
            WHERE codigo_barra = ?
            LIMIT 1
        ");
        $stmt->execute([$cb]);
        $pres = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pres) {
            echo json_encode(['encontrado' => true, 'presentacion' => $pres], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['encontrado' => false]);
        }
    } catch (Throwable $e) {
        echo json_encode(['encontrado' => false]);
    }
    exit;
}

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
        $codigoBarra = preg_replace('/\D/', '', trim($_POST['codigo_barra'] ?? ''));
        $descripcion = trim($_POST['descripcion_presentacion'] ?? $_POST['descripcion'] ?? '');
        if ($descripcion !== '') {
            $descripcion = mb_strtoupper(mb_substr($descripcion, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($descripcion, 1, null, 'UTF-8');
        }
        $factor = intval($_POST['factor_multiplicador'] ?? 1);
        $precio = floatval($_POST['precio_venta_presentacion'] ?? $_POST['precio'] ?? 0);

        if ($productoMaestroId <= 0 || empty($codigoBarra) || empty($descripcion) || $factor <= 0) {
            throw new Exception("El producto maestro, código de barra, descripción y factor multiplicador (> 0) son obligatorios.");
        }

        if (!preg_match('/^\d{8,14}$/', $codigoBarra) || preg_match('/^0+$/', $codigoBarra)) {
            throw new Exception("El código de barra debe contener únicamente números (entre 8 y 14 dígitos válidos EAN/UPC).");
        }

        if ($precio < 0) {
            throw new Exception("El precio de venta no puede ser negativo.");
        }

        if ($accion === 'crear') {
            $stmtCheck = $pdo->prepare("SELECT id FROM inventario.producto_codigos_barra WHERE codigo_barra = ? LIMIT 1");
            $stmtCheck->execute([$codigoBarra]);
            $idExist = $stmtCheck->fetchColumn();
            if ($idExist) {
                $id = intval($idExist);
                $accion = 'editar';
            } else {
                $stmtIns = $pdo->prepare("
                    INSERT INTO inventario.producto_codigos_barra 
                    (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([$productoMaestroId, $codigoBarra, $descripcion, $factor, $precio]);
                responder('success', "Presentación '{$descripcion}' registrada exitosamente.");
            }
        }

        if ($accion === 'editar') {
            if (empty($id)) {
                throw new Exception("Identificador de presentación no proporcionado.");
            }

            $stmtCheckDup = $pdo->prepare("SELECT count(*) FROM inventario.producto_codigos_barra WHERE codigo_barra = ? AND id != ?");
            $stmtCheckDup->execute([$codigoBarra, $id]);
            if ($stmtCheckDup->fetchColumn() > 0) {
                throw new Exception("El código de barra '{$codigoBarra}' ya está asignado a otra presentación.");
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
