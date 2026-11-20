<?php
/**
 * ============================================================================
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Mantenimiento Unificado de Roles y Permisos de Seguridad
 * Tablas: seguridad.roles, seguridad.permisos, seguridad.roles_permisos
 * Estructura de pestañas análoga a Mantenimiento de Clasificaciones
 * Esquema: seguridad.permisos (clave_sistema, entidad, accion, modulo, descripcion)
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';

$roles = [];
$permisos = [];
$tabActiva = $_GET['tab'] ?? 'roles';
if (!in_array($tabActiva, ['roles', 'permisos'])) {
    $tabActiva = 'roles';
}

if ($pdo) {
    try {
        $sqlSyncPath = __DIR__ . '/../../actualizacion_roles_permisos_pin.sql';
        if (is_file($sqlSyncPath)) {
            $pdo->exec(file_get_contents($sqlSyncPath));
        }
    } catch (Throwable $e) {}

    try {
        // Consultar roles con conteo de permisos asignados
        $stmt = $pdo->query("
            SELECT r.*,
                   (SELECT count(*) FROM seguridad.roles_permisos WHERE rol_id = r.rol_id) AS total_permisos,
                   (SELECT array_to_string(array_agg(permiso_id), ',') FROM seguridad.roles_permisos WHERE rol_id = r.rol_id) AS permisos_ids
            FROM seguridad.roles r
            ORDER BY r.creado_en ASC
        ");
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Consultar catálogo de permisos con conteo y nombres de roles asociados (usando clave_sistema)
        $stmtPerm = $pdo->query("
            SELECT p.permiso_id, 
                   COALESCE(p.clave_sistema, '') AS clave_sistema,
                   COALESCE(p.clave_sistema, '') AS codigo,
                   COALESCE(p.modulo, 'general') AS modulo,
                   COALESCE(p.entidad, 'SISTEMA') AS entidad,
                   COALESCE(p.accion, 'ACCESO') AS accion,
                   COALESCE(p.descripcion, '') AS descripcion,
                   p.creado_en,
                   (SELECT count(*) FROM seguridad.roles_permisos WHERE permiso_id = p.permiso_id) AS total_roles,
                   (SELECT string_agg(r.nombre, ', ' ORDER BY r.nombre) 
                    FROM seguridad.roles_permisos rp 
                    JOIN seguridad.roles r ON r.rol_id = rp.rol_id 
                    WHERE rp.permiso_id = p.permiso_id) AS roles_nombres
            FROM seguridad.permisos p 
            ORDER BY p.modulo ASC, p.clave_sistema ASC
        ");
        $permisos = $stmtPerm->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}

// Diccionario y mapeo oficial de Módulos según el Flujo de Procesos del ERP
$infoModulosProcesos = [
    'identidad' => [
        'num'    => 'MÓDULO 1',
        'nombre' => 'Módulo 1: Identidad Centralizada (Terceros)',
        'icono'  => '👥',
        'badge'  => 'MÓDULO 1: IDENTIDAD'
    ],
    'seguridad' => [
        'num'    => 'MÓDULO 2',
        'nombre' => 'Módulo 2: Seguridad, RBAC y Auditoría',
        'icono'  => '🛡️',
        'badge'  => 'MÓDULO 2: SEGURIDAD'
    ],
    'inventario' => [
        'num'    => 'MÓDULO 3',
        'nombre' => 'Módulo 3: Inventario, Catálogo y Lotes FEFO',
        'icono'  => '📦',
        'badge'  => 'MÓDULO 3: INVENTARIO'
    ],
    'ventas' => [
        'num'    => 'MÓDULO 4',
        'nombre' => 'Módulo 4: Ventas, Caja y Facturación POS',
        'icono'  => '🛒',
        'badge'  => 'MÓDULO 4: VENTAS'
    ],
    'cotizaciones' => [
        'num'    => 'MÓDULO 4',
        'nombre' => 'Módulo 4: Ventas - Cotizaciones B2B',
        'icono'  => '📝',
        'badge'  => 'MÓDULO 4: COTIZACIONES'
    ],
    'delivery' => [
        'num'    => 'MÓDULO 4',
        'nombre' => 'Módulo 4: Ventas - Delivery y Despacho',
        'icono'  => '🛵',
        'badge'  => 'MÓDULO 4: DELIVERY'
    ],
    'cxc' => [
        'num'    => 'MÓDULO 4',
        'nombre' => 'Módulo 4: Ventas - Cuentas por Cobrar (CxC)',
        'icono'  => '💳',
        'badge'  => 'MÓDULO 4: CXC'
    ],
    'compras' => [
        'num'    => 'MÓDULO 5',
        'nombre' => 'Módulo 5: Compras y Recepción (Check-in con NCF)',
        'icono'  => '📥',
        'badge'  => 'MÓDULO 5: COMPRAS'
    ],
    'cxp' => [
        'num'    => 'MÓDULO 5',
        'nombre' => 'Módulo 5: Cuentas por Pagar (CxP y Comprobantes)',
        'icono'  => '🏛️',
        'badge'  => 'MÓDULO 5: CXP'
    ],
    'promociones' => [
        'num'    => 'MÓDULO 6',
        'nombre' => 'Módulo 6: Gestión de Promociones (Reglas, Combos, Cupones)',
        'icono'  => '🎁',
        'badge'  => 'MÓDULO 6: PROMO'
    ],
    'tesoreria' => [
        'num'    => 'MÓDULO 7',
        'nombre' => 'Módulo 7: Movimientos Bancarios (Tesorería)',
        'icono'  => '🏦',
        'badge'  => 'MÓDULO 7: BANCOS'
    ],
    'bancos' => [
        'num'    => 'MÓDULO 7',
        'nombre' => 'Módulo 7: Movimientos Bancarios (Tesorería)',
        'icono'  => '🏦',
        'badge'  => 'MÓDULO 7: BANCOS'
    ],
    'auditoria' => [
        'num'    => 'AUDITORÍA',
        'nombre' => 'Auditoría Forense y Bitácora',
        'icono'  => '🔍',
        'badge'  => 'AUDITORÍA'
    ],
    'configuracion' => [
        'num'    => 'CONFIG',
        'nombre' => 'Configuración General del Sistema',
        'icono'  => '⚙️',
        'badge'  => 'CONFIGURACIÓN'
    ],
    'general' => [
        'num'    => 'GENERAL',
        'nombre' => 'General / Operaciones Base',
        'icono'  => '📁',
        'badge'  => 'GENERAL'
    ]
];

// Agrupar permisos por módulo para organizar la asignación en el modal del rol
$permisosPorModulo = [];
foreach ($permisos as $p) {
    $mod = !empty($p['modulo']) ? $p['modulo'] : 'general';
    $permisosPorModulo[$mod][] = $p;
}

// Ordenar los grupos de permisos estrictamente según el Flujo de Procesos (Módulos 1 al 7)
$ordenModulos = [
    'identidad'    => 1,
    'seguridad'    => 2,
    'inventario'   => 3,
    'ventas'       => 4,
    'cotizaciones' => 5,
    'delivery'     => 6,
    'cxc'          => 7,
    'compras'      => 8,
    'cxp'          => 9,
    'promociones'  => 10,
    'tesoreria'    => 11,
    'bancos'       => 12,
    'auditoria'    => 13,
    'configuracion'=> 14,
    'general'      => 15
];

uksort($permisosPorModulo, function($a, $b) use ($ordenModulos) {
    $ordenA = $ordenModulos[strtolower($a)] ?? 99;
    $ordenB = $ordenModulos[strtolower($b)] ?? 99;
    return $ordenA <=> $ordenB;
});

$msg = $_GET['msg'] ?? '';
$tipoMsg = $_GET['tipo'] ?? 'exito';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Roles y Permisos</title>
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
    <!-- MENÚ LATERAL -->
    <?php include __DIR__ . '/../../sidebar.php'; ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content">

      <!-- ENCABEZADO -->
      <div class="page-header">
        <div>
          <h1 class="page-title">Roles y Permisos de Seguridad</h1>
          <p class="page-subtitle" id="pageSubtitle">
            <?= ($tabActiva === 'roles') 
                ? 'Políticas de acceso RBAC, perfiles de usuario y matriz de facultades' 
                : 'Catálogo de permisos por módulo del sistema y autorizaciones de supervisor' ?>
          </p>
        </div>

        <button type="button" class="btn btn-gold" id="btnNuevo" onclick="abrirModalNuevo()">
          <?= ($tabActiva === 'roles') ? '+ Nuevo Rol' : '+ Nuevo Permiso' ?>
        </button>
      </div>

      <!-- PESTAÑAS (TABS) AL ESTILO CLASIFICACIONES -->
      <div class="tab-nav">
        <button type="button" class="tab-btn <?= ($tabActiva === 'roles') ? 'active' : '' ?>" id="tabRoles" onclick="cambiarPestana('roles')">
          <span>🛡️ Roles de Usuario</span>
          <span class="tab-badge" id="badgeTabRoles"><?= count($roles) ?></span>
        </button>
        <button type="button" class="tab-btn <?= ($tabActiva === 'permisos') ? 'active' : '' ?>" id="tabPermisos" onclick="cambiarPestana('permisos')">
          <span>🔑 Catálogo de Permisos</span>
          <span class="tab-badge" id="badgeTabPermisos"><?= count($permisos) ?></span>
        </button>
      </div>

      <!-- TARJETA DE TABLA UNIFICADA -->
      <div class="table-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title" id="cardTitle">
              <?= ($tabActiva === 'roles') ? 'Roles de Acceso al ERP' : 'Catálogo de Permisos del Sistema' ?>
            </h2>
            <span class="badge-count" id="badgeCount">
              <?= ($tabActiva === 'roles') ? count($roles) . ' roles' : count($permisos) . ' permisos' ?>
            </span>
          </div>

          <div class="search-box" style="width: 290px;">
            <span class="search-icon">🔍</span>
            <input
              type="text"
              id="inputBuscar"
              placeholder="<?= ($tabActiva === 'roles') ? 'Buscar por rol o descripción...' : 'Buscar por clave, entidad o módulo...' ?>"
              oninput="filtrarTabla()"
            />
          </div>
        </div>

        <!-- ========================================== -->
        <!-- VISTA 1: TABLA DE ROLES                    -->
        <!-- ========================================== -->
        <div id="vistaRoles" class="table-wrapper" style="<?= ($tabActiva === 'roles') ? '' : 'display: none;' ?>">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width: 220px;">Nombre del Rol</th>
                <th>Descripción de Funciones</th>
                <th class="text-center" style="width: 170px;">Permisos Asignados</th>
                <th class="text-center" style="width: 140px;">Estado</th>
                <th class="text-right" style="width: 210px;">Acciones</th>
              </tr>
            </thead>
            <tbody id="tablaBodyRoles">
              <?php if (empty($roles)): ?>
                <tr>
                  <td colspan="5" class="text-center" style="padding: 30px; color: #888;">
                    No hay roles configurados. Haz clic en <strong>+ Nuevo Rol</strong> para crear uno.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($roles as $r): 
                  $esActivo = ($r['es_activo'] === true || $r['es_activo'] === 't');
                ?>
                  <tr class="fila-rol" data-texto="<?= strtolower(htmlspecialchars($r['nombre'] . ' ' . $r['descripcion'])) ?>">
                    <td>
                      <strong style="color: #a66e2e; font-size: 1.05em;"><?= htmlspecialchars($r['nombre']) ?></strong>
                    </td>
                    <td>
                      <div style="color: #444; font-size: 0.92em;"><?= htmlspecialchars($r['descripcion']) ?></div>
                    </td>
                    <td class="text-center">
                      <span class="badge" style="background: #eadecd; color: #5a3818; font-weight: bold; padding: 4px 10px; border-radius: 8px;">
                        <?= intval($r['total_permisos']) ?> permisos
                      </span>
                    </td>
                    <td class="text-center">
                      <label class="toggle-wrap" title="Cambiar estado del rol">
                        <input type="checkbox" <?= $esActivo ? 'checked' : '' ?> <?= ($r['nombre'] === 'Administrador') ? 'disabled' : '' ?> onchange="toggleEstadoRol('<?= $r['rol_id'] ?>', this.checked, this)" />
                        <span class="toggle-slider"></span>
                        <span class="toggle-text"><?= $esActivo ? 'Activo' : 'Inactivo' ?></span>
                      </label>
                    </td>
                    <td class="text-right action-btns">
                      <button type="button" class="btn-sm btn-outline" onclick='editarRol(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                        Editar Permisos
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- ========================================== -->
        <!-- VISTA 2: TABLA DE PERMISOS                 -->
        <!-- ========================================== -->
        <div id="vistaPermisos" class="table-wrapper" style="<?= ($tabActiva === 'permisos') ? '' : 'display: none;' ?>">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width: 220px;">Clave de Sistema</th>
                <th style="width: 140px;">Módulo</th>
                <th style="width: 150px;">Entidad / Acción</th>
                <th>Descripción de la Autorización</th>
                <th class="text-center" style="width: 150px;">Roles Asignados</th>
                <th class="text-right" style="width: 150px;">Acciones</th>
              </tr>
            </thead>
            <tbody id="tablaBodyPermisos">
              <?php if (empty($permisos)): ?>
                <tr>
                  <td colspan="6" class="text-center" style="padding: 30px; color: #888;">
                    No hay permisos registrados. Haz clic en <strong>+ Nuevo Permiso</strong> para crear uno.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($permisos as $p): ?>
                  <tr class="fila-permiso" data-texto="<?= strtolower(htmlspecialchars($p['clave_sistema'] . ' ' . $p['modulo'] . ' ' . $p['entidad'] . ' ' . $p['accion'] . ' ' . $p['descripcion'] . ' ' . ($p['roles_nombres'] ?? ''))) ?>">
                    <td>
                      <span class="code-inline" style="font-size: 0.88rem; font-weight: 700; color: #8a5a22; letter-spacing: 0.3px;">
                        <?= htmlspecialchars($p['clave_sistema']) ?>
                      </span>
                    </td>
                    <td>
                      <?php 
                        $mInfo = $infoModulosProcesos[$p['modulo']] ?? [
                          'num'    => strtoupper($p['modulo']),
                          'nombre' => ucfirst($p['modulo']),
                          'icono'  => '📁',
                          'badge'  => strtoupper($p['modulo'])
                        ];
                      ?>
                      <span class="badge" title="<?= htmlspecialchars($mInfo['nombre']) ?>" style="background: #f4ecdf; color: #6d471b; font-weight: 700; font-size: 0.72rem; letter-spacing: 0.3px; padding: 3px 8px; border-radius: 6px; border: 1px solid #e0d0ba; white-space: nowrap;">
                        <?= $mInfo['icono'] ?> <?= htmlspecialchars($mInfo['badge']) ?>
                      </span>
                      <div style="font-size: 0.74rem; color: #888; margin-top: 3px; font-weight: 500;">
                        <?= htmlspecialchars($mInfo['nombre']) ?>
                      </div>
                    </td>
                    <td>
                      <span style="font-size: 0.82rem; font-weight: 600; color: #555;">
                        <?= htmlspecialchars($p['entidad']) ?> &bull; <span style="color: #a66e2e;"><?= htmlspecialchars($p['accion']) ?></span>
                      </span>
                    </td>
                    <td>
                      <div style="color: #444; font-size: 0.92em; line-height: 1.4;">
                        <?= htmlspecialchars($p['descripcion'] ?: 'Sin descripción') ?>
                      </div>
                    </td>
                    <td class="text-center">
                      <?php if (intval($p['total_roles']) > 0): ?>
                        <span class="badge" style="background: #e8f4fd; color: #1565c0; font-weight: bold; padding: 4px 10px; border-radius: 8px; border: 1px solid #bbdefb; cursor: help;" title="Roles asignados: <?= htmlspecialchars($p['roles_nombres']) ?>">
                          👥 <?= intval($p['total_roles']) ?> rol(es)
                        </span>
                      <?php else: ?>
                        <span style="color: #999; font-size: 0.85em; font-style: italic;">
                          Sin asignar
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="text-right action-btns">
                      <button type="button" class="btn-sm btn-outline" onclick='editarPermiso(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                        Editar
                      </button>
                      <button type="button" class="btn-sm btn-danger" onclick="eliminarPermiso('<?= $p['permiso_id'] ?>', '<?= htmlspecialchars(addslashes($p['clave_sistema'])) ?>', <?= intval($p['total_roles']) ?>)">
                        Eliminar
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- PIE DE TARJETA -->
        <div class="table-card-footer">
          <span id="footerInfo">
            <?= ($tabActiva === 'roles') ? 'Total: ' . count($roles) . ' roles de seguridad' : 'Total: ' . count($permisos) . ' permisos registrados' ?>
          </span>
          <span id="footerSchema" style="color: var(--text-muted); font-size: 0.82rem;">
            House &amp; Ice • Seguridad y Control
          </span>
        </div>
      </div>

    </main>
  </div>

  <!-- ========================================== -->
  <!-- MODAL: NUEVO / EDITAR ROL                  -->
  <!-- ========================================== -->
  <div id="modalRol" class="modal-overlay" onclick="cerrarSiFondoRol(event)">
    <div class="modal-box modal-lg">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTituloRol">Nuevo Rol de Seguridad</h2>
      </div>
      <form id="formRol" action="procesar_roles.php" method="POST">
        <input type="hidden" name="accion" id="formAccionRol" value="crear" />
        <input type="hidden" name="tab" value="roles" />
        <input type="hidden" name="rol_id" id="rol_id" value="" />

        <div class="modal-body">
          <div class="form-row-2">
            <div class="form-group">
              <label for="rol_nombre" class="form-label">Nombre del Rol *</label>
              <input type="text" id="rol_nombre" name="nombre" class="form-control" required placeholder="Ej. Supervisor de Piso" maxlength="50" />
            </div>
            <div class="form-group">
              <label for="rol_es_activo" class="form-label">Estado *</label>
              <select id="rol_es_activo" name="es_activo" class="form-control">
                <option value="true">ACTIVO</option>
                <option value="false">INACTIVO</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label for="rol_descripcion" class="form-label">Descripción de Responsabilidades</label>
            <input type="text" id="rol_descripcion" name="descripcion" class="form-control" placeholder="Alcance y facultades de este rol en el ERP" />
          </div>

          <div class="form-group" style="margin-top: 15px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <label class="form-label" style="font-weight: 600; color: #a66e2e; margin: 0;">Matriz de Permisos del Sistema (RBAC)</label>
              <div style="font-size: 0.8rem; display: flex; gap: 8px;">
                <a href="javascript:void(0)" onclick="seleccionarTodosPermisos(true)" style="color: #a66e2e; text-decoration: none; font-weight: 600;">Marcar todos</a>
                <span style="color: #ccc;">•</span>
                <a href="javascript:void(0)" onclick="seleccionarTodosPermisos(false)" style="color: #888; text-decoration: none;">Desmarcar todos</a>
              </div>
            </div>

            <div style="background: #faf7f2; border: 1px solid #eedcb9; border-radius: 8px; padding: 12px; max-height: 250px; overflow-y: auto;">
              <?php if (empty($permisos)): ?>
                <div style="text-align: center; color: #888; padding: 15px; font-size: 0.9rem;">
                  No hay permisos creados aún. Cambia a la pestaña "Catálogo de Permisos" para crear permisos.
                </div>
              <?php else: ?>
                <?php foreach ($permisosPorModulo as $mod => $itemsPerm): 
                  $mInfo = $infoModulosProcesos[$mod] ?? [
                    'num'    => strtoupper($mod),
                    'nombre' => ucfirst($mod),
                    'icono'  => '📁'
                  ];
                ?>
                  <div style="margin-bottom: 14px;">
                    <div style="font-size: 0.76rem; font-weight: 700; color: #794a15; text-transform: uppercase; letter-spacing: 0.5px; padding-bottom: 3px; border-bottom: 1px dashed #d8c29d; margin-bottom: 6px;">
                      <?= $mInfo['icono'] ?> <?= htmlspecialchars($mInfo['nombre']) ?> (<?= count($itemsPerm) ?>)
                    </div>
                    <?php foreach ($itemsPerm as $p): ?>
                      <label style="display: flex; align-items: center; gap: 9px; padding: 5px 0; border-bottom: 1px solid #f2ede4; cursor: pointer;">
                        <input type="checkbox" name="permisos[]" class="chk-permiso" value="<?= $p['permiso_id'] ?>" style="width: 16px; height: 16px; accent-color: #a66e2e;" />
                        <div style="flex: 1;">
                          <div style="display: flex; align-items: center; gap: 6px;">
                            <span style="font-weight: 700; font-size: 0.88em; color: #211813;"><?= htmlspecialchars($p['clave_sistema']) ?></span>
                            <span style="font-size: 0.75rem; color: #888;">(<?= htmlspecialchars($p['entidad']) ?> &bull; <?= htmlspecialchars($p['accion']) ?>)</span>
                          </div>
                          <?php if (!empty($p['descripcion'])): ?>
                            <div style="font-size: 0.78em; color: #666; margin-top: 1px;"><?= htmlspecialchars($p['descripcion']) ?></div>
                          <?php endif; ?>
                        </div>
                      </label>
                    <?php endforeach; ?>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModalRol()">Cancelar</button>
          <button type="submit" class="btn btn-gold">Guardar Rol</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ========================================== -->
  <!-- MODAL: NUEVO / EDITAR PERMISO              -->
  <!-- ========================================== -->
  <div id="modalPermiso" class="modal-overlay" onclick="cerrarSiFondoPermiso(event)">
    <div class="modal-box">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTituloPermiso">Nuevo Permiso del Sistema</h2>
      </div>
      <form id="formPermiso" action="procesar_roles.php" method="POST">
        <input type="hidden" name="accion" id="formAccionPermiso" value="crear_permiso" />
        <input type="hidden" name="tab" value="permisos" />
        <input type="hidden" name="permiso_id" id="permiso_id" value="" />

        <div class="modal-body">
          <div class="form-group">
            <label for="permiso_clave" class="form-label">Clave de Sistema *</label>
            <input 
              type="text" 
              id="permiso_clave" 
              name="clave_sistema" 
              class="form-control" 
              required 
              placeholder="Ej. VENTAS_FACTURAR, INVENTARIO_AJUSTE"
              maxlength="100"
              style="text-transform: uppercase; font-family: var(--font-mono, monospace); font-weight: 600;"
              oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_'); autoDeducirCampos();"
            />
            <small style="color: var(--text-muted); font-size: 0.75rem; margin-top: 4px; display: block;">
              Identificador único del permiso dentro del sistema.
            </small>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="permiso_modulo" class="form-label">Módulo del Flujo de Procesos *</label>
              <select id="permiso_modulo" name="modulo" class="form-control" required>
                <option value="identidad">Módulo 1: Identidad Centralizada (Terceros, Clientes, Empleados, Proveedores)</option>
                <option value="seguridad">Módulo 2: Seguridad, RBAC y Auditoría (Usuarios, Roles, Permisos, PIN)</option>
                <option value="inventario">Módulo 3: Inventario, Catálogo y Lotes (FEFO, Almacenes, Traslados, Mermas)</option>
                <option value="ventas">Módulo 4: Ventas y Facturación POS (Cajas, Facturas, Devoluciones, Consignaciones)</option>
                <option value="cotizaciones">Módulo 4: Ventas - Cotizaciones (Presupuestos B2B)</option>
                <option value="delivery">Módulo 4: Ventas - Delivery y Despacho (Rutas y Tarifas)</option>
                <option value="cxc">Módulo 4: Ventas - Cuentas por Cobrar (CxC y Recibos)</option>
                <option value="compras">Módulo 5: Compras y Recepción (Check-in con NCF)</option>
                <option value="cxp">Módulo 5: Cuentas por Pagar (CxP y Comprobantes Egreso)</option>
                <option value="promociones">Módulo 6: Gestión de Promociones (Combos, Volumen, Cupones, Happy Hour)</option>
                <option value="tesoreria">Módulo 7: Movimientos Bancarios (Tesorería, Cuentas, Depósitos)</option>
                <option value="auditoria">Reportes y Auditoría Forense (Bitácora, 606/607)</option>
                <option value="configuracion">Configuración General del Sistema</option>
              </select>
            </div>
            <div class="form-group">
              <label for="permiso_entidad" class="form-label">Entidad *</label>
              <input 
                type="text" 
                id="permiso_entidad" 
                name="entidad" 
                class="form-control" 
                required 
                placeholder="Ej. VENTAS, PRODUCTOS"
                maxlength="50"
                style="text-transform: uppercase;"
              />
            </div>
          </div>

          <div class="form-group">
            <label for="permiso_accion" class="form-label">Acción *</label>
            <input 
              type="text" 
              id="permiso_accion" 
              name="accion_permiso" 
              class="form-control" 
              required 
              placeholder="Ej. FACTURAR, ANULAR, ACCESO"
              maxlength="50"
              style="text-transform: uppercase;"
            />
          </div>

          <div class="form-group">
            <label for="permiso_descripcion" class="form-label">Descripción de la Autorización *</label>
            <textarea 
              id="permiso_descripcion" 
              name="descripcion" 
              class="form-control" 
              rows="3" 
              required
              placeholder="Detalla qué operaciones o accesos habilita este permiso dentro del ERP..."
              style="resize: vertical;"
            ></textarea>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModalPermiso()">Cancelar</button>
          <button type="submit" class="btn btn-gold" id="btnGuardarPermiso">Guardar Permiso</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast"></div>

    <script src="roles.js"></script>
</body>
</html>
