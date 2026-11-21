<?php
/**
 * Mantenimiento de Productos Maestros
 * House & Ice • Sistema Administrativo
 * Tablas: inventario.productos_maestro, inventario.categoria_producto, inventario.marca
 */

require_once __DIR__ . '/../../config/conexion.php';

$productos = [];
$categorias = [];
$marcas = [];
$impuestosCatalogo = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT pm.*, 
                   c.nombre AS categoria_nombre, 
                   m.nombre AS marca_nombre,
                   COALESCE((
                       SELECT JSON_AGG(pi.impuesto_id ORDER BY pi.impuesto_id)
                       FROM inventario.producto_impuestos pi
                       WHERE pi.producto_maestro_id = pm.id
                   ), '[]') AS impuestos_ids_json,
                   COALESCE((
                       SELECT STRING_AGG(imp.nombre, ' | ' ORDER BY imp.orden_calculo ASC, imp.id ASC)
                       FROM inventario.producto_impuestos pi
                       JOIN tesoreria.impuestos imp ON pi.impuesto_id = imp.id
                       WHERE pi.producto_maestro_id = pm.id
                   ), 'Exento de Impuestos') AS impuestos_nombres,
                   COALESCE(
                       NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                       (
                           SELECT descripcion_presentacion 
                           FROM inventario.producto_codigos_barra 
                           WHERE producto_maestro_id = pm.id 
                           ORDER BY factor_multiplicador ASC LIMIT 1
                       ),
                       pm.sku
                   ) AS descripcion
            FROM inventario.productos_maestro pm
            LEFT JOIN inventario.categoria_producto c ON pm.categoria_id = c.id
            LEFT JOIN inventario.marca m ON pm.marca_id = m.id
            WHERE pm.id > 0
            ORDER BY pm.id ASC
        ");
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($productos as &$pRow) {
            $pRow['impuestos_ids'] = json_decode($pRow['impuestos_ids_json'] ?? '[]', true) ?: [];
        }
        unset($pRow);
        $categorias = $pdo->query("SELECT id, nombre FROM inventario.categoria_producto WHERE id > 0 AND estado = 'ACTIVO' ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $marcas = $pdo->query("SELECT id, nombre FROM inventario.marca WHERE id > 0 AND estado = 'ACTIVO' ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $impuestosCatalogo = $pdo->query("SELECT id, siglas, nombre, tipo_calculo, tasa_o_monto, orden_calculo FROM tesoreria.impuestos WHERE activo = TRUE ORDER BY orden_calculo ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
  <title>House &amp; Ice • Maestro de Productos</title>
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

      <!-- ENCABEZADO -->
      <div class="page-header">
        <div>
          <h1 class="page-title">Catálogo Maestro de Productos</h1>
          <p class="page-subtitle">Definición de SKUs, marcas licoreras, control de lotes, costos y precios base</p>
        </div>

        <button type="button" class="btn btn-gold" onclick="abrirModal()">
          + Nuevo Producto
        </button>
      </div>

      <!-- TARJETA DE TABLA -->
      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Listado de Artículos Registrados</h2>
            <span class="badge-count" id="badgeCount"><?= count($productos) ?> artículos</span>
          </div>

          <div class="search-box" style="width: 280px;">
            <span class="search-icon">🔍</span>
            <input
              type="text"
              id="buscador"
              placeholder="Buscar por SKU, descripción, marca o categoría..."
              oninput="filtrarTabla()"
            />
          </div>
        </div>

        <table class="data-table">
          <thead>
            <tr>
              <th>SKU</th>
              <th>Descripción del Producto</th>
              <th>Categoría &amp; Marca</th>
              <th class="text-center">Control Lote</th>
              <th class="text-right">Costo Promedio</th>
              <th class="text-right">Precio Base</th>
              <th class="text-center">Tributos / Impuestos</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaBody">
            <?php if (empty($productos)): ?>
              <tr>
                <td colspan="8" class="text-center" style="padding: 30px; color: #888;">
                  No hay productos registrados en el inventario. Haz clic en <strong>+ Nuevo Producto</strong> para agregar el primero.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($productos as $p): ?>
                <tr class="fila-producto" data-texto="<?= strtolower(htmlspecialchars($p['sku'] . ' ' . $p['descripcion'] . ' ' . $p['marca_nombre'] . ' ' . $p['categoria_nombre'])) ?>">
                  <td><strong style="color: #a66e2e; font-family: monospace; font-size: 0.95em;"><?= htmlspecialchars($p['sku']) ?></strong></td>
                  <td>
                    <div style="font-weight: 600; color: #211813;"><?= htmlspecialchars($p['descripcion']) ?></div>
                  </td>
                  <td>
                    <span class="badge" style="background: #eadecd; color: #5a3818;"><?= htmlspecialchars($p['categoria_nombre'] ?: 'General') ?></span>
                    <span style="font-size: 0.85em; color: #666; margin-left: 4px;"><?= htmlspecialchars($p['marca_nombre'] ?: 'Genérica') ?></span>
                  </td>
                  <td class="text-center">
                    <?php if ($p['requiere_lote']): ?>
                      <span class="status-badge active" title="Exige número de lote y fecha de vencimiento">LOTE ACTIVO</span>
                    <?php else: ?>
                      <span class="status-badge inactive">ESTÁNDAR</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-right" style="font-family: monospace; color: #666;">
                    $<?= number_format($p['costo_promedio'], 2) ?>
                  </td>
                  <td class="text-right" style="font-weight: 600; font-family: monospace; color: #211813;">
                    $<?= number_format($p['precio_base_unidad'], 2) ?>
                  </td>
                  <td class="text-center" style="font-size: 0.84em;">
                    <?php foreach (explode(' | ', $p['impuestos_nombres'] ?: 'Exento de Impuestos') as $impNom): ?>
                      <span class="code-inline" style="display: inline-block; margin: 2px; font-size: 0.76rem;"><?= htmlspecialchars($impNom) ?></span>
                    <?php endforeach; ?>
                  </td>
                  <td class="text-right action-btns">
                    <button type="button" class="btn-sm btn-outline" onclick='editarProducto(<?= json_encode($p) ?>)'>Editar</button>
                    <a href="../presentaciones/presentaciones.php" class="btn-sm btn-gold" title="Ver presentaciones de este producto">Presentaciones →</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="table-card-footer">
          <span id="footerInfo">Total: <?= count($productos) ?> artículos</span>
          <span>House &amp; Ice • Sistema Administrativo</span>
        </div>
      </div>

    </main>
  </div>

  <!-- MODAL NUEVO / EDITAR PRODUCTO -->
  <div id="modalProducto" class="modal-overlay" onclick="cerrarSiFondo(event)">
    <div class="modal-box modal-lg">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitulo">Nuevo Producto Maestro</h2>
      </div>
      <form id="formProducto" action="procesar_productos.php" method="POST" onsubmit="return validarFormularioProducto(event)">
        <input type="hidden" name="accion" id="formAccion" value="crear" />
        <input type="hidden" name="producto_id" id="producto_id" value="" />

        <div class="modal-body">
          <div class="form-row-2">
            <div class="form-group">
              <label for="sku" class="form-label">Código SKU Maestro *</label>
              <input type="text" id="sku" name="sku" class="form-control" required placeholder="Ej. PRO-001 o RON-BRU-700" maxlength="30" style="font-family: monospace; font-weight: bold; text-transform: uppercase;" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, ''); autocompletarProductoPorSku(this.value);" onblur="autocompletarProductoPorSku(this.value)" />
              <small id="skuFeedback" style="display: none; color: var(--accent-gold); font-weight: 600; font-size: 0.76rem; margin-top: 3px;"></small>
            </div>
            <div class="form-group">
              <label for="nombre" class="form-label">Descripción Comercial del Producto *</label>
              <input type="text" id="nombre" name="nombre" class="form-control" required placeholder="Ej. Ron Brugal Leyenda Botella 700ml" />
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="categoria_id" class="form-label">Categoría *</label>
              <select id="categoria_id" name="categoria_id" class="form-control" required>
                <?php foreach ($categorias as $cat): ?>
                  <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="marca_id" class="form-label">Marca / Casa Licorera *</label>
              <select id="marca_id" name="marca_id" class="form-control" required>
                <?php foreach ($marcas as $mar): ?>
                  <option value="<?= $mar['id'] ?>"><?= htmlspecialchars($mar['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="costo_promedio" class="form-label">Costo Promedio (DOP) *</label>
              <input
                type="text"
                inputmode="decimal"
                id="costo_promedio"
                name="costo_promedio"
                class="form-control"
                required
                value="0.00"
                style="font-family: monospace; font-weight: 700;"
                onkeydown="return soloNumerosDecimales(event, this)"
                oninput="limpiarDecimal(this)"
              />
            </div>
            <div class="form-group">
              <label for="precio_base_unidad" class="form-label">Precio Venta Unidad (DOP) *</label>
              <input
                type="text"
                inputmode="decimal"
                id="precio_base_unidad"
                name="precio_base_unidad"
                class="form-control"
                required
                value="0.00"
                style="font-family: monospace; font-weight: 700;"
                onkeydown="return soloNumerosDecimales(event, this)"
                oninput="limpiarDecimal(this)"
              />
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Tributos e Impuestos Aplicables (Selección Múltiple) *</label>
            <div id="contenedorImpuestosProducto" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; background: #faf6f0; padding: 10px; border: 1px solid var(--border-color, #e2d5c4); border-radius: 8px;">
              <?php foreach ($impuestosCatalogo as $imp): ?>
                <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 0.82rem; cursor: pointer; padding: 6px 8px; border-radius: 6px; background: #fff; border: 1px solid #eadecd;">
                  <input
                    type="checkbox"
                    name="impuestos[]"
                    class="chk-impuesto-prod"
                    value="<?= intval($imp['id']) ?>"
                    data-siglas="<?= htmlspecialchars($imp['siglas']) ?>"
                    onchange="alCambiarTributoProducto(this)"
                    style="margin-top: 2px; accent-color: #a66e2e;"
                  />
                  <span>
                    <strong style="color: #211813; display: block;"><?= htmlspecialchars($imp['nombre']) ?></strong>
                    <small style="color: #8a7b70; font-family: monospace;"><?= htmlspecialchars($imp['siglas']) ?> • <?= number_format((float)$imp['tasa_o_monto'], 2) ?>% (Orden #<?= intval($imp['orden_calculo']) ?>)</small>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
            <small style="color: #8a7b70; font-size: 0.75rem;">Puedes combinar ISC Alcoholes + ITBIS en bebidas alcohólicas, o marcar Exento.</small>
          </div>

          <div class="form-group" style="margin-top: 10px;">
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
              <input type="checkbox" id="requiere_lote" name="requiere_lote" value="true" style="width: 18px; height: 18px;" />
              <span style="font-weight: 500; color: #211813;">Requiere control estricto de lote y fecha de vencimiento (FEFO)</span>
            </label>
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

    <script src="productos.js?v=5"></script>
</body>
</html>
