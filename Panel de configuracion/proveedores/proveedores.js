// ==========================================
    // MÁSCARAS DE ENTRADA
    // ==========================================
    function mascaraDoc(input) {
      const tipo = document.getElementById('tipo_documento').value;
      let val = input.value.replace(/\D/g, '');
      if (tipo === 'RNC') {
        if (val.length > 9) val = val.substring(0, 9);
        if (val.length > 1 && val.length <= 8) {
          input.value = val.substring(0, 1) + '-' + val.substring(1);
        } else if (val.length > 8) {
          input.value = val.substring(0, 1) + '-' + val.substring(1, 3) + '-' + val.substring(3, 8) + '-' + val.substring(8, 9);
        } else {
          input.value = val;
        }
      } else if (tipo === 'CEDULA') {
        if (val.length > 11) val = val.substring(0, 11);
        if (val.length > 3 && val.length <= 10) {
          input.value = val.substring(0, 3) + '-' + val.substring(3);
        } else if (val.length > 10) {
          input.value = val.substring(0, 3) + '-' + val.substring(3, 10) + '-' + val.substring(10, 11);
        } else {
          input.value = val;
        }
      } else {
        input.value = input.value.toUpperCase();
      }
    }

    function mascaraTelefono(input) {
      let val = input.value.replace(/\D/g, '');
      if (val.length > 10) val = val.substring(0, 10);
      if (val.length > 6) {
        input.value = '(' + val.substring(0, 3) + ') ' + val.substring(3, 6) + '-' + val.substring(6, 10);
      } else if (val.length > 3) {
        input.value = '(' + val.substring(0, 3) + ') ' + val.substring(3);
      } else if (val.length > 0) {
        input.value = '(' + val;
      } else {
        input.value = '';
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
      }
      mascaraDoc(docInput);
    }

    // ==========================================
    // AUTOCOMPLETADO DE TERCERO (SUPERTIPO / SUBTIPO)
    // ==========================================
    function rellenarPersonaProveedor(p) {
      const feedback = document.getElementById('terceroFeedback');
      document.getElementById('persona_id').value = p.persona_id;
      if (p.tipo_documento) document.getElementById('tipo_documento').value = p.tipo_documento;
      if (p.documento_identidad) document.getElementById('documento_identidad').value = p.documento_identidad;
      document.getElementById('nombre_razon_social').value = p.nombre_razon_social || p.primer_nombre;
      if (p.nombre_comercial) document.getElementById('nombre_comercial').value = p.nombre_comercial;
      if (p.telefono_principal || p.telefono) document.getElementById('telefono_principal').value = p.telefono_principal || p.telefono;
      if (p.email) document.getElementById('email').value = p.email;
      if (p.tipo_persona) document.getElementById('tipo_persona').value = p.tipo_persona;
      if (feedback) {
        const rolExtra = p.resumen_roles ? ' [' + p.resumen_roles + ']' : '';
        feedback.style.display = 'block';
        feedback.textContent = '✨ Persona localizada en identidad.personas (' + (p.nombre_razon_social || p.primer_nombre) + rolExtra + '). Sus datos se han rellenado y NO se duplicará en personas.';
      }
      mostrarToast('✨ Datos de identidad.personas cargados automáticamente.');
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
      document.getElementById('dias_credito_habitual').value = '30';
      document.getElementById('retencion_isr_porc').value = '0.00';
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
      document.getElementById('dias_credito_habitual').value = prv.dias_credito_habitual || 30;
      document.getElementById('retencion_isr_porc').value = parseFloat(prv.retencion_isr_porc || 0).toFixed(2);
      document.getElementById('terceroFeedback').style.display = 'none';

      document.getElementById('modalProveedor').classList.add('open');
    }

    function cerrarModal() {
      document.getElementById('modalProveedor').classList.remove('open');
    }

    function cerrarSiFondo(e) {
      if (e.target.id === 'modalProveedor') cerrarModal();
    }

    function toggleEstadoProveedor(proveedorId, esActivoActual) {
      const nuevoEstado = !esActivoActual;
      const accionTxt = nuevoEstado ? 'Activar' : 'Desactivar';
      if (!confirm(`¿Deseas ${accionTxt} este proveedor?`)) return;

      const fd = new FormData();
      fd.append('accion', 'cambiar_estado');
      fd.append('proveedor_id', proveedorId);
      fd.append('estado', nuevoEstado ? 'true' : 'false');
      fd.append('format', 'json');

      fetch('procesar_proveedores.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.status === 'success') {
            mostrarToast(data.message);
            setTimeout(() => location.reload(), 600);
          } else {
            alert(data.message || 'Error al cambiar estado');
          }
        })
        .catch(err => {
          console.error(err);
          alert('Error al comunicarse con el servidor.');
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
})();
