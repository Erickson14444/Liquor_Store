<?php
/**
 * ============================================================================
 * Vista Principal: Auditoría Forense (Filtros Completos + Navegación Fluida)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Seguridad y Control/AuditoriaForense/auditoria.php
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
if (file_exists(__DIR__ . '/../../config/funciones.php')) {
    require_once __DIR__ . '/../../config/funciones.php';
}
require_once __DIR__ . '/../../config/auditoria_helper.php';

requerirSesionActiva(false, '../../Login y Menu/login.php');

if (!usuarioEsAdministrador() && !usuarioTienePermiso('VER_AUDITORIA_FORENSE')) {
    header("Location: ../../Login y Menu/menu.php?error=acceso_denegado");
    exit;
}

function formatearNombreTablaAmigable(string $tabla): string
{
    $t = strpos($tabla, '.') !== false ? substr($tabla, strrpos($tabla, '.') + 1) : $tabla;
    $map = [
        'comprobantes_egreso_cxp' => 'Comprobantes CxP',
        'comprobante_egreso_retenciones' => 'Retenciones DGII',
        'cuentas_por_pagar' => 'Cuentas por Pagar (CxP)',
        'ajustes_cxp' => 'Ajustes CxP',
        'movimientos_inventario' => 'Movimientos Stock',
        'traslados_internos' => 'Traslados Internos',
        'consignaciones' => 'Consignaciones',
        'consignaciones_detalle' => 'Retorno Consignaciones',
        'cupones_descuento' => 'Cupones Descuento',
        'promociones_campanas' => 'Promociones y Campañas',
        'facturas' => 'Facturación / Ventas',
        'facturas_detalles' => 'Detalle Factura',
        'cotizaciones' => 'Cotizaciones',
        'devoluciones' => 'Devoluciones (B04)',
        'ordenes_compra' => 'Órdenes de Compra',
        'recepciones' => 'Recepciones Mercancía',
        'recepciones_compra' => 'Recepciones Mercancía',
        'sesiones_caja' => 'Sesiones de Caja',
        'caja_sesiones' => 'Sesiones de Caja',
        'movimiento_caja' => 'Movimientos de Caja',
        'caja_movimientos' => 'Movimientos de Caja',
        'movimientos_bancarios' => 'Movimientos Bancarios',
        'depositos_bancarios' => 'Depósitos Bancarios',
        'cuentas_por_cobrar' => 'Cuentas por Cobrar (CxC)',
        'recibos_ingreso_cxc' => 'Recibos de Ingreso CxC',
        'ajustes_cxc' => 'Ajustes CxC',
        'notas_debito' => 'Notas de Débito (B03)',
        'usuarios' => 'Usuarios',
        'roles' => 'Roles y Permisos',
        'productos_maestro' => 'Catálogo Productos',
        'articulos' => 'Catálogo Artículos',
        'clientes' => 'Clientes',
        'proveedores' => 'Proveedores',
        'personas' => 'Clientes y Proveedores',
    ];
    return $map[strtolower($t)] ?? ucwords(str_replace('_', ' ', $t));
}

function formatearAccionAmigable(string $accion): string
{
    $map = [
        'LIQUIDAR_EGRESO_CXP' => 'Liquidación Egreso CxP',
        'REGISTRAR_GASTO_SERVICIO_CXP' => 'Gasto / Servicio CxP',
        'REGISTRAR_AJUSTE_CXP' => 'Ajuste / Anulación CxP',
        'LIQUIDACION_RETENCIONES_DGII' => 'Liquidación Retenciones DGII',
        'REGISTRAR_RECIBO_CXC' => 'Recibo de Ingreso CxC',
        'EMITIR_NOTA_DEBITO_B03' => 'Nota de Débito (B03)',
        'LIQUIDAR_CONSIGNACION' => 'Liquidación Consignación',
        'CREAR_CONSIGNACION' => 'Creación Consignación',
        'REGISTRAR_RETORNO_CONSIGNACION' => 'Retorno Físico Consignación',
        'POS_EMITIR_FACTURA' => 'Emisión de Factura POS',
        'POS_ANULAR_FACTURA' => 'Anulación de Factura POS',
        'POS_CREAR_CLIENTE_RAPIDO' => 'Alta Rápida de Cliente',
        'INVENTARIO_TRASLADO_EMISION' => 'Emisión Traslado Stock',
        'INVENTARIO_AJUSTE_ENTRADA' => 'Entrada por Ajuste Stock',
        'INVENTARIO_AJUSTE_SALIDA' => 'Salida por Ajuste Stock',
        'APERTURA_CAJA' => 'Apertura de Caja',
        'CIERRE_CAJA' => 'Cierre / Arqueo de Caja',
        'DEVOLUCION_VENTA' => 'Devolución de Venta (B04)',
        'RECEPCION_MERCANCIA' => 'Recepción de Mercancía',
        'OVERRIDE_PIN' => 'Autorización con PIN',
    ];
    $accUp = strtoupper(trim($accion));
    return $map[$accUp] ?? ucwords(str_replace('_', ' ', strtolower($accion)));
}

function generarResumenAmigable(?array $datos): string
{
    if (empty($datos) || !is_array($datos)) {
        return '(Sin datos adicionales)';
    }

    $nombresCampos = [
        'numero_comprobante' => 'Comprobante',
        'numero_recibo' => 'Recibo',
        'numero_factura' => 'Factura',
        'numero_consignacion' => 'Consignación',
        'ncf_nota_debito' => 'NCF B03',
        'ncf' => 'NCF',
        'total_pagado' => 'Total Pagado',
        'monto_total_recibido' => 'Total Cobrado',
        'monto_total_liquidado' => 'Total Liquidado',
        'total_retenciones' => 'Retenciones',
        'facturas_afectadas' => 'Facturas',
        'estado' => 'Estado',
        'estado_final' => 'Estado',
        'numero_traslado' => 'Nº Traslado',
        'almacen_origen' => 'Origen',
        'almacen_destino' => 'Destino',
        'tipo_movimiento' => 'Tipo',
        'justificacion' => 'Motivo',
        'concepto' => 'Concepto',
        'concepto_cargo' => 'Concepto',
        'monto' => 'Monto',
        'monto_total' => 'Total',
        'total_facturado' => 'Facturado',
        'cliente_nombre' => 'Cliente',
        'proveedor' => 'Proveedor',
        'banco' => 'Banco',
        'referencia_dgii' => 'Aut. DGII',
    ];

    $partes = [];
    foreach ($datos as $k => $v) {
        $kLower = strtolower($k);
        if ($kLower === 'id' || substr($kLower, -3) === '_id' || substr($kLower, -5) === '_uuid' || $kLower === 'uuid') {
            continue;
        }
        if (is_array($v)) {
            $partes[] = 'Ítems: ' . count($v);
            continue;
        }
        if (!is_scalar($v) || $v === null || trim((string) $v) === '') {
            continue;
        }

        $etiqueta = $nombresCampos[$kLower] ?? ucwords(str_replace('_', ' ', $kLower));
        $valStr = (string) $v;

        if (is_numeric($v) && preg_match('/(total|monto|pagado|retencion|saldo|precio|costo|fianza|reembolso)/i', $kLower)) {
            $valStr = 'RD$ ' . number_format((float) $v, 2);
        }

        $partes[] = "{$etiqueta}: {$valStr}";
        if (count($partes) >= 4)
            break;
    }

    return !empty($partes) ? implode(' • ', $partes) : '(Detalles registrados)';
}

$kpiTotalRegistros = 0;
$kpiHoyRegistros = 0;
$kpiOverridesPin = 0;
$kpiTablasDist = 0;
$limiteInicial = 50;

$tablasDisponibles = [];
$accionesDisponibles = [];
$usuariosList = [];
$supervisoresList = [];
$listaAuditoria = [];

if ($pdo) {
    try {
        $kRow = $pdo->query("
            SELECT
                COUNT(*) AS total_reg,
                COUNT(CASE WHEN fecha_hora::date = CURRENT_DATE THEN 1 END) AS hoy_reg,
                COUNT(CASE WHEN supervisor_id IS NOT NULL THEN 1 END) AS overrides_pin,
                COUNT(DISTINCT tabla_afectada) AS tablas_dist
            FROM seguridad.auditoria
        ")->fetch(PDO::FETCH_ASSOC);

        if ($kRow) {
            $kpiTotalRegistros = (int) $kRow['total_reg'];
            $kpiHoyRegistros = (int) $kRow['hoy_reg'];
            $kpiOverridesPin = (int) $kRow['overrides_pin'];
            $kpiTablasDist = (int) $kRow['tablas_dist'];
        }
    } catch (Throwable $e) {
    }

    try {
        $tablasRaw = $pdo->query("
            SELECT DISTINCT tabla_afectada
            FROM seguridad.auditoria
            WHERE tabla_afectada IS NOT NULL AND tabla_afectada <> ''
            ORDER BY tabla_afectada ASC
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

        foreach ($tablasRaw as $tr) {
            $nombreLimpio = strpos($tr, '.') !== false ? substr($tr, strrpos($tr, '.') + 1) : $tr;
            if ($nombreLimpio !== '' && !isset($tablasDisponibles[$nombreLimpio])) {
                $tablasDisponibles[$nombreLimpio] = $nombreLimpio;
            }
        }
        ksort($tablasDisponibles);

        $accionesDisponibles = $pdo->query("
            SELECT DISTINCT accion
            FROM seguridad.auditoria
            WHERE accion IS NOT NULL AND accion <> ''
            ORDER BY accion ASC
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $usuariosList = $pdo->query("
            SELECT u.usuario_id, u.username, COALESCE(p.nombre_razon_social, u.username) AS nombre_razon_social
            FROM seguridad.usuarios u
            LEFT JOIN identidad.personas p ON p.persona_id = u.persona_id
            ORDER BY u.username ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $supervisoresList = $pdo->query("
            SELECT DISTINCT u.usuario_id, u.username, COALESCE(p.nombre_razon_social, u.username) AS nombre_razon_social
            FROM seguridad.usuarios u
            LEFT JOIN identidad.personas p ON p.persona_id = u.persona_id
            WHERE u.usuario_id IN (SELECT DISTINCT supervisor_id FROM seguridad.auditoria WHERE supervisor_id IS NOT NULL)
               OR u.pin_hash IS NOT NULL
            ORDER BY u.username ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    try {
        $stmtInit = $pdo->prepare("
            SELECT a.auditoria_id,
                   a.usuario_id,
                   a.supervisor_id,
                   a.accion,
                   a.tabla_afectada,
                   a.datos_anteriores,
                   a.datos_nuevos,
                   TO_CHAR(a.fecha_hora, 'DD/MM/YYYY HH12:MI:SS AM') AS fecha_fmt,
                   COALESCE(u.username, 'sistema') AS usuario_username,
                   COALESCE(pu.nombre_razon_social, u.username, 'Sistema') AS usuario_nombre,
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
            LIMIT ?
        ");
        $stmtInit->execute([$limiteInicial]);
        $listaAuditoria = $stmtInit->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }
}

$totalPaginasInicial = max(1, (int) ceil($kpiTotalRegistros / $limiteInicial));
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Auditoría Forense</title>
    <link rel="stylesheet" href="../styles.css?v=<?= time() ?>" />
    <link rel="stylesheet" href="styles.css?v=<?= time() ?>" />
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
                <span class="brand-subtitle">Auditoría del Sistema</span>
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
        <?php include_once __DIR__ . '/../../sidebar.php'; ?>

        <main class="main-content">
            <!-- CABECERA -->
            <div class="page-header aud-header-compact">
                <div>
                    <h1 class="page-title">🛡️ Auditoría del Sistema</h1>
                    <p class="page-subtitle">Trazabilidad forense de transacciones, cambios Antes/Después y
                        autorizaciones con PIN de supervisor.</p>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="exportarAuditoriaCsv()"
                        style="background:#fff; border: 1px solid #d4c1a7; font-weight: 600; padding: 5px 11px; font-size: 0.78rem;">
                        📥 Exportar CSV
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm"
                        onclick="recargarAuditoria(AUD_PAGINA_ACTUAL)"
                        style="background:#fff; border: 1px solid var(--border-light); font-weight: 600; padding: 5px 11px; font-size: 0.78rem;">
                        🔄 Actualizar
                    </button>
                </div>
            </div>

            <!-- KPIs INTERACTIVOS (CLIC PARA FILTRAR) -->
            <div class="aud-kpi-container">
                <div class="aud-kpi-card aud-kpi-clickable" onclick="limpiarFiltrosAuditoria()"
                    title="Ver todos los registros">
                    <div class="aud-kpi-icon dark">👁️</div>
                    <div class="aud-kpi-data">
                        <h4>Total Registros</h4>
                        <div class="aud-kpi-value"><?= number_format($kpiTotalRegistros) ?></div>
                        <div class="aud-kpi-sub">Clic para ver todo</div>
                    </div>
                </div>

                <div class="aud-kpi-card aud-kpi-clickable" onclick="aplicarRangoRapidoAud('hoy')"
                    title="Filtrar actividad de hoy">
                    <div class="aud-kpi-icon blue">📅</div>
                    <div class="aud-kpi-data">
                        <h4>Operaciones de Hoy</h4>
                        <div class="aud-kpi-value" style="color: #1e6091;"><?= number_format($kpiHoyRegistros) ?></div>
                        <div class="aud-kpi-sub">Clic para filtrar hoy</div>
                    </div>
                </div>

                <div class="aud-kpi-card aud-kpi-clickable" onclick="filtrarSoloOverridesRapido()"
                    title="Filtrar operaciones con PIN">
                    <div class="aud-kpi-icon orange">🔑</div>
                    <div class="aud-kpi-data">
                        <h4>Autorizaciones PIN</h4>
                        <div class="aud-kpi-value" style="color: #d35400;"><?= number_format($kpiOverridesPin) ?></div>
                        <div class="aud-kpi-sub">Clic para ver overrides</div>
                    </div>
                </div>

                <div class="aud-kpi-card">
                    <div class="aud-kpi-icon purple">🗄️</div>
                    <div class="aud-kpi-data">
                        <h4>Módulos Auditados</h4>
                        <div class="aud-kpi-value" style="color: #7d3c98;"><?= $kpiTablasDist ?></div>
                        <div class="aud-kpi-sub">Tablas bajo vigilancia</div>
                    </div>
                </div>
            </div>

            <!-- PANEL DE FILTROS COMPLETOS (2 FILAS ORDENADAS + RANGO DE FECHAS) -->
            <div class="aud-filter-panel">
                <!-- Fila 1: Búsqueda + Rango de Fechas + Atajos Rápidos -->
                <div class="aud-filter-row">
                    <div class="aud-filter-group" style="flex: 2.2; min-width: 220px;">
                        <label for="filtroBusqueda">Búsqueda Profunda (NCF, Folio, Monto, Usuario, JSON)</label>
                        <input type="text" id="filtroBusqueda" class="form-control"
                            placeholder="Ej: FAC-2026, B03, EGR-, nombre cliente, monto..."
                            oninput="alCambiarFiltroAuditoria()" />
                    </div>

                    <div class="aud-filter-group" style="width: 145px;">
                        <label for="filtroFechaInicio">Fecha Desde</label>
                        <input type="date" id="filtroFechaInicio" class="form-control" max="<?= date('Y-m-d') ?>"
                            onchange="alCambiarFechaManualAud()" />
                    </div>

                    <div class="aud-filter-group" style="width: 145px;">
                        <label for="filtroFechaFin">Fecha Hasta</label>
                        <input type="date" id="filtroFechaFin" class="form-control" max="<?= date('Y-m-d') ?>"
                            onchange="alCambiarFechaManualAud()" />
                    </div>

                    <div class="aud-filter-group">
                        <label>Rango Rápido</label>
                        <div class="aud-quick-dates">
                            <button type="button" class="aud-pill-btn" data-rango="hoy"
                                onclick="aplicarRangoRapidoAud('hoy')">Hoy</button>
                            <button type="button" class="aud-pill-btn" data-rango="7dias"
                                onclick="aplicarRangoRapidoAud('7dias')">7 días</button>
                            <button type="button" class="aud-pill-btn" data-rango="mes"
                                onclick="aplicarRangoRapidoAud('mes')">Este mes</button>
                            <button type="button" class="aud-pill-btn active" data-rango="todo"
                                onclick="aplicarRangoRapidoAud('todo')">Todo</button>
                        </div>
                    </div>

                    <div class="aud-filter-group" style="width: 145px;">
                        <label for="filtroOrden">Orden</label>
                        <select id="filtroOrden" class="form-control" onchange="alCambiarFiltroAuditoria(true)">
                            <option value="fecha_desc">Más recientes</option>
                            <option value="fecha_asc">Más antiguos</option>
                        </select>
                    </div>
                </div>

                <!-- Fila 2: Módulo + Categoría + Acción + Operador + Supervisor + Checkboxes -->
                <div class="aud-filter-row" style="padding-top: 8px; border-top: 1px dashed #ece3d5;">
                    <div class="aud-filter-group" style="flex: 1.2; min-width: 150px;">
                        <label for="filtroTabla">Módulo / Tabla</label>
                        <select id="filtroTabla" class="form-control" onchange="alCambiarFiltroAuditoria(true)">
                            <option value="">-- Todos los Módulos --</option>
                            <?php foreach ($tablasDisponibles as $tLimpia): ?>
                                <option value="<?= htmlspecialchars($tLimpia) ?>">
                                    <?= htmlspecialchars(formatearNombreTablaAmigable($tLimpia)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="aud-filter-group" style="flex: 1.1; min-width: 145px;">
                        <label for="filtroCategoria">Categoría de Evento</label>
                        <select id="filtroCategoria" class="form-control" onchange="alCambiarFiltroAuditoria(true)">
                            <option value="">-- Todas --</option>
                            <option value="CREACION">✨ Creaciones / Altas</option>
                            <option value="MODIFICACION">📝 Modificaciones / Ajustes</option>
                            <option value="ANULACION">🗑️ Anulaciones / Bajas</option>
                            <option value="FISCAL">🏛️ Fiscales (NCF / DGII)</option>
                            <option value="CAJA_TESORERIA">💳 Caja, Bancos, CxC y CxP</option>
                            <option value="INVENTARIO">📦 Inventario y Consignaciones</option>
                        </select>
                    </div>

                    <div class="aud-filter-group" style="flex: 1.2; min-width: 155px;">
                        <label for="filtroAccion">Operación Exacta</label>
                        <select id="filtroAccion" class="form-control" onchange="alCambiarFiltroAuditoria(true)">
                            <option value="">-- Todas las Operaciones --</option>
                            <?php foreach ($accionesDisponibles as $acc): ?>
                                <option value="<?= htmlspecialchars($acc) ?>">
                                    <?= htmlspecialchars(formatearAccionAmigable($acc)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="aud-filter-group" style="flex: 1; min-width: 135px;">
                        <label for="filtroUsuario">Usuario Operador</label>
                        <select id="filtroUsuario" class="form-control" onchange="alCambiarFiltroAuditoria(true)">
                            <option value="">-- Todos --</option>
                            <?php foreach ($usuariosList as $u): ?>
                                <option value="<?= htmlspecialchars($u['usuario_id']) ?>">
                                    <?= htmlspecialchars($u['username']) ?>
                                    (<?= htmlspecialchars($u['nombre_razon_social']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="aud-filter-group" style="flex: 1; min-width: 135px;">
                        <label for="filtroSupervisor">Supervisor PIN</label>
                        <select id="filtroSupervisor" class="form-control" onchange="alCambiarFiltroAuditoria(true)">
                            <option value="">-- Todos --</option>
                            <?php foreach ($supervisoresList as $sup): ?>
                                <option value="<?= htmlspecialchars($sup['usuario_id']) ?>">
                                    <?= htmlspecialchars($sup['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="aud-check-group">
                        <label class="aud-check-pill">
                            <input type="checkbox" id="filtroSoloOverrides" onchange="alCambiarFiltroAuditoria(true)" />
                            <span>🔒 Solo PIN</span>
                        </label>
                        <label class="aud-check-pill">
                            <input type="checkbox" id="filtroSoloCambios" onchange="alCambiarFiltroAuditoria(true)" />
                            <span>⚖️ Antes / Después</span>
                        </label>
                    </div>

                    <div>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="limpiarFiltrosAuditoria()"
                            style="height: 32px; padding: 4px 12px; font-size: 0.78rem;">
                            ✕ Limpiar
                        </button>
                    </div>
                </div>
            </div>

            <!-- TARJETA DE TABLA CON SCROLL SUPERIOR SINCRONIZADO + BOTONES ◀ ▶ + COLUMNA DETALLE FIJA -->
            <div class="aud-table-card">
                <div class="aud-table-card-top">
                    <div class="table-card-title">
                        <span>Historial de Transacciones y Movimientos</span>
                        <span class="badge-count" id="badgeTotalAud"><?= number_format($kpiTotalRegistros) ?>
                            registros</span>
                    </div>

                    <div class="aud-table-tools">
                        <label
                            style="font-size: 0.72rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 5px;">
                            Ver:
                            <select id="filtroLimite" class="form-control"
                                style="height: 26px; width: 68px; padding: 1px 6px; font-size: 0.74rem;"
                                onchange="alCambiarLimiteAud()">
                                <option value="25">25</option>
                                <option value="50" selected>50</option>
                                <option value="100">100</option>
                                <option value="200">200</option>
                            </select>
                        </label>

                        <div class="aud-hscroll-controls"
                            title="Desplazar columnas horizontalmente (también puedes usar Shift + Rueda)">
                            <button type="button" class="aud-scroll-btn" onclick="desplazarTablaAud(-320)"
                                aria-label="Desplazar izquierda">◀</button>
                            <span style="font-size: 0.68rem; color: var(--text-muted); padding: 0 3px;">Columnas</span>
                            <button type="button" class="aud-scroll-btn" onclick="desplazarTablaAud(320)"
                                aria-label="Desplazar derecha">▶</button>
                        </div>
                    </div>
                </div>

                <!-- BARRA DE DESPLAZAMIENTO HORIZONTAL SUPERIOR SINCRONIZADA -->
                <div class="aud-top-scroll" id="audTopScroll" aria-label="Desplazamiento horizontal superior">
                    <div class="aud-top-scroll-inner" id="audTopScrollInner"></div>
                </div>

                <!-- CONTENEDOR SCROLLEABLE CON CABECERA STICKY Y ACCIÓN FIJA A LA DERECHA -->
                <div class="aud-table-wrapper" id="audTableWrapper">
                    <table class="aud-data-table" id="tablaAuditoria">
                        <thead>
                            <tr>
                                <th class="col-fecha">Fecha y Hora</th>
                                <th class="col-modulo">Módulo / Área</th>
                                <th class="col-accion">Operación</th>
                                <th class="col-usuario">Usuario Operador</th>
                                <th class="col-supervisor">Supervisor PIN</th>
                                <th class="col-resumen">Resumen de la Operación</th>
                                <th class="col-detalle col-sticky-right text-center">Detalle</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyAuditoria">
                            <?php if (empty($listaAuditoria)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 28px; color: var(--text-muted);">
                                        No hay registros de auditoría en la base de datos.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($listaAuditoria as $row):
                                    $nuevosArr = $row['datos_nuevos'] ? json_decode($row['datos_nuevos'], true) : [];
                                    $resumenTxt = generarResumenAmigable($nuevosArr);

                                    $claseAccion = 'update';
                                    $accUp = strtoupper((string) $row['accion']);
                                    if (strpos($accUp, 'INSERT') !== false || strpos($accUp, 'CREAR') !== false || strpos($accUp, 'APERTURA') !== false || strpos($accUp, 'REGISTRAR_GASTO') !== false) {
                                        $claseAccion = 'insert';
                                    } elseif (strpos($accUp, 'DELETE') !== false || strpos($accUp, 'ANUL') !== false || strpos($accUp, 'CANCEL') !== false || strpos($accUp, 'INCOBRABLE') !== false) {
                                        $claseAccion = 'delete';
                                    } elseif (strpos($accUp, 'B03') !== false || strpos($accUp, 'FISCAL') !== false || strpos($accUp, 'FACTURA') !== false || strpos($accUp, 'DGII') !== false) {
                                        $claseAccion = 'fiscal';
                                    } elseif (!empty($row['supervisor_id'])) {
                                        $claseAccion = 'override';
                                    }

                                    $tablaLimpia = strpos($row['tabla_afectada'], '.') !== false
                                        ? substr($row['tabla_afectada'], strrpos($row['tabla_afectada'], '.') + 1)
                                        : $row['tabla_afectada'];
                                    ?>
                                    <tr class="fila-aud" data-id="<?= (int) $row['auditoria_id'] ?>"
                                        ondblclick="inspeccionarForense(<?= (int) $row['auditoria_id'] ?>)"
                                        title="Doble clic para ver detalle completo">
                                        <td class="col-fecha">
                                            <span
                                                style="font-size: 0.78rem; font-weight: 600; font-family: var(--font-mono);"><?= htmlspecialchars($row['fecha_fmt']) ?></span>
                                        </td>
                                        <td class="col-modulo">
                                            <span class="badge-tabla" title="<?= htmlspecialchars($row['tabla_afectada']) ?>">
                                                <?= htmlspecialchars(formatearNombreTablaAmigable($tablaLimpia)) ?>
                                            </span>
                                        </td>
                                        <td class="col-accion">
                                            <span class="badge-accion <?= $claseAccion ?>">
                                                <?= htmlspecialchars(formatearAccionAmigable($row['accion'])) ?>
                                            </span>
                                        </td>
                                        <td class="col-usuario">
                                            <strong><?= htmlspecialchars($row['usuario_username']) ?></strong>
                                            <small
                                                style="display: block; font-size: 0.70rem; color: var(--text-muted);"><?= htmlspecialchars($row['usuario_nombre']) ?></small>
                                        </td>
                                        <td class="col-supervisor">
                                            <?php if (!empty($row['supervisor_id'])): ?>
                                                <span class="badge-override-pin" title="Autorizado con PIN de supervisor">🔒
                                                    <?= htmlspecialchars($row['supervisor_username'] ?: 'Supervisor') ?></span>
                                            <?php else: ?>
                                                <span style="color: #bbb; font-size: 0.74rem;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-resumen">
                                            <div class="aud-resumen-cell" title="<?= htmlspecialchars($resumenTxt) ?>">
                                                <?= htmlspecialchars($resumenTxt) ?>
                                            </div>
                                        </td>
                                        <td class="col-detalle col-sticky-right text-center">
                                            <button type="button" class="btn btn-sm btn-outline"
                                                onclick="inspeccionarForense(<?= (int) $row['auditoria_id'] ?>)"
                                                style="padding: 3px 9px; font-weight: 600; font-size: 0.75rem;">
                                                🔍 Ver
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- BARRA DE PAGINACIÓN -->
                <div class="aud-pagination-bar">
                    <div id="audInfoPaginacion" style="font-size: 0.76rem; color: var(--text-muted);">
                        Mostrando <?= count($listaAuditoria) ?> de <?= number_format($kpiTotalRegistros) ?> registros
                    </div>
                    <div class="aud-pagination-btns">
                        <button type="button" class="aud-page-btn" id="btnPagPrimera" onclick="irPaginaAud(1)"
                            disabled>« Primera</button>
                        <button type="button" class="aud-page-btn" id="btnPagAnterior"
                            onclick="irPaginaAud(AUD_PAGINA_ACTUAL - 1)" disabled>‹ Anterior</button>
                        <span id="audIndicadorPagina"
                            style="font-size: 0.76rem; font-weight: 700; padding: 0 8px;">Página 1 de
                            <?= $totalPaginasInicial ?></span>
                        <button type="button" class="aud-page-btn" id="btnPagSiguiente"
                            onclick="irPaginaAud(AUD_PAGINA_ACTUAL + 1)" <?= ($totalPaginasInicial <= 1) ? 'disabled' : '' ?>>Siguiente ›</button>
                        <button type="button" class="aud-page-btn" id="btnPagUltima"
                            onclick="irPaginaAud(AUD_TOTAL_PAGINAS)" <?= ($totalPaginasInicial <= 1) ? 'disabled' : '' ?>>Última »</button>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- MODAL INSPECTOR AMIGABLE -->
    <div class="modal-overlay" id="modalInspectorForense" onclick="cerrarModalBackdrop(event, 'modalInspectorForense')">
        <div class="modal-forense-box" onclick="event.stopPropagation()">
            <div class="modal-forense-header">
                <h3><span>🔍</span> Detalle de la Operación</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalInspectorForense')"
                    aria-label="Cerrar">&times;</button>
            </div>
            <div class="modal-forense-body" id="bodyInspectorForense"></div>
            <div class="modal-forense-footer">
                <button type="button" class="btn btn-secondary btn-sm" onclick="cerrarModal('modalInspectorForense')"
                    style="padding: 5px 16px; font-size: 0.8rem;">
                    Cerrar
                </button>
            </div>
        </div>
    </div>

    <script>
        window.AUD_INIT_STATE = {
            total: <?= (int) $kpiTotalRegistros ?>,
            pagina: 1,
            limite: <?= (int) $limiteInicial ?>,
            totalPaginas: <?= (int) $totalPaginasInicial ?>
        };
    </script>
    <script src="auditoria.js?v=<?= time() ?>"></script>
</body>

</html>