<?php
/**
 * Mantenimiento de Cuentas Bancarias
 * House & Ice • Sistema Administrativo
 * Tabla afectada: tesoreria.cuentas_bancarias
 */

require_once __DIR__ . '/../../config/conexion.php';


$cuentasDb = [];
$monedasDb = [];
if ($pdo) {
    try {
        $cuentasDb = $pdo->query("
            SELECT cb.id,
                   ('CTA-' || LPAD(cb.id::text, 3, '0')) AS codigo,
                   cb.nombre_banco,
                   cb.numero_cuenta,
                   cb.tipo_cuenta,
                   cb.moneda_id,
                   COALESCE(m.codigo_iso, 'DOP') AS moneda,
                   COALESCE(m.nombre, 'Peso Dominicano') AS moneda_nombre,
                   COALESCE(m.simbolo, 'RD$') AS moneda_simbolo,
                   cb.activa
            FROM tesoreria.cuentas_bancarias cb
            LEFT JOIN tesoreria.monedas m ON cb.moneda_id = m.id
            ORDER BY cb.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $monedasDb = $pdo->query("SELECT id, codigo_iso, nombre, simbolo FROM tesoreria.monedas WHERE activa = TRUE ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
          <h1 class="page-title">Cuentas Bancarias</h1>
          <p class="page-subtitle">Gestión de cuentas corrientes y de ahorro en DOP y USD</p>
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
                <th>BANCO</th>
                <th>NÚMERO DE CUENTA</th>
                <th>TIPO</th>
                <th>MONEDA</th>
                <th class="text-center">ESTADO</th>
                <th class="text-right">ACCIONES</th>
              </tr>
            </thead>
            <tbody id="tablaBody"></tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerInfo">Mostrando cuentas</span>
          <span style="color: var(--text-muted); font-size: 0.82rem;">* Directorio de cuentas para transferencias y cheques (movimientos en Tesorería)</span>
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
              <label class="form-label" for="moneda_id">MONEDA *</label>
              <select id="moneda_id" class="form-control" required>
                <?php if (!empty($monedasDb)): ?>
                  <?php foreach ($monedasDb as $mon): ?>
                    <option value="<?= intval($mon['id']) ?>" data-iso="<?= htmlspecialchars($mon['codigo_iso']) ?>">
                      <?= htmlspecialchars($mon['codigo_iso'] . ' (' . $mon['simbolo'] . ') - ' . $mon['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                <?php else: ?>
                  <option value="1" data-iso="DOP">DOP (RD$) - Peso Dominicano</option>
                  <option value="2" data-iso="USD">USD (US$) - Dólar Estadounidense</option>
                  <option value="3" data-iso="EUR">EUR (€) - Euro</option>
                <?php endif; ?>
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
  <script src="cuentas-bancarias.js?v=5"></script>
</body>
</html>
