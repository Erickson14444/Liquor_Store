let pestanaActiva = new URLSearchParams(window.location.search).get('tab') === 'repartidores' ? 'repartidores' : 'personal';
    const totalPersonalInicial = document.querySelectorAll('.fila-personal').length;
    const totalRepartidoresInicial = document.querySelectorAll('.fila-repartidor').length;

    // ------------------------------------------
    // CONTROL DE PESTAÑAS (TABS)
    // ------------------------------------------
    function cambiarPestana(tab) {
      pestanaActiva = tab;
      
      // Actualizar botones
      document.getElementById('tabBtnPersonal').classList.toggle('active', tab === 'personal');
      document.getElementById('tabBtnRepartidores').classList.toggle('active', tab === 'repartidores');

      // Actualizar paneles
      document.getElementById('panePersonal').classList.toggle('active', tab === 'personal');
      document.getElementById('paneRepartidores').classList.toggle('active', tab === 'repartidores');

      // Actualizar botón de acción principal superior
      const btn = document.getElementById('btnNuevoRegistro');
      if (tab === 'personal') {
        btn.textContent = '+ Nuevo Empleado';
      } else {
        btn.textContent = '+ Nuevo Repartidor';
      }

      // Actualizar URL sin recargar
      const url = new URL(window.location);
      url.searchParams.set('tab', tab);
      window.history.replaceState({}, '', url);
    }

    function abrirModalSegunTab() {
      if (pestanaActiva === 'personal') {
        abrirModalEmpleado();
      } else {
        abrirModalRepartidor();
      }
    }

    // ------------------------------------------
    // MÁSCARAS Y FORMATOS DE ENTRADA
    // ------------------------------------------
    function formatearDocumento(input) {
      const tipo = document.getElementById('emp_tipo_documento').value;
      const ev = window.event;
      const isDelete = ev && ev.inputType && ev.inputType.startsWith('delete');
      let val = input.value.replace(/[^0-9]/g, '');

      if ((tipo === 'CEDULA' || tipo === 'RNC') && isDelete && input.dataset.prevDocDigits === val && val.length > 0) {
        val = val.slice(0, -1);
      }

      if (tipo === 'CEDULA') {
        if (val.length > 11) val = val.substring(0, 11);
        let formatted = '';
        if (val.length > 0) formatted += val.substring(0, Math.min(3, val.length));
        if (val.length > 3) formatted += '-' + val.substring(3, Math.min(10, val.length));
        if (val.length > 10) formatted += '-' + val.substring(10, 11);
        input.value = formatted;
        input.dataset.prevDocDigits = val;
      } else if (tipo === 'RNC') {
        if (val.length > 11) val = val.substring(0, 11);
        if (val.length <= 9) {
          let formatted = '';
          if (val.length > 0) formatted += val.substring(0, Math.min(1, val.length));
          if (val.length > 1) formatted += '-' + val.substring(1, Math.min(3, val.length));
          if (val.length > 3) formatted += '-' + val.substring(3, Math.min(8, val.length));
          if (val.length > 8) formatted += '-' + val.substring(8, 9);
          input.value = formatted;
        } else {
          input.value = val;
        }
        input.dataset.prevDocDigits = val;
      }
    }

    function formatearTelefono(input) {
      const ev = window.event;
      const isDelete = ev && ev.inputType && ev.inputType.startsWith('delete');
      let val = input.value.replace(/[^0-9]/g, '');

      // Si el usuario borró un paréntesis, espacio o guion, eliminar también el dígito anterior
      if (isDelete && input.dataset.prevDigits === val && val.length > 0) {
        val = val.slice(0, -1);
      }

      if (val.length > 10) val = val.substring(0, 10);

      if (val.length > 6) {
        input.value = '(' + val.substring(0, 3) + ') ' + val.substring(3, 6) + '-' + val.substring(6, 10);
      } else if (val.length > 3) {
        input.value = '(' + val.substring(0, 3) + ') ' + val.substring(3);
      } else {
        input.value = val;
      }
      input.dataset.prevDigits = val;
    }

    function formatearLicencia(input) {
      const ev = window.event;
      const isDelete = ev && ev.inputType && ev.inputType.startsWith('delete');
      let val = input.value.replace(/[^0-9]/g, '');
      if (isDelete && input.dataset.prevLicDigits === val && val.length > 0) {
        val = val.slice(0, -1);
      }
      if (val.length > 11) val = val.substring(0, 11);
      let formatted = '';
      if (val.length > 0) formatted += val.substring(0, Math.min(3, val.length));
      if (val.length > 3) formatted += '-' + val.substring(3, Math.min(10, val.length));
      if (val.length > 10) formatted += '-' + val.substring(10, 11);
      input.value = formatted;
      input.dataset.prevLicDigits = val;
    }

    function alSeleccionarEmpleadoRepartidor(select) {
      const opt = select.options[select.selectedIndex];
      if (!opt || !opt.value) return;
      const doc = opt.getAttribute('data-documento') || '';
      const tel = opt.getAttribute('data-telefono') || '';
      const licInput = document.getElementById('rep_numero_licencia');
      const telInput = document.getElementById('rep_telefono_principal');
      if (doc && !licInput.value) {
        licInput.value = doc;
        formatearLicencia(licInput);
      }
      if (tel) {
        telInput.value = tel;
        formatearTelefono(telInput);
      }
    }

    function aplicarMascaraDocumento() {
      const input = document.getElementById('emp_documento_identidad');
      const tipo = document.getElementById('emp_tipo_documento').value;
      if (tipo === 'CEDULA') {
        input.placeholder = '000-0000000-0';
      } else if (tipo === 'RNC') {
        input.placeholder = '0-00-00000-0';
      } else {
        input.placeholder = 'Número de Pasaporte';
      }
      formatearDocumento(input);
    }

    // ------------------------------------------
    // BÚSQUEDA LIVE DE TERCEROS (AUTOCOMPLETADO)
    // ------------------------------------------
    let debounceTercero = null;
    function rellenarDatosPersonaEncontrada(p) {
      const feedback = document.getElementById('feedbackTercero');
      document.getElementById('emp_persona_id').value = p.persona_id;
      if (p.empleado_id) {
        document.getElementById('emp_empleado_id').value = p.empleado_id;
        document.getElementById('formAccionEmp').value = 'editar_empleado';
      }
      if (p.codigo_empleado) document.getElementById('emp_codigo_empleado').value = p.codigo_empleado;
      if (p.cargo) document.getElementById('emp_cargo').value = p.cargo;
      if (p.tipo_documento) document.getElementById('emp_tipo_documento').value = p.tipo_documento;
      if (p.documento_identidad) document.getElementById('emp_documento_identidad').value = p.documento_identidad;
      if (p.nombre_razon_social) document.getElementById('emp_nombre_razon_social').value = p.nombre_razon_social;
      if (p.telefono_principal) document.getElementById('emp_telefono_principal').value = p.telefono_principal;
      if (p.email) document.getElementById('emp_email').value = p.email;
      if (p.fecha_ingreso) document.getElementById('emp_fecha_ingreso').value = p.fecha_ingreso;
      if (p.estado_laboral) document.getElementById('emp_estado_laboral').value = p.estado_laboral;

      feedback.style.display = 'block';
      feedback.textContent = 'Registro existente localizado';
      mostrarToast('Datos cargados automáticamente');
    }

    let debounceCodigoEmp = null;
    function verificarEmpleadoPorCodigo(codVal) {
      clearTimeout(debounceCodigoEmp);
      const cod = (codVal || '').trim();
      if (cod.length < 3) return;
      debounceCodigoEmp = setTimeout(() => {
        fetch('procesar_personal.php?codigo=' + encodeURIComponent(cod))
          .then(r => r.json())
          .then(res => {
            if (res.encontrado && res.persona) {
              rellenarDatosPersonaEncontrada(res.persona);
            }
          })
          .catch(() => {});
      }, 300);
    }

    function verificarTerceroLive(doc) {
      clearTimeout(debounceTercero);
      const feedback = document.getElementById('feedbackTercero');
      const docLimpio = doc.replace(/[^0-9A-Za-z]/g, '');

      if (docLimpio.length < 9) {
        feedback.style.display = 'none';
        return;
      }

      debounceTercero = setTimeout(() => {
        fetch('procesar_personal.php?documento=' + encodeURIComponent(doc))
          .then(r => r.json())
          .then(res => {
            if (res.encontrado && res.persona) {
              rellenarDatosPersonaEncontrada(res.persona);
            } else {
              feedback.style.display = 'none';
            }
          })
          .catch(() => {});
      }, 300);
    }

    function verificarTerceroPorNombre(nombre) {
      const nom = (nombre || '').trim();
      const docActual = document.getElementById('emp_documento_identidad').value.trim();
      if (nom.length < 4 || docActual.length >= 9) return;

      fetch('procesar_personal.php?nombre=' + encodeURIComponent(nom))
        .then(r => r.json())
        .then(res => {
          if (res.encontrado && res.persona) {
            rellenarDatosPersonaEncontrada(res.persona);
          }
        })
        .catch(() => {});
    }

    // ------------------------------------------
    // GESTIÓN DE MODAL: EMPLEADO
    // ------------------------------------------
    function abrirModalEmpleado() {
      document.getElementById('formAccionEmp').value = 'crear_empleado';
      document.getElementById('modalTituloEmpleado').textContent = 'Nuevo Empleado';
      document.getElementById('emp_empleado_id').value = '';
      document.getElementById('emp_persona_id').value = '';
      document.getElementById('formEmpleado').reset();

      // Generar código correlativo sugerido
      const count = document.querySelectorAll('.fila-personal').length + 1;
      const codSugerido = 'EMP-' + String(count).padStart(3, '0');
      document.getElementById('emp_codigo_empleado').value = codSugerido;
      document.getElementById('emp_fecha_ingreso').value = new Date().toISOString().split('T')[0];
      document.getElementById('feedbackTercero').style.display = 'none';

      document.getElementById('btnGuardarEmpleado').textContent = 'Guardar Empleado';
      document.getElementById('modalEmpleado').classList.add('open');
      setTimeout(() => document.getElementById('emp_documento_identidad').focus(), 100);
    }

    function editarEmpleado(emp) {
      document.getElementById('formAccionEmp').value = 'editar_empleado';
      document.getElementById('modalTituloEmpleado').textContent = 'Editar Empleado (' + emp.codigo_empleado + ')';
      document.getElementById('emp_empleado_id').value = emp.empleado_id;
      document.getElementById('emp_persona_id').value = emp.persona_id;
      document.getElementById('emp_codigo_empleado').value = emp.codigo_empleado;
      const mapaCargos = {
        'Cajero POS': 'Cajero',
        'Sommelier / Asesor': 'Asesor',
        'Contador / Auditor': 'Contador',
        'Seguridad / Control': 'Seguridad'
      };
      document.getElementById('emp_cargo').value = mapaCargos[emp.cargo] || emp.cargo;
      document.getElementById('emp_tipo_documento').value = (emp.tipo_documento === 'PASAPORTE') ? 'PASAPORTE' : 'CEDULA';
      document.getElementById('emp_documento_identidad').value = emp.documento_identidad;
      document.getElementById('emp_nombre_razon_social').value = emp.nombre_razon_social;
      document.getElementById('emp_telefono_principal').value = emp.telefono_principal || '';
      document.getElementById('emp_email').value = emp.email || '';
      document.getElementById('emp_fecha_ingreso').value = emp.fecha_ingreso || new Date().toISOString().split('T')[0];
      document.getElementById('emp_estado_laboral').value = emp.estado_laboral || 'ACTIVO';
      document.getElementById('feedbackTercero').style.display = 'none';

      document.getElementById('btnGuardarEmpleado').textContent = 'Guardar Cambios';
      document.getElementById('modalEmpleado').classList.add('open');
      setTimeout(() => document.getElementById('emp_nombre_razon_social').focus(), 100);
    }

    function cerrarModalEmpleado(forzar = false) {
      if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
      document.getElementById('modalEmpleado').classList.remove('open');
    }

    function cerrarSiFondoEmpleado(e) {
      if (e.target.id === 'modalEmpleado') cerrarModalEmpleado();
    }

    function guardarEmpleado(e) {
      e.preventDefault();
      const form = document.getElementById('formEmpleado');
      const fd = new FormData(form);
      fd.append('format', 'json');

      const btn = document.getElementById('btnGuardarEmpleado');
      btn.disabled = true;
      btn.textContent = 'Guardando...';

      fetch('procesar_personal.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          btn.disabled = false;
          if (res.status === 'success') {
            cerrarModalEmpleado(true);
            mostrarToast(res.message);
            setTimeout(() => location.href = 'personal.php?tab=personal&msg=' + encodeURIComponent(res.message) + '&tipo=exito', 400);
          } else {
            btn.textContent = (document.getElementById('formAccionEmp').value === 'editar_empleado') ? 'Guardar Cambios' : 'Guardar Empleado';
            mostrarToast('⚠️ ' + (res.message || 'Error al procesar empleado'));
          }
        })
        .catch(err => {
          btn.disabled = false;
          btn.textContent = 'Guardar';
          console.error(err);
          mostrarToast('⚠️ Error de conexión con el servidor.');
        });
    }

    function cambiarEstadoEmpleado(id, nuevoEstado) {
      const accionTexto = (nuevoEstado === 'ACTIVO') ? 'activar' : 'desactivar';
      if (!confirm('¿Deseas ' + accionTexto + ' este empleado en nómina?')) return;

      const fd = new FormData();
      fd.append('accion', 'cambiar_estado_empleado');
      fd.append('empleado_id', id);
      fd.append('nuevo_estado', nuevoEstado);
      fd.append('format', 'json');

      fetch('procesar_personal.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (res.status === 'success') {
            mostrarToast(res.message);
            setTimeout(() => location.href = 'personal.php?tab=personal&msg=' + encodeURIComponent(res.message) + '&tipo=exito', 350);
          } else {
            mostrarToast('⚠️ ' + (res.message || 'Error al cambiar estado'));
          }
        })
        .catch(() => mostrarToast('⚠️ Error de conexión.'));
    }

    // ------------------------------------------
    // GESTIÓN DE MODAL: REPARTIDOR DELIVERY
    // ------------------------------------------
    function abrirModalRepartidor() {
      document.getElementById('formAccionRep').value = 'crear_repartidor';
      document.getElementById('modalTituloRepartidor').textContent = 'Asignar Nuevo Repartidor';
      document.getElementById('formRepartidor').reset();

      document.getElementById('grupoSelectEmpleado').style.display = 'block';
      document.getElementById('rep_empleado_id').required = true;
      document.getElementById('infoRepartidorEditando').style.display = 'none';

      document.getElementById('btnGuardarRepartidor').textContent = 'Guardar Repartidor';
      document.getElementById('modalRepartidor').classList.add('open');
      setTimeout(() => document.getElementById('rep_empleado_id').focus(), 100);
    }

    function editarRepartidor(rep) {
      document.getElementById('formAccionRep').value = 'editar_repartidor';
      document.getElementById('modalTituloRepartidor').textContent = 'Editar Repartidor (' + rep.codigo_empleado + ')';
      
      document.getElementById('grupoSelectEmpleado').style.display = 'none';
      document.getElementById('rep_empleado_id').required = false;
      document.getElementById('rep_empleado_id').value = rep.empleado_id;

      const infoBox = document.getElementById('infoRepartidorEditando');
      infoBox.style.display = 'block';
      document.getElementById('labelNombreRepartidor').textContent = 'Conductor: ' + rep.nombre_razon_social + ' (' + rep.codigo_empleado + ')';

      document.getElementById('rep_numero_licencia').value = rep.numero_licencia || '';
      formatearLicencia(document.getElementById('rep_numero_licencia'));
      document.getElementById('rep_telefono_principal').value = rep.telefono_principal || '';
      formatearTelefono(document.getElementById('rep_telefono_principal'));
      document.getElementById('rep_tipo_vehiculo').value = rep.tipo_vehiculo;
      document.getElementById('rep_placa_vehiculo').value = rep.placa_vehiculo;
      document.getElementById('rep_capacidad_pedidos').value = rep.capacidad_pedidos;
      document.getElementById('rep_estado_logistico').value = rep.estado_logistico;

      document.getElementById('btnGuardarRepartidor').textContent = 'Guardar Cambios';
      document.getElementById('modalRepartidor').classList.add('open');
      setTimeout(() => document.getElementById('rep_numero_licencia').focus(), 100);
    }

    function cerrarModalRepartidor(forzar = false) {
      if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
      document.getElementById('modalRepartidor').classList.remove('open');
    }

    function cerrarSiFondoRepartidor(e) {
      if (e.target.id === 'modalRepartidor') cerrarModalRepartidor();
    }

    function guardarRepartidor(e) {
      e.preventDefault();
      const form = document.getElementById('formRepartidor');
      const fd = new FormData(form);
      fd.append('format', 'json');

      const btn = document.getElementById('btnGuardarRepartidor');
      btn.disabled = true;
      btn.textContent = 'Guardando...';

      fetch('procesar_personal.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          btn.disabled = false;
          if (res.status === 'success') {
            cerrarModalRepartidor(true);
            mostrarToast(res.message);
            setTimeout(() => location.href = 'personal.php?tab=repartidores&msg=' + encodeURIComponent(res.message) + '&tipo=exito', 400);
          } else {
            btn.textContent = (document.getElementById('formAccionRep').value === 'editar_repartidor') ? 'Guardar Cambios' : 'Guardar Repartidor';
            mostrarToast('⚠️ ' + (res.message || 'Error al procesar repartidor'));
          }
        })
        .catch(err => {
          btn.disabled = false;
          btn.textContent = 'Guardar';
          console.error(err);
          mostrarToast('⚠️ Error de conexión.');
        });
    }

    function eliminarRepartidor(empleadoId, nombre) {
      if (!confirm('¿Deseas desvincular a ' + nombre + ' de la flota de repartidores delivery?\n(Su ficha como empleado no será eliminada).')) {
        return;
      }

      const fd = new FormData();
      fd.append('accion', 'eliminar_repartidor');
      fd.append('empleado_id', empleadoId);
      fd.append('tab', 'repartidores');
      fd.append('format', 'json');

      fetch('procesar_personal.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (res.status === 'success') {
            mostrarToast(res.message);
            setTimeout(() => location.href = 'personal.php?tab=repartidores&msg=' + encodeURIComponent(res.message) + '&tipo=exito', 350);
          } else {
            mostrarToast('⚠️ ' + (res.message || 'Error al retirar repartidor'));
          }
        })
        .catch(() => mostrarToast('⚠️ Error de conexión.'));
    }

    // ------------------------------------------
    // FILTROS EN VIVO
    // ------------------------------------------
    function filtrarPersonal() {
      const q = document.getElementById('buscarPersonal').value.toLowerCase().trim();
      const filas = document.querySelectorAll('.fila-personal');
      let visibles = 0;

      filas.forEach(f => {
        const texto = f.getAttribute('data-busqueda') || '';
        if (texto.includes(q)) {
          f.style.display = '';
          visibles++;
        } else {
          f.style.display = 'none';
        }
      });

      document.getElementById('countPersonal').textContent = visibles + ' de ' + totalPersonalInicial + ' empleados';
      document.getElementById('footerPersonal').textContent = 'Mostrando ' + visibles + ' de ' + totalPersonalInicial + ' empleados';
    }

    function filtrarRepartidores() {
      const q = document.getElementById('buscarRepartidores').value.toLowerCase().trim();
      const filas = document.querySelectorAll('.fila-repartidor');
      let visibles = 0;

      filas.forEach(f => {
        const texto = f.getAttribute('data-busqueda') || '';
        if (texto.includes(q)) {
          f.style.display = '';
          visibles++;
        } else {
          f.style.display = 'none';
        }
      });

      document.getElementById('countRepartidores').textContent = visibles + ' de ' + totalRepartidoresInicial + ' unidades';
      document.getElementById('footerRepartidores').textContent = 'Mostrando ' + visibles + ' de ' + totalRepartidoresInicial + ' repartidores';
    }

    // Cerrar modales con tecla Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        if (document.getElementById('modalEmpleado').classList.contains('open')) cerrarModalEmpleado();
        if (document.getElementById('modalRepartidor').classList.contains('open')) cerrarModalRepartidor();
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

    // Si viene mensaje en URL, mostrar toast
    (() => {
  const _urlMsg = new URLSearchParams(window.location.search).get('msg');
  if (_urlMsg && typeof mostrarToast === 'function') mostrarToast(_urlMsg);

  const elNomEmp = document.getElementById('emp_nombre_razon_social');
  if (elNomEmp) {
    elNomEmp.addEventListener('input', function () {
      if (this.value.length > 0) {
        const pos = this.selectionStart;
        this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1);
        if (typeof pos === 'number') this.setSelectionRange(pos, pos);
      }
    });
  }
})();
