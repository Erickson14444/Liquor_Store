<?php
/**
 * Módulo 6: Gestión de Promociones • Motor de Reglas y Campañas
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: promociones.promociones_campanas, promociones.promociones_detalles
 */

require_once __DIR__ . '/../config/conexion.php';

$promociones = [];
$productos   = [];
$marcas      = [];
$categorias  = [];

if ($pdo) {
    try {
        // 1. Obtener campañas principales
        $stmtCamp = $pdo->query("
            SELECT pc.*
            FROM promociones.promociones_campanas pc
            ORDER BY pc.fecha_inicio DESC, pc.id DESC
        ");
        $campanasRaw = $stmtCamp->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 2. Obtener todos los detalles de reglas (incluyendo id BIGSERIAL y marca_id)
        $stmtDet = $pdo->query("
            SELECT pd.id,
                   pd.promocion_id,
                   pd.condicion_producto_id,
                   pd.condicion_categoria_id,
                   pd.marca_id,
                   pd.cantidad_requerida,
                   pd.beneficio_producto_id,
                   pd.beneficio_porcentaje,
                   pd.beneficio_monto_fijo,
                   pm_cond.sku AS producto_sku,
                   COALESCE(
                       (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm_cond.id LIMIT 1),
                       NULLIF(NULLIF(TRIM(pm_cond.nombre), ''), 'Producto sin nombre asignado'),
                       pm_cond.sku
                   ) AS producto_nombre,
                   pm_bene.sku AS beneficio_producto_sku,
                   COALESCE(
                       (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm_bene.id LIMIT 1),
                       NULLIF(NULLIF(TRIM(pm_bene.nombre), ''), 'Producto sin nombre asignado'),
                       pm_bene.sku
                   ) AS beneficio_producto_nombre,
                   m.nombre AS marca_nombre,
                   cp.nombre AS categoria_nombre
            FROM promociones.promociones_detalles pd
            LEFT JOIN inventario.productos_maestro pm_cond ON pd.condicion_producto_id = pm_cond.id
            LEFT JOIN inventario.productos_maestro pm_bene ON pd.beneficio_producto_id = pm_bene.id
            LEFT JOIN inventario.marca m ON pd.marca_id = m.id
            LEFT JOIN inventario.categoria_producto cp ON pd.condicion_categoria_id = cp.id
            ORDER BY pd.id ASC
        ");
        $detallesRaw = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $detallesPorCampana = [];
        foreach ($detallesRaw as $d) {
            $detallesPorCampana[$d['promocion_id']][] = $d;
        }

        foreach ($campanasRaw as $c) {
            $c['reglas'] = $detallesPorCampana[$c['id']] ?? [];
            $promociones[] = $c;
        }

        // 3. Catálogo de Productos (excluyendo el comodín id = 0)
        $productos = $pdo->query("
            SELECT pm.id, pm.sku,
                   COALESCE(
                       (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id LIMIT 1),
                       NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                       pm.sku
                   ) AS nombre
            FROM inventario.productos_maestro pm
            WHERE pm.id > 0
            ORDER BY pm.sku ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 4. Catálogo de Marcas (excluyendo el comodín id = 0)
        $marcas = $pdo->query("
            SELECT id, nombre
            FROM inventario.marca
            WHERE id > 0 AND estado = 'ACTIVO'
            ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 5. Catálogo de Categorías (excluyendo el comodín id = 0)
        $categorias = $pdo->query("
            SELECT id, nombre
            FROM inventario.categoria_producto
            WHERE id > 0 AND estado = 'ACTIVO'
            ORDER BY nombre ASC
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
  <title>House &amp; Ice • Gestión de Promociones</title>
  <link rel="stylesheet" href="styles.css?v=16" />
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
      <a href="../Login y Menu/menu.php" class="topbar-brand" style="text-decoration: none;">
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
    <!-- MENÚ LATERAL UNIFICADO -->
    <?php include __DIR__ . '/../sidebar.php'; ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content">

      <!-- ENCABEZADO -->
      <div class="page-header">
        <div>
          <h1 class="page-title">Gestión de Promociones y Motor de Reglas</h1>
          <p class="page-subtitle">Proceso de registro de campañas y carrito de reglas automáticas (Volumen, Happy Hour, Cross-Selling, Temporadas y Cupones)</p>
        </div>

        <button type="button" class="btn btn-gold" onclick="abrirModal()">
          + Nueva Campaña Promocional
        </button>
      </div>

      <!-- TARJETA DE TABLA DE CAMPAÑAS -->
      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Campañas y Reglas Registradas</h2>
            <span class="badge-count" id="badgeCount"><?= count($promociones) ?> campañas</span>
          </div>

          <div class="search-box" style="width: 300px;">
            <span class="search-icon">🔍</span>
            <input
              type="text"
              id="buscador"
              placeholder="Buscar por campaña, tipo, marca o producto..."
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
              <th>Reglas Configuradas (Condición &rarr; Beneficio)</th>
              <th class="text-center">Estado</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaBody">
            <?php if (empty($promociones)): ?>
              <tr>
                <td colspan="7" class="text-center" style="padding: 30px; color: #888;">
                  No hay campañas registradas. Haz clic en <strong>+ Nueva Campaña Promocional</strong> para configurar reglas.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($promociones as $pr):
                $esActiva = ($pr['estado'] === 'ACTIVA');
                $reglasCamp = $pr['reglas'] ?? [];
                $textoBusqueda = $pr['nombre_promocion'] . ' ' . $pr['tipo_promocion'];
                foreach ($reglasCamp as $rc) {
                    $textoBusqueda .= ' ' . ($rc['producto_sku'] ?? '') . ' ' . ($rc['producto_nombre'] ?? '') . ' ' . ($rc['marca_nombre'] ?? '') . ' ' . ($rc['categoria_nombre'] ?? '');
                }
              ?>
                <tr class="fila-promo" data-texto="<?= strtolower(htmlspecialchars($textoBusqueda)) ?>">
                  <td>
                    <strong style="color: #211813; font-size: 1em;"><?= htmlspecialchars($pr['nombre_promocion']) ?></strong>
                    <?php if (floatval($pr['monto_minimo_factura']) > 0): ?>
                      <div style="font-size: 0.8em; color: #8a7b70;">Mínimo factura: RD$ <?= number_format(floatval($pr['monto_minimo_factura']), 2) ?></div>
                    <?php endif; ?>
                    <div style="font-size: 0.76em; color: #a66e2e; font-weight: 600; margin-top: 2px;">
                      <?= count($reglasCamp) ?> regla(s) asociada(s)
                    </div>
                  </td>
                  <td>
                    <span class="badge" style="background: #eadecd; color: #5a3818; font-weight: 700;"><?= htmlspecialchars($pr['tipo_promocion']) ?></span>
                  </td>
                  <td>
                    <div style="font-size: 0.86em; color: #333;">Desde: <strong><?= htmlspecialchars($pr['fecha_inicio']) ?></strong></div>
                    <div style="font-size: 0.86em; color: #333;">Hasta: <strong><?= htmlspecialchars($pr['fecha_fin']) ?></strong></div>
                  </td>
                  <td>
                    <div style="font-size: 0.85em; font-weight: 600; color: #4a3b32;"><?= htmlspecialchars($pr['dias_semana'] ?: 'TODOS') ?></div>
                    <?php if (!empty($pr['hora_inicio']) && !empty($pr['hora_fin'])): ?>
                      <div style="font-size: 0.8em; color: #a66e2e; font-weight: 600;">⏰ <?= substr($pr['hora_inicio'], 0, 5) ?> - <?= substr($pr['hora_fin'], 0, 5) ?></div>
                    <?php else: ?>
                      <div style="font-size: 0.78em; color: #888;">Todo el día</div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (empty($reglasCamp)): ?>
                      <span style="color: #999; font-size: 0.84em;">Sin reglas detalladas</span>
                    <?php else: ?>
                      <div class="rule-chip-list">
                        <?php foreach ($reglasCamp as $rc):
                          $alcancePartes = [];
                          if (!empty($rc['condicion_producto_id']) && intval($rc['condicion_producto_id']) > 0) {
                              $alcancePartes[] = '🍾 ' . ($rc['producto_sku'] ?: ('Prod #' . $rc['condicion_producto_id']));
                          }
                          if (!empty($rc['marca_id']) && intval($rc['marca_id']) > 0) {
                              $alcancePartes[] = '🏷️ Marca: ' . ($rc['marca_nombre'] ?: ('#' . $rc['marca_id']));
                          }
                          if (!empty($rc['condicion_categoria_id']) && intval($rc['condicion_categoria_id']) > 0) {
                              $alcancePartes[] = '📁 Cat: ' . ($rc['categoria_nombre'] ?: ('#' . $rc['condicion_categoria_id']));
                          }
                          $alcanceTexto = !empty($alcancePartes) ? implode(' + ', $alcancePartes) : '🌐 Catálogo General';
                        ?>
                          <div class="rule-chip">
                            <strong><?= htmlspecialchars($alcanceTexto) ?></strong>
                            <span>(Cant &ge; <?= intval($rc['cantidad_requerida']) ?>)</span>
                            <?php if (!empty($rc['beneficio_producto_id']) && intval($rc['beneficio_producto_id']) > 0): ?>
                              <span>&rarr; Combo: <strong><?= htmlspecialchars($rc['beneficio_producto_sku'] ?: ('Prod #' . $rc['beneficio_producto_id'])) ?></strong></span>
                            <?php endif; ?>
                            <?php if (!empty($rc['beneficio_porcentaje'])): ?>
                              <span class="rule-chip-benefit">-<?= number_format(floatval($rc['beneficio_porcentaje']), 0) ?>% OFF</span>
                            <?php elseif (!empty($rc['beneficio_monto_fijo'])): ?>
                              <span class="rule-chip-benefit">-RD$ <?= number_format(floatval($rc['beneficio_monto_fijo']), 2) ?></span>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <label class="toggle-wrap" title="Activar o Pausar Campaña en PostgreSQL">
                      <input type="checkbox" <?= $esActiva ? 'checked' : '' ?> onchange="toggleEstadoPromo(<?= intval($pr['id']) ?>, this.checked, this)" />
                      <span class="toggle-slider"></span>
                      <span class="toggle-text"><?= $esActiva ? 'Activa' : 'Inactiva' ?></span>
                    </label>
                  </td>
                  <td class="text-right action-btns">
                    <button type="button" class="btn-sm btn-outline" onclick='editarPromo(<?= json_encode($pr, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>Editar</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="table-card-footer">
          <span id="footerInfo">Total: <?= count($promociones) ?> campañas configuradas</span>
          <span>House &amp; Ice • Sistema Administrativo</span>
        </div>
      </div>

    </main>
  </div>

  <!-- ============================================================================ -->
  <!-- MODAL PROCESO DE PROMOCIONES (3 BLOQUES: CABECERA, DINÁMICOS Y CARRITO)      -->
  <!-- ============================================================================ -->
  <div id="modalPromo" class="modal-overlay" onclick="cerrarSiFondo(event)">
    <div class="modal-box modal-xl">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitulo">Nueva Campaña Promocional y Carrito de Reglas</h2>
      </div>

      <form id="formPromo" onsubmit="guardarCampanaConReglas(event)">
        <input type="hidden" id="formAccion" value="crear" />
        <input type="hidden" id="promocion_id" value="" />

        <div class="modal-body">

          <!-- ==================================================================== -->
          <!-- BLOQUE 1: CABECERA FIJA (DATOS GENERALES DE LA CAMPAÑA)              -->
          <!-- ==================================================================== -->
          <div class="promo-block-card">
            <div class="promo-block-header">
              <div class="promo-block-title">
                <span class="promo-step-badge">1</span>
                <span>Cabecera Fija • Datos Generales de la Campaña</span>
              </div>
              <span class="promo-type-hint" id="hintTipoPromocion">Descuento por cantidad de unidades</span>
            </div>

            <div class="form-row-2">
              <div class="form-group">
                <label for="nombre_promocion" class="form-label">Nombre de la Campaña *</label>
                <input
                  type="text"
                  id="nombre_promocion"
                  class="form-control"
                  required
                  placeholder="Ej. Happy Hour Viernes de Brugal / Combo Romo + Refresco"
                  maxlength="100"
                />
              </div>
              <div class="form-group">
                <label for="tipo_promocion" class="form-label">Tipo de Promoción (Motor de Reglas) *</label>
                <select id="tipo_promocion" class="form-control" required onchange="actualizarControlesDinamicos()">
                  <option value="VOLUMEN">VOLUMEN • Descuento por Volumen (Cantidad Mínima)</option>
                  <option value="HAPPY_HOUR">HAPPY_HOUR • Happy Hour (Horario y Días Especiales)</option>
                  <option value="CROSS_SELLING">CROSS_SELLING • Venta Cruzada / Combo (Producto A + Producto B)</option>
                  <option value="FECHAS">FECHAS • Oferta de Temporada (Marcas, Categorías o Productos)</option>
                  <option value="CUPON_TICKET">CUPON_TICKET • Cupón Impreso en Factura (Monto Mínimo)</option>
                </select>
              </div>
            </div>

            <div class="form-row-3">
              <div class="form-group">
                <label for="fecha_inicio" class="form-label">Fecha de Inicio *</label>
                <input type="date" id="fecha_inicio" class="form-control" required value="<?= date('Y-m-d') ?>" />
              </div>
              <div class="form-group">
                <label for="fecha_fin" class="form-label">Fecha de Fin *</label>
                <input type="date" id="fecha_fin" class="form-control" required value="<?= date('Y-m-d', strtotime('+30 days')) ?>" />
              </div>
              <div class="form-group">
                <label for="estado_campana" class="form-label">Estado de la Campaña *</label>
                <select id="estado_campana" class="form-control">
                  <option value="ACTIVA">ACTIVA</option>
                  <option value="PROGRAMADA">PROGRAMADA</option>
                  <option value="INACTIVA">INACTIVA</option>
                  <option value="FINALIZADA">FINALIZADA</option>
                </select>
              </div>
            </div>

            <!-- Sub-bloque Cabecera para HAPPY_HOUR (Horas y Días) -->
            <div class="form-row-3" id="bloqueHorarioCabecera" style="display: none; margin-top: 4px; padding-top: 10px; border-top: 1px dashed #e5d8c3;">
              <div class="form-group">
                <label for="hora_inicio" class="form-label">⏰ Hora de Inicio (Happy Hour) *</label>
                <input type="time" id="hora_inicio" class="form-control" />
              </div>
              <div class="form-group">
                <label for="hora_fin" class="form-label">⏰ Hora de Fin (Happy Hour) *</label>
                <input type="time" id="hora_fin" class="form-control" />
              </div>
              <div class="form-group">
                <label for="dias_semana" class="form-label">📅 Días de la Semana</label>
                <select id="dias_semana" class="form-control">
                  <option value="TODOS">Todos los días</option>
                  <option value="JUE-DOM">Jueves a Domingo</option>
                  <option value="VIE-DOM">Viernes a Domingo</option>
                  <option value="VIE-SAB">Viernes y Sábado</option>
                  <option value="LUN-JUE">Lunes a Jueves</option>
                  <option value="LUN-VIE">Lunes a Viernes</option>
                </select>
              </div>
            </div>

            <!-- Sub-bloque Cabecera para CUPON_TICKET (Monto Mínimo de Factura) -->
            <div class="form-row-2" id="bloqueMontoMinimoCabecera" style="display: none; margin-top: 4px; padding-top: 10px; border-top: 1px dashed #e5d8c3;">
              <div class="form-group">
                <label for="monto_minimo_factura" class="form-label">💵 Monto Mínimo en Factura para Emitir Cupón (RD$) *</label>
                <input type="number" step="0.01" min="0" id="monto_minimo_factura" class="form-control" value="0.00" placeholder="Ej. 3000.00" />
              </div>
              <div class="form-group" style="display: flex; align-items: center; padding-top: 18px; color: #7d6752; font-size: 0.83rem;">
                El cupón se generará automáticamente cuando el total facturado alcance o supere este monto mínimo.
              </div>
            </div>
          </div>

          <!-- ==================================================================== -->
          <!-- BLOQUE 2: CONTROLES DINÁMICOS (CONSTRUCTOR DE REGLAS)                -->
          <!-- ==================================================================== -->
          <div class="promo-block-card dynamic-builder">
            <div class="promo-block-header">
              <div class="promo-block-title">
                <span class="promo-step-badge">2</span>
                <span id="tituloConstructorRegla">Controles Dinámicos • Configurar Regla a Agregar</span>
              </div>
              <span style="font-size: 0.78rem; color: #8a6d4b;">Puedes agregar múltiples reglas a la misma campaña</span>
            </div>

            <div class="form-row-3">
              <!-- Producto A (Condición) -->
              <div class="form-group" id="grupoProductoA">
                <label for="regla_producto_a" class="form-label" id="labelProductoA">Producto Condición (Producto A)</label>
                <select id="regla_producto_a" class="form-control">
                  <option value="0">-- Cualquier Producto / No específico --</option>
                  <?php foreach ($productos as $pr): ?>
                    <option value="<?= intval($pr['id']) ?>" data-texto="<?= htmlspecialchars($pr['sku'] . ' - ' . $pr['nombre']) ?>">
                      <?= htmlspecialchars($pr['sku'] . ' - ' . $pr['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Marca (Vinculada a inventario.marca para ofertas de temporada o Happy Hours por marca) -->
              <div class="form-group" id="grupoMarca">
                <label for="regla_marca_id" class="form-label">Marca (Oferta por Marca Completa)</label>
                <select id="regla_marca_id" class="form-control">
                  <option value="">-- Todas las Marcas --</option>
                  <?php foreach ($marcas as $m): ?>
                    <option value="<?= intval($m['id']) ?>" data-texto="<?= htmlspecialchars($m['nombre']) ?>">
                      <?= htmlspecialchars($m['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Categoría -->
              <div class="form-group" id="grupoCategoria">
                <label for="regla_categoria_id" class="form-label">Categoría de Producto</label>
                <select id="regla_categoria_id" class="form-control">
                  <option value="">-- Todas las Categorías --</option>
                  <?php foreach ($categorias as $cat): ?>
                    <option value="<?= intval($cat['id']) ?>" data-texto="<?= htmlspecialchars($cat['nombre']) ?>">
                      <?= htmlspecialchars($cat['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="form-row-3" style="margin-top: 6px;">
              <!-- Cantidad Mínima Requerida -->
              <div class="form-group" id="grupoCantidad">
                <label for="regla_cantidad" class="form-label" id="labelCantidad">Cantidad Mínima Requerida *</label>
                <input type="number" min="1" step="1" id="regla_cantidad" class="form-control" value="1" />
              </div>

              <!-- Producto B (Beneficio / Combo para Cross-Selling) -->
              <div class="form-group" id="grupoProductoB" style="display: none;">
                <label for="regla_producto_b" class="form-label">Producto B (Lleva en Combo / Beneficio) *</label>
                <select id="regla_producto_b" class="form-control">
                  <option value="">-- Seleccionar Producto B --</option>
                  <?php foreach ($productos as $pr): ?>
                    <option value="<?= intval($pr['id']) ?>" data-texto="<?= htmlspecialchars($pr['sku'] . ' - ' . $pr['nombre']) ?>">
                      <?= htmlspecialchars($pr['sku'] . ' - ' . $pr['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- % Descuento -->
              <div class="form-group" id="grupoBeneficioPorcentaje">
                <label for="regla_beneficio_porcentaje" class="form-label">% Descuento (1% - 100%)</label>
                <input
                  type="number"
                  step="0.5"
                  min="0.5"
                  max="100"
                  id="regla_beneficio_porcentaje"
                  class="form-control"
                  placeholder="Ej. 15"
                  oninput="if(this.value) document.getElementById('regla_beneficio_monto_fijo').value = '';"
                />
              </div>

              <!-- Monto Fijo de Rebaja / Crédito -->
              <div class="form-group" id="grupoBeneficioMonto">
                <label for="regla_beneficio_monto_fijo" class="form-label" id="labelBeneficioMonto">o Rebaja / Monto Fijo (RD$)</label>
                <input
                  type="number"
                  step="0.01"
                  min="0.01"
                  id="regla_beneficio_monto_fijo"
                  class="form-control"
                  placeholder="Ej. 250.00"
                  oninput="if(this.value) document.getElementById('regla_beneficio_porcentaje').value = '';"
                />
              </div>
            </div>

            <div style="display: flex; justify-content: flex-end; margin-top: 10px;">
              <button type="button" class="btn btn-gold" onclick="agregarReglaAlCarrito()">
                + Agregar Regla al Carrito
              </button>
            </div>
          </div>

          <!-- ==================================================================== -->
          <!-- BLOQUE 3: TABLA TEMPORAL ("CARRITO DE REGLAS")                       -->
          <!-- ==================================================================== -->
          <div class="promo-block-card rules-cart">
            <div class="promo-block-header">
              <div class="promo-block-title">
                <span class="promo-step-badge">3</span>
                <span>Tabla Temporal • "Carrito de Reglas" de la Campaña</span>
              </div>
              <span class="badge-count" id="contadorCarritoReglas">0 reglas en el carrito</span>
            </div>

            <div class="carrito-reglas-wrapper">
              <table class="carrito-reglas-table">
                <thead>
                  <tr>
                    <th style="width: 45px;">#</th>
                    <th>Alcance / Condición (Producto A, Marca o Categoría)</th>
                    <th style="width: 110px;">Cant. Mínima</th>
                    <th>Producto B (Cross-Selling)</th>
                    <th style="width: 150px;">Beneficio</th>
                    <th style="width: 95px; text-align: right;">Quitar</th>
                  </tr>
                </thead>
                <tbody id="tablaCarritoReglasBody">
                  <tr>
                    <td colspan="6" style="text-align: center; padding: 20px; color: #888;">
                      El carrito de reglas está vacío. Configura una regla en el paso 2 y presiona <strong>+ Agregar Regla al Carrito</strong>.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-gold" id="btnGuardarCampana">Guardar Campaña y Reglas</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast"></div>

  <script src="promociones.js"></script>
</body>
</html>

