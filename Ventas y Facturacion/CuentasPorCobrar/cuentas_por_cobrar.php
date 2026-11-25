<?php
/**
 * ============================================================================
 * Vista Principal: Proceso 7 - Cuentas por Cobrar (CxC)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/CuentasPorCobrar/cuentas_por_cobrar.php
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/funciones.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: ../../Login y Menu/login.php");
    exit;
}

if (!usuarioPuedeAccederCxc()) {
    header("Location: ../../Login y Menu/menu.php?error=acceso_denegado");
    exit;
}

$usuarioId = $_SESSION['usuario']['usuario_id'];
$personaId = $_SESSION['usuario']['persona_id'] ?? null;

// ============================================================================
// CARGA DE DATOS Y CONSULTAS PRINCIPALES
// ============================================================================
$kpiTotalDeuda   = 0.00;
$kpiVencidasCnt  = 0;
$kpiVencidasMto  = 0.00;
$kpiAlDiaCnt     = 0;
$kpiAlDiaMto     = 0.00;
$kpiCobrosMesMto = 0.00;

$listaCxc         = [];
$listaRecibos     = [];
$listaAjustes     = [];
$clientesList     = [];
$metodosPagoList  = [];
$cuentasBancarias = [];
$monedasList      = [];
$supervisoresList = [];
$sesionCajaActiva = null;

if ($pdo) {
    // 1. KPIs Financieros de Cartera CxC (Exactamente 4 indicadores)
    try {
        $stmtKpi = $pdo->query("
            SELECT 
                COALESCE(SUM(saldo_pendiente), 0) AS total_deuda,
                COUNT(CASE WHEN fecha_vencimiento < CURRENT_DATE THEN 1 END) AS vencidas_cnt,
                COALESCE(SUM(CASE WHEN fecha_vencimiento < CURRENT_DATE THEN saldo_pendiente ELSE 0 END), 0) AS vencidas_mto,
                COUNT(CASE WHEN fecha_vencimiento >= CURRENT_DATE THEN 1 END) AS al_dia_cnt,
                COALESCE(SUM(CASE WHEN fecha_vencimiento >= CURRENT_DATE THEN saldo_pendiente ELSE 0 END), 0) AS al_dia_mto
            FROM ventas.cuentas_por_cobrar
            WHERE estado IN ('PENDIENTE', 'PAGADA_PARCIAL')
        ");
        $kRow = $stmtKpi ? $stmtKpi->fetch(PDO::FETCH_ASSOC) : null;
        if ($kRow) {
            $kpiTotalDeuda   = (float)$kRow['total_deuda'];
            $kpiVencidasCnt  = (int)$kRow['vencidas_cnt'];
            $kpiVencidasMto  = (float)$kRow['vencidas_mto'];
            $kpiAlDiaCnt     = (int)$kRow['al_dia_cnt'];
            $kpiAlDiaMto     = (float)$kRow['al_dia_mto'];
        }
    } catch (Throwable $e) {}

    // Cobros / Recibos de ingreso liquidados del mes actual
    try {
        $stmtCobrosMes = $pdo->query("
            SELECT COALESCE(SUM(monto_total_recibido), 0)
            FROM ventas.recibos_ingreso_cxc
            WHERE DATE_TRUNC('month', fecha_recibo) = DATE_TRUNC('month', CURRENT_DATE)
        ");
        $kpiCobrosMesMto = $stmtCobrosMes ? (float)$stmtCobrosMes->fetchColumn() : 0.00;
    } catch (Throwable $e) {}

    // 2. Sesión de caja abierta del cajero actual (Para Vía A: cobro en mostrador)
    try {
        $stmtSes = $pdo->prepare("
            SELECT sc.id, sc.caja_id, c.nombre_equipo
            FROM ventas.sesiones_caja sc
            JOIN ventas.cajas_pos c ON c.id = sc.caja_id
            WHERE (sc.cajero_id = ? OR sc.cajero_id = ?) AND sc.estado = 'ABIERTA'
            ORDER BY sc.id DESC LIMIT 1
        ");
        $stmtSes->execute([$usuarioId, $personaId]);
        $sesionCajaActiva = $stmtSes->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    // 3. Catálogos
    try {
        $stmtCl = $pdo->query("
            SELECT c.persona_id, c.codigo_cliente, c.categoria_cliente, c.limite_credito,
                   p.nombre_razon_social, p.documento_identidad, p.telefono_principal
            FROM identidad.clientes c
            JOIN identidad.personas p ON p.persona_id = c.persona_id
            WHERE NOT EXISTS (SELECT 1 FROM identidad.empleados e WHERE e.persona_id = c.persona_id)
              AND NOT EXISTS (SELECT 1 FROM seguridad.usuarios u WHERE u.persona_id = c.persona_id)
            ORDER BY p.nombre_razon_social ASC
        ");
        $clientesList = $stmtCl ? $stmtCl->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    try {
        $stmtMp = $pdo->query("
            SELECT id, codigo, descripcion
            FROM ventas.metodos_pago
            ORDER BY id ASC
        ");
        $metodosPagoList = $stmtMp ? $stmtMp->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    try {
        $stmtCb = $pdo->query("
            SELECT id, nombre_banco, numero_cuenta, moneda_id
            FROM tesoreria.cuentas_bancarias
            WHERE activa = true
            ORDER BY nombre_banco ASC
        ");
        $cuentasBancarias = $stmtCb ? $stmtCb->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    try {
        $stmtMon = $pdo->query("
            SELECT id, codigo_iso, simbolo
            FROM tesoreria.monedas
            WHERE activa = true
            ORDER BY id ASC
        ");
        $monedasList = $stmtMon ? $stmtMon->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    try {
        $supervisoresList = obtenerSupervisoresAutorizados($pdo);
    } catch (Throwable $e) {}

    // 4. Listado Principal de Cuentas por Cobrar (Fases 1 y 2)
    try {
        $stmtCxc = $pdo->query("
            SELECT cxc.id,
                   cxc.cliente_id,
                   cxc.factura_id,
                   cxc.nota_debito_id,
                   cxc.monto_original,
                   cxc.saldo_pendiente,
                   cxc.estado,
                   cxc.fecha_emision,
                   cxc.fecha_vencimiento,
                   cxc.moneda_id,
                   cxc.tasa_cambio,
                   m.simbolo AS moneda_simbolo,
                   p.nombre_razon_social AS cliente_nombre,
                   p.documento_identidad AS cliente_rnc,
                   cl.codigo_cliente,
                   cl.categoria_cliente,
                   COALESCE(f.numero_factura, 'ND-' || nd.ncf_nota_debito) AS numero_documento,
                   COALESCE(f.ncf, nd.ncf_nota_debito) AS ncf,
                   CASE 
                       WHEN cxc.factura_id IS NOT NULL THEN 'Factura a Crédito #' || f.numero_factura
                       ELSE 'Nota de Débito (B03): ' || nd.concepto_cargo
                   END AS concepto,
                   (CURRENT_DATE - cxc.fecha_vencimiento) AS dias_diferencia
            FROM ventas.cuentas_por_cobrar cxc
            JOIN identidad.clientes cl ON cl.persona_id = cxc.cliente_id
            JOIN identidad.personas p ON p.persona_id = cl.persona_id
            JOIN tesoreria.monedas m ON m.id = cxc.moneda_id
            LEFT JOIN ventas.facturas f ON f.id = cxc.factura_id
            LEFT JOIN ventas.notas_debito nd ON nd.id = cxc.nota_debito_id
            ORDER BY 
                CASE WHEN cxc.estado IN ('PENDIENTE', 'PAGADA_PARCIAL') THEN 0 ELSE 1 END,
                cxc.fecha_vencimiento ASC,
                cxc.id DESC
        ");
        $listaCxc = $stmtCxc ? $stmtCxc->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    // 5. Historial de Recibos de Ingreso (Fase 3)
    try {
        $stmtRec = $pdo->query("
            SELECT r.id,
                   r.numero_recibo,
                   r.fecha_recibo,
                   r.monto_total_recibido,
                   r.saldo_a_favor_generado,
                   m.simbolo AS moneda_simbolo,
                   p.nombre_razon_social AS cliente_nombre,
                   p.documento_identidad AS cliente_rnc,
                   u.username AS cajero_username,
                   cpos.nombre_equipo AS caja_nombre,
                   (SELECT COUNT(*) FROM ventas.recibo_ingreso_detalles WHERE recibo_ingreso_id = r.id) AS total_facturas_canceladas
            FROM ventas.recibos_ingreso_cxc r
            JOIN identidad.clientes cl ON cl.persona_id = r.cliente_id
            JOIN identidad.personas p ON p.persona_id = cl.persona_id
            JOIN tesoreria.monedas m ON m.id = r.moneda_id
            LEFT JOIN seguridad.usuarios u ON u.persona_id = r.cajero_id
            LEFT JOIN ventas.sesiones_caja sc ON sc.id = r.sesion_caja_id
            LEFT JOIN ventas.cajas_pos cpos ON cpos.id = sc.caja_id
            ORDER BY r.fecha_recibo DESC, r.id DESC
            LIMIT 100
        ");
        $listaRecibos = $stmtRec ? $stmtRec->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    // 6. Historial de Ajustes Administrativos e Incobrables (Fase 4)
    try {
        $stmtAj = $pdo->query("
            SELECT aj.id,
                   aj.cuenta_por_cobrar_id,
                   aj.tipo_ajuste,
                   aj.monto_ajuste,
                   aj.saldo_anterior,
                   aj.saldo_restante,
                   aj.justificacion,
                   aj.fecha_ajuste,
                   u.username AS supervisor_username,
                   p.nombre_razon_social AS cliente_nombre,
                   COALESCE(f.numero_factura, 'ND-' || nd.ncf_nota_debito) AS numero_documento
            FROM ventas.ajustes_cxc aj
            JOIN ventas.cuentas_por_cobrar cxc ON cxc.id = aj.cuenta_por_cobrar_id
            JOIN identidad.clientes cl ON cl.persona_id = cxc.cliente_id
            JOIN identidad.personas p ON p.persona_id = cl.persona_id
            LEFT JOIN ventas.facturas f ON f.id = cxc.factura_id
            LEFT JOIN ventas.notas_debito nd ON nd.id = cxc.nota_debito_id
            JOIN seguridad.usuarios u ON u.usuario_id = aj.supervisor_id
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
    <title>House &amp; Ice • Cuentas por Cobrar (CxC)</title>
    <link rel="stylesheet" href="../styles.css?v=31" />
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
                <span class="brand-subtitle">Cuentas por Cobrar (CxC)</span>
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
                    <h1 class="page-title">💳 Cuentas por Cobrar (CxC)</h1>
                    <p class="page-subtitle">
                        Administración de crédito corporativo y VIP, Split Universal de cobros auditados y ajustes contables.
                    </p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary" onclick="abrirModalCargoNotaDebito()" style="background: #a66e2e; color: #ffffff !important; border: 1px solid #8e5c23; font-weight: 700; box-shadow: 0 2px 8px rgba(166, 110, 46, 0.35); display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; border-radius: 6px;">
                        <span style="font-weight: 900; font-size: 1.1rem; color: #ffffff !important;">➕</span> <span style="color: #ffffff !important; font-weight: 700;">Registrar Cargo a Cliente</span>
                    </button>
                    <button type="button" class="btn btn-primary" id="btnTopCobrarSeleccion" onclick="abrirModalCobro()" style="display: none; background: #a66e2e; color: #ffffff !important; font-weight: 700;">
                        <span>💳</span> Cobrar Selección (<span id="txtTopCantSeleccionadas">0</span>)
                    </button>
                </div>
            </div>

            <!-- TARJETAS DE INDICADORES (KPIS: EXACTAMENTE 4 TARJETAS) -->
            <div class="cxc-kpi-container">
                <div class="cxc-kpi-card">
                    <div class="cxc-kpi-icon gold">💰</div>
                    <div class="cxc-kpi-data">
                        <h4>Deuda Total por Cobrar</h4>
                        <div class="cxc-kpi-value">RD$ <?= number_format($kpiTotalDeuda, 2) ?></div>
                        <div class="cxc-kpi-sub">Cartera de clientes a crédito</div>
                    </div>
                </div>

                <div class="cxc-kpi-card">
                    <div class="cxc-kpi-icon red">⚠️</div>
                    <div class="cxc-kpi-data">
                        <h4>Facturas Vencidas</h4>
                        <div class="cxc-kpi-value" style="color: #b53d32;"><?= $kpiVencidasCnt ?> facturas</div>
                        <div class="cxc-kpi-sub">RD$ <?= number_format($kpiVencidasMto, 2) ?> en mora</div>
                    </div>
                </div>

                <div class="cxc-kpi-card">
                    <div class="cxc-kpi-icon green">📅</div>
                    <div class="cxc-kpi-data">
                        <h4>Por Vencer / En Plazo</h4>
                        <div class="cxc-kpi-value" style="color: #2e7d52;"><?= $kpiAlDiaCnt ?> facturas</div>
                        <div class="cxc-kpi-sub">RD$ <?= number_format($kpiAlDiaMto, 2) ?> vigentes</div>
                    </div>
                </div>

                <div class="cxc-kpi-card">
                    <div class="cxc-kpi-icon blue">🏦</div>
                    <div class="cxc-kpi-data">
                        <h4>Cobros del Mes</h4>
                        <div class="cxc-kpi-value" style="color: #1e6091;">RD$ <?= number_format($kpiCobrosMesMto, 2) ?></div>
                        <div class="cxc-kpi-sub">Ingresos liquidados por recibo</div>
                    </div>
                </div>
            </div>

            <!-- TABS DE NAVEGACIÓN -->
            <div class="cxc-tabs">
                <button type="button" class="cxc-tab-btn active" onclick="cambiarTabCxc('cartera', this)">
                    📋 Cartera y Antigüedad de Saldos
                </button>
                <button type="button" class="cxc-tab-btn" onclick="cambiarTabCxc('recibos', this)">
                    🧾 Historial de Recibos de Ingreso
                </button>
                <button type="button" class="cxc-tab-btn" onclick="cambiarTabCxc('ajustes', this)">
                    ⚖️ Ajustes, Condonaciones e Incobrables
                </button>
            </div>

            <!-- ========================================================================= -->
            <!-- TAB 1: CARTERA Y ANTIGÜEDAD DE CUENTAS POR COBRAR                         -->
            <!-- ========================================================================= -->
            <div id="tab-cartera" class="cxc-tab-content active">
                <!-- BARRA DE FILTROS -->
                <div class="cxc-filter-bar">
                    <div class="cxc-filter-group" style="flex: 2; min-width: 200px;">
                        <label>Buscar Cliente, NCF o Factura</label>
                        <input type="text" id="filtroTexto" class="form-control" placeholder="Escriba documento, NCF, nombre de cliente..." oninput="filtrarTablaCxc()" />
                    </div>

                    <div class="cxc-filter-group" style="flex: 1.5; min-width: 180px;">
                        <label>Filtrar por Cliente</label>
                        <select id="filtroCliente" class="form-control" onchange="filtrarTablaCxc()">
                            <option value="">-- Todos los Clientes --</option>
                            <?php foreach ($clientesList as $cl): ?>
                                <option value="<?= htmlspecialchars($cl['persona_id']) ?>">
                                    <?= htmlspecialchars($cl['nombre_razon_social']) ?> (<?= htmlspecialchars($cl['categoria_cliente']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cxc-filter-group" style="flex: 1.2; min-width: 160px;">
                        <label>Estado de Deuda</label>
                        <select id="filtroEstado" class="form-control" onchange="filtrarTablaCxc()">
                            <option value="PENDIENTES">Solo Pendientes y Parciales</option>
                            <option value="TODOS">Todos los Estados</option>
                            <option value="VENCIDAS">⚠️ Solo Facturas Vencidas</option>
                            <option value="PAGADA">● Solo Saldadas</option>
                            <option value="TERMINALES">✕ Incobrables / Anuladas</option>
                        </select>
                    </div>

                    <div>
                        <button type="button" class="btn btn-secondary" onclick="limpiarFiltrosCxc()" style="padding: 8px 14px;">
                            Limpiar
                        </button>
                    </div>
                </div>

                <!-- TABLA DE CARTERA -->
                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Cartera de Cuentas por Cobrar</h2>
                            <span class="badge-count" id="badgeTotalCxc"><?= count($listaCxc) ?> deudas</span>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">
                            Selecciona una o varias facturas del mismo cliente para cobrar en lote
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table" id="tablaCxc">
                            <thead>
                                <tr>
                                    <th style="width: 44px; text-align: center;">
                                        <input type="checkbox" id="chkSelectAll" onchange="toggleSelectAllCxc(this)" title="Seleccionar visibles" />
                                    </th>
                                    <th>Documento / NCF</th>
                                    <th>Cliente Corporativo</th>
                                    <th>Concepto / Origen</th>
                                    <th>Emisión</th>
                                    <th>Vencimiento</th>
                                    <th>Antigüedad</th>
                                    <th class="text-right">Monto Orig.</th>
                                    <th class="text-right">Saldo Pendiente</th>
                                    <th class="text-center">Estado</th>
                                    <th class="text-center" style="min-width: 140px;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaCxc)): ?>
                                    <tr>
                                        <td colspan="11" style="text-align: center; padding: 36px; color: var(--text-muted);">
                                            No hay cuentas por cobrar registradas en el sistema.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaCxc as $cxc): 
                                        $diasDif = (int)$cxc['dias_diferencia'];
                                        $esPendiente = in_array($cxc['estado'], ['PENDIENTE', 'PAGADA_PARCIAL']);

                                        // Semáforo de antigüedad
                                        if ($cxc['estado'] === 'ANULADA') {
                                            $badgeAntiguedad = '<span class="badge-anulada">ANULADA</span>';
                                        } elseif ($cxc['estado'] === 'INCOBRABLE') {
                                            $badgeAntiguedad = '<span class="badge-incobrable">INCOBRABLE</span>';
                                        } elseif ($cxc['estado'] === 'PAGADA') {
                                            $badgeAntiguedad = '<span class="badge-pagada">SALDADA</span>';
                                        } elseif ($diasDif > 0) {
                                            $badgeAntiguedad = '<span class="badge-vencida">⚠️ Vencida +' . $diasDif . ' d</span>';
                                        } elseif ($diasDif === 0) {
                                            $badgeAntiguedad = '<span class="badge-hoy">⏰ Vence Hoy</span>';
                                        } else {
                                            $badgeAntiguedad = '<span class="badge-al-dia">● ' . abs($diasDif) . ' d restantes</span>';
                                        }
                                    ?>
                                        <tr class="fila-cxc"
                                            data-id="<?= $cxc['id'] ?>"
                                            data-cliente-id="<?= htmlspecialchars($cxc['cliente_id']) ?>"
                                            data-cliente-nombre="<?= htmlspecialchars($cxc['cliente_nombre']) ?>"
                                            data-documento="<?= htmlspecialchars($cxc['numero_documento']) ?>"
                                            data-ncf="<?= htmlspecialchars($cxc['ncf']) ?>"
                                            data-saldo="<?= (float)$cxc['saldo_pendiente'] ?>"
                                            data-estado="<?= htmlspecialchars($cxc['estado']) ?>"
                                            data-vencido="<?= ($diasDif > 0) ? '1' : '0' ?>"
                                            data-hoy="<?= ($diasDif === 0) ? '1' : '0' ?>">
                                            
                                            <td style="text-align: center;">
                                                <?php if ($esPendiente): ?>
                                                    <input type="checkbox" class="chk-cxc-item" 
                                                           value="<?= $cxc['id'] ?>" 
                                                           data-cliente="<?= htmlspecialchars($cxc['cliente_id']) ?>"
                                                           data-saldo="<?= (float)$cxc['saldo_pendiente'] ?>"
                                                           onchange="alCambiarSeleccionItemCxc(this)" />
                                                <?php else: ?>
                                                    <span style="color: #ccc;">—</span>
                                                <?php endif; ?>
                                            </td>

                                            <td style="white-space: nowrap;">
                                                <div style="font-weight: 700; color: var(--text-dark); font-size: 0.88rem;">
                                                    <?= htmlspecialchars($cxc['numero_documento']) ?>
                                                </div>
                                                <div class="cell-sub" style="font-family: var(--font-mono); font-size: 0.76rem; color: var(--accent-gold); font-weight: 600;">
                                                    NCF: <?= htmlspecialchars($cxc['ncf']) ?>
                                                </div>
                                            </td>

                                            <td>
                                                <strong style="font-size: 0.88rem;"><?= htmlspecialchars($cxc['cliente_nombre']) ?></strong>
                                                <div class="cell-sub">
                                                    <?= htmlspecialchars($cxc['codigo_cliente']) ?> • <?= htmlspecialchars($cxc['cliente_rnc'] ?: 'S/Documento') ?>
                                                    <span style="background: #eee; border-radius: 4px; padding: 1px 4px; font-size: 0.7rem; font-weight: 700; margin-left: 4px;"><?= htmlspecialchars($cxc['categoria_cliente']) ?></span>
                                                </div>
                                            </td>

                                            <td>
                                                <div style="font-size: 0.84rem;"><?= htmlspecialchars($cxc['concepto']) ?></div>
                                            </td>

                                            <td style="font-size: 0.82rem; white-space: nowrap;"><?= htmlspecialchars($cxc['fecha_emision']) ?></td>
                                            <td style="font-size: 0.82rem; white-space: nowrap;"><?= htmlspecialchars($cxc['fecha_vencimiento']) ?></td>

                                            <td style="white-space: nowrap;">
                                                <?= $badgeAntiguedad ?>
                                            </td>

                                            <td class="text-right" style="font-family: var(--font-mono); font-size: 0.88rem;">
                                                RD$ <?= number_format($cxc['monto_original'], 2) ?>
                                            </td>

                                            <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; font-size: 0.95rem; color: <?= ($cxc['saldo_pendiente'] > 0) ? '#b53d32' : '#2e7d52' ?>;">
                                                RD$ <?= number_format($cxc['saldo_pendiente'], 2) ?>
                                            </td>

                                            <td class="text-center">
                                                <?php if ($cxc['estado'] === 'PENDIENTE'): ?>
                                                    <span class="badge badge-warning" style="font-size: 0.72rem;">PENDIENTE</span>
                                                <?php elseif ($cxc['estado'] === 'PAGADA_PARCIAL'): ?>
                                                    <span class="badge badge-info" style="font-size: 0.72rem;">ABONADA</span>
                                                <?php elseif ($cxc['estado'] === 'PAGADA'): ?>
                                                    <span class="badge badge-success" style="font-size: 0.72rem;">PAGADA</span>
                                                <?php elseif ($cxc['estado'] === 'INCOBRABLE'): ?>
                                                    <span class="badge badge-danger" style="font-size: 0.72rem; background: #b71c1c; color:#fff;">INCOBRABLE</span>
                                                <?php else: ?>
                                                    <span class="badge" style="background:#796e65; color:#fff; font-size: 0.72rem;">ANULADA</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-center">
                                                <div style="display: flex; gap: 6px; justify-content: center;">
                                                    <?php if ($esPendiente): ?>
                                                        <button type="button" class="btn btn-sm btn-primary" onclick="iniciarCobroUnico(<?= $cxc['id'] ?>, '<?= htmlspecialchars($cxc['cliente_id']) ?>')" title="Cobrar esta factura">
                                                            <span>💳</span> Cobrar
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-secondary" onclick="abrirModalAjusteCxc(<?= $cxc['id'] ?>, '<?= htmlspecialchars(addslashes($cxc['cliente_nombre'])) ?>', <?= (float)$cxc['saldo_pendiente'] ?>)" title="Ajuste, Condonación o Incobrable">
                                                            <span>⚖️</span>
                                                        </button>
                                                    <?php else: ?>
                                                        <span style="font-size: 0.78rem; color: var(--text-muted);">Saldada</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- BARRA FLOTANTE INFERIOR DE ACCIÓN CONSOLIDADA -->
                <div class="cxc-floating-bar" id="cxcFloatingBar">
                    <div>
                        <span style="font-weight: 700; font-size: 0.95rem;">
                            <span id="txtSeleccionadasCant">0</span> facturas seleccionadas
                        </span>
                        <div style="font-size: 0.85rem; color: #c8beb2;">
                            Cliente: <strong id="txtSeleccionadasCliente" style="color: #fff;">—</strong>
                        </div>
                        <div class="cxc-selection-total">
                            Total a Cobrar: <span id="txtSeleccionadasTotal">RD$ 0.00</span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button type="button" class="btn" onclick="limpiarSeleccionCxc()" style="background: transparent; color: #e2dacb; border: 1px solid rgba(255,255,255,0.3); padding: 8px 14px;">
                            <span>✕</span> Cancelar Selección
                        </button>
                        <button type="button" class="btn btn-primary" onclick="abrirModalCobro()" style="background: #a66e2e; color: #ffffff; font-weight: 700; padding: 8px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.3);">
                            <span>💳</span> Procesar Recibo de Ingreso
                        </button>
                    </div>
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- TAB 2: HISTORIAL DE RECIBOS DE INGRESO (FASE 3)                           -->
            <!-- ========================================================================= -->
            <div id="tab-recibos" class="cxc-tab-content">
                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Recibos de Ingreso de Cuentas por Cobrar</h2>
                            <span class="badge-count"><?= count($listaRecibos) ?> recibos</span>
                        </div>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">Cobros consolidados con Split Universal (Caja física y Bancos)</span>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>No. Recibo</th>
                                    <th>Fecha y Hora</th>
                                    <th>Cliente</th>
                                    <th>Docs Saldados</th>
                                    <th class="text-right">Total Cobrado</th>
                                    <th class="text-right">Saldo a Favor</th>
                                    <th>Cajero / Turno</th>
                                    <th class="text-center">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaRecibos)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 32px; color: var(--text-muted);">
                                            No se han emitido recibos de ingreso todavía.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaRecibos as $rec): ?>
                                        <tr>
                                            <td>
                                                <strong class="code-inline" style="font-size: 0.88rem; color: var(--accent-gold);">
                                                    <?= htmlspecialchars($rec['numero_recibo']) ?>
                                                </strong>
                                            </td>
                                            <td style="font-size: 0.84rem;"><?= htmlspecialchars(substr($rec['fecha_recibo'], 0, 16)) ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($rec['cliente_nombre']) ?></strong>
                                                <div class="cell-sub">RNC/Céd: <?= htmlspecialchars($rec['cliente_rnc'] ?: 'S/D') ?></div>
                                            </td>
                                            <td>
                                                <span class="badge-al-dia"><?= $rec['total_facturas_canceladas'] ?> docs</span>
                                            </td>
                                            <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: #2e7d52;">
                                                RD$ <?= number_format($rec['monto_total_recibido'], 2) ?>
                                            </td>
                                            <td class="text-right" style="font-family: var(--font-mono); color: #b78103;">
                                                <?= ($rec['saldo_a_favor_generado'] > 0) ? 'RD$ ' . number_format($rec['saldo_a_favor_generado'], 2) : '—' ?>
                                            </td>
                                            <td style="font-size: 0.82rem;">
                                                <?= htmlspecialchars($rec['cajero_username'] ?: 'Cajero') ?>
                                                <?php if (!empty($rec['caja_nombre'])): ?>
                                                    <div class="cell-sub">Caja: <?= htmlspecialchars($rec['caja_nombre']) ?></div>
                                                <?php else: ?>
                                                    <div class="cell-sub" style="color: #1e6091;">(Vía Bancaria)</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-secondary" onclick="verDetalleRecibo(<?= $rec['id'] ?>)">
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

            <!-- ========================================================================= -->
            <!-- TAB 3: AJUSTES, CONDONACIONES E INCOBRABLES (FASE 4)                      -->
            <!-- ========================================================================= -->
            <div id="tab-ajustes" class="cxc-tab-content">
                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Auditoría de Ajustes y Cuentas Incobrables</h2>
                            <span class="badge-count"><?= count($listaAjustes) ?> ajustes</span>
                        </div>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">Rebajas contables no monetarias autorizadas con PIN de supervisor</span>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Fecha / Hora</th>
                                    <th>Cliente</th>
                                    <th>Documento</th>
                                    <th>Tipo de Ajuste</th>
                                    <th class="text-right">Monto Rebajado</th>
                                    <th class="text-right">Saldo Restante</th>
                                    <th>Supervisor PIN</th>
                                    <th>Justificación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaAjustes)): ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center; padding: 32px; color: var(--text-muted);">
                                            No se han registrado ajustes administrativos ni castigos de cartera.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaAjustes as $aj): ?>
                                        <tr>
                                            <td>#<?= $aj['id'] ?></td>
                                            <td style="font-size: 0.82rem;"><?= htmlspecialchars(substr($aj['fecha_ajuste'], 0, 16)) ?></td>
                                            <td><strong><?= htmlspecialchars($aj['cliente_nombre']) ?></strong></td>
                                            <td><span class="code-inline" style="font-size: 0.82rem;"><?= htmlspecialchars($aj['numero_documento']) ?></span></td>
                                            <td>
                                                <?php if ($aj['tipo_ajuste'] === 'INCOBRABLE'): ?>
                                                    <span class="badge badge-danger" style="background: #b71c1c; color:#fff;">INCOBRABLE</span>
                                                <?php elseif ($aj['tipo_ajuste'] === 'ANULACION_FACTURA'): ?>
                                                    <span class="badge" style="background: #796e65; color:#fff;">ANULACIÓN</span>
                                                <?php elseif ($aj['tipo_ajuste'] === 'CONDONACION'): ?>
                                                    <span class="badge badge-warning">CONDONACIÓN</span>
                                                <?php else: ?>
                                                    <span class="badge badge-info"><?= htmlspecialchars($aj['tipo_ajuste']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: #b53d32;">
                                                RD$ <?= number_format($aj['monto_ajuste'], 2) ?>
                                            </td>
                                            <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: <?= ($aj['saldo_restante'] > 0) ? '#b53d32' : '#2e7d52' ?>;">
                                                RD$ <?= number_format($aj['saldo_restante'], 2) ?>
                                            </td>
                                            <td style="font-size: 0.82rem; font-weight: 600;">
                                                🛡️ <?= htmlspecialchars($aj['supervisor_username']) ?>
                                            </td>
                                            <td style="font-size: 0.8rem; color: var(--text-muted); max-width: 200px;">
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
    <!-- MODAL 1: RECIBO DE INGRESO CON SPLIT UNIVERSAL (FASES 2 & 3)              -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalCobro">
        <div class="modal-dialog modal-lg">
            <div class="modal-header">
                <h3><span>💳</span> Emisión de Recibo de Ingreso (Split Universal)</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalCobro')">&times;</button>
            </div>
            <form id="formCobro" onsubmit="procesarLiquidacionCobro(event)">
                <div class="modal-body">
                    <!-- Resumen del Cliente -->
                    <div style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <div style="font-size: 0.76rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Cliente Seleccionado</div>
                            <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-dark);" id="modalCobroClienteNombre">—</div>
                        </div>
                        <div style="display: flex; gap: 24px;">
                            <div>
                                <div style="font-size: 0.74rem; color: var(--text-muted); text-transform: uppercase;">Total a Cancelar</div>
                                <div style="font-size: 1.2rem; font-weight: 700; font-family: var(--font-mono); color: #2e7d52;" id="modalTotalCobrar">RD$ 0.00</div>
                            </div>
                            <div>
                                <div style="font-size: 0.74rem; color: var(--text-muted); text-transform: uppercase;">Total Recibido (Split)</div>
                                <div style="font-size: 1.2rem; font-weight: 700; font-family: var(--font-mono); color: #1e6091;" id="modalTotalRecibidoSplit">RD$ 0.00</div>
                            </div>
                            <div>
                                <div style="font-size: 0.74rem; color: #b78103; text-transform: uppercase;">Excedente (Saldo a Favor)</div>
                                <div style="font-size: 1.2rem; font-weight: 700; font-family: var(--font-mono); color: #b78103;" id="modalTotalExcedente">RD$ 0.00</div>
                            </div>
                        </div>
                    </div>

                    <!-- 1. Facturas y Cargos a Cancelar -->
                    <h4 style="font-size: 0.92rem; font-weight: 700; margin-bottom: 10px; color: var(--text-dark);">
                        1. Documentos y Facturas a Cancelar
                    </h4>
                    <div class="table-wrapper" style="margin-bottom: 20px; border: 1px solid var(--border-light); border-radius: 6px;">
                        <table class="data-table" style="font-size: 0.85rem;" id="tablaFacturasCobro">
                            <thead>
                                <tr>
                                    <th>Documento</th>
                                    <th>NCF</th>
                                    <th>Concepto</th>
                                    <th class="text-right">Saldo Actual</th>
                                    <th class="text-right" style="width: 150px;">Monto a Cobrar</th>
                                    <th class="text-right" style="width: 120px;">Saldo Restante</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyFacturasCobro">
                                <!-- Inyectado dinámicamente -->
                            </tbody>
                        </table>
                    </div>

                    <!-- 2. Split Universal de Instrumentos de Pago -->
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <div>
                                <h4 style="font-size: 0.92rem; font-weight: 700; color: var(--text-dark); margin-bottom: 2px;">
                                    2. Instrumentos de Pago (Split Universal)
                                </h4>
                                <div style="font-size: 0.76rem; color: var(--text-muted);">
                                    Vía A: Efectivo/Tarjeta en mostrador (asocia caja activa). Vía B: Transferencia/Cheque (asiento banco automático).
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="agregarLineaSplitCobro()">
                                <span>➕</span> Agregar Instrumento
                            </button>
                        </div>

                        <div id="contenedorSplitCobros" style="margin-bottom: 12px;">
                            <!-- Inyectado dinámicamente -->
                        </div>

                        <!-- Barra de Cuadre del Split -->
                        <div style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 6px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <span style="font-size: 0.82rem; color: var(--text-muted);">Total en Instrumentos:</span>
                                <strong style="font-family: var(--font-mono); font-size: 1rem; margin-left: 6px;" id="lblTotalInstrumentos">RD$ 0.00</strong>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <button type="button" class="btn btn-sm btn-secondary" onclick="autoCuadrarSplitCobro()" style="font-size: 0.75rem; padding: 3px 8px;" title="Ajusta el primer método al total a cobrar">
                                    ⚖️ Ajustar al Total
                                </button>
                                <div id="lblEstadoCuadreCobro" style="font-size: 0.85rem; font-weight: 700; color: #b53d32;">
                                    ⚠️ Faltan RD$ 0.00 por cubrir
                                </div>
                            </div>
                        </div>

                        <!-- Botón de acción directo -->
                        <div style="margin-top: 14px; padding: 12px 16px; background: #eef8f2; border: 1px solid #c2e5d1; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <div style="font-size: 0.85rem; font-weight: 700; color: #2e7d52;">● Listo para Registrar Ingreso</div>
                                <div style="font-size: 0.76rem; color: var(--text-muted);">Se emitirá el recibo y se actualizarán las deudas y movimientos.</div>
                            </div>
                            <button type="submit" class="btn btn-primary" id="btnConfirmarCobro" style="background: #2e7d52; color: #ffffff; font-weight: 700; font-size: 0.95rem; padding: 10px 22px; box-shadow: 0 2px 8px rgba(46, 125, 82, 0.3);">
                                <span>💰</span> EMITIR RECIBO DE INGRESO
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 2: AJUSTE ADMINISTRATIVO, CONDONACIÓN E INCOBRABLE (FASE 4)         -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalAjusteCxc">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3><span>⚖️</span> Ajuste Contable y Castigo de Cartera (CxC)</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalAjusteCxc')">&times;</button>
            </div>
            <form id="formAjusteCxc" onsubmit="procesarAjusteCxc(event)">
                <input type="hidden" id="ajusteCxcId" name="cxc_id" />
                <div class="modal-body">
                    <div style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px;">
                        <div style="font-size: 0.76rem; color: var(--text-muted);">Cliente:</div>
                        <div style="font-weight: 700; font-size: 0.95rem;" id="ajusteClienteNombre">—</div>
                        <div style="font-size: 0.82rem; margin-top: 4px;">
                            Saldo Actual Pendiente: <strong id="ajusteSaldoActual" style="font-family: var(--font-mono); color: #b53d32;">RD$ 0.00</strong>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Tipo de Ajuste Contable <span style="color:red;">*</span></label>
                        <select name="tipo_ajuste" id="tipoAjusteCxc" class="form-control" required onchange="alCambiarTipoAjusteCxc()">
                            <option value="DESCUENTO_PRONTO_PAGO">DESCUENTO_PRONTO_PAGO (Acuerdo comercial de descuento)</option>
                            <option value="CONDONACION">CONDONACION (Exoneración parcial o total)</option>
                            <option value="INCOBRABLE">INCOBRABLE (Castigo total de cartera irrecuperable)</option>
                            <option value="NOTA_CREDITO_B04">NOTA_CREDITO_B04 (Rebaja fiscal por devolución/bonificación)</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Monto del Ajuste (RD$) <span style="color:red;">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="monto_ajuste" id="inputMontoAjusteCxc" class="form-control" required style="font-family: var(--font-mono); font-weight: 700; font-size: 1rem;" />
                        <small style="color: var(--text-muted); font-size: 0.74rem;">Si el ajuste llega a 0.00 la cuenta pasará a PAGADA o INCOBRABLE.</small>
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Justificación Contable Obligatoria <span style="color:red;">*</span></label>
                        <textarea name="justificacion" id="justificacionAjusteCxc" class="form-control" rows="2" placeholder="Describa el motivo, acuerdo comercial o dictamen de cobro legal..." required></textarea>
                    </div>

                    <!-- Autenticación de Supervisor por PIN -->
                    <div style="background: #fdf5ea; border: 1px solid #f0d5b0; border-radius: 6px; padding: 12px; margin-bottom: 12px;">
                        <div style="font-size: 0.78rem; font-weight: 700; color: #a66e2e; margin-bottom: 8px; display: flex; align-items: center; gap: 5px;">
                            <span>🔒</span> AUTORIZACIÓN DE SUPERVISOR
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <div>
                                <label style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600;">Supervisor:</label>
                                <select name="supervisor_id" id="supervisorIdCxc" class="form-control" required style="font-size: 0.8rem;">
                                    <?php foreach ($supervisoresList as $sup): ?>
                                        <option value="<?= htmlspecialchars($sup['usuario_id']) ?>">
                                            <?= htmlspecialchars($sup['nombre_completo']) ?> (<?= htmlspecialchars($sup['username']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600;">PIN de Autorización:</label>
                                <input type="password" name="pin_supervisor" id="pinSupervisorCxc" class="form-control" maxlength="6" placeholder="••••" required style="letter-spacing: 3px; font-weight: 700; text-align: center;" />
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalAjusteCxc')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnConfirmarAjusteCxc">Aplicar Ajuste Contable</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 3: REGISTRAR CARGO A CLIENTE (FASE 1)                           -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalCargo">
        <div class="modal-dialog" style="max-width: 540px;">
            <div class="modal-header">
                <h3><span>📄</span> Registrar Cargo a Cliente</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalCargo')">&times;</button>
            </div>
            <form id="formCargo" onsubmit="procesarRegistroCargo(event)">
                <div class="modal-body" style="overflow: visible;">
                    <!-- SELECTOR BUSCABLE DE CLIENTE -->
                    <div class="form-group" style="margin-bottom: 14px; position: relative;">
                        <label class="form-label">Cliente Registrado <span style="color:red;">*</span></label>
                        <input type="hidden" name="cliente_id" id="cxcCargoClienteId" required value="" />
                        
                        <div id="boxBusquedaClienteCxc" style="position: relative;">
                            <input type="text" id="inputBuscarClienteCxc" class="form-control" placeholder="🔍 Escriba nombre, RNC/Cédula o tipo de cliente..." autocomplete="off" oninput="filtrarClientesCxc(this.value)" onfocus="abrirDropdownClientesCxc()" style="height: 36px; font-size: 0.85rem;" />
                            <div id="dropdownClientesCxc" style="display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 200px; overflow-y: auto; background: #ffffff; border: 1.5px solid #d4c1a7; border-radius: 6px; box-shadow: 0 8px 24px rgba(0,0,0,0.22); z-index: 1050; margin-top: 2px;">
                                <!-- Inyectado dinámicamente -->
                            </div>
                        </div>

                        <div id="badgeClienteElegidoCxc" style="display: none; background: #fdf5ea; border: 1px solid #dfcaa4; border-radius: 6px; padding: 4px 10px; align-items: center; justify-content: space-between; height: 36px;">
                            <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.84rem; color: #211813;">
                                <strong id="txtNombreClienteCxc">—</strong>
                                <small id="txtDetalleClienteCxc" style="color: #7a6b5d; margin-left: 6px;"></small>
                            </div>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="limpiarClienteSeleccionadoCxc()" style="padding: 1px 6px; font-size: 0.72rem; height: 22px; flex-shrink: 0;">✕ Cambiar</button>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                        <div class="form-group">
                            <label class="form-label">Monto del Cargo (RD$) <span style="color:red;">*</span></label>
                            <input type="number" step="0.01" min="0.01" name="monto_total" class="form-control" placeholder="0.00" required style="font-family: var(--font-mono); font-weight: 700;" />
                        </div>
                        <div class="form-group">
                            <label class="form-label">Días de Crédito / Plazo</label>
                            <input type="number" name="dias_credito" class="form-control" value="30" min="0" />
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">NCF Factura Modificada (Opcional)</label>
                        <input type="text" name="ncf_factura_modificada" class="form-control" placeholder="Ej: B0200000001" style="text-transform: uppercase;" />
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Concepto Detallado del Cargo <span style="color:red;">*</span></label>
                        <textarea name="concepto_cargo" class="form-control" rows="2" placeholder="Ej: Cargo por cheque devuelto sin fondos No. 0491 Banco BHD..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalCargo')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarCargo">Guardar Cargo a Cliente</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 4: DETALLE DEL RECIBO DE INGRESO                                   -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalDetalleRecibo">
        <div class="modal-dialog modal-lg">
            <div class="modal-header">
                <h3><span>🧾</span> Detalle de Recibo de Ingreso</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalDetalleRecibo')">&times;</button>
            </div>
            <div class="modal-body" id="bodyDetalleRecibo">
                <!-- Inyectado dinámicamente -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalDetalleRecibo')">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- SCRIPT DE DATOS Y LÓGICA DE NEGOCIO -->
    <script>
        const CLIENTES_LISTA   = <?= json_encode($clientesList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const CATALOGO_METODOS = <?= json_encode($metodosPagoList) ?>;
        const CATALOGO_BANCOS  = <?= json_encode($cuentasBancarias) ?>;
        const SESION_CAJA_ACTIVA = <?= json_encode($sesionCajaActiva) ?>;
    </script>
    <script src="cuentas_por_cobrar.js?v=4"></script>
</body>

</html>
