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
    if ($action === 'guardar') {
        $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
        $nombre_banco = trim($_POST['nombre_banco'] ?? '');
        $numero_cuenta = preg_replace('/\D/', '', trim($_POST['numero_cuenta'] ?? ''));
        $tipo_cuenta = $_POST['tipo_cuenta'] ?? 'CORRIENTE';
        $moneda = $_POST['moneda'] ?? 'DOP';

        if (empty($nombre_banco) || empty($numero_cuenta)) {
            throw new Exception("Banco y número de cuenta son obligatorios.");
        }

        if (!preg_match('/^\d{8,20}$/', $numero_cuenta) || preg_match('/^0+$/', $numero_cuenta)) {
            throw new Exception("El número de cuenta debe contener entre 8 y 20 dígitos numéricos válidos.");
        }

        if ($id) {
            $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM tesoreria.cuentas_bancarias WHERE numero_cuenta = ? AND id <> ?");
            $stmtDup->execute([$numero_cuenta, $id]);
        } else {
            $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM tesoreria.cuentas_bancarias WHERE numero_cuenta = ?");
            $stmtDup->execute([$numero_cuenta]);
        }
        if ((int)$stmtDup->fetchColumn() > 0) {
            throw new Exception("Este número de cuenta bancaria ya se encuentra registrado.");
        }

        if ($id) {
            $stmt = $pdo->prepare("UPDATE tesoreria.cuentas_bancarias SET nombre_banco = ?, numero_cuenta = ?, tipo_cuenta = ?, moneda = ? WHERE id = ?");
            $stmt->execute([$nombre_banco, $numero_cuenta, $tipo_cuenta, $moneda, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO tesoreria.cuentas_bancarias (nombre_banco, numero_cuenta, tipo_cuenta, moneda, saldo_actual, activa) VALUES (?, ?, ?, ?, 0.00, true) RETURNING id");
            $stmt->execute([$nombre_banco, $numero_cuenta, $tipo_cuenta, $moneda]);
            $id = $stmt->fetchColumn();
        }

        echo json_encode([
            'status' => 'success',
            'id' => intval($id),
            'nombre_banco' => $nombre_banco,
            'numero_cuenta' => $numero_cuenta,
            'tipo_cuenta' => $tipo_cuenta,
            'moneda' => $moneda,
            'saldo_actual' => 0.00,
            'activa' => true
        ]);
        exit;
    }

    if ($action === 'toggle_estado') {
        $id = intval($_POST['id']);
        $activa = filter_var($_POST['activa'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        $stmt = $pdo->prepare("UPDATE tesoreria.cuentas_bancarias SET activa = $activa WHERE id = ?");
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
