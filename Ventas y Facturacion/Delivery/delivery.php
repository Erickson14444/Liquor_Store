<?php
/**
 * ============================================================================
 * PROCESO DE DELIVERY: ASIGNACIÓN DE HORARIOS DE TRABAJO Y ZONAS DE REPARTO
 * Carpeta: Ventas y Facturacion/Delivery/
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas:
 *   - ventas.repartidor_horarios (repartidor_id, dia_semana, hora_inicio, hora_fin)
 *   - ventas.repartidor_zonas    (repartidor_id, zona_id, es_ruta_principal)
 *   - identidad.repartidores     (empleado_id, numero_licencia, tipo_vehiculo, placa_vehiculo, estado_logistico, capacidad_pedidos)
 *   - ventas.zonas_delivery      (id, nombre, tarifa_envio, tiempo_promedio_min, activa)
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';

$repartidores = [];
$zonasDelivery = [];
$horarios = [];
$zonasAsignadas = [];

if ($pdo) {
    try {
        // 1. Repartidores registrados (identidad.repartidores + empleados + personas)
        $stmtRep = $pdo->query("
            SELECT r.empleado_id,
                   r.numero_licencia,
                   r.tipo_vehiculo,
                   r.placa_vehiculo,
                   r.estado_logistico,
                   r.capacidad_pedidos,
                   e.codigo_empleado,
                   e.cargo,
                   e.estado_laboral,
                   p.nombre_razon_social,
                   p.documento_identidad,
                   p.telefono_principal
            FROM identidad.repartidores r
            JOIN identidad.empleados e ON e.empleado_id = r.empleado_id
            JOIN identidad.personas p  ON p.persona_id = e.persona_id
            ORDER BY p.nombre_razon_social ASC
        ");
        $repartidores = $stmtRep->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    try {
        // 2. Zonas de Delivery registradas (ventas.zonas_delivery)
        $stmtZon = $pdo->query("
            SELECT id,
                   nombre,
                   tarifa_envio,
                   tiempo_promedio_min,
                   activa
            FROM ventas.zonas_delivery
            ORDER BY activa DESC, nombre ASC
        ");
        $zonasDelivery = $stmtZon->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    try {
        // 3. Horarios asignados a repartidores (ventas.repartidor_horarios)
        $stmtHor = $pdo->query("
            SELECT h.repartidor_id,
                   h.dia_semana,
                   TO_CHAR(h.hora_inicio, 'HH24:MI') AS hora_inicio,
                   TO_CHAR(h.hora_fin, 'HH24:MI')    AS hora_fin,
                   r.tipo_vehiculo,
                   r.placa_vehiculo,
                   r.estado_logistico,
                   e.codigo_empleado,
                   p.nombre_razon_social
            FROM ventas.repartidor_horarios h
            JOIN identidad.repartidores r ON r.empleado_id = h.repartidor_id
            JOIN identidad.empleados e    ON e.empleado_id = r.empleado_id
            JOIN identidad.personas p     ON p.persona_id = e.persona_id
            ORDER BY h.dia_semana ASC, h.hora_inicio ASC, p.nombre_razon_social ASC
        ");
        $horarios = $stmtHor->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    try {
        // 4. Zonas asignadas a repartidores (ventas.repartidor_zonas)
        $stmtRepZon = $pdo->query("
            SELECT rz.repartidor_id,
                   rz.zona_id,
                   rz.es_ruta_principal,
                   r.tipo_vehiculo,
                   r.placa_vehiculo,
                   r.estado_logistico,
                   r.capacidad_pedidos,
                   e.codigo_empleado,
                   p.nombre_razon_social,
                   z.nombre              AS zona_nombre,
                   z.tarifa_envio        AS zona_tarifa,
                   z.tiempo_promedio_min AS zona_tiempo,
                   z.activa              AS zona_activa
            FROM ventas.repartidor_zonas rz
            JOIN identidad.repartidores r ON r.empleado_id = rz.repartidor_id
            JOIN identidad.empleados e    ON e.empleado_id = r.empleado_id
            JOIN identidad.personas p     ON p.persona_id = e.persona_id
            JOIN ventas.zonas_delivery z  ON z.id = rz.zona_id
            ORDER BY p.nombre_razon_social ASC, rz.es_ruta_principal DESC, z.nombre ASC
        ");
        $zonasAsignadas = $stmtRepZon->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Proceso de Delivery • Horarios y Zonas | House &amp; Ice</title>
  <link rel="stylesheet" href="../styles.css?v=17" />
</head>
<body>

  <!-- BARRA SUPERIOR -->
  <header class="topbar">
    <div style="display: flex; align-items: center; gap: 10px;">
      <button type="button" class="mobile-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Abrir Menú">
        <span></span>
        <span></span>
        <span></span>
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
            <span class="user-caja-pill open">
              <span class="pill-dot green"></span>
              Caja 0<?= htmlspecialchars((string)$sesionCajaId) ?>
            </span>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="app-container">
    <!-- SIDEBAR UNIFICADO EN RAÍZ -->
    <?php include __DIR__ . '/../../sidebar.php'; ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content">
      <div class="page-header">
        <div>
          <h1 class="page-title">Proceso de Delivery • Horarios y Zonas</h1>
          <p class="page-subtitle" id="pageSubtitle">
            Asignación operativa de turnos de trabajo y cobertura de zonas de entrega para repartidores
          </p>
        </div>
        <div style="display: flex; gap: 10px;">
          <button type="button" class="btn btn-gold" id="btnNuevo" onclick="abrirModalNuevo()">
            + Asignar Horario de Trabajo
          </button>
        </div>
      </div>

      <!-- PESTAÑAS (2 TABS COMO EN MARCAS Y CATEGORÍAS) -->
      <div class="tab-nav">
        <button type="button" class="tab-btn active" id="tabHorarios" onclick="cambiarPestana('horarios')">
          <span>🕒 Asignar Horario de Trabajo</span>
          <span class="tab-badge" id="badgeTabHorarios"><?= count($horarios) ?></span>
        </button>
        <button type="button" class="tab-btn" id="tabZonas" onclick="cambiarPestana('zonas')">
          <span>📍 Asignar Zona de Delivery</span>
          <span class="tab-badge" id="badgeTabZonas"><?= count($zonasAsignadas) ?></span>
        </button>
      </div>

      <!-- TARJETA DE TABLA DINÁMICA SEGÚN LA PESTAÑA ACTIVA -->
      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title" id="cardTitle">Horarios de Trabajo Asignados</h2>
            <span class="badge-count" id="badgeCount"><?= count($horarios) ?> asignaciones</span>
          </div>
          <div class="search-box">
            <span class="search-icon">🔍</span>
            <input
              type="text"
              id="inputBuscar"
              placeholder="Buscar por repartidor, código, día o vehículo..."
              oninput="filtrarDatos()"
            />
          </div>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead id="tablaHead">
              <!-- Encabezados dinámicos renderizados según pestaña activa -->
            </thead>
            <tbody id="tablaBody">
              <!-- Filas dinámicas renderizadas vía delivery.js -->
            </tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerCount">Cargando registros...</span>
          <span id="footerSchema" style="color: var(--text-muted); font-size: 0.82rem;">
            House &amp; Ice • Gestión de Turnos y Rutas de Reparto
          </span>
        </div>
      </div>
    </main>
  </div>

  <!-- ========================================================================= -->
  <!-- MODAL 1: ASIGNAR / EDITAR HORARIO DE TRABAJO                              -->
  <!-- ========================================================================= -->
  <div class="modal-overlay" id="modalHorario">
    <div class="modal-box" style="max-width: 560px;">
      <div class="modal-header">
        <div>
          <h2 class="modal-title" id="modalHorarioTitle">Asignar Horario de Trabajo</h2>
          <p class="modal-subtitle" style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px; font-weight: 400;">Configura el día laboral y el turno asignado al repartidor</p>
        </div>
      </div>

      <form id="formHorario" onsubmit="guardarHorario(event)">
        <div class="modal-body">
          <!-- Llaves originales para edición -->
          <input type="hidden" id="horOrigRepartidorId" value="" />
          <input type="hidden" id="horOrigDiaSemana" value="" />
          <input type="hidden" id="horOrigHoraInicio" value="" />

          <?php if (empty($repartidores)): ?>
            <div style="background: #fff8eb; border: 1px solid #f0d8a8; border-radius: 6px; padding: 12px 14px; margin-bottom: 14px; font-size: 0.84rem; color: #7a5216;">
              ⚠️ <strong>No hay repartidores registrados.</strong><br>
              Primero registra un repartidor en <a href="../../Panel de configuracion/personal/personal.php?tab=repartidores" style="color: var(--accent-gold); font-weight: 700;">Personal y Repartidores</a>.
            </div>
          <?php endif; ?>

          <div class="form-group">
            <label class="form-label" for="horRepartidorId">REPARTIDOR *</label>
            <select id="horRepartidorId" class="form-control" required>
              <option value="">-- Seleccione el Repartidor --</option>
              <?php foreach ($repartidores as $rep): ?>
                <option value="<?= htmlspecialchars($rep['empleado_id']) ?>">
                  <?= htmlspecialchars($rep['nombre_razon_social']) ?> (<?= htmlspecialchars($rep['codigo_empleado']) ?> • <?= htmlspecialchars($rep['tipo_vehiculo']) ?><?= !empty($rep['placa_vehiculo']) ? ' [' . htmlspecialchars($rep['placa_vehiculo']) . ']' : '' ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <div class="days-header-row">
              <label class="form-label" style="margin-bottom: 0;">DÍAS DE LA SEMANA *</label>
              <div class="days-quick-actions">
                <button type="button" class="btn-day-preset" onclick="seleccionarDiasPreset([1,2,3,4,5])">Lun–Vie</button>
                <button type="button" class="btn-day-preset" onclick="seleccionarDiasPreset([1,2,3,4,5,6])">Lun–Sáb</button>
                <button type="button" class="btn-day-preset" onclick="seleccionarDiasPreset([1,3,5])">Lun•Mié•Vie</button>
                <button type="button" class="btn-day-preset" onclick="seleccionarDiasPreset([1,2,3,4,5,6,7])">Todos</button>
                <button type="button" class="btn-day-preset" onclick="seleccionarDiasPreset([])">Limpiar</button>
              </div>
            </div>

            <div class="days-check-grid" id="gridDiasSemana">
              <label class="day-check-card">
                <input type="checkbox" class="chk-dia-horario" value="1" onchange="actualizarEstiloDias()" />
                <span class="day-check-box">✓</span>
                <span class="day-check-name">Lunes</span>
              </label>
              <label class="day-check-card">
                <input type="checkbox" class="chk-dia-horario" value="2" onchange="actualizarEstiloDias()" />
                <span class="day-check-box">✓</span>
                <span class="day-check-name">Martes</span>
              </label>
              <label class="day-check-card">
                <input type="checkbox" class="chk-dia-horario" value="3" onchange="actualizarEstiloDias()" />
                <span class="day-check-box">✓</span>
                <span class="day-check-name">Miércoles</span>
              </label>
              <label class="day-check-card">
                <input type="checkbox" class="chk-dia-horario" value="4" onchange="actualizarEstiloDias()" />
                <span class="day-check-box">✓</span>
                <span class="day-check-name">Jueves</span>
              </label>
              <label class="day-check-card">
                <input type="checkbox" class="chk-dia-horario" value="5" onchange="actualizarEstiloDias()" />
                <span class="day-check-box">✓</span>
                <span class="day-check-name">Viernes</span>
              </label>
              <label class="day-check-card">
                <input type="checkbox" class="chk-dia-horario" value="6" onchange="actualizarEstiloDias()" />
                <span class="day-check-box">✓</span>
                <span class="day-check-name">Sábado</span>
              </label>
              <label class="day-check-card">
                <input type="checkbox" class="chk-dia-horario" value="7" onchange="actualizarEstiloDias()" />
                <span class="day-check-box">✓</span>
                <span class="day-check-name">Domingo</span>
              </label>
            </div>
          </div>

          <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
            <div class="form-group">
              <label class="form-label" for="horHoraInicio">HORA DE INICIO *</label>
              <input
                type="time"
                id="horHoraInicio"
                class="form-control"
                required
              />
            </div>

            <div class="form-group">
              <label class="form-label" for="horHoraFin">HORA DE FIN *</label>
              <input
                type="time"
                id="horHoraFin"
                class="form-control"
                required
              />
            </div>
          </div>

          <p style="font-size: 0.78rem; color: var(--text-muted); margin-top: 4px;">
            * La <strong>Hora de Fin</strong> debe ser posterior a la <strong>Hora de Inicio</strong> del turno.
          </p>
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
          <p class="modal-subtitle" style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px; font-weight: 400;">Vincula un sector de entrega al repartidor y define la prioridad de la ruta</p>
        </div>
      </div>

      <form id="formZonaAsignacion" onsubmit="guardarZonaAsignacion(event)">
        <div class="modal-body">
          <!-- Llaves originales para edición -->
          <input type="hidden" id="zonOrigRepartidorId" value="" />
          <input type="hidden" id="zonOrigZonaId" value="" />

          <?php if (empty($repartidores) || empty($zonasDelivery)): ?>
            <div style="background: #fff8eb; border: 1px solid #f0d8a8; border-radius: 6px; padding: 12px 14px; margin-bottom: 14px; font-size: 0.84rem; color: #7a5216;">
              ⚠️ <strong>Faltan datos requeridos:</strong>
              <?php if (empty($repartidores)): ?>
                <div>• Registra al menos un repartidor en <a href="../../Panel de configuracion/personal/personal.php?tab=repartidores" style="color: var(--accent-gold); font-weight: 700;">Personal y Repartidores</a>.</div>
              <?php endif; ?>
              <?php if (empty($zonasDelivery)): ?>
                <div>• Registra al menos una zona en <a href="../../Panel de configuracion/zonas-delivery/zonas-delivery.php" style="color: var(--accent-gold); font-weight: 700;">Zonas Delivery</a>.</div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="form-group">
            <label class="form-label" for="zonRepartidorId">REPARTIDOR *</label>
            <select id="zonRepartidorId" class="form-control" required>
              <option value="">-- Seleccione el Repartidor --</option>
              <?php foreach ($repartidores as $rep): ?>
                <option value="<?= htmlspecialchars($rep['empleado_id']) ?>">
                  <?= htmlspecialchars($rep['nombre_razon_social']) ?> (<?= htmlspecialchars($rep['codigo_empleado']) ?> • <?= htmlspecialchars($rep['tipo_vehiculo']) ?> • Cap: <?= intval($rep['capacidad_pedidos']) ?> ped.)
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
                  #<?= intval($z['id']) ?> - <?= htmlspecialchars($z['nombre']) ?> (RD$ <?= number_format((float)$z['tarifa_envio'], 2) ?> • ~<?= intval($z['tiempo_promedio_min']) ?> min<?= !$esActivaZ ? ' [INACTIVA]' : '' ?>)
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

  <!-- TOAST NOTIFICACIÓN -->
  <div class="toast" id="toast">Operación exitosa</div>

  <!-- COLECCIONES INYECTADAS DESDE POSTGRESQL -->
  <div
    id="datosDelivery"
    data-horarios="<?= htmlspecialchars(json_encode($horarios, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
    data-zonas-asignadas="<?= htmlspecialchars(json_encode($zonasAsignadas, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
    style="display: none;"
  ></div>

  <script src="delivery.js?v=2"></script>
</body>
</html>

