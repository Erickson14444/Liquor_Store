<?php
/**
 * ============================================================================
 * Procesador de Cupones y Descuentos Promocionales (Backend Controller)
 * Ubicación: c:\xampp\htdocs\Liquor_Store\Gestion de Promociones\procesar_cupones.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tabla Principal: promociones.cupones_descuento
 * ============================================================================
 */

require_once __DIR__ . '/../config/conexion.php';

// Leer payload JSON o Form-Data POST
$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : null;
$datos    = is_array($jsonBody) ? $jsonBody : $_POST;

$isAjax = is_array($jsonBody) ||
          (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
          (isset($datos['format']) && $datos['format'] === 'json') ||
          (isset($_GET['format']) && $_GET['format'] === 'json');

function responderJson($status, $mensaje, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['status' => $status, 'message' => $mensaje], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function responder($status, $mensaje, $extra = []) {
    global $isAjax;
    if ($isAjax) {
        responderJson($status, $mensaje, $extra);
    } else {
        $tipo = ($status === 'success') ? 'exito' : 'error';
        header("Location: cupones.php?msg=" . urlencode($mensaje) . "&tipo=" . $tipo);
        exit;
    }
}

// Permitir peticiones GET para obtener_detalle si se solicita
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$accion = $datos['accion'] ?? $datos['action'] ?? $_GET['accion'] ?? $_GET['action'] ?? '';

if (!$pdo) {
    responder('error', 'Error crítico: No hay conexión con la base de datos PostgreSQL.');
}

// Mantenimiento preventivo: auto-actualizar cupones vencidos
try {
    $pdo->exec("UPDATE promociones.cupones_descuento SET estado = 'VENCIDO' WHERE estado = 'DISPONIBLE' AND fecha_vencimiento < CURRENT_DATE");
} catch (Throwable $e) {}

// Asegurar que factura_origen_id permita NULL para emisiones promocionales directas
try {
    $pdo->exec("ALTER TABLE promociones.cupones_descuento ALTER COLUMN factura_origen_id DROP NOT NULL");
} catch (Throwable $e) {}

// ACCIÓN: OBTENER DETALLE (Para modal de consulta forense o ticket)
if ($accion === 'obtener_detalle') {
    $cuponId = intval($datos['cupon_id'] ?? $_GET['cupon_id'] ?? 0);
    if ($cuponId <= 0) {
        responderJson('error', 'ID de cupón no proporcionado o inválido.');
    }

    try {
        $stmt = $pdo->prepare("
            SELECT c.id,
                   c.codigo_cupon,
                   c.promocion_id,
                   c.factura_origen_id,
                   c.factura_canje_id,
                   c.cliente_id,
                   c.monto_credito,
                   c.fecha_vencimiento,
                   c.estado,
                   TO_CHAR(c.fecha_vencimiento, 'DD/MM/YYYY') AS fecha_vencimiento_fmt,
                   (c.fecha_vencimiento - CURRENT_DATE) AS dias_restantes,
                   COALESCE(camp.nombre_promocion, 'Cortesía Promocional') AS campana_nombre,
                   camp.tipo_promocion,
                   fo.numero_factura AS factura_origen_numero,
                   fo.ncf AS factura_origen_ncf,
                   TO_CHAR(fo.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS factura_origen_fecha,
                   fc.numero_factura AS factura_canje_numero,
                   fc.ncf AS factura_canje_ncf,
                   TO_CHAR(fc.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS factura_canje_fecha,
                   cl.codigo_cliente,
                   p.nombre_razon_social AS cliente_nombre,
                   p.documento_identidad AS cliente_documento,
                   p.telefono_principal AS cliente_telefono,
                   p.email AS cliente_email
            FROM promociones.cupones_descuento c
            LEFT JOIN promociones.promociones_campanas camp ON c.promocion_id = camp.id
            LEFT JOIN ventas.facturas fo ON c.factura_origen_id = fo.id
            LEFT JOIN ventas.facturas fc ON c.factura_canje_id = fc.id
            LEFT JOIN identidad.clientes cl ON c.cliente_id = cl.persona_id
            LEFT JOIN identidad.personas p ON cl.persona_id = p.persona_id
            WHERE c.id = ?
        ");
        $stmt->execute([$cuponId]);
        $cupon = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cupon) {
            responderJson('error', 'El cupón solicitado no fue encontrado.');
        }

        responderJson('success', 'Cupón cargado correctamente.', ['cupon' => $cupon]);
    } catch (Throwable $e) {
        responderJson('error', 'Error al consultar el cupón: ' . $e->getMessage());
    }
}

// A partir de aquí solo permitimos POST
if ($metodo !== 'POST') {
    responder('error', 'Método HTTP no autorizado.');
}

// Función generadora de código seguro de cupón
function generarCodigoCuponUnico($pdo, $prefijo = 'CUP-') {
    $caracteres = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $longitud = 6;
    for ($intento = 0; $intento < 10; $intento++) {
        $aleatorio = '';
        for ($i = 0; $i < $longitud; $i++) {
            $aleatorio .= $caracteres[random_int(0, strlen($caracteres) - 1)];
        }
        $codigo = $prefijo . $aleatorio;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM promociones.cupones_descuento WHERE codigo_cupon = ?");
        $stmt->execute([$codigo]);
        if ($stmt->fetchColumn() == 0) {
            return $codigo;
        }
    }
    return $prefijo . strtoupper(substr(md5(uniqid('', true)), 0, 6));
}

// ACCIÓN: EMITIR NUEVO CUPÓN
if ($accion === 'emitir' || $accion === 'crear') {
    try {
        $codigoCupon = trim($datos['codigo_cupon'] ?? '');
        if (empty($codigoCupon)) {
            $codigoCupon = generarCodigoCuponUnico($pdo);
        } else {
            $codigoCupon = strtoupper(preg_replace('/[^A-Za-z0-9\-_]/', '', $codigoCupon));
            // Validar que no exista
            $chk = $pdo->prepare("SELECT COUNT(*) FROM promociones.cupones_descuento WHERE codigo_cupon = ?");
            $chk->execute([$codigoCupon]);
            if ($chk->fetchColumn() > 0) {
                throw new Exception("El código de cupón '{$codigoCupon}' ya existe en el sistema.");
            }
        }

        $promocionId = !empty($datos['promocion_id']) ? intval($datos['promocion_id']) : null;
        if (!$promocionId) {
            // Asignar primera campaña activa o crear una de respaldo
            $campDef = $pdo->query("SELECT id FROM promociones.promociones_campanas WHERE estado = 'ACTIVA' ORDER BY id ASC LIMIT 1")->fetchColumn();
            if ($campDef) {
                $promocionId = intval($campDef);
            } else {
                $campCualquiera = $pdo->query("SELECT id FROM promociones.promociones_campanas ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($campCualquiera) {
                    $promocionId = intval($campCualquiera);
                } else {
                    // Crear campaña automática de cortesía
                    $stmtNC = $pdo->prepare("
                        INSERT INTO promociones.promociones_campanas
                        (nombre_promocion, tipo_promocion, fecha_inicio, fecha_fin, monto_minimo_factura, estado)
                        VALUES ('Cupones Promocionales Generales', 'CUPON_TICKET', CURRENT_DATE, CURRENT_DATE + INTERVAL '1 year', 0, 'ACTIVA')
                        RETURNING id
                    ");
                    $stmtNC->execute();
                    $promocionId = intval($stmtNC->fetchColumn());
                }
            }
        }

        $montoCredito = max(0.01, floatval($datos['monto_credito'] ?? 0));
        if ($montoCredito <= 0) {
            throw new Exception("El monto de crédito del cupón debe ser mayor a RD$ 0.00.");
        }

        $fechaVencimiento = !empty($datos['fecha_vencimiento']) ? trim($datos['fecha_vencimiento']) : null;
        if (!$fechaVencimiento || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaVencimiento)) {
            // Por defecto 30 días
            $fechaVencimiento = date('Y-m-d', strtotime('+30 days'));
        }

        if (strtotime($fechaVencimiento) < strtotime(date('Y-m-d'))) {
            throw new Exception("La fecha de vencimiento no puede ser anterior al día de hoy.");
        }

        $clienteId = !empty($datos['cliente_id']) ? trim($datos['cliente_id']) : null;
        if (!empty($clienteId)) {
            // Verificar que exista en identidad.clientes
            $chkCl = $pdo->prepare("SELECT COUNT(*) FROM identidad.clientes WHERE persona_id = ?");
            $chkCl->execute([$clienteId]);
            if ($chkCl->fetchColumn() == 0) {
                $clienteId = null;
            }
        }

        $facturaOrigenId = !empty($datos['factura_origen_id']) ? intval($datos['factura_origen_id']) : null;
        if ($facturaOrigenId) {
            $chkFac = $pdo->prepare("SELECT COUNT(*) FROM ventas.facturas WHERE id = ?");
            $chkFac->execute([$facturaOrigenId]);
            if ($chkFac->fetchColumn() == 0) {
                $facturaOrigenId = null;
            }
        }

        // Insertar en la tabla
        $stmtIns = $pdo->prepare("
            INSERT INTO promociones.cupones_descuento
            (codigo_cupon, promocion_id, factura_origen_id, factura_canje_id, cliente_id, monto_credito, fecha_vencimiento, estado)
            VALUES (?, ?, ?, NULL, ?, ?, ?, 'DISPONIBLE')
            RETURNING id
        ");
        $stmtIns->execute([
            $codigoCupon,
            $promocionId,
            $facturaOrigenId,
            $clienteId,
            $montoCredito,
            $fechaVencimiento
        ]);
        $nuevoId = $stmtIns->fetchColumn();

        responder('success', "Cupón [{$codigoCupon}] emitido exitosamente por valor de RD$ " . number_format($montoCredito, 2) . ".", [
            'cupon_id' => $nuevoId,
            'codigo_cupon' => $codigoCupon,
            'monto_credito' => $montoCredito,
            'fecha_vencimiento' => $fechaVencimiento
        ]);

    } catch (Throwable $e) {
        responder('error', 'Error al emitir el cupón: ' . $e->getMessage());
    }
}

// ACCIÓN: ANULAR CUPÓN
if ($accion === 'anular') {
    try {
        $cuponId = intval($datos['cupon_id'] ?? 0);
        if ($cuponId <= 0) {
            throw new Exception("Identificador de cupón inválido.");
        }

        $stmtSel = $pdo->prepare("SELECT codigo_cupon, estado FROM promociones.cupones_descuento WHERE id = ?");
        $stmtSel->execute([$cuponId]);
        $row = $stmtSel->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception("El cupón solicitado no existe.");
        }

        if ($row['estado'] === 'USADO') {
            throw new Exception("No es posible anular el cupón [{$row['codigo_cupon']}] porque ya fue canjeado en una factura de venta.");
        }

        if ($row['estado'] === 'ANULADO') {
            throw new Exception("El cupón ya se encuentra anulado.");
        }

        $stmtUpd = $pdo->prepare("UPDATE promociones.cupones_descuento SET estado = 'ANULADO' WHERE id = ?");
        $stmtUpd->execute([$cuponId]);

        responder('success', "El cupón [{$row['codigo_cupon']}] fue anulado exitosamente.", [
            'cupon_id' => $cuponId,
            'nuevo_estado' => 'ANULADO'
        ]);
    } catch (Throwable $e) {
        responder('error', $e->getMessage());
    }
}

responder('error', 'Acción no reconocida en el procesador de cupones.');
