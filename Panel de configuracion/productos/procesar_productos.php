<?php
/**
 * Procesador de Productos Maestros (Backend Controller)
 * House & Ice • Sistema Administrativo
 * Tabla: inventario.productos_maestro
 */

require_once __DIR__ . '/../../config/conexion.php';

// Búsqueda GET en vivo por Código SKU Maestro (ej. PRO-001)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['sku'])) {
    header('Content-Type: application/json; charset=utf-8');
    $skuBuscado = trim($_GET['sku'] ?? '');
    if ($skuBuscado === '' || !$pdo) {
        echo json_encode(['encontrado' => false]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT pm.id, pm.sku, pm.categoria_id, pm.marca_id, pm.requiere_lote,
                   pm.costo_promedio, pm.precio_base_unidad,
                   COALESCE((
                       SELECT imp.tasa_o_monto
                       FROM inventario.producto_impuestos pi
                       JOIN tesoreria.impuestos imp ON pi.impuesto_id = imp.id
                       WHERE pi.producto_maestro_id = pm.id
                         AND imp.siglas IN ('ITBIS_18', 'ITBIS_16', 'EXENTO')
                       ORDER BY imp.id ASC
                       LIMIT 1
                   ), 18.00) AS porcentaje_itbis,
                   COALESCE(
                       NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                       (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                       pm.sku
                   ) AS descripcion
            FROM inventario.productos_maestro pm
            WHERE UPPER(TRIM(pm.sku)) = UPPER(TRIM(?)) AND pm.id > 0
            LIMIT 1
        ");
        $stmt->execute([$skuBuscado]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($prod) {
            $stmtImpIds = $pdo->prepare("
                SELECT impuesto_id FROM inventario.producto_impuestos
                WHERE producto_maestro_id = ?
                ORDER BY impuesto_id ASC
            ");
            $stmtImpIds->execute([$prod['id']]);
            $prod['impuestos_ids'] = array_map('intval', $stmtImpIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
            echo json_encode(['encontrado' => true, 'producto' => $prod], JSON_UNESCAPED_UNICODE);
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
        $sku = strtoupper(trim($_POST['sku'] ?? ''));
        $nombre = trim($_POST['nombre'] ?? $_POST['descripcion'] ?? '');
        if ($nombre !== '') {
            $nombre = mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($nombre, 1, null, 'UTF-8');
        }
        $categoriaId = intval($_POST['categoria_id'] ?? 0);
        $marcaId = intval($_POST['marca_id'] ?? 0);
        $requiereLote = filter_var($_POST['requiere_lote'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $costoRaw = trim((string)($_POST['costo_promedio'] ?? '0'));
        $precioRaw = trim((string)($_POST['precio_base_unidad'] ?? '0'));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $costoRaw) || !preg_match('/^\d+(\.\d{1,2})?$/', $precioRaw)) {
            throw new Exception("El costo promedio y el precio base deben ser valores numéricos válidos (sin letras).");
        }
        $costoPromedio = floatval($costoRaw);
        $precioBase = floatval($precioRaw);
        $impuestosArr = isset($_POST['impuestos']) && is_array($_POST['impuestos'])
                        ? array_values(array_unique(array_filter(array_map('intval', $_POST['impuestos']), fn($v) => $v > 0)))
                        : [];
        $porcentajeItbis = floatval($_POST['porcentaje_itbis'] ?? 18.00);

        if (empty($sku) || $categoriaId <= 0 || $marcaId <= 0) {
            throw new Exception("El SKU, categoría y marca son obligatorios.");
        }
        if (!preg_match('/^[A-Z0-9_-]{3,30}$/i', $sku)) {
            throw new Exception("El código SKU '{$sku}' no tiene un formato válido (usa letras, números y guiones, ej. PRO-001).");
        }
        if (empty($impuestosArr)) {
            throw new Exception("Debes seleccionar al menos un tributo o impuesto fiscal (o marcar Exento).");
        }

        $pdo->beginTransaction();

        // Si el SKU ya existe mientras se intenta crear, actualizar el registro existente
        if ($accion === 'crear' && empty($id)) {
            $stmtExist = $pdo->prepare("SELECT id FROM inventario.productos_maestro WHERE UPPER(TRIM(sku)) = UPPER(TRIM(?)) AND id > 0 LIMIT 1");
            $stmtExist->execute([$sku]);
            $idExist = $stmtExist->fetchColumn();
            if ($idExist) {
                $id = intval($idExist);
                $accion = 'editar';
            }
        }

        if ($accion === 'crear') {
            $stmtIns = $pdo->prepare("
                INSERT INTO inventario.productos_maestro 
                (sku, nombre, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad) 
                VALUES (?, ?, ?, ?, ?, ?, ?) 
                RETURNING id
            ");
            $stmtIns->execute([$sku, $nombre ?: $sku, $categoriaId, $marcaId, $requiereLote ? 'true' : 'false', $costoPromedio, $precioBase]);
            $id = intval($stmtIns->fetchColumn());
        } elseif ($accion === 'editar') {
            if (empty($id)) {
                throw new Exception("Identificador de producto maestro no proporcionado.");
            }

            $stmtUp = $pdo->prepare("
                UPDATE inventario.productos_maestro 
                SET sku = ?, nombre = ?, categoria_id = ?, marca_id = ?, requiere_lote = ?, 
                    costo_promedio = ?, precio_base_unidad = ? 
                WHERE id = ?
            ");
            $stmtUp->execute([$sku, $nombre ?: $sku, $categoriaId, $marcaId, $requiereLote ? 'true' : 'false', $costoPromedio, $precioBase, $id]);
        }

        // Sincronizar tributos múltiples en inventario.producto_impuestos + tesoreria.impuestos
        if (!empty($id)) {
            $pdo->prepare("DELETE FROM inventario.producto_impuestos WHERE producto_maestro_id = ?")->execute([$id]);

            if (!empty($impuestosArr)) {
                $stmtInsPi = $pdo->prepare("
                    INSERT INTO inventario.producto_impuestos (producto_maestro_id, impuesto_id)
                    VALUES (?, ?)
                    ON CONFLICT DO NOTHING
                ");
                foreach ($impuestosArr as $impId) {
                    $stmtInsPi->execute([$id, $impId]);
                }
            } else {
                $siglaItbis = ($porcentajeItbis <= 0) ? 'EXENTO' : ((abs($porcentajeItbis - 16) < 0.01) ? 'ITBIS_16' : 'ITBIS_18');
                $stmtImp = $pdo->prepare("SELECT id FROM tesoreria.impuestos WHERE siglas = ? OR ABS(tasa_o_monto - ?) < 0.01 ORDER BY id ASC LIMIT 1");
                $stmtImp->execute([$siglaItbis, $porcentajeItbis]);
                $impId = $stmtImp->fetchColumn();
                if ($impId) {
                    $pdo->prepare("
                        INSERT INTO inventario.producto_impuestos (producto_maestro_id, impuesto_id)
                        VALUES (?, ?)
                        ON CONFLICT DO NOTHING
                    ")->execute([$id, $impId]);
                }
            }
        }

        $pdo->commit();
        if ($accion === 'crear') {
            responder('success', "Producto maestro '{$sku}' registrado exitosamente.");
        } else {
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
