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

$puedeLiquidarDgii = (function_exists('usuarioEsAdministrador') && usuarioEsAdministrador())
    || (function_exists('usuarioTienePermiso') && (
        usuarioTienePermiso('REGISTRAR_MOVIMIENTOS_BANCARIOS')
        || usuarioTienePermiso('EJECUTAR_CIERRES_FISCALES_PERIODO')
    ));

// Variables iniciales
$kpiTotalDeuda = 0.00;
$kpiVencidasCnt = 0;
$kpiVencidasMto = 0.00;
$kpiVencenHoyCnt = 0;
$kpiVencenHoyMto = 0.00;
$kpiAlDiaCnt = 0;
$kpiAlDiaMto = 0.00;
$kpiEgresosMesMto = 0.00;

$kpiRetencionesPendientesMto = 0.00;
$kpiRetencionesPendientesCnt = 0;
$kpiRetItbisPendMto = 0.00;
$kpiRetItbisPendCnt = 0;
$kpiRetIsrPendMto = 0.00;
$kpiRetIsrPendCnt = 0;
$periodoSugeridoDgii = date('Y-m');

$listaCxp = [];
$listaEgresos = [];
$listaAjustes = [];
$listaRetencionesHist = [];
$proveedoresList = [];
$monedasList = [];
$cuentasBancarias = [];
$cuentasBancariasDop = [];
$retencionesList = [];
$metodosPagoList = [];
$supervisoresList = [];

if ($pdo) {
    // 1. KPIs Financieros (consolidados en DOP usando tasa_cambio)
    try {
        $stmtKpi = $pdo->query("
            SELECT
                COALESCE(SUM(saldo_pendiente * tasa_cambio), 0) AS total_deuda,
                COUNT(CASE WHEN fecha_vencimiento < CURRENT_DATE THEN 1 END) AS vencidas_cnt,
                COALESCE(SUM(CASE WHEN fecha_vencimiento < CURRENT_DATE THEN saldo_pendiente * tasa_cambio ELSE 0 END), 0) AS vencidas_mto,
                COUNT(CASE WHEN fecha_vencimiento = CURRENT_DATE THEN 1 END) AS hoy_cnt,
                COALESCE(SUM(CASE WHEN fecha_vencimiento = CURRENT_DATE THEN saldo_pendiente * tasa_cambio ELSE 0 END), 0) AS hoy_mto,
                COUNT(CASE WHEN fecha_vencimiento > CURRENT_DATE THEN 1 END) AS al_dia_cnt,
                COALESCE(SUM(CASE WHEN fecha_vencimiento > CURRENT_DATE THEN saldo_pendiente * tasa_cambio ELSE 0 END), 0) AS al_dia_mto
            FROM compras.cuentas_por_pagar
            WHERE estado IN ('PENDIENTE', 'PAGADA_PARCIAL')
        ");
        $kRow = $stmtKpi ? $stmtKpi->fetch(PDO::FETCH_ASSOC) : null;
        if ($kRow) {
            $kpiTotalDeuda = (float) $kRow['total_deuda'];
            $kpiVencidasCnt = (int) $kRow['vencidas_cnt'];
            $kpiVencidasMto = (float) $kRow['vencidas_mto'];
            $kpiVencenHoyCnt = (int) $kRow['hoy_cnt'];
            $kpiVencenHoyMto = (float) $kRow['hoy_mto'];
            $kpiAlDiaCnt = (int) $kRow['al_dia_cnt'];
            $kpiAlDiaMto = (float) $kRow['al_dia_mto'];
        }
    } catch (Throwable $e) {
    }

    // Egresos del mes en curso (DOP)
    try {
        $stmtEgrMes = $pdo->query("
            SELECT COALESCE(SUM(monto_total_pagado * tasa_cambio), 0)
            FROM compras.comprobantes_egreso_cxp
            WHERE DATE_TRUNC('month', fecha_pago) = DATE_TRUNC('month', CURRENT_DATE)
        ");
        $kpiEgresosMesMto = $stmtEgrMes ? (float) $stmtEgrMes->fetchColumn() : 0.00;
    } catch (Throwable $e) {
    }

    // Retenciones fiscales DGII pendientes desglosadas (IT-1 vs IR-17) + período más reciente pendiente
    try {
        $stmtRetPend = $pdo->query("
            SELECT
                COALESCE(SUM(cer.monto_retenido * COALESCE(ce.tasa_cambio, 1.0)), 0) AS total_monto,
                COUNT(*) AS total_filas,
                COALESCE(SUM(CASE WHEN UPPER(r.siglas) LIKE '%ITBIS%' THEN cer.monto_retenido * COALESCE(ce.tasa_cambio, 1.0) ELSE 0 END), 0) AS itbis_monto,
                COUNT(CASE WHEN UPPER(r.siglas) LIKE '%ITBIS%' THEN 1 END) AS itbis_cnt,
                COALESCE(SUM(CASE WHEN NOT (UPPER(r.siglas) LIKE '%ITBIS%') THEN cer.monto_retenido * COALESCE(ce.tasa_cambio, 1.0) ELSE 0 END), 0) AS isr_monto,
                COUNT(CASE WHEN NOT (UPPER(r.siglas) LIKE '%ITBIS%') THEN 1 END) AS isr_cnt,
                MAX(TO_CHAR(ce.fecha_pago, 'YYYY-MM')) AS ultimo_periodo
            FROM compras.comprobante_egreso_retenciones cer
            JOIN compras.comprobantes_egreso_cxp ce ON ce.id = cer.comprobante_egreso_id
            JOIN tesoreria.retenciones r ON r.id = cer.retencion_id
            WHERE cer.estado = 'PENDIENTE'
        ");
        $retRow = $stmtRetPend ? $stmtRetPend->fetch(PDO::FETCH_ASSOC) : null;
        if ($retRow) {
            $kpiRetencionesPendientesMto = (float) $retRow['total_monto'];
            $kpiRetencionesPendientesCnt = (int) $retRow['total_filas'];
            $kpiRetItbisPendMto = (float) $retRow['itbis_monto'];
            $kpiRetItbisPendCnt = (int) $retRow['itbis_cnt'];
            $kpiRetIsrPendMto = (float) $retRow['isr_monto'];
            $kpiRetIsrPendCnt = (int) $retRow['isr_cnt'];
            if (!empty($retRow['ultimo_periodo'])) {
                $periodoSugeridoDgii = (string) $retRow['ultimo_periodo'];
            }
        }
    } catch (Throwable $e) {
    }

    // 2. Catálogos
    try {
        $stmtPr = $pdo->query("
            SELECT pr.persona_id, pr.codigo_proveedor, pr.dias_credito_habitual,
                   p.nombre_razon_social, p.documento_identidad, p.telefono_principal
            FROM identidad.proveedores pr
            JOIN identidad.personas p ON p.persona_id = pr.persona_id
            WHERE p.estado = TRUE OR p.estado IS NULL
            ORDER BY p.nombre_razon_social ASC
        ");
        $proveedoresList = $stmtPr ? $stmtPr->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
    }

    try {
        $stmtMon = $pdo->query("
            SELECT id, codigo_iso, nombre, simbolo, tasa_cambio_referencia, es_moneda_base
            FROM tesoreria.monedas
            WHERE activa = TRUE
            ORDER BY CASE WHEN es_moneda_base = TRUE THEN 0 ELSE 1 END, id ASC
        ");
        $monedasList = $stmtMon ? $stmtMon->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
    }

    try {
        $stmtCb = $pdo->query("
            SELECT cb.id, cb.nombre_banco, cb.numero_cuenta, cb.tipo_cuenta, cb.moneda_id,
                   COALESCE(m.codigo_iso, 'DOP') AS moneda_iso,
                   COALESCE(m.simbolo, 'RD$') AS moneda_simbolo,
                   COALESCE(m.es_moneda_base, TRUE) AS es_moneda_base
            FROM tesoreria.cuentas_bancarias cb
            LEFT JOIN tesoreria.monedas m ON m.id = cb.moneda_id
            WHERE cb.activa = TRUE
            ORDER BY CASE WHEN COALESCE(m.es_moneda_base, TRUE) = TRUE THEN 0 ELSE 1 END, cb.nombre_banco ASC
        ");
        $cuentasBancarias = $stmtCb ? $stmtCb->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($cuentasBancarias as $ctaItem) {
            $esBase = in_array($ctaItem['es_moneda_base'], [true, 't', 1, '1'], true) || strtoupper((string) $ctaItem['moneda_iso']) === 'DOP';
            if ($esBase) {
                $cuentasBancariasDop[] = $ctaItem;
            }
        }
    } catch (Throwable $e) {
    }

    try {
        $stmtRet = $pdo->query("
            SELECT id, siglas, nombre, base_calculo, porcentaje
            FROM tesoreria.retenciones
            WHERE activo = TRUE
            ORDER BY id ASC
        ");
        $retencionesList = $stmtRet ? $stmtRet->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
    }

    try {
        $stmtMp = $pdo->query("
            SELECT id, codigo, descripcion, requiere_referencia
            FROM ventas.metodos_pago
            WHERE activo = TRUE AND codigo IN ('EFECTIVO', 'TRANSFERENCIA', 'CHEQUE')
            ORDER BY id ASC
        ");
        $metodosPagoList = $stmtMp ? $stmtMp->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
    }

    try {
        $supervisoresList = obtenerSupervisoresAutorizados($pdo);
    } catch (Throwable $e) {
    }

    // 3. Cuentas por Pagar
    try {
        $stmtCxp = $pdo->query("
            SELECT cxp.id, cxp.proveedor_id, cxp.recepcion_compra_id,
                   cxp.monto_original, cxp.saldo_pendiente,
                   cxp.fecha_emision, cxp.fecha_vencimiento, cxp.estado,
                   cxp.moneda_id, cxp.tasa_cambio,
                   m.simbolo AS moneda_simbolo, m.codigo_iso AS moneda_iso,
                   p.nombre_razon_social AS proveedor_nombre,
                   p.documento_identidad AS proveedor_rnc,
                   pr.codigo_proveedor,
                   COALESCE(rc.numero_factura_proveedor, cxp.numero_factura_servicio, 'S/F') AS numero_factura,
                   COALESCE(rc.ncf_proveedor, cxp.ncf_servicio, 'N/A') AS ncf,
                   COALESCE(NULLIF(TRIM(cxp.concepto_servicio), ''), CASE WHEN rc.id IS NOT NULL THEN 'Recepción de Mercancía #' || rc.id ELSE 'Obligación #' || cxp.id END) AS concepto,
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
    } catch (Throwable $e) {
    }

    // 4. Historial de Egresos
    try {
        $stmtEgr = $pdo->query("
            SELECT ce.id, ce.numero_comprobante, ce.monto_total_pagado, ce.fecha_pago,
                   m.simbolo AS moneda_simbolo,
                   p.nombre_razon_social AS proveedor_nombre,
                   p.documento_identidad AS proveedor_rnc,
                   u.username AS usuario_registro,
                   (SELECT COUNT(*) FROM compras.comprobante_egreso_detalles ced WHERE ced.comprobante_egreso_id = ce.id) AS total_facturas_canceladas,
                   (SELECT COALESCE(SUM(monto_retenciones), 0) FROM compras.comprobante_egreso_detalles ced WHERE ced.comprobante_egreso_id = ce.id) AS total_retenciones
            FROM compras.comprobantes_egreso_cxp ce
            JOIN identidad.proveedores pr ON pr.persona_id = ce.proveedor_id
            JOIN identidad.personas p ON p.persona_id = pr.persona_id
            JOIN tesoreria.monedas m ON m.id = ce.moneda_id
            JOIN seguridad.usuarios u ON u.usuario_id = ce.usuario_registro_id
            ORDER BY ce.fecha_pago DESC, ce.id DESC
            LIMIT 60
        ");
        $listaEgresos = $stmtEgr ? $stmtEgr->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
    }

    // 5. Historial de Ajustes
    try {
        $stmtAj = $pdo->query("
            SELECT aj.id, aj.cuenta_por_pagar_id, aj.tipo_ajuste, aj.ncf_nota_credito_proveedor,
                   aj.monto_ajuste, aj.saldo_anterior, aj.saldo_restante, aj.justificacion, aj.fecha_ajuste,
                   u.username AS supervisor_username,
                   COALESCE(sup_p.nombre_razon_social, u.username) AS supervisor_nombre,
                   p.nombre_razon_social AS proveedor_nombre,
                   COALESCE(rc.numero_factura_proveedor, cxp.numero_factura_servicio, 'S/F') AS numero_factura
            FROM compras.ajustes_cxp aj
            JOIN compras.cuentas_por_pagar cxp ON cxp.id = aj.cuenta_por_pagar_id
            JOIN identidad.proveedores pr ON pr.persona_id = cxp.proveedor_id
            JOIN identidad.personas p ON p.persona_id = pr.persona_id
            LEFT JOIN compras.recepciones_compra rc ON rc.id = cxp.recepcion_compra_id
            JOIN seguridad.usuarios u ON u.usuario_id = aj.supervisor_id
            LEFT JOIN identidad.personas sup_p ON sup_p.persona_id = u.persona_id
            ORDER BY aj.fecha_ajuste DESC, aj.id DESC
            LIMIT 60
        ");
        $listaAjustes = $stmtAj ? $stmtAj->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
    }

    // 6. Historial de Retenciones Fiscales DGII
    try {
        $stmtRetHist = $pdo->query("
            SELECT cer.comprobante_egreso_id, cer.cuenta_por_pagar_id,
                   cer.base_calculo_aplicada, cer.monto_base, cer.porcentaje_aplicado, cer.monto_retenido,
                   COALESCE(cer.estado, 'PENDIENTE') AS estado,
                   cer.fecha_liquidacion, cer.movimiento_bancario_id,
                   r.siglas AS retencion_siglas, r.nombre AS retencion_nombre,
                   CASE WHEN UPPER(r.siglas) LIKE '%ITBIS%' THEN 'IT-1' ELSE 'IR-17' END AS formulario_dgii,
                   TO_CHAR(ce.fecha_pago, 'YYYY-MM') AS periodo_fiscal,
                   ce.numero_comprobante, ce.fecha_pago,
                   m.simbolo AS moneda_simbolo,
                   p.nombre_razon_social AS proveedor_nombre,
                   p.documento_identidad AS proveedor_rnc,
                   COALESCE(rc.ncf_proveedor, cxp.ncf_servicio, 'N/A') AS ncf_factura,
                   mb.referencia AS ref_liquidacion_dgii,
                   cb.nombre_banco AS banco_liquidacion
            FROM compras.comprobante_egreso_retenciones cer
            JOIN tesoreria.retenciones r ON r.id = cer.retencion_id
            JOIN compras.comprobantes_egreso_cxp ce ON ce.id = cer.comprobante_egreso_id
            JOIN tesoreria.monedas m ON m.id = ce.moneda_id
            JOIN compras.cuentas_por_pagar cxp ON cxp.id = cer.cuenta_por_pagar_id
            LEFT JOIN compras.recepciones_compra rc ON rc.id = cxp.recepcion_compra_id
            JOIN identidad.personas p ON p.persona_id = ce.proveedor_id
            LEFT JOIN tesoreria.movimientos_bancarios mb ON mb.id = cer.movimiento_bancario_id
            LEFT JOIN tesoreria.cuentas_bancarias cb ON cb.id = mb.cuenta_bancaria_id
            ORDER BY
                CASE WHEN COALESCE(cer.estado, 'PENDIENTE') = 'PENDIENTE' THEN 0 ELSE 1 END,
                ce.fecha_pago DESC,
                cer.comprobante_egreso_id DESC
            LIMIT 120
        ");
        $listaRetencionesHist = $stmtRetHist ? $stmtRetHist->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Cuentas por Pagar (CxP)</title>
    <link rel="stylesheet" href="../styles.css?v=32" />
    <link rel="stylesheet" href="styles.css?v=12" />
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
                        <?php if (function_exists('renderizarPillCajaTopbar')): ?>
                            <span class="user-bullet">•</span>
                            <?= renderizarPillCajaTopbar() ?>
                        <?php endif; ?>
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
            <div class="page-header" style="margin-bottom: 20px;">
                <div>
                    <h1 class="page-title">🏛️ Cuentas por Pagar (CxP) y Retenciones DGII</h1>
                    <p class="page-subtitle">Administración de obligaciones hacia suplidores, proyección de tesorería,
                        retenciones fiscales (IT-1 / IR-17) y desembolsos auditados.</p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <?php if ($puedeLiquidarDgii): ?>
                        <button type="button" class="btn btn-secondary" onclick="abrirModalLiquidarDGII()"
                            style="background: #ffffff; color: var(--text-dark); border: 1.5px solid #d4c1a7; display: inline-flex; align-items: center; gap: 8px; font-weight: 600;">
                            <span>🏛️</span> Liquidar Retenciones DGII
                            <?php if ($kpiRetencionesPendientesCnt > 0): ?>
                                <span class="badge-count"
                                    style="background: #b78103; color: #fff; padding: 2px 8px; border-radius: 12px; font-size: 0.74rem; font-weight: 700;">RD$
                                    <?= number_format($kpiRetencionesPendientesMto, 2) ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" onclick="abrirModalGastoManual()"
                        style="background: #ffffff; color: var(--text-dark); border: 1px solid var(--border-light); display: inline-flex; align-items: center; gap: 6px;">
                        <span>➕</span> Registrar Gasto / Servicio
                    </button>
                    <button type="button" class="btn btn-primary" onclick="abrirModalDesembolso()" id="btnDesembolsoTop"
                        disabled>
                        <span>💳</span> Liquidar Selección (<span id="countSeleccionadasTop">0</span>)
                    </button>
                </div>
            </div>

            <!-- TARJETAS DE INDICADORES -->
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
                        <div class="cxp-kpi-value" style="color: #2e7d52;"><?= ($kpiVencenHoyCnt + $kpiAlDiaCnt) ?>
                            facturas</div>
                        <div class="cxp-kpi-sub">RD$ <?= number_format($kpiVencenHoyMto + $kpiAlDiaMto, 2) ?> vigentes
                        </div>
                    </div>
                </div>

                <div class="cxp-kpi-card">
                    <div class="cxp-kpi-icon blue">🏦</div>
                    <div class="cxp-kpi-data">
                        <h4>Desembolsos del Mes</h4>
                        <div class="cxp-kpi-value" style="color: #1e6091;">RD$
                            <?= number_format($kpiEgresosMesMto, 2) ?></div>
                        <div class="cxp-kpi-sub">Ret. pend.: RD$ <?= number_format($kpiRetencionesPendientesMto, 2) ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABS DE NAVEGACIÓN -->
            <div class="cxp-tabs">
                <button type="button" class="cxp-tab-btn active" onclick="cambiarTab('tab-obligaciones', this)">
                    <span>📋</span> Obligaciones y Antigüedad
                </button>
                <button type="button" class="cxp-tab-btn" onclick="cambiarTab('tab-egresos', this)">
                    <span>💳</span> Comprobantes de Egreso
                </button>
                <button type="button" class="cxp-tab-btn" onclick="cambiarTab('tab-retenciones', this)">
                    <span>🏛️</span> Retenciones DGII
                    <?php if ($kpiRetencionesPendientesCnt > 0): ?>
                        <span class="badge-count"
                            style="font-size: 0.7rem; padding: 1px 6px;"><?= $kpiRetencionesPendientesCnt ?></span>
                    <?php endif; ?>
                </button>
                <button type="button" class="cxp-tab-btn" onclick="cambiarTab('tab-ajustes', this)">
                    <span>⚖️</span> Ajustes y Anulaciones
                </button>
            </div>

            <!-- TAB 1: OBLIGACIONES Y ANTIGÜEDAD DE SALDOS -->
            <div id="tab-obligaciones" class="cxp-tab-content active">
                <div class="table-card" style="margin-bottom: 16px; padding: 14px 18px;">
                    <div style="display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end;">
                        <div style="flex: 1; min-width: 240px;">
                            <label class="form-label"
                                style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Buscar
                                Factura, NCF o Concepto</label>
                            <input type="text" id="filtroBusqueda" class="form-control"
                                placeholder="Escriba número de factura, NCF, concepto..."
                                oninput="aplicarFiltrosCxp()" />
                        </div>

                        <div style="width: 260px;">
                            <label class="form-label"
                                style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Filtrar
                                por Suplidor</label>
                            <select id="filtroProveedor" class="form-control" onchange="aplicarFiltrosCxp()">
                                <option value="">-- Todos los Proveedores --</option>
                                <?php foreach ($proveedoresList as $pr): ?>
                                    <option value="<?= htmlspecialchars($pr['persona_id']) ?>">
                                        <?= htmlspecialchars($pr['nombre_razon_social']) ?>
                                        (<?= htmlspecialchars($pr['documento_identidad']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="width: 220px;">
                            <label class="form-label"
                                style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Estado
                                de Deuda</label>
                            <select id="filtroEstado" class="form-control" onchange="aplicarFiltrosCxp()">
                                <option value="PENDIENTES" selected>Solo Pendientes y Parciales</option>
                                <option value="VENCIDAS">Solo Vencidas</option>
                                <option value="HOY">Vencen Hoy</option>
                                <option value="TODAS">Todas (Incluye Pagadas/Anuladas)</option>
                            </select>
                        </div>

                        <div>
                            <button type="button" class="btn btn-secondary"
                                onclick="limpiarFiltrosCxp()">Limpiar</button>
                        </div>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Cartera de Cuentas por Pagar</h2>
                            <span class="badge-count" id="badgeTotalCxp"><?= count($listaCxp) ?> obligaciones</span>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">
                            Selecciona una o varias facturas del mismo suplidor (y misma moneda) para liquidar en lote
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table" id="tablaCxp">
                            <thead>
                                <tr>
                                    <th style="width: 44px; text-align: center;">
                                        <input type="checkbox" id="chkSelectAll" onchange="toggleSelectAll(this)"
                                            title="Seleccionar visibles" />
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
                                        <td colspan="11"
                                            style="text-align: center; padding: 36px; color: var(--text-muted);">
                                            No hay cuentas por pagar registradas todavía.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaCxp as $cxp):
                                        $diasDif = (int) $cxp['dias_diferencia'];
                                        $esPendiente = in_array($cxp['estado'], ['PENDIENTE', 'PAGADA_PARCIAL'], true);
                                        $sim = $cxp['moneda_simbolo'] ?: 'RD$';

                                        if ($cxp['estado'] === 'ANULADA') {
                                            $badgeAntiguedad = '<span class="badge-anulada">ANULADA</span>';
                                        } elseif ($cxp['estado'] === 'PAGADA') {
                                            $badgeAntiguedad = '<span class="badge-pagada">SALDADA</span>';
                                        } elseif ($diasDif > 0) {
                                            $badgeAntiguedad = '<span class="badge-vencida">⚠️ Vencida +' . $diasDif . ' d</span>';
                                        } elseif ($diasDif === 0) {
                                            $badgeAntiguedad = '<span class="badge-hoy">⏰ Vence Hoy</span>';
                                        } else {
                                            $badgeAntiguedad = '<span class="badge-al-dia">● ' . abs($diasDif) . ' d restantes</span>';
                                        }
                                        ?>
                                        <tr class="fila-cxp" data-id="<?= (int) $cxp['id'] ?>"
                                            data-proveedor-id="<?= htmlspecialchars($cxp['proveedor_id']) ?>"
                                            data-proveedor-nombre="<?= htmlspecialchars($cxp['proveedor_nombre']) ?>"
                                            data-factura="<?= htmlspecialchars($cxp['numero_factura']) ?>"
                                            data-ncf="<?= htmlspecialchars($cxp['ncf']) ?>"
                                            data-saldo="<?= (float) $cxp['saldo_pendiente'] ?>"
                                            data-moneda-id="<?= (int) $cxp['moneda_id'] ?>"
                                            data-moneda-simbolo="<?= htmlspecialchars($sim) ?>"
                                            data-moneda-iso="<?= htmlspecialchars($cxp['moneda_iso'] ?: 'DOP') ?>"
                                            data-tasa="<?= (float) $cxp['tasa_cambio'] ?>"
                                            data-estado="<?= htmlspecialchars($cxp['estado']) ?>"
                                            data-vencido="<?= ($diasDif > 0) ? '1' : '0' ?>"
                                            data-hoy="<?= ($diasDif === 0) ? '1' : '0' ?>">

                                            <td style="text-align: center;">
                                                <?php if ($esPendiente): ?>
                                                    <input type="checkbox" class="chk-cxp-item" value="<?= (int) $cxp['id'] ?>"
                                                        data-proveedor="<?= htmlspecialchars($cxp['proveedor_id']) ?>"
                                                        data-saldo="<?= (float) $cxp['saldo_pendiente'] ?>"
                                                        onchange="alCambiarSeleccionItem(this)" />
                                                <?php else: ?>
                                                    <span style="color: #ccc;">—</span>
                                                <?php endif; ?>
                                            </td>

                                            <td style="white-space: nowrap;">
                                                <div style="font-weight: 700; color: var(--text-dark); font-size: 0.88rem;">
                                                    <?= htmlspecialchars($cxp['numero_factura']) ?></div>
                                                <div class="cell-sub"
                                                    style="font-family: var(--font-mono); font-size: 0.76rem; color: var(--accent-gold); font-weight: 600;">
                                                    NCF: <?= htmlspecialchars($cxp['ncf']) ?></div>
                                            </td>

                                            <td>
                                                <strong
                                                    style="color: var(--text-dark);"><?= htmlspecialchars($cxp['proveedor_nombre']) ?></strong>
                                                <div class="cell-sub">RNC: <?= htmlspecialchars($cxp['proveedor_rnc']) ?></div>
                                            </td>

                                            <td>
                                                <div style="max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"
                                                    title="<?= htmlspecialchars($cxp['concepto']) ?>">
                                                    <?= htmlspecialchars($cxp['concepto']) ?>
                                                </div>
                                                <div class="cell-sub" style="color: var(--accent-gold); font-weight: 600;">
                                                    <?= $cxp['recepcion_compra_id'] ? '📦 Mercancía (Recep. #' . (int) $cxp['recepcion_compra_id'] . ')' : '📑 Servicio Directo' ?>
                                                </div>
                                            </td>

                                            <td style="white-space: nowrap; font-size: 0.82rem;">
                                                <?= htmlspecialchars($cxp['fecha_emision']) ?></td>
                                            <td style="white-space: nowrap; font-weight: 600; font-size: 0.82rem;">
                                                <?= htmlspecialchars($cxp['fecha_vencimiento']) ?></td>
                                            <td><?= $badgeAntiguedad ?></td>

                                            <td class="text-right"
                                                style="font-family: var(--font-mono); font-size: 0.88rem; white-space: nowrap;">
                                                <?= htmlspecialchars($sim) ?>
                                                <?= number_format((float) $cxp['monto_original'], 2) ?>
                                            </td>

                                            <td class="text-right"
                                                style="font-family: var(--font-mono); font-weight: 700; font-size: 0.92rem; white-space: nowrap; color: <?= $esPendiente ? '#b53d32' : '#2e7d52' ?>;">
                                                <?= htmlspecialchars($sim) ?>
                                                <?= number_format((float) $cxp['saldo_pendiente'], 2) ?>
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
                                                        onclick="abrirModalAjuste(<?= (int) $cxp['id'] ?>, '<?= htmlspecialchars(addslashes($cxp['proveedor_nombre']), ENT_QUOTES) ?>', <?= (float) $cxp['saldo_pendiente'] ?>, '<?= htmlspecialchars($sim, ENT_QUOTES) ?>')"
                                                        title="Ajuste, Descuento o Anulación con Supervisor"
                                                        style="padding: 4px 8px; margin-right: 4px;">
                                                        <span>⚖️</span>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-primary"
                                                        onclick="liquidarFacturaIndividual(<?= (int) $cxp['id'] ?>)"
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
                        <div class="cxp-selection-count"><span id="txtSeleccionadasCount">0</span> factura(s)
                            seleccionada(s)</div>
                        <div style="font-size: 0.85rem; color: #c8beb2;">Proveedor: <strong
                                id="txtSeleccionadasProveedor" style="color: #fff;">—</strong></div>
                        <div class="cxp-selection-total">Total a Liquidar: <span id="txtSeleccionadasTotal">RD$
                                0.00</span></div>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button type="button" class="btn" onclick="limpiarSeleccion()"
                            style="background: transparent; color: #e2dacb; border: 1px solid rgba(255,255,255,0.3); padding: 8px 14px;">✕
                            Cancelar Selección</button>
                        <button type="button" class="btn btn-primary" onclick="abrirModalDesembolso()"
                            style="background: #a66e2e; color: #ffffff; font-weight: 700; padding: 8px 18px;">💳 Pagar
                            Selección</button>
                    </div>
                </div>
            </div>

            <!-- TAB 2: HISTORIAL DE COMPROBANTES DE EGRESO -->
            <div id="tab-egresos" class="cxp-tab-content">
                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Registro de Egresos y Liquidaciones de CxP</h2>
                            <span class="badge-count"><?= count($listaEgresos) ?> comprobantes</span>
                        </div>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">Liquidaciones externas (Bóveda /
                            Bancos) con sesion_caja_id = NULL</span>
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
                                        <td colspan="8"
                                            style="text-align: center; padding: 32px; color: var(--text-muted);">
                                            No se han registrado comprobantes de egreso todavía.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaEgresos as $egr):
                                        $simE = $egr['moneda_simbolo'] ?: 'RD$';
                                        ?>
                                        <tr>
                                            <td><strong class="code-inline"
                                                    style="font-size: 0.88rem; color: var(--accent-gold);"><?= htmlspecialchars($egr['numero_comprobante']) ?></strong>
                                            </td>
                                            <td style="font-size: 0.84rem;">
                                                <?= htmlspecialchars(substr((string) $egr['fecha_pago'], 0, 16)) ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($egr['proveedor_nombre']) ?></strong>
                                                <div class="cell-sub">RNC: <?= htmlspecialchars($egr['proveedor_rnc']) ?></div>
                                            </td>
                                            <td><span class="badge-al-dia"><?= (int) $egr['total_facturas_canceladas'] ?>
                                                    docs</span></td>
                                            <td class="text-right"
                                                style="font-family: var(--font-mono); color: #b78103; white-space: nowrap;">
                                                <?= htmlspecialchars($simE) ?>
                                                <?= number_format((float) $egr['total_retenciones'], 2) ?></td>
                                            <td class="text-right"
                                                style="font-family: var(--font-mono); font-weight: 700; color: #2e7d52; white-space: nowrap;">
                                                <?= htmlspecialchars($simE) ?>
                                                <?= number_format((float) $egr['monto_total_pagado'], 2) ?></td>
                                            <td style="font-size: 0.82rem;"><?= htmlspecialchars($egr['usuario_registro']) ?>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-secondary"
                                                    onclick="verDetalleEgreso(<?= (int) $egr['id'] ?>)">👁️ Ver Detalle</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 3: CONTROL Y DESGLOSE DE RETENCIONES FISCALES DGII (IT-1 / IR-17) -->
            <div id="tab-retenciones" class="cxp-tab-content">
                <!-- Resumen IT-1 vs IR-17 -->
                <div
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 16px;">
                    <div class="cxp-kpi-card" style="border-left: 4px solid #1e6091;">
                        <div class="cxp-kpi-data">
                            <h4>ITBIS Retenido Pendiente (IT-1)</h4>
                            <div class="cxp-kpi-value" style="color: #1e6091;">RD$
                                <?= number_format($kpiRetItbisPendMto, 2) ?></div>
                            <div class="cxp-kpi-sub"><?= $kpiRetItbisPendCnt ?> retención(es) por declarar en IT-1</div>
                        </div>
                    </div>
                    <div class="cxp-kpi-card" style="border-left: 4px solid #b78103;">
                        <div class="cxp-kpi-data">
                            <h4>ISR Retenido Pendiente (IR-17)</h4>
                            <div class="cxp-kpi-value" style="color: #b78103;">RD$
                                <?= number_format($kpiRetIsrPendMto, 2) ?></div>
                            <div class="cxp-kpi-sub"><?= $kpiRetIsrPendCnt ?> retención(es) por declarar en IR-17</div>
                        </div>
                    </div>
                    <div class="cxp-kpi-card" style="border-left: 4px solid #2e7d52;">
                        <div class="cxp-kpi-data">
                            <h4>Total Pasivo Fiscal Pendiente</h4>
                            <div class="cxp-kpi-value" style="color: #2e7d52;">RD$
                                <?= number_format($kpiRetencionesPendientesMto, 2) ?></div>
                            <div class="cxp-kpi-sub">Vence el día 20 del mes siguiente (OFV DGII)</div>
                        </div>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Retenciones Fiscales por Pagar y Liquidadas a DGII</h2>
                            <span class="badge-count"><?= count($listaRetencionesHist) ?> registros</span>
                        </div>
                        <?php if ($puedeLiquidarDgii): ?>
                            <button type="button" class="btn btn-primary btn-sm" onclick="abrirModalLiquidarDGII()"
                                <?= ($kpiRetencionesPendientesCnt <= 0) ? 'disabled' : '' ?>>
                                🏛️ Liquidar por Período / Formulario
                            </button>
                        <?php else: ?>
                            <span style="font-size: 0.78rem; color: var(--text-muted);">Solo Administrador / Tesorería puede
                                liquidar pagos a DGII</span>
                        <?php endif; ?>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Período / Comprobante</th>
                                    <th>Formulario</th>
                                    <th>Proveedor / NCF</th>
                                    <th>Retención DGII</th>
                                    <th class="text-right">Base Imponible</th>
                                    <th class="text-center">%</th>
                                    <th class="text-right">Monto Retenido</th>
                                    <th class="text-center">Estado DGII</th>
                                    <th>Liquidación Bancaria</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaRetencionesHist)): ?>
                                    <tr>
                                        <td colspan="9"
                                            style="text-align: center; padding: 32px; color: var(--text-muted);">
                                            No se han registrado retenciones fiscales en desembolsos todavía.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaRetencionesHist as $rh):
                                        $simR = $rh['moneda_simbolo'] ?: 'RD$';
                                        $esLiq = (strtoupper((string) $rh['estado']) === 'LIQUIDADA');
                                        $esIt1 = ($rh['formulario_dgii'] === 'IT-1');
                                        ?>
                                        <tr>
                                            <td style="white-space: nowrap;">
                                                <strong class="code-inline"
                                                    style="color: var(--accent-gold);"><?= htmlspecialchars($rh['periodo_fiscal']) ?></strong>
                                                <div class="cell-sub" style="font-family: var(--font-mono);">
                                                    <?= htmlspecialchars($rh['numero_comprobante']) ?></div>
                                            </td>
                                            <td style="white-space: nowrap;">
                                                <span
                                                    class="<?= $esIt1 ? 'badge-pagada' : 'badge-hoy' ?>"><?= htmlspecialchars($rh['formulario_dgii']) ?></span>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($rh['proveedor_nombre']) ?></strong>
                                                <div class="cell-sub" style="font-family: var(--font-mono);">RNC:
                                                    <?= htmlspecialchars($rh['proveedor_rnc']) ?> • NCF:
                                                    <?= htmlspecialchars($rh['ncf_factura']) ?></div>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($rh['retencion_siglas']) ?></strong>
                                                <div class="cell-sub">Base:
                                                    <?= htmlspecialchars($rh['base_calculo_aplicada']) ?></div>
                                            </td>
                                            <td class="text-right" style="font-family: var(--font-mono); white-space: nowrap;">
                                                <?= htmlspecialchars($simR) ?>         <?= number_format((float) $rh['monto_base'], 2) ?>
                                            </td>
                                            <td class="text-center" style="font-family: var(--font-mono); font-weight: 700;">
                                                <?= number_format((float) $rh['porcentaje_aplicado'], 2) ?>%</td>
                                            <td class="text-right"
                                                style="font-family: var(--font-mono); font-weight: 700; color: #b78103; white-space: nowrap;">
                                                <?= htmlspecialchars($simR) ?>
                                                <?= number_format((float) $rh['monto_retenido'], 2) ?></td>
                                            <td class="text-center">
                                                <?= $esLiq ? '<span class="badge-al-dia">LIQUIDADA</span>' : '<span class="badge-hoy">PENDIENTE</span>' ?>
                                            </td>
                                            <td style="font-size: 0.8rem;">
                                                <?php if ($esLiq): ?>
                                                    <div>
                                                        <strong><?= htmlspecialchars($rh['banco_liquidacion'] ?: 'Banco') ?></strong>
                                                    </div>
                                                    <div class="cell-sub" style="font-family: var(--font-mono);">Ref:
                                                        <?= htmlspecialchars($rh['ref_liquidacion_dgii'] ?: '—') ?> •
                                                        <?= htmlspecialchars((string) ($rh['fecha_liquidacion'] ?: '')) ?></div>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">Pendiente de pago</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 4: HISTORIAL DE AJUSTES Y ANULACIONES -->
            <div id="tab-ajustes" class="cxp-tab-content">
                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Auditoría de Ajustes, Descuentos y Exoneraciones</h2>
                            <span class="badge-count"><?= count($listaAjustes) ?> ajustes</span>
                        </div>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">Autorizaciones registradas con PIN de
                            Supervisor</span>
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
                                        <td colspan="9"
                                            style="text-align: center; padding: 32px; color: var(--text-muted);">
                                            No se han registrado ajustes a cuentas por pagar.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaAjustes as $aj): ?>
                                        <tr>
                                            <td style="font-size: 0.82rem; white-space: nowrap;">
                                                <?= htmlspecialchars(substr((string) $aj['fecha_ajuste'], 0, 16)) ?></td>
                                            <td><strong
                                                    style="color: var(--text-dark);"><?= htmlspecialchars($aj['numero_factura']) ?></strong>
                                            </td>
                                            <td style="font-size: 0.84rem;"><?= htmlspecialchars($aj['proveedor_nombre']) ?>
                                            </td>
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
                                                <?= htmlspecialchars($aj['ncf_nota_credito_proveedor'] ?: '—') ?></td>
                                            <td class="text-right"
                                                style="font-family: var(--font-mono); font-weight: 700; color: #b53d32;">RD$
                                                <?= number_format((float) $aj['monto_ajuste'], 2) ?></td>
                                            <td class="text-right" style="font-family: var(--font-mono);">RD$
                                                <?= number_format((float) $aj['saldo_restante'], 2) ?></td>
                                            <td style="font-size: 0.82rem;">
                                                <strong><?= htmlspecialchars($aj['supervisor_username']) ?></strong>
                                                <div class="cell-sub"><?= htmlspecialchars($aj['supervisor_nombre']) ?></div>
                                            </td>
                                            <td style="font-size: 0.82rem; max-width: 220px;"
                                                title="<?= htmlspecialchars($aj['justificacion']) ?>">
                                                <?= htmlspecialchars($aj['justificacion']) ?></td>
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

    <!-- MODAL 1: GASTO / SERVICIO DIRECTO -->
    <div class="modal-overlay" id="modalGastoManual">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3><span>📑</span> Registrar Obligación por Gasto / Servicio Directo</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalGastoManual')">&times;</button>
            </div>
            <form id="formGastoManual" onsubmit="guardarGastoManual(event)">
                <div class="modal-body">
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 16px;">
                        Para obligaciones sin mercancía en almacén (alquiler, electricidad, internet, honorarios). Nace
                        en estado <strong>PENDIENTE</strong> con <code>recepcion_compra_id = NULL</code>.
                    </p>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                        <div class="form-group" style="position: relative;">
                            <label class="form-label">Suplidor / Prestador <span style="color:red;">*</span></label>
                            <input type="hidden" name="proveedor_id" id="suplidorGastoId" required value="" />

                            <div id="boxBusquedaProveedorCxp" style="position: relative;">
                                <input type="text" id="inputBuscarProveedorCxp" class="form-control"
                                    placeholder="🔍 Escriba nombre o RNC del suplidor..." autocomplete="off"
                                    oninput="filtrarProveedoresCxp(this.value)"
                                    onfocus="abrirDropdownProveedoresCxp()" />
                                <div id="dropdownProveedoresCxp"
                                    style="display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 200px; overflow-y: auto; background: #ffffff; border: 1.5px solid #d4c1a7; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.15); z-index: 1050; margin-top: 2px;">
                                </div>
                            </div>

                            <div id="badgeProveedorElegidoCxp"
                                style="display: none; background: #fdf5ea; border: 1px solid #dfcaa4; border-radius: 6px; padding: 4px 8px; align-items: center; justify-content: space-between; min-height: 38px;">
                                <div
                                    style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.82rem; color: #211813;">
                                    <strong id="badgeProveedorNombreCxp">—</strong>
                                    <small id="badgeProveedorRncCxp"
                                        style="color: #7a6b5d; margin-left: 6px; font-family: var(--font-mono);"></small>
                                </div>
                                <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="limpiarProveedorSeleccionadoCxp()"
                                    style="padding: 1px 6px; font-size: 0.70rem; height: 22px; flex-shrink: 0;">✕
                                    Cambiar</button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Condición de Pago <span style="color:red;">*</span></label>
                            <select name="condicion_pago" id="condicionPagoGasto" class="form-control" required
                                onchange="alCambiarCondicionGasto()">
                                <option value="CONTADO">CONTADO (Vence Mismo Día)</option>
                                <option value="CREDITO">CRÉDITO (Aplica Días de Crédito)</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                        <div class="form-group">
                            <label class="form-label">No. Factura Proveedor</label>
                            <input type="text" name="numero_factura_servicio" class="form-control"
                                placeholder="Ej: FAC-98210" maxlength="50" />
                        </div>
                        <div class="form-group">
                            <label class="form-label">NCF Proveedor (DGII 606)</label>
                            <input type="text" name="ncf_servicio" class="form-control" placeholder="Ej: B0100000452"
                                maxlength="13" style="text-transform: uppercase;" />
                        </div>
                        <div class="form-group">
                            <label class="form-label">Días de Crédito</label>
                            <input type="number" name="dias_credito" id="diasCreditoGasto" class="form-control"
                                value="0" min="0" />
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                        <div class="form-group">
                            <label class="form-label">Fecha Emisión <span style="color:red;">*</span></label>
                            <input type="date" name="fecha_emision" class="form-control" value="<?= date('Y-m-d') ?>"
                                required />
                        </div>
                        <div class="form-group">
                            <label class="form-label">Moneda <span style="color:red;">*</span></label>
                            <select name="moneda_id" class="form-control" required>
                                <?php foreach ($monedasList as $m): ?>
                                    <option value="<?= (int) $m['id'] ?>"><?= htmlspecialchars($m['codigo_iso']) ?>
                                        (<?= htmlspecialchars($m['simbolo']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Monto Total Facturado <span style="color:red;">*</span></label>
                            <input type="number" step="0.01" min="0.01" name="monto_original" class="form-control"
                                placeholder="0.00" required style="font-weight: 700; font-family: var(--font-mono);" />
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Concepto Detallado del Gasto / Servicio <span
                                style="color:red;">*</span></label>
                        <textarea name="concepto_servicio" class="form-control" rows="2"
                            placeholder="Ej: Factura de electricidad mes de Noviembre 2026 local principal..."
                            required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                        onclick="cerrarModal('modalGastoManual')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarGasto">Guardar Obligación</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 2: DESEMBOLSO CONSOLIDADO -->
    <div class="modal-overlay" id="modalDesembolso">
        <div class="modal-dialog modal-lg">
            <div class="modal-header">
                <h3><span>💳</span> Ejecución de Desembolso y Liquidación Consolidada</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalDesembolso')">&times;</button>
            </div>
            <form id="formDesembolso" onsubmit="procesarLiquidacionDesembolso(event)">
                <div class="modal-body">
                    <div
                        style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <div
                                style="font-size: 0.76rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">
                                Proveedor Seleccionado</div>
                            <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-dark);"
                                id="modalDesembolsoProveedorNombre">—</div>
                        </div>
                        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                            <div>
                                <div style="font-size: 0.74rem; color: var(--text-muted); text-transform: uppercase;">
                                    Total Bruto</div>
                                <div style="font-size: 1.15rem; font-weight: 700; font-family: var(--font-mono);"
                                    id="modalTotalBrutoFacturas">RD$ 0.00</div>
                            </div>
                            <div>
                                <div style="font-size: 0.74rem; color: #b78103; text-transform: uppercase;">Retenciones
                                    DGII</div>
                                <div style="font-size: 1.15rem; font-weight: 700; font-family: var(--font-mono); color: #b78103;"
                                    id="modalTotalRetenciones">RD$ 0.00</div>
                            </div>
                            <div>
                                <div style="font-size: 0.74rem; color: #2e7d52; text-transform: uppercase;">Neto a
                                    Desembolsar</div>
                                <div style="font-size: 1.25rem; font-weight: 700; font-family: var(--font-mono); color: #2e7d52;"
                                    id="modalTotalNetoDesembolsar">RD$ 0.00</div>
                            </div>
                        </div>
                    </div>

                    <h4 style="font-size: 0.92rem; font-weight: 700; margin-bottom: 10px; color: var(--text-dark);">1.
                        Documentos y Facturas a Cancelar</h4>
                    <div class="table-wrapper"
                        style="margin-bottom: 20px; border: 1px solid var(--border-light); border-radius: 6px;">
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
                            <tbody id="tbodyFacturasDesembolso"></tbody>
                        </table>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <div>
                                <h4
                                    style="font-size: 0.92rem; font-weight: 700; color: var(--text-dark); margin-bottom: 2px;">
                                    2. Retenciones Fiscales DGII (Formato 606)</h4>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">
                                    Las retenciones sobre <strong>ITBIS</strong> estiman la base del 18%
                                    automáticamente; puedes ajustar la base manualmente si aplica.
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="agregarLineaRetencion()">➕
                                Agregar Retención</button>
                        </div>
                        <div id="contenedorRetenciones"></div>
                    </div>

                    <div>
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <div>
                                <h4
                                    style="font-size: 0.92rem; font-weight: 700; color: var(--text-dark); margin-bottom: 2px;">
                                    3. Distribución del Pago (Split de Tesorería)</h4>
                                <div style="font-size: 0.76rem; color: var(--text-muted);">
                                    Los pagos se liquidan en tesorería/banco externo
                                    (<code>sesion_caja_id = NULL</code>), sin tocar el Arqueo Ciego POS.
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="agregarLineaSplitPago()">➕
                                Agregar Método</button>
                        </div>

                        <div id="contenedorSplitPagos" style="margin-bottom: 12px;"></div>

                        <div
                            style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 6px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <span style="font-size: 0.82rem; color: var(--text-muted);">Total en Métodos de
                                    Pago:</span>
                                <strong style="font-family: var(--font-mono); font-size: 1rem; margin-left: 6px;"
                                    id="lblTotalSplit">RD$ 0.00</strong>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="autoCuadrarSplitConNeto()" style="font-size: 0.75rem; padding: 3px 8px;">⚖️
                                    Ajustar al Neto</button>
                                <div id="lblEstadoCuadreSplit"
                                    style="font-size: 0.85rem; font-weight: 700; color: #b53d32;">⚠️ Faltan RD$ 0.00 por
                                    desglosar</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                        onclick="cerrarModal('modalDesembolso')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnConfirmarDesembolso"
                        style="background: #2e7d52; color: #ffffff; font-weight: 700; font-size: 0.95rem; padding: 10px 22px;"
                        disabled>
                        <span>💰</span> CONFIRMAR Y EMITIR PAGO
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 3: AJUSTE / ANULACIÓN CON PIN -->
    <div class="modal-overlay" id="modalAjuste">
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h3><span>⚖️</span> Ajuste / Anulación de Cuenta por Pagar</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalAjuste')">&times;</button>
            </div>
            <form id="formAjuste" onsubmit="guardarAjusteCxp(event)">
                <input type="hidden" name="cxp_id" id="ajusteCxpId" />
                <div class="modal-body">
                    <div
                        style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px;">
                        <div style="font-size: 0.75rem; color: var(--text-muted);">Proveedor: <strong
                                id="ajusteProveedorNombre" style="color: #251c16;">—</strong></div>
                        <div
                            style="font-size: 0.85rem; font-family: var(--font-mono); font-weight: 700; color: #b53d32; margin-top: 3px;">
                            Saldo Actual: <span id="ajusteSaldoActual">RD$ 0.00</span>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Tipo de Ajuste <span style="color:red;">*</span></label>
                        <select name="tipo_ajuste" id="tipoAjusteSelect" class="form-control" required
                            onchange="alCambiarTipoAjuste()">
                            <option value="DESCUENTO_PRONTO_PAGO">Descuento por Pronto Pago</option>
                            <option value="EXONERACION_PROVEEDOR">Exoneración otorgada por Proveedor</option>
                            <option value="NOTA_CREDITO_PROVEEDOR">Nota de Crédito Proveedor (NCF 606)</option>
                            <option value="ANULACION">ANULACIÓN PERMANENTE (Terminal)</option>
                        </select>
                    </div>

                    <div class="form-group" id="grupoNcfNotaCredito" style="display: none; margin-bottom: 12px;">
                        <label class="form-label">NCF Nota de Crédito (B04 Suplidor) <span
                                style="color:red;">*</span></label>
                        <input type="text" name="ncf_nota_credito_proveedor" id="inputNcfNotaCredito"
                            class="form-control" placeholder="B0400000123" maxlength="13"
                            style="text-transform: uppercase;" />
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Monto del Ajuste <span style="color:red;">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="monto_ajuste" id="inputMontoAjuste"
                            class="form-control" required style="font-family: var(--font-mono); font-weight: 700;" />
                        <div style="font-size: 0.74rem; color: var(--text-muted); margin-top: 3px;"
                            id="lblAyudaMontoAjuste">Rebaja el saldo sin movimiento de caja o banco.</div>
                    </div>

                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label">Justificación del Ajuste <span style="color:red;">*</span></label>
                        <textarea name="justificacion" class="form-control" rows="2"
                            placeholder="Motivo documentado del ajuste..." required></textarea>
                    </div>

                    <div style="background: #fdf5ea; border: 1px solid #f0d5b0; border-radius: 6px; padding: 12px;">
                        <div style="font-size: 0.78rem; font-weight: 700; color: #a66e2e; margin-bottom: 8px;">🔒
                            AUTORIZACIÓN DE SUPERVISOR</div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <div>
                                <label style="font-size: 0.72rem; color: var(--text-muted);">Supervisor:</label>
                                <select name="supervisor_id" class="form-control" required style="font-size: 0.8rem;">
                                    <?php foreach ($supervisoresList as $sup): ?>
                                        <option value="<?= htmlspecialchars($sup['usuario_id']) ?>">
                                            <?= htmlspecialchars($sup['nombre_completo']) ?>
                                            (<?= htmlspecialchars($sup['username']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="font-size: 0.72rem; color: var(--text-muted);">PIN de
                                    Autorización:</label>
                                <input type="password" name="pin_supervisor" class="form-control" maxlength="6"
                                    placeholder="••••" required
                                    style="letter-spacing: 3px; font-weight: 700; text-align: center;"
                                    oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6);" />
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                        onclick="cerrarModal('modalAjuste')">Cancelar</button>
                    <button type="submit" class="btn btn-danger" id="btnGuardarAjuste">Autorizar y Aplicar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 4: DETALLE COMPROBANTE DE EGRESO -->
    <div class="modal-overlay" id="modalDetalleEgreso">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3><span>🏦</span> Comprobante de Egreso #<span id="txtDetalleNumComprobante">—</span></h3>
                <button type="button" class="modal-close-btn"
                    onclick="cerrarModal('modalDetalleEgreso')">&times;</button>
            </div>
            <div class="modal-body" id="cuerpoDetalleEgreso">
                <div style="text-align: center; padding: 30px; color: var(--text-muted);">Cargando información del
                    egreso...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                    onclick="cerrarModal('modalDetalleEgreso')">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- MODAL 5: LIQUIDACIÓN DE RETENCIONES DGII POR PERÍODO Y FORMULARIO (SOLO ADMIN / TESORERÍA) -->
    <?php if ($puedeLiquidarDgii): ?>
        <div class="modal-overlay" id="modalLiquidarRetencionesDGII">
            <div class="modal-dialog">
                <div class="modal-header">
                    <h3><span>🏛️</span> Liquidar Retenciones Fiscales DGII</h3>
                    <button type="button" class="modal-close-btn"
                        onclick="cerrarModal('modalLiquidarRetencionesDGII')">&times;</button>
                </div>
                <form id="formLiquidarRetencionesDGII" onsubmit="procesarLiquidacionDGII(event)">
                    <div class="modal-body">

                        <!-- Selector de Período y Formulario (actualiza totales en vivo) -->
                        <div style="display: grid; grid-template-columns: 1fr 1.35fr; gap: 12px; margin-bottom: 14px;">
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label">Período Fiscal a Declarar <span
                                        style="color:red;">*</span></label>
                                <input type="month" name="periodo_fiscal" id="dgiiPeriodoFiscal" class="form-control"
                                    value="<?= htmlspecialchars($periodoSugeridoDgii) ?>" required
                                    onchange="actualizarResumenRetencionesDGII()" />
                            </div>
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label">Declaración / Impuesto <span style="color:red;">*</span></label>
                                <select name="tipo_impuesto" id="dgiiTipoImpuesto" class="form-control" required
                                    onchange="actualizarMontoSeleccionadoDGII()">
                                    <option value="ITBIS">Solo ITBIS Retenido (Formulario IT-1)</option>
                                    <option value="ISR">Solo ISR Retenido (Formulario IR-17)</option>
                                    <option value="AMBOS" selected>Ambos — Todo el Período (IT-1 + IR-17)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Resumen en vivo del Período Seleccionado -->
                        <div
                            style="background: #fdfaf4; border: 1.5px solid #d4c1a7; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px;">
                            <div
                                style="display: grid; grid-template-columns: 1fr 1fr 1.15fr; gap: 10px; align-items: center;">
                                <div>
                                    <div
                                        style="font-size: 0.70rem; text-transform: uppercase; font-weight: 700; color: #1e6091;">
                                        ITBIS (IT-1)</div>
                                    <div id="dgiiPrevItbis"
                                        style="font-size: 0.98rem; font-weight: 700; font-family: var(--font-mono); color: #1e6091;">
                                        RD$ 0.00</div>
                                    <div id="dgiiPrevItbisCnt" style="font-size: 0.70rem; color: var(--text-muted);">0 ret.
                                    </div>
                                </div>
                                <div>
                                    <div
                                        style="font-size: 0.70rem; text-transform: uppercase; font-weight: 700; color: #b78103;">
                                        ISR (IR-17)</div>
                                    <div id="dgiiPrevIsr"
                                        style="font-size: 0.98rem; font-weight: 700; font-family: var(--font-mono); color: #b78103;">
                                        RD$ 0.00</div>
                                    <div id="dgiiPrevIsrCnt" style="font-size: 0.70rem; color: var(--text-muted);">0 ret.
                                    </div>
                                </div>
                                <div style="border-left: 1px solid #e6d8be; padding-left: 12px;">
                                    <div
                                        style="font-size: 0.70rem; text-transform: uppercase; font-weight: 700; color: #2e7d52;">
                                        Monto a Pagar Ahora</div>
                                    <div id="txtModalTotalRetencionesDGII"
                                        style="font-size: 1.22rem; font-weight: 800; font-family: var(--font-mono); color: #2e7d52;">
                                        RD$ 0.00</div>
                                    <div id="dgiiPrevSelCnt" style="font-size: 0.71rem; color: var(--text-muted);">
                                        Consultando período...</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 12px;">
                            <label class="form-label">Cuenta Bancaria de Salida (DOP) <span
                                    style="color:red;">*</span></label>
                            <select name="cuenta_bancaria_id" id="dgiiCuentaBancaria" class="form-control" required>
                                <?php if (empty($cuentasBancariasDop)): ?>
                                    <option value="">⚠️ No hay cuentas bancarias activas en DOP</option>
                                <?php else: ?>
                                    <option value="">-- Seleccionar Cuenta Bancaria en DOP --</option>
                                    <?php foreach ($cuentasBancariasDop as $cta): ?>
                                        <option value="<?= (int) $cta['id'] ?>">
                                            <?= htmlspecialchars($cta['nombre_banco']) ?> —
                                            <?= htmlspecialchars($cta['numero_cuenta']) ?>
                                            (<?= htmlspecialchars($cta['tipo_cuenta']) ?> DOP)
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 12px; margin-bottom: 12px;">
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label">No. Autorización / Volante DGII <span
                                        style="color:red;">*</span></label>
                                <input type="text" name="numero_autorizacion" id="dgiiNumAutorizacion" class="form-control"
                                    placeholder="Ej: DGII-IT1-202611-001" required minlength="3" maxlength="100"
                                    style="text-transform: uppercase; font-family: var(--font-mono);" />
                            </div>
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label">Fecha de Pago <span style="color:red;">*</span></label>
                                <input type="date" name="fecha_pago" id="dgiiFechaPago" class="form-control"
                                    value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required />
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 12px;">
                            <label class="form-label">Método de Pago Bancario <span style="color:red;">*</span></label>
                            <select name="metodo_pago" class="form-control" required>
                                <option value="TRANSFERENCIA">TRANSFERENCIA BANCARIA (TRANSFERENCIA_SALIDA)</option>
                                <option value="CHEQUE">CHEQUE DE ADMINISTRACIÓN (CHEQUE_SALIDA)</option>
                            </select>
                        </div>

                        <div
                            style="background: #faf8f5; border: 1px solid #ebdcc5; border-radius: 6px; padding: 10px 12px; font-size: 0.78rem; color: #554436;">
                            ℹ️ Registra únicamente el egreso en <code>tesoreria.movimientos_bancarios</code>, marca como
                            <code>LIQUIDADA</code> las retenciones del período y formulario seleccionados y deja intactos el
                            Arqueo Ciego POS y el inventario.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary"
                            onclick="cerrarModal('modalLiquidarRetencionesDGII')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="btnGuardarLiquidacionDGII"
                            style="background: #a66e2e;" disabled>
                            🏛️ Liquidar y Registrar Pago DGII
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- SCRIPTS JS -->
    <script>
        const CATALOGO_METODOS = <?= json_encode($metodosPagoList, JSON_UNESCAPED_UNICODE) ?>;
        const CATALOGO_BANCOS = <?= json_encode($cuentasBancarias, JSON_UNESCAPED_UNICODE) ?>;
        const CATALOGO_RETENCIONES = <?= json_encode($retencionesList, JSON_UNESCAPED_UNICODE) ?>;
        const TIENE_BANCOS_DOP = <?= !empty($cuentasBancariasDop) ? 'true' : 'false' ?>;
        window.PROVEEDORES_LISTA = <?= json_encode($proveedoresList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        const PROVEEDORES_LISTA = window.PROVEEDORES_LISTA;
    </script>
    <script src="cuentas_por_pagar.js?v=<?= time() ?>"></script>
</body>

</html>