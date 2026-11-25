<?php
require __DIR__ . '/../config/conexion.php';
$rows = $pdo->query("SELECT * FROM ventas.sesiones_caja ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
