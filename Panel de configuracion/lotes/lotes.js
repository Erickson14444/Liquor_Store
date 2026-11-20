let filtroFefoActivo = 'TODOS';

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

function abrirModal() {
  document.getElementById('formAccion').value = 'crear';
  document.getElementById('modalTitulo').textContent = 'Nuevo Lote Inicial';
  document.getElementById('lote_id').value = '';
  document.getElementById('producto_maestro_id').value = '';
  document.getElementById('numero_lote').value = '';
  document.getElementById('fecha_vencimiento').value = new Date(Date.now() + 180 * 86400000).toISOString().split('T')[0];
  document.getElementById('cantidad_actual').value = '0';
  const fb = document.getElementById('loteFeedback');
  if (fb) fb.style.display = 'none';
  document.getElementById('modalLote').classList.add('open');
}

function editarLote(lt) {
  document.getElementById('formAccion').value = 'editar';
  document.getElementById('modalTitulo').textContent = 'Editar / Corregir Lote (' + lt.numero_lote + ')';
  document.getElementById('lote_id').value = lt.id;
  document.getElementById('producto_maestro_id').value = lt.producto_maestro_id;
  document.getElementById('numero_lote').value = lt.numero_lote;
  document.getElementById('fecha_vencimiento').value = lt.fecha_vencimiento;
  document.getElementById('cantidad_actual').value = parseInt(lt.cantidad_actual || 0, 10);
  const fb = document.getElementById('loteFeedback');
  if (fb) fb.style.display = 'none';
  document.getElementById('modalLote').classList.add('open');
}

let timerLote = null;
function autocompletarLotePorCodigo() {
  clearTimeout(timerLote);
  const numLote = (document.getElementById('numero_lote').value || '').trim();
  const prodId = document.getElementById('producto_maestro_id').value || '';
  const fb = document.getElementById('loteFeedback');

  if (numLote.length < 3) {
    if (fb) fb.style.display = 'none';
    return;
  }

  timerLote = setTimeout(() => {
    fetch('procesar_lotes.php?numero_lote=' + encodeURIComponent(numLote) + '&producto_maestro_id=' + encodeURIComponent(prodId))
      .then(r => r.json())
      .then(res => {
        if (res.encontrado && res.lote) {
          const l = res.lote;
          document.getElementById('formAccion').value = 'editar';
          document.getElementById('lote_id').value = l.id;
          document.getElementById('producto_maestro_id').value = l.producto_maestro_id;
          document.getElementById('fecha_vencimiento').value = l.fecha_vencimiento;
          document.getElementById('cantidad_actual').value = l.cantidad_actual;
          document.getElementById('modalTitulo').textContent = 'Editar / Corregir Lote (' + l.numero_lote + ')';
          if (fb) {
            fb.textContent = '✓ Lote existente localizado. Datos cargados automáticamente.';
            fb.style.display = 'block';
          }
          mostrarToast('✓ Lote localizado en la base de datos.');
        } else {
          if (fb) fb.style.display = 'none';
        }
      })
      .catch(() => {});
  }, 300);
}

function validarFormularioLote(e) {
  const numLote = (document.getElementById('numero_lote').value || '').trim().toUpperCase();
  const cant = (document.getElementById('cantidad_actual').value || '').trim();
  const fecha = (document.getElementById('fecha_vencimiento').value || '').trim();

  if (!/^[A-Z0-9_-]{3,50}$/.test(numLote)) {
    e.preventDefault();
    mostrarToast('Error: El número de lote debe tener formato válido (ej. LOT-2026-001).');
    document.getElementById('numero_lote').focus();
    return false;
  }

  if (!fecha) {
    e.preventDefault();
    mostrarToast('Error: La fecha de vencimiento es obligatoria.');
    return false;
  }

  if (!/^\d+$/.test(cant) || parseInt(cant, 10) < 0) {
    e.preventDefault();
    mostrarToast('Error: La existencia actual debe ser un número entero mayor o igual a 0.');
    document.getElementById('cantidad_actual').focus();
    return false;
  }

  return true;
}

function cerrarModal(forzar = false) {
  if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
  document.getElementById('modalLote').classList.remove('open');
}

function cerrarSiFondo(e) {
  if (e.target.id === 'modalLote') cerrarModal();
}

function filtrarPorEstadoFefo(estado, btnEl) {
  filtroFefoActivo = estado;
  document.querySelectorAll('.filtro-fefo-btn').forEach(b => {
    b.classList.remove('btn-gold');
    b.classList.add('btn-outline');
  });
  if (btnEl) {
    btnEl.classList.remove('btn-outline');
    btnEl.classList.add('btn-gold');
  }
  filtrarTabla();
}

function filtrarTabla() {
  const q = (document.getElementById('buscador').value || '').toLowerCase();
  const filas = document.querySelectorAll('.fila-lote');
  let visibles = 0;

  filas.forEach(f => {
    const texto = f.getAttribute('data-texto') || '';
    const est = f.getAttribute('data-estado-fefo') || '';
    const coincideTexto = texto.includes(q);
    const coincideEstado = (filtroFefoActivo === 'TODOS' || est === filtroFefoActivo);
    if (coincideTexto && coincideEstado) {
      f.style.display = '';
      visibles++;
    } else {
      f.style.display = 'none';
    }
  });

  document.getElementById('footerInfo').textContent = `Mostrando ${visibles} lotes`;
}

function mostrarToast(msg) {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3000);
}

(() => {
  const _urlMsg = new URLSearchParams(window.location.search).get('msg');
  if (_urlMsg) mostrarToast(_urlMsg);
})();
