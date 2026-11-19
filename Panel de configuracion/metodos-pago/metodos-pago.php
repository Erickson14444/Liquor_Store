<?php
require_once __DIR__ . '/../../config/conexion.php';


$metodosDb = [];
if ($pdo) {
    try {
        $metodosDb = $pdo->query("SELECT id, ('MET-' || LPAD(id::text, 3, '0')) AS codigo_formateado, codigo, descripcion, requiere_referencia, activo FROM ventas.metodos_pago ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House & Ice • Métodos de Pago</title>
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
    <!-- MENÚ LATERAL -->
    <?php include __DIR__ . '/../../sidebar.php'; ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content">
      <div class="page-header">
        <div>
          <h1 class="page-title">Métodos de Pago</h1>
          <p class="page-subtitle">Parámetros de cobro en punto de venta, exigencia de váucher y disponibilidad</p>
        </div>
        <button type="button" class="btn btn-gold" onclick="abrirNuevoMetodo()">
          + Nuevo Método
        </button>
      </div>

      <!-- TARJETA DE TABLA -->
      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Listado de Métodos de Pago</h2>
            <span class="badge-count" id="badgeCount">5 registrados</span>
          </div>
          <div class="search-box">
            <span class="search-icon">🔍</span>
            <input
              type="text"
              id="inputBuscar"
              placeholder="Buscar por código o descripción..."
              oninput="filtrarMetodos()"
            />
          </div>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width: 170px;">CÓDIGO</th>
                <th>DESCRIPCIÓN</th>
                <th class="text-center" style="width: 190px;">REQUIERE REFERENCIA</th>
                <th class="text-center" style="width: 130px;">ACTIVO</th>
                <th style="text-align: right; width: 90px;">ACCIONES</th>
              </tr>
            </thead>
            <tbody id="tablaMetodos">
              <!-- Renderizado dinámicamente -->
            </tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerCount">Mostrando 5 métodos</span>
          <span class="cell-sub">House &amp; Ice • Sistema Administrativo</span>
        </div>
      </div>
    </main>
  </div>

  <!-- MODAL DE CREAR / EDITAR MÉTODO DE PAGO -->
  <div class="modal-overlay" id="modalMetodo">
    <div class="modal-box modal-lg">
      <div class="modal-header">
        <h3 class="modal-title" id="modalTitle">Nuevo Método de Pago</h3>
      </div>

      <form id="formMetodo" autocomplete="off" onsubmit="guardarMetodo(event)">
        <input type="hidden" id="metodoId" />
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label">CÓDIGO *</label>
            <input type="text" class="form-control" id="metodoCodigo" maxlength="20" placeholder="Ej. EFECTIVO, TARJETA, CHEQUE" required style="font-family: var(--font-mono); text-transform: uppercase;" />
            <small id="codigoFeedbackMetodo" style="display: none; color: var(--accent-gold); font-weight: 600; font-size: 0.76rem; margin-top: 3px;"></small>
          </div>

          <div class="form-group">
            <label class="form-label">DESCRIPCIÓN *</label>
            <input type="text" class="form-control" id="metodoDescripcion" maxlength="50" placeholder="Ej. Tarjeta de Crédito / Débito, Efectivo" required />
          </div>

          <!-- PARÁMETRO: REQUIERE REFERENCIA -->
          <div style="background-color: #faf6ee; border: 1px solid #e8decb; border-radius: var(--radius-sm); padding: 12px 14px; margin-top: 4px;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
              <div>
                <strong style="font-size: 0.82rem; color: var(--text-dark); display: block;">REQUIERE REFERENCIA</strong>
                <span class="cell-sub">Obligar al cajero a escribir número de váucher (tarjeta) o aprobación (transferencia).</span>
              </div>
              <label class="toggle-wrap" style="margin-left: 14px;">
                <input type="checkbox" id="metodoVaucher" />
                <span class="toggle-slider"></span>
              </label>
            </div>
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

    <div id="datosMetodos" data-metodos="<?= htmlspecialchars(json_encode($metodosDb, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" style="display:none;"></div>
  <script src="metodos-pago.js"></script>
</body>
</html>

