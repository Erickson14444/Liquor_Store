<?php
/**
 * ============================================================================
 * Módulo 4: Ventas • Proceso 1: Control de Caja (Vista Principal Dual RBAC)
 * Ubicación: c:\xampp\htdocs\Liquor_Store\Ventas y Facturacion\ControlCaja\control_caja.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

$usuarioActualId = requerirSesionActiva(false, '../../Login y Menu/login.php');

// ============================================================================
// CONTROL DE ACCESO (RBAC):
//   - Cajero (ABRIR_Y_CERRAR_CAJA): puede abrir su propio turno con PIN de supervisor
//   - Supervisor / Admin (HACER_CUADRE): asigna turnos y audita arqueos ciegos
// ============================================================================
if (function_exists('usuarioPuedeAccederControlCaja') && !usuarioPuedeAccederControlCaja()) {
  header("Location: ../../Login y Menu/menu.php?error=" . urlencode("Acceso restringido: no posees permisos para acceder a Control de Caja."));
  exit;
}

$puedeAuditarCaja = function_exists('usuarioPuedeAuditarCaja') ? usuarioPuedeAuditarCaja() : true;
$cajasPos = [];
$empleadosCajeros = [];
$historialSesiones = [];
$cajeroYaTieneTurno = false;
$miCajaActivaNombre = '';
$supervisoresAutorizados = ($pdo && function_exists('obtenerSupervisoresAutorizados')) ? obtenerSupervisoresAutorizados($pdo) : [];

if ($pdo) {
  try {
    // Sincronizar automáticamente cajas físicas sin sesión activa
    $pdo->exec("
            UPDATE ventas.cajas_pos
            SET estado = 'CERRADA'
            WHERE id NOT IN (SELECT caja_id FROM ventas.sesiones_caja WHERE estado IN ('ABIERTA', 'EN_CUADRE'))
              AND estado <> 'CERRADA'
        ");

    // 1. Terminales físicas de caja con su sesión activa (incluyendo s.cajero_id para validar titularidad)
    $stmtCajas = $pdo->query("
            SELECT cp.id,
                   cp.nombre_equipo,
                   CASE
                       WHEN s.estado = 'EN_CUADRE' THEN 'EN_ARQUEO'
                       WHEN s.estado = 'ABIERTA' THEN 'ABIERTA'
                       ELSE 'CERRADA'
                   END AS estado,
                   cp.ubicacion,
                   s.id AS sesion_activa_id,
                   s.cajero_id AS sesion_cajero_id,
                   s.monto_inicial_efectivo,
                   TO_CHAR(s.fecha_apertura, 'DD/MM/YYYY HH12:MI AM') AS fecha_apertura_fmt,
                   s.estado AS sesion_estado,
                   p.nombre_razon_social AS cajero_en_turno,
                   e.codigo_empleado
            FROM ventas.cajas_pos cp
            LEFT JOIN ventas.sesiones_caja s ON cp.id = s.caja_id AND s.estado IN ('ABIERTA', 'EN_CUADRE')
            LEFT JOIN seguridad.usuarios u ON s.cajero_id = u.usuario_id
            LEFT JOIN identidad.personas p ON u.persona_id = p.persona_id
            LEFT JOIN identidad.empleados e ON e.persona_id = p.persona_id
            ORDER BY cp.id ASC
        ");
    $cajasPos = $stmtCajas->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($cajasPos as $cpChk) {
      if (!empty($cpChk['sesion_activa_id']) && (string) ($cpChk['sesion_cajero_id'] ?? '') === $usuarioActualId) {
        $cajeroYaTieneTurno = true;
        $miCajaActivaNombre = (string) ($cpChk['nombre_equipo'] ?? 'Caja');
        break;
      }
    }

    // 2 y 3. Solo cargar lista de cajeros y auditoría histórica si tiene permiso de supervisión
    if ($puedeAuditarCaja) {
      $stmtEmp = $pdo->query("
                SELECT u.usuario_id AS cajero_usuario_id,
                       e.empleado_id,
                       e.codigo_empleado,
                       p.nombre_razon_social,
                       p.documento_identidad,
                       e.cargo,
                       string_agg(DISTINCT r.nombre, ' / ') AS roles_nombres
                FROM seguridad.usuarios u
                JOIN identidad.personas p ON u.persona_id = p.persona_id
                LEFT JOIN identidad.empleados e ON e.persona_id = p.persona_id
                JOIN seguridad.usuarios_roles ur ON u.usuario_id = ur.usuario_id
                JOIN seguridad.roles r ON ur.rol_id = r.rol_id
                WHERE u.estado = 'ACTIVO'
                  AND p.estado = TRUE
                  AND u.usuario_id NOT IN (
                      SELECT sc.cajero_id
                      FROM ventas.sesiones_caja sc
                      WHERE sc.estado IN ('ABIERTA', 'EN_CUADRE')
                  )
                GROUP BY u.usuario_id, e.empleado_id, e.codigo_empleado, p.nombre_razon_social, p.documento_identidad, e.cargo
                HAVING bool_or(LOWER(COALESCE(r.nombre, '')) = 'cajero' OR LOWER(COALESCE(e.cargo, '')) LIKE '%cajer%')
                ORDER BY p.nombre_razon_social ASC
            ");
      $empleadosCajeros = $stmtEmp->fetchAll(PDO::FETCH_ASSOC) ?: [];

      $stmtHist = $pdo->query("
                SELECT s.id,
                       s.caja_id,
                       s.cajero_id,
                       s.monto_inicial_efectivo,
                       s.estado,
                       TO_CHAR(s.fecha_apertura, 'DD/MM/YYYY HH12:MI AM') AS fecha_apertura_fmt,
                       TO_CHAR(s.fecha_cierre, 'DD/MM/YYYY HH12:MI AM')   AS fecha_cierre_fmt,
                       s.monto_efectivo_esperado,
                       s.monto_efectivo_declarado,
                       s.diferencia_descuadre,
                       cp.nombre_equipo,
                       p.nombre_razon_social AS cajero_nombre,
                       e.codigo_empleado
                FROM ventas.sesiones_caja s
                JOIN ventas.cajas_pos cp ON s.caja_id = cp.id
                JOIN seguridad.usuarios u ON s.cajero_id = u.usuario_id
                JOIN identidad.personas p ON u.persona_id = p.persona_id
                LEFT JOIN identidad.empleados e ON e.persona_id = p.persona_id
                ORDER BY s.id DESC
                LIMIT 60
            ");
      $historialSesiones = $stmtHist->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  } catch (Throwable $e) {
    $errorDb = $e->getMessage();
  }
}

// Contadores rápidos
$totalCajas = count($cajasPos);
$cajasAbiertas = 0;
$cajasEnCuadre = 0;
$faltantesDetectados = 0;
$totalFaltantesDinero = 0.00;

foreach ($cajasPos as $c) {
  if ($c['estado'] === 'ABIERTA')
    $cajasAbiertas++;
  if ($c['estado'] === 'EN_ARQUEO')
    $cajasEnCuadre++;
}

foreach ($historialSesiones as $h) {
  $dif = floatval($h['diferencia_descuadre'] ?? 0);
  if ($dif < 0) {
    $faltantesDetectados++;
    $totalFaltantesDinero += abs($dif);
  }
}

if (!function_exists('obtenerInicialesCajero')) {
  function obtenerInicialesCajero($nombre)
  {
    $partes = preg_split('/\s+/', trim((string) $nombre));
    $in = '';
    if (!empty($partes[0]))
      $in .= mb_substr($partes[0], 0, 1, 'UTF-8');
    if (isset($partes[1]) && !empty($partes[1]))
      $in .= mb_substr($partes[1], 0, 1, 'UTF-8');
    return strtoupper($in ?: 'CJ');
  }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Control de Caja y Arqueo Ciego</title>
  <link rel="stylesheet" href="../../Panel de configuracion/styles.css?v=21" />
  <style>
    .pos-card-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(330px, 1fr));
      gap: 18px;
      margin-bottom: 26px;
    }

    .pos-box-card {
      background: #ffffff;
      border: 1px solid #e7ded3;
      border-radius: 14px;
      padding: 20px;
      box-shadow: 0 3px 12px rgba(33, 24, 19, 0.04);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      position: relative;
      overflow: hidden;
      transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .pos-box-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(33, 24, 19, 0.08);
      border-color: #c89d5c;
    }

    .pos-box-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: #d8c8b8;
    }

    .pos-box-card.abierta::before {
      background: linear-gradient(90deg, #2e7d32, #4caf50);
    }

    .pos-box-card.cerrada::before {
      background: #9c8e82;
    }

    .pos-box-card.en_cuadre::before,
    .pos-box-card.en_arqueo::before {
      background: linear-gradient(90deg, #b25e09, #f59e0b);
    }

    .pos-card-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 14px;
    }

    .pos-terminal-info {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .pos-icon-badge {
      width: 42px;
      height: 42px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      background: #fbf7f0;
      border: 1px solid #ebdccb;
      flex-shrink: 0;
    }

    .pos-box-card.abierta .pos-icon-badge {
      background: #e8f5e9;
      border-color: #c8e6c9;
    }

    .pos-box-card.en_cuadre .pos-icon-badge,
    .pos-box-card.en_arqueo .pos-icon-badge {
      background: #fff3e0;
      border-color: #ffe082;
    }

    .pos-terminal-titles h3 {
      font-size: 1.1rem;
      font-weight: 800;
      color: #211813;
      margin: 0;
    }

    .pos-terminal-titles .pos-loc {
      font-size: 0.75rem;
      color: #8a7b70;
      margin-top: 3px;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .kpi-caja-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 99px;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: 0.4px;
      text-transform: uppercase;
      flex-shrink: 0;
    }

    .pill-abierta {
      background: #e8f5e9;
      color: #1b5e20;
      border: 1px solid #a5d6a7;
    }

    .pill-cerrada {
      background: #f5f2ed;
      color: #6e645a;
      border: 1px solid #ddd5c9;
    }

    .pill-en-cuadre,
    .pill-en-arqueo {
      background: #fff3e0;
      color: #b25e09;
      border: 1px solid #ffe082;
    }

    .pulse-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #2e7d32;
      box-shadow: 0 0 0 rgba(46, 125, 50, 0.4);
      animation: pulse-green 1.8s infinite;
      display: inline-block;
    }

    @keyframes pulse-green {
      0% {
        box-shadow: 0 0 0 0 rgba(46, 125, 50, 0.6);
      }

      70% {
        box-shadow: 0 0 0 6px rgba(46, 125, 50, 0);
      }

      100% {
        box-shadow: 0 0 0 0 rgba(46, 125, 50, 0);
      }
    }

    .pos-card-content {
      background: #fdfbf7;
      border: 1px solid #eee5d8;
      border-radius: 10px;
      padding: 13px 15px;
      margin-bottom: 14px;
    }

    .pos-cashier-row {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 10px;
      padding-bottom: 9px;
      border-bottom: 1px dashed #e8dccf;
    }

    .cashier-avatar-mini {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: #211813;
      color: #d8b888;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.78rem;
      font-weight: 800;
      flex-shrink: 0;
    }

    .cashier-meta {
      flex: 1;
      min-width: 0;
    }

    .cashier-name {
      font-weight: 800;
      color: #211813;
      font-size: 0.9rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .cashier-tag {
      font-size: 0.72rem;
      color: #794a15;
      font-weight: 600;
    }

    .pos-stats-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
    }

    .pos-stat-item {
      display: flex;
      flex-direction: column;
    }

    .pos-stat-label {
      font-size: 0.67rem;
      font-weight: 700;
      color: #8a7b70;
      text-transform: uppercase;
      letter-spacing: 0.4px;
    }

    .pos-stat-value {
      font-size: 0.86rem;
      font-weight: 800;
      color: #211813;
      font-family: monospace;
      margin-top: 1px;
    }

    .pos-card-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .badge-veredicto {
      font-size: 0.74rem;
      font-weight: 800;
      padding: 3px 8px;
      border-radius: 4px;
      display: inline-block;
    }

    .modal-backdrop {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(20, 14, 10, 0.65);
      backdrop-filter: blur(4px);
      z-index: 99999;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
      box-sizing: border-box;
    }

    .modal-content {
      background: #ffffff;
      border-radius: 12px;
      box-shadow: 0 20px 48px rgba(0, 0, 0, 0.35);
      border: 1px solid #d8b888;
      width: 100%;
      overflow: hidden;
      animation: modalFadeIn 0.2s ease-out;
      box-sizing: border-box;
    }

    @keyframes modalFadeIn {
      from {
        opacity: 0;
        transform: scale(0.96) translateY(-8px);
      }

      to {
        opacity: 1;
        transform: scale(1) translateY(0);
      }
    }

    .modal-header {
      padding: 16px 20px;
      border-bottom: 1px solid #eadecd;
      background: #fdfaf4;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
    }

    .modal-title {
      font-size: 1.15rem;
      font-weight: 700;
      color: #211813;
      margin: 0 0 4px 0;
    }

    .modal-subtitle {
      font-size: 0.8rem;
      color: #8a7b70;
      margin: 0;
    }

    .modal-body {
      padding: 20px;
      box-sizing: border-box;
    }

    .modal-footer {
      padding: 14px 20px;
      border-top: 1px solid #eadecd;
      background: #fdfaf4;
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      box-sizing: border-box;
    }

    .btn-close {
      background: none;
      border: none;
      font-size: 1.6rem;
      line-height: 1;
      color: #8a7b70;
      cursor: pointer;
      padding: 0 4px;
    }

    .btn-close:hover {
      color: #211813;
    }

    .veredicto-exacto {
      background: #e8f5e9;
      color: #2e7d32;
      border: 1px solid #a5d6a7;
    }

    .veredicto-faltante {
      background: #ffebee;
      color: #c62828;
      border: 1px solid #ef9a9a;
    }

    .veredicto-sobrante {
      background: #fff8e1;
      color: #b25e09;
      border: 1px solid #ffe082;
    }
  </style>
</head>

<body>

  <!-- BARRA SUPERIOR UNIFICADA -->
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
        <div class="user-avatar"><?= htmlspecialchars($sesionAvatarInitials ?? 'AD') ?></div>
        <div class="user-info-col">
          <div class="user-name"><?= htmlspecialchars($sesionNombreCompleto ?? 'Usuario') ?></div>
          <div class="user-sub-line">
            <span class="user-role-text"><?= htmlspecialchars($sesionRol ?? 'Empleado') ?></span>
            <span class="user-bullet">•</span>
            <?php if (function_exists('renderizarPillCajaTopbar')): ?>
              <?= renderizarPillCajaTopbar() ?>
            <?php else: ?>
              <span class="user-caja-pill open"><span class="pill-dot green"></span> Caja
                0<?= htmlspecialchars((string) ($sesionCajaId ?? 1)) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="app-container">
    <?php include __DIR__ . '/../../sidebar.php'; ?>

    <main class="main-content">

      <!-- ENCABEZADO DE PÁGINA -->
      <div class="page-header">
        <div>
          <h1 class="page-title">
            <?= $puedeAuditarCaja ? 'Control de Caja y Arqueo Ciego' : 'Apertura de Turno de Caja' ?></h1>
          <p class="page-subtitle">
            <?= $puedeAuditarCaja
              ? 'Asignación operativa de turnos a terminales POS, fondo inicial (menudo), control de disponibilidad y auditoría de arqueos ciegos.'
              : 'Selecciona una terminal disponible y solicita a tu supervisor validar tu fondo inicial con su usuario y PIN para comenzar a facturar.' ?>
          </p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center;">
          <a href="../Facturacion/facturacion.php" class="btn btn-gold" style="text-decoration: none;">
            🛒 Ir a Facturación (POS)
          </a>
        </div>
      </div>

      <?php if (!$puedeAuditarCaja && $cajeroYaTieneTurno): ?>
        <div
          style="background: #e8f5e9; border: 1px solid #a5d6a7; color: #1b5e20; padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 0.88rem; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
          <span>✅ <strong>Tu turno ya está activo en <?= htmlspecialchars($miCajaActivaNombre) ?>.</strong> Puedes
            facturar normalmente y al finalizar tu jornada presiona <strong>🔒 Finalizar Turno</strong> en
            Facturación.</span>
          <a href="../Facturacion/facturacion.php" class="btn btn-gold btn-sm" style="text-decoration: none;">Abrir
            Facturación →</a>
        </div>
      <?php endif; ?>

      <!-- TARJETAS INDICADORAS DE ESTADO -->
      <div
        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; margin-bottom: 22px;">
        <div class="card" style="padding: 14px 18px;">
          <div style="font-size: 0.74rem; font-weight: 700; color: #8a7b70; text-transform: uppercase;">Terminales POS
            Totales</div>
          <div style="font-size: 1.5rem; font-weight: 800; font-family: monospace; color: #211813;"><?= $totalCajas ?>
          </div>
          <div style="font-size: 0.74rem; color: #8a7b70;">Cajas físicas registradas</div>
        </div>

        <div class="card" style="padding: 14px 18px;">
          <div style="font-size: 0.74rem; font-weight: 700; color: #2e7d32; text-transform: uppercase;">Turnos Abiertos
            Hoy</div>
          <div style="font-size: 1.5rem; font-weight: 800; font-family: monospace; color: #2e7d32;">
            <?= $cajasAbiertas ?></div>
          <div style="font-size: 0.74rem; color: #8a7b70;">Cajeros operando en vivo</div>
        </div>

        <div class="card" style="padding: 14px 18px;">
          <div style="font-size: 0.74rem; font-weight: 700; color: #b25e09; text-transform: uppercase;">Terminales en
            Arqueo</div>
          <div style="font-size: 1.5rem; font-weight: 800; font-family: monospace; color: #b25e09;">
            <?= $cajasEnCuadre ?></div>
          <div style="font-size: 0.74rem; color: #8a7b70;">En proceso de cuadre ciego</div>
        </div>

        <?php if ($puedeAuditarCaja): ?>
          <div class="card" style="padding: 14px 18px;">
            <div style="font-size: 0.74rem; font-weight: 700; color: #c62828; text-transform: uppercase;">Faltantes en
              Arqueo</div>
            <div style="font-size: 1.5rem; font-weight: 800; font-family: monospace; color: #c62828;">
              <?= $faltantesDetectados ?></div>
            <div style="font-size: 0.74rem; color: #8a7b70;">RD$ <?= number_format($totalFaltantesDinero, 2) ?> acumulados
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- SECCIÓN 1: MATRIZ DE TERMINALES POS -->
      <div style="margin-bottom: 26px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
          <h2
            style="font-size: 1.15rem; font-weight: 700; color: #211813; margin: 0; display: flex; align-items: center; gap: 8px;">
            <span>💻</span> Terminales Físicas y Turnos en Vivo
          </h2>
          <span style="font-size: 0.8rem; color: #8a7b70;">
            <?= $puedeAuditarCaja ? 'Abre o gestiona turnos directamente desde cada terminal' : 'Elige una terminal cerrada para solicitar apertura con tu supervisor' ?>
          </span>
        </div>

        <div class="pos-card-grid">
          <?php if (empty($cajasPos)): ?>
            <div class="card" style="padding: 30px; text-align: center; color: #8a7b70; grid-column: span 3;">
              No hay cajas físicas registradas.
            </div>
          <?php else: ?>
            <?php foreach ($cajasPos as $cp):
              $estado = $cp['estado'] ?? 'CERRADA';
              $estClase = strtolower($estado);
              $pillClase = 'pill-' . str_replace('_', '-', $estClase);
              $esAbierta = ($estado === 'ABIERTA' && !empty($cp['sesion_activa_id']));
              $esEnCuadre = ($estado === 'EN_ARQUEO' || $estado === 'EN_CUADRE');
              $esMiTurno = (!empty($cp['sesion_activa_id']) && (string) ($cp['sesion_cajero_id'] ?? '') === $usuarioActualId);
              ?>
              <div class="pos-box-card <?= htmlspecialchars($estClase) ?>">
                <div>
                  <div class="pos-card-header">
                    <div class="pos-terminal-info">
                      <div class="pos-icon-badge">
                        <?= $esAbierta ? '🖥️' : ($esEnCuadre ? '⏳' : '🔒') ?>
                      </div>
                      <div class="pos-terminal-titles">
                        <h3><?= htmlspecialchars($cp['nombre_equipo']) ?></h3>
                        <div class="pos-loc">
                          <span>📍</span> <?= htmlspecialchars($cp['ubicacion'] ?: 'Mostrador Principal') ?>
                        </div>
                      </div>
                    </div>

                    <span class="kpi-caja-pill <?= $pillClase ?>">
                      <?php if ($esAbierta): ?>
                        <span class="pulse-dot"></span> <?= $esMiTurno ? 'MI TURNO' : 'ABIERTA' ?>
                      <?php elseif ($esEnCuadre): ?>
                        ⏳ EN ARQUEO
                      <?php else: ?>
                        CERRADA
                      <?php endif; ?>
                    </span>
                  </div>

                  <div class="pos-card-content">
                    <?php if ($esAbierta): ?>
                      <div class="pos-cashier-row">
                        <div class="cashier-avatar-mini">
                          <?= htmlspecialchars(obtenerInicialesCajero($cp['cajero_en_turno'])) ?></div>
                        <div class="cashier-meta">
                          <div class="cashier-name" title="<?= htmlspecialchars($cp['cajero_en_turno']) ?>">
                            <?= htmlspecialchars($cp['cajero_en_turno']) ?></div>
                          <div class="cashier-tag"><?= htmlspecialchars($cp['codigo_empleado'] ?: 'CAJERO') ?> • Turno Activo
                          </div>
                        </div>
                      </div>

                      <div class="pos-stats-grid">
                        <div class="pos-stat-item">
                          <span class="pos-stat-label">Hora de Inicio</span>
                          <span class="pos-stat-value"
                            style="font-size: 0.8rem;"><?= htmlspecialchars($cp['fecha_apertura_fmt']) ?></span>
                        </div>
                        <div class="pos-stat-item" style="text-align: right;">
                          <span class="pos-stat-label">Fondo de Menudo</span>
                          <span class="pos-stat-value" style="color: #2e7d32;">
                            <?= ($puedeAuditarCaja || $esMiTurno) ? ('RD$ ' . number_format((float) $cp['monto_inicial_efectivo'], 2)) : 'Privado' ?>
                          </span>
                        </div>
                      </div>

                    <?php elseif ($esEnCuadre): ?>
                      <div style="display: flex; gap: 10px; align-items: flex-start;">
                        <span style="font-size: 1.3rem;">⚠️</span>
                        <div>
                          <div style="font-weight: 800; color: #b25e09; font-size: 0.88rem;">Arqueo Ciego en Curso</div>
                          <div style="font-size: 0.76rem; color: #8a7b70; margin-top: 2px;">
                            Cajero <strong><?= htmlspecialchars($cp['cajero_en_turno']) ?></strong> está realizando el conteo
                            físico de gaveta.
                          </div>
                        </div>
                      </div>

                    <?php else: ?>
                      <div style="display: flex; gap: 10px; align-items: center; color: #8a7b70;">
                        <span style="font-size: 1.25rem; opacity: 0.75;">✨</span>
                        <div>
                          <div style="font-weight: 700; color: #5a4f46; font-size: 0.85rem;">Terminal Disponible</div>
                          <div style="font-size: 0.75rem; color: #9c8e82; margin-top: 2px;">
                            <?= $puedeAuditarCaja
                              ? 'Sin turno activo. Asigna un cajero y fondo para iniciar facturación.'
                              : 'Sin turno activo. Solicita apertura con el usuario y PIN de tu supervisor.' ?>
                          </div>
                        </div>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- ACCIONES INFERIORES -->
                <div class="pos-card-actions">
                  <?php if ($cp['estado'] === 'CERRADA' || empty($cp['sesion_activa_id'])): ?>
                    <?php if ($puedeAuditarCaja): ?>
                      <button type="button" class="btn btn-gold btn-sm"
                        style="width: 100%; justify-content: center; font-weight: 700; padding: 9px 14px;"
                        onclick="abrirModalApertura(<?= (int) $cp['id'] ?>, '<?= htmlspecialchars(addslashes($cp['nombre_equipo']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($cp['ubicacion'] ?: 'Mostrador Principal'), ENT_QUOTES) ?>')">
                        🔓 Asignar Cajero y Abrir
                      </button>
                    <?php elseif (!$cajeroYaTieneTurno): ?>
                      <button type="button" class="btn btn-gold btn-sm"
                        style="width: 100%; justify-content: center; font-weight: 700; padding: 9px 14px;"
                        onclick="abrirModalApertura(<?= (int) $cp['id'] ?>, '<?= htmlspecialchars(addslashes($cp['nombre_equipo']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($cp['ubicacion'] ?: 'Mostrador Principal'), ENT_QUOTES) ?>')">
                        🔐 Abrir mi Turno (PIN Supervisor)
                      </button>
                    <?php else: ?>
                      <div
                        style="width: 100%; text-align: center; font-size: 0.76rem; color: #8a7b70; font-weight: 600; padding: 6px;">
                        Ya posees un turno abierto en <?= htmlspecialchars($miCajaActivaNombre) ?>
                      </div>
                    <?php endif; ?>

                  <?php elseif ($esAbierta): ?>
                    <?php if ($puedeAuditarCaja || $esMiTurno): ?>
                      <button type="button" class="btn btn-beige btn-sm"
                        style="flex: 1; justify-content: center; font-weight: 700; padding: 9px 12px; border: 1px solid #d8b888; background: #fffcf7;"
                        onclick="abrirModalCajaChica(<?= (int) $cp['sesion_activa_id'] ?>, '<?= htmlspecialchars(addslashes($cp['nombre_equipo']), ENT_QUOTES) ?>')">
                        💸 Caja Chica
                      </button>
                      <?php if ($esMiTurno): ?>
                        <a href="../Facturacion/facturacion.php" class="btn btn-gold btn-sm"
                          style="flex: 1; justify-content: center; text-decoration: none; font-weight: 700; padding: 9px 12px;">
                          🛒 Facturar
                        </a>
                      <?php endif; ?>
                    <?php else: ?>
                      <div
                        style="width: 100%; text-align: center; font-size: 0.76rem; color: #8a7b70; font-weight: 600; padding: 6px;">
                        🔒 Terminal ocupada por otro cajero
                      </div>
                    <?php endif; ?>

                  <?php elseif ($esEnCuadre): ?>
                    <div
                      style="width: 100%; text-align: center; font-size: 0.75rem; color: #b25e09; font-weight: 700; padding: 6px;">
                      🔒 Terminal bloqueada por arqueo
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- SECCIÓN 2: AUDITORÍA DE TURNOS Y ARQUEOS CIEGOS (SOLO SUPERVISOR / ADMIN) -->
      <?php if ($puedeAuditarCaja): ?>
        <div class="table-card">
          <div class="table-card-top">
            <div class="table-card-info">
              <h2 class="table-card-title">Auditoría de Turnos y Arqueos Ciegos</h2>
              <span class="badge-count"><?= count($historialSesiones) ?> turnos</span>
            </div>
            <div style="font-size: 0.8rem; color: #8a7b70;">
              Veredicto automático de descuadre: monto esperado vs dinero declarado
            </div>
          </div>

          <div class="table-wrapper">
            <table class="data-table">
              <thead>
                <tr>
                  <th>SESIÓN / TERMINAL</th>
                  <th>CAJERO ASIGNADO</th>
                  <th>APERTURA / FONDO</th>
                  <th>CIERRE DEL TURNO</th>
                  <th style="text-align: right;">EFECTIVO ESPERADO</th>
                  <th style="text-align: right;">EFECTIVO DECLARADO</th>
                  <th style="text-align: center;">VEREDICTO (DIFERENCIA)</th>
                  <th style="text-align: right; padding-right: 18px;">ACCIONES</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($historialSesiones)): ?>
                  <tr>
                    <td colspan="8" style="text-align: center; padding: 30px; color: #8a7b70;">
                      No hay sesiones registradas todavía. Abre un turno desde una terminal superior para iniciar.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($historialSesiones as $h):
                    $dif = floatval($h['diferencia_descuadre'] ?? 0);
                    $difClase = 'veredicto-exacto';
                    $difTxt = '🟢 EXACTO (RD$ 0.00)';
                    if ($h['estado'] === 'ABIERTA') {
                      $difTxt = '⏳ En Curso';
                      $difClase = '';
                    } elseif ($h['estado'] === 'EN_CUADRE') {
                      $difTxt = '🟠 En Arqueo';
                      $difClase = '';
                    } elseif ($dif < 0) {
                      $difClase = 'veredicto-faltante';
                      $difTxt = '🔴 FALTANTE: - RD$ ' . number_format(abs($dif), 2);
                    } elseif ($dif > 0) {
                      $difClase = 'veredicto-sobrante';
                      $difTxt = '🟡 SOBRANTE: + RD$ ' . number_format($dif, 2);
                    }
                    ?>
                    <tr>
                      <td>
                        <div style="font-weight: 700; color: #211813;">Sesión #<?= (int) $h['id'] ?></div>
                        <div style="font-size: 0.75rem; color: #8a7b70;"><?= htmlspecialchars($h['nombre_equipo']) ?></div>
                      </td>
                      <td>
                        <div style="font-weight: 600; color: #211813;"><?= htmlspecialchars($h['cajero_nombre']) ?></div>
                        <div style="font-size: 0.74rem; color: #8a7b70;"><?= htmlspecialchars($h['codigo_empleado']) ?></div>
                      </td>
                      <td>
                        <div style="font-size: 0.82rem; font-weight: 600;"><?= htmlspecialchars($h['fecha_apertura_fmt']) ?>
                        </div>
                        <div style="font-size: 0.76rem; color: #794a15; font-weight: 700;">Fondo: RD$
                          <?= number_format((float) $h['monto_inicial_efectivo'], 2) ?></div>
                      </td>
                      <td>
                        <div style="font-size: 0.82rem; color: #211813;">
                          <?= htmlspecialchars($h['fecha_cierre_fmt'] ?: 'En curso...') ?></div>
                        <div style="font-size: 0.74rem; color: #8a7b70;">Estado:
                          <strong><?= htmlspecialchars($h['estado']) ?></strong></div>
                      </td>
                      <td style="text-align: right; font-family: monospace; font-size: 0.95rem; font-weight: 700;">
                        <?= $h['monto_efectivo_esperado'] !== null ? 'RD$ ' . number_format((float) $h['monto_efectivo_esperado'], 2) : '—' ?>
                      </td>
                      <td style="text-align: right; font-family: monospace; font-size: 0.95rem; font-weight: 700;">
                        <?= $h['monto_efectivo_declarado'] !== null ? 'RD$ ' . number_format((float) $h['monto_efectivo_declarado'], 2) : '—' ?>
                      </td>
                      <td style="text-align: center;">
                        <?php if (!empty($difClase)): ?>
                          <span class="badge-veredicto <?= $difClase ?>"><?= $difTxt ?></span>
                        <?php else: ?>
                          <span style="font-size: 0.78rem; font-weight: 700; color: #b25e09;"><?= $difTxt ?></span>
                        <?php endif; ?>
                      </td>
                      <td style="text-align: right; white-space: nowrap;">
                        <button type="button" class="btn btn-beige btn-sm" onclick="verDesgloseArqueo(<?= (int) $h['id'] ?>)">
                          🔍 Ver Desglose
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

    </main>
  </div>

  <!-- ======================================================================== -->
  <!-- MODAL 1: APERTURA DE TURNO (DUAL: SUPERVISOR ELIGE / CAJERO PIDE PIN)    -->
  <!-- ======================================================================== -->
  <div id="modalAperturaTurno" class="modal-backdrop" style="display: none;">
    <div class="modal-content" style="max-width: 500px;">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">
            <?= $puedeAuditarCaja ? 'Asignar Caja y Apertura de Turno' : 'Abrir Turno con Autorización de Supervisor' ?>
          </h3>
          <p class="modal-subtitle">
            <?= $puedeAuditarCaja
              ? 'Asigna una terminal física al cajero y declara su fondo inicial (menudo)'
              : 'Confirma el fondo inicial recibido y solicita al supervisor digitar su usuario y PIN' ?>
          </p>
        </div>
        <button type="button" class="btn-close" onclick="cerrarModalApertura()">&times;</button>
      </div>

      <form id="formAperturaTurno" onsubmit="guardarAperturaTurno(event)">
        <input type="hidden" id="apertura_caja_id" name="caja_id" value="" required />

        <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">

          <div
            style="background: #fbf7f0; border: 1px solid #ebdccb; border-radius: 10px; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 10px;">
              <span style="font-size: 1.4rem;">💻</span>
              <div>
                <div style="font-size: 0.68rem; font-weight: 700; color: #8a7b70; text-transform: uppercase;">Terminal a
                  Iniciar</div>
                <div id="lblModalCajaNombre" style="font-size: 1.05rem; font-weight: 800; color: #211813;">Caja POS
                </div>
              </div>
            </div>
            <span id="lblModalCajaUbicacion"
              style="font-size: 0.74rem; font-weight: 700; color: #794a15; background: #f3e5d3; padding: 3px 9px; border-radius: 99px;">
              Mostrador Principal
            </span>
          </div>

          <?php if ($puedeAuditarCaja): ?>
            <!-- MODO SUPERVISOR / ADMIN: selecciona cualquier cajero disponible -->
            <div class="form-group" style="margin: 0;">
              <label class="form-label" for="apertura_cajero_id">EMPLEADO / CAJERO ASIGNADO *</label>
              <select id="apertura_cajero_id" name="cajero_id" class="form-control" required <?= empty($empleadosCajeros) ? 'disabled' : '' ?>>
                <?php if (empty($empleadosCajeros)): ?>
                  <option value="" disabled selected>⚠️ No hay cajeros disponibles (todos tienen turno abierto)</option>
                <?php else: ?>
                  <option value="">-- Seleccione el Cajero --</option>
                  <?php foreach ($empleadosCajeros as $emp): ?>
                    <option value="<?= htmlspecialchars($emp['cajero_usuario_id']) ?>">
                      <?= htmlspecialchars($emp['nombre_razon_social']) ?> (<?= htmlspecialchars($emp['codigo_empleado']) ?> •
                      <?= htmlspecialchars($emp['roles_nombres'] ?: 'Cajero') ?>)
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
              <small style="color: #8a7b70; font-size: 0.74rem; display: block; margin-top: 4px;">Solo se listan cajeros
                que no tienen una caja asignada actualmente.</small>
            </div>
          <?php else: ?>
            <!-- MODO CAJERO: bloqueado a sí mismo + autorización obligatoria Supervisor + PIN -->
            <input type="hidden" name="cajero_id" value="<?= htmlspecialchars($usuarioActualId) ?>" />
            <div
              style="background: #eef5ee; border: 1px solid #c3dec4; border-radius: 8px; padding: 10px 12px; font-size: 0.86rem; color: #1b5e20;">
              👤 <strong>Cajero titular del turno:</strong> <?= htmlspecialchars($sesionNombreCompleto ?? 'Cajero') ?>
            </div>
          <?php endif; ?>

          <!-- FONDO INICIAL (MENUDO) -->
          <div class="form-group" style="margin: 0;">
            <label class="form-label" for="apertura_monto_inicial">FONDO INICIAL EN EFECTIVO (MENUDO RD$) *</label>
            <input type="number" step="0.01" min="0" id="apertura_monto_inicial" name="monto_inicial_efectivo"
              class="form-control" style="font-size: 1.15rem; font-weight: 800; font-family: monospace;"
              placeholder="Ej. 2500.00" value="2500.00" required />
            <small style="color: #8a7b70; font-size: 0.74rem; display: block; margin-top: 4px;">Efectivo entregado para
              dar cambio a los clientes.</small>
          </div>

          <?php if (!$puedeAuditarCaja): ?>
            <div style="background: #fff8e1; border: 1.5px dashed #ffe082; padding: 14px; border-radius: 8px;">
              <div style="font-weight: 700; color: #794a15; margin-bottom: 8px; font-size: 0.84rem;">
                🔐 AUTORIZACIÓN OBLIGATORIA DEL SUPERVISOR
              </div>
              <div class="form-group" style="margin-bottom: 10px;">
                <label class="form-label" for="apertura_usuario_supervisor"
                  style="color: #794a15; font-size: 0.76rem; font-weight: 700;">USUARIO DEL SUPERVISOR *</label>
                <input type="text" id="apertura_usuario_supervisor" name="usuario_supervisor" class="form-control"
                  placeholder="Ej. admin o usuario del supervisor" autocomplete="off" required
                  style="background: #fff; font-weight: 600;" />
              </div>
              <div class="form-group" style="margin: 0;">
                <label class="form-label" for="apertura_pin_supervisor"
                  style="color: #794a15; font-size: 0.76rem; font-weight: 700;">PIN DE SEGURIDAD DEL SUPERVISOR *</label>
                <input type="password" maxlength="6" inputmode="numeric" id="apertura_pin_supervisor"
                  name="pin_supervisor" class="form-control" placeholder="••••"
                  style="text-align: center; font-size: 1.35rem; letter-spacing: 6px; background: #fff;"
                  oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);" required />
              </div>
            </div>
          <?php endif; ?>

        </div>

        <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px;">
          <button type="button" class="btn btn-beige" onclick="cerrarModalApertura()">Cancelar</button>
          <button type="submit" id="btnSubmitApertura" class="btn btn-gold" <?= ($puedeAuditarCaja && empty($empleadosCajeros)) ? 'disabled' : '' ?>>
            <?= $puedeAuditarCaja ? 'Abrir Turno' : 'Validar PIN y Abrir Turno' ?>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ======================================================================== -->
  <!-- MODAL 2: ARQUEO CIEGO (CONTEO FÍSICO)                                    -->
  <!-- ======================================================================== -->
  <div id="modalArqueoCiego" class="modal-backdrop" style="display: none;">
    <div class="modal-content" style="max-width: 620px;">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">Arqueo Ciego de Caja (Conteo Físico)</h3>
          <p class="modal-subtitle" id="lblArqueoSesion">Declaración de efectivo y vouchers sin cálculos a la vista</p>
        </div>
        <button type="button" class="btn-close" onclick="cerrarModalArqueo()">&times;</button>
      </div>

      <form id="formArqueoCiego" onsubmit="guardarArqueoCiego(event)">
        <input type="hidden" id="arq_sesion_id" name="sesion_id" value="" />
        <input type="hidden" id="arq_efectivo_declarado" name="efectivo_declarado" value="0.00" />

        <div class="modal-body"
          style="display: flex; flex-direction: column; gap: 14px; max-height: 70vh; overflow-y: auto;">
          <div
            style="background: #fdf5ea; border: 1px dashed #d8b888; border-radius: 8px; padding: 12px; font-size: 0.82rem; color: #794a15;">
            <strong>⚠️ Regla de Arqueo Ciego:</strong> Cuente físicamente todo el dinero en la gaveta y digite las
            cantidades por denominación. El sistema no revela el monto esperado hasta procesar el cierre.
          </div>

          <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <label class="form-label" style="margin: 0;">DESGLOSE DE EFECTIVO DOMINICANO (DOP)</label>
              <div style="font-weight: 800; font-size: 1.15rem; color: #211813; font-family: monospace;"
                id="lblTotalEfectivoDeclarado">RD$ 0.00</div>
            </div>
            <div id="contenedorDenominaciones"
              style="background: #fff; border: 1px solid #eadecd; border-radius: 8px; max-height: 220px; overflow-y: auto;">
            </div>
          </div>

          <div
            style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; background: #fafafa; border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px;">
            <div class="form-group" style="margin: 0;">
              <label class="form-label" for="arq_tarjeta_declarada">MONTO TOTAL VOUCHERS (RD$) *</label>
              <input type="number" step="0.01" min="0" id="arq_tarjeta_declarada" name="tarjeta_declarada"
                class="form-control" value="0.00" required />
            </div>
            <div class="form-group" style="margin: 0;">
              <label class="form-label" for="arq_cantidad_vouchers">CANTIDAD DE VOUCHERS FÍSICOS *</label>
              <input type="number" min="0" step="1" id="arq_cantidad_vouchers" name="cantidad_vouchers"
                class="form-control" value="0" required />
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label class="form-label" for="arq_monto_retenido">MENUDO DEJADO EN CAJA (RD$)</label>
              <input type="number" step="0.01" min="0" id="arq_monto_retenido" name="monto_retenido_fondo"
                class="form-control" value="0.00" />
            </div>
            <div class="form-group">
              <label class="form-label" for="arq_estado_deposito">DESTINO DEL SOBRANTE A BANCO</label>
              <select id="arq_estado_deposito" name="estado_deposito" class="form-control">
                <option value="PENDIENTE">PENDIENTE (A Bóveda Local)</option>
                <option value="DEPOSITADO">DEPOSITADO (Conduce a Banco)</option>
                <option value="RETENIDO">RETENIDO (100% en Gaveta)</option>
              </select>
            </div>
          </div>
        </div>

        <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px;">
          <button type="button" class="btn btn-beige" onclick="cerrarModalArqueo()">Cancelar</button>
          <button type="submit" id="btnSubmitArqueo" class="btn btn-gold">Confirmar y Liquidar Turno</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ======================================================================== -->
  <!-- MODAL 3: MOVIMIENTO DE CAJA CHICA (INGRESO / EGRESO CON PIN)             -->
  <!-- ======================================================================== -->
  <div id="modalCajaChica" class="modal-backdrop" style="display: none;">
    <div class="modal-content" style="max-width: 480px;">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">Movimiento de Caja Chica</h3>
          <p class="modal-subtitle" id="lblMovimientoCaja">Entrada o salida menor autorizada por supervisor</p>
        </div>
        <button type="button" class="btn-close" onclick="cerrarModalCajaChica()">&times;</button>
      </div>

      <form id="formCajaChica" onsubmit="guardarMovimientoCajaChica(event)">
        <input type="hidden" id="mov_sesion_id" name="sesion_id" value="" />

        <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
          <div class="form-group">
            <label class="form-label" for="mov_tipo">TIPO DE MOVIMIENTO *</label>
            <select id="mov_tipo" name="tipo_movimiento" class="form-control" required>
              <option value="EGRESO">EGRESO (Salida: Compra de hielo, agua, insumos)</option>
              <option value="INGRESO">INGRESO (Entrada: Fianza de consignación, refuerzo)</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label" for="mov_monto">MONTO (RD$) *</label>
            <input type="number" step="0.01" min="0.01" id="mov_monto" name="monto" class="form-control"
              placeholder="0.00" style="font-size: 1.1rem; font-weight: 800; font-family: monospace;" required />
          </div>

          <div class="form-group">
            <label class="form-label" for="mov_concepto">CONCEPTO O JUSTIFICACIÓN *</label>
            <input type="text" id="mov_concepto" name="concepto" class="form-control"
              placeholder="Ej. Compra urgente de 10 fundas de hielo" required />
          </div>

          <div class="form-group"
            style="background: #fff8e1; border: 1.5px dashed #ffe082; padding: 14px; border-radius: 8px;">
            <div style="font-weight: 700; color: #794a15; margin-bottom: 8px; font-size: 0.88rem;">🔐 AUTORIZACIÓN DEL
              SUPERVISOR EN TURNO</div>
            <div style="margin-bottom: 10px;">
              <label class="form-label" for="mov_usuario_supervisor"
                style="color: #794a15; font-size: 0.78rem; font-weight: 700;">USUARIO DEL SUPERVISOR *</label>
              <input type="text" id="mov_usuario_supervisor" name="usuario_supervisor" class="form-control"
                placeholder="Ej. admin o usuario del supervisor" autocomplete="off" required
                style="background: #fff; font-weight: 600;" />
            </div>
            <div>
              <label class="form-label" for="mov_pin" style="color: #794a15; font-size: 0.78rem; font-weight: 700;">PIN
                DE SEGURIDAD DEL SUPERVISOR *</label>
              <input type="password" maxlength="6" inputmode="numeric" id="mov_pin" name="pin_supervisor"
                class="form-control" placeholder="••••"
                style="text-align: center; font-size: 1.4rem; letter-spacing: 6px; background: #fff;"
                oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);" required />
            </div>
          </div>
        </div>

        <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px;">
          <button type="button" class="btn btn-beige" onclick="cerrarModalCajaChica()">Cancelar</button>
          <button type="submit" id="btnSubmitMovimiento" class="btn btn-gold">Registrar y Autorizar con PIN</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ======================================================================== -->
  <!-- MODAL 4: DESGLOSE COMPLETO DE AUDITORÍA (SOLO SUPERVISOR / ADMIN)        -->
  <!-- ======================================================================== -->
  <?php if ($puedeAuditarCaja): ?>
    <div id="modalDesgloseArqueo" class="modal-backdrop" style="display: none;">
      <div class="modal-content" style="max-width: 760px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
          <div>
            <h3 class="modal-title">Desglose de Auditoría y Monto Esperado</h3>
            <p class="modal-subtitle" id="desNumSesion">Justificación matemática de los fondos de caja</p>
          </div>
          <button type="button" class="btn-close" onclick="cerrarModalDesglose()">&times;</button>
        </div>

        <div class="modal-body" style="display: flex; flex-direction: column; gap: 16px; font-size: 0.88rem;">
          <div id="desVeredictoCard"
            style="border-radius: 8px; padding: 12px 16px; text-align: center; border: 1px solid #d8b888; background: #fdfaf4;">
            <div style="font-size: 0.72rem; font-weight: 700; color: #8a7b70; text-transform: uppercase;">Veredicto de
              Auditoría</div>
            <div id="desVeredictoTxt" style="font-size: 1.25rem; font-weight: 900; margin-top: 4px;">—</div>
          </div>

          <div
            style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; background: #fafafa; border: 1px solid #e0e0e0; border-radius: 8px; padding: 10px 14px;">
            <div>
              <div style="font-size: 0.72rem; color: #8a7b70; font-weight: 700;">CAJERO</div>
              <div id="desCajero" style="font-weight: 600; color: #211813; font-size: 0.82rem;">—</div>
            </div>
            <div>
              <div style="font-size: 0.72rem; color: #8a7b70; font-weight: 700;">TERMINAL POS</div>
              <div id="desTerminal" style="font-weight: 600; color: #211813; font-size: 0.82rem;">—</div>
            </div>
            <div>
              <div style="font-size: 0.72rem; color: #8a7b70; font-weight: 700;">APERTURA</div>
              <div id="desApertura" style="font-size: 0.78rem; color: #211813;">—</div>
            </div>
            <div>
              <div style="font-size: 0.72rem; color: #8a7b70; font-weight: 700;">CIERRE</div>
              <div id="desCierre" style="font-size: 0.78rem; color: #211813;">—</div>
            </div>
          </div>

          <div>
            <div
              style="font-weight: 700; font-size: 0.9rem; color: #211813; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between;">
              <span>💵 Justificación del Efectivo Físico en Gaveta:</span>
              <span style="font-size: 0.75rem; color: #8a7b70; font-weight: normal;">(Fórmula oficial de arqueo)</span>
            </div>

            <table
              style="width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #eadecd; border-radius: 8px; overflow: hidden; font-size: 0.84rem;">
              <tbody>
                <tr style="border-bottom: 1px solid #f0f0f0;">
                  <td style="padding: 8px 12px;"><strong>(+) Fondo Inicial de Apertura (Menudo)</strong></td>
                  <td
                    style="padding: 8px 12px; text-align: right; font-family: monospace; font-weight: 700; color: #794a15;"
                    id="desFondoInicial">RD$ 0.00</td>
                </tr>
                <tr style="border-bottom: 1px solid #f0f0f0;">
                  <td style="padding: 8px 12px;">(+) Ventas POS en Efectivo <span id="desFacturasCount"
                      style="color: #8a7b70; font-size: 0.74rem;">(0 facturas)</span></td>
                  <td
                    style="padding: 8px 12px; text-align: right; font-family: monospace; font-weight: 700; color: #2e7d32;"
                    id="desVentasEf">RD$ 0.00</td>
                </tr>
                <tr style="border-bottom: 1px solid #f0f0f0;">
                  <td style="padding: 8px 12px;">(+) Cobros de Cuentas por Cobrar (CxC) en Efectivo</td>
                  <td
                    style="padding: 8px 12px; text-align: right; font-family: monospace; font-weight: 700; color: #2e7d32;"
                    id="desCxcEf">RD$ 0.00</td>
                </tr>
                <tr style="border-bottom: 1px solid #f0f0f0;">
                  <td style="padding: 8px 12px;">(+) Fianzas de Consignación en Efectivo</td>
                  <td
                    style="padding: 8px 12px; text-align: right; font-family: monospace; font-weight: 700; color: #2e7d32;"
                    id="desConsignacionesEf">RD$ 0.00</td>
                </tr>
                <tr style="border-bottom: 1px solid #f0f0f0;">
                  <td style="padding: 8px 12px;">(+) Ingresos de Caja Chica (Refuerzos / Entradas Menores)</td>
                  <td
                    style="padding: 8px 12px; text-align: right; font-family: monospace; font-weight: 700; color: #2e7d32;"
                    id="desIngresosCajaChica">RD$ 0.00</td>
                </tr>
                <tr style="border-bottom: 1px solid #f0f0f0;">
                  <td style="padding: 8px 12px; color: #c62828;">(-) Devoluciones a Clientes en Efectivo (Reembolsos de
                    POS)</td>
                  <td
                    style="padding: 8px 12px; text-align: right; font-family: monospace; font-weight: 700; color: #c62828;"
                    id="desDevolucionesEf">- RD$ 0.00</td>
                </tr>
                <tr style="border-bottom: 1px solid #eadecd;">
                  <td style="padding: 8px 12px; color: #c62828;">(-) Egresos Menores de Caja Chica (Hielo, agua, insumos
                    con PIN)</td>
                  <td
                    style="padding: 8px 12px; text-align: right; font-family: monospace; font-weight: 700; color: #c62828;"
                    id="desEgresosCajaChica">- RD$ 0.00</td>
                </tr>
                <tr style="background: #fdfaf4; font-weight: 800; border-bottom: 2px solid #d8b888;">
                  <td style="padding: 10px 12px; font-size: 0.9rem;">(=) TOTAL EFECTIVO ESPERADO POR EL SISTEMA</td>
                  <td
                    style="padding: 10px 12px; text-align: right; font-family: monospace; font-size: 1rem; color: #794a15;"
                    id="desTotalEsperadoEf">RD$ 0.00</td>
                </tr>
                <tr style="background: #fff; font-weight: 800; border-bottom: 1px solid #eadecd;">
                  <td style="padding: 10px 12px;">(-) Total Efectivo Declarado por el Cajero (Arqueo Físico)</td>
                  <td style="padding: 10px 12px; text-align: right; font-family: monospace; font-size: 1rem;"
                    id="desTotalDeclaradoEf">RD$ 0.00</td>
                </tr>
                <tr style="background: #fdf5ea; font-weight: 900;">
                  <td style="padding: 10px 12px; color: #794a15; font-size: 0.92rem;">(=) DIFERENCIA DE CUADRE (DESCUADRE)
                  </td>
                  <td style="padding: 10px 12px; text-align: right; font-family: monospace; font-size: 1.05rem;"
                    id="desDiferenciaEf">RD$ 0.00</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div style="background: #fafafa; border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px 14px;">
            <div style="font-weight: 700; color: #211813; margin-bottom: 6px;">💳 Tarjetas de Crédito / Débito (Vouchers):
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; font-size: 0.82rem;">
              <div>
                <span style="color: #8a7b70;">Esperado en Sistema:</span>
                <div id="desTarjetaEsperada" style="font-family: monospace; font-weight: 700; font-size: 0.95rem;">RD$
                  0.00</div>
              </div>
              <div>
                <span style="color: #8a7b70;">Declarado en Vouchers:</span>
                <div id="desTarjetaDeclarada" style="font-family: monospace; font-weight: 700; font-size: 0.95rem;">RD$
                  0.00</div>
              </div>
              <div>
                <span style="color: #8a7b70;">Vouchers Físicos:</span>
                <div id="desCantVouchers"
                  style="font-family: monospace; font-weight: 700; font-size: 0.95rem; color: #794a15;">0 comprobantes
                </div>
              </div>
            </div>
          </div>

          <div>
            <div style="font-weight: 700; font-size: 0.86rem; color: #211813; margin-bottom: 6px;">📝 Detalle de Gastos
              Menores y Refuerzos de Caja Chica:</div>
            <div style="max-height: 140px; overflow-y: auto; border: 1px solid #eadecd; border-radius: 8px;">
              <table style="width: 100%; border-collapse: collapse; font-size: 0.78rem;">
                <thead style="background: #fdfaf4; position: sticky; top: 0; border-bottom: 1px solid #eadecd;">
                  <tr>
                    <th style="padding: 6px 10px; text-align: left;">Hora</th>
                    <th style="padding: 6px 10px; text-align: left;">Tipo</th>
                    <th style="padding: 6px 10px; text-align: left;">Concepto / Justificación</th>
                    <th style="padding: 6px 10px; text-align: left;">Supervisor (PIN)</th>
                    <th style="padding: 6px 10px; text-align: right;">Monto</th>
                  </tr>
                </thead>
                <tbody id="desTbodyCajaChica">
                  <tr>
                    <td colspan="5" style="text-align: center; padding: 12px; color: #8a7b70;">Sin movimientos de caja
                      chica en este turno.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="modal-footer" style="display: flex; justify-content: flex-end; margin-top: 10px;">
          <button type="button" class="btn btn-gold" onclick="cerrarModalDesglose()">Entendido / Cerrar Desglose</button>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <script>
    window.HISTORIAL_SESIONES = <?= json_encode($historialSesiones, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="control_caja.js?v=<?= time() ?>"></script>
</body>

</html>