<?php
/**
 * ============================================================================
 * PROCESO DE DELIVERY: MONITOR DE DESPACHO EN VIVO, HORARIOS Y ZONAS
 * Carpeta: Ventas y Facturacion/Delivery/
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

$usuarioActualId = requerirSesionActiva(false, '../../Login y Menu/login.php');

if (function_exists('usuarioPuedeAccederDelivery') && !usuarioPuedeAccederDelivery()) {
  header("Location: ../../Login y Menu/menu.php?error=" . urlencode("Acceso restringido: no posees permisos para Despacho Delivery."));
  exit;
}

$puedeGestionarConfig = (function_exists('usuarioEsAdministrador') && usuarioEsAdministrador())
  || !empty($_SESSION['usuario']['es_supervisor']);

$puedeVerTodosPedidos = $puedeGestionarConfig
  || (function_exists('usuarioTienePermiso') && usuarioTienePermiso('CREAR_FACTURA'));

$repartidores = [];
$zonasDelivery = [];
$horarios = [];
$zonasAsignadas = [];
$pedidos = [];
$miEmpleadoId = null;

if ($pdo) {
  try {
    if (!$puedeVerTodosPedidos) {
      $stEmpMe = $pdo->prepare("
                SELECT e.empleado_id
                FROM seguridad.usuarios u
                JOIN identidad.empleados e ON e.persona_id = u.persona_id
                WHERE u.usuario_id = ?
                LIMIT 1
            ");
      $stEmpMe->execute([$usuarioActualId]);
      $miEmpleadoId = $stEmpMe->fetchColumn() ?: null;
    }

    // 1. Pedidos de Delivery en Vivo (Fases 3 y 4)
    $wherePed = (!$puedeVerTodosPedidos && $miEmpleadoId) ? "WHERE pd.repartidor_id = ?" : "";
    $paramsPed = (!$puedeVerTodosPedidos && $miEmpleadoId) ? [$miEmpleadoId] : [];

    $stmtPed = $pdo->prepare("
            SELECT pd.id,
                   pd.factura_id,
                   pd.repartidor_id,
                   pd.direccion_destino,
                   pd.zona_id,
                   pd.estado_delivery,
                   TO_CHAR(pd.hora_despacho, 'DD/MM/YYYY HH12:MI AM') AS hora_despacho_fmt,
                   TO_CHAR(pd.hora_entrega,  'DD/MM/YYYY HH12:MI AM') AS hora_entrega_fmt,
                   CASE
                       WHEN pd.hora_despacho IS NOT NULL AND pd.hora_entrega IS NOT NULL
                           THEN ROUND(EXTRACT(EPOCH FROM (pd.hora_entrega - pd.hora_despacho)) / 60.0, 1)
                       WHEN pd.hora_despacho IS NOT NULL AND pd.estado_delivery = 'EN_CAMINO'
                           THEN ROUND(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - pd.hora_despacho)) / 60.0, 1)
                       ELSE NULL
                   END AS minutos_viaje,
                   f.numero_factura,
                   COALESCE(f.ncf, 'S/C') AS ncf,
                   f.condicion_pago,
                   f.monto_total,
                   f.estado AS estado_factura,
                   TO_CHAR(f.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_factura_fmt,
                   COALESCE(NULLIF(TRIM(f.razon_social_cliente), ''), pc.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
                   COALESCE(pc.telefono_principal, '') AS cliente_telefono,
                   z.nombre AS zona_nombre,
                   z.tarifa_envio,
                   z.tiempo_promedio_min,
                   e.codigo_empleado AS repartidor_codigo,
                   pr.nombre_razon_social AS repartidor_nombre,
                   r.tipo_vehiculo,
                   COALESCE(r.placa_vehiculo, '') AS placa_vehiculo,
                   r.estado_logistico
            FROM ventas.pedidos_delivery pd
            JOIN ventas.facturas f          ON f.id = pd.factura_id
            LEFT JOIN identidad.personas pc ON pc.persona_id = f.cliente_id
            JOIN ventas.zonas_delivery z    ON z.id = pd.zona_id
            JOIN identidad.repartidores r   ON r.empleado_id = pd.repartidor_id
            JOIN identidad.empleados e      ON e.empleado_id = r.empleado_id
            JOIN identidad.personas pr      ON pr.persona_id = e.persona_id
            {$wherePed}
            ORDER BY CASE pd.estado_delivery
                         WHEN 'PENDIENTE' THEN 0
                         WHEN 'EN_CAMINO' THEN 1
                         WHEN 'ENTREGADO' THEN 2
                         ELSE 3
                     END ASC,
                     pd.id DESC
            LIMIT 150
        ");
    $stmtPed->execute($paramsPed);
    $pedidos = $stmtPed->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
  }

  if ($puedeGestionarConfig) {
    try {
      $repartidores = $pdo->query("
                SELECT r.empleado_id, r.numero_licencia, r.tipo_vehiculo, r.placa_vehiculo,
                       r.estado_logistico, r.capacidad_pedidos, e.codigo_empleado, e.cargo,
                       e.estado_laboral, p.nombre_razon_social, p.documento_identidad, p.telefono_principal
                FROM identidad.repartidores r
                JOIN identidad.empleados e ON e.empleado_id = r.empleado_id
                JOIN identidad.personas p  ON p.persona_id = e.persona_id
                ORDER BY p.nombre_razon_social ASC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    try {
      $zonasDelivery = $pdo->query("
                SELECT id, nombre, tarifa_envio, tiempo_promedio_min, activa
                FROM ventas.zonas_delivery
                ORDER BY activa DESC, nombre ASC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    try {
      $horarios = $pdo->query("
                SELECT h.repartidor_id, h.dia_semana,
                       TO_CHAR(h.hora_inicio, 'HH24:MI') AS hora_inicio,
                       TO_CHAR(h.hora_fin, 'HH24:MI')    AS hora_fin,
                       r.tipo_vehiculo, r.placa_vehiculo, r.estado_logistico,
                       e.codigo_empleado, p.nombre_razon_social
                FROM ventas.repartidor_horarios h
                JOIN identidad.repartidores r ON r.empleado_id = h.repartidor_id
                JOIN identidad.empleados e    ON e.empleado_id = r.empleado_id
                JOIN identidad.personas p     ON p.persona_id = e.persona_id
                ORDER BY h.dia_semana ASC, h.hora_inicio ASC, p.nombre_razon_social ASC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    try {
      $zonasAsignadas = $pdo->query("
                SELECT rz.repartidor_id, rz.zona_id, rz.es_ruta_principal,
                       r.tipo_vehiculo, r.placa_vehiculo, r.estado_logistico, r.capacidad_pedidos,
                       e.codigo_empleado, p.nombre_razon_social,
                       z.nombre AS zona_nombre, z.tarifa_envio AS zona_tarifa,
                       z.tiempo_promedio_min AS zona_tiempo, z.activa AS zona_activa
                FROM ventas.repartidor_zonas rz
                JOIN identidad.repartidores r ON r.empleado_id = rz.repartidor_id
                JOIN identidad.empleados e    ON e.empleado_id = r.empleado_id
                JOIN identidad.personas p     ON p.persona_id = e.persona_id
                JOIN ventas.zonas_delivery z  ON z.id = rz.zona_id
                ORDER BY p.nombre_razon_social ASC, rz.es_ruta_principal DESC, z.nombre ASC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }
  }
}

$kpiPendientes = 0;
$kpiEnCamino = 0;
$kpiEntregados = 0;
$kpiRetrasos = 0;
foreach ($pedidos as $p) {
  $est = strtoupper((string) $p['estado_delivery']);
  if ($est === 'PENDIENTE')
    $kpiPendientes++;
  elseif ($est === 'EN_CAMINO')
    $kpiEnCamino++;
  elseif ($est === 'ENTREGADO') {
    $kpiEntregados++;
    if ($p['minutos_viaje'] !== null && floatval($p['minutos_viaje']) > floatval($p['tiempo_promedio_min'] ?? 30)) {
      $kpiRetrasos++;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Despacho Delivery • Pedidos, Horarios y Zonas | House &amp; Ice</title>
  <link rel="stylesheet" href="../styles.css?v=17" />
</head>

<body>

  <!-- BARRA SUPERIOR -->
  <header class="topbar">
    <div style="display: flex; align-items: center; gap: 10px;">
      <button type="button" class="mobile-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Abrir Menú">
        <span></span><span></span><span></span>
      </button>
      <a href="../../Login y Menu/menu.php" class="topbar-brand" style="text-decoration: none;">
        <span class="brand-title">HOUSE &amp; ICE</span>
        <span class="brand-dot">•</span>
        <span class="brand-subtitle">Sistema Administrativo</span>
      </a>
    </div>
    <div class="topbar-actions">
      <div class="topbar-user">
        <div class="user-avatar"><?= htmlspecialchars($sesionAvatarInitials) ?></div>
        <div class="user-info-col">
          <div class="user-name"><?= htmlspecialchars($sesionNombreCompleto) ?></div>
          <div class="user-sub-line">
            <span class="user-role-text"><?= htmlspecialchars($sesionRol) ?></span>
            <span class="user-bullet">•</span>
            <?php if (function_exists('renderizarPillCajaTopbar')): ?>
              <?= renderizarPillCajaTopbar() ?>
            <?php else: ?>
              <span class="user-caja-pill open"><span class="pill-dot green"></span> Caja
                0<?= htmlspecialchars((string) $sesionCajaId) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="app-container">
    <?php include __DIR__ . '/../../sidebar.php'; ?>

    <main class="main-content">
      <div class="page-header">
        <div>
          <h1 class="page-title">Despacho Delivery • Pedidos, Horarios y Zonas</h1>
          <p class="page-subtitle" id="pageSubtitle">
            Monitor de salidas y entregas en vivo con auditoría de tiempos, asignación de turnos y cobertura por zona
          </p>
        </div>
        <div style="display: flex; gap: 10px;">
          <button type="button" class="btn btn-gold" id="btnNuevo" onclick="abrirModalNuevo()" style="display: none;">
            + Asignar Horario de Trabajo
          </button>
        </div>
      </div>

      <!-- RESUMEN KPI DEL MONITOR DE PEDIDOS -->
      <div id="kpiGridDelivery"
        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(175px, 1fr)); gap: 12px; margin-bottom: 18px;">
        <div style="background: #fff; border: 1px solid #ffe0b2; border-radius: 10px; padding: 14px 16px;">
          <div style="font-size: 0.76rem; color: #e65100; font-weight: 700;">PENDIENTES EN PUERTA</div>
          <div id="kpiValPendientes" style="font-size: 1.5rem; font-weight: 800; color: #e65100; margin-top: 4px;">
            <?= $kpiPendientes ?>
          </div>
        </div>
        <div style="background: #fff; border: 1px solid #bbdefb; border-radius: 10px; padding: 14px 16px;">
          <div style="font-size: 0.76rem; color: #1565c0; font-weight: 700;">EN CAMINO (EN RUTA)</div>
          <div id="kpiValEnCamino" style="font-size: 1.5rem; font-weight: 800; color: #1565c0; margin-top: 4px;">
            <?= $kpiEnCamino ?>
          </div>
        </div>
        <div style="background: #fff; border: 1px solid #c8e6c9; border-radius: 10px; padding: 14px 16px;">
          <div style="font-size: 0.76rem; color: #2e7d32; font-weight: 700;">ENTREGADOS</div>
          <div id="kpiValEntregados" style="font-size: 1.5rem; font-weight: 800; color: #2e7d32; margin-top: 4px;">
            <?= $kpiEntregados ?>
          </div>
        </div>
        <div style="background: #fff; border: 1px solid #ffcdd2; border-radius: 10px; padding: 14px 16px;">
          <div style="font-size: 0.76rem; color: #c62828; font-weight: 700;">ENTREGAS CON RETRASO</div>
          <div id="kpiValRetrasos" style="font-size: 1.5rem; font-weight: 800; color: #c62828; margin-top: 4px;">
            <?= $kpiRetrasos ?>
          </div>
        </div>
      </div>

      <!-- PESTAÑAS (3 TABS: PEDIDOS EN VIVO + HORARIOS + ZONAS) -->
      <div class="tab-nav">
        <button type="button" class="tab-btn active" id="tabPedidos" onclick="cambiarPestana('pedidos')">
          <span>🛵 Pedidos y Despacho en Vivo</span>
          <span class="tab-badge" id="badgeTabPedidos"><?= count($pedidos) ?></span>
        </button>
        <?php if ($puedeGestionarConfig): ?>
          <button type="button" class="tab-btn" id="tabHorarios" onclick="cambiarPestana('horarios')">
            <span>🕒 Asignar Horario de Trabajo</span>
            <span class="tab-badge" id="badgeTabHorarios"><?= count($horarios) ?></span>
          </button>
          <button type="button" class="tab-btn" id="tabZonas" onclick="cambiarPestana('zonas')">
            <span>📍 Asignar Zona de Delivery</span>
            <span class="tab-badge" id="badgeTabZonas"><?= count($zonasAsignadas) ?></span>
          </button>
        <?php endif; ?>
      </div>

      <!-- TARJETA PRINCIPAL DE TABLA DINÁMICA -->
      <div class="table-card">
        <div class="table-card-top" style="flex-wrap: wrap; gap: 10px;">
          <div class="table-card-info">
            <h2 class="table-card-title" id="cardTitle">Monitor de Pedidos a Domicilio</h2>
            <span class="badge-count" id="badgeCount"><?= count($pedidos) ?> pedidos</span>
          </div>

          <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <select id="filtroEstadoPedido" class="form-control" style="width: 175px; height: 38px;"
              onchange="filtrarDatos()">
              <option value="">Todos los estados</option>
              <option value="PENDIENTE">PENDIENTE</option>
              <option value="EN_CAMINO">EN CAMINO</option>
              <option value="ENTREGADO">ENTREGADO</option>
              <option value="CANCELADO">CANCELADO</option>
            </select>

            <div class="search-box">
              <span class="search-icon">🔍</span>
              <input type="text" id="inputBuscar"
                placeholder="Buscar por factura, cliente, zona, dirección o motorista..." oninput="filtrarDatos()" />
            </div>
          </div>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead id="tablaHead"></thead>
            <tbody id="tablaBody"></tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerCount">Cargando registros...</span>
          <span id="footerSchema" style="color: var(--text-muted); font-size: 0.82rem;">
            House &amp; Ice • Monitor de Despacho y Eficiencia Delivery
          </span>
        </div>
      </div>
    </main>
  </div>

  <!-- ========================================================================= -->
  <!-- MODAL 0: DETALLE DEL PEDIDO DELIVERY (PRODUCTOS, DIRECCIÓN Y TIEMPOS)     -->
  <!-- ========================================================================= -->
  <div class="modal-overlay" id="modalDetallePedido"
    onclick="if(event.target.id==='modalDetallePedido') cerrarModalDetallePedido()">
    <div class="modal-box modal-lg" style="max-width: 720px;">
      <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
          <h2 class="modal-title" id="detPedTitulo" style="margin: 0;">Detalle del Pedido Delivery</h2>
          <p class="modal-subtitle" id="detPedSub"
            style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px;">Hoja de ruta y artículos a entregar
          </p>
        </div>
        <button type="button" class="btn-sm btn-secondary" onclick="cerrarModalDetallePedido()">✕</button>
      </div>
      <div class="modal-body" id="detPedBody" style="max-height: 70vh; overflow-y: auto;">
        <p style="color: #888;">Consultando información del pedido...</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="cerrarModalDetallePedido()">Cerrar</button>
      </div>
    </div>
  </div>

  <?php if ($puedeGestionarConfig): ?>
    <!-- ========================================================================= -->
    <!-- MODAL 1: ASIGNAR / EDITAR HORARIO DE TRABAJO                              -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalHorario">
      <div class="modal-box" style="max-width: 560px;">
        <div class="modal-header">
          <div>
            <h2 class="modal-title" id="modalHorarioTitle">Asignar Horario de Trabajo</h2>
            <p class="modal-subtitle" style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px;">Configura el
              día laboral y el turno asignado al repartidor</p>
          </div>
        </div>

        <form id="formHorario" onsubmit="guardarHorario(event)">
          <div class="modal-body">
            <input type="hidden" id="horOrigRepartidorId" value="" />
            <input type="hidden" id="horOrigDiaSemana" value="" />
            <input type="hidden" id="horOrigHoraInicio" value="" />

            <div class="form-group">
              <label class="form-label" for="horRepartidorId">REPARTIDOR *</label>
              <select id="horRepartidorId" class="form-control" required>
                <option value="">-- Seleccione el Repartidor --</option>
                <?php foreach ($repartidores as $rep): ?>
                  <option value="<?= htmlspecialchars($rep['empleado_id']) ?>">
                    <?= htmlspecialchars($rep['nombre_razon_social']) ?> (<?= htmlspecialchars($rep['codigo_empleado']) ?> •
                    <?= htmlspecialchars($rep['tipo_vehiculo']) ?>
                    <?= !empty($rep['placa_vehiculo']) ? ' [' . htmlspecialchars($rep['placa_vehiculo']) . ']' : '' ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <div class="days-header-row">
                <label class="form-label" style="margin-bottom: 0;">DÍAS DE LA SEMANA *</label>
                <div class="days-quick-actions">
                  <button type="button" class="btn-day-preset"
                    onclick="seleccionarDiasPreset([1,2,3,4,5])">Lun–Vie</button>
                  <button type="button" class="btn-day-preset"
                    onclick="seleccionarDiasPreset([1,2,3,4,5,6])">Lun–Sáb</button>
                  <button type="button" class="btn-day-preset" onclick="seleccionarDiasPreset([6,7])">Fin Sem.</button>
                  <button type="button" class="btn-day-preset"
                    onclick="seleccionarDiasPreset([1,2,3,4,5,6,7])">Todos</button>
                  <button type="button" class="btn-day-preset" onclick="seleccionarDiasPreset([])">Limpiar</button>
                </div>
              </div>

              <div class="days-check-grid" id="gridDiasSemana">
                <label class="day-check-card"><input type="checkbox" class="chk-dia-horario" value="1"
                    onchange="actualizarEstiloDias()" /><span class="day-check-box">✓</span><span
                    class="day-check-name">Lunes</span></label>
                <label class="day-check-card"><input type="checkbox" class="chk-dia-horario" value="2"
                    onchange="actualizarEstiloDias()" /><span class="day-check-box">✓</span><span
                    class="day-check-name">Martes</span></label>
                <label class="day-check-card"><input type="checkbox" class="chk-dia-horario" value="3"
                    onchange="actualizarEstiloDias()" /><span class="day-check-box">✓</span><span
                    class="day-check-name">Miércoles</span></label>
                <label class="day-check-card"><input type="checkbox" class="chk-dia-horario" value="4"
                    onchange="actualizarEstiloDias()" /><span class="day-check-box">✓</span><span
                    class="day-check-name">Jueves</span></label>
                <label class="day-check-card"><input type="checkbox" class="chk-dia-horario" value="5"
                    onchange="actualizarEstiloDias()" /><span class="day-check-box">✓</span><span
                    class="day-check-name">Viernes</span></label>
                <label class="day-check-card"><input type="checkbox" class="chk-dia-horario" value="6"
                    onchange="actualizarEstiloDias()" /><span class="day-check-box">✓</span><span
                    class="day-check-name">Sábado</span></label>
                <label class="day-check-card"><input type="checkbox" class="chk-dia-horario" value="7"
                    onchange="actualizarEstiloDias()" /><span class="day-check-box">✓</span><span
                    class="day-check-name">Domingo</span></label>
              </div>
            </div>

            <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
              <div class="form-group">
                <label class="form-label" for="horHoraInicio">HORA DE INICIO *</label>
                <input type="time" id="horHoraInicio" class="form-control" required />
              </div>
              <div class="form-group">
                <label class="form-label" for="horHoraFin">HORA DE FIN *</label>
                <input type="time" id="horHoraFin" class="form-control" required />
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="cerrarModalHorario()">Cancelar</button>
            <button type="submit" class="btn btn-gold" id="btnGuardarHorario">Guardar Horario</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 2: ASIGNAR / EDITAR ZONA DE DELIVERY                                -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="modalZonaAsignacion">
      <div class="modal-box" style="max-width: 560px;">
        <div class="modal-header">
          <div>
            <h2 class="modal-title" id="modalZonaTitle">Asignar Zona de Delivery</h2>
            <p class="modal-subtitle" style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px;">Vincula un
              sector de entrega al repartidor y define la prioridad de la ruta</p>
          </div>
        </div>

        <form id="formZonaAsignacion" onsubmit="guardarZonaAsignacion(event)">
          <div class="modal-body">
            <input type="hidden" id="zonOrigRepartidorId" value="" />
            <input type="hidden" id="zonOrigZonaId" value="" />

            <div class="form-group">
              <label class="form-label" for="zonRepartidorId">REPARTIDOR *</label>
              <select id="zonRepartidorId" class="form-control" required>
                <option value="">-- Seleccione el Repartidor --</option>
                <?php foreach ($repartidores as $rep): ?>
                  <option value="<?= htmlspecialchars($rep['empleado_id']) ?>">
                    <?= htmlspecialchars($rep['nombre_razon_social']) ?> (<?= htmlspecialchars($rep['codigo_empleado']) ?> •
                    <?= htmlspecialchars($rep['tipo_vehiculo']) ?> • Cap: <?= intval($rep['capacidad_pedidos']) ?> ped.)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label" for="zonZonaId">ZONA DE DELIVERY *</label>
              <select id="zonZonaId" class="form-control" required>
                <option value="">-- Seleccione la Zona Geográfica --</option>
                <?php foreach ($zonasDelivery as $z):
                  $esActivaZ = ($z['activa'] === true || $z['activa'] === 't' || $z['activa'] === 1 || $z['activa'] === '1');
                  ?>
                  <option value="<?= intval($z['id']) ?>">
                    <?= htmlspecialchars($z['nombre']) ?> (RD$ <?= number_format((float) $z['tarifa_envio'], 2) ?> •
                    ~<?= intval($z['tiempo_promedio_min']) ?> min<?= !$esActivaZ ? ' [INACTIVA]' : '' ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label" for="zonEsRutaPrincipal">¿ES RUTA PRINCIPAL? *</label>
              <select id="zonEsRutaPrincipal" class="form-control" required>
                <option value="false">No • Ruta Secundaria / Cobertura de Apoyo</option>
                <option value="true">Sí • Ruta Principal Prioritaria del Repartidor</option>
              </select>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="cerrarModalZona()">Cancelar</button>
            <button type="submit" class="btn btn-gold" id="btnGuardarZona">Guardar Asignación</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <div class="toast" id="toast">Operación exitosa</div>

  <div id="datosDelivery" data-puede-config="<?= $puedeGestionarConfig ? '1' : '0' ?>"
    data-pedidos="<?= htmlspecialchars(json_encode($pedidos, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
    data-horarios="<?= htmlspecialchars(json_encode($horarios, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
    data-zonas-asignadas="<?= htmlspecialchars(json_encode($zonasAsignadas, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
    style="display: none;"></div>

  <script src="delivery.js?v=<?= time() ?>"></script>
</body>

</html>