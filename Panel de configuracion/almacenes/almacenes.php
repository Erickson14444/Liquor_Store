<?php
require_once __DIR__ . '/../../config/conexion.php';

$almacenesDb = [];
if ($pdo) {
    try {
        $almacenesDb = $pdo->query("SELECT id, ('ALM-' || LPAD(id::text, 3, '0')) AS codigo, nombre, ubicacion, activo FROM inventario.almacenes ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Almacenes</title>
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
    <?php include __DIR__ . '/../../sidebar.php'; ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content">
      <?php if (!empty($_GET['msg'])): ?>
        <div style="margin-bottom: 16px; padding: 12px 18px; border-radius: 8px; font-weight: 500; font-size: 0.9rem; <?= ($_GET['tipo'] ?? '') === 'exito' ? 'background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7;' : 'background: #ffebee; color: #c62828; border: 1px solid #ef9a9a;' ?>">
          <?= htmlspecialchars($_GET['msg']) ?>
        </div>
      <?php endif; ?>

      <div class="page-header">
        <div>
          <h1 class="page-title">Almacenes Físicos</h1>
          <p class="page-subtitle">Ubicaciones de almacenamiento, control de stock y puntos de despacho</p>
        </div>
        <button type="button" class="btn btn-gold" onclick="abrirModal()">+ Nuevo Almacén</button>
      </div>

      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Listado de Almacenes</h2>
            <span class="badge-count" id="badgeCount"><?= count($almacenesDb) ?> bodegas</span>
          </div>
          <div class="search-box" style="width: 280px;">
            <span class="search-icon">🔍</span>
            <input type="text" id="buscador" placeholder="Buscar almacén..." oninput="filtrarTabla()" />
          </div>
        </div>

        <table class="data-table">
          <thead>
            <tr>
              <th>Nombre del Almacén</th>
              <th>Ubicación Física</th>
              <th class="text-center" style="width: 150px;">Estado</th>
              <th class="text-right" style="width: 100px;">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaBody">
            <?php if (empty($almacenesDb)): ?>
              <tr>
                <td colspan="4" style="text-align: center; padding: 28px; color: var(--text-muted);">
                  No hay almacenes registrados. Haz clic en <strong>+ Nuevo Almacén</strong> para crear uno.
                </td>
              </tr>
            <?php endif; ?>
            <?php foreach ($almacenesDb as $a): 
              $esActivo = ($a['activo'] === true || $a['activo'] === 't' || $a['activo'] == 1 || $a['activo'] === '1');
            ?>
              <tr class="fila-almacen" data-texto="<?= strtolower(htmlspecialchars($a['nombre'] . ' ' . $a['ubicacion'])) ?>">
                <td><div style="font-weight: 600; color: #211813;"><?= htmlspecialchars($a['nombre']) ?></div></td>
                <td><?= htmlspecialchars($a['ubicacion'] ?: 'Área general') ?></td>
                <td class="text-center">
                  <label class="toggle-wrap" title="Cambiar estado">
                    <input type="checkbox" <?= $esActivo ? 'checked' : '' ?> onchange="toggleEstadoAlmacen(<?= $a['id'] ?>, this.checked, this)" />
                    <span class="toggle-slider"></span>
                    <span class="toggle-text"><?= $esActivo ? 'Activo' : 'Inactivo' ?></span>
                  </label>
                </td>
                <td class="text-right action-btns">
                  <button type="button" class="btn-sm btn-outline" onclick='editarAlmacen(<?= json_encode($a) ?>)'>Editar</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="table-card-footer">
          <span id="footerInfo">Total: <?= count($almacenesDb) ?> almacenes</span>
          <span>House &amp; Ice • Sistema Administrativo</span>
        </div>
      </div>
    </main>
  </div>

  <!-- MODAL -->
  <div id="modalAlmacen" class="modal-overlay" onclick="cerrarSiFondo(event)">
    <div class="modal-box">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitulo">Nuevo Almacén</h2>
      </div>
      <form id="formAlmacen" action="procesar_almacenes.php" method="POST" onsubmit="guardarAlmacen(event)">
        <input type="hidden" name="accion" id="formAccion" value="crear" />
        <input type="hidden" name="id" id="almacen_id" value="" />
        <div class="modal-body">
          <div class="form-group">
            <label for="nombre" class="form-label">Nombre del Almacén *</label>
            <input type="text" id="nombre" name="nombre" class="form-control" required placeholder="Ej. Cámara Fría, Bodega Este..." />
          </div>
          <div class="form-group">
            <label for="ubicacion" class="form-label">Ubicación</label>
            <input type="text" id="ubicacion" name="ubicacion" class="form-control" placeholder="Ej. Área Central, Pasillo 3, Sótano..." />
          </div>
          <div class="form-group" id="grupoEstadoModal" style="display: none;">
            <label class="form-label">Estado del Almacén</label>
            <div class="toggle-field-box" style="border-radius: var(--radius-sm); border: 1px solid #cfc3b0;">
              <label class="toggle-wrap">
                <input type="checkbox" id="modalActivo" name="activo" value="true" checked onchange="document.getElementById('modalActivoText').textContent = this.checked ? 'Activo' : 'Inactivo'" />
                <span class="toggle-slider"></span>
                <span class="toggle-text" id="modalActivoText">Activo</span>
              </label>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-gold" id="btnGuardar">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast"></div>

    <script src="almacenes.js"></script>
</body>
</html>