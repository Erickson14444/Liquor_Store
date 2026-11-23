<?php
require_once __DIR__ . '/../config/conexion.php';
$stmt = $pdo->query("SELECT * FROM ventas.secuencias_fiscales_dgii ORDER BY id");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
