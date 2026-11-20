<?php
/**
 * Procesador de Retenciones Fiscales DGII (Backend Controller)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tabla: tesoreria.retenciones
 */

require_once __DIR__ . '/../../config/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
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
        $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
        $siglas = strtoupper(trim($_POST['siglas'] ?? ''));
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre !== '') {
            $nombre = mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($nombre, 1, null, 'UTF-8');
        }
        $baseCalculo = strtoupper(trim($_POST['base_calculo'] ?? 'SUBTOTAL'));
        $porcRaw = trim((string)($_POST['porcentaje'] ?? '0'));

        if ($siglas === '' || $nombre === '') {
            throw new Exception("Las siglas y el nombre de la retención son obligatorios.");
        }
        if (!preg_match('/^[A-Z0-9_-]{2,20}$/', $siglas)) {
            throw new Exception("Las siglas solo pueden contener letras, números y guiones (ej. RET_ISR_10).");
        }
        if (!in_array($baseCalculo, ['SUBTOTAL', 'IMPUESTO'], true)) {
            throw new Exception("La base de cálculo debe ser SUBTOTAL o IMPUESTO.");
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $porcRaw)) {
            throw new Exception("El porcentaje debe ser un valor numérico válido.");
        }
        $porcentaje = floatval($porcRaw);
        if ($porcentaje <= 0 || $porcentaje > 100) {
            throw new Exception("El porcentaje de retención debe ser mayor a 0% y menor o igual a 100%.");
        }

        if (!$id) {
            $stmtEx = $pdo->prepare("SELECT id FROM tesoreria.retenciones WHERE UPPER(siglas) = ? LIMIT 1");
            $stmtEx->execute([$siglas]);
            $exId = $stmtEx->fetchColumn();
            if ($exId) {
                $id = intval($exId);
            }
        } else {
            $stmtDup = $pdo->prepare("SELECT id FROM tesoreria.retenciones WHERE UPPER(siglas) = ? AND id <> ? LIMIT 1");
            $stmtDup->execute([$siglas, $id]);
            if ($stmtDup->fetchColumn()) {
                throw new Exception("Las siglas '{$siglas}' ya pertenecen a otra retención.");
            }
        }

        if ($id) {
            $stmt = $pdo->prepare("
                UPDATE tesoreria.retenciones
                SET siglas = ?, nombre = ?, base_calculo = ?, porcentaje = ?
                WHERE id = ?
            ");
            $stmt->execute([$siglas, $nombre, $baseCalculo, $porcentaje, $id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO tesoreria.retenciones (siglas, nombre, base_calculo, porcentaje, activo)
                VALUES (?, ?, ?, ?, TRUE)
                RETURNING id
            ");
            $stmt->execute([$siglas, $nombre, $baseCalculo, $porcentaje]);
            $id = intval($stmt->fetchColumn());
        }

        $todas = $pdo->query("
            SELECT id, siglas, nombre, base_calculo, porcentaje, activo
            FROM tesoreria.retenciones
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'status' => 'success',
            'message' => "Retención '{$siglas}' guardada correctamente.",
            'retenciones' => $todas
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'toggle_estado') {
        $id = intval($_POST['id'] ?? 0);
        $activo = filter_var($_POST['activo'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $stmt = $pdo->prepare("UPDATE tesoreria.retenciones SET activo = ? WHERE id = ?");
        $stmt->execute([$activo ? 'true' : 'false', $id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    throw new Exception("Acción no reconocida.");
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
