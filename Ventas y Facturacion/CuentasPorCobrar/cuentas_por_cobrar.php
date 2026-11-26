<?php
/**
 * ============================================================================
 * Vista Principal: Proceso 7 - Cuentas por Cobrar (CxC)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/CuentasPorCobrar/cuentas_por_cobrar.php
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
if (file_exists(__DIR__ . '/../../config/funciones.php')) {
    require_once __DIR__ . '/../../config/funciones.php';
}
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

$kpiTotalDeuda = 0.00;
$kpiVencidasCnt = 0;
$kpiVencidasMto = 0.00;
$kpiAlDiaCnt = 0;
$kpiAlDiaMto = 0.00;
$kpiCobrosMesMto = 0.00;

$listaCxc = [];
$listaRecibos = [];
$listaAjustes = [];
$listaNotasDebito = [];
$clientesList = [];
$metodosPagoList = [];
$cuentasBancarias = [];
$monedasList = [];
$supervisoresList = [];
$sesionCajaActiva = null;

if ($pdo) {
    // 1. KPIs Financieros consolidados en DOP (saldo * tasa_cambio)
    try {
        $stmtKpi = $pdo->query("
            SELECT
                COALESCE(SUM(saldo_pendiente * tasa_cambio), 0) AS total_deuda,
                COUNT(CASE WHEN fecha_vencimiento < CURRENT_DATE THEN 1 END) AS vencidas_cnt,
                COALESCE(SUM(CASE WHEN fecha_vencimiento < CURRENT_DATE THEN saldo_pendiente * tasa_cambio ELSE 0 END), 0) AS vencidas_mto,
                COUNT(CASE WHEN fecha_vencimiento >= CURRENT_DATE THEN 1 END) AS al_dia_cnt,
                COALESCE(SUM(CASE WHEN fecha_vencimiento >= CURRENT_DATE THEN saldo_pendiente * tasa_cambio ELSE 0 END), 0) AS al_dia_mto
            FROM ventas.cuentas_por_cobrar
            WHERE estado IN ('PENDIENTE', 'PAGADA_PARCIAL')
        ");
        $kRow = $stmtKpi ? $stmtKpi->fetch(PDO::FETCH_ASSOC) : null;
        if ($kRow) {
            $kpiTotalDeuda = (float) $kRow['total_deuda'];
            $kpiVencidasCnt = (int) $kRow['vencidas_cnt'];
            $kpiVencidasMto = (float) $kRow['vencidas_mto'];
            $kpiAlDiaCnt = (int) $kRow['al_dia_cnt'];
            $kpiAlDiaMto = (float) $kRow['al_dia_mto'];
        }
    } catch (Throwable $e) {
    }

    try {
        $stmtCobrosMes = $pdo->query("
            SELECT COALESCE(SUM(monto_total_recibido * tasa_cambio), 0)
            FROM ventas.recibos_ingreso_cxc
            WHERE DATE_TRUNC('month', fecha_recibo) = DATE_TRUNC('month', CURRENT_DATE)
        ");
        $kpiCobrosMesMto = $stmtCobrosMes ? (float) $stmtCobrosMes->fetchColumn() : 0.00;
    } catch (Throwable $e) {
    }

    // 2. Sesión de caja ABIERTA del cajero actual (Vía A: Efectivo / Tarjeta en mostrador)
    try {
        $stmtSes = $pdo->prepare("
            SELECT sc.id, sc.caja_id, c.nombre_equipo
            FROM ventas.sesiones_caja sc
            JOIN ventas.cajas_pos c ON c.id = sc.caja_id
            WHERE sc.cajero_id IN (?, ?) AND sc.estado = 'ABIERTA'
            ORDER BY sc.id DESC LIMIT 1
        ");
        $stmtSes->execute([$usuarioId, $personaId ?: $usuarioId]);
        $sesionCajaActiva = $stmtSes->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
    }

    // 3. Catálogos
    try {
        $stmtCl = $pdo->query("
            SELECT c.persona_id, c.codigo_cliente, c.categoria_cliente, c.limite_credito,
                   p.nombre_razon_social, p.documento_identidad, p.telefono_principal
            FROM identidad.clientes c
            JOIN identidad.personas p ON p.persona_id = c.persona_id
            WHERE (p.estado = TRUE OR p.estado IS NULL)
              AND c.codigo_cliente <> 'CLI-000'
              AND p.documento_identidad <> '00000000000'
            ORDER BY p.nombre_razon_social ASC
        ");
        $clientesList = $stmtCl ? $stmtCl->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
    }

    try {
        $stmtMp = $pdo->query("
            SELECT id, codigo, descripcion
            FROM ventas.metodos_pago
            WHERE activo = TRUE
            ORDER BY id ASC
        ");
        $metodosPagoList = $stmtMp ? $stmtMp->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
    }

    // Cuentas bancarias priorizando moneda base (DOP)
    try {
        $stmtCb = $pdo->query("
            SELECT cb.id, cb.nombre_banco, cb.numero_cuenta, cb.moneda_id,
                   COALESCE(m.codigo_iso, 'DOP') AS moneda_iso,
                   COALESCE(m.simbolo, 'RD$') AS moneda_simbolo,
                   COALESCE(m.es_moneda_base, TRUE) AS es_moneda_base
            FROM tesoreria.cuentas_bancarias cb
            LEFT JOIN tesoreria.monedas m ON m.id = cb.moneda_id
            WHERE cb.activa = TRUE
            ORDER BY CASE WHEN COALESCE(m.es_moneda_base, TRUE) = TRUE THEN 0 ELSE 1 END, cb.nombre_banco ASC
        ");
        $cuentasBancarias = $stmtCb ? $stmtCb->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
    }

    try {
        $stmtMon = $pdo->query("
            SELECT id, codigo_iso, simbolo
            FROM tesoreria.monedas
            WHERE activa = TRUE
            ORDER BY CASE WHEN es_moneda_base = TRUE THEN 0 ELSE 1 END, id ASC
        ");
        $monedasList = $stmtMon ? $stmtMon->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
    }

    try {
        $supervisoresList = obtenerSupervisoresAutorizados($pdo);
    } catch (Throwable $e) {
    }

    // 4. Cartera de Cuentas por Cobrar
    try {
        $stmtCxc = $pdo->query("
            SELECT cxc.id, cxc.cliente_id, cxc.factura_id, cxc.nota_debito_id,
                   cxc.monto_original, cxc.saldo_pendiente, cxc.estado,
                   cxc.fecha_emision, cxc.fecha_vencimiento,
                   cxc.moneda_id, cxc.tasa_cambio,
                   m.simbolo AS moneda_simbolo, m.codigo_iso AS moneda_iso,
                   p.nombre_razon_social AS cliente_nombre,
                   p.documento_identidad AS cliente_rnc,
                   cl.codigo_cliente, cl.categoria_cliente,
                   COALESCE(f.numero_factura, 'ND-' || nd.ncf_nota_debito, 'CXC-' || cxc.id) AS numero_documento,
                   COALESCE(f.ncf, nd.ncf_nota_debito, 'S/C') AS ncf,
                   CASE
                       WHEN cxc.factura_id IS NOT NULL THEN 'Factura a Crédito #' || f.numero_factura
                       ELSE 'Nota de Débito (B03): ' || COALESCE(nd.concepto_cargo, 'Cargo')
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
    } catch (Throwable $e) {
    }

    // 5. Historial de Recibos de Ingreso
    try {
        $stmtRec = $pdo->query("
            SELECT r.id, r.numero_recibo, r.fecha_recibo,
                   r.monto_total_recibido, r.saldo_a_favor_generado,
                   m.simbolo AS moneda_simbolo,
                   p.nombre_razon_social AS cliente_nombre,
                   p.documento_identidad AS cliente_rnc,
                   COALESCE(u.username, caj_p.nombre_razon_social, 'Cajero') AS cajero_username,
                   cpos.nombre_equipo AS caja_nombre,
                   (SELECT COUNT(*) FROM ventas.recibo_ingreso_detalles WHERE recibo_ingreso_id = r.id) AS total_facturas_canceladas
            FROM ventas.recibos_ingreso_cxc r
            JOIN identidad.clientes cl ON cl.persona_id = r.cliente_id
            JOIN identidad.personas p ON p.persona_id = cl.persona_id
            JOIN tesoreria.monedas m ON m.id = r.moneda_id
            LEFT JOIN seguridad.usuarios u ON (u.usuario_id = r.cajero_id OR u.persona_id = r.cajero_id)
            LEFT JOIN identidad.personas caj_p ON caj_p.persona_id = COALESCE(u.persona_id, r.cajero_id)
            LEFT JOIN ventas.sesiones_caja sc ON sc.id = r.sesion_caja_id
            LEFT JOIN ventas.cajas_pos cpos ON cpos.id = sc.caja_id
            ORDER BY r.fecha_recibo DESC, r.id DESC
            LIMIT 100
        ");
        $listaRecibos = $stmtRec ? $stmtRec->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
    }

    // 6. Historial de Ajustes Administrativos e Incobrables
    try {
        $stmtAj = $pdo->query("
            SELECT aj.id, aj.cuenta_por_cobrar_id, aj.tipo_ajuste, aj.monto_ajuste,
                   aj.saldo_anterior, aj.saldo_restante, aj.justificacion, aj.fecha_ajuste,
                   m.simbolo AS moneda_simbolo,
                   u.username AS supervisor_username,
                   p.nombre_razon_social AS cliente_nombre,
                   COALESCE(f.numero_factura, 'ND-' || nd.ncf_nota_debito, 'CXC-' || cxc.id) AS numero_documento
            FROM ventas.ajustes_cxc aj
            JOIN ventas.cuentas_por_cobrar cxc ON cxc.id = aj.cuenta_por_cobrar_id
            JOIN tesoreria.monedas m ON m.id = aj.moneda_id
            JOIN identidad.clientes cl ON cl.persona_id = cxc.cliente_id
            JOIN identidad.personas p ON p.persona_id = cl.persona_id
            LEFT JOIN ventas.facturas f ON f.id = cxc.factura_id
            LEFT JOIN ventas.notas_debito nd ON nd.id = cxc.nota_debito_id
            JOIN seguridad.usuarios u ON u.usuario_id = aj.supervisor_id
            ORDER BY aj.fecha_ajuste DESC, aj.id DESC
            LIMIT 60
        ");
        $listaAjustes = $stmtAj ? $stmtAj->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
    }

    // 7. Historial Compacto de Notas de Débito (B03)
    try {
        $stmtNd = $pdo->query("
            SELECT nd.id,
                   nd.ncf_nota_debito,
                   nd.ncf_factura_modificada,
                   nd.concepto_cargo,
                   nd.monto_subtotal,
                   nd.monto_impuestos,
                   nd.monto_total,
                   nd.fecha_emision,
                   m.simbolo AS moneda_simbolo,
                   COALESCE(f.numero_factura, 'S/F') AS factura_origen,
                   p.nombre_razon_social AS cliente_nombre,
                   p.documento_identidad AS cliente_rnc,
                   COALESCE(u.username, em_p.nombre_razon_social, 'Sistema') AS emisor_username,
                   cxc.id AS cxc_id,
                   COALESCE(cxc.saldo_pendiente, 0) AS saldo_pendiente,
                   COALESCE(cxc.estado, 'PENDIENTE') AS estado_cxc
            FROM ventas.notas_debito nd
            JOIN identidad.personas p ON p.persona_id = nd.cliente_id
            JOIN tesoreria.monedas m ON m.id = nd.moneda_id
            LEFT JOIN ventas.facturas f ON f.id = nd.factura_id
            LEFT JOIN ventas.cuentas_por_cobrar cxc ON cxc.nota_debito_id = nd.id
            LEFT JOIN seguridad.usuarios u ON (u.usuario_id = nd.usuario_emisor_id OR u.persona_id = nd.usuario_emisor_id)
            LEFT JOIN identidad.personas em_p ON em_p.persona_id = COALESCE(u.persona_id, nd.usuario_emisor_id)
            ORDER BY nd.fecha_emision DESC, nd.id DESC
            LIMIT 100
        ");
        $listaNotasDebito = $stmtNd ? $stmtNd->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
    }


}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Cuentas por Cobrar (CxC)</title>
    <link rel="stylesheet" href="../styles.css?v=31" />
    <link rel="stylesheet" href="styles.css?v=5" />
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

    <div class="app-container">
        <?php include_once __DIR__ . '/../../sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header" style="align-items: center;">
                <div>
                    <h1 class="page-title">💳 Cuentas por Cobrar (CxC)</h1>
                    <p class="page-subtitle">Administración de crédito corporativo y VIP, Split Universal de cobros
                        auditados y ajustes contables.</p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary" onclick="abrirModalCargoNotaDebito()"
                        style="background: #a66e2e; color: #ffffff !important; border: 1px solid #8e5c23; font-weight: 700; box-shadow: 0 2px 8px rgba(166, 110, 46, 0.35); display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; border-radius: 6px;">
                        <span style="font-weight: 900; font-size: 1.05rem;">➕</span> <span>Registrar Cargo / Nota Débito
                            (B03)</span>
                    </button>
                    <button type="button" class="btn btn-primary" id="btnTopCobrarSeleccion" onclick="abrirModalCobro()"
                        style="display: none; background: #2e7d52; color: #ffffff !important; font-weight: 700;">
                        <span>💳</span> Cobrar Selección (<span id="txtTopCantSeleccionadas">0</span>)
                    </button>
                </div>
            </div>

            <!-- KPIs (4 TARJETAS) -->
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
                        <div class="cxc-kpi-value" style="color: #1e6091;">RD$ <?= number_format($kpiCobrosMesMto, 2) ?>
                        </div>
                        <div class="cxc-kpi-sub">Ingresos liquidados por recibo</div>
                    </div>
                </div>
            </div>

            <!-- TABS -->
            <div class="cxc-tabs">
                <button type="button" class="cxc-tab-btn active" onclick="cambiarTabCxc('cartera', this)">📋 Cartera y
                    Antigüedad de Saldos</button>
                <button type="button" class="cxc-tab-btn" onclick="cambiarTabCxc('recibos', this)">🧾 Historial de
                    Recibos de Ingreso</button>
                <button type="button" class="cxc-tab-btn" onclick="cambiarTabCxc('notas-debito', this)">
                    📄 Notas de Débito (B03)
                    <?php if (!empty($listaNotasDebito)): ?>
                        <span class="badge-count" style="font-size: 0.7rem; padding: 1px 6px; margin-left: 4px;">
                            <?= count($listaNotasDebito) ?>
                        </span>
                    <?php endif; ?>
                </button>
                <button type="button" class="cxc-tab-btn" onclick="cambiarTabCxc('ajustes', this)">⚖️ Ajustes,
                    Condonaciones e Incobrables</button>
            </div>

            <!-- TAB 1: CARTERA -->
            <div id="tab-cartera" class="cxc-tab-content active">
                <div class="cxc-filter-bar">
                    <div class="cxc-filter-group" style="flex: 2; min-width: 200px;">
                        <label>Buscar Cliente, NCF o Factura</label>
                        <input type="text" id="filtroTexto" class="form-control"
                            placeholder="Escriba documento, NCF, nombre de cliente..." oninput="filtrarTablaCxc()" />
                    </div>
                    <div class="cxc-filter-group" style="flex: 1.5; min-width: 180px;">
                        <label>Filtrar por Cliente</label>
                        <select id="filtroCliente" class="form-control" onchange="filtrarTablaCxc()">
                            <option value="">-- Todos los Clientes --</option>
                            <?php foreach ($clientesList as $cl): ?>
                                <option value="<?= htmlspecialchars($cl['persona_id']) ?>">
                                    <?= htmlspecialchars($cl['nombre_razon_social']) ?>
                                    (<?= htmlspecialchars($cl['categoria_cliente']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="cxc-filter-group" style="flex: 1.2; min-width: 160px;">
                        <label>Estado de Deuda</label>
                        <select id="filtroEstado" class="form-control" onchange="filtrarTablaCxc()">
                            <option value="PENDIENTES" selected>Solo Pendientes y Parciales</option>
                            <option value="TODOS">Todos los Estados</option>
                            <option value="VENCIDAS">⚠️ Solo Facturas Vencidas</option>
                            <option value="PAGADA">● Solo Saldadas</option>
                            <option value="TERMINALES">✕ Incobrables / Anuladas</option>
                        </select>
                    </div>
                    <div>
                        <button type="button" class="btn btn-secondary" onclick="limpiarFiltrosCxc()"
                            style="padding: 8px 14px;">Limpiar</button>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Cartera de Cuentas por Cobrar</h2>
                            <span class="badge-count" id="badgeTotalCxc"><?= count($listaCxc) ?> deudas</span>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">Selecciona una o varias facturas del
                            mismo cliente (y misma moneda) para cobrar en lote</div>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table" id="tablaCxc">
                            <thead>
                                <tr>
                                    <th style="width: 44px; text-align: center;">
                                        <input type="checkbox" id="chkSelectAll" onchange="toggleSelectAllCxc(this)"
                                            title="Seleccionar visibles" />
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
                                        <td colspan="11"
                                            style="text-align: center; padding: 36px; color: var(--text-muted);">No hay
                                            cuentas por cobrar registradas en el sistema.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaCxc as $cxc):
                                        $diasDif = (int) $cxc['dias_diferencia'];
                                        $esPendiente = in_array($cxc['estado'], ['PENDIENTE', 'PAGADA_PARCIAL'], true);
                                        $sim = $cxc['moneda_simbolo'] ?: 'RD$';

                                        if ($cxc['estado'] === 'ANULADA')
                                            $badgeAntiguedad = '<span class="badge-anulada">ANULADA</span>';
                                        elseif ($cxc['estado'] === 'INCOBRABLE')
                                            $badgeAntiguedad = '<span class="badge-incobrable">INCOBRABLE</span>';
                                        elseif ($cxc['estado'] === 'PAGADA')
                                            $badgeAntiguedad = '<span class="badge-pagada">SALDADA</span>';
                                        elseif ($diasDif > 0)
                                            $badgeAntiguedad = '<span class="badge-vencida">⚠️ Vencida +' . $diasDif . ' d</span>';
                                        elseif ($diasDif === 0)
                                            $badgeAntiguedad = '<span class="badge-hoy">⏰ Vence Hoy</span>';
                                        else
                                            $badgeAntiguedad = '<span class="badge-al-dia">● ' . abs($diasDif) . ' d restantes</span>';
                                        ?>
                                        <tr class="fila-cxc" data-id="<?= (int) $cxc['id'] ?>"
                                            data-cliente-id="<?= htmlspecialchars($cxc['cliente_id']) ?>"
                                            data-cliente-nombre="<?= htmlspecialchars($cxc['cliente_nombre']) ?>"
                                            data-documento="<?= htmlspecialchars($cxc['numero_documento']) ?>"
                                            data-ncf="<?= htmlspecialchars($cxc['ncf']) ?>"
                                            data-concepto="<?= htmlspecialchars($cxc['concepto']) ?>"
                                            data-saldo="<?= (float) $cxc['saldo_pendiente'] ?>"
                                            data-moneda-id="<?= (int) $cxc['moneda_id'] ?>"
                                            data-moneda-simbolo="<?= htmlspecialchars($sim) ?>"
                                            data-moneda-iso="<?= htmlspecialchars($cxc['moneda_iso'] ?: 'DOP') ?>"
                                            data-tasa="<?= (float) $cxc['tasa_cambio'] ?>"
                                            data-estado="<?= htmlspecialchars($cxc['estado']) ?>"
                                            data-vencido="<?= ($diasDif > 0) ? '1' : '0' ?>"
                                            data-hoy="<?= ($diasDif === 0) ? '1' : '0' ?>">

                                            <td style="text-align: center;">
                                                <?php if ($esPendiente): ?>
                                                    <input type="checkbox" class="chk-cxc-item" value="<?= (int) $cxc['id'] ?>"
                                                        data-cliente="<?= htmlspecialchars($cxc['cliente_id']) ?>"
                                                        data-saldo="<?= (float) $cxc['saldo_pendiente'] ?>"
                                                        onchange="alCambiarSeleccionItemCxc(this)" />
                                                <?php else: ?>
                                                    <span style="color: #ccc;">—</span>
                                                <?php endif; ?>
                                            </td>

                                            <td style="white-space: nowrap;">
                                                <div style="font-weight: 700; color: var(--text-dark); font-size: 0.88rem;">
                                                    <?= htmlspecialchars($cxc['numero_documento']) ?>
                                                </div>
                                                <div class="cell-sub"
                                                    style="font-family: var(--font-mono); font-size: 0.76rem; color: var(--accent-gold); font-weight: 600;">
                                                    NCF: <?= htmlspecialchars($cxc['ncf']) ?></div>
                                            </td>

                                            <td>
                                                <strong
                                                    style="font-size: 0.88rem;"><?= htmlspecialchars($cxc['cliente_nombre']) ?></strong>
                                                <div class="cell-sub">
                                                    <?= htmlspecialchars($cxc['codigo_cliente']) ?> •
                                                    <?= htmlspecialchars($cxc['cliente_rnc'] ?: 'S/Documento') ?>
                                                    <span
                                                        style="background: #eee; border-radius: 4px; padding: 1px 4px; font-size: 0.7rem; font-weight: 700; margin-left: 4px;"><?= htmlspecialchars($cxc['categoria_cliente']) ?></span>
                                                </div>
                                            </td>

                                            <td>
                                                <div style="font-size: 0.84rem;"><?= htmlspecialchars($cxc['concepto']) ?></div>
                                            </td>
                                            <td style="font-size: 0.82rem; white-space: nowrap;">
                                                <?= htmlspecialchars($cxc['fecha_emision']) ?>
                                            </td>
                                            <td style="font-size: 0.82rem; white-space: nowrap;">
                                                <?= htmlspecialchars($cxc['fecha_vencimiento']) ?>
                                            </td>
                                            <td style="white-space: nowrap;"><?= $badgeAntiguedad ?></td>

                                            <td class="text-right"
                                                style="font-family: var(--font-mono); font-size: 0.88rem; white-space: nowrap;">
                                                <?= htmlspecialchars($sim) ?>
                                                <?= number_format((float) $cxc['monto_original'], 2) ?>
                                            </td>

                                            <td class="text-right"
                                                style="font-family: var(--font-mono); font-weight: 700; font-size: 0.95rem; white-space: nowrap; color: <?= ((float) $cxc['saldo_pendiente'] > 0) ? '#b53d32' : '#2e7d52' ?>;">
                                                <?= htmlspecialchars($sim) ?>
                                                <?= number_format((float) $cxc['saldo_pendiente'], 2) ?>
                                            </td>

                                            <td class="text-center">
                                                <?php if ($cxc['estado'] === 'PENDIENTE'): ?>
                                                    <span class="badge-hoy">PENDIENTE</span>
                                                <?php elseif ($cxc['estado'] === 'PAGADA_PARCIAL'): ?>
                                                    <span class="badge-al-dia">ABONADA</span>
                                                <?php elseif ($cxc['estado'] === 'PAGADA'): ?>
                                                    <span class="badge-pagada">PAGADA</span>
                                                <?php elseif ($cxc['estado'] === 'INCOBRABLE'): ?>
                                                    <span class="badge-incobrable">INCOBRABLE</span>
                                                <?php else: ?>
                                                    <span class="badge-anulada">ANULADA</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-center">
                                                <div style="display: flex; gap: 6px; justify-content: center;">
                                                    <?php if ($esPendiente): ?>
                                                        <button type="button" class="btn btn-sm btn-primary"
                                                            onclick="iniciarCobroUnico(<?= (int) $cxc['id'] ?>)"
                                                            title="Cobrar esta deuda">💳 Cobrar</button>
                                                        <button type="button" class="btn btn-sm btn-secondary"
                                                            onclick="abrirModalAjusteCxc(<?= (int) $cxc['id'] ?>, '<?= htmlspecialchars(addslashes($cxc['cliente_nombre']), ENT_QUOTES) ?>', <?= (float) $cxc['saldo_pendiente'] ?>, '<?= htmlspecialchars($sim, ENT_QUOTES) ?>')"
                                                            title="Ajuste, Condonación o Incobrable">⚖️</button>
                                                    <?php else: ?>
                                                        <span style="font-size: 0.78rem; color: var(--text-muted);">Cerrada</span>
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

                <!-- BARRA FLOTANTE -->
                <div class="cxc-floating-bar" id="cxcFloatingBar">
                    <div>
                        <span style="font-weight: 700; font-size: 0.95rem;"><span id="txtSeleccionadasCant">0</span>
                            deuda(s) seleccionada(s)</span>
                        <div style="font-size: 0.85rem; color: #c8beb2;">Cliente: <strong id="txtSeleccionadasCliente"
                                style="color: #fff;">—</strong></div>
                        <div class="cxc-selection-total">Total a Cobrar: <span id="txtSeleccionadasTotal">RD$
                                0.00</span></div>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button type="button" class="btn" onclick="limpiarSeleccionCxc()"
                            style="background: transparent; color: #e2dacb; border: 1px solid rgba(255,255,255,0.3); padding: 8px 14px;">✕
                            Cancelar Selección</button>
                        <button type="button" class="btn btn-primary" onclick="abrirModalCobro()"
                            style="background: #a66e2e; color: #ffffff; font-weight: 700; padding: 8px 18px;">💳
                            Procesar Recibo de Ingreso</button>
                    </div>
                </div>
            </div>

            <!-- TAB 2: RECIBOS DE INGRESO -->
            <div id="tab-recibos" class="cxc-tab-content">
                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Recibos de Ingreso de Cuentas por Cobrar</h2>
                            <span class="badge-count"><?= count($listaRecibos) ?> recibos</span>
                        </div>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">Cobros consolidados con Split
                            Universal (Caja física y Bancos)</span>
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
                                    <th>Cajero / Canal</th>
                                    <th class="text-center">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaRecibos)): ?>
                                    <tr>
                                        <td colspan="8"
                                            style="text-align: center; padding: 32px; color: var(--text-muted);">No se han
                                            emitido recibos de ingreso todavía.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaRecibos as $rec):
                                        $simR = $rec['moneda_simbolo'] ?: 'RD$';
                                        ?>
                                        <tr>
                                            <td><strong class="code-inline"
                                                    style="font-size: 0.88rem; color: var(--accent-gold);"><?= htmlspecialchars($rec['numero_recibo']) ?></strong>
                                            </td>
                                            <td style="font-size: 0.84rem;">
                                                <?= htmlspecialchars(substr((string) $rec['fecha_recibo'], 0, 16)) ?>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($rec['cliente_nombre']) ?></strong>
                                                <div class="cell-sub">RNC/Céd:
                                                    <?= htmlspecialchars($rec['cliente_rnc'] ?: 'S/D') ?>
                                                </div>
                                            </td>
                                            <td><span class="badge-al-dia"><?= (int) $rec['total_facturas_canceladas'] ?>
                                                    docs</span></td>
                                            <td class="text-right"
                                                style="font-family: var(--font-mono); font-weight: 700; color: #2e7d52; white-space: nowrap;">
                                                <?= htmlspecialchars($simR) ?>
                                                <?= number_format((float) $rec['monto_total_recibido'], 2) ?>
                                            </td>
                                            <td class="text-right"
                                                style="font-family: var(--font-mono); color: #b78103; white-space: nowrap;">
                                                <?= ((float) $rec['saldo_a_favor_generado'] > 0) ? htmlspecialchars($simR) . ' ' . number_format((float) $rec['saldo_a_favor_generado'], 2) : '—' ?>
                                            </td>
                                            <td style="font-size: 0.82rem;">
                                                <?= htmlspecialchars($rec['cajero_username']) ?>
                                                <?php if (!empty($rec['caja_nombre'])): ?>
                                                    <div class="cell-sub">Caja: <?= htmlspecialchars($rec['caja_nombre']) ?></div>
                                                <?php else: ?>
                                                    <div class="cell-sub" style="color: #1e6091;">(Vía Bancaria / Crédito)</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-secondary"
                                                    onclick="verDetalleRecibo(<?= (int) $rec['id'] ?>)">👁️ Ver Detalle</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 2B: NOTAS DE DÉBITO (B03) -->
            <div id="tab-notas-debito" class="cxc-tab-content">
                <div class="cxc-filter-bar">
                    <div class="cxc-filter-group" style="flex: 2; min-width: 220px;">
                        <label>Buscar NCF B03, Factura Modificada, Cliente o Concepto</label>
                        <input type="text" id="filtroTextoNd" class="form-control"
                            placeholder="Ej: B0300000001, FAC-2026, cheque devuelto..."
                            oninput="filtrarTablaNotasDebito()" />
                    </div>
                    <div class="cxc-filter-group" style="flex: 1; min-width: 170px;">
                        <label>Estado de Cobro</label>
                        <select id="filtroEstadoNd" class="form-control" onchange="filtrarTablaNotasDebito()">
                            <option value="TODAS">Todas las B03</option>
                            <option value="PENDIENTES">Solo Pendientes / Abonadas</option>
                            <option value="PAGADA">Solo Cobradas</option>
                        </select>
                    </div>
                    <div>
                        <button type="button" class="btn btn-primary" onclick="abrirModalCargoNotaDebito()"
                            style="background: #a66e2e; color: #fff; font-weight: 700;">
                            ➕ Nueva B03
                        </button>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Registro de Notas de Débito Fiscales (B03)</h2>
                            <span class="badge-count" id="badgeTotalNd"><?= count($listaNotasDebito) ?> emitidas</span>
                        </div>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">Reportadas automáticamente en el
                            Formato 607 DGII</span>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>NCF Nota Débito</th>
                                    <th>Fecha</th>
                                    <th>Factura Modificada</th>
                                    <th>Cliente</th>
                                    <th>Concepto del Cargo</th>
                                    <th class="text-right">Base / ITBIS</th>
                                    <th class="text-right">Total B03</th>
                                    <th class="text-center">Estado CxC</th>
                                    <th class="text-center">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaNotasDebito)): ?>
                                    <tr>
                                        <td colspan="9"
                                            style="text-align: center; padding: 32px; color: var(--text-muted);">No se han
                                            emitido Notas de Débito (B03) todavía.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaNotasDebito as $nd):
                                        $simNd = $nd['moneda_simbolo'] ?: 'RD$';
                                        $estNd = strtoupper((string) $nd['estado_cxc']);
                                        $esPend = in_array($estNd, ['PENDIENTE', 'PAGADA_PARCIAL'], true) && !empty($nd['cxc_id']);
                                        ?>
                                        <tr class="fila-nd" data-estado="<?= htmlspecialchars($estNd) ?>"
                                            data-busqueda="<?= htmlspecialchars(mb_strtolower($nd['ncf_nota_debito'] . ' ' . $nd['ncf_factura_modificada'] . ' ' . $nd['factura_origen'] . ' ' . $nd['cliente_nombre'] . ' ' . $nd['cliente_rnc'] . ' ' . $nd['concepto_cargo'], 'UTF-8')) ?>">
                                            <td style="white-space: nowrap;">
                                                <strong class="code-inline"
                                                    style="color: var(--accent-gold); font-size: 0.88rem;"><?= htmlspecialchars($nd['ncf_nota_debito']) ?></strong>
                                                <div class="cell-sub">Por: <?= htmlspecialchars($nd['emisor_username']) ?></div>
                                            </td>
                                            <td style="font-size: 0.82rem; white-space: nowrap;">
                                                <?= htmlspecialchars(substr((string) $nd['fecha_emision'], 0, 16)) ?>
                                            </td>
                                            <td style="white-space: nowrap;">
                                                <div style="font-weight: 600; font-size: 0.84rem;">
                                                    <?= htmlspecialchars($nd['factura_origen']) ?>
                                                </div>
                                                <div class="cell-sub" style="font-family: var(--font-mono);">
                                                    <?= htmlspecialchars($nd['ncf_factura_modificada']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($nd['cliente_nombre']) ?></strong>
                                                <div class="cell-sub"><?= htmlspecialchars($nd['cliente_rnc'] ?: 'S/D') ?></div>
                                            </td>
                                            <td style="font-size: 0.82rem; max-width: 240px;"
                                                title="<?= htmlspecialchars($nd['concepto_cargo']) ?>">
                                                <?= htmlspecialchars($nd['concepto_cargo']) ?>
                                            </td>
                                            <td class="text-right"
                                                style="font-family: var(--font-mono); font-size: 0.78rem; white-space: nowrap;">
                                                <div>Base: <?= htmlspecialchars($simNd) ?>
                                                    <?= number_format((float) $nd['monto_subtotal'], 2) ?>
                                                </div>
                                                <div style="color: var(--text-muted);">ITBIS: <?= htmlspecialchars($simNd) ?>
                                                    <?= number_format((float) $nd['monto_impuestos'], 2) ?>
                                                </div>
                                            </td>
                                            <td class="text-right"
                                                style="font-family: var(--font-mono); font-weight: 700; white-space: nowrap; color: #211813;">
                                                <?= htmlspecialchars($simNd) ?>
                                                <?= number_format((float) $nd['monto_total'], 2) ?>
                                                <?php if ($esPend): ?>
                                                    <div class="cell-sub" style="color: #b53d32;">Saldo:
                                                        <?= htmlspecialchars($simNd) ?>
                                                        <?= number_format((float) $nd['saldo_pendiente'], 2) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($estNd === 'PENDIENTE'): ?>
                                                    <span class="badge-hoy">PENDIENTE</span>
                                                <?php elseif ($estNd === 'PAGADA_PARCIAL'): ?>
                                                    <span class="badge-al-dia">ABONADA</span>
                                                <?php elseif ($estNd === 'PAGADA'): ?>
                                                    <span class="badge-pagada">COBRADA</span>
                                                <?php elseif ($estNd === 'INCOBRABLE'): ?>
                                                    <span class="badge-incobrable">INCOBRABLE</span>
                                                <?php else: ?>
                                                    <span class="badge-anulada"><?= htmlspecialchars($estNd) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($esPend): ?>
                                                    <button type="button" class="btn btn-sm btn-primary"
                                                        onclick="iniciarCobroUnico(<?= (int) $nd['cxc_id'] ?>)">💳 Cobrar</button>
                                                <?php else: ?>
                                                    <span style="font-size: 0.78rem; color: var(--text-muted);">Cerrada</span>
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

            <!-- TAB 3: AJUSTES -->
            <div id="tab-ajustes" class="cxc-tab-content">
                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Auditoría de Ajustes y Cuentas Incobrables</h2>
                            <span class="badge-count"><?= count($listaAjustes) ?> ajustes</span>
                        </div>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">Rebajas contables no monetarias
                            autorizadas con PIN de supervisor</span>
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
                                        <td colspan="9"
                                            style="text-align: center; padding: 32px; color: var(--text-muted);">No se han
                                            registrado ajustes administrativos ni castigos de cartera.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaAjustes as $aj):
                                        $simA = $aj['moneda_simbolo'] ?: 'RD$';
                                        ?>
                                        <tr>
                                            <td>#<?= (int) $aj['id'] ?></td>
                                            <td style="font-size: 0.82rem; white-space: nowrap;">
                                                <?= htmlspecialchars(substr((string) $aj['fecha_ajuste'], 0, 16)) ?>
                                            </td>
                                            <td><strong><?= htmlspecialchars($aj['cliente_nombre']) ?></strong></td>
                                            <td><span class="code-inline"
                                                    style="font-size: 0.82rem;"><?= htmlspecialchars($aj['numero_documento']) ?></span>
                                            </td>
                                            <td>
                                                <?php if ($aj['tipo_ajuste'] === 'INCOBRABLE'): ?>
                                                    <span class="badge-incobrable">INCOBRABLE</span>
                                                <?php elseif ($aj['tipo_ajuste'] === 'ANULACION_FACTURA'): ?>
                                                    <span class="badge-anulada">ANULACIÓN</span>
                                                <?php elseif ($aj['tipo_ajuste'] === 'CONDONACION'): ?>
                                                    <span class="badge-hoy">CONDONACIÓN</span>
                                                <?php else: ?>
                                                    <span class="badge-pagada"><?= htmlspecialchars($aj['tipo_ajuste']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right"
                                                style="font-family: var(--font-mono); font-weight: 700; color: #b53d32; white-space: nowrap;">
                                                <?= htmlspecialchars($simA) ?>
                                                <?= number_format((float) $aj['monto_ajuste'], 2) ?>
                                            </td>
                                            <td class="text-right"
                                                style="font-family: var(--font-mono); font-weight: 700; white-space: nowrap; color: <?= ((float) $aj['saldo_restante'] > 0) ? '#b53d32' : '#2e7d52' ?>;">
                                                <?= htmlspecialchars($simA) ?>
                                                <?= number_format((float) $aj['saldo_restante'], 2) ?>
                                            </td>
                                            <td style="font-size: 0.82rem; font-weight: 600;">🛡️
                                                <?= htmlspecialchars($aj['supervisor_username']) ?>
                                            </td>
                                            <td style="font-size: 0.8rem; color: var(--text-muted); max-width: 220px;">
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

    <!-- MODAL 1: RECIBO DE INGRESO (SPLIT UNIVERSAL) -->
    <div class="modal-overlay" id="modalCobro">
        <div class="modal-dialog modal-lg">
            <div class="modal-header">
                <h3><span>💳</span> Emisión de Recibo de Ingreso (Split Universal)</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalCobro')">&times;</button>
            </div>
            <form id="formCobro" onsubmit="procesarLiquidacionCobro(event)">
                <div class="modal-body">
                    <div
                        style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <div
                                style="font-size: 0.74rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">
                                Cliente Seleccionado</div>
                            <div style="font-size: 1.08rem; font-weight: 700; color: var(--text-dark);"
                                id="modalCobroClienteNombre">—</div>
                            <div id="modalCobroSaldoFavorInfo"
                                style="font-size: 0.75rem; color: #b78103; font-weight: 700; margin-top: 2px;"></div>
                        </div>
                        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                            <div>
                                <div style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase;">
                                    Total a Cancelar</div>
                                <div style="font-size: 1.18rem; font-weight: 700; font-family: var(--font-mono); color: #2e7d52;"
                                    id="modalTotalCobrar">RD$ 0.00</div>
                            </div>
                            <div>
                                <div style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase;">
                                    Total Recibido</div>
                                <div style="font-size: 1.18rem; font-weight: 700; font-family: var(--font-mono); color: #1e6091;"
                                    id="modalTotalRecibidoSplit">RD$ 0.00</div>
                            </div>
                            <div>
                                <div style="font-size: 0.72rem; color: #b78103; text-transform: uppercase;">Excedente
                                    (Saldo Favor)</div>
                                <div style="font-size: 1.18rem; font-weight: 700; font-family: var(--font-mono); color: #b78103;"
                                    id="modalTotalExcedente">RD$ 0.00</div>
                            </div>
                        </div>
                    </div>

                    <h4 style="font-size: 0.92rem; font-weight: 700; margin-bottom: 10px; color: var(--text-dark);">1.
                        Documentos y Facturas a Cancelar</h4>
                    <div class="table-wrapper"
                        style="margin-bottom: 20px; border: 1px solid var(--border-light); border-radius: 6px;">
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
                            <tbody id="tbodyFacturasCobro"></tbody>
                        </table>
                    </div>

                    <div>
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <h4
                                    style="font-size: 0.92rem; font-weight: 700; color: var(--text-dark); margin-bottom: 2px;">
                                    2. Instrumentos de Pago (Split Universal)</h4>
                                <div style="font-size: 0.76rem; color: var(--text-muted);">
                                    Efectivo/Tarjeta requieren turno de caja abierto. Transferencia/Cheque asientan en
                                    Tesorería sin tocar el Arqueo Ciego.
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="agregarLineaSplitCobro()">➕
                                Agregar Instrumento</button>
                        </div>

                        <div id="contenedorSplitCobros" style="margin-bottom: 12px;"></div>

                        <div
                            style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 6px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <span style="font-size: 0.82rem; color: var(--text-muted);">Total Neto en
                                    Instrumentos:</span>
                                <strong style="font-family: var(--font-mono); font-size: 1rem; margin-left: 6px;"
                                    id="lblTotalInstrumentos">RD$ 0.00</strong>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <button type="button" class="btn btn-sm btn-secondary" onclick="autoCuadrarSplitCobro()"
                                    style="font-size: 0.75rem; padding: 3px 8px;">⚖️ Ajustar al Total</button>
                                <div id="lblEstadoCuadreCobro"
                                    style="font-size: 0.85rem; font-weight: 700; color: #b53d32;">⚠️ Faltan RD$ 0.00 por
                                    cubrir</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                        onclick="cerrarModal('modalCobro')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnConfirmarCobro"
                        style="background: #2e7d52; color: #ffffff; font-weight: 700; font-size: 0.95rem; padding: 10px 22px;"
                        disabled>
                        <span>💰</span> EMITIR RECIBO DE INGRESO
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 2: AJUSTE ADMINISTRATIVO / INCOBRABLE CON PIN -->
    <div class="modal-overlay" id="modalAjusteCxc">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3><span>⚖️</span> Ajuste Contable y Castigo de Cartera (CxC)</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalAjusteCxc')">&times;</button>
            </div>
            <form id="formAjusteCxc" onsubmit="procesarAjusteCxc(event)">
                <input type="hidden" id="ajusteCxcId" name="cxc_id" />
                <div class="modal-body">
                    <div
                        style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px;">
                        <div style="font-size: 0.76rem; color: var(--text-muted);">Cliente:</div>
                        <div style="font-weight: 700; font-size: 0.95rem;" id="ajusteClienteNombre">—</div>
                        <div style="font-size: 0.82rem; margin-top: 4px;">
                            Saldo Actual Pendiente: <strong id="ajusteSaldoActual"
                                style="font-family: var(--font-mono); color: #b53d32;">RD$ 0.00</strong>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Tipo de Ajuste Contable <span style="color:red;">*</span></label>
                        <select name="tipo_ajuste" id="tipoAjusteCxc" class="form-control" required
                            onchange="alCambiarTipoAjusteCxc()">
                            <option value="DESCUENTO_PRONTO_PAGO">DESCUENTO_PRONTO_PAGO (Acuerdo comercial de descuento)
                            </option>
                            <option value="CONDONACION">CONDONACION (Exoneración parcial o total)</option>
                            <option value="INCOBRABLE">INCOBRABLE (Castigo total de cartera irrecuperable)</option>
                            <option value="NOTA_CREDITO_B04">NOTA_CREDITO_B04 (Rebaja fiscal por bonificación)</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Monto del Ajuste <span style="color:red;">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="monto_ajuste" id="inputMontoAjusteCxc"
                            class="form-control" required
                            style="font-family: var(--font-mono); font-weight: 700; font-size: 1rem;" />
                        <small style="color: var(--text-muted); font-size: 0.74rem;">Si el saldo llega a 0.00 la cuenta
                            pasará a PAGADA o INCOBRABLE.</small>
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Justificación Contable Obligatoria <span
                                style="color:red;">*</span></label>
                        <textarea name="justificacion" id="justificacionAjusteCxc" class="form-control" rows="2"
                            placeholder="Describa el motivo, acuerdo comercial o dictamen..." required
                            minlength="6"></textarea>
                    </div>

                    <div style="background: #fdf5ea; border: 1px solid #f0d5b0; border-radius: 6px; padding: 12px;">
                        <div style="font-size: 0.78rem; font-weight: 700; color: #a66e2e; margin-bottom: 8px;">🔒
                            AUTORIZACIÓN DE SUPERVISOR</div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <div>
                                <label
                                    style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600;">Supervisor:</label>
                                <select name="supervisor_id" id="supervisorIdCxc" class="form-control" required
                                    style="font-size: 0.8rem;">
                                    <?php foreach ($supervisoresList as $sup): ?>
                                        <option value="<?= htmlspecialchars($sup['usuario_id']) ?>">
                                            <?= htmlspecialchars($sup['nombre_completo']) ?>
                                            (<?= htmlspecialchars($sup['username']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600;">PIN de
                                    Autorización:</label>
                                <input type="password" name="pin_supervisor" id="pinSupervisorCxc" class="form-control"
                                    maxlength="6" placeholder="••••" required
                                    style="letter-spacing: 3px; font-weight: 700; text-align: center;"
                                    oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6);" />
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                        onclick="cerrarModal('modalAjusteCxc')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnConfirmarAjusteCxc">Aplicar Ajuste
                        Contable</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 3: REGISTRAR CARGO / NOTA DE DÉBITO B03 -->
    <div class="modal-overlay" id="modalCargo">
        <div class="modal-dialog" style="max-width: 560px;">
            <div class="modal-header">
                <h3><span>📄</span> Registrar Cargo / Nota de Débito (B03)</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalCargo')">&times;</button>
            </div>
            <form id="formCargo" onsubmit="procesarRegistroCargo(event)">
                <div class="modal-body" style="overflow: visible;">
                    <div class="form-group" style="margin-bottom: 14px; position: relative;">
                        <label class="form-label">Cliente Registrado <span style="color:red;">*</span></label>
                        <input type="hidden" name="cliente_id" id="cxcCargoClienteId" required value="" />

                        <div id="boxBusquedaClienteCxc" style="position: relative;">
                            <input type="text" id="inputBuscarClienteCxc" class="form-control"
                                placeholder="🔍 Escriba nombre, RNC/Cédula o categoría..." autocomplete="off"
                                oninput="filtrarClientesCxc(this.value)" onfocus="abrirDropdownClientesCxc()"
                                style="height: 36px; font-size: 0.85rem; width: 400px" />
                            <div id="dropdownClientesCxc"
                                style="display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 200px; overflow-y: auto; background: #ffffff; border: 1.5px solid #d4c1a7; border-radius: 6px; box-shadow: 0 8px 24px rgba(0,0,0,0.22); z-index: 1050; margin-top: 2px;">
                            </div>
                        </div>

                        <div id="badgeClienteElegidoCxc"
                            style="display: none; background: #fdf5ea; border: 1px solid #dfcaa4; border-radius: 6px; padding: 4px 10px; align-items: center; justify-content: space-between; height: 36px;">
                            <div
                                style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.84rem; color: #211813;">
                                <strong id="txtNombreClienteCxc">—</strong>
                                <small id="txtDetalleClienteCxc" style="color: #7a6b5d; margin-left: 6px;"></small>
                            </div>
                            <button type="button" class="btn btn-sm btn-secondary"
                                onclick="limpiarClienteSeleccionadoCxc()"
                                style="padding: 1px 6px; font-size: 0.72rem; height: 22px; flex-shrink: 0;">✕
                                Cambiar</button>
                        </div>
                    </div>

                    <!-- SELECTOR BUSCABLE DE FACTURA FISCAL DEL CLIENTE -->
                    <div class="form-group" style="margin-bottom: 14px; position: relative;">
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <label class="form-label" style="margin: 0;">Factura Fiscal a Modificar (NCF B03) <span
                                    style="color:red;">*</span></label>
                            <small id="lblContadorFacturasCargo"
                                style="font-size: 0.72rem; color: var(--text-muted);"></small>
                        </div>
                        <input type="hidden" name="factura_id" id="cxcCargoFacturaId" value="" />

                        <div id="boxBusquedaFacturaCxc" style="position: relative;">
                            <input type="text" id="inputBuscarFacturaCxc" class="form-control"
                                placeholder="Primero seleccione un cliente..." autocomplete="off" disabled
                                oninput="programarBusquedaFacturasCxc()" onfocus="abrirDropdownFacturasCxc()"
                                style="height: 36px; font-size: 0.85rem;width: 300px" />
                            <div id="dropdownFacturasCxc"
                                style="display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 210px; overflow-y: auto; background: #ffffff; border: 1.5px solid #d4c1a7; border-radius: 6px; box-shadow: 0 8px 24px rgba(0,0,0,0.22); z-index: 1060; margin-top: 2px;">
                            </div>
                        </div>

                        <div id="badgeFacturaElegidaCxc"
                            style="display: none; background: #eef8f2; border: 1px solid #c2e5d1; border-radius: 6px; padding: 4px 10px; align-items: center; justify-content: space-between; height: 36px;">
                            <div
                                style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.82rem; color: #1b5e20; font-family: var(--font-mono);">
                                <strong id="txtFacturaElegidaCxc">—</strong>
                                <small id="txtFacturaElegidaDetCxc"
                                    style="color: #556b5d; margin-left: 6px; font-family: inherit;"></small>
                            </div>
                            <button type="button" class="btn btn-sm btn-secondary"
                                onclick="limpiarFacturaSeleccionadaCxc(true)"
                                style="padding: 1px 6px; font-size: 0.72rem; height: 22px; flex-shrink: 0;">✕
                                Cambiar</button>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label">Monto Base del Cargo <span style="color:red;">*</span></label>
                            <input type="number" step="0.01" min="0.01" name="monto_total" class="form-control"
                                placeholder="0.00" required style="font-family: var(--font-mono); font-weight: 700;" />
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label">Días de Crédito / Plazo</label>
                            <input type="number" name="dias_credito" class="form-control" value="30" min="0" />
                        </div>
                    </div>

                    <div style="margin-bottom: 12px;">
                        <label
                            style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 600; color: #5a3818; cursor: pointer;">
                            <input type="checkbox" name="aplica_itbis" value="1" />
                            <span>Aplicar ITBIS (18%) sobre este cargo (se desglosa en Formato 607)</span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-bottom: 4px;">
                        <label class="form-label">Concepto Detallado del Cargo <span style="color:red;">*</span></label>
                        <textarea name="concepto_cargo" class="form-control" rows="2"
                            placeholder="Ej: Cargo por cheque devuelto sin fondos No. 0491..." required
                            minlength="5"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                        onclick="cerrarModal('modalCargo')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarCargo">Emitir Nota de Débito
                        (B03)</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 4: DETALLE DEL RECIBO DE INGRESO -->
    <div class="modal-overlay" id="modalDetalleRecibo">
        <div class="modal-dialog modal-lg">
            <div class="modal-header">
                <h3><span>🧾</span> Detalle de Recibo de Ingreso</h3>
                <button type="button" class="modal-close-btn"
                    onclick="cerrarModal('modalDetalleRecibo')">&times;</button>
            </div>
            <div class="modal-body" id="bodyDetalleRecibo"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                    onclick="cerrarModal('modalDetalleRecibo')">Cerrar</button>
            </div>
        </div>
    </div>

    <script>
        const CLIENTES_LISTA = <?= json_encode($clientesList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        const CATALOGO_METODOS = <?= json_encode($metodosPagoList, JSON_UNESCAPED_UNICODE) ?>;
        const CATALOGO_BANCOS = <?= json_encode($cuentasBancarias, JSON_UNESCAPED_UNICODE) ?>;
        const SESION_CAJA_ACTIVA = <?= json_encode($sesionCajaActiva, JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="cuentas_por_cobrar.js?v=<?= time() ?>"></script>
</body>

</html>