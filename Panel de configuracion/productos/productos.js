function abrirModal() {
      document.getElementById('formAccion').value = 'crear';
      document.getElementById('modalTitulo').textContent = 'Nuevo Producto Maestro';
      document.getElementById('producto_id').value = '';
      document.getElementById('sku').value = '';
      document.getElementById('codigo_barra').value = '';
      document.getElementById('nombre').value = '';
      document.getElementById('costo_promedio').value = '0.00';
      document.getElementById('precio_base_unidad').value = '0.00';
      document.getElementById('porcentaje_itbis').value = '18.00';
      document.getElementById('requiere_lote').checked = true;
      document.getElementById('modalProducto').classList.add('open');
    }

    function editarProducto(p) {
      document.getElementById('formAccion').value = 'editar';
      document.getElementById('modalTitulo').textContent = 'Editar Producto (' + p.sku + ')';
      document.getElementById('producto_id').value = p.id;
      document.getElementById('sku').value = p.sku;
      document.getElementById('codigo_barra').value = p.codigo_barra || '';
      document.getElementById('nombre').value = p.descripcion || '';
      document.getElementById('categoria_id').value = p.categoria_id;
      document.getElementById('marca_id').value = p.marca_id;
      document.getElementById('costo_promedio').value = parseFloat(p.costo_promedio).toFixed(2);
      document.getElementById('precio_base_unidad').value = parseFloat(p.precio_base_unidad).toFixed(2);
      document.getElementById('porcentaje_itbis').value = parseFloat(p.porcentaje_itbis).toFixed(2);
      document.getElementById('requiere_lote').checked = (p.requiere_lote === true || p.requiere_lote === 't');
      document.getElementById('modalProducto').classList.add('open');
    }

    function cerrarModal(forzar = false) {
      if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
      document.getElementById('modalProducto').classList.remove('open');
    }

    function cerrarSiFondo(e) {
      if (e.target.id === 'modalProducto') cerrarModal();
    }

    function filtrarTabla() {
      const q = document.getElementById('buscador').value.toLowerCase();
      const filas = document.querySelectorAll('.fila-producto');
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
      document.getElementById('footerInfo').textContent = `Mostrando ${visibles} artículos`;
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
