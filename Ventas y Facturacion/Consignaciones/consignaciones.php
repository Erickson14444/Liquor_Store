<?php
/**
 * ============================================================================
 * Vista Principal: Proceso 5 - Consignaciones (Eventos y Bodas)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/Consignaciones/consignaciones.php
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
if (file_exists(__DIR__ . '/../../config/funciones.php')) {
    require_once __DIR__ . '/../../config/funciones.php';
}
require_once __DIR__ . '/../../config/auditoria_helper.php';

$usuarioId = requerirSesionActiva(false, '../../Login y Menu/login.php');

$esAdmin = function_exists('usuarioEsAdministrador') && usuarioEsAdministrador();
$puedeCsg = $esAdmin || (function_exists('usuarioPuedeAccederConsignaciones') && usuarioPuedeAccederConsignaciones());

if (!$puedeCsg) {
    header("Location: ../../Login y Menu/menu.php?error=" . urlencode("Acceso restringido: no posees permisos para Consignaciones."));
    exit;
}

$personaId = $_SESSION['usuario']['persona_id'] ?? null;

$kpiActivasCnt = 0;
$kpiComprometidasCnt = 0;
$kpiFianzaTotalMto = 0.00;
$kpiLiquidadasMesCnt = 0;

$listaActivas = [];
$listaPendientes = [];
$listaLiquidadas = [];
$clientesList = [];
$almacenesList = [];
$metodosPagoList = [];
$cuentasBancariasDop = [];
$supervisoresList = [];
$sesionCajaActiva = null;

if ($pdo) {
    // 1. KPIs Financieros y Operativos
    try {
        $stmtKpi1 = $pdo->query("
            SELECT COUNT(*) AS activas_cnt, COALESCE(SUM(monto_deposito_garantia), 0) AS fianza_total
            FROM ventas.consignaciones
            WHERE estado = 'DESPACHADA'
        ");
        $kRow1 = $stmtKpi1 ? $stmtKpi1->fetch(PDO::FETCH_ASSOC) : null;
        if ($kRow1) {
            $kpiActivasCnt = (int) $kRow1['activas_cnt'];
            $kpiFianzaTotalMto = (float) $kRow1['fianza_total'];
        }
    } catch (Throwable $e) {
    }

    try {
        $stmtKpi2 = $pdo->query("
            SELECT COALESCE(SUM(cd.cantidad_despachada - cd.cantidad_devuelta), 0)
            FROM ventas.consignaciones_detalle cd
            JOIN ventas.consignaciones c ON c.id = cd.consignacion_id
            WHERE c.estado = 'DESPACHADA'
        ");
        $kpiComprometidasCnt = $stmtKpi2 ? (int) $stmtKpi2->fetchColumn() : 0;
    } catch (Throwable $e) {
    }

    try {
        $stmtKpi3 = $pdo->query("
            SELECT COUNT(*) FROM ventas.consignaciones
            WHERE estado = 'LIQUIDADA' AND DATE_TRUNC('month', fecha_despacho) = DATE_TRUNC('month', CURRENT_DATE)
        ");
        $kpiLiquidadasMesCnt = $stmtKpi3 ? (int) $stmtKpi3->fetchColumn() : 0;
    } catch (Throwable $e) {
    }

    // 2. Sesión de caja ABIERTA del usuario actual (cajero_id = usuario_id o persona_id)
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

    // 3. Catálogos auxiliares (excluyendo CLI-000 Consumidor Final)
    try {
        $stmtCl = $pdo->query("
            SELECT c.persona_id, c.codigo_cliente, c.categoria_cliente,
                   p.nombre_razon_social, p.documento_identidad, p.tipo_documento, p.telefono_principal
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
        $almacenesList = $pdo->query("SELECT id, nombre FROM inventario.almacenes WHERE activo = TRUE ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    try {
        $metodosPagoList = $pdo->query("
            SELECT id, codigo, descripcion
            FROM ventas.metodos_pago
            WHERE activo = TRUE AND UPPER(codigo) IN ('EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'CHEQUE')
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    // Cuentas bancarias activas en DOP (Consignaciones opera en DOP)
    try {
        $cuentasBancariasDop = $pdo->query("
            SELECT cb.id, cb.nombre_banco, cb.numero_cuenta, cb.tipo_cuenta,
                   COALESCE(m.codigo_iso, 'DOP') AS moneda_iso
            FROM tesoreria.cuentas_bancarias cb
            LEFT JOIN tesoreria.monedas m ON m.id = cb.moneda_id
            WHERE cb.activa = TRUE
              AND (COALESCE(m.es_moneda_base, TRUE) = TRUE OR UPPER(COALESCE(m.codigo_iso, 'DOP')) = 'DOP')
            ORDER BY cb.nombre_banco ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    try {
        $supervisoresList = obtenerSupervisoresAutorizados($pdo);
    } catch (Throwable $e) {
    }

    // 4. Consignaciones Activas
    try {
        $listaActivas = $pdo->query("
            SELECT c.id, c.numero_consignacion, c.nombre_evento, c.responsable_evento,
                   c.fecha_despacho, c.fecha_estimada_retorno, c.monto_deposito_garantia, c.estado,
                   p.nombre_razon_social AS cliente_nombre, p.documento_identidad AS cliente_rnc, p.telefono_principal AS cliente_telefono,
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
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    foreach ($listaActivas as $a) {
        if ((int) $a['dias_restantes'] <= 3) {
            $listaPendientes[] = $a;
        }
    }

    // 5. Consignaciones Liquidadas
    try {
        $listaLiquidadas = $pdo->query("
            SELECT c.id, c.numero_consignacion, c.nombre_evento, c.responsable_evento,
                   c.fecha_despacho, c.monto_deposito_garantia, c.estado,
                   p.nombre_razon_social AS cliente_nombre,
                   f.numero_factura, f.tipo_ncf, f.ncf, f.monto_total AS monto_facturado,
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
            LIMIT 60
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Consignaciones para Eventos y Bodas</title>
    <link rel="stylesheet" href="../styles.css?v=32" />
    <link rel="stylesheet" href="styles.css?v=<?= time() ?>" />
</head>

<body>

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
                    <h1 class="page-title">🍾 Control de Consignaciones (Eventos y Bodas)</h1>
                    <p class="page-subtitle">Préstamos de mercancía en custodia temporal, fianza garantizada y liquidación fiscal con impuestos DGII y Split Universal.</p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary" onclick="abrirModalNuevaConsignacion()">
                        <span>➕</span> Nueva Consignación (Despacho)
                    </button>
                </div>
            </div>

            <!-- KPIs -->
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

            <!-- TABS -->
            <div class="csg-tabs">
                <button type="button" class="csg-tab-btn active" onclick="cambiarTabConsignacion('activas', this)">📋 En Curso / Despachadas (<?= count($listaActivas) ?>)</button>
                <button type="button" class="csg-tab-btn" onclick="cambiarTabConsignacion('pendientes', this)">⚠️ Retornos Próximos / En Mora (<?= count($listaPendientes) ?>)</button>
                <button type="button" class="csg-tab-btn" onclick="cambiarTabConsignacion('liquidadas', this)">📜 Historial de Liquidadas (<?= count($listaLiquidadas) ?>)</button>
            </div>

            <!-- TAB 1: ACTIVAS -->
            <div id="tab-activas" class="csg-tab-content active">
                <div class="table-card">
                    <div class="table-card-top">
                        <div class="table-card-info">
                            <h2 class="table-card-title">Expedientes Activos en Custodia</h2>
                            <span class="badge-count" id="badgeTotalActivas"><?= count($listaActivas) ?> eventos</span>
                        </div>
                        <input type="text" id="filtroTextoActivas" class="form-control" placeholder="Buscar por cliente, evento o No..." oninput="filtrarTablaActivas()" style="width: 260px;" />
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table" id="tablaActivas">
                            <thead>
                                <tr>
                                    <th>No. Consignación</th>
                                    <th>Cliente / Empresa</th>
                                    <th>Evento</th>
                                    <th>Responsable</th>
                                    <th>Despacho</th>
                                    <th>Retorno Previsto</th>
                                    <th class="text-center">Botellas (Desp / Dev)</th>
                                    <th class="text-right">Fianza</th>
                                    <th class="text-center">Estado</th>
                                    <th class="text-center" style="min-width: 195px;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaActivas)): ?>
                                        <tr><td colspan="10" style="text-align: center; padding: 36px; color: var(--text-muted);">No hay consignaciones activas en este momento.</td></tr>
                                <?php else: ?>
                                        <?php foreach ($listaActivas as $csg):
                                            $diasRest = (int) $csg['dias_restantes'];
                                            $alertaRetorno = ($diasRest < 0)
                                                ? '<span class="badge-mora">' . abs($diasRest) . 'd Vencida</span>'
                                                : (($diasRest === 0) ? '<span class="badge-hoy">Vence Hoy</span>' : '<span class="badge-plazo">' . $diasRest . 'd restantes</span>');
                                            ?>
                                                <tr class="fila-csg"
                                                    data-id="<?= (int) $csg['id'] ?>"
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
                                                    <td style="font-size: 0.82rem; white-space: nowrap;"><?= htmlspecialchars(substr((string) $csg['fecha_despacho'], 0, 10)) ?></td>
                                                    <td style="font-size: 0.82rem; white-space: nowrap;"><?= htmlspecialchars($csg['fecha_estimada_retorno']) ?><br /><?= $alertaRetorno ?></td>
                                                    <td class="text-center" style="white-space: nowrap;">
                                                        <span class="badge badge-info">📦 <?= (int) $csg['total_despachado'] ?> / <?= (int) $csg['total_devuelto'] ?></span>
                                                    </td>
                                                    <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: #2e7d52; white-space: nowrap;">
                                                        RD$ <?= number_format((float) $csg['monto_deposito_garantia'], 2) ?>
                                                    </td>
                                                    <td class="text-center"><span class="badge badge-warning">DESPACHADA</span></td>
                                                    <td class="text-center">
                                                        <div style="display: inline-flex; gap: 4px; align-items: center;">
                                                            <button type="button" class="btn btn-sm btn-outline" onclick="abrirModalDetalle(<?= (int) $csg['id'] ?>)" title="Ver expediente">🔍</button>
                                                            <button type="button" class="btn btn-sm btn-secondary" onclick="abrirModalRetorno(<?= (int) $csg['id'] ?>)" title="Registrar conteo físico de retorno">📦 Retorno</button>
                                                            <button type="button" class="btn btn-sm btn-gold" onclick="abrirModalLiquidacion(<?= (int) $csg['id'] ?>)" style="background: #2e7d52; color: #fff; border-color: #2e7d52;" title="Liquidar y Facturar">💰 Liquidar</button>
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

            <!-- TAB 2: RETORNOS PRÓXIMOS / MORA -->
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
                                    <th>No. Consignación</th><th>Cliente</th><th>Evento</th><th>Contacto / Teléfono</th>
                                    <th>Fecha Retorno</th><th class="text-center">Semáforo</th><th class="text-right">Fianza</th><th class="text-center">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaPendientes)): ?>
                                        <tr><td colspan="8" style="text-align: center; padding: 36px; color: var(--text-muted);">No hay consignaciones vencidas ni próximas a vencer.</td></tr>
                                <?php else: ?>
                                        <?php foreach ($listaPendientes as $pnd): ?>
                                                <tr>
                                                    <td><span class="code-inline" style="font-weight: 700;"><?= htmlspecialchars($pnd['numero_consignacion']) ?></span></td>
                                                    <td><strong><?= htmlspecialchars($pnd['cliente_nombre']) ?></strong></td>
                                                    <td><?= htmlspecialchars($pnd['nombre_evento']) ?></td>
                                                    <td><?= htmlspecialchars($pnd['responsable_evento']) ?> (<?= htmlspecialchars($pnd['cliente_telefono'] ?: 'Sin Tel.') ?>)</td>
                                                    <td><?= htmlspecialchars($pnd['fecha_estimada_retorno']) ?></td>
                                                    <td class="text-center">
                                                        <?php if ((int) $pnd['dias_restantes'] < 0): ?>
                                                                <span class="badge-mora"><?= abs((int) $pnd['dias_restantes']) ?> DÍAS VENCIDO</span>
                                                        <?php elseif ((int) $pnd['dias_restantes'] === 0): ?>
                                                                <span class="badge-hoy">VENCE HOY</span>
                                                        <?php else: ?>
                                                                <span class="badge-alerta">VENCE EN <?= (int) $pnd['dias_restantes'] ?> DÍAS</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-right" style="font-family: var(--font-mono); font-weight: 700;">RD$ <?= number_format((float) $pnd['monto_deposito_garantia'], 2) ?></td>
                                                    <td class="text-center">
                                                        <button type="button" class="btn btn-sm btn-secondary" onclick="abrirModalRetorno(<?= (int) $pnd['id'] ?>)">📦 Registrar Retorno</button>
                                                    </td>
                                                </tr>
                                        <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 3: HISTORIAL LIQUIDADAS -->
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
                                    <th>No. Consignación</th><th>Cliente</th><th>Evento</th>
                                    <th class="text-center">Desp.</th><th class="text-center">Dev.</th><th class="text-center">Cons.</th>
                                    <th class="text-right">Fianza</th><th>Factura Fiscal</th><th class="text-right">Total Facturado</th><th class="text-center">Detalle</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaLiquidadas)): ?>
                                        <tr><td colspan="10" style="text-align: center; padding: 36px; color: var(--text-muted);">Aún no se han liquidado consignaciones.</td></tr>
                                <?php else: ?>
                                        <?php foreach ($listaLiquidadas as $liq): ?>
                                                <tr>
                                                    <td><span class="code-inline" style="font-weight: 700;"><?= htmlspecialchars($liq['numero_consignacion']) ?></span></td>
                                                    <td><strong><?= htmlspecialchars($liq['cliente_nombre']) ?></strong></td>
                                                    <td><?= htmlspecialchars($liq['nombre_evento']) ?></td>
                                                    <td class="text-center"><?= (int) $liq['total_despachado'] ?></td>
                                                    <td class="text-center" style="color: #2e7d52; font-weight: 700;"><?= (int) $liq['total_devuelto'] ?></td>
                                                    <td class="text-center" style="color: #b53d32; font-weight: 700;"><?= (int) $liq['total_consumido'] ?></td>
                                                    <td class="text-right" style="font-family: var(--font-mono);">RD$ <?= number_format((float) $liq['monto_deposito_garantia'], 2) ?></td>
                                                    <td>
                                                        <?php if ($liq['numero_factura']): ?>
                                                                <span class="code-inline" style="color: #1e6091; font-weight: 700;"><?= htmlspecialchars($liq['numero_factura']) ?> (<?= htmlspecialchars($liq['ncf'] ?: 'S/C') ?>)</span>
                                                        <?php else: ?>
                                                                <span class="badge badge-info">SIN CONSUMO</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: #1e6091;">RD$ <?= number_format((float) ($liq['monto_facturado'] ?? 0), 2) ?></td>
                                                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline" onclick="abrirModalDetalle(<?= (int) $liq['id'] ?>)">🔍 Ver</button></td>
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
                <div class="modal-body" style="padding: 12px 16px;">
                    <div style="background: #faf8f5; border: 1px solid #eae3d5; border-radius: 8px; padding: 10px 12px; display: grid; grid-template-columns: 2.2fr 1fr; gap: 12px; align-items: start; margin-bottom: 10px;">
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <div style="display: grid; grid-template-columns: 1.2fr 1fr 1fr; gap: 8px;">
                                <div class="form-group" style="margin: 0; position: relative;">
                                    <label class="form-label" style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Cliente Registrado <span style="color:red;">*</span></label>
                                    <input type="hidden" id="csgCliente" name="cliente_id" value="" required />
                                    <div id="boxBusquedaClienteCsg" style="position: relative;">
                                        <input type="text" id="inputBuscarClienteCsg" class="form-control" placeholder="🔍 Escriba nombre o RNC..." autocomplete="off" oninput="filtrarClientesCsg(this.value)" onfocus="abrirDropdownClientesCsg()" style="height: 30px; font-size: 0.80rem; width:300px" />
                                        <div id="dropdownClientesCsg" style="display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 180px; overflow-y: auto; background: #ffffff; border: 1.5px solid #d4c1a7; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.15); z-index: 1050; margin-top: 2px;"></div>
                                    </div>
                                    <div id="badgeClienteElegidoCsg" style="display: none; background: #fdf5ea; border: 1px solid #dfcaa4; border-radius: 6px; padding: 2px 8px; align-items: center; justify-content: space-between; height: 30px;">
                                        <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.78rem; color: #211813;">
                                            <strong id="txtNombreClienteCsg">—</strong>
                                            <small id="txtDetalleClienteCsg" style="color: #7a6b5d; margin-left: 6px;"></small>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="limpiarClienteSeleccionadoCsg()" style="padding: 1px 6px; font-size: 0.68rem; height: 20px; flex-shrink: 0;">✕ Cambiar</button>
                                    </div>
                                </div>

                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Nombre del Evento <span style="color:red;">*</span></label>
                                    <input type="text" id="csgNombreEvento" class="form-control" placeholder="Ej: Boda Martínez - Gómez" required style="height: 30px; font-size: 0.80rem;" />
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Responsable / Contacto <span style="color:red;">*</span></label>
                                    <input type="text" id="csgResponsable" class="form-control" placeholder="Ej: Lic. Martínez (809-555-0192)" required style="height: 30px; font-size: 0.80rem;" />
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr 1.3fr; gap: 8px;">
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Fecha Estimada Retorno <span style="color:red;">*</span></label>
                                    <input type="date" id="csgFechaRetorno" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required style="height: 30px; font-size: 0.80rem;" />
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Almacén de Despacho <span style="color:red;">*</span></label>
                                    <select id="csgAlmacen" class="form-control" onchange="limpiarCarritoDespacho(); cargarCatalogoAlmacenCsg();" style="height: 30px; font-size: 0.80rem;">
                                        <?php foreach ($almacenesList as $alm): ?>
                                                <option value="<?= (int) $alm['id'] ?>"><?= htmlspecialchars($alm['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label" style="font-size: 0.70rem; font-weight: 700; margin-bottom: 2px;">Fianza / Garantía (DOP)</label>
                                    <div style="display: flex; gap: 6px;">
                                        <input type="number" step="0.01" min="0" id="csgMontoFianza" class="form-control" value="0.00" style="font-family: var(--font-mono); font-weight: 700; width: 100px; height: 30px; font-size: 0.80rem;" oninput="actualizarSubpanelFianza()" />
                                        <select id="csgMetodoFianza" class="form-control" style="flex: 1; height: 30px; font-size: 0.80rem;" onchange="actualizarSubpanelFianza()">
                                            <?php foreach ($metodosPagoList as $mp): ?>
                                                    <option value="<?= (int) $mp['id'] ?>" data-codigo="<?= htmlspecialchars(strtoupper($mp['codigo'])) ?>"><?= htmlspecialchars($mp['descripcion']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Subpanel dinámico de datos de Fianza (solo si monto > 0) -->
                            <div id="csgSubpanelFianza" style="display: none; background: #fff; border: 1px solid #dfcaa4; border-radius: 6px; padding: 7px 10px; font-size: 0.78rem;"></div>
                        </div>

                        <!-- Autorización Supervisor -->
                        <div style="background: #fff8eb; border: 1.5px solid #f0d8a8; border-radius: 6px; padding: 8px 10px; display: flex; flex-direction: column; gap: 5px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #fae8c8; padding-bottom: 3px;">
                                <span style="font-size: 0.70rem; font-weight: 700; color: #7a5216; text-transform: uppercase;">🛡️ Autorización Supervisor</span>
                                <small style="color: #9a6828; font-size: 0.65rem; font-weight: 600;">Obligatorio</small>
                            </div>
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label" style="font-size: 0.68rem; font-weight: 700; margin-bottom: 1px;">Supervisor Autorizado <span style="color:red;">*</span></label>
                                <select id="csgSupervisor" class="form-control" required style="height: 28px; font-size: 0.78rem; padding: 2px 6px;">
                                    <option value="">-- Seleccionar Supervisor --</option>
                                    <?php foreach ($supervisoresList as $sup): ?>
                                            <option value="<?= htmlspecialchars($sup['usuario_id']) ?>"><?= htmlspecialchars($sup['nombre_completo']) ?> (<?= htmlspecialchars($sup['username']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label" style="font-size: 0.68rem; font-weight: 700; margin-bottom: 1px;">PIN Numérico <span style="color:red;">*</span></label>
                                <input type="password" id="csgPin" class="form-control" maxlength="6" placeholder="PIN de 4 a 6 dígitos" required autocomplete="off" style="height: 28px; font-size: 0.78rem; padding: 2px 6px;" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6);" />
                            </div>
                        </div>
                    </div>

                    <!-- CATÁLOGO Y CARRITO -->
                    <div class="grid-split-consignacion" style="display: grid; grid-template-columns: 1fr 1.25fr; gap: 10px; align-items: stretch;">
                        <div style="background: #faf8f5; border: 1.5px solid #dfcaa4; border-radius: 8px; padding: 8px 10px; display: flex; flex-direction: column; height: 270px; gap: 6px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ebdcc5; padding-bottom: 2px;">
                                <span style="font-size: 0.74rem; font-weight: 700; color: var(--accent-gold); text-transform: uppercase;">Catálogo de Artículos</span>
                                <span style="font-size: 0.70rem; color: var(--text-muted); font-weight: 600;">Con stock disponible</span>
                            </div>
                            <input type="text" id="inputBuscarCatalogoCsg" class="form-control" placeholder="🔍 Buscar licor, whisky, vino o SKU..." oninput="alFiltrarCatalogoCsg(this.value)" style="height: 30px; font-size: 0.80rem; flex-shrink: 0;" />
                            <div id="panelResultadosCatalogoCsg" style="flex: 1; min-height: 0; overflow-y: auto; background: #ffffff; border: 1px solid #dfcaa4; border-radius: 6px; padding: 4px; display: flex; flex-direction: column; gap: 3px;"></div>
                        </div>

                        <div style="background: #faf8f5; border: 1.5px solid #dfcaa4; border-radius: 8px; padding: 8px 10px; display: flex; flex-direction: column; height: 270px; gap: 6px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ebdcc5; padding-bottom: 2px;">
                                <span style="font-size: 0.74rem; font-weight: 700; color: var(--accent-gold); text-transform: uppercase;">Mercancía en la Consignación</span>
                                <small style="color: var(--text-muted); font-size: 0.70rem; font-weight: 600;">Precio base (pre-impuestos)</small>
                            </div>
                            <div style="flex: 1; min-height: 0; overflow-y: auto; background: #ffffff; border: 1px solid #dfcaa4; border-radius: 6px;">
                                <table class="data-table" style="font-size: 0.78rem; margin: 0; width: 100%; border-collapse: collapse;">
                                    <thead style="position: sticky; top: 0; z-index: 5;">
                                        <tr style="background: #f6efe4; white-space: nowrap;">
                                            <th style="width: 34%; padding: 5px 6px; font-size: 0.68rem;">Producto</th>
                                            <th style="width: 20%; padding: 5px 4px; font-size: 0.68rem;">Lote (FEFO)</th>
                                            <th style="width: 12%; text-align: center; padding: 5px 4px; font-size: 0.68rem;">Cant. *</th>
                                            <th style="width: 16%; text-align: right; padding: 5px 4px; font-size: 0.68rem;">Precio Base *</th>
                                            <th style="width: 14%; text-align: right; padding: 5px 6px; font-size: 0.68rem;">Subtotal</th>
                                            <th style="width: 4%; text-align: center; padding: 5px 2px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="csgTbodyCarrito">
                                        <tr><td colspan="6" style="text-align: center; padding: 24px; color: var(--text-muted); font-size: 0.80rem;">Haz clic en <strong>+ Agregar</strong> en el catálogo de la izquierda.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div style="background: #ffffff; border: 1px solid #dfcaa4; border-radius: 6px; padding: 5px 10px; display: flex; justify-content: space-between; align-items: center; font-weight: 700; flex-shrink: 0;">
                                <span style="font-size: 0.76rem; color: #5a3e24;">Total Botellas: <strong id="csgTxtTotalBotellas" style="color: #1e6091; font-family: var(--font-mono);">0</strong> u.</span>
                                <span style="font-size: 0.84rem; font-family: var(--font-mono); color: var(--accent-gold);">Subtotal Base: <strong id="csgTxtTotalValor">RD$ 0.00</strong></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer" style="padding: 10px 16px;">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalNuevaConsignacion')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarNuevaConsignacion" style="padding: 7px 20px;">📦 Despachar y Reservar Stock</button>
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
                    <div id="retCabeceraInfo" style="background: #fdfaf6; border: 1px solid var(--border-light); border-radius: 8px; padding: 12px 16px; margin-bottom: 14px;"></div>

                    <p style="font-size: 0.84rem; color: var(--text-muted); margin-bottom: 10px;">
                        Digita las botellas que regresaron <strong>selladas e intactas</strong> al almacén. El consumo base se calcula automáticamente:
                    </p>

                    <div class="table-wrapper" style="max-height: 280px; overflow-y: auto; margin-bottom: 14px;">
                        <table class="data-table" style="font-size: 0.84rem;">
                            <thead>
                                <tr>
                                    <th>Producto Maestro</th><th>Lote</th><th class="text-center">Despachadas</th>
                                    <th class="text-center" style="width: 130px;">Devueltas</th><th class="text-center" style="width: 130px;">Consumidas</th>
                                    <th class="text-right">Precio Pactado</th><th class="text-right">Subtotal Base</th>
                                </tr>
                            </thead>
                            <tbody id="retTbodyArticulos"></tbody>
                        </table>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; background: #faf7f2; border: 1px solid var(--border-light); border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <span>Total Devueltas: <strong id="retTxtTotalDevueltas" style="color: #2e7d52;">0</strong></span>
                            <span style="margin-left: 14px;">Total Consumidas: <strong id="retTxtTotalConsumidas" style="color: #b53d32;">0</strong></span>
                        </div>
                        <div>
                            <span>Subtotal Consumo (antes de imp.):</span>
                            <strong id="retTxtTotalConsumoMonto" style="font-size: 1.12rem; font-family: var(--font-mono); color: var(--text-dark); margin-left: 6px;">RD$ 0.00</strong>
                        </div>
                    </div>

                    <div style="background: #fff8eb; border: 1px solid #f0d8a8; border-radius: 8px; padding: 12px 16px;">
                        <h4 style="font-size: 0.84rem; font-weight: 700; color: #7a5216; margin-bottom: 8px;">🛡️ Auditoría del Conteo Físico por Supervisor</h4>
                        <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 12px;">
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label">Supervisor <span style="color:red;">*</span></label>
                                <select id="retSupervisor" class="form-control" required>
                                    <option value="">-- Seleccionar Supervisor --</option>
                                    <?php foreach ($supervisoresList as $sup): ?>
                                            <option value="<?= htmlspecialchars($sup['usuario_id']) ?>"><?= htmlspecialchars($sup['nombre_completo']) ?> (<?= htmlspecialchars($sup['username']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label">PIN Numérico <span style="color:red;">*</span></label>
                                <input type="password" id="retPin" class="form-control" maxlength="6" placeholder="PIN de 4 a 6 dígitos" required autocomplete="off" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6);" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalRetornoFisico')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarRetorno" style="background: #1e6091 !important; border-color: #1e6091 !important;">💾 Asentar Conteo y Actualizar Consumo</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 3: LIQUIDACIÓN FINANCIERA, FACTURA FISCAL Y SPLIT (FASE 3)          -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalLiquidacionFinanciera">
        <div class="modal-dialog modal-lg">
            <div class="modal-header">
                <h3><span>💰</span> Liquidación Financiera, Facturación Fiscal y Split</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalLiquidacionFinanciera')">&times;</button>
            </div>
            <form id="formLiquidacionFinanciera" onsubmit="guardarLiquidacionFinanciera(event)">
                <input type="hidden" id="liqConsignacionId" />
                <div class="modal-body">
                    <!-- Resumen de botellas y estado del conteo -->
                    <div id="liqBannerConteo" style="background: #fdfaf6; border: 1px solid var(--border-light); border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; font-size: 0.82rem;"></div>

                    <!-- RESUMEN FISCAL Y FINANCIERO -->
                    <div class="csg-liquidacion-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px;">
                        <div class="csg-liq-box">
                            <small>Subtotal Consumo</small>
                            <strong id="liqTxtSubtotal" style="color: #251c16;">RD$ 0.00</strong>
                        </div>
                        <div class="csg-liq-box">
                            <small>Impuestos (ITBIS / ISC)</small>
                            <strong id="liqTxtImpuestos" style="color: #a66e2e;">RD$ 0.00</strong>
                            <div id="liqPillsImpuestos" style="font-size: 0.68rem; color: #7a6b5d; margin-top: 2px;"></div>
                        </div>
                        <div class="csg-liq-box">
                            <small>Total Factura vs Fianza</small>
                            <strong id="liqTxtTotalConsumido" style="color: #b53d32;">RD$ 0.00</strong>
                            <div style="font-size: 0.72rem; color: #2e7d52; font-weight: 700;">Fianza: <span id="liqTxtMontoFianza">RD$ 0.00</span></div>
                        </div>
                        <div class="csg-liq-box" id="liqBoxBalance">
                            <small id="liqLblBalance">Diferencia a Cobrar</small>
                            <strong id="liqTxtBalanceDiferencia" style="color: var(--text-dark);">RD$ 0.00</strong>
                        </div>
                    </div>

                    <!-- OPCIÓN FISCAL Y ALMACÉN -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label">Comprobante Fiscal (DGII) <span style="color:red;">*</span></label>
                            <select id="liqTipoNcf" class="form-control" required>
                                <option value="B02">B02 • Consumo Final</option>
                                <option value="B01">B01 • Crédito Fiscal</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label">Almacén de Origen (Descarga / Liberación)</label>
                            <select id="liqAlmacen" class="form-control">
                                <?php foreach ($almacenesList as $alm): ?>
                                        <option value="<?= (int) $alm['id'] ?>"><?= htmlspecialchars($alm['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- SECCIÓN SPLIT: COBRO DE DIFERENCIA (SI TOTAL FACTURA > FIANZA) -->
                    <div id="liqSeccionSplitDiferencia" style="display: none; background: #faf7f2; border: 1px solid var(--border-light); border-radius: 8px; padding: 12px 16px; margin-bottom: 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                            <h4 style="font-size: 0.86rem; font-weight: 700; color: var(--text-dark); margin: 0;">
                                💳 Split de Cobro para Diferencia Restante (<span id="liqTxtMontoDiferenciaSpan">RD$ 0.00</span>)
                            </h4>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="agregarFilaSplitLiq()">+ Agregar Instrumento</button>
                        </div>
                        <div id="liqContenedorSplit"></div>
                        <div style="background: #fff; border: 1px solid #eae3d5; border-radius: 6px; padding: 8px 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 8px;">
                            <div>
                                <span style="font-size: 0.80rem; color: var(--text-muted);">Total Neto en Instrumentos:</span>
                                <strong id="liqLblTotalSplit" style="font-family: var(--font-mono); margin-left: 6px;">RD$ 0.00</strong>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <button type="button" class="btn btn-sm btn-secondary" onclick="autoCuadrarSplitLiq()" style="font-size: 0.74rem; padding: 3px 8px;">⚖️ Ajustar</button>
                                <span id="liqLblEstadoCuadre" style="font-size: 0.82rem; font-weight: 700;"></span>
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN REEMBOLSO (SI TOTAL FACTURA < FIANZA) -->
                    <div id="liqSeccionReembolso" style="display: none; background: #eef8f2; border: 1px solid #c2e5d1; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px;">
                        <h4 style="font-size: 0.86rem; font-weight: 700; color: #2e7d52; margin-bottom: 6px;">
                            💵 Reembolso de Excedente de Fianza: <strong id="liqTxtReembolsoMonto" style="font-family: var(--font-mono);">RD$ 0.00</strong>
                        </h4>
                        <div style="display: grid; grid-template-columns: 1fr 1.2fr 1fr; gap: 8px; align-items: end;">
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label" style="font-size: 0.74rem;">Vía de Reembolso *</label>
                                <select id="liqReembolsoMetodo" class="form-control" onchange="alCambiarReembolsoMetodo()" style="height: 34px; font-size: 0.82rem;">
                                    <option value="EFECTIVO">Efectivo (Egreso Caja POS)</option>
                                    <option value="TRANSFERENCIA">Transferencia Bancaria (DOP)</option>
                                </select>
                            </div>
                            <div class="form-group" id="liqReemCuentaWrap" style="margin: 0; display: none;">
                                <label class="form-label" style="font-size: 0.74rem;">Cuenta Bancaria Salida (DOP) *</label>
                                <select id="liqReembolsoCuenta" class="form-control" style="height: 34px; font-size: 0.82rem;">
                                    <?php foreach ($cuentasBancariasDop as $b): ?>
                                            <option value="<?= (int) $b['id'] ?>"><?= htmlspecialchars($b['nombre_banco'] . ' (' . $b['numero_cuenta'] . ')') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" id="liqReemRefWrap" style="margin: 0; display: none;">
                                <label class="form-label" style="font-size: 0.74rem;">No. Referencia Transferencia *</label>
                                <input type="text" id="liqReembolsoRef" class="form-control" placeholder="TRF-REEM-001" style="height: 34px; font-size: 0.82rem; font-family: var(--font-mono);" />
                            </div>
                        </div>
                    </div>

                    <!-- AUTORIZACIÓN PIN -->
                    <div style="background: #fff8eb; border: 1px solid #f0d8a8; border-radius: 8px; padding: 12px 16px;">
                        <h4 style="font-size: 0.84rem; font-weight: 700; color: #7a5216; margin-bottom: 8px;">🛡️ Autorización de Supervisor para Liquidación y Emisión Fiscal</h4>
                        <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 12px;">
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label">Supervisor <span style="color:red;">*</span></label>
                                <select id="liqSupervisor" class="form-control" required>
                                    <option value="">-- Seleccionar Supervisor --</option>
                                    <?php foreach ($supervisoresList as $sup): ?>
                                            <option value="<?= htmlspecialchars($sup['usuario_id']) ?>"><?= htmlspecialchars($sup['nombre_completo']) ?> (<?= htmlspecialchars($sup['username']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label">PIN Numérico <span style="color:red;">*</span></label>
                                <input type="password" id="liqPin" class="form-control" maxlength="6" placeholder="PIN de 4 a 6 dígitos" required autocomplete="off" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6);" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalLiquidacionFinanciera')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnProcesarLiquidacion" style="background: #2e7d52 !important; border-color: #2e7d52 !important;">
                        🧾 Emitir Factura Fiscal y Cerrar Consignación
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 4: EXPEDIENTE / DETALLE -->
    <div class="modal-overlay" id="modalDetalleConsignacion">
        <div class="modal-dialog modal-xl">
            <div class="modal-header">
                <h3><span>🔍</span> Expediente de Consignación</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalDetalleConsignacion')">&times;</button>
            </div>
            <div class="modal-body" id="bodyDetalleConsignacion"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalDetalleConsignacion')">Cerrar</button>
            </div>
        </div>
    </div>

    <script>
        const CLIENTES_LISTA     = <?= json_encode($clientesList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        const CATALOGO_METODOS   = <?= json_encode($metodosPagoList, JSON_UNESCAPED_UNICODE) ?>;
        const CATALOGO_BANCOS    = <?= json_encode($cuentasBancariasDop, JSON_UNESCAPED_UNICODE) ?>;
        const SESION_CAJA_ACTIVA = <?= json_encode($sesionCajaActiva, JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="consignaciones.js?v=<?= time() ?>"></script>
</body>

</html>