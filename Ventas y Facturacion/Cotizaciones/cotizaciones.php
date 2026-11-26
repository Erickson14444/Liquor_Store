<?php
/**
 * ============================================================================
 * Módulo 4: Ventas • Proceso 2: Cotizaciones (Vista Principal)
 * Ubicación: Ventas y Facturacion/Cotizaciones/cotizaciones.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

requerirSesionActiva(false, '../../Login y Menu/login.php');

if (function_exists('usuarioPuedeAccederCotizaciones')) {
    requerirAccesoModulo(usuarioPuedeAccederCotizaciones(), false, 'Acceso restringido: no posees permisos para gestionar Cotizaciones.');
}

$puedeDescuentoManual = (function_exists('usuarioEsAdministrador') && usuarioEsAdministrador())
    || !empty($_SESSION['usuario']['es_supervisor'])
    || (function_exists('usuarioTienePermiso') && usuarioTienePermiso('AUTORIZAR_CON_PIN'));

$puedeCancelarCot = (function_exists('usuarioEsAdministrador') && usuarioEsAdministrador())
    || !empty($_SESSION['usuario']['es_supervisor'])
    || (function_exists('usuarioTienePermiso') && usuarioTienePermiso('CANCELAR_COTIZACION'));

$cotizaciones = [];
$monedas = [];
$categorias = [];
$marcas = [];

$kpiTotal = 0;
$kpiPendientes = 0;
$kpiConvertidas = 0;
$kpiVencidasCan = 0;

if ($pdo) {
    try {
        // Fase 2 (Vigilancia automática): vencer presupuestos expirados
        $pdo->exec("
            UPDATE ventas.cotizaciones
            SET estado = 'VENCIDA'
            WHERE estado = 'PENDIENTE'
              AND fecha_vencimiento < CURRENT_DATE
        ");

        // 1. Monedas activas
        $monedas = $pdo->query("
            SELECT id, codigo_iso, nombre, simbolo, tasa_cambio_referencia, es_moneda_base
            FROM tesoreria.monedas
            WHERE activa = TRUE
            ORDER BY CASE WHEN es_moneda_base = TRUE THEN 0 ELSE 1 END, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 2. Categorías y marcas para filtros del buscador
        $categorias = $pdo->query("
            SELECT id, nombre FROM inventario.categoria_producto WHERE id > 0 AND estado = 'ACTIVO' ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $marcas = $pdo->query("
            SELECT id, nombre FROM inventario.marca WHERE id > 0 AND estado = 'ACTIVO' ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 3. Listado de Cotizaciones
        $cotizaciones = $pdo->query("
            SELECT cot.id,
                   cot.numero_cotizacion,
                   cot.estado,
                   cot.fecha_vencimiento,
                   TO_CHAR(cot.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_emision_fmt,
                   TO_CHAR(cot.fecha_vencimiento, 'DD/MM/YYYY')        AS fecha_vencimiento_fmt,
                   cot.monto_subtotal,
                   cot.monto_impuestos,
                   cot.monto_total,
                   cot.observaciones,
                   COALESCE(m.simbolo, 'RD$') AS simbolo,
                   p.nombre_razon_social AS cliente_nombre,
                   p.documento_identidad AS cliente_doc,
                   COALESCE(NULLIF(TRIM(pv.nombre_razon_social), ''), uv.username) AS vendedor_nombre,
                   f.numero_factura AS factura_numero,
                   COUNT(cd.producto_maestro_id) AS total_lineas,
                   COALESCE(STRING_AGG(DISTINCT pm.sku || ' ' || COALESCE(pm.nombre, ''), ' | '), '') AS productos_busqueda
            FROM ventas.cotizaciones cot
            JOIN identidad.clientes c        ON c.persona_id = cot.cliente_id
            JOIN identidad.personas p        ON p.persona_id = c.persona_id
            LEFT JOIN tesoreria.monedas m    ON m.id = cot.moneda_id
            LEFT JOIN seguridad.usuarios uv  ON uv.usuario_id = cot.vendedor_id
            LEFT JOIN identidad.personas pv  ON pv.persona_id = uv.persona_id
            LEFT JOIN ventas.facturas f      ON f.id = cot.factura_generada_id
            LEFT JOIN ventas.cotizaciones_detalle cd ON cd.cotizacion_id = cot.id
            LEFT JOIN inventario.productos_maestro pm ON pm.id = cd.producto_maestro_id
            GROUP BY cot.id, cot.numero_cotizacion, cot.estado, cot.fecha_vencimiento,
                     cot.fecha_emision, cot.monto_subtotal, cot.monto_impuestos, cot.monto_total,
                     cot.observaciones, m.simbolo, p.nombre_razon_social, p.documento_identidad,
                     pv.nombre_razon_social, uv.username, f.numero_factura
            ORDER BY CASE cot.estado
                         WHEN 'PENDIENTE' THEN 0
                         WHEN 'CONVERTIDA_A_FACTURA' THEN 1
                         WHEN 'VENCIDA' THEN 2
                         ELSE 3
                     END ASC,
                     cot.fecha_emision DESC, cot.id DESC
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $kpiTotal = count($cotizaciones);
        foreach ($cotizaciones as $c) {
            $e = strtoupper((string) $c['estado']);
            if ($e === 'PENDIENTE') {
                $kpiPendientes++;
            } elseif ($e === 'CONVERTIDA_A_FACTURA') {
                $kpiConvertidas++;
            } else {
                $kpiVencidasCan++;
            }
        }
    } catch (Throwable $e) {
        $errorCarga = $e->getMessage();
    }
}

$badgesEstado = [
    'PENDIENTE' => ['label' => 'PENDIENTE', 'bg' => '#fff3e0', 'color' => '#e65100'],
    'CONVERTIDA_A_FACTURA' => ['label' => 'FACTURADA', 'bg' => '#e8f5e9', 'color' => '#2e7d32'],
    'VENCIDA' => ['label' => 'VENCIDA', 'bg' => '#ede7f6', 'color' => '#5e35b1'],
    'CANCELADA' => ['label' => 'CANCELADA', 'bg' => '#ffebee', 'color' => '#c62828'],
];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Cotizaciones Comerciales</title>
    <link rel="stylesheet" href="../styles.css?v=24" />
    <style>
        .toast {
            z-index: 999999 !important;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.55rem;
            line-height: 1;
            color: #8a7b70;
            cursor: pointer;
            padding: 2px 8px;
            border-radius: 6px;
        }

        .btn-close:hover {
            color: #211813;
            background: rgba(0, 0, 0, 0.06);
        }

        /* Controles de cantidad (− / +) en carrito de cotizaciones */
        .cot-qty-control {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .cot-qty-btn {
            width: 28px;
            height: 30px;
            border: 1px solid #d4c4b0;
            background: #faf6f0;
            color: #211813;
            border-radius: 6px;
            font-weight: 800;
            font-size: 1rem;
            line-height: 1;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .cot-qty-btn:hover {
            background: #eadecd;
            border-color: #a66e2e;
        }

        .cot-qty-input {
            width: 58px;
            height: 30px;
            text-align: center;
            font-weight: 700;
            border: 1px solid #d4c4b0;
            border-radius: 6px;
            font-family: monospace;
            font-size: 0.92rem;
        }

        /* Buscador en vivo de clientes */
        .cliente-picker-wrap {
            position: relative;
            flex: 1;
            min-width: 0;
        }

        .cliente-selected-pill {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            min-height: 38px;
            padding: 5px 10px;
            background: #fdf8ee;
            border: 1.5px solid #d4a359;
            border-radius: 6px;
            font-size: 0.85rem;
        }

        .cliente-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            max-height: 240px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #d4a359;
            border-radius: 8px;
            box-shadow: 0 10px 26px rgba(33, 24, 19, 0.18);
            z-index: 1500;
        }

        .cliente-opt-item {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f2ebe1;
            font-size: 0.84rem;
        }

        .cliente-opt-item:last-child {
            border-bottom: none;
        }

        .cliente-opt-item:hover {
            background: #fdf5ea;
        }

        .cot-tax-pill {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 99px;
            background: #f3ece1;
            color: #5a3818;
            margin: 1px 2px;
        }

        .cot-totals-box {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            background: #faf7f2;
            border: 1px solid #e7dcc8;
            border-radius: 10px;
            padding: 12px 14px;
            margin-top: 12px;
        }

        .cot-total-cell {
            text-align: right;
        }

        .cot-total-cell small {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            color: #8a7b70;
            text-transform: uppercase;
        }

        .cot-total-cell strong {
            font-family: monospace;
            font-size: 1.05rem;
            color: #211813;
        }

        @media print {
            body * {
                visibility: hidden;
            }

            #areaImpresionCotizacion,
            #areaImpresionCotizacion * {
                visibility: visible;
            }

            #areaImpresionCotizacion {
                position: fixed;
                left: 0;
                top: 0;
                width: 100%;
                background: #fff;
                padding: 24px;
                box-sizing: border-box;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>

<body>

    <!-- BARRA SUPERIOR -->
    <header class="topbar">
        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" class="mobile-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Abrir Menú">
                <span></span><span></span><span></span>
            </button>
            <a href="../../Login y Menu/menu.php" class="topbar-brand" style="text-decoration: none;">
                <span class="brand-title">HOUSE &amp; ICE</span>
                <span class="brand-dot">•</span>
                <span class="brand-subtitle">Sistema Administrativo</span>
            </a>
        </div>
        <div class="topbar-actions">
            <div class="topbar-user">
                <div class="user-avatar"><?= htmlspecialchars($sesionAvatarInitials) ?></div>
                <div class="user-info-col">
                    <div class="user-name"><?= htmlspecialchars($sesionNombreCompleto) ?></div>
                    <div class="user-sub-line">
                        <span class="user-role-text"><?= htmlspecialchars($sesionRol) ?></span>
                        <span class="user-bullet">•</span>
                        <?php if (function_exists('renderizarPillCajaTopbar')): ?>
                            <?= renderizarPillCajaTopbar() ?>
                        <?php else: ?>
                            <span class="user-caja-pill open"><span class="pill-dot green"></span> Caja
                                0<?= htmlspecialchars((string) $sesionCajaId) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="app-container">
        <?php include __DIR__ . '/../../sidebar.php'; ?>

        <main class="main-content">

            <div class="page-header">
                <div>
                    <h1 class="page-title">Cotizaciones Comerciales (Promesas de Precio)</h1>
                    <p class="page-subtitle">Congelamiento de precios e impuestos dinámicos sin afectar el inventario
                        físico, con vencimiento automático y conversión directa al POS</p>
                </div>

                <button type="button" class="btn btn-gold" onclick="abrirModalNuevaCotizacion()">
                    + Nueva Cotización
                </button>
            </div>

            <?php if (!empty($errorCarga)): ?>
                <div
                    style="background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                    <strong>Aviso:</strong> <?= htmlspecialchars($errorCarga) ?>
                </div>
            <?php endif; ?>

            <!-- KPIs -->
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 18px;">
                <div style="background: #fff; border: 1px solid #e7dcc8; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.76rem; color: #7a6b5d; font-weight: 700;">TOTAL COTIZACIONES</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #211813; margin-top: 4px;"><?= $kpiTotal ?>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #ffe0b2; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.76rem; color: #e65100; font-weight: 700;">PENDIENTES VIGENTES</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #e65100; margin-top: 4px;">
                        <?= $kpiPendientes ?>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #c8e6c9; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.76rem; color: #2e7d32; font-weight: 700;">CONVERTIDAS A FACTURA</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #2e7d32; margin-top: 4px;">
                        <?= $kpiConvertidas ?>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #ffcdd2; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.76rem; color: #c62828; font-weight: 700;">VENCIDAS / CANCELADAS</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #c62828; margin-top: 4px;">
                        <?= $kpiVencidasCan ?>
                    </div>
                </div>
            </div>

            <!-- TABLA DE COTIZACIONES (SIN ID SUBROGADO) -->
            <div class="table-card">
                <div class="table-card-top" style="flex-wrap: wrap; gap: 10px;">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Registro de Presupuestos y Cotizaciones</h2>
                        <span class="badge-count" id="badgeCount"><?= count($cotizaciones) ?> registros</span>
                    </div>

                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <select id="filtroEstado" class="form-control" style="width: 185px; height: 38px;"
                            onchange="filtrarTablaCotizaciones()">
                            <option value="">Todos los estados</option>
                            <option value="PENDIENTE">PENDIENTE</option>
                            <option value="CONVERTIDA_A_FACTURA">FACTURADA</option>
                            <option value="VENCIDA">VENCIDA</option>
                            <option value="CANCELADA">CANCELADA</option>
                        </select>

                        <div class="search-box" style="width: 280px;">
                            <span class="search-icon">🔍</span>
                            <input type="text" id="buscador"
                                placeholder="Buscar por N° cotización, cliente, SKU o vendedor..."
                                oninput="filtrarTablaCotizaciones()" />
                        </div>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>N° Cotización</th>
                                <th>Cliente</th>
                                <th>Emisión / Vigencia</th>
                                <th>Vendedor</th>
                                <th class="text-right">Monto Total</th>
                                <th class="text-center">Estado</th>
                                <th class="text-right" style="padding-right: 20px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tablaBody">
                            <?php if (empty($cotizaciones)): ?>
                                <tr>
                                    <td colspan="7" class="text-center" style="padding: 32px; color: #888;">
                                        No hay cotizaciones registradas todavía. Haz clic en <strong>+ Nueva
                                            Cotización</strong> para generar tu primer presupuesto.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($cotizaciones as $cot):
                                    $estKey = strtoupper((string) $cot['estado']);
                                    $estilo = $badgesEstado[$estKey] ?? ['label' => $estKey, 'bg' => '#eadecd', 'color' => '#5a3818'];
                                    $sim = $cot['simbolo'] ?: 'RD$';
                                    $textoF = strtolower(
                                        ($cot['numero_cotizacion'] ?? '') . ' ' .
                                        ($cot['cliente_nombre'] ?? '') . ' ' .
                                        ($cot['cliente_doc'] ?? '') . ' ' .
                                        ($cot['vendedor_nombre'] ?? '') . ' ' .
                                        ($cot['productos_busqueda'] ?? '')
                                    );
                                    ?>
                                    <tr class="fila-cotizacion" data-estado="<?= htmlspecialchars($estKey) ?>"
                                        data-texto="<?= htmlspecialchars($textoF) ?>">
                                        <td style="white-space: nowrap;">
                                            <strong style="color: #a66e2e; font-family: monospace; font-size: 0.94rem;">
                                                <?= htmlspecialchars($cot['numero_cotizacion']) ?>
                                            </strong>
                                            <div style="font-size: 0.74rem; color: #8a7b70;"><?= intval($cot['total_lineas']) ?>
                                                producto(s)</div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 700; color: #211813;">
                                                <?= htmlspecialchars($cot['cliente_nombre']) ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: #8a7b70; font-family: monospace;">
                                                <?= htmlspecialchars($cot['cliente_doc']) ?>
                                            </div>
                                        </td>
                                        <td style="white-space: nowrap; font-size: 0.82rem;">
                                            <div>Emitida: <?= htmlspecialchars($cot['fecha_emision_fmt']) ?></div>
                                            <div
                                                style="font-weight: 700; color: <?= ($estKey === 'VENCIDA') ? '#c62828' : '#794a15' ?>;">
                                                Vence: <?= htmlspecialchars($cot['fecha_vencimiento_fmt']) ?>
                                            </div>
                                        </td>
                                        <td style="font-size: 0.84rem; color: #4a3b32;">
                                            <?= htmlspecialchars($cot['vendedor_nombre'] ?: '—') ?>
                                        </td>
                                        <td class="text-right"
                                            style="white-space: nowrap; font-family: monospace; font-weight: 800; color: #211813;">
                                            <?= htmlspecialchars($sim) ?>         <?= number_format((float) $cot['monto_total'], 2) ?>
                                            <div style="font-size: 0.72rem; font-weight: 400; color: #8a7b70;">
                                                Imp: <?= htmlspecialchars($sim) ?>
                                                <?= number_format((float) $cot['monto_impuestos'], 2) ?>
                                            </div>
                                        </td>
                                        <td class="text-center" style="white-space: nowrap;">
                                            <span class="badge"
                                                style="background: <?= $estilo['bg'] ?>; color: <?= $estilo['color'] ?>; font-weight: 700;">
                                                <?= htmlspecialchars($estilo['label']) ?>
                                            </span>
                                            <?php if (!empty($cot['factura_numero'])): ?>
                                                <div
                                                    style="font-size: 0.71rem; color: #2e7d32; font-family: monospace; margin-top: 2px;">
                                                    <?= htmlspecialchars($cot['factura_numero']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right action-btns" style="padding-right: 20px; white-space: nowrap;">
                                            <button type="button" class="btn-sm btn-outline"
                                                onclick="verDetalleCotizacion(<?= (int) $cot['id'] ?>)">Ver / Imprimir</button>
                                            <?php if ($estKey === 'PENDIENTE'): ?>
                                                <button type="button" class="btn-sm btn-outline"
                                                    onclick="abrirEditarCotizacion(<?= (int) $cot['id'] ?>)">Editar</button>
                                                <a href="../Facturacion/facturacion.php?cotizacion_id=<?= (int) $cot['id'] ?>"
                                                    class="btn-sm btn-gold" style="text-decoration: none;">Facturar</a>
                                                <?php if ($puedeCancelarCot): ?>
                                                    <button type="button" class="btn-sm btn-danger"
                                                        onclick="abrirModalCancelarCot(<?= (int) $cot['id'] ?>, '<?= htmlspecialchars($cot['numero_cotizacion'], ENT_QUOTES) ?>')">Cancelar</button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-card-footer">
                    <span id="footerInfo">Total: <?= count($cotizaciones) ?> cotizaciones mostradas</span>
                    <span>House &amp; Ice • Presupuestos sin reserva de inventario</span>
                </div>
            </div>

        </main>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 1: CREAR / EDITAR COTIZACIÓN (PATRÓN 3 BLOQUES)                    -->
    <!-- ======================================================================== -->
    <div id="modalCotizacion" class="modal-overlay">
        <div class="modal-box modal-xl">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="tituloModalCot">Nueva Cotización Comercial</h2>
                    <p class="modal-subtitle" style="margin: 0; font-size: 0.8rem; color: #7a6b5d;">Promesa comercial
                        con precios e impuestos congelados (no descuenta ni reserva stock)</p>
                </div>
                <button type="button" class="btn-close" onclick="cerrarModalCotizacion()">&times;</button>
            </div>

            <form id="formCotizacion" onsubmit="guardarCotizacion(event)">
                <input type="hidden" id="cot_id_edicion" value="0" />

                <div class="modal-body" style="max-height: 74vh; overflow-y: auto;">

                    <!-- BLOQUE 1: CLIENTE (BUSCADOR EN VIVO), VIGENCIA Y MONEDA -->
                    <div class="promo-block-card">
                        <div class="promo-block-header">
                            <div class="promo-block-title">
                                <span class="promo-step-badge">1</span>
                                <span>Cliente, Vigencia y Condiciones del Presupuesto</span>
                            </div>
                            <span class="promo-type-hint">Las cotizaciones vencidas se bloquean automáticamente</span>
                        </div>

                        <div style="display: grid; grid-template-columns: 2fr 1fr 0.8fr; gap: 12px;">
                            <div class="form-group">
                                <label for="cot_cliente_buscar" class="form-label">Cliente *</label>
                                <div style="display: flex; gap: 6px; align-items: flex-start;">
                                    <div class="cliente-picker-wrap" id="cotClientePickerWrap">
                                        <input type="hidden" id="cot_cliente_id" value="" />
                                        <div id="cot_cliente_pill" class="cliente-selected-pill">
                                            <div id="cot_cliente_pill_texto"
                                                style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            </div>
                                            <button type="button" class="btn-sm btn-outline"
                                                onclick="limpiarClienteCot(true)"
                                                style="padding: 2px 7px; font-size: 0.74rem; flex-shrink: 0;">Cambiar</button>
                                        </div>
                                        <input type="text" id="cot_cliente_buscar" class="form-control"
                                            autocomplete="off" placeholder="Buscar cliente por nombre, cédula, RNC"
                                            onfocus="buscarClientesCot()" oninput="programarBusquedaClientesCot()"
                                            onkeydown="teclaBusquedaClientesCot(event)" style="width: 320px" />
                                        <div id="cot_cliente_dropdown" class="cliente-dropdown"></div>
                                    </div>
                                    <button type="button" class="btn btn-beige" onclick="abrirModalClienteRapidoCot()"
                                        style="white-space: nowrap;">+ Cliente</button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="cot_fecha_vencimiento" class="form-label">Válida Hasta (Vencimiento)
                                    *</label>
                                <input type="date" id="cot_fecha_vencimiento" class="form-control" required
                                    min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" />
                            </div>

                            <div class="form-group">
                                <label for="cot_moneda_id" class="form-label">Moneda *</label>
                                <select id="cot_moneda_id" class="form-control" onchange="renderizarTotalesCot()">
                                    <?php foreach ($monedas as $mon): ?>
                                        <option value="<?= (int) $mon['id'] ?>"
                                            data-simbolo="<?= htmlspecialchars($mon['simbolo']) ?>">
                                            <?= htmlspecialchars($mon['codigo_iso']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="cot_observaciones" class="form-label">Observaciones / Notas del Evento
                                (Opcional)</label>
                            <input type="text" id="cot_observaciones" class="form-control" maxlength="255"
                                placeholder="Ej. Evento corporativo sábado • Precios sujetos a disponibilidad al momento de facturar" />
                        </div>
                    </div>

                    <!-- BLOQUE 2: AGREGAR PRODUCTOS (ESCÁNER + MODAL CATÁLOGO) -->
                    <div class="promo-block-card dynamic-builder">
                        <div class="promo-block-header">
                            <div class="promo-block-title">
                                <span class="promo-step-badge">2</span>
                                <span>Agregar Productos al Presupuesto</span>
                            </div>
                            <button type="button" class="btn-sm btn-outline" onclick="abrirModalCatalogoCot()"
                                style="font-weight: 700; color: #794a15; border-color: #d4a359; background: #fff8ee;">
                                🔍 Explorar Catálogo y Presentaciones
                            </button>
                        </div>

                        <div style="display: flex; gap: 8px; align-items: end;">
                            <div class="form-group" style="flex: 1; margin-bottom: 0;">
                                <label for="cot_scanner" class="form-label">Escáner Rápido (Código de Barras o
                                    SKU)</label>
                                <input type="text" id="cot_scanner" class="form-control"
                                    placeholder="Escanea botella, six-pack o caja y presiona Enter..."
                                    onkeydown="if(event.key==='Enter'){ event.preventDefault(); escanearCodigoCot(); }" />
                            </div>
                            <button type="button" class="btn btn-secondary"
                                onclick="escanearCodigoCot()">Agregar</button>
                        </div>
                    </div>

                    <!-- BLOQUE 3: CARRITO DE COTIZACIÓN -->
                    <div class="promo-block-card rules-cart">
                        <div class="promo-block-header">
                            <div class="promo-block-title">
                                <span class="promo-step-badge">3</span>
                                <span>Detalle de Artículos Cotizados</span>
                            </div>
                            <span class="badge-count" id="cotContadorCarrito">0 líneas</span>
                        </div>

                        <div class="carrito-reglas-wrapper">
                            <table class="carrito-reglas-table">
                                <thead>
                                    <tr>
                                        <th>SKU / Producto</th>
                                        <th style="text-align: center; width: 145px;">Cantidad</th>
                                        <th style="text-align: right; width: 110px;">Precio Unit.</th>
                                        <th style="text-align: right; width: 125px;">Desc.
                                            Manual<?= $puedeDescuentoManual ? '' : ' 🔒' ?></th>
                                        <th style="text-align: right; width: 125px;">Impuestos</th>
                                        <th style="text-align: right; width: 115px;">Total Línea</th>
                                        <th style="width: 55px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="cotTablaCarritoBody">
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 20px; color: #888;">
                                            El presupuesto está vacío. Escanea un producto o usa <strong>🔍 Explorar
                                                Catálogo y Presentaciones</strong>.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="cot-totals-box">
                            <div class="cot-total-cell">
                                <small>Bruto</small>
                                <strong id="cotTotBruto">RD$ 0.00</strong>
                            </div>
                            <div class="cot-total-cell">
                                <small>Descuentos</small>
                                <strong id="cotTotDesc" style="color: #2e7d32;">-RD$ 0.00</strong>
                            </div>
                            <div class="cot-total-cell">
                                <small>Impuestos Dinámicos</small>
                                <strong id="cotTotImp">RD$ 0.00</strong>
                                <div id="cotPillsImp" style="margin-top: 3px;"></div>
                            </div>
                            <div class="cot-total-cell">
                                <small>Total Cotizado</small>
                                <strong id="cotTotGeneral" style="color: #a66e2e; font-size: 1.2rem;">RD$ 0.00</strong>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalCotizacion()">Cancelar</button>
                    <button type="submit" class="btn btn-gold" id="btnGuardarCot">Guardar Cotización</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 2: BUSCADOR FILTRADO DE CATÁLOGO Y PRESENTACIONES                  -->
    <!-- ======================================================================== -->
    <div id="modalCatalogoCot" class="modal-overlay" style="z-index: 1200;"
        onclick="if(event.target.id==='modalCatalogoCot') cerrarModal('modalCatalogoCot')">
        <div class="modal-box modal-xl">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" style="margin: 0;">Explorar Catálogo y Presentaciones</h3>
                    <small style="color: #7a6b5d;">Puedes cotizar cualquier producto del catálogo (se muestra el stock
                        global solo como referencia)</small>
                </div>
                <button type="button" class="btn-close" onclick="cerrarModal('modalCatalogoCot')">&times;</button>
            </div>

            <div class="modal-body">
                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Nombre, SKU, Código de Barras o Empaque</label>
                        <input type="text" id="catFiltroTexto" class="form-control"
                            placeholder="Ej. Brugal, Presidente, Caja..." oninput="programarBusquedaCatCot()" />
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Categoría</label>
                        <select id="catFiltroCategoria" class="form-control" onchange="buscarEnCatalogoCot()">
                            <option value="0">Todas las categorías</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= (int) $cat['id'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Marca</label>
                        <select id="catFiltroMarca" class="form-control" onchange="buscarEnCatalogoCot()">
                            <option value="0">Todas las marcas</option>
                            <?php foreach ($marcas as $mar): ?>
                                <option value="<?= (int) $mar['id'] ?>"><?= htmlspecialchars($mar['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <label
                        style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.84rem; cursor: pointer;">
                        <input type="checkbox" id="catFiltroSoloStock" onchange="buscarEnCatalogoCot()"
                            style="width: 15px; height: 15px; accent-color: #a66e2e;" />
                        <span>Mostrar solo productos con existencias actuales</span>
                    </label>
                    <span class="badge-count" id="catContadorResultados">0 productos</span>
                </div>

                <div class="carrito-reglas-wrapper" style="max-height: 380px; overflow-y: auto;">
                    <table class="carrito-reglas-table">
                        <thead>
                            <tr>
                                <th style="width: 26%;">Producto / SKU</th>
                                <th style="width: 18%;">Categoría / Marca</th>
                                <th style="width: 12%; text-align: center;">Stock Ref.</th>
                                <th>Presentaciones (clic para agregar)</th>
                            </tr>
                        </thead>
                        <tbody id="catTablaResultados"></tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                    onclick="cerrarModal('modalCatalogoCot')">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 3: VER / IMPRIMIR COTIZACIÓN + CHEQUEO EN VIVO DE STOCK            -->
    <!-- ======================================================================== -->
    <div id="modalDetalleCot" class="modal-overlay"
        onclick="if(event.target.id==='modalDetalleCot') cerrarModal('modalDetalleCot')">
        <div class="modal-box modal-lg">
            <div class="modal-header no-print">
                <h3 class="modal-title" id="detCotTitulo">Detalle de Cotización</h3>
                <button type="button" class="btn-close" onclick="cerrarModal('modalDetalleCot')">&times;</button>
            </div>
            <div class="modal-body" id="areaImpresionCotizacion" style="max-height: 72vh; overflow-y: auto;">
                <p style="color: #888;">Consultando cotización...</p>
            </div>
            <div class="modal-footer no-print" id="detCotFooter">
                <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalDetalleCot')">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 4: CLIENTE RÁPIDO (+ CLIENTE)                                      -->
    <!-- ======================================================================== -->
    <div id="modalClienteRapidoCot" class="modal-overlay" style="z-index: 1250;">
        <div class="modal-box" style="max-width: 520px;">
            <div class="modal-header">
                <h3 class="modal-title">Registrar Nuevo Cliente</h3>
                <button type="button" class="btn-close" onclick="cerrarModal('modalClienteRapidoCot')">&times;</button>
            </div>
            <form id="formClienteRapidoCot" onsubmit="guardarClienteRapidoCot(event)">
                <div class="modal-body">
                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Tipo de Documento *</label>
                            <select id="cliCotTipoDoc" class="form-control">
                                <option value="RNC">RNC (Jurídico)</option>
                                <option value="CEDULA" selected>Cédula</option>
                                <option value="PASAPORTE">Pasaporte</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Número de Documento *</label>
                            <input type="text" id="cliCotDoc" class="form-control" required maxlength="20"
                                placeholder="001-1234567-8" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nombre Completo o Razón Social *</label>
                        <input type="text" id="cliCotNombre" class="form-control" required maxlength="150" />
                    </div>
                    <div class="form-row-2">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Teléfono</label>
                            <input type="text" id="cliCotTel" class="form-control" maxlength="20"
                                placeholder="(809) 555-0101" />
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Correo Electrónico</label>
                            <input type="email" id="cliCotEmail" class="form-control" maxlength="120"
                                placeholder="cliente@correo.com" />
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                        onclick="cerrarModal('modalClienteRapidoCot')">Cancelar</button>
                    <button type="submit" class="btn btn-gold" id="btnGuardarCliCot">Guardar y Seleccionar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 5: CANCELAR COTIZACIÓN PENDIENTE                                   -->
    <!-- ======================================================================== -->
    <div id="modalCancelarCot" class="modal-overlay"
        onclick="if(event.target.id==='modalCancelarCot') cerrarModal('modalCancelarCot')">
        <div class="modal-box" style="max-width: 460px;">
            <div class="modal-header">
                <h3 class="modal-title">Cancelar Cotización</h3>
                <button type="button" class="btn-close" onclick="cerrarModal('modalCancelarCot')">&times;</button>
            </div>
            <form id="formCancelarCot" onsubmit="confirmarCancelacionCot(event)">
                <input type="hidden" id="cancelarCotId" value="" />
                <div class="modal-body">
                    <div
                        style="background: #fff8ee; border: 1px solid #e7dcc8; border-radius: 8px; padding: 10px 12px; margin-bottom: 12px; font-size: 0.86rem;">
                        Vas a cerrar la cotización <strong id="cancelarCotNumero"></strong> como
                        <strong>CANCELADA</strong>. Conservará su historial para análisis comercial.
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="cancelarCotMotivo" class="form-label">Motivo de Cancelación / Rechazo *</label>
                        <input type="text" id="cancelarCotMotivo" class="form-control" required minlength="4"
                            maxlength="200" placeholder="Ej. El cliente pospuso el evento / eligió otra propuesta" />
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                        onclick="cerrarModal('modalCancelarCot')">Volver</button>
                    <button type="submit" class="btn btn-danger" id="btnConfirmarCancelarCot">Confirmar
                        Cancelación</button>
                </div>
            </form>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
        window.COT_PUEDE_DESC_MANUAL = <?= $puedeDescuentoManual ? 'true' : 'false' ?>;
    </script>
    <script src="cotizaciones.js?v=<?= time() ?>"></script>
</body>

</html>