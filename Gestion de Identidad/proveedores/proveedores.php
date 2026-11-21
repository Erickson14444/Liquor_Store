<?php
/**
 * Mantenimiento de Proveedores y Suplidores
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: identidad.proveedores, identidad.personas
 */

require_once __DIR__ . '/../../config/conexion.php';

$proveedores = [];
$retencionesCatalogo = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT prv.persona_id AS proveedor_id, prv.codigo_proveedor, prv.dias_credito_habitual, prv.creado_en,
                   COALESCE((
                       SELECT JSON_AGG(prr.retencion_id ORDER BY prr.retencion_id)
                       FROM identidad.proveedor_retenciones prr
                       WHERE prr.proveedor_id = prv.persona_id
                   ), '[]') AS retenciones_ids_json,
                   COALESCE((
                       SELECT STRING_AGG(r.nombre, ' | ' ORDER BY r.id)
                       FROM identidad.proveedor_retenciones prr
                       JOIN tesoreria.retenciones r ON prr.retencion_id = r.id
                       WHERE prr.proveedor_id = prv.persona_id
                   ), '') AS retenciones_nombres,
                   p.persona_id, p.documento_identidad, p.tipo_documento, p.nombre_razon_social, p.nombre_comercial,
                   p.tipo_persona, p.telefono_principal, p.email, p.estado
            FROM identidad.proveedores prv
            JOIN identidad.personas p ON prv.persona_id = p.persona_id
            ORDER BY prv.creado_en DESC
        ");
        $proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($proveedores as &$pRow) {
            $pRow['retenciones_ids'] = json_decode($pRow['retenciones_ids_json'] ?? '[]', true) ?: [];
        }
        unset($pRow);
        $retencionesCatalogo = $pdo->query("SELECT id, siglas, nombre, base_calculo, porcentaje FROM tesoreria.retenciones WHERE activo = TRUE ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
    <!-- MENÚ LATERAL -->
    <?php include __DIR__ . '/../../sidebar.php'; ?>

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
              <th class="text-center">Retenciones Fiscales</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaBody">
            <?php if (empty($proveedores)): ?>
              <tr>
                <td colspan="7" class="text-center" style="padding: 30px; color: #888;">
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
                  <td><span class="code-inline"><?= htmlspecialchars($prv['codigo_proveedor']) ?></span></td>
                  <td>
                    <div style="font-weight: 700; color: var(--text-dark); font-size: 0.92rem;"><?= $nom ?></div>
                    <?php if (!empty($com) && $com !== $nom): ?>
                      <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px;"><?= $com ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="code-inline"><?= htmlspecialchars($prv['tipo_documento']) ?>: <?= $doc ?></span>
                  </td>
                  <td>
                    <div style="font-weight: 500; color: var(--text-body);">📞 <?= htmlspecialchars($prv['telefono_principal'] ?: 'S/N') ?></div>
                    <div style="font-size: 0.78rem; color: var(--text-muted);">✉️ <?= htmlspecialchars($prv['email'] ?: 'S/N') ?></div>
                  </td>
                  <td class="text-center" style="font-weight: 600; color: var(--text-dark);">
                    <?= intval($prv['dias_credito_habitual']) ?> días
                  </td>
                  <td class="text-center">
                    <?php if (!empty($prv['retenciones_nombres'])): ?>
                      <?php foreach (explode(' | ', $prv['retenciones_nombres']) as $rNom): ?>
                        <span class="code-inline" style="display: inline-block; margin: 2px; font-size: 0.76rem;"><?= htmlspecialchars($rNom) ?></span>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <span style="color: var(--text-muted); font-size: 0.8rem;">Sin retenciones</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-right action-btns">
                    <button type="button" class="btn-sm btn-outline" onclick='editarProveedor(<?= json_encode($prv) ?>)'>Editar</button>
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
      <form id="formProveedor" action="procesar_proveedores.php" method="POST" onsubmit="return validarFormularioProveedor(event)">
        <input type="hidden" name="accion" id="formAccion" value="crear" />
        <input type="hidden" name="proveedor_id" id="proveedor_id" value="" />
        <input type="hidden" name="persona_id" id="persona_id" value="" />

        <div class="modal-body">
          <div class="form-row-2">
            <div class="form-group">
              <label for="codigo_proveedor" class="form-label">Código Proveedor *</label>
              <input type="text" id="codigo_proveedor" name="codigo_proveedor" class="form-control" required maxlength="20" placeholder="PROV-001" style="font-family: monospace; font-weight: bold; text-transform: uppercase;" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, ''); autocompletarProveedorPorCodigo(this.value);" onblur="autocompletarProveedorPorCodigo(this.value)" />
            </div>
            <div class="form-group">
              <label for="tipo_documento" class="form-label">Tipo de Identificación *</label>
              <select id="tipo_documento" name="tipo_documento" class="form-control" onchange="aplicarMascaraDoc()">
                <option value="RNC">RNC (Empresas / Distribuidores - 9 dígitos)</option>
                <option value="CEDULA">Cédula Personal (11 dígitos)</option>
                <option value="PASAPORTE">Pasaporte / Extranjero</option>
              </select>
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="documento_identidad" class="form-label">Número de RNC / Cédula *</label>
              <input type="text" id="documento_identidad" name="documento_identidad" class="form-control" placeholder="0-00-00000-0" required onkeydown="return validarTeclaDoc(event)" oninput="mascaraDoc(this); if(this.value.replace(/\D/g,'').length>=9) autocompletarTercero();" onblur="autocompletarTercero()" />
              <small id="terceroFeedback" style="display: none; color: var(--accent-gold); font-weight: 600; font-size: 0.76rem; margin-top: 3px;"></small>
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
              <input type="text" id="telefono_principal" name="telefono_principal" class="form-control" placeholder="(809) 000-0000" maxlength="14" inputmode="numeric" onkeydown="return soloNumerosEnteros(event)" oninput="mascaraTelefono(this)" />
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
              <label for="dias_credito_habitual" class="form-label">Días de Crédito Habitual (Entero) *</label>
              <input
                type="text"
                inputmode="numeric"
                id="dias_credito_habitual"
                name="dias_credito_habitual"
                class="form-control"
                maxlength="3"
                value="30"
                required
                style="font-family: monospace; font-size: 1.05rem; font-weight: 700;"
                onkeydown="return soloNumerosEnteros(event)"
                oninput="this.value = this.value.replace(/[^0-9]/g, ''); if(parseInt(this.value || '0', 10) > 180) this.value = '180';"
              />
              <small style="color: #999;">Solo números enteros (0 a 180 días para Cuentas por Pagar).</small>
            </div>
            <div class="form-group">
              <label class="form-label">Retenciones Fiscales Habituales (Selección Múltiple)</label>
              <div id="contenedorRetenciones" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; background: #faf6f0; padding: 10px; border: 1px solid var(--border-color, #e2d5c4); border-radius: 8px; max-height: 135px; overflow-y: auto;">
                <?php foreach ($retencionesCatalogo as $ret): ?>
                  <label style="display: flex; align-items: flex-start; gap: 7px; font-size: 0.81rem; cursor: pointer; padding: 4px 6px; border-radius: 6px; background: #fff; border: 1px solid #eadecd;">
                    <input
                      type="checkbox"
                      name="retenciones[]"
                      class="chk-retencion-prov"
                      value="<?= intval($ret['id']) ?>"
                      style="margin-top: 2px; accent-color: #a66e2e;"
                    />
                    <span>
                      <strong style="color: #211813; display: block;"><?= htmlspecialchars($ret['nombre']) ?></strong>
                      <small style="color: #8a7b70; font-family: monospace;"><?= htmlspecialchars($ret['siglas']) ?> • <?= number_format((float)$ret['porcentaje'], 2) ?>% (<?= htmlspecialchars($ret['base_calculo']) ?>)</small>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
              <small style="color: #999;">Puedes marcar una o varias retenciones fiscales DGII (606) aplicables al proveedor.</small>
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

    <script src="proveedores.js?v=5"></script>
</body>
</html>
