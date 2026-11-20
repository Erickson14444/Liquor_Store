const _datosMonedas = document.getElementById('datosMonedas');
let monedas = _datosMonedas ? JSON.parse(_datosMonedas.dataset.monedas || '[]') : [];
let editandoId = null;

function soloLetrasIso(e) {
  const k = e.key;
  if (['Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(k) || e.ctrlKey || e.metaKey) {
    return true;
  }
  if (!/^[a-zA-Z]$/.test(k)) {
    e.preventDefault();
    return false;
  }
  return true;
}

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

function limpiarDecimalCuatro(input) {
  let val = (input.value || '').replace(/[^0-9.]/g, '');
  const partes = val.split('.');
  if (partes.length > 2) {
    val = partes[0] + '.' + partes.slice(1).join('');
  }
  const p2 = val.split('.');
  if (p2.length === 2 && p2[1].length > 4) {
    val = p2[0] + '.' + p2[1].slice(0, 4);
  }
  if (input.value !== val) input.value = val;
}

function alCambiarMonedaBase() {
  const chk = document.getElementById('es_moneda_base');
  const tasaInput = document.getElementById('tasa_cambio_referencia');
  if (chk && chk.checked) {
    tasaInput.value = '1.0000';
    tasaInput.readOnly = true;
  } else if (tasaInput) {
    tasaInput.readOnly = false;
  }
}

function autocompletarPorIso(isoVal) {
  const iso = (isoVal || '').trim().toUpperCase();
  const fb = document.getElementById('isoFeedback');
  if (iso.length < 3) {
    if (fb) fb.style.display = 'none';
    return;
  }
  const existente = monedas.find(m => String(m.codigo_iso).toUpperCase() === iso);
  if (existente && Number(existente.id) !== Number(editandoId)) {
    editandoId = Number(existente.id);
    document.getElementById('modalTitulo').textContent = 'Editar Moneda (' + existente.codigo_iso + ')';
    document.getElementById('nombre').value = existente.nombre;
    document.getElementById('simbolo').value = existente.simbolo;
    document.getElementById('tasa_cambio_referencia').value = Number(existente.tasa_cambio_referencia).toFixed(4);
    const esBase = (existente.es_moneda_base === true || existente.es_moneda_base === 't' || existente.es_moneda_base === 1);
    document.getElementById('es_moneda_base').checked = esBase;
    alCambiarMonedaBase();
    if (fb) {
      fb.textContent = `✓ Código ISO existente (${existente.nombre}). Datos cargados.`;
      fb.style.display = 'block';
    }
    showToast(`✓ Moneda ${existente.codigo_iso} cargada.`);
  } else if (fb) {
    fb.style.display = 'none';
  }
}

function renderizarTabla(filtro = '') {
  const tbody = document.getElementById('tablaBody');
  tbody.innerHTML = '';
  const q = filtro.trim().toLowerCase();
  const filtradas = monedas.filter(m =>
    String(m.codigo_iso).toLowerCase().includes(q) ||
    String(m.nombre).toLowerCase().includes(q) ||
    String(m.simbolo).toLowerCase().includes(q)
  );

  if (filtradas.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 28px; color: var(--text-muted);">No hay monedas registradas.</td></tr>';
    return;
  }

  filtradas.forEach(m => {
    const tr = document.createElement('tr');
    const isActiva = (m.activa === true || m.activa === 't' || m.activa === 1);
    const isBase = (m.es_moneda_base === true || m.es_moneda_base === 't' || m.es_moneda_base === 1);

    tr.innerHTML = `
      <td><span class="code-inline" style="font-weight: 700; color: #a66e2e;">${escapeHtml(m.codigo_iso)}</span></td>
      <td><strong>${escapeHtml(m.nombre)}</strong></td>
      <td class="text-center"><span class="badge" style="background: #eadecd; color: #5a3818; font-family: monospace; font-weight: 700;">${escapeHtml(m.simbolo)}</span></td>
      <td class="text-right" style="font-family: monospace; font-weight: 700; color: #211813;">RD$ ${Number(m.tasa_cambio_referencia).toFixed(4)}</td>
      <td class="text-center">
        ${isBase
          ? '<span class="badge" style="background: #fdf3e2; color: #9c621c; border: 1px solid #e7cca4; font-weight: 700;">⭐ Moneda Base</span>'
          : '<span style="color: #888; font-size: 0.82rem;">Divisa Extranjera</span>'}
      </td>
      <td class="text-center">
        <label class="toggle-wrap">
          <input type="checkbox" ${isActiva ? 'checked' : ''} ${isBase ? 'disabled' : ''} onchange="toggleEstado(${m.id}, this.checked, this)" />
          <span class="toggle-slider"></span>
          <span class="toggle-text">${isActiva ? 'Activa' : 'Inactiva'}</span>
        </label>
      </td>
      <td class="text-right">
        <button type="button" class="link-edit" onclick="abrirModal(${m.id})">Editar</button>
      </td>
    `;
    tbody.appendChild(tr);
  });

  document.getElementById('badgeCount').textContent = `${monedas.length} monedas`;
  document.getElementById('footerInfo').textContent = `Mostrando ${filtradas.length} de ${monedas.length} divisas`;
}

async function toggleEstado(id, checked, chkEl) {
  const fd = new FormData();
  fd.append('action', 'toggle_estado');
  fd.append('id', id);
  fd.append('activa', checked);
  try {
    const r = await fetch('procesar_monedas.php', { method: 'POST', body: fd });
    const data = await r.json();
    if (data.status === 'success') {
      const m = monedas.find(i => Number(i.id) === Number(id));
      if (m) m.activa = checked;
      showToast('Estado de moneda actualizado.');
      renderizarTabla(document.getElementById('buscar').value);
    } else {
      if (chkEl) chkEl.checked = !checked;
      showToast('Error: ' + (data.message || 'No se pudo cambiar el estado.'));
    }
  } catch (e) {
    if (chkEl) chkEl.checked = !checked;
    showToast('Error de conexión.');
  }
}

function abrirModal(id = null) {
  editandoId = id;
  const form = document.getElementById('formMoneda');
  form.reset();
  const fb = document.getElementById('isoFeedback');
  if (fb) fb.style.display = 'none';

  if (id) {
    const m = monedas.find(i => Number(i.id) === Number(id));
    if (m) {
      document.getElementById('modalTitulo').textContent = 'Editar Moneda (' + m.codigo_iso + ')';
      document.getElementById('codigo_iso').value = m.codigo_iso;
      document.getElementById('nombre').value = m.nombre;
      document.getElementById('simbolo').value = m.simbolo;
      document.getElementById('tasa_cambio_referencia').value = Number(m.tasa_cambio_referencia).toFixed(4);
      const isBase = (m.es_moneda_base === true || m.es_moneda_base === 't' || m.es_moneda_base === 1);
      document.getElementById('es_moneda_base').checked = isBase;
      alCambiarMonedaBase();
    }
  } else {
    document.getElementById('modalTitulo').textContent = 'Nueva Moneda';
    document.getElementById('tasa_cambio_referencia').value = '1.0000';
    document.getElementById('es_moneda_base').checked = false;
    alCambiarMonedaBase();
  }
  document.getElementById('modalMoneda').classList.add('open');
}

function cerrarModal(forzar = false) {
  if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
  document.getElementById('modalMoneda').classList.remove('open');
  editandoId = null;
}

async function guardarMoneda(e) {
  e.preventDefault();
  const iso = document.getElementById('codigo_iso').value.trim().toUpperCase();
  const nombre = document.getElementById('nombre').value.trim();
  const simbolo = document.getElementById('simbolo').value.trim();
  const tasa = document.getElementById('tasa_cambio_referencia').value.trim();
  const esBase = document.getElementById('es_moneda_base').checked;

  if (!/^[A-Z]{3}$/.test(iso)) {
    showToast('Error: El código ISO debe tener exactamente 3 letras (ej. DOP, USD, EUR).');
    document.getElementById('codigo_iso').focus();
    return;
  }
  if (!/^\d+(\.\d{1,4})?$/.test(tasa) || parseFloat(tasa) <= 0) {
    showToast('Error: La tasa de cambio debe ser un número positivo mayor a 0.');
    document.getElementById('tasa_cambio_referencia').focus();
    return;
  }

  const fd = new FormData();
  fd.append('action', 'guardar');
  if (editandoId) fd.append('id', editandoId);
  fd.append('codigo_iso', iso);
  fd.append('nombre', nombre);
  fd.append('simbolo', simbolo);
  fd.append('tasa_cambio_referencia', esBase ? '1.0000' : tasa);
  fd.append('es_moneda_base', esBase ? 'true' : 'false');

  try {
    const r = await fetch('procesar_monedas.php', { method: 'POST', body: fd });
    const data = await r.json();
    if (data.status === 'success') {
      if (Array.isArray(data.monedas)) {
        monedas = data.monedas;
      }
      cerrarModal(true);
      showToast(data.message || 'Moneda guardada correctamente.');
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
