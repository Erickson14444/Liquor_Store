<?php
require __DIR__ . '/../config/conexion.php';
$rows = $pdo->query("SELECT * FROM seguridad.auditoria ORDER BY auditoria_id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
