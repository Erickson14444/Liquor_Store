<?php
require __DIR__ . '/../config/conexion.php';
$stmt = $pdo->prepare("INSERT INTO inventario.marca (nombre, activo) VALUES ('Test Brand', true) RETURNING id");
$stmt->execute();
$newId = $stmt->fetchColumn();
echo "Inserted Test Brand with ID: " . $newId . "\n";

// Delete the test brand
$pdo->exec("DELETE FROM inventario.marca WHERE id = $newId");
// Reset sequence to max(id)
$pdo->exec("SELECT setval('inventario.marca_id_seq', (SELECT MAX(id) FROM inventario.marca), true)");
echo "Cleaned up and sequence reset to max ID!\n";

