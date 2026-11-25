<?php
session_start();
$_SESSION['usuario'] = [
    'usuario_id' => 1,
    'persona_id' => 1,
    'username' => 'admin',
    'rol' => 'ADMINISTRADOR',
    'nombre' => 'Nathalia Melendez S.'
];
ob_start();
try {
    include __DIR__ . '/../Ventas y Facturacion/CuentasPorCobrar/cuentas_por_cobrar.php';
    $out = ob_get_clean();
    echo "Length: " . strlen($out) . PHP_EOL;
    echo "First 400 chars:\n" . substr($out, 0, 400) . PHP_EOL;
    echo "End 400 chars:\n" . substr($out, -400) . PHP_EOL;
} catch (Throwable $t) {
    echo "Exception: " . $t->getMessage() . " at " . $t->getFile() . ":" . $t->getLine() . PHP_EOL;
}
