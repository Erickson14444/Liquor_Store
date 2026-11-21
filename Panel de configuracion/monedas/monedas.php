<?php
/**
 * #12 — Mantenimiento de Monedas [NUEVO]
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tabla: tesoreria.monedas
 */

require_once __DIR__ . '/../../config/conexion.php';

if (!usuarioPuedeAccederMonedas()) {
    header("Location: ../../Login%20y%20Menu/menu.php?msg=" . urlencode("Acceso restringido: Solo el rol Administrador posee el permiso para gestionar Monedas."));
    exit;
}

$monedasDb = [];
if ($pdo) {
    try {
        $monedasDb = $pdo->query("
            SELECT id, codigo_iso, nombre, simbolo, tasa_cambio_referencia, es_moneda_base, activa
            FROM tesoreria.monedas
            ORDER BY CASE WHEN es_moneda_base = TRUE THEN 0 ELSE 1 END, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Monedas y Tasas de Cambio</title>
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
            <?= renderizarPillCajaTopbar() ?>
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
          <h1 class="page-title">Monedas y Tasas de Cambio</h1>
          <p class="page-subtitle">Administración de divisas (DOP, USD, EUR), símbolos monetarios, tasa de cambio del día e indicador de moneda base</p>
        </div>
        <button type="button" class="btn btn-gold" onclick="abrirModal()">+ Nueva Moneda</button>
      </div>

      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Divisas Configuradas</h2>
            <span class="badge-count" id="badgeCount"><?= count($monedasDb) ?> monedas</span>
          </div>
          <div class="search-box">
            <span class="search-icon">🔍</span>
            <input type="text" id="buscar" placeholder="Buscar por código ISO, nombre o símbolo..." oninput="renderizarTabla(this.value)" />
          </div>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>CÓDIGO ISO</th>
                <th>NOMBRE DE LA DIVISA</th>
                <th class="text-center">SÍMBOLO</th>
                <th class="text-right">TASA DE CAMBIO DEL DÍA</th>
                <th class="text-center">TIPO / ROL</th>
                <th class="text-center">ESTADO</th>
                <th class="text-right">ACCIONES</th>
              </tr>
            </thead>
            <tbody id="tablaBody"></tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerInfo">Mostrando divisas</span>
          <span style="color: var(--text-muted); font-size: 0.82rem;">* La moneda base del sistema mantiene tasa fija de 1.0000</span>
        </div>
      </div>
    </main>
  </div>

  <!-- MODAL NUEVA / EDITAR MONEDA -->
  <div id="modalMoneda" class="modal-overlay" onclick="if(event.target===this) cerrarModal()">
    <div class="modal-box">
      <div class="modal-header">
        <h2 id="modalTitulo" class="modal-title">Nueva Moneda</h2>
      </div>
      <form id="formMoneda" onsubmit="guardarMoneda(event)">
        <div class="modal-body">
          <div class="form-row-2">
            <div class="form-group">
              <label class="form-label" for="codigo_iso">CÓDIGO ISO (3 LETRAS) *</label>
              <input
                type="text"
                id="codigo_iso"
                class="form-control"
                required
                minlength="3"
                maxlength="3"
                placeholder="Ej. DOP, USD, EUR"
                style="font-family: monospace; font-weight: 700; text-transform: uppercase;"
                onkeydown="return soloLetrasIso(event)"
                oninput="this.value = this.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3); autocompletarPorIso(this.value);"
              />
              <small id="isoFeedback" style="display: none; color: var(--accent-gold); font-weight: 600; font-size: 0.76rem; margin-top: 3px;"></small>
              <small style="color: #888; font-size: 0.74rem;">Exactamente 3 letras (estándar ISO 4217).</small>
            </div>
            <div class="form-group">
              <label class="form-label" for="simbolo">SÍMBOLO MONETARIO *</label>
              <input
                type="text"
                id="simbolo"
                class="form-control"
                required
                maxlength="10"
                placeholder="Ej. RD$, US$, €"
                style="font-family: monospace; font-weight: 700;"
              />
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="nombre">NOMBRE DE LA MONEDA *</label>
            <input
              type="text"
              id="nombre"
              class="form-control"
              required
              maxlength="50"
              placeholder="Ej. Peso Dominicano, Dólar Estadounidense"
            />
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label class="form-label" for="tasa_cambio_referencia">TASA DE CAMBIO DEL DÍA (EN DOP) *</label>
              <input
                type="text"
                inputmode="decimal"
                id="tasa_cambio_referencia"
                class="form-control"
                required
                value="1.0000"
                placeholder="Ej. 59.5000"
                style="font-family: monospace; font-weight: 700; font-size: 1.02rem;"
                onkeydown="return soloNumerosDecimales(event, this)"
                oninput="limpiarDecimalCuatro(this)"
              />
              <small style="color: #888; font-size: 0.74rem;">Valor positivo mayor a 0 (hasta 4 decimales).</small>
            </div>
            <div class="form-group" style="display: flex; flex-direction: column; justify-content: center;">
              <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-top: 16px;">
                <input type="checkbox" id="es_moneda_base" onchange="alCambiarMonedaBase()" style="width: 18px; height: 18px; accent-color: #a66e2e;" />
                <span style="font-weight: 600; color: #211813;">¿Es la Moneda Base del Sistema?</span>
              </label>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-gold">Guardar Moneda</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast"></div>
  <div id="datosMonedas" data-monedas="<?= htmlspecialchars(json_encode($monedasDb, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" style="display:none;"></div>
  <script src="monedas.js?v=1"></script>
</body>
</html>
