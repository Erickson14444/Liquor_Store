<?php
/**
 * ============================================================================
 * Generador de PDF de Orden de Compra (Nativo PHP sin dependencias)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/ordenes_compra/correo/generador_pdf_orden.php
 * ============================================================================
 */

require_once __DIR__ . '/MiniPdfOrdenCompra.php';

function generarPdfOrdenCompra(array $datosOrden, array $lineasDetalle, array $datosProveedor): string
{
    $pdf = new MiniPdfOrdenCompra();

    $numeroOrden   = (string)($datosOrden['numero_orden'] ?? 'OC-S/N');
    $fechaEmision  = !empty($datosOrden['fecha_emision']) ? date('d/m/Y', strtotime($datosOrden['fecha_emision'])) : date('d/m/Y');
    $fechaEsperada = !empty($datosOrden['fecha_esperada']) ? date('d/m/Y', strtotime($datosOrden['fecha_esperada'])) : 'A convenir';
    $proveedorNom  = (string)($datosProveedor['nombre_razon_social'] ?? 'Proveedor General');
    $proveedorDoc  = (string)($datosProveedor['documento_identidad'] ?? 'S/RNC');
    $proveedorMail = (string)($datosProveedor['email'] ?? 'No especificado');
    $proveedorTel  = (string)($datosProveedor['telefono_principal'] ?? 'No especificado');
    $montoTotal    = (float)($datosOrden['monto_total_estimado'] ?? 0);

    // 1. Encabezado Superior (Banda Oscura y Línea Dorada)
    $pdf->rectanguloRelleno(36, 36, 540, 56, 0.13, 0.09, 0.07); // Fondo chocolate oscuro
    $pdf->rectanguloRelleno(36, 92, 540, 3, 0.65, 0.43, 0.18);  // Línea dorada

    // Textos de Encabezado
    $pdf->texto(50, 58, "HOUSE & ICE", 'F2', 18, 1, 1, 1);
    $pdf->texto(50, 75, "SISTEMA ADMINISTRATIVO  |  GESTIÓN DE COMPRAS", 'F1', 8, 0.85, 0.72, 0.52);

    $pdf->textoDerecha(520, 58, "ORDEN DE COMPRA", 'F2', 13, 0.85, 0.72, 0.52, 0.68);
    $pdf->textoDerecha(520, 75, $numeroOrden, 'F2', 11.5, 1, 1, 1, 0.62);

    // 2. Bloque de Datos (Proveedor a la izquierda, Fechas a la derecha)
    $yBloque = 108;
    $pdf->rectanguloRelleno(36, $yBloque, 540, 76, 0.98, 0.97, 0.95);
    $pdf->rectanguloBorde(36, $yBloque, 540, 76, 0.88, 0.82, 0.74, 1.0);

    // Columna Izquierda: Proveedor
    $pdf->texto(48, $yBloque + 18, "DATOS DEL PROVEEDOR / SUPLIDOR:", 'F2', 8, 0.65, 0.43, 0.18);
    $pdf->texto(48, $yBloque + 34, mb_strimwidth($proveedorNom, 0, 48, "..."), 'F2', 11, 0.13, 0.09, 0.07);
    $pdf->texto(48, $yBloque + 49, "RNC / Identificación: {$proveedorDoc}", 'F1', 9, 0.35, 0.28, 0.22);
    $pdf->texto(48, $yBloque + 63, "Teléfono: {$proveedorTel}   Email: {$proveedorMail}", 'F1', 8.5, 0.45, 0.38, 0.32);

    // Columna Derecha: Metadatos de la Orden
    $pdf->texto(360, $yBloque + 18, "DETALLES DE LA SOLICITUD:", 'F2', 8, 0.65, 0.43, 0.18);
    $pdf->texto(360, $yBloque + 34, "Fecha de Emisión:  {$fechaEmision}", 'F1', 9, 0.25, 0.20, 0.15);
    $pdf->texto(360, $yBloque + 49, "Fecha Esperada:   {$fechaEsperada}", 'F2', 9, 0.65, 0.43, 0.18);
    $pdf->texto(360, $yBloque + 63, "Moneda: Pesos Dominicanos (RD$)", 'F1', 8.5, 0.45, 0.38, 0.32);

    // 3. Tabla de Productos Solicitados
    $yTabla = 196;
    $altoCabecera = 22;

    // Cabecera de la tabla
    $pdf->rectanguloRelleno(36, $yTabla, 540, $altoCabecera, 0.93, 0.88, 0.80);
    $pdf->rectanguloBorde(36, $yTabla, 540, $altoCabecera, 0.82, 0.74, 0.64, 1.0);

    $pdf->texto(44, $yTabla + 15, "SKU / CÓDIGO", 'F2', 8, 0.25, 0.18, 0.12);
    $pdf->texto(130, $yTabla + 15, "DESCRIPCIÓN DEL PRODUCTO", 'F2', 8, 0.25, 0.18, 0.12);
    $pdf->textoDerecha(385, $yTabla + 15, "CANT.", 'F2', 8, 0.25, 0.18, 0.12);
    $pdf->textoDerecha(470, $yTabla + 15, "COSTO PACTADO", 'F2', 8, 0.25, 0.18, 0.12);
    $pdf->textoDerecha(566, $yTabla + 15, "SUBTOTAL (RD$)", 'F2', 8, 0.25, 0.18, 0.12);

    // Filas de detalle
    $yFila = $yTabla + $altoCabecera;
    $altoFila = 22;
    $totalUnidades = 0;

    foreach ($lineasDetalle as $idx => $linea) {
        $sku = (string)($linea['sku'] ?? 'N/A');
        $nombre = (string)($linea['producto_nombre'] ?? $linea['nombre'] ?? 'Producto');
        $cant = (int)($linea['cantidad_ordenada'] ?? 0);
        $costo = (float)($linea['costo_pactado_unitario'] ?? 0);
        $subtotal = $cant * $costo;
        $totalUnidades += $cant;

        // Color alterno
        if ($idx % 2 === 1) {
            $pdf->rectanguloRelleno(36, $yFila, 540, $altoFila, 0.98, 0.97, 0.95);
        }
        $pdf->rectanguloBorde(36, $yFila, 540, $altoFila, 0.90, 0.85, 0.78, 0.5);

        $pdf->texto(44, $yFila + 15, mb_strimwidth($sku, 0, 14, ""), 'F1', 8.5, 0.40, 0.32, 0.25);
        $pdf->texto(130, $yFila + 15, mb_strimwidth($nombre, 0, 42, "..."), 'F2', 8.5, 0.15, 0.10, 0.08);
        $pdf->textoDerecha(385, $yFila + 15, (string)$cant, 'F2', 9, 0.15, 0.10, 0.08);
        $pdf->textoDerecha(470, $yFila + 15, number_format($costo, 2), 'F1', 8.5, 0.30, 0.25, 0.20);
        $pdf->textoDerecha(566, $yFila + 15, number_format($subtotal, 2), 'F2', 9, 0.65, 0.43, 0.18);

        $yFila += $altoFila;
        if ($yFila > 640) break; // Límite de página por ahora
    }

    // 4. Bloque de Totales
    $yTotales = $yFila + 6;
    $pdf->rectanguloRelleno(310, $yTotales, 266, 44, 0.96, 0.92, 0.86);
    $pdf->rectanguloBorde(310, $yTotales, 266, 44, 0.78, 0.65, 0.48, 1.0);

    $pdf->texto(320, $yTotales + 18, "TOTAL UNIDADES:", 'F1', 8.5, 0.40, 0.32, 0.25);
    $pdf->textoDerecha(564, $yTotales + 18, "{$totalUnidades} und.", 'F2', 9.5, 0.20, 0.15, 0.10);

    $pdf->texto(320, $yTotales + 36, "MONTO TOTAL ESTIMADO:", 'F2', 9.5, 0.20, 0.15, 0.10);
    $pdf->textoDerecha(564, $yTotales + 36, "RD$ " . number_format($montoTotal, 2), 'F2', 12, 0.65, 0.43, 0.18);

    // 5. Términos y Condiciones
    $yNotas = $yTotales + 56;
    $pdf->rectanguloRelleno(36, $yNotas, 540, 52, 0.99, 0.99, 0.98);
    $pdf->rectanguloBorde(36, $yNotas, 540, 52, 0.88, 0.82, 0.74, 0.75);

    $pdf->texto(46, $yNotas + 15, "CONDICIONES DE ENTREGA Y FACTURACIÓN:", 'F2', 8, 0.65, 0.43, 0.18);
    $pdf->texto(46, $yNotas + 28, "1. Las mercancías deben venir acompañadas de su Factura con Comprobante Fiscal (NCF válido para crédito fiscal).", 'F1', 7.5, 0.40, 0.34, 0.28);
    $pdf->texto(46, $yNotas + 39, "2. Todo lote de licores o cervezas debe contar con fecha de vencimiento y precintos oficiales en perfecto estado.", 'F1', 7.5, 0.40, 0.34, 0.28);

    // 6. Espacios para Firmas
    $yFirmas = $yNotas + 72;
    $pdf->linea(60, $yFirmas, 240, $yFirmas, 0.65, 0.58, 0.50, 1.0);
    $pdf->texto(85, $yFirmas + 14, "Firma y Sello Compras", 'F1', 8, 0.50, 0.42, 0.36);
    $pdf->texto(105, $yFirmas + 24, "HOUSE & ICE", 'F2', 8, 0.65, 0.43, 0.18);

    $pdf->linea(372, $yFirmas, 552, $yFirmas, 0.65, 0.58, 0.50, 1.0);
    $pdf->texto(395, $yFirmas + 14, "Firma de Recibido / Conforme", 'F1', 8, 0.50, 0.42, 0.36);
    $pdf->texto(425, $yFirmas + 24, "PROVEEDOR", 'F2', 8, 0.65, 0.43, 0.18);

    // 7. Pie de Página Institucional
    $pdf->texto(36, 765, "Documento oficial generado por House & Ice - Sistema Administrativo.", 'F1', 7.5, 0.55, 0.48, 0.42);
    $pdf->textoDerecha(576, 765, "Página 1 de 1", 'F1', 7.5, 0.55, 0.48, 0.42);

    return $pdf->compilar();
}
