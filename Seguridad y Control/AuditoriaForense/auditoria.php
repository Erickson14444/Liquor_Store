<?php
/**
 * ============================================================================
 * Vista Principal: Auditoría Forense (Compacta, Práctica y Amigable)
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

// ============================================================================
// FUNCIONES AUXILIARES: TRADUCCIÓN A LENGUAJE HUMANO (SIN CÓDIGO NI IDs)
// ============================================================================
function formatearNombreTablaAmigable(string $tabla): string {
    $t = strpos($tabla, '.') !== false ? substr($tabla, strrpos($tabla, '.') + 1) : $tabla;
    $map = [
        'comprobantes_egreso_cxp' => 'Comprobantes CxP',
        'movimientos_inventario' => 'Movimientos Stock',
        'traslados_internos'     => 'Traslados Internos',
        'consignaciones'         => 'Consignaciones',
        'cupones_descuento'      => 'Cupones Descuento',
        'promociones_campanas'   => 'Promociones y Campañas',
        'facturas'               => 'Facturación / Ventas',
        'facturas_detalles'      => 'Detalle Factura',
        'cotizaciones'           => 'Cotizaciones',
        'devoluciones'           => 'Devoluciones',
        'ordenes_compra'         => 'Órdenes de Compra',
        'recepciones'            => 'Recepciones Mercancía',
        'caja_sesiones'          => 'Sesiones de Caja',
        'caja_movimientos'       => 'Movimientos de Caja',
        'cuentas_por_cobrar'     => 'Cuentas por Cobrar (CxC)',
        'notas_debito'           => 'Notas de Débito',
        'usuarios'               => 'Usuarios',
        'roles'                  => 'Roles y Permisos',
        'articulos'              => 'Catálogo Artículos',
        'personas'               => 'Clientes y Proveedores'
    ];
    return $map[strtolower($t)] ?? ucwords(str_replace('_', ' ', $t));
}

function formatearAccionAmigable(string $accion): string {
    $map = [
        'LIQUIDAR_EGRESO_CXP'        => 'Liquidación Egreso CxP',
        'LIQUIDAR_CONSIGNACION'      => 'Liquidación Consignación',
        'CREAR_CONSIGNACION'         => 'Creación Consignación',
        'INVENTARIO_TRASLADO_EMISION'=> 'Emisión Traslado Stock',
        'INVENTARIO_AJUSTE_ENTRADA'  => 'Entrada por Ajuste Stock',
        'INVENTARIO_AJUSTE_SALIDA'   => 'Salida por Ajuste Stock',
        'FACTURACION_B01'            => 'Factura Crédito Fiscal (B01)',
        'FACTURACION_B02'            => 'Factura Consumo (B02)',
        'FACTURACION_B03'            => 'Nota de Débito Fiscal (B03)',
        'FACTURACION_B04'            => 'Nota de Crédito / Devolución',
        'APERTURA_CAJA'              => 'Apertura de Caja',
        'CIERRE_CAJA'                => 'Cierre de Caja',
        'DEVOLUCION_VENTA'           => 'Devolución de Venta',
        'RECEPCION_MERCANCIA'        => 'Recepción de Mercancía',
        'ANULACION_VENTA'            => 'Anulación de Venta',
        'OVERRIDE_PIN'               => 'Autorización con PIN'
    ];
    $accUp = strtoupper(trim($accion));
    if (isset($map[$accUp])) return $map[$accUp];
    return ucwords(str_replace('_', ' ', strtolower($accion)));
}

function generarResumenAmigable(?array $datos): string {
    if (empty($datos) || !is_array($datos)) {
        return '(Sin datos adicionales)';
    }

    $nombresCampos = [
        'numero_comprobante' => 'Comprobante',
        'total_pagado'       => 'Total Pagado',
        'total_retenciones'  => 'Retenciones',
        'facturas_afectadas' => 'Facturas',
        'ncf'                => 'NCF',
        'estado'             => 'Estado',
        'numero'             => 'Nº',
        'numero_traslado'    => 'Nº Traslado',
        'almacen_origen'     => 'Origen',
        'almacen_destino'    => 'Destino',
        'almacen_nombre'     => 'Almacén',
        'tipo_movimiento'    => 'Tipo',
        'justificacion'      => 'Motivo',
        'monto'              => 'Monto',
        'total'              => 'Total',
        'subtotal'           => 'Subtotal',
        'descuento'          => 'Descuento',
        'cliente'            => 'Cliente',
        'proveedor'          => 'Proveedor'
    ];

    $partes = [];
    foreach ($datos as $k => $v) {
        $kLower = strtolower($k);
        // Omitir estrictamente IDs técnicos
        if ($kLower === 'id' || substr($kLower, -3) === '_id' || substr($kLower, -5) === '_uuid' || $kLower === 'uuid') {
            continue;
        }
        if (is_array($v)) {
            $partes[] = "Artículos: " . count($v);
            continue;
        }
        if (!is_scalar($v) || $v === null || $v === '') {
            continue;
        }

        $etiqueta = $nombresCampos[$kLower] ?? ucwords(str_replace('_', ' ', $kLower));
        $valStr = (string)$v;

        // Formatear montos en moneda local RD$
        if (is_numeric($v) && (strpos($kLower, 'total') !== false || strpos($kLower, 'monto') !== false || strpos($kLower, 'pagado') !== false || strpos($kLower, 'retencion') !== false)) {
            $valStr = 'RD$ ' . number_format((float)$v, 2);
        }

        $partes[] = "{$etiqueta}: {$valStr}";
        if (count($partes) >= 3) break;
    }

    return !empty($partes) ? implode(' • ', $partes) : '(Detalles registrados)';
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

    // 2. Filtros disponibles (Nombre de tabla sin esquema)
    try {
        $tablasRaw = $pdo->query("
            SELECT DISTINCT tabla_afectada 
            FROM seguridad.auditoria 
            WHERE tabla_afectada IS NOT NULL AND tabla_afectada <> '' 
            ORDER BY tabla_afectada ASC
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $tablasDisponibles = [];
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

    <div class="app-container app-layout">
        <!-- SIDEBAR DE NAVEGACIÓN -->
        <?php include_once __DIR__ . '/../../sidebar.php'; ?>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <!-- CABECERA DEL MÓDULO (COMPACTA) -->
            <div class="page-header aud-header-compact">
                <div>
                    <h1 class="page-title">🛡️ Auditoría del Sistema</h1>
                    <p class="page-subtitle">
                        Registro claro y transparente de todas las transacciones, operaciones y autorizaciones con PIN.
                    </p>
                </div>
                <div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="recargarAuditoria()" style="background:#fff; border: 1px solid var(--border-light); font-weight: 600; padding: 4px 10px; font-size: 0.8rem;">
                        🔄 Actualizar
                    </button>
                </div>
            </div>

            <!-- TARJETAS DE INDICADORES (KPIS COMPACTOS) -->
            <div class="aud-kpi-container">
                <div class="aud-kpi-card">
                    <div class="aud-kpi-icon dark">👁️</div>
                    <div class="aud-kpi-data">
                        <h4>Total Registros</h4>
                        <div class="aud-kpi-value"><?= number_format($kpiTotalRegistros) ?></div>
                        <div class="aud-kpi-sub">Operaciones auditadas</div>
                    </div>
                </div>

                <div class="aud-kpi-card">
                    <div class="aud-kpi-icon blue">📅</div>
                    <div class="aud-kpi-data">
                        <h4>Operaciones de Hoy</h4>
                        <div class="aud-kpi-value" style="color: #1e6091;"><?= number_format($kpiHoyRegistros) ?></div>
                        <div class="aud-kpi-sub">Actividad del día</div>
                    </div>
                </div>

                <div class="aud-kpi-card">
                    <div class="aud-kpi-icon orange">🔑</div>
                    <div class="aud-kpi-data">
                        <h4>Autorizaciones PIN</h4>
                        <div class="aud-kpi-value" style="color: #d35400;"><?= number_format($kpiOverridesPin) ?></div>
                        <div class="aud-kpi-sub">Permitidas por supervisor</div>
                    </div>
                </div>

                <div class="aud-kpi-card">
                    <div class="aud-kpi-icon purple">🗄️</div>
                    <div class="aud-kpi-data">
                        <h4>Módulos Auditados</h4>
                        <div class="aud-kpi-value" style="color: #7d3c98;"><?= $kpiTablasDist ?></div>
                        <div class="aud-kpi-sub">Tablas vigiladas</div>
                    </div>
                </div>
            </div>

            <!-- FILTROS AVANZADOS (COMPACTOS Y AMIGABLES) -->
            <div class="aud-filter-bar">
                <div class="aud-filter-group" style="flex: 2; min-width: 180px;">
                    <label>Búsqueda en Registros</label>
                    <input type="text" id="filtroBusqueda" class="form-control" placeholder="Buscar comprobante, NCF, monto, producto, usuario..." oninput="alCambiarFiltroAuditoria()" />
                </div>

                <div class="aud-filter-group" style="flex: 1.2; min-width: 150px;">
                    <label>Módulo / Tabla</label>
                    <select id="filtroTabla" class="form-control" onchange="alCambiarFiltroAuditoria()">
                        <option value="">-- Todos los Módulos --</option>
                        <?php foreach ($tablasDisponibles as $tLimpia): ?>
                            <option value="<?= htmlspecialchars($tLimpia) ?>">
                                <?= htmlspecialchars(formatearNombreTablaAmigable($tLimpia)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="aud-filter-group" style="flex: 1.2; min-width: 140px;">
                    <label>Operación Realizada</label>
                    <select id="filtroAccion" class="form-control" onchange="alCambiarFiltroAuditoria()">
                        <option value="">-- Todas las Operaciones --</option>
                        <?php foreach ($accionesDisponibles as $acc): ?>
                            <option value="<?= htmlspecialchars($acc) ?>">
                                <?= htmlspecialchars(formatearAccionAmigable($acc)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="aud-filter-group" style="flex: 1.2; min-width: 140px;">
                    <label>Usuario Operador</label>
                    <select id="filtroUsuario" class="form-control" onchange="alCambiarFiltroAuditoria()">
                        <option value="">-- Todos los Usuarios --</option>
                        <?php foreach ($usuariosList as $u): ?>
                            <option value="<?= htmlspecialchars($u['usuario_id']) ?>">
                                <?= htmlspecialchars($u['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="aud-filter-group" style="align-items: center; justify-content: flex-end; padding-bottom: 5px;">
                    <label style="display: flex; align-items: center; gap: 5px; cursor: pointer; text-transform: none; font-size: 0.74rem; font-weight: 700; color: #8c4c15;">
                        <input type="checkbox" id="filtroSoloOverrides" onchange="alCambiarFiltroAuditoria()" />
                        Solo con PIN Supervisor
                    </label>
                </div>

                <div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="limpiarFiltrosAuditoria()" style="height: 32px; padding: 4px 10px; font-size: 0.78rem;">
                        Limpiar
                    </button>
                </div>
            </div>

            <!-- TABLA FORENSE (ENCAJADA AL 100%, SIN SCROLL HORIZONTAL, SIN IDs TÉCNICOS) -->
            <div class="aud-table-card">
                <div class="aud-table-card-top">
                    <div class="table-card-title">
                        <span>Historial de Transacciones y Movimientos</span>
                        <span class="badge-count" id="badgeTotalAud"><?= count($listaAuditoria) ?> registros</span>
                    </div>
                    <div style="font-size: 0.72rem; color: var(--text-muted);">
                        Seguridad y Trazabilidad Activa
                    </div>
                </div>

                <div class="aud-table-wrapper">
                    <table class="aud-data-table" id="tablaAuditoria">
                        <thead>
                            <tr>
                                <th style="width: 15%;">Fecha y Hora</th>
                                <th style="width: 17%;">Módulo / Área</th>
                                <th style="width: 17%;">Operación</th>
                                <th style="width: 14%;">Usuario</th>
                                <th style="width: 13%;">Supervisor PIN</th>
                                <th style="width: 16%;">Resumen de la Operación</th>
                                <th class="text-center" style="width: 8%;">Detalle</th>
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

                                    $tablaLimpia = strpos($row['tabla_afectada'], '.') !== false 
                                        ? substr($row['tabla_afectada'], strrpos($row['tabla_afectada'], '.') + 1) 
                                        : $row['tabla_afectada'];

                                    $nombreModuloAmigable = formatearNombreTablaAmigable($tablaLimpia);
                                    $nombreAccionAmigable = formatearAccionAmigable($row['accion']);
                                ?>
                                    <tr class="fila-aud" data-id="<?= $row['auditoria_id'] ?>">
                                        <td>
                                            <span style="font-size: 0.78rem; font-weight: 600;"><?= htmlspecialchars($row['fecha_fmt']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge-tabla" title="<?= htmlspecialchars($row['tabla_afectada']) ?>">
                                                <?= htmlspecialchars($nombreModuloAmigable) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge-accion <?= $claseAccion ?>">
                                                <?= htmlspecialchars($nombreAccionAmigable) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($row['usuario_username']) ?></strong>
                                            <small style="display: block; font-size: 0.70rem; color: var(--text-muted);"><?= htmlspecialchars($row['usuario_nombre']) ?></small>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['supervisor_id'])): ?>
                                                <span class="badge-override-pin" title="Operación autorizada con PIN de supervisor">
                                                    🔒 <?= htmlspecialchars($row['supervisor_username'] ?: 'Supervisor') ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #bbb; font-size: 0.74rem;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.75rem; color: #444; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($resumenTxt) ?>">
                                                <?= htmlspecialchars($resumenTxt) ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline" onclick="inspeccionarForense(<?= $row['auditoria_id'] ?>)" style="padding: 2px 7px; font-weight: 600; font-size: 0.75rem;">
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
        </main>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL: DETALLE AMIGABLE Y ENTENDIBLE DE AUDITORÍA                         -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalInspectorForense" onclick="cerrarModalBackdrop(event, 'modalInspectorForense')">
        <div class="modal-forense-box" onclick="event.stopPropagation()">
            <div class="modal-forense-header">
                <h3><span>🔍</span> Detalle de la Operación</h3>
                <button type="button" class="modal-close-btn" onclick="cerrarModal('modalInspectorForense')" aria-label="Cerrar">&times;</button>
            </div>
            <div class="modal-forense-body" id="bodyInspectorForense">
                <!-- Inyectado dinámicamente en formato amigable -->
            </div>
            <div class="modal-forense-footer">
                <button type="button" class="btn btn-secondary btn-sm" onclick="cerrarModal('modalInspectorForense')" style="padding: 4px 14px; font-size: 0.8rem;">
                    Cerrar
                </button>
            </div>
        </div>
    </div>

    <script src="auditoria.js?v=3"></script>
</body>

</html>
