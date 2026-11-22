<?php
/**
 * ============================================================================
 * House & Ice • Helper Centralizado de Seguridad, PIN de Supervisor y Auditoría
 * Ubicación: config/auditoria_helper.php
 * Tablas: seguridad.usuarios, seguridad.usuarios_roles, seguridad.roles,
 *         seguridad.roles_permisos, seguridad.permisos, seguridad.auditoria
 * ============================================================================
 */

if (!function_exists('requerirSesionActiva')) {
    /**
     * Garantiza que exista un usuario autenticado en $_SESSION['usuario'].
     * Devuelve el UUID del usuario en sesión.
     */
    function requerirSesionActiva(bool $esAjax = false, string $loginUrl = '../../Login y Menu/login.php'): string
    {
        $usuarioId = $_SESSION['usuario']['usuario_id'] ?? null;
        if (!empty($usuarioId)) {
            return (string) $usuarioId;
        }

        if ($esAjax) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'error',
                'message' => 'Tu sesión ha expirado. Inicia sesión nuevamente para continuar.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header("Location: {$loginUrl}");
        exit;
    }
}

if (!function_exists('obtenerColumnasTabla')) {
    /**
     * Devuelve un mapa asociativo ['columna' => true] con las columnas reales
     * de una tabla en PostgreSQL (con caché en memoria por petición).
     */
    function obtenerColumnasTabla(PDO $pdo, string $esquema, string $tabla): array
    {
        static $cache = [];
        $key = strtolower("{$esquema}.{$tabla}");
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $stmt = $pdo->prepare("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ?
        ");
        $stmt->execute([$esquema, $tabla]);
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $mapa = [];
        foreach ($cols as $c) {
            $mapa[strtolower((string) $c)] = true;
        }
        $cache[$key] = $mapa;
        return $mapa;
    }
}

if (!function_exists('obtenerSupervisoresAutorizados')) {
    /**
     * Lista los usuarios ACTIVOS que poseen PIN configurado y al menos un rol
     * activo con permiso AUTORIZAR_CON_PIN (o clave relacionada con PIN).
     */
    function obtenerSupervisoresAutorizados(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT u.usuario_id,
                   u.username,
                   COALESCE(NULLIF(TRIM(p.nombre_razon_social), ''), u.username) AS nombre_completo,
                   COALESCE((
                       SELECT STRING_AGG(DISTINCT r2.nombre, ' / ' ORDER BY r2.nombre)
                       FROM seguridad.usuarios_roles ur2
                       JOIN seguridad.roles r2 ON ur2.rol_id = r2.rol_id
                       WHERE ur2.usuario_id = u.usuario_id AND r2.es_activo = TRUE
                   ), 'Supervisor') AS roles_nombres
            FROM seguridad.usuarios u
            JOIN identidad.personas p ON p.persona_id = u.persona_id
            WHERE u.estado = 'ACTIVO'
              AND u.pin_autorizacion IS NOT NULL
              AND TRIM(u.pin_autorizacion) <> ''
              AND EXISTS (
                  SELECT 1
                  FROM seguridad.usuarios_roles ur
                  JOIN seguridad.roles r ON ur.rol_id = r.rol_id
                  JOIN seguridad.roles_permisos rp ON rp.rol_id = r.rol_id
                  JOIN seguridad.permisos perm ON perm.permiso_id = rp.permiso_id
                  WHERE ur.usuario_id = u.usuario_id
                    AND r.es_activo = TRUE
                    AND (
                        UPPER(perm.clave_sistema) = 'AUTORIZAR_CON_PIN' 
                        OR UPPER(perm.clave_sistema) LIKE '%PIN%'
                        OR UPPER(r.nombre) LIKE '%SUPERVISOR%'
                        OR UPPER(r.nombre) LIKE '%ADMIN%'
                    )
              )
            ORDER BY nombre_completo ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('validarPinSupervisor')) {
    /**
     * Valida las credenciales rápidas (Supervisor + PIN) contra seguridad.usuarios.
     * Soporta hash BCrypt/Argon (password_verify) y compatibilidad segura con
     * registros previos en texto plano mientras se migran.
     *
     * @throws Exception si el supervisor no existe, no tiene permiso o el PIN no coincide.
     */
    function validarPinSupervisor(PDO $pdo, string $supervisorUsuarioId, string $pinIngresado): array
    {
        $supervisorUsuarioId = trim($supervisorUsuarioId);
        $pinIngresado = trim($pinIngresado);

        if ($supervisorUsuarioId === '') {
            throw new Exception("Debes indicar el usuario del supervisor que autoriza la operación.");
        }
        if (!preg_match('/^\d{4,6}$/', $pinIngresado)) {
            throw new Exception("El PIN de autorización debe contener entre 4 y 6 dígitos numéricos.");
        }

        $stmt = $pdo->prepare("
            SELECT u.usuario_id,
                   u.username,
                   u.estado,
                   u.pin_autorizacion,
                   COALESCE(NULLIF(TRIM(p.nombre_razon_social), ''), u.username) AS nombre_completo,
                   EXISTS (
                       SELECT 1
                       FROM seguridad.usuarios_roles ur
                       JOIN seguridad.roles r ON ur.rol_id = r.rol_id
                       JOIN seguridad.roles_permisos rp ON rp.rol_id = r.rol_id
                       JOIN seguridad.permisos perm ON perm.permiso_id = rp.permiso_id
                       WHERE ur.usuario_id = u.usuario_id
                         AND r.es_activo = TRUE
                         AND (
                             UPPER(perm.clave_sistema) = 'AUTORIZAR_CON_PIN' 
                             OR UPPER(perm.clave_sistema) LIKE '%PIN%'
                             OR UPPER(r.nombre) LIKE '%SUPERVISOR%'
                             OR UPPER(r.nombre) LIKE '%ADMIN%'
                         )
                   ) AS tiene_permiso_pin
            FROM seguridad.usuarios u
            JOIN identidad.personas p ON p.persona_id = u.persona_id
            LEFT JOIN identidad.empleados e ON e.persona_id = p.persona_id
            WHERE u.usuario_id::text = :sup
               OR LOWER(u.username) = LOWER(:sup)
               OR LOWER(COALESCE(e.codigo_empleado, '')) = LOWER(:sup)
            LIMIT 1
        ");
        $stmt->execute([':sup' => $supervisorUsuarioId]);
        $sup = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sup) {
            throw new Exception("El supervisor seleccionado no existe en el sistema.");
        }
        if (strtoupper((string) $sup['estado']) !== 'ACTIVO') {
            throw new Exception("La cuenta del supervisor '{$sup['username']}' se encuentra inactiva.");
        }

        $tienePermiso = ($sup['tiene_permiso_pin'] === true || $sup['tiene_permiso_pin'] === 't' || $sup['tiene_permiso_pin'] === 1);
        if (!$tienePermiso) {
            throw new Exception("El usuario '{$sup['username']}' no posee un rol activo con permiso AUTORIZAR_CON_PIN.");
        }

        $pinGuardado = trim((string) ($sup['pin_autorizacion'] ?? ''));
        if ($pinGuardado === '') {
            throw new Exception("El supervisor '{$sup['username']}' no tiene un PIN de autorización configurado.");
        }

        $infoHash = password_get_info($pinGuardado);
        $esValido = !empty($infoHash['algo'])
            ? password_verify($pinIngresado, $pinGuardado)
            : hash_equals($pinGuardado, $pinIngresado);

        if (!$esValido) {
            throw new Exception("PIN de autorización incorrecto para el supervisor '{$sup['nombre_completo']}'.");
        }

        return [
            'usuario_id' => $sup['usuario_id'],
            'username' => $sup['username'],
            'nombre_completo' => $sup['nombre_completo'],
        ];
    }
}

if (!function_exists('registrarAuditoria')) {
    /**
     * Inserta un registro forense en seguridad.auditoria (datos JSONB).
     * Debe llamarse dentro de la misma transacción PDO de la operación principal.
     */
    function registrarAuditoria(
        PDO $pdo,
        ?string $usuarioId,
        ?string $supervisorId,
        string $accion,
        string $tablaAfectada,
        ?array $datosAnteriores = null,
        ?array $datosNuevos = null
    ): void {
        $jsonAnt = ($datosAnteriores !== null)
            ? json_encode($datosAnteriores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        $jsonNue = ($datosNuevos !== null)
            ? json_encode($datosNuevos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $stmt = $pdo->prepare("
            INSERT INTO seguridad.auditoria
                (usuario_id, supervisor_id, accion, tabla_afectada, datos_anteriores, datos_nuevos)
            VALUES
                (?, ?, ?, ?, ?::jsonb, ?::jsonb)
        ");
        $stmt->execute([
            $usuarioId ?: null,
            $supervisorId ?: null,
            strtoupper(trim($accion)),
            trim($tablaAfectada),
            $jsonAnt,
            $jsonNue,
        ]);
    }
}