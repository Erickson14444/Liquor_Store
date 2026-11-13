let pestanaActiva = new URLSearchParams(window.location.search).get('tab') === 'permisos' ? 'permisos' : 'roles';
    const totalRolesInicial = document.querySelectorAll('.fila-rol').length;
    const totalPermisosInicial = document.querySelectorAll('.fila-permiso').length;

    // Cambiar entre pestaña Roles y pestaña Permisos
    function cambiarPestana(p) {
      pestanaActiva = p;
      document.getElementById('inputBuscar').value = '';

      const tabRoles = document.getElementById('tabRoles');
      const tabPermisos = document.getElementById('tabPermisos');
      const vistaRoles = document.getElementById('vistaRoles');
      const vistaPermisos = document.getElementById('vistaPermisos');
      const btnNuevo = document.getElementById('btnNuevo');
      const cardTitle = document.getElementById('cardTitle');
      const pageSubtitle = document.getElementById('pageSubtitle');
      const badgeCount = document.getElementById('badgeCount');
      const inputBuscar = document.getElementById('inputBuscar');
      const footerInfo = document.getElementById('footerInfo');
      const footerSchema = document.getElementById('footerSchema');

      if (p === 'roles') {
        tabRoles.classList.add('active');
        tabPermisos.classList.remove('active');
        vistaRoles.style.display = '';
        vistaPermisos.style.display = 'none';
        btnNuevo.textContent = '+ Nuevo Rol';
        cardTitle.textContent = 'Roles de Acceso al ERP';
        pageSubtitle.textContent = 'Políticas de acceso RBAC, perfiles de usuario y matriz de facultades';
        badgeCount.textContent = totalRolesInicial + ' roles';
        inputBuscar.placeholder = 'Buscar por rol o descripción...';
        footerInfo.textContent = 'Total: ' + totalRolesInicial + ' roles de seguridad';
        footerSchema.innerHTML = 'Tabla: <strong>seguridad.roles</strong> &bull; Permisos asociados en <code>seguridad.roles_permisos</code>';
      } else {
        tabPermisos.classList.add('active');
        tabRoles.classList.remove('active');
        vistaPermisos.style.display = '';
        vistaRoles.style.display = 'none';
        btnNuevo.textContent = '+ Nuevo Permiso';
        cardTitle.textContent = 'Catálogo de Permisos del Sistema';
        pageSubtitle.textContent = 'Catálogo de permisos por módulo del sistema y autorizaciones de supervisor';
        badgeCount.textContent = totalPermisosInicial + ' permisos';
        inputBuscar.placeholder = 'Buscar por clave, entidad o módulo...';
        footerInfo.textContent = 'Total: ' + totalPermisosInicial + ' permisos registrados';
        footerSchema.innerHTML = 'Tabla: <strong>seguridad.permisos</strong> &bull; Llave única: <code>clave_sistema (VARCHAR)</code>';
      }

      filtrarTabla();
    }

    // Botón principal de "+ Nuevo ..."
    function abrirModalNuevo() {
      if (pestanaActiva === 'roles') {
        abrirModalRol();
      } else {
        abrirModalPermiso();
      }
    }

    // Auto-completar entidad y acción si están vacías al escribir la clave
    function autoDeducirCampos() {
      const clave = document.getElementById('permiso_clave').value.trim();
      const entidad = document.getElementById('permiso_entidad');
      const accion = document.getElementById('permiso_accion');
      if (clave.includes('_')) {
        const partes = clave.split('_');
        if (!entidad.value || entidad.dataset.autollenado === '1') {
          entidad.value = partes[0];
          entidad.dataset.autollenado = '1';
        }
        if (!accion.value || accion.dataset.autollenado === '1') {
          accion.value = partes.slice(1).join('_');
          accion.dataset.autollenado = '1';
        }
      }
    }

    // ------------------------------------------
    // FUNCIONES PARA ROLES
    // ------------------------------------------
    function abrirModalRol() {
      document.getElementById('formAccionRol').value = 'crear';
      document.getElementById('modalTituloRol').textContent = 'Nuevo Rol de Seguridad';
      document.getElementById('rol_id').value = '';
      document.getElementById('rol_nombre').value = '';
      document.getElementById('rol_descripcion').value = '';
      document.getElementById('rol_es_activo').value = 'true';
      document.querySelectorAll('.chk-permiso').forEach(c => c.checked = false);
      document.getElementById('modalRol').classList.add('open');
      setTimeout(() => document.getElementById('rol_nombre').focus(), 100);
    }

    function editarRol(r) {
      document.getElementById('formAccionRol').value = 'editar';
      document.getElementById('modalTituloRol').textContent = 'Editar Rol (' + r.nombre + ')';
      document.getElementById('rol_id').value = r.rol_id;
      document.getElementById('rol_nombre').value = r.nombre;
      document.getElementById('rol_descripcion').value = r.descripcion || '';
      document.getElementById('rol_es_activo').value = (r.es_activo === true || r.es_activo === 't') ? 'true' : 'false';

      const asignados = (r.permisos_ids || '').split(',');
      document.querySelectorAll('.chk-permiso').forEach(c => {
        c.checked = asignados.includes(c.value);
      });

      document.getElementById('modalRol').classList.add('open');
      setTimeout(() => document.getElementById('rol_nombre').focus(), 100);
    }

    function cerrarModalRol() {
      document.getElementById('modalRol').classList.remove('open');
    }

    function cerrarSiFondoRol(e) {
      if (e.target.id === 'modalRol') cerrarModalRol();
    }

    function seleccionarTodosPermisos(marcar) {
      document.querySelectorAll('.chk-permiso').forEach(c => c.checked = marcar);
    }

    function toggleEstadoRol(rolId) {
      if (!confirm("¿Deseas alternar el estado de activación de este rol?")) return;

      const fd = new FormData();
      fd.append('accion', 'cambiar_estado');
      fd.append('rol_id', rolId);
      fd.append('tab', 'roles');
      fd.append('format', 'json');

      fetch('procesar_roles.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.status === 'success') {
            mostrarToast(data.message);
            setTimeout(() => location.href = 'roles.php?tab=roles&msg=' + encodeURIComponent(data.message) + '&tipo=exito', 400);
          } else {
            alert(data.message || 'Error al cambiar estado');
          }
        })
        .catch(err => {
          console.error(err);
          alert('Error al comunicarse con el servidor.');
        });
    }

    // ------------------------------------------
    // FUNCIONES PARA PERMISOS
    // ------------------------------------------
    function abrirModalPermiso() {
      document.getElementById('formAccionPermiso').value = 'crear_permiso';
      document.getElementById('modalTituloPermiso').textContent = 'Nuevo Permiso del Sistema';
      document.getElementById('permiso_id').value = '';
      document.getElementById('permiso_clave').value = '';
      document.getElementById('permiso_modulo').value = 'ventas';
      document.getElementById('permiso_entidad').value = 'VENTAS';
      document.getElementById('permiso_accion').value = 'ACCESO';
      document.getElementById('permiso_descripcion').value = '';
      document.getElementById('btnGuardarPermiso').textContent = 'Guardar Permiso';
      document.getElementById('modalPermiso').classList.add('open');
      setTimeout(() => document.getElementById('permiso_clave').focus(), 100);
    }

    function editarPermiso(p) {
      document.getElementById('formAccionPermiso').value = 'editar_permiso';
      document.getElementById('modalTituloPermiso').textContent = 'Editar Permiso (' + p.clave_sistema + ')';
      document.getElementById('permiso_id').value = p.permiso_id;
      document.getElementById('permiso_clave').value = p.clave_sistema;
      document.getElementById('permiso_modulo').value = p.modulo;
      document.getElementById('permiso_entidad').value = p.entidad || '';
      document.getElementById('permiso_accion').value = p.accion || '';
      document.getElementById('permiso_descripcion').value = p.descripcion || '';
      document.getElementById('btnGuardarPermiso').textContent = 'Guardar Cambios';
      document.getElementById('modalPermiso').classList.add('open');
      setTimeout(() => document.getElementById('permiso_clave').focus(), 100);
    }

    function cerrarModalPermiso() {
      document.getElementById('modalPermiso').classList.remove('open');
    }

    function cerrarSiFondoPermiso(e) {
      if (e.target.id === 'modalPermiso') cerrarModalPermiso();
    }

    function eliminarPermiso(permisoId, clave, totalRoles) {
      if (totalRoles > 0) {
        alert('No se puede eliminar el permiso "' + clave + '" porque está asignado a ' + totalRoles + ' rol(es).\nPrimero retíralo de dichos roles.');
        return;
      }

      if (!confirm('¿Estás seguro de que deseas eliminar permanentemente el permiso "' + clave + '"?')) {
        return;
      }

      const fd = new FormData();
      fd.append('accion', 'eliminar_permiso');
      fd.append('permiso_id', permisoId);
      fd.append('tab', 'permisos');
      fd.append('format', 'json');

      fetch('procesar_roles.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.status === 'success') {
            mostrarToast(data.message);
            setTimeout(() => location.href = 'roles.php?tab=permisos&msg=' + encodeURIComponent(data.message) + '&tipo=exito', 400);
          } else {
            alert(data.message || 'Error al eliminar permiso');
          }
        })
        .catch(err => {
          console.error(err);
          alert('Error de conexión con el servidor.');
        });
    }

    // ------------------------------------------
    // FILTRADO DINÁMICO
    // ------------------------------------------
    function filtrarTabla() {
      const q = document.getElementById('inputBuscar').value.toLowerCase().trim();
      const badgeCount = document.getElementById('badgeCount');
      const footerInfo = document.getElementById('footerInfo');

      if (pestanaActiva === 'roles') {
        const filas = document.querySelectorAll('.fila-rol');
        let visibles = 0;
        filas.forEach(f => {
          const texto = f.getAttribute('data-texto') || '';
          if (texto.includes(q)) {
            f.style.display = '';
            visibles++;
          } else {
            f.style.display = 'none';
          }
        });
        badgeCount.textContent = visibles + ' roles';
        footerInfo.textContent = 'Mostrando ' + visibles + ' de ' + totalRolesInicial + ' roles';
      } else {
        const filas = document.querySelectorAll('.fila-permiso');
        let visibles = 0;
        filas.forEach(f => {
          const texto = f.getAttribute('data-texto') || '';
          if (texto.includes(q)) {
            f.style.display = '';
            visibles++;
          } else {
            f.style.display = 'none';
          }
        });
        badgeCount.textContent = visibles + ' permisos';
        footerInfo.textContent = 'Mostrando ' + visibles + ' de ' + totalPermisosInicial + ' permisos';
      }
    }

    // Cerrar modales con tecla Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        cerrarModalRol();
        cerrarModalPermiso();
      }
    });

    // ------------------------------------------
    // TOAST NOTIFICACIONES
    // ------------------------------------------
    function mostrarToast(msg) {
      const t = document.getElementById('toast');
      t.textContent = msg;
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), 3200);
    }

    (() => {
  const _urlMsg = new URLSearchParams(window.location.search).get('msg');
  if (_urlMsg && typeof mostrarToast === 'function') mostrarToast(_urlMsg);
})();
