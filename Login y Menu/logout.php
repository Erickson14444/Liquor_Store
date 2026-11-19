<?php
session_start();
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    @setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
if (!empty($_COOKIE)) {
    foreach ($_COOKIE as $k => $v) {
        @setcookie($k, '', time() - 42000, '/');
    }
}
@session_destroy();
header("Location: login.php");
exit;
