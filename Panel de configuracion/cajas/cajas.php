<?php
require_once __DIR__ . '/../../config/conexion.php';


$cajasDb = [];
if ($pdo) {
    try {
        $cajasDb = $pdo->query("SELECT id, ('CAJA-POS-0' || id::text) AS codigo, nombre_equipo, estado FROM ventas.cajas_pos ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Cajas Registradoras</title>
  <link rel="stylesheet" href="../styles.css?v=13" />
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
            <?= renderizarPillCajaTopbar() ?>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="app-container">
    <!-- MENÚ LATERAL -->
    <?php include __DIR__ . '/../../sidebar.php'; ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content">
      <div class="page-header">
        <div>
          <h1 class="page-title">Cajas Registradoras</h1>
          <p class="page-subtitle">Registro y administración de cajas registradoras físicas y terminales de cobro del local</p>
        </div>
        <button type="button" class="btn btn-gold" onclick="abrirNuevaCaja()">
          + Nueva Caja Registradora
        </button>
      </div>

      <!-- TARJETA DE TABLA -->
      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Listado de Cajas Registradoras</h2>
            <span class="badge-count" id="badgeCount">4 registradas</span>
          </div>
          <div class="search-box">
            <span class="search-icon">🔍</span>
            <input
              type="text"
              id="inputBuscar"
              placeholder="Buscar por equipo o ubicación..."
              oninput="filtrarCajas()"
            />
          </div>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width: 280px;">NOMBRE EQUIPO</th>
                <th>UBICACIÓN</th>
                <th class="text-center" style="width: 160px;">ESTADO</th>
                <th style="text-align: right; width: 90px;">ACCIONES</th>
              </tr>
            </thead>
            <tbody id="tablaCajas">
              <!-- Renderizado dinámicamente -->
            </tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerCount">Mostrando cajas registradoras</span>
          <span class="cell-sub">House &amp; Ice • Sistema Administrativo</span>
        </div>
      </div>
    </main>
  </div>

  <!-- MODAL DE CREAR / EDITAR CAJA REGISTRADORA -->
  <div class="modal-overlay" id="modalCaja">
    <div class="modal-box modal-lg">
      <div class="modal-header">
        <h3 class="modal-title" id="modalTitle">Nueva Caja Registradora</h3>
      </div>

      <form id="formCaja" autocomplete="off" onsubmit="guardarCaja(event)">
        <input type="hidden" id="cajaId" />
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label">NOMBRE DE LA CAJA REGISTRADORA *</label>
            <input type="text" class="form-control" id="cajaNombre" maxlength="50" placeholder="Ej. Caja Principal, Caja Drive-Thru" required />
          </div>

          <div class="form-group">
            <label class="form-label">UBICACIÓN (OPCIONAL)</label>
            <input type="text" class="form-control" id="cajaUbicacion" maxlength="50" placeholder="Ej. Mostrador Entrada, Barra Lounge" />
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-beige" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-gold">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- TOAST DE NOTIFICACIÓN -->
  <div class="toast" id="toast"></div>

    <div id="datosCajas" data-cajas="<?= htmlspecialchars(json_encode($cajasDb, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" style="display:none;"></div>
  <script src="cajas.js"></script>
</body>
</html>

