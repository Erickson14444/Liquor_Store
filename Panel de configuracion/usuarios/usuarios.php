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
        $stmt = $pdo->query("
            SELECT u.usuario_id, u.username, u.estado, (u.estado = 'ACTIVO') AS es_activo, u.ultimo_acceso, u.creado_en,
                   p.persona_id, p.nombre_razon_social, p.nombre_comercial, p.email, p.documento_identidad,
                   r.rol_id, r.nombre AS rol_nombre
            FROM seguridad.usuarios u
            JOIN identidad.personas p ON u.persona_id = p.persona_id
            LEFT JOIN seguridad.usuarios_roles ur ON u.usuario_id = ur.usuario_id
            LEFT JOIN seguridad.roles r ON ur.rol_id = r.rol_id
            ORDER BY u.creado_en DESC
        ");
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $empleados = $pdo->query("
            SELECT e.empleado_id, e.persona_id, e.codigo_empleado, e.cargo,
                   p.nombre_razon_social, p.nombre_comercial, p.documento_identidad
            FROM identidad.empleados e
            JOIN identidad.personas p ON e.persona_id = p.persona_id
            WHERE e.estado_laboral = 'ACTIVO'
            ORDER BY p.nombre_razon_social ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $roles = $pdo->query("SELECT rol_id, nombre FROM seguridad.roles WHERE es_activo = true ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
              <th>Rol Asignado</th>
              <th>Último Acceso</th>
              <th class="text-center">Estado</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaBody">
            <?php if (empty($usuarios)): ?>
              <tr>
                <td colspan="6" class="text-center" style="padding: 30px; color: #888;">
                  No hay usuarios registrados. Haz clic en <strong>+ Nuevo Usuario</strong> para dar de alta uno.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($usuarios as $u): 
                $nombreCompleto = trim($u['nombre_razon_social'] ?? '') ?: $u['username'];
                $esActivo = ($u['es_activo'] === true || $u['es_activo'] === 't' || strtoupper($u['estado'] ?? '') === 'ACTIVO');
              ?>
                <tr class="fila-usuario" data-texto="<?= strtolower(htmlspecialchars($u['username'] . ' ' . $nombreCompleto . ' ' . ($u['rol_nombre'] ?? ''))) ?>">
                  <td>
                    <strong style="color: #a66e2e; font-size: 1.05em;"><?= htmlspecialchars($u['username']) ?></strong>
                  </td>
                  <td>
                    <div style="font-weight: 600; color: #211813;"><?= htmlspecialchars($nombreCompleto) ?></div>
                    <div style="font-size: 0.8em; color: #8a7b70;"><?= htmlspecialchars($u['email'] ?: 'Sin email') ?></div>
                  </td>
                  <td>
                    <span class="badge" style="background: #eadecd; color: #5a3818; font-weight: bold;">
                      <?= htmlspecialchars($u['rol_nombre'] ?: 'Sin Rol') ?>
                    </span>
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
      <form id="formUsuario" action="procesar_usuarios.php" method="POST">
        <input type="hidden" name="accion" id="formAccion" value="crear" />
        <input type="hidden" name="usuario_id" id="usuario_id" value="" />

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
              <input type="text" id="username" name="username" class="form-control" required placeholder="ej. jperez" maxlength="50" />
            </div>
            <div class="form-group">
              <label for="rol_id" class="form-label">Rol del Sistema *</label>
              <select id="rol_id" name="rol_id" class="form-control" required>
                <?php foreach ($roles as $r): ?>
                  <option value="<?= $r['rol_id'] ?>"><?= htmlspecialchars($r['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="password" class="form-label" id="lblPassword">Contraseña de Seguridad *</label>
              <input type="password" id="password" name="password" class="form-control" placeholder="Mínimo 8 caracteres" />
              <small id="hintPassword" style="display: none; color: #888; font-size: 0.8em;">Deja en blanco para conservar la actual</small>
            </div>
            <div class="form-group">
              <label for="es_activo" class="form-label">Estado de Acceso *</label>
              <select id="es_activo" name="es_activo" class="form-control">
                <option value="true">ACTIVO</option>
                <option value="false">INACTIVO / BLOQUEADO</option>
              </select>
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

    <script src="usuarios.js"></script>
</body>
</html>

