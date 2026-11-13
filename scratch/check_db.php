<?php
require __DIR__ . '/../config/conexion.php';
if ($pdo) {
    echo "Connected to PostgreSQL!" . PHP_EOL;
    $res = $pdo->query("SELECT * FROM inventario.marca")->fetchAll();
    echo "Current rows in inventario.marca:" . PHP_EOL;
    print_r($res);
    $seq = $pdo->query("SELECT last_value, is_called FROM inventario.marca_id_seq")->fetch();
    echo "Sequence info:" . PHP_EOL;
    print_r($seq);
} else {
    echo "Failed to connect: " . $dbError . PHP_EOL;
}

