<?php
/**
 * ============================================================================
 * Visor / Descarga Directa de PDF de Orden de Compra
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/ordenes_compra/compra/ver_pdf_orden.php
 * ============================================================================
 */

require_once __DIR__ . '/../../../config/conexion.php';
require_once __DIR__ . '/generador_pdf_orden.php';

// Validar sesión
if (empty($_SESSION['usuario']['usuario_id'])) {
    http_response_code(403);
    echo "Acceso denegado. Inicie sesión en el sistema.";
    exit;
}

$ordenId = (int)($_GET['id'] ?? $_GET['orden_id'] ?? 0);
if ($ordenId <= 0) {
    http_response_code(400);
    echo "ID de orden no especificado o inválido.";
    exit;
}

try {
    // 1. Consultar Cabecera de la Orden y Proveedor
    $stmtOrd = $pdo->prepare("
        SELECT oc.id,
               oc.numero_orden,
               oc.monto_total_estimado,
               oc.fecha_emision,
               oc.fecha_esperada,
               p.nombre_razon_social,
               p.documento_identidad,
               p.email,
               p.telefono_principal
        FROM compras.ordenes_compra oc
        JOIN identidad.proveedores pr ON pr.persona_id = oc.proveedor_id
        JOIN identidad.personas p ON p.persona_id = pr.persona_id
        WHERE oc.id = ?
        LIMIT 1
    ");
    $stmtOrd->execute([$ordenId]);
    $orden = $stmtOrd->fetch(PDO::FETCH_ASSOC);

    if (!$orden) {
        http_response_code(404);
        echo "La orden de compra no fue encontrada.";
        exit;
    }

    // 2. Consultar Detalles de Productos
    $stmtDet = $pdo->prepare("
        SELECT d.cantidad_ordenada,
               d.costo_pactado_unitario,
               pm.sku,
               COALESCE(
                   NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                   (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                   pm.sku
               ) AS producto_nombre
        FROM compras.orden_compra_detalles d
        JOIN inventario.productos_maestro pm ON pm.id = d.producto_maestro_id
        WHERE d.orden_compra_id = ?
        ORDER BY d.producto_maestro_id ASC
    ");
    $stmtDet->execute([$ordenId]);
    $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 3. Generar PDF
    $pdfBinario = generarPdfOrdenCompra($orden, $detalles, [
        'nombre_razon_social' => $orden['nombre_razon_social'],
        'documento_identidad' => $orden['documento_identidad'],
        'email' => $orden['email'] ?? '',
        'telefono_principal' => $orden['telefono_principal'] ?? ''
    ]);

    $nombreArchivoPdf = "Orden_Compra_" . preg_replace('/[^A-Za-z0-9_-]/', '_', $orden['numero_orden']) . ".pdf";

    // 4. Enviar PDF al navegador (inline para previsualización directa)
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $nombreArchivoPdf . '"');
    header('Content-Length: ' . strlen($pdfBinario));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $pdfBinario;
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo "Error al generar el PDF de la orden: " . $e->getMessage();
    exit;
}
