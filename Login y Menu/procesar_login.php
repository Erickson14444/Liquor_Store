<?php
/**
 * Procesador de Inicio de Sesión por Empleado
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Valida credenciales contra seguridad.usuarios, identidad.personas, identidad.empleados y seguridad.roles
 */

require_once __DIR__ . '/../config/conexion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header("Location: login.php");
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$cajaId   = max(1, intval($_POST['caja_id'] ?? 1));

if (empty($username) || empty($password)) {
    header("Location: login.php?error=" . urlencode("Por favor ingresa tu usuario y contraseña."));
    exit;
}

try {
    if (!$pdo) {
        header("Location: login.php?error=" . urlencode("No hay conexión activa con el servidor PostgreSQL."));
        exit;
    }

    $sql = "
        SELECT u.usuario_id, u.username, u.password_hash, u.estado,
               p.persona_id, p.nombre_razon_social, p.nombre_comercial, p.email, p.documento_identidad,
               e.empleado_id, e.codigo_empleado, e.cargo, e.estado_laboral,
               r.rol_id, r.nombre AS rol_nombre
        FROM seguridad.usuarios u
        LEFT JOIN identidad.personas p ON u.persona_id = p.persona_id
        LEFT JOIN identidad.empleados e ON e.persona_id = p.persona_id
        LEFT JOIN seguridad.usuarios_roles ur ON u.usuario_id = ur.usuario_id
        LEFT JOIN seguridad.roles r ON ur.rol_id = r.rol_id
        WHERE LOWER(u.username) = LOWER(:identificador)
           OR LOWER(COALESCE(p.email, '')) = LOWER(:identificador)
           OR LOWER(COALESCE(e.codigo_empleado, '')) = LOWER(:identificador)
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':identificador' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header("Location: login.php?error=" . urlencode("El usuario o código de empleado '{$username}' no existe."));
        exit;
    }

    $estaActivo = (strtoupper($user['estado'] ?? '') === 'ACTIVO' || $user['estado'] === true || $user['estado'] === 't');
    if (!$estaActivo) {
        header("Location: login.php?error=" . urlencode("Tu cuenta de usuario se encuentra inactiva o suspendida."));
        exit;
    }

    if (!empty($user['estado_laboral']) && strtoupper($user['estado_laboral']) !== 'ACTIVO') {
        header("Location: login.php?error=" . urlencode("El expediente laboral de este empleado no se encuentra ACTIVO."));
        exit;
    }

    if (!password_verify($password, $user['password_hash'])) {
        try {
            $pdo->prepare("UPDATE seguridad.usuarios SET intentos_fallidos = COALESCE(intentos_fallidos, 0) + 1 WHERE usuario_id = ?")
                ->execute([$user['usuario_id']]);
        } catch (Throwable $e) {}

        header("Location: login.php?error=" . urlencode("Contraseña incorrecta para el usuario '{$user['username']}'."));
        exit;
    }

    @session_regenerate_id(true);

    $nombreCompleto = trim($user['nombre_razon_social'] ?? '') ?: $user['username'];
    $rolMostrar     = !empty($user['rol_nombre'])
                      ? $user['rol_nombre']
                      : (!empty($user['cargo']) ? $user['cargo'] : 'Empleado');
    $iniciales      = calcularInicialesEmpleado($nombreCompleto);
    $tabToken       = bin2hex(random_bytes(16));

    $_SESSION['usuario'] = [
        'id'              => $user['usuario_id'],
        'usuario_id'      => $user['usuario_id'],
        'persona_id'      => $user['persona_id'],
        'empleado_id'     => $user['empleado_id'] ?? null,
        'codigo_empleado' => $user['codigo_empleado'] ?? '',
        'username'        => $user['username'],
        'nombre_completo' => $nombreCompleto,
        'cargo'           => $user['cargo'] ?? '',
        'email'           => $user['email'] ?? '',
        'rol'             => $rolMostrar,
        'avatar_initials' => $iniciales,
        'caja_id'         => $cajaId,
        'tab_token'       => $tabToken
    ];

    try {
        $pdo->prepare("UPDATE seguridad.usuarios SET ultimo_acceso = CURRENT_TIMESTAMP, intentos_fallidos = 0 WHERE usuario_id = ?")
            ->execute([$user['usuario_id']]);
    } catch (Throwable $e) {}

    header("Location: menu.php?auth_init=1");
    exit;

} catch (Throwable $e) {
    header("Location: login.php?error=" . urlencode("Error al procesar acceso: " . $e->getMessage()));
    exit;
}
