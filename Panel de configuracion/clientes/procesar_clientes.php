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
 * Procesador de Clientes (Backend Controller)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: identidad.personas, identidad.clientes
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
        header("Location: clientes.php?msg=" . urlencode($mensaje) . "&tipo=" . $tipo);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder('error', 'Método HTTP no permitido.');
}

$accion = trim($_POST['accion'] ?? $_POST['action'] ?? 'crear');

if (!$pdo) {
    responder('error', 'No hay conexión activa con la base de datos PostgreSQL.');
}

try {
    if ($accion === 'crear' || $accion === 'editar') {
        $clienteId = !empty($_POST['cliente_id']) ? trim($_POST['cliente_id']) : null;
        $personaId = !empty($_POST['persona_id']) ? trim($_POST['persona_id']) : null;

        $codigoCliente    = strtoupper(trim($_POST['codigo_cliente'] ?? ''));
        $tipoDoc          = strtoupper(trim($_POST['tipo_documento'] ?? 'CEDULA'));
        $numDoc           = trim($_POST['documento_identidad'] ?? $_POST['numero_documento'] ?? '');
        $nombreRazon      = trim($_POST['nombre_razon_social'] ?? $_POST['primer_nombre'] ?? '');
        $nombreComercial  = trim($_POST['nombre_comercial'] ?? '');
        $telefono         = trim($_POST['telefono_principal'] ?? $_POST['telefono'] ?? '');
        $email            = trim($_POST['email'] ?? '');
        $tipoPersona      = strtoupper(trim($_POST['tipo_persona'] ?? (($tipoDoc === 'RNC') ? 'JURIDICA' : 'FISICA')));
        $categoriaCliente = strtoupper(trim($_POST['categoria_cliente'] ?? 'REGULAR'));
        $permiteCredito   = !empty($_POST['permite_credito']) ? true : false;
        $limiteCredito    = floatval($_POST['limite_credito'] ?? 0);
        $estadoPersona    = !empty($_POST['estado_persona']) ? filter_var($_POST['estado_persona'], FILTER_VALIDATE_BOOLEAN) : true;

        // Validaciones obligatorias
        if (empty($codigoCliente) || empty($numDoc) || empty($nombreRazon)) {
            throw new Exception("El código de cliente, documento de identidad y nombre/razón social son campos obligatorios.");
        }

        // Validación de formato de código (ej. CLI-001)
        if (!preg_match('/^[A-Z0-9_-]{3,20}$/i', $codigoCliente)) {
            throw new Exception("El código '{$codigoCliente}' no es válido. Usa letras, números y guiones (ej. CLI-001).");
        }

        // Validación de documento
        $docLimpio = preg_replace('/[^0-9A-Za-z]/', '', $numDoc);
        if ($tipoDoc === 'CEDULA' && strlen($docLimpio) !== 11) {
            throw new Exception("La cédula debe contener exactamente 11 dígitos numéricos.");
        }
        if ($tipoDoc === 'RNC' && (strlen($docLimpio) !== 9 && strlen($docLimpio) !== 11)) {
            throw new Exception("El RNC debe contener 9 u 11 dígitos numéricos.");
        }

        // Validación de email si se proporciona
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("El correo electrónico '{$email}' no tiene un formato válido.");
        }

        // Validar límite de crédito
        if ($limiteCredito < 0) {
            $limiteCredito = 0.00;
        }

        // Validar categorías permitidas
        $catsPermitidas = ['REGULAR', 'VIP', 'MAYORISTA'];
        if (!in_array($categoriaCliente, $catsPermitidas, true)) {
            $categoriaCliente = 'REGULAR';
        }

        $pdo->beginTransaction();

        // 1. Validar unicidad del código de cliente
        if ($accion === 'crear') {
            $stmtChkCod = $pdo->prepare("SELECT 1 FROM identidad.clientes WHERE UPPER(codigo_cliente) = ?");
            $stmtChkCod->execute([$codigoCliente]);
            if ($stmtChkCod->fetchColumn()) {
                throw new Exception("El código de cliente '{$codigoCliente}' ya está registrado. Por favor genera o digita otro.");
            }
        } else {
            $stmtChkCod = $pdo->prepare("SELECT 1 FROM identidad.clientes WHERE UPPER(codigo_cliente) = ? AND cliente_id != ?");
            $stmtChkCod->execute([$codigoCliente, $clienteId]);
            if ($stmtChkCod->fetchColumn()) {
                throw new Exception("El código '{$codigoCliente}' ya pertenece a otro cliente registrado.");
            }
        }

        // 2. Gestionar Persona Maestra (Tercero - Supertipo / Subtipo)
        if ($accion === 'crear') {
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
                $stmtChkPer->execute([$docLimpio, $numDoc, $nombreRazon]);
                $existingPersonaId = $stmtChkPer->fetchColumn();
            }

            if ($existingPersonaId) {
                $personaId = $existingPersonaId;

                // Verificar si esta persona ya tiene ficha de cliente
                $stmtChkCliExist = $pdo->prepare("SELECT codigo_cliente FROM identidad.clientes WHERE persona_id = ?");
                $stmtChkCliExist->execute([$personaId]);
                $codExist = $stmtChkCliExist->fetchColumn();
                if ($codExist) {
                    throw new Exception("Este cliente ya está registrado bajo el código '{$codExist}'.");
                }

                // Reutilizar persona existente sin duplicar en identidad.personas
                $stmtUpPer = $pdo->prepare("
                    UPDATE identidad.personas 
                    SET nombre_comercial = COALESCE(NULLIF(?, ''), nombre_comercial),
                        telefono_principal = COALESCE(NULLIF(?, ''), telefono_principal),
                        email = COALESCE(NULLIF(?, ''), email)
                    WHERE persona_id = ?
                ");
                $stmtUpPer->execute([$nombreComercial ?: $nombreRazon, $telefono, $email, $personaId]);
            } else {
                // Insertar nueva persona
                $stmtInsPer = $pdo->prepare("
                    INSERT INTO identidad.personas 
                    (tipo_documento, documento_identidad, nombre_razon_social, nombre_comercial, telefono_principal, email, tipo_persona, estado) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, true) 
                    RETURNING persona_id
                ");
                $stmtInsPer->execute([$tipoDoc, $numDoc, $nombreRazon, $nombreComercial ?: $nombreRazon, $telefono, $email, $tipoPersona]);
                $personaId = $stmtInsPer->fetchColumn();
            }

            // Insertar cliente
            $stmtInsCli = $pdo->prepare("
                INSERT INTO identidad.clientes 
                (persona_id, codigo_cliente, categoria_cliente, permite_credito, limite_credito) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtInsCli->execute([$personaId, $codigoCliente, $categoriaCliente, $permiteCredito ? 'true' : 'false', $limiteCredito]);

            $pdo->commit();
            responder('success', "Cliente {$codigoCliente} ({$nombreRazon}) registrado exitosamente.");
        }

        if ($accion === 'editar') {
            if (empty($clienteId)) {
                throw new Exception("Identificador de cliente no especificado.");
            }

            // Actualizar persona
            if ($personaId) {
                // Verificar que no choque con otra persona
                $stmtChkDoc = $pdo->prepare("
                    SELECT 1 FROM identidad.personas 
                    WHERE (replace(replace(documento_identidad, '-', ''), ' ', '') = ? OR documento_identidad = ?)
                      AND persona_id != ?
                ");
                $stmtChkDoc->execute([$docLimpio, $numDoc, $personaId]);
                if ($stmtChkDoc->fetchColumn()) {
                    throw new Exception("El documento '{$numDoc}' ya pertenece a otra persona registrada.");
                }

                $stmtUpPer = $pdo->prepare("
                    UPDATE identidad.personas 
                    SET tipo_documento = ?, documento_identidad = ?, nombre_razon_social = ?, 
                        nombre_comercial = ?, telefono_principal = ?, email = ?, tipo_persona = ?, estado = ?
                    WHERE persona_id = ?
                ");
                $stmtUpPer->execute([$tipoDoc, $numDoc, $nombreRazon, $nombreComercial ?: $nombreRazon, $telefono, $email, $tipoPersona, $estadoPersona ? 'true' : 'false', $personaId]);
            }

            // Actualizar cliente
            $stmtUpCli = $pdo->prepare("
                UPDATE identidad.clientes 
                SET codigo_cliente = ?, categoria_cliente = ?, permite_credito = ?, limite_credito = ?
                WHERE cliente_id = ?
            ");
            $stmtUpCli->execute([$codigoCliente, $categoriaCliente, $permiteCredito ? 'true' : 'false', $limiteCredito, $clienteId]);

            $pdo->commit();
            responder('success', "Datos del cliente {$codigoCliente} actualizados correctamente.");
        }
    }

    // ========================================================================
    // ACCIÓN: CAMBIAR ESTADO / PERMITE CRÉDITO
    // ========================================================================
    if ($accion === 'cambiar_credito') {
        $clienteId = trim($_POST['cliente_id'] ?? '');
        $permite   = filter_var($_POST['permite_credito'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (empty($clienteId)) {
            throw new Exception("Identificador de cliente no proporcionado.");
        }

        $stmt = $pdo->prepare("UPDATE identidad.clientes SET permite_credito = ? WHERE cliente_id = ?");
        $stmt->execute([$permite ? 'true' : 'false', $clienteId]);

        responder('success', "Estado de crédito del cliente actualizado.");
    }

    throw new Exception("Acción '{$accion}' no reconocida por el procesador de clientes.");

} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder('error', $e->getMessage());
}
