<?php
require_once __DIR__ . '/../config/conexion.php';

$stmt = $pdo->query("
    SELECT column_name, data_type, is_nullable, column_default 
    FROM information_schema.columns 
    WHERE table_schema = 'ventas' AND table_name = 'cuentas_por_cobrar'
    ORDER BY ordinal_position
");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  - {$r['column_name']} ({$r['data_type']}) NULL:{$r['is_nullable']} DEF:{$r['column_default']}\n";
}
