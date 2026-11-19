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
  if (p2.length === 2 && p2[1].length > 2) {
    val = p2[0] + '.' + p2[1].slice(0, 2);
  }
  if (input.value !== val) {
    input.value = val;
  }
}

function marcarImpuestosProducto(ids = []) {
  const setIds = new Set((Array.isArray(ids) ? ids : []).map(x => Number(x)));
  const chks = document.querySelectorAll('.chk-impuesto-prod');
  chks.forEach(chk => {
    chk.checked = setIds.has(Number(chk.value));
  });
  if (setIds.size === 0 && chks.length > 0) {
    // Por defecto marcar ITBIS_18 (o el primer impuesto activo)
    const def = Array.from(chks).find(c => c.dataset.siglas === 'ITBIS_18') || chks[0];
    if (def) def.checked = true;
  }
}

function alCambiarTributoProducto(chkActual) {
  if (!chkActual || !chkActual.checked) return;
  const sigla = (chkActual.dataset.siglas || '').toUpperCase();
  const chks = document.querySelectorAll('.chk-impuesto-prod');

  if (sigla === 'EXENTO') {
    // Si marca EXENTO, desmarcar todos los demás tributos gravados
    chks.forEach(c => {
      if (c !== chkActual) c.checked = false;
    });
  } else {
    // Si marca cualquier tributo gravado, desmarcar EXENTO
    chks.forEach(c => {
      const s = (c.dataset.siglas || '').toUpperCase();
      if (s === 'EXENTO') c.checked = false;
      // ITBIS_18 e ITBIS_16 son mutuamente excluyentes entre sí (pero compatibles con ISC)
      if (sigla.startsWith('ITBIS_') && s.startsWith('ITBIS_') && c !== chkActual) {
        c.checked = false;
      }
    });
  }
}

function validarFormularioProducto(e) {
  const sku = (document.getElementById('sku').value || '').trim().toUpperCase();
  const costo = (document.getElementById('costo_promedio').value || '').trim();
  const precio = (document.getElementById('precio_base_unidad').value || '').trim();
  const seleccionados = document.querySelectorAll('.chk-impuesto-prod:checked').length;

  if (!/^[A-Z0-9_-]{3,30}$/.test(sku)) {
    e.preventDefault();
    mostrarToast('Error: El SKU debe tener formato válido (ej. PRO-001 o RON-BRU-700).');
    document.getElementById('sku').focus();
    return false;
  }

  if (!/^\d+(\.\d{1,2})?$/.test(costo) || !/^\d+(\.\d{1,2})?$/.test(precio)) {
    e.preventDefault();
    mostrarToast('Error: El costo promedio y el precio base deben ser números válidos.');
    return false;
  }

  if (seleccionados === 0) {
    e.preventDefault();
    mostrarToast('Error: Selecciona al menos un tributo o marca Exento.');
    return false;
  }

  return true;
}

function abrirModal() {
  document.getElementById('formAccion').value = 'crear';
  document.getElementById('modalTitulo').textContent = 'Nuevo Producto Maestro';
  document.getElementById('producto_id').value = '';
  document.getElementById('sku').value = '';
  document.getElementById('nombre').value = '';
  document.getElementById('costo_promedio').value = '0.00';
  document.getElementById('precio_base_unidad').value = '0.00';
  marcarImpuestosProducto([1]);
  document.getElementById('requiere_lote').checked = true;
  const fb = document.getElementById('skuFeedback');
  if (fb) fb.style.display = 'none';
  document.getElementById('modalProducto').classList.add('open');
}

function editarProducto(p) {
  document.getElementById('formAccion').value = 'editar';
  document.getElementById('modalTitulo').textContent = 'Editar Producto (' + p.sku + ')';
  document.getElementById('producto_id').value = p.id;
  document.getElementById('sku').value = p.sku;
  document.getElementById('nombre').value = p.descripcion || p.nombre || '';
  document.getElementById('categoria_id').value = p.categoria_id;
  document.getElementById('marca_id').value = p.marca_id;
  document.getElementById('costo_promedio').value = parseFloat(p.costo_promedio || 0).toFixed(2);
  document.getElementById('precio_base_unidad').value = parseFloat(p.precio_base_unidad || 0).toFixed(2);
  marcarImpuestosProducto(Array.isArray(p.impuestos_ids) ? p.impuestos_ids : [1]);
  document.getElementById('requiere_lote').checked = (p.requiere_lote === true || p.requiere_lote === 't');
  const fb = document.getElementById('skuFeedback');
  if (fb) fb.style.display = 'none';
  document.getElementById('modalProducto').classList.add('open');
}

let timerSku = null;
function autocompletarProductoPorSku(skuValor) {
  clearTimeout(timerSku);
  const sku = (skuValor || '').trim();
  const fb = document.getElementById('skuFeedback');
  if (sku.length < 3) {
    if (fb) fb.style.display = 'none';
    return;
  }

  timerSku = setTimeout(() => {
    fetch('procesar_productos.php?sku=' + encodeURIComponent(sku))
      .then(r => r.json())
      .then(res => {
        if (res.encontrado && res.producto) {
          const p = res.producto;
          document.getElementById('formAccion').value = 'editar';
          document.getElementById('producto_id').value = p.id;
          document.getElementById('nombre').value = p.descripcion || '';
          document.getElementById('categoria_id').value = p.categoria_id;
          document.getElementById('marca_id').value = p.marca_id;
          document.getElementById('costo_promedio').value = parseFloat(p.costo_promedio || 0).toFixed(2);
          document.getElementById('precio_base_unidad').value = parseFloat(p.precio_base_unidad || 0).toFixed(2);
          marcarImpuestosProducto(Array.isArray(p.impuestos_ids) ? p.impuestos_ids : [1]);
          document.getElementById('requiere_lote').checked = (p.requiere_lote === true || p.requiere_lote === 't');
          if (fb) {
            fb.textContent = `✓ Código existente localizado (${p.descripcion}). Datos rellenados automáticamente.`;
            fb.style.display = 'block';
          }
          mostrarToast(`✓ Producto ${p.sku} localizado en la base de datos.`);
        } else {
          if (fb) fb.style.display = 'none';
        }
      })
      .catch(() => {});
  }, 300);
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
  if (!t) return;
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3000);
}

(() => {
  const _urlMsg = new URLSearchParams(window.location.search).get('msg');
  if (_urlMsg && typeof mostrarToast === 'function') mostrarToast(_urlMsg);

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
})();
