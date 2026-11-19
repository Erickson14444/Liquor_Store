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
        $tipo_cuenta = strtoupper(trim($_POST['tipo_cuenta'] ?? 'CORRIENTE'));
        $monedaId = !empty($_POST['moneda_id']) ? intval($_POST['moneda_id']) : 0;
        $moneda = strtoupper(trim($_POST['moneda'] ?? 'DOP'));

        if (empty($nombre_banco) || empty($numero_cuenta)) {
            throw new Exception("Banco y número de cuenta son obligatorios.");
        }
        if (preg_match('/^\d+$/', $nombre_banco)) {
            throw new Exception("El nombre del banco no puede contener únicamente números.");
        }
        if (!in_array($tipo_cuenta, ['CORRIENTE', 'AHORRO'], true)) {
            throw new Exception("El tipo de cuenta debe ser CORRIENTE o AHORRO.");
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

        if ($monedaId > 0) {
            $stmtMon = $pdo->prepare("SELECT id, codigo_iso, nombre, simbolo FROM tesoreria.monedas WHERE id = ? LIMIT 1");
            $stmtMon->execute([$monedaId]);
            $monRow = $stmtMon->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmtMon = $pdo->prepare("SELECT id, codigo_iso, nombre, simbolo FROM tesoreria.monedas WHERE UPPER(codigo_iso) = UPPER(?) LIMIT 1");
            $stmtMon->execute([$moneda]);
            $monRow = $stmtMon->fetch(PDO::FETCH_ASSOC);
        }
        $monedaId = !empty($monRow['id']) ? intval($monRow['id']) : 1;
        $monedaIso = $monRow['codigo_iso'] ?? 'DOP';
        $monedaNombre = $monRow['nombre'] ?? 'Peso Dominicano';
        $monedaSimbolo = $monRow['simbolo'] ?? 'RD$';

        if ($id) {
            $stmt = $pdo->prepare("UPDATE tesoreria.cuentas_bancarias SET nombre_banco = ?, numero_cuenta = ?, tipo_cuenta = ?, moneda_id = ? WHERE id = ?");
            $stmt->execute([$nombre_banco, $numero_cuenta, $tipo_cuenta, $monedaId, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO tesoreria.cuentas_bancarias (nombre_banco, numero_cuenta, tipo_cuenta, moneda_id, activa) VALUES (?, ?, ?, ?, true) RETURNING id");
            $stmt->execute([$nombre_banco, $numero_cuenta, $tipo_cuenta, $monedaId]);
            $id = $stmt->fetchColumn();
        }

        echo json_encode([
            'status' => 'success',
            'id' => intval($id),
            'nombre_banco' => $nombre_banco,
            'numero_cuenta' => $numero_cuenta,
            'tipo_cuenta' => $tipo_cuenta,
            'moneda_id' => $monedaId,
            'moneda' => $monedaIso,
            'moneda_nombre' => $monedaNombre,
            'moneda_simbolo' => $monedaSimbolo,
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
