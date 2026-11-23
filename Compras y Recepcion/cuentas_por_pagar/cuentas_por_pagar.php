<?php
/**
 * ============================================================================
 * Módulo 5: Compras y Recepción • Proceso 2: Cuentas por Pagar (CxP)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/cuentas_por_pagar/cuentas_por_pagar.php
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: ../../Login y Menu/login.php");
    exit;
}

if (!usuarioPuedeAccederCxp()) {
    header("Location: ../../Login y Menu/menu.php");
    exit;
}

// Variables iniciales
$kpiTotalDeuda     = 0.00;
$kpiVencidasCnt    = 0;
$kpiVencidasMto    = 0.00;
$kpiVencenHoyCnt   = 0;
$kpiVencenHoyMto   = 0.00;
$kpiAlDiaCnt       = 0;
$kpiAlDiaMto       = 0.00;
$kpiEgresosMesMto  = 0.00;

$listaCxp          = [];
$listaEgresos      = [];
$listaAjustes      = [];
$proveedoresList   = [];
$monedasList       = [];
$cuentasBancarias  = [];
$retencionesList   = [];
$metodosPagoList   = [];
$supervisoresList  = [];

if ($pdo) {
    // 1. KPIs Financieros
    try {
        $stmtKpi = $pdo->query("
            SELECT 
                COALESCE(SUM(saldo_pendiente), 0) AS total_deuda,
                COUNT(CASE WHEN fecha_vencimiento < CURRENT_DATE THEN 1 END) AS vencidas_cnt,
                COALESCE(SUM(CASE WHEN fecha_vencimiento < CURRENT_DATE THEN saldo_pendiente ELSE 0 END), 0) AS vencidas_mto,
                COUNT(CASE WHEN fecha_vencimiento = CURRENT_DATE THEN 1 END) AS hoy_cnt,
                COALESCE(SUM(CASE WHEN fecha_vencimiento = CURRENT_DATE THEN saldo_pendiente ELSE 0 END), 0) AS hoy_mto,
                COUNT(CASE WHEN fecha_vencimiento > CURRENT_DATE THEN 1 END) AS al_dia_cnt,
                COALESCE(SUM(CASE WHEN fecha_vencimiento > CURRENT_DATE THEN saldo_pendiente ELSE 0 END), 0) AS al_dia_mto
            FROM compras.cuentas_por_pagar
            WHERE estado IN ('PENDIENTE', 'PAGADA_PARCIAL')
        ");
        $kRow = $stmtKpi ? $stmtKpi->fetch(PDO::FETCH_ASSOC) : null;
        if ($kRow) {
            $kpiTotalDeuda   = (float)$kRow['total_deuda'];
            $kpiVencidasCnt  = (int)$kRow['vencidas_cnt'];
            $kpiVencidasMto  = (float)$kRow['vencidas_mto'];
            $kpiVencenHoyCnt = (int)$kRow['hoy_cnt'];
            $kpiVencenHoyMto = (float)$kRow['hoy_mto'];
            $kpiAlDiaCnt     = (int)$kRow['al_dia_cnt'];
            $kpiAlDiaMto     = (float)$kRow['al_dia_mto'];
        }
    } catch (Throwable $e) {}

    // Egresos del mes en curso
    try {
        $stmtEgrMes = $pdo->query("
            SELECT COALESCE(SUM(monto_total_pagado), 0)
            FROM compras.comprobantes_egreso_cxp
            WHERE DATE_TRUNC('month', fecha_pago) = DATE_TRUNC('month', CURRENT_DATE)
        ");
        $kpiEgresosMesMto = $stmtEgrMes ? (float)$stmtEgrMes->fetchColumn() : 0.00;
    } catch (Throwable $e) {}

    // 2. Catálogos
    try {
        $stmtPr = $pdo->query("
            SELECT pr.persona_id, pr.codigo_proveedor, pr.dias_credito_habitual,
                   p.nombre_razon_social, p.documento_identidad, p.telefono_principal
            FROM identidad.proveedores pr
            JOIN identidad.personas p ON p.persona_id = pr.persona_id
            ORDER BY p.nombre_razon_social ASC
        ");
        $proveedoresList = $stmtPr ? $stmtPr->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    try {
        $stmtMon = $pdo->query("
            SELECT id, codigo_iso, nombre, simbolo, tasa_cambio_referencia, es_moneda_base
            FROM tesoreria.monedas
            WHERE activa = true
            ORDER BY id ASC
        ");
        $monedasList = $stmtMon ? $stmtMon->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    try {
        $stmtCb = $pdo->query("
            SELECT id, nombre_banco, numero_cuenta, tipo_cuenta, moneda_id
            FROM tesoreria.cuentas_bancarias
            WHERE activa = true
            ORDER BY nombre_banco ASC
        ");
        $cuentasBancarias = $stmtCb ? $stmtCb->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    try {
        $stmtRet = $pdo->query("
            SELECT id, siglas, nombre, base_calculo, porcentaje
            FROM tesoreria.retenciones
            WHERE activo = true
            ORDER BY id ASC
        ");
        $retencionesList = $stmtRet ? $stmtRet->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    try {
        $stmtMp = $pdo->query("
            SELECT id, codigo, descripcion, requiere_referencia
            FROM ventas.metodos_pago
            WHERE activo = true AND codigo IN ('EFECTIVO', 'TRANSFERENCIA', 'CHEQUE')
            ORDER BY id ASC
        ");
        $metodosPagoList = $stmtMp ? $stmtMp->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    try {
        $supervisoresList = obtenerSupervisoresAutorizados($pdo);
    } catch (Throwable $e) {}

    // 3. Cuentas por Pagar (Fases 1 y 2: Listado y Antigüedad)
    try {
        $stmtCxp = $pdo->query("
            SELECT cxp.id,
                   cxp.proveedor_id,
                   cxp.recepcion_compra_id,
                   cxp.monto_original,
                   cxp.saldo_pendiente,
                   cxp.fecha_emision,
                   cxp.fecha_vencimiento,
                   cxp.estado,
                   cxp.moneda_id,
                   cxp.tasa_cambio,
                   m.simbolo AS moneda_simbolo,
                   p.nombre_razon_social AS proveedor_nombre,
                   p.documento_identidad AS proveedor_rnc,
                   pr.codigo_proveedor,
                   COALESCE(rc.numero_factura_proveedor, cxp.numero_factura_servicio, 'S/F') AS numero_factura,
                   COALESCE(rc.ncf_proveedor, cxp.ncf_servicio, 'N/A') AS ncf,
                   COALESCE(cxp.concepto_servicio, 'Recepción de Mercancía #' || rc.id) AS concepto,
                   (CURRENT_DATE - cxp.fecha_vencimiento) AS dias_diferencia
            FROM compras.cuentas_por_pagar cxp
            JOIN identidad.proveedores pr ON pr.persona_id = cxp.proveedor_id
            JOIN identidad.personas p ON p.persona_id = pr.persona_id
            JOIN tesoreria.monedas m ON m.id = cxp.moneda_id
            LEFT JOIN compras.recepciones_compra rc ON rc.id = cxp.recepcion_compra_id
            ORDER BY 
                CASE WHEN cxp.estado IN ('PENDIENTE', 'PAGADA_PARCIAL') THEN 0 ELSE 1 END,
                cxp.fecha_vencimiento ASC,
                cxp.id DESC
        ");
        $listaCxp = $stmtCxp ? $stmtCxp->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    // 4. Historial de Egresos / Desembolsos (Fase 3)
    try {
        $stmtEgr = $pdo->query("
            SELECT ce.id,
                   ce.numero_comprobante,
                   ce.monto_total_pagado,
                   ce.fecha_pago,
                   m.simbolo AS moneda_simbolo,
                   p.nombre_razon_social AS proveedor_nombre,
                   p.documento_identidad AS proveedor_rnc,
                   u.username AS usuario_registro,
                   (
                       SELECT COUNT(*) 
                       FROM compras.comprobante_egreso_detalles ced 
                       WHERE ced.comprobante_egreso_id = ce.id
                   ) AS total_facturas_canceladas,
                   (
                       SELECT COALESCE(SUM(monto_retenciones), 0)
                       FROM compras.comprobante_egreso_detalles ced
                       WHERE ced.comprobante_egreso_id = ce.id
                   ) AS total_retenciones
            FROM compras.comprobantes_egreso_cxp ce
            JOIN identidad.proveedores pr ON pr.persona_id = ce.proveedor_id
            JOIN identidad.personas p ON p.persona_id = pr.persona_id
            JOIN tesoreria.monedas m ON m.id = ce.moneda_id
            JOIN seguridad.usuarios u ON u.usuario_id = ce.usuario_registro_id
            ORDER BY ce.fecha_pago DESC, ce.id DESC
            LIMIT 50
        ");
        $listaEgresos = $stmtEgr ? $stmtEgr->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    // 5. Historial de Ajustes y Descuentos (Fase 4)
    try {
        $stmtAj = $pdo->query("
            SELECT aj.id,
                   aj.cuenta_por_pagar_id,
                   aj.tipo_ajuste,
                   aj.ncf_nota_credito_proveedor,
                   aj.monto_ajuste,
                   aj.saldo_anterior,
                   aj.saldo_restante,
                   aj.justificacion,
                   aj.fecha_ajuste,
                   u.username AS supervisor_username,
                   sup_p.nombre_razon_social AS supervisor_nombre,
                   p.nombre_razon_social AS proveedor_nombre,
                   COALESCE(rc.numero_factura_proveedor, cxp.numero_factura_servicio, 'S/F') AS numero_factura
            FROM compras.ajustes_cxp aj
            JOIN compras.cuentas_por_pagar cxp ON cxp.id = aj.cuenta_por_pagar_id
            JOIN identidad.proveedores pr ON pr.persona_id = cxp.proveedor_id
            JOIN identidad.personas p ON p.persona_id = pr.persona_id
            LEFT JOIN compras.recepciones_compra rc ON rc.id = cxp.recepcion_compra_id
            JOIN seguridad.usuarios u ON u.usuario_id = aj.supervisor_id
            JOIN identidad.personas sup_p ON sup_p.persona_id = u.persona_id
            ORDER BY aj.fecha_ajuste DESC, aj.id DESC
            LIMIT 50
        ");
        $listaAjustes = $stmtAj ? $stmtAj->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Cuentas por Pagar (CxP)</title>
    <link rel="stylesheet" href="../styles.css?v=31" />
    <link rel="stylesheet" href="styles.css?v=7" />
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
                <span class="brand-subtitle">Cuentas por Pagar (CxP)</span>
            </a>
        </div>
        <div class="topbar-actions">
            <div class="topbar-user">
                <div class="user-avatar"><?= htmlspecialchars($sesionAvatarInitials ?? 'AD') ?></div>
                <div class="user-info-col">
                    <div class="user-name"><?= htmlspecialchars($sesionNombreCompleto ?? 'Usuario') ?></div>
                    <div class="user-sub-line">
                        <span class="user-role-text"><?= htmlspecialchars($sesionRol ?? 'Administrador') ?></span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="app-container app-layout">
        <!-- SIDEBAR -->
        <?php include __DIR__ . '/../../sidebar.php'; ?>

        <!-- MAIN CONTENT -->
        <main class="main-content">

            <!-- BANNER DE ENCABEZADO -->
            <section class="welcome-banner" style="margin-bottom: 20px;">
                <div class="welcome-text">
                    <h1>🏛️ Cuentas por Pagar (CxP) y Retenciones DGII</h1>
                    <p>Administración de obligaciones hacia suplidores, proyección de tesorería, retenciones fiscales y desembolsos auditados.</p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-secondary" onclick="abrirModalGastoManual()" style="background: #ffffff; color: var(--text-dark); border: 1px solid var(--border-light);">
                        <span>➕</span> Registrar Gasto / Servicio
                    </button>
                    <button type="button" class="btn btn-primary" onclick="abrirModalDesembolso()" id="btnDesembolsoTop">
                        <span>💳</span> Liquidar Selección (<span id="countSeleccionadasTop">0</span>)
                    </button>
                </div>
            </section>

            <!-- TARJETAS DE INDICADORES (KPIS: 4 TARJETAS) -->
            <div class="cxp-kpi-container">
                <div class="cxp-kpi-card">
                    <div class="cxp-kpi-icon gold">💰</div>
                    <div class="cxp-kpi-data">
                        <h4>Deuda Total Pendiente</h4>
                        <div class="cxp-kpi-value">RD$ <?= number_format($kpiTotalDeuda, 2) ?></div>
                        <div class="cxp-kpi-sub">Obligaciones comerciales y servicios</div>
                    </div>
                </div>

                <div class="cxp-kpi-card">
                    <div class="cxp-kpi-icon red">⚠️</div>
                    <div class="cxp-kpi-data">
                        <h4>Facturas Vencidas</h4>
                        <div class="cxp-kpi-value" style="color: #b53d32;"><?= $kpiVencidasCnt ?> facturas</div>
                        <div class="cxp-kpi-sub">RD$ <?= number_format($kpiVencidasMto, 2) ?> en mora</div>
                    </div>
                </div>

                <div class="cxp-kpi-card">
                    <div class="cxp-kpi-icon green">📅</div>
                    <div class="cxp-kpi-data">
                        <h4>Por Vencer / En Plazo</h4>
                        <div class="cxp-kpi-value" style="color: #2e7d52;"><?= ($kpiVencenHoyCnt + $kpiAlDiaCnt) ?> facturas</div>
                        <div class="cxp-kpi-sub">RD$ <?= number_format($kpiVencenHoyMto + $kpiAlDiaMto, 2) ?> vigentes</div>
                    </div>
                </div>

                <div class="cxp-kpi-card">
                    <div class="cxp-kpi-icon blue">🏦</div>
                    <div class="cxp-kpi-data">
                        <h4>Desembolsos del Mes</h4>
                        <div class="cxp-kpi-value" style="color: #1e6091;">RD$ <?= number_format($kpiEgresosMesMto, 2) ?></div>
                        <div class="cxp-kpi-sub">Egresos bancarios liquidados</div>
                    </div>
                </div>
            </div>

            <!-- TABS DE NAVEGACIÓN -->
            <div class="cxp-tabs">
                <button type="button" class="cxp-tab-btn active" onclick="cambiarTab('tab-obligaciones', this)">
                    <span>📋</span> Obligaciones y Antigüedad de Saldos
                </button>
                <button type="button" class="cxp-tab-btn" onclick="cambiarTab('tab-egresos', this)">
                    <span>💳</span> Historial de Comprobantes de Egreso
                </button>
                <button type="button" class="cxp-tab-btn" onclick="cambiarTab('tab-ajustes', this)">
                    <span>⚖️</span> Ajustes, Descuentos y Anulaciones
                </button>
            </div>

            <!-- TAB 1: OBLIGACIONES Y ANTIGÜEDAD DE SALDOS -->
            <div id="tab-obligaciones" class="cxp-tab-content active">
                
                <!-- CARD DE FILTROS Y BÚSQUEDA -->
                <div class="table-card" style="margin-bottom: 16px; padding: 14px 18px;">
                    <div style="display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end;">
                        <div style="flex: 1; min-width: 240px;">
                            <label class="form-label" style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Buscar Factura, NCF o Concepto</label>
                            <input type="text" id="filtroBusqueda" class="form-control" placeholder="Escriba número de factura, NCF, concepto..." oninput="aplicarFiltrosCxp()" />
                        </div>

                        <div style="width: 260px;">
                            <label class="form-label" style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Filtrar por Suplidor</label>
                            <select id="filtroProveedor" class="form-control" onchange="aplicarFiltrosCxp()">
                                <option value="">-- Todos los Proveedores --</option>
                                <?php foreach ($proveedoresList as $pr): ?>
                                    <option value="<?= htmlspecialchars($pr['persona_id']) ?>">
                                        <?= htmlspecialchars($pr['nombre_razon_social']) ?> (<?= htmlspecialchars($pr['documento_identidad']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="width: 220px;">
                            <label class="form-label" style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Estado de Deuda</label>
                            <select id="filtroEstado" class="form-control" onchange="aplicarFiltrosCxp()">
                                <option value="PENDIENTES">Solo Pendientes y Parciales</option>
                                <option value="VENCIDAS">Solo Vencidas</option>
                                <option value="HOY">Vencen Hoy</option>
                                <option value="TODAS">Todas (Incluye Pagadas/Anuladas)</option>
                            </select>
                        </div>

                        <div>
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('filtroProveedor').value=''; document.getElementById('filtroEstado').value='PENDIENTES'; document.getElementById('filtroBusqueda').value=''; aplicarFiltrosCxp();">
                                Limpiar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- TABLA DE CUENTAS POR PAGAR (TABLA FORMATEADA) -->
                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Cartera de Cuentas por Pagar</h2>
                            <span class="badge-count" id="badgeTotalCxp"><?= count($listaCxp) ?> obligaciones</span>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">
                            Selecciona una o varias facturas del mismo suplidor para liquidar en lote
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table" id="tablaCxp">
                            <thead>
                                <tr>
                                    <th style="width: 44px; text-align: center;">
                                        <input type="checkbox" id="chkSelectAll" onchange="toggleSelectAll(this)" title="Seleccionar visibles" />
                                    </th>
                                    <th>Documento / NCF</th>
                                    <th>Proveedor / Suplidor</th>
                                    <th>Concepto / Origen</th>
                                    <th>Emisión</th>
                                    <th>Vencimiento</th>
                                    <th>Antigüedad</th>
                                    <th class="text-right">Monto Orig.</th>
                                    <th class="text-right">Saldo Pendiente</th>
                                    <th class="text-center">Estado</th>
                                    <th class="text-center" style="min-width: 120px;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaCxp)): ?>
                                    <tr>
                                        <td colspan="11" style="text-align: center; padding: 36px; color: var(--text-muted);">
                                            No hay cuentas por pagar registradas con los filtros seleccionados.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaCxp as $cxp): 
                                        $diasDif = (int)$cxp['dias_diferencia'];
                                        $esPendiente = in_array($cxp['estado'], ['PENDIENTE', 'PAGADA_PARCIAL']);
                                        
                                        // Semáforo de antigüedad
                                        if ($cxp['estado'] === 'ANULADA') {
                                            $badgeAntiguedad = '<span class="badge-anulada">ANULADA</span>';
                                        } elseif ($cxp['estado'] === 'PAGADA') {
                                            $badgeAntiguedad = '<span class="badge-pagada">SALDADA</span>';
                                        } elseif ($diasDif > 0) {
                                            $badgeAntiguedad = '<span class="badge-vencida">⚠️ Vencida +' . $diasDif . ' d</span>';
                                        } elseif ($diasDif === 0) {
                                            $badgeAntiguedad = '<span class="badge-hoy">⏰ Vence Hoy</span>';
                                        } else {
                                            $diasRestantes = abs($diasDif);
                                            $badgeAntiguedad = '<span class="badge-al-dia">● ' . $diasRestantes . ' d restantes</span>';
                                        }
                                    ?>
                                        <tr class="fila-cxp" 
                                            data-id="<?= $cxp['id'] ?>"
                                            data-proveedor-id="<?= htmlspecialchars($cxp['proveedor_id']) ?>"
                                            data-proveedor-nombre="<?= htmlspecialchars($cxp['proveedor_nombre']) ?>"
                                            data-factura="<?= htmlspecialchars($cxp['numero_factura']) ?>"
                                            data-ncf="<?= htmlspecialchars($cxp['ncf']) ?>"
                                            data-saldo="<?= (float)$cxp['saldo_pendiente'] ?>"
                                            data-estado="<?= htmlspecialchars($cxp['estado']) ?>"
                                            data-vencido="<?= ($diasDif > 0) ? '1' : '0' ?>"
                                            data-hoy="<?= ($diasDif === 0) ? '1' : '0' ?>">
                                            
                                            <td style="text-align: center;">
                                                <?php if ($esPendiente): ?>
                                                    <input type="checkbox" class="chk-cxp-item" 
                                                           value="<?= $cxp['id'] ?>" 
                                                           data-proveedor="<?= htmlspecialchars($cxp['proveedor_id']) ?>"
                                                           data-saldo="<?= (float)$cxp['saldo_pendiente'] ?>"
                                                           onchange="alCambiarSeleccionItem(this)" />
                                                <?php else: ?>
                                                    <span style="color: #ccc;">—</span>
                                                <?php endif; ?>
                                            </td>

                                            <td style="white-space: nowrap;">
                                                <div style="font-weight: 700; color: var(--text-dark); font-size: 0.88rem;">
                                                    <?= htmlspecialchars($cxp['numero_factura']) ?>
                                                </div>
                                                <div class="cell-sub" style="font-family: var(--font-mono); font-size: 0.76rem; color: var(--accent-gold); font-weight: 600;">
                                                    NCF: <?= htmlspecialchars($cxp['ncf']) ?>
                                                </div>
                                            </td>

                                            <td>
                                                <strong style="color: var(--text-dark);"><?= htmlspecialchars($cxp['proveedor_nombre']) ?></strong>
                                                <div class="cell-sub">
                                                    RNC: <?= htmlspecialchars($cxp['proveedor_rnc']) ?>
                                                </div>
                                            </td>

                                            <td>
                                                <div style="max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($cxp['concepto']) ?>">
                                                    <?= htmlspecialchars($cxp['concepto']) ?>
                                                </div>
                                                <div class="cell-sub" style="color: var(--accent-gold); font-weight: 600;">
                                                    <?= $cxp['recepcion_compra_id'] ? '📦 Mercancía (Recep. #' . $cxp['recepcion_compra_id'] . ')' : '📑 Servicio Directo' ?>
                                                </div>
                                            </td>

                                            <td style="white-space: nowrap; font-size: 0.82rem;">
                                                <?= htmlspecialchars($cxp['fecha_emision']) ?>
                                            </td>

                                            <td style="white-space: nowrap; font-weight: 600; font-size: 0.82rem;">
                                                <?= htmlspecialchars($cxp['fecha_vencimiento']) ?>
                                            </td>

                                            <td><?= $badgeAntiguedad ?></td>

                                            <td class="text-right" style="font-family: var(--font-mono); font-size: 0.88rem;">
                                                RD$ <?= number_format($cxp['monto_original'], 2) ?>
                                            </td>

                                            <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; font-size: 0.92rem; color: <?= $esPendiente ? '#b53d32' : '#2e7d52' ?>;">
                                                RD$ <?= number_format($cxp['saldo_pendiente'], 2) ?>
                                            </td>

                                            <td class="text-center">
                                                <?php if ($cxp['estado'] === 'PENDIENTE'): ?>
                                                    <span class="badge-hoy">PENDIENTE</span>
                                                <?php elseif ($cxp['estado'] === 'PAGADA_PARCIAL'): ?>
                                                    <span class="badge-al-dia">PARCIAL</span>
                                                <?php elseif ($cxp['estado'] === 'PAGADA'): ?>
                                                    <span class="badge-pagada">PAGADA</span>
                                                <?php else: ?>
                                                    <span class="badge-anulada">ANULADA</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-center" style="white-space: nowrap;">
                                                <?php if ($esPendiente): ?>
                                                    <button type="button" class="btn btn-sm btn-secondary" 
                                                            onclick="abrirModalAjuste(<?= $cxp['id'] ?>, '<?= htmlspecialchars(addslashes($cxp['proveedor_nombre'])) ?>', <?= (float)$cxp['saldo_pendiente'] ?>)"
                                                            title="Ajuste, Descuento o Anulación con Supervisor" style="padding: 4px 8px; margin-right: 4px;">
                                                        <span>⚖️</span>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-primary"
                                                            onclick="liquidarFacturaIndividual(<?= $cxp['id'] ?>, '<?= htmlspecialchars($cxp['proveedor_id']) ?>')"
                                                            title="Liquidar de inmediato" style="padding: 4px 10px;">
                                                        <span>💳</span> Pagar
                                                    </button>
                                                <?php else: ?>
                                                    <span style="font-size: 0.75rem; color: #888;">Cerrada</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Barra Flotante de Selección Consolidada -->
                <div class="cxp-selection-bar" id="cxpSelectionBar" style="display: none;">
                    <div class="cxp-selection-info">
                        <div class="cxp-selection-count">
                            <span id="txtSeleccionadasCount">0</span> facturas seleccionadas
                        </div>
                        <div style="font-size: 0.85rem; color: #c8beb2;">
                            Proveedor: <strong id="txtSeleccionadasProveedor" style="color: #fff;">—</strong>
                        </div>
                        <div class="cxp-selection-total">
                            Total a Liquidar: <span id="txtSeleccionadasTotal">RD$ 0.00</span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button type="button" class="btn" onclick="limpiarSeleccion()" style="background: transparent; color: #e2dacb; border: 1px solid rgba(255,255,255,0.3); padding: 8px 14px;">
                            <span>✕</span> Cancelar Selección
                        </button>
                        <button type="button" class="btn btn-primary" onclick="abrirModalDesembolso()" style="background: #a66e2e; color: #ffffff; font-weight: 700; padding: 8px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.3);">
                            <span>💳</span> Pagar Selección (Desembolso)
                        </button>
                    </div>
                </div>
            </div>

            <!-- TAB 2: HISTORIAL DE COMPROBANTES DE EGRESO (DESEMBOLSOS) -->
            <div id="tab-egresos" class="cxp-tab-content">
                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Registro de Egresos y Liquidaciones de CxP</h2>
                            <span class="badge-count"><?= count($listaEgresos) ?> comprobantes</span>
                        </div>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">Liquidaciones externas (Bóveda / Bancos) con sesion_caja_id = NULL</span>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>No. Comprobante</th>
                                    <th>Fecha Pago</th>
                                    <th>Proveedor</th>
                                    <th>Facturas Saldadas</th>
                                    <th class="text-right">Retenciones DGII</th>
                                    <th class="text-right">Total Desembolsado</th>
                                    <th>Usuario Registro</th>
                                    <th class="text-center">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaEgresos)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 32px; color: var(--text-muted);">
                                            No se han registrado comprobantes de egreso todavía.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaEgresos as $egr): ?>
                                        <tr>
                                            <td>
                                                <strong class="code-inline" style="font-size: 0.88rem; color: var(--accent-gold);">
                                                    <?= htmlspecialchars($egr['numero_comprobante']) ?>
                                                </strong>
                                            </td>
                                            <td style="font-size: 0.84rem;"><?= htmlspecialchars(substr($egr['fecha_pago'], 0, 16)) ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($egr['proveedor_nombre']) ?></strong>
                                                <div class="cell-sub">RNC: <?= htmlspecialchars($egr['proveedor_rnc']) ?></div>
                                            </td>
                                            <td>
                                                <span class="badge-al-dia"><?= $egr['total_facturas_canceladas'] ?> docs</span>
                                            </td>
                                            <td class="text-right" style="font-family: var(--font-mono); color: #b78103;">
                                                RD$ <?= number_format($egr['total_retenciones'], 2) ?>
                                            </td>
                                            <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: #2e7d52;">
                                                RD$ <?= number_format($egr['monto_total_pagado'], 2) ?>
                                            </td>
                                            <td style="font-size: 0.82rem;"><?= htmlspecialchars($egr['usuario_registro']) ?></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-secondary" onclick="verDetalleEgreso(<?= $egr['id'] ?>)">
                                                    <span>👁️</span> Ver Detalle
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 3: HISTORIAL DE AJUSTES Y ANULACIONES -->
            <div id="tab-ajustes" class="cxp-tab-content">
                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Auditoría de Ajustes, Descuentos y Exoneraciones</h2>
                            <span class="badge-count"><?= count($listaAjustes) ?> ajustes</span>
                        </div>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">Autorizaciones registradas con PIN de Supervisor</span>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Factura</th>
                                    <th>Proveedor</th>
                                    <th>Tipo de Ajuste</th>
                                    <th>NCF Proveedor</th>
                                    <th class="text-right">Monto Ajustado</th>
                                    <th class="text-right">Saldo Restante</th>
                                    <th>Supervisor</th>
                                    <th>Justificación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaAjustes)): ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center; padding: 32px; color: var(--text-muted);">
                                            No se han registrado ajustes a cuentas por pagar.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaAjustes as $aj): ?>
                                        <tr>
                                            <td style="font-size: 0.82rem; white-space: nowrap;"><?= htmlspecialchars(substr($aj['fecha_ajuste'], 0, 16)) ?></td>
                                            <td>
                                                <strong style="color: var(--text-dark);"><?= htmlspecialchars($aj['numero_factura']) ?></strong>
                                            </td>
                                            <td style="font-size: 0.84rem;"><?= htmlspecialchars($aj['proveedor_nombre']) ?></td>
                                            <td>
                                                <?php if ($aj['tipo_ajuste'] === 'ANULACION'): ?>
                                                    <span class="badge-anulada">ANULACIÓN</span>
                                                <?php elseif ($aj['tipo_ajuste'] === 'DESCUENTO_PRONTO_PAGO'): ?>
                                                    <span class="badge-al-dia">DESC. PRONTO PAGO</span>
                                                <?php elseif ($aj['tipo_ajuste'] === 'NOTA_CREDITO_PROVEEDOR'): ?>
                                                    <span class="badge-hoy">NOTA CRÉDITO PROV.</span>
                                                <?php else: ?>
                                                    <span class="badge-pagada"><?= htmlspecialchars($aj['tipo_ajuste']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-family: var(--font-mono); font-size: 0.8rem;">
                                                <?= htmlspecialchars($aj['ncf_nota_credito_proveedor'] ?: '—') ?>
                                            </td>
                                            <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: #b53d32;">
                                                RD$ <?= number_format($aj['monto_ajuste'], 2) ?>
                                            </td>
                                            <td class="text-right" style="font-family: var(--font-mono);">
                                                RD$ <?= number_format($aj['saldo_restante'], 2) ?>
                                            </td>
                                            <td style="font-size: 0.82rem;">
                                                <strong><?= htmlspecialchars($aj['supervisor_username']) ?></strong>
                                                <div class="cell-sub"><?= htmlspecialchars($aj['supervisor_nombre']) ?></div>
                                            </td>
                                            <td style="font-size: 0.82rem; max-width: 220px;" title="<?= htmlspecialchars($aj['justificacion']) ?>">
                                                <?= htmlspecialchars($aj['justificacion']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 1: REGISTRO MANUAL DE GASTO / SERVICIO DIRECTO                      -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalGastoManual">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3><span>📑</span> Registrar Obligación por Gasto / Servicio Directo</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalGastoManual')">&times;</button>
            </div>
            <form id="formGastoManual" onsubmit="guardarGastoManual(event)">
                <div class="modal-body">
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 16px;">
                        Para obligaciones financieras sin mercancía en almacén (ej. alquiler de local, electricidad Edesur, agua, internet, honorarios contables). Nace en estado <strong>PENDIENTE</strong> con `recepcion_compra_id = NULL`.
                    </p>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                        <div class="form-group">
                            <label class="form-label">Suplidor / Prestador <span style="color:red;">*</span></label>
                            <select name="proveedor_id" class="form-control" required onchange="alCambiarProveedorGasto(this)">
                                <option value="">-- Seleccionar Suplidor --</option>
                                <?php foreach ($proveedoresList as $pr): ?>
                                    <option value="<?= htmlspecialchars($pr['persona_id']) ?>" data-dias="<?= (int)$pr['dias_credito_habitual'] ?>">
                                        <?= htmlspecialchars($pr['nombre_razon_social']) ?> (RNC: <?= htmlspecialchars($pr['documento_identidad']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Condición de Pago <span style="color:red;">*</span></label>
                            <select name="condicion_pago" id="condicionPagoGasto" class="form-control" required onchange="alCambiarCondicionGasto()">
                                <option value="CONTADO">CONTADO (Vence Mismo Día)</option>
                                <option value="CREDITO">CRÉDITO (Aplica Días de Crédito)</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                        <div class="form-group">
                            <label class="form-label">No. Factura Proveedor</label>
                            <input type="text" name="numero_factura_servicio" class="form-control" placeholder="Ej: FAC-98210" />
                        </div>

                        <div class="form-group">
                            <label class="form-label">NCF Proveedor (DGII 606)</label>
                            <input type="text" name="ncf_servicio" class="form-control" placeholder="Ej: B0100000452" maxlength="13" style="text-transform: uppercase;" />
                        </div>

                        <div class="form-group">
                            <label class="form-label">Días de Crédito</label>
                            <input type="number" name="dias_credito" id="diasCreditoGasto" class="form-control" value="0" min="0" />
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                        <div class="form-group">
                            <label class="form-label">Fecha Emisión <span style="color:red;">*</span></label>
                            <input type="date" name="fecha_emision" class="form-control" value="<?= date('Y-m-d') ?>" required />
                        </div>

                        <div class="form-group">
                            <label class="form-label">Moneda <span style="color:red;">*</span></label>
                            <select name="moneda_id" class="form-control" required>
                                <?php foreach ($monedasList as $m): ?>
                                    <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['codigo_iso']) ?> (<?= htmlspecialchars($m['simbolo']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Monto Total Facturado <span style="color:red;">*</span></label>
                            <input type="number" step="0.01" name="monto_original" class="form-control" placeholder="0.00" required style="font-weight: 700; font-family: var(--font-mono);" />
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Concepto Detallado del Gasto / Servicio <span style="color:red;">*</span></label>
                        <textarea name="concepto_servicio" class="form-control" rows="2" placeholder="Ej: Factura de electricidad mes de Noviembre 2026 local principal..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalGastoManual')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarGasto">Guardar Obligación</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 2: LIQUIDACIÓN DE DESEMBOLSO CONSOLIDADO (SPLIT Y RETENCIONES)      -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalDesembolso">
        <div class="modal-dialog modal-lg">
            <div class="modal-header">
                <h3><span>💳</span> Ejecución de Desembolso y Liquidación Consolidada</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalDesembolso')">&times;</button>
            </div>
            <form id="formDesembolso" onsubmit="procesarLiquidacionDesembolso(event)">
                <div class="modal-body">
                    <!-- Resumen del Proveedor -->
                    <div style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <div style="font-size: 0.76rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Proveedor Seleccionado</div>
                            <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-dark);" id="modalDesembolsoProveedorNombre">—</div>
                        </div>
                        <div style="display: flex; gap: 20px;">
                            <div>
                                <div style="font-size: 0.74rem; color: var(--text-muted); text-transform: uppercase;">Total Bruto</div>
                                <div style="font-size: 1.15rem; font-weight: 700; font-family: var(--font-mono);" id="modalTotalBrutoFacturas">RD$ 0.00</div>
                            </div>
                            <div>
                                <div style="font-size: 0.74rem; color: #b78103; text-transform: uppercase;">Retenciones DGII</div>
                                <div style="font-size: 1.15rem; font-weight: 700; font-family: var(--font-mono); color: #b78103;" id="modalTotalRetenciones">RD$ 0.00</div>
                            </div>
                            <div>
                                <div style="font-size: 0.74rem; color: #2e7d52; text-transform: uppercase;">Neto a Desembolsar</div>
                                <div style="font-size: 1.25rem; font-weight: 700; font-family: var(--font-mono); color: #2e7d52;" id="modalTotalNetoDesembolsar">RD$ 0.00</div>
                            </div>
                        </div>
                    </div>

                    <!-- 1. Facturas Seleccionadas para Liquidar -->
                    <h4 style="font-size: 0.92rem; font-weight: 700; margin-bottom: 10px; color: var(--text-dark);">
                        1. Documentos y Facturas a Cancelar
                    </h4>
                    <div class="table-wrapper" style="margin-bottom: 20px; border: 1px solid var(--border-light); border-radius: 6px;">
                        <table class="data-table" style="font-size: 0.85rem;" id="tablaFacturasDesembolso">
                            <thead>
                                <tr>
                                    <th>Documento</th>
                                    <th>NCF</th>
                                    <th class="text-right">Saldo Actual</th>
                                    <th class="text-right" style="width: 140px;">Total a Cancelar</th>
                                    <th class="text-right" style="width: 120px;">Retención DGII</th>
                                    <th class="text-right" style="width: 130px;">Neto Desembolso</th>
                                    <th class="text-right" style="width: 120px;">Saldo Restante</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyFacturasDesembolso">
                                <!-- Inyectado dinámicamente -->
                            </tbody>
                        </table>
                    </div>

                    <!-- 2. Retenciones Fiscales Dinámicas DGII -->
                    <div style="margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <h4 style="font-size: 0.92rem; font-weight: 700; color: var(--text-dark);">
                                2. Retenciones Fiscales DGII (Formato 606)
                            </h4>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="agregarLineaRetencion()">
                                <span>➕</span> Agregar Retención
                            </button>
                        </div>
                        <div id="contenedorRetenciones">
                            <!-- Inyectadas dinámicamente -->
                        </div>
                    </div>

                    <!-- 3. Distribución de Desembolso (Split de Pagos) -->
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <div>
                                <h4 style="font-size: 0.92rem; font-weight: 700; color: var(--text-dark); margin-bottom: 2px;">
                                    3. Distribución del Pago (Split de Tesorería)
                                </h4>
                                <div style="font-size: 0.76rem; color: var(--text-muted);">
                                    Los pagos se liquidan en tesorería/banco externo (<code>sesion_caja_id = NULL</code>), sin tocar el Arqueo Ciego POS.
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="agregarLineaSplitPago()">
                                <span>➕</span> Agregar Método
                            </button>
                        </div>

                        <div id="contenedorSplitPagos" style="margin-bottom: 12px;">
                            <!-- Inyectado dinámicamente -->
                        </div>

                        <!-- Barra de Cuadre del Split -->
                        <div style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 6px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span style="font-size: 0.82rem; color: var(--text-muted);">Total en Métodos de Pago:</span>
                                <strong style="font-family: var(--font-mono); font-size: 1rem; margin-left: 6px;" id="lblTotalSplit">RD$ 0.00</strong>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <button type="button" class="btn btn-sm btn-secondary" onclick="autoCuadrarSplitConNeto()" style="font-size: 0.75rem; padding: 3px 8px;" title="Ajusta el método de pago al neto exacto a desembolsar">
                                    ⚖️ Ajustar al Neto
                                </button>
                                <div id="lblEstadoCuadreSplit" style="font-size: 0.85rem; font-weight: 700; color: #b53d32;">
                                    ⚠️ Faltan RD$ 0.00 por desglosar
                                </div>
                            </div>
                        </div>

                        <!-- Botón de acción directo dentro del cuerpo -->
                        <div style="margin-top: 14px; padding: 12px 16px; background: #eef8f2; border: 1px solid #c2e5d1; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <div style="font-size: 0.85rem; font-weight: 700; color: #2e7d52;">● Listo para Desembolsar</div>
                                <div style="font-size: 0.76rem; color: var(--text-muted);">Se liquidará la deuda y se generará el asiento bancario.</div>
                            </div>
                            <button type="submit" class="btn btn-primary" id="btnConfirmarDesembolsoBody" style="background: #2e7d52; color: #ffffff; font-weight: 700; font-size: 0.95rem; padding: 10px 22px; box-shadow: 0 2px 8px rgba(46, 125, 82, 0.3);">
                                <span>💰</span> PAGAR AHORA
                            </button>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalDesembolso')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnConfirmarDesembolso" style="background: #2e7d52; color: #ffffff; font-weight: 700; font-size: 0.95rem; padding: 10px 22px;" disabled>
                        <span>💰</span> CONFIRMAR Y EMITIR PAGO
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 3: AJUSTES, DESCUENTOS Y ANULACIONES CON PIN DE SUPERVISOR          -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalAjuste">
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h3><span>⚖️</span> Ajuste / Anulación de Cuenta por Pagar</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalAjuste')">&times;</button>
            </div>
            <form id="formAjuste" onsubmit="guardarAjusteCxp(event)">
                <input type="hidden" name="cxp_id" id="ajusteCxpId" />
                <div class="modal-body">
                    <div style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px;">
                        <div style="font-size: 0.75rem; color: var(--text-muted);">Proveedor: <strong id="ajusteProveedorNombre" style="color: #251c16;">—</strong></div>
                        <div style="font-size: 0.85rem; font-family: var(--font-mono); font-weight: 700; color: #b53d32; margin-top: 3px;">
                            Saldo Actual: <span id="ajusteSaldoActual">RD$ 0.00</span>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Tipo de Ajuste <span style="color:red;">*</span></label>
                        <select name="tipo_ajuste" id="tipoAjusteSelect" class="form-control" required onchange="alCambiarTipoAjuste()">
                            <option value="DESCUENTO_PRONTO_PAGO">Descuento por Pronto Pago</option>
                            <option value="EXONERACION_PROVEEDOR">Exoneración otorgada por Proveedor</option>
                            <option value="NOTA_CREDITO_PROVEEDOR">Nota de Crédito Proveedor (NCF 606)</option>
                            <option value="ANULACION">ANULACIÓN PERMANENTE (Terminal)</option>
                        </select>
                    </div>

                    <div class="form-group" id="grupoNcfNotaCredito" style="display: none; margin-bottom: 12px;">
                        <label class="form-label">NCF Nota de Crédito (B04 Suplidor) <span style="color:red;">*</span></label>
                        <input type="text" name="ncf_nota_credito_proveedor" id="inputNcfNotaCredito" class="form-control" placeholder="B0400000123" maxlength="13" style="text-transform: uppercase;" />
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Monto del Ajuste (RD$) <span style="color:red;">*</span></label>
                        <input type="number" step="0.01" name="monto_ajuste" id="inputMontoAjuste" class="form-control" required style="font-family: var(--font-mono); font-weight: 700;" />
                        <div style="font-size: 0.74rem; color: var(--text-muted); margin-top: 3px;" id="lblAyudaMontoAjuste">
                            Rebaja el saldo sin movimiento de caja o banco.
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label">Justificación del Ajuste <span style="color:red;">*</span></label>
                        <textarea name="justificacion" class="form-control" rows="2" placeholder="Motivo documentado del ajuste..." required></textarea>
                    </div>

                    <!-- Autenticación de Supervisor por PIN -->
                    <div style="background: #fdf5ea; border: 1px solid #f0d5b0; border-radius: 6px; padding: 12px;">
                        <div style="font-size: 0.78rem; font-weight: 700; color: #a66e2e; margin-bottom: 8px; display: flex; align-items: center; gap: 5px;">
                            <span>🔒</span> AUTORIZACIÓN DE SUPERVISOR
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <div>
                                <label style="font-size: 0.72rem; color: var(--text-muted);">Supervisor:</label>
                                <select name="supervisor_id" class="form-control" required style="font-size: 0.8rem;">
                                    <?php foreach ($supervisoresList as $sup): ?>
                                        <option value="<?= htmlspecialchars($sup['usuario_id']) ?>">
                                            <?= htmlspecialchars($sup['nombre_completo']) ?> (<?= htmlspecialchars($sup['username']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="font-size: 0.72rem; color: var(--text-muted);">PIN de Autorización:</label>
                                <input type="password" name="pin_supervisor" class="form-control" maxlength="6" placeholder="••••" required style="letter-spacing: 3px; font-weight: 700; text-align: center;" />
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalAjuste')">Cancelar</button>
                    <button type="submit" class="btn btn-danger" id="btnGuardarAjuste">Autorizar y Aplicar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 4: DETALLE DE COMPROBANTE DE EGRESO                                 -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalDetalleEgreso">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3><span>🏦</span> Comprobante de Egreso #<span id="txtDetalleNumComprobante">—</span></h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalDetalleEgreso')">&times;</button>
            </div>
            <div class="modal-body" id="cuerpoDetalleEgreso">
                <div style="text-align: center; padding: 30px; color: var(--text-muted);">Cargando información del egreso...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalDetalleEgreso')">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- SCRIPTS JS -->
    <script>
        const CATALOGO_METODOS = <?= json_encode($metodosPagoList) ?>;
        const CATALOGO_BANCOS  = <?= json_encode($cuentasBancarias) ?>;
        const CATALOGO_RETENCIONES = <?= json_encode($retencionesList) ?>;
    </script>
    <script src="cuentas_por_pagar.js?v=7"></script>
</body>

</html>
