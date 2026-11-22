<?php
/**
 * ============================================================================
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Archivo Central de Funciones, Sesión y Control de Permisos (RBAC)
 * Ubicación: c:\xampp\htdocs\Liquor_Store\config\funciones.php
 * (Separado de conexion.php para que las credenciales de BD de cada PC no cambien)
 * ============================================================================
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

if (PHP_SAPI !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.cookie_lifetime', '0');
        @ini_set('session.cookie_httponly', '1');
        @session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        session_start();
    }

    // Eliminar cualquier cookie persistente ajena a la sesión efímera actual
    if (!empty($_COOKIE)) {
        foreach ($_COOKIE as $cookieName => $cookieVal) {
            if ($cookieName !== session_name()) {
                @setcookie($cookieName, '', time() - 42000, '/');
                unset($_COOKIE[$cookieName]);
            }
        }
    }

    // Evitar caché de páginas protegidas en el navegador
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if (isset($pdo) && $pdo && !empty($_SESSION['usuario']['usuario_id'])) {
        try {
            $stmtSesEmp = $pdo->prepare("
                SELECT u.usuario_id, u.username, u.estado,
                       p.persona_id, p.nombre_razon_social, p.email,
                       e.empleado_id, e.codigo_empleado, e.cargo, e.estado_laboral,
                       COALESCE((
                           SELECT STRING_AGG(r.nombre, ', ' ORDER BY CASE WHEN UPPER(r.nombre) = 'ADMINISTRADOR' THEN 0 ELSE 1 END, r.nombre)
                           FROM seguridad.usuarios_roles ur
                           JOIN seguridad.roles r ON ur.rol_id = r.rol_id
                           WHERE ur.usuario_id = u.usuario_id AND r.es_activo = TRUE
                       ), '') AS roles_nombres,
                       COALESCE((
                           SELECT JSON_AGG(UPPER(TRIM(r.nombre)))
                           FROM seguridad.usuarios_roles ur
                           JOIN seguridad.roles r ON ur.rol_id = r.rol_id
                           WHERE ur.usuario_id = u.usuario_id AND r.es_activo = TRUE
                       ), '[]') AS roles_upper_json,
                       COALESCE((
                           SELECT JSON_AGG(DISTINCT UPPER(TRIM(perm.clave_sistema)))
                           FROM seguridad.usuarios_roles ur
                           JOIN seguridad.roles r ON ur.rol_id = r.rol_id
                           JOIN seguridad.roles_permisos rp ON rp.rol_id = r.rol_id
                           JOIN seguridad.permisos perm ON perm.permiso_id = rp.permiso_id
                           WHERE ur.usuario_id = u.usuario_id AND r.es_activo = TRUE
                       ), '[]') AS permisos_json
                FROM seguridad.usuarios u
                LEFT JOIN identidad.personas p ON u.persona_id = p.persona_id
                LEFT JOIN identidad.empleados e ON e.persona_id = p.persona_id
                WHERE u.usuario_id = ?
                LIMIT 1
            ");
            $stmtSesEmp->execute([$_SESSION['usuario']['usuario_id']]);
            $empRow = $stmtSesEmp->fetch(PDO::FETCH_ASSOC);

            if ($empRow && strtoupper($empRow['estado'] ?? '') === 'ACTIVO') {
                $nombreReal  = trim($empRow['nombre_razon_social'] ?? '') ?: $empRow['username'];
                $rolReal     = !empty($empRow['roles_nombres'])
                               ? $empRow['roles_nombres']
                               : (!empty($empRow['cargo']) ? $empRow['cargo'] : 'Empleado');
                $rolesUpper  = json_decode($empRow['roles_upper_json'] ?? '[]', true) ?: [];
                $permisosArr = json_decode($empRow['permisos_json'] ?? '[]', true) ?: [];
                $esAdmin     = in_array('ADMINISTRADOR', $rolesUpper, true) || strtolower($empRow['username'] ?? '') === 'admin';
                $esSupervisor = false;
                foreach ($rolesUpper as $rUp) {
                    if (strpos($rUp, 'SUPERVISOR') !== false) {
                        $esSupervisor = true;
                        break;
                    }
                }

                $_SESSION['usuario']['nombre_completo'] = $nombreReal;
                $_SESSION['usuario']['rol']             = $rolReal;
                $_SESSION['usuario']['roles_upper']     = $rolesUpper;
                $_SESSION['usuario']['permisos']        = $permisosArr;
                $_SESSION['usuario']['es_admin']        = $esAdmin;
                $_SESSION['usuario']['es_supervisor']   = $esSupervisor;
                $_SESSION['usuario']['cargo']           = $empRow['cargo'] ?? '';
                $_SESSION['usuario']['codigo_empleado'] = $empRow['codigo_empleado'] ?? '';
                $_SESSION['usuario']['email']           = $empRow['email'] ?? '';
                $_SESSION['usuario']['avatar_initials'] = calcularInicialesEmpleado($nombreReal);
            } else {
                unset($_SESSION['usuario']);
            }
        } catch (Throwable $e) {}
    }

    $paginaActual = basename($_SERVER['PHP_SELF'] ?? '');
    $paginasPublicas = ['login.php', 'procesar_login.php', 'logout.php', 'test_conexion.php'];
    $esPeticionGetNormal = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') &&
                           !isset($_GET['documento']) &&
                           !isset($_GET['nombre']) &&
                           !isset($_GET['codigo']) &&
                           !isset($_GET['sku']) &&
                           !isset($_GET['numero_lote']) &&
                           !isset($_GET['accion']) &&
                           empty($_SERVER['HTTP_X_REQUESTED_WITH']);

    if (empty($_SESSION['usuario']['usuario_id']) && !in_array($paginaActual, $paginasPublicas, true)) {
        if ($esPeticionGetNormal) {
            header("Location: /Liquor_Store/Login%20y%20Menu/login.php");
            exit;
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'ok'      => false,
                'message' => 'Sesión no iniciada. Por favor inicie sesión.',
                'mensaje' => 'Sesión no iniciada. Por favor inicie sesión.'
            ]);
            exit;
        }
    }

    // Guardia por pestaña (sessionStorage): obliga a iniciar sesión si se abre desde otra pestaña
    if (!empty($_SESSION['usuario']['usuario_id']) && !in_array($paginaActual, $paginasPublicas, true) && $esPeticionGetNormal) {
        if (empty($_SESSION['usuario']['tab_token'])) {
            $_SESSION['usuario']['tab_token'] = bin2hex(random_bytes(16));
        }
        $tabTokenJs = json_encode($_SESSION['usuario']['tab_token']);
        ob_start(function (string $buffer) use ($tabTokenJs): string {
            if (stripos($buffer, '<head') === false) {
                return $buffer;
            }
            $guardScript = "<script>(function(){try{var tk={$tabTokenJs};var sp=new URLSearchParams(window.location.search);if(sp.get('auth_init')==='1'&&tk){sessionStorage.setItem('hi_tab_token',tk);sp.delete('auth_init');var u=window.location.pathname+(sp.toString()?'?'+sp.toString():'')+window.location.hash;window.history.replaceState({},'',u);}else if(!tk||sessionStorage.getItem('hi_tab_token')!==tk){sessionStorage.clear();window.location.replace('/Liquor_Store/Login%20y%20Menu/logout.php');}}catch(e){}})();</script>";
            return preg_replace('/(<head\b[^>]*>)/i', '$1' . "\n  " . $guardScript, $buffer, 1);
        });
    }
}

$sesionUsuario        = $_SESSION['usuario'] ?? [];
$sesionNombreCompleto = $sesionUsuario['nombre_completo'] ?? 'Usuario';
$sesionRol            = $sesionUsuario['rol'] ?? ($sesionUsuario['cargo'] ?? 'Empleado');
$sesionCargo          = $sesionUsuario['cargo'] ?? '';
$sesionAvatarInitials = $sesionUsuario['avatar_initials'] ?? calcularInicialesEmpleado($sesionNombreCompleto);
$sesionEsAdmin        = !empty($sesionUsuario['es_admin']) || stripos($sesionRol, 'Administrador') !== false;
$sesionEsSupervisor   = !empty($sesionUsuario['es_supervisor']) || stripos($sesionRol, 'Supervisor') !== false;
$sesionPermisos       = is_array($sesionUsuario['permisos'] ?? null) ? $sesionUsuario['permisos'] : [];

// ============================================================================
// Detección dinámica de la Caja POS asignada al cajero/usuario logueado
// ============================================================================
$sesionCajaActiva   = null;
$sesionTieneCaja    = false;
$sesionCajaNombre   = 'Sin Caja Asignada';
$sesionCajaId       = null;

if (isset($pdo) && $pdo && !empty($sesionUsuario['usuario_id'])) {
    try {
        $stmtCjActiva = $pdo->prepare("
            SELECT sc.id, sc.caja_id, sc.cajero_id, sc.monto_inicial_efectivo, sc.estado,
                   cp.nombre_equipo, cp.ubicacion
            FROM ventas.sesiones_caja sc
            JOIN ventas.cajas_pos cp ON cp.id = sc.caja_id
            WHERE sc.cajero_id = ?
              AND sc.estado IN ('ABIERTA', 'EN_CUADRE')
            ORDER BY sc.fecha_apertura DESC
            LIMIT 1
        ");
        $stmtCjActiva->execute([$sesionUsuario['usuario_id']]);
        $rowCjActiva = $stmtCjActiva->fetch(PDO::FETCH_ASSOC);

        if ($rowCjActiva) {
            $sesionCajaActiva = $rowCjActiva;
            $sesionTieneCaja  = true;
            $sesionCajaId     = intval($rowCjActiva['caja_id']);
            $sesionCajaNombre = $rowCjActiva['nombre_equipo'];
        }
    } catch (Throwable $e) {
        $sesionTieneCaja = false;
    }
}

// Fallback de retrocompatibilidad si $sesionCajaId se usa en consultas numéricas
$cajaId = $sesionCajaId ?: 1;

if (!function_exists('renderizarPillCajaTopbar')) {
    function renderizarPillCajaTopbar(): string {
        global $sesionTieneCaja, $sesionCajaNombre, $sesionCajaActiva;
        if (!empty($sesionTieneCaja)) {
            $nombre = htmlspecialchars($sesionCajaNombre);
            $title = !empty($sesionCajaActiva['id']) ? " title=\"Sesión #{$sesionCajaActiva['id']} activa\"" : ' title="Caja asignada activa"';
            return '<span class="user-caja-pill open"' . $title . '><span class="pill-dot green"></span>' . $nombre . '</span>';
        } else {
            return '<span class="user-caja-pill" style="background: #fdf5ea; color: #b25e09; border: 1px solid #eccca2;" title="No tiene turno abierto en ninguna terminal física"><span class="pill-dot" style="background: #b25e09;"></span>Sin Caja Asignada</span>';
        }
    }
}

if (!function_exists('usuarioTienePermiso')) {
    function usuarioTienePermiso(string $clave): bool {
        global $sesionEsAdmin, $sesionPermisos;
        if ($sesionEsAdmin) {
            return true;
        }
        return in_array(strtoupper(trim($clave)), $sesionPermisos, true);
    }
}

if (!function_exists('usuarioEsAdministrador')) {
    function usuarioEsAdministrador(): bool {
        global $sesionEsAdmin;
        return (bool)$sesionEsAdmin;
    }
}

// Permisos exclusivos del Administrador: Métodos de Pago, Monedas, Impuestos y Retenciones Fiscales
if (!function_exists('usuarioPuedeAccederMetodosPago')) {
    function usuarioPuedeAccederMetodosPago(): bool {
        return usuarioEsAdministrador() || usuarioTienePermiso('GESTIONAR_METODOS_PAGO');
    }
}

if (!function_exists('usuarioPuedeAccederMonedas')) {
    function usuarioPuedeAccederMonedas(): bool {
        return usuarioEsAdministrador() || usuarioTienePermiso('GESTIONAR_MONEDAS');
    }
}

if (!function_exists('usuarioPuedeAccederImpuestos')) {
    function usuarioPuedeAccederImpuestos(): bool {
        return usuarioEsAdministrador() || usuarioTienePermiso('GESTIONAR_IMPUESTOS');
    }
}

if (!function_exists('usuarioPuedeAccederRetenciones')) {
    function usuarioPuedeAccederRetenciones(): bool {
        return usuarioEsAdministrador() || usuarioTienePermiso('GESTIONAR_RETENCIONES');
    }
}

// Secuencias Fiscales DGII: Administrador y Supervisor
if (!function_exists('usuarioPuedeAccederSecuenciasFiscales')) {
    function usuarioPuedeAccederSecuenciasFiscales(): bool {
        global $sesionEsSupervisor;
        return usuarioEsAdministrador() || $sesionEsSupervisor || usuarioTienePermiso('GESTIONAR_SECUENCIAS_FISCALES');
    }
}

// Control de Caja: Exclusivo para Administrador y Supervisor (no cajero)
if (!function_exists('usuarioPuedeAccederControlCaja')) {
    function usuarioPuedeAccederControlCaja(): bool {
        global $sesionEsAdmin, $sesionEsSupervisor;
        return $sesionEsAdmin || $sesionEsSupervisor || usuarioTienePermiso('GESTIONAR_CONTROL_CAJA') || usuarioTienePermiso('SUPERVISOR_CAJA');
    }
}

// Compras y Recepción: Permisos RBAC
if (!function_exists('usuarioPuedeCrearOrdenCompra')) {
    function usuarioPuedeCrearOrdenCompra(): bool {
        global $sesionEsAdmin;
        return (bool)$sesionEsAdmin || usuarioTienePermiso('CREAR_ORDEN_COMPRA');
    }
}

if (!function_exists('usuarioPuedeRegistrarRecepcion')) {
    function usuarioPuedeRegistrarRecepcion(): bool {
        global $sesionEsAdmin;
        return (bool)$sesionEsAdmin || usuarioTienePermiso('REGISTRAR_RECEPCION_COMPRA');
    }
}

if (!function_exists('usuarioPuedeAccederCompras')) {
    function usuarioPuedeAccederCompras(): bool {
        global $sesionEsAdmin;
        return (bool)$sesionEsAdmin || usuarioTienePermiso('CREAR_ORDEN_COMPRA') || usuarioTienePermiso('REGISTRAR_RECEPCION_COMPRA') || usuarioTienePermiso('GESTIONAR_PROVEEDORES') || usuarioTienePermiso('GESTIONAR_CXP');
    }
}
