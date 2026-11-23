<?php
require __DIR__ . '/../config/conexion.php';
$cols = $pdo->query("
    SELECT column_name, data_type 
    FROM information_schema.columns 
    WHERE table_schema = 'ventas' AND table_name = 'sesiones_caja'
")->fetchAll(PDO::FETCH_ASSOC);
print_r($cols);
