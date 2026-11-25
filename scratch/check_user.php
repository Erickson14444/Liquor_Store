<?php
require __DIR__ . '/../config/conexion.php';
$u = $pdo->query("SELECT u.usuario_id, u.username, p.persona_id, p.nombre_razon_social FROM seguridad.usuarios u JOIN identidad.personas p ON u.persona_id = p.persona_id WHERE p.persona_id = 'bbfb7afa-b430-4285-bceb-43858655fcee'")->fetchAll(PDO::FETCH_ASSOC);
print_r($u);
