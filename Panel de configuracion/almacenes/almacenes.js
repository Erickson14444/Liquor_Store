function abrirModal() {
      document.getElementById('formAccion').value = 'crear';
      document.getElementById('modalTitulo').textContent = 'Nuevo Almacén';
      document.getElementById('almacen_id').value = '';
      document.getElementById('nombre').value = '';
      document.getElementById('ubicacion').value = '';
      const grupo = document.getElementById('grupoEstadoModal');
      if (grupo) grupo.style.display = 'none';
      document.getElementById('modalAlmacen').classList.add('open');
      setTimeout(() => document.getElementById('nombre').focus(), 100);
    }

    function editarAlmacen(a) {
      document.getElementById('formAccion').value = 'editar';
      document.getElementById('modalTitulo').textContent = 'Editar Almacén (' + a.codigo + ')';
      document.getElementById('almacen_id').value = a.id;
      document.getElementById('nombre').value = a.nombre;
      document.getElementById('ubicacion').value = a.ubicacion || '';
      
      const esActivo = (a.activo === true || a.activo === 't' || a.activo == 1 || a.activo === '1');
      const chk = document.getElementById('modalActivo');
      const txt = document.getElementById('modalActivoText');
      if (chk) chk.checked = esActivo;
      if (txt) txt.textContent = esActivo ? 'Activo' : 'Inactivo';

      const grupo = document.getElementById('grupoEstadoModal');
      if (grupo) grupo.style.display = 'block';

      document.getElementById('modalAlmacen').classList.add('open');
      setTimeout(() => document.getElementById('nombre').focus(), 100);
    }

    function cerrarModal(forzar = false) {
      if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
      document.getElementById('modalAlmacen').classList.remove('open');
    }

    function cerrarSiFondo(e) {
      if (e.target.id === 'modalAlmacen') cerrarModal();
    }

    async function guardarAlmacen(e) {
      e.preventDefault();
      const form = document.getElementById('formAlmacen');
      const btn = document.getElementById('btnGuardar');
      const nombre = document.getElementById('nombre').value.trim();

      if (!nombre) {
        alert('Por favor ingrese el nombre del almacén.');
        return;
      }

      const fd = new FormData(form);
      fd.append('format', 'json');

      const origText = btn.textContent;
      btn.textContent = 'Guardando...';
      btn.disabled = true;

      try {
        const res = await fetch('procesar_almacenes.php', {
          method: 'POST',
          headers: { 'Accept': 'application/json' },
          body: fd
        });
        const data = await res.json();

        if (data.status === 'success') {
          cerrarModal(true);
          location.reload();
        } else {
          alert('Aviso: ' + (data.message || 'No se pudo guardar el almacén.'));
        }
      } catch (err) {
        alert('Error de comunicación con el servidor.');
      } finally {
        btn.textContent = origText;
        btn.disabled = false;
      }
    }

    async function toggleEstadoAlmacen(id, checked, checkboxEl) {
      const toggleText = checkboxEl.parentElement.querySelector('.toggle-text');
      const fd = new FormData();
      fd.append('action', 'toggle_estado');
      fd.append('format', 'json');
      fd.append('id', id);
      fd.append('activo', checked);

      try {
        const res = await fetch('procesar_almacenes.php', {
          method: 'POST',
          headers: { 'Accept': 'application/json' },
          body: fd
        });
        const data = await res.json();

        if (data.status === 'success') {
          if (toggleText) toggleText.textContent = checked ? 'Activo' : 'Inactivo';
          mostrarToast(`Almacén #${id}: estado actualizado a ${checked ? 'ACTIVO' : 'INACTIVO'}`);
        } else {
          checkboxEl.checked = !checked;
          if (toggleText) toggleText.textContent = (!checked) ? 'Activo' : 'Inactivo';
          alert('Aviso: ' + (data.message || 'No se pudo cambiar el estado.'));
        }
      } catch (err) {
        checkboxEl.checked = !checked;
        if (toggleText) toggleText.textContent = (!checked) ? 'Activo' : 'Inactivo';
        alert('Error de conexión con el servidor.');
      }
    }

    function mostrarToast(msg) {
      const toast = document.getElementById('toast');
      if (!toast) return;
      toast.textContent = msg;
      toast.className = 'toast show';
      setTimeout(() => { toast.className = 'toast'; }, 2600);
    }

    function filtrarTabla() {
      const q = document.getElementById('buscador').value.toLowerCase();
      document.querySelectorAll('.fila-almacen').forEach(f => {
        f.style.display = (f.getAttribute('data-texto') || '').includes(q) ? '' : 'none';
      });
    }
