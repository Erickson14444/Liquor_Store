<?php
/**
 * Redirección principal del ERP House & Ice
 */
session_start();

if (!empty($_SESSION['usuario']['usuario_id'])) {
    header("Location: Login%20y%20Menu/menu.php");
} else {
    header("Location: Login%20y%20Menu/login.php");
}
exit;
