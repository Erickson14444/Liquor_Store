<?php
/**
 * Mantenimiento de Proveedores y Suplidores
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: identidad.proveedores, identidad.personas
 */

require_once __DIR__ . '/../../config/conexion.php';

$proveedores = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT prv.proveedor_id, prv.codigo_proveedor, prv.dias_credito_habitual, prv.retencion_isr_porc, prv.creado_en,
                   p.persona_id, p.documento_identidad, p.tipo_documento, p.nombre_razon_social, p.nombre_comercial,
                   p.tipo_persona, p.telefono_principal, p.email, p.estado
            FROM identidad.proveedores prv
            JOIN identidad.personas p ON prv.persona_id = p.persona_id
            ORDER BY prv.creado_en DESC
        ");
        $proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $proveedores = [];
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
  <title>House &amp; Ice • Proveedores</title>
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
          <h1 class="page-title">Proveedores y Casas Licoreras</h1>
          <p class="page-subtitle">Registro de importadores, distribuidores oficiales, RNC fiscal y condiciones de compra</p>
        </div>

        <button type="button" class="btn btn-gold" onclick="abrirModal()">
          + Nuevo Proveedor
        </button>
      </div>

      <!-- TARJETA DE TABLA -->
      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Listado de Proveedores Oficiales</h2>
            <span class="badge-count" id="badgeCount"><?= count($proveedores) ?> registrados</span>
          </div>

          <div class="search-box" style="width: 280px;">
            <span class="search-icon">🔍</span>
            <input
              type="text"
              id="buscador"
              placeholder="Buscar por código, razón social o RNC..."
              oninput="filtrarTabla()"
            />
          </div>
        </div>

        <table class="data-table">
          <thead>
            <tr>
              <th>Código</th>
              <th>Razón Social / Proveedor</th>
              <th>RNC / Documento</th>
              <th>Contacto</th>
              <th class="text-center">Crédito Habitual</th>
              <th class="text-center">Retención ISR</th>
              <th class="text-center">Estado</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaBody">
            <?php if (empty($proveedores)): ?>
              <tr>
                <td colspan="8" class="text-center" style="padding: 30px; color: #888;">
                  No hay proveedores registrados. Haz clic en <strong>+ Nuevo Proveedor</strong> para agregar el primero.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($proveedores as $prv): 
                $esActivo = !empty($prv['estado']);
                $doc = htmlspecialchars($prv['documento_identidad']);
                $nom = htmlspecialchars($prv['nombre_razon_social']);
                $com = htmlspecialchars($prv['nombre_comercial'] ?? '');
              ?>
                <tr class="fila-prov" data-texto="<?= strtolower(htmlspecialchars($prv['codigo_proveedor'] . ' ' . $nom . ' ' . $com . ' ' . $doc . ' ' . ($prv['email'] ?? ''))) ?>">
                  <td><strong><?= htmlspecialchars($prv['codigo_proveedor']) ?></strong></td>
                  <td>
                    <div style="font-weight: 600; color: #fff;"><?= $nom ?></div>
                    <?php if (!empty($com) && $com !== $nom): ?>
                      <div style="font-size: 0.8rem; color: #d4a359;"><?= $com ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge-rnc"><?= htmlspecialchars($prv['tipo_documento']) ?>: <?= $doc ?></span>
                  </td>
                  <td>
                    <div>📞 <?= htmlspecialchars($prv['telefono_principal'] ?: 'S/N') ?></div>
                    <div style="font-size: 0.82em; color: #aaa;">✉️ <?= htmlspecialchars($prv['email'] ?: 'S/N') ?></div>
                  </td>
                  <td class="text-center" style="font-weight: 600;">
                    <?= intval($prv['dias_credito_habitual']) ?> días
                  </td>
                  <td class="text-center">
                    <span class="badge-isr"><?= number_format($prv['retencion_isr_porc'], 2) ?>%</span>
                  </td>
                  <td class="text-center">
                    <span class="status-badge <?= $esActivo ? 'active' : 'inactive' ?>">
                      <?= $esActivo ? 'ACTIVO' : 'INACTIVO' ?>
                    </span>
                  </td>
                  <td class="text-right action-btns">
                    <button type="button" class="btn-sm btn-outline" onclick='editarProveedor(<?= json_encode($prv) ?>)'>Editar</button>
                    <button type="button" class="btn-sm <?= $esActivo ? 'btn-danger' : 'btn-gold' ?>" onclick="toggleEstadoProveedor('<?= $prv['proveedor_id'] ?>', <?= $esActivo ? 'true' : 'false' ?>)">
                      <?= $esActivo ? 'Desactivar' : 'Activar' ?>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="table-card-footer">
          <span id="footerInfo">Total: <?= count($proveedores) ?> proveedores</span>
          <span>House &amp; Ice • Módulo de Compras y Cuentas por Pagar</span>
        </div>
      </div>

    </main>
  </div>

  <!-- MODAL NUEVO / EDITAR PROVEEDOR -->
  <div id="modalProveedor" class="modal-overlay" onclick="cerrarSiFondo(event)">
    <div class="modal-box modal-lg">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitulo">Nuevo Proveedor</h2>
      </div>
      <form id="formProveedor" action="procesar_proveedores.php" method="POST">
        <input type="hidden" name="accion" id="formAccion" value="crear" />
        <input type="hidden" name="proveedor_id" id="proveedor_id" value="" />
        <input type="hidden" name="persona_id" id="persona_id" value="" />

        <div class="modal-body">
          <div class="form-row-2">
            <div class="form-group">
              <label for="codigo_proveedor" class="form-label">Código Proveedor *</label>
              <input type="text" id="codigo_proveedor" name="codigo_proveedor" class="form-control" required maxlength="20" placeholder="PROV-001" style="font-family: monospace; font-weight: bold; text-transform: uppercase;" />
            </div>
            <div class="form-group">
              <label for="tipo_documento" class="form-label">Tipo de Identificación *</label>
              <select id="tipo_documento" name="tipo_documento" class="form-control" onchange="aplicarMascaraDoc()">
                <option value="RNC">RNC (Empresas / Distribuidores)</option>
                <option value="CEDULA">Cédula Personal</option>
                <option value="PASAPORTE">Pasaporte / Extranjero</option>
              </select>
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="documento_identidad" class="form-label">Número de RNC / Cédula *</label>
              <input type="text" id="documento_identidad" name="documento_identidad" class="form-control" placeholder="0-00-00000-0" required oninput="mascaraDoc(this); if(this.value.replace(/\D/g,'').length>=9) autocompletarTercero();" onblur="autocompletarTercero()" />
              <small id="terceroFeedback" style="display: none; color: #ffd89b; font-weight: 500; font-size: 0.82em; margin-top: 4px;"></small>
            </div>
            <div class="form-group">
              <label for="nombre_razon_social" class="form-label">Razón Social *</label>
              <input type="text" id="nombre_razon_social" name="nombre_razon_social" class="form-control" placeholder="Ej. Casa Brugal SAS" required onblur="autocompletarTerceroPorNombre(this.value)" />
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="nombre_comercial" class="form-label">Nombre Comercial / Marca Fantasía</label>
              <input type="text" id="nombre_comercial" name="nombre_comercial" class="form-control" placeholder="Ej. Licores &amp; Vinos Nacionales" />
            </div>
            <div class="form-group">
              <label for="telefono_principal" class="form-label">Teléfono de Pedidos</label>
              <input type="text" id="telefono_principal" name="telefono_principal" class="form-control" placeholder="(809) 000-0000" oninput="mascaraTelefono(this)" />
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="email" class="form-label">Correo Electrónico (Órdenes de Compra)</label>
              <input type="email" id="email" name="email" class="form-control" placeholder="pedidos@proveedor.com" />
            </div>
            <div class="form-group">
              <label for="tipo_persona" class="form-label">Tipo de Suplidor *</label>
              <select id="tipo_persona" name="tipo_persona" class="form-control">
                <option value="JURIDICA">Persona Jurídica (SRL, SAS, SA)</option>
                <option value="FISICA">Persona Física</option>
              </select>
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="dias_credito_habitual" class="form-label">Días de Crédito Habitual *</label>
              <input type="number" id="dias_credito_habitual" name="dias_credito_habitual" class="form-control" min="0" max="180" value="30" required style="font-family: monospace; font-size: 1.05rem;" />
              <small style="color: #999;">Días otorgados para el pago de facturas en Cuentas por Pagar.</small>
            </div>
            <div class="form-group">
              <label for="retencion_isr_porc" class="form-label">Porcentaje de Retención ISR (%) *</label>
              <input type="number" step="0.01" id="retencion_isr_porc" name="retencion_isr_porc" class="form-control" min="0" max="100" value="0.00" required style="font-family: monospace; font-size: 1.05rem;" />
              <small style="color: #999;">% de retención fiscal en el comprobante fiscal 606 DGII.</small>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-gold">Guardar Proveedor</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast"></div>

    <script src="proveedores.js"></script>
</body>
</html>
