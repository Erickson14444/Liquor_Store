<?php
require_once __DIR__ . '/../config/conexion.php';
if ($pdo) {
    $rows = $pdo->query("SELECT DISTINCT modulo FROM seguridad.permisos ORDER BY modulo")->fetchAll(PDO::FETCH_COLUMN);
    echo "Modulos en permisos: " . json_encode($rows) . PHP_EOL;
    $perms = $pdo->query("SELECT clave_sistema, modulo, entidad, accion, descripcion FROM seguridad.permisos ORDER BY modulo, clave_sistema")->fetchAll(PDO::FETCH_ASSOC);
    echo "Total permisos: " . count($perms) . PHP_EOL;
    foreach ($perms as $p) {
        echo "- [{$p['modulo']}] {$p['clave_sistema']} ({$p['entidad']}.{$p['accion']}): {$p['descripcion']}\n";
    }
} else {
    echo "No DB connection: " . ($dbError ?? 'unknown');
}

