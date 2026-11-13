<?php
require __DIR__ . '/../config/conexion.php';
if (!$pdo) exit("No PDO\n");

$seqs = $pdo->query("
    SELECT sequence_schema, sequence_name 
    FROM information_schema.sequences 
    WHERE sequence_schema NOT IN ('pg_catalog', 'information_schema')
    ORDER BY sequence_schema, sequence_name
")->fetchAll();

foreach ($seqs as $s) {
    $fullName = $s['sequence_schema'] . '.' . $s['sequence_name'];
    $val = $pdo->query("SELECT last_value FROM $fullName")->fetchColumn();
    echo "$fullName: last_value = $val\n";
}

