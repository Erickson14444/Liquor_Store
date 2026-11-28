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
            SELECT pcb.*,
                   pm.sku,
                   pm.precio_base_unidad,
                   COALESCE(
                       NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                       (SELECT pcb2.descripcion_presentacion
                        FROM inventario.producto_codigos_barra pcb2
                        WHERE pcb2.producto_maestro_id = pm.id
                        ORDER BY pcb2.factor_multiplicador ASC
                        LIMIT 1),
                       pm.sku
                   ) AS producto_nombre
            FROM inventario.producto_codigos_barra pcb
            JOIN inventario.productos_maestro pm ON pcb.producto_maestro_id = pm.id
            WHERE pm.id > 0
            ORDER BY producto_nombre ASC, pcb.factor_multiplicador ASC
        ");
        $presentaciones = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $productosMaestros = $pdo->query("
            SELECT pm.id,
                   pm.sku,
                   pm.precio_base_unidad,
                   COALESCE(
                       NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                       (SELECT pcb2.descripcion_presentacion
                        FROM inventario.producto_codigos_barra pcb2
                        WHERE pcb2.producto_maestro_id = pm.id
                        ORDER BY pcb2.factor_multiplicador ASC
                        LIMIT 1),
                       pm.sku
                   ) AS nombre
            FROM inventario.productos_maestro pm
            WHERE pm.id > 0
            ORDER BY nombre ASC, pm.sku ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
  <style>
    /* Selector buscable de Producto Maestro (estilo Órdenes de Compra) */
    #modalPresentacion .modal-box {
      overflow: visible;
    }
    #modalPresentacion .modal-body {
      overflow: visible;
    }
    .pres-prod-picker {
      position: relative;
      width: 100%;
    }
    .pres-prod-input-wrap {
      position: relative;
    }
    .pres-prod-search-icon {
      position: absolute;
      left: 10px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 0.85rem;
      opacity: 0.65;
      pointer-events: none;
    }
    #inputBuscarProductoMaestro {
      width: 100%;
      padding-left: 32px !important;
      box-sizing: border-box;
    }
    .pres-prod-pill {
      display: none;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      min-height: 36px;
      padding: 5px 10px;
      background: #fdf8ee;
      border: 1.5px solid #d4a359;
      border-radius: 6px;
      box-sizing: border-box;
    }
    .pres-prod-pill-info {
      min-width: 0;
      flex: 1;
    }
    .pres-prod-pill-name {
      font-size: 0.84rem;
      font-weight: 700;
      color: #211813;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .pres-prod-pill-sub {
      font-size: 0.71rem;
      color: #7a6b5d;
      font-family: var(--font-mono, monospace);
    }
    .pres-prod-pill-btn {
      background: #ffffff;
      border: 1px solid #d8c6ae;
      color: #6b441a;
      border-radius: 5px;
      padding: 3px 8px;
      font-size: 0.72rem;
      font-weight: 700;
      cursor: pointer;
      white-space: nowrap;
      flex-shrink: 0;
    }
    .pres-prod-pill-btn:hover {
      background: #f5ecde;
      border-color: #a66e2e;
    }
    .pres-prod-dropdown {
      display: none;
      position: absolute;
      top: calc(100% + 4px);
      left: 0;
      right: 0;
      max-height: 250px;
      overflow-y: auto;
      background: #ffffff;
      border: 1px solid #d4a359;
      border-radius: 8px;
      box-shadow: 0 12px 28px rgba(33, 24, 19, 0.18);
      z-index: 2000;
    }
    .pres-prod-opt {
      padding: 8px 12px;
      cursor: pointer;
      border-bottom: 1px solid #f2ebe1;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
    }
    .pres-prod-opt:last-child {
      border-bottom: none;
    }
    .pres-prod-opt:hover,
    .pres-prod-opt.active {
      background: #fdf5ea;
    }
    .pres-prod-opt-name {
      font-size: 0.84rem;
      font-weight: 700;
      color: #211813;
      line-height: 1.25;
    }
    .pres-prod-opt-sku {
      font-size: 0.72rem;
      color: #8c8173;
      font-family: var(--font-mono, monospace);
      margin-top: 1px;
    }
    .pres-prod-opt-price {
      font-size: 0.78rem;
      font-weight: 700;
      color: #a66e2e;
      font-family: var(--font-mono, monospace);
      white-space: nowrap;
      flex-shrink: 0;
    }
    .pres-prod-empty {
      padding: 12px;
      text-align: center;
      font-size: 0.8rem;
      color: #8c8173;
    }
    .pres-prod-more {
      padding: 6px 12px;
      text-align: center;
      font-size: 0.72rem;
      color: #8c8173;
      background: #faf7f2;
      border-top: 1px solid #efe7da;
    }
  </style>
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
              placeholder="Buscar por producto, descripción, barra o SKU..."
              oninput="filtrarTabla()"
            />
          </div>
        </div>

        <table class="data-table">
          <thead>
            <tr>
              <th>Código de Barra</th>
              <th>Descripción de la Presentación</th>
              <th>Producto Maestro</th>
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
                <tr class="fila-presentacion" data-texto="<?= strtolower(htmlspecialchars($pres['codigo_barra'] . ' ' . $pres['descripcion_presentacion'] . ' ' . $pres['producto_nombre'] . ' ' . $pres['sku'])) ?>">
                  <td>
                    <strong style="color: #a66e2e; font-family: monospace; font-size: 0.95em;">
                      <?= htmlspecialchars($pres['codigo_barra']) ?>
                    </strong>
                  </td>
                  <td>
                    <div style="font-weight: 600; color: #211813;"><?= htmlspecialchars($pres['descripcion_presentacion']) ?></div>
                  </td>
                  <td>
                    <div style="font-weight: 600; color: #211813; font-size: 0.85rem; line-height: 1.25;"><?= htmlspecialchars($pres['producto_nombre']) ?></div>
                    <span class="badge" style="background: #f3ece1; color: #6b441a; font-family: monospace; font-size: 0.7rem; margin-top: 2px;"><?= htmlspecialchars($pres['sku']) ?></span>
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
      <form id="formPresentacion" action="procesar_presentaciones.php" method="POST" onsubmit="return validarFormularioPresentacion(event)">
        <input type="hidden" name="accion" id="formAccion" value="crear" />
        <input type="hidden" name="presentacion_id" id="presentacion_id" value="" />

        <div class="modal-body">
          <div class="form-row-2">
            <div class="form-group">
              <label for="inputBuscarProductoMaestro" class="form-label">Producto Maestro *</label>
              <div class="pres-prod-picker" id="presProdPicker">
                <input type="hidden" id="producto_maestro_id" name="producto_maestro_id" value="" />

                <div id="presProdPill" class="pres-prod-pill">
                  <div class="pres-prod-pill-info">
                    <div id="presProdPillName" class="pres-prod-pill-name"></div>
                    <div id="presProdPillSub" class="pres-prod-pill-sub"></div>
                  </div>
                  <button type="button" class="pres-prod-pill-btn" onclick="limpiarProductoMaestro(true)">✕ Cambiar</button>
                </div>

                <div id="presProdInputWrap" class="pres-prod-input-wrap">
                  <span class="pres-prod-search-icon">🔍</span>
                  <input
                    type="text"
                    id="inputBuscarProductoMaestro"
                    class="form-control"
                    autocomplete="off"
                    placeholder="Buscar producto por nombre o SKU..."
                    onfocus="filtrarProductosMaestro()"
                    oninput="filtrarProductosMaestro()"
                    onkeydown="teclaProductoMaestro(event)"
                  />
                </div>

                <div id="dropdownProductosMaestros" class="pres-prod-dropdown"></div>
              </div>
              <small id="errorProductoMaestro" style="display: none; color: var(--accent-danger); font-size: 0.76rem; font-weight: 600; margin-top: 3px;"></small>
            </div>
            <div class="form-group">
              <label for="codigo_barra" class="form-label">Código de Barra (EAN-13 / UPC) *</label>
              <input
                type="text"
                id="codigo_barra"
                name="codigo_barra"
                class="form-control"
                required
                placeholder="Ej. 7460100123456 (8 a 14 dígitos)"
                inputmode="numeric"
                minlength="8"
                maxlength="14"
                style="font-family: monospace; font-weight: 600;"
                onkeydown="if(['Backspace','Delete','Tab','Escape','Enter','ArrowLeft','ArrowRight','Home','End'].includes(event.key) || event.ctrlKey || event.metaKey) return true; if(!/^[0-9]$/.test(event.key)) { event.preventDefault(); return false; }"
                oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 14); validarInputCodigoBarra(this);"
              />
              <small id="errorCodigoBarra" style="display: none; color: var(--accent-danger); font-size: 0.76rem; font-weight: 600; margin-top: 3px;"></small>
              <small style="color: var(--text-muted); font-size: 0.74rem;">Solo números (entre 8 y 14 dígitos EAN-8 / UPC / EAN-13).</small>
            </div>
          </div>

          <div class="form-group">
            <label for="descripcion_presentacion" class="form-label">Descripción de la Presentación *</label>
            <input type="text" id="descripcion_presentacion" name="descripcion_presentacion" class="form-control" required placeholder="Ej. Ron Brugal Leyenda Caja x 6 Botellas" maxlength="100" />
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="factor_multiplicador" class="form-label">Factor Multiplicador (Unidades que contiene) *</label>
              <input
                type="number"
                min="1"
                step="1"
                id="factor_multiplicador"
                name="factor_multiplicador"
                class="form-control"
                required
                value="1"
                onkeydown="if(['e','E','+','-','.',','].includes(event.key)) { event.preventDefault(); return false; }"
                oninput="this.value = this.value.replace(/[^0-9]/g, ''); actualizarPrecioSugerido();"
              />
            </div>
            <div class="form-group">
              <label for="precio_venta_presentacion" class="form-label">Precio de Venta Presentación (DOP) *</label>
              <input
                type="number"
                step="0.01"
                min="0"
                id="precio_venta_presentacion"
                name="precio_venta_presentacion"
                class="form-control"
                required
                value="0.00"
                onkeydown="if(['e','E','+','-'].includes(event.key)) { event.preventDefault(); return false; }"
              />
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

  <script>
    const PRODUCTOS_MAESTROS_LISTA = <?= json_encode($productosMaestros, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="presentaciones.js?v=2"></script>
</body>
</html>
