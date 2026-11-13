<?php
/**
 * Mantenimiento de Promociones y Motor de Reglas
 * House & Ice • Sistema Administrativo
 * Tablas: promociones.promociones_campanas, promociones.promociones_detalles
 */

require_once __DIR__ . '/../../config/conexion.php';

$promociones = [];
$productos = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT pc.*, 
                   pd.id AS detalle_id,
                   pd.condicion_producto_id,
                   pd.cantidad_requerida,
                   pd.beneficio_porcentaje,
                   pd.beneficio_monto_fijo,
                   pm.sku AS producto_sku,
                   (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id LIMIT 1) AS producto_nombre
            FROM promociones.promociones_campanas pc
            LEFT JOIN promociones.promociones_detalles pd ON pc.id = pd.promocion_id
            LEFT JOIN inventario.productos_maestro pm ON pd.condicion_producto_id = pm.id
            ORDER BY pc.fecha_inicio DESC, pc.id DESC
        ");
        $promociones = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $productos = $pdo->query("
            SELECT pm.id, pm.sku, 
                   COALESCE((SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id LIMIT 1), pm.sku) AS nombre
            FROM inventario.productos_maestro pm
            ORDER BY pm.sku ASC
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
  <title>House &amp; Ice • Promociones</title>
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
        <div class="user-avatar">NM</div>
        <div class="user-info-col">
          <div class="user-name">Nathalia Meléndez</div>
          <div class="user-sub-line">
            <span class="user-role-text">Administrador</span>
            <span class="user-bullet">•</span>
            <span class="user-caja-pill open">
              <span class="pill-dot green"></span>
              Caja 01
            </span>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="app-container">
    <!-- MENÚ LATERAL (PANEL DE CONFIGURACIÓN AL FINAL) -->
    <?php include __DIR__ . '/../sidebar.php'; ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content">

      <!-- ENCABEZADO -->
      <div class="page-header">
        <div>
          <h1 class="page-title">Promociones</h1>
          <p class="page-subtitle">Reglas automáticas de caja: descuentos por volumen, Happy Hour, combos 2x1 y cupones</p>
        </div>

        <button type="button" class="btn btn-gold" onclick="abrirModal()">
          + Nueva Campaña Promocional
        </button>
      </div>

      <!-- TARJETA DE TABLA -->
      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Campañas y Reglas Registradas</h2>
            <span class="badge-count" id="badgeCount"><?= count($promociones) ?> campañas</span>
          </div>

          <div class="search-box" style="width: 280px;">
            <span class="search-icon">🔍</span>
            <input
              type="text"
              id="buscador"
              placeholder="Buscar por campaña, tipo o producto..."
              oninput="filtrarTabla()"
            />
          </div>
        </div>

        <table class="data-table">
          <thead>
            <tr>
              <th>Campaña Promocional</th>
              <th>Tipo de Regla</th>
              <th>Vigencia (Fechas)</th>
              <th>Horario / Días</th>
              <th>Condición &amp; Beneficio</th>
              <th class="text-center">Estado</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaBody">
            <?php if (empty($promociones)): ?>
              <tr>
                <td colspan="7" class="text-center" style="padding: 30px; color: #888;">
                  No hay promociones activas. Haz clic en <strong>+ Nueva Campaña Promocional</strong> para crear la primera regla.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($promociones as $pr): 
                $esActiva = ($pr['estado'] === 'ACTIVA');
              ?>
                <tr class="fila-promo" data-texto="<?= strtolower(htmlspecialchars($pr['nombre_promocion'] . ' ' . $pr['tipo_promocion'] . ' ' . ($pr['producto_sku'] ?? ''))) ?>">
                  <td>
                    <strong style="color: #211813; font-size: 1em;"><?= htmlspecialchars($pr['nombre_promocion']) ?></strong>
                    <?php if ($pr['monto_minimo_factura'] > 0): ?>
                      <div style="font-size: 0.8em; color: #8a7b70;">Mínimo factura: DOP $<?= number_format($pr['monto_minimo_factura'], 2) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge" style="background: #eadecd; color: #5a3818;"><?= htmlspecialchars($pr['tipo_promocion']) ?></span>
                  </td>
                  <td>
                    <div style="font-size: 0.88em; color: #333;">Desde: <?= htmlspecialchars($pr['fecha_inicio']) ?></div>
                    <div style="font-size: 0.88em; color: #333;">Hasta: <?= htmlspecialchars($pr['fecha_fin']) ?></div>
                  </td>
                  <td>
                    <div style="font-size: 0.85em;"><?= htmlspecialchars($pr['dias_semana'] ?: 'Todos los días') ?></div>
                    <?php if ($pr['hora_inicio']): ?>
                      <div style="font-size: 0.8em; color: #777;"><?= substr($pr['hora_inicio'], 0, 5) ?> - <?= substr($pr['hora_fin'], 0, 5) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($pr['producto_sku']): ?>
                      <div><strong style="color: #a66e2e;"><?= htmlspecialchars($pr['producto_sku']) ?></strong> (Cant: ≥ <?= $pr['cantidad_requerida'] ?>)</div>
                    <?php else: ?>
                      <div>Aplica a todo el catálogo</div>
                    <?php endif; ?>
                    <?php if ($pr['beneficio_porcentaje']): ?>
                      <div style="color: #2e7d32; font-weight: bold;">Descuento: <?= number_format($pr['beneficio_porcentaje'], 0) ?>%</div>
                    <?php elseif ($pr['beneficio_monto_fijo']): ?>
                      <div style="color: #2e7d32; font-weight: bold;">Rebaja fija: $<?= number_format($pr['beneficio_monto_fijo'], 2) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <span class="status-badge <?= $esActiva ? 'active' : 'inactive' ?>">
                      <?= htmlspecialchars($pr['estado']) ?>
                    </span>
                  </td>
                  <td class="text-right action-btns">
                    <button type="button" class="btn-sm btn-outline" onclick='editarPromo(<?= json_encode($pr) ?>)'>Editar</button>
                    <button type="button" class="btn-sm <?= $esActiva ? 'btn-danger' : 'btn-gold' ?>" onclick="toggleEstadoPromo('<?= $pr['id'] ?>', '<?= $pr['estado'] ?>')">
                      <?= $esActiva ? 'Pausar' : 'Activar' ?>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="table-card-footer">
          <span id="footerInfo">Total: <?= count($promociones) ?> promociones configuradas</span>
          <span>House &amp; Ice • Sistema Administrativo</span>
        </div>
      </div>

    </main>
  </div>

  <!-- MODAL NUEVA / EDITAR PROMOCION -->
  <div id="modalPromo" class="modal-overlay" onclick="cerrarSiFondo(event)">
    <div class="modal-box modal-lg">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitulo">Nueva Campaña Promocional</h2>
      </div>
      <form id="formPromo" action="procesar_promociones.php" method="POST">
        <input type="hidden" name="accion" id="formAccion" value="crear" />
        <input type="hidden" name="promocion_id" id="promocion_id" value="" />

        <div class="modal-body">
          <div class="form-row-2">
            <div class="form-group">
              <label for="nombre_promocion" class="form-label">Nombre de la Promoción *</label>
              <input type="text" id="nombre_promocion" name="nombre_promocion" class="form-control" required placeholder="Ej. Viernes de Whiskies 15% OFF" maxlength="100" />
            </div>
            <div class="form-group">
              <label for="tipo_promocion" class="form-label">Tipo de Motor de Regla *</label>
              <select id="tipo_promocion" name="tipo_promocion" class="form-control" required>
                <option value="VOLUMEN">Descuento por Volumen (3 o más)</option>
                <option value="HAPPY_HOUR">Happy Hour (Horario Especial)</option>
                <option value="CROSS_SELLING">Combo / Venta Cruzada (X + Y)</option>
                <option value="FECHAS">Temporada Especial (Black Friday / Navidad)</option>
                <option value="CUPON_TICKET">Cupón Impreso en Factura</option>
              </select>
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="fecha_inicio" class="form-label">Fecha de Inicio *</label>
              <input type="date" id="fecha_inicio" name="fecha_inicio" class="form-control" required value="<?= date('Y-m-d') ?>" />
            </div>
            <div class="form-group">
              <label for="fecha_fin" class="form-label">Fecha de Fin *</label>
              <input type="date" id="fecha_fin" name="fecha_fin" class="form-control" required value="<?= date('Y-m-d', strtotime('+30 days')) ?>" />
            </div>
          </div>

          <div class="form-row-3">
            <div class="form-group">
              <label for="hora_inicio" class="form-label">Hora Inicio (Opcional)</label>
              <input type="time" id="hora_inicio" name="hora_inicio" class="form-control" />
            </div>
            <div class="form-group">
              <label for="hora_fin" class="form-label">Hora Fin (Opcional)</label>
              <input type="time" id="hora_fin" name="hora_fin" class="form-control" />
            </div>
            <div class="form-group">
              <label for="monto_minimo_factura" class="form-label">Monto Mínimo Factura</label>
              <input type="number" step="0.01" min="0" id="monto_minimo_factura" name="monto_minimo_factura" class="form-control" value="0.00" />
            </div>
          </div>

          <div style="background: #faf7f2; border: 1px solid #eedcb9; border-radius: 8px; padding: 14px; margin-top: 10px;">
            <div style="font-weight: 600; color: #a66e2e; margin-bottom: 8px;">Regla de Descuento / Beneficio</div>
            <div class="form-row-3">
              <div class="form-group">
                <label for="condicion_producto_id" class="form-label">Producto Específico</label>
                <select id="condicion_producto_id" name="condicion_producto_id" class="form-control">
                  <option value="">-- Todos los Productos --</option>
                  <?php foreach ($productos as $pr): ?>
                    <option value="<?= $pr['id'] ?>"><?= htmlspecialchars($pr['sku'] . ' - ' . $pr['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label for="cantidad_requerida" class="form-label">Cantidad Mínima</label>
                <input type="number" min="1" id="cantidad_requerida" name="cantidad_requerida" class="form-control" value="1" />
              </div>
              <div class="form-group">
                <label for="beneficio_porcentaje" class="form-label">% Descuento Directo</label>
                <input type="number" step="0.5" min="1" max="100" id="beneficio_porcentaje" name="beneficio_porcentaje" class="form-control" placeholder="Ej. 15%" />
              </div>
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

    <script src="promociones.js"></script>
</body>
</html>

