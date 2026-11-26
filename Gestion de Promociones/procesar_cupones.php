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
$datos = is_array($jsonBody) ? $jsonBody : $_POST;

$isAjax = is_array($jsonBody) ||
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($datos['format']) && $datos['format'] === 'json') ||
    (isset($_GET['format']) && $_GET['format'] === 'json');

function responderJson($status, $mensaje, $extra = [])
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['status' => $status, 'message' => $mensaje], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function responder($status, $mensaje, $extra = [])
{
    global $isAjax;
    if ($isAjax) {
        responderJson($status, $mensaje, $extra);
    } else {
        $tipo = ($status === 'success') ? 'exito' : 'error';
        header("Location: cupones.php?msg=" . urlencode($mensaje) . "&tipo=" . $tipo);
        exit;
    }
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$accion = $datos['accion'] ?? $datos['action'] ?? $_GET['accion'] ?? $_GET['action'] ?? '';

if (!$pdo) {
    responder('error', 'Error crítico: No hay conexión con la base de datos PostgreSQL.');
}

// Mantenimiento preventivo: auto-actualizar cupones vencidos
try {
    $pdo->exec("UPDATE promociones.cupones_descuento SET estado = 'VENCIDO' WHERE estado = 'DISPONIBLE' AND fecha_vencimiento < CURRENT_DATE");
} catch (Throwable $e) {
}

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
                   COALESCE(camp.nombre_promocion, 'Campaña Promocional') AS campana_nombre,
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

// BLOQUEO EXPLÍCITO DE EMISIÓN MANUAL DE CUPONES
if ($accion === 'emitir' || $accion === 'crear') {
    responder('error', 'La emisión manual de cupones está deshabilitada. Los cupones solo se generan automáticamente desde campañas promocionales en Facturación.');
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