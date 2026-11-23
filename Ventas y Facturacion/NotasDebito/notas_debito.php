<?php
/**
 * ============================================================================
 * Vista Principal: Proceso 8 - Notas de Débito (NCF B03)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/NotasDebito/notas_debito.php
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/funciones.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

requerirSesionActiva(false, '../../Login y Menu/login.php');

if (!function_exists('usuarioPuedeAccederNotasDebito') || !usuarioPuedeAccederNotasDebito()) {
    header("Location: ../../Login y Menu/menu.php?error=acceso_denegado");
    exit;
}

$usuarioId = $_SESSION['usuario']['usuario_id'];
$personaId = $_SESSION['usuario']['persona_id'] ?? null;

// ============================================================================
// CARGA DE DATOS Y CONSULTAS PRINCIPALES
// ============================================================================
$kpiTotalNd      = 0;
$kpiMontoTotalNd = 0.00;
$kpiPendienteMto = 0.00;
$kpiPendienteCnt = 0;
$kpiCobradoMto   = 0.00;
$kpiCobradoCnt   = 0;

$listaNotasDebito = [];
$supervisoresList = [];
$secuenciaB03     = null;

if ($pdo) {
    // 1. KPIs Financieros de Notas de Débito
    try {
        $stmtKpi = $pdo->query("
            SELECT 
                COUNT(nd.id) AS total_nd,
                COALESCE(SUM(nd.monto_total), 0) AS monto_total_nd,
                COUNT(CASE WHEN cxc.estado IN ('PENDIENTE', 'PAGADA_PARCIAL') THEN 1 END) AS pend_cnt,
                COALESCE(SUM(CASE WHEN cxc.estado IN ('PENDIENTE', 'PAGADA_PARCIAL') THEN cxc.saldo_pendiente ELSE 0 END), 0) AS pend_mto,
                COUNT(CASE WHEN cxc.estado = 'PAGADA' THEN 1 END) AS cobrado_cnt,
                COALESCE(SUM(CASE WHEN cxc.estado = 'PAGADA' THEN nd.monto_total ELSE 0 END), 0) AS cobrado_mto
            FROM ventas.notas_debito nd
            LEFT JOIN ventas.cuentas_por_cobrar cxc ON cxc.nota_debito_id = nd.id
        ");
        $kRow = $stmtKpi ? $stmtKpi->fetch(PDO::FETCH_ASSOC) : null;
        if ($kRow) {
            $kpiTotalNd      = (int)$kRow['total_nd'];
            $kpiMontoTotalNd = (float)$kRow['monto_total_nd'];
            $kpiPendienteCnt = (int)$kRow['pend_cnt'];
            $kpiPendienteMto = (float)$kRow['pend_mto'];
            $kpiCobradoCnt   = (int)$kRow['cobrado_cnt'];
            $kpiCobradoMto   = (float)$kRow['cobrado_mto'];
        }
    } catch (Throwable $e) {}

    // 2. Secuencia Fiscal Activa para B03
    try {
        $stmtSec = $pdo->query("
            SELECT id, secuencia_desde, secuencia_hasta, secuencia_actual, estado,
                   TO_CHAR(fecha_vencimiento, 'DD/MM/YYYY') AS vencimiento_fmt
            FROM ventas.secuencias_fiscales_dgii
            WHERE tipo_comprobante = 'B03' AND estado = 'ACTIVO'
            ORDER BY id ASC LIMIT 1
        ");
        $secuenciaB03 = $stmtSec ? $stmtSec->fetch(PDO::FETCH_ASSOC) : null;
    } catch (Throwable $e) {}

    // 3. Supervisores autorizados para aprobación con PIN
    try {
        $supervisoresList = obtenerSupervisoresAutorizados($pdo);
    } catch (Throwable $e) {}

    // 4. Listado Principal de Notas de Débito
    try {
        $stmtNd = $pdo->query("
            SELECT nd.id,
                   nd.ncf_nota_debito,
                   nd.ncf_factura_modificada,
                   nd.concepto_cargo,
                   nd.monto_subtotal,
                   nd.monto_impuestos,
                   nd.monto_total,
                   TO_CHAR(nd.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_fmt,
                   f.numero_factura AS factura_numero,
                   p.nombre_razon_social AS cliente_nombre,
                   p.documento_identidad AS cliente_rnc,
                   cl.codigo_cliente,
                   u.username AS usuario_emisor,
                   COALESCE(cxc.estado, 'PENDIENTE') AS cxc_estado,
                   COALESCE(cxc.saldo_pendiente, nd.monto_total) AS cxc_saldo_pendiente,
                   cxc.id AS cxc_id
            FROM ventas.notas_debito nd
            JOIN identidad.clientes cl ON cl.persona_id = nd.cliente_id
            JOIN identidad.personas p ON p.persona_id = cl.persona_id
            JOIN ventas.facturas f ON f.id = nd.factura_id
            JOIN seguridad.usuarios u ON u.usuario_id = nd.usuario_emisor_id
            LEFT JOIN ventas.cuentas_por_cobrar cxc ON cxc.nota_debito_id = nd.id
            ORDER BY nd.fecha_emision DESC, nd.id DESC
        ");
        $listaNotasDebito = $stmtNd ? $stmtNd->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}
}

$ncfProximoB03 = $secuenciaB03 
    ? 'B03' . str_pad((string)$secuenciaB03['secuencia_actual'], 8, '0', STR_PAD_LEFT)
    : 'No disponible';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Notas de Débito (NCF B03)</title>
    <link rel="stylesheet" href="../styles.css?v=33" />
    <link rel="stylesheet" href="styles.css?v=2" />
</head>

<body>

    <!-- TOPBAR -->
    <header class="topbar">
        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" class="mobile-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Abrir Menú">
                <span></span><span></span><span></span>
            </button>
            <a href="../../Login y Menu/menu.php" class="topbar-brand" style="text-decoration: none;">
                <span class="brand-title">HOUSE &amp; ICE</span>
                <span class="brand-dot">•</span>
                <span class="brand-subtitle">Notas de Débito (NCF B03)</span>
            </a>
        </div>
        <div class="topbar-actions">
            <div class="topbar-user">
                <div class="user-avatar"><?= htmlspecialchars($sesionAvatarInitials ?? 'NM') ?></div>
                <div class="user-info-col">
                    <div class="user-name"><?= htmlspecialchars($sesionNombreCompleto ?? 'Usuario') ?></div>
                    <div class="user-sub-line">
                        <span class="user-role-text"><?= htmlspecialchars($sesionRol ?? 'Administrador') ?></span>
                        <span class="user-bullet">•</span>
                        <?= renderizarPillCajaTopbar() ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="app-container">
        <!-- SIDEBAR DE NAVEGACIÓN -->
        <?php include_once __DIR__ . '/../../sidebar.php'; ?>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <!-- CABECERA DEL MÓDULO -->
            <div class="page-header" style="align-items: center;">
                <div>
                    <h1 class="page-title">📄 Notas de Débito (NCF B03)</h1>
                    <p class="page-subtitle">
                        Gestión fiscal de recargos, penalidades bancarias por cheques devueltos, mora y corrección de subfacturación (DGII 607).
                    </p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <?php if ($secuenciaB03): ?>
                        <div class="badge-ncf-b03" title="Próximo comprobante a emitir">
                            Próximo NCF: <strong><?= $ncfProximoB03 ?></strong>
                        </div>
                    <?php else: ?>
                        <span style="font-size: 0.8rem; color: #b71c1c; font-weight: 700;">⚠️ Sin secuencia B03 activa</span>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" onclick="abrirModalEmitirNd()" style="background: #a66e2e; color: #ffffff !important; font-weight: 700;">
                        <span>➕</span> Emitir Nota de Débito (B03)
                    </button>
                </div>
            </div>

            <!-- TARJETAS DE INDICADORES (KPIS: EXACTAMENTE 4 TARJETAS) -->
            <div class="nd-kpi-container">
                <div class="nd-kpi-card">
                    <div class="nd-kpi-icon gold">📄</div>
                    <div class="nd-kpi-data">
                        <h4>Notas de Débito Emitidas</h4>
                        <div class="nd-kpi-value"><?= $kpiTotalNd ?> docs</div>
                        <div class="nd-kpi-sub">Comprobantes fiscales B03</div>
                    </div>
                </div>

                <div class="nd-kpi-card">
                    <div class="nd-kpi-icon blue">💰</div>
                    <div class="nd-kpi-data">
                        <h4>Monto Total Facturado</h4>
                        <div class="nd-kpi-value">RD$ <?= number_format($kpiMontoTotalNd, 2) ?></div>
                        <div class="nd-kpi-sub">Subtotal + ITBIS cargado</div>
                    </div>
                </div>

                <div class="nd-kpi-card">
                    <div class="nd-kpi-icon orange">⏳</div>
                    <div class="nd-kpi-data">
                        <h4>Cargos Pendientes en CxC</h4>
                        <div class="nd-kpi-value" style="color: #d35400;">RD$ <?= number_format($kpiPendienteMto, 2) ?></div>
                        <div class="nd-kpi-sub"><?= $kpiPendienteCnt ?> obligaciones por cobrar</div>
                    </div>
                </div>

                <div class="nd-kpi-card">
                    <div class="nd-kpi-icon green">✅</div>
                    <div class="nd-kpi-data">
                        <h4>Cargos Liquidados / Cobrados</h4>
                        <div class="nd-kpi-value" style="color: #2e7d52;">RD$ <?= number_format($kpiCobradoMto, 2) ?></div>
                        <div class="nd-kpi-sub"><?= $kpiCobradoCnt ?> pagados vía Recibos CxC</div>
                    </div>
                </div>
            </div>

            <!-- FILTROS Y BÚSQUEDA -->
            <div class="nd-filter-bar">
                <div class="nd-filter-group" style="flex: 2; min-width: 220px;">
                    <label>Buscar NCF, Factura Origen o Cliente</label>
                    <input type="text" id="filtroTexto" class="form-control" placeholder="Escriba B03, NCF original, cliente, motivo..." oninput="filtrarTablaNd()" />
                </div>

                <div class="nd-filter-group" style="flex: 1; min-width: 170px;">
                    <label>Estado en Cuentas por Cobrar</label>
                    <select id="filtroEstado" class="form-control" onchange="filtrarTablaNd()">
                        <option value="TODOS">Todos los Estados</option>
                        <option value="PENDIENTE">Solo Pendientes</option>
                        <option value="PAGADA">Solo Pagadas / Cobradas</option>
                    </select>
                </div>

                <div>
                    <button type="button" class="btn btn-secondary" onclick="limpiarFiltrosNd()" style="padding: 8px 14px;">
                        Limpiar
                    </button>
                </div>
            </div>

            <!-- TABLA DE NOTAS DE DÉBITO -->
            <div class="table-card">
                <div class="table-card-top">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Registro Fiscal de Notas de Débito (Formato 607)</h2>
                        <span class="badge-count" id="badgeTotalNd"><?= count($listaNotasDebito) ?> registros</span>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                        Impacto contable: Las Notas de Débito nacen en ventas.cuentas_por_cobrar sin contaminar la caja del turno
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="data-table" id="tablaNd">
                        <thead>
                            <tr>
                                <th>NCF B03</th>
                                <th>Factura Modificada</th>
                                <th>Cliente / RNC</th>
                                <th>Concepto del Cargo</th>
                                <th>Fecha Emisión</th>
                                <th class="text-right">Subtotal</th>
                                <th class="text-right">ITBIS</th>
                                <th class="text-right">Total Cargo</th>
                                <th class="text-center">Estado CxC</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($listaNotasDebito)): ?>
                                <tr>
                                    <td colspan="10" style="text-align: center; padding: 36px; color: var(--text-muted);">
                                        No se han emitido Notas de Débito (NCF B03) en el sistema.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($listaNotasDebito as $nd): ?>
                                    <tr class="fila-nd"
                                        data-ncf="<?= htmlspecialchars($nd['ncf_nota_debito']) ?>"
                                        data-ncf-mod="<?= htmlspecialchars($nd['ncf_factura_modificada']) ?>"
                                        data-factura="<?= htmlspecialchars($nd['factura_numero']) ?>"
                                        data-cliente="<?= htmlspecialchars($nd['cliente_nombre']) ?>"
                                        data-doc="<?= htmlspecialchars($nd['cliente_rnc'] ?? '') ?>"
                                        data-concepto="<?= htmlspecialchars($nd['concepto_cargo']) ?>"
                                        data-estado="<?= htmlspecialchars($nd['cxc_estado']) ?>">
                                        <td>
                                            <span class="badge-ncf-b03"><?= htmlspecialchars($nd['ncf_nota_debito']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge-ncf-mod"><?= htmlspecialchars($nd['ncf_factura_modificada']) ?></span>
                                            <small style="display: block; color: var(--text-muted); font-size: 0.72rem;">Factura #<?= htmlspecialchars($nd['factura_numero']) ?></small>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($nd['cliente_nombre']) ?></strong>
                                            <small style="display: block; color: var(--text-muted); font-size: 0.72rem;"><?= htmlspecialchars($nd['cliente_rnc'] ?: 'Sin RNC') ?></small>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.85rem;" title="<?= htmlspecialchars($nd['concepto_cargo']) ?>">
                                                <?= htmlspecialchars(mb_strimwidth($nd['concepto_cargo'], 0, 45, '...')) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.82rem;"><?= htmlspecialchars($nd['fecha_fmt']) ?></span>
                                            <small style="display: block; color: var(--text-muted); font-size: 0.7rem;">Por: <?= htmlspecialchars($nd['usuario_emisor']) ?></small>
                                        </td>
                                        <td class="text-right" style="font-family: var(--font-mono);">RD$ <?= number_format($nd['monto_subtotal'], 2) ?></td>
                                        <td class="text-right" style="font-family: var(--font-mono);">RD$ <?= number_format($nd['monto_impuestos'], 2) ?></td>
                                        <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: #a66e2e;">
                                            RD$ <?= number_format($nd['monto_total'], 2) ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($nd['cxc_estado'] === 'PAGADA'): ?>
                                                <span class="badge-status pagada" style="background: #eef8f2; color: #2e7d52; padding: 3px 8px; border-radius: 12px; font-weight: 700; font-size: 0.75rem;">
                                                    ● Cobrada
                                                </span>
                                            <?php else: ?>
                                                <span class="badge-status pendiente" style="background: #fdf2e9; color: #d35400; padding: 3px 8px; border-radius: 12px; font-weight: 700; font-size: 0.75rem;">
                                                    ⏳ Pendiente (RD$ <?= number_format($nd['cxc_saldo_pendiente'], 2) ?>)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline" onclick="verDetalleNd(<?= (int)$nd['id'] ?>)" title="Ver detalle fiscal de la Nota de Débito">
                                                🔍 Ver Detalle
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

    <!-- ========================================================================= -->
    <!-- MODAL 1: EMITIR NOTA DE DÉBITO (B03)                                      -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalEmitirNd">
        <div class="modal-dialog modal-lg">
            <div class="modal-header">
                <h3><span>📄</span> Emitir Nota de Débito Fiscal (NCF B03)</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalEmitirNd')">&times;</button>
            </div>
            <form id="formEmitirNd" onsubmit="guardarNotaDebito(event)">
                <div class="modal-body">
                    <!-- FASE 1: Búsqueda y Selección de la Factura Origen -->
                    <div style="background: #fdfaf5; border: 1px solid #ebd9bf; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                        <h4 style="font-size: 0.88rem; color: #7a4e1d; margin-bottom: 8px;">
                            Paso 1: Localizar Factura Origen a Modificar (Cruce Formato 607 DGII)
                        </h4>
                        <div style="position: relative;">
                            <input type="text" id="inputBuscarFactura" class="form-control" placeholder="Buscar por número de factura, NCF (B01, B02) o cliente..." autocomplete="off" oninput="buscarFacturasOrigen()" />
                            <div id="resultadosBusquedaFacturas" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #d4c7b8; border-radius: 6px; box-shadow: 0 6px 16px rgba(0,0,0,0.15); z-index: 100; max-height: 220px; overflow-y: auto;"></div>
                        </div>

                        <!-- Card de Factura Seleccionada -->
                        <div id="cardFacturaSeleccionada" class="factura-selected-card">
                            <input type="hidden" name="factura_id" id="ndFacturaId" value="" />
                            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                <div>
                                    <div style="font-size: 0.95rem; font-weight: 700; color: #251c16;">
                                        Factura #<span id="txtFacNumero">-</span> • NCF: <span id="txtFacNcf" class="badge-ncf-mod">-</span>
                                    </div>
                                    <div style="font-size: 0.82rem; color: #555; margin-top: 3px;">
                                        Cliente: <strong id="txtFacCliente">-</strong> (Doc: <span id="txtFacDoc">-</span>)
                                    </div>
                                    <div style="font-size: 0.78rem; color: #888; margin-top: 2px;">
                                        Fecha: <span id="txtFacFecha">-</span> • Monto Original Facturado: <strong>RD$ <span id="txtFacMonto">0.00</span></strong>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-secondary" onclick="deseleccionarFactura()" style="padding: 3px 8px; font-size: 0.75rem;">
                                    ✕ Cambiar
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- FASE 2: Concepto Legal y Motivo -->
                    <div style="margin-bottom: 14px;">
                        <label class="form-label">Plantilla de Concepto Rápido / Motivo Legal:</label>
                        <select id="selectPresetConcepto" class="form-control" style="margin-bottom: 8px;" onchange="aplicarPresetConcepto()">
                            <option value="">-- Seleccionar motivo estándar --</option>
                            <option value="Penalidad por cheque devuelto sin fondos + comisión bancaria">Penalidad por cheque devuelto sin fondos (Banco)</option>
                            <option value="Recargo por mora e intereses financieros (atraso mayor a 60 días)">Recargo por mora e intereses por atraso</option>
                            <option value="Ajuste por corrección de sub-facturación de precio pactado">Corrección por sub-facturación de precio pactado</option>
                            <option value="OTRO">Otro concepto personalizado</option>
                        </select>

                        <label class="form-label">Concepto Legal DGII <span style="color:red;">*</span> (Se estampará en la Nota de Débito):</label>
                        <textarea name="concepto_cargo" id="ndConceptoCargo" class="form-control" rows="2" placeholder="Describa el motivo legal del recargo para el Formato 607..." required></textarea>
                    </div>

                    <!-- FASE 3: Desglose de Líneas de Cargos -->
                    <div style="background: #ffffff; border: 1px solid var(--border-light); border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h4 style="font-size: 0.88rem; color: #4a3c31; font-weight: 700;">
                                Desglose de Recargos / Cargos Adicionales
                            </h4>
                            <button type="button" class="btn btn-sm btn-outline" onclick="agregarLineaCargo()" style="font-weight: 600;">
                                ➕ Agregar Cargo
                            </button>
                        </div>

                        <!-- Encabezados de columnas -->
                        <div style="display: grid; grid-template-columns: 1.8fr 1.1fr 55px 80px 48px 80px 28px; gap: 6px; font-size: 0.7rem; font-weight: 700; color: #8c8173; text-transform: uppercase; margin-bottom: 4px; padding: 0 6px;">
                            <div>Descripción Cargo <span style="color:red;">*</span></div>
                            <div>Producto (Opcional)</div>
                            <div class="text-center">Cant.</div>
                            <div class="text-right">Precio</div>
                            <div class="text-center">ITBIS</div>
                            <div class="text-right">Total</div>
                            <div></div>
                        </div>

                        <div id="contenedorLineasNd">
                            <!-- Inyectadas dinámicamente -->
                        </div>

                        <!-- Totales calculados -->
                        <div class="nd-totales-strip">
                            <div class="tot-item">
                                <small>Subtotal Bruto</small>
                                <strong id="lblNdSubtotal">RD$ 0.00</strong>
                            </div>
                            <div class="tot-item">
                                <small>ITBIS (18%)</small>
                                <strong id="lblNdImpuestos">RD$ 0.00</strong>
                            </div>
                            <div class="tot-item">
                                <small>Total Nota de Débito (B03)</small>
                                <strong id="lblNdTotal" style="color: #a66e2e;">RD$ 0.00</strong>
                            </div>
                        </div>
                    </div>

                    <!-- FASE 4: Condiciones de CxC y Autorización con PIN -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; background: #faf7f2; border: 1px solid #ebd9bf; border-radius: 8px; padding: 12px;">
                        <div class="form-group">
                            <label class="form-label">Plazo de Cobro (Días de Crédito):</label>
                            <input type="number" name="dias_credito" id="ndDiasCredito" class="form-control" value="30" min="1" max="180" />
                        </div>
                        <div class="form-group">
                            <label class="form-label">Supervisor Aprobador <span style="color:red;">*</span>:</label>
                            <select name="supervisor_id" id="ndSupervisorId" class="form-control" required>
                                <option value="">-- Seleccionar supervisor --</option>
                                <?php foreach ($supervisoresList as $sup): ?>
                                    <option value="<?= htmlspecialchars($sup['usuario_id']) ?>">
                                        <?= htmlspecialchars($sup['nombre_razon_social']) ?> (<?= htmlspecialchars($sup['username']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">PIN de Supervisor <span style="color:red;">*</span>:</label>
                            <input type="password" name="pin_autorizacion" id="ndPinAutorizacion" class="form-control" placeholder="••••" maxlength="6" required autocomplete="off" />
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalEmitirNd')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarNd" style="background: #a66e2e; color: #ffffff !important; font-weight: 700;">
                        <span>🔒</span> Emitir Nota de Débito Fiscal (B03)
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 2: DETALLE DE NOTA DE DÉBITO                                        -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalDetalleNd">
        <div class="modal-dialog modal-lg">
            <div class="modal-header">
                <h3><span>🔍</span> Detalle Fiscal de Nota de Débito</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalDetalleNd')">&times;</button>
            </div>
            <div class="modal-body" id="bodyDetalleNd">
                <!-- Inyectado dinámicamente -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalDetalleNd')">Cerrar</button>
            </div>
        </div>
    </div>

    <script src="notas_debito.js?v=1"></script>
</body>

</html>
