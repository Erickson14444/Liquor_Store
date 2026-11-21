<?php
/**
 * ============================================================================
 * Mantenimiento Unificado de Personal & Repartidores
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: identidad.empleados, identidad.repartidores, identidad.personas
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';

$tabActiva = $_GET['tab'] ?? 'personal';
if (!in_array($tabActiva, ['personal', 'repartidores'])) {
    $tabActiva = 'personal';
}

$empleados = [];
$repartidores = [];
$empleadosDisponibles = [];

if ($pdo) {
    try {
        // 1. Listado de Personal / Empleados
        $stmtEmp = $pdo->query("
            SELECT e.empleado_id, e.persona_id, e.codigo_empleado, e.cargo, 
                   e.fecha_ingreso, e.estado_laboral, e.creado_en,
                   p.tipo_documento, p.documento_identidad, p.nombre_razon_social, 
                   p.nombre_comercial, p.email, p.telefono_principal, p.tipo_persona,
                   (SELECT COUNT(*) FROM identidad.repartidores r WHERE r.empleado_id = e.empleado_id) AS es_repartidor
            FROM identidad.empleados e
            JOIN identidad.personas p ON e.persona_id = p.persona_id
            ORDER BY e.codigo_empleado ASC
        ");
        $empleados = $stmtEmp->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 2. Listado de Repartidores Delivery
        $stmtRep = $pdo->query("
            SELECT r.empleado_id, r.numero_licencia, r.tipo_vehiculo, r.placa_vehiculo, 
                   r.estado_logistico, r.capacidad_pedidos,
                   e.codigo_empleado, e.cargo, e.estado_laboral,
                   p.persona_id, p.documento_identidad, p.nombre_razon_social, 
                   p.telefono_principal, p.email
            FROM identidad.repartidores r
            JOIN identidad.empleados e ON r.empleado_id = e.empleado_id
            JOIN identidad.personas p ON e.persona_id = p.persona_id
            ORDER BY p.nombre_razon_social ASC
        ");
        $repartidores = $stmtRep->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 3. Empleados disponibles para asignar como Repartidores (únicamente con cargo de Repartidor Delivery)
        $stmtDisp = $pdo->query("
            SELECT e.empleado_id, e.codigo_empleado, e.cargo, p.nombre_razon_social, p.documento_identidad, p.tipo_documento, p.telefono_principal
            FROM identidad.empleados e
            JOIN identidad.personas p ON e.persona_id = p.persona_id
            WHERE e.estado_laboral = 'ACTIVO'
              AND (e.cargo ILIKE '%Repartidor%' OR e.cargo ILIKE '%Delivery%')
              AND e.empleado_id NOT IN (SELECT empleado_id FROM identidad.repartidores)
            ORDER BY p.nombre_razon_social ASC
        ");
        $empleadosDisponibles = $stmtDisp->fetchAll(PDO::FETCH_ASSOC) ?: [];

    } catch (Throwable $e) {
        $errorCarga = $e->getMessage();
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
  <title>House &amp; Ice • Personal y Repartidores</title>
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
          <h1 class="page-title">Personal &amp; Repartidores</h1>
          <p class="page-subtitle">Gestión centralizada del personal administrativo, cajeros, supervisores y la flota motorizada de delivery</p>
        </div>

        <button type="button" class="btn btn-gold" id="btnNuevoRegistro" onclick="abrirModalSegunTab()">
          + Nuevo Empleado
        </button>
      </div>

      <!-- NAVEGACIÓN POR PESTAÑAS (TABS) -->
      <div class="tab-nav-container">
        <button type="button" class="tab-btn <?= ($tabActiva === 'personal') ? 'active' : '' ?>" id="tabBtnPersonal" onclick="cambiarPestana('personal')">
          <span>👔 Personal</span>
          <span class="tab-badge" id="badgePersonal"><?= count($empleados) ?></span>
        </button>
        <button type="button" class="tab-btn <?= ($tabActiva === 'repartidores') ? 'active' : '' ?>" id="tabBtnRepartidores" onclick="cambiarPestana('repartidores')">
          <span>🛵 Repartidores Delivery</span>
          <span class="tab-badge" id="badgeRepartidores"><?= count($repartidores) ?></span>
        </button>
      </div>

      <!-- ======================================================== -->
      <!-- PESTAÑA 1: LISTADO DE PERSONAL / EMPLEADOS               -->
      <!-- ======================================================== -->
      <div class="tab-pane <?= ($tabActiva === 'personal') ? 'active' : '' ?>" id="panePersonal">
        <div class="table-card">
          <div class="table-card-top">
            <div class="table-card-info">
              <h2 class="table-card-title">Listado de Empleados del Sistema</h2>
              <span class="badge-count" id="countPersonal"><?= count($empleados) ?> registrados</span>
            </div>

            <div class="search-box-unified">
              <span>🔍</span>
              <input type="text" id="buscarPersonal" placeholder="Buscar por código, cédula, nombre o cargo..." oninput="filtrarPersonal()" />
            </div>
          </div>

          <table class="data-table">
            <thead>
              <tr>
                <th style="width: 105px;">Código</th>
                <th>Documento</th>
                <th>Nombre Completo</th>
                <th>Cargo / Puesto</th>
                <th>Teléfono / Contacto</th>
                <th class="text-center" style="width: 130px;">Estado</th>
                <th class="text-right" style="width: 160px;">Acciones</th>
              </tr>
            </thead>
            <tbody id="tbodyPersonal">
              <?php if (empty($empleados)): ?>
                <tr>
                  <td colspan="7" class="text-center" style="padding: 30px; color: #8e8071;">
                    No hay empleados registrados. Haz clic en <strong>+ Nuevo Empleado</strong> para comenzar.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($empleados as $emp): 
                  $est = strtoupper($emp['estado_laboral'] ?? 'ACTIVO');
                  $textoBusqueda = strtolower($emp['codigo_empleado'] . ' ' . $emp['documento_identidad'] . ' ' . $emp['nombre_razon_social'] . ' ' . $emp['cargo'] . ' ' . $emp['telefono_principal']);
                ?>
                  <tr class="fila-personal" data-busqueda="<?= htmlspecialchars($textoBusqueda) ?>">
                    <td><span class="code-badge"><?= htmlspecialchars($emp['codigo_empleado']) ?></span></td>
                    <td>
                      <strong><?= htmlspecialchars($emp['documento_identidad']) ?></strong>
                      <div style="font-size: 0.75rem; color: #8e8071;"><?= htmlspecialchars($emp['tipo_documento'] ?? 'CEDULA') ?></div>
                    </td>
                    <td>
                      <div style="font-weight: 600; color: #2b1f18;"><?= htmlspecialchars($emp['nombre_razon_social']) ?></div>
                      <?php if (!empty($emp['email'])): ?>
                        <div style="font-size: 0.78rem; color: #7a6b5d;"><?= htmlspecialchars($emp['email']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div style="font-weight: 600; color: #8c581c;"><?= htmlspecialchars($emp['cargo']) ?></div>
                      <?php if ($emp['es_repartidor'] > 0): ?>
                        <div style="margin-top: 4px;">
                          <span style="display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; font-size: 0.72rem; background: #e8f4fd; color: #0277bd; padding: 2px 8px; border-radius: 4px; border: 1px solid #b3e5fc; font-weight: 600;">🛵 Delivery</span>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span>📞 <?= htmlspecialchars($emp['telefono_principal'] ?: 'No registrado') ?></span>
                    </td>
                    <td class="text-center">
                      <span class="status-pill <?= strtolower($est) ?>"><?= htmlspecialchars($est) ?></span>
                    </td>
                    <td class="text-right action-btns">
                      <button type="button" class="btn-sm btn-outline" onclick='editarEmpleado(<?= json_encode($emp) ?>)'>Editar</button>
                      <button type="button" class="btn-sm <?= ($est === 'ACTIVO') ? 'btn-danger' : 'btn-gold' ?>" onclick="cambiarEstadoEmpleado('<?= $emp['empleado_id'] ?>', '<?= ($est === 'ACTIVO') ? 'INACTIVO' : 'ACTIVO' ?>')">
                        <?= ($est === 'ACTIVO') ? 'Desactivar' : 'Activar' ?>
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>

          <div class="table-card-footer">
            <span id="footerPersonal">Mostrando <?= count($empleados) ?> de <?= count($empleados) ?> empleados</span>
            <span>House &amp; Ice • Módulo 1: Gestión de Identidad</span>
          </div>
        </div>
      </div>

      <!-- ======================================================== -->
      <!-- PESTAÑA 2: LISTADO DE REPARTIDORES DELIVERY              -->
      <!-- ======================================================== -->
      <div class="tab-pane <?= ($tabActiva === 'repartidores') ? 'active' : '' ?>" id="paneRepartidores">
        <div class="table-card">
          <div class="table-card-top">
            <div class="table-card-info">
              <h2 class="table-card-title">Flota Motorizada y Repartidores</h2>
              <span class="badge-count" id="countRepartidores"><?= count($repartidores) ?> en flota</span>
            </div>

            <div class="search-box-unified">
              <span>🔍</span>
              <input type="text" id="buscarRepartidores" placeholder="Buscar por nombre, licencia, placa o vehículo..." oninput="filtrarRepartidores()" />
            </div>
          </div>

          <table class="data-table">
            <thead>
              <tr>
                <th>Repartidor / Conductor</th>
                <th>Licencia</th>
                <th>Vehículo</th>
                <th>Placa</th>
                <th class="text-center">Capacidad</th>
                <th class="text-center" style="width: 140px;">Estado Logístico</th>
                <th class="text-right" style="width: 160px;">Acciones</th>
              </tr>
            </thead>
            <tbody id="tbodyRepartidores">
              <?php if (empty($repartidores)): ?>
                <tr>
                  <td colspan="7" class="text-center" style="padding: 30px; color: #8e8071;">
                    No hay repartidores registrados en la flota. Haz clic en <strong>+ Nuevo Repartidor</strong> para asignar uno.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($repartidores as $rep): 
                  $estLog = strtoupper($rep['estado_logistico'] ?? 'BASE');
                  $textoBusqueda = strtolower($rep['nombre_razon_social'] . ' ' . $rep['numero_licencia'] . ' ' . $rep['placa_vehiculo'] . ' ' . $rep['tipo_vehiculo'] . ' ' . $rep['codigo_empleado']);
                ?>
                  <tr class="fila-repartidor" data-busqueda="<?= htmlspecialchars($textoBusqueda) ?>">
                    <td>
                      <div style="font-weight: 600; color: #2b1f18;"><?= htmlspecialchars($rep['nombre_razon_social']) ?></div>
                      <div style="font-size: 0.78rem; color: #7a6b5d;">
                        <span class="code-badge" style="font-size: 0.72rem; padding: 1px 5px;"><?= htmlspecialchars($rep['codigo_empleado']) ?></span>
                        <span style="margin-left: 6px;">📞 <?= htmlspecialchars($rep['telefono_principal'] ?: 'Sin teléfono') ?></span>
                      </div>
                    </td>
                    <td>
                      <strong style="color: #8c581c;"><?= htmlspecialchars($rep['numero_licencia']) ?></strong>
                    </td>
                    <td>
                      <span><?= ($rep['tipo_vehiculo'] === 'MOTOCICLETA') ? '🛵' : (($rep['tipo_vehiculo'] === 'FURGONETA') ? '🚐' : '🚗') ?></span>
                      <span style="margin-left: 4px;"><?= htmlspecialchars($rep['tipo_vehiculo']) ?></span>
                    </td>
                    <td>
                      <span class="plate-badge"><?= htmlspecialchars($rep['placa_vehiculo']) ?></span>
                    </td>
                    <td class="text-center">
                      <strong><?= intval($rep['capacidad_pedidos']) ?></strong> <span style="font-size: 0.78rem; color: #8e8071;">pedidos</span>
                    </td>
                    <td class="text-center">
                      <span class="status-pill <?= strtolower($estLog) ?>">
                        <?= str_replace('_', ' ', htmlspecialchars($estLog)) ?>
                      </span>
                    </td>
                    <td class="text-right action-btns">
                      <button type="button" class="btn-sm btn-outline" onclick='editarRepartidor(<?= json_encode($rep) ?>)'>Editar</button>
                      <button type="button" class="btn-sm btn-danger" onclick="eliminarRepartidor('<?= $rep['empleado_id'] ?>', '<?= htmlspecialchars(addslashes($rep['nombre_razon_social'])) ?>')">
                        Retirar
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>

          <div class="table-card-footer">
            <span id="footerRepartidores">Mostrando <?= count($repartidores) ?> de <?= count($repartidores) ?> unidades</span>
            <span>House &amp; Ice • Monitoreo de Despacho &amp; Flota</span>
          </div>
        </div>
      </div>

    </main>
  </div>

  <!-- ======================================================== -->
  <!-- MODAL 1: NUEVO / EDITAR EMPLEADO                         -->
  <!-- ======================================================== -->
  <div id="modalEmpleado" class="modal-overlay" onclick="cerrarSiFondoEmpleado(event)">
    <div class="modal-box" style="max-width: 640px;">
      <div class="modal-header">
        <h3 class="modal-title" id="modalTituloEmpleado">Nuevo Empleado</h3>
      </div>

      <form id="formEmpleado" onsubmit="guardarEmpleado(event)">
        <input type="hidden" name="accion" id="formAccionEmp" value="crear_empleado" />
        <input type="hidden" name="tab" value="personal" />
        <input type="hidden" name="empleado_id" id="emp_empleado_id" value="" />
        <input type="hidden" name="persona_id" id="emp_persona_id" value="" />

        <div class="modal-body">
          <div class="form-row-2">
            <div class="form-group">
              <label for="emp_codigo_empleado" class="form-label">Código de Empleado *</label>
              <input type="text" id="emp_codigo_empleado" name="codigo_empleado" class="form-control" required placeholder="Ej. EMP-001" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, ''); verificarEmpleadoPorCodigo(this.value);" onblur="verificarEmpleadoPorCodigo(this.value)" />
              <small style="color: #8e8071; font-size: 0.78rem;">Identificador único interno de nómina</small>
            </div>
            <div class="form-group">
              <label for="emp_cargo" class="form-label">Cargo / Puesto de Trabajo *</label>
              <select id="emp_cargo" name="cargo" class="form-control" required>
                <option value="Cajero">Cajero</option>
                <option value="Supervisor de Turno">Supervisor de Turno</option>
                <option value="Encargado de Almacén">Encargado de Almacén</option>
                <option value="Repartidor Delivery">Repartidor Delivery</option>
                <option value="Asesor">Asesor</option>
                <option value="Gerente de Tienda">Gerente de Tienda</option>
                <option value="Contador">Contador</option>
                <option value="Seguridad">Seguridad</option>
              </select>
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="emp_tipo_documento" class="form-label">Tipo de Documento *</label>
              <select id="emp_tipo_documento" name="tipo_documento" class="form-control" onchange="aplicarMascaraDocumento()">
                <option value="CEDULA">Cédula Dominicana</option>
                <option value="PASAPORTE">Pasaporte Extranjero</option>
              </select>
            </div>
            <div class="form-group">
              <label for="emp_documento_identidad" class="form-label">Número de Documento *</label>
              <input type="text" id="emp_documento_identidad" name="documento_identidad" class="form-control" required placeholder="000-0000000-0" onkeydown="if(document.getElementById('emp_tipo_documento').value === 'CEDULA' && !['Backspace','Delete','Tab','Escape','Enter','ArrowLeft','ArrowRight','Home','End'].includes(event.key) && !event.ctrlKey && !event.metaKey && !/^[0-9]$/.test(event.key)) { event.preventDefault(); return false; }" oninput="formatearDocumento(this); verificarTerceroLive(this.value);" />
              <small id="feedbackTercero" style="display: none; color: var(--accent-gold); font-size: 0.76rem; margin-top: 3px; font-weight: 600;"></small>
            </div>
          </div>

          <div class="form-group">
            <label for="emp_nombre_razon_social" class="form-label">Nombre Completo *</label>
            <input type="text" id="emp_nombre_razon_social" name="nombre_razon_social" class="form-control" required placeholder="Ej. Juan Carlos Pérez Morales" onblur="verificarTerceroPorNombre(this.value)" />
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="emp_telefono_principal" class="form-label">Teléfono Móvil</label>
              <input type="text" id="emp_telefono_principal" name="telefono_principal" class="form-control" placeholder="(809) 000-0000" maxlength="14" inputmode="numeric" onkeydown="if(!['Backspace','Delete','Tab','Escape','Enter','ArrowLeft','ArrowRight','Home','End'].includes(event.key) && !event.ctrlKey && !event.metaKey && !/^[0-9]$/.test(event.key)) { event.preventDefault(); return false; }" oninput="formatearTelefono(this)" />
            </div>
            <div class="form-group">
              <label for="emp_email" class="form-label">Correo Electrónico</label>
              <input type="email" id="emp_email" name="email" class="form-control" placeholder="empleado@houseandice.do" />
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="emp_fecha_ingreso" class="form-label">Fecha de Ingreso *</label>
              <input type="date" id="emp_fecha_ingreso" name="fecha_ingreso" class="form-control" required value="<?= date('Y-m-d') ?>" />
            </div>
            <div class="form-group">
              <label for="emp_estado_laboral" class="form-label">Estado Laboral *</label>
              <select id="emp_estado_laboral" name="estado_laboral" class="form-control" required>
                <option value="ACTIVO">ACTIVO</option>
                <option value="VACACIONES">VACACIONES</option>
                <option value="SUSPENDIDO">SUSPENDIDO</option>
                <option value="INACTIVO">INACTIVO</option>
              </select>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModalEmpleado()">Cancelar</button>
          <button type="submit" class="btn btn-gold" id="btnGuardarEmpleado">Guardar Empleado</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ======================================================== -->
  <!-- MODAL 2: NUEVO / EDITAR REPARTIDOR DELIVERY              -->
  <!-- ======================================================== -->
  <div id="modalRepartidor" class="modal-overlay" onclick="cerrarSiFondoRepartidor(event)">
    <div class="modal-box" style="max-width: 580px;">
      <div class="modal-header">
        <h3 class="modal-title" id="modalTituloRepartidor">Asignar Repartidor Delivery</h3>
      </div>

      <form id="formRepartidor" onsubmit="guardarRepartidor(event)">
        <input type="hidden" name="accion" id="formAccionRep" value="crear_repartidor" />
        <input type="hidden" name="tab" value="repartidores" />

        <div class="modal-body">
          <div class="form-group" id="grupoSelectEmpleado">
            <label for="rep_empleado_id" class="form-label">Empleado a Designar *</label>
            <select id="rep_empleado_id" name="empleado_id" class="form-control" required onchange="alSeleccionarEmpleadoRepartidor(this)">
              <option value="">-- Selecciona un repartidor disponible --</option>
              <?php foreach ($empleadosDisponibles as $ed): ?>
                <option value="<?= htmlspecialchars($ed['empleado_id']) ?>"
                        data-documento="<?= htmlspecialchars($ed['documento_identidad'] ?? '') ?>"
                        data-telefono="<?= htmlspecialchars($ed['telefono_principal'] ?? '') ?>">
                  <?= htmlspecialchars($ed['codigo_empleado']) ?> • <?= htmlspecialchars($ed['nombre_razon_social']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small style="color: #8e8071; font-size: 0.78rem;">Solo se listan empleados con cargo Repartidor Delivery sin ficha asignada</small>
          </div>

          <div id="infoRepartidorEditando" style="display: none; margin-bottom: 15px; padding: 10px; background: #fdf4e7; border-radius: 6px; border: 1px solid #e6c89c;">
            <strong id="labelNombreRepartidor" style="color: #2b1f18; font-size: 0.95rem;"></strong>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="rep_numero_licencia" class="form-label">Licencia de Conducir *</label>
              <input type="text" id="rep_numero_licencia" name="numero_licencia" class="form-control" required maxlength="13" inputmode="numeric" placeholder="000-0000000-0" onkeydown="if(!['Backspace','Delete','Tab','Escape','Enter','ArrowLeft','ArrowRight','Home','End'].includes(event.key) && !event.ctrlKey && !event.metaKey && !/^[0-9]$/.test(event.key)) { event.preventDefault(); return false; }" oninput="formatearLicencia(this)" />
              <small style="color: #8e8071; font-size: 0.76rem;">Formato oficial INTRANT / Cédula</small>
            </div>
            <div class="form-group">
              <label for="rep_telefono_principal" class="form-label">Teléfono Móvil *</label>
              <input type="text" id="rep_telefono_principal" name="telefono_principal" class="form-control" required maxlength="14" inputmode="numeric" placeholder="(809) 000-0000" onkeydown="if(!['Backspace','Delete','Tab','Escape','Enter','ArrowLeft','ArrowRight','Home','End'].includes(event.key) && !event.ctrlKey && !event.metaKey && !/^[0-9]$/.test(event.key)) { event.preventDefault(); return false; }" oninput="formatearTelefono(this)" />
              <small style="color: #8e8071; font-size: 0.76rem;">Número de contacto en ruta</small>
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="rep_tipo_vehiculo" class="form-label">Tipo de Vehículo *</label>
              <select id="rep_tipo_vehiculo" name="tipo_vehiculo" class="form-control" required>
                <option value="MOTOCICLETA">🛵 Motocicleta / Pasola</option>
                <option value="FURGONETA">🚐 Furgoneta / Camioneta</option>
                <option value="AUTOMOVIL">🚗 Automóvil / Sedán</option>
              </select>
            </div>
            <div class="form-group">
              <label for="rep_placa_vehiculo" class="form-label">Placa del Vehículo *</label>
              <input type="text" id="rep_placa_vehiculo" name="placa_vehiculo" class="form-control" required maxlength="10" placeholder="Ej. K0123456" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '')" />
            </div>
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="rep_capacidad_pedidos" class="form-label">Capacidad de Pedidos Simultáneos *</label>
              <input type="number" id="rep_capacidad_pedidos" name="capacidad_pedidos" class="form-control" required min="1" max="25" value="1" onkeydown="if(['e','E','+','-','.',','].includes(event.key)) { event.preventDefault(); return false; }" oninput="this.value = this.value.replace(/[^0-9]/g, '');" />
            </div>
            <div class="form-group">
              <label for="rep_estado_logistico" class="form-label">Estado Logístico en Tiempo Real *</label>
              <select id="rep_estado_logistico" name="estado_logistico" class="form-control" required>
                <option value="BASE">BASE</option>
                <option value="EN_RUTA_CON_ESPACIO">EN RUTA CON ESPACIO</option>
                <option value="EN_RUTA_LLENO">EN RUTA LLENO</option>
                <option value="INACTIVO">INACTIVO</option>
              </select>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModalRepartidor()">Cancelar</button>
          <button type="submit" class="btn btn-gold" id="btnGuardarRepartidor">Guardar Repartidor</button>
        </div>
      </form>
    </div>
  </div>

  <!-- TOAST DE NOTIFICACIONES -->
  <div id="toast" class="toast"></div>

    <script src="personal.js?v=4"></script>
</body>
</html>

