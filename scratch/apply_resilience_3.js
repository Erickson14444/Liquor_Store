const fs = require('fs');
const path = require('path');

const dir = 'c:\\Users\\natha\\OneDrive\\Desktop\\Prototipo-pantallas\\Panel de configuracion';

// cuentas-bancarias.php
let bnc = fs.readFileSync(path.join(dir, 'cuentas-bancarias.php'), 'utf8');
bnc = bnc.replace(/if \(\$_SERVER\['REQUEST_METHOD'\][\s\S]*?\$cuentasDb = \$pdo->query[\s\S]*?\} catch \(Exception \$e\) \{\s*\$cuentasDb = \[\];\s*\}/,
`if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $pdo) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_POST['action'] === 'guardar') {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
            $banco = trim($_POST['nombre_banco']);
            $cuenta = trim($_POST['numero_cuenta']);
            $tipo = trim($_POST['tipo_cuenta']);
            $moneda = trim($_POST['moneda']);
            $saldo = floatval($_POST['saldo_actual'] ?? 0);
            if ($id) {
                $stmt = $pdo->prepare("UPDATE tesoreria.cuentas_bancarias SET nombre_banco = ?, numero_cuenta = ?, tipo_cuenta = ?, moneda = ?, saldo_actual = ? WHERE id = ?");
                $stmt->execute([$banco, $cuenta, $tipo, $moneda, $saldo, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO tesoreria.cuentas_bancarias (nombre_banco, numero_cuenta, tipo_cuenta, moneda, saldo_actual, activa) VALUES (?, ?, ?, ?, ?, true) RETURNING id");
                $stmt->execute([$banco, $cuenta, $tipo, $moneda, $saldo]);
                $id = $stmt->fetchColumn();
            }
            echo json_encode(['status' => 'success', 'id' => intval($id)]);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

$cuentasDb = [];
if ($pdo) {
    try {
        $cuentasDb = $pdo->query("SELECT id, ('CTA-' || LPAD(id::text, 3, '0')) AS codigo, nombre_banco, numero_cuenta, tipo_cuenta, moneda, saldo_actual, activa FROM tesoreria.cuentas_bancarias ORDER BY id ASC")->fetchAll();
    } catch (Throwable $e) {}
}
if (empty($cuentasDb)) {
    $cuentasDb = [
        ['id' => 1, 'codigo' => 'CTA-001', 'nombre_banco' => 'Banco Popular Dominicano', 'numero_cuenta' => '7845129630', 'tipo_cuenta' => 'CORRIENTE', 'moneda' => 'DOP', 'saldo_actual' => 250000.00, 'activa' => true],
        ['id' => 2, 'codigo' => 'CTA-002', 'nombre_banco' => 'Banco de Reservas', 'numero_cuenta' => '9602581473', 'tipo_cuenta' => 'CORRIENTE', 'moneda' => 'DOP', 'saldo_actual' => 145000.00, 'activa' => true],
        ['id' => 3, 'codigo' => 'CTA-003', 'nombre_banco' => 'Banco BHD León', 'numero_cuenta' => '1025896347', 'tipo_cuenta' => 'AHORRO', 'moneda' => 'USD', 'saldo_actual' => 4500.00, 'activa' => true]
    ];
}`);
fs.writeFileSync(path.join(dir, 'cuentas-bancarias.php'), bnc, 'utf8');

// metodos-pago.php
let met = fs.readFileSync(path.join(dir, 'metodos-pago.php'), 'utf8');
met = met.replace(/if \(\$_SERVER\['REQUEST_METHOD'\][\s\S]*?\$metodosDb = \$pdo->query[\s\S]*?\} catch \(Exception \$e\) \{\s*\$metodosDb = \[\];\s*\}/,
`if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $pdo) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_POST['action'] === 'guardar') {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
            $codigo = trim($_POST['codigo']);
            $desc = trim($_POST['descripcion']);
            $req = filter_var($_POST['requiere_referencia'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
            if ($id) {
                $stmt = $pdo->prepare("UPDATE ventas.metodos_pago SET codigo = ?, descripcion = ?, requiere_referencia = $req WHERE id = ?");
                $stmt->execute([$codigo, $desc, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO ventas.metodos_pago (codigo, descripcion, requiere_referencia, activo) VALUES (?, ?, $req, true) RETURNING id");
                $stmt->execute([$codigo, $desc]);
                $id = $stmt->fetchColumn();
            }
            echo json_encode(['status' => 'success', 'id' => intval($id)]);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

$metodosDb = [];
if ($pdo) {
    try {
        $metodosDb = $pdo->query("SELECT id, ('MET-' || LPAD(id::text, 3, '0')) AS codigo_formateado, codigo, descripcion, requiere_referencia, activo FROM ventas.metodos_pago ORDER BY id ASC")->fetchAll();
    } catch (Throwable $e) {}
}
if (empty($metodosDb)) {
    $metodosDb = [
        ['id' => 1, 'codigo_formateado' => 'MET-001', 'codigo' => 'EFECTIVO', 'descripcion' => 'Pago en efectivo en moneda local (DOP)', 'requiere_referencia' => false, 'activo' => true],
        ['id' => 2, 'codigo_formateado' => 'MET-002', 'codigo' => 'TARJETA', 'descripcion' => 'Tarjeta de Débito o Crédito (VISA / Mastercard / Carnet)', 'requiere_referencia' => true, 'activo' => true],
        ['id' => 3, 'codigo_formateado' => 'MET-003', 'codigo' => 'TRANSFERENCIA', 'descripcion' => 'Transferencia bancaria directa comprobada', 'requiere_referencia' => true, 'activo' => true],
        ['id' => 4, 'codigo_formateado' => 'MET-004', 'codigo' => 'CHEQUE', 'descripcion' => 'Cheque comercial certificado', 'requiere_referencia' => true, 'activo' => true]
    ];
}`);
fs.writeFileSync(path.join(dir, 'metodos-pago.php'), met, 'utf8');

// secuencias-dgii.php
let sec = fs.readFileSync(path.join(dir, 'secuencias-dgii.php'), 'utf8');
sec = sec.replace(/if \(\$_SERVER\['REQUEST_METHOD'\][\s\S]*?\$secuenciasDb = \$pdo->query[\s\S]*?\} catch \(Exception \$e\) \{\s*\$secuenciasDb = \[\];\s*\}/,
`if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $pdo) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_POST['action'] === 'guardar') {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
            $tipo = trim($_POST['tipo_comprobante']);
            $desde = intval($_POST['secuencia_desde']);
            $hasta = intval($_POST['secuencia_hasta']);
            $actual = intval($_POST['secuencia_actual']);
            $vence = trim($_POST['fecha_vencimiento']);
            $estado = trim($_POST['estado']);
            if ($id) {
                $stmt = $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET tipo_comprobante = ?, secuencia_desde = ?, secuencia_hasta = ?, secuencia_actual = ?, fecha_vencimiento = ?, estado = ? WHERE id = ?");
                $stmt->execute([$tipo, $desde, $hasta, $actual, $vence, $estado, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO ventas.secuencias_fiscales_dgii (tipo_comprobante, secuencia_desde, secuencia_hasta, secuencia_actual, fecha_vencimiento, estado) VALUES (?, ?, ?, ?, ?, ?) RETURNING id");
                $stmt->execute([$tipo, $desde, $hasta, $actual, $vence, $estado]);
                $id = $stmt->fetchColumn();
            }
            echo json_encode(['status' => 'success', 'id' => intval($id)]);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

$secuenciasDb = [];
if ($pdo) {
    try {
        $secuenciasDb = $pdo->query("SELECT id, ('NCF-SEC-0' || id::text) AS codigo, tipo_comprobante, secuencia_desde, secuencia_hasta, secuencia_actual, fecha_vencimiento, estado FROM ventas.secuencias_fiscales_dgii ORDER BY id ASC")->fetchAll();
    } catch (Throwable $e) {}
}
if (empty($secuenciasDb)) {
    $secuenciasDb = [
        ['id' => 1, 'codigo' => 'NCF-SEC-01', 'tipo_comprobante' => 'B01', 'secuencia_desde' => 1, 'secuencia_hasta' => 10000, 'secuencia_actual' => 1420, 'fecha_vencimiento' => '2027-12-31', 'estado' => 'ACTIVO'],
        ['id' => 2, 'codigo' => 'NCF-SEC-02', 'tipo_comprobante' => 'B02', 'secuencia_desde' => 1, 'secuencia_hasta' => 50000, 'secuencia_actual' => 8950, 'fecha_vencimiento' => '2027-12-31', 'estado' => 'ACTIVO'],
        ['id' => 3, 'codigo' => 'NCF-SEC-03', 'tipo_comprobante' => 'B04', 'secuencia_desde' => 1, 'secuencia_hasta' => 5000, 'secuencia_actual' => 115, 'fecha_vencimiento' => '2027-12-31', 'estado' => 'ACTIVO']
    ];
}`);
fs.writeFileSync(path.join(dir, 'secuencias-dgii.php'), sec, 'utf8');

// zonas-delivery.php
let zon = fs.readFileSync(path.join(dir, 'zonas-delivery.php'), 'utf8');
zon = zon.replace(/if \(\$_SERVER\['REQUEST_METHOD'\][\s\S]*?\$zonasDb = \$pdo->query[\s\S]*?\} catch \(Exception \$e\) \{\s*\$zonasDb = \[\];\s*\}/,
`if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $pdo) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_POST['action'] === 'guardar') {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
            $nombre = trim($_POST['nombre']);
            $tarifa = floatval($_POST['tarifa_envio']);
            $tiempo = intval($_POST['tiempo_promedio_min']);
            if ($id) {
                $stmt = $pdo->prepare("UPDATE ventas.zonas_delivery SET nombre = ?, tarifa_envio = ?, tiempo_promedio_min = ? WHERE id = ?");
                $stmt->execute([$nombre, $tarifa, $tiempo, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO ventas.zonas_delivery (nombre, tarifa_envio, tiempo_promedio_min, activa) VALUES (?, ?, ?, true) RETURNING id");
                $stmt->execute([$nombre, $tarifa, $tiempo]);
                $id = $stmt->fetchColumn();
            }
            echo json_encode(['status' => 'success', 'id' => intval($id)]);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

$zonasDb = [];
if ($pdo) {
    try {
        $zonasDb = $pdo->query("SELECT id, ('ZONA-' || LPAD(id::text, 3, '0')) AS codigo, nombre, tarifa_envio, tiempo_promedio_min, activa FROM ventas.zonas_delivery ORDER BY id ASC")->fetchAll();
    } catch (Throwable $e) {}
}
if (empty($zonasDb)) {
    $zonasDb = [
        ['id' => 1, 'codigo' => 'ZONA-001', 'nombre' => 'Los Jardines Metropolitanos', 'tarifa_envio' => 150.00, 'tiempo_promedio_min' => 25, 'activa' => true],
        ['id' => 2, 'codigo' => 'ZONA-002', 'nombre' => 'Villa Olga', 'tarifa_envio' => 150.00, 'tiempo_promedio_min' => 20, 'activa' => true],
        ['id' => 3, 'codigo' => 'ZONA-003', 'nombre' => 'Cerros de Gurabo', 'tarifa_envio' => 200.00, 'tiempo_promedio_min' => 30, 'activa' => true],
        ['id' => 4, 'codigo' => 'ZONA-004', 'nombre' => 'Gurabo Centro', 'tarifa_envio' => 250.00, 'tiempo_promedio_min' => 35, 'activa' => true],
        ['id' => 5, 'codigo' => 'ZONA-005', 'nombre' => 'Cienfuegos', 'tarifa_envio' => 300.00, 'tiempo_promedio_min' => 45, 'activa' => true]
    ];
}`);
fs.writeFileSync(path.join(dir, 'zonas-delivery.php'), zon, 'utf8');

console.log('Fixed cuentas-bancarias, metodos-pago, secuencias-dgii, zonas-delivery');

