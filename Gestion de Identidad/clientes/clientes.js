// ==========================================
    // MÁSCARAS Y FORMATOS DE ENTRADA
    // ==========================================
    function mascaraDoc(input) {
      const tipo = document.getElementById('tipo_documento').value;
      const ev = window.event;
      const isDelete = ev && ev.inputType && ev.inputType.startsWith('delete');
      let val = input.value.replace(/\D/g, '');

      if (tipo === 'CEDULA' || tipo === 'RNC') {
        if (isDelete && input.dataset.prevDocDigits === val && val.length > 0) {
          val = val.slice(0, -1);
        }
      }

      if (tipo === 'CEDULA') {
        if (val.length > 11) val = val.substring(0, 11);
        if (val.length > 3 && val.length <= 10) {
          input.value = val.substring(0, 3) + '-' + val.substring(3);
        } else if (val.length > 10) {
          input.value = val.substring(0, 3) + '-' + val.substring(3, 10) + '-' + val.substring(10, 11);
        } else {
          input.value = val;
        }
        input.dataset.prevDocDigits = val;
      } else if (tipo === 'RNC') {
        if (val.length > 9) val = val.substring(0, 9);
        if (val.length > 1 && val.length <= 8) {
          input.value = val.substring(0, 1) + '-' + val.substring(1);
        } else if (val.length > 8) {
          input.value = val.substring(0, 1) + '-' + val.substring(1, 3) + '-' + val.substring(3, 8) + '-' + val.substring(8, 9);
        } else {
          input.value = val;
        }
        input.dataset.prevDocDigits = val;
      } else {
        input.value = input.value.toUpperCase();
      }
    }

    function mascaraTelefono(input) {
      const ev = window.event;
      const isDelete = ev && ev.inputType && ev.inputType.startsWith('delete');
      let val = input.value.replace(/\D/g, '');

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

    function aplicarMascaraDoc() {
      const tipo = document.getElementById('tipo_documento').value;
      const docInput = document.getElementById('documento_identidad');
      const tipoPersona = document.getElementById('tipo_persona');
      if (tipo === 'CEDULA') {
        docInput.placeholder = '000-0000000-0';
        tipoPersona.value = 'FISICA';
      } else if (tipo === 'RNC') {
        docInput.placeholder = '0-00-00000-0';
        tipoPersona.value = 'JURIDICA';
      } else {
        docInput.placeholder = 'Pasaporte / Identificación';
      }
      ajustarTipoPersona();
      mascaraDoc(docInput);
    }

    function ajustarTipoPersona() {
      const tp = document.getElementById('tipo_persona').value;
      const lbl = document.getElementById('labelNombre');
      const inp = document.getElementById('nombre_razon_social');
      const selDoc = document.getElementById('tipo_documento');
      const optRnc = selDoc ? selDoc.querySelector('option[value="RNC"]') : null;
      const optCed = selDoc ? selDoc.querySelector('option[value="CEDULA"]') : null;
      const optPas = selDoc ? selDoc.querySelector('option[value="PASAPORTE"]') : null;

      const grpFecha = document.getElementById('grupoFechaNacimiento');
      const inpFecha = document.getElementById('fecha_nacimiento');

      const esEdicion = (document.getElementById('formAccion')?.value === 'editar');
      if (tp === 'JURIDICA') {
        lbl.textContent = 'Razón Social de la Empresa *';
        inp.placeholder = 'Ej. Distribuidora del Cibao SAS';
        if (optRnc) optRnc.hidden = false;
        if (optCed) optCed.hidden = true;
        if (optPas) optPas.hidden = true;
        if (selDoc && selDoc.value !== 'RNC') selDoc.value = 'RNC';
        if (grpFecha) grpFecha.style.display = 'none';
        if (inpFecha) { inpFecha.required = false; inpFecha.value = ''; }
      } else {
        lbl.textContent = 'Nombres y Apellidos *';
        inp.placeholder = 'Ej. Juan Pérez';
        if (optRnc) optRnc.hidden = true;
        if (optCed) optCed.hidden = false;
        if (optPas) optPas.hidden = false;
        if (selDoc && selDoc.value === 'RNC') selDoc.value = 'CEDULA';
        if (grpFecha) grpFecha.style.display = '';
        if (inpFecha) inpFecha.required = !esEdicion;
      }
    }

    function toggleCreditoInput() {
      const chk = document.getElementById('permite_credito');
      const div = document.getElementById('grupoLimiteCredito');
      div.style.display = chk.checked ? 'block' : 'none';
      if (!chk.checked) {
        document.getElementById('limite_credito').value = '0.00';
      }
    }

    // ==========================================
    // AUTOCOMPLETADO DE TERCERO (SUPERTIPO / SUBTIPO)
    // ==========================================
    function rellenarPersonaCliente(p) {
      const feedback = document.getElementById('terceroFeedback');
      document.getElementById('persona_id').value = p.persona_id;
      if (p.cliente_id) {
        document.getElementById('cliente_id').value = p.cliente_id;
        document.getElementById('formAccion').value = 'editar';
      }
      if (p.codigo_cliente) document.getElementById('codigo_cliente').value = p.codigo_cliente;
      if (p.tipo_documento) document.getElementById('tipo_documento').value = p.tipo_documento;
      if (p.documento_identidad) document.getElementById('documento_identidad').value = p.documento_identidad;
      document.getElementById('nombre_razon_social').value = p.nombre_razon_social || (p.primer_nombre ? (p.primer_nombre + ' ' + (p.primer_apellido || '')) : '');
      if (p.nombre_comercial) document.getElementById('nombre_comercial').value = p.nombre_comercial;
      if (p.telefono_principal || p.telefono) document.getElementById('telefono_principal').value = p.telefono_principal || p.telefono;
      if (p.email) document.getElementById('email').value = p.email;
      if (p.tipo_persona) {
        document.getElementById('tipo_persona').value = p.tipo_persona;
        ajustarTipoPersona();
      }
      if (p.fecha_nacimiento) {
        const fn = document.getElementById('fecha_nacimiento');
        if (fn) fn.value = String(p.fecha_nacimiento).substring(0, 10);
      }
      if (p.categoria_cliente) document.getElementById('categoria_cliente').value = p.categoria_cliente;
      if (p.permite_credito !== undefined && p.permite_credito !== null) {
        const tieneCred = (p.permite_credito === true || p.permite_credito === 't' || p.permite_credito === 1);
        document.getElementById('permite_credito').checked = tieneCred;
        document.getElementById('limite_credito').value = parseFloat(p.limite_credito || 0).toFixed(2);
        toggleCreditoInput();
      }
      if (feedback) {
        feedback.style.display = 'block';
        feedback.textContent = 'Registro existente localizado';
      }
      mostrarToast('Datos cargados automáticamente');
    }

    let timerCodigoCli = null;
    function autocompletarClientePorCodigo(codVal) {
      clearTimeout(timerCodigoCli);
      const cod = (codVal || '').trim();
      if (cod.length < 3) return;
      timerCodigoCli = setTimeout(() => {
        fetch('procesar_clientes.php?codigo=' + encodeURIComponent(cod))
          .then(r => r.json())
          .then(res => {
            if (res.encontrado && res.persona) {
              rellenarPersonaCliente(res.persona);
            }
          })
          .catch(() => {});
      }, 300);
    }

    function autocompletarTercero() {
      const doc = document.getElementById('documento_identidad').value.trim();
      const feedback = document.getElementById('terceroFeedback');
      const docLimpio = doc.replace(/\D/g, '');
      if (docLimpio.length < 8) {
        if (feedback) feedback.style.display = 'none';
        return;
      }
      
      fetch('procesar_clientes.php?documento=' + encodeURIComponent(doc))
        .then(r => r.json())
        .then(res => {
          if (res.encontrado && res.persona) {
            rellenarPersonaCliente(res.persona);
          } else {
            if (feedback) feedback.style.display = 'none';
          }
        })
        .catch(() => {});
    }

    function autocompletarTerceroPorNombre(nombre) {
      const nom = (nombre || '').trim();
      const docActual = document.getElementById('documento_identidad').value.trim();
      if (nom.length < 4 || docActual.replace(/\D/g, '').length >= 8) return;

      fetch('procesar_clientes.php?nombre=' + encodeURIComponent(nom))
        .then(r => r.json())
        .then(res => {
          if (res.encontrado && res.persona) {
            rellenarPersonaCliente(res.persona);
          }
        })
        .catch(() => {});
    }

    // ==========================================
    // APERTURA / CIERRE MODAL
    // ==========================================
    function abrirModal() {
      document.getElementById('formAccion').value = 'crear';
      document.getElementById('modalTitulo').textContent = 'Nuevo Cliente';
      document.getElementById('cliente_id').value = '';
      document.getElementById('persona_id').value = '';
      
      const count = document.querySelectorAll('.fila-cliente').length;
      document.getElementById('codigo_cliente').value = 'CLI-' + String(count + 1).padStart(3, '0');
      document.getElementById('tipo_persona').value = 'FISICA';
      document.getElementById('tipo_documento').value = 'CEDULA';
      document.getElementById('documento_identidad').value = '';
      document.getElementById('nombre_razon_social').value = '';
      document.getElementById('nombre_comercial').value = '';
      document.getElementById('telefono_principal').value = '';
      document.getElementById('email').value = '';
      const fnCli = document.getElementById('fecha_nacimiento');
      if (fnCli) fnCli.value = '';
      document.getElementById('categoria_cliente').value = 'REGULAR';
      document.getElementById('permite_credito').checked = false;
      document.getElementById('limite_credito').value = '0.00';
      document.getElementById('terceroFeedback').style.display = 'none';
      
      ajustarTipoPersona();
      toggleCreditoInput();
      document.getElementById('modalCliente').classList.add('open');
    }

    function editarCliente(cli) {
      document.getElementById('formAccion').value = 'editar';
      document.getElementById('modalTitulo').textContent = 'Editar Cliente (' + cli.codigo_cliente + ')';
      document.getElementById('cliente_id').value = cli.cliente_id;
      document.getElementById('persona_id').value = cli.persona_id;
      document.getElementById('codigo_cliente').value = cli.codigo_cliente;
      document.getElementById('tipo_persona').value = cli.tipo_persona || 'FISICA';
      document.getElementById('tipo_documento').value = cli.tipo_documento || 'CEDULA';
      document.getElementById('documento_identidad').value = cli.documento_identidad;
      document.getElementById('nombre_razon_social').value = cli.nombre_razon_social;
      document.getElementById('nombre_comercial').value = cli.nombre_comercial || '';
      document.getElementById('telefono_principal').value = cli.telefono_principal || '';
      document.getElementById('email').value = cli.email || '';
      document.getElementById('categoria_cliente').value = cli.categoria_cliente || 'REGULAR';
      
      const tieneCred = (cli.permite_credito === true || cli.permite_credito === 't' || cli.permite_credito === 1);
      document.getElementById('permite_credito').checked = tieneCred;
      document.getElementById('limite_credito').value = parseFloat(cli.limite_credito || 0).toFixed(2);
      document.getElementById('terceroFeedback').style.display = 'none';

      ajustarTipoPersona();
      const fnCliEdit = document.getElementById('fecha_nacimiento');
      if (fnCliEdit) fnCliEdit.value = cli.fecha_nacimiento ? String(cli.fecha_nacimiento).substring(0, 10) : '';
      toggleCreditoInput();
      document.getElementById('modalCliente').classList.add('open');
    }

    function cerrarModal(forzar = false) {
      if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
      document.getElementById('modalCliente').classList.remove('open');
    }

    function cerrarSiFondo(e) {
      if (e.target.id === 'modalCliente') cerrarModal();
    }

    function toggleEstadoCliente(clienteId, checked, checkboxEl) {
      const toggleText = checkboxEl ? checkboxEl.parentElement.querySelector('.toggle-text') : null;
      const fd = new FormData();
      fd.append('accion', 'cambiar_estado');
      fd.append('cliente_id', clienteId);
      fd.append('estado', checked ? 'true' : 'false');
      fd.append('format', 'json');

      fetch('procesar_clientes.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.status === 'success') {
            if (toggleText) toggleText.textContent = checked ? 'Activo' : 'Inactivo';
            mostrarToast(data.message);
          } else {
            if (checkboxEl) checkboxEl.checked = !checked;
            if (toggleText) toggleText.textContent = (!checked) ? 'Activo' : 'Inactivo';
            mostrarToast('⚠️ ' + (data.message || 'Error al cambiar estado'));
          }
        })
        .catch(err => {
          if (checkboxEl) checkboxEl.checked = !checked;
          if (toggleText) toggleText.textContent = (!checked) ? 'Activo' : 'Inactivo';
          console.error(err);
          mostrarToast('⚠️ Error al comunicarse con el servidor.');
        });
    }

    function filtrarTabla() {
      const q = document.getElementById('buscador').value.toLowerCase();
      const filas = document.querySelectorAll('.fila-cliente');
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
      document.getElementById('footerInfo').textContent = `Mostrando ${visibles} clientes`;
    }

    function mostrarToast(msg) {
      const t = document.getElementById('toast');
      t.textContent = msg;
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), 3000);
    }

    // Notificación en URL
    (() => {
  const _urlMsg = new URLSearchParams(window.location.search).get('msg');
  if (_urlMsg && typeof mostrarToast === 'function') mostrarToast(_urlMsg);

  ['nombre_razon_social', 'nombre_comercial'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.addEventListener('input', function () {
        if (this.value.length > 0) {
          const pos = this.selectionStart;
          this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1);
          if (typeof pos === 'number') this.setSelectionRange(pos, pos);
        }
      });
    }
  });
})();
