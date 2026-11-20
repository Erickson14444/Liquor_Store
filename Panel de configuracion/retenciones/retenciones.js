const _datosRetenciones = document.getElementById('datosRetenciones');
let retenciones = _datosRetenciones ? JSON.parse(_datosRetenciones.dataset.retenciones || '[]') : [];
let editandoId = null;

function soloNumerosDecimales(e, input) {
  const k = e.key;
  if (['Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(k) || e.ctrlKey || e.metaKey) {
    return true;
  }
  if (k === '.' && input && !input.value.includes('.')) {
    return true;
  }
  if (!/^[0-9]$/.test(k)) {
    e.preventDefault();
    return false;
  }
  return true;
}

function limpiarDecimalDos(input) {
  let val = (input.value || '').replace(/[^0-9.]/g, '');
  const partes = val.split('.');
  if (partes.length > 2) {
    val = partes[0] + '.' + partes.slice(1).join('');
  }
  const p2 = val.split('.');
  if (p2.length === 2 && p2[1].length > 2) {
    val = p2[0] + '.' + p2[1].slice(0, 2);
  }
  if (input.value !== val) input.value = val;
}

function autocompletarPorSiglas(val) {
  const sig = (val || '').trim().toUpperCase();
  const fb = document.getElementById('siglasFeedback');
  if (sig.length < 3) {
    if (fb) fb.style.display = 'none';
    return;
  }
  const ex = retenciones.find(r => String(r.siglas).toUpperCase() === sig);
  if (ex && Number(ex.id) !== Number(editandoId)) {
    editandoId = Number(ex.id);
    document.getElementById('modalTitulo').textContent = 'Editar Retención (' + ex.siglas + ')';
    document.getElementById('nombre').value = ex.nombre;
    document.getElementById('base_calculo').value = ex.base_calculo;
    document.getElementById('porcentaje').value = Number(ex.porcentaje).toFixed(2);
    if (fb) {
      fb.textContent = `✓ Retención existente localizada (${ex.nombre}). Datos cargados.`;
      fb.style.display = 'block';
    }
    showToast(`✓ Retención ${ex.siglas} cargada.`);
  } else if (fb) {
    fb.style.display = 'none';
  }
}

function renderizarTabla(filtro = '') {
  const tbody = document.getElementById('tablaBody');
  tbody.innerHTML = '';
  const q = filtro.trim().toLowerCase();
  const filtradas = retenciones.filter(r =>
    String(r.siglas).toLowerCase().includes(q) ||
    String(r.nombre).toLowerCase().includes(q) ||
    String(r.base_calculo).toLowerCase().includes(q)
  );

  if (filtradas.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 28px; color: var(--text-muted);">No hay retenciones registradas.</td></tr>';
    return;
  }

  filtradas.forEach(r => {
    const tr = document.createElement('tr');
    const isActiva = (r.activo === true || r.activo === 't' || r.activo === 1);
    const badgeBase = r.base_calculo === 'IMPUESTO'
      ? '<span class="badge" style="background: #e8f4fd; color: #1565c0; border: 1px solid #bbdefb; font-weight: 700;">IMPUESTO (Sobre ITBIS)</span>'
      : '<span class="badge" style="background: #eadecd; color: #5a3818; font-weight: 700;">SUBTOTAL (Sobre Neto • ISR)</span>';

    tr.innerHTML = `
      <td><span class="code-inline" style="font-weight: 700; color: #a66e2e;">${escapeHtml(r.siglas)}</span></td>
      <td><strong>${escapeHtml(r.nombre)}</strong></td>
      <td class="text-center">${badgeBase}</td>
      <td class="text-right" style="font-family: monospace; font-weight: 700; color: #211813;">${Number(r.porcentaje).toFixed(2)}%</td>
      <td class="text-center">
        <label class="toggle-wrap">
          <input type="checkbox" ${isActiva ? 'checked' : ''} onchange="toggleEstado(${r.id}, this.checked)" />
          <span class="toggle-slider"></span>
          <span class="toggle-text">${isActiva ? 'Activa' : 'Inactiva'}</span>
        </label>
      </td>
      <td class="text-right">
        <button type="button" class="link-edit" onclick="abrirModal(${r.id})">Editar</button>
      </td>
    `;
    tbody.appendChild(tr);
  });

  document.getElementById('badgeCount').textContent = `${retenciones.length} retenciones`;
  document.getElementById('footerInfo').textContent = `Mostrando ${filtradas.length} de ${retenciones.length} retenciones DGII`;
}

async function toggleEstado(id, checked) {
  const fd = new FormData();
  fd.append('action', 'toggle_estado');
  fd.append('id', id);
  fd.append('activo', checked);
  try {
    await fetch('procesar_retenciones.php', { method: 'POST', body: fd });
    const item = retenciones.find(i => Number(i.id) === Number(id));
    if (item) item.activo = checked;
    showToast('Estado de retención actualizado.');
    renderizarTabla(document.getElementById('buscar').value);
  } catch (e) {
    showToast('Error al conectar.');
  }
}

function abrirModal(id = null) {
  editandoId = id;
  const form = document.getElementById('formRetencion');
  form.reset();
  const fb = document.getElementById('siglasFeedback');
  if (fb) fb.style.display = 'none';

  if (id) {
    const r = retenciones.find(x => Number(x.id) === Number(id));
    if (r) {
      document.getElementById('modalTitulo').textContent = 'Editar Retención (' + r.siglas + ')';
      document.getElementById('siglas').value = r.siglas;
      document.getElementById('nombre').value = r.nombre;
      document.getElementById('base_calculo').value = r.base_calculo;
      document.getElementById('porcentaje').value = Number(r.porcentaje).toFixed(2);
    }
  } else {
    document.getElementById('modalTitulo').textContent = 'Nueva Retención Fiscal DGII';
    document.getElementById('base_calculo').value = 'SUBTOTAL';
    document.getElementById('porcentaje').value = '10.00';
  }
  document.getElementById('modalRetencion').classList.add('open');
}

function cerrarModal(forzar = false) {
  if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
  document.getElementById('modalRetencion').classList.remove('open');
  editandoId = null;
}

async function guardarRetencion(e) {
  e.preventDefault();
  const siglas = document.getElementById('siglas').value.trim().toUpperCase();
  const nombre = document.getElementById('nombre').value.trim();
  const base = document.getElementById('base_calculo').value;
  const porc = document.getElementById('porcentaje').value.trim();

  if (!/^[A-Z0-9_-]{2,20}$/.test(siglas)) {
    showToast('Error: Las siglas deben tener un formato válido (ej. RET_ISR_10).');
    document.getElementById('siglas').focus();
    return;
  }
  if (!/^\d+(\.\d{1,2})?$/.test(porc) || parseFloat(porc) <= 0 || parseFloat(porc) > 100) {
    showToast('Error: El porcentaje debe ser un número entre 0.01% y 100.00%.');
    document.getElementById('porcentaje').focus();
    return;
  }

  const fd = new FormData();
  fd.append('action', 'guardar');
  if (editandoId) fd.append('id', editandoId);
  fd.append('siglas', siglas);
  fd.append('nombre', nombre);
  fd.append('base_calculo', base);
  fd.append('porcentaje', porc);

  try {
    const r = await fetch('procesar_retenciones.php', { method: 'POST', body: fd });
    const data = await r.json();
    if (data.status === 'success') {
      if (Array.isArray(data.retenciones)) retenciones = data.retenciones;
      cerrarModal(true);
      showToast(data.message || 'Retención guardada correctamente.');
      renderizarTabla(document.getElementById('buscar').value);
    } else {
      showToast('Error: ' + (data.message || 'No se pudo guardar.'));
    }
  } catch (err) {
    showToast('Error de conexión.');
  }
}

function showToast(msg) {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3000);
}

function escapeHtml(str) {
  return String(str || '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m]);
}

document.addEventListener('DOMContentLoaded', () => {
  renderizarTabla();
  const elNombre = document.getElementById('nombre');
  if (elNombre) {
    elNombre.addEventListener('input', function () {
      if (this.value.length > 0) {
        const pos = this.selectionStart;
        this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1);
        if (typeof pos === 'number') this.setSelectionRange(pos, pos);
      }
    });
  }
});
