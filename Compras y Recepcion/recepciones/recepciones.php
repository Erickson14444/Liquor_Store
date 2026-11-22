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
$ordenIdPreseleccionada = (int)($_GET['orden_id'] ?? 0);

// Órdenes de compra disponibles para recibir
$ordenesPendientes = [];
// Historial reciente de recepciones
$historialRecepciones = [];

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
    <link rel="stylesheet" href="../styles.css?v=20" />
    <style>
        .card-producto-recepcion {
            background: #ffffff;
            border: 1px solid #eae3d5;
            border-radius: 8px;
            margin-bottom: 16px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(37, 28, 22, 0.03);
        }
        .card-producto-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 16px;
            background: #fbf9f5;
            border-bottom: 1px solid #eae3d5;
            flex-wrap: wrap;
            gap: 10px;
        }
        .badge-pendiente {
            background-color: #fef9e7;
            color: #b78103;
            border: 1px solid #f9e79f;
            font-size: 0.76rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 4px;
        }
        .alerta-exceso {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 8px;
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
                    <h1 class="page-title">Recepción de Mercancía (Check-in)</h1>
                    <p class="page-subtitle">Ingreso físico con captura de factura, NCF, partición multi-lote y fechas de vencimiento FEFO</p>
                </div>

                <div style="display: flex; gap: 10px;">
                    <a href="../ordenes_compra/ordenes_compra.php" class="btn btn-secondary" style="text-decoration: none;">
                        📥 Ver Órdenes de Compra
                    </a>
                </div>
            </div>

            <?php if (!empty($errorCarga)): ?>
                <div style="background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 0.9rem;">
                    <strong>Error de carga:</strong>
                    <?= htmlspecialchars($errorCarga) ?>
                </div>
            <?php endif; ?>

            <!-- ================================================================= -->
            <!-- FORMULARIO DE CHECK-IN / ENTRADA DE CAMIÓN                        -->
            <!-- ================================================================= -->
            <form id="formRecepcion" onsubmit="guardarRecepcion(event)" class="table-card" style="padding: 20px; margin-bottom: 24px;">
                
                <!-- PASO 1: SELECCIONAR ORDEN -->
                <div class="form-section-bar">
                    <span class="form-section-label">1. Selección de Orden de Compra Pendiente</span>
                    <span style="font-size: 0.75rem; color: var(--text-muted);">Solo órdenes en estado PENDIENTE o RECIBIDA_PARCIAL</span>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 10px;">
                    <div class="form-group">
                        <label class="form-label">Orden de Compra a Recibir *</label>
                        <select id="selectOrdenCompra" class="form-control" required onchange="alCambiarOrdenSeleccionada()">
                            <option value="">-- Seleccione una Orden de Compra --</option>
                            <?php foreach ($ordenesPendientes as $op): ?>
                                <option value="<?= (int)$op['id'] ?>"
                                        data-proveedor="<?= htmlspecialchars($op['proveedor_nombre']) ?>"
                                        data-rnc="<?= htmlspecialchars($op['proveedor_rnc'] ?? '') ?>"
                                        data-numero="<?= htmlspecialchars($op['numero_orden']) ?>"
                                        data-lineas="<?= (int)$op['lineas_pendientes'] ?>"
                                        <?= ($ordenIdPreseleccionada === (int)$op['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($op['numero_orden']) ?> — <?= htmlspecialchars($op['proveedor_nombre']) ?> (<?= $op['lineas_pendientes'] ?> líneas pendientes)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Ficha Resumen de la Orden -->
                    <div id="cajaInfoOrden" style="background: #fdfcf9; border: 1px solid #e7dcc8; border-radius: 6px; padding: 10px 14px; display: none;">
                        <div style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase;">Proveedor de la Orden</div>
                        <div id="infoOrdenProveedor" style="font-weight: 700; color: var(--text-dark);">--</div>
                        <small id="infoOrdenRnc" style="color: var(--text-muted);">RNC: --</small>
                    </div>
                </div>

                <!-- PASO 2: CABECERA DE FACTURA FÍSICA -->
                <div class="form-section-bar" style="margin-top: 20px;">
                    <span class="form-section-label">2. Datos de la Factura Física del Suplidor (Comprobante Fiscal)</span>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1.2fr; gap: 16px; margin-top: 10px;">
                    <div class="form-group">
                        <label class="form-label">Número de Factura Física *</label>
                        <input type="text" id="inputNumFactura" class="form-control" required maxlength="50" placeholder="Ej: FACT-98234" />
                    </div>

                    <div class="form-group">
                        <label class="form-label">NCF del Proveedor (DGII) *</label>
                        <input type="text" id="inputNcf" class="form-control" style="font-family: var(--font-mono); text-transform: uppercase;" required maxlength="13" placeholder="Ej: B0100000001" />
                    </div>

                    <div class="form-group">
                        <label class="form-label">Condición de Pago *</label>
                        <div style="display: flex; align-items: center; height: 35px; gap: 12px;">
                            <label class="radio-condicion-pago">
                                <input type="radio" name="condicion_pago" value="CREDITO" checked onchange="actualizarNotaCondicionPago()" />
                                <span>CRÉDITO</span>
                            </label>
                            <label class="radio-condicion-pago">
                                <input type="radio" name="condicion_pago" value="CONTADO" onchange="actualizarNotaCondicionPago()" />
                                <span>CONTADO</span>
                            </label>
                        </div>
                        <small id="notaCondicionPago" style="color: #1976d2; font-size: 0.72rem;">
                            ℹ️ Crédito: Se creará una cuenta por pagar con vencimiento según los días habituales del proveedor.
                        </small>
                    </div>
                </div>

                <!-- PASO 3: DETALLE MULTI-LOTE -->
                <div class="form-section-bar" style="margin-top: 24px;">
                    <span class="form-section-label">3. Detalle de Mercancía, Cantidades Recibidas y Lotes FEFO</span>
                    <span style="font-size: 0.75rem; color: var(--text-muted);">
                        Puede usar <strong>+ Dividir Lote</strong> para registrar varios lotes de un mismo producto.
                    </span>
                </div>

                <div id="contenedorLineasRecepcion" style="margin-top: 12px;">
                    <div style="text-align: center; padding: 24px; color: var(--text-muted); background: #fbf9f5; border: 1px dashed #e2dacb; border-radius: 8px;">
                        Seleccione una orden de compra arriba para cargar los productos con saldo pendiente.
                    </div>
                </div>

                <!-- PASO 4: TOTALES Y CONFIRMACIÓN -->
                <div id="cajaTotalesRecepcion" style="display: none; border-top: 2px solid #e7dcc8; padding-top: 16px; margin-top: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                        <div>
                            <div style="font-size: 0.85rem; color: #5c5043;">
                                Total Unidades a Ingresar al Kardex: <strong id="lblTotalUnidadesRecepcion">0</strong> u.
                            </div>
                            <div style="font-size: 0.85rem; color: #5c5043;">
                                Total Productos con Mercancía: <strong id="lblTotalProductosRecepcion">0</strong>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 20px;">
                            <div style="text-align: right;">
                                <div style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase;">MONTO TOTAL FACTURADO</div>
                                <div id="lblMontoTotalRecepcion" style="font-size: 1.45rem; font-weight: 700; color: var(--accent-gold); font-family: var(--font-mono);">
                                    RD$ 0.00
                                </div>
                            </div>

                            <?php if ($puedeRegistrar): ?>
                                <button type="submit" class="btn btn-gold" id="btnGuardarRecepcion" style="font-size: 0.95rem; padding: 10px 24px;">
                                    💾 Registrar Recepción y Entrada a Inventario
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary" disabled>
                                    Sin Permisos para Recibir
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </form>

            <!-- ================================================================= -->
            <!-- HISTORIAL RECIENTE DE RECEPCIONES                                 -->
            <!-- ================================================================= -->
            <div class="table-card">
                <div class="table-card-top">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Historial de Recepciones Físicas Registradas</h2>
                        <span class="badge-count"><?= count($historialRecepciones) ?> registros recientes</span>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="data-table">
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
                                    <tr>
                                        <td style="font-weight: 700; font-family: var(--font-mono); color: var(--accent-gold);">
                                            #<?= (int)$hr['id'] ?>
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
                                            <span style="font-size: 0.72rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: <?= ($hr['condicion_pago'] === 'CONTADO') ? '#e8f5e9' : '#e3f2fd' ?>; color: <?= ($hr['condicion_pago'] === 'CONTADO') ? '#2e7d32' : '#1565c0' ?>;">
                                                <?= htmlspecialchars($hr['condicion_pago']) ?>
                                            </span>
                                        </td>
                                        <td style="font-size: 0.82rem;">
                                            <?= date('d/m/Y h:i A', strtotime($hr['fecha_recepcion'])) ?>
                                        </td>
                                        <td class="text-center" style="font-size: 0.8rem; font-family: var(--font-mono);">
                                            <?= (int)$hr['total_unidades'] ?> u. (<?= (int)$hr['total_productos'] ?> prod)
                                        </td>
                                        <td class="text-right" style="font-family: var(--font-mono); font-weight: 700;">
                                            RD$ <?= number_format((float)$hr['monto_total_factura'], 2) ?>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline" onclick="verComprobanteRecepcion(<?= (int)$hr['id'] ?>)">
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
    <!-- MODAL DE COMPROBANTE DE RECEPCIÓN (HISTORIAL)                         -->
    <!-- ===================================================================== -->
    <div class="modal-overlay" id="modalVerRecepcion">
        <div class="modal-box modal-xl">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" id="recModalTitulo">Comprobante de Recepción</h3>
                    <small id="recModalSubtitulo" style="color: var(--text-muted); font-size: 0.78rem;">Entrada de mercancía a almacén y registro fiscal 606</small>
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

    <!-- Script inicial para precarga de orden por GET si aplica -->
    <script>
        const ORDEN_ID_INICIAL = <?= $ordenIdPreseleccionada ?>;
    </script>
    <script src="recepciones.js?v=20"></script>
</body>

</html>
