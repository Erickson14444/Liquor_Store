<?php
require_once __DIR__ . '/../config/conexion.php';

$chk = $pdo->query("SELECT id FROM ventas.secuencias_fiscales_dgii WHERE tipo_comprobante = 'B03'")->fetchColumn();
if (!$chk) {
    $pdo->exec("
        INSERT INTO ventas.secuencias_fiscales_dgii 
            (tipo_comprobante, secuencia_desde, secuencia_hasta, secuencia_actual, fecha_vencimiento, estado)
        VALUES 
            ('B03', 1, 1000, 1, '2027-12-31', 'ACTIVO')
    ");
    echo "Secuencia B03 insertada exitosamente.\n";
} else {
    echo "Secuencia B03 ya existe (ID: {$chk}).\n";
}
