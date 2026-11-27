<?php
/**
 * ============================================================================
 * Backend API: Recepciones de Compra (Entregas Parciales, Multi-Lote y Contado)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/recepciones/procesar_recepciones.php
 * ============================================================================
 */

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/conexion.php';
if (file_exists(__DIR__ . '/../../config/auditoria_helper.php')) {
    require_once __DIR__ . '/../../config/auditoria_helper.php';
}

if (empty($_SESSION['usuario']['usuario_id'])) {
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'mensaje' => 'Sesión expirada o no iniciada.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Soporte universal para GET, POST form-data y POST JSON (php://input)
$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : null;
$datos = is_array($jsonBody) ? array_merge($_GET, $jsonBody) : array_merge($_GET, $_POST);
$accion = trim((string) ($datos['accion'] ?? $_REQUEST['accion'] ?? ''));

try {
    switch ($accion) {

        // ====================================================================
        // 1. Obtener líneas pendientes (cantidad_pendiente > 0) de una orden
        // ====================================================================
        case 'obtener_lineas_orden':
            $ordenId = (int) ($_GET['orden_id'] ?? 0);
            if ($ordenId <= 0) {
                throw new Exception("ID de orden de compra inválido.");
            }

            $stmtOrd = $pdo->prepare("
                SELECT oc.id,
                       oc.numero_orden,
                       oc.proveedor_id,
                       oc.estado,
                       oc.monto_total_estimado,
                       oc.fecha_emision,
                       oc.fecha_esperada,
                       pr.codigo_proveedor,
                       pr.dias_credito_habitual,
                       p.nombre_razon_social AS proveedor_nombre,
                       p.nombre_comercial AS proveedor_comercial,
                       p.documento_identidad AS proveedor_rnc,
                       p.telefono_principal AS proveedor_telefono
                FROM compras.ordenes_compra oc
                JOIN identidad.proveedores pr ON pr.persona_id = oc.proveedor_id
                JOIN identidad.personas p ON p.persona_id = pr.persona_id
                WHERE oc.id = ?
                LIMIT 1
            ");
            $stmtOrd->execute([$ordenId]);
            $orden = $stmtOrd->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                throw new Exception("La orden de compra no fue encontrada.");
            }

            if (!in_array($orden['estado'], ['PENDIENTE', 'RECIBIDA_PARCIAL'], true)) {
                throw new Exception("Esta orden se encuentra en estado '{$orden['estado']}' y no puede recibir nueva mercancía.");
            }

            $stmtLineas = $pdo->prepare("
                SELECT d.orden_compra_id,
                       d.producto_maestro_id,
                       d.cantidad_ordenada,
                       d.cantidad_recibida_total,
                       d.cantidad_pendiente,
                       d.costo_pactado_unitario,
                       pm.sku,
                       COALESCE(
                           NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                           (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                           pm.sku
                       ) AS producto_nombre,
                       pm.requiere_lote,
                       pm.costo_promedio
                FROM compras.orden_compra_detalles d
                JOIN inventario.productos_maestro pm ON pm.id = d.producto_maestro_id
                WHERE d.orden_compra_id = ?
                  AND d.cantidad_pendiente > 0
                ORDER BY pm.nombre ASC, d.producto_maestro_id ASC
            ");
            $stmtLineas->execute([$ordenId]);
            $lineasPendientes = $stmtLineas->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($lineasPendientes)) {
                throw new Exception("Esta orden no posee productos con saldo pendiente por recibir.");
            }

            echo json_encode([
                'ok' => true,
                'orden' => $orden,
                'lineas' => $lineasPendientes
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ====================================================================
        // 2. Obtener detalle de una recepción ya guardada (Historial)
        // ====================================================================
        case 'obtener_detalle_recepcion':
            $recepcionId = (int) ($_GET['recepcion_id'] ?? 0);
            if ($recepcionId <= 0) {
                throw new Exception("ID de recepción inválido.");
            }

            $stmtRec = $pdo->prepare("
                SELECT rc.id,
                       rc.orden_compra_id,
                       rc.proveedor_id,
                       rc.numero_factura_proveedor,
                       rc.ncf_proveedor,
                       rc.monto_total_factura,
                       rc.usuario_recepcion_id,
                       rc.fecha_recepcion,
                       rc.condicion_pago,
                       rc.moneda_id,
                       rc.tasa_cambio,
                       oc.numero_orden,
                       p.nombre_razon_social AS proveedor_nombre,
                       p.documento_identidad AS proveedor_rnc,
                       u.username AS usuario_receptor,
                       COALESCE(emp_p.nombre_razon_social, u.username) AS receptor_nombre_completo
                FROM compras.recepciones_compra rc
                LEFT JOIN compras.ordenes_compra oc ON oc.id = rc.orden_compra_id
                JOIN identidad.proveedores pr ON pr.persona_id = rc.proveedor_id
                JOIN identidad.personas p ON p.persona_id = pr.persona_id
                JOIN seguridad.usuarios u ON u.usuario_id = rc.usuario_recepcion_id
                LEFT JOIN identidad.personas emp_p ON emp_p.persona_id = u.persona_id
                WHERE rc.id = ?
                LIMIT 1
            ");
            $stmtRec->execute([$recepcionId]);
            $recepcion = $stmtRec->fetch(PDO::FETCH_ASSOC);

            if (!$recepcion) {
                throw new Exception("La recepción solicitada no existe.");
            }

            $stmtDet = $pdo->prepare("
                SELECT rcd.recepcion_id,
                       rcd.producto_maestro_id,
                       rcd.numero_lote,
                       rcd.fecha_vencimiento,
                       rcd.cantidad_recibida_buena,
                       rcd.costo_unitario_facturado,
                       rcd.orden_compra_id,
                       (rcd.cantidad_recibida_buena * rcd.costo_unitario_facturado) AS subtotal_linea,
                       pm.sku,
                       pm.requiere_lote,
                       COALESCE(
                           NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                           (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                           pm.sku
                       ) AS producto_nombre
                FROM compras.recepcion_compra_detalles rcd
                JOIN inventario.productos_maestro pm ON pm.id = rcd.producto_maestro_id
                WHERE rcd.recepcion_id = ?
                ORDER BY pm.nombre ASC, rcd.numero_lote ASC
            ");
            $stmtDet->execute([$recepcionId]);
            $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmtCxp = $pdo->prepare("
                SELECT cxp.id,
                       cxp.monto_original,
                       cxp.saldo_pendiente,
                       cxp.fecha_emision,
                       cxp.fecha_vencimiento,
                       cxp.estado
                FROM compras.cuentas_por_pagar cxp
                WHERE cxp.recepcion_compra_id = ?
                LIMIT 1
            ");
            $stmtCxp->execute([$recepcionId]);
            $cxp = $stmtCxp->fetch(PDO::FETCH_ASSOC) ?: null;

            $egreso = null;
            if ($cxp && !empty($cxp['id'])) {
                $stmtEgr = $pdo->prepare("
                    SELECT ce.id, ce.numero_comprobante, ce.monto_total_pagado, ce.fecha_pago
                    FROM compras.comprobante_egreso_detalles ced
                    JOIN compras.comprobantes_egreso_cxp ce ON ce.id = ced.comprobante_egreso_id
                    WHERE ced.cuenta_por_pagar_id = ?
                    ORDER BY ce.id DESC
                    LIMIT 1
                ");
                $stmtEgr->execute([(int) $cxp['id']]);
                $egreso = $stmtEgr->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            echo json_encode([
                'ok' => true,
                'recepcion' => $recepcion,
                'detalles' => $detalles,
                'cxp' => $cxp,
                'egreso' => $egreso
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ====================================================================
        // 3. Registrar Recepción de Mercancía (Multi-Lote + Liquidación Contado)
        // ====================================================================
        case 'registrar_recepcion':
            if (!usuarioPuedeRegistrarRecepcion()) {
                throw new Exception("No cuenta con permisos autorizados (REGISTRAR_RECEPCION_COMPRA) para ingresar recepciones.");
            }

            $input = is_array($jsonBody) ? $jsonBody : $_POST;

            $ordenId = !empty($input['orden_compra_id']) ? (int) $input['orden_compra_id'] : null;
            $proveedorIdInput = !empty($input['proveedor_id']) ? trim((string) $input['proveedor_id']) : null;
            $almacenId = (int) ($input['almacen_id'] ?? 0);
            $numFactura = trim((string) ($input['numero_factura_proveedor'] ?? ''));
            $ncfProveedor = strtoupper(trim((string) ($input['ncf_proveedor'] ?? '')));
            $condicionPago = strtoupper(trim((string) ($input['condicion_pago'] ?? 'CREDITO')));

            $lineasRecibidas = $input['lineas'] ?? [];
            if (is_string($lineasRecibidas)) {
                $lineasRecibidas = json_decode($lineasRecibidas, true) ?: [];
            }

            // Validar almacén destino
            if ($almacenId <= 0) {
                throw new Exception("Debe seleccionar un almacén de destino para ingresar la mercancía.");
            }
            $stmtAlm = $pdo->prepare("
                SELECT id, nombre
                FROM inventario.almacenes
                WHERE id = ? AND activo = TRUE
                LIMIT 1
            ");
            $stmtAlm->execute([$almacenId]);
            $almacenRow = $stmtAlm->fetch(PDO::FETCH_ASSOC);
            if (!$almacenRow) {
                throw new Exception("El almacén destino seleccionado no existe o está inactivo.");
            }
            $almacenNombre = (string) $almacenRow['nombre'];

            // 1. Validaciones de Cabecera Física
            $proveedorId = null;
            if ($ordenId !== null && $ordenId > 0) {
                $stmtOrd = $pdo->prepare("
                    SELECT id, proveedor_id, estado, numero_orden
                    FROM compras.ordenes_compra
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmtOrd->execute([$ordenId]);
                $orden = $stmtOrd->fetch(PDO::FETCH_ASSOC);

                if (!$orden) {
                    throw new Exception("La orden de compra seleccionada no existe.");
                }
                if (!in_array($orden['estado'], ['PENDIENTE', 'RECIBIDA_PARCIAL'], true)) {
                    throw new Exception("La orden {$orden['numero_orden']} tiene estado '{$orden['estado']}' y no admite recepciones.");
                }
                $proveedorId = $orden['proveedor_id'];
            } else {
                $ordenId = null;
                if (empty($proveedorIdInput)) {
                    throw new Exception("Debe seleccionar un proveedor para registrar la recepción directa.");
                }
                $stmtProv = $pdo->prepare("SELECT persona_id FROM identidad.proveedores WHERE persona_id = ?");
                $stmtProv->execute([$proveedorIdInput]);
                if (!$stmtProv->fetchColumn()) {
                    throw new Exception("El proveedor seleccionado no existe o es inválido.");
                }
                $proveedorId = $proveedorIdInput;
            }

            if (empty($numFactura)) {
                throw new Exception("El número de factura del proveedor es obligatorio.");
            }
            if (strlen($numFactura) > 50) {
                throw new Exception("El número de factura no puede superar 50 caracteres.");
            }

            if (empty($ncfProveedor)) {
                throw new Exception("El NCF del proveedor es obligatorio para efectos fiscales.");
            }
            if (strlen($ncfProveedor) > 13) {
                throw new Exception("El NCF no puede superar los 13 caracteres (formato DGII).");
            }

            if (!in_array($condicionPago, ['CONTADO', 'CREDITO'], true)) {
                throw new Exception("La condición de pago debe ser CONTADO o CREDITO.");
            }

            // Obtener nombre del proveedor para concepto bancario y auditoría
            $stmtProvNom = $pdo->prepare("SELECT nombre_razon_social FROM identidad.personas WHERE persona_id = ? LIMIT 1");
            $stmtProvNom->execute([$proveedorId]);
            $proveedorNombre = (string) ($stmtProvNom->fetchColumn() ?: 'Proveedor');

            // Si es al CONTADO, validar método de pago (bóveda/banco externo, nunca caja POS)
            $metodoContadoRow = null;
            $cuentaContadoRow = null;
            $refContado = '';

            if ($condicionPago === 'CONTADO') {
                $metodoPagoIn = (int) ($input['metodo_pago_id'] ?? 0);
                if ($metodoPagoIn > 0) {
                    $stMp = $pdo->prepare("SELECT id, codigo, descripcion FROM ventas.metodos_pago WHERE id = ? AND activo = TRUE LIMIT 1");
                    $stMp->execute([$metodoPagoIn]);
                    $metodoContadoRow = $stMp->fetch(PDO::FETCH_ASSOC);
                } else {
                    $metodoContadoRow = $pdo->query("SELECT id, codigo, descripcion FROM ventas.metodos_pago WHERE UPPER(codigo) = 'EFECTIVO' AND activo = TRUE LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                }

                if (!$metodoContadoRow) {
                    throw new Exception("El método de pago seleccionado para la compra al contado no es válido.");
                }

                $tarjetaContado = null;
                $codMetContado = strtoupper((string) $metodoContadoRow['codigo']);
                if (!in_array($codMetContado, ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'CHEQUE'], true)) {
                    throw new Exception("El método '{$codMetContado}' no está permitido para pagar recepciones al contado.");
                }

                if ($codMetContado === 'TARJETA') {
                    $td = is_array($input['tarjeta_datos'] ?? null) ? $input['tarjeta_datos'] : [];
                    $tipoTj = strtoupper(trim((string) ($td['tipo_tarjeta'] ?? 'DEBITO')));
                    $marcaTj = strtoupper(trim((string) ($td['marca_tarjeta'] ?? 'VISA')));
                    $ult4 = preg_replace('/\D/', '', (string) ($td['ultimos_4_digitos'] ?? ''));
                    $numAut = trim((string) ($td['numero_autorizacion'] ?? ''));

                    if (strlen($ult4) !== 4) {
                        throw new Exception("Para pago al contado con Tarjeta debe ingresar los últimos 4 dígitos.");
                    }
                    if ($numAut === '') {
                        throw new Exception("Para pago al contado con Tarjeta debe ingresar el número de autorización.");
                    }

                    $tarjetaContado = [
                        'tipo_tarjeta' => in_array($tipoTj, ['CREDITO', 'DEBITO'], true) ? $tipoTj : 'DEBITO',
                        'marca_tarjeta' => in_array($marcaTj, ['VISA', 'MASTERCARD', 'AMEX', 'OTRA'], true) ? $marcaTj : 'OTRA',
                        'ultimos_4_digitos' => $ult4,
                        'numero_autorizacion' => $numAut,
                    ];
                }

                if (in_array($codMetContado, ['TRANSFERENCIA', 'CHEQUE'], true)) {
                    $ctaIdIn = (int) ($input['cuenta_bancaria_id'] ?? 0);
                    $refContado = trim((string) ($input['referencia_pago'] ?? ''));

                    if ($ctaIdIn <= 0) {
                        throw new Exception("Debe seleccionar una cuenta bancaria en DOP para el pago por {$codMetContado}.");
                    }
                    if ($refContado === '') {
                        $etq = ($codMetContado === 'CHEQUE') ? 'número de cheque' : 'número de referencia bancaria';
                        throw new Exception("Debe ingresar el {$etq} para liquidar la recepción al contado.");
                    }

                    $stCta = $pdo->prepare("
                        SELECT cb.id, cb.nombre_banco, cb.numero_cuenta, cb.activa, cb.moneda_id,
                               COALESCE(m.codigo_iso, 'DOP') AS codigo_iso,
                               COALESCE(m.es_moneda_base, TRUE) AS es_moneda_base
                        FROM tesoreria.cuentas_bancarias cb
                        LEFT JOIN tesoreria.monedas m ON m.id = cb.moneda_id
                        WHERE cb.id = ? LIMIT 1
                    ");
                    $stCta->execute([$ctaIdIn]);
                    $cuentaContadoRow = $stCta->fetch(PDO::FETCH_ASSOC);
                    if (!$cuentaContadoRow || !in_array($cuentaContadoRow['activa'], [true, 't', 1, '1'], true)) {
                        throw new Exception("La cuenta bancaria seleccionada no existe o está inactiva.");
                    }
                    $esDop = in_array($cuentaContadoRow['es_moneda_base'], [true, 't', 1, '1'], true) || strtoupper((string) $cuentaContadoRow['codigo_iso']) === 'DOP';
                    if (!$esDop) {
                        throw new Exception("La cuenta «{$cuentaContadoRow['nombre_banco']}» está en {$cuentaContadoRow['codigo_iso']}. Seleccione una cuenta en DOP.");
                    }

                    $stDupRef = $pdo->prepare("
                        SELECT id, TO_CHAR(fecha_movimiento, 'DD/MM/YYYY') AS fecha_fmt
                        FROM tesoreria.movimientos_bancarios
                        WHERE cuenta_bancaria_id = ? AND UPPER(TRIM(referencia)) = UPPER(?)
                        LIMIT 1
                    ");
                    $stDupRef->execute([$ctaIdIn, $refContado]);
                    if ($dupM = $stDupRef->fetch(PDO::FETCH_ASSOC)) {
                        throw new Exception("La referencia «{$refContado}» ya existe en {$cuentaContadoRow['nombre_banco']} (Mov. #{$dupM['id']} del {$dupM['fecha_fmt']}).");
                    }
                }
            }

            // Validar antes de la transacción si la Factura o el NCF ya existen para este proveedor
            $stmtDupFac = $pdo->prepare("
                SELECT id, TO_CHAR(fecha_recepcion, 'DD/MM/YYYY') AS fecha_fmt
                FROM compras.recepciones_compra
                WHERE proveedor_id = ?
                  AND UPPER(TRIM(numero_factura_proveedor)) = UPPER(?)
                LIMIT 1
            ");
            $stmtDupFac->execute([$proveedorId, $numFactura]);
            $dupFac = $stmtDupFac->fetch(PDO::FETCH_ASSOC);

            $stmtDupNcf = $pdo->prepare("
                SELECT id, TO_CHAR(fecha_recepcion, 'DD/MM/YYYY') AS fecha_fmt
                FROM compras.recepciones_compra
                WHERE proveedor_id = ?
                  AND UPPER(TRIM(COALESCE(ncf_proveedor, ''))) = UPPER(?)
                LIMIT 1
            ");
            $stmtDupNcf->execute([$proveedorId, $ncfProveedor]);
            $dupNcf = $stmtDupNcf->fetch(PDO::FETCH_ASSOC);

            if ($dupFac && $dupNcf) {
                throw new Exception(
                    "Esta factura ya fue registrada anteriormente para este proveedor (Recepción #{$dupFac['id']} del {$dupFac['fecha_fmt']}). " .
                    "Verifica tanto el N° de Factura ({$numFactura}) como el NCF ({$ncfProveedor})."
                );
            }
            if ($dupFac) {
                throw new Exception(
                    "El N° de Factura Física «{$numFactura}» ya existe para este proveedor (Recepción #{$dupFac['id']} del {$dupFac['fecha_fmt']}). " .
                    "Por favor revisa el número de factura antes de continuar."
                );
            }
            if ($dupNcf) {
                throw new Exception(
                    "El NCF «{$ncfProveedor}» ya fue registrado para este proveedor (Recepción #{$dupNcf['id']} del {$dupNcf['fecha_fmt']}). " .
                    "Por favor verifica el comprobante fiscal impreso en la factura."
                );
            }

            // 2. Validaciones de Líneas y Multi-Lote
            if (!is_array($lineasRecibidas) || count($lineasRecibidas) === 0) {
                throw new Exception("No se recibieron datos de productos para ingresar.");
            }

            $dbPendientes = [];
            if ($ordenId !== null) {
                $stmtPendBD = $pdo->prepare("
                    SELECT d.producto_maestro_id,
                           d.cantidad_pendiente,
                           d.costo_pactado_unitario,
                           pm.sku,
                           pm.requiere_lote,
                           COALESCE(
                               NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                               (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                               pm.sku
                           ) AS producto_nombre
                    FROM compras.orden_compra_detalles d
                    JOIN inventario.productos_maestro pm ON pm.id = d.producto_maestro_id
                    WHERE d.orden_compra_id = ?
                ");
                $stmtPendBD->execute([$ordenId]);
                foreach ($stmtPendBD->fetchAll(PDO::FETCH_ASSOC) as $pRow) {
                    $dbPendientes[$pRow['producto_maestro_id']] = $pRow;
                }
            }

            $lineasValidas = [];
            $lotesPorProducto = [];
            $cantidadesPorProducto = [];

            foreach ($lineasRecibidas as $linea) {
                $prodId = (int) ($linea['producto_maestro_id'] ?? 0);
                $cantRec = (int) ($linea['cantidad_recibida_buena'] ?? 0);
                $costoFact = (float) ($linea['costo_unitario_facturado'] ?? 0);
                $lote = trim((string) ($linea['numero_lote'] ?? ''));
                $venc = trim((string) ($linea['fecha_vencimiento'] ?? ''));

                if ($cantRec <= 0) {
                    continue;
                }

                $prodNombre = 'Producto';
                $reqLoteRaw = true;

                if ($ordenId !== null) {
                    if ($prodId <= 0 || !isset($dbPendientes[$prodId])) {
                        throw new Exception("El producto ID {$prodId} no pertenece a esta orden de compra.");
                    }
                    $infoProd = $dbPendientes[$prodId];
                    $prodNombre = $infoProd['producto_nombre'];
                    $reqLoteRaw = $infoProd['requiere_lote'];
                } else {
                    if ($prodId <= 0) {
                        throw new Exception("ID de producto inválido.");
                    }
                    $stmtPInfo = $pdo->prepare("SELECT nombre, sku, requiere_lote FROM inventario.productos_maestro WHERE id = ?");
                    $stmtPInfo->execute([$prodId]);
                    $pRow = $stmtPInfo->fetch(PDO::FETCH_ASSOC);
                    if (!$pRow) {
                        throw new Exception("El producto ID {$prodId} no existe en catálogo.");
                    }
                    $prodNombre = $pRow['nombre'] ?? $pRow['sku'];
                    $reqLoteRaw = $pRow['requiere_lote'];
                }

                $requiereLote = ($reqLoteRaw === true || $reqLoteRaw === 't' || $reqLoteRaw === '1' || $reqLoteRaw === 1);

                if (!$requiereLote) {
                    $lote = 'GENERICO - SIN LOTE';
                    $venc = '2099-12-31';
                } else {
                    if (empty($lote)) {
                        throw new Exception("Debe ingresar el número de lote para el producto '{$prodNombre}'.");
                    }
                    if (strlen($lote) > 50) {
                        throw new Exception("El lote '{$lote}' supera los 50 caracteres permitidos.");
                    }

                    if (empty($venc)) {
                        throw new Exception("Debe ingresar la fecha de vencimiento para el lote '{$lote}' del producto '{$prodNombre}'.");
                    }
                    $dtVenc = DateTime::createFromFormat('Y-m-d', $venc);
                    if (!$dtVenc || $dtVenc->format('Y-m-d') !== $venc) {
                        throw new Exception("La fecha de vencimiento '{$venc}' del lote '{$lote}' no tiene un formato válido (AAAA-MM-DD).");
                    }
                }

                if ($costoFact <= 0) {
                    throw new Exception("El costo unitario facturado para el producto '{$prodNombre}' debe ser mayor a cero.");
                }

                if (!isset($lotesPorProducto[$prodId])) {
                    $lotesPorProducto[$prodId] = [];
                    $cantidadesPorProducto[$prodId] = 0;
                }

                $loteKey = strtoupper($lote);
                if (in_array($loteKey, $lotesPorProducto[$prodId], true)) {
                    throw new Exception($requiereLote
                        ? "El número de lote '{$lote}' está repetido para el producto '{$prodNombre}'. Cada sub-lote de un mismo producto debe tener un número de lote único."
                        : "El producto '{$prodNombre}' (sin control de lote) está repetido en más de una línea.");
                }
                $lotesPorProducto[$prodId][] = $loteKey;
                $cantidadesPorProducto[$prodId] += $cantRec;

                $lineasValidas[] = [
                    'producto_maestro_id' => $prodId,
                    'requiere_lote' => $requiereLote,
                    'numero_lote' => $lote,
                    'fecha_vencimiento' => $venc,
                    'cantidad_recibida_buena' => $cantRec,
                    'costo_unitario_facturado' => round($costoFact, 2),
                    'orden_compra_id' => $ordenId
                ];
            }

            if (empty($lineasValidas)) {
                throw new Exception("Debe ingresar al menos una línea con cantidad recibida mayor a cero.");
            }

            if ($ordenId !== null) {
                foreach ($cantidadesPorProducto as $prodId => $totalRecibidoProd) {
                    if (isset($dbPendientes[$prodId])) {
                        $infoProd = $dbPendientes[$prodId];
                        $pendienteMax = (int) $infoProd['cantidad_pendiente'];

                        if ($totalRecibidoProd > $pendienteMax) {
                            throw new Exception("La suma recibida ({$totalRecibidoProd} unidades) para el producto '{$infoProd['producto_nombre']}' excede la cantidad pendiente ({$pendienteMax} unidades).");
                        }
                    }
                }
            }

            $montoTotalFactura = 0.00;
            foreach ($lineasValidas as $lv) {
                $montoTotalFactura += ($lv['cantidad_recibida_buena'] * $lv['costo_unitario_facturado']);
            }
            $montoTotalFactura = round($montoTotalFactura, 2);

            $usuarioId = $_SESSION['usuario']['usuario_id'];

            // INSERCIÓN ATÓMICA EN TRANSACCIÓN PDO
            $pdo->beginTransaction();

            $stmtInsRec = $pdo->prepare("
                INSERT INTO compras.recepciones_compra (
                    orden_compra_id,
                    proveedor_id,
                    numero_factura_proveedor,
                    ncf_proveedor,
                    monto_total_factura,
                    usuario_recepcion_id,
                    fecha_recepcion,
                    condicion_pago,
                    moneda_id,
                    tasa_cambio
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    CURRENT_TIMESTAMP, ?, 1, 1.0000
                ) RETURNING id
            ");
            $stmtInsRec->execute([
                $ordenId,
                $proveedorId,
                $numFactura,
                $ncfProveedor,
                $montoTotalFactura,
                $usuarioId,
                $condicionPago
            ]);
            $recepcionId = (int) $stmtInsRec->fetchColumn();

            // 1. Sentencias preparadas para recepción, inventario, lotes y Kardex
            $stmtInsDet = $pdo->prepare("
                INSERT INTO compras.recepcion_compra_detalles (
                    recepcion_id,
                    producto_maestro_id,
                    numero_lote,
                    fecha_vencimiento,
                    cantidad_recibida_buena,
                    costo_unitario_facturado,
                    orden_compra_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmtMov = $pdo->prepare("
                INSERT INTO inventario.movimientos_inventario (
                    tipo_movimiento,
                    justificacion,
                    supervisor_id,
                    documento_referencia_id,
                    fecha_movimiento
                ) VALUES (
                    'ENTRADA_COMPRA',
                    ?,
                    ?,
                    ?,
                    CURRENT_TIMESTAMP
                ) RETURNING id
            ");
            $justificacionMov = "Ingreso físico en {$almacenNombre} por Recepción de Compra #{$recepcionId} • Factura {$numFactura} (NCF: {$ncfProveedor})";
            $stmtMov->execute([$justificacionMov, $usuarioId, $recepcionId]);
            $movimientoId = (int) $stmtMov->fetchColumn();

            $stmtBuscarLote = $pdo->prepare("
                SELECT id, cantidad_actual
                FROM inventario.lotes
                WHERE producto_maestro_id = ? AND UPPER(numero_lote) = UPPER(?)
                LIMIT 1
            ");

            $stmtActualizarLote = $pdo->prepare("
                UPDATE inventario.lotes
                SET cantidad_actual = cantidad_actual + ?,
                    fecha_vencimiento = ?
                WHERE id = ?
            ");

            $stmtInsertarLote = $pdo->prepare("
                INSERT INTO inventario.lotes (
                    producto_maestro_id,
                    numero_lote,
                    fecha_vencimiento,
                    cantidad_actual
                ) VALUES (?, ?, ?, ?)
                RETURNING id
            ");

            $stmtUpsertStock = $pdo->prepare("
                INSERT INTO inventario.inventario_stock (
                    almacen_id,
                    producto_maestro_id,
                    lote_id,
                    stock_fisico_unidades,
                    stock_comprometido
                ) VALUES (?, ?, ?, ?, 0)
                ON CONFLICT (almacen_id, producto_maestro_id, lote_id)
                DO UPDATE SET stock_fisico_unidades = inventario.inventario_stock.stock_fisico_unidades + EXCLUDED.stock_fisico_unidades
            ");

            $stmtInsMovDet = $pdo->prepare("
                INSERT INTO inventario.movimiento_inventario_detalles (
                    movimiento_id,
                    producto_maestro_id,
                    lote_id,
                    cantidad_unidades,
                    costo_unitario_referencia
                ) VALUES (?, ?, ?, ?, ?)
            ");

            $stmtActualizarCostoProm = $pdo->prepare("
                UPDATE inventario.productos_maestro
                SET costo_promedio = ROUND(
                    COALESCE(
                        (costo_promedio * 0.5 + ? * 0.5),
                        ?
                    ), 2)
                WHERE id = ?
            ");

            $stmtActualizarOcDet = $pdo->prepare("
                UPDATE compras.orden_compra_detalles
                SET cantidad_recibida_total = cantidad_recibida_total + ?
                WHERE orden_compra_id = ? AND producto_maestro_id = ?
            ");

            foreach ($lineasValidas as $l) {
                $stmtInsDet->execute([
                    $recepcionId,
                    $l['producto_maestro_id'],
                    $l['numero_lote'],
                    $l['fecha_vencimiento'],
                    $l['cantidad_recibida_buena'],
                    $l['costo_unitario_facturado'],
                    $ordenId
                ]);




                if (!$l['requiere_lote']) {
                    // Producto sin control de lote (ej. Funda de Hielo): usa lote comodín 0
                    $loteId = 0;
                } else {
                    $stmtBuscarLote->execute([$l['producto_maestro_id'], $l['numero_lote']]);
                    $loteExistente = $stmtBuscarLote->fetch(PDO::FETCH_ASSOC);

                    if ($loteExistente) {
                        $loteId = (int) $loteExistente['id'];
                        $stmtActualizarLote->execute([
                            $l['cantidad_recibida_buena'],
                            $l['fecha_vencimiento'],
                            $loteId
                        ]);
                    } else {
                        $stmtInsertarLote->execute([
                            $l['producto_maestro_id'],
                            $l['numero_lote'],
                            $l['fecha_vencimiento'],
                            $l['cantidad_recibida_buena']
                        ]);
                        $loteId = (int) $stmtInsertarLote->fetchColumn();
                    }
                }

                $stmtUpsertStock->execute([
                    $almacenId,
                    $l['producto_maestro_id'],
                    $loteId,
                    $l['cantidad_recibida_buena']
                ]);

                $stmtInsMovDet->execute([
                    $movimientoId,
                    $l['producto_maestro_id'],
                    $loteId,
                    $l['cantidad_recibida_buena'],
                    $l['costo_unitario_facturado']
                ]);

                $stmtActualizarCostoProm->execute([
                    $l['costo_unitario_facturado'],
                    $l['costo_unitario_facturado'],
                    $l['producto_maestro_id']
                ]);

                if ($ordenId !== null && $ordenId > 0) {
                    $stmtActualizarOcDet->execute([
                        $l['cantidad_recibida_buena'],
                        $ordenId,
                        $l['producto_maestro_id']
                    ]);
                }
            }

            // 2. Si proviene de Orden de Compra, actualizar el estado de la cabecera de la orden
            if ($ordenId !== null && $ordenId > 0) {
                $stmtCalcPend = $pdo->prepare("
                    SELECT COALESCE(SUM(cantidad_pendiente), 0) AS saldo_pendiente_total
                    FROM compras.orden_compra_detalles
                    WHERE orden_compra_id = ?
                ");
                $stmtCalcPend->execute([$ordenId]);
                $saldoPend = (int) $stmtCalcPend->fetchColumn();

                $nuevoEstadoOrden = ($saldoPend === 0) ? 'COMPLETADA' : 'RECIBIDA_PARCIAL';
                $pdo->prepare("UPDATE compras.ordenes_compra SET estado = ? WHERE id = ?")
                    ->execute([$nuevoEstadoOrden, $ordenId]);
            }

            // 3. Verificar si el trigger trg_recepcion_compra_cxp ya generó la Cuenta por Pagar (CxP)
            $stmtBuscarCxp = $pdo->prepare("
                SELECT id
                FROM compras.cuentas_por_pagar
                WHERE recepcion_compra_id = ?
                LIMIT 1
            ");
            $stmtBuscarCxp->execute([$recepcionId]);
            $cxpId = (int) ($stmtBuscarCxp->fetchColumn() ?: 0);

            if ($cxpId <= 0) {
                $stmtProvDias = $pdo->prepare("SELECT dias_credito_habitual FROM identidad.proveedores WHERE persona_id = ?");
                $stmtProvDias->execute([$proveedorId]);
                $diasHab = (int) ($stmtProvDias->fetchColumn() ?: 30);
                $diasPlazo = ($condicionPago === 'CONTADO') ? 0 : max(1, $diasHab);
                $fechaVenc = date('Y-m-d', strtotime("+{$diasPlazo} days"));

                $stmtInsCxp = $pdo->prepare("
                    INSERT INTO compras.cuentas_por_pagar (
                        proveedor_id,
                        recepcion_compra_id,
                        monto_original,
                        saldo_pendiente,
                        fecha_emision,
                        fecha_vencimiento,
                        estado,
                        moneda_id,
                        tasa_cambio
                    ) VALUES (
                        ?, ?, ?, ?,
                        CURRENT_DATE, ?, 'PENDIENTE', 1, 1.0000
                    ) RETURNING id
                ");
                $stmtInsCxp->execute([
                    $proveedorId,
                    $recepcionId,
                    $montoTotalFactura,
                    $montoTotalFactura,
                    $fechaVenc
                ]);
                $cxpId = (int) $stmtInsCxp->fetchColumn();
            }

            // 4. Si la recepción fue al CONTADO, emitir Comprobante de Egreso y saldar la CxP automáticamente
            $numComprobanteContado = null;
            if ($condicionPago === 'CONTADO' && $cxpId > 0 && $montoTotalFactura > 0) {
                $seqEgr = $pdo->query("SELECT pg_get_serial_sequence('compras.comprobantes_egreso_cxp', 'id')")->fetchColumn()
                    ?: 'compras.comprobantes_egreso_cxp_id_seq';
                $egresoId = (int) $pdo->query("SELECT nextval('{$seqEgr}')")->fetchColumn();
                $numComprobanteContado = 'EGR-' . date('Y') . '-' . str_pad((string) $egresoId, 6, '0', STR_PAD_LEFT);

                // 4A. Cabecera del comprobante de egreso
                $pdo->prepare("
                    INSERT INTO compras.comprobantes_egreso_cxp (
                        id, numero_comprobante, proveedor_id, usuario_registro_id,
                        monto_total_pagado, fecha_pago, moneda_id, tasa_cambio
                    ) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 1, 1.0000)
                ")->execute([
                            $egresoId,
                            $numComprobanteContado,
                            $proveedorId,
                            $usuarioId,
                            $montoTotalFactura
                        ]);

                // 4B. Detalle de egreso (dispara trg_actualizar_saldo_cxp_egreso -> CxP = PAGADA, saldo = 0.00)
                $pdo->prepare("
                    INSERT INTO compras.comprobante_egreso_detalles (
                        comprobante_egreso_id, cuenta_por_pagar_id, monto_aplicado,
                        saldo_anterior, saldo_restante, monto_retenciones
                    ) VALUES (?, ?, ?, ?, 0.00, 0.00)
                ")->execute([
                            $egresoId,
                            $cxpId,
                            $montoTotalFactura,
                            $montoTotalFactura
                        ]);

                // Respaldo trigger-safe si no existiera el trigger
                $stSaldoPost = $pdo->prepare("SELECT saldo_pendiente FROM compras.cuentas_por_pagar WHERE id = ?");
                $stSaldoPost->execute([$cxpId]);
                if ((float) $stSaldoPost->fetchColumn() > 0.00) {
                    $pdo->prepare("UPDATE compras.cuentas_por_pagar SET saldo_pendiente = 0.00, estado = 'PAGADA' WHERE id = ?")
                        ->execute([$cxpId]);
                }

                // 4C. Detalle de pago en Tesorería (sesion_caja_id = NULL para no alterar Arqueo Ciego POS)
                $stDp = $pdo->prepare("
                    INSERT INTO tesoreria.detalles_pago (
                        metodo_pago_id, moneda_id, tasa_cambio, monto_pagado, monto_devuelta, sesion_caja_id
                    ) VALUES (?, 1, 1.0000, ?, 0.00, NULL)
                    RETURNING id
                ");
                $stDp->execute([(int) $metodoContadoRow['id'], $montoTotalFactura]);
                $detallePagoId = (int) $stDp->fetchColumn();

                // 4D. Enlace puente (antes del subtipo para que los triggers espejo de CxP detecten el egreso)
                $pdo->prepare("
                    INSERT INTO compras.egreso_cxp_pagos (comprobante_egreso_id, detalle_pago_id)
                    VALUES (?, ?)
                ")->execute([$egresoId, $detallePagoId]);

                // 4E. Subtipo bancario + movimiento trigger-safe si fue TRANSFERENCIA o CHEQUE
                // 4E. Subtipo bancario / tarjeta + movimiento trigger-safe si fue TRANSFERENCIA o CHEQUE
                $codMetContado = strtoupper((string) $metodoContadoRow['codigo']);
                if (in_array($codMetContado, ['TRANSFERENCIA', 'CHEQUE'], true) && $cuentaContadoRow) {
                    $ctaId = (int) $cuentaContadoRow['id'];
                    $bancoNom = (string) $cuentaContadoRow['nombre_banco'];

                    if ($codMetContado === 'TRANSFERENCIA') {
                        $pdo->prepare("
                            INSERT INTO tesoreria.pago_transferencia (
                                detalle_pago_id, banco_origen, cuenta_bancaria_id, numero_referencia, fecha_transferencia, titular_origen
                            ) VALUES (?, ?, ?, ?, CURRENT_DATE, 'House & Ice')
                        ")->execute([$detallePagoId, $bancoNom, $ctaId, $refContado]);

                        $tipoMovBanc = 'TRANSFERENCIA_SALIDA';
                        $conceptoMov = mb_substr("Compra Contado ({$proveedorNombre}) • Rec. #{$recepcionId} / {$numComprobanteContado} • Ref: {$refContado}", 0, 255, 'UTF-8');
                    } else {
                        $pdo->prepare("
                            INSERT INTO tesoreria.pago_cheque (
                                detalle_pago_id, numero_cheque, banco_emisor, titular_cheque,
                                fecha_emision, cuenta_bancaria_id, estado_cheque
                            ) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, 'RECIBIDO')
                        ")->execute([$detallePagoId, $refContado, $bancoNom, $proveedorNombre, $ctaId]);

                        $tipoMovBanc = 'CHEQUE_SALIDA';
                        $conceptoMov = mb_substr("Cheque Compra Contado ({$proveedorNombre}) • Rec. #{$recepcionId} / {$numComprobanteContado} • Chq #{$refContado}", 0, 255, 'UTF-8');
                    }

                    $stBusMov = $pdo->prepare("
                        SELECT id FROM tesoreria.movimientos_bancarios
                        WHERE detalle_pago_id = ?
                           OR (cuenta_bancaria_id = ? AND UPPER(TRIM(referencia)) = UPPER(?))
                        ORDER BY id DESC LIMIT 1
                    ");
                    $stBusMov->execute([$detallePagoId, $ctaId, $refContado]);
                    $movExistId = (int) ($stBusMov->fetchColumn() ?: 0);

                    if ($movExistId > 0) {
                        $pdo->prepare("
                            UPDATE tesoreria.movimientos_bancarios
                            SET tipo_movimiento = ?, monto = ?, concepto = ?,
                                fecha_movimiento = CURRENT_DATE, usuario_id = ?, detalle_pago_id = ?
                            WHERE id = ?
                        ")->execute([$tipoMovBanc, $montoTotalFactura, $conceptoMov, $usuarioId, $detallePagoId, $movExistId]);
                    } else {
                        $pdo->prepare("
                            INSERT INTO tesoreria.movimientos_bancarios (
                                cuenta_bancaria_id, tipo_movimiento, monto, referencia,
                                concepto, fecha_movimiento, usuario_id, detalle_pago_id
                            ) VALUES (?, ?, ?, ?, ?, CURRENT_DATE, ?, ?)
                        ")->execute([$ctaId, $tipoMovBanc, $montoTotalFactura, $refContado, $conceptoMov, $usuarioId, $detallePagoId]);
                    }
                } elseif ($codMetContado === 'TARJETA' && $tarjetaContado) {
                    $pdo->prepare("
                        INSERT INTO tesoreria.pago_tarjeta (
                            detalle_pago_id, tipo_tarjeta, marca_tarjeta, ultimos_4_digitos, numero_autorizacion, numero_lote
                        ) VALUES (?, ?, ?, ?, ?, NULL)
                    ")->execute([
                                $detallePagoId,
                                $tarjetaContado['tipo_tarjeta'],
                                $tarjetaContado['marca_tarjeta'],
                                $tarjetaContado['ultimos_4_digitos'],
                                $tarjetaContado['numero_autorizacion']
                            ]);
                }
                if (function_exists('registrarAuditoria')) {
                    registrarAuditoria(
                        $pdo,
                        $usuarioId,
                        null,
                        'LIQUIDAR_EGRESO_CXP',
                        'compras.comprobantes_egreso_cxp',
                        null,
                        [
                            'comprobante_id' => $egresoId,
                            'numero_comprobante' => $numComprobanteContado,
                            'origen' => 'RECEPCION_CONTADO',
                            'recepcion_id' => $recepcionId,
                            'cxp_id' => $cxpId,
                            'proveedor' => $proveedorNombre,
                            'metodo_pago' => $codMetContado,
                            'total_pagado' => $montoTotalFactura,
                            'total_retenciones' => 0.00,
                            'facturas_afectadas' => 1
                        ]
                    );
                }
            }

            $pdo->commit();

            $msgFinal = ($numComprobanteContado !== null)
                ? "Recepción #{$recepcionId} (Factura: {$numFactura}) ingresada en «{$almacenNombre}» y saldada automáticamente al contado con el Comprobante de Egreso {$numComprobanteContado} (CxP marcada como PAGADA)."
                : "Recepción #{$recepcionId} (Factura: {$numFactura}) procesada exitosamente en «{$almacenNombre}». Se actualizó el inventario, Kardex, lotes y cuenta por pagar.";

            echo json_encode([
                'ok' => true,
                'mensaje' => $msgFinal,
                'recepcion_id' => $recepcionId,
                'orden_id' => $ordenId,
                'almacen_id' => $almacenId,
                'numero_comprobante' => $numComprobanteContado
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception("Acción no reconocida: " . htmlspecialchars($accion));
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msgDb = $e->getMessage();
    $mensaje = 'No se pudo guardar la recepción por un error de validación en la base de datos.';

    if ($e->getCode() === '23505') {
        if (stripos($msgDb, 'uk_recepcion_factura') !== false) {
            $mensaje = 'Ya existe una recepción registrada con ese N° de Factura Física para este proveedor. Por favor revisa el número de factura.';
        } elseif (stripos($msgDb, 'uk_recepcion_ncf') !== false) {
            $mensaje = 'Ya existe una recepción registrada con ese NCF para este proveedor. Por favor verifica el número de comprobante fiscal.';
        } elseif (stripos($msgDb, 'uk_movbanco_ref') !== false) {
            $mensaje = 'Ese número de referencia bancaria o cheque ya fue registrado en la cuenta seleccionada.';
        } else {
            $mensaje = 'Ya existe un registro duplicado para esta recepción. Verifica el N° de Factura, el NCF y los lotes ingresados.';
        }
    }

    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'mensaje' => $mensaje
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'mensaje' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
