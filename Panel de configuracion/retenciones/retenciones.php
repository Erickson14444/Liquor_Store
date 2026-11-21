<?php
/**
 * #14 — Mantenimiento de Retenciones DGII [NUEVO]
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tabla: tesoreria.retenciones
 */

require_once __DIR__ . '/../../config/conexion.php';

if (!usuarioPuedeAccederRetenciones()) {
    header("Location: ../../Login%20y%20Menu/menu.php?msg=" . urlencode("Acceso restringido: Solo el rol Administrador posee el permiso para gestionar Retenciones Fiscales."));
    exit;
}

$retencionesDb = [];
if ($pdo) {
    try {
        $retencionesDb = $pdo->query("
            SELECT id, siglas, nombre, base_calculo, porcentaje, activo
            FROM tesoreria.retenciones
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Retenciones DGII</title>
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
          <h1 class="page-title">Retenciones Fiscales DGII</h1>
          <p class="page-subtitle">Configuración de retenciones de compras (ISR 2%, ISR 10%, ITBIS 30%, ITBIS 100%) y su base de cálculo (Subtotal o Impuesto)</p>
        </div>
        <button type="button" class="btn btn-gold" onclick="abrirModal()">+ Nueva Retención DGII</button>
      </div>

      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Retenciones Configuradas (Reporte 606)</h2>
            <span class="badge-count" id="badgeCount"><?= count($retencionesDb) ?> retenciones</span>
          </div>
          <div class="search-box">
            <span class="search-icon">🔍</span>
            <input type="text" id="buscar" placeholder="Buscar por siglas, nombre o base..." oninput="renderizarTabla(this.value)" />
          </div>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>SIGLAS / CÓDIGO</th>
                <th>NOMBRE DE LA RETENCIÓN</th>
                <th class="text-center">BASE DE CÁLCULO</th>
                <th class="text-right">PORCENTAJE (%)</th>
                <th class="text-center">ESTADO</th>
                <th class="text-right">ACCIONES</th>
              </tr>
            </thead>
            <tbody id="tablaBody"></tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerInfo">Mostrando retenciones</span>
          <span style="color: var(--text-muted); font-size: 0.82rem;">* SUBTOTAL calcula sobre el monto neto antes de impuestos (ISR); IMPUESTO calcula sobre el ITBIS facturado</span>
        </div>
      </div>
    </main>
  </div>

  <!-- MODAL NUEVA / EDITAR RETENCIÓN -->
  <div id="modalRetencion" class="modal-overlay" onclick="if(event.target===this) cerrarModal()">
    <div class="modal-box">
      <div class="modal-header">
        <h2 id="modalTitulo" class="modal-title">Nueva Retención Fiscal DGII</h2>
      </div>
      <form id="formRetencion" onsubmit="guardarRetencion(event)">
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
                placeholder="Ej. RET_ISR_10, RET_ITBIS_30"
                style="font-family: monospace; font-weight: 700; text-transform: uppercase;"
                oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9_-]/g, ''); autocompletarPorSiglas(this.value);"
              />
              <small id="siglasFeedback" style="display: none; color: var(--accent-gold); font-weight: 600; font-size: 0.76rem; margin-top: 3px;"></small>
            </div>
            <div class="form-group">
              <label class="form-label" for="base_calculo">BASE DE CÁLCULO *</label>
              <select id="base_calculo" class="form-control" required>
                <option value="SUBTOTAL">SUBTOTAL (Sobre Monto Neto • ISR)</option>
                <option value="IMPUESTO">IMPUESTO (Sobre ITBIS Facturado)</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="nombre">NOMBRE DE LA RETENCIÓN *</label>
            <input
              type="text"
              id="nombre"
              class="form-control"
              required
              maxlength="100"
              placeholder="Ej. Retención 10% ISR (Honorarios Profesionales)"
            />
          </div>

          <div class="form-group">
            <label class="form-label" for="porcentaje">PORCENTAJE DE RETENCIÓN (0.01% A 100.00%) *</label>
            <input
              type="text"
              inputmode="decimal"
              id="porcentaje"
              class="form-control"
              required
              value="10.00"
              placeholder="Ej. 10.00 o 30.00"
              style="font-family: monospace; font-weight: 700; font-size: 1.02rem;"
              onkeydown="return soloNumerosDecimales(event, this)"
              oninput="limpiarDecimalDos(this)"
            />
            <small style="color: #888; font-size: 0.74rem;">Solo números positivos entre 0.01 y 100.00.</small>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-gold">Guardar Retención</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast"></div>
  <div id="datosRetenciones" data-retenciones="<?= htmlspecialchars(json_encode($retencionesDb, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" style="display:none;"></div>
  <script src="retenciones.js?v=1"></script>
</body>
</html>
