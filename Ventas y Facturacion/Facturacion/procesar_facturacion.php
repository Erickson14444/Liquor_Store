<?php
/**
 * ============================================================================
 * Módulo 4: Ventas • Proceso 3: Facturación (POS) — Controlador Backend Completo
 * Ubicación: Ventas y Facturacion/Facturacion/procesar_facturacion.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Cobertura:
 *   - Fase 1: Almacén POS, Escáner, Catálogo filtrado, Impuestos Dinámicos (cascada/paralelo)
 *   - Fase 2: Motor de Promociones en servidor (CROSS_SELLING, VOLUMEN, HAPPY_HOUR 1NF, FECHAS)
 *             + Canje de Cupones (cupones_descuento) con prorrateo fiscal
 *   - Fase 3: Validación B01 + Asignación concurrente de NCF (FOR UPDATE) + Multimoneda
 *   - Fase 4: Split Universal de Pagos (EFECTIVO, TARJETA, TRANSFERENCIA, CHEQUE, SALDO_FAVOR B04)
 *             con subtipos 1:1 en tesoreria.* y movimientos_bancarios
 *   - Fase 5: Bloqueo estricto de sesión (Control de Caja), Descarga FEFO sincronizada
 *             (inventario_stock + lotes + Kardex SALIDA_VENTA), Emisión automática CUPON_TICKET,
 *             CxC con límite de crédito, Delivery con Triple Filtro + Tarifa, Anulación con PIN
 *             y Auditoría Forense JSONB
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

header('Content-Type: application/json; charset=utf-8');

function responderPos(bool $ok, string $mensaje, array $extra = [], int $httpCode = 200): void
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
    responderPos(false, 'No hay conexión activa con PostgreSQL.', [], 500);
}

$vendedorId = requerirSesionActiva(true);

$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : null;
$datos = is_array($jsonBody) ? array_merge($_GET, $jsonBody) : array_merge($_GET, $_POST);
$accion = trim((string) ($datos['accion'] ?? ''));

// ============================================================================
// FUNCIONES INTERNAS DEL MOTOR DE FACTURACIÓN (IMPUESTOS, PROMOS, DELIVERY)
// ============================================================================

/**
 * Obtiene la moneda seleccionada (o la moneda base DOP por defecto).
 */
function obtenerMonedaFactura(PDO $pdo, int $monedaId): array
{
    if ($monedaId > 0) {
        $stmt = $pdo->prepare("
            SELECT id, codigo_iso, nombre, simbolo, tasa_cambio_referencia, es_moneda_base
            FROM tesoreria.monedas
            WHERE id = ? AND activa = TRUE
            LIMIT 1
        ");
        $stmt->execute([$monedaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    $rowBase = $pdo->query("
        SELECT id, codigo_iso, nombre, simbolo, tasa_cambio_referencia, es_moneda_base
        FROM tesoreria.monedas
        WHERE activa = TRUE
        ORDER BY CASE WHEN es_moneda_base = TRUE THEN 0 ELSE 1 END, id ASC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    return $rowBase ?: [
        'id' => 1,
        'codigo_iso' => 'DOP',
        'nombre' => 'Peso Dominicano',
        'simbolo' => 'RD$',
        'tasa_cambio_referencia' => 1.0000,
        'es_moneda_base' => true,
    ];
}

/**
 * Obtiene los impuestos dinámicos asignados a un producto maestro, ordenados
 * por orden_calculo (para soportar cálculo paralelo y en cascada: ISC -> ITBIS).
 */
function obtenerImpuestosProducto(PDO $pdo, int $productoId): array
{
    static $cacheImp = [];
    static $fallbackItbis = null;

    if (isset($cacheImp[$productoId])) {
        return $cacheImp[$productoId];
    }

    $stmt = $pdo->prepare("
        SELECT i.id, i.siglas, i.nombre, i.tipo_calculo, i.tasa_o_monto, i.orden_calculo
        FROM inventario.producto_impuestos pi
        JOIN tesoreria.impuestos i ON i.id = pi.impuesto_id
        WHERE pi.producto_maestro_id = ?
          AND i.activo = TRUE
        ORDER BY i.orden_calculo ASC, i.id ASC
    ");
    $stmt->execute([$productoId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($rows)) {
        if ($fallbackItbis === null) {
            $fallbackItbis = $pdo->query("
                SELECT id, siglas, nombre, tipo_calculo, tasa_o_monto, orden_calculo
                FROM tesoreria.impuestos
                WHERE activo = TRUE AND UPPER(siglas) LIKE 'ITBIS%'
                ORDER BY id ASC
                LIMIT 1
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $rows = $fallbackItbis;
    }

    $cacheImp[$productoId] = $rows;
    return $rows;
}

/**
 * Calcula los impuestos en paralelo (mismo orden_calculo) y cascada (distinto orden_calculo)
 * sobre una base neta de línea (después de descuento de promoción y cupón prorrateado).
 */
function calcularImpuestosLinea(array $impuestosCfg, float $baseNeta, int $cantidadUnidades): array
{
    $niveles = [];
    foreach ($impuestosCfg as $imp) {
        $ord = intval($imp['orden_calculo'] ?? 1);
        $niveles[$ord][] = $imp;
    }
    ksort($niveles);

    $baseAcumulada = max(0.0, $baseNeta);
    $desglose = [];
    $totalImpLinea = 0.0;

    foreach ($niveles as $impuestosNivel) {
        $sumaNivel = 0.0;
        foreach ($impuestosNivel as $imp) {
            $tipo = strtoupper((string) $imp['tipo_calculo']);
            $tasa = floatval($imp['tasa_o_monto']);
            $base = round($baseAcumulada, 2);

            if ($tipo === 'MONTO_FIJO') {
                $monto = round($tasa * $cantidadUnidades, 2);
            } else {
                $monto = round($base * ($tasa / 100.0), 2);
            }

            $desglose[] = [
                'impuesto_id' => intval($imp['id']),
                'siglas' => $imp['siglas'],
                'nombre' => $imp['nombre'],
                'orden_calculo' => intval($imp['orden_calculo']),
                'tipo_calculo_aplicado' => $tipo,
                'base_imponible' => $base,
                'tasa_aplicada' => $tasa,
                'monto_impuesto' => $monto,
            ];

            $sumaNivel += $monto;
            $totalImpLinea += $monto;
        }
        // Cascada: los niveles superiores se calculan sobre base + impuestos de niveles previos
        $baseAcumulada = round($baseAcumulada + $sumaNivel, 2);
    }

    return [
        'total_impuestos' => round($totalImpLinea, 2),
        'desglose' => $desglose,
    ];
}

/**
 * Evalúa el carrito completo en el servidor:
 *  - Verifica productos, presentaciones, factores, precios, costos y stock disponible en el almacén
 *  - Consolida por producto_maestro_id (respetando PK compuesta de factura_detalle)
 *  - Aplica las 4 campañas activas de línea (HAPPY_HOUR 1NF, CROSS_SELLING, VOLUMEN, FECHAS)
 *  - Valida y prorratea el Cupón de Descuento (antes de impuestos)
 *  - Calcula impuestos dinámicos (cascada/paralelo)
 *  - Suma tarifa de Delivery si corresponde
 *  - Verifica si la venta califica para generar un nuevo Cupón Cashback (CUPON_TICKET)
 */
function evaluarCarritoServidor(
    PDO $pdo,
    array $partidasRaw,
    int $almacenId,
    string $cuponCodigo = '',
    string $origenVenta = 'MOSTRADOR',
    int $zonaDeliveryId = 0,
    ?string $clienteUuid = null
): array {
    if (empty($partidasRaw)) {
        return [
            'lineas' => [],
            'monto_subtotal' => 0.00,
            'monto_desc_promos' => 0.00,
            'monto_desc_cupon' => 0.00,
            'monto_descuento' => 0.00,
            'monto_impuestos' => 0.00,
            'resumen_impuestos' => [],
            'tarifa_delivery' => 0.00,
            'monto_total' => 0.00,
            'cupon_aplicado' => null,
            'cupon_cashback_prev' => null,
            'contiene_alcohol' => false,
            'alertas_stock' => [],
        ];
    }

    $stmtProd = $pdo->prepare("
        SELECT pm.id, pm.sku, pm.categoria_id, pm.marca_id, pm.requiere_lote,
               pm.costo_promedio, pm.precio_base_unidad,
               COALESCE(
                   NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                   (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                   pm.sku
               ) AS nombre,
               COALESCE(cp.nombre, 'General') AS categoria_nombre
        FROM inventario.productos_maestro pm
        LEFT JOIN inventario.categoria_producto cp ON cp.id = pm.categoria_id
        WHERE pm.id = ? AND pm.id > 0
        LIMIT 1
    ");

    $stmtPres = $pdo->prepare("
        SELECT id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion
        FROM inventario.producto_codigos_barra
        WHERE id = ? AND producto_maestro_id = ?
        LIMIT 1
    ");

    $stmtStockAlm = $pdo->prepare("
        SELECT COALESCE(SUM(stock_fisico_unidades - stock_comprometido), 0)
        FROM inventario.inventario_stock
        WHERE almacen_id = ? AND producto_maestro_id = ?
    ");

    // 1. Consolidar partidas por producto_maestro_id desde catálogo real en BD
    $consolidadas = [];
    $contieneAlcohol = false;

    foreach ($partidasRaw as $raw) {
        $pmId = intval($raw['producto_maestro_id'] ?? 0);
        $cant = intval($raw['cantidad'] ?? 0);
        $cbId = !empty($raw['codigo_barra_id']) ? intval($raw['codigo_barra_id']) : null;
        if ($pmId <= 0 || $cant <= 0) {
            continue;
        }

        $stmtProd->execute([$pmId]);
        $prod = $stmtProd->fetch(PDO::FETCH_ASSOC);
        if (!$prod) {
            throw new Exception("El producto #{$pmId} no existe en el catálogo maestro.");
        }

        $factor = 1;
        $precioPres = floatval($prod['precio_base_unidad']);
        $descPres = 'Unidad Base (x1)';
        $codigoBar = $prod['sku'];

        if ($cbId !== null && $cbId > 0) {
            $stmtPres->execute([$cbId, $pmId]);
            $pres = $stmtPres->fetch(PDO::FETCH_ASSOC);
            if ($pres) {
                $factor = max(1, intval($pres['factor_multiplicador']));
                $precioPres = floatval($pres['precio_venta_presentacion']);
                $descPres = $pres['descripcion_presentacion'];
                $codigoBar = $pres['codigo_barra'];
            } else {
                $cbId = null;
            }
        }

        $unidadesLinea = $cant * $factor;
        $brutoAporte = round($cant * $precioPres, 2);

        // Detectar si requiere confirmación de mayoría de edad (categorías no exentas de licor)
        $catLow = mb_strtolower($prod['categoria_nombre'] . ' ' . $prod['nombre'], 'UTF-8');
        if (!preg_match('/(hielo|vaso|agua|refresco|soda|jugo|energizante|snack|mezclador)/u', $catLow)) {
            $contieneAlcohol = true;
        }

        if (!isset($consolidadas[$pmId])) {
            $stockDisp = 0;
            if ($almacenId > 0) {
                $stmtStockAlm->execute([$almacenId, $pmId]);
                $stockDisp = intval($stmtStockAlm->fetchColumn());
            }

            $consolidadas[$pmId] = [
                'producto_maestro_id' => $pmId,
                'sku' => $prod['sku'],
                'producto_nombre' => $prod['nombre'],
                'categoria_id' => intval($prod['categoria_id']),
                'marca_id' => intval($prod['marca_id']),
                'categoria_nombre' => $prod['categoria_nombre'],
                'requiere_lote' => ($prod['requiere_lote'] === true || $prod['requiere_lote'] === 't' || $prod['requiere_lote'] == 1),
                'codigo_barra_id' => $cbId,
                'codigo_barra' => $codigoBar,
                'descripcion_presentacion' => $descPres,
                'factor_conversion' => $factor,
                'cantidad_presentacion' => $cant,
                'cantidad_unidades' => $unidadesLinea,
                'precio_unitario_his' => round($precioPres, 2),
                'costo_unitario_hist' => round(floatval($prod['costo_promedio']), 2),
                'monto_subtotal' => $brutoAporte,
                'stock_disponible_almacen' => $stockDisp,
                'promocion_aplicada' => null,
                'etiqueta_promo' => '',
                'monto_descuento_promo' => 0.00,
                'promo_hint' => '',
                'cupon_prorrateado' => 0.00,
                'monto_impuestos' => 0.00,
                'impuestos_detalle' => [],
                'monto_total_linea' => $brutoAporte,
            ];
        } else {
            // Si el mismo producto maestro entra con distinta presentación, consolidar en unidades base
            $c = &$consolidadas[$pmId];
            $c['cantidad_unidades'] += $unidadesLinea;
            $c['monto_subtotal'] = round($c['monto_subtotal'] + $brutoAporte, 2);

            if ($c['codigo_barra_id'] === $cbId && $c['factor_conversion'] === $factor) {
                $c['cantidad_presentacion'] += $cant;
            } else {
                $c['codigo_barra_id'] = null;
                $c['factor_conversion'] = 1;
                $c['cantidad_presentacion'] = $c['cantidad_unidades'];
                $c['descripcion_presentacion'] = 'Consolidado (' . $c['cantidad_unidades'] . ' uds. base)';
                $c['precio_unitario_his'] = round($c['monto_subtotal'] / max(1, $c['cantidad_unidades']), 2);
            }
            unset($c);
        }
    }

    if (empty($consolidadas)) {
        throw new Exception('No hay líneas válidas en el carrito.');
    }

    // 2. Evaluar Motor de Promociones en servidor (HAPPY_HOUR 1NF, CROSS_SELLING, VOLUMEN, FECHAS)
    $stmtPromos = $pdo->query("
        SELECT pc.id AS promocion_id,
               pc.nombre_promocion,
               pc.tipo_promocion,
               pc.hora_inicio,
               pc.hora_fin,
               pd.id AS regla_id,
               pd.condicion_producto_id,
               pd.condicion_categoria_id,
               pd.condicion_marca_id,
               pd.cantidad_requerida,
               pd.beneficio_producto_id,
               pd.beneficio_porcentaje,
               pd.beneficio_monto_fijo
        FROM promociones.promociones_campanas pc
        JOIN promociones.promociones_detalles pd ON pd.promocion_id = pc.id
        WHERE pc.estado = 'ACTIVA'
          AND pc.tipo_promocion IN ('VOLUMEN', 'CROSS_SELLING', 'HAPPY_HOUR', 'FECHAS')
          AND CURRENT_DATE BETWEEN pc.fecha_inicio AND pc.fecha_fin
          AND (
              pc.tipo_promocion <> 'HAPPY_HOUR'
              OR (
                  (pc.hora_inicio IS NULL OR CURRENT_TIME >= pc.hora_inicio)
                  AND (pc.hora_fin IS NULL OR CURRENT_TIME <= pc.hora_fin)
                  AND (
                      NOT EXISTS (SELECT 1 FROM promociones.promociones_dias pdia0 WHERE pdia0.promocion_id = pc.id)
                      OR EXISTS (
                          SELECT 1
                          FROM promociones.promociones_dias pdia
                          WHERE pdia.promocion_id = pc.id
                            AND pdia.dia_semana = EXTRACT(ISODOW FROM CURRENT_TIMESTAMP)::smallint
                      )
                  )
              )
          )
        ORDER BY pc.id DESC, pd.id ASC
    ");
    $reglasPromo = $stmtPromos->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 2A. Primero evaluar combos CROSS_SELLING (la condición en una línea descuenta beneficio_producto_id en otra)
    foreach ($reglasPromo as $reg) {
        if ($reg['tipo_promocion'] !== 'CROSS_SELLING') {
            continue;
        }
        $condProdId = intval($reg['condicion_producto_id'] ?? 0);
        $condCatId = intval($reg['condicion_categoria_id'] ?? 0);
        $condMarcaId = intval($reg['condicion_marca_id'] ?? 0);
        $benProdId = intval($reg['beneficio_producto_id'] ?? 0);
        $cantReq = max(1, intval($reg['cantidad_requerida'] ?? 1));

        if ($benProdId <= 0 || !isset($consolidadas[$benProdId])) {
            continue;
        }

        // Contar cuántas unidades en el carrito cumplen la condición
        $unidadesCondicion = 0;
        foreach ($consolidadas as $ln) {
            if ($ln['producto_maestro_id'] === $benProdId && $condProdId !== $benProdId) {
                continue;
            }
            $okProd = ($condProdId === 0 || $ln['producto_maestro_id'] === $condProdId);
            $okCat = ($condCatId === 0 || $ln['categoria_id'] === $condCatId);
            $okMarca = ($condMarcaId === 0 || $ln['marca_id'] === $condMarcaId);
            if ($okProd && $okCat && $okMarca) {
                $unidadesCondicion += $ln['cantidad_unidades'];
            }
        }

        $bloques = intdiv($unidadesCondicion, $cantReq);
        if ($bloques <= 0) {
            continue;
        }

        $lineaBen = &$consolidadas[$benProdId];
        $udsAfect = min($lineaBen['cantidad_unidades'], $bloques);
        $precioUnit = ($lineaBen['cantidad_unidades'] > 0)
            ? ($lineaBen['monto_subtotal'] / $lineaBen['cantidad_unidades'])
            : 0.0;

        $porc = floatval($reg['beneficio_porcentaje'] ?? 0);
        $fijo = floatval($reg['beneficio_monto_fijo'] ?? 0);
        $desc = 0.0;
        if ($porc > 0) {
            $desc = round(($udsAfect * $precioUnit) * ($porc / 100.0), 2);
        } elseif ($fijo > 0) {
            $desc = round($udsAfect * $fijo, 2);
        }
        $desc = min($lineaBen['monto_subtotal'], max(0.0, $desc));

        if ($desc > $lineaBen['monto_descuento_promo']) {
            $lineaBen['monto_descuento_promo'] = $desc;
            $lineaBen['promocion_aplicada'] = intval($reg['promocion_id']);
            $lineaBen['etiqueta_promo'] = 'Combo • ' . $reg['nombre_promocion'];
        }
        unset($lineaBen);
    }

    // 2B. Evaluar VOLUMEN, HAPPY_HOUR y FECHAS por cada línea (escoge el mayor beneficio válido)
    foreach ($consolidadas as &$ln) {
        $mejorDesc = $ln['monto_descuento_promo'];
        $mejorPromo = $ln['promocion_aplicada'];
        $mejorEtiq = $ln['etiqueta_promo'];
        $hintPend = '';

        foreach ($reglasPromo as $reg) {
            if ($reg['tipo_promocion'] === 'CROSS_SELLING') {
                continue;
            }
            $condProdId = intval($reg['condicion_producto_id'] ?? 0);
            $condCatId = intval($reg['condicion_categoria_id'] ?? 0);
            $condMarcaId = intval($reg['condicion_marca_id'] ?? 0);
            $cantReq = max(1, intval($reg['cantidad_requerida'] ?? 1));

            $okProd = ($condProdId === 0 || $ln['producto_maestro_id'] === $condProdId);
            $okCat = ($condCatId === 0 || $ln['categoria_id'] === $condCatId);
            $okMarca = ($condMarcaId === 0 || $ln['marca_id'] === $condMarcaId);
            if (!($okProd && $okCat && $okMarca)) {
                continue;
            }

            $porc = floatval($reg['beneficio_porcentaje'] ?? 0);
            $fijo = floatval($reg['beneficio_monto_fijo'] ?? 0);
            $benTxt = $porc > 0 ? "-{$porc}%" : ($fijo > 0 ? '-RD$ ' . number_format($fijo, 2) : '');

            if ($ln['cantidad_unidades'] < $cantReq) {
                if ($hintPend === '' && $benTxt !== '') {
                    $faltan = $cantReq - $ln['cantidad_unidades'];
                    $hintPend = "Faltan {$faltan} ud(s). para {$benTxt} ({$reg['nombre_promocion']})";
                }
                continue;
            }

            $desc = 0.0;
            if ($porc > 0) {
                $desc = round($ln['monto_subtotal'] * ($porc / 100.0), 2);
            } elseif ($fijo > 0) {
                $bloques = ($reg['tipo_promocion'] === 'VOLUMEN')
                    ? intdiv($ln['cantidad_unidades'], $cantReq)
                    : $ln['cantidad_unidades'];
                $desc = round($bloques * $fijo, 2);
            }
            $desc = min($ln['monto_subtotal'], max(0.0, $desc));

            if ($desc > $mejorDesc) {
                $mejorDesc = $desc;
                $mejorPromo = intval($reg['promocion_id']);
                $prefijo = ($reg['tipo_promocion'] === 'HAPPY_HOUR') ? 'Happy Hour • ' : '';
                $mejorEtiq = $prefijo . $reg['nombre_promocion'];
            }
        }

        $ln['monto_descuento_promo'] = $mejorDesc;
        $ln['promocion_aplicada'] = $mejorPromo;
        $ln['etiqueta_promo'] = $mejorEtiq;
        $ln['promo_hint'] = ($mejorDesc <= 0) ? $hintPend : '';
    }
    unset($ln);

    // 3. Validar Cupón Promocional de Cabecera (si fue ingresado) y prorratear sobre base antes de impuestos
    $subtotalBrutoTotal = 0.0;
    $descPromosTotal = 0.0;
    $baseTrasPromosTotal = 0.0;

    foreach ($consolidadas as $ln) {
        $subtotalBrutoTotal += $ln['monto_subtotal'];
        $descPromosTotal += $ln['monto_descuento_promo'];
        $baseTrasPromosTotal += max(0.0, $ln['monto_subtotal'] - $ln['monto_descuento_promo']);
    }
    $subtotalBrutoTotal = round($subtotalBrutoTotal, 2);
    $descPromosTotal = round($descPromosTotal, 2);
    $baseTrasPromosTotal = round($baseTrasPromosTotal, 2);

    $cuponAplicado = null;
    $descCuponAplicar = 0.00;
    $cuponCodigo = strtoupper(trim($cuponCodigo));

    if ($cuponCodigo !== '') {
        $stmtCup = $pdo->prepare("
            SELECT c.id, c.codigo_cupon, c.promocion_id, c.cliente_id, c.monto_credito,
                   c.fecha_vencimiento, c.estado, pc.nombre_promocion
            FROM promociones.cupones_descuento c
            LEFT JOIN promociones.promociones_campanas pc ON pc.id = c.promocion_id
            WHERE UPPER(TRIM(c.codigo_cupon)) = ?
            LIMIT 1
        ");
        $stmtCup->execute([$cuponCodigo]);
        $cupRow = $stmtCup->fetch(PDO::FETCH_ASSOC);

        if (!$cupRow) {
            throw new Exception("El cupón '{$cuponCodigo}' no existe.");
        }
        if (strtoupper((string) $cupRow['estado']) !== 'DISPONIBLE') {
            throw new Exception("El cupón '{$cuponCodigo}' no está disponible (estado: {$cupRow['estado']}).");
        }
        if ($cupRow['fecha_vencimiento'] < date('Y-m-d')) {
            throw new Exception("El cupón '{$cuponCodigo}' venció el {$cupRow['fecha_vencimiento']}.");
        }
        if (!empty($cupRow['cliente_id']) && !empty($clienteUuid) && $cupRow['cliente_id'] !== $clienteUuid) {
            throw new Exception("El cupón '{$cuponCodigo}' es exclusivo de otro cliente registrado.");
        }

        // Un cupón promocional nunca puede exceder la base imponible restante (chk_factura_descuento)
        $descCuponAplicar = round(min(floatval($cupRow['monto_credito']), $baseTrasPromosTotal), 2);
        $cuponAplicado = [
            'id' => intval($cupRow['id']),
            'codigo_cupon' => $cupRow['codigo_cupon'],
            'nombre_promocion' => $cupRow['nombre_promocion'] ?? 'Cupón Promocional',
            'monto_credito' => floatval($cupRow['monto_credito']),
            'monto_aplicado' => $descCuponAplicar,
            'fecha_vencimiento' => $cupRow['fecha_vencimiento'],
        ];
    }

    // Prorratear descuento de cupón proporcionalmente entre líneas (para calcular impuestos DGII exactos)
    if ($descCuponAplicar > 0 && $baseTrasPromosTotal > 0) {
        $claves = array_keys($consolidadas);
        $ultimaKey = end($claves);
        $acumProrr = 0.0;
        foreach ($consolidadas as $k => &$ln) {
            $baseLn = max(0.0, $ln['monto_subtotal'] - $ln['monto_descuento_promo']);
            if ($k === $ultimaKey) {
                $prorr = round(max(0.0, $descCuponAplicar - $acumProrr), 2);
            } else {
                $prorr = round($descCuponAplicar * ($baseLn / $baseTrasPromosTotal), 2);
                $prorr = min($prorr, $baseLn);
                $acumProrr = round($acumProrr + $prorr, 2);
            }
            $ln['cupon_prorrateado'] = $prorr;
        }
        unset($ln);
    }

    // 4. Calcular Impuestos Dinámicos por línea (paralelo/cascada) y totales finales
    $impuestosTotal = 0.0;
    $resumenImpuestos = [];
    $alertasStock = [];

    foreach ($consolidadas as &$ln) {
        $baseNeta = round(max(0.0, $ln['monto_subtotal'] - $ln['monto_descuento_promo'] - $ln['cupon_prorrateado']), 2);
        $impCfg = obtenerImpuestosProducto($pdo, $ln['producto_maestro_id']);
        $resImp = calcularImpuestosLinea($impCfg, $baseNeta, $ln['cantidad_unidades']);

        $ln['monto_impuestos'] = $resImp['total_impuestos'];
        $ln['impuestos_detalle'] = $resImp['desglose'];
        $ln['monto_total_linea'] = round(($ln['monto_subtotal'] - $ln['monto_descuento_promo']) + $resImp['total_impuestos'], 2);

        $impuestosTotal += $resImp['total_impuestos'];

        foreach ($resImp['desglose'] as $di) {
            $sig = $di['siglas'];
            if (!isset($resumenImpuestos[$sig])) {
                $resumenImpuestos[$sig] = [
                    'siglas' => $sig,
                    'nombre' => $di['nombre'],
                    'monto' => 0.0,
                ];
            }
            $resumenImpuestos[$sig]['monto'] = round($resumenImpuestos[$sig]['monto'] + $di['monto_impuesto'], 2);
        }

        if ($almacenId > 0 && $ln['cantidad_unidades'] > $ln['stock_disponible_almacen']) {
            $alertasStock[] = "Stock insuficiente para {$ln['sku']} ({$ln['producto_nombre']}): disponible {$ln['stock_disponible_almacen']} uds., requerido {$ln['cantidad_unidades']} uds.";
        }
    }
    unset($ln);

    $impuestosTotal = round($impuestosTotal, 2);
    $descuentoTotal = round(min($subtotalBrutoTotal, $descPromosTotal + $descCuponAplicar), 2);

    // 5. Tarifa de Delivery si aplica
    $tarifaDelivery = 0.00;
    if (strtoupper($origenVenta) === 'DELIVERY' && $zonaDeliveryId > 0) {
        $stmtZ = $pdo->prepare("SELECT tarifa_envio FROM ventas.zonas_delivery WHERE id = ? AND activa = TRUE LIMIT 1");
        $stmtZ->execute([$zonaDeliveryId]);
        $t = $stmtZ->fetchColumn();
        if ($t !== false) {
            $tarifaDelivery = round(floatval($t), 2);
        }
    }

    $montoTotal = round(max(0.0, ($subtotalBrutoTotal - $descuentoTotal) + $impuestosTotal + $tarifaDelivery), 2);

    // 6. Preview de Cupón Cashback (CUPON_TICKET) si supera monto_minimo_factura
    $cuponCashbackPrev = null;
    $stmtCb = $pdo->prepare("
        SELECT pc.id AS promocion_id, pc.nombre_promocion, pc.monto_minimo_factura,
               pd.beneficio_monto_fijo, pd.beneficio_porcentaje
        FROM promociones.promociones_campanas pc
        JOIN promociones.promociones_detalles pd ON pd.promocion_id = pc.id
        WHERE pc.estado = 'ACTIVA'
          AND pc.tipo_promocion = 'CUPON_TICKET'
          AND CURRENT_DATE BETWEEN pc.fecha_inicio AND pc.fecha_fin
          AND COALESCE(pc.monto_minimo_factura, 0) <= ?
        ORDER BY pc.monto_minimo_factura DESC, pc.id DESC
        LIMIT 1
    ");
    $stmtCb->execute([$montoTotal]);
    $cbRow = $stmtCb->fetch(PDO::FETCH_ASSOC);
    if ($cbRow) {
        $fijo = floatval($cbRow['beneficio_monto_fijo'] ?? 0);
        $porc = floatval($cbRow['beneficio_porcentaje'] ?? 0);
        $valorPremio = $fijo > 0 ? round($fijo, 2) : ($porc > 0 ? round($montoTotal * ($porc / 100.0), 2) : 0.0);
        if ($valorPremio > 0) {
            $cuponCashbackPrev = [
                'promocion_id' => intval($cbRow['promocion_id']),
                'nombre_promocion' => $cbRow['nombre_promocion'],
                'monto_minimo_factura' => floatval($cbRow['monto_minimo_factura']),
                'monto_credito_ganado' => $valorPremio,
            ];
        }
    }

    return [
        'lineas' => array_values($consolidadas),
        'monto_subtotal' => $subtotalBrutoTotal,
        'monto_desc_promos' => $descPromosTotal,
        'monto_desc_cupon' => $descCuponAplicar,
        'monto_descuento' => $descuentoTotal,
        'monto_impuestos' => $impuestosTotal,
        'resumen_impuestos' => array_values($resumenImpuestos),
        'tarifa_delivery' => $tarifaDelivery,
        'monto_total' => $montoTotal,
        'cupon_aplicado' => $cuponAplicado,
        'cupon_cashback_prev' => $cuponCashbackPrev,
        'contiene_alcohol' => $contieneAlcohol,
        'alertas_stock' => $alertasStock,
    ];
}

/**
 * Triple Filtro de Delivery (Zona + Horario ISODOW/CURRENT_TIME + Capacidad disponible).
 */
function consultarRepartidoresTripleFiltro(PDO $pdo, int $zonaId): array
{
    $stmt = $pdo->prepare("
        SELECT r.empleado_id AS repartidor_id,
               e.codigo_empleado,
               p.nombre_razon_social AS nombre_completo,
               p.telefono_principal,
               r.tipo_vehiculo,
               COALESCE(r.placa_vehiculo, '') AS placa_vehiculo,
               r.estado_logistico,
               r.capacidad_pedidos,
               rz.es_ruta_principal,
               (
                   SELECT COUNT(*)
                   FROM ventas.pedidos_delivery pd
                   WHERE pd.repartidor_id = r.empleado_id
                     AND pd.estado_delivery IN ('PENDIENTE', 'EN_CAMINO')
               ) AS pedidos_activos,
               EXISTS (
                   SELECT 1
                   FROM ventas.repartidor_horarios rh
                   WHERE rh.repartidor_id = r.empleado_id
                     AND rh.dia_semana = EXTRACT(ISODOW FROM CURRENT_TIMESTAMP)::int
                     AND CURRENT_TIME BETWEEN rh.hora_inicio AND rh.hora_fin
               ) AS en_horario_activo
        FROM ventas.repartidor_zonas rz
        JOIN identidad.repartidores r ON r.empleado_id = rz.repartidor_id
        JOIN identidad.empleados e    ON e.empleado_id = r.empleado_id
        JOIN identidad.personas p     ON p.persona_id = e.persona_id
        WHERE rz.zona_id = ?
          AND e.estado_laboral = 'ACTIVO'
          AND r.estado_logistico IN ('BASE', 'EN_RUTA_CON_ESPACIO')
        ORDER BY
            EXISTS (
                SELECT 1
                FROM ventas.repartidor_horarios rh2
                WHERE rh2.repartidor_id = r.empleado_id
                  AND rh2.dia_semana = EXTRACT(ISODOW FROM CURRENT_TIMESTAMP)::int
                  AND CURRENT_TIME BETWEEN rh2.hora_inicio AND rh2.hora_fin
            ) DESC,
            rz.es_ruta_principal DESC,
            CASE WHEN r.estado_logistico = 'BASE' THEN 0 ELSE 1 END ASC,
            p.nombre_razon_social ASC
    ");
    $stmt->execute([$zonaId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$r) {
        $activos = intval($r['pedidos_activos']);
        $cap = intval($r['capacidad_pedidos']);
        $enHor = ($r['en_horario_activo'] === true || $r['en_horario_activo'] === 't' || $r['en_horario_activo'] == 1);
        $r['es_ruta_principal'] = ($r['es_ruta_principal'] === true || $r['es_ruta_principal'] === 't' || $r['es_ruta_principal'] == 1);
        $r['en_horario_activo'] = $enHor;
        $r['pedidos_activos'] = $activos;
        $r['capacidad_pedidos'] = $cap;
        $r['tiene_capacidad'] = ($activos < $cap);
        $r['elegible_triple'] = ($enHor && $activos < $cap);
    }
    unset($r);

    return $rows;
}

/**
 * Busca y valida una Nota de Crédito B04 con Saldo a Favor vigente (15 días).
 */
function buscarCreditoB04Vigente(PDO $pdo, string $codigoOCredito, int $monedaId, bool $forUpdate = false): array
{
    $colsDev = obtenerColumnasTabla($pdo, 'ventas', 'devoluciones');
    if (!isset($colsDev['saldo_disponible']) || !isset($colsDev['estado_saldo'])) {
        throw new Exception('La tabla ventas.devoluciones aún no tiene aplicada la migración de saldo a favor (saldo_disponible / fecha_vencimiento_saldo).');
    }

    $codigo = strtoupper(trim($codigoOCredito));
    if ($codigo === '') {
        throw new Exception('Ingresa o escanea el código de crédito o NCF B04.');
    }

    $lockSql = $forUpdate ? 'FOR UPDATE OF d' : '';
    $stmt = $pdo->prepare("
        SELECT d.id, d.factura_id, d.ncf_nota_credito, d.codigo_credito, d.monto_total,
               d.saldo_disponible, d.fecha_vencimiento_saldo, d.estado_saldo,
               d.metodo_reembolso, d.moneda_id,
               f.cliente_id,
               COALESCE(p.nombre_razon_social, 'Consumidor Final') AS cliente_nombre
        FROM ventas.devoluciones d
        JOIN ventas.facturas f ON f.id = d.factura_id
        LEFT JOIN identidad.personas p ON p.persona_id = f.cliente_id
        WHERE UPPER(TRIM(COALESCE(d.codigo_credito, ''))) = ?
           OR UPPER(TRIM(COALESCE(d.ncf_nota_credito, ''))) = ?
        LIMIT 1
        {$lockSql}
    ");
    $stmt->execute([$codigo, $codigo]);
    $dev = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dev) {
        throw new Exception("No se encontró ninguna Nota de Crédito B04 con el código '{$codigo}'.");
    }
    if (strtoupper((string) $dev['metodo_reembolso']) !== 'SALDO_FAVOR') {
        throw new Exception("La Nota de Crédito '{$codigo}' fue reembolsada por {$dev['metodo_reembolso']} y no posee saldo canjeable.");
    }
    if (!in_array(strtoupper((string) $dev['estado_saldo']), ['DISPONIBLE', 'USADO_PARCIAL'], true)) {
        throw new Exception("La Nota de Crédito '{$codigo}' no está disponible para canje (estado: {$dev['estado_saldo']}).");
    }
    if (empty($dev['fecha_vencimiento_saldo']) || $dev['fecha_vencimiento_saldo'] < date('Y-m-d')) {
        throw new Exception("La Nota de Crédito '{$codigo}' venció el {$dev['fecha_vencimiento_saldo']} (plazo máximo de 15 días expirado).");
    }
    if (floatval($dev['saldo_disponible']) <= 0) {
        throw new Exception("La Nota de Crédito '{$codigo}' ya consumió el 100% de su saldo.");
    }
    if ($monedaId > 0 && intval($dev['moneda_id']) !== $monedaId) {
        throw new Exception("La moneda de la factura no coincide con la moneda original de la Nota de Crédito '{$codigo}'.");
    }

    return $dev;
}

try {
    // =========================================================================
    // 1. BUSCADOR RÁPIDO POR CÓDIGO DE BARRAS O SKU (CON STOCK EN ALMACÉN POS)
    // =========================================================================
    if ($accion === 'buscar_barcode') {
        $codigo = trim((string) ($datos['codigo'] ?? ''));
        $almacenId = intval($datos['almacen_id'] ?? 0);
        if ($codigo === '') {
            responderPos(false, 'Escanea o escribe un código de barras o SKU.');
        }

        $stmt = $pdo->prepare("
            SELECT pm.id AS producto_maestro_id,
                   pm.sku,
                   COALESCE(NULLIF(TRIM(pm.nombre), ''), pcb.descripcion_presentacion, pm.sku) AS producto_nombre,
                   pm.categoria_id,
                   pm.marca_id,
                   COALESCE(cp.nombre, 'General') AS categoria_nombre,
                   pcb.id AS codigo_barra_id,
                   pcb.codigo_barra,
                   pcb.descripcion_presentacion,
                   pcb.factor_multiplicador AS factor_conversion,
                   pcb.precio_venta_presentacion AS precio_venta
            FROM inventario.producto_codigos_barra pcb
            JOIN inventario.productos_maestro pm ON pm.id = pcb.producto_maestro_id
            LEFT JOIN inventario.categoria_producto cp ON cp.id = pm.categoria_id
            WHERE UPPER(TRIM(pcb.codigo_barra)) = UPPER(TRIM(?))
              AND pm.id > 0
            LIMIT 1
        ");
        $stmt->execute([$codigo]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            $stmtSku = $pdo->prepare("
                SELECT pm.id AS producto_maestro_id,
                       pm.sku,
                       COALESCE(NULLIF(TRIM(pm.nombre), ''), pm.sku) AS producto_nombre,
                       pm.categoria_id,
                       pm.marca_id,
                       COALESCE(cp.nombre, 'General') AS categoria_nombre,
                       (
                           SELECT id
                           FROM inventario.producto_codigos_barra
                           WHERE producto_maestro_id = pm.id AND factor_multiplicador = 1
                           ORDER BY id ASC
                           LIMIT 1
                       ) AS codigo_barra_id,
                       pm.sku AS codigo_barra,
                       'Unidad Base (x1)' AS descripcion_presentacion,
                       1 AS factor_conversion,
                       pm.precio_base_unidad AS precio_venta
                FROM inventario.productos_maestro pm
                LEFT JOIN inventario.categoria_producto cp ON cp.id = pm.categoria_id
                WHERE UPPER(TRIM(pm.sku)) = UPPER(TRIM(?)) AND pm.id > 0
                LIMIT 1
            ");
            $stmtSku->execute([$codigo]);
            $match = $stmtSku->fetch(PDO::FETCH_ASSOC);
        }

        if (!$match) {
            responderPos(false, "Código '{$codigo}' no encontrado en el catálogo.", [], 404);
        }

        $stockDisp = 0;
        if ($almacenId > 0) {
            $stS = $pdo->prepare("SELECT COALESCE(SUM(stock_fisico_unidades - stock_comprometido), 0) FROM inventario.inventario_stock WHERE almacen_id = ? AND producto_maestro_id = ?");
            $stS->execute([$almacenId, intval($match['producto_maestro_id'])]);
            $stockDisp = intval($stS->fetchColumn());
        }
        $match['stock_disponible'] = $stockDisp;

        responderPos(true, 'Producto localizado.', ['item' => $match]);
    }

    // =========================================================================
    // 2. BUSCADOR FILTRADO DE CATÁLOGO Y PRESENTACIONES (STOCK REAL EN ALMACÉN)
    // =========================================================================
    if ($accion === 'buscar_catalogo') {
        $almacenId = intval($datos['almacen_id'] ?? 0);
        $q = trim((string) ($datos['q'] ?? ''));
        $categoriaId = intval($datos['categoria_id'] ?? 0);
        $marcaId = intval($datos['marca_id'] ?? 0);
        $soloConStock = (($datos['solo_con_stock'] ?? '1') === '1');

        if ($almacenId <= 0) {
            responderPos(false, 'Selecciona el almacén de despacho POS.');
        }

        $params = [$almacenId];
        $where = ["pm.id > 0"];

        if ($categoriaId > 0) {
            $where[] = "pm.categoria_id = ?";
            $params[] = $categoriaId;
        }
        if ($marcaId > 0) {
            $where[] = "pm.marca_id = ?";
            $params[] = $marcaId;
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(
                pm.sku ILIKE ? OR pm.nombre ILIKE ? OR cp.nombre ILIKE ? OR m.nombre ILIKE ?
                OR EXISTS (
                    SELECT 1 FROM inventario.producto_codigos_barra cb2
                    WHERE cb2.producto_maestro_id = pm.id
                      AND (cb2.codigo_barra ILIKE ? OR cb2.descripcion_presentacion ILIKE ?)
                )
            )";
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $filtroStock = $soloConStock ? "WHERE base.stock_disponible > 0" : "";

        $sql = "
            WITH base AS (
                SELECT pm.id AS producto_maestro_id,
                       pm.sku,
                       pm.requiere_lote,
                       pm.precio_base_unidad,
                       COALESCE(
                           NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                           (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                           pm.sku
                       ) AS producto_nombre,
                       COALESCE(cp.nombre, 'General') AS categoria_nombre,
                       COALESCE(m.nombre, 'Genérica') AS marca_nombre,
                       COALESCE((
                           SELECT SUM(s.stock_fisico_unidades - s.stock_comprometido)
                           FROM inventario.inventario_stock s
                           WHERE s.almacen_id = ? AND s.producto_maestro_id = pm.id
                       ), 0) AS stock_disponible,
                       COALESCE((
                           SELECT JSON_AGG(
                               JSON_BUILD_OBJECT(
                                   'codigo_barra_id', cb.id,
                                   'codigo_barra', cb.codigo_barra,
                                   'descripcion_presentacion', cb.descripcion_presentacion,
                                   'factor_conversion', cb.factor_multiplicador,
                                   'precio_venta', cb.precio_venta_presentacion
                               )
                               ORDER BY cb.factor_multiplicador ASC, cb.descripcion_presentacion ASC
                           )
                           FROM inventario.producto_codigos_barra cb
                           WHERE cb.producto_maestro_id = pm.id
                       ), '[]'::json) AS presentaciones_json
                FROM inventario.productos_maestro pm
                LEFT JOIN inventario.categoria_producto cp ON cp.id = pm.categoria_id
                LEFT JOIN inventario.marca m ON m.id = pm.marca_id
                WHERE " . implode(' AND ', $where) . "
            )
            SELECT * FROM base {$filtroStock}
            ORDER BY base.producto_nombre ASC, base.sku ASC
            LIMIT 100
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$r) {
            $r['stock_disponible'] = intval($r['stock_disponible']);
            $r['requiere_lote'] = ($r['requiere_lote'] === true || $r['requiere_lote'] === 't' || $r['requiere_lote'] == 1);
            $r['presentaciones'] = json_decode($r['presentaciones_json'] ?? '[]', true) ?: [];
            unset($r['presentaciones_json']);
        }
        unset($r);

        responderPos(true, 'OK', ['items' => $rows]);
    }


    // =========================================================================
    // 2B. BUSCADOR EN VIVO DE CLIENTES (NOMBRE, CÉDULA, RNC, CÓDIGO, TELÉFONO)
    // =========================================================================
    if ($accion === 'buscar_clientes') {
        $q = trim((string) ($datos['q'] ?? ''));
        $params = [];
        $where = [
            "p.estado = TRUE",
            "c.codigo_cliente <> 'CLI-000'",
            "p.documento_identidad <> '00000000000'",
        ];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(
                p.nombre_razon_social ILIKE ?
                OR p.documento_identidad ILIKE ?
                OR c.codigo_cliente ILIKE ?
                OR COALESCE(p.telefono_principal, '') ILIKE ?
            )";
            array_push($params, $like, $like, $like, $like);
        }

        $st = $pdo->prepare("
            SELECT c.persona_id AS cliente_id,
                   c.codigo_cliente,
                   c.categoria_cliente,
                   c.limite_credito,
                   c.permite_credito,
                   p.tipo_documento,
                   p.documento_identidad,
                   p.nombre_razon_social,
                   COALESCE(p.telefono_principal, '') AS telefono_principal
            FROM identidad.clientes c
            JOIN identidad.personas p ON p.persona_id = c.persona_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.nombre_razon_social ASC
            LIMIT 25
        ");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['permite_credito'] = ($r['permite_credito'] === true || $r['permite_credito'] === 't' || $r['permite_credito'] == 1);
            $r['limite_credito'] = floatval($r['limite_credito'] ?? 0);
        }
        unset($r);

        responderPos(true, 'OK', ['clientes' => $rows]);
    }

    // =========================================================================
    // 3. EVALUACIÓN EN VIVO DEL CARRITO (PROMOS 5 TIPOS, CUPÓN, IMPUESTOS, DELIVERY)
    // =========================================================================
    if ($accion === 'evaluar_carrito') {
        $partidasRaw = $datos['partidas'] ?? [];
        $almacenId = intval($datos['almacen_id'] ?? 0);
        $cuponCodigo = trim((string) ($datos['cupon_codigo'] ?? ''));
        $origenVenta = strtoupper(trim((string) ($datos['origen_venta'] ?? 'MOSTRADOR')));
        $zonaDeliveryId = intval($datos['zona_id'] ?? 0);
        $clienteInput = trim((string) ($datos['cliente_id'] ?? ''));
        $clienteUuid = ($clienteInput !== '' && $clienteInput !== 'CONSUMIDOR_FINAL') ? $clienteInput : null;

        $res = evaluarCarritoServidor(
            $pdo,
            is_array($partidasRaw) ? $partidasRaw : [],
            $almacenId,
            $cuponCodigo,
            $origenVenta,
            $zonaDeliveryId,
            $clienteUuid
        );

        responderPos(true, 'Cálculo actualizado.', ['calculo' => $res]);
    }

    // =========================================================================
    // 4. VALIDAR CUPÓN PROMOCIONAL O NOTA DE CRÉDITO B04 (SALDO A FAVOR 15 DÍAS)
    // =========================================================================
    if ($accion === 'validar_cupon') {
        $codigo = strtoupper(trim((string) ($datos['codigo_cupon'] ?? '')));
        if ($codigo === '') {
            responderPos(false, 'Ingresa o escanea un código de cupón.');
        }

        $stmt = $pdo->prepare("
            SELECT c.id, c.codigo_cupon, c.cliente_id, c.monto_credito, c.fecha_vencimiento, c.estado,
                   pc.nombre_promocion
            FROM promociones.cupones_descuento c
            LEFT JOIN promociones.promociones_campanas pc ON pc.id = c.promocion_id
            WHERE UPPER(TRIM(c.codigo_cupon)) = ?
            LIMIT 1
        ");
        $stmt->execute([$codigo]);
        $cup = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cup) {
            responderPos(false, "El cupón '{$codigo}' no existe.");
        }
        if (strtoupper((string) $cup['estado']) !== 'DISPONIBLE') {
            responderPos(false, "El cupón '{$codigo}' no está disponible (estado: {$cup['estado']}).");
        }
        if ($cup['fecha_vencimiento'] < date('Y-m-d')) {
            responderPos(false, "El cupón '{$codigo}' venció el {$cup['fecha_vencimiento']}.");
        }

        responderPos(true, "Cupón '{$cup['codigo_cupon']}' válido por RD$ " . number_format(floatval($cup['monto_credito']), 2), [
            'cupon' => $cup,
        ]);
    }

    if ($accion === 'validar_credito_b04') {
        $codigo = trim((string) ($datos['codigo_credito'] ?? ''));
        $monedaId = intval($datos['moneda_id'] ?? 1);
        $dev = buscarCreditoB04Vigente($pdo, $codigo, $monedaId, false);

        responderPos(true, "Nota de Crédito {$dev['ncf_nota_credito']} vigente • Saldo disponible: RD$ " . number_format(floatval($dev['saldo_disponible']), 2), [
            'credito' => $dev,
        ]);
    }

    // =========================================================================
    // 5. REPARTIDORES DISPONIBLES POR ZONA (TRIPLE FILTRO EN VIVO)
    // =========================================================================
    if ($accion === 'repartidores_zona') {
        $zonaId = intval($datos['zona_id'] ?? 0);
        if ($zonaId <= 0) {
            responderPos(false, 'Selecciona una zona de delivery válida.');
        }
        $lista = consultarRepartidoresTripleFiltro($pdo, $zonaId);
        responderPos(true, 'OK', ['repartidores' => $lista]);
    }

    // =========================================================================
    // 6. OBTENER COTIZACIÓN PENDIENTE (VALIDA VIGENCIA Y STOCK EN ALMACÉN)
    // =========================================================================
    if ($accion === 'obtener_cotizacion') {
        $cotizacionId = intval($datos['cotizacion_id'] ?? 0);
        $almacenId = intval($datos['almacen_id'] ?? 0);
        if ($cotizacionId <= 0) {
            throw new Exception('Identificador de cotización inválido.');
        }

        $stmtCab = $pdo->prepare("
            SELECT cot.id, cot.numero_cotizacion, cot.cliente_id, cot.fecha_vencimiento, cot.estado,
                   c.codigo_cliente, c.permite_credito, c.limite_credito,
                   p.tipo_documento AS cliente_tipo_doc,
                   p.nombre_razon_social AS cliente_nombre,
                   p.documento_identidad AS cliente_doc
            FROM ventas.cotizaciones cot
            JOIN identidad.clientes c ON cot.cliente_id = c.persona_id
            JOIN identidad.personas p ON c.persona_id = p.persona_id
            WHERE cot.id = ?
            LIMIT 1
        ");
        $stmtCab->execute([$cotizacionId]);
        $cab = $stmtCab->fetch(PDO::FETCH_ASSOC);

        if (!$cab || strtoupper((string) $cab['estado']) !== 'PENDIENTE') {
            throw new Exception('La cotización no existe o ya no está pendiente.');
        }
        if ($cab['fecha_vencimiento'] < date('Y-m-d')) {
            $pdo->prepare("UPDATE ventas.cotizaciones SET estado = 'VENCIDA' WHERE id = ?")->execute([$cotizacionId]);
            throw new Exception("La cotización {$cab['numero_cotizacion']} venció el {$cab['fecha_vencimiento']} y fue marcada como VENCIDA.");
        }

        $stmtDet = $pdo->prepare("
            SELECT cd.producto_maestro_id, cd.codigo_barra_id, cd.cantidad, cd.precio_unitario, cd.monto_descuento,
                   pm.sku, COALESCE(NULLIF(TRIM(pm.nombre), ''), pcb.descripcion_presentacion, pm.sku) AS producto_nombre,
                   COALESCE(pcb.descripcion_presentacion, 'Unidad Base (x1)') AS descripcion_presentacion,
                   COALESCE(pcb.factor_multiplicador, 1) AS factor_conversion
            FROM ventas.cotizaciones_detalle cd
            JOIN inventario.productos_maestro pm ON cd.producto_maestro_id = pm.id
            LEFT JOIN inventario.producto_codigos_barra pcb ON cd.codigo_barra_id = pcb.id
            WHERE cd.cotizacion_id = ?
            ORDER BY pm.sku ASC
        ");
        $stmtDet->execute([$cotizacionId]);
        $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $alertas = [];
        if ($almacenId > 0) {
            $stS = $pdo->prepare("SELECT COALESCE(SUM(stock_fisico_unidades - stock_comprometido), 0) FROM inventario.inventario_stock WHERE almacen_id = ? AND producto_maestro_id = ?");
            foreach ($detalles as $d) {
                $stS->execute([$almacenId, intval($d['producto_maestro_id'])]);
                $disp = intval($stS->fetchColumn());
                $req = intval($d['cantidad']) * max(1, intval($d['factor_conversion']));
                if ($disp < $req) {
                    $alertas[] = "{$d['sku']} ({$d['producto_nombre']}): disponible {$disp} uds., cotizado {$req} uds.";
                }
            }
        }

        responderPos(true, "Cotización {$cab['numero_cotizacion']} cargada.", [
            'cotizacion' => $cab,
            'detalles' => $detalles,
            'alertas_stock' => $alertas,
        ]);
    }

    // =========================================================================
    // 7. CREAR CLIENTE RÁPIDO (+ CLIENTE) CON AUDITORÍA
    // =========================================================================
    if ($accion === 'crear_cliente_rapido') {
        $tipoDocumento = strtoupper(trim((string) ($datos['tipo_documento'] ?? 'CEDULA')));
        $documento = trim((string) ($datos['documento_identidad'] ?? ''));
        $nombre = trim((string) ($datos['nombre_razon_social'] ?? ''));
        $telefono = trim((string) ($datos['telefono_principal'] ?? ''));
        $fechaNac = trim((string) ($datos['fecha_nacimiento'] ?? ''));
        $categoria = strtoupper(trim((string) ($datos['categoria_cliente'] ?? 'REGULAR')));

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

        $stmtPer = $pdo->prepare("SELECT persona_id FROM identidad.personas WHERE documento_identidad = ? LIMIT 1");
        $stmtPer->execute([$documento]);
        $personaId = $stmtPer->fetchColumn();

        if ($personaId) {
            $stmtChkCli = $pdo->prepare("SELECT persona_id FROM identidad.clientes WHERE persona_id = ? LIMIT 1");
            $stmtChkCli->execute([$personaId]);
            if ($stmtChkCli->fetchColumn()) {
                throw new Exception('Este cliente ya se encuentra registrado en el sistema.');
            }
        } else {
            $stmtInsPer = $pdo->prepare("
                INSERT INTO identidad.personas (
                    persona_id, tipo_documento, documento_identidad, nombre_razon_social,
                    fecha_nacimiento, telefono_principal, tipo_persona, estado
                ) VALUES (uuid_generate_v4(), ?, ?, ?, ?, ?, ?, TRUE)
                RETURNING persona_id
            ");
            $stmtInsPer->execute([
                $tipoDocumento,
                $documento,
                $nombre,
                ($fechaNac !== '' && $tipoPersona === 'FISICA') ? $fechaNac : null,
                $telefono !== '' ? $telefono : null,
                $tipoPersona,
            ]);
            $personaId = $stmtInsPer->fetchColumn();
        }

        $nextNum = (int) $pdo->query("
            SELECT COALESCE(MAX(CAST(SUBSTRING(codigo_cliente FROM '[0-9]+$') AS INTEGER)), 0) + 1
            FROM identidad.clientes
            WHERE codigo_cliente ~ '^CLI-[0-9]+$'
        ")->fetchColumn();
        $codigoCliente = 'CLI-' . str_pad((string) max(1, $nextNum), 3, '0', STR_PAD_LEFT);

        $pdo->prepare("
            INSERT INTO identidad.clientes (persona_id, codigo_cliente, categoria_cliente, limite_credito, permite_credito)
            VALUES (?, ?, ?, 0.00, FALSE)
        ")->execute([$personaId, $codigoCliente, $categoria]);

        registrarAuditoria($pdo, $vendedorId, null, 'POS_CREAR_CLIENTE_RAPIDO', 'identidad.clientes', null, [
            'persona_id' => $personaId,
            'codigo_cliente' => $codigoCliente,
            'documento' => $documento,
            'nombre' => $nombre,
        ]);

        $pdo->commit();

        responderPos(true, "Cliente «{$nombre}» registrado y seleccionado.", [
            'cliente' => [
                'cliente_id' => $personaId,
                'codigo_cliente' => $codigoCliente,
                'nombre_razon_social' => $nombre,
                'tipo_documento' => $tipoDocumento,
                'documento_identidad' => $documento,
                'permite_credito' => false,
                'limite_credito' => 0,
            ],
        ]);
    }

    // =========================================================================
    // 8. DETALLE COMPLETO DE UNA FACTURA EMITIDA (HISTORIAL / ANULACIÓN)
    // =========================================================================
    if ($accion === 'detalle_factura') {
        $facturaId = intval($datos['factura_id'] ?? 0);
        if ($facturaId <= 0) {
            responderPos(false, 'ID de factura inválido.');
        }

        $stmtCab = $pdo->prepare("
            SELECT f.id, f.numero_factura, f.tipo_ncf, COALESCE(f.ncf, 'SIN NCF') AS ncf,
                   f.condicion_pago, f.origen_venta, f.estado,
                   f.monto_subtotal, f.monto_descuento, f.monto_impuestos, f.monto_total,
                   f.tasa_cambio, m.codigo_iso, m.simbolo,
                   TO_CHAR(f.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_fmt,
                   COALESCE(NULLIF(f.razon_social_cliente, ''), pc.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
                   COALESCE(NULLIF(f.rnc_cedula_cliente, ''), pc.documento_identidad, '') AS cliente_doc,
                   COALESCE(NULLIF(TRIM(pv.nombre_razon_social), ''), uv.username) AS vendedor_nombre,
                   cup.codigo_cupon AS cupon_redimido_codigo
            FROM ventas.facturas f
            LEFT JOIN tesoreria.monedas m ON m.id = f.moneda_id
            LEFT JOIN identidad.personas pc ON pc.persona_id = f.cliente_id
            LEFT JOIN seguridad.usuarios uv ON uv.usuario_id = f.vendedor_id
            LEFT JOIN identidad.personas pv ON pv.persona_id = uv.persona_id
            LEFT JOIN promociones.cupones_descuento cup ON cup.id = f.cupon_aplicado_id
            WHERE f.id = ?
            LIMIT 1
        ");
        $stmtCab->execute([$facturaId]);
        $cab = $stmtCab->fetch(PDO::FETCH_ASSOC);
        if (!$cab) {
            responderPos(false, 'Factura no encontrada.', [], 404);
        }

        $stmtDet = $pdo->prepare("
            SELECT fd.producto_maestro_id, fd.cantidad_presentacion, fd.factor_conversion,
                   fd.cantidad_unidades, fd.precio_unitario_his, fd.monto_subtotal,
                   fd.monto_descuento, fd.monto_impuestos, fd.monto_total,
                   pm.sku, COALESCE(NULLIF(TRIM(pm.nombre), ''), pm.sku) AS producto_nombre,
                   pr.nombre_promocion
            FROM ventas.factura_detalle fd
            JOIN inventario.productos_maestro pm ON pm.id = fd.producto_maestro_id
            LEFT JOIN promociones.promociones_campanas pr ON pr.id = fd.promocion_aplicada
            WHERE fd.factura_id = ?
            ORDER BY pm.sku ASC
        ");
        $stmtDet->execute([$facturaId]);
        $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtPag = $pdo->prepare("
            SELECT dp.id AS detalle_pago_id, mp.codigo AS metodo_codigo, mp.descripcion AS metodo_desc,
                   dp.monto_pagado, dp.monto_devuelta, dp.sesion_caja_id,
                   pt.marca_tarjeta, pt.ultimos_4_digitos, pt.numero_autorizacion,
                   ptr.banco_origen, ptr.numero_referencia AS ref_transferencia,
                   pch.numero_cheque, pch.banco_emisor,
                   psf.origen_saldo, psf.documento_origen_id
            FROM ventas.factura_pagos fp
            JOIN tesoreria.detalles_pago dp ON dp.id = fp.detalle_pago_id
            JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id
            LEFT JOIN tesoreria.pago_tarjeta pt ON pt.detalle_pago_id = dp.id
            LEFT JOIN tesoreria.pago_transferencia ptr ON ptr.detalle_pago_id = dp.id
            LEFT JOIN tesoreria.pago_cheque pch ON pch.detalle_pago_id = dp.id
            LEFT JOIN tesoreria.pago_saldo_favor psf ON psf.detalle_pago_id = dp.id
            WHERE fp.factura_id = ?
            ORDER BY dp.id ASC
        ");
        $stmtPag->execute([$facturaId]);
        $pagos = $stmtPag->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtCbGen = $pdo->prepare("
            SELECT codigo_cupon, monto_credito, fecha_vencimiento, estado
            FROM promociones.cupones_descuento
            WHERE factura_origen_id = ?
        ");
        $stmtCbGen->execute([$facturaId]);
        $cuponesGenerados = $stmtCbGen->fetchAll(PDO::FETCH_ASSOC) ?: [];

        responderPos(true, 'OK', [
            'cabecera' => $cab,
            'detalles' => $detalles,
            'pagos' => $pagos,
            'cupones_generados' => $cuponesGenerados,
        ]);
    }

    // =========================================================================
    // 9. GUARDAR FACTURA COMPLETA (FASES 1 A 5 EN UNA SOLA TRANSACCIÓN ATÓMICA)
    // =========================================================================
    if ($accion === 'guardar_factura') {
        $partidasRaw = $datos['partidas'] ?? [];
        $almacenId = intval($datos['almacen_id'] ?? 0);
        $clienteInput = trim((string) ($datos['cliente_id'] ?? 'CONSUMIDOR_FINAL'));
        $tipoNcf = strtoupper(trim((string) ($datos['tipo_ncf'] ?? 'B02')));
        $condicionPago = strtoupper(trim((string) ($datos['condicion_pago'] ?? 'CONTADO')));
        $origenVenta = strtoupper(trim((string) ($datos['origen_venta'] ?? 'MOSTRADOR')));
        $monedaIdIn = intval($datos['moneda_id'] ?? 1);
        $cuponCodigo = trim((string) ($datos['cupon_codigo'] ?? ''));
        $cotizacionOrigen = !empty($datos['cotizacion_origen']) ? intval($datos['cotizacion_origen']) : null;
        $confirmoEdad = filter_var($datos['confirmo_mayoria_edad'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Delivery
        $zonaDeliveryId = intval($datos['zona_id'] ?? 0);
        $repartidorId = trim((string) ($datos['repartidor_id'] ?? ''));
        $direccionDestino = trim((string) ($datos['direccion_destino'] ?? ''));

        // Split Universal de Pagos (array de pagos; compatibilidad si viene pago simple)
        $pagosInput = $datos['pagos'] ?? [];
        if ((!is_array($pagosInput) || empty($pagosInput)) && $condicionPago === 'CONTADO' && !empty($datos['metodo_pago_id'])) {
            $pagosInput = [
                [
                    'metodo_pago_id' => intval($datos['metodo_pago_id']),
                    'monto_pagado' => floatval($datos['monto_recibido'] ?? 0),
                    'referencia' => trim((string) ($datos['referencia_pago'] ?? '')),
                ]
            ];
        }

        if (!is_array($partidasRaw) || empty($partidasRaw)) {
            throw new Exception('El carrito está vacío. Agrega al menos un producto.');
        }
        if (!in_array($tipoNcf, ['B01', 'B02', 'CONSUMO_INTERNO'], true)) {
            throw new Exception('Tipo de comprobante fiscal no válido.');
        }
        if (!in_array($condicionPago, ['CONTADO', 'CREDITO'], true)) {
            throw new Exception('Condición de pago no válida.');
        }
        if (!in_array($origenVenta, ['MOSTRADOR', 'DELIVERY'], true)) {
            $origenVenta = 'MOSTRADOR';
        }

        // Resolver almacén por defecto si no vino seleccionado
        if ($almacenId <= 0) {
            $almacenId = (int) $pdo->query("
                SELECT id FROM inventario.almacenes
                WHERE activo = TRUE
                ORDER BY CASE WHEN LOWER(nombre) LIKE '%mostrador%' THEN 0 ELSE 1 END, id ASC
                LIMIT 1
            ")->fetchColumn();
        }
        if ($almacenId <= 0) {
            throw new Exception('No hay un almacén físico activo configurado para descargar inventario.');
        }

        $pdo->beginTransaction();

        // ---------------------------------------------------------------------
        // FASE 5 (Control de Caja): Validación ESTRICTA de sesión ABIERTA propia
        // Nunca se toma la sesión de otro cajero ni sesiones EN_CUADRE.
        // ---------------------------------------------------------------------
        $stmtSes = $pdo->prepare("
            SELECT id, caja_id, estado
            FROM ventas.sesiones_caja
            WHERE cajero_id = ? AND estado = 'ABIERTA'
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmtSes->execute([$vendedorId]);
        $sesionRow = $stmtSes->fetch(PDO::FETCH_ASSOC);
        if (!$sesionRow) {
            throw new Exception('No tienes una sesión de caja ABIERTA asignada a tu usuario. Solicita a tu supervisor abrir tu turno en Control de Caja.');
        }
        $sesionCajaId = intval($sesionRow['id']);

        // Moneda
        $moneda = obtenerMonedaFactura($pdo, $monedaIdIn);
        $monedaId = intval($moneda['id']);
        $tasaCambio = floatval($moneda['tasa_cambio_referencia']);

        // ---------------------------------------------------------------------
        // Resolver Cliente y Datos Fiscales (y validar mayoría de edad)
        // ---------------------------------------------------------------------
        $clienteUuid = null;
        $rncCedulaCliente = null;
        $razonSocialCliente = null;
        $cliPermiteCredito = false;
        $cliLimiteCredito = 0.0;
        $esConsumidorFinal = ($clienteInput === '' || $clienteInput === 'CONSUMIDOR_FINAL');

        if ($esConsumidorFinal) {
            if ($condicionPago === 'CREDITO') {
                throw new Exception('Las ventas a Crédito requieren un cliente registrado con línea de crédito.');
            }
            if ($tipoNcf === 'B01') {
                throw new Exception('El comprobante B01 (Crédito Fiscal) exige un cliente registrado con RNC o Cédula.');
            }

            $cfRow = $pdo->query("
                SELECT c.persona_id
                FROM identidad.clientes c
                JOIN identidad.personas p ON p.persona_id = c.persona_id
                WHERE c.codigo_cliente = 'CLI-000' OR p.documento_identidad = '00000000000'
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);

            if ($cfRow) {
                $clienteUuid = $cfRow['persona_id'];
            } else {
                $perCfId = $pdo->query("SELECT persona_id FROM identidad.personas WHERE documento_identidad = '00000000000' LIMIT 1")->fetchColumn();
                if (!$perCfId) {
                    $perCfId = $pdo->query("
                        INSERT INTO identidad.personas (persona_id, tipo_documento, documento_identidad, nombre_razon_social, tipo_persona, estado)
                        VALUES (uuid_generate_v4(), 'CEDULA', '00000000000', 'Consumidor Final', 'FISICA', TRUE)
                        RETURNING persona_id
                    ")->fetchColumn();
                }
                $pdo->prepare("
                    INSERT INTO identidad.clientes (persona_id, codigo_cliente, categoria_cliente, limite_credito, permite_credito)
                    VALUES (?, 'CLI-000', 'REGULAR', 0.00, FALSE)
                    ON CONFLICT (persona_id) DO NOTHING
                ")->execute([$perCfId]);
                $clienteUuid = $perCfId;
            }
            $razonSocialCliente = 'Consumidor Final';
        } else {
            $stmtCli = $pdo->prepare("
                SELECT c.persona_id, c.permite_credito, c.limite_credito,
                       p.documento_identidad, p.nombre_razon_social, p.fecha_nacimiento, p.tipo_persona
                FROM identidad.clientes c
                JOIN identidad.personas p ON p.persona_id = c.persona_id
                WHERE c.persona_id = ? AND p.estado = TRUE
                LIMIT 1
            ");
            $stmtCli->execute([$clienteInput]);
            $cliData = $stmtCli->fetch(PDO::FETCH_ASSOC);
            if (!$cliData) {
                throw new Exception('El cliente seleccionado no existe o está inactivo.');
            }

            $clienteUuid = $cliData['persona_id'];
            $rncCedulaCliente = trim((string) ($cliData['documento_identidad'] ?? '')) ?: null;
            $razonSocialCliente = trim((string) ($cliData['nombre_razon_social'] ?? '')) ?: null;
            $cliPermiteCredito = ($cliData['permite_credito'] === true || $cliData['permite_credito'] === 't' || $cliData['permite_credito'] == 1);
            $cliLimiteCredito = floatval($cliData['limite_credito'] ?? 0);

            if ($tipoNcf === 'B01' && (empty($rncCedulaCliente) || empty($razonSocialCliente))) {
                throw new Exception('Para emitir un comprobante B01 el cliente debe tener RNC/Cédula y Razón Social válidos.');
            }
        }

        // ---------------------------------------------------------------------
        // Evaluar Carrito en Servidor (Precios reales, Promociones, Cupón, Impuestos, Delivery)
        // ---------------------------------------------------------------------
        $eval = evaluarCarritoServidor(
            $pdo,
            $partidasRaw,
            $almacenId,
            $cuponCodigo,
            $origenVenta,
            $zonaDeliveryId,
            $esConsumidorFinal ? null : $clienteUuid
        );

        if ($eval['contiene_alcohol'] && $esConsumidorFinal && !$confirmoEdad) {
            throw new Exception('Venta de bebidas alcohólicas: debes confirmar que el comprador es mayor de 18 años.');
        }
        if (!empty($eval['alertas_stock'])) {
            throw new Exception(implode(' | ', $eval['alertas_stock']));
        }

        $facturaSubtotal = $eval['monto_subtotal'];
        $facturaDescuento = $eval['monto_descuento'];
        $facturaImpuestos = $eval['monto_impuestos'];
        $facturaTotal = $eval['monto_total'];
        $cuponAplicadoId = $eval['cupon_aplicado']['id'] ?? null;

        // Validar Delivery + Triple Filtro (Zona + Horario + Capacidad)
        if ($origenVenta === 'DELIVERY') {
            if ($zonaDeliveryId <= 0 || $direccionDestino === '') {
                throw new Exception('Para ventas por Delivery debes indicar la Zona y la Dirección de entrega.');
            }
            $candidatos = consultarRepartidoresTripleFiltro($pdo, $zonaDeliveryId);
            $elegibles = array_values(array_filter($candidatos, fn($r) => $r['elegible_triple']));

            if ($repartidorId === '') {
                // Asignación automática al primero que supere el Triple Filtro (prioriza Ruta Principal)
                if (empty($elegibles)) {
                    throw new Exception('Ningún repartidor asignado a esta zona cumple el Triple Filtro (turno horario activo y capacidad disponible).');
                }
                $repartidorId = $elegibles[0]['repartidor_id'];
            } else {
                $selRep = null;
                foreach ($candidatos as $cand) {
                    if ($cand['repartidor_id'] === $repartidorId) {
                        $selRep = $cand;
                        break;
                    }
                }
                if (!$selRep) {
                    throw new Exception('El repartidor seleccionado no tiene cobertura asignada para esta zona o está inactivo.');
                }
                if (!$selRep['tiene_capacidad']) {
                    throw new Exception("El repartidor {$selRep['nombre_completo']} ya alcanzó su capacidad máxima de pedidos activos ({$selRep['capacidad_pedidos']}).");
                }
                if (!$selRep['en_horario_activo']) {
                    throw new Exception("El repartidor {$selRep['nombre_completo']} se encuentra fuera de su horario laboral registrado en ventas.repartidor_horarios.");
                }
            }
        }

        // Validar Límite de Crédito si condicion_pago = CREDITO
        if ($condicionPago === 'CREDITO') {
            if (!$cliPermiteCredito) {
                throw new Exception("El cliente '{$razonSocialCliente}' no tiene autorizado operar a crédito.");
            }
            $stmtDeuda = $pdo->prepare("
                SELECT COALESCE(SUM(saldo_pendiente * tasa_cambio), 0)
                FROM ventas.cuentas_por_cobrar
                WHERE cliente_id = ? AND estado IN ('PENDIENTE', 'PAGADA_PARCIAL')
            ");
            $stmtDeuda->execute([$clienteUuid]);
            $deudaActualDop = floatval($stmtDeuda->fetchColumn());
            $nuevoCargoDop = round($facturaTotal * $tasaCambio, 2);

            if (($deudaActualDop + $nuevoCargoDop) > $cliLimiteCredito) {
                $disponibleCred = max(0.0, $cliLimiteCredito - $deudaActualDop);
                throw new Exception(
                    "Límite de crédito excedido para {$razonSocialCliente}. " .
                    "Límite: RD$ " . number_format($cliLimiteCredito, 2) .
                    ", Saldo actual: RD$ " . number_format($deudaActualDop, 2) .
                    ", Disponible: RD$ " . number_format($disponibleCred, 2) . "."
                );
            }
        }

        // ---------------------------------------------------------------------
        // FASE 3: Bloqueo Concurrente de Secuencia Fiscal DGII (FOR UPDATE)
        // ---------------------------------------------------------------------
        $secuenciaDgiiId = null;
        $ncfGenerado = null;

        if (in_array($tipoNcf, ['B01', 'B02'], true)) {
            $stmtSec = $pdo->prepare("
                SELECT id, secuencia_actual, secuencia_hasta, fecha_vencimiento
                FROM ventas.secuencias_fiscales_dgii
                WHERE tipo_comprobante = ?
                  AND estado = 'ACTIVO'
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmtSec->execute([$tipoNcf]);
            $secRow = $stmtSec->fetch(PDO::FETCH_ASSOC);

            if (!$secRow) {
                throw new Exception("No existe un bloque de secuencias fiscales DGII activo para {$tipoNcf}. Configúralo en Secuencias Fiscales.");
            }
            if ($secRow['fecha_vencimiento'] < date('Y-m-d')) {
                $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'VENCIDO' WHERE id = ?")->execute([$secRow['id']]);
                throw new Exception("El bloque de secuencias DGII {$tipoNcf} venció el {$secRow['fecha_vencimiento']}.");
            }

            $secuenciaDgiiId = intval($secRow['id']);
            $numActual = intval($secRow['secuencia_actual']);
            $numHasta = intval($secRow['secuencia_hasta']);

            if ($numActual > $numHasta) {
                $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'AGOTADO' WHERE id = ?")->execute([$secuenciaDgiiId]);
                throw new Exception("La secuencia fiscal {$tipoNcf} está agotada.");
            }

            $ncfGenerado = $tipoNcf . str_pad((string) $numActual, 8, '0', STR_PAD_LEFT);

            if ($numActual < $numHasta) {
                $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET secuencia_actual = secuencia_actual + 1 WHERE id = ?")->execute([$secuenciaDgiiId]);
            } else {
                $pdo->prepare("UPDATE ventas.secuencias_fiscales_dgii SET estado = 'AGOTADO' WHERE id = ?")->execute([$secuenciaDgiiId]);
            }
        }

        // Generar número de factura correlativo basado en secuencia (sin riesgo de colisión)
        $facturaId = intval($pdo->query("SELECT nextval('ventas.facturas_id_seq')")->fetchColumn());
        $numeroFactura = 'FAC-' . date('Ymd') . '-' . str_pad((string) $facturaId, 5, '0', STR_PAD_LEFT);

        // Estado inicial según flujo operativo
        $estadoFactura = ($condicionPago === 'CONTADO' && $origenVenta === 'MOSTRADOR') ? 'PAGADA' : 'EMITIDA';

        // ---------------------------------------------------------------------
        // Insertar Cabecera en ventas.facturas
        // ---------------------------------------------------------------------
        $stmtInsFac = $pdo->prepare("
            INSERT INTO ventas.facturas (
                id, numero_factura, sesion_caja_id, cliente_id, vendedor_id,
                secuencia_dgii_id, tipo_ncf, ncf, condicion_pago,
                monto_subtotal, monto_descuento, monto_impuestos, monto_total,
                estado, cotizacion_origen, rnc_cedula_cliente, razon_social_cliente,
                origen_venta, cupon_aplicado_id, moneda_id, tasa_cambio
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?
            )
        ");
        $stmtInsFac->execute([
            $facturaId,
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
            $facturaImpuestos,
            $facturaTotal,
            $estadoFactura,
            $cotizacionOrigen,
            $rncCedulaCliente,
            $razonSocialCliente,
            $origenVenta,
            $cuponAplicadoId,
            $monedaId,
            $tasaCambio,
        ]);

        // ---------------------------------------------------------------------
        // Insertar Detalles + Impuestos Congelados + Descarga FEFO Sincronizada + Kardex SALIDA_VENTA
        // ---------------------------------------------------------------------
        $stmtInsDet = $pdo->prepare("
            INSERT INTO ventas.factura_detalle (
                factura_id, producto_maestro_id, cantidad_unidades, precio_unitario_his,
                codigo_barra_id, factor_conversion, cantidad_presentacion, costo_unitario_hist,
                promocion_aplicada, monto_descuento, monto_impuestos, monto_subtotal
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtInsDetImp = $pdo->prepare("
            INSERT INTO ventas.factura_detalle_impuestos (
                factura_id, producto_maestro_id, impuesto_id, base_imponible,
                tipo_calculo_aplicado, tasa_aplicada, monto_impuesto
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        // Crear cabecera de Kardex SALIDA_VENTA
        $colsMov = obtenerColumnasTabla($pdo, 'inventario', 'movimientos_inventario');
        $camposMov = ['tipo_movimiento', 'justificacion', 'supervisor_id', 'documento_referencia_id'];
        $valoresMov = ['SALIDA_VENTA', "Salida por facturación {$numeroFactura}" . ($ncfGenerado ? " (NCF: {$ncfGenerado})" : ""), null, $facturaId];
        if (isset($colsMov['almacen_id'])) {
            $camposMov[] = 'almacen_id';
            $valoresMov[] = $almacenId;
        }
        if (isset($colsMov['usuario_id'])) {
            $camposMov[] = 'usuario_id';
            $valoresMov[] = $vendedorId;
        }
        $phMov = implode(', ', array_fill(0, count($camposMov), '?'));
        $stmtInsMov = $pdo->prepare("INSERT INTO inventario.movimientos_inventario (" . implode(', ', $camposMov) . ") VALUES ({$phMov}) RETURNING id");
        $stmtInsMov->execute($valoresMov);
        $movimientoKardexId = intval($stmtInsMov->fetchColumn());

        $stmtInsMovDet = $pdo->prepare("
            INSERT INTO inventario.movimiento_inventario_detalles
                (movimiento_id, producto_maestro_id, lote_id, cantidad_unidades, costo_unitario_referencia)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmtStockFefo = $pdo->prepare("
            SELECT s.id AS stock_id, s.lote_id, s.stock_fisico_unidades, s.stock_comprometido,
                   l.fecha_vencimiento, l.cantidad_actual AS lote_cantidad
            FROM inventario.inventario_stock s
            JOIN inventario.lotes l ON l.id = s.lote_id
            WHERE s.almacen_id = ?
              AND s.producto_maestro_id = ?
              AND (s.stock_fisico_unidades - s.stock_comprometido) > 0
            ORDER BY l.fecha_vencimiento ASC NULLS LAST, s.lote_id ASC
            FOR UPDATE OF s, l
        ");
        $stmtRestarStock = $pdo->prepare("UPDATE inventario.inventario_stock SET stock_fisico_unidades = stock_fisico_unidades - ? WHERE id = ?");
        $stmtRestarLote = $pdo->prepare("UPDATE inventario.lotes SET cantidad_actual = cantidad_actual - ? WHERE id = ?");
        $stmtRestarLote0 = $pdo->prepare("UPDATE inventario.lotes SET cantidad_actual = GREATEST(0, cantidad_actual - ?) WHERE id = 0");

        // Orden determinista para evitar deadlocks en FOR UPDATE
        $lineasOrdenadas = $eval['lineas'];
        usort($lineasOrdenadas, fn($a, $b) => $a['producto_maestro_id'] <=> $b['producto_maestro_id']);

        foreach ($lineasOrdenadas as $ln) {
            $pmId = intval($ln['producto_maestro_id']);
            $qty = intval($ln['cantidad_unidades']);

            $stmtInsDet->execute([
                $facturaId,
                $pmId,
                $qty,
                $ln['precio_unitario_his'],
                $ln['codigo_barra_id'],
                $ln['factor_conversion'],
                $ln['cantidad_presentacion'],
                $ln['costo_unitario_hist'],
                $ln['promocion_aplicada'],
                $ln['monto_descuento_promo'],
                $ln['monto_impuestos'],
                $ln['monto_subtotal'],
            ]);

            foreach ($ln['impuestos_detalle'] as $di) {
                $stmtInsDetImp->execute([
                    $facturaId,
                    $pmId,
                    $di['impuesto_id'],
                    $di['base_imponible'],
                    $di['tipo_calculo_aplicado'],
                    $di['tasa_aplicada'],
                    $di['monto_impuesto'],
                ]);
            }

            // Descarga FEFO estricta y sincronizada entre inventario_stock, lotes y Kardex
            $stmtStockFefo->execute([$almacenId, $pmId]);
            $filasFefo = $stmtStockFefo->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $restante = $qty;
            foreach ($filasFefo as $fs) {
                if ($restante <= 0) {
                    break;
                }
                $dispStock = intval($fs['stock_fisico_unidades']) - intval($fs['stock_comprometido']);
                $loteId = intval($fs['lote_id']);
                $dispLote = ($loteId > 0) ? intval($fs['lote_cantidad']) : $dispStock;
                $tomar = min($restante, $dispStock, $dispLote);

                if ($tomar <= 0) {
                    continue;
                }

                $stmtRestarStock->execute([$tomar, intval($fs['stock_id'])]);
                if ($loteId > 0) {
                    $stmtRestarLote->execute([$tomar, $loteId]);
                } else {
                    $stmtRestarLote0->execute([$tomar]);
                }

                $stmtInsMovDet->execute([
                    $movimientoKardexId,
                    $pmId,
                    $loteId,
                    $tomar,
                    $ln['costo_unitario_hist'],
                ]);

                $restante -= $tomar;
            }

            if ($restante > 0) {
                throw new Exception("Stock físico/lote insuficiente en el almacén POS para {$ln['sku']} ({$ln['producto_nombre']}). Faltan {$restante} uds.");
            }
        }

        // ---------------------------------------------------------------------
        // FASE 4: Split Universal de Pagos (CONTADO) o CxC (CREDITO)
        // ---------------------------------------------------------------------
        $pagosRegistrados = [];

        if ($condicionPago === 'CONTADO') {
            if (!is_array($pagosInput) || empty($pagosInput)) {
                throw new Exception('Debes registrar al menos un método de pago para facturas de contado.');
            }

            $stmtMpInfo = $pdo->prepare("SELECT id, codigo, descripcion FROM ventas.metodos_pago WHERE id = ? AND activo = TRUE LIMIT 1");
            $stmtInsDP = $pdo->prepare("
                INSERT INTO tesoreria.detalles_pago
                    (metodo_pago_id, moneda_id, tasa_cambio, monto_pagado, monto_devuelta, sesion_caja_id)
                VALUES (?, ?, ?, ?, ?, ?)
                RETURNING id
            ");
            $stmtInsFP = $pdo->prepare("INSERT INTO ventas.factura_pagos (factura_id, detalle_pago_id) VALUES (?, ?)");

            $totalCubierto = 0.0;

            foreach ($pagosInput as $idxP => $pg) {
                $numP = $idxP + 1;
                $mpId = intval($pg['metodo_pago_id'] ?? 0);
                $mpCodIn = strtoupper(trim((string) ($pg['metodo_codigo'] ?? '')));

                if ($mpId <= 0 && $mpCodIn !== '') {
                    $stCod = $pdo->prepare("SELECT id FROM ventas.metodos_pago WHERE UPPER(codigo) = ? AND activo = TRUE LIMIT 1");
                    $stCod->execute([$mpCodIn]);
                    $mpId = intval($stCod->fetchColumn());
                }

                $stmtMpInfo->execute([$mpId]);
                $mp = $stmtMpInfo->fetch(PDO::FETCH_ASSOC);
                if (!$mp) {
                    throw new Exception("Pago #{$numP}: método de pago no válido o inactivo.");
                }

                $codigoMetodo = strtoupper((string) $mp['codigo']);
                $montoRecibido = round(floatval($pg['monto_pagado'] ?? 0), 2);
                $pendienteAntes = round(max(0.0, $facturaTotal - $totalCubierto), 2);

                if ($pendienteAntes <= 0) {
                    throw new Exception("Pago #{$numP}: el total de la factura ya quedó cubierto por los pagos anteriores.");
                }
                if ($montoRecibido <= 0) {
                    throw new Exception("Pago #{$numP} ({$mp['descripcion']}): el monto debe ser mayor a 0.");
                }

                // Solo EFECTIVO permite devuelta; los demás medios se acotan al saldo pendiente
                if ($codigoMetodo === 'EFECTIVO') {
                    $aplicado = min($montoRecibido, $pendienteAntes);
                    $devuelta = round(max(0.0, $montoRecibido - $aplicado), 2);
                    if ($devuelta >= $montoRecibido) {
                        throw new Exception("Pago #{$numP}: monto de efectivo inválido respecto al saldo pendiente.");
                    }
                    $sesionParaPago = $sesionCajaId;
                } else {
                    if ($montoRecibido > $pendienteAntes + 0.01) {
                        throw new Exception("Pago #{$numP} ({$codigoMetodo}): el monto (" . number_format($montoRecibido, 2) . ") no puede exceder el saldo pendiente (" . number_format($pendienteAntes, 2) . ").");
                    }
                    $aplicado = min($montoRecibido, $pendienteAntes);
                    $montoRecibido = $aplicado;
                    $devuelta = 0.00;
                    // Solo EFECTIVO y TARJETA se atan a sesion_caja_id (regla de Arqueo Ciego)
                    $sesionParaPago = ($codigoMetodo === 'TARJETA') ? $sesionCajaId : null;
                }

                // Insertar cabecera en tesoreria.detalles_pago
                $stmtInsDP->execute([
                    $mpId,
                    $monedaId,
                    $tasaCambio,
                    $montoRecibido,
                    $devuelta,
                    $sesionParaPago,
                ]);
                $detallePagoId = intval($stmtInsDP->fetchColumn());

                // Completar subtipo 1:1 según método
                if ($codigoMetodo === 'TARJETA') {
                    $tipoTj = strtoupper(trim((string) ($pg['tipo_tarjeta'] ?? 'DEBITO')));
                    $marcaTj = strtoupper(trim((string) ($pg['marca_tarjeta'] ?? 'VISA')));
                    $ult4 = preg_replace('/\D/', '', (string) ($pg['ultimos_4_digitos'] ?? ''));
                    $numAut = trim((string) ($pg['numero_autorizacion'] ?? $pg['referencia'] ?? ''));
                    $numLote = trim((string) ($pg['numero_lote'] ?? '')) ?: null;

                    if (!in_array($tipoTj, ['CREDITO', 'DEBITO'], true)) {
                        $tipoTj = 'DEBITO';
                    }
                    if (!in_array($marcaTj, ['VISA', 'MASTERCARD', 'AMEX', 'OTRA'], true)) {
                        $marcaTj = 'OTRA';
                    }
                    if (strlen($ult4) !== 4) {
                        throw new Exception("Pago #{$numP} (TARJETA): debes ingresar los últimos 4 dígitos de la tarjeta.");
                    }
                    if ($numAut === '') {
                        throw new Exception("Pago #{$numP} (TARJETA): debes ingresar el número de autorización del Verifone.");
                    }

                    $pdo->prepare("
                        INSERT INTO tesoreria.pago_tarjeta
                            (detalle_pago_id, tipo_tarjeta, marca_tarjeta, ultimos_4_digitos, numero_autorizacion, numero_lote)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([$detallePagoId, $tipoTj, $marcaTj, $ult4, $numAut, $numLote]);

                } elseif ($codigoMetodo === 'TRANSFERENCIA') {
                    $bancoOrig = trim((string) ($pg['banco_origen'] ?? ''));
                    $cuentaId = intval($pg['cuenta_bancaria_id'] ?? 0);
                    $numRef = trim((string) ($pg['numero_referencia'] ?? $pg['referencia'] ?? ''));
                    $fechaTr = trim((string) ($pg['fecha_transferencia'] ?? date('Y-m-d')));
                    $titular = trim((string) ($pg['titular_origen'] ?? '')) ?: null;

                    if ($bancoOrig === '' || $cuentaId <= 0 || $numRef === '') {
                        throw new Exception("Pago #{$numP} (TRANSFERENCIA): banco origen, cuenta bancaria receptora y referencia son obligatorios.");
                    }

                    $pdo->prepare("
                        INSERT INTO tesoreria.pago_transferencia
                            (detalle_pago_id, banco_origen, cuenta_bancaria_id, numero_referencia, fecha_transferencia, titular_origen)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([$detallePagoId, $bancoOrig, $cuentaId, $numRef, $fechaTr, $titular]);

                    $pdo->prepare("
                        INSERT INTO tesoreria.movimientos_bancarios
                            (cuenta_bancaria_id, tipo_movimiento, monto, referencia, concepto, fecha_movimiento, usuario_id, detalle_pago_id)
                        VALUES (?, 'TRANSFERENCIA_ENTRADA', ?, ?, ?, ?, ?, ?)
                    ")->execute([
                                $cuentaId,
                                $aplicado,
                                $numRef,
                                "Cobro factura {$numeroFactura} ({$razonSocialCliente})",
                                $fechaTr,
                                $vendedorId,
                                $detallePagoId,
                            ]);

                } elseif ($codigoMetodo === 'CHEQUE') {
                    $numChq = trim((string) ($pg['numero_cheque'] ?? $pg['referencia'] ?? ''));
                    $bancoEmi = trim((string) ($pg['banco_emisor'] ?? ''));
                    $titularChq = trim((string) ($pg['titular_cheque'] ?? $razonSocialCliente ?? ''));
                    $fechaEmi = trim((string) ($pg['fecha_emision'] ?? date('Y-m-d')));
                    $cuentaId = !empty($pg['cuenta_bancaria_id']) ? intval($pg['cuenta_bancaria_id']) : null;

                    if ($numChq === '' || $bancoEmi === '' || $titularChq === '') {
                        throw new Exception("Pago #{$numP} (CHEQUE): número de cheque, banco emisor y titular son obligatorios.");
                    }

                    $pdo->prepare("
                        INSERT INTO tesoreria.pago_cheque
                            (detalle_pago_id, numero_cheque, banco_emisor, titular_cheque, fecha_emision, cuenta_bancaria_id, estado_cheque)
                        VALUES (?, ?, ?, ?, ?, ?, 'RECIBIDO')
                    ")->execute([$detallePagoId, $numChq, $bancoEmi, $titularChq, $fechaEmi, $cuentaId]);

                    if ($cuentaId !== null && $cuentaId > 0) {
                        $pdo->prepare("
                            INSERT INTO tesoreria.movimientos_bancarios
                                (cuenta_bancaria_id, tipo_movimiento, monto, referencia, concepto, fecha_movimiento, usuario_id, detalle_pago_id)
                            VALUES (?, 'CHEQUE_ENTRADA', ?, ?, ?, ?, ?, ?)
                        ")->execute([
                                    $cuentaId,
                                    $aplicado,
                                    $numChq,
                                    "Cheque cobro factura {$numeroFactura} ({$razonSocialCliente})",
                                    $fechaEmi,
                                    $vendedorId,
                                    $detallePagoId,
                                ]);
                    }

                } elseif ($codigoMetodo === 'SALDO_FAVOR') {
                    $codigoCred = trim((string) ($pg['codigo_credito'] ?? $pg['referencia'] ?? ''));
                    $devCred = buscarCreditoB04Vigente($pdo, $codigoCred, $monedaId, true);
                    $saldoDisp = round(floatval($devCred['saldo_disponible']), 2);

                    if ($aplicado > $saldoDisp) {
                        throw new Exception("Pago #{$numP} (SALDO_FAVOR): el monto a aplicar (" . number_format($aplicado, 2) . ") excede el saldo disponible (" . number_format($saldoDisp, 2) . ") de la B04 {$devCred['ncf_nota_credito']}.");
                    }

                    // El trigger trg_validar_saldo_favor_devolucion valida vigencia, moneda y saldo
                    $pdo->prepare("
                        INSERT INTO tesoreria.pago_saldo_favor
                            (detalle_pago_id, cliente_id, origen_saldo, documento_origen_id)
                        VALUES (?, ?, 'DEVOLUCION_B04', ?)
                    ")->execute([$detallePagoId, $clienteUuid, intval($devCred['id'])]);

                    $nuevoSaldoB04 = round($saldoDisp - $aplicado, 2);
                    $nuevoEstadoB04 = ($nuevoSaldoB04 <= 0.00) ? 'USADO' : 'USADO_PARCIAL';
                    $pdo->prepare("
                        UPDATE ventas.devoluciones
                        SET saldo_disponible = ?, estado_saldo = ?
                        WHERE id = ?
                    ")->execute([$nuevoSaldoB04, $nuevoEstadoB04, intval($devCred['id'])]);
                }

                // Enlazar en ventas.factura_pagos
                $stmtInsFP->execute([$facturaId, $detallePagoId]);

                $totalCubierto = round($totalCubierto + $aplicado, 2);
                $pagosRegistrados[] = [
                    'detalle_pago_id' => $detallePagoId,
                    'metodo' => $codigoMetodo,
                    'aplicado' => $aplicado,
                    'devuelta' => $devuelta,
                ];
            }

            if (abs($totalCubierto - $facturaTotal) > 0.01) {
                throw new Exception(
                    "Los pagos ingresados (RD$ " . number_format($totalCubierto, 2) . ") no cubren el total de la factura (RD$ " . number_format($facturaTotal, 2) . ")."
                );
            }
        } else {
            // Venta a CRÉDITO -> Cuenta por Cobrar
            $pdo->prepare("
                INSERT INTO ventas.cuentas_por_cobrar (
                    cliente_id, factura_id, monto_original, saldo_pendiente,
                    estado, fecha_emision, fecha_vencimiento, moneda_id, tasa_cambio
                ) VALUES (
                    ?, ?, ?, ?,
                    'PENDIENTE', CURRENT_DATE, (CURRENT_DATE + INTERVAL '30 days')::date, ?, ?
                )
            ")->execute([
                        $clienteUuid,
                        $facturaId,
                        $facturaTotal,
                        $facturaTotal,
                        $monedaId,
                        $tasaCambio,
                    ]);
        }

        // ---------------------------------------------------------------------
        // FASE 5: Quemado de Cupón Redimido + Emisión de Cupón Cashback (CUPON_TICKET)
        // ---------------------------------------------------------------------
        if ($cuponAplicadoId !== null) {
            $stmtBurn = $pdo->prepare("
                UPDATE promociones.cupones_descuento
                SET estado = 'USADO', factura_canje_id = ?
                WHERE id = ? AND estado = 'DISPONIBLE'
            ");
            $stmtBurn->execute([$facturaId, $cuponAplicadoId]);
            if ($stmtBurn->rowCount() === 0) {
                throw new Exception('El cupón promocional ya fue utilizado por otra terminal en este instante.');
            }
        }

        $cuponEmitido = null;
        if (!empty($eval['cupon_cashback_prev'])) {
            $cb = $eval['cupon_cashback_prev'];
            $codigoNuevo = 'LQR-' . strtoupper(bin2hex(random_bytes(3)));
            $venceCupon = date('Y-m-d', strtotime('+30 days'));

            $pdo->prepare("
                INSERT INTO promociones.cupones_descuento (
                    codigo_cupon, promocion_id, factura_origen_id, cliente_id,
                    monto_credito, fecha_vencimiento, estado
                ) VALUES (?, ?, ?, ?, ?, ?, 'DISPONIBLE')
            ")->execute([
                        $codigoNuevo,
                        $cb['promocion_id'],
                        $facturaId,
                        $esConsumidorFinal ? null : $clienteUuid,
                        $cb['monto_credito_ganado'],
                        $venceCupon,
                    ]);

            $cuponEmitido = [
                'codigo_cupon' => $codigoNuevo,
                'monto_credito' => $cb['monto_credito_ganado'],
                'fecha_vencimiento' => $venceCupon,
                'nombre_promocion' => $cb['nombre_promocion'],
            ];
        }

        // Registrar pedido en Delivery si aplica
        if ($origenVenta === 'DELIVERY') {
            $pdo->prepare("
                INSERT INTO ventas.pedidos_delivery (
                    factura_id, repartidor_id, direccion_destino, zona_id, estado_delivery
                ) VALUES (?, ?, ?, ?, 'PENDIENTE')
            ")->execute([
                        $facturaId,
                        $repartidorId,
                        $direccionDestino,
                        $zonaDeliveryId,
                    ]);
        }

        // Convertir cotización origen si aplica
        if ($cotizacionOrigen !== null && $cotizacionOrigen > 0) {
            $pdo->prepare("
                UPDATE ventas.cotizaciones
                SET estado = 'CONVERTIDA_A_FACTURA', factura_generada_id = ?
                WHERE id = ? AND estado = 'PENDIENTE'
            ")->execute([$facturaId, $cotizacionOrigen]);
        }

        // Auditoría Forense JSONB
        registrarAuditoria($pdo, $vendedorId, null, 'POS_EMITIR_FACTURA', 'ventas.facturas', null, [
            'factura_id' => $facturaId,
            'numero_factura' => $numeroFactura,
            'ncf' => $ncfGenerado,
            'almacen_id' => $almacenId,
            'sesion_caja_id' => $sesionCajaId,
            'condicion_pago' => $condicionPago,
            'origen_venta' => $origenVenta,
            'monto_total' => $facturaTotal,
            'cupon_aplicado_id' => $cuponAplicadoId,
            'cupon_emitido' => $cuponEmitido,
            'pagos' => $pagosRegistrados,
        ]);

        $pdo->commit();

        $msg = "Factura {$numeroFactura}" . ($ncfGenerado ? " (NCF: {$ncfGenerado})" : '') . " emitida correctamente.";
        if ($cuponEmitido) {
            $msg .= " ¡Cupón Cashback generado: {$cuponEmitido['codigo_cupon']} por RD$ " . number_format($cuponEmitido['monto_credito'], 2) . "!";
        }

        responderPos(true, $msg, [
            'factura_id' => $facturaId,
            'numero_factura' => $numeroFactura,
            'ncf' => $ncfGenerado ?? 'SIN NCF',
            'monto_total' => $facturaTotal,
            'cupon_emitido' => $cuponEmitido,
        ]);
    }

    // =========================================================================
    // 10. ANULAR FACTURA CON PIN DE SUPERVISOR (REVERSIÓN ATÓMICA COMPLETA)
    // =========================================================================
    if ($accion === 'anular_factura') {
        $facturaId = intval($datos['factura_id'] ?? 0);
        $justificacion = trim((string) ($datos['justificacion'] ?? ''));
        $supervisorIn = trim((string) ($datos['supervisor_id'] ?? $datos['usuario_supervisor'] ?? ''));
        $pinIngresado = trim((string) ($datos['pin_autorizacion'] ?? $datos['pin_supervisor'] ?? ''));

        if ($facturaId <= 0) {
            throw new Exception('Factura inválida.');
        }
        if (mb_strlen($justificacion, 'UTF-8') < 5) {
            throw new Exception('La justificación de anulación debe tener al menos 5 caracteres.');
        }

        $supInfo = validarPinSupervisor($pdo, $supervisorIn, $pinIngresado);
        $supervisorId = $supInfo['usuario_id'];

        $pdo->beginTransaction();

        $stmtFac = $pdo->prepare("
            SELECT id, numero_factura, sesion_caja_id, condicion_pago, origen_venta,
                   estado, monto_total, cupon_aplicado_id, moneda_id, tasa_cambio
            FROM ventas.facturas
            WHERE id = ?
            FOR UPDATE
        ");
        $stmtFac->execute([$facturaId]);
        $fac = $stmtFac->fetch(PDO::FETCH_ASSOC);

        if (!$fac) {
            throw new Exception('La factura no existe.');
        }
        if ($fac['estado'] === 'ANULADA') {
            throw new Exception("La factura {$fac['numero_factura']} ya fue anulada previamente.");
        }
        if ($fac['estado'] === 'EN_TRANSITO') {
            throw new Exception("No se puede anular la factura {$fac['numero_factura']} mientras el pedido está EN_TRANSITO en la calle.");
        }

        // Bloquear si ya tiene devoluciones B04 emitidas
        $chkDev = $pdo->prepare("SELECT 1 FROM ventas.devoluciones WHERE factura_id = ? LIMIT 1");
        $chkDev->execute([$facturaId]);
        if ($chkDev->fetchColumn()) {
            throw new Exception("La factura {$fac['numero_factura']} ya posee Notas de Crédito B04 asociadas y no puede anularse.");
        }

        // 1. Marcar factura como ANULADA
        $pdo->prepare("
            UPDATE ventas.facturas
            SET estado = 'ANULADA', supervisor_anulacion_id = ?
            WHERE id = ?
        ")->execute([$supervisorId, $facturaId]);

        // 2. Reintegrar inventario a sus lotes exactos mediante AJUSTE_ENTRADA en Kardex
        $colsMov = obtenerColumnasTabla($pdo, 'inventario', 'movimientos_inventario');
        $selAlm = isset($colsMov['almacen_id']) ? 'm.almacen_id' : 'NULL::int AS almacen_id';
        $stmtOrigMov = $pdo->prepare("
            SELECT m.id, {$selAlm}
            FROM inventario.movimientos_inventario m
            WHERE m.tipo_movimiento = 'SALIDA_VENTA' AND m.documento_referencia_id = ?
            ORDER BY m.id DESC
            LIMIT 1
        ");
        $stmtOrigMov->execute([$facturaId]);
        $origMov = $stmtOrigMov->fetch(PDO::FETCH_ASSOC);

        if ($origMov) {
            $almacenRest = intval($origMov['almacen_id'] ?? 0);
            if ($almacenRest <= 0) {
                $almacenRest = (int) $pdo->query("SELECT id FROM inventario.almacenes WHERE activo = TRUE ORDER BY id ASC LIMIT 1")->fetchColumn();
            }

            $stmtOrigDets = $pdo->prepare("
                SELECT producto_maestro_id, lote_id, cantidad_unidades, costo_unitario_referencia
                FROM inventario.movimiento_inventario_detalles
                WHERE movimiento_id = ?
                ORDER BY producto_maestro_id ASC, lote_id ASC
            ");
            $stmtOrigDets->execute([intval($origMov['id'])]);
            $detsOrig = $stmtOrigDets->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (!empty($detsOrig)) {
                $camposRev = ['tipo_movimiento', 'justificacion', 'supervisor_id', 'documento_referencia_id'];
                $valoresRev = ['AJUSTE_ENTRADA', "Reintegro por anulación de factura {$fac['numero_factura']}: {$justificacion}", $supervisorId, $facturaId];
                if (isset($colsMov['almacen_id'])) {
                    $camposRev[] = 'almacen_id';
                    $valoresRev[] = $almacenRest;
                }
                if (isset($colsMov['usuario_id'])) {
                    $camposRev[] = 'usuario_id';
                    $valoresRev[] = $vendedorId;
                }
                $phRev = implode(', ', array_fill(0, count($camposRev), '?'));
                $stRevCab = $pdo->prepare("INSERT INTO inventario.movimientos_inventario (" . implode(', ', $camposRev) . ") VALUES ({$phRev}) RETURNING id");
                $stRevCab->execute($valoresRev);
                $revMovId = intval($stRevCab->fetchColumn());

                $stRevDet = $pdo->prepare("
                    INSERT INTO inventario.movimiento_inventario_detalles
                        (movimiento_id, producto_maestro_id, lote_id, cantidad_unidades, costo_unitario_referencia)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stSumLote = $pdo->prepare("UPDATE inventario.lotes SET cantidad_actual = cantidad_actual + ? WHERE id = ?");
                $stUpStock = $pdo->prepare("
                    INSERT INTO inventario.inventario_stock (almacen_id, producto_maestro_id, lote_id, stock_fisico_unidades, stock_comprometido)
                    VALUES (?, ?, ?, ?, 0)
                    ON CONFLICT (almacen_id, producto_maestro_id, lote_id)
                    DO UPDATE SET stock_fisico_unidades = inventario.inventario_stock.stock_fisico_unidades + EXCLUDED.stock_fisico_unidades
                ");

                foreach ($detsOrig as $d) {
                    $stRevDet->execute([$revMovId, $d['producto_maestro_id'], $d['lote_id'], $d['cantidad_unidades'], $d['costo_unitario_referencia']]);
                    $stSumLote->execute([$d['cantidad_unidades'], $d['lote_id']]);
                    $stUpStock->execute([$almacenRest, $d['producto_maestro_id'], $d['lote_id'], $d['cantidad_unidades']]);
                }
            }
        }

        // 3. Reversar CxC (si CREDITO) o Efectivo/B04 (si CONTADO)
        if ($fac['condicion_pago'] === 'CREDITO') {
            $stCxc = $pdo->prepare("SELECT id, monto_original, saldo_pendiente, estado FROM ventas.cuentas_por_cobrar WHERE factura_id = ? FOR UPDATE");
            $stCxc->execute([$facturaId]);
            $cxc = $stCxc->fetch(PDO::FETCH_ASSOC);
            if ($cxc) {
                if (floatval($cxc['saldo_pendiente']) < floatval($cxc['monto_original'])) {
                    throw new Exception('No se puede anular una factura a crédito que ya posee abonos registrados en CxC.');
                }
                $pdo->prepare("
                    INSERT INTO ventas.ajustes_cxc
                        (cuenta_por_cobrar_id, tipo_ajuste, moneda_id, tasa_cambio, monto_ajuste, saldo_anterior, saldo_restante, justificacion, supervisor_id)
                    VALUES (?, 'ANULACION_FACTURA', ?, ?, ?, ?, 0.00, ?, ?)
                ")->execute([
                            $cxc['id'],
                            $fac['moneda_id'],
                            $fac['tasa_cambio'],
                            $cxc['saldo_pendiente'],
                            $cxc['saldo_pendiente'],
                            "Anulación factura {$fac['numero_factura']}: {$justificacion}",
                            $supervisorId,
                        ]);
                $pdo->prepare("UPDATE ventas.cuentas_por_cobrar SET saldo_pendiente = 0.00, estado = 'ANULADA' WHERE id = ?")->execute([$cxc['id']]);
            }
        } else {
            // Reversar pagos en efectivo (egreso en caja si sesión sigue abierta) y devolver saldo a favor B04
            $stPags = $pdo->prepare("
                SELECT dp.id, mp.codigo, dp.monto_pagado, dp.monto_devuelta, dp.tasa_cambio, dp.sesion_caja_id,
                       psf.origen_saldo, psf.documento_origen_id
                FROM ventas.factura_pagos fp
                JOIN tesoreria.detalles_pago dp ON dp.id = fp.detalle_pago_id
                JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id
                LEFT JOIN tesoreria.pago_saldo_favor psf ON psf.detalle_pago_id = dp.id
                WHERE fp.factura_id = ?
            ");
            $stPags->execute([$facturaId]);
            foreach ($stPags->fetchAll(PDO::FETCH_ASSOC) as $pg) {
                $netoPag = round((floatval($pg['monto_pagado']) - floatval($pg['monto_devuelta'])) * floatval($pg['tasa_cambio']), 2);
                if ($pg['codigo'] === 'EFECTIVO' && !empty($pg['sesion_caja_id']) && $netoPag > 0) {
                    $stSesEst = $pdo->prepare("SELECT estado FROM ventas.sesiones_caja WHERE id = ?");
                    $stSesEst->execute([$pg['sesion_caja_id']]);
                    if ($stSesEst->fetchColumn() === 'ABIERTA') {
                        $pdo->prepare("
                            INSERT INTO ventas.movimiento_caja (sesion_caja_id, tipo_movimiento, monto, concepto, supervisor_id)
                            VALUES (?, 'EGRESO', ?, ?, ?)
                        ")->execute([
                                    $pg['sesion_caja_id'],
                                    $netoPag,
                                    "Reversión efectivo por anulación factura {$fac['numero_factura']}: {$justificacion}",
                                    $supervisorId,
                                ]);
                    }
                } elseif ($pg['codigo'] === 'SALDO_FAVOR' && $pg['origen_saldo'] === 'DEVOLUCION_B04' && !empty($pg['documento_origen_id'])) {
                    $montoRestaurar = round(floatval($pg['monto_pagado']), 2);
                    $pdo->prepare("
                        UPDATE ventas.devoluciones
                        SET saldo_disponible = LEAST(monto_total, saldo_disponible + ?),
                            estado_saldo = CASE
                                WHEN fecha_vencimiento_saldo < CURRENT_DATE THEN 'VENCIDO'
                                WHEN (saldo_disponible + ?) >= monto_total THEN 'DISPONIBLE'
                                ELSE 'USADO_PARCIAL'
                            END
                        WHERE id = ?
                    ")->execute([$montoRestaurar, $montoRestaurar, intval($pg['documento_origen_id'])]);
                }
            }
        }

        // 4. Revertir cupón redimido y anular cupón cashback generado por esta factura
        if (!empty($fac['cupon_aplicado_id'])) {
            $pdo->prepare("
                UPDATE promociones.cupones_descuento
                SET estado = CASE WHEN fecha_vencimiento < CURRENT_DATE THEN 'VENCIDO' ELSE 'DISPONIBLE' END,
                    factura_canje_id = NULL
                WHERE id = ?
            ")->execute([$fac['cupon_aplicado_id']]);
        }
        $pdo->prepare("
            UPDATE promociones.cupones_descuento
            SET estado = 'ANULADO'
            WHERE factura_origen_id = ? AND estado = 'DISPONIBLE'
        ")->execute([$facturaId]);

        // 5. Cancelar despacho delivery pendiente si existía
        $pdo->prepare("
            UPDATE ventas.pedidos_delivery
            SET estado_delivery = 'CANCELADO'
            WHERE factura_id = ? AND estado_delivery = 'PENDIENTE'
        ")->execute([$facturaId]);

        registrarAuditoria($pdo, $vendedorId, $supervisorId, 'POS_ANULAR_FACTURA', 'ventas.facturas', [
            'numero_factura' => $fac['numero_factura'],
            'estado_antes' => $fac['estado'],
            'monto_total' => $fac['monto_total'],
        ], [
            'numero_factura' => $fac['numero_factura'],
            'estado_despues' => 'ANULADA',
            'justificacion' => $justificacion,
            'supervisor_nombre' => $supInfo['nombre_completo'],
        ]);

        $pdo->commit();

        responderPos(true, "Factura {$fac['numero_factura']} anulada correctamente por {$supInfo['nombre_completo']}. Inventario y saldos revertidos.");
    }

    responderPos(false, 'Acción no reconocida.');
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderPos(false, $e->getMessage(), [], 400);
}