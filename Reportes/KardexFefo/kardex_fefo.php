<?php
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

requerirSesionActiva(false, '../../Login y Menu/login.php');

$esAdmin = function_exists('usuarioEsAdministrador') && usuarioEsAdministrador();
$puedeKardex = $esAdmin
    || (function_exists('usuarioPuedeAccederMovimientosInventario') && usuarioPuedeAccederMovimientosInventario())
    || (function_exists('usuarioPuedeAccederLotes') && usuarioPuedeAccederLotes())
    || (function_exists('usuarioTienePermiso') && usuarioTienePermiso('VER_PRODUCTOS'));

if (!$puedeKardex) {
    header("Location: ../../Login y Menu/menu.php?error=" . urlencode("Acceso restringido: no posees permisos para consultar el Kardex Valorado FEFO."));
    exit;
}

$almacenes = $categorias = $marcas = $productos = [];
if ($pdo) {
    try {
        $almacenes = $pdo->query("SELECT id, nombre FROM inventario.almacenes WHERE activo = TRUE ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $categorias = $pdo->query("SELECT id, nombre FROM inventario.categoria_producto WHERE id > 0 AND estado = 'ACTIVO' ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $marcas = $pdo->query("SELECT id, nombre FROM inventario.marca WHERE id > 0 AND estado = 'ACTIVO' ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $productos = $pdo->query("SELECT id, sku, COALESCE(NULLIF(NULLIF(TRIM(nombre), ''), 'Producto sin nombre asignado'), sku) AS nombre FROM inventario.productos_maestro WHERE id > 0 ORDER BY nombre ASC, sku ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }
}

$hoy = date('Y-m-d');
$inicioDeMes = date('Y-m-01');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Kardex Valorado FEFO e Inventario Total</title>
    <link rel="stylesheet" href="../../Panel de configuracion/styles.css?v=21" />
    <style>
        .kf-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .kf-kpi-card {
            background: #fff;
            border: 1px solid #e7dcc8;
            border-radius: 10px;
            padding: 14px 18px;
        }

        .kf-kpi-label {
            font-size: 0.72rem;
            font-weight: 800;
            color: #7a6b5d;
            text-transform: uppercase;
        }

        .kf-kpi-value {
            font-size: 1.4rem;
            font-weight: 900;
            font-family: monospace;
            color: #211813;
            margin-top: 4px;
        }

        .kf-kpi-sub {
            font-size: 0.74rem;
            color: #8a7b70;
            margin-top: 3px;
        }

        .kf-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            border-bottom: 2px solid #e7dcc8;
            padding-bottom: 10px;
        }

        .kf-tab-btn {
            padding: 9px 16px;
            border-radius: 8px;
            border: 1px solid #d8c8b4;
            background: #faf6f0;
            color: #5a3818;
            font-weight: 700;
            font-size: 0.84rem;
            cursor: pointer;
        }

        .kf-tab-btn.active {
            background: #211813;
            color: #f6e7cf;
            border-color: #211813;
        }

        .kf-filter-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(165px, 1fr));
            gap: 10px;
            align-items: end;
            padding: 14px 18px;
            background: #fcfaf6;
            border-bottom: 1px solid #efe7da;
        }

        .kf-alm-pill {
            display: inline-block;
            font-size: 0.71rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 99px;
            background: #f3ece1;
            color: #5a3818;
            border: 1px solid #e2d3bd;
            margin: 1px 2px;
            white-space: nowrap;
        }

        .kf-badge {
            display: inline-block;
            font-size: 0.71rem;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 5px;
            white-space: nowrap;
        }

        .kf-kardex-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            justify-content: space-between;
            align-items: center;
            padding: 10px 18px;
            background: #fdf8ee;
            border-bottom: 1px solid #eadecd;
            font-size: 0.82rem;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(20, 14, 10, 0.65);
            backdrop-filter: blur(4px);
            z-index: 99999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
        }

        .modal-content {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 20px 48px rgba(0, 0, 0, 0.35);
            border: 1px solid #d8b888;
            width: 100%;
            overflow: hidden;
        }

        .modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eadecd;
            background: #fdfaf4;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .modal-title {
            font-size: 1.12rem;
            font-weight: 800;
            color: #211813;
            margin: 0 0 3px 0;
        }

        .modal-subtitle {
            font-size: 0.8rem;
            color: #8a7b70;
            margin: 0;
        }

        .modal-body {
            padding: 18px 20px;
            max-height: 74vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 12px 20px;
            border-top: 1px solid #eadecd;
            background: #fdfaf4;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.55rem;
            line-height: 1;
            color: #8a7b70;
            cursor: pointer;
        }

        @media print {

            .topbar,
            .sidebar,
            .sidebar-overlay,
            .kf-tabs,
            .kf-filter-bar,
            .no-print {
                display: none !important;
            }

            .app-container,
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
        }
    </style>
</head>

<body>
    <header class="topbar">
        <div style="display:flex; align-items:center; gap:10px;">
            <button type="button" class="mobile-toggle-btn" onclick="toggleMobileSidebar()"
                aria-label="Abrir Menú"><span></span><span></span><span></span></button>
            <a href="../../Login y Menu/menu.php" class="topbar-brand" style="text-decoration:none;">
                <span class="brand-title">HOUSE &amp; ICE</span><span class="brand-dot">•</span><span
                    class="brand-subtitle">Sistema Administrativo</span>
            </a>
        </div>
        <div class="topbar-actions">
            <div class="topbar-user">
                <div class="user-avatar">
                    <?= htmlspecialchars($sesionAvatarInitials ?? 'AD') ?>
                </div>
                <div class="user-info-col">
                    <div class="user-name">
                        <?= htmlspecialchars($sesionNombreCompleto ?? 'Usuario') ?>
                    </div>
                    <div class="user-sub-line">
                        <span class="user-role-text">
                            <?= htmlspecialchars($sesionRol ?? 'Usuario') ?>
                        </span>
                        <span class="user-bullet">•</span>
                        <?php if (function_exists('renderizarPillCajaTopbar'))
                            echo renderizarPillCajaTopbar(); ?>
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
                    <h1 class="page-title">Kardex Valorado FEFO e Inventario Total</h1>
                    <p class="page-subtitle">Existencias consolidadas por producto y almacén, valoración contable al
                        costo promedio, historial de movimientos y caducidad FEFO</p>
                </div>
                <div class="no-print" style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="btn btn-beige" onclick="window.print()">🖨️ Imprimir</button>
                    <button type="button" class="btn btn-gold" onclick="exportarCsvActivo()">📥 Exportar Excel
                        (CSV)</button>
                </div>
            </div>

            <div class="kf-kpi-grid">
                <div class="kf-kpi-card" style="border-left:4px solid #794a15;">
                    <div class="kf-kpi-label" style="color:#794a15;">Valor Inventario al Costo (DOP)</div>
                    <div class="kf-kpi-value" id="kpiValorCosto" style="color:#794a15;">RD$ 0.00</div>
                    <div class="kf-kpi-sub" id="kpiSkusSub">0 SKUs con stock • 0 agotados</div>
                </div>
                <div class="kf-kpi-card" style="border-left:4px solid #2e7d32;">
                    <div class="kf-kpi-label" style="color:#2e7d32;">Valor Potencial a Venta (PVP)</div>
                    <div class="kf-kpi-value" id="kpiValorVenta" style="color:#1b5e20;">RD$ 0.00</div>
                    <div class="kf-kpi-sub" id="kpiMargenSub">Margen bruto potencial: RD$ 0.00</div>
                </div>
                <div class="kf-kpi-card" style="border-left:4px solid #1565c0;">
                    <div class="kf-kpi-label" style="color:#1565c0;">Existencias Totales (Unidades)</div>
                    <div class="kf-kpi-value" id="kpiUnidadesFisicas" style="color:#0d47a1;">0 uds.</div>
                    <div class="kf-kpi-sub" id="kpiUnidadesDispSub">0 disponibles para venta</div>
                </div>
                <div class="kf-kpi-card" style="border-left:4px solid #c62828;">
                    <div class="kf-kpi-label" style="color:#c62828;">Capital en Riesgo FEFO (&le; 30 días)</div>
                    <div class="kf-kpi-value" id="kpiValorRiesgo" style="color:#c62828;">RD$ 0.00</div>
                    <div class="kf-kpi-sub" id="kpiRiesgoSub">0 lote(s) • 0 unidades</div>
                </div>
            </div>

            <div class="kf-tabs">
                <button type="button" class="kf-tab-btn active" data-tab="inventario"
                    onclick="cambiarTabKardex('inventario')">📦 1. Inventario Total por Producto</button>
                <button type="button" class="kf-tab-btn" data-tab="kardex" onclick="cambiarTabKardex('kardex')">📜 2.
                    Libro Kardex Valorado</button>
                <button type="button" class="kf-tab-btn" data-tab="fefo" onclick="cambiarTabKardex('fefo')">⏳ 3. Matriz
                    FEFO (Caducidad)</button>
            </div>

            <!-- PANEL 1: INVENTARIO TOTAL -->
            <section id="panelTab_inventario" class="table-card">
                <div class="table-card-top">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Inventario Total Consolidado y Valorizado por Producto</h2>
                        <span class="badge-count" id="badgeInvCount">0 productos</span>
                    </div>
                    <label
                        style="display:inline-flex; align-items:center; gap:6px; font-size:0.82rem; font-weight:600; color:#5a3818; cursor:pointer;">
                        <input type="checkbox" id="invSoloStock" onchange="cargarInventarioConsolidado()" /> Solo con
                        existencias (&gt; 0)
                    </label>
                </div>
                <div class="kf-filter-bar">
                    <div class="form-group" style="margin:0; grid-column:span 2;">
                        <label class="form-label" for="invBuscar">Buscar SKU, Producto o Código de Barras</label>
                        <input type="text" id="invBuscar" class="form-control"
                            placeholder="Ej. RON-BRU, Presidente, Coca-Cola..." oninput="programarCargaInv()" />
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="invAlmacen">Almacén</label>
                        <select id="invAlmacen" class="form-control" onchange="cargarInventarioConsolidado()">
                            <option value="0">Todos los almacenes (Global)</option>
                            <?php foreach ($almacenes as $a): ?>
                                <option value="<?= (int) $a['id'] ?>">
                                    <?= htmlspecialchars($a['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="invCategoria">Categoría</label>
                        <select id="invCategoria" class="form-control" onchange="cargarInventarioConsolidado()">
                            <option value="0">Todas</option>
                            <?php foreach ($categorias as $c): ?>
                                <option value="<?= (int) $c['id'] ?>">
                                    <?= htmlspecialchars($c['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="invMarca">Marca</label>
                        <select id="invMarca" class="form-control" onchange="cargarInventarioConsolidado()">
                            <option value="0">Todas</option>
                            <?php foreach ($marcas as $m): ?>
                                <option value="<?= (int) $m['id'] ?>">
                                    <?= htmlspecialchars($m['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>SKU / PRODUCTO</th>
                                <th>DISTRIBUCIÓN POR ALMACÉN</th>
                                <th style="text-align:center;">STOCK TOTAL (FÍS. / DISP.)</th>
                                <th style="text-align:center;">PRÓX. VENC. FEFO</th>
                                <th style="text-align:right;">COSTO PROM. / PVP</th>
                                <th style="text-align:right;">VALORACIÓN COSTO</th>
                                <th style="text-align:right;">VALORACIÓN VENTA</th>
                                <th class="no-print" style="text-align:right; padding-right:16px;">ACCIONES</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyInvConsolidado">
                            <tr>
                                <td colspan="8" style="text-align:center; padding:26px; color:#8a7b70;">Cargando
                                    inventario total...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- PANEL 2: LIBRO KARDEX -->
            <section id="panelTab_kardex" class="table-card" style="display:none;">
                <div class="table-card-top">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Libro Kardex Valorado (Entradas, Salidas y Saldo Corrido)</h2>
                        <span class="badge-count" id="badgeKardexCount">0 movimientos</span>
                    </div>
                    <div style="font-size:0.78rem; color:#7a6b5d;">Selecciona 1 producto para activar el <strong>Saldo
                            Corrido</strong></div>
                </div>
                <div class="kf-filter-bar">
                    <div class="form-group" style="margin:0; grid-column:span 2;">
                        <label class="form-label" for="karProducto">Producto</label>
                        <select id="karProducto" class="form-control" onchange="cargarLibroKardex()">
                            <option value="0">-- Todos los productos (Vista General) --</option>
                            <?php foreach ($productos as $pr): ?>
                                <option value="<?= (int) $pr['id'] ?>">
                                    <?= htmlspecialchars($pr['sku'] . ' — ' . $pr['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="karTipo">Tipo</label>
                        <select id="karTipo" class="form-control" onchange="cargarLibroKardex()">
                            <option value="">Todos</option>
                            <option value="ENTRADA_COMPRA">ENTRADA_COMPRA (+)</option>
                            <option value="DEVOLUCION_CLIENTE">DEVOLUCION_CLIENTE (+)</option>
                            <option value="AJUSTE_ENTRADA">AJUSTE_ENTRADA (+)</option>
                            <option value="SALIDA_VENTA">SALIDA_VENTA (-)</option>
                            <option value="AJUSTE_SALIDA">AJUSTE_SALIDA (-)</option>
                            <option value="MERMA_ROTURA">MERMA_ROTURA (-)</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="karDesde">Desde</label>
                        <input type="date" id="karDesde" class="form-control"
                            value="<?= htmlspecialchars($inicioDeMes) ?>" onchange="cargarLibroKardex()" />
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="karHasta">Hasta</label>
                        <input type="date" id="karHasta" class="form-control" value="<?= htmlspecialchars($hoy) ?>"
                            onchange="cargarLibroKardex()" />
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="karBuscar">Lote / Documento</label>
                        <input type="text" id="karBuscar" class="form-control" placeholder="FAC-, LOT-, B04..."
                            oninput="programarCargaKar()" />
                    </div>
                </div>
                <div class="kf-kardex-strip">
                    <span>Entradas: <strong id="karEntradasLbl" style="color:#1b5e20;">0 uds. (RD$ 0.00)</strong></span>
                    <span>Salidas: <strong id="karSalidasLbl" style="color:#c62828;">0 uds. (RD$ 0.00)</strong></span>
                    <span id="karSaldoInicialWrap" style="display:none;">Saldo Inicial: <strong
                            id="karSaldoInicialLbl">0 uds.</strong></span>
                    <span id="karSaldoFinalWrap" style="display:none;">Saldo Final: <strong id="karSaldoFinalLbl"
                            style="color:#794a15;">0 uds. (RD$ 0.00)</strong></span>
                </div>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>FECHA / MOV #</th>
                                <th>TIPO / DOCUMENTO</th>
                                <th>PRODUCTO / LOTE</th>
                                <th style="text-align:center;">ENTRADA</th>
                                <th style="text-align:center;">SALIDA</th>
                                <th style="text-align:right;">COSTO UNIT.</th>
                                <th style="text-align:right;">VALOR MOV.</th>
                                <th style="text-align:right;">SALDO (UDS / DOP)</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyLibroKardex">
                            <tr>
                                <td colspan="8" style="text-align:center; padding:26px; color:#8a7b70;">Cargando
                                    Kardex...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- PANEL 3: MATRIZ FEFO -->
            <section id="panelTab_fefo" class="table-card" style="display:none;">
                <div class="table-card-top">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Matriz FEFO • Existencias Físicas Ordenadas por Vencimiento</h2>
                        <span class="badge-count" id="badgeFefoCount">0 lotes</span>
                    </div>
                    <div id="fefoResumenTotal"
                        style="font-size:0.84rem; font-weight:800; color:#794a15; font-family:monospace;">Total: 0 uds.
                        • RD$ 0.00</div>
                </div>
                <div class="kf-filter-bar">
                    <div class="form-group" style="margin:0; grid-column:span 2;">
                        <label class="form-label" for="fefoBuscar">Buscar SKU, Producto, Lote o Almacén</label>
                        <input type="text" id="fefoBuscar" class="form-control"
                            placeholder="Ej. LOT-2026, Presidente..." oninput="programarCargaFefo()" />
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="fefoAlmacen">Almacén</label>
                        <select id="fefoAlmacen" class="form-control" onchange="cargarMatrizFefo()">
                            <option value="0">Todos los almacenes</option>
                            <?php foreach ($almacenes as $a): ?>
                                <option value="<?= (int) $a['id'] ?>">
                                    <?= htmlspecialchars($a['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="fefoEstado">Semáforo FEFO</label>
                        <select id="fefoEstado" class="form-control" onchange="cargarMatrizFefo()">
                            <option value="">Todos</option>
                            <option value="VENCIDO">🔴 Vencidos</option>
                            <option value="CRITICO">🟠 Críticos (0–15d)</option>
                            <option value="PROXIMO">🟡 Próximos (16–45d)</option>
                            <option value="VIGENTE">🟢 Vigentes (&gt;45d)</option>
                            <option value="SIN_LOTE">⚪ Sin Caducidad</option>
                        </select>
                    </div>
                </div>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ALMACÉN</th>
                                <th>SKU / PRODUCTO</th>
                                <th>LOTE</th>
                                <th>VENCIMIENTO</th>
                                <th style="text-align:center;">SEMÁFORO FEFO</th>
                                <th style="text-align:center;">FÍSICO / DISP.</th>
                                <th style="text-align:right;">COSTO UNIT.</th>
                                <th style="text-align:right; padding-right:18px;">VALOR LOTE</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyMatrizFefo">
                            <tr>
                                <td colspan="8" style="text-align:center; padding:26px; color:#8a7b70;">Cargando matriz
                                    FEFO...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <!-- MODAL DETALLE EXISTENCIAS POR ALMACÉN Y LOTE -->
    <div id="modalDetalleProducto" class="modal-backdrop"
        onclick="if(event.target.id==='modalDetalleProducto') cerrarModalProd()">
        <div class="modal-content" style="max-width:760px;">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" id="detProdTitulo">Detalle de Existencias</h3>
                    <p class="modal-subtitle" id="detProdSubtitulo">Distribución física por almacén y lotes FEFO</p>
                </div>
                <button type="button" class="btn-close" onclick="cerrarModalProd()">&times;</button>
            </div>
            <div class="modal-body" id="detProdBody">
                <p style="color:#8a7b70;">Cargando...</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-gold"
                    onclick="cerrarModalProd()">Cerrar</button></div>
        </div>
    </div>

    <script src="kardex_fefo.js?v=<?= time() ?>"></script>
</body>

</html>