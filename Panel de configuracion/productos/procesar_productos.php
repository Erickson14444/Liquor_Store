<?php
/**
 * Procesador de Productos Maestros (Backend Controller)
 * House & Ice • Sistema Administrativo
 * Tablas: inventario.productos_maestro, inventario.producto_codigos_barra
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
        header("Location: productos.php?msg=" . urlencode($mensaje) . "&tipo=" . $tipo);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder('error', 'Método HTTP no permitido.');
}

$accion = $_POST['accion'] ?? $_POST['action'] ?? 'crear';

try {
    if ($accion === 'crear' || $accion === 'editar') {
        $id = !empty($_POST['producto_id']) ? intval($_POST['producto_id']) : (!empty($_POST['id']) ? intval($_POST['id']) : null);
        $sku = trim($_POST['sku'] ?? '');
        $nombre = trim($_POST['nombre'] ?? $_POST['descripcion'] ?? '');
        $categoriaId = intval($_POST['categoria_id'] ?? 0);
        $marcaId = intval($_POST['marca_id'] ?? 0);
        $requiereLote = filter_var($_POST['requiere_lote'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $costoPromedio = floatval($_POST['costo_promedio'] ?? 0);
        $precioBase = floatval($_POST['precio_base_unidad'] ?? 0);
        $impuestoIsc = floatval($_POST['impuesto_isc'] ?? 0);
        $porcentajeItbis = floatval($_POST['porcentaje_itbis'] ?? 18.00);
        $codigoBarra = trim($_POST['codigo_barra'] ?? '');

        if (empty($sku) || $categoriaId <= 0 || $marcaId <= 0) {
            throw new Exception("El SKU, categoría y marca son obligatorios.");
        }

        $pdo->beginTransaction();

        if ($accion === 'crear') {
            // Verificar si el SKU ya existe
            $stmtCheck = $pdo->prepare("SELECT count(*) FROM inventario.productos_maestro WHERE sku = ?");
            $stmtCheck->execute([$sku]);
            if ($stmtCheck->fetchColumn() > 0) {
                throw new Exception("Ya existe un producto con el SKU '{$sku}'.");
            }

            $stmtIns = $pdo->prepare("
                INSERT INTO inventario.productos_maestro 
                (sku, nombre, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, impuesto_isc, porcentaje_itbis) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
                RETURNING id
            ");
            $stmtIns->execute([$sku, $nombre ?: $sku, $categoriaId, $marcaId, $requiereLote ? 'true' : 'false', $costoPromedio, $precioBase, $impuestoIsc, $porcentajeItbis]);
            $nuevoId = $stmtIns->fetchColumn();

            // Si se suministró descripción o código de barra, crear presentación unitaria base
            if (!empty($nombre) || !empty($codigoBarra)) {
                $cb = !empty($codigoBarra) ? $codigoBarra : ('746' . str_pad($nuevoId, 10, '0', STR_PAD_LEFT));
                $desc = !empty($nombre) ? $nombre : ($sku . ' Botella/Unidad');
                $stmtPres = $pdo->prepare("
                    INSERT INTO inventario.producto_codigos_barra 
                    (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion) 
                    VALUES (?, ?, ?, 1, ?)
                ");
                $stmtPres->execute([$nuevoId, $cb, $desc, $precioBase]);
            }

            $pdo->commit();
            responder('success', "Producto maestro '{$sku}' registrado exitosamente.");
        }

        if ($accion === 'editar') {
            if (empty($id)) {
                throw new Exception("Identificador de producto maestro no proporcionado.");
            }

            $stmtUp = $pdo->prepare("
                UPDATE inventario.productos_maestro 
                SET sku = ?, nombre = ?, categoria_id = ?, marca_id = ?, requiere_lote = ?, 
                    costo_promedio = ?, precio_base_unidad = ?, impuesto_isc = ?, porcentaje_itbis = ? 
                WHERE id = ?
            ");
            $stmtUp->execute([$sku, $nombre ?: $sku, $categoriaId, $marcaId, $requiereLote ? 'true' : 'false', $costoPromedio, $precioBase, $impuestoIsc, $porcentajeItbis, $id]);

            // Actualizar descripción de presentación base si aplica
            if (!empty($nombre)) {
                $stmtUpPres = $pdo->prepare("
                    UPDATE inventario.producto_codigos_barra 
                    SET descripcion_presentacion = ? 
                    WHERE producto_maestro_id = ? AND factor_multiplicador = 1
                ");
                $stmtUpPres->execute([$nombre, $id]);
            }

            $pdo->commit();
            responder('success', "Producto maestro '{$sku}' actualizado exitosamente.");
        }
    }

    throw new Exception("Acción no válida o no especificada.");
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder('error', $e->getMessage());
}

