<?php
/**
 * Redirección principal del ERP House & Ice
 * Permite entrar directamente desde: http://localhost/Prototipo-pantallas/
 */
session_start();

// Auto-inicializar sesión de demostración si no existe o si es incompatible
if (empty($_SESSION['usuario']) || !is_array($_SESSION['usuario']) || empty($_SESSION['usuario']['id'])) {
    $_SESSION['usuario'] = [
        'id'              => 1,
        'username'        => 'nmelendez',
        'nombre_completo' => 'Nathalia Meléndez',
        'email'           => 'admin@cavaybarrica.do',
        'rol'             => 'Administrador',
        'caja_id'         => 1
    ];
}

header("Location: Login%20y%20Menu/menu.php");
exit;
