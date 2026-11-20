<?php
/**
 * Procesador de Impuestos y Tributos (Backend Controller)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tabla: tesoreria.impuestos
 */

require_once __DIR__ . '/../../config/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!usuarioPuedeAccederImpuestos()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: Solo el Administrador puede gestionar Impuestos.']);
    exit;
}

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
        $tipoCalculo = strtoupper(trim($_POST['tipo_calculo'] ?? 'PORCENTAJE'));
        $valorRaw = trim((string)($_POST['tasa_o_monto'] ?? '0'));
        $ordenRaw = trim((string)($_POST['orden_calculo'] ?? '1'));

        if ($siglas === '' || $nombre === '') {
            throw new Exception("Las siglas y el nombre del tributo son obligatorios.");
        }
        if (!preg_match('/^[A-Z0-9_-]{2,20}$/', $siglas)) {
            throw new Exception("Las siglas solo pueden contener letras, números y guiones (ej. ITBIS_18).");
        }
        if (!in_array($tipoCalculo, ['PORCENTAJE', 'MONTO_FIJO'], true)) {
            throw new Exception("El tipo de cálculo debe ser PORCENTAJE o MONTO_FIJO.");
        }
        if (!preg_match('/^\d+(\.\d{1,4})?$/', $valorRaw) || floatval($valorRaw) < 0) {
            throw new Exception("La tasa o monto debe ser un valor numérico válido mayor o igual a 0.");
        }
        $tasaOMonto = floatval($valorRaw);
        if ($tipoCalculo === 'PORCENTAJE' && $tasaOMonto > 100) {
            throw new Exception("Un impuesto porcentual no puede superar el 100%.");
        }
        if (!preg_match('/^\d+$/', $ordenRaw) || intval($ordenRaw) < 1) {
            throw new Exception("El orden de cálculo debe ser un número entero mayor o igual a 1.");
        }
        $ordenCalculo = intval($ordenRaw);

        if (!$id) {
            $stmtEx = $pdo->prepare("SELECT id FROM tesoreria.impuestos WHERE UPPER(siglas) = ? LIMIT 1");
            $stmtEx->execute([$siglas]);
            $exId = $stmtEx->fetchColumn();
            if ($exId) {
                $id = intval($exId);
            }
        } else {
            $stmtDup = $pdo->prepare("SELECT id FROM tesoreria.impuestos WHERE UPPER(siglas) = ? AND id <> ? LIMIT 1");
            $stmtDup->execute([$siglas, $id]);
            if ($stmtDup->fetchColumn()) {
                throw new Exception("Las siglas '{$siglas}' ya están asignadas a otro impuesto.");
            }
        }

        if ($id) {
            $stmt = $pdo->prepare("
                UPDATE tesoreria.impuestos
                SET siglas = ?, nombre = ?, tipo_calculo = ?, tasa_o_monto = ?, orden_calculo = ?
                WHERE id = ?
            ");
            $stmt->execute([$siglas, $nombre, $tipoCalculo, $tasaOMonto, $ordenCalculo, $id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO tesoreria.impuestos (siglas, nombre, tipo_calculo, tasa_o_monto, orden_calculo, activo)
                VALUES (?, ?, ?, ?, ?, TRUE)
                RETURNING id
            ");
            $stmt->execute([$siglas, $nombre, $tipoCalculo, $tasaOMonto, $ordenCalculo]);
            $id = intval($stmt->fetchColumn());
        }

        $todos = $pdo->query("
            SELECT id, siglas, nombre, tipo_calculo, tasa_o_monto, orden_calculo, activo
            FROM tesoreria.impuestos
            ORDER BY orden_calculo ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'status' => 'success',
            'message' => "Impuesto '{$siglas}' guardado correctamente.",
            'impuestos' => $todos
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'toggle_estado') {
        $id = intval($_POST['id'] ?? 0);
        $activo = filter_var($_POST['activo'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $stmt = $pdo->prepare("UPDATE tesoreria.impuestos SET activo = ? WHERE id = ?");
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
