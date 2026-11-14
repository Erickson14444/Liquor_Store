function actualizarPrecioSugerido() {
      const sel = document.getElementById('producto_maestro_id');
      const opt = sel.options[sel.selectedIndex];
      if (!opt) return;
      const base = parseFloat(opt.getAttribute('data-precio') || 0);
      const factor = parseInt(document.getElementById('factor_multiplicador').value || 1);
      if (document.getElementById('formAccion').value === 'crear') {
        document.getElementById('precio_venta_presentacion').value = (base * factor).toFixed(2);
      }
    }

    function abrirModal() {
      document.getElementById('formAccion').value = 'crear';
      document.getElementById('modalTitulo').textContent = 'Nueva Presentación';
      document.getElementById('presentacion_id').value = '';
      document.getElementById('codigo_barra').value = '';
      document.getElementById('descripcion_presentacion').value = '';
      document.getElementById('factor_multiplicador').value = '1';
      actualizarPrecioSugerido();
      document.getElementById('modalPresentacion').classList.add('open');
    }

    function editarPresentacion(pres) {
      document.getElementById('formAccion').value = 'editar';
      document.getElementById('modalTitulo').textContent = 'Editar Presentación (' + pres.codigo_barra + ')';
      document.getElementById('presentacion_id').value = pres.id;
      document.getElementById('producto_maestro_id').value = pres.producto_maestro_id;
      document.getElementById('codigo_barra').value = pres.codigo_barra;
      document.getElementById('descripcion_presentacion').value = pres.descripcion_presentacion;
      document.getElementById('factor_multiplicador').value = pres.factor_multiplicador;
      document.getElementById('precio_venta_presentacion').value = parseFloat(pres.precio_venta_presentacion).toFixed(2);
      document.getElementById('modalPresentacion').classList.add('open');
    }

    function cerrarModal(forzar = false) {
      if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
      document.getElementById('modalPresentacion').classList.remove('open');
    }

    function cerrarSiFondo(e) {
      if (e.target.id === 'modalPresentacion') cerrarModal();
    }

    function filtrarTabla() {
      const q = document.getElementById('buscador').value.toLowerCase();
      const filas = document.querySelectorAll('.fila-presentacion');
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
      document.getElementById('footerInfo').textContent = `Mostrando ${visibles} presentaciones`;
    }

    function mostrarToast(msg) {
      const t = document.getElementById('toast');
      t.textContent = msg;
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), 3000);
    }

    (() => {
  const _urlMsg = new URLSearchParams(window.location.search).get('msg');
  if (_urlMsg && typeof mostrarToast === 'function') mostrarToast(_urlMsg);
})();
