<?php
require __DIR__ . '/../config/conexion.php';
$users = $pdo->query("SELECT u.usuario_id, u.username, u.persona_id, p.nombre_razon_social FROM seguridad.usuarios u LEFT JOIN identidad.personas p ON u.persona_id = p.persona_id")->fetchAll(PDO::FETCH_ASSOC);
print_r($users);
$emp = $pdo->query("SELECT * FROM identidad.empleados WHERE empleado_id = 'bbfb7afa-b430-4285-bceb-43858655fcee' OR persona_id = 'bbfb7afa-b430-4285-bceb-43858655fcee'")->fetchAll(PDO::FETCH_ASSOC);
print_r($emp);
