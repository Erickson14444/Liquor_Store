<?php
/**
 * Procesador de Lotes y Vencimientos (Backend Controller)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tabla: inventario.lotes
 */

require_once __DIR__ . '/../../config/conexion.php';

// Autocompletado GET en vivo por número de lote
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['numero_lote'])) {
    header('Content-Type: application/json; charset=utf-8');
    $numLote = trim($_GET['numero_lote'] ?? '');
    $prodId  = intval($_GET['producto_maestro_id'] ?? 0);

    if ($numLote === '' || !$pdo) {
        echo json_encode(['encontrado' => false]);
        exit;
    }

    try {
        if ($prodId > 0) {
            $stmt = $pdo->prepare("
                SELECT id, producto_maestro_id, numero_lote, fecha_vencimiento, cantidad_actual
                FROM inventario.lotes
                WHERE UPPER(TRIM(numero_lote)) = UPPER(TRIM(?)) AND producto_maestro_id = ?
                LIMIT 1
            ");
            $stmt->execute([$numLote, $prodId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, producto_maestro_id, numero_lote, fecha_vencimiento, cantidad_actual
                FROM inventario.lotes
                WHERE UPPER(TRIM(numero_lote)) = UPPER(TRIM(?))
                LIMIT 1
            ");
            $stmt->execute([$numLote]);
        }
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($lote) {
            echo json_encode(['encontrado' => true, 'lote' => $lote], JSON_UNESCAPED_UNICODE);
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
        echo json_encode(array_merge(['status' => $status, 'message' => $mensaje], $data), JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        $tipo = ($status === 'success') ? 'exito' : 'error';
        header("Location: lotes.php?msg=" . urlencode($mensaje) . "&tipo=" . $tipo);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder('error', 'Método HTTP no permitido.');
}

$accion = $_POST['accion'] ?? 'crear';

try {
    if (!$pdo) {
        throw new Exception("No hay conexión con la base de datos.");
    }

    if ($accion === 'crear' || $accion === 'editar') {
        $id = !empty($_POST['lote_id']) ? intval($_POST['lote_id']) : null;
        $productoId = intval($_POST['producto_maestro_id'] ?? 0);
        $numeroLote = strtoupper(trim($_POST['numero_lote'] ?? ''));
        $fechaVenc = trim($_POST['fecha_vencimiento'] ?? '');
        $cantidadRaw = trim((string)($_POST['cantidad_actual'] ?? '0'));

        if ($productoId <= 0 || $numeroLote === '' || $fechaVenc === '') {
            throw new Exception("El producto, número de lote y fecha de vencimiento son obligatorios.");
        }
        if (!preg_match('/^[A-Z0-9_-]{3,50}$/', $numeroLote)) {
            throw new Exception("El número de lote '{$numeroLote}' solo puede contener letras, números y guiones (ej. LOT-2026-001).");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaVenc)) {
            throw new Exception("La fecha de vencimiento no tiene un formato válido.");
        }
        if (!preg_match('/^\d+$/', $cantidadRaw)) {
            throw new Exception("La existencia del lote debe ser un número entero mayor o igual a 0 (sin letras ni decimales).");
        }
        $cantidad = intval($cantidadRaw);

        // Si en crear ya existe la combinación (producto_maestro_id, numero_lote), pasar a editar
        if ($accion === 'crear' && empty($id)) {
            $stmtEx = $pdo->prepare("SELECT id FROM inventario.lotes WHERE producto_maestro_id = ? AND UPPER(TRIM(numero_lote)) = ? LIMIT 1");
            $stmtEx->execute([$productoId, $numeroLote]);
            $exId = $stmtEx->fetchColumn();
            if ($exId) {
                $id = intval($exId);
                $accion = 'editar';
            }
        }

        if ($accion === 'crear') {
            $stmtIns = $pdo->prepare("
                INSERT INTO inventario.lotes (producto_maestro_id, numero_lote, fecha_vencimiento, cantidad_actual)
                VALUES (?, ?, ?, ?)
            ");
            $stmtIns->execute([$productoId, $numeroLote, $fechaVenc, $cantidad]);
            responder('success', "Lote '{$numeroLote}' registrado exitosamente.");
        } else {
            if (empty($id)) {
                throw new Exception("Identificador de lote no especificado.");
            }
            $stmtDup = $pdo->prepare("SELECT 1 FROM inventario.lotes WHERE producto_maestro_id = ? AND UPPER(TRIM(numero_lote)) = ? AND id <> ?");
            $stmtDup->execute([$productoId, $numeroLote, $id]);
            if ($stmtDup->fetchColumn()) {
                throw new Exception("Ya existe otro lote con el código '{$numeroLote}' para este producto.");
            }

            $stmtUp = $pdo->prepare("
                UPDATE inventario.lotes
                SET producto_maestro_id = ?,
                    numero_lote = ?,
                    fecha_vencimiento = ?,
                    cantidad_actual = ?
                WHERE id = ?
            ");
            $stmtUp->execute([$productoId, $numeroLote, $fechaVenc, $cantidad, $id]);
            responder('success', "Lote '{$numeroLote}' actualizado correctamente.");
        }
    }

    throw new Exception("Acción no reconocida.");
} catch (Throwable $e) {
    responder('error', $e->getMessage());
}
