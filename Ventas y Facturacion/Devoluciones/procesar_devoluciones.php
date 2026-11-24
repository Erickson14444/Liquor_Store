<?php
/**
 * ============================================================================
 * Módulo 4: Ventas • Proceso 6: Devoluciones y Notas de Crédito (B04)
 * Ubicación: Ventas y Facturacion/Devoluciones/procesar_devoluciones.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: ventas.devoluciones, ventas.devoluciones_detalles,
 *         ventas.secuencias_fiscales_dgii, ventas.movimiento_caja,
 *         ventas.cuentas_por_cobrar, ventas.ajustes_cxc,
 *         inventario.movimientos_inventario, inventario.inventario_stock,
 *         inventario.lotes, seguridad.auditoria
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

header('Content-Type: application/json; charset=utf-8');

function responderDev(bool $ok, string $mensaje, array $extra = [], int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode(array_merge([
        'ok' => $ok,
        'status' => $ok ? 'success' : 'error',
        'mensaje' => $mensaje,
        'message' => $mensaje,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$pdo) {
    responderDev(false, 'No hay conexión activa con PostgreSQL.', [], 500);
}

$usuarioSesionId = requerirSesionActiva(true);

if (function_exists('usuarioPuedeAccederDevoluciones')) {
    requerirAccesoModulo(
        usuarioPuedeAccederDevoluciones(),
        true,
        'Acceso restringido: no posees el permiso AUTORIZAR_DEVOLUCIONES.'
    );
}

$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : null;
$datos = is_array($jsonBody) ? array_merge($_GET, $jsonBody) : array_merge($_GET, $_POST);
$accion = trim((string) ($datos['accion'] ?? ''));

// Marcar automáticamente créditos B04 vencidos (> 15 días) antes de consultar
try {
    $pdo->exec("
        UPDATE ventas.devoluciones
        SET estado_saldo = 'VENCIDO'
        WHERE metodo_reembolso = 'SALDO_FAVOR'
          AND estado_saldo IN ('DISPONIBLE', 'USADO_PARCIAL')
          AND fecha_vencimiento_saldo < CURRENT_DATE
    ");
} catch (Throwable $e) {
}

/**
 * Ejecuta un INSERT intentando valores candidatos para columnas con CHECK constraint
 * (ej. tipo_movimiento, tipo_ajuste, metodo_reembolso) dentro de SAVEPOINTs seguros.
 */
function ejecutarInsertConCandidatos(PDO $pdo, array $candidatos, callable $callback)
{
    $ultimoError = null;
    foreach ($candidatos as $idx => $val) {
        $sp = 'sp_dev_cand_' . $idx;
        $pdo->exec("SAVEPOINT {$sp}");
        try {
            $res = $callback($val);
            $pdo->exec("RELEASE SAVEPOINT {$sp}");
            return [$val, $res];
        } catch (Throwable $e) {
            $pdo->exec("ROLLBACK TO SAVEPOINT {$sp}");
            $ultimoError = $e;
        }
    }
    throw $ultimoError ?: new Exception('No se pudo completar el registro en base de datos.');
}

/**
 * Obtiene el almacén y los lotes exactos despachados en el Kardex SALIDA_VENTA
 * para una factura específica.
 */
function obtenerDespachoOriginalFactura(PDO $pdo, int $facturaId): array
{
    $colsMov = obtenerColumnasTabla($pdo, 'inventario', 'movimientos_inventario');
    $selAlm = isset($colsMov['almacen_id']) ? 'm.almacen_id' : 'NULL::int AS almacen_id';

    $stMov = $pdo->prepare("
        SELECT m.id, {$selAlm}
        FROM inventario.movimientos_inventario m
        WHERE m.tipo_movimiento = 'SALIDA_VENTA'
          AND m.documento_referencia_id = ?
        ORDER BY m.id DESC
        LIMIT 1
    ");
    $stMov->execute([$facturaId]);
    $mov = $stMov->fetch(PDO::FETCH_ASSOC);

    $almacenId = intval($mov['almacen_id'] ?? 0);
    if ($almacenId <= 0) {
        $almacenId = (int) $pdo->query("
            SELECT id FROM inventario.almacenes
            WHERE activo = TRUE
            ORDER BY CASE WHEN LOWER(nombre) LIKE '%mostrador%' THEN 0 ELSE 1 END, id ASC
            LIMIT 1
        ")->fetchColumn();
    }

    $lotesPorProd = [];
    if ($mov) {
        $stDet = $pdo->prepare("
            SELECT d.producto_maestro_id, d.lote_id, d.cantidad_unidades, d.costo_unitario_referencia,
                   COALESCE(l.numero_lote, 'SIN-LOTE') AS numero_lote
            FROM inventario.movimiento_inventario_detalles d
            LEFT JOIN inventario.lotes l ON l.id = d.lote_id
            WHERE d.movimiento_id = ?
            ORDER BY d.producto_maestro_id ASC, d.lote_id DESC
        ");
        $stDet->execute([intval($mov['id'])]);
        foreach ($stDet->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $pm = intval($row['producto_maestro_id']);
            $lotesPorProd[$pm][] = [
                'lote_id' => intval($row['lote_id']),
                'numero_lote' => $row['numero_lote'],
                'cantidad' => intval($row['cantidad_unidades']),
                'costo_ref' => floatval($row['costo_unitario_referencia']),
            ];
        }
    }

    return [
        'almacen_id' => $almacenId,
        'lotes_por_prod' => $lotesPorProd,
    ];
}

try {
    // =========================================================================
    // 1. BUSCADOR EN VIVO DE FACTURAS ELEGIBLES PARA DEVOLUCIÓN
    // =========================================================================
    if ($accion === 'buscar_facturas') {
        $q = trim((string) ($datos['q'] ?? ''));
        $params = [];
        $where = ["f.estado IN ('PAGADA', 'EMITIDA', 'ENTREGADA')"];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(
                f.numero_factura ILIKE ?
                OR COALESCE(f.ncf, '') ILIKE ?
                OR COALESCE(f.razon_social_cliente, '') ILIKE ?
                OR COALESCE(f.rnc_cedula_cliente, '') ILIKE ?
                OR COALESCE(p.nombre_razon_social, '') ILIKE ?
                OR COALESCE(p.documento_identidad, '') ILIKE ?
            )";
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $sql = "
            SELECT f.id,
                   f.numero_factura,
                   f.tipo_ncf,
                   COALESCE(f.ncf, 'SIN NCF') AS ncf,
                   f.condicion_pago,
                   f.origen_venta,
                   f.estado,
                   f.monto_total,
                   COALESCE(m.simbolo, 'RD$') AS simbolo,
                   TO_CHAR(f.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_fmt,
                   (CURRENT_DATE - f.fecha_emision::date) AS dias_transcurridos,
                   COALESCE(NULLIF(TRIM(f.razon_social_cliente), ''), p.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
                   COALESCE(NULLIF(TRIM(f.rnc_cedula_cliente), ''), p.documento_identidad, '') AS cliente_doc,
                   COALESCE((SELECT SUM(d.monto_total) FROM ventas.devoluciones d WHERE d.factura_id = f.id), 0) AS monto_ya_devuelto
            FROM ventas.facturas f
            LEFT JOIN identidad.personas p ON p.persona_id = f.cliente_id
            LEFT JOIN tesoreria.monedas m  ON m.id = f.moneda_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY f.fecha_emision DESC, f.id DESC
            LIMIT 25
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$r) {
            $r['monto_total'] = round(floatval($r['monto_total']), 2);
            $r['monto_ya_devuelto'] = round(floatval($r['monto_ya_devuelto']), 2);
            $r['dias_transcurridos'] = intval($r['dias_transcurridos']);
        }
        unset($r);

        responderDev(true, 'OK', ['facturas' => $rows]);
    }

    // =========================================================================
    // 2. CARGAR FACTURA CON UNIDADES DISPONIBLES PARA DEVOLVER Y SALDO CXC
    // =========================================================================
    if ($accion === 'cargar_factura') {
        $facturaId = intval($datos['factura_id'] ?? 0);
        if ($facturaId <= 0) {
            responderDev(false, 'Selecciona una factura válida.');
        }

        $stCab = $pdo->prepare("
            SELECT f.id, f.numero_factura, f.tipo_ncf, COALESCE(f.ncf, 'SIN NCF') AS ncf,
                   f.condicion_pago, f.origen_venta, f.estado,
                   f.monto_subtotal, f.monto_descuento, f.monto_impuestos, f.monto_total,
                   f.moneda_id, f.tasa_cambio,
                   COALESCE(m.codigo_iso, 'DOP') AS codigo_iso,
                   COALESCE(m.simbolo, 'RD$') AS simbolo,
                   TO_CHAR(f.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_fmt,
                   (CURRENT_DATE - f.fecha_emision::date) AS dias_transcurridos,
                   COALESCE(NULLIF(TRIM(f.razon_social_cliente), ''), p.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
                   COALESCE(NULLIF(TRIM(f.rnc_cedula_cliente), ''), p.documento_identidad, '') AS cliente_doc,
                   COALESCE((SELECT SUM(d.monto_total) FROM ventas.devoluciones d WHERE d.factura_id = f.id), 0) AS monto_ya_devuelto
            FROM ventas.facturas f
            LEFT JOIN identidad.personas p ON p.persona_id = f.cliente_id
            LEFT JOIN tesoreria.monedas m  ON m.id = f.moneda_id
            WHERE f.id = ?
            LIMIT 1
        ");
        $stCab->execute([$facturaId]);
        $cab = $stCab->fetch(PDO::FETCH_ASSOC);

        if (!$cab) {
            responderDev(false, 'Factura no encontrada.', [], 404);
        }
        if (!in_array(strtoupper((string) $cab['estado']), ['PAGADA', 'EMITIDA', 'ENTREGADA'], true)) {
            responderDev(false, "La factura {$cab['numero_factura']} está en estado {$cab['estado']} y no admite devoluciones.");
        }

        // Saldo CxC si fue a crédito
        $cxcInfo = null;
        if (strtoupper((string) $cab['condicion_pago']) === 'CREDITO') {
            $stCxc = $pdo->prepare("
                SELECT id, monto_original, saldo_pendiente, estado,
                       TO_CHAR(fecha_vencimiento, 'DD/MM/YYYY') AS vencimiento_fmt
                FROM ventas.cuentas_por_cobrar
                WHERE factura_id = ?
                LIMIT 1
            ");
            $stCxc->execute([$facturaId]);
            $cxcInfo = $stCxc->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // Partidas de factura con lo ya devuelto en Notas de Crédito previas
        $stDet = $pdo->prepare("
            SELECT fd.producto_maestro_id,
                   fd.cantidad_unidades,
                   fd.cantidad_presentacion,
                   fd.factor_conversion,
                   fd.precio_unitario_his,
                   fd.costo_unitario_hist,
                   fd.monto_subtotal,
                   fd.monto_descuento,
                   fd.monto_impuestos,
                   (fd.monto_subtotal - fd.monto_descuento + fd.monto_impuestos) AS monto_linea_calc,
                   pm.sku,
                   COALESCE(
                       NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                       pcb.descripcion_presentacion,
                       pm.sku
                   ) AS producto_nombre,
                   COALESCE(pcb.descripcion_presentacion, 'Unidad Base (x1)') AS presentacion,
                   COALESCE((
                       SELECT SUM(dd.cantidad)
                       FROM ventas.devoluciones_detalles dd
                       JOIN ventas.devoluciones dv ON dv.id = dd.nota_credito_id
                       WHERE dv.factura_id = fd.factura_id
                         AND dd.producto_maestro_id = fd.producto_maestro_id
                   ), 0) AS ya_devuelto_unidades
            FROM ventas.factura_detalle fd
            JOIN inventario.productos_maestro pm ON pm.id = fd.producto_maestro_id
            LEFT JOIN inventario.producto_codigos_barra pcb ON pcb.id = fd.codigo_barra_id
            WHERE fd.factura_id = ?
            ORDER BY pm.sku ASC
        ");
        $stDet->execute([$facturaId]);
        $detalles = $stDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Calcular factor de ajuste si hubo cupón en cabecera (para que el valor unitario reembolsable sea exacto)
        $sumaLineas = 0.0;
        foreach ($detalles as $d) {
            $sumaLineas += floatval($d['monto_linea_calc']);
        }
        $montoFacTotal = floatval($cab['monto_total']);
        $factorAjuste = ($sumaLineas > 0 && $montoFacTotal < $sumaLineas)
            ? ($montoFacTotal / $sumaLineas)
            : 1.0;

        $despachoOrig = obtenerDespachoOriginalFactura($pdo, $facturaId);

        $lineas = [];
        foreach ($detalles as $d) {
            $pmId = intval($d['producto_maestro_id']);
            $udsFac = intval($d['cantidad_unidades']);
            $yaDev = intval($d['ya_devuelto_unidades']);
            $dispDev = max(0, $udsFac - $yaDev);
            $totLn = round(floatval($d['monto_linea_calc']) * $factorAjuste, 2);
            $valUnit = ($udsFac > 0) ? round($totLn / $udsFac, 4) : 0.0;

            $lotesTxt = [];
            foreach ($despachoOrig['lotes_por_prod'][$pmId] ?? [] as $lt) {
                $lotesTxt[] = $lt['numero_lote'] . ' (' . $lt['cantidad'] . ')';
            }

            $lineas[] = [
                'producto_maestro_id' => $pmId,
                'sku' => $d['sku'],
                'producto_nombre' => $d['producto_nombre'],
                'presentacion' => $d['presentacion'],
                'cantidad_unidades' => $udsFac,
                'ya_devuelto' => $yaDev,
                'disponible_devolver' => $dispDev,
                'valor_unitario_neto' => $valUnit,
                'monto_linea_total' => $totLn,
                'lotes_origen' => implode(', ', $lotesTxt),
            ];
        }

        // Verificar si el usuario actual tiene sesión de caja ABIERTA (para EFECTIVO / TARJETA)
        $stSes = $pdo->prepare("
            SELECT id FROM ventas.sesiones_caja
            WHERE cajero_id = ? AND estado = 'ABIERTA'
            ORDER BY id DESC LIMIT 1
        ");
        $stSes->execute([$usuarioSesionId]);
        $miSesionId = $stSes->fetchColumn() ?: null;

        responderDev(true, 'Factura cargada.', [
            'cabecera' => $cab,
            'cxc' => $cxcInfo,
            'lineas' => $lineas,
            'almacen_origen' => $despachoOrig['almacen_id'],
            'tiene_caja_open' => ($miSesionId !== null),
        ]);
    }

    // =========================================================================
    // 3. PROCESAR DEVOLUCIÓN Y EMITIR NOTA DE CRÉDITO B04 (TRANSACCIÓN ATÓMICA)
    // =========================================================================
    if ($accion === 'procesar_devolucion') {
        $facturaId = intval($datos['factura_id'] ?? 0);
        $metodoReemb = strtoupper(trim((string) ($datos['metodo_reembolso'] ?? 'SALDO_FAVOR')));
        $justificacion = trim((string) ($datos['justificacion_motivo'] ?? ''));
        $supervisorIn = trim((string) ($datos['supervisor_id'] ?? $datos['usuario_supervisor'] ?? ''));
        $pinIngresado = trim((string) ($datos['pin_autorizacion'] ?? $datos['pin_supervisor'] ?? ''));
        $itemsRaw = $datos['items'] ?? [];

        if ($facturaId <= 0) {
            throw new Exception('Debes seleccionar la factura original a modificar.');
        }
        if (!in_array($metodoReemb, ['SALDO_FAVOR', 'EFECTIVO', 'TARJETA', 'ABONO_CXC'], true)) {
            throw new Exception('Método de reembolso no válido.');
        }
        if (mb_strlen($justificacion, 'UTF-8') < 5) {
            throw new Exception('Describe el motivo de la devolución (mínimo 5 caracteres).');
        }
        if (!is_array($itemsRaw) || empty($itemsRaw)) {
            throw new Exception('Selecciona al menos un producto y cantidad a devolver.');
        }

        // 1. Validar Supervisor + PIN (BCrypt o plano compatible)
        $supInfo = validarPinSupervisor($pdo, $supervisorIn, $pinIngresado);
        $supervisorId = $supInfo['usuario_id'];

        $pdo->beginTransaction();

        // 2. Bloquear factura original
        $stFac = $pdo->prepare("
            SELECT f.id, f.numero_factura, f.tipo_ncf, f.ncf, f.condicion_pago, f.estado,
                   f.monto_subtotal, f.monto_descuento, f.monto_impuestos, f.monto_total,
                   f.moneda_id, f.tasa_cambio, f.cliente_id,
                   COALESCE(NULLIF(TRIM(f.razon_social_cliente), ''), p.nombre_razon_social, 'Consumidor Final') AS cliente_nombre
            FROM ventas.facturas f
            LEFT JOIN identidad.personas p ON p.persona_id = f.cliente_id
            WHERE f.id = ?
            FOR UPDATE OF f
        ");
        $stFac->execute([$facturaId]);
        $fac = $stFac->fetch(PDO::FETCH_ASSOC);

        if (!$fac) {
            throw new Exception('La factura original no existe.');
        }
        if (!in_array(strtoupper((string) $fac['estado']), ['PAGADA', 'EMITIDA', 'ENTREGADA'], true)) {
            throw new Exception("La factura {$fac['numero_factura']} está en estado {$fac['estado']} y no puede recibir devoluciones.");
        }

        // Validar coherencia de método de reembolso vs condición y caja
        if ($metodoReemb === 'ABONO_CXC' && strtoupper((string) $fac['condicion_pago']) !== 'CREDITO') {
            throw new Exception('El reembolso mediante Abono a CxC solo aplica a facturas emitidas a CRÉDITO.');
        }

        $sesionCajaId = null;
        if (in_array($metodoReemb, ['EFECTIVO', 'TARJETA'], true)) {
            $stSes = $pdo->prepare("
                SELECT id FROM ventas.sesiones_caja
                WHERE cajero_id = ? AND estado = 'ABIERTA'
                ORDER BY id DESC LIMIT 1
                FOR UPDATE
            ");
            $stSes->execute([$usuarioSesionId]);
            $sesIdFound = $stSes->fetchColumn();
            if (!$sesIdFound) {
                throw new Exception("Para reembolsar en {$metodoReemb} necesitas tener una sesión de caja ABIERTA (para cuadrar el Arqueo Ciego).");
            }
            $sesionCajaId = intval($sesIdFound);
        }

        // 3. Cargar líneas originales de factura y devoluciones acumuladas
        $stDetFac = $pdo->prepare("
            SELECT fd.producto_maestro_id, fd.cantidad_unidades, fd.costo_unitario_hist,
                   (fd.monto_subtotal - fd.monto_descuento + fd.monto_impuestos) AS monto_linea_calc,
                   pm.sku,
                   COALESCE(NULLIF(TRIM(pm.nombre), ''), pm.sku) AS producto_nombre,
                   COALESCE((
                       SELECT SUM(dd.cantidad)
                       FROM ventas.devoluciones_detalles dd
                       JOIN ventas.devoluciones dv ON dv.id = dd.nota_credito_id
                       WHERE dv.factura_id = fd.factura_id
                         AND dd.producto_maestro_id = fd.producto_maestro_id
                   ), 0) AS ya_devuelto_unidades
            FROM ventas.factura_detalle fd
            JOIN inventario.productos_maestro pm ON pm.id = fd.producto_maestro_id
            WHERE fd.factura_id = ?
        ");
        $stDetFac->execute([$facturaId]);
        $mapDetFac = [];
        $sumaLineasFac = 0.0;
        foreach ($stDetFac->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $pmId = intval($row['producto_maestro_id']);
            $mapDetFac[$pmId] = $row;
            $sumaLineasFac += floatval($row['monto_linea_calc']);
        }

        $montoFacTotal = round(floatval($fac['monto_total']), 2);
        $factorAjuste = ($sumaLineasFac > 0 && $montoFacTotal < $sumaLineasFac)
            ? ($montoFacTotal / $sumaLineasFac)
            : 1.0;

        $stYaDevMto = $pdo->prepare("SELECT COALESCE(SUM(monto_total), 0) FROM ventas.devoluciones WHERE factura_id = ?");
        $stYaDevMto->execute([$facturaId]);
        $montoYaDevuelto = round(floatval($stYaDevMto->fetchColumn()), 2);
        $techoReembolso = round(max(0.0, $montoFacTotal - $montoYaDevuelto), 2);

        // 4. Validar ítems solicitados y calcular monto exacto a reembolsar
        $itemsProcesar = [];
        $montoTotalDev = 0.0;

        foreach ($itemsRaw as $it) {
            $pmId = intval($it['producto_maestro_id'] ?? 0);
            $cant = intval($it['cantidad'] ?? 0);
            $reing = filter_var($it['reingresado_a_inventario'] ?? true, FILTER_VALIDATE_BOOLEAN);

            if ($pmId <= 0 || $cant <= 0) {
                continue;
            }
            if (!isset($mapDetFac[$pmId])) {
                throw new Exception("El producto #{$pmId} no pertenece a la factura {$fac['numero_factura']}.");
            }

            $orig = $mapDetFac[$pmId];
            $disp = intval($orig['cantidad_unidades']) - intval($orig['ya_devuelto_unidades']);
            if ($cant > $disp) {
                throw new Exception("Cantidad inválida para {$orig['sku']} ({$orig['producto_nombre']}): máximo disponible para devolver es {$disp} ud(s).");
            }

            $totLineaAjust = round(floatval($orig['monto_linea_calc']) * $factorAjuste, 2);
            $mtoLineaDev = round(($totLineaAjust / max(1, intval($orig['cantidad_unidades']))) * $cant, 2);

            $itemsProcesar[] = [
                'producto_maestro_id' => $pmId,
                'sku' => $orig['sku'],
                'producto_nombre' => $orig['producto_nombre'],
                'cantidad' => $cant,
                'costo_unitario_hist' => floatval($orig['costo_unitario_hist']),
                'monto_reembolsado' => $mtoLineaDev,
                'reingresado_a_inventario' => $reing,
            ];
            $montoTotalDev = round($montoTotalDev + $mtoLineaDev, 2);
        }

        if (empty($itemsProcesar) || $montoTotalDev <= 0) {
            throw new Exception('Debes indicar al menos 1 unidad válida para devolver.');
        }

        // Acotar por redondeo al saldo reembolsable restante de la factura
        if ($montoTotalDev > $techoReembolso) {
            if (($montoTotalDev - $techoReembolso) <= 0.10) {
                $difRed = round($montoTotalDev - $techoReembolso, 2);
                $itemsProcesar[count($itemsProcesar) - 1]['monto_reembolsado'] = round(
                    max(0.01, $itemsProcesar[count($itemsProcesar) - 1]['monto_reembolsado'] - $difRed),
                    2
                );
                $montoTotalDev = $techoReembolso;
            } else {
                throw new Exception("El monto de devolución (RD$ " . number_format($montoTotalDev, 2) . ") supera el saldo reembolsable de la factura (RD$ " . number_format($techoReembolso, 2) . ").");
            }
        }

        // 5. Bloquear secuencia fiscal DGII B04 (FOR UPDATE)
        $stSec = $pdo->prepare("
            SELECT id, secuencia_actual, secuencia_hasta, fecha_vencimiento
            FROM ventas.secuencias_fiscales_dgii
            WHERE tipo_comprobante = 'B04'
              AND estado = 'ACTIVO'
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
        ");
        $stSec->execute();
        $secRow = $stSec->fetch(PDO::FETCH_ASSOC);

        if (!$secRow) {
            throw new Exception('No existe un bloque de secuencias fiscales DGII activo para Notas de Crédito (B04). Configúralo en Secuencias Fiscales.');
        }
        if ($secRow['fecha_vencimiento'] < date('Y-m-d')) {
            $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'VENCIDO' WHERE id = ?")->execute([$secRow['id']]);
            throw new Exception("La secuencia B04 venció el {$secRow['fecha_vencimiento']}.");
        }

        $secuenciaB04Id = intval($secRow['id']);
        $numActual = intval($secRow['secuencia_actual']);
        $numHasta = intval($secRow['secuencia_hasta']);

        if ($numActual > $numHasta) {
            $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'AGOTADO' WHERE id = ?")->execute([$secuenciaB04Id]);
            throw new Exception('La secuencia fiscal B04 está agotada.');
        }

        $ncfB04 = 'B04' . str_pad((string) $numActual, 8, '0', STR_PAD_LEFT);
        if ($numActual < $numHasta) {
            $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET secuencia_actual = secuencia_actual + 1 WHERE id = ?")->execute([$secuenciaB04Id]);
        } else {
            $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'AGOTADO' WHERE id = ?")->execute([$secuenciaB04Id]);
        }

        // 6. Preparar datos de Saldo a Favor (15 días) o CxC
        $cxcRow = null;
        $montoAbonarCxc = 0.00;
        $montoExcedenteSf = 0.00;

        if ($metodoReemb === 'ABONO_CXC') {
            $stCxc = $pdo->prepare("
                SELECT id, saldo_pendiente, estado
                FROM ventas.cuentas_por_cobrar
                WHERE factura_id = ?
                FOR UPDATE
            ");
            $stCxc->execute([$facturaId]);
            $cxcRow = $stCxc->fetch(PDO::FETCH_ASSOC);
            if (!$cxcRow || floatval($cxcRow['saldo_pendiente']) <= 0) {
                throw new Exception('Esta factura a crédito ya no posee saldo pendiente en CxC; selecciona Saldo a Favor, Efectivo o Tarjeta.');
            }
            $saldoCxcActual = round(floatval($cxcRow['saldo_pendiente']), 2);
            $montoAbonarCxc = min($montoTotalDev, $saldoCxcActual);
            $montoExcedenteSf = round(max(0.0, $montoTotalDev - $montoAbonarCxc), 2);
        }

        $generaSaldoFavor = ($metodoReemb === 'SALDO_FAVOR' || $montoExcedenteSf > 0);
        $codigoCredito = $generaSaldoFavor ? ('CRD-' . strtoupper(bin2hex(random_bytes(3)))) : null;
        $saldoDisponible = ($metodoReemb === 'SALDO_FAVOR') ? $montoTotalDev : ($montoExcedenteSf > 0 ? $montoExcedenteSf : 0.00);
        $venceSaldo = $generaSaldoFavor ? date('Y-m-d', strtotime('+15 days')) : null;

        // 7. Insertar cabecera en ventas.devoluciones (tolerante a variantes de CHECK constraint)
        $candidatosMetodo = match ($metodoReemb) {
            'SALDO_FAVOR' => ['SALDO_FAVOR'],
            'EFECTIVO' => ['EFECTIVO'],
            'TARJETA' => ['TARJETA'],
            'ABONO_CXC' => ['ABONO_CXC', 'CREDITO_CXC', 'CREDITO', 'NOTA_CREDITO', 'SALDO_FAVOR'],
        };
        $candidatosEstadoSaldo = $generaSaldoFavor ? ['DISPONIBLE'] : ['NO_APLICA', null, 'USADO'];

        $combinaciones = [];
        foreach ($candidatosMetodo as $mCand) {
            foreach ($candidatosEstadoSaldo as $eCand) {
                $combinaciones[] = [$mCand, $eCand];
            }
        }

        $ncfModificado = !empty($fac['ncf']) ? $fac['ncf'] : $fac['numero_factura'];

        [, $devolucionId] = ejecutarInsertConCandidatos($pdo, $combinaciones, function ($par) use ($pdo, $facturaId, $secuenciaB04Id, $ncfB04, $ncfModificado, $montoTotalDev, $justificacion, $supervisorId, $sesionCajaId, $fac, $codigoCredito, $saldoDisponible, $venceSaldo) {
            [$metVal, $estSaldoVal] = $par;
            $stIns = $pdo->prepare("
                INSERT INTO ventas.devoluciones (
                    factura_id, secuencia_dgii_id, ncf_nota_credito, ncf_factura_modificada,
                    monto_total, metodo_reembolso, justificacion_motivo, supervisor_id,
                    fecha_emision, sesion_caja_id, moneda_id, tasa_cambio,
                    codigo_credito, saldo_disponible, fecha_vencimiento_saldo, estado_saldo
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    CURRENT_TIMESTAMP, ?, ?, ?,
                    ?, ?, ?, ?
                )
                RETURNING id
            ");
            $stIns->execute([
                $facturaId,
                $secuenciaB04Id,
                $ncfB04,
                $ncfModificado,
                $montoTotalDev,
                $metVal,
                $justificacion,
                $supervisorId,
                $sesionCajaId,
                intval($fac['moneda_id'] ?? 1),
                floatval($fac['tasa_cambio'] ?? 1.0),
                $codigoCredito,
                $saldoDisponible,
                $venceSaldo,
                $estSaldoVal,
            ]);
            return intval($stIns->fetchColumn());
        });

        // 8. Reingresar mercancía en buen estado a lotes exactos + registrar ventas.devoluciones_detalles
        $despachoOrig = obtenerDespachoOriginalFactura($pdo, $facturaId);
        $almacenId = $despachoOrig['almacen_id'];
        $hayReingreso = false;
        foreach ($itemsProcesar as $ip) {
            if ($ip['reingresado_a_inventario']) {
                $hayReingreso = true;
                break;
            }
        }

        $movKardexId = null;
        if ($hayReingreso) {
            $colsMov = obtenerColumnasTabla($pdo, 'inventario', 'movimientos_inventario');
            [, $movKardexId] = ejecutarInsertConCandidatos(
                $pdo,
                ['ENTRADA_DEVOLUCION', 'DEVOLUCION_CLIENTE', 'DEVOLUCION', 'AJUSTE_ENTRADA'],
                function ($tipoMov) use ($pdo, $colsMov, $ncfB04, $fac, $justificacion, $supervisorId, $devolucionId, $almacenId, $usuarioSesionId) {
                    $campos = ['tipo_movimiento', 'justificacion', 'supervisor_id', 'documento_referencia_id'];
                    $valores = [
                        $tipoMov,
                        "Reingreso por devolución {$ncfB04} (Factura {$fac['numero_factura']}): {$justificacion}",
                        $supervisorId,
                        $devolucionId,
                    ];
                    if (isset($colsMov['almacen_id'])) {
                        $campos[] = 'almacen_id';
                        $valores[] = $almacenId;
                    }
                    if (isset($colsMov['usuario_id'])) {
                        $campos[] = 'usuario_id';
                        $valores[] = $usuarioSesionId;
                    }
                    $ph = implode(', ', array_fill(0, count($campos), '?'));
                    $st = $pdo->prepare("INSERT INTO inventario.movimientos_inventario (" . implode(', ', $campos) . ") VALUES ({$ph}) RETURNING id");
                    $st->execute($valores);
                    return intval($st->fetchColumn());
                }
            );
        }

        $stInsDevDet = $pdo->prepare("
            INSERT INTO ventas.devoluciones_detalles
                (nota_credito_id, producto_maestro_id, lote_id, cantidad, monto_reembolsado, reingresado_a_inventario)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stUpdDevDet = $pdo->prepare("
            UPDATE ventas.devoluciones_detalles
            SET cantidad = cantidad + ?, monto_reembolsado = monto_reembolsado + ?
            WHERE nota_credito_id = ? AND producto_maestro_id = ?
        ");
        $stInsMovDet = $pdo->prepare("
            INSERT INTO inventario.movimiento_inventario_detalles
                (movimiento_id, producto_maestro_id, lote_id, cantidad_unidades, costo_unitario_referencia)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stSumLote = $pdo->prepare("UPDATE inventario.lotes SET cantidad_actual = cantidad_actual + ? WHERE id = ?");
        $stUpStock = $pdo->prepare("
            INSERT INTO inventario.inventario_stock
                (almacen_id, producto_maestro_id, lote_id, stock_fisico_unidades, stock_comprometido)
            VALUES (?, ?, ?, ?, 0)
            ON CONFLICT (almacen_id, producto_maestro_id, lote_id)
            DO UPDATE SET stock_fisico_unidades = inventario.inventario_stock.stock_fisico_unidades + EXCLUDED.stock_fisico_unidades
        ");

        $resumenLineasAud = [];

        foreach ($itemsProcesar as $ip) {
            $pmId = $ip['producto_maestro_id'];
            $cantTot = $ip['cantidad'];
            $mtoTot = $ip['monto_reembolsado'];
            $reing = $ip['reingresado_a_inventario'];
            $lotes = $despachoOrig['lotes_por_prod'][$pmId] ?? [];

            if (empty($lotes)) {
                $lotes = [['lote_id' => 0, 'cantidad' => $cantTot, 'costo_ref' => $ip['costo_unitario_hist']]];
            }

            $restante = $cantTot;
            $mtoAsigAcu = 0.0;

            foreach ($lotes as $idxL => $lt) {
                if ($restante <= 0) {
                    break;
                }
                $esUltimoLote = ($idxL === count($lotes) - 1);
                $tomar = $esUltimoLote ? $restante : min($restante, max(1, intval($lt['cantidad'])));
                $loteId = intval($lt['lote_id']);
                $mtoParte = ($restante === $tomar)
                    ? round(max(0.0, $mtoTot - $mtoAsigAcu), 2)
                    : round($mtoTot * ($tomar / $cantTot), 2);
                $mtoAsigAcu = round($mtoAsigAcu + $mtoParte, 2);

                // Guardar detalle de devolución (manejo seguro si PK no incluye lote_id)
                $pdo->exec("SAVEPOINT sp_dev_det");
                try {
                    $stInsDevDet->bindValue(1, $devolucionId, PDO::PARAM_INT);
                    $stInsDevDet->bindValue(2, $pmId, PDO::PARAM_INT);
                    $stInsDevDet->bindValue(3, $loteId, PDO::PARAM_INT);
                    $stInsDevDet->bindValue(4, $tomar, PDO::PARAM_INT);
                    $stInsDevDet->bindValue(5, $mtoParte);
                    $stInsDevDet->bindValue(6, $reing, PDO::PARAM_BOOL);
                    $stInsDevDet->execute();
                    $pdo->exec("RELEASE SAVEPOINT sp_dev_det");
                } catch (Throwable $eDet) {
                    $pdo->exec("ROLLBACK TO SAVEPOINT sp_dev_det");
                    $stUpdDevDet->execute([$tomar, $mtoParte, $devolucionId, $pmId]);
                }

                // Reintegrar físicamente SOLO si el producto está en buen estado (sellado)
                if ($reing && $movKardexId !== null) {
                    $stUpStock->execute([$almacenId, $pmId, $loteId, $tomar]);
                    if ($loteId > 0) {
                        $stSumLote->execute([$tomar, $loteId]);
                    }
                    $stInsMovDet->execute([$movKardexId, $pmId, $loteId, $tomar, $ip['costo_unitario_hist']]);
                }

                $restante -= $tomar;
            }

            $resumenLineasAud[] = [
                'sku' => $ip['sku'],
                'cantidad' => $cantTot,
                'monto' => $mtoTot,
                'reingresa' => $reing,
            ];
        }

        // 9. Impacto financiero en Caja Chica (EFECTIVO) o CxC (ABONO_CXC)
        if ($metodoReemb === 'EFECTIVO' && $sesionCajaId !== null) {
            $montoDop = round($montoTotalDev * floatval($fac['tasa_cambio'] ?? 1.0), 2);
            $pdo->prepare("
                INSERT INTO ventas.movimiento_caja
                    (sesion_caja_id, tipo_movimiento, monto, concepto, supervisor_id, fecha_hora)
                VALUES (?, 'EGRESO', ?, ?, ?, CURRENT_TIMESTAMP)
            ")->execute([
                        $sesionCajaId,
                        $montoDop,
                        "Reembolso efectivo Nota de Crédito {$ncfB04} (Factura {$fac['numero_factura']}): {$justificacion}",
                        $supervisorId,
                    ]);
        } elseif ($metodoReemb === 'ABONO_CXC' && $cxcRow && $montoAbonarCxc > 0) {
            $saldoAntes = round(floatval($cxcRow['saldo_pendiente']), 2);
            $saldoDespues = round(max(0.0, $saldoAntes - $montoAbonarCxc), 2);
            $estadoCxcNvo = ($saldoDespues <= 0.00) ? 'PAGADA' : 'PAGADA_PARCIAL';

            ejecutarInsertConCandidatos(
                $pdo,
                ['DEVOLUCION', 'NOTA_CREDITO', 'DEVOLUCION_B04', 'NOTA_CREDITO_B04', 'ANULACION_FACTURA'],
                function ($tipoAj) use ($pdo, $cxcRow, $devolucionId, $fac, $montoAbonarCxc, $saldoAntes, $saldoDespues, $ncfB04, $justificacion, $supervisorId) {
                    $pdo->prepare("
                        INSERT INTO ventas.ajustes_cxc (
                            cuenta_por_cobrar_id, tipo_ajuste, devolucion_id, moneda_id, tasa_cambio,
                            monto_ajuste, saldo_anterior, saldo_restante, justificacion, supervisor_id
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                                intval($cxcRow['id']),
                                $tipoAj,
                                $devolucionId,
                                intval($fac['moneda_id'] ?? 1),
                                floatval($fac['tasa_cambio'] ?? 1.0),
                                $montoAbonarCxc,
                                $saldoAntes,
                                $saldoDespues,
                                "Nota de Crédito {$ncfB04} (Factura {$fac['numero_factura']}): {$justificacion}",
                                $supervisorId,
                            ]);
                    return true;
                }
            );

            $pdo->prepare("
                UPDATE ventas.cuentas_por_cobrar
                SET saldo_pendiente = ?, estado = ?
                WHERE id = ?
            ")->execute([$saldoDespues, $estadoCxcNvo, intval($cxcRow['id'])]);
        }

        // 10. Auditoría Forense JSONB
        registrarAuditoria(
            $pdo,
            $usuarioSesionId,
            $supervisorId,
            'VENTA_DEVOLUCION_B04',
            'ventas.devoluciones',
            [
                'factura_id' => $facturaId,
                'numero_factura' => $fac['numero_factura'],
                'ncf_modificado' => $ncfModificado,
            ],
            [
                'devolucion_id' => $devolucionId,
                'ncf_nota_credito' => $ncfB04,
                'monto_total' => $montoTotalDev,
                'metodo_reembolso' => $metodoReemb,
                'codigo_credito' => $codigoCredito,
                'fecha_vencimiento_saldo' => $venceSaldo,
                'justificacion' => $justificacion,
                'supervisor_nombre' => $supInfo['nombre_completo'],
                'lineas' => $resumenLineasAud,
            ]
        );

        $pdo->commit();

        $msg = "Nota de Crédito {$ncfB04} emitida por RD$ " . number_format($montoTotalDev, 2) . " (autorizada por {$supInfo['nombre_completo']}).";
        if ($codigoCredito) {
            $msg .= " Código de Saldo a Favor: {$codigoCredito} (vigente hasta {$venceSaldo}).";
        }

        responderDev(true, $msg, [
            'devolucion_id' => $devolucionId,
            'ncf_nota_credito' => $ncfB04,
            'codigo_credito' => $codigoCredito,
            'saldo_disponible' => $saldoDisponible,
            'fecha_vencimiento_saldo' => $venceSaldo,
            'monto_total' => $montoTotalDev,
        ]);
    }

    // =========================================================================
    // 4. VER DETALLE / COMPROBANTE IMPRIMIBLE DE UNA NOTA DE CRÉDITO B04
    // =========================================================================
    if ($accion === 'detalle_devolucion') {
        $devolucionId = intval($datos['devolucion_id'] ?? 0);
        if ($devolucionId <= 0) {
            responderDev(false, 'ID de devolución inválido.');
        }

        $stCab = $pdo->prepare("
            SELECT d.id, d.factura_id, d.ncf_nota_credito, d.ncf_factura_modificada,
                   d.monto_total, d.metodo_reembolso, d.justificacion_motivo,
                   d.codigo_credito, d.saldo_disponible, d.estado_saldo,
                   TO_CHAR(d.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_emision_fmt,
                   TO_CHAR(d.fecha_vencimiento_saldo, 'DD/MM/YYYY')  AS fecha_vencimiento_fmt,
                   COALESCE(m.simbolo, 'RD$') AS simbolo,
                   f.numero_factura, f.condicion_pago,
                   COALESCE(NULLIF(TRIM(f.razon_social_cliente), ''), pc.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
                   COALESCE(NULLIF(TRIM(f.rnc_cedula_cliente), ''), pc.documento_identidad, '') AS cliente_doc,
                   COALESCE(NULLIF(TRIM(ps.nombre_razon_social), ''), us.username, 'Supervisor') AS supervisor_nombre
            FROM ventas.devoluciones d
            JOIN ventas.facturas f          ON f.id = d.factura_id
            LEFT JOIN identidad.personas pc ON pc.persona_id = f.cliente_id
            LEFT JOIN tesoreria.monedas m   ON m.id = d.moneda_id
            LEFT JOIN seguridad.usuarios us ON us.usuario_id = d.supervisor_id
            LEFT JOIN identidad.personas ps ON ps.persona_id = us.persona_id
            WHERE d.id = ?
            LIMIT 1
        ");
        $stCab->execute([$devolucionId]);
        $cab = $stCab->fetch(PDO::FETCH_ASSOC);

        if (!$cab) {
            responderDev(false, 'Nota de Crédito no encontrada.', [], 404);
        }

        $stDet = $pdo->prepare("
            SELECT dd.producto_maestro_id, dd.lote_id, dd.cantidad, dd.monto_reembolsado, dd.reingresado_a_inventario,
                   pm.sku, COALESCE(NULLIF(TRIM(pm.nombre), ''), pm.sku) AS producto_nombre,
                   COALESCE(l.numero_lote, 'SIN-LOTE') AS numero_lote
            FROM ventas.devoluciones_detalles dd
            JOIN inventario.productos_maestro pm ON pm.id = dd.producto_maestro_id
            LEFT JOIN inventario.lotes l         ON l.id = dd.lote_id
            WHERE dd.nota_credito_id = ?
            ORDER BY pm.sku ASC
        ");
        $stDet->execute([$devolucionId]);
        $detalles = $stDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($detalles as &$d) {
            $d['reingresado_a_inventario'] = ($d['reingresado_a_inventario'] === true || $d['reingresado_a_inventario'] === 't' || $d['reingresado_a_inventario'] == 1);
        }
        unset($d);

        responderDev(true, 'OK', [
            'cabecera' => $cab,
            'detalles' => $detalles,
        ]);
    }

    responderDev(false, 'Acción no reconocida.');
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderDev(false, $e->getMessage(), [], 400);
}