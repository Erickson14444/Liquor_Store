<?php
/**
 * ============================================================================
 * Módulo 3: Inventario & Stock • Movimientos de Inventario (Kardex y Ajustes)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: inventario.movimientos_inventario, inventario.movimiento_inventario_detalles,
 *         inventario.inventario_stock, inventario.lotes, inventario.almacenes
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

requerirSesionActiva(false, '../../Login y Menu/login.php');

$movimientos = [];
$almacenes = [];
$productos = [];
$categorias = [];
$marcas = [];
$supervisores = [];
$kpiTotal = 0;
$kpiEntradas = 0;
$kpiSalidas = 0;
$kpiMermas = 0;

if ($pdo) {
    try {
        // 1. Catálogo de Almacenes activos (compatible con columna booleana activo o texto estado)
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

        // 2. Catálogo de Productos Maestros (excluyendo comodín id = 0)
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

        // 2B. Catálogos para filtros del buscador de productos
        $categorias = $pdo->query("
            SELECT id, nombre
            FROM inventario.categoria_producto
            WHERE id > 0 AND estado = 'ACTIVO'
            ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $marcas = $pdo->query("
            SELECT id, nombre
            FROM inventario.marca
            WHERE id > 0 AND estado = 'ACTIVO'
            ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 3. Supervisores autorizados con permiso de PIN
        $supervisores = obtenerSupervisoresAutorizados($pdo);

        // 4. Historial reciente del Kardex (últimos 250 movimientos)
        $colsMov = obtenerColumnasTabla($pdo, 'inventario', 'movimientos_inventario');
        $tieneAlmacen = isset($colsMov['almacen_id']);
        $selectAlmacen = $tieneAlmacen ? "a.nombre AS almacen_nombre," : "NULL::text AS almacen_nombre,";
        $joinAlmacen = $tieneAlmacen ? "LEFT JOIN inventario.almacenes a ON a.id = m.almacen_id" : "";

        $stmtMov = $pdo->query("
            SELECT m.id,
                   m.tipo_movimiento,
                   m.justificacion,
                   m.documento_referencia_id,
                   m.fecha_movimiento,
                   m.supervisor_id,
                   {$selectAlmacen}
                   COALESCE(NULLIF(TRIM(ps.nombre_razon_social), ''), us.username) AS supervisor_nombre,
                   COUNT(d.producto_maestro_id) AS total_lineas,
                   COALESCE(SUM(d.cantidad_unidades), 0) AS total_unidades,
                   COALESCE(SUM(d.cantidad_unidades * d.costo_unitario_referencia), 0) AS total_costo,
                   COALESCE(STRING_AGG(DISTINCT pm.sku || ' ' || COALESCE(pm.nombre, ''), ' | '), '') AS productos_busqueda
            FROM inventario.movimientos_inventario m
            {$joinAlmacen}
            LEFT JOIN inventario.movimiento_inventario_detalles d ON d.movimiento_id = m.id
            LEFT JOIN inventario.productos_maestro pm ON pm.id = d.producto_maestro_id
            LEFT JOIN seguridad.usuarios us ON us.usuario_id = m.supervisor_id
            LEFT JOIN identidad.personas ps ON ps.persona_id = us.persona_id
            GROUP BY m.id, m.tipo_movimiento, m.justificacion, m.documento_referencia_id,
                     m.fecha_movimiento, m.supervisor_id, ps.nombre_razon_social, us.username"
            . ($tieneAlmacen ? ", a.nombre" : "") . "
            ORDER BY m.fecha_movimiento DESC, m.id DESC
            LIMIT 250
        ");
        $movimientos = $stmtMov->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $kpiTotal = count($movimientos);
        foreach ($movimientos as $mv) {
            $t = strtoupper((string) $mv['tipo_movimiento']);
            if (in_array($t, ['ENTRADA_COMPRA', 'AJUSTE_ENTRADA', 'DEVOLUCION_CLIENTE'], true)) {
                $kpiEntradas++;
            } elseif ($t === 'MERMA_ROTURA') {
                $kpiMermas++;
            } else {
                $kpiSalidas++;
            }
        }
    } catch (Throwable $e) {
        $errorCarga = $e->getMessage();
    }
}

$badgesTipo = [
    'ENTRADA_COMPRA' => ['label' => 'ENTRADA COMPRA', 'bg' => '#e8f5e9', 'color' => '#2e7d32'],
    'AJUSTE_ENTRADA' => ['label' => 'AJUSTE ENTRADA', 'bg' => '#e3f2fd', 'color' => '#1565c0'],
    'DEVOLUCION_CLIENTE' => ['label' => 'DEVOLUCIÓN B04', 'bg' => '#ede7f6', 'color' => '#5e35b1'],
    'SALIDA_VENTA' => ['label' => 'SALIDA VENTA', 'bg' => '#fff3e0', 'color' => '#e65100'],
    'AJUSTE_SALIDA' => ['label' => 'AJUSTE SALIDA', 'bg' => '#fce4ec', 'color' => '#ad1457'],
    'MERMA_ROTURA' => ['label' => 'MERMA / ROTURA', 'bg' => '#ffebee', 'color' => '#c62828'],
];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Movimientos de Inventario</title>
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
                <div class="user-avatar">
                    <?= htmlspecialchars($sesionAvatarInitials) ?>
                </div>
                <div class="user-info-col">
                    <div class="user-name">
                        <?= htmlspecialchars($sesionNombreCompleto) ?>
                    </div>
                    <div class="user-sub-line">
                        <span class="user-role-text">
                            <?= htmlspecialchars($sesionRol) ?>
                        </span>
                        <span class="user-bullet">•</span>
                        <span class="user-caja-pill open">
                            <span class="pill-dot green"></span>
                            Caja 0<?= htmlspecialchars((string) $sesionCajaId) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="app-container">
        <!-- MENÚ LATERAL UNIFICADO -->
        <?php include __DIR__ . '/../../sidebar.php'; ?>

        <!-- CONTENIDO PRINCIPAL -->
        <main class="main-content">

            <div class="page-header">
                <div>
                    <h1 class="page-title">Movimientos de Inventario (Kardex y Ajustes)</h1>
                    <p class="page-subtitle">Control transaccional de entradas, salidas, mermas por rotura y creación de
                        lotes FEFO con trazabilidad de auditoría</p>
                </div>

                <button type="button" class="btn btn-gold" onclick="abrirModalMovimiento()">
                    + Nuevo Ajuste / Merma
                </button>
            </div>

            <?php if (!empty($errorCarga)): ?>
                <div
                    style="background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 0.9rem;">
                    <strong>Aviso al consultar Kardex:</strong>
                    <?= htmlspecialchars($errorCarga) ?>
                </div>
            <?php endif; ?>

            <!-- RESUMEN RÁPIDO -->
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 18px;">
                <div style="background: #fff; border: 1px solid #e7dcc8; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.78rem; color: #7a6b5d; font-weight: 600;">ASIENTOS EN KARDEX</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #211813; margin-top: 4px;">
                        <?= $kpiTotal ?>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #c8e6c9; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.78rem; color: #2e7d32; font-weight: 600;">ENTRADAS / REINGRESOS</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #2e7d32; margin-top: 4px;">
                        <?= $kpiEntradas ?>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #ffe0b2; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.78rem; color: #e65100; font-weight: 600;">SALIDAS (VENTA / AJUSTE)</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #e65100; margin-top: 4px;">
                        <?= $kpiSalidas ?>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #ffcdd2; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.78rem; color: #c62828; font-weight: 600;">MERMAS / ROTURAS</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #c62828; margin-top: 4px;">
                        <?= $kpiMermas ?>
                    </div>
                </div>
            </div>

            <!-- TABLA DE KARDEX -->
            <div class="table-card">
                <div class="table-card-top" style="flex-wrap: wrap; gap: 10px;">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Bitácora de Movimientos de Inventario</h2>
                        <span class="badge-count" id="badgeCount">
                            <?= count($movimientos) ?> registros
                        </span>
                    </div>

                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <select id="filtroTipo" class="form-control" style="width: 195px; height: 38px;"
                            onchange="filtrarTablaMovimientos()">
                            <option value="">Todos los tipos</option>
                            <option value="AJUSTE_ENTRADA">AJUSTE_ENTRADA</option>
                            <option value="AJUSTE_SALIDA">AJUSTE_SALIDA</option>
                            <option value="MERMA_ROTURA">MERMA_ROTURA</option>
                            <option value="ENTRADA_COMPRA">ENTRADA_COMPRA</option>
                            <option value="SALIDA_VENTA">SALIDA_VENTA</option>
                            <option value="DEVOLUCION_CLIENTE">DEVOLUCION_CLIENTE</option>
                        </select>

                        <div class="search-box" style="width: 280px;">
                            <span class="search-icon">🔍</span>
                            <input type="text" id="buscador"
                                placeholder="Buscar por SKU, producto, motivo o supervisor..."
                                oninput="filtrarTablaMovimientos()" />
                        </div>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Fecha y Hora</th>
                                <th>Tipo Movimiento</th>
                                <th>Justificación / Origen</th>
                                <th>Supervisor</th>
                                <th class="text-center">Unidades</th>
                                <th class="text-right">Valorizado (RD$)</th>
                                <th class="text-right" style="padding-right: 20px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tablaBody">
                            <?php if (empty($movimientos)): ?>
                                <tr>
                                    <td colspan="7" class="text-center" style="padding: 32px; color: #888;">
                                        No hay movimientos registrados todavía. Haz clic en <strong>+ Nuevo Ajuste /
                                            Merma</strong> para cargar inventario inicial o registrar ajustes.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($movimientos as $mv):
                                    $tipoKey = strtoupper((string) $mv['tipo_movimiento']);
                                    $estilo = $badgesTipo[$tipoKey] ?? ['label' => $tipoKey, 'bg' => '#eadecd', 'color' => '#5a3818'];
                                    $esPos = in_array($tipoKey, ['ENTRADA_COMPRA', 'AJUSTE_ENTRADA', 'DEVOLUCION_CLIENTE'], true);
                                    $signo = $esPos ? '+' : '-';
                                    $textoFiltro = strtolower(
                                        ($mv['justificacion'] ?? '') . ' ' .
                                        ($mv['supervisor_nombre'] ?? '') . ' ' . ($mv['almacen_nombre'] ?? '') . ' ' .
                                        ($mv['productos_busqueda'] ?? '')
                                    );
                                    ?>
                                    <tr class="fila-movimiento" data-tipo="<?= htmlspecialchars($tipoKey) ?>"
                                        data-texto="<?= htmlspecialchars($textoFiltro) ?>">
                                        <td style="white-space: nowrap; font-size: 0.86rem; color: #444;">
                                            <?= !empty($mv['fecha_movimiento']) ? date('d/m/Y H:i', strtotime($mv['fecha_movimiento'])) : '—' ?>
                                            <?php if (!empty($mv['almacen_nombre'])): ?>
                                                <div style="font-size: 0.76rem; color: #8a7b70;">🏢
                                                    <?= htmlspecialchars($mv['almacen_nombre']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <span class="badge"
                                                style="background: <?= $estilo['bg'] ?>; color: <?= $estilo['color'] ?>; font-weight: 700;">
                                                <?= htmlspecialchars($estilo['label']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: #211813; font-size: 0.9rem;">
                                                <?= htmlspecialchars($mv['justificacion'] ?: 'Sin justificación') ?>
                                            </div>
                                            <div style="font-size: 0.76rem; color: #8a7b70;">
                                                <?= intval($mv['total_lineas']) ?> línea(s)
                                                <?php if (!empty($mv['documento_referencia_id'])): ?>
                                                    • Doc. Ref:
                                                    <strong>#<?= htmlspecialchars((string) $mv['documento_referencia_id']) ?></strong>
                                                <?php else: ?>
                                                    • Ajuste Manual
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td style="font-size: 0.86rem; color: #4a3b32;">
                                            <?php if (!empty($mv['supervisor_nombre'])): ?>
                                                <span style="font-weight: 600;">🔐
                                                    <?= htmlspecialchars($mv['supervisor_nombre']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #999;">No requerido</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center" style="white-space: nowrap;">
                                            <strong style="color: <?= $esPos ? '#2e7d32' : '#c62828' ?>; font-size: 0.95rem;">
                                                <?= $signo . number_format(intval($mv['total_unidades'])) ?> uds.
                                            </strong>
                                        </td>
                                        <td class="text-right"
                                            style="white-space: nowrap; font-family: monospace; font-weight: 600;">
                                            RD$ <?= number_format(floatval($mv['total_costo']), 2) ?>
                                        </td>
                                        <td class="text-right action-btns" style="padding-right: 20px;">
                                            <button type="button" class="btn-sm btn-outline"
                                                onclick="verDetalleMovimiento(<?= intval($mv['id']) ?>)">
                                                Ver Detalle
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-card-footer">
                    <span id="footerInfo">Total: <?= count($movimientos) ?> movimientos mostrados</span>
                    <span>House &amp; Ice • Kardex Valorado FEFO</span>
                </div>
            </div>

        </main>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL NUEVO AJUSTE / MERMA (PATRÓN 3 BLOQUES: CABECERA, LÍNEA, CARRITO)  -->
    <!-- ======================================================================== -->
    <div id="modalMovimiento" class="modal-overlay" onclick="cerrarSiFondoMov(event)">
        <div class="modal-box modal-xl">
            <div class="modal-header">
                <h2 class="modal-title">Registrar Movimiento Manual de Inventario</h2>
            </div>

            <form id="formMovimiento" onsubmit="guardarMovimiento(event)">
                <div class="modal-body">

                    <!-- BLOQUE 1: CABECERA DEL MOVIMIENTO -->
                    <div class="promo-block-card">
                        <div class="promo-block-header">
                            <div class="promo-block-title">
                                <span class="promo-step-badge">1</span>
                                <span>Cabecera del Movimiento • Tipo, Almacén y Autorización</span>
                            </div>
                            <span class="promo-type-hint" id="hintTipoMov">Suma existencias al almacén (permite crear
                                lotes nuevos)</span>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="tipo_movimiento" class="form-label">Tipo de Movimiento *</label>
                                <select id="tipo_movimiento" class="form-control" required
                                    onchange="alCambiarTipoMovimiento()">
                                    <option value="AJUSTE_ENTRADA">AJUSTE_ENTRADA • Sobrante de Conteo / Inventario
                                        Inicial</option>
                                    <option value="AJUSTE_SALIDA">AJUSTE_SALIDA • Consumo Interno / Degustación /
                                        Faltante</option>
                                    <option value="MERMA_ROTURA">MERMA_ROTURA • Botella Rota / Daño / Mercancía Vencida
                                    </option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="almacen_id" class="form-label">Almacén Físico Afectado *</label>
                                <select id="almacen_id" class="form-control" required onchange="alCambiarAlmacen()">
                                    <option value="">-- Seleccionar Almacén --</option>
                                    <?php foreach ($almacenes as $alm): ?>
                                        <option value="<?= intval($alm['id']) ?>">
                                            <?= htmlspecialchars($alm['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group" style="margin-top: 4px;">
                            <label for="justificacion" class="form-label">Justificación / Motivo Obligatorio *</label>
                            <input type="text" id="justificacion" class="form-control" required minlength="5"
                                maxlength="255"
                                placeholder="Ej. Carga de inventario inicial / Rotura en bodega principal / Degustación cliente VIP" />
                        </div>

                        <!-- SUB-BLOQUE AUTORIZACIÓN SUPERVISOR + PIN (OBLIGATORIO EN SALIDAS Y MERMAS) -->
                        <div id="bloquePinSupervisor"
                            style="display: none; margin-top: 10px; padding: 12px 14px; background: #fff8ee; border: 1px dashed #d4a359; border-radius: 8px;">
                            <div style="font-size: 0.82rem; font-weight: 700; color: #794a15; margin-bottom: 8px;">
                                🔐 Autorización Obligatoria de Supervisor (Salidas y Mermas)
                            </div>
                            <div class="form-row-2">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="supervisor_id" class="form-label">Supervisor que Autoriza *</label>
                                    <select id="supervisor_id" class="form-control">
                                        <option value="">-- Seleccionar Supervisor --</option>
                                        <?php foreach ($supervisores as $sup): ?>
                                            <option value="<?= htmlspecialchars($sup['usuario_id']) ?>"
                                                <?= ($sup['usuario_id'] === $sesionUsuarioId) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($sup['nombre_completo'] . ' (' . $sup['username'] . ' • ' . $sup['roles_nombres'] . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="pin_supervisor" class="form-label">PIN de Seguridad (4 a 6 dígitos)
                                        *</label>
                                    <input type="password" id="pin_supervisor" class="form-control" inputmode="numeric"
                                        maxlength="6" autocomplete="new-password" placeholder="••••••"
                                        style="font-family: monospace; letter-spacing: 3px; font-weight: 700;"
                                        oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);" />
                                </div>
                            </div>
                            <?php if (empty($supervisores)): ?>
                                <div style="color: #c62828; font-size: 0.8rem; margin-top: 6px; font-weight: 600;">
                                    ⚠️ No hay usuarios activos con PIN y permiso AUTORIZAR_CON_PIN configurados en el módulo
                                    de Usuarios.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- BLOQUE 2: CONSTRUCTOR DE LÍNEAS (ESCÁNER + PRODUCTO + LOTE FEFO) -->
                    <div class="promo-block-card dynamic-builder">
                        <div class="promo-block-header">
                            <div class="promo-block-title">
                                <span class="promo-step-badge">2</span>
                                <span>Agregar Productos al Movimiento</span>
                            </div>
                            <button type="button" class="btn-sm btn-outline" onclick="abrirModalCatalogo()"
                                style="font-weight: 700; color: #794a15; border-color: #d4a359; background: #fff8ee;">
                                🔍 Explorar Catálogo y Presentaciones
                            </button>
                        </div>

                        <!-- ESCÁNER RÁPIDO -->
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
                                    onchange="cargarDatosProductoEnAlmacen()">
                                    <option value="">-- Seleccionar Producto --</option>
                                    <?php foreach ($productos as $pr): ?>
                                        <option value="<?= intval($pr['id']) ?>"
                                            data-sku="<?= htmlspecialchars($pr['sku']) ?>"
                                            data-nombre="<?= htmlspecialchars($pr['nombre']) ?>"
                                            data-costo="<?= number_format(floatval($pr['costo_promedio']), 2, '.', '') ?>"
                                            data-requiere-lote="<?= ($pr['requiere_lote'] === true || $pr['requiere_lote'] === 't' || $pr['requiere_lote'] == 1) ? '1' : '0' ?>">
                                            <?= htmlspecialchars($pr['sku'] . ' - ' . $pr['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- PRESENTACIÓN, CANTIDAD Y EQUIVALENCIA EN UNIDADES BASE -->
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

                        <!-- CONTROL DE LOTE (EXISTENTE FEFO / CREAR NUEVO AL VUELO / COMODÍN 0) -->
                        <div id="bloqueLoteControl"
                            style="margin-top: 8px; padding: 12px; background: #faf7f2; border: 1px solid #e7dcc8; border-radius: 8px;">
                            <div
                                style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
                                <span style="font-size: 0.84rem; font-weight: 700; color: #4a3b32;" id="lblEstadoLote">
                                    📦 Control de Lote (FEFO)
                                </span>
                                <div id="wrapSwitchModoLote" style="display: none; gap: 14px; font-size: 0.82rem;">
                                    <label
                                        style="display: inline-flex; align-items: center; gap: 5px; cursor: pointer;">
                                        <input type="radio" name="modo_lote_radio" value="existente" checked
                                            onchange="alternarModoLote('existente')" />
                                        <span>Usar lote existente</span>
                                    </label>
                                    <label
                                        style="display: inline-flex; align-items: center; gap: 5px; cursor: pointer; color: #a66e2e; font-weight: 700;">
                                        <input type="radio" name="modo_lote_radio" value="nuevo"
                                            onchange="alternarModoLote('nuevo')" />
                                        <span>+ Crear lote nuevo al vuelo</span>
                                    </label>
                                </div>
                            </div>

                            <!-- MODO LOTE EXISTENTE -->
                            <div id="panelLoteExistente">
                                <select id="linea_lote_id" class="form-control">
                                    <option value="">-- Primero selecciona Almacén y Producto --</option>
                                </select>
                                <small id="hintLoteStock"
                                    style="display: block; color: #7a6b5d; font-size: 0.78rem; margin-top: 4px;"></small>
                            </div>

                            <!-- MODO CREAR LOTE AL VUELO (SOLO AJUSTE_ENTRADA + requiere_lote = TRUE) -->
                            <div id="panelLoteNuevo" style="display: none;">
                                <div class="form-row-2">
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <label for="nuevo_numero_lote" class="form-label">Número de Lote Nuevo *</label>
                                        <input type="text" id="nuevo_numero_lote" class="form-control" maxlength="50"
                                            placeholder="Ej. LOT-2026-11A"
                                            oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9._-]/g, '');" />
                                    </div>
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <label for="nueva_fecha_vencimiento" class="form-label">Fecha de Vencimiento
                                            *</label>
                                        <input type="date" id="nueva_fecha_vencimiento" class="form-control" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: flex-end; margin-top: 10px;">
                            <button type="button" class="btn btn-gold" onclick="agregarLineaAlCarrito()">
                                + Agregar Línea al Carrito
                            </button>
                        </div>
                    </div>

                    <!-- BLOQUE 3: CARRITO DE LÍNEAS -->
                    <div class="promo-block-card rules-cart">
                        <div class="promo-block-header">
                            <div class="promo-block-title">
                                <span class="promo-step-badge">3</span>
                                <span>Detalle del Movimiento (Carrito de Líneas)</span>
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
                                        <th style="width: 140px;">Unidades Base</th>
                                        <th style="width: 145px; text-align: right;">Costo Ref.</th>
                                        <th style="width: 90px; text-align: right;">Acción</th>
                                    </tr>
                                </thead>
                                <tbody id="tablaCarritoBody">
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 20px; color: #888;">
                                            El carrito está vacío. Configura un producto en el paso 2 y presiona
                                            <strong>+ Agregar Línea al Carrito</strong>.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalMovimiento()">Cancelar</button>
                    <button type="submit" class="btn btn-gold" id="btnGuardarMovimiento">Confirmar y Registrar en
                        Kardex</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL VER DETALLE DEL MOVIMIENTO                                         -->
    <!-- ======================================================================== -->
    <div id="modalDetalle" class="modal-overlay" onclick="if(event.target.id==='modalDetalle') cerrarModalDetalle()">
        <div class="modal-box modal-lg">
            <div class="modal-header">
                <h2 class="modal-title" id="detalleTitulo">Detalle del Movimiento de Inventario</h2>
            </div>
            <div class="modal-body" id="detalleContenido">
                <p style="color: #888;">Cargando información...</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalDetalle()">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL BUSCADOR FILTRADO DE PRODUCTOS Y PRESENTACIONES                    -->
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
                <!-- BARRA DE FILTROS -->
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
                                <option value="<?= intval($cat['id']) ?>">
                                    <?= htmlspecialchars($cat['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="catFiltroMarca" class="form-label">Marca</label>
                        <select id="catFiltroMarca" class="form-control" onchange="buscarEnCatalogo()">
                            <option value="0">Todas las marcas</option>
                            <?php foreach ($marcas as $mar): ?>
                                <option value="<?= intval($mar['id']) ?>">
                                    <?= htmlspecialchars($mar['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div
                    style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                    <label
                        style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.84rem; color: #4a3b32; cursor: pointer;">
                        <input type="checkbox" id="catFiltroSoloStock" onchange="buscarEnCatalogo()"
                            style="width: 15px; height: 15px; accent-color: #a66e2e;" />
                        <span>Mostrar solo productos con stock disponible en este almacén</span>
                    </label>
                    <span class="badge-count" id="catContadorResultados">0 productos</span>
                </div>

                <!-- TABLA DE RESULTADOS -->
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
                                <td colspan="4" style="text-align: center; padding: 24px; color: #888;">
                                    Usa los filtros superiores para buscar productos...
                                </td>
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

    <div id="toast" class="toast"></div>

    <script src="movimientos.js?v=3"></script>
</body>

</html>