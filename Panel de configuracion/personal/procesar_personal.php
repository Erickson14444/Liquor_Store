<?php

// Búsqueda GET en vivo de Terceros en identidad.personas (Supertipo / Subtipo)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && (isset($_GET['documento']) || isset($_GET['nombre']))) {
    require_once __DIR__ . '/../../config/conexion.php';
    header('Content-Type: application/json; charset=utf-8');
    $documento = trim($_GET['documento'] ?? '');
    $nombre    = trim($_GET['nombre'] ?? '');

    if ((empty($documento) && empty($nombre)) || !$pdo) {
        echo json_encode(['encontrado' => false]);
        exit;
    }

    try {
        $persona = null;
        if (!empty($documento)) {
            $docLimpio = str_replace(['-', ' ', '.'], '', $documento);
            $stmt = $pdo->prepare("
                SELECT p.persona_id, p.tipo_documento, p.documento_identidad,
                       p.documento_identidad AS numero_documento,
                       p.nombre_razon_social, p.nombre_razon_social AS primer_nombre,
                       p.nombre_comercial, p.nombre_comercial AS segundo_nombre,
                       p.fecha_nacimiento, p.email, p.telefono_principal,
                       p.telefono_principal AS telefono, p.tipo_persona, p.estado,
                       (SELECT c.codigo_cliente FROM identidad.clientes c WHERE c.persona_id = p.persona_id LIMIT 1) AS codigo_cliente,
                       (SELECT e.codigo_empleado FROM identidad.empleados e WHERE e.persona_id = p.persona_id LIMIT 1) AS codigo_empleado,
                       (SELECT pr.codigo_proveedor FROM identidad.proveedores pr WHERE pr.persona_id = p.persona_id LIMIT 1) AS codigo_proveedor
                FROM identidad.personas p
                WHERE replace(replace(p.documento_identidad, '-', ''), ' ', '') = ?
                   OR UPPER(p.documento_identidad) = UPPER(?)
                LIMIT 1
            ");
            $stmt->execute([$docLimpio, $documento]);
            $persona = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif (!empty($nombre) && mb_strlen($nombre) >= 4) {
            $stmt = $pdo->prepare("
                SELECT p.persona_id, p.tipo_documento, p.documento_identidad,
                       p.documento_identidad AS numero_documento,
                       p.nombre_razon_social, p.nombre_razon_social AS primer_nombre,
                       p.nombre_comercial, p.nombre_comercial AS segundo_nombre,
                       p.fecha_nacimiento, p.email, p.telefono_principal,
                       p.telefono_principal AS telefono, p.tipo_persona, p.estado,
                       (SELECT c.codigo_cliente FROM identidad.clientes c WHERE c.persona_id = p.persona_id LIMIT 1) AS codigo_cliente,
                       (SELECT e.codigo_empleado FROM identidad.empleados e WHERE e.persona_id = p.persona_id LIMIT 1) AS codigo_empleado,
                       (SELECT pr.codigo_proveedor FROM identidad.proveedores pr WHERE pr.persona_id = p.persona_id LIMIT 1) AS codigo_proveedor
                FROM identidad.personas p
                WHERE UPPER(TRIM(p.nombre_razon_social)) = UPPER(TRIM(?))
                   OR UPPER(p.nombre_razon_social) LIKE UPPER(?)
                ORDER BY CASE WHEN UPPER(TRIM(p.nombre_razon_social)) = UPPER(TRIM(?)) THEN 0 ELSE 1 END
                LIMIT 1
            ");
            $stmt->execute([$nombre, '%' . $nombre . '%', $nombre]);
            $persona = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($persona) {
            $rolesPrevios = [];
            if (!empty($persona['codigo_cliente']))   $rolesPrevios[] = 'Cliente (' . $persona['codigo_cliente'] . ')';
            if (!empty($persona['codigo_empleado']))  $rolesPrevios[] = 'Empleado (' . $persona['codigo_empleado'] . ')';
            if (!empty($persona['codigo_proveedor'])) $rolesPrevios[] = 'Proveedor (' . $persona['codigo_proveedor'] . ')';
            $persona['roles_previos'] = $rolesPrevios;
            $persona['resumen_roles'] = !empty($rolesPrevios) ? implode(', ', $rolesPrevios) : 'Tercero registrado';
            echo json_encode(['encontrado' => true, 'persona' => $persona]);
        } else {
            echo json_encode(['encontrado' => false]);
        }
    } catch (Throwable $e) {
        echo json_encode(['encontrado' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/**
 * ============================================================================
 * Controlador de Personal & Repartidores (Backend Processor)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: identidad.personas, identidad.empleados, identidad.repartidores
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || 
          (isset($_POST['format']) && $_POST['format'] === 'json');

function responder($status, $mensaje, $data = [], $tab = 'personal') {
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['status' => $status, 'message' => $mensaje, 'tab' => $tab], $data));
        exit;
    } else {
        $tipo = ($status === 'success') ? 'exito' : 'error';
        header("Location: personal.php?tab=" . urlencode($tab) . "&msg=" . urlencode($mensaje) . "&tipo=" . $tipo);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder('error', 'Método HTTP no permitido.');
}

$accion = trim($_POST['accion'] ?? $_POST['action'] ?? '');
$tab    = trim($_POST['tab'] ?? 'personal');

if (!$pdo) {
    responder('error', 'No hay conexión activa con la base de datos PostgreSQL.', [], $tab);
}

try {
    // ========================================================================
    // ACCIÓN 1: CREAR O EDITAR EMPLEADO (PERSONAL)
    // ========================================================================
    if ($accion === 'crear_empleado' || $accion === 'editar_empleado') {
        $empleadoId = !empty($_POST['empleado_id']) ? trim($_POST['empleado_id']) : null;
        $personaId  = !empty($_POST['persona_id']) ? trim($_POST['persona_id']) : null;

        $codigoEmpleado = strtoupper(trim($_POST['codigo_empleado'] ?? ''));
        $tipoDocumento  = trim($_POST['tipo_documento'] ?? 'CEDULA');
        $documento      = trim($_POST['documento_identidad'] ?? '');
        $nombreRazon    = trim($_POST['nombre_razon_social'] ?? '');
        $cargo          = trim($_POST['cargo'] ?? 'Cajero POS');
        $fechaIngreso   = !empty($_POST['fecha_ingreso']) ? trim($_POST['fecha_ingreso']) : date('Y-m-d');
        $telefono       = trim($_POST['telefono_principal'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $estadoLaboral  = trim($_POST['estado_laboral'] ?? 'ACTIVO');

        // Validaciones requeridas
        if (empty($codigoEmpleado) || empty($documento) || empty($nombreRazon) || empty($cargo)) {
            throw new Exception("El código de empleado, documento, nombre completo y cargo son obligatorios.");
        }

        // Validación de formato del código (Ej: EMP-001)
        if (!preg_match('/^[A-Z0-9_-]{3,20}$/i', $codigoEmpleado)) {
            throw new Exception("El código '{$codigoEmpleado}' no es válido. Usa letras, números y guiones (ej. EMP-001).");
        }

        // Validación de formato de documento de identidad
        $docLimpio = preg_replace('/[^0-9A-Za-z]/', '', $documento);
        if ($tipoDocumento === 'CEDULA' && strlen($docLimpio) !== 11) {
            throw new Exception("La cédula debe contener exactamente 11 dígitos numéricos.");
        }
        if ($tipoDocumento === 'RNC' && (strlen($docLimpio) !== 9 && strlen($docLimpio) !== 11)) {
            throw new Exception("El RNC debe contener 9 u 11 dígitos numéricos.");
        }

        // Validación de email
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("El correo electrónico '{$email}' no tiene un formato válido.");
        }

        // Validación de estado laboral permitido por la BD
        $estadosPermitidos = ['ACTIVO', 'VACACIONES', 'SUSPENDIDO', 'INACTIVO'];
        if (!in_array($estadoLaboral, $estadosPermitidos, true)) {
            $estadoLaboral = 'ACTIVO';
        }

        $pdo->beginTransaction();

        // 1. Validar unicidad del código de empleado
        if ($accion === 'crear_empleado') {
            $stmtChkCod = $pdo->prepare("SELECT 1 FROM identidad.empleados WHERE UPPER(codigo_empleado) = ?");
            $stmtChkCod->execute([$codigoEmpleado]);
            if ($stmtChkCod->fetchColumn()) {
                throw new Exception("El código de empleado '{$codigoEmpleado}' ya está registrado. Elige otro.");
            }
        } else {
            $stmtChkCod = $pdo->prepare("SELECT 1 FROM identidad.empleados WHERE UPPER(codigo_empleado) = ? AND empleado_id != ?");
            $stmtChkCod->execute([$codigoEmpleado, $empleadoId]);
            if ($stmtChkCod->fetchColumn()) {
                throw new Exception("El código '{$codigoEmpleado}' ya está asignado a otro empleado.");
            }
        }

        // 2. Gestionar la persona (Tercero maestro - Supertipo / Subtipo)
        if ($accion === 'crear_empleado') {
            $existingPersonaId = null;

            if (!empty($personaId)) {
                $stmtById = $pdo->prepare("SELECT persona_id FROM identidad.personas WHERE persona_id = ?");
                $stmtById->execute([$personaId]);
                $existingPersonaId = $stmtById->fetchColumn();
            }

            if (!$existingPersonaId) {
                // Verificar si el documento o el nombre exacto ya existe en identidad.personas
                $stmtChkPer = $pdo->prepare("
                    SELECT persona_id FROM identidad.personas 
                    WHERE replace(replace(documento_identidad, '-', ''), ' ', '') = ?
                       OR UPPER(documento_identidad) = UPPER(?)
                       OR UPPER(TRIM(nombre_razon_social)) = UPPER(TRIM(?))
                    LIMIT 1
                ");
                $stmtChkPer->execute([$docLimpio, $documento, $nombreRazon]);
                $existingPersonaId = $stmtChkPer->fetchColumn();
            }

            $reutilizado = false;
            if ($existingPersonaId) {
                $personaId = $existingPersonaId;
                $reutilizado = true;

                // Verificar si esta persona ya tiene ficha de empleado
                $stmtChkEmpExist = $pdo->prepare("SELECT codigo_empleado FROM identidad.empleados WHERE persona_id = ?");
                $stmtChkEmpExist->execute([$personaId]);
                $codExistente = $stmtChkEmpExist->fetchColumn();
                if ($codExistente) {
                    throw new Exception("Esta persona ya está registrada como empleado con el código '{$codExistente}'.");
                }

                // Reutilizar persona sin crear registro nuevo (solo completar teléfono/email si estaban vacíos)
                $stmtUpPer = $pdo->prepare("
                    UPDATE identidad.personas 
                    SET telefono_principal = COALESCE(NULLIF(?, ''), telefono_principal),
                        email = COALESCE(NULLIF(?, ''), email)
                    WHERE persona_id = ?
                ");
                $stmtUpPer->execute([$telefono, $email, $personaId]);
            } else {
                // Insertar nueva persona únicamente si NO existe en identidad.personas
                $stmtInsPer = $pdo->prepare("
                    INSERT INTO identidad.personas 
                    (tipo_documento, documento_identidad, nombre_razon_social, telefono_principal, email, tipo_persona, estado) 
                    VALUES (?, ?, ?, ?, ?, 'FISICA', true) 
                    RETURNING persona_id
                ");
                $stmtInsPer->execute([$tipoDocumento, $documento, $nombreRazon, $telefono, $email]);
                $personaId = $stmtInsPer->fetchColumn();
            }

            // Insertar únicamente el subtipo en identidad.empleados
            $stmtInsEmp = $pdo->prepare("
                INSERT INTO identidad.empleados 
                (persona_id, codigo_empleado, cargo, fecha_ingreso, estado_laboral) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtInsEmp->execute([$personaId, $codigoEmpleado, $cargo, $fechaIngreso, $estadoLaboral]);

            $pdo->commit();
            $msgExito = $reutilizado 
                ? "Empleado '{$nombreRazon}' ({$codigoEmpleado}) registrado reutilizando sus datos maestros existentes en identidad.personas."
                : "Empleado '{$nombreRazon}' ({$codigoEmpleado}) registrado con éxito.";
            responder('success', $msgExito, [], 'personal');
        }

        if ($accion === 'editar_empleado') {
            if (empty($empleadoId)) {
                throw new Exception("Identificador de empleado no proporcionado.");
            }

            // Actualizar datos de persona
            if ($personaId) {
                // Verificar que no duplique documento con otra persona
                $stmtChkDoc = $pdo->prepare("
                    SELECT 1 FROM identidad.personas 
                    WHERE (replace(replace(documento_identidad, '-', ''), ' ', '') = ? OR documento_identidad = ?)
                      AND persona_id != ?
                ");
                $stmtChkDoc->execute([$docLimpio, $documento, $personaId]);
                if ($stmtChkDoc->fetchColumn()) {
                    throw new Exception("El documento '{$documento}' ya pertenece a otra persona en el sistema.");
                }

                $stmtUpPer = $pdo->prepare("
                    UPDATE identidad.personas 
                    SET tipo_documento = ?, documento_identidad = ?, nombre_razon_social = ?, 
                        telefono_principal = ?, email = ?
                    WHERE persona_id = ?
                ");
                $stmtUpPer->execute([$tipoDocumento, $documento, $nombreRazon, $telefono, $email, $personaId]);
            }

            // Actualizar empleado
            $stmtUpEmp = $pdo->prepare("
                UPDATE identidad.empleados 
                SET codigo_empleado = ?, cargo = ?, fecha_ingreso = ?, estado_laboral = ? 
                WHERE empleado_id = ?
            ");
            $stmtUpEmp->execute([$codigoEmpleado, $cargo, $fechaIngreso, $estadoLaboral, $empleadoId]);

            $pdo->commit();
            responder('success', "Datos del empleado {$codigoEmpleado} actualizados correctamente.", [], 'personal');
        }
    }

    // ========================================================================
    // ACCIÓN 2: CAMBIAR ESTADO DE EMPLEADO
    // ========================================================================
    if ($accion === 'cambiar_estado_empleado') {
        $empleadoId   = trim($_POST['empleado_id'] ?? '');
        $nuevoEstado  = trim($_POST['nuevo_estado'] ?? 'ACTIVO');

        if (empty($empleadoId)) {
            throw new Exception("Identificador de empleado no válido.");
        }

        $stmt = $pdo->prepare("UPDATE identidad.empleados SET estado_laboral = ? WHERE empleado_id = ?");
        $stmt->execute([$nuevoEstado, $empleadoId]);

        responder('success', "Estado del empleado actualizado a '{$nuevoEstado}'.", [], 'personal');
    }

    // ========================================================================
    // ACCIÓN 3: CREAR O EDITAR REPARTIDOR DELIVERY
    // ========================================================================
    if ($accion === 'crear_repartidor' || $accion === 'editar_repartidor') {
        $empleadoId       = trim($_POST['empleado_id'] ?? '');
        $numeroLicencia   = strtoupper(trim($_POST['numero_licencia'] ?? ''));
        $telefonoRep      = trim($_POST['telefono_principal'] ?? '');
        $tipoVehiculo     = strtoupper(trim($_POST['tipo_vehiculo'] ?? 'MOTOCICLETA'));
        $placaVehiculo    = strtoupper(trim($_POST['placa_vehiculo'] ?? ''));
        $capacidadPedidos = intval($_POST['capacidad_pedidos'] ?? 1);
        $estadoLogistico  = strtoupper(trim($_POST['estado_logistico'] ?? 'BASE'));

        if (empty($empleadoId)) {
            throw new Exception("Debes seleccionar un empleado del personal para habilitarlo como repartidor.");
        }

        // Formatear y validar Licencia de Conducir (11 dígitos: 000-0000000-0)
        $licDigits = preg_replace('/[^0-9]/', '', $numeroLicencia);
        if (strlen($licDigits) !== 11) {
            throw new Exception("El número de licencia de conducir debe tener exactamente 11 dígitos (Formato: 000-0000000-0).");
        }
        $numeroLicencia = substr($licDigits, 0, 3) . '-' . substr($licDigits, 3, 7) . '-' . substr($licDigits, 10, 1);

        // Formatear y validar Teléfono Móvil (10 dígitos: (809) 000-0000)
        if (!empty($telefonoRep)) {
            $telDigits = preg_replace('/[^0-9]/', '', $telefonoRep);
            if (strlen($telDigits) !== 10) {
                throw new Exception("El número de teléfono del repartidor debe tener 10 dígitos (Formato: (809) 000-0000).");
            }
            $telefonoRep = '(' . substr($telDigits, 0, 3) . ') ' . substr($telDigits, 3, 3) . '-' . substr($telDigits, 6, 4);
        } else {
            throw new Exception("El número de teléfono móvil del repartidor es obligatorio.");
        }

        if (empty($placaVehiculo)) {
            throw new Exception("La placa del vehículo asignado es obligatoria.");
        }
        if ($capacidadPedidos < 1) {
            $capacidadPedidos = 1;
        }

        // Validar tipo de vehículo según constraint DB
        $vehiculosPermitidos = ['MOTOCICLETA', 'FURGONETA', 'AUTOMOVIL'];
        if (!in_array($tipoVehiculo, $vehiculosPermitidos, true)) {
            $tipoVehiculo = 'MOTOCICLETA';
        }

        // Validar estado logístico según constraint DB
        $estadosLogisticosPermitidos = ['BASE', 'EN_RUTA_CON_ESPACIO', 'EN_RUTA_LLENO', 'INACTIVO'];
        if (!in_array($estadoLogistico, $estadosLogisticosPermitidos, true)) {
            $estadoLogistico = 'BASE';
        }

        $pdo->beginTransaction();

        // Actualizar el teléfono en identidad.personas asociado al empleado
        $stmtUpTel = $pdo->prepare("
            UPDATE identidad.personas 
            SET telefono_principal = ? 
            WHERE persona_id = (SELECT persona_id FROM identidad.empleados WHERE empleado_id = ?)
        ");
        $stmtUpTel->execute([$telefonoRep, $empleadoId]);

        // Validar unicidad de licencia
        $stmtChkLic = $pdo->prepare("SELECT empleado_id FROM identidad.repartidores WHERE UPPER(numero_licencia) = ? AND empleado_id != ?");
        $stmtChkLic->execute([$numeroLicencia, $empleadoId]);
        if ($stmtChkLic->fetchColumn()) {
            throw new Exception("La licencia '{$numeroLicencia}' ya está asignada a otro repartidor.");
        }

        // Validar unicidad de placa
        $stmtChkPlaca = $pdo->prepare("SELECT empleado_id FROM identidad.repartidores WHERE UPPER(placa_vehiculo) = ? AND empleado_id != ?");
        $stmtChkPlaca->execute([$placaVehiculo, $empleadoId]);
        if ($stmtChkPlaca->fetchColumn()) {
            throw new Exception("La placa '{$placaVehiculo}' ya está registrada en otro repartidor.");
        }

        if ($accion === 'crear_repartidor') {
            // Validar que el empleado no sea ya repartidor
            $stmtChkRep = $pdo->prepare("SELECT 1 FROM identidad.repartidores WHERE empleado_id = ?");
            $stmtChkRep->execute([$empleadoId]);
            if ($stmtChkRep->fetchColumn()) {
                throw new Exception("El empleado seleccionado ya se encuentra registrado como repartidor activo.");
            }

            $stmtInsRep = $pdo->prepare("
                INSERT INTO identidad.repartidores 
                (empleado_id, numero_licencia, tipo_vehiculo, placa_vehiculo, estado_logistico, capacidad_pedidos) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtInsRep->execute([$empleadoId, $numeroLicencia, $tipoVehiculo, $placaVehiculo, $estadoLogistico, $capacidadPedidos]);

            // Actualizar cargo si es necesario
            $pdo->prepare("UPDATE identidad.empleados SET cargo = 'Repartidor Delivery' WHERE empleado_id = ? AND cargo NOT LIKE '%Repartidor%'")->execute([$empleadoId]);

            $pdo->commit();
            responder('success', "Repartidor registrado exitosamente con placa '{$placaVehiculo}'.", [], 'repartidores');
        }

        if ($accion === 'editar_repartidor') {
            $stmtUpRep = $pdo->prepare("
                UPDATE identidad.repartidores 
                SET numero_licencia = ?, tipo_vehiculo = ?, placa_vehiculo = ?, estado_logistico = ?, capacidad_pedidos = ? 
                WHERE empleado_id = ?
            ");
            $stmtUpRep->execute([$numeroLicencia, $tipoVehiculo, $placaVehiculo, $estadoLogistico, $capacidadPedidos, $empleadoId]);

            $pdo->commit();
            responder('success', "Ficha del repartidor actualizada correctamente.", [], 'repartidores');
        }
    }

    // ========================================================================
    // ACCIÓN 4: CAMBIAR ESTADO LOGÍSTICO DE REPARTIDOR
    // ========================================================================
    if ($accion === 'cambiar_estado_repartidor') {
        $empleadoId      = trim($_POST['empleado_id'] ?? '');
        $nuevoEstadoLog  = strtoupper(trim($_POST['nuevo_estado_logistico'] ?? 'BASE'));

        if (empty($empleadoId)) {
            throw new Exception("Identificador de repartidor no válido.");
        }

        $stmt = $pdo->prepare("UPDATE identidad.repartidores SET estado_logistico = ? WHERE empleado_id = ?");
        $stmt->execute([$nuevoEstadoLog, $empleadoId]);

        responder('success', "Estado logístico actualizado a '{$nuevoEstadoLog}'.", [], 'repartidores');
    }

    // ========================================================================
    // ACCIÓN 5: RETIRAR PERFIL DE REPARTIDOR
    // ========================================================================
    if ($accion === 'eliminar_repartidor') {
        $empleadoId = trim($_POST['empleado_id'] ?? '');

        if (empty($empleadoId)) {
            throw new Exception("Identificador no válido.");
        }

        // Verificar si tiene pedidos en camino actualmente
        $stmtPed = $pdo->prepare("SELECT 1 FROM ventas.pedidos_delivery WHERE repartidor_id = ? AND estado_delivery = 'EN_CAMINO' LIMIT 1");
        $stmtPed->execute([$empleadoId]);
        if ($stmtPed->fetchColumn()) {
            throw new Exception("No puedes desvincular al repartidor porque tiene entregas en ruta en este momento.");
        }

        $stmt = $pdo->prepare("DELETE FROM identidad.repartidores WHERE empleado_id = ?");
        $stmt->execute([$empleadoId]);

        responder('success', "Perfil de repartidor delivery retirado exitosamente.", [], 'repartidores');
    }

    throw new Exception("Acción '{$accion}' no reconocida por el controlador.");

} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder('error', $e->getMessage(), [], $tab);
}

