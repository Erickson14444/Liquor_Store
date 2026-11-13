function abrirModal() {
      document.getElementById('formAccion').value = 'crear';
      document.getElementById('modalTitulo').textContent = 'Nueva Campaña Promocional';
      document.getElementById('promocion_id').value = '';
      document.getElementById('nombre_promocion').value = '';
      document.getElementById('tipo_promocion').value = 'VOLUMEN';
      document.getElementById('fecha_inicio').value = new Date().toISOString().split('T')[0];
      document.getElementById('fecha_fin').value = new Date(Date.now() + 30 * 86400000).toISOString().split('T')[0];
      document.getElementById('hora_inicio').value = '';
      document.getElementById('hora_fin').value = '';
      document.getElementById('monto_minimo_factura').value = '0.00';
      document.getElementById('condicion_producto_id').value = '';
      document.getElementById('cantidad_requerida').value = '1';
      document.getElementById('beneficio_porcentaje').value = '';
      document.getElementById('modalPromo').classList.add('open');
    }

    function editarPromo(pr) {
      document.getElementById('formAccion').value = 'editar';
      document.getElementById('modalTitulo').textContent = 'Editar Campaña (' + pr.nombre_promocion + ')';
      document.getElementById('promocion_id').value = pr.id;
      document.getElementById('nombre_promocion').value = pr.nombre_promocion;
      document.getElementById('tipo_promocion').value = pr.tipo_promocion;
      document.getElementById('fecha_inicio').value = pr.fecha_inicio;
      document.getElementById('fecha_fin').value = pr.fecha_fin;
      document.getElementById('hora_inicio').value = pr.hora_inicio ? pr.hora_inicio.substring(0,5) : '';
      document.getElementById('hora_fin').value = pr.hora_fin ? pr.hora_fin.substring(0,5) : '';
      document.getElementById('monto_minimo_factura').value = parseFloat(pr.monto_minimo_factura || 0).toFixed(2);
      document.getElementById('condicion_producto_id').value = pr.condicion_producto_id || '';
      document.getElementById('cantidad_requerida').value = pr.cantidad_requerida || '1';
      document.getElementById('beneficio_porcentaje').value = pr.beneficio_porcentaje || '';
      document.getElementById('modalPromo').classList.add('open');
    }

    function cerrarModal(forzar = false) {
      if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
      document.getElementById('modalPromo').classList.remove('open');
    }

    function cerrarSiFondo(e) {
      if (e.target.id === 'modalPromo') cerrarModal();
    }

    function toggleEstadoPromo(promoId, estadoActual) {
      const nuevoEstado = (estadoActual === 'ACTIVA') ? 'INACTIVA' : 'ACTIVA';
      if (!confirm(`¿Deseas cambiar el estado de la promoción a "${nuevoEstado}"?`)) return;

      const fd = new FormData();
      fd.append('accion', 'cambiar_estado');
      fd.append('promocion_id', promoId);
      fd.append('estado', nuevoEstado);
      fd.append('format', 'json');

      fetch('procesar_promociones.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.status === 'success') {
            mostrarToast(data.message);
            setTimeout(() => location.reload(), 600);
          } else {
            alert(data.message || 'Error al actualizar');
          }
        })
        .catch(err => {
          console.error(err);
          alert('Error al comunicarse con el servidor.');
        });
    }

    function filtrarTabla() {
      const q = document.getElementById('buscador').value.toLowerCase();
      const filas = document.querySelectorAll('.fila-promo');
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
      document.getElementById('footerInfo').textContent = `Mostrando ${visibles} campañas`;
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
