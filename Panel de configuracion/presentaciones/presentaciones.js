// ============================================================================
// SELECTOR BUSCABLE DE PRODUCTO MAESTRO (ESCALABLE A 500+ PRODUCTOS)
// ============================================================================
let PRES_PRODS_FILTRADOS = [];
let PRES_PROD_IDX_ACTIVO = -1;

function escPresHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function obtenerProductoMaestroPorId(id) {
  const lista = typeof PRODUCTOS_MAESTROS_LISTA !== 'undefined' ? PRODUCTOS_MAESTROS_LISTA : [];
  return lista.find(p => String(p.id) === String(id)) || null;
}

function filtrarProductosMaestro() {
  const input = document.getElementById('inputBuscarProductoMaestro');
  const dd = document.getElementById('dropdownProductosMaestros');
  if (!dd) return;

  const q = (input ? input.value : '').trim().toLowerCase();
  const lista = typeof PRODUCTOS_MAESTROS_LISTA !== 'undefined' ? PRODUCTOS_MAESTROS_LISTA : [];

  const coincidencias = q === ''
    ? lista
    : lista.filter(p => {
        const nom = (p.nombre || '').toLowerCase();
        const sku = (p.sku || '').toLowerCase();
        return nom.includes(q) || sku.includes(q);
      });

  const maxMostrar = 40;
  PRES_PRODS_FILTRADOS = coincidencias.slice(0, maxMostrar);
  PRES_PROD_IDX_ACTIVO = -1;

  if (PRES_PRODS_FILTRADOS.length === 0) {
    dd.innerHTML = `<div class="pres-prod-empty">No se encontraron productos para "<strong>${escPresHtml(q)}</strong>".</div>`;
    dd.style.display = 'block';
    return;
  }

  let html = PRES_PRODS_FILTRADOS.map((p, i) => {
    const precio = parseFloat(p.precio_base_unidad || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return `
      <div class="pres-prod-opt" data-idx="${i}" onmousedown="event.preventDefault(); seleccionarProductoMaestro(${Number(p.id)})">
        <div style="min-width: 0;">
          <div class="pres-prod-opt-name">${escPresHtml(p.nombre || p.sku)}</div>
          <div class="pres-prod-opt-sku">${escPresHtml(p.sku)}</div>
        </div>
        <div class="pres-prod-opt-price">RD$ ${precio}</div>
      </div>
    `;
  }).join('');

  if (coincidencias.length > maxMostrar) {
    html += `<div class="pres-prod-more">Mostrando ${maxMostrar} de ${coincidencias.length} productos. Escribe más letras para filtrar...</div>`;
  }

  dd.innerHTML = html;
  dd.style.display = 'block';
}

function teclaProductoMaestro(e) {
  const dd = document.getElementById('dropdownProductosMaestros');
  if (!dd || dd.style.display !== 'block' || PRES_PRODS_FILTRADOS.length === 0) return;

  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault();
    const max = PRES_PRODS_FILTRADOS.length - 1;
    PRES_PROD_IDX_ACTIVO = e.key === 'ArrowDown'
      ? Math.min(max, PRES_PROD_IDX_ACTIVO + 1)
      : Math.max(0, PRES_PROD_IDX_ACTIVO - 1);
    dd.querySelectorAll('.pres-prod-opt').forEach((el, i) => {
      el.classList.toggle('active', i === PRES_PROD_IDX_ACTIVO);
      if (i === PRES_PROD_IDX_ACTIVO) el.scrollIntoView({ block: 'nearest' });
    });
  } else if (e.key === 'Enter') {
    e.preventDefault();
    const elegido = PRES_PRODS_FILTRADOS[PRES_PROD_IDX_ACTIVO >= 0 ? PRES_PROD_IDX_ACTIVO : 0];
    if (elegido) seleccionarProductoMaestro(elegido.id);
  } else if (e.key === 'Escape') {
    dd.style.display = 'none';
  }
}

function seleccionarProductoMaestro(prodId) {
  const prod = obtenerProductoMaestroPorId(prodId);
  const hid = document.getElementById('producto_maestro_id');
  const pill = document.getElementById('presProdPill');
  const pillName = document.getElementById('presProdPillName');
  const pillSub = document.getElementById('presProdPillSub');
  const wrap = document.getElementById('presProdInputWrap');
  const dd = document.getElementById('dropdownProductosMaestros');
  const errProd = document.getElementById('errorProductoMaestro');

  if (!prod) {
    limpiarProductoMaestro(false);
    return;
  }

  if (hid) hid.value = String(prod.id);
  if (pillName) pillName.textContent = prod.nombre || prod.sku;
  if (pillSub) {
    const baseFmt = parseFloat(prod.precio_base_unidad || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    pillSub.textContent = `${prod.sku} • Base: RD$ ${baseFmt}`;
  }
  if (pill) pill.style.display = 'flex';
  if (wrap) wrap.style.display = 'none';
  if (dd) dd.style.display = 'none';
  if (errProd) { errProd.style.display = 'none'; errProd.textContent = ''; }

  actualizarPrecioSugerido();
}

function limpiarProductoMaestro(enfocar = false) {
  const hid = document.getElementById('producto_maestro_id');
  const pill = document.getElementById('presProdPill');
  const wrap = document.getElementById('presProdInputWrap');
  const inp = document.getElementById('inputBuscarProductoMaestro');
  const dd = document.getElementById('dropdownProductosMaestros');

  if (hid) hid.value = '';
  if (pill) pill.style.display = 'none';
  if (wrap) wrap.style.display = 'block';
  if (inp) inp.value = '';
  if (dd) dd.style.display = 'none';

  if (enfocar && inp) {
    inp.focus();
    filtrarProductosMaestro();
  }
}

document.addEventListener('click', (e) => {
  const picker = document.getElementById('presProdPicker');
  const dd = document.getElementById('dropdownProductosMaestros');
  if (picker && dd && !picker.contains(e.target)) {
    dd.style.display = 'none';
  }
});

function actualizarPrecioSugerido() {
  const prodId = document.getElementById('producto_maestro_id')?.value;
  const prod = obtenerProductoMaestroPorId(prodId);
  if (!prod) return;
  const base = parseFloat(prod.precio_base_unidad || 0);
  const factor = parseInt(document.getElementById('factor_multiplicador').value || 1, 10);
  if (document.getElementById('formAccion').value === 'crear') {
    document.getElementById('precio_venta_presentacion').value = (base * (isNaN(factor) || factor < 1 ? 1 : factor)).toFixed(2);
  }
}

let timerCodigoBarra = null;
function validarInputCodigoBarra(input) {
  const limpio = (input.value || '').replace(/\D/g, '').slice(0, 14);
  if (input.value !== limpio) {
    input.value = limpio;
  }
  const errEl = document.getElementById('errorCodigoBarra');
  if (!errEl) return;

  if (limpio.length === 0) {
    errEl.style.display = 'none';
    errEl.textContent = '';
    return;
  }

  if (limpio.length < 8) {
    errEl.style.color = 'var(--accent-danger)';
    errEl.textContent = `Faltan ${8 - limpio.length} dígito(s) (mínimo 8 dígitos numéricos).`;
    errEl.style.display = 'block';
    return;
  }

  if (/^0+$/.test(limpio)) {
    errEl.style.color = 'var(--accent-danger)';
    errEl.textContent = 'El código de barra no puede contener solo ceros.';
    errEl.style.display = 'block';
    return;
  }

  errEl.style.display = 'none';
  errEl.textContent = '';

  clearTimeout(timerCodigoBarra);
  timerCodigoBarra = setTimeout(() => {
    fetch('procesar_presentaciones.php?codigo_barra=' + encodeURIComponent(limpio))
      .then(r => r.json())
      .then(res => {
        if (res.encontrado && res.presentacion) {
          const pr = res.presentacion;
          const idActual = document.getElementById('presentacion_id').value;
          if (!idActual || String(idActual) !== String(pr.id)) {
            document.getElementById('formAccion').value = 'editar';
            document.getElementById('presentacion_id').value = pr.id;
            seleccionarProductoMaestro(pr.producto_maestro_id);
            document.getElementById('descripcion_presentacion').value = pr.descripcion_presentacion;
            document.getElementById('factor_multiplicador').value = pr.factor_multiplicador;
            document.getElementById('precio_venta_presentacion').value = parseFloat(pr.precio_venta_presentacion || 0).toFixed(2);
            document.getElementById('modalTitulo').textContent = 'Editar Presentación (' + pr.codigo_barra + ')';
            errEl.style.color = 'var(--accent-gold)';
            errEl.textContent = `✓ Código de barra existente localizado (${pr.descripcion_presentacion}). Datos cargados.`;
            errEl.style.display = 'block';
            mostrarToast('✓ Código de barra localizado en la base de datos.');
          }
        }
      })
      .catch(() => {});
  }, 280);
}

function validarFormularioPresentacion(e) {
  const prodId = parseInt(document.getElementById('producto_maestro_id')?.value || '0', 10);
  const errProd = document.getElementById('errorProductoMaestro');
  if (!prodId || prodId <= 0) {
    e.preventDefault();
    if (errProd) {
      errProd.textContent = 'Debes buscar y seleccionar un Producto Maestro.';
      errProd.style.display = 'block';
    }
    mostrarToast('Error: Selecciona un Producto Maestro de la lista.');
    document.getElementById('inputBuscarProductoMaestro')?.focus();
    return false;
  }

  const cbInput = document.getElementById('codigo_barra');
  const cb = (cbInput ? cbInput.value : '').replace(/\D/g, '').trim();
  const factor = parseInt(document.getElementById('factor_multiplicador').value || '0', 10);
  const precio = parseFloat(document.getElementById('precio_venta_presentacion').value || '0');
  const errEl = document.getElementById('errorCodigoBarra');

  if (!/^\d{8,14}$/.test(cb) || /^0+$/.test(cb)) {
    e.preventDefault();
    if (errEl) {
      errEl.style.color = 'var(--accent-danger)';
      errEl.textContent = 'El código de barra debe tener entre 8 y 14 dígitos numéricos válidos.';
      errEl.style.display = 'block';
    }
    mostrarToast('Error: Ingresa un código de barra numérico válido (8 a 14 dígitos).');
    if (cbInput) cbInput.focus();
    return false;
  }

  if (isNaN(factor) || factor < 1) {
    e.preventDefault();
    mostrarToast('Error: El factor multiplicador debe ser un número entero mayor o igual a 1.');
    return false;
  }

  if (isNaN(precio) || precio < 0) {
    e.preventDefault();
    mostrarToast('Error: Ingresa un precio de venta válido.');
    return false;
  }

  return true;
}

function abrirModal() {
  document.getElementById('formAccion').value = 'crear';
  document.getElementById('modalTitulo').textContent = 'Nueva Presentación';
  document.getElementById('presentacion_id').value = '';
  document.getElementById('codigo_barra').value = '';
  document.getElementById('descripcion_presentacion').value = '';
  document.getElementById('factor_multiplicador').value = '1';
  document.getElementById('precio_venta_presentacion').value = '0.00';
  limpiarProductoMaestro(false);
  const errEl = document.getElementById('errorCodigoBarra');
  if (errEl) {
    errEl.style.display = 'none';
    errEl.textContent = '';
  }
  const errProd = document.getElementById('errorProductoMaestro');
  if (errProd) {
    errProd.style.display = 'none';
    errProd.textContent = '';
  }
  document.getElementById('modalPresentacion').classList.add('open');
  setTimeout(() => document.getElementById('inputBuscarProductoMaestro')?.focus(), 60);
}

function editarPresentacion(pres) {
  document.getElementById('formAccion').value = 'editar';
  document.getElementById('modalTitulo').textContent = 'Editar Presentación (' + pres.codigo_barra + ')';
  document.getElementById('presentacion_id').value = pres.id;
  seleccionarProductoMaestro(pres.producto_maestro_id);
  document.getElementById('codigo_barra').value = pres.codigo_barra;
  document.getElementById('descripcion_presentacion').value = pres.descripcion_presentacion;
  document.getElementById('factor_multiplicador').value = pres.factor_multiplicador;
  document.getElementById('precio_venta_presentacion').value = parseFloat(pres.precio_venta_presentacion).toFixed(2);
  const errEl = document.getElementById('errorCodigoBarra');
  if (errEl) {
    errEl.style.display = 'none';
    errEl.textContent = '';
  }
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

  const elDesc = document.getElementById('descripcion_presentacion');
  if (elDesc) {
    elDesc.addEventListener('input', function () {
      if (this.value.length > 0) {
        const pos = this.selectionStart;
        this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1);
        if (typeof pos === 'number') this.setSelectionRange(pos, pos);
      }
    });
  }
})();
