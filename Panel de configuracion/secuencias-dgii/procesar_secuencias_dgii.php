<?php
require_once __DIR__ . '/../../config/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!usuarioPuedeAccederSecuenciasFiscales($pdo)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: Solo el Administrador o el Supervisor de Turno pueden gestionar Secuencias Fiscales (NCF).']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

$action = $_POST['action'] ?? 'guardar';

try {
    if ($action === 'guardar') {
        $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
        $tipo_comprobante = strtoupper(trim($_POST['tipo_comprobante'] ?? ''));
        $secuencia_desde = intval($_POST['secuencia_desde'] ?? 1);
        $secuencia_hasta = intval($_POST['secuencia_hasta'] ?? 1000);
        $secuencia_actual = intval($_POST['secuencia_actual'] ?? 1);
        $fecha_vencimiento = $_POST['fecha_vencimiento'] ?? date('Y-12-31');
        $estado = $_POST['estado'] ?? 'ACTIVO';

        if ($secuencia_desde <= 0 || $secuencia_hasta < $secuencia_desde || $secuencia_actual < $secuencia_desde) {
            throw new Exception("Rango inválido: 'Hasta' y 'Actual' deben ser mayores o iguales a 'Desde' (> 0).");
        }

        // Validación amigable antes de guardar: solo puede haber una secuencia ACTIVA por tipo de comprobante
        if (strtoupper($estado) === 'ACTIVO') {
            $sqlDup = "SELECT id FROM ventas.secuencias_fiscales_dgii WHERE tipo_comprobante = ? AND estado = 'ACTIVO'";
            $paramsDup = [$tipo_comprobante];
            if ($id) {
                $sqlDup .= " AND id <> ?";
                $paramsDup[] = $id;
            }
            $stmtDup = $pdo->prepare($sqlDup . " LIMIT 1");
            $stmtDup->execute($paramsDup);
            if ($stmtDup->fetchColumn()) {
                throw new Exception("Ya existe una secuencia activa para {$tipo_comprobante}. Edítala o cámbiala a AGOTADO/VENCIDO primero.");
            }
        }

        // Ampliar restricción en BD si aún solo tenía B01..B04 para soportar todos los NCF de la DGII
        try {
            $pdo->exec("
                ALTER TABLE ventas.secuencias_fiscales_dgii DROP CONSTRAINT IF EXISTS chk_secuencias_dgii_tipo;
                ALTER TABLE ventas.secuencias_fiscales_dgii ADD CONSTRAINT chk_secuencias_dgii_tipo
                CHECK (tipo_comprobante IN ('B01','B02','B03','B04','B11','B12','B13','B14','B15','B16','B17'));
            ");
        } catch (Throwable $eIgn) {}

        if ($id) {
            $stmt = $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET tipo_comprobante = ?, secuencia_desde = ?, secuencia_hasta = ?, secuencia_actual = ?, fecha_vencimiento = ?, estado = ? WHERE id = ?");
            $stmt->execute([$tipo_comprobante, $secuencia_desde, $secuencia_hasta, $secuencia_actual, $fecha_vencimiento, $estado, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO ventas.secuencias_fiscales_dgii (tipo_comprobante, secuencia_desde, secuencia_hasta, secuencia_actual, fecha_vencimiento, estado) VALUES (?, ?, ?, ?, ?, ?) RETURNING id");
            $stmt->execute([$tipo_comprobante, $secuencia_desde, $secuencia_hasta, $secuencia_actual, $fecha_vencimiento, $estado]);
            $id = $stmt->fetchColumn();
        }

        echo json_encode([
            'status' => 'success',
            'id' => intval($id),
            'tipo_comprobante' => $tipo_comprobante,
            'secuencia_desde' => $secuencia_desde,
            'secuencia_hasta' => $secuencia_hasta,
            'secuencia_actual' => $secuencia_actual,
            'fecha_vencimiento' => $fecha_vencimiento,
            'estado' => $estado
        ]);
        exit;
    }

    throw new Exception("Acción no reconocida.");
} catch (PDOException $e) {
    http_response_code(400);
    $msgDb = $e->getMessage();
    $code  = (string)$e->getCode();

    if ($code === '23505' || stripos($msgDb, 'uk_secuencia_activa') !== false) {
        $tipoLbl = !empty($tipo_comprobante) ? " para {$tipo_comprobante}" : '';
        $mensaje = "Ya existe una secuencia activa{$tipoLbl}. Edítala o cámbiala a AGOTADO/VENCIDO primero.";
    } elseif (stripos($msgDb, 'chk_secuencias_dgii_rango') !== false) {
        $mensaje = "Rango inválido: 'Hasta' y 'Actual' deben ser mayores o iguales a 'Desde'.";
    } elseif (stripos($msgDb, 'chk_secuencias_dgii_tipo') !== false) {
        $mensaje = "Tipo de comprobante no permitido.";
    } elseif (stripos($msgDb, 'chk_secuencias_dgii_estado') !== false) {
        $mensaje = "Estado inválido (use ACTIVO, AGOTADO o VENCIDO).";
    } else {
        $mensaje = "No se pudo guardar la secuencia fiscal.";
    }

    echo json_encode(['status' => 'error', 'message' => $mensaje]);
    exit;
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
