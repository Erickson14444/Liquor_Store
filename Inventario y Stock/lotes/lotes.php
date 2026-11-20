<?php
/**
 * Módulo 3: Inventario & Stock • Consulta Dinámica de Lotes y Vencimientos (FEFO)
 * Ubicación: c:\xampp\htdocs\Liquor_Store\Inventario y Stock\lotes\lotes.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * (Vista de solo consulta dinámica sin edición manual)
 */

require_once __DIR__ . '/../../config/conexion.php';

$lotes = [];
$categorias = [];
$marcas = [];

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
                   pm.costo_promedio,
                   pm.precio_base_unidad,
                   pm.requiere_lote,
                   pm.categoria_id,
                   pm.marca_id,
                   COALESCE(
                       NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                       (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                       pm.sku
                   ) AS producto_nombre,
                   COALESCE(cp.nombre, 'General') AS categoria_nombre,
                   COALESCE(m.nombre, 'Genérica') AS marca_nombre,
                   COALESCE((
                       SELECT STRING_AGG(DISTINCT a.nombre, ', ')
                       FROM inventario.inventario_stock s
                       JOIN inventario.almacenes a ON s.almacen_id = a.id
                       WHERE s.lote_id = l.id OR (s.lote_id IS NULL AND s.producto_maestro_id = pm.id)
                   ), 'Bodega Principal') AS almacenes_ubicacion
            FROM inventario.lotes l
            JOIN inventario.productos_maestro pm ON l.producto_maestro_id = pm.id
            LEFT JOIN inventario.categoria_producto cp ON pm.categoria_id = cp.id
            LEFT JOIN inventario.marca m ON pm.marca_id = m.id
            WHERE pm.id > 0 AND l.id > 0
            ORDER BY l.fecha_vencimiento ASC, l.numero_lote ASC
        ");
        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $categorias = $pdo->query("SELECT id, nombre FROM inventario.categoria_producto WHERE id > 0 AND estado = 'ACTIVO' ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $marcas = $pdo->query("SELECT id, nombre FROM inventario.marca WHERE id > 0 AND estado = 'ACTIVO' ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Consulta de Lotes y Vencimientos (FEFO)</title>
  <link rel="stylesheet" href="../../Panel de configuracion/styles.css?v=15" />
  <style>
    .fefo-kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(195px, 1fr));
      gap: 12px;
      margin-bottom: 16px;
    }
    .fefo-kpi-card {
      background: #fff;
      border: 1px solid #eadecd;
      border-radius: 10px;
      padding: 13px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 2px 6px rgba(33, 24, 19, 0.03);
      transition: transform 0.15s ease, border-color 0.15s ease;
      cursor: pointer;
    }
    .fefo-kpi-card:hover {
      transform: translateY(-2px);
      border-color: #c89d5c;
    }
    .fefo-kpi-card.active-kpi {
      border: 2px solid #a66e2e;
      background: #fdf9f2;
    }
    .fefo-kpi-lbl {
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.4px;
      color: #8a7b70;
    }
    .fefo-kpi-val {
      font-size: 1.4rem;
      font-weight: 800;
      color: #211813;
      font-family: monospace;
      margin-top: 2px;
    }
    .fefo-kpi-sub {
      font-size: 0.73rem;
      color: #8a7b70;
      margin-top: 2px;
    }
    .fefo-toolbar {
      background: #fff;
      border: 1px solid #eadecd;
      border-radius: 10px;
      padding: 12px 16px;
      margin-bottom: 16px;
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      justify-content: space-between;
    }
    .fefo-progress-wrap {
      width: 110px;
      height: 7px;
      background: #eee4d5;
      border-radius: 99px;
      overflow: hidden;
      margin: 4px auto 0;
    }
    .fefo-progress-bar {
      height: 100%;
      border-radius: 99px;
      transition: width 0.3s ease;
    }
    .fila-lote-click {
      cursor: pointer;
      transition: background 0.12s ease;
    }
    .fila-lote-click:hover {
      background: #fdf8ef !important;
    }
  </style>
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
          <h1 class="page-title">Consulta de Lotes y Vencimientos (FEFO)</h1>
          <p class="page-subtitle">Monitoreo dinámico en tiempo real de existencias por lote, semáforo de caducidad y prioridad de salida (Solo Consulta)</p>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
          <span class="badge" style="background: #fdf3e2; color: #794a15; border: 1px solid #e4c99b; padding: 7px 12px; font-weight: 700; font-size: 0.78rem;">
            🔒 Modo Consulta Auditoría FEFO
          </span>
        </div>
      </div>

      <!-- TARJETAS DINÁMICAS DE INDICADORES FEFO (CLICKEABLES PARA FILTRAR) -->
      <div class="fefo-kpi-grid">
        <div class="fefo-kpi-card active-kpi" id="kpiCardTodos" onclick="seleccionarEstadoFefo('TODOS')">
          <div>
            <div class="fefo-kpi-lbl">Total Lotes</div>
            <div class="fefo-kpi-val" id="kpiTotalLotes">0</div>
            <div class="fefo-kpi-sub" id="kpiTotalUnidades">0 unidades en stock</div>
          </div>
          <span style="font-size: 1.6rem;">📦</span>
        </div>

        <div class="fefo-kpi-card" id="kpiCardVigente" onclick="seleccionarEstadoFefo('VIGENTE')">
          <div>
            <div class="fefo-kpi-lbl" style="color: #2e7d32;">Vigentes (&gt; 30 días)</div>
            <div class="fefo-kpi-val" style="color: #2e7d32;" id="kpiVigentes">0</div>
            <div class="fefo-kpi-sub">Óptimos para venta</div>
          </div>
          <span style="font-size: 1.6rem;">🟢</span>
        </div>

        <div class="fefo-kpi-card" id="kpiCardPorVencer" onclick="seleccionarEstadoFefo('POR_VENCER')">
          <div>
            <div class="fefo-kpi-lbl" style="color: #b25e09;">Alerta FEFO (&le; 30 días)</div>
            <div class="fefo-kpi-val" style="color: #b25e09;" id="kpiPorVencer">0</div>
            <div class="fefo-kpi-sub">Prioridad #1 de salida</div>
          </div>
          <span style="font-size: 1.6rem;">🟠</span>
        </div>

        <div class="fefo-kpi-card" id="kpiCardVencido" onclick="seleccionarEstadoFefo('VENCIDO')">
          <div>
            <div class="fefo-kpi-lbl" style="color: #c62828;">Lotes Vencidos</div>
            <div class="fefo-kpi-val" style="color: #c62828;" id="kpiVencidos">0</div>
            <div class="fefo-kpi-sub">Requieren retiro / merma</div>
          </div>
          <span style="font-size: 1.6rem;">🔴</span>
        </div>
      </div>

      <!-- BARRA DE FILTROS DINÁMICOS -->
      <div class="fefo-toolbar">
        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; flex: 1;">
          <div class="search-box" style="min-width: 260px; flex: 1;">
            <span class="search-icon">🔍</span>
            <input
              type="text"
              id="buscadorLotes"
              placeholder="Buscar lote, SKU, producto, marca o bodega..."
              oninput="renderizarConsultaLotes()"
            />
          </div>

          <select id="filtroCategoria" class="form-control" style="width: 175px; height: 38px;" onchange="renderizarConsultaLotes()">
            <option value="">📁 Todas las Categorías</option>
            <?php foreach ($categorias as $cat): ?>
              <option value="<?= htmlspecialchars($cat['nombre']) ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
            <?php endforeach; ?>
          </select>

          <select id="filtroMarca" class="form-control" style="width: 165px; height: 38px;" onchange="renderizarConsultaLotes()">
            <option value="">🏷️ Todas las Marcas</option>
            <?php foreach ($marcas as $mar): ?>
              <option value="<?= htmlspecialchars($mar['nombre']) ?>"><?= htmlspecialchars($mar['nombre']) ?></option>
            <?php endforeach; ?>
          </select>

          <select id="filtroStock" class="form-control" style="width: 170px; height: 38px;" onchange="renderizarConsultaLotes()">
            <option value="TODOS">📊 Con y Sin Existencia</option>
            <option value="CON_STOCK">✅ Solo con Stock (&gt; 0)</option>
            <option value="AGOTADOS">⚪ Solo Agotados (0 uds)</option>
          </select>

          <select id="ordenLotes" class="form-control" style="width: 215px; height: 38px;" onchange="renderizarConsultaLotes()">
            <option value="FEFO_ASC">⏳ Prioridad FEFO (Vencen primero)</option>
            <option value="FEFO_DESC">📅 Vencimiento más lejano</option>
            <option value="STOCK_DESC">📦 Mayor existencia primero</option>
            <option value="STOCK_ASC">📉 Menor existencia primero</option>
            <option value="SKU_ASC">🔤 Producto / SKU (A - Z)</option>
          </select>
        </div>

        <button type="button" class="btn btn-sm btn-outline" onclick="limpiarFiltrosLotes()" style="height: 38px;">
          🔄 Restablecer
        </button>
      </div>

      <!-- TABLA DE CONSULTA DINÁMICA (SOLO LECTURA) -->
      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Matriz de Trazabilidad y Caducidad por Lote</h2>
            <span class="badge-count" id="badgeCount"><?= count($lotes) ?> lotes</span>
          </div>
          <span style="font-size: 0.78rem; color: #8a7b70;">Haz clic en cualquier fila para ver la ficha completa del lote</span>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>PRIORIDAD / LOTE</th>
                <th>PRODUCTO MAESTRO (SKU)</th>
                <th>CATEGORÍA &amp; MARCA</th>
                <th>UBICACIÓN</th>
                <th class="text-center">VENCIMIENTO</th>
                <th class="text-center">SEMÁFORO Y VIDA ÚTIL</th>
                <th class="text-right">EXISTENCIA</th>
                <th class="text-right">DETALLE</th>
              </tr>
            </thead>
            <tbody id="tablaLotesBody"></tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerInfo">Mostrando lotes</span>
          <span>House &amp; Ice • Módulo 3: Inventario &amp; Stock (Regla FEFO)</span>
        </div>
      </div>
    </main>
  </div>

  <!-- MODAL DE SOLO CONSULTA: FICHA TÉCNICA DEL LOTE -->
  <div id="modalDetalleLote" class="modal-overlay" onclick="if(event.target===this) cerrarDetalleLote()">
    <div class="modal-box">
      <div class="modal-header">
        <h2 class="modal-title" id="detTituloLote">Ficha de Trazabilidad del Lote</h2>
      </div>
      <div class="modal-body" id="detCuerpoLote" style="font-size: 0.9rem;"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-gold" onclick="cerrarDetalleLote()">Cerrar Consulta</button>
      </div>
    </div>
  </div>

  <div id="datosLotes" data-lotes="<?= htmlspecialchars(json_encode($lotes, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" style="display:none;"></div>
  <script src="lotes.js?v=1"></script>
</body>
</html>
