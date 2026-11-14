<?php
/**
 * Mantenimiento de Cuentas Bancarias
 * House & Ice • Sistema Administrativo
 * Tabla afectada: tesoreria.cuentas_bancarias
 */

require_once __DIR__ . '/../../config/conexion.php';


$cuentasDb = [];
if ($pdo) {
    try {
        $cuentasDb = $pdo->query("SELECT id, ('CTA-' || LPAD(id::text, 3, '0')) AS codigo, nombre_banco, numero_cuenta, tipo_cuenta, moneda, saldo_actual, activa FROM tesoreria.cuentas_bancarias ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Cuentas Bancarias</title>
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
          <h1 class="page-title">Cuentas Bancarias</h1>
          <p class="page-subtitle">Gestión de cuentas corrientes y de ahorro en DOP/USD (Tabla: tesoreria.cuentas_bancarias)</p>
        </div>
        <button type="button" class="btn btn-gold" onclick="abrirModal()">+ Nueva Cuenta Bancaria</button>
      </div>

      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Cuentas Registradas</h2>
            <span class="badge-count" id="badgeCount"><?= count($cuentasDb) ?> cuentas</span>
          </div>
          <div class="search-box">
            <span class="search-icon">🔍</span>
            <input type="text" id="buscar" placeholder="Buscar banco o número de cuenta..." oninput="renderizarTabla(this.value)" />
          </div>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>BANCO</th>
                <th>NÚMERO DE CUENTA</th>
                <th>TIPO</th>
                <th>MONEDA</th>
                <th style="text-align: right;">SALDO DISPONIBLE</th>
                <th class="text-center">ESTADO</th>
                <th class="text-right">ACCIONES</th>
              </tr>
            </thead>
            <tbody id="tablaBody"></tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerInfo">Mostrando cuentas</span>
          <span style="color: var(--text-muted); font-size: 0.82rem;">* Los saldos se alteran automáticamente mediante depósitos y egresos</span>
        </div>
      </div>
    </main>
  </div>

  <!-- MODAL -->
  <div id="modalCuenta" class="modal-overlay">
    <div class="modal-box">
      <div class="modal-header">
        <h2 id="modalTitulo" class="modal-title">Nueva Cuenta Bancaria</h2>
      </div>
      <form id="formCuenta" onsubmit="guardarCuenta(event)">
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label" for="nombre_banco">NOMBRE DEL BANCO *</label>
            <input type="text" id="nombre_banco" class="form-control" placeholder="Ej. Banco Popular Dominicano, Banreservas..." required />
          </div>
          <div class="form-group">
            <label class="form-label" for="numero_cuenta">NÚMERO DE CUENTA *</label>
            <input
              type="text"
              id="numero_cuenta"
              class="form-control"
              placeholder="Ej. 7845129630 (8 a 20 dígitos)"
              inputmode="numeric"
              minlength="8"
              maxlength="20"
              onkeydown="if(['Backspace','Delete','Tab','Escape','Enter','ArrowLeft','ArrowRight','Home','End'].includes(event.key) || event.ctrlKey || event.metaKey) return true; if(!/^[0-9]$/.test(event.key)) { event.preventDefault(); return false; }"
              oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 20); if(typeof validarInputNumeroCuenta === 'function') validarInputNumeroCuenta(this);"
              required
            />
            <small id="errorNumeroCuenta" style="display: none; color: var(--accent-danger); font-size: 0.76rem; font-weight: 600; margin-top: 3px;"></small>
            <small style="color: var(--text-muted); font-size: 0.75rem;">Solo números (entre 8 y 20 dígitos, sin letras ni símbolos).</small>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="tipo_cuenta">TIPO DE CUENTA</label>
              <select id="tipo_cuenta" class="form-control">
                <option value="CORRIENTE">CORRIENTE</option>
                <option value="AHORRO">AHORRO</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="moneda">MONEDA</label>
              <select id="moneda" class="form-control">
                <option value="DOP">DOP (Pesos Dominicanos)</option>
                <option value="USD">USD (Dólares Americanos)</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-gold" id="btnGuardar">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast">Guardado correctamente</div>

    <div id="datosCuentas" data-cuentas="<?= htmlspecialchars(json_encode($cuentasDb, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" style="display:none;"></div>
  <script src="cuentas-bancarias.js?v=3"></script>
</body>
</html>
