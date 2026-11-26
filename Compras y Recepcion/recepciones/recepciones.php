<?php
/**
 * ============================================================================
 * Módulo 5: Compras y Recepción • Recepción Check-in (Multi-Lote y Parciales)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/recepciones/recepciones.php
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';

$puedeRegistrar = usuarioPuedeRegistrarRecepcion();

// Parámetro de orden precargada desde la URL
$ordenIdPreseleccionada = (int) ($_GET['orden_id'] ?? 0);

// Colecciones base
$ordenesPendientes = [];
$historialRecepciones = [];
$proveedores = [];
$productos = [];
$almacenes = [];
$metodosPagoContado = [];
$cuentasBancariasDop = [];

$kpiTotalRecepciones = 0;
$kpiTotalUnidades = 0;
$kpiTotalMonto = 0.00;

if ($pdo) {
    try {
        // 1. Órdenes con entregas pendientes
        $stmtOrd = $pdo->query("
            SELECT oc.id,
                   oc.numero_orden,
                   oc.estado,
                   oc.monto_total_estimado,
                   oc.fecha_emision,
                   p.nombre_razon_social AS proveedor_nombre,
                   p.documento_identidad AS proveedor_rnc,
                   (
                       SELECT COUNT(*)
                       FROM compras.orden_compra_detalles d
                       WHERE d.orden_compra_id = oc.id AND d.cantidad_pendiente > 0
                   ) AS lineas_pendientes
            FROM compras.ordenes_compra oc
            JOIN identidad.proveedores pr ON pr.persona_id = oc.proveedor_id
            JOIN identidad.personas p ON p.persona_id = pr.persona_id
            WHERE oc.estado IN ('PENDIENTE', 'RECIBIDA_PARCIAL')
            ORDER BY oc.fecha_emision DESC, oc.id DESC
        ");
        $ordenesPendientes = $stmtOrd->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 2. Historial de las últimas 50 recepciones registradas
        $stmtHist = $pdo->query("
            SELECT rc.id,
                   rc.orden_compra_id,
                   rc.numero_factura_proveedor,
                   rc.ncf_proveedor,
                   rc.monto_total_factura,
                   rc.fecha_recepcion,
                   rc.condicion_pago,
                   oc.numero_orden,
                   p.nombre_razon_social AS proveedor_nombre,
                   p.documento_identidad AS proveedor_rnc,
                   u.username AS usuario_receptor,
                   (
                       SELECT COUNT(DISTINCT rcd.producto_maestro_id)
                       FROM compras.recepcion_compra_detalles rcd
                       WHERE rcd.recepcion_id = rc.id
                   ) AS total_productos,
                   (
                       SELECT COALESCE(SUM(rcd.cantidad_recibida_buena), 0)
                       FROM compras.recepcion_compra_detalles rcd
                       WHERE rcd.recepcion_id = rc.id
                   ) AS total_unidades
            FROM compras.recepciones_compra rc
            LEFT JOIN compras.ordenes_compra oc ON oc.id = rc.orden_compra_id
            JOIN identidad.proveedores pr ON pr.persona_id = rc.proveedor_id
            JOIN identidad.personas p ON p.persona_id = pr.persona_id
            JOIN seguridad.usuarios u ON u.usuario_id = rc.usuario_recepcion_id
            ORDER BY rc.fecha_recepcion DESC, rc.id DESC
            LIMIT 50
        ");
        $historialRecepciones = $stmtHist->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Totales KPI para resumen
        $kpiTotalRecepciones = count($historialRecepciones);
        foreach ($historialRecepciones as $hr) {
            $kpiTotalUnidades += (int) $hr['total_unidades'];
            $kpiTotalMonto += (float) $hr['monto_total_factura'];
        }

        // 3. Catálogo de Proveedores
        $stmtProv = $pdo->query("
            SELECT pr.persona_id,
                   p.nombre_razon_social,
                   p.nombre_comercial,
                   p.documento_identidad AS rnc,
                   pr.dias_credito_habitual
            FROM identidad.proveedores pr
            JOIN identidad.personas p ON p.persona_id = pr.persona_id
            ORDER BY p.nombre_razon_social ASC
        ");
        $proveedores = $stmtProv->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 4. Catálogo de Productos Maestro
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

        // 5. Catálogo de Almacenes activos (prioriza Bodega para recepciones de compra)
        $stmtAlm = $pdo->query("
            SELECT id, nombre, ubicacion
            FROM inventario.almacenes
            WHERE activo = TRUE
            ORDER BY CASE WHEN LOWER(nombre) LIKE '%bodega%' THEN 0 ELSE 1 END, id ASC
        ");
        $almacenes = $stmtAlm->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 6. Métodos de pago permitidos para compras al contado (Tesorería externa, sin caja POS)
        $stmtMp = $pdo->query("
            SELECT id, codigo, descripcion
            FROM ventas.metodos_pago
            WHERE activo = TRUE AND UPPER(codigo) IN ('EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'CHEQUE')
            ORDER BY id ASC
        ");
        $metodosPagoContado = $stmtMp ? $stmtMp->fetchAll(PDO::FETCH_ASSOC) : [];

        // 7. Cuentas bancarias activas en DOP (para Transferencia / Cheque al contado)
        $stmtCb = $pdo->query("
            SELECT cb.id, cb.nombre_banco, cb.numero_cuenta, cb.tipo_cuenta,
                   COALESCE(m.codigo_iso, 'DOP') AS moneda_iso
            FROM tesoreria.cuentas_bancarias cb
            LEFT JOIN tesoreria.monedas m ON m.id = cb.moneda_id
            WHERE cb.activa = TRUE
              AND (COALESCE(m.es_moneda_base, TRUE) = TRUE OR UPPER(COALESCE(m.codigo_iso, 'DOP')) = 'DOP')
            ORDER BY cb.nombre_banco ASC
        ");
        $cuentasBancariasDop = $stmtCb ? $stmtCb->fetchAll(PDO::FETCH_ASSOC) : [];

    } catch (Throwable $e) {
        $errorCarga = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Recepción de Mercancía</title>
    <link rel="stylesheet" href="../styles.css?v=22" />
    <style>
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(28, 20, 14, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 16px;
        }

        .modal-overlay.open,
        .modal-overlay.active {
            display: flex !important;
        }

        .modal-box {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.25);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalIn 0.2s ease-out;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: translateY(-12px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            background-color: var(--bg-table-header, #f6efe4);
            border-bottom: 1px solid var(--border-light, #eae3d5);
            flex-shrink: 0;
        }

        .modal-title {
            font-family: var(--font-serif, 'Playfair Display', Georgia, serif);
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-dark, #251c16);
            margin: 0;
        }

        .modal-xl {
            width: 96% !important;
            max-width: 1180px !important;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-muted, #8c8173);
            cursor: pointer;
            padding: 0 4px;
            line-height: 1;
            transition: color 0.15s ease;
        }

        .modal-close:hover {
            color: var(--text-dark, #251c16);
        }

        .grid-split-orden {
            display: grid;
            grid-template-columns: 1fr 1.25fr;
            gap: 14px;
            margin-top: 10px;
            align-items: start;
        }

        @media (max-width: 920px) {
            .grid-split-orden {
                grid-template-columns: 1fr !important;
            }
        }

        .card-producto-recepcion {
            background: #ffffff;
            border: 1px solid #eae3d5;
            border-radius: 8px;
            margin-bottom: 14px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(37, 28, 22, 0.03);
        }

        .card-producto-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 14px;
            background: #fbf9f5;
            border-bottom: 1px solid #eae3d5;
            flex-wrap: wrap;
            gap: 10px;
        }

        .badge-pendiente {
            background-color: #fef9e7;
            color: #b78103;
            border: 1px solid #f9e79f;
            font-size: 0.74rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 4px;
        }

        .alerta-exceso {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.78rem;
            font-weight: 600;
            margin-top: 6px;
            display: none;
        }

        .radio-condicion-pago {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            cursor: pointer;
            margin-right: 18px;
        }

        .item-catalogo-busqueda:hover {
            background: #fdf8f0 !important;
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

            <div class="page-header"
                style="margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid #eae3d5;">
                <div>
                    <h1 class="page-title"
                        style="font-family: var(--font-sans, 'DM Sans', sans-serif) !important; font-size: 1.55rem; font-weight: 700; color: var(--text-dark); margin-bottom: 2px;">
                        Recepción de Mercancía (Check-in)</h1>
                    <p class="page-subtitle" style="font-size: 0.82rem; color: var(--text-muted);">Ingreso físico con
                        captura de factura, NCF, almacén destino, partición multi-lote y fechas de vencimiento FEFO</p>
                </div>

                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-gold btn-sm" onclick="abrirModalNuevaRecepcion()"
                        style="height: 36px; padding: 0 14px; display: inline-flex; align-items: center; gap: 6px; font-weight: 700;">
                        <span>➕</span> Nueva Recepción Directa
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="abrirModalImportarOrden()"
                        style="height: 36px; padding: 0 14px; background: #fff; border: 1.5px solid #d4c1a7; display: inline-flex; align-items: center; gap: 6px;">
                        <span>📋</span> Importar Orden de Compra
                        <?php if (count($ordenesPendientes) > 0): ?>
                            <span class="badge-contador"
                                style="background: #a66e2e; color: #fff; border-radius: 12px; padding: 1px 7px; font-size: 0.72rem; font-weight: 700;"><?= count($ordenesPendientes) ?></span>
                        <?php endif; ?>
                    </button>
                    <a href="../ordenes_compra/ordenes_compra.php" class="btn btn-secondary btn-sm"
                        style="height: 36px; padding: 0 14px; background: #fff; border: 1px solid var(--border-light); text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                        📥 Ver Órdenes de Compra
                    </a>
                </div>
            </div>

            <?php if (!empty($errorCarga)): ?>
                <div
                    style="background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 0.9rem;">
                    <strong>Error de carga:</strong>
                    <?= htmlspecialchars($errorCarga) ?>
                </div>
            <?php endif; ?>

            <!-- RESUMEN / KPIS -->
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 18px;">
                <div style="background: #fff; border: 1px solid #e7dcc8; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.74rem; color: #7a6b5d; font-weight: 700; text-transform: uppercase;">TOTAL
                        RECEPCIONES</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #211813; margin-top: 4px;">
                        <?= number_format($kpiTotalRecepciones) ?>
                    </div>
                    <small style="color: var(--text-muted); font-size: 0.74rem;">Entradas a inventario
                        registradas</small>
                </div>
                <div style="background: #fff; border: 1px solid #c8e6c9; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.74rem; color: #2e7d52; font-weight: 700; text-transform: uppercase;">
                        UNIDADES INGRESADAS</div>
                    <div
                        style="font-size: 1.5rem; font-weight: 700; color: #2e7d52; margin-top: 4px; font-family: var(--font-mono);">
                        <?= number_format($kpiTotalUnidades) ?> u.
                    </div>
                    <small style="color: var(--text-muted); font-size: 0.74rem;">Botellas y cajas en Kardex</small>
                </div>
                <div style="background: #fff; border: 1px solid #eadecd; border-radius: 10px; padding: 14px 16px;">
                    <div
                        style="font-size: 0.74rem; color: var(--accent-gold); font-weight: 700; text-transform: uppercase;">
                        TOTAL FACTURADO EN COMPRAS</div>
                    <div
                        style="font-size: 1.5rem; font-weight: 700; color: var(--accent-gold); margin-top: 4px; font-family: var(--font-mono);">
                        RD$ <?= number_format($kpiTotalMonto, 2) ?>
                    </div>
                    <small style="color: var(--text-muted); font-size: 0.74rem;">Monto bruto ingresado</small>
                </div>
                <div style="background: #fff; border: 1px solid #f9e79f; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.74rem; color: #b78103; font-weight: 700; text-transform: uppercase;">
                        ÓRDENES POR RECIBIR</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #b78103; margin-top: 4px;">
                        <?= count($ordenesPendientes) ?>
                    </div>
                    <small style="color: var(--text-muted); font-size: 0.74rem;">Pendientes o parciales</small>
                </div>
            </div>

            <!-- CARD DE BÚSQUEDA Y FILTRADO DEL HISTORIAL -->
            <div class="table-card" style="margin-bottom: 16px; padding: 12px 16px;">
                <div style="display: flex; gap: 12px; align-items: center;">
                    <div style="flex: 1;">
                        <input type="text" id="inputBuscarTablaRecepciones" class="form-control"
                            placeholder="🔍 Buscar en historial por N° recepción, factura física, NCF, proveedor..."
                            oninput="filtrarTablaRecepciones()" style="height: 38px; width: 500px; max-width: 100%;" />
                    </div>
                </div>
            </div>

            <!-- HISTORIAL RECIENTE DE RECEPCIONES -->
            <div class="table-card">
                <div class="table-card-top">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Historial de Recepciones Físicas Registradas</h2>
                        <span class="badge-count" id="badgeTotalRecepciones"><?= count($historialRecepciones) ?>
                            registros recientes</span>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="data-table" id="tablaHistorialRecepciones">
                        <thead>
                            <tr>
                                <th># Recepción</th>
                                <th>N° Factura Física</th>
                                <th>NCF DGII</th>
                                <th>Proveedor</th>
                                <th>Orden de Compra</th>
                                <th class="text-center">Condición</th>
                                <th>Fecha Recepción</th>
                                <th class="text-center">Mercancía</th>
                                <th class="text-right">Monto Total</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($historialRecepciones)): ?>
                                <tr>
                                    <td colspan="10" class="text-center" style="padding: 24px; color: var(--text-muted);">
                                        No se han registrado recepciones físicas de mercancía en el sistema aún.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($historialRecepciones as $hr): ?>
                                    <tr
                                        data-texto="<?= strtolower(htmlspecialchars($hr['id'] . ' ' . $hr['numero_factura_proveedor'] . ' ' . ($hr['ncf_proveedor'] ?? '') . ' ' . $hr['proveedor_nombre'] . ' ' . ($hr['numero_orden'] ?? ''))) ?>">
                                        <td style="font-weight: 700; font-family: var(--font-mono); color: var(--accent-gold);">
                                            #<?= (int) $hr['id'] ?>
                                        </td>
                                        <td style="font-weight: 600;">
                                            <?= htmlspecialchars($hr['numero_factura_proveedor']) ?>
                                        </td>
                                        <td style="font-family: var(--font-mono); font-size: 0.8rem;">
                                            <?= htmlspecialchars($hr['ncf_proveedor'] ?? 'N/A') ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($hr['proveedor_nombre']) ?>
                                        </td>
                                        <td style="font-family: var(--font-mono);">
                                            <?= htmlspecialchars($hr['numero_orden'] ?? 'Sin Orden') ?>
                                        </td>
                                        <td class="text-center">
                                            <span
                                                style="font-size: 0.72rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: <?= ($hr['condicion_pago'] === 'CONTADO') ? '#e8f5e9' : '#e3f2fd' ?>; color: <?= ($hr['condicion_pago'] === 'CONTADO') ? '#2e7d32' : '#1565c0' ?>;">
                                                <?= htmlspecialchars($hr['condicion_pago']) ?>
                                            </span>
                                        </td>
                                        <td style="font-size: 0.82rem;">
                                            <?= date('d/m/Y h:i A', strtotime($hr['fecha_recepcion'])) ?>
                                        </td>
                                        <td class="text-center" style="font-size: 0.8rem; font-family: var(--font-mono);">
                                            <?= (int) $hr['total_unidades'] ?> u. (<?= (int) $hr['total_productos'] ?> prod)
                                        </td>
                                        <td class="text-right" style="font-family: var(--font-mono); font-weight: 700;">
                                            RD$ <?= number_format((float) $hr['monto_total_factura'], 2) ?>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline"
                                                onclick="verComprobanteRecepcion(<?= (int) $hr['id'] ?>)">
                                                👁️ Comprobante
                                            </button>
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
    <!-- MODAL 1: NUEVA RECEPCIÓN DE MERCANCÍA (CHECK-IN)                      -->
    <!-- ===================================================================== -->
    <div class="modal-overlay" id="modalNuevaRecepcion" style="display: none;">
        <div class="modal-box modal-xl">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Recepción de Mercancía (Check-in)</h3>
                    <small id="badgeModoRecepcion" style="color: var(--text-muted); font-size: 0.78rem;">Recepción
                        directa sin orden obligatoria o importada desde orden de compra</small>
                </div>
                <button type="button" class="modal-close" onclick="cerrarModalNuevaRecepcion()">&times;</button>
            </div>

            <form id="formRecepcion" onsubmit="guardarRecepcion(event)"
                style="display: flex; flex-direction: column; overflow: hidden;">
                <input type="hidden" id="inputOrdenCompraId" name="orden_compra_id" value="" />

                <div class="modal-body" style="padding: 12px 18px; max-height: calc(88vh - 120px); overflow-y: auto;">

                    <!-- BANNER ORDEN DE COMPRA VINCULADA -->
                    <div id="bannerOrdenImportada"
                        style="display: none; background: #faf4ea; border: 1.5px solid #d4c1a7; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; align-items: center; justify-content: space-between; gap: 12px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="font-size: 1.3rem;">📋</span>
                            <div>
                                <strong id="textoOrdenVinculada"
                                    style="font-size: 0.92rem; color: var(--text-dark);">OC-0000 — Suplidor</strong>
                                <div id="subtextoOrdenVinculada" style="font-size: 0.74rem; color: var(--text-muted);">
                                    RNC: ... | Días Crédito: ...</div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="desvincularOrdenCompra()"
                            style="border: 1px solid #d0c2b0; font-size: 0.76rem; padding: 3px 8px;">
                            ✕ Quitar Vínculo
                        </button>
                    </div>

                    <!-- 1. Cabecera Compacta -->
                    <div
                        style="background: #faf8f5; border: 1px solid #eae3d5; border-radius: 8px; padding: 7px 12px; margin-bottom: 8px;">
                        <div
                            style="font-size: 0.68rem; font-weight: 700; color: var(--accent-gold); text-transform: uppercase; margin-bottom: 4px;">
                            1. Datos del Proveedor, Almacén Destino y Factura Física (DGII 606)
                        </div>
                        <div
                            style="display: grid; grid-template-columns: 1.45fr 1fr 0.9fr 0.9fr 0.95fr; gap: 8px; align-items: start;">
                            <!-- SELECTOR BUSCABLE DE PROVEEDOR -->
                            <div class="form-group" style="margin: 0; position: relative;">
                                <label class="form-label"
                                    style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Proveedor
                                    *</label>
                                <input type="hidden" id="selectProveedorRecepcion" name="proveedor_id" required
                                    value="" />

                                <div id="boxBusquedaProveedorRecep" style="position: relative;">
                                    <input type="text" id="inputBuscarProveedorRecep" class="form-control"
                                        placeholder="🔍 Escriba nombre o RNC..." autocomplete="off"
                                        oninput="filtrarProveedoresRecep(this.value)"
                                        onfocus="abrirDropdownProveedoresRecep()"
                                        style="height: 30px; font-size: 0.80rem; width: 100%;" />
                                    <div id="dropdownProveedoresRecep"
                                        style="display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 200px; overflow-y: auto; background: #ffffff; border: 1.5px solid #d4c1a7; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.15); z-index: 1050; margin-top: 2px;">
                                        <!-- Dinámico -->
                                    </div>
                                </div>

                                <div id="badgeProveedorElegidoRecep"
                                    style="display: none; background: #fdf5ea; border: 1px solid #dfcaa4; border-radius: 6px; padding: 2px 8px; align-items: center; justify-content: space-between; height: 30px;">
                                    <div
                                        style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.78rem; color: #211813;">
                                        <strong id="txtNombreProvRecep">—</strong>
                                        <small id="txtDetalleProvRecep"
                                            style="color: #7a6b5d; margin-left: 6px; font-family: var(--font-mono);"></small>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-secondary" id="btnCambiarProvRecep"
                                        onclick="limpiarProveedorSeleccionadoRecep()"
                                        style="padding: 1px 6px; font-size: 0.68rem; height: 20px; flex-shrink: 0;">✕
                                        Cambiar</button>
                                </div>

                                <small id="infoProveedorRecepcion"
                                    style="color: var(--text-muted); font-size: 0.66rem; margin-top: 1px; display: block;">
                                    Días habituales: --
                                </small>
                            </div>

                            <!-- SELECTOR DE ALMACÉN DESTINO -->
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label" for="selectAlmacenRecepcion"
                                    style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Almacén Destino
                                    *</label>
                                <select id="selectAlmacenRecepcion" name="almacen_id" class="form-control" required
                                    style="height: 30px; font-size: 0.80rem;">
                                    <?php if (empty($almacenes)): ?>
                                        <option value="">-- Sin almacenes activos --</option>
                                    <?php else: ?>
                                        <?php foreach ($almacenes as $alm): ?>
                                            <option value="<?= (int) $alm['id'] ?>">
                                                <?= htmlspecialchars($alm['nombre']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="form-group" style="margin: 0;">
                                <label class="form-label"
                                    style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">N° Factura Física
                                    *</label>
                                <input type="text" id="inputNumFactura" class="form-control" required maxlength="50"
                                    placeholder="Ej: FACT-98234" style="height: 30px; font-size: 0.80rem;" />
                            </div>

                            <div class="form-group" style="margin: 0;">
                                <label class="form-label"
                                    style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">NCF Proveedor
                                    (DGII) *</label>
                                <input type="text" id="inputNcf" class="form-control"
                                    style="font-family: var(--font-mono); text-transform: uppercase; height: 30px; font-size: 0.80rem;"
                                    required maxlength="13" placeholder="Ej: B0100000001" />
                            </div>

                            <div class="form-group" style="margin: 0;">
                                <label class="form-label"
                                    style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Condición de Pago
                                    *</label>
                                <div style="display: flex; align-items: center; height: 30px; gap: 8px;">
                                    <label class="radio-condicion-pago" style="font-size: 0.75rem; margin: 0;">
                                        <input type="radio" name="condicion_pago" value="CREDITO" checked
                                            onchange="actualizarNotaCondicionPago()" />
                                        <span>CRÉDITO</span>
                                    </label>
                                    <label class="radio-condicion-pago" style="font-size: 0.75rem; margin: 0;">
                                        <input type="radio" name="condicion_pago" value="CONTADO"
                                            onchange="actualizarNotaCondicionPago()" />
                                        <span>CONTADO</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- PANEL DE LIQUIDACIÓN INMEDIATA (SOLO SI CONDICIÓN = CONTADO) -->
                        <div id="panelPagoContadoRecep"
                            style="display: none; margin-top: 8px; padding-top: 8px; border-top: 1px dashed #dfcaa4;">
                            <div
                                style="display: grid; grid-template-columns: 1.1fr 1.3fr 1fr 1.4fr; gap: 8px; align-items: end;">
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" for="selectMetodoPagoContado"
                                        style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px; color: #2e7d52;">
                                        💳 Método de Pago (Contado) *
                                    </label>
                                    <select id="selectMetodoPagoContado" name="metodo_pago_id" class="form-control"
                                        onchange="alCambiarMetodoPagoContado()"
                                        style="height: 30px; font-size: 0.80rem;">
                                        <?php foreach ($metodosPagoContado as $mp): ?>
                                            <option value="<?= (int) $mp['id'] ?>"
                                                data-codigo="<?= htmlspecialchars(strtoupper($mp['codigo'])) ?>">
                                                <?= htmlspecialchars($mp['descripcion']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group" id="wrapBancoContado" style="margin: 0; display: none;">
                                    <label class="form-label" for="selectCuentaBancoContado"
                                        style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">
                                        Cuenta Bancaria Salida (DOP) *
                                    </label>
                                    <select id="selectCuentaBancoContado" name="cuenta_bancaria_id" class="form-control"
                                        style="height: 30px; font-size: 0.80rem;">
                                        <?php if (empty($cuentasBancariasDop)): ?>
                                            <option value="">-- Sin cuentas DOP activas --</option>
                                        <?php else: ?>
                                            <?php foreach ($cuentasBancariasDop as $cb): ?>
                                                <option value="<?= (int) $cb['id'] ?>">
                                                    <?= htmlspecialchars($cb['nombre_banco'] . ' (' . $cb['numero_cuenta'] . ')') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <div class="form-group" id="wrapRefContado" style="margin: 0; display: none;">
                                    <label class="form-label" id="lblRefContado" for="inputRefPagoContado"
                                        style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">
                                        No. Referencia *
                                    </label>
                                    <input type="text" id="inputRefPagoContado" name="referencia_pago"
                                        class="form-control" maxlength="100" placeholder="Ej: TRF-00491"
                                        style="height: 30px; font-size: 0.80rem; font-family: var(--font-mono);" />
                                </div>

                                <div id="lblInfoPagoContado"
                                    style="font-size: 0.71rem; color: #2e7d52; background: #eef8f2; border: 1px solid #c2e5d1; border-radius: 5px; padding: 5px 8px; line-height: 1.25;">
                                    <strong>Pago inmediato:</strong> Se emitirá el Comprobante de Egreso y la CxP
                                    quedará <strong>PAGADA</strong> (saldo RD$ 0.00). Si aplican retenciones DGII, usa
                                    <em>CRÉDITO</em> y liquida en CxP.
                                </div>
                            </div>

                            <div id="wrapTarjetaContado"
                                style="display: none; margin-top: 8px; grid-template-columns: 0.9fr 1fr 0.8fr 1.3fr; gap: 8px;">
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" for="selectTipoTarjetaContado"
                                        style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Tipo Tarjeta
                                        *</label>
                                    <select id="selectTipoTarjetaContado" class="form-control"
                                        style="height: 30px; font-size: 0.80rem;">
                                        <option value="DEBITO">Débito Corporativa</option>
                                        <option value="CREDITO">Crédito Corporativa</option>
                                    </select>
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" for="selectMarcaTarjetaContado"
                                        style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Marca
                                        *</label>
                                    <select id="selectMarcaTarjetaContado" class="form-control"
                                        style="height: 30px; font-size: 0.80rem;">
                                        <option value="VISA">Visa</option>
                                        <option value="MASTERCARD">Mastercard</option>
                                        <option value="AMEX">Amex</option>
                                        <option value="OTRA">Otra</option>
                                    </select>
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" for="inputUlt4TarjetaContado"
                                        style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Últ. 4 Dígitos
                                        *</label>
                                    <input type="text" id="inputUlt4TarjetaContado" class="form-control" maxlength="4"
                                        placeholder="1234"
                                        style="height: 30px; font-size: 0.80rem; font-family: var(--font-mono);"
                                        oninput="this.value=this.value.replace(/\D/g,'').slice(0,4);" />
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" for="inputAutTarjetaContado"
                                        style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">No.
                                        Autorización / Voucher *</label>
                                    <input type="text" id="inputAutTarjetaContado" class="form-control" maxlength="50"
                                        placeholder="Ej: AUT-849201"
                                        style="height: 30px; font-size: 0.80rem; font-family: var(--font-mono);" />
                                </div>
                            </div>




                        </div>
                    </div>

                    <!-- 2. Detalle y Selección de Productos (Layout Split 2 Columnas Simétricas y Elevadas) -->
                    <div class="grid-split-orden"
                        style="display: grid; grid-template-columns: 1fr 1.18fr; gap: 12px; margin-top: 4px; align-items: stretch;">

                        <!-- Columna Izquierda: Catálogo de Productos -->
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

                            <input type="text" id="inputBuscarCatalogo" class="form-control"
                                placeholder="🔍 Buscar nombre o SKU..." oninput="buscarProductosCatalogo(this.value)"
                                style="height: 30px; padding-left: 8px; font-size: 0.78rem; flex-shrink: 0;" />

                            <div id="panelResultadosCatalogo"
                                style="flex: 1; min-height: 0; overflow-y: auto; background: #ffffff; border: 1px solid #dfcaa4; border-radius: 6px; padding: 3px;">
                                <!-- Items dinámicos -->
                            </div>
                        </div>

                        <!-- Columna Derecha: Productos en la Recepción (Bandeja) -->
                        <div
                            style="background: #faf8f5; border: 1.5px solid #dfcaa4; border-radius: 8px; padding: 8px 10px; display: flex; flex-direction: column; height: 290px; box-sizing: border-box; gap: 6px;">
                            <div
                                style="display: flex; justify-content: space-between; align-items: center; height: 22px; border-bottom: 1px solid #ebdcc5; padding-bottom: 4px;">
                                <span
                                    style="font-size: 0.72rem; font-weight: 700; color: var(--accent-gold); text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap;">
                                    Productos en la Recepción
                                </span>
                                <small
                                    style="color: var(--text-muted); font-size: 0.68rem; font-weight: 600; white-space: nowrap;">Use
                                    <strong>+ Lote</strong> para dividir</small>
                            </div>

                            <div id="contenedorLineasRecepcion"
                                style="flex: 1; min-height: 0; overflow-y: auto; background: #ffffff; border: 1px solid #dfcaa4; border-radius: 6px;">
                                <!-- Tabla compacta generada por JS -->
                            </div>

                            <div
                                style="background: #ffffff; border: 1px solid #dfcaa4; border-radius: 6px; padding: 4px 10px; display: flex; justify-content: space-between; align-items: center; font-weight: 700; min-height: 28px; box-sizing: border-box; flex-shrink: 0;">
                                <div style="display: flex; gap: 14px; font-size: 0.76rem; color: #5a3e24;">
                                    <span>Total Líneas: <strong id="lblTotalLineasRecep"
                                            style="color: #211813;">0</strong></span>
                                    <span>Total Unidades: <strong id="lblTotalUnidadesRecep"
                                            style="color: #211813; font-family: var(--font-mono);">0</strong> u.</span>
                                </div>
                                <div
                                    style="font-size: 0.85rem; font-family: var(--font-mono); color: var(--accent-gold);">
                                    <span
                                        style="font-size: 0.68rem; color: #7a6b5d; text-transform: uppercase; font-family: var(--font-sans); margin-right: 4px;">Total
                                        Facturado:</span>
                                    <span id="lblMontoTotalEstimadoRecep">RD$ 0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Footer del Modal con Totales y Botones -->
                <div class="modal-footer"
                    style="padding: 10px 18px; background: #faf8f5; border-top: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div style="display: flex; gap: 16px; font-size: 0.80rem; color: #5c5043;">
                        <span>Total Unidades: <strong id="lblTotalUnidadesRecepcion"
                                style="color: #211813; font-family: var(--font-mono);">0</strong> u.</span>
                        <span>Total Productos: <strong id="lblTotalProductosRecepcion"
                                style="color: #211813;">0</strong></span>
                        <span>Total Facturado: <strong id="lblMontoTotalRecepcion"
                                style="color: var(--accent-gold); font-family: var(--font-mono); font-size: 1.05rem;">RD$
                                0.00</strong></span>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn btn-secondary"
                            onclick="cerrarModalNuevaRecepcion()">Cancelar</button>
                        <?php if ($puedeRegistrar): ?>
                            <button type="submit" class="btn btn-gold" id="btnGuardarRecepcion" style="font-weight: 700;">
                                💾 Registrar Recepción y Entrada a Inventario
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary" disabled>Sin Permisos</button>
                        <?php endif; ?>
                    </div>
                </div>

            </form>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- MODAL: IMPORTAR ORDEN DE COMPRA (CHECK-IN)                           -->
    <!-- ===================================================================== -->
    <div class="modal-overlay" id="modalImportarOrdenCompra" style="display: none;">
        <div class="modal-box" style="max-width: 820px; width: 95%;">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">📋 Importar Orden de Compra a Check-in</h3>
                    <small style="color: var(--text-muted); font-size: 0.78rem;">Seleccione una orden aprobada para
                        verificar y recibir la mercancía en almacén</small>
                </div>
                <button type="button" class="btn-close" onclick="cerrarModalImportarOrden()"
                    style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-muted);">&times;</button>
            </div>

            <div class="modal-body" style="padding: 16px 20px; max-height: 70vh; overflow-y: auto;">
                <div style="margin-bottom: 14px;">
                    <input type="text" id="filtroModalOrdenes" class="form-control"
                        placeholder="🔍 Filtrar por número de orden, proveedor o RNC..."
                        oninput="filtrarOrdenesModal(this.value)"
                        style="height: 38px; font-size: 0.88rem; padding-left: 12px; width: 300px; max-width: 100%;" />
                </div>

                <div class="table-wrapper"
                    style="border: 1px solid var(--border-light); border-radius: 6px; overflow: hidden;">
                    <table class="data-table" style="width: 100%; border-collapse: collapse;" id="tablaModalOrdenes">
                        <thead>
                            <tr style="background: var(--bg-table-header, #f6efe4); border-bottom: 1px solid #e4d5be;">
                                <th
                                    style="padding: 10px 12px; text-align: left; font-size: 0.75rem; text-transform: uppercase;">
                                    N° Orden</th>
                                <th
                                    style="padding: 10px 12px; text-align: left; font-size: 0.75rem; text-transform: uppercase;">
                                    Proveedor / RNC</th>
                                <th
                                    style="padding: 10px 10px; text-align: center; font-size: 0.75rem; text-transform: uppercase;">
                                    Emisión</th>
                                <th
                                    style="padding: 10px 10px; text-align: center; font-size: 0.75rem; text-transform: uppercase;">
                                    Líneas Pend.</th>
                                <th
                                    style="padding: 10px 12px; text-align: right; font-size: 0.75rem; text-transform: uppercase;">
                                    Total Est.</th>
                                <th
                                    style="padding: 10px 12px; text-align: center; font-size: 0.75rem; text-transform: uppercase;">
                                    Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ordenesPendientes)): ?>
                                <tr>
                                    <td colspan="6" class="text-center" style="padding: 30px; color: var(--text-muted);">
                                        No hay órdenes de compra pendientes o en recepción parcial.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($ordenesPendientes as $op): ?>
                                    <tr class="fila-modal-orden"
                                        data-texto="<?= strtolower(htmlspecialchars($op['numero_orden'] . ' ' . $op['proveedor_nombre'] . ' ' . ($op['proveedor_rnc'] ?? ''))) ?>"
                                        style="border-bottom: 1px solid #f2ede4;">
                                        <td style="padding: 10px 12px;">
                                            <strong
                                                style="font-family: var(--font-mono); color: var(--accent-gold); font-size: 0.88rem;"><?= htmlspecialchars($op['numero_orden']) ?></strong>
                                            <div style="font-size: 0.72rem; color: var(--text-muted);">
                                                <?= ($op['estado'] === 'RECIBIDA_PARCIAL') ? '⚡ Entrega Parcial' : '⏳ Pendiente' ?>
                                            </div>
                                        </td>
                                        <td style="padding: 10px 12px;">
                                            <div style="font-weight: 600; color: var(--text-dark); font-size: 0.88rem;">
                                                <?= htmlspecialchars($op['proveedor_nombre']) ?>
                                            </div>
                                            <div
                                                style="font-size: 0.75rem; color: var(--text-muted); font-family: var(--font-mono);">
                                                RNC: <?= htmlspecialchars($op['proveedor_rnc'] ?? 'N/A') ?></div>
                                        </td>
                                        <td style="padding: 10px 10px; text-align: center; font-size: 0.82rem; color: #5a4b3d;">
                                            <?= htmlspecialchars(date('d/m/Y', strtotime($op['fecha_emision']))) ?>
                                        </td>
                                        <td style="padding: 10px 10px; text-align: center;">
                                            <span
                                                style="background: #eef5fb; color: #1e6091; font-weight: 700; border-radius: 10px; padding: 2px 8px; font-size: 0.78rem;">
                                                <?= (int) $op['lineas_pendientes'] ?> líneas
                                            </span>
                                        </td>
                                        <td
                                            style="padding: 10px 12px; text-align: right; font-family: var(--font-mono); font-weight: 700; font-size: 0.90rem; color: var(--text-dark);">
                                            RD$ <?= number_format((float) $op['monto_total_estimado'], 2) ?>
                                        </td>
                                        <td style="padding: 10px 12px; text-align: center;">
                                            <button type="button" class="btn btn-sm btn-gold"
                                                onclick="importarOrdenDesdeModal(<?= (int) $op['id'] ?>)"
                                                style="padding: 6px 14px; font-size: 0.82rem; font-weight: 700;">
                                                📥 Cargar
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer"
                style="padding: 12px 20px; background: #faf8f5; border-top: 1px solid var(--border-light); display: flex; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalImportarOrden()">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- MODAL DE COMPROBANTE DE RECEPCIÓN (HISTORIAL)                         -->
    <!-- ===================================================================== -->
    <div class="modal-overlay" id="modalVerRecepcion" style="display: none;">
        <div class="modal-box modal-xl">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" id="recModalTitulo">Comprobante de Recepción</h3>
                    <small id="recModalSubtitulo" style="color: var(--text-muted); font-size: 0.78rem;">Entrada de
                        mercancía a almacén y registro fiscal 606</small>
                </div>
                <button type="button" class="modal-close" onclick="cerrarModalRecepcion()">&times;</button>
            </div>

            <div class="modal-body" style="max-height: calc(85vh - 120px); overflow-y: auto;" id="cuerpoVerRecepcion">
                <!-- Se llena dinámicamente vía fetch -->
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalRecepcion()">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- Script inicial para precarga de orden por GET si aplica y catálogos -->
    <script>
        const ORDEN_ID_INICIAL = <?= $ordenIdPreseleccionada ?>;
        const CATALOGO_PRODUCTOS = <?= json_encode($productos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        const PROVEEDORES_LISTA = <?= json_encode($proveedores, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="recepciones.js?v=<?= time() ?>"></script>
</body>

</html>