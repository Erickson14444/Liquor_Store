<?php
/**
 * ============================================================================
 * Vista Principal: Auditoría Forense
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Seguridad y Control/AuditoriaForense/auditoria.php
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/funciones.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

requerirSesionActiva(false, '../../Login y Menu/login.php');

if (!usuarioEsAdministrador() && !usuarioTienePermiso('VER_AUDITORIA_FORENSE')) {
    header("Location: ../../Login y Menu/menu.php?error=acceso_denegado");
    exit;
}

$kpiTotalRegistros = 0;
$kpiHoyRegistros   = 0;
$kpiOverridesPin   = 0;
$kpiTablasDist     = 0;

$tablasDisponibles = [];
$accionesDisponibles = [];
$usuariosList = [];
$listaAuditoria = [];

if ($pdo) {
    // 1. KPIs Forenses
    try {
        $stmtKpi = $pdo->query("
            SELECT 
                COUNT(*) AS total_reg,
                COUNT(CASE WHEN fecha_hora::date = CURRENT_DATE THEN 1 END) AS hoy_reg,
                COUNT(CASE WHEN supervisor_id IS NOT NULL THEN 1 END) AS overrides_pin,
                COUNT(DISTINCT tabla_afectada) AS tablas_dist
            FROM seguridad.auditoria
        ");
        $kRow = $stmtKpi ? $stmtKpi->fetch(PDO::FETCH_ASSOC) : null;
        if ($kRow) {
            $kpiTotalRegistros = (int)$kRow['total_reg'];
            $kpiHoyRegistros   = (int)$kRow['hoy_reg'];
            $kpiOverridesPin   = (int)$kRow['overrides_pin'];
            $kpiTablasDist     = (int)$kRow['tablas_dist'];
        }
    } catch (Throwable $e) {}

    // 2. Filtros disponibles
    try {
        $tablasDisponibles = $pdo->query("
            SELECT DISTINCT tabla_afectada 
            FROM seguridad.auditoria 
            WHERE tabla_afectada IS NOT NULL AND tabla_afectada <> '' 
            ORDER BY tabla_afectada ASC
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $accionesDisponibles = $pdo->query("
            SELECT DISTINCT accion 
            FROM seguridad.auditoria 
            WHERE accion IS NOT NULL AND accion <> '' 
            ORDER BY accion ASC
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $usuariosList = $pdo->query("
            SELECT u.usuario_id, u.username, p.nombre_razon_social
            FROM seguridad.usuarios u
            LEFT JOIN identidad.personas p ON p.persona_id = u.persona_id
            WHERE u.estado = 'ACTIVO'
            ORDER BY u.username ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    // 3. Primeros 100 registros para render inicial
    try {
        $stmtInit = $pdo->query("
            SELECT a.auditoria_id,
                   a.usuario_id,
                   a.supervisor_id,
                   a.accion,
                   a.tabla_afectada,
                   a.datos_anteriores,
                   a.datos_nuevos,
                   TO_CHAR(a.fecha_hora, 'DD/MM/YYYY HH12:MI:SS AM') AS fecha_fmt,
                   u.username AS usuario_username,
                   COALESCE(pu.nombre_razon_social, u.username) AS usuario_nombre,
                   sup.username AS supervisor_username,
                   COALESCE(psup.nombre_razon_social, sup.username) AS supervisor_nombre,
                   (a.datos_anteriores IS NOT NULL) AS tiene_anteriores,
                   (a.supervisor_id IS NOT NULL) AS es_override
            FROM seguridad.auditoria a
            LEFT JOIN seguridad.usuarios u ON u.usuario_id = a.usuario_id
            LEFT JOIN identidad.personas pu ON pu.persona_id = u.persona_id
            LEFT JOIN seguridad.usuarios sup ON sup.usuario_id = a.supervisor_id
            LEFT JOIN identidad.personas psup ON psup.persona_id = sup.persona_id
            ORDER BY a.fecha_hora DESC, a.auditoria_id DESC
            LIMIT 100
        ");
        $listaAuditoria = $stmtInit ? $stmtInit->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Auditoría Forense</title>
    <link rel="stylesheet" href="../../Ventas y Facturacion/styles.css?v=33" />
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
                <span class="brand-subtitle">Auditoría Forense</span>
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
                    <h1 class="page-title">🛡️ Auditoría Forense</h1>
                    <p class="page-subtitle">
                        Caja negra inmutable: Trazabilidad total de transacciones, diffs JSONB (datos anteriores vs nuevos) y autorizaciones con PIN.
                    </p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button type="button" class="btn btn-secondary" onclick="recargarAuditoria()" style="background:#fff; border: 1px solid var(--border-light); font-weight: 600;">
                        🔄 Actualizar Registros
                    </button>
                </div>
            </div>

            <!-- TARJETAS DE INDICADORES (KPIS: EXACTAMENTE 4 TARJETAS) -->
            <div class="aud-kpi-container">
                <div class="aud-kpi-card">
                    <div class="aud-kpi-icon dark">👁️</div>
                    <div class="aud-kpi-data">
                        <h4>Total Eventos Forenses</h4>
                        <div class="aud-kpi-value"><?= number_format($kpiTotalRegistros) ?></div>
                        <div class="aud-kpi-sub">Transacciones registradas</div>
                    </div>
                </div>

                <div class="aud-kpi-card">
                    <div class="aud-kpi-icon blue">📅</div>
                    <div class="aud-kpi-data">
                        <h4>Eventos del Día</h4>
                        <div class="aud-kpi-value" style="color: #1e6091;"><?= number_format($kpiHoyRegistros) ?></div>
                        <div class="aud-kpi-sub">Actividad hoy en vivo</div>
                    </div>
                </div>

                <div class="aud-kpi-card">
                    <div class="aud-kpi-icon orange">🔑</div>
                    <div class="aud-kpi-data">
                        <h4>Overrides con PIN</h4>
                        <div class="aud-kpi-value" style="color: #d35400;"><?= number_format($kpiOverridesPin) ?></div>
                        <div class="aud-kpi-sub">Autorizaciones de supervisor</div>
                    </div>
                </div>

                <div class="aud-kpi-card">
                    <div class="aud-kpi-icon purple">🗄️</div>
                    <div class="aud-kpi-data">
                        <h4>Módulos y Tablas</h4>
                        <div class="aud-kpi-value" style="color: #7d3c98;"><?= $kpiTablasDist ?> tablas</div>
                        <div class="aud-kpi-sub">Ventas, compras, stock, caja</div>
                    </div>
                </div>
            </div>

            <!-- FILTROS AVANZADOS -->
            <div class="aud-filter-bar">
                <div class="aud-filter-group" style="flex: 2; min-width: 220px;">
                    <label>Búsqueda Profunda (JSON, Acción, Usuario)</label>
                    <input type="text" id="filtroBusqueda" class="form-control" placeholder="Buscar NCF, ID, cédula, monto, producto o texto..." oninput="alCambiarFiltroAuditoria()" />
                </div>

                <div class="aud-filter-group" style="flex: 1.2; min-width: 170px;">
                    <label>Tabla Afectada</label>
                    <select id="filtroTabla" class="form-control" onchange="alCambiarFiltroAuditoria()">
                        <option value="">-- Todas las Tablas --</option>
                        <?php foreach ($tablasDisponibles as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="aud-filter-group" style="flex: 1.2; min-width: 170px;">
                    <label>Tipo de Acción</label>
                    <select id="filtroAccion" class="form-control" onchange="alCambiarFiltroAuditoria()">
                        <option value="">-- Todas las Acciones --</option>
                        <?php foreach ($accionesDisponibles as $acc): ?>
                            <option value="<?= htmlspecialchars($acc) ?>"><?= htmlspecialchars($acc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="aud-filter-group" style="flex: 1.2; min-width: 160px;">
                    <label>Usuario Operador</label>
                    <select id="filtroUsuario" class="form-control" onchange="alCambiarFiltroAuditoria()">
                        <option value="">-- Todos los Usuarios --</option>
                        <?php foreach ($usuariosList as $u): ?>
                            <option value="<?= htmlspecialchars($u['usuario_id']) ?>">
                                <?= htmlspecialchars($u['username']) ?> (<?= htmlspecialchars($u['nombre_razon_social'] ?: 'S/N') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="aud-filter-group" style="align-items: center; justify-content: flex-end; padding-bottom: 6px;">
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; text-transform: none; font-size: 0.8rem; font-weight: 700; color: #8c4c15;">
                        <input type="checkbox" id="filtroSoloOverrides" onchange="alCambiarFiltroAuditoria()" />
                        Solo Overrides con PIN
                    </label>
                </div>

                <div>
                    <button type="button" class="btn btn-secondary" onclick="limpiarFiltrosAuditoria()" style="padding: 8px 14px;">
                        Limpiar
                    </button>
                </div>
            </div>

            <!-- TABLA FORENSE -->
            <div class="table-card">
                <div class="table-card-top">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Eventos y Modificaciones Sensibles del Sistema</h2>
                        <span class="badge-count" id="badgeTotalAud"><?= count($listaAuditoria) ?> eventos</span>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                        Indexado en PostgreSQL mediante GIN (idx_auditoria_gin_datos_nuevos)
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="data-table" id="tablaAuditoria">
                        <thead>
                            <tr>
                                <th style="width: 80px;">ID</th>
                                <th>Fecha y Hora</th>
                                <th>Tabla Afectada</th>
                                <th>Acción Realizada</th>
                                <th>Usuario Operador</th>
                                <th>Supervisor (PIN)</th>
                                <th>Resumen de Datos</th>
                                <th class="text-center" style="width: 140px;">Inspección</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyAuditoria">
                            <?php if (empty($listaAuditoria)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 36px; color: var(--text-muted);">
                                        No hay eventos de auditoría registrados en la base de datos.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($listaAuditoria as $row): 
                                    $nuevosArr = $row['datos_nuevos'] ? json_decode($row['datos_nuevos'], true) : [];
                                    $resumenTxt = '';
                                    if (is_array($nuevosArr)) {
                                        $partes = [];
                                        foreach ($nuevosArr as $k => $v) {
                                            if (is_scalar($v)) {
                                                $partes[] = "{$k}: {$v}";
                                            }
                                            if (count($partes) >= 3) break;
                                        }
                                        $resumenTxt = implode(' • ', $partes);
                                    }
                                    $claseAccion = 'update';
                                    $accUp = strtoupper($row['accion']);
                                    if (strpos($accUp, 'INSERT') !== false || strpos($accUp, 'CREAR') !== false || strpos($accUp, 'APERTURA') !== false) {
                                        $claseAccion = 'insert';
                                    } elseif (strpos($accUp, 'DELETE') !== false || strpos($accUp, 'ANULAC') !== false || strpos($accUp, 'CANCEL') !== false) {
                                        $claseAccion = 'delete';
                                    } elseif (strpos($accUp, 'B03') !== false || strpos($accUp, 'FISCAL') !== false || strpos($accUp, 'B02') !== false || strpos($accUp, 'B01') !== false) {
                                        $claseAccion = 'fiscal';
                                    } elseif (!empty($row['supervisor_id'])) {
                                        $claseAccion = 'override';
                                    }
                                ?>
                                    <tr class="fila-aud" data-id="<?= $row['auditoria_id'] ?>">
                                        <td style="font-family: var(--font-mono); font-weight: 700; color: #888;">#<?= $row['auditoria_id'] ?></td>
                                        <td>
                                            <span style="font-size: 0.82rem; font-weight: 600;"><?= htmlspecialchars($row['fecha_fmt']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge-tabla"><?= htmlspecialchars($row['tabla_afectada']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge-accion <?= $claseAccion ?>"><?= htmlspecialchars($row['accion']) ?></span>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($row['usuario_username']) ?></strong>
                                            <small style="display: block; font-size: 0.72rem; color: var(--text-muted);"><?= htmlspecialchars($row['usuario_nombre']) ?></small>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['supervisor_id'])): ?>
                                                <span class="badge-override-pin" title="Operación autorizada con PIN de supervisor">
                                                    🔒 <?= htmlspecialchars($row['supervisor_username'] ?: 'Supervisor') ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #bbb; font-size: 0.76rem;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.78rem; color: #555; max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($resumenTxt) ?>">
                                                <?= htmlspecialchars($resumenTxt ?: '(Sin detalles adicionales)') ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline" onclick="inspeccionarForense(<?= $row['auditoria_id'] ?>)" style="padding: 3px 8px; font-weight: 600;">
                                                🔍 Inspeccionar
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
    <!-- MODAL: INSPECTOR FORENSE DE JSONB Y DIFF                                  -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalInspectorForense">
        <div class="modal-dialog modal-lg">
            <div class="modal-header">
                <h3><span>🔍</span> Inspector Forense • Registro #<span id="txtModalAudId">-</span></h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalInspectorForense')">&times;</button>
            </div>
            <div class="modal-body" id="bodyInspectorForense">
                <!-- Inyectado dinámicamente -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalInspectorForense')">Cerrar</button>
            </div>
        </div>
    </div>

    <script src="auditoria.js?v=1"></script>
</body>

</html>
