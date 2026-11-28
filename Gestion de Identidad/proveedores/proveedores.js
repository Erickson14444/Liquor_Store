// ==========================================
    // VALIDACIONES ESTRICTAS DE TECLADO Y MÁSCARAS DE ENTRADA
    // ==========================================
    function soloNumerosEnteros(e) {
      const k = e.key;
      if (['Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(k) || e.ctrlKey || e.metaKey) {
        return true;
      }
      if (!/^[0-9]$/.test(k)) {
        e.preventDefault();
        return false;
      }
      return true;
    }

    function validarTeclaDoc(e) {
      const tipo = document.getElementById('tipo_documento').value;
      const k = e.key;
      if (['Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(k) || e.ctrlKey || e.metaKey) {
        return true;
      }
      if (tipo === 'RNC' || tipo === 'CEDULA') {
        if (!/^[0-9]$/.test(k)) {
          e.preventDefault();
          return false;
        }
      } else {
        if (!/^[0-9A-Za-z]$/.test(k)) {
          e.preventDefault();
          return false;
        }
      }
      return true;
    }

    function marcarRetencionesProveedor(ids = []) {
      const setIds = new Set((Array.isArray(ids) ? ids : []).map(x => Number(x)));
      document.querySelectorAll('.chk-retencion-prov').forEach(chk => {
        chk.checked = setIds.has(Number(chk.value));
      });
    }

    function validarFormularioProveedor(e) {
      const cod = (document.getElementById('codigo_proveedor').value || '').trim().toUpperCase();
      const tipoDoc = document.getElementById('tipo_documento').value;
      const docVal = (document.getElementById('documento_identidad').value || '').trim();
      const docDigits = docVal.replace(/\D/g, '');
      const telVal = (document.getElementById('telefono_principal').value || '').trim();
      const telDigits = telVal.replace(/\D/g, '');
      const diasVal = (document.getElementById('dias_credito_habitual').value || '').trim();

      if (!/^[A-Z0-9_-]{3,20}$/.test(cod)) {
        e.preventDefault();
        mostrarToast('Error: El código de proveedor debe tener formato válido (ej. PROV-001).');
        document.getElementById('codigo_proveedor').focus();
        return false;
      }

      if (tipoDoc === 'RNC' && docDigits.length !== 9 && docDigits.length !== 11) {
        e.preventDefault();
        mostrarToast('Error: El RNC debe contener exactamente 9 dígitos numéricos (formato 0-00-00000-0).');
        document.getElementById('documento_identidad').focus();
        return false;
      }

      if (tipoDoc === 'CEDULA' && docDigits.length !== 11) {
        e.preventDefault();
        mostrarToast('Error: La Cédula debe contener exactamente 11 dígitos numéricos (formato 000-0000000-0).');
        document.getElementById('documento_identidad').focus();
        return false;
      }

      if (telVal !== '' && telDigits.length !== 10) {
        e.preventDefault();
        mostrarToast('Error: El teléfono debe tener 10 dígitos con formato (809) 000-0000.');
        document.getElementById('telefono_principal').focus();
        return false;
      }

      if (!/^\d+$/.test(diasVal) || parseInt(diasVal, 10) < 0 || parseInt(diasVal, 10) > 180) {
        e.preventDefault();
        mostrarToast('Error: Los días de crédito habitual deben ser un número entero entre 0 y 180.');
        document.getElementById('dias_credito_habitual').focus();
        return false;
      }

      return true;
    }

    function mascaraDoc(input) {
      const tipo = document.getElementById('tipo_documento').value;
      const ev = window.event;
      const isDelete = ev && ev.inputType && ev.inputType.startsWith('delete');
      let val = input.value.replace(/\D/g, '');

      if (tipo === 'RNC' || tipo === 'CEDULA') {
        if (isDelete && input.dataset.prevDocDigits === val && val.length > 0) {
          val = val.slice(0, -1);
        }
      }

      if (tipo === 'RNC') {
        if (val.length > 9) val = val.substring(0, 9);
        if (val.length > 1 && val.length <= 8) {
          input.value = val.substring(0, 1) + '-' + val.substring(1);
        } else if (val.length > 8) {
          input.value = val.substring(0, 1) + '-' + val.substring(1, 3) + '-' + val.substring(3, 8) + '-' + val.substring(8, 9);
        } else {
          input.value = val;
        }
        input.dataset.prevDocDigits = val;
      } else if (tipo === 'CEDULA') {
        if (val.length > 11) val = val.substring(0, 11);
        if (val.length > 3 && val.length <= 10) {
          input.value = val.substring(0, 3) + '-' + val.substring(3);
        } else if (val.length > 10) {
          input.value = val.substring(0, 3) + '-' + val.substring(3, 10) + '-' + val.substring(10, 11);
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

    function ajustarTipoPersonaProveedor() {
      const tp = document.getElementById('tipo_persona')?.value || 'JURIDICA';
      const esEdicion = (document.getElementById('formAccion')?.value === 'editar');
      const grp = document.getElementById('grupoFechaNacProv');
      const inp = document.getElementById('fecha_nacimiento');
      if (tp === 'FISICA') {
        if (grp) grp.style.display = '';
        if (inp) inp.required = !esEdicion;
      } else {
        if (grp) grp.style.display = 'none';
        if (inp) { inp.required = false; inp.value = ''; }
      }
    }

    function aplicarMascaraDoc() {
      const tipo = document.getElementById('tipo_documento').value;
      const docInput = document.getElementById('documento_identidad');
      const tipoPersona = document.getElementById('tipo_persona');
      if (tipo === 'RNC') {
        docInput.placeholder = '0-00-00000-0';
        tipoPersona.value = 'JURIDICA';
      } else if (tipo === 'CEDULA') {
        docInput.placeholder = '000-0000000-0';
        tipoPersona.value = 'FISICA';
      } else {
        docInput.placeholder = 'Pasaporte / Extranjero';
        tipoPersona.value = 'FISICA';
      }
      ajustarTipoPersonaProveedor();
      mascaraDoc(docInput);
    }

    // ==========================================
    // AUTOCOMPLETADO DE TERCERO (SUPERTIPO / SUBTIPO)
    // ==========================================
    function rellenarPersonaProveedor(p) {
      const feedback = document.getElementById('terceroFeedback');
      document.getElementById('persona_id').value = p.persona_id;
      if (p.proveedor_id) {
        document.getElementById('proveedor_id').value = p.proveedor_id;
        document.getElementById('formAccion').value = 'editar';
      }
      if (p.codigo_proveedor) document.getElementById('codigo_proveedor').value = p.codigo_proveedor;
      if (p.tipo_documento) document.getElementById('tipo_documento').value = p.tipo_documento;
      if (p.documento_identidad) document.getElementById('documento_identidad').value = p.documento_identidad;
      document.getElementById('nombre_razon_social').value = p.nombre_razon_social || p.primer_nombre;
      if (p.nombre_comercial) document.getElementById('nombre_comercial').value = p.nombre_comercial;
      if (p.telefono_principal || p.telefono) document.getElementById('telefono_principal').value = p.telefono_principal || p.telefono;
      if (p.email) document.getElementById('email').value = p.email;
      if (p.tipo_persona) document.getElementById('tipo_persona').value = p.tipo_persona;
      ajustarTipoPersonaProveedor();
      if (p.fecha_nacimiento) {
        const fn = document.getElementById('fecha_nacimiento');
        if (fn) fn.value = String(p.fecha_nacimiento).substring(0, 10);
      }
      if (p.dias_credito_habitual !== undefined && p.dias_credito_habitual !== null) {
        document.getElementById('dias_credito_habitual').value = p.dias_credito_habitual;
      }
      if (Array.isArray(p.retenciones_ids)) {
        marcarRetencionesProveedor(p.retenciones_ids);
      }
      if (feedback) {
        feedback.style.display = 'block';
        feedback.textContent = 'Registro existente localizado';
      }
      mostrarToast('Datos cargados automáticamente');
    }

    let timerCodigoProv = null;
    function autocompletarProveedorPorCodigo(codVal) {
      clearTimeout(timerCodigoProv);
      const cod = (codVal || '').trim();
      if (cod.length < 3) return;
      timerCodigoProv = setTimeout(() => {
        fetch('procesar_proveedores.php?codigo=' + encodeURIComponent(cod))
          .then(r => r.json())
          .then(res => {
            if (res.encontrado && res.persona) {
              rellenarPersonaProveedor(res.persona);
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
      
      fetch('procesar_proveedores.php?documento=' + encodeURIComponent(doc))
        .then(r => r.json())
        .then(res => {
          if (res.encontrado && res.persona) {
            rellenarPersonaProveedor(res.persona);
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

      fetch('procesar_proveedores.php?nombre=' + encodeURIComponent(nom))
        .then(r => r.json())
        .then(res => {
          if (res.encontrado && res.persona) {
            rellenarPersonaProveedor(res.persona);
          }
        })
        .catch(() => {});
    }

    // ==========================================
    // MODAL
    // ==========================================
    function abrirModal() {
      document.getElementById('formAccion').value = 'crear';
      document.getElementById('modalTitulo').textContent = 'Nuevo Proveedor';
      document.getElementById('proveedor_id').value = '';
      document.getElementById('persona_id').value = '';

      const count = document.querySelectorAll('.fila-prov').length;
      document.getElementById('codigo_proveedor').value = 'PROV-' + String(count + 1).padStart(3, '0');
      document.getElementById('tipo_documento').value = 'RNC';
      document.getElementById('tipo_persona').value = 'JURIDICA';
      document.getElementById('documento_identidad').value = '';
      document.getElementById('nombre_razon_social').value = '';
      document.getElementById('nombre_comercial').value = '';
      document.getElementById('telefono_principal').value = '';
      document.getElementById('email').value = '';
      const fnP = document.getElementById('fecha_nacimiento');
      if (fnP) fnP.value = '';
      document.getElementById('dias_credito_habitual').value = '30';
      marcarRetencionesProveedor([]);
      document.getElementById('terceroFeedback').style.display = 'none';

      aplicarMascaraDoc();
      document.getElementById('modalProveedor').classList.add('open');
    }

    function editarProveedor(prv) {
      document.getElementById('formAccion').value = 'editar';
      document.getElementById('modalTitulo').textContent = 'Editar Proveedor (' + prv.codigo_proveedor + ')';
      document.getElementById('proveedor_id').value = prv.proveedor_id;
      document.getElementById('persona_id').value = prv.persona_id;
      document.getElementById('codigo_proveedor').value = prv.codigo_proveedor;
      document.getElementById('tipo_documento').value = prv.tipo_documento || 'RNC';
      document.getElementById('tipo_persona').value = prv.tipo_persona || 'JURIDICA';
      document.getElementById('documento_identidad').value = prv.documento_identidad;
      document.getElementById('nombre_razon_social').value = prv.nombre_razon_social;
      document.getElementById('nombre_comercial').value = prv.nombre_comercial || '';
      document.getElementById('telefono_principal').value = prv.telefono_principal || '';
      document.getElementById('email').value = prv.email || '';
      document.getElementById('dias_credito_habitual').value = (prv.dias_credito_habitual !== undefined && prv.dias_credito_habitual !== null) ? prv.dias_credito_habitual : 30;
      marcarRetencionesProveedor(Array.isArray(prv.retenciones_ids) ? prv.retenciones_ids : []);
      document.getElementById('terceroFeedback').style.display = 'none';

      ajustarTipoPersonaProveedor();
      const fnPEdit = document.getElementById('fecha_nacimiento');
      if (fnPEdit) fnPEdit.value = prv.fecha_nacimiento ? String(prv.fecha_nacimiento).substring(0, 10) : '';
      document.getElementById('modalProveedor').classList.add('open');
    }

    function cerrarModal(forzar = false) {
      if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
      document.getElementById('modalProveedor').classList.remove('open');
    }

    function cerrarSiFondo(e) {
      if (e.target.id === 'modalProveedor') cerrarModal();
    }

    function toggleEstadoProveedor(proveedorId, checked, checkboxEl) {
      const toggleText = checkboxEl ? checkboxEl.parentElement.querySelector('.toggle-text') : null;
      const fd = new FormData();
      fd.append('accion', 'cambiar_estado');
      fd.append('proveedor_id', proveedorId);
      fd.append('estado', checked ? 'true' : 'false');
      fd.append('format', 'json');

      fetch('procesar_proveedores.php', { method: 'POST', body: fd })
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
      const filas = document.querySelectorAll('.fila-prov');
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
      document.getElementById('footerInfo').textContent = `Mostrando ${visibles} proveedores`;
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
