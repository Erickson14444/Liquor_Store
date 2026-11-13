<?php
require __DIR__ . '/../config/conexion.php';
if (!$pdo) exit("No PDO\n");

$tables = [
    'inventario.categoria_producto',
    'inventario.marca',
    'inventario.almacenes',
    'inventario.productos_maestro',
    'inventario.presentaciones_producto',
    'seguridad.usuarios',
    'ventas.cajas_pos',
    'tesoreria.cuentas_bancarias'
];

foreach ($tables as $t) {
    try {
        $c = $pdo->query("SELECT count(*) FROM $t")->fetchColumn();
        echo "$t: $c rows\n";
    } catch (Throwable $e) {
        echo "$t: Error (" . $e->getMessage() . ")\n";
    }
}

