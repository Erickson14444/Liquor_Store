<?php
/**
 * ============================================================================
 * Vista Principal: Proceso 5 - Consignaciones (Eventos y Bodas)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/Consignaciones/consignaciones.php
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/funciones.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

$usuarioId = requerirSesionActiva(false, '../../Login y Menu/login.php');

if (!function_exists('usuarioPuedeAccederConsignaciones') || !usuarioPuedeAccederConsignaciones()) {
    header("Location: ../../Login y Menu/menu.php?error=" . urlencode("Acceso restringido: no posees permisos para Consignaciones."));
    exit;
}

$personaId = $_SESSION['usuario']['persona_id'] ?? null;

// ============================================================================
// CARGA DE DATOS Y CONSULTAS PRINCIPALES
// ============================================================================
$kpiActivasCnt       = 0;
$kpiComprometidasCnt = 0;
$kpiFianzaTotalMto   = 0.00;
$kpiLiquidadasMesCnt = 0;

$listaActivas       = [];
$listaPendientes    = [];
$listaLiquidadas    = [];
$clientesList       = [];
$almacenesList      = [];
$metodosPagoList    = [];
$cuentasBancarias   = [];
$supervisoresList   = [];
$sesionCajaActiva   = null;

if ($pdo) {
    // 1. KPIs Financieros y Operativos (Exactamente 4 indicadores)
    try {
        // Consignaciones Activas (DESPACHADA) y total de fianza en custodia
        $stmtKpi1 = $pdo->query("
            SELECT 
                COUNT(*) AS activas_cnt,
                COALESCE(SUM(monto_deposito_garantia), 0) AS fianza_total
            FROM ventas.consignaciones
            WHERE estado = 'DESPACHADA'
        ");
        $kRow1 = $stmtKpi1 ? $stmtKpi1->fetch(PDO::FETCH_ASSOC) : null;
        if ($kRow1) {
            $kpiActivasCnt     = (int)$kRow1['activas_cnt'];
            $kpiFianzaTotalMto = (float)$kRow1['fianza_total'];
        }
    } catch (Throwable $e) {}

    try {
        // Total de botellas comprometidas en custodia actualmente
        $stmtKpi2 = $pdo->query("
            SELECT COALESCE(SUM(cd.cantidad_despachada - cd.cantidad_devuelta), 0) AS total_comprometido
            FROM ventas.consignaciones_detalle cd
            JOIN ventas.consignaciones c ON c.id = cd.consignacion_id
            WHERE c.estado = 'DESPACHADA'
        ");
        $kpiComprometidasCnt = $stmtKpi2 ? (int)$stmtKpi2->fetchColumn() : 0;
    } catch (Throwable $e) {}

    try {
        // Liquidadas del mes actual
        $stmtKpi3 = $pdo->query("
            SELECT COUNT(*)
            FROM ventas.consignaciones
            WHERE estado = 'LIQUIDADA'
              AND DATE_TRUNC('month', fecha_despacho) = DATE_TRUNC('month', CURRENT_DATE)
        ");
        $kpiLiquidadasMesCnt = $stmtKpi3 ? (int)$stmtKpi3->fetchColumn() : 0;
    } catch (Throwable $e) {}

    // 2. Sesión de caja abierta del cajero actual (Para depósito de fianza en mostrador)
    try {
        $stmtSes = $pdo->prepare("
            SELECT sc.id, sc.caja_id, c.nombre_equipo
            FROM ventas.sesiones_caja sc
            JOIN ventas.cajas_pos c ON c.id = sc.caja_id
            WHERE sc.cajero_id = ? AND sc.estado = 'ABIERTA'
            ORDER BY sc.id DESC LIMIT 1
        ");
        $stmtSes->execute([$personaId]);
        $sesionCajaActiva = $stmtSes->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    // 3. Catálogos auxiliares
    try {
        $stmtCl = $pdo->query("
            SELECT c.persona_id, c.categoria_cliente,
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
        $stmtAlm = $pdo->query("
            SELECT id, nombre FROM inventario.almacenes WHERE activo = true ORDER BY id ASC
        ");
        $almacenesList = $stmtAlm ? $stmtAlm->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    try {
        $stmtMp = $pdo->query("
            SELECT id, codigo, descripcion
            FROM ventas.metodos_pago
            WHERE id IN (1, 2, 3, 4)
            ORDER BY id ASC
        ");
        $metodosPagoList = $stmtMp ? $stmtMp->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    try {
        $stmtCb = $pdo->query("
            SELECT id, nombre_banco, numero_cuenta
            FROM tesoreria.cuentas_bancarias
            WHERE activa = true
            ORDER BY nombre_banco ASC
        ");
        $cuentasBancarias = $stmtCb ? $stmtCb->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    $supervisoresList = obtenerSupervisoresAutorizados($pdo);

    // 4. Listado de Consignaciones Activas / Despachadas
    try {
        $stmtAct = $pdo->query("
            SELECT c.id,
                   c.numero_consignacion,
                   c.nombre_evento,
                   c.responsable_evento,
                   c.fecha_despacho,
                   c.fecha_estimada_retorno,
                   c.monto_deposito_garantia,
                   c.estado,
                   p.nombre_razon_social AS cliente_nombre,
                   p.documento_identidad AS cliente_rnc,
                   p.telefono_principal AS cliente_telefono,
                   u.username AS supervisor_username,
                   (SELECT COALESCE(SUM(cantidad_despachada), 0) FROM ventas.consignaciones_detalle WHERE consignacion_id = c.id) AS total_despachado,
                   (SELECT COALESCE(SUM(cantidad_devuelta), 0) FROM ventas.consignaciones_detalle WHERE consignacion_id = c.id) AS total_devuelto,
                   (SELECT COALESCE(SUM(cantidad_consumida), 0) FROM ventas.consignaciones_detalle WHERE consignacion_id = c.id) AS total_consumido,
                   (c.fecha_estimada_retorno - CURRENT_DATE) AS dias_restantes
            FROM ventas.consignaciones c
            JOIN identidad.clientes cl ON cl.persona_id = c.cliente_id
            JOIN identidad.personas p ON p.persona_id = cl.persona_id
            JOIN seguridad.usuarios u ON u.usuario_id = c.supervisor_id
            WHERE c.estado = 'DESPACHADA'
            ORDER BY c.fecha_estimada_retorno ASC, c.id DESC
        ");
        $listaActivas = $stmtAct ? $stmtAct->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    // 5. Retornos pendientes (Vencidas o por vencer en 3 días)
    foreach ($listaActivas as $a) {
        if ($a['dias_restantes'] <= 3) {
            $listaPendientes[] = $a;
        }
    }

    // 6. Historial de Consignaciones Liquidadas
    try {
        $stmtLiq = $pdo->query("
            SELECT c.id,
                   c.numero_consignacion,
                   c.nombre_evento,
                   c.responsable_evento,
                   c.fecha_despacho,
                   c.monto_deposito_garantia,
                   c.estado,
                   p.nombre_razon_social AS cliente_nombre,
                   f.numero_factura,
                   f.tipo_ncf,
                   f.ncf,
                   f.monto_total AS monto_facturado,
                   (SELECT COALESCE(SUM(cantidad_despachada), 0) FROM ventas.consignaciones_detalle WHERE consignacion_id = c.id) AS total_despachado,
                   (SELECT COALESCE(SUM(cantidad_devuelta), 0) FROM ventas.consignaciones_detalle WHERE consignacion_id = c.id) AS total_devuelto,
                   (SELECT COALESCE(SUM(cantidad_consumida), 0) FROM ventas.consignaciones_detalle WHERE consignacion_id = c.id) AS total_consumido
            FROM ventas.consignaciones c
            JOIN identidad.clientes cl ON cl.persona_id = c.cliente_id
            JOIN identidad.personas p ON p.persona_id = cl.persona_id
            LEFT JOIN ventas.consignacion_facturas cf ON cf.consignacion_id = c.id
            LEFT JOIN ventas.facturas f ON f.id = cf.factura_id
            WHERE c.estado = 'LIQUIDADA'
            ORDER BY c.id DESC
            LIMIT 50
        ");
        $listaLiquidadas = $stmtLiq ? $stmtLiq->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Consignaciones para Eventos y Bodas</title>
    <link rel="stylesheet" href="../styles.css?v=32" />
    <link rel="stylesheet" href="styles.css?v=1" />
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
                <span class="brand-subtitle">Consignaciones</span>
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
                    <h1 class="page-title">🍾 Control de Consignaciones (Eventos y Bodas)</h1>
                    <p class="page-subtitle">
                        Préstamos de mercancía en custodia temporal, depósito de fianza blindado en caja y liquidación con Split Universal.
                    </p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary" onclick="abrirModalNuevaConsignacion()" style="background: #a66e2e; color: #ffffff !important; font-weight: 700;">
                        <span>➕</span> Nueva Consignación (Despacho)
                    </button>
                </div>
            </div>

            <!-- TARJETAS DE INDICADORES (KPIS: EXACTAMENTE 4 TARJETAS) -->
            <div class="csg-kpi-container">
                <div class="csg-kpi-card">
                    <div class="csg-kpi-icon gold">🍾</div>
                    <div class="csg-kpi-data">
                        <h4>Consignaciones Activas</h4>
                        <div class="csg-kpi-value"><?= $kpiActivasCnt ?> eventos</div>
                        <div class="csg-kpi-sub">En custodia temporal</div>
                    </div>
                </div>

                <div class="csg-kpi-card">
                    <div class="csg-kpi-icon blue">📦</div>
                    <div class="csg-kpi-data">
                        <h4>Mercancía Comprometida</h4>
                        <div class="csg-kpi-value" style="color: #1e6091;"><?= number_format($kpiComprometidasCnt) ?> botellas</div>
                        <div class="csg-kpi-sub">Reservadas en inventario</div>
                    </div>
                </div>

                <div class="csg-kpi-card">
                    <div class="csg-kpi-icon green">🛡️</div>
                    <div class="csg-kpi-data">
                        <h4>Fianzas en Custodia</h4>
                        <div class="csg-kpi-value" style="color: #2e7d52;">RD$ <?= number_format($kpiFianzaTotalMto, 2) ?></div>
                        <div class="csg-kpi-sub">Depósitos de garantía activos</div>
                    </div>
                </div>

                <div class="csg-kpi-card">
                    <div class="csg-kpi-icon purple">🧾</div>
                    <div class="csg-kpi-data">
                        <h4>Liquidadas del Mes</h4>
                        <div class="csg-kpi-value" style="color: #6a1b9a;"><?= $kpiLiquidadasMesCnt ?> eventos</div>
                        <div class="csg-kpi-sub">Facturadas y cerradas</div>
                    </div>
                </div>
            </div>

            <!-- NAVEGACIÓN POR PESTAÑAS (TABS) -->
            <div class="csg-tabs">
                <button type="button" class="csg-tab-btn active" onclick="cambiarTabConsignacion('activas', this)">
                    📋 En Curso / Despachadas (<?= count($listaActivas) ?>)
                </button>
                <button type="button" class="csg-tab-btn" onclick="cambiarTabConsignacion('pendientes', this)">
                    ⚠️ Retornos Próximos / En Mora (<?= count($listaPendientes) ?>)
                </button>
                <button type="button" class="csg-tab-btn" onclick="cambiarTabConsignacion('liquidadas', this)">
                    📜 Historial de Liquidadas (<?= count($listaLiquidadas) ?>)
                </button>
            </div>

            <!-- ================================================================= -->
            <!-- TAB 1: CONSIGNACIONES ACTIVAS                                     -->
            <!-- ================================================================= -->
            <div id="tab-activas" class="csg-tab-content active">
                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Expedientes Activos en Custodia</h2>
                            <span class="badge-count" id="badgeTotalActivas"><?= count($listaActivas) ?> eventos</span>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" id="filtroTextoActivas" class="form-control" placeholder="Buscar por cliente, evento o No..." onkeyup="filtrarTablaActivas()" style="width: 260px;" />
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table" id="tablaActivas">
                            <thead>
                                <tr>
                                    <th>No. Consignación</th>
                                    <th>Cliente / Empresa</th>
                                    <th>Evento</th>
                                    <th>Responsable</th>
                                    <th>Fecha Despacho</th>
                                    <th>Retorno Previsto</th>
                                    <th class="text-center">Botellas</th>
                                    <th class="text-right">Fianza en Caja</th>
                                    <th class="text-center">Estado</th>
                                    <th class="text-center" style="min-width: 190px;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaActivas)): ?>
                                    <tr>
                                        <td colspan="10" style="text-align: center; padding: 36px; color: var(--text-muted);">
                                            No hay consignaciones activas en este momento.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaActivas as $csg): 
                                        $diasRest = (int)$csg['dias_restantes'];
                                        $alertaRetorno = '';
                                        if ($diasRest < 0) {
                                            $alertaRetorno = '<span class="badge-mora">' . abs($diasRest) . 'd Vencida</span>';
                                        } elseif ($diasRest === 0) {
                                            $alertaRetorno = '<span class="badge-hoy">Vence Hoy</span>';
                                        } else {
                                            $alertaRetorno = '<span class="badge-plazo">' . $diasRest . 'd restantes</span>';
                                        }
                                    ?>
                                        <tr class="fila-csg" 
                                            data-id="<?= $csg['id'] ?>"
                                            data-numero="<?= htmlspecialchars($csg['numero_consignacion']) ?>"
                                            data-cliente="<?= htmlspecialchars($csg['cliente_nombre']) ?>"
                                            data-evento="<?= htmlspecialchars($csg['nombre_evento']) ?>">
                                            <td><span class="code-inline" style="font-weight: 700;"><?= htmlspecialchars($csg['numero_consignacion']) ?></span></td>
                                            <td>
                                                <strong><?= htmlspecialchars($csg['cliente_nombre']) ?></strong>
                                                <div style="font-size: 0.76rem; color: var(--text-muted);"><?= htmlspecialchars($csg['cliente_rnc'] ?: 'S/D') ?></div>
                                            </td>
                                            <td><strong><?= htmlspecialchars($csg['nombre_evento']) ?></strong></td>
                                            <td style="font-size: 0.84rem;"><?= htmlspecialchars($csg['responsable_evento']) ?></td>
                                            <td style="font-size: 0.82rem;"><?= htmlspecialchars(substr($csg['fecha_despacho'], 0, 10)) ?></td>
                                            <td style="font-size: 0.82rem;">
                                                <?= htmlspecialchars($csg['fecha_estimada_retorno']) ?><br />
                                                <?= $alertaRetorno ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-info" title="Despachadas: <?= $csg['total_despachado'] ?>, Devueltas: <?= $csg['total_devuelto'] ?>">
                                                    📦 <?= $csg['total_despachado'] - $csg['total_devuelto'] ?> bot.
                                                </span>
                                            </td>
                                            <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: #2e7d52;">
                                                RD$ <?= number_format($csg['monto_deposito_garantia'], 2) ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-warning">DESPACHADA</span>
                                            </td>
                                            <td class="text-center">
                                                <div style="display: inline-flex; gap: 4px; align-items: center;">
                                                    <button type="button" class="btn btn-sm btn-outline" onclick="abrirModalDetalle(<?= $csg['id'] ?>)" title="Ver expediente">
                                                        🔍
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-secondary" onclick="abrirModalRetorno(<?= $csg['id'] ?>)" title="Registrar retorno físico">
                                                        📦 Retorno
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-gold" onclick="abrirModalLiquidacion(<?= $csg['id'] ?>)" style="background: #2e7d52; color: #fff; border-color: #2e7d52;" title="Liquidar y Facturar Consumo">
                                                        💰 Liquidar
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ================================================================= -->
            <!-- TAB 2: RETORNOS PRÓXIMOS O EN MORA                                -->
            <!-- ================================================================= -->
            <div id="tab-pendientes" class="csg-tab-content">
                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Retornos Próximos o Vencidos (Semáforo de Custodia)</h2>
                            <span class="badge-count"><?= count($listaPendientes) ?> eventos críticos</span>
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>No. Consignación</th>
                                    <th>Cliente</th>
                                    <th>Evento</th>
                                    <th>Contacto / Teléfono</th>
                                    <th>Fecha Retorno</th>
                                    <th class="text-center">Semáforo</th>
                                    <th class="text-right">Fianza Custodiada</th>
                                    <th class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaPendientes)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 36px; color: var(--text-muted);">
                                            No hay consignaciones con retorno vencido ni en riesgo de vencimiento.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaPendientes as $pnd): ?>
                                        <tr>
                                            <td><span class="code-inline" style="font-weight: 700;"><?= htmlspecialchars($pnd['numero_consignacion']) ?></span></td>
                                            <td><strong><?= htmlspecialchars($pnd['cliente_nombre']) ?></strong></td>
                                            <td><?= htmlspecialchars($pnd['nombre_evento']) ?></td>
                                            <td><?= htmlspecialchars($pnd['responsable_evento']) ?> (<?= htmlspecialchars($pnd['cliente_telefono'] ?: 'Sin Tel.') ?>)</td>
                                            <td><?= htmlspecialchars($pnd['fecha_estimada_retorno']) ?></td>
                                            <td class="text-center">
                                                <?php if ($pnd['dias_restantes'] < 0): ?>
                                                    <span class="badge-mora"><?= abs($pnd['dias_restantes']) ?> DÍAS VENCIDO</span>
                                                <?php elseif ($pnd['dias_restantes'] === 0): ?>
                                                    <span class="badge-hoy">VENCE HOY</span>
                                                <?php else: ?>
                                                    <span class="badge-alerta">VENCE EN <?= $pnd['dias_restantes'] ?> DÍAS</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right" style="font-family: var(--font-mono); font-weight: 700;">
                                                RD$ <?= number_format($pnd['monto_deposito_garantia'], 2) ?>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-secondary" onclick="abrirModalRetorno(<?= $pnd['id'] ?>)">
                                                    📦 Registrar Retorno
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

            <!-- ================================================================= -->
            <!-- TAB 3: HISTORIAL DE LIQUIDADAS                                   -->
            <!-- ================================================================= -->
            <div id="tab-liquidadas" class="csg-tab-content">
                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Historial de Consignaciones Liquidadas</h2>
                            <span class="badge-count"><?= count($listaLiquidadas) ?> liquidadas</span>
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>No. Consignación</th>
                                    <th>Cliente</th>
                                    <th>Evento</th>
                                    <th class="text-center">Despachadas</th>
                                    <th class="text-center">Devueltas</th>
                                    <th class="text-center">Consumidas</th>
                                    <th class="text-right">Fianza Aplicada</th>
                                    <th>Factura Fiscal</th>
                                    <th class="text-right">Total Facturado</th>
                                    <th class="text-center">Detalle</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaLiquidadas)): ?>
                                    <tr>
                                        <td colspan="10" style="text-align: center; padding: 36px; color: var(--text-muted);">
                                            Aún no se han liquidado consignaciones en el sistema.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listaLiquidadas as $liq): ?>
                                        <tr>
                                            <td><span class="code-inline" style="font-weight: 700;"><?= htmlspecialchars($liq['numero_consignacion']) ?></span></td>
                                            <td><strong><?= htmlspecialchars($liq['cliente_nombre']) ?></strong></td>
                                            <td><?= htmlspecialchars($liq['nombre_evento']) ?></td>
                                            <td class="text-center"><?= $liq['total_despachado'] ?></td>
                                            <td class="text-center" style="color: #2e7d52; font-weight: 700;"><?= $liq['total_devuelto'] ?></td>
                                            <td class="text-center" style="color: #b53d32; font-weight: 700;"><?= $liq['total_consumido'] ?></td>
                                            <td class="text-right" style="font-family: var(--font-mono); font-weight: 600;">
                                                RD$ <?= number_format($liq['monto_deposito_garantia'], 2) ?>
                                            </td>
                                            <td>
                                                <?php if ($liq['numero_factura']): ?>
                                                    <span class="code-inline" style="color: #1e6091; font-weight: 700;">
                                                        <?= htmlspecialchars($liq['numero_factura']) ?> (<?= htmlspecialchars($liq['ncf'] ?: 'S/C') ?>)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-info">SIN CONSUMO</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: #1e6091;">
                                                RD$ <?= number_format($liq['monto_facturado'] ?? 0, 2) ?>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-outline" onclick="abrirModalDetalle(<?= $liq['id'] ?>)">
                                                    🔍 Ver
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
        </main>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 1: NUEVA CONSIGNACIÓN (DESPACHO Y FIANZA)                           -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalNuevaConsignacion">
        <div class="modal-dialog modal-xl">
            <div class="modal-header">
                <h3><span>🍾</span> Nueva Consignación (Despacho para Eventos y Bodas)</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalNuevaConsignacion')">&times;</button>
            </div>
            <form id="formNuevaConsignacion" onsubmit="guardarNuevaConsignacion(event)">
                <div class="modal-body" style="padding: 10px 16px; display: flex; flex-direction: column; gap: 6px;">
                    <!-- 1. HEADER: DATOS DEL EVENTO (IZQ) + AUTORIZACIÓN DE SUPERVISOR (DER, UNO ABAJO DE OTRO) -->
                    <div style="background: #faf8f5; border: 1px solid #eae3d5; border-radius: 8px; padding: 8px 12px; display: grid; grid-template-columns: 2.2fr 1fr; gap: 12px; align-items: start;">
                        
                        <!-- Lado Izquierdo: Evento y Cliente -->
                        <div style="display: flex; flex-direction: column; gap: 6px;">
                            <div style="display: grid; grid-template-columns: 1.2fr 1fr 1fr; gap: 8px; align-items: start;">
                                <!-- BUSCADOR INTERACTIVO DE CLIENTE -->
                                <div class="form-group" style="margin: 0; position: relative;">
                                    <label class="form-label" style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Cliente Registrado <span style="color:red;">*</span></label>
                                    <input type="hidden" id="csgCliente" name="cliente_id" value="" required />
                                    
                                    <div id="boxBusquedaClienteCsg" style="position: relative;">
                                        <input type="text" id="inputBuscarClienteCsg" class="form-control" placeholder="🔍 Escriba nombre o RNC..." autocomplete="off" oninput="filtrarClientesCsg(this.value)" onfocus="abrirDropdownClientesCsg()" style="height: 28px; font-size: 0.80rem;" />
                                        <div id="dropdownClientesCsg" style="display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 180px; overflow-y: auto; background: #ffffff; border: 1.5px solid #d4c1a7; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.15); z-index: 1050; margin-top: 2px;">
                                            <!-- Inyectado dinámicamente -->
                                        </div>
                                    </div>

                                    <div id="badgeClienteElegidoCsg" style="display: none; background: #fdf5ea; border: 1px solid #dfcaa4; border-radius: 6px; padding: 2px 8px; align-items: center; justify-content: space-between; height: 28px;">
                                        <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.78rem; color: #211813;">
                                            <strong id="txtNombreClienteCsg">—</strong>
                                            <small id="txtDetalleClienteCsg" style="color: #7a6b5d; margin-left: 6px;"></small>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="limpiarClienteSeleccionadoCsg()" style="padding: 1px 6px; font-size: 0.68rem; height: 20px; flex-shrink: 0;">✕ Cambiar</button>
                                    </div>
                                </div>

                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Nombre del Evento <span style="color:red;">*</span></label>
                                    <input type="text" id="csgNombreEvento" class="form-control" placeholder="Ej: Boda Martínez - Gómez" required style="height: 28px; font-size: 0.80rem;" />
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Responsable / Contacto <span style="color:red;">*</span></label>
                                    <input type="text" id="csgResponsable" class="form-control" placeholder="Ej: Lic. Martínez (809-555-0192)" required style="height: 28px; font-size: 0.80rem;" />
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr 1.2fr; gap: 8px; align-items: start;">
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Fecha Estimada de Retorno <span style="color:red;">*</span></label>
                                    <input type="date" id="csgFechaRetorno" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required style="height: 28px; font-size: 0.80rem;" />
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Almacén de Despacho <span style="color:red;">*</span></label>
                                    <select id="csgAlmacen" class="form-control" onchange="limpiarCarritoDespacho(); cargarCatalogoAlmacenCsg();" style="height: 28px; font-size: 0.80rem;">
                                        <?php foreach ($almacenesList as $alm): ?>
                                            <option value="<?= $alm['id'] ?>"><?= htmlspecialchars($alm['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Depósito de Fianza (Garantía) <span style="color:red;">*</span></label>
                                    <div style="display: flex; gap: 6px;">
                                        <input type="number" step="0.01" min="0" id="csgMontoFianza" class="form-control" placeholder="0.00" value="0.00" style="font-family: var(--font-mono); font-weight: 700; width: 95px; height: 28px; font-size: 0.80rem;" onchange="alCambiarMontoFianza()" />
                                        <select id="csgMetodoFianza" class="form-control" style="flex: 1; height: 28px; font-size: 0.80rem;">
                                            <?php foreach ($metodosPagoList as $mp): ?>
                                                <option value="<?= $mp['id'] ?>"><?= htmlspecialchars($mp['descripcion']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Lado Derecho: Autorización de Supervisor (Uno abajo de otro) -->
                        <div style="background: #fff8eb; border: 1.5px solid #f0d8a8; border-radius: 6px; padding: 6px 10px; display: flex; flex-direction: column; gap: 4px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #fae8c8; padding-bottom: 2px;">
                                <span style="font-size: 0.70rem; font-weight: 700; color: #7a5216; text-transform: uppercase;">
                                    🛡️ Autorización Supervisor
                                </span>
                                <small style="color: #9a6828; font-size: 0.65rem; font-weight: 600;">Obligatorio</small>
                            </div>
                            
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label" style="font-size: 0.68rem; font-weight: 700; margin-bottom: 1px;">Supervisor Autorizado <span style="color:red;">*</span></label>
                                <select id="csgSupervisor" class="form-control" required style="height: 27px; font-size: 0.78rem; padding: 2px 6px;">
                                    <option value="">-- Seleccionar Supervisor --</option>
                                    <?php foreach ($supervisoresList as $sup): ?>
                                        <option value="<?= htmlspecialchars($sup['usuario_id']) ?>">
                                            <?= htmlspecialchars($sup['nombre_completo']) ?> (<?= htmlspecialchars($sup['username']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group" style="margin: 0;">
                                <label class="form-label" style="font-size: 0.68rem; font-weight: 700; margin-bottom: 1px;">PIN Numérico <span style="color:red;">*</span></label>
                                <input type="password" id="csgPin" class="form-control" maxlength="6" placeholder="PIN de 4 a 6 dígitos" required autocomplete="off" style="height: 27px; font-size: 0.78rem; padding: 2px 6px;" />
                            </div>
                        </div>

                    </div>

                    <!-- 2. CATÁLOGO Y CARRITO DE CONSIGNACIÓN (AMPLIADO Y MÁS ABAJO) -->
                    <div class="grid-split-consignacion" style="display: grid; grid-template-columns: 1fr 1.25fr; gap: 10px; margin-bottom: 4px; align-items: stretch;">
                        
                        <!-- Columna Izquierda: Catálogo de Productos con Stock -->
                        <div style="background: #faf8f5; border: 1.5px solid #dfcaa4; border-radius: 8px; padding: 8px 10px; display: flex; flex-direction: column; height: 265px; box-sizing: border-box; gap: 6px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; height: 22px; border-bottom: 1px solid #ebdcc5; padding-bottom: 2px;">
                                <span style="font-size: 0.74rem; font-weight: 700; color: var(--accent-gold); text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap;">
                                    Catálogo de Artículos
                                </span>
                                <span id="lblEstadoCatalogoCsg" style="font-size: 0.70rem; color: var(--text-muted); font-weight: 600; white-space: nowrap;">
                                    Disponibles en almacén
                                </span>
                            </div>

                            <!-- Buscador en Catálogo -->
                            <input type="text" id="inputBuscarCatalogoCsg" class="form-control"
                                placeholder="🔍 Buscar licor, whisky, vino..."
                                oninput="alFiltrarCatalogoCsg(this.value)"
                                style="height: 30px; padding-left: 8px; font-size: 0.80rem; flex-shrink: 0;" />

                            <!-- Panel de Productos Scrolleable -->
                            <div id="panelResultadosCatalogoCsg" style="flex: 1; min-height: 0; overflow-y: auto; background: #ffffff; border: 1px solid #dfcaa4; border-radius: 6px; padding: 4px; display: flex; flex-direction: column; gap: 3px;">
                                <!-- Items dinámicos con botones Agregar / Quitar -->
                            </div>
                        </div>

                        <!-- Columna Derecha: Artículos en la Consignación (Carrito de Despacho) -->
                        <div style="background: #faf8f5; border: 1.5px solid #dfcaa4; border-radius: 8px; padding: 8px 10px; display: flex; flex-direction: column; height: 265px; box-sizing: border-box; gap: 6px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; height: 22px; border-bottom: 1px solid #ebdcc5; padding-bottom: 2px;">
                                <span style="font-size: 0.74rem; font-weight: 700; color: var(--accent-gold); text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap;">
                                    Mercancía en la Consignación
                                </span>
                                <small style="color: var(--text-muted); font-size: 0.70rem; font-weight: 600; white-space: nowrap;">Define botellas a entregar</small>
                            </div>

                            <div style="flex: 1; min-height: 0; overflow-y: auto; background: #ffffff; border: 1px solid #dfcaa4; border-radius: 6px;">
                                <table class="data-table" style="font-size: 0.78rem; margin: 0; width: 100%; border-collapse: collapse;">
                                    <thead style="position: sticky; top: 0; z-index: 5;">
                                        <tr style="background: #f6efe4; white-space: nowrap;">
                                            <th style="width: 36%; padding: 5px 6px; font-size: 0.68rem; font-weight: 700; color: #5a3e24; text-transform: uppercase; white-space: nowrap;">Producto Maestro</th>
                                            <th style="width: 20%; padding: 5px 4px; font-size: 0.68rem; font-weight: 700; color: #5a3e24; text-transform: uppercase; white-space: nowrap;">Lote (FEFO)</th>
                                            <th style="width: 12%; text-align: center; padding: 5px 4px; font-size: 0.68rem; font-weight: 700; color: #5a3e24; text-transform: uppercase; white-space: nowrap;">Cant. *</th>
                                            <th style="width: 15%; text-align: right; padding: 5px 4px; font-size: 0.68rem; font-weight: 700; color: #5a3e24; text-transform: uppercase; white-space: nowrap;">Precio (RD$) *</th>
                                            <th style="width: 13%; text-align: right; padding: 5px 6px; font-size: 0.68rem; font-weight: 700; color: #5a3e24; text-transform: uppercase; white-space: nowrap;">Subtotal</th>
                                            <th style="width: 4%; text-align: center; padding: 5px 2px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="csgTbodyCarrito">
                                        <tr id="csgFilaVaciaCarrito">
                                            <td colspan="6" style="text-align: center; padding: 24px 14px; color: var(--text-muted); font-size: 0.80rem;">
                                                No hay artículos agregados al despacho. Haz clic en <strong>+ Agregar</strong> en el catálogo de la izquierda.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div style="background: #ffffff; border: 1px solid #dfcaa4; border-radius: 6px; padding: 4px 10px; display: flex; justify-content: space-between; align-items: center; font-weight: 700; min-height: 28px; box-sizing: border-box; flex-shrink: 0;">
                                <div style="display: flex; gap: 14px; font-size: 0.74rem; color: #5a3e24;">
                                    <span>Total Botellas: <strong id="csgTxtTotalBotellas" style="color: #1e6091; font-family: var(--font-mono);">0</strong> u.</span>
                                </div>
                                <div style="font-size: 0.82rem; font-family: var(--font-mono); color: var(--accent-gold);">
                                    <span style="font-size: 0.68rem; color: #7a6b5d; text-transform: uppercase; font-family: var(--font-sans); margin-right: 4px;">Valor Estimado:</span>
                                    <span id="csgTxtTotalValor">RD$ 0.00</span>
                                </div>
                            </div>
                        </div>

                    </div>

                </div>

                <div class="modal-footer" style="padding: 10px 16px;">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalNuevaConsignacion')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarNuevaConsignacion" style="background: #a66e2e; color: #ffffff !important; font-weight: 700; padding: 7px 20px;">
                        <span>📦</span> Despachar y Reservar Stock
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 2: REGISTRAR RETORNO FÍSICO (FASE 2)                                -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalRetornoFisico">
        <div class="modal-dialog modal-lg">
            <div class="modal-header">
                <h3><span>📦</span> Conteo Físico y Retorno de Mercancía Post-Evento</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalRetornoFisico')">&times;</button>
            </div>
            <form id="formRetornoFisico" onsubmit="guardarRetornoFisico(event)">
                <input type="hidden" id="retConsignacionId" />
                <div class="modal-body">
                    <div id="retCabeceraInfo" style="background: #fdfaf6; border: 1px solid var(--border-light); border-radius: 8px; padding: 12px 16px; margin-bottom: 14px;">
                        <!-- Inyectado por JS -->
                    </div>

                    <p style="font-size: 0.84rem; color: var(--text-muted); margin-bottom: 10px;">
                        Digita las botellas que regresaron <strong>selladas e intactas</strong> al almacén. El consumo se calculará automáticamente:
                    </p>

                    <div class="table-wrapper" style="max-height: 280px; overflow-y: auto; margin-bottom: 14px;">
                        <table class="data-table" style="font-size: 0.84rem;">
                            <thead>
                                <tr>
                                    <th>Producto Maestro</th>
                                    <th>Lote</th>
                                    <th class="text-center">Despachadas</th>
                                    <th class="text-center" style="width: 130px;">Botellas Devueltas</th>
                                    <th class="text-center" style="width: 130px;">Botellas Consumidas</th>
                                    <th class="text-right">Precio Pactado</th>
                                    <th class="text-right">Subtotal Consumo</th>
                                </tr>
                            </thead>
                            <tbody id="retTbodyArticulos">
                                <!-- Filas generadas por JS -->
                            </tbody>
                        </table>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; background: #faf7f2; border: 1px solid var(--border-light); border-radius: 8px; padding: 12px 16px; margin-bottom: 14px;">
                        <div>
                            <span>Total Devueltas: <strong id="retTxtTotalDevueltas" style="color: #2e7d52;">0</strong></span>
                            <span style="margin-left: 14px;">Total Consumidas: <strong id="retTxtTotalConsumidas" style="color: #b53d32;">0</strong></span>
                        </div>
                        <div>
                            <span>Monto Consumo Resultante:</span>
                            <strong id="retTxtTotalConsumoMonto" style="font-size: 1.15rem; font-family: var(--font-mono); color: var(--text-dark); margin-left: 6px;">RD$ 0.00</strong>
                        </div>
                    </div>

                    <!-- AUTORIZACIÓN PIN -->
                    <div style="background: #fff8eb; border: 1px solid #f0d8a8; border-radius: 8px; padding: 12px 16px;">
                        <h4 style="font-size: 0.84rem; font-weight: 700; color: #7a5216; margin-bottom: 8px;">
                            🛡️ Auditoría del Conteo Físico por Supervisor
                        </h4>
                        <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 12px;">
                            <div class="form-group">
                                <label class="form-label">Supervisor <span style="color:red;">*</span></label>
                                <select id="retSupervisor" class="form-control" required>
                                    <option value="">-- Seleccionar Supervisor --</option>
                                    <?php foreach ($supervisoresList as $sup): ?>
                                        <option value="<?= htmlspecialchars($sup['usuario_id']) ?>">
                                            <?= htmlspecialchars($sup['nombre_completo']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">PIN Numérico <span style="color:red;">*</span></label>
                                <input type="password" id="retPin" class="form-control" maxlength="6" placeholder="PIN de 4 a 6 dígitos" required autocomplete="off" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalRetornoFisico')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarRetorno" style="background: #1e6091;">
                        <span>💾</span> Asentar Conteo y Actualizar Consumo
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 3: LIQUIDACIÓN FINANCIERA, FACTURA B01/B02 Y SPLIT (FASE 3)         -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalLiquidacionFinanciera">
        <div class="modal-dialog modal-lg">
            <div class="modal-header">
                <h3><span>💰</span> Liquidación Financiera, Facturación y Split</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalLiquidacionFinanciera')">&times;</button>
            </div>
            <form id="formLiquidacionFinanciera" onsubmit="guardarLiquidacionFinanciera(event)">
                <input type="hidden" id="liqConsignacionId" />
                <div class="modal-body">
                    <!-- RESUMEN COMPARATIVO: FIANZA VS CONSUMO -->
                    <div class="csg-liquidacion-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 14px;">
                        <div class="csg-liq-box">
                            <small>Monto Total Consumido</small>
                            <strong id="liqTxtTotalConsumido" style="color: #b53d32;">RD$ 0.00</strong>
                        </div>
                        <div class="csg-liq-box">
                            <small>Fianza en Custodia (Depósito)</small>
                            <strong id="liqTxtMontoFianza" style="color: #2e7d52;">RD$ 0.00</strong>
                        </div>
                        <div class="csg-liq-box" id="liqBoxBalance">
                            <small id="liqLblBalance">Diferencia a Cobrar</small>
                            <strong id="liqTxtBalanceDiferencia" style="color: var(--text-dark);">RD$ 0.00</strong>
                        </div>
                    </div>

                    <!-- OPCIÓN FISCAL (B01 / B02) -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                        <div class="form-group">
                            <label class="form-label">Comprobante Fiscal (DGII) <span style="color:red;">*</span></label>
                            <select id="liqTipoNcf" class="form-control" required>
                                <option value="B02">B02 • Consumo Final</option>
                                <option value="B01">B01 • Crédito Fiscal</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Almacén de Descarga Definitiva</label>
                            <select id="liqAlmacen" class="form-control">
                                <?php foreach ($almacenesList as $alm): ?>
                                    <option value="<?= $alm['id'] ?>"><?= htmlspecialchars($alm['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- SECCIÓN SPLIT: COBRO DE LA DIFERENCIA (SI CONSUMO > FIANZA) -->
                    <div id="liqSeccionSplitDiferencia" style="display: none; background: #faf7f2; border: 1px solid var(--border-light); border-radius: 8px; padding: 12px 16px; margin-bottom: 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h4 style="font-size: 0.86rem; font-weight: 700; color: var(--text-dark);">
                                💳 Split de Cobro para la Diferencia Restante (<span id="liqTxtMontoDiferenciaSpan">RD$ 0.00</span>)
                            </h4>
                            <button type="button" class="btn btn-sm btn-outline" onclick="agregarFilaSplitLiq()">+ Método de Pago</button>
                        </div>
                        <div id="liqContenedorSplit">
                            <!-- Filas dinámicas -->
                        </div>
                    </div>

                    <!-- SECCIÓN REEMBOLSO (SI CONSUMO < FIANZA) -->
                    <div id="liqSeccionReembolso" style="display: none; background: #eef8f2; border: 1px solid #c2e5d1; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px;">
                        <h4 style="font-size: 0.86rem; font-weight: 700; color: #2e7d52; margin-bottom: 4px;">
                            💵 Reembolso de Excedente de Fianza al Cliente
                        </h4>
                        <p style="font-size: 0.82rem; color: #1b5e20; margin-bottom: 0;">
                            El consumo fue menor a la fianza entregada. Se devolverá la diferencia de <strong id="liqTxtReembolsoMonto">RD$ 0.00</strong> emitiendo un <strong>EGRESO</strong> de caja autorizado.
                        </p>
                    </div>

                    <!-- AUTORIZACIÓN PIN -->
                    <div style="background: #fff8eb; border: 1px solid #f0d8a8; border-radius: 8px; padding: 12px 16px;">
                        <h4 style="font-size: 0.84rem; font-weight: 700; color: #7a5216; margin-bottom: 8px;">
                            🛡️ Autorización de Supervisor para Liquidación y Emisión Fiscal
                        </h4>
                        <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 12px;">
                            <div class="form-group">
                                <label class="form-label">Supervisor <span style="color:red;">*</span></label>
                                <select id="liqSupervisor" class="form-control" required>
                                    <option value="">-- Seleccionar Supervisor --</option>
                                    <?php foreach ($supervisoresList as $sup): ?>
                                        <option value="<?= htmlspecialchars($sup['usuario_id']) ?>">
                                            <?= htmlspecialchars($sup['nombre_completo']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">PIN Numérico <span style="color:red;">*</span></label>
                                <input type="password" id="liqPin" class="form-control" maxlength="6" placeholder="PIN de 4 a 6 dígitos" required autocomplete="off" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalLiquidacionFinanciera')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnProcesarLiquidacion" style="background: #2e7d52;">
                        <span>🧾</span> Emitir Factura Fiscal y Cerrar Consignación
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 4: EXPEDIENTE / VER DETALLE                                        -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalDetalleConsignacion">
        <div class="modal-dialog modal-xl">
            <div class="modal-header">
                <h3><span>🔍</span> Expediente de Consignación</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalDetalleConsignacion')">&times;</button>
            </div>
            <div class="modal-body" id="bodyDetalleConsignacion">
                <!-- Inyectado dinámicamente -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalDetalleConsignacion')">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- INYECCIÓN DE CATÁLOGOS JS -->
    <script>
        const CLIENTES_LISTA   = <?= json_encode($clientesList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const CATALOGO_METODOS = <?= json_encode($metodosPagoList) ?>;
        const CATALOGO_BANCOS  = <?= json_encode($cuentasBancarias) ?>;
        const SESION_CAJA_ACTIVA = <?= json_encode($sesionCajaActiva) ?>;
    </script>
    <script src="consignaciones.js?v=4"></script>
</body>

</html>
