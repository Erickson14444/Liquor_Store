<?php
/**
 * ============================================================================
 * House & Ice • Sistema Administrativo
 * Procesador de Roles y Permisos (Backend Controller)
 * Tablas: seguridad.roles, seguridad.permisos, seguridad.roles_permisos
 * Actualizado con el nuevo esquema: clave_sistema, entidad, accion
 * ============================================================================
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
        $tab = $_POST['tab'] ?? 'roles';
        header("Location: roles.php?msg=" . urlencode($mensaje) . "&tipo=" . $tipo . "&tab=" . urlencode($tab));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder('error', 'Método HTTP no permitido.');
}

$accion = $_POST['accion'] ?? $_POST['action'] ?? 'crear';

try {
    if (!$pdo) {
        throw new Exception("No hay conexión con la base de datos.");
    }

    // ==========================================
    // 1. GESTIÓN DE ROLES
    // ==========================================
    if ($accion === 'crear' || $accion === 'editar') {
        $rolId = !empty($_POST['rol_id']) ? trim($_POST['rol_id']) : null;
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $esActivo = filter_var($_POST['es_activo'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $permisosSeleccionados = $_POST['permisos'] ?? [];

        if (empty($nombre)) {
            throw new Exception("El nombre del rol es obligatorio.");
        }

        // Validar unicidad del nombre
        if ($accion === 'crear') {
            $stmtCheck = $pdo->prepare("SELECT rol_id FROM seguridad.roles WHERE LOWER(nombre) = LOWER(?)");
            $stmtCheck->execute([$nombre]);
            if ($stmtCheck->fetch()) {
                throw new Exception("Ya existe un rol con el nombre '{$nombre}'.");
            }
        } else {
            $stmtCheck = $pdo->prepare("SELECT rol_id FROM seguridad.roles WHERE LOWER(nombre) = LOWER(?) AND rol_id <> ?");
            $stmtCheck->execute([$nombre, $rolId]);
            if ($stmtCheck->fetch()) {
                throw new Exception("Ya existe otro rol con el nombre '{$nombre}'.");
            }
        }

        $pdo->beginTransaction();

        if ($accion === 'crear') {
            $stmt = $pdo->prepare("INSERT INTO seguridad.roles (nombre, descripcion, es_activo) VALUES (?, ?, ?) RETURNING rol_id");
            $stmt->execute([$nombre, $descripcion, $esActivo ? 'true' : 'false']);
            $rolId = $stmt->fetchColumn();

            // Asignar permisos seleccionados
            if (!empty($permisosSeleccionados) && is_array($permisosSeleccionados)) {
                $stmtPerm = $pdo->prepare("INSERT INTO seguridad.roles_permisos (rol_id, permiso_id) VALUES (?, ?)");
                foreach ($permisosSeleccionados as $pid) {
                    if (!empty($pid)) {
                        $stmtPerm->execute([$rolId, trim($pid)]);
                    }
                }
            }

            $pdo->commit();
            responder('success', "Rol '{$nombre}' creado exitosamente.");
        }

        if ($accion === 'editar') {
            if (empty($rolId)) {
                throw new Exception("Identificador de rol no proporcionado.");
            }

            $stmt = $pdo->prepare("UPDATE seguridad.roles SET nombre = ?, descripcion = ?, es_activo = ? WHERE rol_id = ?");
            $stmt->execute([$nombre, $descripcion, $esActivo ? 'true' : 'false', $rolId]);

            // Reemplazar permisos
            $pdo->prepare("DELETE FROM seguridad.roles_permisos WHERE rol_id = ?")->execute([$rolId]);
            if (!empty($permisosSeleccionados) && is_array($permisosSeleccionados)) {
                $stmtPerm = $pdo->prepare("INSERT INTO seguridad.roles_permisos (rol_id, permiso_id) VALUES (?, ?)");
                foreach ($permisosSeleccionados as $pid) {
                    if (!empty($pid)) {
                        $stmtPerm->execute([$rolId, trim($pid)]);
                    }
                }
            }

            $pdo->commit();
            responder('success', "Rol '{$nombre}' actualizado exitosamente.");
        }
    }

    if ($accion === 'cambiar_estado' || $accion === 'toggle_estado') {
        $rolId = trim($_POST['rol_id'] ?? $_POST['id'] ?? '');

        if (empty($rolId)) {
            throw new Exception("Identificador de rol no proporcionado.");
        }

        $stmtCurr = $pdo->prepare("SELECT es_activo, nombre FROM seguridad.roles WHERE rol_id = ?");
        $stmtCurr->execute([$rolId]);
        $row = $stmtCurr->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception("El rol no existe.");
        }

        if ($row['nombre'] === 'Administrador') {
            throw new Exception("El rol Administrador no puede ser desactivado.");
        }

        $curr = $row['es_activo'];
        $nuevo = ($curr === true || $curr === 't') ? 'false' : 'true';

        $stmt = $pdo->prepare("UPDATE seguridad.roles SET es_activo = ? WHERE rol_id = ?");
        $stmt->execute([$nuevo, $rolId]);
        responder('success', "Estado del rol '{$row['nombre']}' actualizado a " . ($nuevo === 'true' ? 'ACTIVO' : 'INACTIVO') . ".");
    }

    // ==========================================
    // 2. GESTIÓN DE PERMISOS (Esquema actualizado)
    // ==========================================
    if ($accion === 'crear_permiso' || $accion === 'editar_permiso') {
        $permisoId = !empty($_POST['permiso_id']) ? trim($_POST['permiso_id']) : null;
        $claveSistema = strtoupper(trim($_POST['clave_sistema'] ?? $_POST['codigo'] ?? ''));
        $modulo = strtolower(trim($_POST['modulo'] ?? 'general'));
        $entidad = strtoupper(trim($_POST['entidad'] ?? ''));
        $accionPerm = strtoupper(trim($_POST['accion_permiso'] ?? ''));
        $descripcion = trim($_POST['descripcion'] ?? '');

        // Formatear clave_sistema (solo mayúsculas, números y guiones bajos)
        $claveSistema = preg_replace('/[^A-Z0-9_]/', '_', $claveSistema);
        $claveSistema = preg_replace('/_+/', '_', $claveSistema);

        if (empty($claveSistema)) {
            throw new Exception("La clave del sistema para el permiso es obligatoria (ej. VENTAS_FACTURAR).");
        }
        if (empty($modulo)) {
            throw new Exception("El módulo o categoría del permiso es obligatorio.");
        }

        // Si entidad o acción están vacías, deducirlas de clave_sistema
        if (empty($entidad)) {
            $partes = explode('_', $claveSistema);
            $entidad = !empty($partes[0]) ? $partes[0] : strtoupper($modulo);
        }
        if (empty($accionPerm)) {
            $partes = explode('_', $claveSistema);
            $accionPerm = (count($partes) > 1) ? $partes[1] : 'ACCESO';
        }

        if (empty($descripcion)) {
            $descripcion = "Permiso {$claveSistema} en módulo {$modulo}";
        }

        if ($accion === 'crear_permiso') {
            // Verificar duplicados
            $stmtCheck = $pdo->prepare("SELECT permiso_id FROM seguridad.permisos WHERE UPPER(clave_sistema) = ?");
            $stmtCheck->execute([$claveSistema]);
            if ($stmtCheck->fetch()) {
                throw new Exception("Ya existe un permiso registrado con la clave '{$claveSistema}'.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO seguridad.permisos (clave_sistema, modulo, descripcion, entidad, accion) 
                VALUES (?, ?, ?, ?, ?) 
                RETURNING permiso_id
            ");
            $stmt->execute([$claveSistema, $modulo, $descripcion, $entidad, $accionPerm]);
            $permisoId = $stmt->fetchColumn();

            responder('success', "Permiso '{$claveSistema}' creado exitosamente.");
        }

        if ($accion === 'editar_permiso') {
            if (empty($permisoId)) {
                throw new Exception("Identificador del permiso no proporcionado.");
            }

            // Verificar duplicados excluyendo el actual
            $stmtCheck = $pdo->prepare("SELECT permiso_id FROM seguridad.permisos WHERE UPPER(clave_sistema) = ? AND permiso_id <> ?");
            $stmtCheck->execute([$claveSistema, $permisoId]);
            if ($stmtCheck->fetch()) {
                throw new Exception("Ya existe otro permiso con la clave '{$claveSistema}'.");
            }

            $stmt = $pdo->prepare("
                UPDATE seguridad.permisos 
                SET clave_sistema = ?, modulo = ?, descripcion = ?, entidad = ?, accion = ? 
                WHERE permiso_id = ?
            ");
            $stmt->execute([$claveSistema, $modulo, $descripcion, $entidad, $accionPerm, $permisoId]);

            responder('success', "Permiso '{$claveSistema}' actualizado exitosamente.");
        }
    }

    if ($accion === 'eliminar_permiso') {
        $permisoId = trim($_POST['permiso_id'] ?? $_POST['id'] ?? '');

        if (empty($permisoId)) {
            throw new Exception("Identificador de permiso no proporcionado.");
        }

        // Consultar información del permiso
        $stmtPerm = $pdo->prepare("SELECT clave_sistema FROM seguridad.permisos WHERE permiso_id = ?");
        $stmtPerm->execute([$permisoId]);
        $clavePerm = $stmtPerm->fetchColumn();

        if (!$clavePerm) {
            throw new Exception("El permiso especificado no existe.");
        }

        // Validar si está en uso por roles
        $stmtUso = $pdo->prepare("SELECT count(*) FROM seguridad.roles_permisos WHERE permiso_id = ?");
        $stmtUso->execute([$permisoId]);
        $totalRoles = intval($stmtUso->fetchColumn());

        if ($totalRoles > 0) {
            throw new Exception("No se puede eliminar el permiso '{$clavePerm}' porque está asignado a {$totalRoles} rol(es). Primero retíralo de dichos roles.");
        }

        $stmtDel = $pdo->prepare("DELETE FROM seguridad.permisos WHERE permiso_id = ?");
        $stmtDel->execute([$permisoId]);

        responder('success', "Permiso '{$clavePerm}' eliminado correctamente.");
    }

    throw new Exception("Acción no válida o no especificada.");
} catch (Exception $e) {
    if (isset($pdo) && $pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder('error', $e->getMessage());
}
