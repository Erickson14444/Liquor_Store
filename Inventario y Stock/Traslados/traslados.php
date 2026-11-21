<?php
/**
 * ============================================================================
 * Módulo 3: Inventario & Stock • Traslados Internos entre Almacenes
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: inventario.traslados_internos, inventario.traslado_detalles,
 *         inventario.inventario_stock, inventario.lotes, inventario.almacenes
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

requerirSesionActiva(false, '../../Login y Menu/login.php');

$traslados = [];
$almacenes = [];
$productos = [];
$categorias = [];
$marcas = [];
$supervisores = [];

$kpiTotal = 0;
$kpiEnTransito = 0;
$kpiRecibidos = 0;
$kpiCancelados = 0;

if ($pdo) {
    try {
        // 1. Almacenes activos
        $almacenesRaw = $pdo->query("SELECT * FROM inventario.almacenes ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($almacenesRaw as $alm) {
            if (array_key_exists('activo', $alm) && !($alm['activo'] === true || $alm['activo'] === 't' || $alm['activo'] == 1)) {
                continue;
            }
            if (array_key_exists('estado', $alm) && strtoupper((string) $alm['estado']) !== 'ACTIVO') {
                continue;
            }
            $almacenes[] = $alm;
        }

        // 2. Productos maestros (sin comodín id = 0)
        $productos = $pdo->query("
            SELECT pm.id, pm.sku, pm.requiere_lote, pm.costo_promedio,
                   COALESCE(
                       NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                       (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                       pm.sku
                   ) AS nombre
            FROM inventario.productos_maestro pm
            WHERE pm.id > 0
            ORDER BY pm.sku ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 3. Categorías y marcas para filtros del catálogo
        $categorias = $pdo->query("
            SELECT id, nombre FROM inventario.categoria_producto WHERE id > 0 AND estado = 'ACTIVO' ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $marcas = $pdo->query("
            SELECT id, nombre FROM inventario.marca WHERE id > 0 AND estado = 'ACTIVO' ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 4. Supervisores autorizados con PIN
        $supervisores = obtenerSupervisoresAutorizados($pdo);

        // 5. Listado de Traslados Internos
        $traslados = $pdo->query("
            SELECT t.id,
                   t.numero_traslado,
                   t.estado,
                   t.fecha_emision,
                   ao.nombre AS origen_nombre,
                   ad.nombre AS destino_nombre,
                   COALESCE(NULLIF(TRIM(pe.nombre_razon_social), ''), ue.username) AS emisor_nombre,
                   COALESCE(NULLIF(TRIM(pr.nombre_razon_social), ''), ur.username) AS receptor_nombre,
                   COUNT(d.producto_maestro_id) AS total_lineas,
                   COALESCE(SUM(d.cantidad_enviada), 0) AS total_enviadas,
                   COALESCE(SUM(d.cantidad_recibida), 0) AS total_recibidas,
                   COALESCE(STRING_AGG(DISTINCT pm.sku || ' ' || COALESCE(pm.nombre, ''), ' | '), '') AS productos_busqueda
            FROM inventario.traslados_internos t
            JOIN inventario.almacenes ao ON ao.id = t.almacen_origen_id
            JOIN inventario.almacenes ad ON ad.id = t.almacen_destino_id
            LEFT JOIN inventario.traslado_detalles d ON d.traslado_id = t.id
            LEFT JOIN inventario.productos_maestro pm ON pm.id = d.producto_maestro_id
            LEFT JOIN seguridad.usuarios ue ON ue.usuario_id = t.usuario_emisor_id
            LEFT JOIN identidad.personas pe ON pe.persona_id = ue.persona_id
            LEFT JOIN seguridad.usuarios ur ON ur.usuario_id = t.usuario_receptor_id
            LEFT JOIN identidad.personas pr ON pr.persona_id = ur.persona_id
            GROUP BY t.id, t.numero_traslado, t.estado, t.fecha_emision,
                     ao.nombre, ad.nombre, pe.nombre_razon_social, ue.username,
                     pr.nombre_razon_social, ur.username
            ORDER BY CASE WHEN t.estado = 'EN_TRANSITO' THEN 0 ELSE 1 END,
                     t.fecha_emision DESC, t.id DESC
            LIMIT 250
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $kpiTotal = count($traslados);
        foreach ($traslados as $tr) {
            $est = strtoupper((string) $tr['estado']);
            if ($est === 'EN_TRANSITO') {
                $kpiEnTransito++;
            } elseif ($est === 'RECIBIDO') {
                $kpiRecibidos++;
            } elseif ($est === 'CANCELADO') {
                $kpiCancelados++;
            }
        }
    } catch (Throwable $e) {
        $errorCarga = $e->getMessage();
    }
}

$badgesEstado = [
    'EN_TRANSITO' => ['label' => 'EN TRÁNSITO', 'bg' => '#fff3e0', 'color' => '#e65100'],
    'RECIBIDO' => ['label' => 'RECIBIDO', 'bg' => '#e8f5e9', 'color' => '#2e7d32'],
    'CANCELADO' => ['label' => 'CANCELADO', 'bg' => '#ffebee', 'color' => '#c62828'],
];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Traslados Internos</title>
    <link rel="stylesheet" href="../styles.css?v=17" />
</head>

<body>

    <!-- BARRA SUPERIOR -->
    <header class="topbar">
        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" class="mobile-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Abrir Menú">
                <span></span>
                <span></span>
                <span></span>
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
                        <?= renderizarPillCajaTopbar() ?>
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
                    <h1 class="page-title">Traslados Internos entre Almacenes</h1>
                    <p class="page-subtitle">Reabastecimiento controlado en dos fases (En Tránsito → Recibido) con merma
                        automática ante roturas en pasillo</p>
                </div>

                <button type="button" class="btn btn-gold" onclick="abrirModalNuevoTraslado()">
                    + Nuevo Traslado
                </button>
            </div>

            <?php if (!empty($errorCarga)): ?>
                <div
                    style="background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 0.9rem;">
                    <strong>Aviso al consultar traslados:</strong> <?= htmlspecialchars($errorCarga) ?>
                </div>
            <?php endif; ?>

            <?php if (count($almacenes) < 2): ?>
                <div
                    style="background: #fff8e1; color: #8d6e00; border: 1px solid #ffe082; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 0.88rem;">
                    <strong>Nota:</strong> Se necesitan al menos <strong>2 almacenes activos</strong> en
                    <code>inventario.almacenes</code> para emitir traslados internos.
                </div>
            <?php endif; ?>

            <!-- RESUMEN KPI -->
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 18px;">
                <div style="background: #fff; border: 1px solid #e7dcc8; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.78rem; color: #7a6b5d; font-weight: 600;">TOTAL TRASLADOS</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #211813; margin-top: 4px;"><?= $kpiTotal ?>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #ffe0b2; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.78rem; color: #e65100; font-weight: 600;">EN TRÁNSITO (POR RECIBIR)</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #e65100; margin-top: 4px;">
                        <?= $kpiEnTransito ?></div>
                </div>
                <div style="background: #fff; border: 1px solid #c8e6c9; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.78rem; color: #2e7d32; font-weight: 600;">RECIBIDOS</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #2e7d32; margin-top: 4px;">
                        <?= $kpiRecibidos ?></div>
                </div>
                <div style="background: #fff; border: 1px solid #ffcdd2; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.78rem; color: #c62828; font-weight: 600;">CANCELADOS</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #c62828; margin-top: 4px;">
                        <?= $kpiCancelados ?></div>
                </div>
            </div>

            <!-- TABLA DE TRASLADOS -->
            <div class="table-card">
                <div class="table-card-top" style="flex-wrap: wrap; gap: 10px;">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Órdenes de Traslado Interno</h2>
                        <span class="badge-count" id="badgeCount"><?= count($traslados) ?> registros</span>
                    </div>

                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <select id="filtroEstado" class="form-control" style="width: 175px; height: 38px;"
                            onchange="filtrarTablaTraslados()">
                            <option value="">Todos los estados</option>
                            <option value="EN_TRANSITO">EN TRÁNSITO</option>
                            <option value="RECIBIDO">RECIBIDO</option>
                            <option value="CANCELADO">CANCELADO</option>
                        </select>

                        <div class="search-box" style="width: 285px;">
                            <span class="search-icon">🔍</span>
                            <input type="text" id="buscador"
                                placeholder="Buscar por N° traslado, almacén, SKU o usuario..."
                                oninput="filtrarTablaTraslados()" />
                        </div>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>N° Traslado</th>
                                <th>Fecha Emisión</th>
                                <th>Ruta (Origen → Destino)</th>
                                <th>Responsables</th>
                                <th class="text-center">Unidades</th>
                                <th class="text-center">Estado</th>
                                <th class="text-right" style="padding-right: 20px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tablaBody">
                            <?php if (empty($traslados)): ?>
                                <tr>
                                    <td colspan="7" class="text-center" style="padding: 32px; color: #888;">
                                        No hay traslados registrados todavía. Haz clic en <strong>+ Nuevo Traslado</strong>
                                        para mover mercancía entre almacenes.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($traslados as $tr):
                                    $estKey = strtoupper((string) $tr['estado']);
                                    $estilo = $badgesEstado[$estKey] ?? ['label' => $estKey, 'bg' => '#eadecd', 'color' => '#5a3818'];
                                    $enviadas = intval($tr['total_enviadas']);
                                    $recibidas = intval($tr['total_recibidas']);
                                    $faltantes = ($estKey === 'RECIBIDO') ? max(0, $enviadas - $recibidas) : 0;
                                    $textoFiltro = strtolower(
                                        ($tr['numero_traslado'] ?? '') . ' ' .
                                        ($tr['origen_nombre'] ?? '') . ' ' .
                                        ($tr['destino_nombre'] ?? '') . ' ' .
                                        ($tr['emisor_nombre'] ?? '') . ' ' .
                                        ($tr['receptor_nombre'] ?? '') . ' ' .
                                        ($tr['productos_busqueda'] ?? '')
                                    );
                                    ?>
                                    <tr class="fila-traslado" data-estado="<?= htmlspecialchars($estKey) ?>"
                                        data-texto="<?= htmlspecialchars($textoFiltro) ?>">
                                        <td style="white-space: nowrap;">
                                            <strong style="color: #a66e2e; font-family: monospace; font-size: 0.94rem;">
                                                <?= htmlspecialchars($tr['numero_traslado']) ?>
                                            </strong>
                                            <div style="font-size: 0.74rem; color: #8a7b70;"><?= intval($tr['total_lineas']) ?>
                                                línea(s)</div>
                                        </td>
                                        <td style="white-space: nowrap; font-size: 0.86rem; color: #444;">
                                            <?= !empty($tr['fecha_emision']) ? date('d/m/Y H:i', strtotime($tr['fecha_emision'])) : '—' ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: #211813; font-size: 0.88rem;">
                                                🏢 <?= htmlspecialchars($tr['origen_nombre']) ?>
                                                <span style="color: #a66e2e; margin: 0 4px;">→</span>
                                                <?= htmlspecialchars($tr['destino_nombre']) ?>
                                            </div>
                                        </td>
                                        <td style="font-size: 0.82rem; color: #4a3b32;">
                                            <div><strong>Envió:</strong> <?= htmlspecialchars($tr['emisor_nombre'] ?: '—') ?>
                                            </div>
                                            <div style="color: #7a6b5d;">
                                                <strong>Recibió:</strong>
                                                <?= htmlspecialchars($tr['receptor_nombre'] ?: ($estKey === 'EN_TRANSITO' ? 'Pendiente' : '—')) ?>
                                            </div>
                                        </td>
                                        <td class="text-center" style="white-space: nowrap;">
                                            <strong><?= number_format($enviadas) ?> env.</strong>
                                            <?php if ($estKey === 'RECIBIDO'): ?>
                                                <div
                                                    style="font-size: 0.76rem; color: <?= $faltantes > 0 ? '#c62828' : '#2e7d32' ?>; font-weight: 600;">
                                                    <?= number_format($recibidas) ?>
                                                    rec.<?= $faltantes > 0 ? " (-{$faltantes} merma)" : '' ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center" style="white-space: nowrap;">
                                            <span class="badge"
                                                style="background: <?= $estilo['bg'] ?>; color: <?= $estilo['color'] ?>; font-weight: 700;">
                                                <?= htmlspecialchars($estilo['label']) ?>
                                            </span>
                                        </td>
                                        <td class="text-right action-btns" style="padding-right: 20px; white-space: nowrap;">
                                            <button type="button" class="btn-sm btn-outline"
                                                onclick="verDetalleTraslado(<?= intval($tr['id']) ?>)">
                                                Ver Detalle
                                            </button>
                                            <?php if ($estKey === 'EN_TRANSITO'): ?>
                                                <button type="button" class="btn-sm btn-gold"
                                                    onclick="abrirModalRecibir(<?= intval($tr['id']) ?>)">
                                                    Recibir
                                                </button>
                                                <button type="button" class="btn-sm btn-danger"
                                                    onclick="abrirModalCancelar(<?= intval($tr['id']) ?>, '<?= htmlspecialchars($tr['numero_traslado'], ENT_QUOTES) ?>')">
                                                    Cancelar
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-card-footer">
                    <span id="footerInfo">Total: <?= count($traslados) ?> traslados mostrados</span>
                    <span>House &amp; Ice • Logística Multi-Almacén FEFO</span>
                </div>
            </div>

        </main>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 1: EMITIR NUEVO TRASLADO (PATRÓN 3 BLOQUES)                        -->
    <!-- ======================================================================== -->
    <div id="modalNuevoTraslado" class="modal-overlay"
        onclick="if(event.target.id==='modalNuevoTraslado') cerrarModalNuevoTraslado()">
        <div class="modal-box modal-xl">
            <div class="modal-header">
                <h2 class="modal-title">Emitir Nuevo Traslado Interno</h2>
            </div>

            <form id="formNuevoTraslado" onsubmit="emitirTraslado(event)">
                <div class="modal-body">

                    <!-- BLOQUE 1: RUTA DEL TRASLADO -->
                    <div class="promo-block-card">
                        <div class="promo-block-header">
                            <div class="promo-block-title">
                                <span class="promo-step-badge">1</span>
                                <span>Ruta Logística • Almacén Origen y Almacén Destino</span>
                            </div>
                            <span class="promo-type-hint">Al emitir, se descuenta el origen al instante (estado
                                EN_TRANSITO)</span>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="almacen_origen_id" class="form-label">Almacén Origen (Desde) *</label>
                                <select id="almacen_origen_id" class="form-control" required
                                    onchange="alCambiarAlmacenOrigen()">
                                    <option value="">-- Seleccionar Almacén Origen --</option>
                                    <?php foreach ($almacenes as $alm): ?>
                                        <option value="<?= intval($alm['id']) ?>"><?= htmlspecialchars($alm['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="almacen_destino_id" class="form-label">Almacén Destino (Hacia) *</label>
                                <select id="almacen_destino_id" class="form-control" required>
                                    <option value="">-- Seleccionar Almacén Destino --</option>
                                    <?php foreach ($almacenes as $alm): ?>
                                        <option value="<?= intval($alm['id']) ?>"><?= htmlspecialchars($alm['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- BLOQUE 2: CONSTRUCTOR DE LÍNEAS -->
                    <div class="promo-block-card dynamic-builder">
                        <div class="promo-block-header">
                            <div class="promo-block-title">
                                <span class="promo-step-badge">2</span>
                                <span>Seleccionar Mercancía a Trasladar</span>
                            </div>
                            <button type="button" class="btn-sm btn-outline" onclick="abrirModalCatalogo()"
                                style="font-weight: 700; color: #794a15; border-color: #d4a359; background: #fff8ee;">
                                🔍 Explorar Catálogo y Presentaciones
                            </button>
                        </div>

                        <div class="form-row-2" style="align-items: end; margin-bottom: 8px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="linea_barcode" class="form-label">Escáner Rápido (Código de Barras o
                                    SKU)</label>
                                <div style="display: flex; gap: 6px;">
                                    <input type="text" id="linea_barcode" class="form-control"
                                        placeholder="Escanea el empaque y presiona Enter..."
                                        onkeydown="if(event.key === 'Enter'){ event.preventDefault(); buscarPorCodigoBarra(); }" />
                                    <button type="button" class="btn btn-secondary" onclick="buscarPorCodigoBarra()"
                                        style="white-space: nowrap;">
                                        Buscar
                                    </button>
                                </div>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="linea_producto_id" class="form-label">Producto Maestro *</label>
                                <select id="linea_producto_id" class="form-control"
                                    onchange="cargarDatosProductoEnOrigen()">
                                    <option value="">-- Seleccionar Producto --</option>
                                    <?php foreach ($productos as $pr): ?>
                                        <option value="<?= intval($pr['id']) ?>">
                                            <?= htmlspecialchars($pr['sku'] . ' - ' . $pr['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row-3" style="margin-top: 8px;">
                            <div class="form-group">
                                <label for="linea_presentacion" class="form-label">Presentación / Empaque</label>
                                <select id="linea_presentacion" class="form-control"
                                    onchange="actualizarPreviewUnidades()">
                                    <option value="" data-factor="1">Unidad Base (x1)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="linea_cantidad" class="form-label">Cantidad (Empaques / Botellas) *</label>
                                <input type="number" id="linea_cantidad" class="form-control pos-qty-input" min="1"
                                    step="1" value="1" oninput="actualizarPreviewUnidades()" />
                            </div>
                            <div class="form-group">
                                <label class="form-label">Equivalencia en Unidades Base</label>
                                <div id="previewUnidadesBase"
                                    style="height: 38px; display: flex; align-items: center; padding: 0 12px; background: #f8f4ec; border: 1px solid #e5d8c3; border-radius: 6px; font-weight: 700; color: #5a3818;">
                                    1 unidad base
                                </div>
                            </div>
                        </div>

                        <div
                            style="margin-top: 8px; padding: 12px; background: #faf7f2; border: 1px solid #e7dcc8; border-radius: 8px;">
                            <div style="font-size: 0.84rem; font-weight: 700; color: #4a3b32; margin-bottom: 6px;"
                                id="lblEstadoLote">
                                📦 Lote Disponible en Almacén Origen (Orden FEFO)
                            </div>
                            <select id="linea_lote_id" class="form-control">
                                <option value="">-- Primero selecciona Almacén Origen y Producto --</option>
                            </select>
                            <small id="hintLoteStock"
                                style="display: block; color: #7a6b5d; font-size: 0.78rem; margin-top: 4px;"></small>
                        </div>

                        <div style="display: flex; justify-content: flex-end; margin-top: 10px;">
                            <button type="button" class="btn btn-gold" onclick="agregarLineaAlCarrito()">
                                + Agregar Línea al Traslado
                            </button>
                        </div>
                    </div>

                    <!-- BLOQUE 3: CARRITO DE TRASLADO -->
                    <div class="promo-block-card rules-cart">
                        <div class="promo-block-header">
                            <div class="promo-block-title">
                                <span class="promo-step-badge">3</span>
                                <span>Mercancía a Despachar (Carrito)</span>
                            </div>
                            <span class="badge-count" id="contadorCarrito">0 líneas</span>
                        </div>

                        <div class="carrito-reglas-wrapper">
                            <table class="carrito-reglas-table">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">#</th>
                                        <th>Producto</th>
                                        <th>Lote / Vencimiento</th>
                                        <th style="width: 145px;">Unidades a Enviar</th>
                                        <th style="width: 90px; text-align: right;">Acción</th>
                                    </tr>
                                </thead>
                                <tbody id="tablaCarritoBody">
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 20px; color: #888;">
                                            El carrito está vacío. Configura un producto en el paso 2 y presiona
                                            <strong>+ Agregar Línea al Traslado</strong>.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                        onclick="cerrarModalNuevoTraslado()">Cancelar</button>
                    <button type="submit" class="btn btn-gold" id="btnEmitirTraslado">Emitir Traslado (En
                        Tránsito)</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 2: BUSCADOR FILTRADO DE PRODUCTOS Y PRESENTACIONES                 -->
    <!-- ======================================================================== -->
    <div id="modalBuscarCatalogo" class="modal-overlay" style="z-index: 1200;"
        onclick="if(event.target.id==='modalBuscarCatalogo') cerrarModalCatalogo()">
        <div class="modal-box modal-xl">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2 class="modal-title" style="margin: 0;">Buscar Producto y Presentación</h2>
                    <small id="subtituloCatalogoAlmacen" style="color: #7a6b5d; font-size: 0.8rem;"></small>
                </div>
                <button type="button" class="btn-sm btn-secondary" onclick="cerrarModalCatalogo()">✕</button>
            </div>

            <div class="modal-body">
                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="catFiltroTexto" class="form-label">Buscar por Nombre, SKU, Código de Barras o
                            Empaque</label>
                        <input type="text" id="catFiltroTexto" class="form-control"
                            placeholder="Ej. Brugal, 74600..., Six-Pack, Caja..."
                            oninput="programarBusquedaCatalogo()" />
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="catFiltroCategoria" class="form-label">Categoría</label>
                        <select id="catFiltroCategoria" class="form-control" onchange="buscarEnCatalogo()">
                            <option value="0">Todas las categorías</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= intval($cat['id']) ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="catFiltroMarca" class="form-label">Marca</label>
                        <select id="catFiltroMarca" class="form-control" onchange="buscarEnCatalogo()">
                            <option value="0">Todas las marcas</option>
                            <?php foreach ($marcas as $mar): ?>
                                <option value="<?= intval($mar['id']) ?>"><?= htmlspecialchars($mar['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div
                    style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                    <label
                        style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.84rem; color: #4a3b32; cursor: pointer;">
                        <input type="checkbox" id="catFiltroSoloStock" checked onchange="buscarEnCatalogo()"
                            style="width: 15px; height: 15px; accent-color: #a66e2e;" />
                        <span>Mostrar solo productos con stock disponible en el almacén origen</span>
                    </label>
                    <span class="badge-count" id="catContadorResultados">0 productos</span>
                </div>

                <div class="carrito-reglas-wrapper" style="max-height: 390px; overflow-y: auto;">
                    <table class="carrito-reglas-table">
                        <thead>
                            <tr>
                                <th style="width: 26%;">Producto / SKU</th>
                                <th style="width: 18%;">Categoría / Marca</th>
                                <th style="width: 14%; text-align: center;">Stock Disp.</th>
                                <th>Presentaciones (clic para seleccionar)</th>
                            </tr>
                        </thead>
                        <tbody id="catTablaResultados">
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 24px; color: #888;">Buscando
                                    productos...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalCatalogo()">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 3: CONFIRMAR RECEPCIÓN EN DESTINO (CON DETECCIÓN DE MERMAS)        -->
    <!-- ======================================================================== -->
    <div id="modalRecibirTraslado" class="modal-overlay"
        onclick="if(event.target.id==='modalRecibirTraslado') cerrarModalRecibir()">
        <div class="modal-box modal-lg">
            <div class="modal-header">
                <h2 class="modal-title" id="recibirTitulo">Confirmar Recepción de Traslado</h2>
            </div>

            <form id="formRecibirTraslado" onsubmit="confirmarRecepcion(event)">
                <input type="hidden" id="recibirTrasladoId" value="" />
                <div class="modal-body">
                    <div id="recibirResumen"
                        style="background: #faf7f2; border: 1px solid #e7dcc8; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 0.88rem;">
                        Cargando datos del traslado...
                    </div>

                    <div class="carrito-reglas-wrapper">
                        <table class="carrito-reglas-table">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Lote / Vencimiento</th>
                                    <th style="text-align: center; width: 100px;">Enviadas</th>
                                    <th style="text-align: center; width: 130px;">Recibidas *</th>
                                    <th style="text-align: center; width: 105px;">Faltante</th>
                                </tr>
                            </thead>
                            <tbody id="recibirTablaBody"></tbody>
                        </table>
                    </div>

                    <!-- BLOQUE CONDICIONAL DE MERMA AUTOMÁTICA (SOLO SI HAY FALTANTES) -->
                    <div id="bloqueMermaRecepcion"
                        style="display: none; margin-top: 14px; padding: 14px; background: #fff5f5; border: 1px dashed #d32f2f; border-radius: 8px;">
                        <div style="font-size: 0.86rem; font-weight: 700; color: #b71c1c; margin-bottom: 8px;">
                            ⚠️ Diferencia detectada: las unidades faltantes se registrarán automáticamente como
                            MERMA_ROTURA en el Kardex y se descontarán del lote.
                        </div>

                        <div class="form-group">
                            <label for="recibirJustificacion" class="form-label">Justificación del Faltante / Rotura
                                *</label>
                            <input type="text" id="recibirJustificacion" class="form-control" maxlength="255"
                                placeholder="Ej. Se cayó 1 botella al bajar la caja hacia el mostrador" />
                        </div>

                        <div class="form-row-2">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="recibirSupervisorId" class="form-label">Supervisor que Autoriza la Merma
                                    *</label>
                                <select id="recibirSupervisorId" class="form-control">
                                    <option value="">-- Seleccionar Supervisor --</option>
                                    <?php foreach ($supervisores as $sup): ?>
                                        <option value="<?= htmlspecialchars($sup['usuario_id']) ?>"
                                            <?= ($sup['usuario_id'] === $sesionUsuarioId) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sup['nombre_completo'] . ' (' . $sup['username'] . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="recibirPin" class="form-label">PIN de Supervisor (4 a 6 dígitos) *</label>
                                <input type="password" id="recibirPin" class="form-control" inputmode="numeric"
                                    maxlength="6" autocomplete="new-password" placeholder="••••••"
                                    style="font-family: monospace; letter-spacing: 3px; font-weight: 700;"
                                    oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalRecibir()">Cancelar</button>
                    <button type="submit" class="btn btn-gold" id="btnConfirmarRecepcion">Confirmar Recepción en
                        Destino</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 4: CANCELAR TRASLADO EN TRÁNSITO (DEVUELVE STOCK A ORIGEN + PIN)   -->
    <!-- ======================================================================== -->
    <div id="modalCancelarTraslado" class="modal-overlay"
        onclick="if(event.target.id==='modalCancelarTraslado') cerrarModalCancelar()">
        <div class="modal-box">
            <div class="modal-header">
                <h2 class="modal-title">Cancelar Traslado en Tránsito</h2>
            </div>

            <form id="formCancelarTraslado" onsubmit="confirmarCancelacion(event)">
                <input type="hidden" id="cancelarTrasladoId" value="" />
                <div class="modal-body">
                    <div
                        style="background: #fff8ee; border: 1px solid #e7dcc8; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; font-size: 0.88rem; color: #5a3818;">
                        Al cancelar <strong id="cancelarNumeroTexto"></strong>, todas las unidades enviadas regresarán
                        íntegras al almacén de origen.
                    </div>

                    <div class="form-group">
                        <label for="cancelarJustificacion" class="form-label">Motivo de Cancelación *</label>
                        <input type="text" id="cancelarJustificacion" class="form-control" required minlength="5"
                            maxlength="255" placeholder="Ej. Error en selección de destino o solicitud anulada" />
                    </div>

                    <div class="form-group">
                        <label for="cancelarSupervisorId" class="form-label">Supervisor que Autoriza *</label>
                        <select id="cancelarSupervisorId" class="form-control" required>
                            <option value="">-- Seleccionar Supervisor --</option>
                            <?php foreach ($supervisores as $sup): ?>
                                <option value="<?= htmlspecialchars($sup['usuario_id']) ?>"
                                    <?= ($sup['usuario_id'] === $sesionUsuarioId) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sup['nombre_completo'] . ' (' . $sup['username'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="cancelarPin" class="form-label">PIN de Supervisor (4 a 6 dígitos) *</label>
                        <input type="password" id="cancelarPin" class="form-control" required inputmode="numeric"
                            maxlength="6" autocomplete="new-password" placeholder="••••••"
                            style="font-family: monospace; letter-spacing: 3px; font-weight: 700;"
                            oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);" />
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalCancelar()">Volver</button>
                    <button type="submit" class="btn btn-danger" id="btnConfirmarCancelacion">Confirmar
                        Cancelación</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 5: VER DETALLE DEL TRASLADO                                        -->
    <!-- ======================================================================== -->
    <div id="modalDetalleTraslado" class="modal-overlay"
        onclick="if(event.target.id==='modalDetalleTraslado') cerrarModalDetalle()">
        <div class="modal-box modal-lg">
            <div class="modal-header">
                <h2 class="modal-title" id="detalleTitulo">Detalle de Traslado Interno</h2>
            </div>
            <div class="modal-body" id="detalleContenido">
                <p style="color: #888;">Cargando información...</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalDetalle()">Cerrar</button>
            </div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script src="traslados.js?v=1"></script>
</body>

</html>