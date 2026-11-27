<?php
/**
 * ============================================================================
 * Módulo 5: Compras y Recepción • Órdenes de Compra
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/ordenes_compra/ordenes_compra.php
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';

// Verificar permisos
$puedeCrear = usuarioPuedeCrearOrdenCompra();
$puedeRecibir = usuarioPuedeRegistrarRecepcion();

// Catálogo de Proveedores
$proveedores = [];
// Catálogo de Productos Maestros
$productos = [];
// Listado de Órdenes de Compra
$ordenes = [];

$kpiTotal = 0;
$kpiPendientes = 0;
$kpiParciales = 0;
$kpiCompletadas = 0;

$filtroEstado = trim($_GET['estado'] ?? '');
$filtroProveedor = trim($_GET['proveedor_id'] ?? '');

if ($pdo) {
    try {
        // 1. Proveedores activos
        $stmtPrv = $pdo->query("
            SELECT pr.persona_id,
                   pr.codigo_proveedor,
                   pr.dias_credito_habitual,
                   p.nombre_razon_social,
                   p.nombre_comercial,
                   p.documento_identidad
            FROM identidad.proveedores pr
            JOIN identidad.personas p ON p.persona_id = pr.persona_id
            WHERE p.estado = true OR p.estado IS NULL
            ORDER BY p.nombre_razon_social ASC
        ");
        $proveedores = $stmtPrv->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 2. Catálogo de Productos Maestros
        $stmtProd = $pdo->query("
            SELECT pm.id,
                   pm.sku,
                   COALESCE(
                       NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                       (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                       pm.sku
                   ) AS nombre,
                   pm.costo_promedio,
                   pm.precio_base_unidad
            FROM inventario.productos_maestro pm
            WHERE pm.id > 0
            ORDER BY pm.nombre ASC, pm.sku ASC
        ");
        $productos = $stmtProd->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 3. Consultar Órdenes de Compra con agregaciones
        $whereSql = [];
        $params = [];

        if (!empty($filtroEstado) && in_array($filtroEstado, ['PENDIENTE', 'RECIBIDA_PARCIAL', 'COMPLETADA', 'CANCELADA'], true)) {
            $whereSql[] = "oc.estado = ?";
            $params[] = $filtroEstado;
        }

        if (!empty($filtroProveedor)) {
            $whereSql[] = "oc.proveedor_id = ?";
            $params[] = $filtroProveedor;
        }

        $whereClause = !empty($whereSql) ? "WHERE " . implode(" AND ", $whereSql) : "";

        $query = "
            SELECT oc.id,
                   oc.numero_orden,
                   oc.proveedor_id,
                   oc.usuario_id,
                   oc.estado,
                   oc.monto_total_estimado,
                   oc.fecha_emision,
                   oc.fecha_esperada,
                   p.nombre_razon_social AS proveedor_nombre,
                   p.nombre_comercial AS proveedor_comercial,
                   p.documento_identidad AS proveedor_rnc,
                   u.username AS usuario_creador,
                   COUNT(d.producto_maestro_id) AS total_lineas,
                   COALESCE(SUM(d.cantidad_ordenada), 0) AS total_ordenado,
                   COALESCE(SUM(d.cantidad_recibida_total), 0) AS total_recibido,
                   COALESCE(SUM(d.cantidad_pendiente), 0) AS total_pendiente,
                   (
                       SELECT COUNT(rc.id) 
                       FROM compras.recepciones_compra rc 
                       WHERE rc.orden_compra_id = oc.id
                   ) AS total_recepciones
            FROM compras.ordenes_compra oc
            JOIN identidad.proveedores pr ON pr.persona_id = oc.proveedor_id
            JOIN identidad.personas p ON p.persona_id = pr.persona_id
            JOIN seguridad.usuarios u ON u.usuario_id = oc.usuario_id
            LEFT JOIN compras.orden_compra_detalles d ON d.orden_compra_id = oc.id
            {$whereClause}
            GROUP BY oc.id, oc.numero_orden, oc.proveedor_id, oc.usuario_id, oc.estado,
                     oc.monto_total_estimado, oc.fecha_emision, oc.fecha_esperada,
                     p.nombre_razon_social, p.nombre_comercial, p.documento_identidad, u.username
            ORDER BY oc.fecha_emision DESC, oc.id DESC
        ";

        $stmtOrd = $pdo->prepare($query);
        $stmtOrd->execute($params);
        $ordenes = $stmtOrd->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Contadores KPI sobre el universo global de órdenes
        $stmtKpi = $pdo->query("
            SELECT estado, COUNT(*) AS conteo
            FROM compras.ordenes_compra
            GROUP BY estado
        ");
        $kpiRows = $stmtKpi->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($kpiRows as $kr) {
            $est = strtoupper((string) $kr['estado']);
            $cant = (int) $kr['conteo'];
            $kpiTotal += $cant;
            if ($est === 'PENDIENTE')
                $kpiPendientes = $cant;
            elseif ($est === 'RECIBIDA_PARCIAL')
                $kpiParciales = $cant;
            elseif ($est === 'COMPLETADA')
                $kpiCompletadas = $cant;
        }

    } catch (Throwable $e) {
        $errorCarga = $e->getMessage();
    }
}

// Configuración de badges para estado
$badgesEstado = [
    'PENDIENTE' => [
        'label' => 'PENDIENTE',
        'bg' => '#fef9e7',
        'color' => '#b78103',
        'border' => '#f9e79f'
    ],
    'RECIBIDA_PARCIAL' => [
        'label' => 'RECIBIDA PARCIAL',
        'bg' => '#e8f4fd',
        'color' => '#1976d2',
        'border' => '#bbdefb'
    ],
    'COMPLETADA' => [
        'label' => 'COMPLETADA',
        'bg' => '#e8f8f0',
        'color' => '#2e7d52',
        'border' => '#c8e6c9'
    ],
    'CANCELADA' => [
        'label' => 'CANCELADA',
        'bg' => '#fdf2f2',
        'color' => '#c62828',
        'border' => '#ffcdd2'
    ]
];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Órdenes de Compra</title>
    <link rel="stylesheet" href="../styles.css?v=45" />
    <style>
        .badge-estado {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        #modalDetalleOrden .modal-box {
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }

        #cuerpoDetalleOrden {
            display: block !important;
            flex: 1 1 auto;
            min-height: 0;
            max-height: calc(90vh - 130px) !important;
            overflow-y: auto !important;
        }

        .modal-xl {
            width: 96% !important;
            max-width: 1140px !important;
        }

        .grid-split-orden {
            display: grid;
            grid-template-columns: 1fr 1.15fr;
            gap: 14px;
            margin-top: 10px;
            align-items: start;
        }

        @media (max-width: 920px) {
            .grid-split-orden {
                grid-template-columns: 1fr !important;
            }
        }

        .table-compacta-orden {
            width: 100% !important;
            border-collapse: collapse !important;
            table-layout: fixed !important;
        }

        .table-compacta-orden th {
            background: #f6efe4 !important;
            color: #5a3e24 !important;
            font-weight: 700 !important;
            font-size: 0.70rem !important;
            letter-spacing: 0.3px !important;
            text-transform: uppercase !important;
            padding: 6px 8px !important;
            border-bottom: 1px solid #e4d5be !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 5 !important;
        }

        .table-compacta-orden td {
            padding: 4px 6px !important;
            border-bottom: 1px solid #f0e8db !important;
            vertical-align: middle !important;
        }
    </style>
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
                    <?= htmlspecialchars($sesionAvatarInitials ?? 'US') ?>
                </div>
                <div class="user-info-col">
                    <div class="user-name">
                        <?= htmlspecialchars($sesionNombreCompleto ?? 'Usuario') ?>
                    </div>
                    <div class="user-sub-line">
                        <span class="user-role-text">
                            <?= htmlspecialchars($sesionRol ?? 'Empleado') ?>
                        </span>
                        <span class="user-bullet">•</span>
                        <?= renderizarPillCajaTopbar() ?>
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
                    <h1 class="page-title">Órdenes de Compra</h1>
                    <p class="page-subtitle">Emisión formal de pedidos a proveedores, control de costos pactados y
                        seguimiento de entregas parciales</p>
                </div>

                <div style="display: flex; gap: 10px; align-items: center;">
                    <a href="../recepciones/recepciones.php" class="btn btn-secondary" style="text-decoration: none;">
                        🏷️ Ir a Recepciones
                    </a>
                    <?php if ($puedeCrear): ?>
                        <button type="button" class="btn btn-gold" onclick="abrirModalNuevaOrden()">
                            + Nueva Orden de Compra
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($errorCarga)): ?>
                <div
                    style="background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 0.9rem;">
                    <strong>Error al cargar las órdenes de compra:</strong>
                    <?= htmlspecialchars($errorCarga) ?>
                </div>
            <?php endif; ?>

            <!-- RESUMEN / KPIS -->
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 18px;">
                <div style="background: #fff; border: 1px solid #e7dcc8; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.76rem; color: #7a6b5d; font-weight: 700; text-transform: uppercase;">TOTAL
                        ÓRDENES</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #211813; margin-top: 4px;">
                        <?= number_format($kpiTotal) ?>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #f9e79f; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.76rem; color: #b78103; font-weight: 700; text-transform: uppercase;">
                        PENDIENTES (SIN RECIBIR)</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #b78103; margin-top: 4px;">
                        <?= number_format($kpiPendientes) ?>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #bbdefb; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.76rem; color: #1976d2; font-weight: 700; text-transform: uppercase;">
                        RECIBIDAS PARCIALES</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #1976d2; margin-top: 4px;">
                        <?= number_format($kpiParciales) ?>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #c8e6c9; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.76rem; color: #2e7d52; font-weight: 700; text-transform: uppercase;">
                        COMPLETADAS</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #2e7d52; margin-top: 4px;">
                        <?= number_format($kpiCompletadas) ?>
                    </div>
                </div>
            </div>

            <!-- CARD DE FILTROS Y BÚSQUEDA -->
            <div class="table-card" style="margin-bottom: 16px; padding: 14px 18px;">
                <form method="GET" action="ordenes_compra.php" id="formFiltros"
                    style="display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end;">

                    <div style="flex: 1; min-width: 220px;">
                        <label class="form-label">Buscar Orden o Proveedor</label>
                        <input type="text" id="inputBuscarTabla" class="form-control"
                            placeholder="Escriba número de orden, RNC o proveedor..." oninput="filtrarTablaCliente()"
                            style="width: 350px" />
                    </div>

                    <div style="width: 200px;">
                        <label class="form-label">Filtrar por Estado</label>
                        <select name="estado" class="form-control"
                            onchange="document.getElementById('formFiltros').submit();">
                            <option value="">-- Todos los Estados --</option>
                            <option value="PENDIENTE" <?= ($filtroEstado === 'PENDIENTE') ? 'selected' : '' ?>>PENDIENTE
                            </option>
                            <option value="RECIBIDA_PARCIAL" <?= ($filtroEstado === 'RECIBIDA_PARCIAL') ? 'selected' : '' ?>>RECIBIDA PARCIAL</option>
                            <option value="COMPLETADA" <?= ($filtroEstado === 'COMPLETADA') ? 'selected' : '' ?>>COMPLETADA
                            </option>
                            <option value="CANCELADA" <?= ($filtroEstado === 'CANCELADA') ? 'selected' : '' ?>>CANCELADA
                            </option>
                        </select>
                    </div>

                    <div style="width: 260px;">
                        <label class="form-label">Filtrar por Proveedor</label>
                        <select name="proveedor_id" class="form-control"
                            onchange="document.getElementById('formFiltros').submit();">
                            <option value="">-- Todos los Proveedores --</option>
                            <?php foreach ($proveedores as $prv): ?>
                                <option value="<?= htmlspecialchars($prv['persona_id']) ?>"
                                    <?= ($filtroProveedor === $prv['persona_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($prv['nombre_razon_social']) ?>
                                    (<?= htmlspecialchars($prv['documento_identidad'] ?? 'S/RNC') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <a href="ordenes_compra.php" class="btn btn-secondary" style="text-decoration: none;">Limpiar
                            Filtros</a>
                    </div>
                </form>
            </div>

            <!-- TABLA DE ÓRDENES DE COMPRA -->
            <div class="table-card">
                <div class="table-card-top">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Listado de Órdenes Registradas</h2>
                        <span class="badge-count" id="badgeTotalOrdenes"><?= count($ordenes) ?> órdenes</span>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="data-table" id="tablaOrdenes">
                        <thead>
                            <tr>
                                <th>N° Orden</th>
                                <th>Proveedor</th>
                                <th>Fecha Emisión</th>
                                <th>Fecha Esperada</th>
                                <th class="text-right">Monto Estimado</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Avance Recibido</th>
                                <th class="text-center" style="min-width: 130px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ordenes)): ?>
                                <tr>
                                    <td colspan="8" class="text-center" style="padding: 24px; color: var(--text-muted);">
                                        No se encontraron órdenes de compra registradas con los criterios seleccionados.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($ordenes as $oc):
                                    $est = $oc['estado'] ?? 'PENDIENTE';
                                    $badge = $badgesEstado[$est] ?? [
                                        'label' => $est,
                                        'bg' => '#f5f5f5',
                                        'color' => '#616161',
                                        'border' => '#e0e0e0'
                                    ];
                                    $puedeRecibirFila = in_array($est, ['PENDIENTE', 'RECIBIDA_PARCIAL'], true);
                                    $pct = ($oc['total_ordenado'] > 0) ? round(($oc['total_recibido'] / $oc['total_ordenado']) * 100) : 0;
                                    ?>
                                    <tr data-numero="<?= htmlspecialchars(strtolower($oc['numero_orden'])) ?>"
                                        data-proveedor="<?= htmlspecialchars(strtolower($oc['proveedor_nombre'] . ' ' . $oc['proveedor_rnc'])) ?>"
                                        data-estado="<?= htmlspecialchars($est) ?>">
                                        <td style="font-weight: 700; font-family: var(--font-mono); color: var(--accent-gold);">
                                            <?= htmlspecialchars($oc['numero_orden']) ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: var(--text-dark);">
                                                <?= htmlspecialchars($oc['proveedor_nombre']) ?>
                                            </div>
                                            <small style="color: var(--text-muted); font-size: 0.75rem;">
                                                RNC: <?= htmlspecialchars($oc['proveedor_rnc'] ?? 'N/A') ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?= date('d/m/Y', strtotime($oc['fecha_emision'])) ?>
                                        </td>
                                        <td>
                                            <?= !empty($oc['fecha_esperada']) ? date('d/m/Y', strtotime($oc['fecha_esperada'])) : '<span style="color:#aaa;">No definida</span>' ?>
                                        </td>
                                        <td class="text-right" style="font-family: var(--font-mono); font-weight: 700;">
                                            RD$ <?= number_format((float) $oc['monto_total_estimado'], 2) ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge-estado"
                                                style="background: <?= $badge['bg'] ?>; color: <?= $badge['color'] ?>; border: 1px solid <?= $badge['border'] ?>;">
                                                <?= $badge['label'] ?>
                                            </span>
                                        </td>
                                        <td class="text-center" style="font-size: 0.8rem;">
                                            <div style="font-weight: 600; font-family: var(--font-mono);">
                                                <?= (int) $oc['total_recibido'] ?> / <?= (int) $oc['total_ordenado'] ?> u.
                                            </div>
                                            <small style="color: <?= ($pct >= 100) ? '#2e7d52' : '#8c8173' ?>;">
                                                (<?= $pct ?>% recibido)
                                            </small>
                                        </td>
                                        <td class="text-center">
                                            <div style="display: flex; gap: 6px; justify-content: center; align-items: center;">
                                                <button type="button" class="btn btn-sm btn-outline"
                                                    title="Ver Detalle y Seguimiento"
                                                    onclick="abrirModalDetalle(<?= (int) $oc['id'] ?>)">
                                                    👁️ Detalle
                                                </button>
                                                <a href="compra/ver_pdf_orden.php?id=<?= (int) $oc['id'] ?>" target="_blank"
                                                    class="btn btn-sm btn-outline" title="Ver / Descargar Documento PDF Oficial"
                                                    style="display: inline-flex; align-items: center; gap: 3px; border-color: #a66e2e; color: #a66e2e; font-weight: 600; text-decoration: none;">
                                                    📄 PDF
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <!-- ===================================================================== -->
    <!-- MODAL 1: NUEVA ORDEN DE COMPRA                                        -->
    <!-- ===================================================================== -->
    <div class="modal-overlay" id="modalNuevaOrden">
        <div class="modal-box modal-xl">
            <div class="modal-header">
                <h3 class="modal-title">Nueva Orden de Compra</h3>
                <button type="button" class="modal-close" onclick="cerrarModalNuevaOrden()">&times;</button>
            </div>

            <form id="formNuevaOrden" onsubmit="guardarNuevaOrden(event)">
                <div class="modal-body" style="padding: 10px 16px; max-height: calc(88vh - 90px); overflow-y: auto;">

                    <!-- 1. Cabecera Compacta -->
                    <div
                        style="background: #faf8f5; border: 1px solid #eae3d5; border-radius: 8px; padding: 7px 12px; margin-bottom: 8px;">
                        <div
                            style="font-size: 0.68rem; font-weight: 700; color: var(--accent-gold); text-transform: uppercase; margin-bottom: 4px;">
                            1. Datos de Cabecera
                        </div>
                        <div style="display: grid; grid-template-columns: 2fr 1.2fr 1fr; gap: 8px; align-items: start;">
                            <div class="form-group" style="margin: 0; position: relative;">
                                <label class="form-label"
                                    style="font-size: 0.70rem; font-weight: 700; color: #5c5043; margin-bottom: 2px;">Proveedor
                                    *</label>
                                <input type="hidden" id="nuevaOrdenProveedor" name="proveedor_id" required value="" />

                                <div id="boxBusquedaProveedorOc" style="position: relative;">
                                    <input type="text" id="inputBuscarProveedorOc" class="form-control"
                                        placeholder="🔍 Escriba nombre o RNC del suplidor..." autocomplete="off"
                                        oninput="filtrarProveedoresOc(this.value)"
                                        onfocus="abrirDropdownProveedoresOc()"
                                        style="font-size: 0.80rem; height: 30px; width: 100%; box-sizing: border-box;" />
                                    <div id="dropdownProveedoresOc"
                                        style="display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 200px; overflow-y: auto; background: #ffffff; border: 1.5px solid #d4c1a7; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.15); z-index: 1050; margin-top: 2px;">
                                        <!-- Dinámico vía JS -->
                                    </div>
                                </div>

                                <div id="badgeProveedorElegidoOc"
                                    style="display: none; background: #fdf5ea; border: 1px solid #dfcaa4; border-radius: 6px; padding: 2px 8px; align-items: center; justify-content: space-between; height: 30px;">
                                    <div
                                        style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.78rem; color: #211813;">
                                        <strong id="badgeProveedorNombreOc">—</strong>
                                        <small id="badgeProveedorRncOc"
                                            style="color: #7a6b5d; margin-left: 6px; font-family: var(--font-mono);"></small>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-secondary"
                                        onclick="limpiarProveedorSeleccionadoOc()"
                                        style="padding: 1px 6px; font-size: 0.68rem; height: 20px; flex-shrink: 0;">✕
                                        Cambiar</button>
                                </div>

                                <small id="infoProveedorDias"
                                    style="color: var(--text-muted); font-size: 0.66rem; margin-top: 1px; display: block;">
                                    Días de crédito habitual: --
                                </small>
                            </div>

                            <div class="form-group" style="margin: 0;">
                                <label class="form-label"
                                    style="font-size: 0.70rem; font-weight: 700; color: #5c5043; margin-bottom: 2px;">Número
                                    de Orden *</label>
                                <div style="display: flex; gap: 4px;">
                                    <input type="text" id="nuevaOrdenNumero" name="numero_orden" class="form-control"
                                        style="font-family: var(--font-mono); font-weight: 700; font-size: 0.80rem; height: 30px;"
                                        required maxlength="30" placeholder="OC-YYYYMMDD-0001" />
                                    <button type="button" class="btn btn-secondary btn-sm" title="Generar correlativo"
                                        onclick="generarSiguienteNumeroOrden()"
                                        style="padding: 0 8px; flex-shrink: 0; height: 30px;">🔄</button>
                                </div>
                            </div>

                            <div class="form-group" style="margin: 0;">
                                <label class="form-label"
                                    style="font-size: 0.70rem; font-weight: 700; color: #5c5043; margin-bottom: 2px;">Fecha
                                    Esperada</label>
                                <input type="date" id="nuevaOrdenFechaEsperada" name="fecha_esperada"
                                    class="form-control" min="<?= date('Y-m-d') ?>"
                                    style="font-size: 0.80rem; height: 30px;" />
                            </div>
                        </div>
                    </div>

                    <!-- 2. Detalle y Selección de Productos (Layout Split 2 Columnas Simétricas y Elevadas) -->
                    <div class="grid-split-orden"
                        style="display: grid; grid-template-columns: 1fr 1.18fr; gap: 12px; margin-top: 4px; align-items: stretch;">

                        <!-- Columna Izquierda: Catálogo de Productos y Búsqueda -->
                        <div
                            style="background: #faf8f5; border: 1.5px solid #dfcaa4; border-radius: 8px; padding: 8px 10px; display: flex; flex-direction: column; height: 290px; box-sizing: border-box; gap: 6px;">
                            <div
                                style="display: flex; justify-content: space-between; align-items: center; height: 22px; border-bottom: 1px solid #ebdcc5; padding-bottom: 4px;">
                                <span
                                    style="font-size: 0.72rem; font-weight: 700; color: var(--accent-gold); text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap;">
                                    Catálogo de Productos
                                </span>
                                <span id="lblEstadoCatalogo"
                                    style="font-size: 0.68rem; color: var(--text-muted); font-weight: 600; white-space: nowrap;">
                                    <?= count($productos) ?> en catálogo
                                </span>
                            </div>

                            <!-- Buscador en Catálogo -->
                            <input type="text" id="inputBuscarCatalogo" class="form-control"
                                placeholder="🔍 Buscar nombre o SKU..." oninput="buscarProductosCatalogo(this.value)"
                                style="height: 30px; padding-left: 8px; font-size: 0.78rem; flex-shrink: 0;" />

                            <!-- Panel de Productos Scrolleable -->
                            <div id="panelResultadosCatalogo"
                                style="flex: 1; min-height: 0; overflow-y: auto; background: #ffffff; border: 1px solid #dfcaa4; border-radius: 6px; padding: 3px;">
                                <!-- Items dinámicos con botones Agregar / Desagregar -->
                            </div>
                        </div>

                        <!-- Columna Derecha: Productos Agregados a la Orden (Bandeja) -->
                        <div
                            style="background: #faf8f5; border: 1.5px solid #dfcaa4; border-radius: 8px; padding: 8px 10px; display: flex; flex-direction: column; height: 290px; box-sizing: border-box; gap: 6px;">
                            <div
                                style="display: flex; justify-content: space-between; align-items: center; height: 22px; border-bottom: 1px solid #ebdcc5; padding-bottom: 4px;">
                                <span
                                    style="font-size: 0.72rem; font-weight: 700; color: var(--accent-gold); text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap;">
                                    Productos en la Orden
                                </span>
                                <small
                                    style="color: var(--text-muted); font-size: 0.68rem; font-weight: 600; white-space: nowrap;">Define
                                    cantidades</small>
                            </div>

                            <div
                                style="flex: 1; min-height: 0; overflow-y: auto; background: #ffffff; border: 1px solid #dfcaa4; border-radius: 6px;">
                                <table class="table-compacta-orden" id="tablaDetalleNuevaOrden"
                                    style="width: 100%; border-collapse: collapse; margin: 0;">
                                    <thead style="position: sticky; top: 0; z-index: 5;">
                                        <tr style="background: #f6efe4; white-space: nowrap;">
                                            <th
                                                style="width: 38%; padding: 5px 6px; font-size: 0.68rem; font-weight: 700; color: #5a3e24; text-transform: uppercase; white-space: nowrap;">
                                                Producto Seleccionado</th>
                                            <th
                                                style="width: 15%; text-align: center; padding: 5px 4px; font-size: 0.68rem; font-weight: 700; color: #5a3e24; text-transform: uppercase; white-space: nowrap;">
                                                Cantidad *</th>
                                            <th
                                                style="width: 22%; text-align: right; padding: 5px 4px; font-size: 0.68rem; font-weight: 700; color: #5a3e24; text-transform: uppercase; white-space: nowrap;">
                                                Costo (RD$) *</th>
                                            <th
                                                style="width: 19%; text-align: right; padding: 5px 4px; font-size: 0.68rem; font-weight: 700; color: #5a3e24; text-transform: uppercase; white-space: nowrap;">
                                                Subtotal</th>
                                            <th style="width: 6%; text-align: center; padding: 5px 4px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbodyLineasNuevaOrden">
                                        <!-- Filas de productos agregados -->
                                    </tbody>
                                </table>
                            </div>

                            <div
                                style="background: #ffffff; border: 1px solid #dfcaa4; border-radius: 6px; padding: 4px 10px; display: flex; justify-content: space-between; align-items: center; font-weight: 700; min-height: 28px; box-sizing: border-box; flex-shrink: 0;">
                                <div style="display: flex; gap: 14px; font-size: 0.76rem; color: #5a3e24;">
                                    <span>Total Líneas: <strong id="lblTotalLineas"
                                            style="color: #211813;">0</strong></span>
                                    <span>Total Unidades: <strong id="lblTotalUnidades"
                                            style="color: #211813; font-family: var(--font-mono);">0</strong> u.</span>
                                </div>
                                <div
                                    style="font-size: 0.85rem; font-family: var(--font-mono); color: var(--accent-gold);">
                                    <span
                                        style="font-size: 0.68rem; color: #7a6b5d; text-transform: uppercase; font-family: var(--font-sans); margin-right: 4px;">Total
                                        Estimado:</span>
                                    <span id="lblMontoTotalEstimado">RD$ 0.00</span>
                                </div>
                            </div>
                        </div>

                    </div>

                </div>

                <div class="modal-footer" style="padding: 8px 16px;">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalNuevaOrden()">Cancelar</button>
                    <button type="submit" class="btn btn-gold" id="btnGuardarOrden">💾 Crear y Emitir Orden</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- MODAL 2: SEGUIMIENTO Y DETALLE DE ORDEN DE COMPRA                     -->
    <!-- ===================================================================== -->
    <div class="modal-overlay" id="modalDetalleOrden">
        <div class="modal-box modal-xl">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" id="detModalTitulo">Detalle de Orden de Compra</h3>
                    <small id="detModalSubtitulo" style="color: var(--text-muted); font-size: 0.78rem;">Seguimiento y
                        recepciones vinculadas</small>
                </div>
                <button type="button" class="modal-close" onclick="cerrarModalDetalle()">&times;</button>
            </div>

            <div class="modal-body" style="max-height: calc(85vh - 120px); overflow-y: auto;" id="cuerpoDetalleOrden">
                <div style="text-align: center; padding: 30px; color: var(--text-muted);">
                    Cargando información de la orden...
                </div>
            </div>

            <div class="modal-footer" id="footerDetalleOrden">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalDetalle()">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- Script de Productos y Proveedores para JS -->
    <script>
        const CATALOGO_PRODUCTOS = <?= json_encode($productos, JSON_UNESCAPED_UNICODE) ?>;
        window.PROVEEDORES_LISTA = <?= json_encode($proveedores, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="ordenes_compra.js?v=49"></script>
</body>

</html>