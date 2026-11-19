<?php
/**
 * Mantenimiento de Secuencias Fiscales NCF (DGII)
 * House & Ice • Sistema Administrativo
 * Tabla afectada: ventas.secuencias_fiscales_dgii
 */

require_once __DIR__ . '/../../config/conexion.php';


$secuenciasDb = [];
if ($pdo) {
    try {
        $secuenciasDb = $pdo->query("SELECT id, ('NCF-SEC-0' || id::text) AS codigo, tipo_comprobante, secuencia_desde, secuencia_hasta, secuencia_actual, fecha_vencimiento, estado FROM ventas.secuencias_fiscales_dgii ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Secuencias Fiscales</title>
  <link rel="stylesheet" href="../styles.css?v=13" />
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
          <h1 class="page-title">Secuencias Fiscales</h1>
          <p class="page-subtitle">Bloques autorizados por la Dirección General de Impuestos Internos (B01, B02, B04)</p>
        </div>
        <button type="button" class="btn btn-gold" onclick="abrirModal()">+ Nuevo Bloque NCF</button>
      </div>

      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Bloques de Comprobantes Autorizados</h2>
            <span class="badge-count" id="badgeCount"><?= count($secuenciasDb) ?> secuencias</span>
          </div>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>TIPO COMPROBANTE</th>
                <th>DESDE</th>
                <th>HASTA (LÍMITE)</th>
                <th>SECUENCIA ACTUAL</th>
                <th>DISPONIBLES</th>
                <th>VENCIMIENTO</th>
                <th class="text-center">ESTADO</th>
                <th class="text-right">ACCIONES</th>
              </tr>
            </thead>
            <tbody id="tablaBody"></tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <div id="modalSecuencia" class="modal-overlay">
    <div class="modal-box">
      <div class="modal-header">
        <h2 id="modalTitulo" class="modal-title">Nuevo Bloque Fiscal DGII</h2>
      </div>
      <form id="formSecuencia" onsubmit="guardarSecuencia(event)">
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label" for="tipo_comprobante">TIPO DE COMPROBANTE *</label>
            <select id="tipo_comprobante" class="form-control" required>
              <option value="B01">B01 - Factura de Crédito Fiscal</option>
              <option value="B02">B02 - Factura de Consumo Final</option>
              <option value="B03">B03 - Nota de Débito</option>
              <option value="B04">B04 - Nota de Crédito</option>
            </select>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="secuencia_desde">SECUENCIA DESDE *</label>
              <input type="number" min="1" step="1" id="secuencia_desde" class="form-control" placeholder="Ej. 1" required onkeydown="if(['e','E','+','-','.',','].includes(event.key)) { event.preventDefault(); return false; }" oninput="this.value = this.value.replace(/[^0-9]/g, '');" />
            </div>
            <div class="form-group">
              <label class="form-label" for="secuencia_hasta">SECUENCIA HASTA *</label>
              <input type="number" min="1" step="1" id="secuencia_hasta" class="form-control" placeholder="Ej. 10000" required onkeydown="if(['e','E','+','-','.',','].includes(event.key)) { event.preventDefault(); return false; }" oninput="this.value = this.value.replace(/[^0-9]/g, '');" />
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="secuencia_actual">SECUENCIA ACTUAL *</label>
              <input type="number" min="1" step="1" id="secuencia_actual" class="form-control" placeholder="Ej. 1420" required onkeydown="if(['e','E','+','-','.',','].includes(event.key)) { event.preventDefault(); return false; }" oninput="this.value = this.value.replace(/[^0-9]/g, '');" />
            </div>
            <div class="form-group">
              <label class="form-label" for="fecha_vencimiento">FECHA VENCIMIENTO DGII *</label>
              <input type="date" id="fecha_vencimiento" class="form-control" required />
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" for="estado">ESTADO DEL BLOQUE</label>
            <select id="estado" class="form-control">
              <option value="ACTIVO">ACTIVO</option>
              <option value="AGOTADO">AGOTADO</option>
              <option value="VENCIDO">VENCIDO</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-gold">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast">Guardado correctamente</div>

    <div id="datosSecuencias" data-secuencias="<?= htmlspecialchars(json_encode($secuenciasDb, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" style="display:none;"></div>
  <script src="secuencias-dgii.js?v=2"></script>
</body>
</html>
