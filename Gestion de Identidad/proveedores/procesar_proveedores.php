<?php

// Búsqueda GET en vivo de Terceros en identidad.personas (por documento, nombre o código)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && (isset($_GET['documento']) || isset($_GET['nombre']) || isset($_GET['codigo']))) {
    require_once __DIR__ . '/../../config/conexion.php';
    header('Content-Type: application/json; charset=utf-8');
    $documento = trim($_GET['documento'] ?? '');
    $nombre    = trim($_GET['nombre'] ?? '');
    $codigo    = trim($_GET['codigo'] ?? '');

    if ((empty($documento) && empty($nombre) && empty($codigo)) || !$pdo) {
        echo json_encode(['encontrado' => false]);
        exit;
    }

    try {
        $persona = null;
        if (!empty($codigo)) {
            $stmt = $pdo->prepare("
                SELECT p.persona_id, p.tipo_documento, p.documento_identidad,
                       p.documento_identidad AS numero_documento,
                       p.nombre_razon_social, p.nombre_razon_social AS primer_nombre,
                       p.nombre_comercial, p.nombre_comercial AS segundo_nombre,
                       p.fecha_nacimiento, p.email, p.telefono_principal,
                       p.telefono_principal AS telefono, p.tipo_persona, p.estado,
                       pr.persona_id AS proveedor_id, pr.codigo_proveedor, pr.dias_credito_habitual,
                       COALESCE((
                           SELECT MAX(r.porcentaje)
                           FROM identidad.proveedor_retenciones prr
                           JOIN tesoreria.retenciones r ON prr.retencion_id = r.id
                           WHERE prr.proveedor_id = pr.persona_id
                       ), 0.00) AS retencion_isr_porc,
                       (SELECT c.codigo_cliente FROM identidad.clientes c WHERE c.persona_id = p.persona_id LIMIT 1) AS codigo_cliente,
                       (SELECT e.codigo_empleado FROM identidad.empleados e WHERE e.persona_id = p.persona_id LIMIT 1) AS codigo_empleado
                FROM identidad.personas p
                LEFT JOIN identidad.proveedores pr ON pr.persona_id = p.persona_id
                LEFT JOIN identidad.clientes c ON c.persona_id = p.persona_id
                LEFT JOIN identidad.empleados e ON e.persona_id = p.persona_id
                WHERE UPPER(TRIM(pr.codigo_proveedor)) = UPPER(TRIM(?))
                   OR REPLACE(UPPER(TRIM(pr.codigo_proveedor)), 'PROV-', 'PRO-') = REPLACE(UPPER(TRIM(?)), 'PROV-', 'PRO-')
                   OR UPPER(TRIM(c.codigo_cliente)) = UPPER(TRIM(?))
                   OR UPPER(TRIM(e.codigo_empleado)) = UPPER(TRIM(?))
                LIMIT 1
            ");
            $stmt->execute([$codigo, $codigo, $codigo, $codigo]);
            $persona = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif (!empty($documento)) {
            $docLimpio = str_replace(['-', ' ', '.'], '', $documento);
            $stmt = $pdo->prepare("
                SELECT p.persona_id, p.tipo_documento, p.documento_identidad,
                       p.documento_identidad AS numero_documento,
                       p.nombre_razon_social, p.nombre_razon_social AS primer_nombre,
                       p.nombre_comercial, p.nombre_comercial AS segundo_nombre,
                       p.fecha_nacimiento, p.email, p.telefono_principal,
                       p.telefono_principal AS telefono, p.tipo_persona, p.estado,
                       pr.persona_id AS proveedor_id, pr.codigo_proveedor, pr.dias_credito_habitual,
                       COALESCE((
                           SELECT MAX(r.porcentaje)
                           FROM identidad.proveedor_retenciones prr
                           JOIN tesoreria.retenciones r ON prr.retencion_id = r.id
                           WHERE prr.proveedor_id = pr.persona_id
                       ), 0.00) AS retencion_isr_porc,
                       (SELECT c.codigo_cliente FROM identidad.clientes c WHERE c.persona_id = p.persona_id LIMIT 1) AS codigo_cliente,
                       (SELECT e.codigo_empleado FROM identidad.empleados e WHERE e.persona_id = p.persona_id LIMIT 1) AS codigo_empleado
                FROM identidad.personas p
                LEFT JOIN identidad.proveedores pr ON pr.persona_id = p.persona_id
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
                       pr.persona_id AS proveedor_id, pr.codigo_proveedor, pr.dias_credito_habitual,
                       COALESCE((
                           SELECT MAX(r.porcentaje)
                           FROM identidad.proveedor_retenciones prr
                           JOIN tesoreria.retenciones r ON prr.retencion_id = r.id
                           WHERE prr.proveedor_id = pr.persona_id
                       ), 0.00) AS retencion_isr_porc,
                       (SELECT c.codigo_cliente FROM identidad.clientes c WHERE c.persona_id = p.persona_id LIMIT 1) AS codigo_cliente,
                       (SELECT e.codigo_empleado FROM identidad.empleados e WHERE e.persona_id = p.persona_id LIMIT 1) AS codigo_empleado
                FROM identidad.personas p
                LEFT JOIN identidad.proveedores pr ON pr.persona_id = p.persona_id
                WHERE UPPER(TRIM(p.nombre_razon_social)) = UPPER(TRIM(?))
                   OR UPPER(p.nombre_razon_social) LIKE UPPER(?)
                ORDER BY CASE WHEN UPPER(TRIM(p.nombre_razon_social)) = UPPER(TRIM(?)) THEN 0 ELSE 1 END
                LIMIT 1
            ");
            $stmt->execute([$nombre, '%' . $nombre . '%', $nombre]);
            $persona = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($persona) {
            $stmtRetIds = $pdo->prepare("
                SELECT retencion_id FROM identidad.proveedor_retenciones
                WHERE proveedor_id = ?
                ORDER BY retencion_id ASC
            ");
            $stmtRetIds->execute([$persona['proveedor_id'] ?: $persona['persona_id']]);
            $persona['retenciones_ids'] = array_map('intval', $stmtRetIds->fetchAll(PDO::FETCH_COLUMN) ?: []);

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
 * Procesador de Proveedores (Backend Controller)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: identidad.personas, identidad.proveedores
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
        header("Location: proveedores.php?msg=" . urlencode($mensaje) . "&tipo=" . $tipo);
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
        $proveedorId = !empty($_POST['proveedor_id']) ? trim($_POST['proveedor_id']) : null;
        $personaId   = !empty($_POST['persona_id']) ? trim($_POST['persona_id']) : null;

        $codigoProveedor = strtoupper(trim($_POST['codigo_proveedor'] ?? ''));
        $tipoDoc         = strtoupper(trim($_POST['tipo_documento'] ?? 'RNC'));
        $numDoc          = trim($_POST['documento_identidad'] ?? $_POST['ruc'] ?? $_POST['rnc'] ?? '');
        $razonSocial     = trim($_POST['nombre_razon_social'] ?? $_POST['razon_social'] ?? '');
        $nombreComercial = trim($_POST['nombre_comercial'] ?? '');
        $telefono        = trim($_POST['telefono_principal'] ?? $_POST['telefono_contacto'] ?? $_POST['telefono'] ?? '');
        $email           = trim($_POST['email'] ?? $_POST['email_contacto'] ?? '');
        $tipoPersona     = strtoupper(trim($_POST['tipo_persona'] ?? (($tipoDoc === 'RNC') ? 'JURIDICA' : 'FISICA')));
        $fechaNacimiento = trim($_POST['fecha_nacimiento'] ?? '');

        if ($tipoPersona === 'JURIDICA') {
            $fechaNacimiento = null;
        } else {
            if ($fechaNacimiento === '') {
                if ($accion === 'crear') {
                    throw new Exception("La fecha de nacimiento es obligatoria para proveedores persona física (mayores de 18 años).");
                }
                $fechaNacimiento = null;
            } else {
                $dtNac = DateTimeImmutable::createFromFormat('Y-m-d', $fechaNacimiento);
                $hoy   = new DateTimeImmutable('today');
                if (!$dtNac || $dtNac->format('Y-m-d') !== $fechaNacimiento || $dtNac > $hoy) {
                    throw new Exception("La fecha de nacimiento ingresada no es válida.");
                }
                $edad = $dtNac->diff($hoy)->y;
                if ($edad < 18) {
                    throw new Exception("El proveedor persona física debe ser mayor de 18 años (edad calculada: {$edad} años).");
                }
            }
        }
        
        $diasCreditoRaw  = trim((string)($_POST['dias_credito_habitual'] ?? '30'));
        if ($diasCreditoRaw === '' || !preg_match('/^\d+$/', $diasCreditoRaw)) {
            throw new Exception("Los días de crédito habitual deben ser un número entero válido (sin letras ni decimales).");
        }
        $diasCredito     = intval($diasCreditoRaw);
        if ($diasCredito < 0 || $diasCredito > 180) {
            throw new Exception("Los días de crédito habitual deben estar entre 0 y 180 días.");
        }
        $retencionesArr  = isset($_POST['retenciones']) && is_array($_POST['retenciones'])
                           ? array_values(array_unique(array_filter(array_map('intval', $_POST['retenciones']), fn($v) => $v > 0)))
                           : [];
        $retencionIsr    = floatval($_POST['retencion_isr_porc'] ?? 0.00);

        // Validaciones obligatorias
        if (empty($codigoProveedor) || empty($numDoc) || empty($razonSocial)) {
            throw new Exception("El código de proveedor, RNC/Documento y Razón Social son campos obligatorios.");
        }

        // Validación de formato de código (ej. PROV-001)
        if (!preg_match('/^[A-Z0-9_-]{3,20}$/i', $codigoProveedor)) {
            throw new Exception("El código '{$codigoProveedor}' no es válido. Usa letras, números y guiones (ej. PROV-001).");
        }

        // Validación de documento (RNC 9 dígitos, Cédula 11)
        $docLimpio = preg_replace('/[^0-9A-Za-z]/', '', $numDoc);
        if ($tipoDoc === 'RNC' && (!preg_match('/^\d{9}$/', $docLimpio) && !preg_match('/^\d{11}$/', $docLimpio))) {
            throw new Exception("El RNC debe contener 9 dígitos numéricos válidos (o 11 si es persona física con RNC).");
        } elseif ($tipoDoc === 'CEDULA' && !preg_match('/^\d{11}$/', $docLimpio)) {
            throw new Exception("La cédula dominicana debe contener exactamente 11 dígitos numéricos.");
        }

        // Validación de teléfono (si se proporciona, debe tener 10 dígitos numéricos)
        if (!empty($telefono)) {
            $telDigitos = preg_replace('/\D/', '', $telefono);
            if (strlen($telDigitos) !== 10) {
                throw new Exception("El teléfono debe contener exactamente 10 dígitos numéricos con formato (809) 000-0000.");
            }
            $telefono = '(' . substr($telDigitos, 0, 3) . ') ' . substr($telDigitos, 3, 3) . '-' . substr($telDigitos, 6, 4);
        }

        // Validación de días de crédito y retención
        if ($diasCredito < 0) $diasCredito = 0;
        if ($retencionIsr < 0 || $retencionIsr > 100) {
            throw new Exception("El porcentaje de retención ISR debe estar entre 0.00% y 100.00%.");
        }

        // Validación de email
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("El correo electrónico '{$email}' no tiene un formato válido.");
        }

        $pdo->beginTransaction();

        // 1. Validar unicidad del código de proveedor
        if ($accion === 'crear') {
            $stmtChkCod = $pdo->prepare("SELECT 1 FROM identidad.proveedores WHERE UPPER(codigo_proveedor) = ?");
            $stmtChkCod->execute([$codigoProveedor]);
            if ($stmtChkCod->fetchColumn()) {
                throw new Exception("El código de proveedor '{$codigoProveedor}' ya está registrado. Genera o digita otro código único.");
            }
        } else {
            $stmtChkCod = $pdo->prepare("SELECT 1 FROM identidad.proveedores WHERE UPPER(codigo_proveedor) = ? AND persona_id != ?");
            $stmtChkCod->execute([$codigoProveedor, $proveedorId ?: $personaId]);
            if ($stmtChkCod->fetchColumn()) {
                throw new Exception("El código de proveedor '{$codigoProveedor}' ya pertenece a otro proveedor registrado.");
            }
        }

        // 2. Gestionar Tercero (identidad.personas - Supertipo / Subtipo)
        $docFormateado = $numDoc;
        $personaExistenteId = null;

        if (!empty($personaId)) {
            $stmtById = $pdo->prepare("SELECT persona_id FROM identidad.personas WHERE persona_id = ?");
            $stmtById->execute([$personaId]);
            $personaExistenteId = $stmtById->fetchColumn();
        }

        if (!$personaExistenteId) {
            $stmtPer = $pdo->prepare("
                SELECT persona_id FROM identidad.personas 
                WHERE replace(replace(documento_identidad, '-', ''), ' ', '') = ?
                   OR UPPER(documento_identidad) = UPPER(?)
                   OR UPPER(TRIM(nombre_razon_social)) = UPPER(TRIM(?))
                LIMIT 1
            ");
            $stmtPer->execute([$docLimpio, $docFormateado, $razonSocial]);
            $personaExistenteId = $stmtPer->fetchColumn();
        }

        if ($personaExistenteId) {
            $personaId = $personaExistenteId;
            // Reutilizar persona existente sin duplicar en identidad.personas
            $stmtUpdPer = $pdo->prepare("
                UPDATE identidad.personas
                SET nombre_comercial = COALESCE(NULLIF(?, ''), nombre_comercial),
                    telefono_principal = COALESCE(NULLIF(?, ''), telefono_principal),
                    email = COALESCE(NULLIF(?, ''), email),
                    fecha_nacimiento = CASE WHEN ? = 'JURIDICA' THEN NULL ELSE COALESCE(?, fecha_nacimiento) END
                WHERE persona_id = ?
            ");
            $stmtUpdPer->execute([
                $nombreComercial ?: $razonSocial,
                $telefono,
                $email,
                $tipoPersona,
                $fechaNacimiento,
                $personaId
            ]);
        } else {
            // Insertar nuevo tercero
            $stmtInsPer = $pdo->prepare("
                INSERT INTO identidad.personas 
                (persona_id, documento_identidad, tipo_documento, nombre_razon_social, nombre_comercial, tipo_persona, telefono_principal, email, fecha_nacimiento, estado)
                VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, ?, true)
                RETURNING persona_id
            ");
            $stmtInsPer->execute([
                $docFormateado,
                $tipoDoc,
                $razonSocial,
                $nombreComercial ?: $razonSocial,
                $tipoPersona,
                $telefono,
                $email,
                $fechaNacimiento
            ]);
            $personaId = $stmtInsPer->fetchColumn();
        }

        // 3. Crear o Actualizar identidad.proveedores y sus retenciones en identidad.proveedor_retenciones
        $targetProvId = $proveedorId ?: $personaId;
        if ($accion === 'crear') {
            // Verificar si este tercero ya es proveedor
            $stmtChkProv = $pdo->prepare("SELECT 1 FROM identidad.proveedores WHERE persona_id = ?");
            $stmtChkProv->execute([$personaId]);
            if ($stmtChkProv->fetchColumn()) {
                throw new Exception("Este suplidor/tercero ya está registrado como proveedor activo.");
            }

            $stmtInsProv = $pdo->prepare("
                INSERT INTO identidad.proveedores
                (persona_id, codigo_proveedor, dias_credito_habitual)
                VALUES (?, ?, ?)
            ");
            $stmtInsProv->execute([$personaId, $codigoProveedor, $diasCredito]);
            $targetProvId = $personaId;
        } else {
            if (empty($targetProvId)) {
                throw new Exception("ID de proveedor no especificado para actualizar.");
            }

            $stmtUpdProv = $pdo->prepare("
                UPDATE identidad.proveedores
                SET codigo_proveedor = ?,
                    dias_credito_habitual = ?
                WHERE persona_id = ?
            ");
            $stmtUpdProv->execute([$codigoProveedor, $diasCredito, $targetProvId]);
        }

        // Sincronizar retenciones múltiples en identidad.proveedor_retenciones + tesoreria.retenciones
        $pdo->prepare("DELETE FROM identidad.proveedor_retenciones WHERE proveedor_id = ?")->execute([$targetProvId]);
        if (!empty($retencionesArr)) {
            $stmtInsPrvRet = $pdo->prepare("
                INSERT INTO identidad.proveedor_retenciones (proveedor_id, retencion_id)
                VALUES (?, ?)
                ON CONFLICT DO NOTHING
            ");
            foreach ($retencionesArr as $rId) {
                $stmtInsPrvRet->execute([$targetProvId, $rId]);
            }
        } elseif ($retencionIsr > 0) {
            $stmtFindRet = $pdo->prepare("
                SELECT id FROM tesoreria.retenciones
                WHERE ABS(porcentaje - ?) < 0.01
                ORDER BY CASE WHEN base_calculo = 'SUBTOTAL' THEN 0 ELSE 1 END, id ASC
                LIMIT 1
            ");
            $stmtFindRet->execute([$retencionIsr]);
            $retId = $stmtFindRet->fetchColumn();
            if ($retId) {
                $stmtInsPrvRet = $pdo->prepare("
                    INSERT INTO identidad.proveedor_retenciones (proveedor_id, retencion_id)
                    VALUES (?, ?)
                    ON CONFLICT DO NOTHING
                ");
                $stmtInsPrvRet->execute([$targetProvId, $retId]);
            }
        }

        $pdo->commit();
        if ($accion === 'crear') {
            responder('success', "Proveedor '{$razonSocial}' ({$codigoProveedor}) registrado exitosamente.");
        } else {
            responder('success', "Proveedor '{$razonSocial}' ({$codigoProveedor}) actualizado exitosamente.");
        }
    }

    if ($accion === 'cambiar_estado' || $accion === 'toggle_estado') {
        $proveedorId = trim($_POST['proveedor_id'] ?? $_POST['id'] ?? '');
        $nuevoEstado = filter_var($_POST['estado'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if (empty($proveedorId)) {
            throw new Exception("Identificador de proveedor no proporcionado.");
        }

        $pdo->beginTransaction();
        $stmtGetPer = $pdo->prepare("SELECT persona_id FROM identidad.proveedores WHERE persona_id = ?");
        $stmtGetPer->execute([$proveedorId]);
        $personaId = $stmtGetPer->fetchColumn();

        if ($personaId) {
            $stmtUpd = $pdo->prepare("UPDATE identidad.personas SET estado = ? WHERE persona_id = ?");
            $stmtUpd->execute([$nuevoEstado ? 'true' : 'false', $personaId]);
        }

        $pdo->commit();
        $txt = $nuevoEstado ? 'activado' : 'desactivado';
        responder('success', "Proveedor {$txt} correctamente.", ['estado' => $nuevoEstado]);
    }

    throw new Exception("Acción no válida o no especificada.");
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder('error', $e->getMessage());
}
