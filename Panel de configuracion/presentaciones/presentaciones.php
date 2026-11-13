<?php
/**
 * Mantenimiento de Presentaciones y Códigos de Barra
 * House & Ice • Sistema Administrativo
 * Tablas: inventario.producto_codigos_barra, inventario.productos_maestro
 */

require_once __DIR__ . '/../../config/conexion.php';

$presentaciones = [];
$productosMaestros = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT pcb.*, pm.sku, pm.precio_base_unidad
            FROM inventario.producto_codigos_barra pcb
            JOIN inventario.productos_maestro pm ON pcb.producto_maestro_id = pm.id
            ORDER BY pm.sku ASC, pcb.factor_multiplicador ASC
        ");
        $presentaciones = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $productosMaestros = $pdo->query("SELECT id, sku, precio_base_unidad FROM inventario.productos_maestro ORDER BY sku ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}

$msg = $_GET['msg'] ?? '';
$tipoMsg = $_GET['tipo'] ?? 'exito';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Presentaciones</title>
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

      <!-- ENCABEZADO -->
      <div class="page-header">
        <div>
          <h1 class="page-title">Presentaciones</h1>
          <p class="page-subtitle">Configuración multicódigo: Botella Unitaria, Six-Pack, Cajas x12 o x24 con factores de conversión</p>
        </div>

        <button type="button" class="btn btn-gold" onclick="abrirModal()">
          + Nueva Presentación
        </button>
      </div>

      <!-- TARJETA DE TABLA -->
      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Listado de Presentaciones Comerciales</h2>
            <span class="badge-count" id="badgeCount"><?= count($presentaciones) ?> presentaciones</span>
          </div>

          <div class="search-box" style="width: 280px;">
            <span class="search-icon">🔍</span>
            <input
              type="text"
              id="buscador"
              placeholder="Buscar por código de barra, descripción o SKU..."
              oninput="filtrarTabla()"
            />
          </div>
        </div>

        <table class="data-table">
          <thead>
            <tr>
              <th>Código de Barra</th>
              <th>Descripción de la Presentación</th>
              <th>Producto Maestro (SKU)</th>
              <th class="text-center">Factor Conversión</th>
              <th class="text-right">Precio de Venta</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaBody">
            <?php if (empty($presentaciones)): ?>
              <tr>
                <td colspan="6" class="text-center" style="padding: 30px; color: #888;">
                  No hay presentaciones registradas. Haz clic en <strong>+ Nueva Presentación</strong> para crear una.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($presentaciones as $pres): ?>
                <tr class="fila-presentacion" data-texto="<?= strtolower(htmlspecialchars($pres['codigo_barra'] . ' ' . $pres['descripcion_presentacion'] . ' ' . $pres['sku'])) ?>">
                  <td>
                    <strong style="color: #a66e2e; font-family: monospace; font-size: 0.95em;">
                      <?= htmlspecialchars($pres['codigo_barra']) ?>
                    </strong>
                  </td>
                  <td>
                    <div style="font-weight: 600; color: #211813;"><?= htmlspecialchars($pres['descripcion_presentacion']) ?></div>
                  </td>
                  <td>
                    <span class="badge" style="background: #eadecd; color: #5a3818;"><?= htmlspecialchars($pres['sku']) ?></span>
                  </td>
                  <td class="text-center">
                    <span style="font-weight: bold; color: #211813;">x<?= intval($pres['factor_multiplicador']) ?> <?= ($pres['factor_multiplicador'] == 1) ? 'Unidad' : 'Unidades' ?></span>
                  </td>
                  <td class="text-right" style="font-weight: 600; font-family: monospace; color: #211813;">
                    $<?= number_format($pres['precio_venta_presentacion'], 2) ?>
                  </td>
                  <td class="text-right action-btns">
                    <button type="button" class="btn-sm btn-outline" onclick='editarPresentacion(<?= json_encode($pres) ?>)'>Editar</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="table-card-footer">
          <span id="footerInfo">Total: <?= count($presentaciones) ?> presentaciones</span>
          <span>House &amp; Ice • Sistema Administrativo</span>
        </div>
      </div>

    </main>
  </div>

  <!-- MODAL NUEVO / EDITAR PRESENTACION -->
  <div id="modalPresentacion" class="modal-overlay" onclick="cerrarSiFondo(event)">
    <div class="modal-box modal-lg">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitulo">Nueva Presentación</h2>
      </div>
      <form id="formPresentacion" action="procesar_presentaciones.php" method="POST">
        <input type="hidden" name="accion" id="formAccion" value="crear" />
        <input type="hidden" name="presentacion_id" id="presentacion_id" value="" />

        <div class="modal-body">
          <div class="form-row-2">
            <div class="form-group">
              <label for="producto_maestro_id" class="form-label">Producto Maestro *</label>
              <select id="producto_maestro_id" name="producto_maestro_id" class="form-control" required onchange="actualizarPrecioSugerido()">
                <?php foreach ($productosMaestros as $pm): ?>
                  <option value="<?= $pm['id'] ?>" data-precio="<?= $pm['precio_base_unidad'] ?>">
                    <?= htmlspecialchars($pm['sku']) ?> (Base: $<?= number_format($pm['precio_base_unidad'], 2) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="codigo_barra" class="form-label">Código de Barra (EAN-13 / UPC) *</label>
              <input type="text" id="codigo_barra" name="codigo_barra" class="form-control" required placeholder="7460100123456" maxlength="50" />
            </div>
          </div>

          <div class="form-group">
            <label for="descripcion_presentacion" class="form-label">Descripción de la Presentación *</label>
            <input type="text" id="descripcion_presentacion" name="descripcion_presentacion" class="form-control" required placeholder="Ej. Ron Brugal Leyenda Caja x 6 Botellas" maxlength="100" />
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="factor_multiplicador" class="form-label">Factor Multiplicador (Unidades que contiene) *</label>
              <input type="number" min="1" id="factor_multiplicador" name="factor_multiplicador" class="form-control" required value="1" oninput="actualizarPrecioSugerido()" />
            </div>
            <div class="form-group">
              <label for="precio_venta_presentacion" class="form-label">Precio de Venta Presentación (DOP) *</label>
              <input type="number" step="0.01" min="0" id="precio_venta_presentacion" name="precio_venta_presentacion" class="form-control" required value="0.00" />
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

  <div id="toast" class="toast"></div>

    <script src="presentaciones.js"></script>
</body>
</html>
