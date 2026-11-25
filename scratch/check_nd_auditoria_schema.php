<?php
require_once __DIR__ . '/../config/conexion.php';

function inspectTable($pdo, $schema, $table) {
    echo "=== $schema.$table ===\n";
    $stmt = $pdo->prepare("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_schema = ? AND table_name = ?
        ORDER BY ordinal_position
    ");
    $stmt->execute([$schema, $table]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  - {$r['column_name']} ({$r['data_type']}) NULL:{$r['is_nullable']} DEF:{$r['column_default']}\n";
    }
}

inspectTable($pdo, 'ventas', 'notas_debito');
inspectTable($pdo, 'ventas', 'nota_debito_detalles');
inspectTable($pdo, 'seguridad', 'auditoria');

echo "\n=== CONSTRAINTS NOTAS DEBITO ===\n";
$stmt2 = $pdo->query("
    SELECT conname, contype, pg_get_constraintdef(c.oid) as def
    FROM pg_constraint c
    JOIN pg_class t ON c.conrelid = t.oid
    JOIN pg_namespace n ON t.relnamespace = n.oid
    WHERE t.relname LIKE '%nota%debito%'
");
while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    echo "{$r['conname']} ({$r['contype']}): {$r['def']}\n";
}

echo "\n=== SECUENCIA B03 EN VENTAS.SECUENCIAS_FISCALES_DGII ===\n";
$stmt3 = $pdo->query("SELECT * FROM ventas.secuencias_fiscales_dgii WHERE tipo_comprobante = 'B03'");
print_r($stmt3->fetchAll(PDO::FETCH_ASSOC));
