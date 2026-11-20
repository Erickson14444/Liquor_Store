<?php
/**
 * Procesador de Usuarios del Sistema (Backend Controller)
 * House & Ice • Sistema Administrativo
 * Tablas: seguridad.usuarios, seguridad.usuarios_roles, seguridad.roles
 */

require_once __DIR__ . '/../../config/conexion.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || 
          (isset($_POST['format']) && $_POST['format'] === 'json');

function responder($status, $mensaje, $data = []) {
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['status' => $status, 'message' => $mensaje], $data));
        exit;
    } else {
        $tipo = ($status === 'success') ? 'exito' : 'error';
        header("Location: usuarios.php?msg=" . urlencode($mensaje) . "&tipo=" . $tipo);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder('error', 'Método HTTP no permitido.');
}

$accion = $_POST['accion'] ?? $_POST['action'] ?? 'crear';

try {
    if ($accion === 'crear' || $accion === 'editar') {
        $usuarioId = !empty($_POST['usuario_id']) ? trim($_POST['usuario_id']) : null;
        $personaId = !empty($_POST['persona_id']) ? trim($_POST['persona_id']) : null;
        $username = strtolower(trim($_POST['username'] ?? ''));
        $password = trim($_POST['password'] ?? '');
        $pinRaw = trim((string)($_POST['pin_autorizacion'] ?? ''));
        $rolesArr = isset($_POST['roles']) && is_array($_POST['roles'])
                    ? array_values(array_unique(array_filter(array_map('trim', $_POST['roles']), fn($v) => $v !== '')))
                    : [];
        if (empty($rolesArr) && !empty($_POST['rol_id'])) {
            $rolesArr = [trim($_POST['rol_id'])];
        }
        $esActivo = filter_var($_POST['es_activo'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $estado = $esActivo ? 'ACTIVO' : 'INACTIVO';

        if (empty($username)) {
            throw new Exception("El nombre de usuario es obligatorio.");
        }
        if (empty($rolesArr)) {
            throw new Exception("Debes seleccionar al menos un rol para el usuario.");
        }

        // Verificar si alguno de los roles seleccionados posee el permiso AUTORIZAR_CON_PIN
        $placeholders = implode(',', array_fill(0, count($rolesArr), '?'));
        $stmtChkPinPerm = $pdo->prepare("
            SELECT COUNT(*)
            FROM seguridad.roles_permisos rp
            JOIN seguridad.permisos perm ON rp.permiso_id = perm.permiso_id
            WHERE rp.rol_id IN ($placeholders)
              AND (UPPER(perm.clave_sistema) = 'AUTORIZAR_CON_PIN' OR UPPER(perm.clave_sistema) LIKE '%PIN%')
        ");
        $stmtChkPinPerm->execute($rolesArr);
        $algunRolTienePermisoPin = ((int)$stmtChkPinPerm->fetchColumn() > 0);

        $pinHashNuevo = null;
        $actualizarPin = false;

        if ($algunRolTienePermisoPin) {
            if ($pinRaw !== '') {
                if (!preg_match('/^\d{4,6}$/', $pinRaw)) {
                    throw new Exception("El PIN de autorización debe contener únicamente números y tener entre 4 y 6 dígitos.");
                }
                $pinHashNuevo = $pinRaw;
                $actualizarPin = true;
            } elseif ($accion === 'crear') {
                throw new Exception("El rol seleccionado posee el permiso de PIN: debes asignar un PIN numérico de 4 a 6 dígitos.");
            } else {
                // En edición, verificar si el usuario ya tenía un PIN guardado
                $stmtTienePin = $pdo->prepare("SELECT pin_autorizacion FROM seguridad.usuarios WHERE usuario_id = ?");
                $stmtTienePin->execute([$usuarioId]);
                $pinActualDb = $stmtTienePin->fetchColumn();
                if (empty($pinActualDb)) {
                    throw new Exception("El rol seleccionado requiere un PIN numérico de 4 a 6 dígitos.");
                }
            }
        } else {
            // Si ningún rol seleccionado tiene permiso de PIN, limpiar el PIN
            $pinHashNuevo = null;
            $actualizarPin = true;
        }

        $pdo->beginTransaction();

        if ($accion === 'crear') {
            if (empty($personaId)) {
                throw new Exception("Debe seleccionar un empleado para asociarle la cuenta de usuario.");
            }
            if (empty($password)) {
                throw new Exception("La contraseña inicial es obligatoria.");
            }

            // Verificar si el username ya está ocupado
            $stmtCheck = $pdo->prepare("SELECT count(*) FROM seguridad.usuarios WHERE username = ?");
            $stmtCheck->execute([$username]);
            if ($stmtCheck->fetchColumn() > 0) {
                throw new Exception("El nombre de usuario '{$username}' ya está en uso.");
            }

            $passwordHash = password_hash($password, PASSWORD_BCRYPT);

            $stmtIns = $pdo->prepare("
                INSERT INTO seguridad.usuarios 
                (persona_id, username, password_hash, pin_autorizacion, estado) 
                VALUES (?, ?, ?, ?, ?) 
                RETURNING usuario_id
            ");
            $stmtIns->execute([$personaId, $username, $passwordHash, $pinHashNuevo, $estado]);
            $usuarioId = $stmtIns->fetchColumn();

            // Asignar múltiples roles
            $stmtRol = $pdo->prepare("INSERT INTO seguridad.usuarios_roles (usuario_id, rol_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
            foreach ($rolesArr as $rId) {
                $stmtRol->execute([$usuarioId, $rId]);
            }

            $pdo->commit();
            responder('success', "Usuario '{$username}' creado exitosamente con " . count($rolesArr) . " rol(es).");
        }

        if ($accion === 'editar') {
            if (empty($usuarioId)) {
                throw new Exception("Identificador de usuario no proporcionado.");
            }

            if (!empty($password)) {
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                $stmtUp = $pdo->prepare("
                    UPDATE seguridad.usuarios 
                    SET username = ?, password_hash = ?, estado = ? 
                    WHERE usuario_id = ?
                ");
                $stmtUp->execute([$username, $passwordHash, $estado, $usuarioId]);
            } else {
                $stmtUp = $pdo->prepare("
                    UPDATE seguridad.usuarios 
                    SET username = ?, estado = ? 
                    WHERE usuario_id = ?
                ");
                $stmtUp->execute([$username, $estado, $usuarioId]);
            }

            if ($actualizarPin) {
                $stmtPin = $pdo->prepare("UPDATE seguridad.usuarios SET pin_autorizacion = ? WHERE usuario_id = ?");
                $stmtPin->execute([$pinHashNuevo, $usuarioId]);
            }

            // Actualizar múltiples roles
            $pdo->prepare("DELETE FROM seguridad.usuarios_roles WHERE usuario_id = ?")->execute([$usuarioId]);
            $stmtRol = $pdo->prepare("INSERT INTO seguridad.usuarios_roles (usuario_id, rol_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
            foreach ($rolesArr as $rId) {
                $stmtRol->execute([$usuarioId, $rId]);
            }

            $pdo->commit();
            responder('success', "Usuario '{$username}' actualizado exitosamente.");
        }
    }

    if ($accion === 'cambiar_estado' || $accion === 'toggle_estado') {
        $usuarioId = trim($_POST['usuario_id'] ?? $_POST['id'] ?? '');

        if (empty($usuarioId)) {
            throw new Exception("Identificador de usuario no proporcionado.");
        }

        $stmtCurr = $pdo->prepare("SELECT estado FROM seguridad.usuarios WHERE usuario_id = ?");
        $stmtCurr->execute([$usuarioId]);
        $curr = $stmtCurr->fetchColumn();
        $nuevo = ($curr === 'ACTIVO' || $curr === true || $curr === 't') ? 'INACTIVO' : 'ACTIVO';

        // Soft delete / cambio de estado
        $stmt = $pdo->prepare("UPDATE seguridad.usuarios SET estado = ? WHERE usuario_id = ?");
        $stmt->execute([$nuevo, $usuarioId]);
        responder('success', "Estado del usuario actualizado a {$nuevo}.");
    }

    throw new Exception("Acción no válida o no especificada.");
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder('error', $e->getMessage());
}

