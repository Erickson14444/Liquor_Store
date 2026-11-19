<?php
/**
 * Controlador Backend: Proceso de Facturación (POS)
 * Ubicación: c:\xampp\htdocs\Liquor_Store\Ventas y Facturacion\Facturacion\procesar_facturacion.php
 * House & Ice • Sistema Administrativo
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/conexion.php';

if (!$pdo) {
    echo json_encode([
        'ok'      => false,
        'mensaje' => 'Error de conexión con la base de datos.'
    ]);
    exit;
}

// Aceptar tanto JSON body como POST tradicional
$rawInput = file_get_contents('php://input');
$jsonBody = json_decode($rawInput, true);
$datos    = is_array($jsonBody) ? $jsonBody : $_POST;
$accion   = trim($datos['accion'] ?? $_GET['accion'] ?? '');

try {

    // =========================================================================
    // ACCIÓN 1: OBTENER DETALLE DE UNA COTIZACIÓN PENDIENTE PARA IMPORTAR
    // =========================================================================
    if ($accion === 'obtener_cotizacion') {
        $cotizacionId = (int)($datos['cotizacion_id'] ?? $_GET['cotizacion_id'] ?? 0);
        if ($cotizacionId <= 0) {
            throw new Exception('Identificador de cotización inválido.');
        }

        $stmtCab = $pdo->prepare("
            SELECT cot.id,
                   cot.numero_cotizacion,
                   cot.cliente_id,
                   p.nombre_razon_social AS cliente_nombre,
                   p.documento_identidad AS cliente_doc
            FROM ventas.cotizaciones cot
            INNER JOIN identidad.clientes c ON cot.cliente_id = c.persona_id
            INNER JOIN identidad.personas p ON c.persona_id = p.persona_id
            WHERE cot.id = ? AND cot.estado = 'PENDIENTE'
            LIMIT 1
        ");
        $stmtCab->execute([$cotizacionId]);
        $cabecera = $stmtCab->fetch(PDO::FETCH_ASSOC);

        if (!$cabecera) {
            throw new Exception('La cotización seleccionada no existe o ya no está pendiente.');
        }

        $stmtDet = $pdo->prepare("
            SELECT cd.producto_maestro_id,
                   cd.codigo_barra_id,
                   cd.cantidad,
                   cd.precio_unitario,
                   cd.monto_descuento,
                   pm.sku,
                   COALESCE(
                       NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                       pcb.descripcion_presentacion,
                       pm.sku
                   ) AS producto_nombre,
                   COALESCE((
                       SELECT imp.tasa_o_monto
                       FROM inventario.producto_impuestos pi
                       JOIN tesoreria.impuestos imp ON pi.impuesto_id = imp.id
                       WHERE pi.producto_maestro_id = pm.id
                         AND imp.siglas IN ('ITBIS_18', 'ITBIS_16', 'EXENTO')
                       ORDER BY imp.id ASC
                       LIMIT 1
                   ), 18.00) AS porcentaje_itbis,
                   pm.costo_promedio,
                   COALESCE(pcb.descripcion_presentacion, 'Unidad Individual') AS descripcion_presentacion,
                   COALESCE(pcb.factor_multiplicador, 1) AS factor_conversion
            FROM ventas.cotizaciones_detalle cd
            INNER JOIN inventario.productos_maestro pm ON cd.producto_maestro_id = pm.id
            LEFT JOIN inventario.producto_codigos_barra pcb ON cd.codigo_barra_id = pcb.id
            WHERE cd.cotizacion_id = ?
            ORDER BY pm.sku ASC
        ");
        $stmtDet->execute([$cotizacionId]);
        $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'ok'         => true,
            'cotizacion' => $cabecera,
            'detalles'   => $detalles,
        ]);
        exit;
    }

    // =========================================================================
    // ACCIÓN 2: CREAR CLIENTE RÁPIDO (+ CLIENTE) DESDE FACTURACIÓN
    // =========================================================================
    if ($accion === 'crear_cliente_rapido') {
        $tipoDocumento = strtoupper(trim($datos['tipo_documento'] ?? 'CEDULA'));
        $documento     = trim($datos['documento_identidad'] ?? '');
        $nombre        = trim($datos['nombre_razon_social'] ?? '');
        $telefono      = trim($datos['telefono_principal'] ?? '');
        $categoria     = strtoupper(trim($datos['categoria_cliente'] ?? 'REGULAR'));

        if (!in_array($tipoDocumento, ['CEDULA', 'RNC', 'PASAPORTE'], true)) {
            $tipoDocumento = 'CEDULA';
        }
        if (!in_array($categoria, ['REGULAR', 'VIP', 'MAYORISTA'], true)) {
            $categoria = 'REGULAR';
        }
        if ($documento === '' || $nombre === '') {
            throw new Exception('El número de documento y el nombre o razón social son obligatorios.');
        }

        $tipoPersona = ($tipoDocumento === 'RNC') ? 'JURIDICA' : 'FISICA';

        $pdo->beginTransaction();

        // Verificar si la persona ya existe por documento
        $stmtPer = $pdo->prepare("
            SELECT persona_id, nombre_razon_social, tipo_documento, documento_identidad
            FROM identidad.personas
            WHERE documento_identidad = ?
            LIMIT 1
        ");
        $stmtPer->execute([$documento]);
        $personaRow = $stmtPer->fetch(PDO::FETCH_ASSOC);

        if ($personaRow) {
            $personaId = $personaRow['persona_id'];
            // Verificar si ya está en identidad.clientes
            $stmtChkCli = $pdo->prepare("SELECT persona_id FROM identidad.clientes WHERE persona_id = ? LIMIT 1");
            $stmtChkCli->execute([$personaId]);
            if ($stmtChkCli->fetchColumn()) {
                $pdo->rollBack();
                throw new Exception('Este cliente ya se encuentra registrado en el sistema.');
            }
        } else {
            $stmtInsPer = $pdo->prepare("
                INSERT INTO identidad.personas (
                    tipo_documento, documento_identidad, nombre_razon_social,
                    telefono_principal, tipo_persona, estado
                ) VALUES (?, ?, ?, ?, ?, TRUE)
                RETURNING persona_id
            ");
            $stmtInsPer->execute([
                $tipoDocumento,
                $documento,
                $nombre,
                $telefono !== '' ? $telefono : null,
                $tipoPersona
            ]);
            $personaId = $stmtInsPer->fetchColumn();
        }

        // Generar código de cliente correlativo CLI-XXX
        $stmtMax = $pdo->query("
            SELECT COALESCE(MAX(CAST(SUBSTRING(codigo_cliente FROM '[0-9]+$') AS INTEGER)), 0) + 1
            FROM identidad.clientes
            WHERE codigo_cliente ~ '^CLI-[0-9]+$'
        ");
        $nextNum = (int)$stmtMax->fetchColumn();
        if ($nextNum <= 0) {
            $nextNum = 1;
        }
        $codigoCliente = 'CLI-' . str_pad((string)$nextNum, 3, '0', STR_PAD_LEFT);

        $stmtInsCli = $pdo->prepare("
            INSERT INTO identidad.clientes (
                persona_id, codigo_cliente, categoria_cliente, limite_credito, permite_credito
            ) VALUES (?, ?, ?, 0.00, FALSE)
        ");
        $stmtInsCli->execute([$personaId, $codigoCliente, $categoria]);

        $pdo->commit();

        echo json_encode([
            'ok'      => true,
            'mensaje' => "Cliente «{$nombre}» registrado y seleccionado.",
            'cliente' => [
                'cliente_id'          => $personaId,
                'codigo_cliente'      => $codigoCliente,
                'nombre_razon_social' => $nombre,
                'tipo_documento'      => $tipoDocumento,
                'documento_identidad' => $documento,
                'permite_credito'     => false,
                'limite_credito'      => 0,
            ]
        ]);
        exit;
    }

    // =========================================================================
    // ACCIÓN 3: GUARDAR FACTURA COMPLETA EN TRANSACCIÓN
    // =========================================================================
    if ($accion === 'guardar_factura') {
        $partidas = $datos['partidas'] ?? [];
        if (!is_array($partidas) || empty($partidas)) {
            throw new Exception('El carrito está vacío. Agrega al menos un producto antes de guardar la factura.');
        }

        $clienteInput       = trim($datos['cliente_id'] ?? 'CONSUMIDOR_FINAL');
        $tipoNcf            = strtoupper(trim($datos['tipo_ncf'] ?? 'B02'));
        $condicionPago      = strtoupper(trim($datos['condicion_pago'] ?? 'CONTADO'));
        $metodoPagoInput    = trim((string)($datos['metodo_pago_id'] ?? ''));
        $origenVenta        = strtoupper(trim($datos['origen_venta'] ?? 'MOSTRADOR'));
        $montoRecibidoInput = (float)($datos['monto_recibido'] ?? 0);
        $referenciaPago     = trim($datos['referencia_pago'] ?? '');
        $cotizacionOrigen   = !empty($datos['cotizacion_origen']) ? (int)$datos['cotizacion_origen'] : null;

        // Datos si es Delivery
        $zonaDeliveryId     = !empty($datos['zona_id']) ? (int)$datos['zona_id'] : 0;
        $repartidorId       = trim($datos['repartidor_id'] ?? '');
        $direccionDestino   = trim($datos['direccion_destino'] ?? '');

        if (!in_array($tipoNcf, ['B01', 'B02', 'CONSUMO_INTERNO'], true)) {
            throw new Exception('El tipo de comprobante fiscal seleccionado no es válido.');
        }
        if (!in_array($condicionPago, ['CONTADO', 'CREDITO'], true)) {
            throw new Exception('La condición de pago seleccionada no es válida.');
        }
        if (!in_array($origenVenta, ['MOSTRADOR', 'DELIVERY'], true)) {
            $origenVenta = 'MOSTRADOR';
        }

        if ($origenVenta === 'DELIVERY') {
            if ($zonaDeliveryId <= 0 || $repartidorId === '' || $direccionDestino === '') {
                throw new Exception('Para facturar con Despacho Delivery debes seleccionar la Zona, el Repartidor y la Dirección de Destino.');
            }
        }

        $pdo->beginTransaction();

        // 1. Resolver vendedor_id (usuario autenticado o primer usuario activo)
        $vendedorId = $_SESSION['usuario']['usuario_id'] ?? null;
        if (empty($vendedorId)) {
            $vendedorId = $pdo->query("SELECT usuario_id FROM seguridad.usuarios WHERE estado = 'ACTIVO' ORDER BY creado_en ASC LIMIT 1")->fetchColumn();
        }
        if (empty($vendedorId)) {
            throw new Exception('No se encontró un usuario cajero/vendedor activo para emitir la factura.');
        }

        // 2. Resolver sesion_caja_id abierta (o crear una automáticamente para no bloquear el POS)
        $stmtSes = $pdo->prepare("
            SELECT id FROM ventas.sesiones_caja
            WHERE estado = 'ABIERTA'
            ORDER BY id DESC LIMIT 1
        ");
        $stmtSes->execute();
        $sesionCajaId = (int)$stmtSes->fetchColumn();

        if ($sesionCajaId <= 0) {
            // Buscar o crear una Caja POS
            $cajaPosId = (int)$pdo->query("SELECT id FROM ventas.cajas_pos ORDER BY id ASC LIMIT 1")->fetchColumn();
            if ($cajaPosId <= 0) {
                $stmtNewCaja = $pdo->query("
                    INSERT INTO ventas.cajas_pos (nombre_equipo, estado, ubicacion)
                    VALUES ('Caja Principal 01', 'ABIERTA', 'Mostrador Principal')
                    RETURNING id
                ");
                $cajaPosId = (int)$stmtNewCaja->fetchColumn();
            } else {
                $pdo->prepare("UPDATE ventas.cajas_pos SET estado = 'ABIERTA' WHERE id = ?")->execute([$cajaPosId]);
            }

            $stmtNewSes = $pdo->prepare("
                INSERT INTO ventas.sesiones_caja (caja_id, cajero_id, monto_inicial_efectivo, estado)
                VALUES (?, ?, 2000.00, 'ABIERTA')
                RETURNING id
            ");
            $stmtNewSes->execute([$cajaPosId, $vendedorId]);
            $sesionCajaId = (int)$stmtNewSes->fetchColumn();
        }

        // 3. Resolver cliente_id y datos fiscales del cliente
        $clienteUuid         = null;
        $rncCedulaCliente    = null;
        $razonSocialCliente  = null;

        if ($clienteInput === '' || $clienteInput === 'CONSUMIDOR_FINAL') {
            if ($condicionPago === 'CREDITO') {
                throw new Exception('Las ventas a Crédito requieren seleccionar un cliente registrado (no aplica para Consumidor Final).');
            }
            if ($tipoNcf === 'B01') {
                throw new Exception('El comprobante B01 (Crédito Fiscal) exige seleccionar un cliente con RNC o Cédula registrado.');
            }

            // Buscar o crear registro genérico de Consumidor Final en identidad.clientes
            $stmtCf = $pdo->query("
                SELECT c.persona_id, p.documento_identidad, p.nombre_razon_social
                FROM identidad.clientes c
                INNER JOIN identidad.personas p ON c.persona_id = p.persona_id
                WHERE c.codigo_cliente = 'CLI-000' OR p.documento_identidad = '00000000000'
                LIMIT 1
            ");
            $cfRow = $stmtCf->fetch(PDO::FETCH_ASSOC);

            if ($cfRow) {
                $clienteUuid        = $cfRow['persona_id'];
                $rncCedulaCliente   = null;
                $razonSocialCliente = 'Consumidor Final';
            } else {
                $stmtPerCf = $pdo->prepare("
                    SELECT persona_id FROM identidad.personas WHERE documento_identidad = '00000000000' LIMIT 1
                ");
                $stmtPerCf->execute();
                $perCfId = $stmtPerCf->fetchColumn();

                if (!$perCfId) {
                    $stmtInsPerCf = $pdo->query("
                        INSERT INTO identidad.personas (
                            tipo_documento, documento_identidad, nombre_razon_social, tipo_persona, estado
                        ) VALUES ('CEDULA', '00000000000', 'Consumidor Final', 'FISICA', TRUE)
                        RETURNING persona_id
                    ");
                    $perCfId = $stmtInsPerCf->fetchColumn();
                }

                $stmtInsCliCf = $pdo->prepare("
                    INSERT INTO identidad.clientes (
                        persona_id, codigo_cliente, categoria_cliente, limite_credito, permite_credito
                    ) VALUES (?, 'CLI-000', 'REGULAR', 0.00, FALSE)
                ");
                $stmtInsCliCf->execute([$perCfId]);
                $clienteUuid        = $perCfId;
                $rncCedulaCliente   = null;
                $razonSocialCliente = 'Consumidor Final';
            }
        } else {
            $stmtCliRow = $pdo->prepare("
                SELECT c.persona_id,
                       c.permite_credito,
                       c.limite_credito,
                       p.documento_identidad,
                       p.nombre_razon_social
                FROM identidad.clientes c
                INNER JOIN identidad.personas p ON c.persona_id = p.persona_id
                WHERE c.persona_id = ?
                LIMIT 1
            ");
            $stmtCliRow->execute([$clienteInput]);
            $cliData = $stmtCliRow->fetch(PDO::FETCH_ASSOC);

            if (!$cliData) {
                throw new Exception('El cliente seleccionado no fue encontrado.');
            }

            $clienteUuid        = $cliData['persona_id'];
            $rncCedulaCliente   = trim($cliData['documento_identidad'] ?? '') ?: null;
            $razonSocialCliente = trim($cliData['nombre_razon_social'] ?? '') ?: null;

            if ($tipoNcf === 'B01' && (empty($rncCedulaCliente) || empty($razonSocialCliente))) {
                throw new Exception('Para emitir un comprobante B01 (Crédito Fiscal), el cliente debe poseer RNC/Cédula y Razón Social válidos.');
            }
        }

        // 4. Consolidar y validar partidas del carrito por producto_maestro_id
        //    (La llave primaria de ventas.factura_detalle es (factura_id, producto_maestro_id))
        $partidasAgrupadas = [];
        foreach ($partidas as $item) {
            $pmId = (int)($item['producto_maestro_id'] ?? 0);
            $cant = (int)($item['cantidad'] ?? 0);
            if ($pmId <= 0 || $cant <= 0) {
                continue;
            }

            $stmtProdInfo = $pdo->prepare("
                SELECT pm.id, pm.sku, pm.nombre, pm.costo_promedio, pm.precio_base_unidad,
                       COALESCE((
                           SELECT imp.tasa_o_monto
                           FROM inventario.producto_impuestos pi
                           JOIN tesoreria.impuestos imp ON pi.impuesto_id = imp.id
                           WHERE pi.producto_maestro_id = pm.id
                             AND imp.siglas IN ('ITBIS_18', 'ITBIS_16', 'EXENTO')
                           ORDER BY imp.id ASC
                           LIMIT 1
                       ), 18.00) AS porcentaje_itbis,
                       COALESCE((
                           SELECT imp.id
                           FROM inventario.producto_impuestos pi
                           JOIN tesoreria.impuestos imp ON pi.impuesto_id = imp.id
                           WHERE pi.producto_maestro_id = pm.id
                             AND imp.siglas IN ('ITBIS_18', 'ITBIS_16', 'EXENTO')
                           ORDER BY imp.id ASC
                           LIMIT 1
                       ), 1) AS impuesto_id
                FROM inventario.productos_maestro pm
                WHERE pm.id = ?
                LIMIT 1
            ");
            $stmtProdInfo->execute([$pmId]);
            $prodDb = $stmtProdInfo->fetch(PDO::FETCH_ASSOC);
            if (!$prodDb) {
                throw new Exception("El producto con ID #{$pmId} no existe en el catálogo.");
            }

            $codBarraId  = !empty($item['codigo_barra_id']) ? (int)$item['codigo_barra_id'] : null;
            $factorConv  = max(1, (int)($item['factor_conversion'] ?? 1));
            $precioUnit  = max(0, (float)($item['precio_unitario'] ?? $prodDb['precio_base_unidad']));
            $porcItbis   = max(0, (float)($prodDb['porcentaje_itbis'] ?? 18.00));
            $impuestoId  = max(1, (int)($prodDb['impuesto_id'] ?? 1));
            $costoHist   = max(0, (float)($prodDb['costo_promedio'] ?? 0.00));
            $promoId     = !empty($item['promocion_id']) ? (int)$item['promocion_id'] : null;
            $descuentoLn = max(0, (float)($item['monto_descuento'] ?? 0.00));

            if (!isset($partidasAgrupadas[$pmId])) {
                $partidasAgrupadas[$pmId] = [
                    'producto_maestro_id'   => $pmId,
                    'codigo_barra_id'       => $codBarraId,
                    'factor_conversion'     => $factorConv,
                    'cantidad_presentacion' => $cant,
                    'cantidad_unidades'     => $cant * $factorConv,
                    'precio_unitario_his'   => $precioUnit,
                    'costo_unitario_hist'   => $costoHist,
                    'promocion_aplicada'    => $promoId,
                    'monto_descuento'       => $descuentoLn,
                    'porcentaje_itbis'      => $porcItbis,
                    'impuesto_id'           => $impuestoId,
                ];
            } else {
                // Si el mismo producto maestro se agregó dos veces, acumular unidades y presentaciones
                $partidasAgrupadas[$pmId]['cantidad_presentacion'] += $cant;
                $partidasAgrupadas[$pmId]['cantidad_unidades']     += ($cant * $factorConv);
                $partidasAgrupadas[$pmId]['monto_descuento']       += $descuentoLn;
            }
        }

        if (empty($partidasAgrupadas)) {
            throw new Exception('No hay líneas válidas para facturar.');
        }

        // 5. Calcular subtotales, ITBIS y total general con precisión fiscal
        $facturaSubtotal  = 0.00;
        $facturaDescuento = 0.00;
        $facturaItbis     = 0.00;
        $facturaTotal     = 0.00;

        foreach ($partidasAgrupadas as $pmId => &$linea) {
            $brutoLinea = round($linea['cantidad_presentacion'] * $linea['precio_unitario_his'], 2);
            $descLinea  = min($brutoLinea, round($linea['monto_descuento'], 2));
            $baseImpon  = max(0, $brutoLinea - $descLinea);

            // Cálculo de Neto + ITBIS según tasa del producto (18% por defecto)
            $tasaDecimal = $linea['porcentaje_itbis'] / 100.0;
            $netoLinea   = round($baseImpon / (1.0 + $tasaDecimal), 2);
            $itbisLinea  = round($baseImpon - $netoLinea, 2);
            $totalLinea  = round($netoLinea + $itbisLinea, 2);

            // En BD: monto_total = (monto_subtotal - monto_descuento) + monto_impuestos
            $subtotalAntesDesc = round($netoLinea + $descLinea, 2);

            $linea['base_imponible']  = $netoLinea;
            $linea['monto_subtotal']  = $subtotalAntesDesc;
            $linea['monto_descuento'] = $descLinea;
            $linea['monto_itbis']     = $itbisLinea;
            $linea['monto_total']     = $totalLinea;

            $facturaSubtotal  += $subtotalAntesDesc;
            $facturaDescuento += $descLinea;
            $facturaItbis     += $itbisLinea;
            $facturaTotal     += $totalLinea;
        }
        unset($linea);

        $facturaSubtotal  = round($facturaSubtotal, 2);
        $facturaDescuento = min($facturaSubtotal, round($facturaDescuento, 2));
        $facturaItbis     = round($facturaItbis, 2);
        $facturaTotal     = round($facturaTotal, 2);

        // 6. Obtener secuencia fiscal DGII si corresponde (B01 o B02)
        $secuenciaDgiiId = null;
        $ncfGenerado     = null;

        if (in_array($tipoNcf, ['B01', 'B02'], true)) {
            $stmtSec = $pdo->prepare("
                SELECT id, tipo_comprobante, secuencia_actual, secuencia_hasta
                FROM ventas.secuencias_fiscales_dgii
                WHERE tipo_comprobante = ?
                  AND estado = 'ACTIVO'
                  AND secuencia_actual <= secuencia_hasta
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmtSec->execute([$tipoNcf]);
            $secRow = $stmtSec->fetch(PDO::FETCH_ASSOC);

            if ($secRow) {
                $secuenciaDgiiId = (int)$secRow['id'];
                $numActual       = (int)$secRow['secuencia_actual'];
                $numHasta        = (int)$secRow['secuencia_hasta'];
                $ncfGenerado     = $tipoNcf . str_pad((string)$numActual, 8, '0', STR_PAD_LEFT);

                if ($numActual < $numHasta) {
                    $stmtUpdSec = $pdo->prepare("
                        UPDATE ventas.secuencias_fiscales_dgii
                        SET secuencia_actual = secuencia_actual + 1
                        WHERE id = ?
                    ");
                    $stmtUpdSec->execute([$secuenciaDgiiId]);
                } else {
                    $stmtUpdSec = $pdo->prepare("
                        UPDATE ventas.secuencias_fiscales_dgii
                        SET estado = 'AGOTADO'
                        WHERE id = ?
                    ");
                    $stmtUpdSec->execute([$secuenciaDgiiId]);
                }
            }
        }

        // 7. Generar número de factura único (FAC-YYYYMMDD-XXXX)
        $prefijoFac = 'FAC-' . date('Ymd') . '-';
        $stmtCountFac = $pdo->prepare("
            SELECT COUNT(*) + 1
            FROM ventas.facturas
            WHERE numero_factura LIKE ?
        ");
        $stmtCountFac->execute([$prefijoFac . '%']);
        $correlativoFac = (int)$stmtCountFac->fetchColumn();
        $numeroFactura  = $prefijoFac . str_pad((string)$correlativoFac, 4, '0', STR_PAD_LEFT);

        // Estado inicial de la factura
        $estadoFactura = 'EMITIDA';
        if ($origenVenta === 'DELIVERY') {
            $estadoFactura = 'EN_TRANSITO';
        } elseif ($condicionPago === 'CONTADO') {
            $estadoFactura = 'PAGADA';
        }

        // 8. Insertar cabecera en ventas.facturas
        $stmtInsFac = $pdo->prepare("
            INSERT INTO ventas.facturas (
                numero_factura, sesion_caja_id, cliente_id, vendedor_id,
                secuencia_dgii_id, tipo_ncf, ncf, condicion_pago,
                monto_subtotal, monto_descuento, monto_impuestos, monto_total,
                estado, cotizacion_origen, rnc_cedula_cliente, razon_social_cliente,
                origen_venta
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?
            )
            RETURNING id
        ");
        $stmtInsFac->execute([
            $numeroFactura,
            $sesionCajaId,
            $clienteUuid,
            $vendedorId,
            $secuenciaDgiiId,
            $tipoNcf,
            $ncfGenerado,
            $condicionPago,
            $facturaSubtotal,
            $facturaDescuento,
            $facturaItbis,
            $facturaTotal,
            $estadoFactura,
            $cotizacionOrigen,
            $rncCedulaCliente,
            $razonSocialCliente,
            $origenVenta
        ]);
        $facturaId = (int)$stmtInsFac->fetchColumn();

        // 9. Insertar líneas en ventas.factura_detalle (monto_total es columna generada) e impuestos en ventas.factura_detalle_impuestos
        $stmtInsDet = $pdo->prepare("
            INSERT INTO ventas.factura_detalle (
                factura_id, producto_maestro_id, cantidad_unidades, precio_unitario_his,
                codigo_barra_id, factor_conversion, cantidad_presentacion, costo_unitario_hist,
                promocion_aplicada, monto_descuento, monto_impuestos, monto_subtotal
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?
            )
        ");

        $stmtInsDetImp = $pdo->prepare("
            INSERT INTO ventas.factura_detalle_impuestos (
                factura_id, producto_maestro_id, impuesto_id, base_imponible,
                tipo_calculo_aplicado, tasa_aplicada, monto_impuesto
            ) VALUES (?, ?, ?, ?, 'PORCENTAJE', ?, ?)
            ON CONFLICT DO NOTHING
        ");

        foreach ($partidasAgrupadas as $linea) {
            $stmtInsDet->execute([
                $facturaId,
                $linea['producto_maestro_id'],
                $linea['cantidad_unidades'],
                $linea['precio_unitario_his'],
                $linea['codigo_barra_id'],
                $linea['factor_conversion'],
                $linea['cantidad_presentacion'],
                $linea['costo_unitario_hist'],
                $linea['promocion_aplicada'],
                $linea['monto_descuento'],
                $linea['monto_itbis'],
                $linea['monto_subtotal'],
            ]);

            $stmtInsDetImp->execute([
                $facturaId,
                $linea['producto_maestro_id'],
                $linea['impuesto_id'] ?? 1,
                $linea['base_imponible'] ?? 0.00,
                $linea['porcentaje_itbis'],
                $linea['monto_itbis'],
            ]);

            // Rebajar de inventario_stock o lotes si hay existencias registradas
            $unidadesRebajar = (int)$linea['cantidad_unidades'];
            $stmtStockRows = $pdo->prepare("
                SELECT id, stock_fisico_unidades, stock_comprometido
                FROM inventario.inventario_stock
                WHERE producto_maestro_id = ? AND (stock_fisico_unidades - stock_comprometido) > 0
                ORDER BY (stock_fisico_unidades - stock_comprometido) DESC
                FOR UPDATE
            ");
            $stmtStockRows->execute([$linea['producto_maestro_id']]);
            $filasStock = $stmtStockRows->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($filasStock as $st) {
                if ($unidadesRebajar <= 0) {
                    break;
                }
                $disp = max(0, (int)$st['stock_fisico_unidades'] - (int)$st['stock_comprometido']);
                $descontar = min($disp, $unidadesRebajar);
                $stmtUpdSt = $pdo->prepare("
                    UPDATE inventario.inventario_stock
                    SET stock_fisico_unidades = stock_fisico_unidades - ?
                    WHERE id = ?
                ");
                $stmtUpdSt->execute([$descontar, $st['id']]);
                $unidadesRebajar -= $descontar;
            }

            // También rebajar de inventario.lotes (FEFO: primero en vencer, primero en salir)
            $unidadesLotes = (int)$linea['cantidad_unidades'];
            $stmtLoteRows = $pdo->prepare("
                SELECT id, cantidad_actual
                FROM inventario.lotes
                WHERE producto_maestro_id = ? AND cantidad_actual > 0
                ORDER BY fecha_vencimiento ASC, id ASC
                FOR UPDATE
            ");
            $stmtLoteRows->execute([$linea['producto_maestro_id']]);
            $filasLotes = $stmtLoteRows->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($filasLotes as $lt) {
                if ($unidadesLotes <= 0) {
                    break;
                }
                $dispLt = (int)$lt['cantidad_actual'];
                $descLt = min($dispLt, $unidadesLotes);
                $stmtUpdLt = $pdo->prepare("
                    UPDATE inventario.lotes
                    SET cantidad_actual = cantidad_actual - ?
                    WHERE id = ?
                ");
                $stmtUpdLt->execute([$descLt, $lt['id']]);
                $unidadesLotes -= $descLt;
            }
        }

        // 10. Registrar el cobro en ventas.factura_pagos (CONTADO) o cuenta por cobrar en ventas.cuentas_por_cobrar (CREDITO)
        if ($condicionPago === 'CONTADO') {
            $metodoId = (int)$metodoPagoInput;
            if ($metodoId <= 0) {
                $metodoId = (int)$pdo->query("SELECT id FROM ventas.metodos_pago WHERE activo = TRUE ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($metodoId <= 0) {
                    $stmtNewMp = $pdo->query("
                        INSERT INTO ventas.metodos_pago (codigo, descripcion, requiere_referencia, activo)
                        VALUES ('EFECTIVO', 'Efectivo de Mostrador', FALSE, TRUE)
                        RETURNING id
                    ");
                    $metodoId = (int)$stmtNewMp->fetchColumn();
                }
            }

            $montoPagado   = $montoRecibidoInput >= $facturaTotal ? round($montoRecibidoInput, 2) : $facturaTotal;
            $montoDevuelta = max(0.00, round($montoPagado - $facturaTotal, 2));

            $stmtInsDetPago = $pdo->prepare("
                INSERT INTO tesoreria.detalles_pago (
                    metodo_pago_id, moneda_id, tasa_cambio, monto_pagado, monto_devuelta, sesion_caja_id
                ) VALUES (?, 1, 1.0000, ?, ?, ?)
                RETURNING id
            ");
            $stmtInsDetPago->execute([
                $metodoId,
                $montoPagado,
                $montoDevuelta,
                $sesionCajaId
            ]);
            $detallePagoId = (int)$stmtInsDetPago->fetchColumn();

            $stmtInsPago = $pdo->prepare("
                INSERT INTO ventas.factura_pagos (
                    factura_id, detalle_pago_id
                ) VALUES (?, ?)
            ");
            $stmtInsPago->execute([
                $facturaId,
                $detallePagoId
            ]);
        } else {
            // Venta a CRÉDITO -> Generar Cuenta por Cobrar (30 días)
            $stmtInsCxc = $pdo->prepare("
                INSERT INTO ventas.cuentas_por_cobrar (
                    cliente_id, factura_id, monto_original, saldo_pendiente,
                    estado, fecha_emision, fecha_vencimiento
                ) VALUES (
                    ?, ?, ?, ?,
                    'PENDIENTE', CURRENT_DATE, (CURRENT_DATE + INTERVAL '30 days')::date
                )
            ");
            $stmtInsCxc->execute([
                $clienteUuid,
                $facturaId,
                $facturaTotal,
                $facturaTotal
            ]);
        }

        // 11. Si la modalidad es DELIVERY, registrar el pedido en ventas.pedidos_delivery
        if ($origenVenta === 'DELIVERY') {
            $stmtInsDel = $pdo->prepare("
                INSERT INTO ventas.pedidos_delivery (
                    factura_id, repartidor_id, direccion_destino, zona_id, estado_delivery
                ) VALUES (?, ?, ?, ?, 'PENDIENTE')
            ");
            $stmtInsDel->execute([
                $facturaId,
                $repartidorId,
                $direccionDestino,
                $zonaDeliveryId
            ]);
        }

        // 12. Si proviene de una cotización, marcarla como CONVERTIDA_A_FACTURA
        if ($cotizacionOrigen !== null && $cotizacionOrigen > 0) {
            $stmtUpdCot = $pdo->prepare("
                UPDATE ventas.cotizaciones
                SET estado = 'CONVERTIDA_A_FACTURA',
                    factura_generada_id = ?
                WHERE id = ?
            ");
            $stmtUpdCot->execute([$facturaId, $cotizacionOrigen]);
        }

        $pdo->commit();

        echo json_encode([
            'ok'             => true,
            'factura_id'     => $facturaId,
            'numero_factura' => $numeroFactura,
            'ncf'            => $ncfGenerado ?? 'SIN NCF',
            'monto_total'    => $facturaTotal,
            'mensaje'        => "Factura {$numeroFactura}" . ($ncfGenerado ? " (NCF: {$ncfGenerado})" : "") . " guardada exitosamente."
        ]);
        exit;
    }

    throw new Exception('Acción no reconocida.');

} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'ok'      => false,
        'mensaje' => $e->getMessage()
    ]);
    exit;
}
