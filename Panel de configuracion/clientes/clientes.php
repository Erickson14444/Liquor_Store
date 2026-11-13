<?php
/**
 * Mantenimiento de Clientes
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: identidad.clientes, identidad.personas
 */

require_once __DIR__ . '/../../config/conexion.php';

$clientes = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT c.cliente_id, c.codigo_cliente, c.categoria_cliente, c.permite_credito, c.limite_credito, c.creado_en,
                   p.persona_id, p.tipo_documento, p.documento_identidad, p.nombre_razon_social, p.nombre_comercial,
                   p.tipo_persona, p.email, p.telefono_principal, p.fecha_nacimiento, p.estado
            FROM identidad.clientes c
            JOIN identidad.personas p ON c.persona_id = p.persona_id
            ORDER BY c.creado_en DESC
        ");
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $clientes = [];
    }
}

$msg = $_GET['msg'] ?? '';
$tipoMsg = $_GET['tipo'] ?? 'exito';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Clientes</title>
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
    <!-- MENÚ LATERAL -->
    <?php include __DIR__ . '/../sidebar.php'; ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content">

      <!-- ENCABEZADO -->
      <div class="page-header">
        <div>
          <h1 class="page-title">Directorio de Clientes</h1>
          <p class="page-subtitle">Gestión de clientes autorizados, identificación fiscal (RNC/Cédula) y límites de crédito</p>
        </div>

        <button type="button" class="btn btn-gold" onclick="abrirModal()">
          + Nuevo Cliente
        </button>
      </div>

      <!-- TARJETA DE TABLA -->
      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Listado de Clientes Registrados</h2>
            <span class="badge-count" id="badgeCount"><?= count($clientes) ?> registrados</span>
          </div>

          <div class="search-box" style="width: 280px;">
            <span class="search-icon">🔍</span>
            <input
              type="text"
              id="buscador"
              placeholder="Buscar por código, nombre o documento..."
              oninput="filtrarTabla()"
            />
          </div>
        </div>

        <table class="data-table">
          <thead>
            <tr>
              <th>Código</th>
              <th>Cliente / Razón Social</th>
              <th>Documento</th>
              <th>Contactos</th>
              <th class="text-center">Categoría</th>
              <th class="text-right">Crédito</th>
              <th class="text-center">Estado</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaBody">
            <?php if (empty($clientes)): ?>
              <tr>
                <td colspan="8" class="text-center" style="padding: 30px; color: #888;">
                  No hay clientes registrados en la base de datos. Haz clic en <strong>+ Nuevo Cliente</strong> para crear el primero.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($clientes as $cli): 
                $nombre = htmlspecialchars($cli['nombre_razon_social']);
                $esActivo = !empty($cli['estado']);
                $permiteCred = !empty($cli['permite_credito']);
                $cat = strtoupper($cli['categoria_cliente'] ?? 'REGULAR');
                $catClass = ($cat === 'VIP') ? 'cat-vip' : (($cat === 'MAYORISTA') ? 'cat-mayorista' : 'cat-regular');
              ?>
                <tr class="fila-cliente" data-texto="<?= strtolower(htmlspecialchars($cli['codigo_cliente'] . ' ' . $cli['nombre_razon_social'] . ' ' . ($cli['nombre_comercial'] ?? '') . ' ' . $cli['documento_identidad'] . ' ' . ($cli['email'] ?? ''))) ?>">
                  <td><strong><?= htmlspecialchars($cli['codigo_cliente']) ?></strong></td>
                  <td>
                    <div style="font-weight: 600; color: #fff;">
                      <?= $nombre ?>
                      <span class="badge-persona"><?= htmlspecialchars($cli['tipo_persona'] ?? 'FISICA') ?></span>
                    </div>
                    <?php if (!empty($cli['nombre_comercial'])): ?>
                      <div style="font-size: 0.8em; color: #d4a359;"><?= htmlspecialchars($cli['nombre_comercial']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge" style="background: rgba(212, 163, 89, 0.2); color: #ffd89b; border: 1px solid rgba(212, 163, 89, 0.4);"><?= htmlspecialchars($cli['tipo_documento']) ?></span>
                    <span style="font-family: monospace; font-size: 0.9em; margin-left: 4px;"><?= htmlspecialchars($cli['documento_identidad']) ?></span>
                  </td>
                  <td>
                    <div>📞 <?= htmlspecialchars($cli['telefono_principal'] ?: 'S/N') ?></div>
                    <div style="font-size: 0.82em; color: #aaa;">✉️ <?= htmlspecialchars($cli['email'] ?: 'S/N') ?></div>
                  </td>
                  <td class="text-center">
                    <span class="cat-badge <?= $catClass ?>"><?= $cat ?></span>
                  </td>
                  <td class="text-right" style="font-weight: 600; font-family: monospace; color: <?= $permiteCred ? '#2ecc71' : '#888' ?>;">
                    <?php if ($permiteCred): ?>
                      DOP $<?= number_format($cli['limite_credito'], 2) ?>
                    <?php else: ?>
                      <span style="font-size: 0.82rem; font-weight: normal; color: #888;">Sólo Contado</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <span class="status-badge <?= $esActivo ? 'active' : 'inactive' ?>" id="status-<?= $cli['cliente_id'] ?>">
                      <?= $esActivo ? 'ACTIVO' : 'INACTIVO' ?>
                    </span>
                  </td>
                  <td class="text-right action-btns">
                    <button type="button" class="btn-sm btn-outline" onclick='editarCliente(<?= json_encode($cli) ?>)'>Editar</button>
                    <button type="button" class="btn-sm <?= $esActivo ? 'btn-danger' : 'btn-gold' ?>" onclick="toggleEstadoCliente('<?= $cli['cliente_id'] ?>', <?= $esActivo ? 'true' : 'false' ?>)">
                      <?= $esActivo ? 'Desactivar' : 'Activar' ?>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="table-card-footer">
          <span id="footerInfo">Total: <?= count($clientes) ?> clientes</span>
          <span>House &amp; Ice • Sistema Administrativo</span>
        </div>
      </div>

    </main>
  </div>

  <!-- MODAL NUEVO / EDITAR CLIENTE -->
  <div id="modalCliente" class="modal-overlay" onclick="cerrarSiFondo(event)">
    <div class="modal-box modal-lg">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitulo">Nuevo Cliente</h2>
      </div>
      <form id="formCliente" action="procesar_clientes.php" method="POST">
        <input type="hidden" name="accion" id="formAccion" value="crear" />
        <input type="hidden" name="cliente_id" id="cliente_id" value="" />
        <input type="hidden" name="persona_id" id="persona_id" value="" />

        <div class="modal-body">
          <div class="form-row-2">
            <div class="form-group">
              <label for="codigo_cliente" class="form-label">Código Cliente *</label>
              <input type="text" id="codigo_cliente" name="codigo_cliente" class="form-control" required maxlength="20" placeholder="CLI-001" style="font-family: monospace; font-weight: bold; text-transform: uppercase;" />
            </div>
            <div class="form-group">
              <label for="tipo_persona" class="form-label">Tipo de Persona *</label>
              <select id="tipo_persona" name="tipo_persona" class="form-control" onchange="ajustarTipoPersona()">
                <option value="FISICA">Persona Física</option>
                <option value="JURIDICA">Persona Jurídica (Empresa)</option>
              </select>
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="tipo_documento" class="form-label">Tipo de Documento *</label>
              <select id="tipo_documento" name="tipo_documento" class="form-control" onchange="aplicarMascaraDoc()">
                <option value="CEDULA">Cédula Dominicana</option>
                <option value="RNC">RNC Fiscal</option>
                <option value="PASAPORTE">Pasaporte</option>
              </select>
            </div>
            <div class="form-group">
              <label for="documento_identidad" class="form-label">Número de Documento *</label>
              <input type="text" id="documento_identidad" name="documento_identidad" class="form-control" placeholder="000-0000000-0" required oninput="mascaraDoc(this); if(this.value.replace(/\D/g,'').length>=9) autocompletarTercero();" onblur="autocompletarTercero()" />
              <small id="terceroFeedback" style="display: none; color: #ffd89b; font-weight: 500; font-size: 0.82em; margin-top: 4px;"></small>
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="nombre_razon_social" class="form-label" id="labelNombre">Nombres y Apellidos *</label>
              <input type="text" id="nombre_razon_social" name="nombre_razon_social" class="form-control" placeholder="Ej. Juan Pérez" required onblur="autocompletarTerceroPorNombre(this.value)" />
            </div>
            <div class="form-group">
              <label for="nombre_comercial" class="form-label">Nombre Comercial (Opcional)</label>
              <input type="text" id="nombre_comercial" name="nombre_comercial" class="form-control" placeholder="Ej. Bodega El Roble" />
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="telefono_principal" class="form-label">Teléfono Principal</label>
              <input type="text" id="telefono_principal" name="telefono_principal" class="form-control" placeholder="(809) 000-0000" oninput="mascaraTelefono(this)" />
            </div>
            <div class="form-group">
              <label for="email" class="form-label">Correo Electrónico</label>
              <input type="email" id="email" name="email" class="form-control" placeholder="cliente@dominio.com" />
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="categoria_cliente" class="form-label">Categoría del Cliente *</label>
              <select id="categoria_cliente" name="categoria_cliente" class="form-control">
                <option value="REGULAR">REGULAR (Estándar)</option>
                <option value="VIP">VIP (Descuentos preferenciales)</option>
                <option value="MAYORISTA">MAYORISTA (Precios por caja/volumen)</option>
              </select>
            </div>
            <div class="form-group" style="display: flex; flex-direction: column; justify-content: center;">
              <label class="form-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-top: 14px;">
                <input type="checkbox" id="permite_credito" name="permite_credito" value="1" onchange="toggleCreditoInput()" style="width: 18px; height: 18px; accent-color: #d4a359;" />
                <span style="font-weight: 600;">¿Habilitar Crédito Operativo?</span>
              </label>
            </div>
          </div>

          <div class="form-group" id="grupoLimiteCredito" style="display: none;">
            <label for="limite_credito" class="form-label">Límite de Crédito Autorizado (DOP) *</label>
            <input type="number" step="0.01" min="0" id="limite_credito" name="limite_credito" class="form-control" value="0.00" style="font-family: monospace; font-size: 1.05rem;" />
            <small style="color: #999;">Monto máximo de saldo pendiente en cuentas por cobrar.</small>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-gold">Guardar Cliente</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast"></div>

    <script src="clientes.js"></script>
</body>
</html>
