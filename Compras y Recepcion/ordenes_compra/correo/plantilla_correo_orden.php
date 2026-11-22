<?php
/**
 * ============================================================================
 * Plantilla de Correo HTML: Solicitud de Orden de Compra
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/ordenes_compra/correo/plantilla_correo_orden.php
 * ============================================================================
 */

function generarPlantillaHtmlOrdenCompra(array $datosOrden, array $lineasDetalle, array $datosProveedor): string
{
    $numeroOrden    = htmlspecialchars($datosOrden['numero_orden'] ?? 'OC-S/N');
    $fechaEmision   = !empty($datosOrden['fecha_emision']) ? date('d/m/Y', strtotime($datosOrden['fecha_emision'])) : date('d/m/Y');
    $fechaEsperada  = !empty($datosOrden['fecha_esperada']) ? date('d/m/Y', strtotime($datosOrden['fecha_esperada'])) : 'A convenir';
    $proveedorNom   = htmlspecialchars($datosProveedor['nombre_razon_social'] ?? 'Estimado Proveedor');
    $proveedorRnc   = htmlspecialchars($datosProveedor['documento_identidad'] ?? 'S/RNC');
    $montoTotal     = (float)($datosOrden['monto_total_estimado'] ?? 0);
    $totalUnidades  = 0;

    $filasHtml = '';
    foreach ($lineasDetalle as $idx => $linea) {
        $cant = (int)($linea['cantidad_ordenada'] ?? 0);
        $costo = (float)($linea['costo_pactado_unitario'] ?? 0);
        $subtotal = $cant * $costo;
        $totalUnidades += $cant;

        $nomProd = htmlspecialchars($linea['producto_nombre'] ?? $linea['nombre'] ?? 'Producto');
        $skuProd = htmlspecialchars($linea['sku'] ?? '');
        $bgFila = ($idx % 2 === 0) ? '#ffffff' : '#fcfaf6';

        $filasHtml .= "
            <tr style='background-color: {$bgFila};'>
                <td style='padding: 10px 12px; border-bottom: 1px solid #ebdcc5; font-size: 13px; color: #2b221a;'>
                    <strong>{$nomProd}</strong><br>
                    <span style='font-family: monospace; font-size: 11px; color: #7f6e5e;'>SKU: {$skuProd}</span>
                </td>
                <td style='padding: 10px 12px; border-bottom: 1px solid #ebdcc5; text-align: center; font-size: 13px; font-weight: bold; color: #211813;'>
                    {$cant}
                </td>
                <td style='padding: 10px 12px; border-bottom: 1px solid #ebdcc5; text-align: right; font-size: 13px; font-family: monospace; color: #4a3a2d;'>
                    RD$ " . number_format($costo, 2) . "
                </td>
                <td style='padding: 10px 12px; border-bottom: 1px solid #ebdcc5; text-align: right; font-size: 13px; font-family: monospace; font-weight: bold; color: #a66e2e;'>
                    RD$ " . number_format($subtotal, 2) . "
                </td>
            </tr>
        ";
    }

    $montoTotalFormateado = number_format($montoTotal, 2);

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orden de Compra {$numeroOrden}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f5f0e8; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #33261d;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f5f0e8; padding: 25px 10px;">
        <tr>
            <td align="center">
                <!-- Tarjeta Principal -->
                <table role="presentation" width="620" cellspacing="0" cellpadding="0" style="max-width: 620px; width: 100%; background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 18px rgba(45,30,18,0.08); border: 1px solid #e5d8c3;">
                    
                    <!-- Encabezado Institucional -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #1f1712 0%, #2f2015 100%); padding: 26px 30px; text-align: left; border-bottom: 3px solid #a66e2e;">
                            <div style="font-size: 22px; font-weight: bold; letter-spacing: 1.5px; color: #ffffff; font-family: Georgia, serif;">
                                HOUSE &amp; ICE
                            </div>
                            <div style="font-size: 11px; letter-spacing: 1px; color: #d4a96a; text-transform: uppercase; margin-top: 3px;">
                                Sistema Administrativo • Departamento de Compras
                            </div>
                        </td>
                    </tr>

                    <!-- Cuerpo -->
                    <tr>
                        <td style="padding: 26px 30px;">
                            <h2 style="margin: 0 0 10px 0; font-size: 18px; color: #211813; font-family: Georgia, serif;">
                                Solicitud de Pedido: Orden de Compra <span style="color: #a66e2e;">{$numeroOrden}</span>
                            </h2>
                            <p style="margin: 0 0 18px 0; font-size: 13.5px; line-height: 1.5; color: #5a4b3d;">
                                Estimados señores de <strong>{$proveedorNom}</strong> (RNC: {$proveedorRnc}), por medio de la presente formalizamos la solicitud de los siguientes productos según las condiciones acordadas.
                            </p>

                            <!-- Cuadro de Metadatos -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #faf6ee; border: 1px solid #ebdcc5; border-radius: 6px; margin-bottom: 20px;">
                                <tr>
                                    <td style="padding: 10px 14px; font-size: 12px; color: #6a5745; width: 50%;">
                                        <strong>Fecha de Emisión:</strong> {$fechaEmision}
                                    </td>
                                    <td style="padding: 10px 14px; font-size: 12px; color: #6a5745; width: 50%;">
                                        <strong>Fecha Esperada de Entrega:</strong> <span style="color: #a66e2e; font-weight: bold;">{$fechaEsperada}</span>
                                    </td>
                                </tr>
                            </table>

                            <!-- Tabla de Productos Solicitados -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse: collapse; margin-bottom: 18px; border: 1px solid #ebdcc5; border-radius: 6px; overflow: hidden;">
                                <thead>
                                    <tr style="background-color: #f1e6d3; color: #433120; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.5px;">
                                        <th style="padding: 10px 12px; text-align: left; border-bottom: 2px solid #d5c09f;">Producto</th>
                                        <th style="padding: 10px 12px; text-align: center; border-bottom: 2px solid #d5c09f; width: 60px;">Cant.</th>
                                        <th style="padding: 10px 12px; text-align: right; border-bottom: 2px solid #d5c09f; width: 110px;">Costo Pactado</th>
                                        <th style="padding: 10px 12px; text-align: right; border-bottom: 2px solid #d5c09f; width: 110px;">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {$filasHtml}
                                </tbody>
                                <tfoot>
                                    <tr style="background-color: #f6ecdc; font-weight: bold;">
                                        <td style="padding: 10px 12px; font-size: 13px; color: #3b2c1f;">Total de Unidades Solicitadas:</td>
                                        <td style="padding: 10px 12px; text-align: center; font-size: 13px; color: #3b2c1f;">{$totalUnidades}</td>
                                        <td style="padding: 10px 12px; text-align: right; font-size: 12px; color: #6e5a48; text-transform: uppercase;">Monto Estimado:</td>
                                        <td style="padding: 10px 12px; text-align: right; font-size: 14px; font-family: monospace; color: #a66e2e;">RD$ {$montoTotalFormateado}</td>
                                    </tr>
                                </tfoot>
                            </table>

                            <!-- Nota final -->
                            <p style="margin: 0; font-size: 12px; line-height: 1.5; color: #7a6a5b; background-color: #fcfbf9; padding: 10px 12px; border-left: 3px solid #a66e2e; border-radius: 4px;">
                                <strong>Documento Adjunto:</strong> Hemos adjuntado el archivo oficial en formato <strong>PDF</strong> con el detalle completo de esta orden de compra para su archivo y procesamiento.<br>
                                Favor confirmar la recepción del pedido y coordinar la entrega en almacén.
                            </p>
                        </td>
                    </tr>

                    <!-- Pie de página -->
                    <tr>
                        <td style="background-color: #f9f5ed; padding: 16px 30px; text-align: center; font-size: 11.5px; color: #8a7867; border-top: 1px solid #ebdcc5;">
                            Mensaje institucional emitido por <strong>House &amp; Ice - Sistema Administrativo</strong>.<br>
                            Para cualquier consulta respecto a esta orden, por favor responda directamente a este correo.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}
