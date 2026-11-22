<?php
/**
 * ============================================================================
 * Despachador de Correo: Envío de Orden de Compra al Proveedor
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/ordenes_compra/correo/enviar_correo_orden.php
 * ============================================================================
 */

require_once __DIR__ . '/../../../config/conexion.php';
require_once __DIR__ . '/../../../config/correo_mailer.php';
require_once __DIR__ . '/plantilla_correo_orden.php';
require_once __DIR__ . '/../compra/generador_pdf_orden.php';

/**
 * Envía la Orden de Compra por correo al proveedor correspondiente con documento PDF adjunto
 * 
 * @param PDO $pdo Conexión activa a PostgreSQL
 * @param int $ordenId ID de la orden de compra recién creada o seleccionada
 * @return array ['ok' => bool, 'mensaje' => string, 'correo_destinatario' => string]
 */
function enviarCorreoOrdenAlProveedor(PDO $pdo, int $ordenId): array
{
    try {
        if ($ordenId <= 0) {
            return ['ok' => false, 'mensaje' => 'ID de orden no válido.'];
        }

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
            return ['ok' => false, 'mensaje' => 'La orden de compra no fue encontrada en el sistema.'];
        }

        $emailProveedor = trim($orden['email'] ?? '');
        if (empty($emailProveedor)) {
            return [
                'ok' => false,
                'mensaje' => "El proveedor '{$orden['nombre_razon_social']}' no tiene un correo electrónico registrado en su ficha.",
                'correo_destinatario' => ''
            ];
        }

        // 2. Consultar Líneas de Detalle de la Orden
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

        if (empty($detalles)) {
            return ['ok' => false, 'mensaje' => 'La orden no tiene productos asociados.'];
        }

        // 3. Generar Contenido HTML del Correo
        $htmlContenido = generarPlantillaHtmlOrdenCompra($orden, $detalles, [
            'nombre_razon_social' => $orden['nombre_razon_social'],
            'documento_identidad' => $orden['documento_identidad']
        ]);

        // 4. Generar Documento PDF Oficial de la Orden de Compra para Adjunto
        $pdfBinario = generarPdfOrdenCompra($orden, $detalles, [
            'nombre_razon_social' => $orden['nombre_razon_social'],
            'documento_identidad' => $orden['documento_identidad'],
            'email' => $emailProveedor,
            'telefono_principal' => $orden['telefono_principal'] ?? ''
        ]);

        $nombreArchivoPdf = "Orden_Compra_" . preg_replace('/[^A-Za-z0-9_-]/', '_', $orden['numero_orden']) . ".pdf";

        $adjuntos = [
            [
                'nombre' => $nombreArchivoPdf,
                'contenido' => $pdfBinario,
                'tipo' => 'application/pdf'
            ]
        ];

        $asunto = "Orden de Compra {$orden['numero_orden']} - House & Ice";

        // 5. Enviar mediante el Mailer SMTP Autenticado con PDF adjunto y cabeceras anti-spam
        $mailer = new CorreoMailer();
        $resultado = $mailer->enviarHtml(
            $emailProveedor,
            $orden['nombre_razon_social'],
            $asunto,
            $htmlContenido,
            'House & Ice',
            $adjuntos
        );

        $resultado['correo_destinatario'] = $emailProveedor;
        return $resultado;

    } catch (Throwable $e) {
        return [
            'ok' => false,
            'mensaje' => "Excepción al procesar correo: " . $e->getMessage()
        ];
    }
}
