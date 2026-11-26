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
  <style>
    .badge-code {
      white-space: nowrap !important;
      display: inline-block !important;
      letter-spacing: 0.5px;
      font-family: monospace;
      font-size: 0.82rem !important;
      font-weight: 700;
      color: #925f24;
      background-color: #faf5ee;
      border: 1px solid #ebdcc5;
      padding: 3px 8px !important;
      border-radius: 4px;
    }

    .badge-ret-pill {
      display: inline-block;
      max-width: 170px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      vertical-align: middle;
      font-size: 0.74rem;
      padding: 2px 7px;
      background: #faf6f0;
      border: 1px solid #e5d7c3;
      border-radius: 4px;
      color: #553e29;
      font-weight: 600;
      margin: 1px 2px;
    }

    .col-codigo {
      width: 95px !important;
      white-space: nowrap !important;
    }

    .col-doc {
      width: 160px !important;
      white-space: nowrap !important;
    }

    .col-acciones {
      width: 85px !important;
      text-align: right !important;
    }

    .retencion-card-label {
      user-select: none;
    }

    .retencion-card-label:hover {
      border-color: #a66e2e !important;
      background-color: #fff9f0 !important;
      box-shadow: 0 2px 6px rgba(166, 110, 46, 0.12);
    }

    .retencion-card-label:has(.chk-retencion-prov:checked) {
      border-color: #a66e2e !important;
      background-color: #faf3e6 !important;
      box-shadow: 0 0 0 1px #a66e2e;
    }

    #contenedorRetenciones::-webkit-scrollbar {
      width: 6px;
    }

    #contenedorRetenciones::-webkit-scrollbar-thumb {
      background: #c8b69f;
      border-radius: 4px;
    }

    #contenedorRetenciones::-webkit-scrollbar-thumb:hover {
      background: #a66e2e;
    }
  </style>
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
          <p class="page-subtitle">Registro de importadores, distribuidores oficiales, RNC fiscal y condiciones de
            compra</p>
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
            <input type="text" id="buscador" placeholder="Buscar por código, razón social o RNC..."
              oninput="filtrarTabla()" style="width:350px" />
          </div>
        </div>

        <div style="overflow-x: auto; width: 100%;">
          <table class="data-table" style="min-width: 860px; width: 100%;">
            <thead>
              <tr>
                <th class="col-codigo">Código</th>
                <th>Razón Social / Proveedor</th>
                <th class="col-doc">RNC / Documento</th>
                <th style="width: 170px;">Contacto</th>
                <th class="text-center" style="width: 100px;">Crédito Habitual</th>
                <th class="text-center" style="width: 170px;">Retenciones Fiscales</th>
                <th class="col-acciones">Acciones</th>
              </tr>
            </thead>
            <tbody id="tablaBody">
              <?php if (empty($proveedores)): ?>
                <tr>
                  <td colspan="7" class="text-center" style="padding: 30px; color: #888;">
                    No hay proveedores registrados. Haz clic en <strong>+ Nuevo Proveedor</strong> para agregar el
                    primero.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($proveedores as $prv):
                  $esActivo = !empty($prv['estado']);
                  $doc = htmlspecialchars($prv['documento_identidad']);
                  $nom = htmlspecialchars($prv['nombre_razon_social']);
                  $com = htmlspecialchars($prv['nombre_comercial'] ?? '');
                  ?>
                  <tr class="fila-prov"
                    data-texto="<?= strtolower(htmlspecialchars($prv['codigo_proveedor'] . ' ' . $nom . ' ' . $com . ' ' . $doc . ' ' . ($prv['email'] ?? ''))) ?>">
                    <td class="col-codigo"><span class="badge-code"><?= htmlspecialchars($prv['codigo_proveedor']) ?></span>
                    </td>
                    <td>
                      <div style="font-weight: 700; color: var(--text-dark); font-size: 0.92rem;"><?= $nom ?></div>
                      <?php if (!empty($com) && $com !== $nom): ?>
                        <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px;"><?= $com ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="col-doc">
                      <span class="badge-code" style="font-weight: 600;"><?= htmlspecialchars($prv['tipo_documento']) ?>:
                        <?= $doc ?></span>
                    </td>
                    <td>
                      <div style="font-weight: 500; color: var(--text-body); font-size: 0.84rem;">📞
                        <?= htmlspecialchars($prv['telefono_principal'] ?: 'S/N') ?>
                      </div>
                      <div style="font-size: 0.78rem; color: var(--text-muted);">✉️
                        <?= htmlspecialchars($prv['email'] ?: 'S/N') ?>
                      </div>
                    </td>
                    <td class="text-center" style="font-weight: 600; color: var(--text-dark);">
                      <?= intval($prv['dias_credito_habitual']) ?> días
                    </td>
                    <td class="text-center">
                      <?php if (!empty($prv['retenciones_nombres'])): ?>
                        <?php foreach (explode(' | ', $prv['retenciones_nombres']) as $rNom): ?>
                          <span class="badge-ret-pill"
                            title="<?= htmlspecialchars($rNom) ?>"><?= htmlspecialchars($rNom) ?></span>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <span style="color: var(--text-muted); font-size: 0.8rem;">Sin retenciones</span>
                      <?php endif; ?>
                    </td>
                    <td class="col-acciones action-btns">
                      <button type="button" class="btn-sm btn-outline"
                        onclick='editarProveedor(<?= json_encode($prv) ?>)'>Editar</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerInfo">Total: <?= count($proveedores) ?> proveedores</span>
          <span>House &amp; Ice • Módulo de Compras y Cuentas por Pagar</span>
        </div>
      </div>

    </main>
  </div>

  <!-- MODAL NUEVO / EDITAR PROVEEDOR -->
  <div id="modalProveedor" class="modal-overlay" onclick="cerrarSiFondo(event)">
    <div class="modal-box modal-xl">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitulo">Nuevo Proveedor</h2>
      </div>
      <form id="formProveedor" action="procesar_proveedores.php" method="POST"
        onsubmit="return validarFormularioProveedor(event)">
        <input type="hidden" name="accion" id="formAccion" value="crear" />
        <input type="hidden" name="proveedor_id" id="proveedor_id" value="" />
        <input type="hidden" name="persona_id" id="persona_id" value="" />

        <div class="modal-body">
          <div class="form-row-2">
            <div class="form-group">
              <label for="codigo_proveedor" class="form-label">Código Proveedor *</label>
              <input type="text" id="codigo_proveedor" name="codigo_proveedor" class="form-control" required
                maxlength="20" placeholder="PROV-001"
                style="font-family: monospace; font-weight: bold; text-transform: uppercase;"
                oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, ''); autocompletarProveedorPorCodigo(this.value);"
                onblur="autocompletarProveedorPorCodigo(this.value)" readonly />
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
              <input type="text" id="documento_identidad" name="documento_identidad" class="form-control"
                placeholder="0-00-00000-0" required onkeydown="return validarTeclaDoc(event)"
                oninput="mascaraDoc(this); if(this.value.replace(/\D/g,'').length>=9) autocompletarTercero();"
                onblur="autocompletarTercero()" />
              <small id="terceroFeedback"
                style="display: none; color: var(--accent-gold); font-weight: 600; font-size: 0.76rem; margin-top: 3px;"></small>
            </div>
            <div class="form-group">
              <label for="nombre_razon_social" class="form-label">Razón Social *</label>
              <input type="text" id="nombre_razon_social" name="nombre_razon_social" class="form-control"
                placeholder="Ej. Casa Brugal SAS" required onblur="autocompletarTerceroPorNombre(this.value)" />
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="nombre_comercial" class="form-label">Nombre Comercial / Marca Fantasía</label>
              <input type="text" id="nombre_comercial" name="nombre_comercial" class="form-control"
                placeholder="Ej. Licores &amp; Vinos Nacionales" />
            </div>
            <div class="form-group">
              <label for="telefono_principal" class="form-label">Teléfono de Pedidos</label>
              <input type="text" id="telefono_principal" name="telefono_principal" class="form-control"
                placeholder="(809) 000-0000" maxlength="14" inputmode="numeric"
                onkeydown="return soloNumerosEnteros(event)" oninput="mascaraTelefono(this)" />
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
              <input type="text" inputmode="numeric" id="dias_credito_habitual" name="dias_credito_habitual"
                class="form-control" maxlength="3" value="30" required
                style="font-family: monospace; font-size: 1.05rem; font-weight: 700;"
                onkeydown="return soloNumerosEnteros(event)"
                oninput="this.value = this.value.replace(/[^0-9]/g, ''); if(parseInt(this.value || '0', 10) > 180) this.value = '180';" />
              <small style="color: #999;">Solo números enteros (0 a 180 días para Cuentas por Pagar).</small>
            </div>
          </div>

          <!-- SECCIÓN: RETENCIONES FISCALES HABITUALES (DGII 606) -->
          <div class="form-group" style="margin-top: 14px; border-top: 1px solid #ebdcc5; padding-top: 14px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <label class="form-label" style="font-weight: 700; color: #211813; margin: 0; font-size: 0.88rem;">
                Retenciones Fiscales Habituales (Formato 606 DGII)
              </label>
              <span style="font-size: 0.78rem; color: #8a7b70;">Selección múltiple opcional</span>
            </div>

            <div id="contenedorRetenciones"
              style="display: grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: 10px; background: #faf6f0; padding: 12px; border: 1.5px solid var(--border-color, #e2d5c4); border-radius: 8px; max-height: 220px; overflow-y: auto; overflow-x: hidden;">
              <?php foreach ($retencionesCatalogo as $ret): ?>
                <label class="retencion-card-label"
                  style="display: flex; align-items: flex-start; gap: 10px; font-size: 0.84rem; cursor: pointer; padding: 10px 12px; border-radius: 8px; background: #fff; border: 1.5px solid #e8decb; transition: all 0.2s ease;">
                  <input type="checkbox" name="retenciones[]" class="chk-retencion-prov" value="<?= intval($ret['id']) ?>"
                    style="width: 17px; height: 17px; accent-color: #a66e2e; margin-top: 2px; flex-shrink: 0; cursor: pointer;" />
                  <div style="flex: 1; min-width: 0;">
                    <strong
                      style="color: #211813; display: block; font-size: 0.84rem; line-height: 1.25; margin-bottom: 4px;"><?= htmlspecialchars($ret['nombre']) ?></strong>
                    <div style="display: flex; align-items: center; gap: 6px; font-size: 0.76rem; color: #7f6e5e;">
                      <span
                        style="background: #f1e6d4; color: #5a3e1b; font-family: monospace; font-weight: 700; padding: 1px 6px; border-radius: 4px;"><?= htmlspecialchars($ret['siglas']) ?></span>
                      <span><?= number_format((float) $ret['porcentaje'], 2) ?>%</span>
                      <span style="color: #a49282;">•</span>
                      <span style="color: #8c7866;"><?= htmlspecialchars($ret['base_calculo']) ?></span>
                    </div>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
            <small style="color: #8a7b70; font-size: 0.78rem; display: block; margin-top: 6px;">
              Puedes marcar una o varias retenciones fiscales aplicables habitualmente a este proveedor para agilizar el
              registro de compras.
            </small>
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