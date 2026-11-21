<?php
/**
 * Mantenimiento de Clasificaciones (Categorías y Marcas)
 * House & Ice • Sistema Administrativo
 * Tablas afectadas: inventario.categoria_producto, inventario.marca
 */

require_once __DIR__ . '/../../config/conexion.php';

// Procesar peticiones AJAX de guardado y toggle de estado (Soft Delete)

// Cargar colecciones reales desde PostgreSQL
$categorias = [];
$marcas = [];

if ($pdo) {
    try {
        $stmtCat = $pdo->query("SELECT id, nombre, estado, (estado = 'ACTIVO') AS activo FROM inventario.categoria_producto ORDER BY id ASC");
        $categorias = $stmtCat->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtMar = $pdo->query("SELECT id, nombre, estado, (estado = 'ACTIVO') AS activo FROM inventario.marca ORDER BY id ASC");
        $marcas = $stmtMar->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mantenimiento de Clasificaciones • House &amp; Ice</title>
  <link rel="stylesheet" href="../styles.css?v=13">
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
          <h1 class="page-title">Clasificaciones</h1>
          <p class="page-subtitle" id="pageSubtitle">Gestión unificada de Categorías y Marcas comerciales (Conectado a PostgreSQL)</p>
        </div>
        <div style="display: flex; gap: 10px;">
          <button type="button" class="btn btn-gold" id="btnNuevo" onclick="abrirModalNuevo()">
            + Nueva Categoría
          </button>
        </div>
      </div>

      <!-- PESTAÑAS (TABS) -->
      <div class="tab-nav">
        <button type="button" class="tab-btn active" id="tabCategorias" onclick="cambiarPestana('categorias')">
          <span>📁 Categorías de Productos</span>
          <span class="tab-badge" id="badgeTabCat"><?= count($categorias) ?></span>
        </button>
        <button type="button" class="tab-btn" id="tabMarcas" onclick="cambiarPestana('marcas')">
          <span>🏷️ Marcas Comerciales</span>
          <span class="tab-badge" id="badgeTabMar"><?= count($marcas) ?></span>
        </button>
      </div>

      <!-- TARJETA DE TABLA -->
      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title" id="cardTitle">Listado de Categorías</h2>
            <span class="badge-count" id="badgeCount"><?= count($categorias) ?> registradas</span>
          </div>
          <div class="search-box">
            <span class="search-icon">🔍</span>
            <input
              type="text"
              id="inputBuscar"
              placeholder="Buscar categoría por nombre..."
              oninput="filtrarDatos()"
            />
          </div>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th id="thNombre">NOMBRE DE LA CATEGORÍA</th>
                <th style="width: 170px;" class="text-center">ESTADO (ACTIVO)</th>
                <th style="width: 110px; text-align: right;">ACCIONES</th>
              </tr>
            </thead>
            <tbody id="tablaBody">
              <!-- Renderizado dinámico vía JavaScript / PostgreSQL -->
            </tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerCount">Cargando registros...</span>
          <span id="footerSchema" style="color: var(--text-muted); font-size: 0.82rem;">
            House &amp; Ice • Sistema Administrativo
          </span>
        </div>
      </div>
    </main>
  </div>

  <!-- MODAL: CREAR / EDITAR CLASIFICACIÓN -->
  <div class="modal-overlay" id="modalClasificacion">
    <div class="modal-box">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitle">Nueva Categoría</h2>
      </div>
      <form id="formClasificacion" onsubmit="guardarRegistro(event)">
        <input type="hidden" id="itemId" />
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label" for="itemNombre" id="labelNombre">NOMBRE DE LA CATEGORÍA *</label>
            <input
              type="text"
              id="itemNombre"
              class="form-control"
              placeholder="Ej. Ron, Whisky, Cerveza..."
              required
            />
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-gold" id="btnGuardar">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- TOAST NOTIFICACIÓN -->
  <div class="toast" id="toast">Operación exitosa</div>

    <div id="datosClasificaciones" data-categorias="<?= htmlspecialchars(json_encode($categorias, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" data-marcas="<?= htmlspecialchars(json_encode($marcas, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" style="display:none;"></div>
  <script src="clasificaciones.js"></script>
</body>
</html>

