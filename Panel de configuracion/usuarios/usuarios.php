<?php
/**
 * Mantenimiento de Usuarios y Accesos
 * House & Ice • Sistema Administrativo
 * Tablas: seguridad.usuarios, seguridad.usuarios_roles, seguridad.roles, identidad.personas
 */

require_once __DIR__ . '/../../config/conexion.php';

$usuarios = [];
$empleados = [];
$roles = [];
if ($pdo) {
    try {
        $sqlSyncPath = __DIR__ . '/../../actualizacion_roles_permisos_pin.sql';
        if (is_file($sqlSyncPath)) {
            $pdo->exec(file_get_contents($sqlSyncPath));
        }
    } catch (Throwable $e) {}

    try {
        $stmt = $pdo->query("
            SELECT u.usuario_id, u.username, u.estado, (u.estado = 'ACTIVO') AS es_activo,
                   u.ultimo_acceso, u.creado_en,
                   u.pin_autorizacion,
                   CASE WHEN u.pin_autorizacion ~ '^[0-9]{4,6}$' THEN u.pin_autorizacion ELSE '' END AS pin_visible,
                   (u.pin_autorizacion IS NOT NULL AND TRIM(u.pin_autorizacion) <> '') AS tiene_pin_guardado,
                   p.persona_id, p.nombre_razon_social, p.nombre_comercial, p.email, p.documento_identidad,
                   COALESCE((
                       SELECT JSON_AGG(ur.rol_id ORDER BY r2.nombre)
                       FROM seguridad.usuarios_roles ur
                       JOIN seguridad.roles r2 ON ur.rol_id = r2.rol_id
                       WHERE ur.usuario_id = u.usuario_id
                   ), '[]') AS roles_ids_json,
                   COALESCE((
                       SELECT STRING_AGG(r2.nombre, ' | ' ORDER BY r2.nombre)
                       FROM seguridad.usuarios_roles ur
                       JOIN seguridad.roles r2 ON ur.rol_id = r2.rol_id
                       WHERE ur.usuario_id = u.usuario_id
                   ), '') AS roles_nombres
            FROM seguridad.usuarios u
            JOIN identidad.personas p ON u.persona_id = p.persona_id
            ORDER BY u.creado_en DESC
        ");
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($usuarios as &$uRow) {
            $uRow['roles_ids'] = json_decode($uRow['roles_ids_json'] ?? '[]', true) ?: [];
        }
        unset($uRow);

        $empleados = $pdo->query("
            SELECT e.empleado_id, e.persona_id, e.codigo_empleado, e.cargo,
                   p.nombre_razon_social, p.nombre_comercial, p.documento_identidad
            FROM identidad.empleados e
            JOIN identidad.personas p ON e.persona_id = p.persona_id
            WHERE e.estado_laboral = 'ACTIVO'
            ORDER BY p.nombre_razon_social ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $roles = $pdo->query("
            SELECT r.rol_id, r.nombre, COALESCE(r.descripcion, '') AS descripcion,
                   EXISTS (
                       SELECT 1
                       FROM seguridad.roles_permisos rp
                       JOIN seguridad.permisos perm ON rp.permiso_id = perm.permiso_id
                       WHERE rp.rol_id = r.rol_id
                         AND (UPPER(perm.clave_sistema) = 'AUTORIZAR_CON_PIN' OR UPPER(perm.clave_sistema) LIKE '%PIN%')
                   ) AS tiene_permiso_pin
            FROM seguridad.roles r
            WHERE r.es_activo = true
            ORDER BY CASE WHEN UPPER(r.nombre) = 'ADMINISTRADOR' THEN 0 ELSE 1 END, r.nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}

$msg = $_GET['msg'] ?? '';
$tipoMsg = $_GET['tipo'] ?? 'exito';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Usuarios</title>
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
    <!-- MENÚ LATERAL (PANEL DE CONFIGURACIÓN AL FINAL) -->
    <?php include __DIR__ . '/../../sidebar.php'; ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content">

      <!-- ENCABEZADO -->
      <div class="page-header">
        <div>
          <h1 class="page-title">Usuarios</h1>
          <p class="page-subtitle">Credenciales de inicio de sesión, asignación de roles operativos y contraseñas cifradas</p>
        </div>

        <button type="button" class="btn btn-gold" onclick="abrirModal()">
          + Nuevo Usuario
        </button>
      </div>

      <!-- TARJETA DE TABLA -->
      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Listado de Usuarios Autorizados</h2>
            <span class="badge-count" id="badgeCount"><?= count($usuarios) ?> usuarios</span>
          </div>

          <div class="search-box" style="width: 280px;">
            <span class="search-icon">🔍</span>
            <input
              type="text"
              id="buscador"
              placeholder="Buscar por usuario, nombre o rol..."
              oninput="filtrarTabla()"
            />
          </div>
        </div>

        <table class="data-table">
          <thead>
            <tr>
              <th>Usuario (Login)</th>
              <th>Empleado Asociado</th>
              <th>Roles Asignados</th>
              <th class="text-center">PIN Seguridad</th>
              <th>Último Acceso</th>
              <th class="text-center">Estado</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaBody">
            <?php if (empty($usuarios)): ?>
              <tr>
                <td colspan="7" class="text-center" style="padding: 30px; color: #888;">
                  No hay usuarios registrados. Haz clic en <strong>+ Nuevo Usuario</strong> para dar de alta uno.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($usuarios as $u): 
                $nombreCompleto = trim($u['nombre_razon_social'] ?? '') ?: $u['username'];
                $esActivo = ($u['es_activo'] === true || $u['es_activo'] === 't' || strtoupper($u['estado'] ?? '') === 'ACTIVO');
                $tienePinGuardado = ($u['tiene_pin_guardado'] === true || $u['tiene_pin_guardado'] === 't' || $u['tiene_pin_guardado'] === 1);
                $listaRoles = !empty($u['roles_nombres']) ? explode(' | ', $u['roles_nombres']) : [];
              ?>
                <tr class="fila-usuario" data-texto="<?= strtolower(htmlspecialchars($u['username'] . ' ' . $nombreCompleto . ' ' . ($u['roles_nombres'] ?? ''))) ?>">
                  <td>
                    <strong style="color: #a66e2e; font-size: 1.05em;"><?= htmlspecialchars($u['username']) ?></strong>
                  </td>
                  <td>
                    <div style="font-weight: 600; color: #211813;"><?= htmlspecialchars($nombreCompleto) ?></div>
                    <div style="font-size: 0.8em; color: #8a7b70;"><?= htmlspecialchars($u['email'] ?: 'Sin email') ?></div>
                  </td>
                  <td>
                    <?php if (!empty($listaRoles)): ?>
                      <?php foreach ($listaRoles as $rNom): ?>
                        <span class="badge" style="background: #eadecd; color: #5a3818; font-weight: bold; display: inline-block; margin: 2px;">
                          <?= htmlspecialchars($rNom) ?>
                        </span>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <span class="badge" style="background: #f0ebe3; color: #888;">Sin Rol</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?php if (!empty($u['pin_visible'])): ?>
                      <span class="badge" style="background: #fdf3e2; color: #794a15; border: 1px solid #e4c99b; font-family: monospace; font-weight: 700; font-size: 0.84rem; letter-spacing: 1px;">
                        🔐 <?= htmlspecialchars($u['pin_visible']) ?>
                      </span>
                    <?php elseif ($tienePinGuardado): ?>
                      <span class="badge" style="background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; font-weight: 700; font-size: 0.75rem;">
                        🔐 PIN Configurado
                      </span>
                    <?php else: ?>
                      <span style="color: #999; font-size: 0.8rem;">No asignado</span>
                    <?php endif; ?>
                  </td>
                  <td style="font-size: 0.88em; color: #555;">
                    <?= $u['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($u['ultimo_acceso'])) : 'Nunca' ?>
                  </td>
                  <td class="text-center">
                    <label class="toggle-wrap" title="Cambiar estado de acceso">
                      <input type="checkbox" <?= $esActivo ? 'checked' : '' ?> onchange="toggleEstadoUsuario('<?= $u['usuario_id'] ?>', this.checked, this)" />
                      <span class="toggle-slider"></span>
                      <span class="toggle-text"><?= $esActivo ? 'Activo' : 'Inactivo' ?></span>
                    </label>
                  </td>
                  <td class="text-right action-btns">
                    <button type="button" class="btn-sm btn-outline" onclick='editarUsuario(<?= json_encode($u) ?>)'>Editar</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="table-card-footer">
          <span id="footerInfo">Total: <?= count($usuarios) ?> usuarios</span>
          <span>House &amp; Ice • Sistema Administrativo</span>
        </div>
      </div>

    </main>
  </div>

  <!-- MODAL NUEVO / EDITAR USUARIO -->
  <div id="modalUsuario" class="modal-overlay" onclick="cerrarSiFondo(event)">
    <div class="modal-box modal-lg">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitulo">Nuevo Usuario</h2>
      </div>
      <form id="formUsuario" action="procesar_usuarios.php" method="POST" onsubmit="return validarFormularioUsuario(event)">
        <input type="hidden" name="accion" id="formAccion" value="crear" />
        <input type="hidden" name="usuario_id" id="usuario_id" value="" />
        <input type="hidden" id="tiene_pin_guardado" value="0" />

        <div class="modal-body">
          <div class="form-group" id="grupoPersona">
            <label for="persona_id" class="form-label">Empleado Asociado (Tercero) *</label>
            <select id="persona_id" name="persona_id" class="form-control" required>
              <option value="">-- Seleccionar Empleado --</option>
              <?php foreach ($empleados as $emp): ?>
                <option value="<?= $emp['persona_id'] ?>">
                  <?= htmlspecialchars($emp['nombre_razon_social'] . ' - ' . $emp['cargo'] . ' (' . $emp['codigo_empleado'] . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="username" class="form-label">Nombre de Usuario (Login) *</label>
              <input type="text" id="username" name="username" class="form-control" required placeholder="ej. jperez" maxlength="50" oninput="this.value = this.value.toLowerCase().replace(/[^a-z0-9._-]/g, '');" />
            </div>
            <div class="form-group">
              <label for="es_activo" class="form-label">Estado de Acceso *</label>
              <select id="es_activo" name="es_activo" class="form-control">
                <option value="true">ACTIVO</option>
                <option value="false">INACTIVO / BLOQUEADO</option>
              </select>
            </div>
          </div>

          <!-- FILA DE CONTRASEÑA + PIN AL LADO (CUANDO EL ROL POSEE PERMISO DE PIN) -->
          <div style="display: flex; gap: 12px; align-items: flex-start; flex-wrap: nowrap;">
            <div class="form-group" style="flex: 1; min-width: 0;">
              <label for="password" class="form-label" id="lblPassword">Contraseña de Seguridad *</label>
              <input type="password" id="password" name="password" class="form-control" placeholder="Mínimo 8 caracteres" />
              <small id="hintPassword" style="display: none; color: #888; font-size: 0.78em;">Deja en blanco para conservar la actual</small>
            </div>

            <div class="form-group" id="bloquePinUsuario" style="display: none; width: 235px; flex-shrink: 0;">
              <label for="pin_autorizacion" class="form-label" id="lblPinAutorizacion" style="color: #794a15; font-weight: 700;">
                🔐 PIN (4 a 6 dígitos) *
              </label>
              <div style="display: flex; align-items: center; gap: 6px;">
                <input
                  type="text"
                  id="pin_autorizacion"
                  name="pin_autorizacion"
                  class="form-control"
                  inputmode="numeric"
                  minlength="4"
                  maxlength="6"
                  autocomplete="off"
                  placeholder="Ej. 1234"
                  style="font-family: monospace; font-size: 0.96rem; letter-spacing: 2px; font-weight: 700; text-align: center; color: #211813;"
                  onkeydown="return soloNumerosPin(event)"
                  oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6); validarInputPinEnVivo(this);"
                />
                <button type="button" class="btn-sm btn-outline" onclick="alternarVisibilidadPin()" id="btnVerPin" title="Mostrar / Ocultar PIN" style="height: 38px; padding: 0 9px; font-size: 0.85rem;">
                  👁️
                </button>
              </div>
              <small id="errorPinUsuario" style="display: none; font-size: 0.74rem; font-weight: 600; margin-top: 2px;"></small>
              <small id="hintPinUsuario" style="display: block; color: #8a7b70; font-size: 0.72rem; margin-top: 2px;">Solo números (4 a 6 dígitos)</small>
            </div>
          </div>

          <!-- MATRIZ DE ROLES DEL USUARIO (CHECKBOXES MÚLTIPLES IGUAL QUE ROLES TIENE PERMISOS) -->
          <div class="form-group" style="margin-top: 10px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
              <label class="form-label" style="font-weight: 700; color: #a66e2e; margin: 0;">Roles Asignados al Usuario (Selección Múltiple) *</label>
              <span style="font-size: 0.76rem; color: #8a7b70;">Un usuario puede ejercer uno o varios roles</span>
            </div>
            <div id="contenedorRolesUsuario" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; background: #faf7f2; border: 1px solid #eedcb9; border-radius: 8px; padding: 12px; max-height: 205px; overflow-y: auto;">
              <?php foreach ($roles as $r):
                $tienePinRol = ($r['tiene_permiso_pin'] === true || $r['tiene_permiso_pin'] === 't' || $r['tiene_permiso_pin'] === 1);
              ?>
                <label style="display: flex; align-items: flex-start; gap: 8px; padding: 8px 10px; border-radius: 6px; background: #fff; border: 1px solid #eadecd; cursor: pointer;">
                  <input
                    type="checkbox"
                    name="roles[]"
                    class="chk-rol-usuario"
                    value="<?= htmlspecialchars($r['rol_id']) ?>"
                    data-nombre="<?= htmlspecialchars($r['nombre']) ?>"
                    data-tiene-pin="<?= $tienePinRol ? '1' : '0' ?>"
                    onchange="evaluarPermisoPinUsuario()"
                    style="width: 16px; height: 16px; margin-top: 2px; accent-color: #a66e2e;"
                  />
                  <div style="flex: 1;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; flex-wrap: wrap;">
                      <strong style="font-size: 0.86rem; color: #211813;"><?= htmlspecialchars($r['nombre']) ?></strong>
                      <?php if ($tienePinRol): ?>
                        <span style="font-size: 0.68rem; background: #fdf3e2; color: #9c621c; border: 1px solid #e7cca4; padding: 1px 6px; border-radius: 4px; font-weight: 700;">
                          🔐 Permiso PIN
                        </span>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($r['descripcion'])): ?>
                      <div style="font-size: 0.74rem; color: #7a6b5d; margin-top: 2px; line-height: 1.3;">
                        <?= htmlspecialchars(mb_strimwidth($r['descripcion'], 0, 95, '...')) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-gold">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast"></div>

    <script src="usuarios.js?v=7"></script>
</body>
</html>

