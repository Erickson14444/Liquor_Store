<?php
/**
 * ============================================================================
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Módulo de Conexión a Base de Datos PostgreSQL y Sesión de Empleado Activo
 * ============================================================================
 */

$host     = "localhost";
$port     = "5433";
$dbname   = "Liquor_Store";
$user     = "postgres";
$password = "postgre";

$pdo         = null;
$dbConnected = false;
$dbError     = null;

try {
    // Cadena de conexión PDO para PostgreSQL con schemas automáticos y UTF-8
    $searchPath = "public,identidad,seguridad,inventario,ventas,compras,promociones,tesoreria";
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};options='--client_encoding=UTF8 -c search_path={$searchPath}'";

    $opciones = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 2, // Timeout corto para no congelar la carga si PG no está encendido
    ];

    $pdo = new PDO($dsn, $user, $password, $opciones);
    $dbConnected = true;

} catch (PDOException $e) {
    $pdo = null;
    $dbConnected = false;
    $dbError = $e->getMessage();
}

/**
 * Función auxiliar para obtener la conexión PDO
 * @return PDO|null
 */
function getConexion(): ?PDO {
    global $pdo;
    return $pdo;
}

/**
 * Calcula las 2 iniciales del nombre del empleado (ej. "Erickson Santos" -> "ES")
 */
if (!function_exists('calcularInicialesEmpleado')) {
    function calcularInicialesEmpleado(string $nombre): string {
        $partes = preg_split('/\s+/', trim($nombre), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($partes)) {
            return 'US';
        }
        $ini1 = mb_strtoupper(mb_substr($partes[0], 0, 1, 'UTF-8'), 'UTF-8');
        $ini2 = isset($partes[1])
            ? mb_strtoupper(mb_substr($partes[1], 0, 1, 'UTF-8'), 'UTF-8')
            : mb_strtoupper(mb_substr($partes[0], 1, 1, 'UTF-8'), 'UTF-8');
        return $ini1 . ($ini2 ?: '');
    }
}

// Inicialización y sincronización de sesión del empleado en entorno web
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Limpiar cualquier sesión demo antigua (id = 1 / nmelendez) que no provenga de seguridad.usuarios
    if (!empty($_SESSION['usuario']) && (
        ($_SESSION['usuario']['id'] ?? null) === 1 ||
        ($_SESSION['usuario']['username'] ?? '') === 'nmelendez' ||
        empty($_SESSION['usuario']['usuario_id'])
    )) {
        unset($_SESSION['usuario']);
    }

    // Si hay un empleado autenticado, sincronizar sus datos en vivo desde PostgreSQL
    if (!empty($_SESSION['usuario']['usuario_id']) && $pdo) {
        try {
            $stmtSesEmp = $pdo->prepare("
                SELECT u.usuario_id, u.username, u.estado,
                       p.persona_id, p.nombre_razon_social, p.email,
                       e.empleado_id, e.codigo_empleado, e.cargo, e.estado_laboral,
                       r.rol_id, r.nombre AS rol_nombre
                FROM seguridad.usuarios u
                LEFT JOIN identidad.personas p ON u.persona_id = p.persona_id
                LEFT JOIN identidad.empleados e ON e.persona_id = p.persona_id
                LEFT JOIN seguridad.usuarios_roles ur ON u.usuario_id = ur.usuario_id
                LEFT JOIN seguridad.roles r ON ur.rol_id = r.rol_id
                WHERE u.usuario_id = ?
                LIMIT 1
            ");
            $stmtSesEmp->execute([$_SESSION['usuario']['usuario_id']]);
            $empRow = $stmtSesEmp->fetch(PDO::FETCH_ASSOC);

            if ($empRow && strtoupper($empRow['estado'] ?? '') === 'ACTIVO') {
                $nombreReal = trim($empRow['nombre_razon_social'] ?? '') ?: $empRow['username'];
                $rolReal    = !empty($empRow['rol_nombre'])
                              ? $empRow['rol_nombre']
                              : (!empty($empRow['cargo']) ? $empRow['cargo'] : 'Empleado');

                $_SESSION['usuario']['nombre_completo'] = $nombreReal;
                $_SESSION['usuario']['rol']             = $rolReal;
                $_SESSION['usuario']['cargo']           = $empRow['cargo'] ?? '';
                $_SESSION['usuario']['codigo_empleado'] = $empRow['codigo_empleado'] ?? '';
                $_SESSION['usuario']['email']           = $empRow['email'] ?? '';
                $_SESSION['usuario']['avatar_initials'] = calcularInicialesEmpleado($nombreReal);
            } else {
                unset($_SESSION['usuario']);
            }
        } catch (Throwable $e) {}
    }

    // Si no ha iniciado sesión y está visitando una pantalla protegida por GET, redirigir al login
    $paginaActual = basename($_SERVER['PHP_SELF'] ?? '');
    $paginasPublicas = ['login.php', 'procesar_login.php', 'logout.php', 'test_conexion.php'];
    $esPeticionGetNormal = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') &&
                           !isset($_GET['documento']) &&
                           !isset($_GET['nombre']) &&
                           empty($_SERVER['HTTP_X_REQUESTED_WITH']);

    if (empty($_SESSION['usuario']['usuario_id']) && !in_array($paginaActual, $paginasPublicas, true) && $esPeticionGetNormal) {
        header("Location: /Liquor_Store/Login%20y%20Menu/login.php");
        exit;
    }
}

// Variables globales de sesión listas para usar en la barra superior (Topbar) de todas las pantallas
$sesionUsuario        = $_SESSION['usuario'] ?? [];
$sesionNombreCompleto = $sesionUsuario['nombre_completo'] ?? 'Usuario';
$sesionRol            = $sesionUsuario['rol'] ?? ($sesionUsuario['cargo'] ?? 'Empleado');
$sesionCargo          = $sesionUsuario['cargo'] ?? '';
$sesionCajaId         = intval($sesionUsuario['caja_id'] ?? 1);
$sesionAvatarInitials = $sesionUsuario['avatar_initials'] ?? calcularInicialesEmpleado($sesionNombreCompleto);
