<?php
require __DIR__ . '/../config/conexion.php';
$cols = $pdo->query("
    SELECT column_name, data_type 
    FROM information_schema.columns 
    WHERE table_schema = 'seguridad' AND table_name = 'auditoria'
")->fetchAll(PDO::FETCH_ASSOC);
print_r($cols);
$indexes = $pdo->query("
    SELECT indexname, indexdef 
    FROM pg_indexes 
    WHERE schemaname = 'seguridad' AND tablename = 'auditoria'
")->fetchAll(PDO::FETCH_ASSOC);
print_r($indexes);
