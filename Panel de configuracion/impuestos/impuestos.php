<?php
/**
 * #13 — Mantenimiento de Impuestos y Tributos [NUEVO]
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tabla: tesoreria.impuestos
 */

require_once __DIR__ . '/../../config/conexion.php';

if (!usuarioPuedeAccederImpuestos()) {
    header("Location: ../../Login%20y%20Menu/menu.php?msg=" . urlencode("Acceso restringido: Solo el rol Administrador posee el permiso para gestionar Impuestos."));
    exit;
}

$impuestosDb = [];
if ($pdo) {
    try {
        $impuestosDb = $pdo->query("
            SELECT id, siglas, nombre, tipo_calculo, tasa_o_monto, orden_calculo, activo
            FROM tesoreria.impuestos
            ORDER BY orden_calculo ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Impuestos y Tributos</title>
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
          <h1 class="page-title">Impuestos y Tributos Fiscales</h1>
          <p class="page-subtitle">Configuración de tributos (ITBIS 18%, ITBIS 16%, ISC Alcoholes, Exento), tipo de cálculo, valor y orden de prelación</p>
        </div>
        <button type="button" class="btn btn-gold" onclick="abrirModal()">+ Nuevo Impuesto</button>
      </div>

      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Catálogo de Tributos</h2>
            <span class="badge-count" id="badgeCount"><?= count($impuestosDb) ?> impuestos</span>
          </div>
          <div class="search-box">
            <span class="search-icon">🔍</span>
            <input type="text" id="buscar" placeholder="Buscar por siglas o nombre..." oninput="renderizarTabla(this.value)" />
          </div>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>SIGLAS / CÓDIGO</th>
                <th>NOMBRE DEL TRIBUTO</th>
                <th class="text-center">TIPO DE CÁLCULO</th>
                <th class="text-right">TASA O MONTO</th>
                <th class="text-center">ORDEN DE CÁLCULO</th>
                <th class="text-center">ESTADO</th>
                <th class="text-right">ACCIONES</th>
              </tr>
            </thead>
            <tbody id="tablaBody"></tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerInfo">Mostrando impuestos</span>
          <span style="color: var(--text-muted); font-size: 0.82rem;">* El orden de cálculo define la prelación fiscal (ej. #1 ISC Alcoholes &rarr; #2 ITBIS)</span>
        </div>
      </div>
    </main>
  </div>

  <!-- MODAL NUEVO / EDITAR IMPUESTO -->
  <div id="modalImpuesto" class="modal-overlay" onclick="if(event.target===this) cerrarModal()">
    <div class="modal-box">
      <div class="modal-header">
        <h2 id="modalTitulo" class="modal-title">Nuevo Impuesto Fiscal</h2>
      </div>
      <form id="formImpuesto" onsubmit="guardarImpuesto(event)">
        <div class="modal-body">
          <div class="form-row-2">
            <div class="form-group">
              <label class="form-label" for="siglas">SIGLAS / CÓDIGO *</label>
              <input
                type="text"
                id="siglas"
                class="form-control"
                required
                maxlength="20"
                placeholder="Ej. ITBIS_18, ISC_ADVAL_10"
                style="font-family: monospace; font-weight: 700; text-transform: uppercase;"
                oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9_-]/g, ''); autocompletarPorSiglas(this.value);"
              />
              <small id="siglasFeedback" style="display: none; color: var(--accent-gold); font-weight: 600; font-size: 0.76rem; margin-top: 3px;"></small>
            </div>
            <div class="form-group">
              <label class="form-label" for="tipo_calculo">TIPO DE CÁLCULO *</label>
              <select id="tipo_calculo" class="form-control" required onchange="actualizarLabelValor()">
                <option value="PORCENTAJE">PORCENTAJE (%)</option>
                <option value="MONTO_FIJO">MONTO FIJO (RD$)</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="nombre">NOMBRE DEL TRIBUTO *</label>
            <input
              type="text"
              id="nombre"
              class="form-control"
              required
              maxlength="100"
              placeholder="Ej. ITBIS Tasa General 18%, ISC Ad Valorem Alcoholes 10%"
            />
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label class="form-label" for="tasa_o_monto" id="lblValorImpuesto">VALOR / PORCENTAJE (%) *</label>
              <input
                type="text"
                inputmode="decimal"
                id="tasa_o_monto"
                class="form-control"
                required
                value="18.00"
                placeholder="Ej. 18.00"
                style="font-family: monospace; font-weight: 700; font-size: 1.02rem;"
                onkeydown="return soloNumerosDecimales(event, this)"
                oninput="limpiarDecimal(this)"
              />
              <small style="color: #888; font-size: 0.74rem;">Solo números (&ge; 0).</small>
            </div>
            <div class="form-group">
              <label class="form-label" for="orden_calculo">ORDEN DE CÁLCULO (ENTERO &ge; 1) *</label>
              <input
                type="text"
                inputmode="numeric"
                id="orden_calculo"
                class="form-control"
                required
                maxlength="2"
                value="1"
                style="font-family: monospace; font-weight: 700; font-size: 1.02rem;"
                onkeydown="return soloNumerosEnteros(event)"
                oninput="this.value = this.value.replace(/[^0-9]/g, '');"
              />
              <small style="color: #888; font-size: 0.74rem;">Entero positivo (1 = primero, 2 = segundo).</small>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-gold">Guardar Impuesto</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast"></div>
  <div id="datosImpuestos" data-impuestos="<?= htmlspecialchars(json_encode($impuestosDb, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" style="display:none;"></div>
  <script src="impuestos.js?v=1"></script>
</body>
</html>
