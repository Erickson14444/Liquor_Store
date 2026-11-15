<?php
require_once __DIR__ . '/../config/conexion.php';

$user           = $_SESSION['usuario'] ?? [];
$cajaId         = $sesionCajaId;
$nombreCompleto = $sesionNombreCompleto;
$userRol        = $sesionRol;
$avatarInitials = $sesionAvatarInitials;

// Zona horaria de República Dominicana
date_default_timezone_set('America/Santo_Domingo');

// Fecha y hora del sistema en español
$diasEsp = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$mesesEsp = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$fechaActualHumana = $diasEsp[date('w')] . ', ' . date('j') . ' de ' . $mesesEsp[(int)date('n')] . ' ' . date('Y');

// Variables para las tarjetas de indicadores operativos (en vivo desde PostgreSQL)
$ventasHoy             = 0.00;
$facturasHoyCount      = 0;
$ultimoNcf             = 'B0200000001';
$ncfDisponibles        = 0;
$bloquesNcfActivos     = 0;
$cajaNombre            = "Caja POS 0{$cajaId}";
$cajaEstado            = 'ABIERTA';
$fondoInicialCaja      = 2500.00;

$pedidosRuta           = 0;
$pedidosPendientes     = 0;
$repartidoresBase      = 0;
$repartidoresTotal     = 0;
$lotesAlerta           = 0;
$trasladosTransito     = 0;

$promocionesActivasCnt = 0;
$cuponesDisponiblesCnt = 0;
$cuponesDisponiblesMto = 0.00;
$consignacionesActivas = 0;
$cotizacionesVigentes  = 0;

$ordenesEsperando      = 0;
$cxcPendientesCnt      = 0;
$cxcPendientesMonto    = 0.00;
$cxpPendientesCnt      = 0;
$cxpPendientesMonto    = 0.00;
$arqueosPendientesCnt  = 0;
$arqueosPendientesMto  = 0.00;

$cajasList             = [];
$secuenciasList        = [];
$totalProductos        = 0;
$totalClientes         = 0;
$totalPersonal         = 0;
$totalProveedores      = 0;

try {
    require_once __DIR__ . '/../config/conexion.php';

    if ($pdo) {
        // 1. Ventas reales de hoy
        try {
            $stmtV = $pdo->query("SELECT COALESCE(SUM(monto_total), 0), COUNT(*) FROM ventas.facturas WHERE DATE(fecha_emision) = CURRENT_DATE AND estado != 'ANULADA'");
            $rowV = $stmtV->fetch(PDO::FETCH_NUM);
            $ventasHoy = (float)($rowV[0] ?? 0);
            $facturasHoyCount = (int)($rowV[1] ?? 0);
        } catch (Throwable $e) {}

        // 2. Último NCF emitido y disponibilidad de secuencias DGII
        try {
            $stmtN = $pdo->query("SELECT ncf FROM ventas.facturas WHERE ncf IS NOT NULL AND TRIM(ncf) != '' ORDER BY id DESC LIMIT 1");
            $nReal = $stmtN ? $stmtN->fetchColumn() : null;
            if ($nReal) {
                $ultimoNcf = $nReal;
            } else {
                $stmtSec = $pdo->query("SELECT tipo_comprobante || LPAD(secuencia_actual::text, 8, '0') FROM ventas.secuencias_fiscales_dgii WHERE estado = 'ACTIVO' ORDER BY id ASC LIMIT 1");
                $secReal = $stmtSec ? $stmtSec->fetchColumn() : null;
                $ultimoNcf = $secReal ?: 'B0200000001';
            }

            $stmtDisp = $pdo->query("SELECT COUNT(*), COALESCE(SUM(secuencia_hasta - secuencia_actual), 0) FROM ventas.secuencias_fiscales_dgii WHERE estado = 'ACTIVO'");
            $rDisp = $stmtDisp->fetch(PDO::FETCH_NUM);
            $bloquesNcfActivos = (int)($rDisp[0] ?? 0);
            $ncfDisponibles    = (int)($rDisp[1] ?? 0);
        } catch (Throwable $e) {}

        // 3. Estado de la Caja POS asignada
        try {
            $stmtC = $pdo->prepare("SELECT nombre_equipo, estado FROM ventas.cajas_pos WHERE id = ?");
            $stmtC->execute([$cajaId]);
            $cRow = $stmtC->fetch(PDO::FETCH_ASSOC);
            if ($cRow) {
                $cajaNombre = $cRow['nombre_equipo'];
                $cajaEstado = $cRow['estado'];
            }
            $stmtSes = $pdo->prepare("SELECT monto_inicial_efectivo FROM ventas.sesiones_caja WHERE caja_id = ? AND estado = 'ABIERTA' ORDER BY id DESC LIMIT 1");
            $stmtSes->execute([$cajaId]);
            $fIni = $stmtSes->fetchColumn();
            if ($fIni !== false) {
                $fondoInicialCaja = (float)$fIni;
            }
        } catch (Throwable $e) {}

        // 4. Pedidos delivery y flota de repartidores
        try {
            $pedidosRuta       = (int)$pdo->query("SELECT COUNT(*) FROM ventas.pedidos_delivery WHERE estado_delivery = 'EN_CAMINO'")->fetchColumn();
            $pedidosPendientes = (int)$pdo->query("SELECT COUNT(*) FROM ventas.pedidos_delivery WHERE estado_delivery = 'PENDIENTE'")->fetchColumn();
            $repartidoresTotal = (int)$pdo->query("SELECT COUNT(*) FROM identidad.repartidores WHERE estado_logistico != 'INACTIVO'")->fetchColumn();
            $repartidoresBase  = (int)$pdo->query("SELECT COUNT(*) FROM identidad.repartidores WHERE estado_logistico = 'BASE'")->fetchColumn();
        } catch (Throwable $e) {}

        // 5. Lotes FEFO por vencer y traslados en tránsito
        try {
            $lotesAlerta       = (int)$pdo->query("SELECT COUNT(*) FROM inventario.lotes WHERE fecha_vencimiento <= CURRENT_DATE + INTERVAL '30 days' AND cantidad_actual > 0")->fetchColumn();
            $trasladosTransito = (int)$pdo->query("SELECT COUNT(*) FROM inventario.traslados_internos WHERE estado = 'EN_TRANSITO'")->fetchColumn();
        } catch (Throwable $e) {}

        // 6. Promociones activas, cupones, consignaciones y cotizaciones
        try {
            $promocionesActivasCnt = (int)$pdo->query("SELECT COUNT(*) FROM promociones.promociones_campanas WHERE estado = 'ACTIVA'")->fetchColumn();
            $stmtCup = $pdo->query("SELECT COUNT(*), COALESCE(SUM(monto_credito), 0) FROM promociones.cupones_descuento WHERE estado = 'DISPONIBLE' AND fecha_vencimiento >= CURRENT_DATE");
            $rCup = $stmtCup->fetch(PDO::FETCH_NUM);
            $cuponesDisponiblesCnt = (int)($rCup[0] ?? 0);
            $cuponesDisponiblesMto = (float)($rCup[1] ?? 0);
        } catch (Throwable $e) {}

        try {
            $consignacionesActivas = (int)$pdo->query("SELECT COUNT(*) FROM ventas.consignaciones WHERE estado = 'DESPACHADA'")->fetchColumn();
            $cotizacionesVigentes  = (int)$pdo->query("SELECT COUNT(*) FROM ventas.cotizaciones WHERE estado = 'PENDIENTE'")->fetchColumn();
        } catch (Throwable $e) {}

        // 7. Órdenes de compra esperando camión, CxC, CxP y Arqueos por depositar
        try {
            $ordenesEsperando = (int)$pdo->query("SELECT COUNT(*) FROM compras.ordenes_compra WHERE estado IN ('PENDIENTE', 'RECIBIDA_PARCIAL')")->fetchColumn();

            $stmtCxc = $pdo->query("SELECT COUNT(*), COALESCE(SUM(saldo_pendiente), 0) FROM ventas.cuentas_por_cobrar WHERE estado IN ('PENDIENTE', 'PAGADA_PARCIAL')");
            $rCxc = $stmtCxc->fetch(PDO::FETCH_NUM);
            $cxcPendientesCnt   = (int)($rCxc[0] ?? 0);
            $cxcPendientesMonto = (float)($rCxc[1] ?? 0);

            $stmtCxp = $pdo->query("SELECT COUNT(*), COALESCE(SUM(saldo_pendiente), 0) FROM compras.cuentas_por_pagar WHERE estado IN ('PENDIENTE', 'PAGADA_PARCIAL')");
            $rCxp = $stmtCxp->fetch(PDO::FETCH_NUM);
            $cxpPendientesCnt   = (int)($rCxp[0] ?? 0);
            $cxpPendientesMonto = (float)($rCxp[1] ?? 0);

            $stmtArq = $pdo->query("SELECT COUNT(*), COALESCE(SUM(monto_declarado_cajero), 0) FROM ventas.arqueo_detalles WHERE estado_deposito = 'PENDIENTE'");
            $rArq = $stmtArq->fetch(PDO::FETCH_NUM);
            $arqueosPendientesCnt = (int)($rArq[0] ?? 0);
            $arqueosPendientesMto = (float)($rArq[1] ?? 0);
        } catch (Throwable $e) {}

        // 8. Listado de Cajas POS, Secuencias NCF y Conteos de Base Operativa
        try {
            $cajasList = $pdo->query("SELECT id, nombre_equipo, estado FROM ventas.cajas_pos ORDER BY id ASC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        try {
            $secuenciasList = $pdo->query("SELECT tipo_comprobante, secuencia_actual, secuencia_hasta, fecha_vencimiento, estado FROM ventas.secuencias_fiscales_dgii ORDER BY id ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        try {
            $totalProductos = (int)$pdo->query("SELECT COUNT(*) FROM inventario.productos_maestro")->fetchColumn();
        } catch (Throwable $e) {}

        try {
            $totalClientes = (int)$pdo->query("SELECT COUNT(*) FROM identidad.clientes")->fetchColumn();
        } catch (Throwable $e) {}

        try {
            $totalPersonal = (int)$pdo->query("SELECT COUNT(*) FROM identidad.empleados WHERE estado_laboral = 'ACTIVO'")->fetchColumn();
        } catch (Throwable $e) {}

        try {
            $totalProveedores = (int)$pdo->query("SELECT COUNT(*) FROM identidad.proveedores")->fetchColumn();
        } catch (Throwable $e) {}
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Menú Principal • House &amp; Ice</title>
  <link rel="stylesheet" href="styles.css?v=15">
</head>
<body>

  <!-- ==================== TOPBAR ==================== -->
  <header class="topbar">
    <div style="display: flex; align-items: center; gap: 10px;">
      <button type="button" class="mobile-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Abrir Menú">
        <span></span>
        <span></span>
        <span></span>
      </button>
      <a href="menu.php" class="topbar-brand" style="text-decoration: none;">
        <span class="brand-title">HOUSE &amp; ICE</span>
        <span class="brand-dot">•</span>
        <span class="brand-subtitle">Sistema Administrativo</span>
      </a>
    </div>

    <!-- Usuario con la Caja asignada directamente abajo -->
    <div class="topbar-actions">
      <div class="topbar-user">
        <div class="user-avatar"><?= htmlspecialchars($avatarInitials) ?></div>
        <div class="user-info-col">
          <div class="user-name"><?= htmlspecialchars($nombreCompleto) ?></div>
          <div class="user-sub-line">
            <span class="user-role-text"><?= htmlspecialchars($userRol) ?></span>
            <span class="user-bullet">•</span>
            <span class="user-caja-pill open">
              <span class="pill-dot green"></span>
              Caja 0<?= htmlspecialchars((string)$cajaId) ?>
            </span>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="app-container app-layout">
    <!-- ==================== SIDEBAR UNIFICADO ==================== -->
    <?php include __DIR__ . '/../sidebar.php'; ?>

    <!-- ==================== MAIN CONTENT (DASHBOARD HUB) ==================== -->
    <main class="main-content">
      
      <!-- Welcome Banner Simplificado y Elegante -->
      <section class="welcome-banner">
        <div class="welcome-text">
          <h1>¡Hola, <?= htmlspecialchars(explode(' ', $nombreCompleto)[0] ?? $nombreCompleto) ?>! 👋</h1>
          <p>Panel de control operativo y administrativo de <strong>House &amp; Ice</strong>.</p>
        </div>
        <div class="welcome-date-badge">
          <span class="date-icon">📅</span>
          <div>
            <div class="date-main"><?= htmlspecialchars($fechaActualHumana) ?></div>
            <div class="date-sub">Sistema en línea • Horario RD (GMT-4)</div>
          </div>
        </div>
      </section>

      <!-- ========================================================================= -->
      <!-- TARJETAS SENCILLAS DE INDICADORES DEL TURNO (MISMO ESTILO LIMPIO)         -->
      <!-- ========================================================================= -->
      <div class="kpi-grid">
        <!-- 1. Ventas Totales Hoy -->
        <div class="kpi-card">
          <div class="kpi-icon-box gold">💵</div>
          <div class="kpi-meta">
            <h3>Ventas Totales Hoy</h3>
            <div class="kpi-value">RD$ <?= number_format($ventasHoy, 2) ?></div>
            <div class="kpi-sub">↑ <?= $facturasHoyCount ?> facturas emitidas hoy</div>
          </div>
        </div>

        <!-- 2. En Ruta Delivery -->
        <div class="kpi-card">
          <div class="kpi-icon-box green">🛵</div>
          <div class="kpi-meta">
            <h3>En Ruta Delivery</h3>
            <div class="kpi-value"><?= intval($pedidosRuta) ?> pedidos</div>
            <div class="kpi-sub"><?= intval($pedidosPendientes) ?> pendientes de despacho</div>
          </div>
        </div>

        <!-- 4. Alertas de Vencimiento FEFO -->
        <div class="kpi-card">
          <div class="kpi-icon-box red">⏳</div>
          <div class="kpi-meta">
            <h3>Alertas de Vencimiento</h3>
            <div class="kpi-value"><?= intval($lotesAlerta) ?> lotes</div>
            <div class="kpi-sub">&lt; 30 días (Regla FEFO)</div>
          </div>
        </div>

        <!-- 5. NCF Disponibles DGII -->
        <div class="kpi-card">
          <div class="kpi-icon-box blue">🧾</div>
          <div class="kpi-meta">
            <h3>Comprobantes NCF Disp.</h3>
            <div class="kpi-value"><?= number_format($ncfDisponibles) ?> NCF</div>
            <div class="kpi-sub"><?= $bloquesNcfActivos ?> bloques fiscales activos</div>
          </div>
        </div>

        <!-- 6. Repartidores en Base -->
        <div class="kpi-card">
          <div class="kpi-icon-box green">🏍️</div>
          <div class="kpi-meta">
            <h3>Repartidores en Base</h3>
            <div class="kpi-value"><?= $repartidoresBase ?> disponibles</div>
            <div class="kpi-sub">De <?= $repartidoresTotal ?> motoristas en flota</div>
          </div>
        </div>

        <!-- 7. Cotizaciones Vigentes -->
        <div class="kpi-card">
          <div class="kpi-icon-box blue">📝</div>
          <div class="kpi-meta">
            <h3>Cotizaciones Pendientes</h3>
            <div class="kpi-value"><?= $cotizacionesVigentes ?> abiertas</div>
            <div class="kpi-sub">Precios garantizados vigentes</div>
          </div>
        </div>

        <!-- 8. Órdenes Esperando Camión (Check-in) -->
        <div class="kpi-card">
          <div class="kpi-icon-box gold">📥</div>
          <div class="kpi-meta">
            <h3>Compras por Recibir</h3>
            <div class="kpi-value"><?= $ordenesEsperando ?> órdenes</div>
            <div class="kpi-sub">Esperando camión (Check-in / NCF)</div>
          </div>
        </div>

        <!-- 9. Arqueos Pendientes de Depósito -->
        <div class="kpi-card">
          <div class="kpi-icon-box red">🏦</div>
          <div class="kpi-meta">
            <h3>Arqueos por Depositar</h3>
            <div class="kpi-value"><?= $arqueosPendientesCnt ?> cierres</div>
            <div class="kpi-sub">RD$ <?= number_format($arqueosPendientesMto, 0) ?> en bóveda para banco</div>
          </div>
        </div>
      </div>

      <!-- ========================================================================= -->
      <!-- PANEL INFERIOR EN TARJETAS BLANCAS: PROCESOS Y MONITOREO DEL TURNO        -->
      <!-- ========================================================================= -->
      <div class="dash-bottom-grid">
        
        <!-- COLUMNA IZQUIERDA: ESTADO DE TERMINALES POS Y SECUENCIAS NCF -->
        <section class="dash-white-card">
          <div class="dash-card-header">
            <h2 class="dash-card-title"><span>💻</span> Terminales POS y Secuencias Fiscales DGII</h2>
            <span class="dash-card-badge">Monitoreo en Vivo</span>
          </div>

          <!-- Tira de Cajas POS -->
          <div class="dash-cajas-row">
            <?php if (empty($cajasList)): ?>
              <div class="dash-caja-item">
                <div>
                  <div class="dash-caja-name">Caja Principal 01</div>
                  <div class="dash-caja-sub">Terminal Asignada</div>
                </div>
                <span class="dash-pill green">● ABIERTA</span>
              </div>
            <?php else: ?>
              <?php foreach ($cajasList as $cItem): 
                $estC = strtoupper($cItem['estado'] ?? 'ABIERTA');
                $esActiva = ($estC === 'ABIERTA' || $estC === 'ACTIVA');
              ?>
                <div class="dash-caja-item">
                  <div>
                    <div class="dash-caja-name"><?= htmlspecialchars($cItem['nombre_equipo']) ?></div>
                    <div class="dash-caja-sub">Terminal #0<?= intval($cItem['id']) ?></div>
                  </div>
                  <span class="dash-pill <?= $esActiva ? 'green' : 'gray' ?>">
                    ● <?= htmlspecialchars($estC) ?>
                  </span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <!-- Tabla compacta de Secuencias NCF -->
          <div style="overflow-x: auto;">
            <table class="dash-mini-table">
              <thead>
                <tr>
                  <th>Tipo NCF</th>
                  <th>Próximo Comprobante</th>
                  <th>Disponibles</th>
                  <th>Vencimiento</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($secuenciasList)): ?>
                  <tr>
                    <td><strong>B02</strong> (Consumo)</td>
                    <td style="font-family: var(--font-mono);">B0200000001</td>
                    <td><strong>500</strong> uds.</td>
                    <td>31/12/2026</td>
                    <td><span class="dash-pill green">ACTIVO</span></td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($secuenciasList as $sec): 
                    $dispSec = max(0, intval($sec['secuencia_hasta']) - intval($sec['secuencia_actual']));
                    $proxNcf = $sec['tipo_comprobante'] . str_pad((string)$sec['secuencia_actual'], 8, '0', STR_PAD_LEFT);
                    $estSec  = strtoupper($sec['estado'] ?? 'ACTIVO');
                  ?>
                    <tr>
                      <td><strong><?= htmlspecialchars($sec['tipo_comprobante']) ?></strong></td>
                      <td style="font-family: var(--font-mono); font-weight: 600;"><?= htmlspecialchars($proxNcf) ?></td>
                      <td><strong><?= number_format($dispSec) ?></strong> NCF</td>
                      <td><?= htmlspecialchars($sec['fecha_vencimiento'] ?? '—') ?></td>
                      <td>
                        <span class="dash-pill <?= ($estSec === 'ACTIVO') ? 'green' : 'gold' ?>">
                          <?= htmlspecialchars($estSec) ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

        <!-- COLUMNA DERECHA: PROCESOS OPERATIVOS RÁPIDOS Y BASE ACTIVA -->
        <section class="dash-white-card">
          <div class="dash-card-header">
            <h2 class="dash-card-title"><span>⚡</span> Flujo de Procesos Operativos</h2>
            <span class="dash-card-badge">Operación Diaria</span>
          </div>

          <!-- Resumen rápido de registros activos en BD -->
          <div class="dash-stat-strip">
            <div class="dash-stat-box">
              <div class="dash-stat-num"><?= number_format($totalProductos) ?></div>
              <div class="dash-stat-lbl">Productos</div>
            </div>
            <div class="dash-stat-box">
              <div class="dash-stat-num"><?= number_format($totalClientes) ?></div>
              <div class="dash-stat-lbl">Clientes</div>
            </div>
            <div class="dash-stat-box">
              <div class="dash-stat-num"><?= number_format($totalPersonal) ?></div>
              <div class="dash-stat-lbl">Personal</div>
            </div>
            <div class="dash-stat-box">
              <div class="dash-stat-num"><?= number_format($totalProveedores) ?></div>
              <div class="dash-stat-lbl">Proveedores</div>
            </div>
          </div>

          <!-- Accesos directos exclusivamente a PROCESOS (sin mezclar mantenimientos) -->
          <div class="dash-process-grid">
            <a href="../Ventas y Facturacion/Facturacion/facturacion.php" class="dash-process-item">
              <div class="dash-process-icon">🛒</div>
              <div class="dash-process-info">
                <h4>Facturación (POS)</h4>
                <p>Facturación y emisión NCF</p>
              </div>
            </a>

            <a href="#" class="dash-process-item" onclick="alert('Proceso de Apertura y Cierre de Caja en desarrollo'); return false;">
              <div class="dash-process-icon">🔒</div>
              <div class="dash-process-info">
                <h4>Apertura y Arqueo</h4>
                <p>Control de turno y efectivo</p>
              </div>
            </a>

            <a href="#" class="dash-process-item" onclick="alert('Proceso de Recepción de Mercancía con Lotes FEFO en desarrollo'); return false;">
              <div class="dash-process-icon">📥</div>
              <div class="dash-process-info">
                <h4>Recepción Check-in</h4>
                <p>Entrada de compra y lotes FEFO</p>
              </div>
            </a>

            <a href="../Ventas y Facturacion/Delivery/delivery.php" class="dash-process-item">
              <div class="dash-process-icon">🛵</div>
              <div class="dash-process-info">
                <h4>Despacho Delivery</h4>
                <p>Horarios y asignación de zonas</p>
              </div>
            </a>

            <a href="#" class="dash-process-item" onclick="alert('Proceso de Ajustes, Mermas y Traslados en desarrollo'); return false;">
              <div class="dash-process-icon">⚖️</div>
              <div class="dash-process-info">
                <h4>Lotes, Ajustes y Traslados</h4>
                <p>Control de stock y bodegas</p>
              </div>
            </a>

            <a href="../Gestion de Promociones/promociones.php" class="dash-process-item">
              <div class="dash-process-icon">🎁</div>
              <div class="dash-process-info">
                <h4>Gestión de Promociones</h4>
                <p>Campañas y carrito de reglas</p>
              </div>
            </a>

            <a href="#" class="dash-process-item" onclick="alert('Proceso de Depósitos de Arqueos Bancarios en desarrollo'); return false;">
              <div class="dash-process-icon">💰</div>
              <div class="dash-process-info">
                <h4>Depósitos de Arqueos</h4>
                <p>Conciliación y envío a banco</p>
              </div>
            </a>
          </div>
        </section>

      </div>

    </main>
  </div>

  <script>
    function toggleSidebarMenu(menuId) {
      const item = document.getElementById(menuId);
      if (!item) return;

      const willOpen = item.classList.contains('collapsed');

      // Comportamiento acordeón: cerrar los demás módulos abiertos
      document.querySelectorAll('.sidebar-nav > .sidebar-item').forEach(el => {
        if (el !== item && el.classList.contains('sidebar-item')) {
          el.classList.add('collapsed');
        }
      });

      // Abrir el módulo clickeado (o cerrarlo si ya estaba abierto)
      if (willOpen) {
        item.classList.remove('collapsed');
      } else {
        item.classList.add('collapsed');
      }
    }

    function toggleMobileSidebar() {
      const sidebar = document.querySelector('.sidebar');
      const overlay = document.getElementById('sidebarOverlay');
      if (sidebar) sidebar.classList.toggle('mobile-open');
      if (overlay) overlay.classList.toggle('active');
    }
  </script>
</body>
</html>
