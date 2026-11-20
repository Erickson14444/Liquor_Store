<?php
/**
 * Procesador de Monedas y Tasas de Cambio (Backend Controller)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tabla: tesoreria.monedas
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
        $codigoIso = strtoupper(trim($_POST['codigo_iso'] ?? ''));
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre !== '') {
            $nombre = mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($nombre, 1, null, 'UTF-8');
        }
        $simbolo = trim($_POST['simbolo'] ?? '');
        $tasaRaw = trim((string)($_POST['tasa_cambio_referencia'] ?? '1.0000'));
        $esBase = filter_var($_POST['es_moneda_base'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!preg_match('/^[A-Z]{3}$/', $codigoIso)) {
            throw new Exception("El código ISO debe contener exactamente 3 letras mayúsculas (ej. DOP, USD, EUR).");
        }
        if ($nombre === '' || $simbolo === '') {
            throw new Exception("El nombre de la divisa y el símbolo son obligatorios.");
        }
        if (!preg_match('/^\d+(\.\d{1,4})?$/', $tasaRaw) || floatval($tasaRaw) <= 0) {
            throw new Exception("La tasa de cambio debe ser un número positivo mayor a 0.");
        }

        $tasa = $esBase ? 1.0000 : floatval($tasaRaw);

        $pdo->beginTransaction();

        if (!$id) {
            $stmtEx = $pdo->prepare("SELECT id FROM tesoreria.monedas WHERE UPPER(codigo_iso) = ? LIMIT 1");
            $stmtEx->execute([$codigoIso]);
            $exId = $stmtEx->fetchColumn();
            if ($exId) {
                $id = intval($exId);
            }
        } else {
            $stmtDup = $pdo->prepare("SELECT id FROM tesoreria.monedas WHERE UPPER(codigo_iso) = ? AND id <> ? LIMIT 1");
            $stmtDup->execute([$codigoIso, $id]);
            if ($stmtDup->fetchColumn()) {
                throw new Exception("El código ISO '{$codigoIso}' ya pertenece a otra moneda.");
            }
        }

        if ($esBase) {
            $pdo->query("UPDATE tesoreria.monedas SET es_moneda_base = FALSE");
        }

        if ($id) {
            $stmt = $pdo->prepare("
                UPDATE tesoreria.monedas
                SET codigo_iso = ?, nombre = ?, simbolo = ?, tasa_cambio_referencia = ?, es_moneda_base = ?
                WHERE id = ?
            ");
            $stmt->execute([$codigoIso, $nombre, $simbolo, $tasa, $esBase ? 'true' : 'false', $id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO tesoreria.monedas (codigo_iso, nombre, simbolo, tasa_cambio_referencia, es_moneda_base, activa)
                VALUES (?, ?, ?, ?, ?, TRUE)
                RETURNING id
            ");
            $stmt->execute([$codigoIso, $nombre, $simbolo, $tasa, $esBase ? 'true' : 'false']);
            $id = intval($stmt->fetchColumn());
        }

        $pdo->commit();

        $todas = $pdo->query("
            SELECT id, codigo_iso, nombre, simbolo, tasa_cambio_referencia, es_moneda_base, activa
            FROM tesoreria.monedas
            ORDER BY CASE WHEN es_moneda_base = TRUE THEN 0 ELSE 1 END, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'status' => 'success',
            'message' => "Moneda '{$codigoIso}' guardada correctamente.",
            'monedas' => $todas
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'toggle_estado') {
        $id = intval($_POST['id'] ?? 0);
        $activa = filter_var($_POST['activa'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $stmtChk = $pdo->prepare("SELECT es_moneda_base, codigo_iso FROM tesoreria.monedas WHERE id = ?");
        $stmtChk->execute([$id]);
        $row = $stmtChk->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception("Moneda no encontrada.");
        }
        if (!$activa && ($row['es_moneda_base'] === true || $row['es_moneda_base'] === 't')) {
            throw new Exception("La moneda base del sistema ({$row['codigo_iso']}) no puede ser desactivada.");
        }

        $stmt = $pdo->prepare("UPDATE tesoreria.monedas SET activa = ? WHERE id = ?");
        $stmt->execute([$activa ? 'true' : 'false', $id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    throw new Exception("Acción no reconocida.");
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
