<?php
/**
 * Mantenimiento de Zonas de Delivery
 * House & Ice • Sistema Administrativo
 * Tabla afectada: ventas.zonas_delivery
 */

require_once __DIR__ . '/../../config/conexion.php';


$zonasDb = [];
if ($pdo) {
    try {
        $zonasDb = $pdo->query("SELECT id, ('ZONA-' || LPAD(id::text, 3, '0')) AS codigo, nombre, tarifa_envio, tiempo_promedio_min, activa FROM ventas.zonas_delivery ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Zonas de Delivery</title>
  <link rel="stylesheet" href="../styles.css?v=13" />
</head>
<body>

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
            <?= renderizarPillCajaTopbar() ?>
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
          <h1 class="page-title">Zonas de Delivery</h1>
          <p class="page-subtitle">Sectores geográficos, tarifas de envío y tiempos estimados de entrega (Santiago de los Caballeros)</p>
        </div>
        <button type="button" class="btn btn-gold" onclick="abrirModal()">+ Nueva Zona de Envío</button>
      </div>

      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Sectores Configurados</h2>
            <span class="badge-count" id="badgeCount"><?= count($zonasDb) ?> zonas</span>
          </div>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>NOMBRE DEL SECTOR / ZONA</th>
                <th style="text-align: right;">TARIFA DE ENVÍO</th>
                <th style="text-align: center;">TIEMPO PROMEDIO</th>
                <th class="text-center">ESTADO</th>
                <th class="text-right">ACCIONES</th>
              </tr>
            </thead>
            <tbody id="tablaBody"></tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <div id="modalZona" class="modal-overlay">
    <div class="modal-box">
      <div class="modal-header">
        <h2 id="modalTitulo" class="modal-title">Nueva Zona de Delivery</h2>
      </div>
      <form id="formZona" onsubmit="guardarZona(event)">
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label" for="nombre_zona">NOMBRE DEL SECTOR *</label>
            <input type="text" id="nombre_zona" class="form-control" placeholder="Ej. Los Jardines, Villa Olga, Gurabo..." required />
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="tarifa_envio">TARIFA DE ENVÍO (RD$) *</label>
              <input type="number" step="0.01" min="0" id="tarifa_envio" class="form-control" placeholder="Ej. 150.00" required onkeydown="if(['e','E','+','-'].includes(event.key)) { event.preventDefault(); return false; }" oninput="this.value = this.value.replace(/[^0-9.]/g, '');" />
            </div>
            <div class="form-group">
              <label class="form-label" for="tiempo_promedio_min">TIEMPO PROMEDIO (MINUTOS) *</label>
              <input type="number" min="1" step="1" id="tiempo_promedio_min" class="form-control" placeholder="Ej. 30" required onkeydown="if(['e','E','+','-','.',','].includes(event.key)) { event.preventDefault(); return false; }" oninput="this.value = this.value.replace(/[^0-9]/g, '');" />
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-gold">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast">Guardado correctamente</div>

    <div id="datosZonas" data-zonas="<?= htmlspecialchars(json_encode($zonasDb, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" style="display:none;"></div>
  <script src="zonas-delivery.js"></script>
</body>
</html>
