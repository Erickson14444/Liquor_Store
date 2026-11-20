<?php
/**
 * #11 — Mantenimiento / Consulta de Lotes y Vencimientos (FEFO)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: inventario.lotes, inventario.productos_maestro, inventario.marca
 */

require_once __DIR__ . '/../../config/conexion.php';

$lotes = [];
$productos = [];

if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT l.id,
                   l.producto_maestro_id,
                   l.numero_lote,
                   l.fecha_vencimiento,
                   TO_CHAR(l.fecha_vencimiento, 'DD/MM/YYYY') AS fecha_vencimiento_fmt,
                   (l.fecha_vencimiento - CURRENT_DATE) AS dias_restantes,
                   l.cantidad_actual,
                   pm.sku,
                   COALESCE(
                       NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                       (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                       pm.sku
                   ) AS producto_nombre,
                   COALESCE(m.nombre, 'Genérica') AS marca_nombre
            FROM inventario.lotes l
            JOIN inventario.productos_maestro pm ON l.producto_maestro_id = pm.id
            LEFT JOIN inventario.marca m ON pm.marca_id = m.id
            WHERE pm.id > 0
            ORDER BY l.fecha_vencimiento ASC, l.numero_lote ASC
        ");
        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $productos = $pdo->query("
            SELECT pm.id, pm.sku, pm.requiere_lote,
                   COALESCE(
                       NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                       (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                       pm.sku
                   ) AS nombre
            FROM inventario.productos_maestro pm
            WHERE pm.id > 0
            ORDER BY pm.sku ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}

$totalLotes = count($lotes);
$vencidosCnt = 0;
$porVencerCnt = 0;
$vigentesCnt = 0;
foreach ($lotes as $lt) {
    $dias = intval($lt['dias_restantes']);
    if ($dias < 0) {
        $vencidosCnt++;
    } elseif ($dias <= 30) {
        $porVencerCnt++;
    } else {
        $vigentesCnt++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Lotes y Vencimientos (FEFO)</title>
  <link rel="stylesheet" href="../styles.css?v=14" />
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
    <?php include __DIR__ . '/../../sidebar.php'; ?>

    <main class="main-content">
      <div class="page-header">
        <div>
          <h1 class="page-title">Lotes y Vencimientos (FEFO)</h1>
          <p class="page-subtitle">Consulta de existencias por caducidad, corrección de fechas de vencimiento y alta de lotes iniciales</p>
        </div>
        <button type="button" class="btn btn-gold" onclick="abrirModal()">+ Nuevo Lote Inicial</button>
      </div>

      <!-- FILTROS RÁPIDOS DE CADUCIDAD FEFO -->
      <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px;">
        <button type="button" class="btn btn-sm btn-gold filtro-fefo-btn" data-filtro="TODOS" onclick="filtrarPorEstadoFefo('TODOS', this)">
          Todos los Lotes (<?= $totalLotes ?>)
        </button>
        <button type="button" class="btn btn-sm btn-outline filtro-fefo-btn" data-filtro="VIGENTE" onclick="filtrarPorEstadoFefo('VIGENTE', this)">
          🟢 Vigentes &gt; 30 días (<?= $vigentesCnt ?>)
        </button>
        <button type="button" class="btn btn-sm btn-outline filtro-fefo-btn" data-filtro="POR_VENCER" onclick="filtrarPorEstadoFefo('POR_VENCER', this)">
          🟠 Próximos a Vencer &le; 30 días (<?= $porVencerCnt ?>)
        </button>
        <button type="button" class="btn btn-sm btn-outline filtro-fefo-btn" data-filtro="VENCIDO" onclick="filtrarPorEstadoFefo('VENCIDO', this)">
          🔴 Vencidos (<?= $vencidosCnt ?>)
        </button>
      </div>

      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Control de Lotes por Orden de Caducidad (FEFO)</h2>
            <span class="badge-count" id="badgeCount"><?= $totalLotes ?> lotes</span>
          </div>
          <div class="search-box" style="width: 300px;">
            <span class="search-icon">🔍</span>
            <input type="text" id="buscador" placeholder="Buscar por código de lote, SKU o producto..." oninput="filtrarTabla()" />
          </div>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>NÚMERO DE LOTE</th>
                <th>PRODUCTO MAESTRO (SKU)</th>
                <th>FECHA DE VENCIMIENTO</th>
                <th class="text-center">ESTADO FEFO</th>
                <th class="text-right">EXISTENCIA ACTUAL</th>
                <th class="text-right">ACCIONES</th>
              </tr>
            </thead>
            <tbody id="tablaBody">
              <?php if (empty($lotes)): ?>
                <tr>
                  <td colspan="6" class="text-center" style="padding: 30px; color: #888;">
                    No hay lotes registrados. Haz clic en <strong>+ Nuevo Lote Inicial</strong> para registrar el primero.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($lotes as $lt):
                  $dias = intval($lt['dias_restantes']);
                  if ($dias < 0) {
                      $estadoFefo = 'VENCIDO';
                      $badgeStyle = 'background: #fdecea; color: #c62828; border: 1px solid #f5c6cb;';
                      $labelFefo  = 'VENCIDO (' . abs($dias) . ' días)';
                  } elseif ($dias <= 30) {
                      $estadoFefo = 'POR_VENCER';
                      $badgeStyle = 'background: #fff4e5; color: #b25e09; border: 1px solid #ffd599;';
                      $labelFefo  = 'POR VENCER (' . $dias . ' días)';
                  } else {
                      $estadoFefo = 'VIGENTE';
                      $badgeStyle = 'background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9;';
                      $labelFefo  = 'VIGENTE (' . $dias . ' días)';
                  }
                ?>
                  <tr class="fila-lote"
                      data-estado-fefo="<?= $estadoFefo ?>"
                      data-texto="<?= strtolower(htmlspecialchars($lt['numero_lote'] . ' ' . $lt['sku'] . ' ' . $lt['producto_nombre'] . ' ' . $lt['marca_nombre'])) ?>">
                    <td>
                      <span class="code-inline" style="font-weight: 700; color: #a66e2e;"><?= htmlspecialchars($lt['numero_lote']) ?></span>
                    </td>
                    <td>
                      <div style="font-weight: 700; color: #211813;"><?= htmlspecialchars($lt['producto_nombre']) ?></div>
                      <div style="font-size: 0.78rem; color: #8a7b70;">
                        <strong style="font-family: monospace; color: #794a15;"><?= htmlspecialchars($lt['sku']) ?></strong> • <?= htmlspecialchars($lt['marca_nombre']) ?>
                      </div>
                    </td>
                    <td style="font-family: monospace; font-weight: 600; color: #211813;">
                      📅 <?= htmlspecialchars($lt['fecha_vencimiento_fmt']) ?>
                    </td>
                    <td class="text-center">
                      <span class="badge" style="<?= $badgeStyle ?> font-weight: 700; font-size: 0.75rem; padding: 4px 10px; border-radius: 6px;">
                        <?= htmlspecialchars($labelFefo) ?>
                      </span>
                    </td>
                    <td class="text-right" style="font-family: monospace; font-weight: 700; font-size: 0.98rem; color: <?= intval($lt['cantidad_actual']) > 0 ? '#211813' : '#c62828' ?>;">
                      <?= number_format(intval($lt['cantidad_actual'])) ?> uds.
                    </td>
                    <td class="text-right action-btns">
                      <button type="button" class="btn-sm btn-outline" onclick='editarLote(<?= json_encode($lt, JSON_UNESCAPED_UNICODE) ?>)'>
                        Editar / Corregir
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerInfo">Total: <?= $totalLotes ?> lotes registrados</span>
          <span>House &amp; Ice • Control de Caducidad FEFO (Primero en Vencer, Primero en Salir)</span>
        </div>
      </div>
    </main>
  </div>

  <!-- MODAL NUEVO / EDITAR LOTE -->
  <div id="modalLote" class="modal-overlay" onclick="cerrarSiFondo(event)">
    <div class="modal-box">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitulo">Nuevo Lote Inicial</h2>
      </div>
      <form id="formLote" action="procesar_lotes.php" method="POST" onsubmit="return validarFormularioLote(event)">
        <input type="hidden" name="accion" id="formAccion" value="crear" />
        <input type="hidden" name="lote_id" id="lote_id" value="" />

        <div class="modal-body">
          <div class="form-group">
            <label for="producto_maestro_id" class="form-label">Producto Maestro *</label>
            <select id="producto_maestro_id" name="producto_maestro_id" class="form-control" required onchange="autocompletarLotePorCodigo()">
              <option value="">-- Seleccionar Producto --</option>
              <?php foreach ($productos as $pr): ?>
                <option value="<?= intval($pr['id']) ?>">
                  <?= htmlspecialchars($pr['sku'] . ' — ' . $pr['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="numero_lote" class="form-label">Número / Código de Lote *</label>
            <input
              type="text"
              id="numero_lote"
              name="numero_lote"
              class="form-control"
              required
              maxlength="50"
              placeholder="Ej. LOT-2026-001"
              style="font-family: monospace; font-weight: 700; text-transform: uppercase;"
              oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9_-]/g, ''); autocompletarLotePorCodigo();"
              onblur="autocompletarLotePorCodigo()"
            />
            <small id="loteFeedback" style="display: none; color: var(--accent-gold); font-weight: 600; font-size: 0.76rem; margin-top: 3px;"></small>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="fecha_vencimiento" class="form-label">Fecha de Vencimiento *</label>
              <input type="date" id="fecha_vencimiento" name="fecha_vencimiento" class="form-control" required value="<?= date('Y-m-d', strtotime('+180 days')) ?>" />
            </div>
            <div class="form-group">
              <label for="cantidad_actual" class="form-label">Existencia (Unidades Enteras) *</label>
              <input
                type="text"
                inputmode="numeric"
                id="cantidad_actual"
                name="cantidad_actual"
                class="form-control"
                required
                maxlength="9"
                value="0"
                style="font-family: monospace; font-weight: 700; font-size: 1.02rem;"
                onkeydown="return soloNumerosEnteros(event)"
                oninput="this.value = this.value.replace(/[^0-9]/g, '');"
              />
              <small style="color: #888; font-size: 0.74rem;">Solo números enteros (&ge; 0).</small>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-gold">Guardar Lote</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast"></div>
  <script src="lotes.js?v=1"></script>
</body>
</html>
