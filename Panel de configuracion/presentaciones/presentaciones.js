function actualizarPrecioSugerido() {
  const sel = document.getElementById('producto_maestro_id');
  const opt = sel.options[sel.selectedIndex];
  if (!opt) return;
  const base = parseFloat(opt.getAttribute('data-precio') || 0);
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
            document.getElementById('producto_maestro_id').value = pr.producto_maestro_id;
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
  const errEl = document.getElementById('errorCodigoBarra');
  if (errEl) {
    errEl.style.display = 'none';
    errEl.textContent = '';
  }
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
