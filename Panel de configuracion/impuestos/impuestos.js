const _datosImpuestos = document.getElementById('datosImpuestos');
let impuestos = _datosImpuestos ? JSON.parse(_datosImpuestos.dataset.impuestos || '[]') : [];
let editandoId = null;

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

function limpiarDecimal(input) {
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

function actualizarLabelValor() {
  const tipo = document.getElementById('tipo_calculo').value;
  const lbl = document.getElementById('lblValorImpuesto');
  if (lbl) {
    lbl.textContent = (tipo === 'MONTO_FIJO') ? 'MONTO FIJO (RD$) *' : 'VALOR / PORCENTAJE (%) *';
  }
}

function autocompletarPorSiglas(val) {
  const sig = (val || '').trim().toUpperCase();
  const fb = document.getElementById('siglasFeedback');
  if (sig.length < 3) {
    if (fb) fb.style.display = 'none';
    return;
  }
  const ex = impuestos.find(i => String(i.siglas).toUpperCase() === sig);
  if (ex && Number(ex.id) !== Number(editandoId)) {
    editandoId = Number(ex.id);
    document.getElementById('modalTitulo').textContent = 'Editar Impuesto (' + ex.siglas + ')';
    document.getElementById('nombre').value = ex.nombre;
    document.getElementById('tipo_calculo').value = ex.tipo_calculo;
    document.getElementById('tasa_o_monto').value = Number(ex.tasa_o_monto).toFixed(2);
    document.getElementById('orden_calculo').value = ex.orden_calculo;
    actualizarLabelValor();
    if (fb) {
      fb.textContent = `✓ Impuesto existente localizado (${ex.nombre}). Datos cargados.`;
      fb.style.display = 'block';
    }
    showToast(`✓ Impuesto ${ex.siglas} cargado.`);
  } else if (fb) {
    fb.style.display = 'none';
  }
}

function renderizarTabla(filtro = '') {
  const tbody = document.getElementById('tablaBody');
  tbody.innerHTML = '';
  const q = filtro.trim().toLowerCase();
  const filtrados = impuestos.filter(i =>
    String(i.siglas).toLowerCase().includes(q) ||
    String(i.nombre).toLowerCase().includes(q)
  );

  if (filtrados.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 28px; color: var(--text-muted);">No hay impuestos configurados.</td></tr>';
    return;
  }

  filtrados.forEach(i => {
    const tr = document.createElement('tr');
    const isActivo = (i.activo === true || i.activo === 't' || i.activo === 1);
    const valorFmt = (i.tipo_calculo === 'MONTO_FIJO')
      ? 'RD$ ' + Number(i.tasa_o_monto).toFixed(2)
      : Number(i.tasa_o_monto).toFixed(2) + '%';

    tr.innerHTML = `
      <td><span class="code-inline" style="font-weight: 700; color: #a66e2e;">${escapeHtml(i.siglas)}</span></td>
      <td><strong>${escapeHtml(i.nombre)}</strong></td>
      <td class="text-center"><span class="badge badge-neutral">${escapeHtml(i.tipo_calculo)}</span></td>
      <td class="text-right" style="font-family: monospace; font-weight: 700; color: #211813;">${valorFmt}</td>
      <td class="text-center"><span class="badge" style="background: #eadecd; color: #5a3818; font-weight: 700;">Orden #${parseInt(i.orden_calculo, 10)}</span></td>
      <td class="text-center">
        <label class="toggle-wrap">
          <input type="checkbox" ${isActivo ? 'checked' : ''} onchange="toggleEstado(${i.id}, this.checked)" />
          <span class="toggle-slider"></span>
          <span class="toggle-text">${isActivo ? 'Activo' : 'Inactivo'}</span>
        </label>
      </td>
      <td class="text-right">
        <button type="button" class="link-edit" onclick="abrirModal(${i.id})">Editar</button>
      </td>
    `;
    tbody.appendChild(tr);
  });

  document.getElementById('badgeCount').textContent = `${impuestos.length} impuestos`;
  document.getElementById('footerInfo').textContent = `Mostrando ${filtrados.length} de ${impuestos.length} tributos`;
}

async function toggleEstado(id, checked) {
  const fd = new FormData();
  fd.append('action', 'toggle_estado');
  fd.append('id', id);
  fd.append('activo', checked);
  try {
    await fetch('procesar_impuestos.php', { method: 'POST', body: fd });
    const item = impuestos.find(i => Number(i.id) === Number(id));
    if (item) item.activo = checked;
    showToast('Estado del tributo actualizado.');
    renderizarTabla(document.getElementById('buscar').value);
  } catch (e) {
    showToast('Error al conectar.');
  }
}

function abrirModal(id = null) {
  editandoId = id;
  const form = document.getElementById('formImpuesto');
  form.reset();
  const fb = document.getElementById('siglasFeedback');
  if (fb) fb.style.display = 'none';

  if (id) {
    const i = impuestos.find(x => Number(x.id) === Number(id));
    if (i) {
      document.getElementById('modalTitulo').textContent = 'Editar Impuesto (' + i.siglas + ')';
      document.getElementById('siglas').value = i.siglas;
      document.getElementById('nombre').value = i.nombre;
      document.getElementById('tipo_calculo').value = i.tipo_calculo;
      document.getElementById('tasa_o_monto').value = Number(i.tasa_o_monto).toFixed(2);
      document.getElementById('orden_calculo').value = i.orden_calculo;
      actualizarLabelValor();
    }
  } else {
    document.getElementById('modalTitulo').textContent = 'Nuevo Impuesto Fiscal';
    document.getElementById('tipo_calculo').value = 'PORCENTAJE';
    document.getElementById('tasa_o_monto').value = '18.00';
    document.getElementById('orden_calculo').value = '1';
    actualizarLabelValor();
  }
  document.getElementById('modalImpuesto').classList.add('open');
}

function cerrarModal(forzar = false) {
  if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
  document.getElementById('modalImpuesto').classList.remove('open');
  editandoId = null;
}

async function guardarImpuesto(e) {
  e.preventDefault();
  const siglas = document.getElementById('siglas').value.trim().toUpperCase();
  const nombre = document.getElementById('nombre').value.trim();
  const tipo = document.getElementById('tipo_calculo').value;
  const valor = document.getElementById('tasa_o_monto').value.trim();
  const orden = document.getElementById('orden_calculo').value.trim();

  if (!/^[A-Z0-9_-]{2,20}$/.test(siglas)) {
    showToast('Error: Las siglas deben tener un formato válido (ej. ITBIS_18).');
    document.getElementById('siglas').focus();
    return;
  }
  if (!/^\d+(\.\d{1,4})?$/.test(valor) || parseFloat(valor) < 0) {
    showToast('Error: Ingresa una tasa o monto numérico válido.');
    document.getElementById('tasa_o_monto').focus();
    return;
  }
  if (tipo === 'PORCENTAJE' && parseFloat(valor) > 100) {
    showToast('Error: El porcentaje no puede superar el 100%.');
    document.getElementById('tasa_o_monto').focus();
    return;
  }
  if (!/^\d+$/.test(orden) || parseInt(orden, 10) < 1) {
    showToast('Error: El orden de cálculo debe ser un número entero mayor o igual a 1.');
    document.getElementById('orden_calculo').focus();
    return;
  }

  const fd = new FormData();
  fd.append('action', 'guardar');
  if (editandoId) fd.append('id', editandoId);
  fd.append('siglas', siglas);
  fd.append('nombre', nombre);
  fd.append('tipo_calculo', tipo);
  fd.append('tasa_o_monto', valor);
  fd.append('orden_calculo', orden);

  try {
    const r = await fetch('procesar_impuestos.php', { method: 'POST', body: fd });
    const data = await r.json();
    if (data.status === 'success') {
      if (Array.isArray(data.impuestos)) impuestos = data.impuestos;
      cerrarModal(true);
      showToast(data.message || 'Impuesto guardado correctamente.');
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
