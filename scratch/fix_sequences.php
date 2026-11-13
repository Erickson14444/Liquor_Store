<?php
require __DIR__ . '/../config/conexion.php';
if (!$pdo) exit("No PDO connection\n");

try {
    $pdo->beginTransaction();

    // 1. Corregir 'tito' a id = 1 si existe con id = 11
    $exists = $pdo->query("SELECT id FROM inventario.marca WHERE id = 11")->fetchColumn();
    if ($exists) {
        $pdo->exec("UPDATE inventario.marca SET id = 1 WHERE id = 11");
        $pdo->exec("SELECT setval('inventario.marca_id_seq', 1, true)");
        echo "✅ Marca 'tito' actualizada a ID 1 y secuencia ajustada para que el próximo sea 2.\n";
    }

    // 2. Reiniciar secuencias de tablas que están vacías
    $emptyTablesWithSeqs = [
        ['table' => 'inventario.categoria_producto', 'seq' => 'inventario.categoria_producto_id_seq'],
        ['table' => 'inventario.almacenes',          'seq' => 'inventario.almacenes_id_seq'],
        ['table' => 'ventas.cajas_pos',              'seq' => 'ventas.cajas_pos_id_seq']
    ];

    foreach ($emptyTablesWithSeqs as $item) {
        $count = $pdo->query("SELECT count(*) FROM {$item['table']}")->fetchColumn();
        if ($count == 0) {
            $pdo->exec("SELECT setval('{$item['seq']}', 1, false)");
            echo "✅ Secuencia {$item['seq']} reiniciada a 1 (próximo registro será ID 1).\n";
        }
    }

    $pdo->commit();

    // Verificación final
    echo "\n--- ESTADO ACTUAL ---\n";
    $marcas = $pdo->query("SELECT * FROM inventario.marca")->fetchAll();
    print_r($marcas);

    $seqMarca = $pdo->query("SELECT last_value, is_called FROM inventario.marca_id_seq")->fetch();
    print_r($seqMarca);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}

