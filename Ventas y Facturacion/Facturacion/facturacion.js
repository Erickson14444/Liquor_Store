/**
 * ============================================================================
 * Controlador Frontend • Proceso de Facturación (POS) + Split Universal + Caja
 * Ubicación: Ventas y Facturacion/Facturacion/facturacion.js
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

let carritoRaw = []; // [{producto_maestro_id, codigo_barra_id, cantidad}]
let ultimoCalculo = null;
let cuponCodigoActivo = '';
let cotizacionOrigenId = null;
let pagosSplit = [];
let creditoB04Verificado = null;
let timerCatPos = null;
let timerCliPos = null;
let resultadosClientesPos = [];

const CLIENTE_CONSUMIDOR_FINAL = {
  cliente_id: 'CONSUMIDOR_FINAL',
  codigo_cliente: 'CLI-000',
  nombre_razon_social: 'Consumidor Final',
  tipo_documento: 'CEDULA',
  documento_identidad: '',
  permite_credito: false,
  limite_credito: 0
};

let clienteSeleccionadoPos = { ...CLIENTE_CONSUMIDOR_FINAL };

let pedidoDeliveryConfig = {
  activo: false,
  zona_id: '',
  zona_nombre: '',
  zona_tarifa: 0,
  repartidor_id: '',
  repartidor_nombre: '',
  direccion_destino: ''
};

const DENOMINACIONES_DOP_POS = [
  { valor: 2000, tipo: 'Billete' },
  { valor: 1000, tipo: 'Billete' },
  { valor: 500, tipo: 'Billete' },
  { valor: 200, tipo: 'Billete' },
  { valor: 100, tipo: 'Billete' },
  { valor: 50, tipo: 'Billete' },
  { valor: 25, tipo: 'Moneda' },
  { valor: 10, tipo: 'Moneda' },
  { valor: 5, tipo: 'Moneda' },
  { valor: 1, tipo: 'Moneda' }
];

document.addEventListener('DOMContentLoaded', () => {
  actualizarInfoNcf();
  alCambiarCondicionVenta();
  renderizarCarritoDesdeCalculo(null);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      const dd = document.getElementById('posClienteDropdown');
      if (dd && dd.style.display === 'block') {
        cerrarBuscadorClientePos();
        return;
      }
      document.querySelectorAll('.modal-overlay.open, .modal-overlay.active').forEach((m) => {
        m.classList.remove('open', 'active');
      });
    }
  });

  document.addEventListener('click', (e) => {
    const wrap = document.getElementById('posClientPickerWrap');
    const dd = document.getElementById('posClienteDropdown');
    if (wrap && dd && dd.style.display === 'block' && !wrap.contains(e.target)) {
      cerrarBuscadorClientePos();
    }
  });

  const cotParam = new URLSearchParams(window.location.search).get('cotizacion_id');
  if (cotParam && parseInt(cotParam, 10) > 0) {
    cargarCotizacionEnCarrito(parseInt(cotParam, 10));
  }
});

/* ==========================================================================
   UTILIDADES GENERALES
   ========================================================================== */
function mostrarToast(mensaje, tipo = 'exito') {
  const toast = document.getElementById('toast');
  if (!toast) return;
  toast.textContent = mensaje;
  toast.className = 'toast show ' + (tipo === 'error' ? 'toast-error' : 'toast-success');
  clearTimeout(window._toastTimerPos);
  window._toastTimerPos = setTimeout(() => toast.classList.remove('show'), 4000);
}

function obtenerSimboloMoneda() {
  const sel = document.getElementById('selectMoneda');
  const opt = sel ? sel.options[sel.selectedIndex] : null;
  return opt?.getAttribute('data-simbolo') || 'RD$';
}

function formatearMoneda(valor) {
  const num = Number(valor) || 0;
  return obtenerSimboloMoneda() + ' ' + num.toLocaleString('es-DO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

function escaparHtml(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function abrirModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.add('open', 'active');
}

function cerrarModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('open', 'active');
}

/* ==========================================================================
   BUSCADOR EN VIVO DE CLIENTES PARA POS (ESCALABLE A CIENTOS/MILES)
   ========================================================================== */
function abrirBuscadorClientePos() {
  const pill = document.getElementById('posClientePill');
  const inp = document.getElementById('inputBuscarClientePos');
  if (pill) pill.style.display = 'none';
  if (inp) {
    inp.style.display = '';
    inp.value = '';
    inp.focus();
  }
  buscarClientesPos();
}

function cerrarBuscadorClientePos() {
  const pill = document.getElementById('posClientePill');
  const inp = document.getElementById('inputBuscarClientePos');
  const dd = document.getElementById('posClienteDropdown');
  if (dd) dd.style.display = 'none';
  if (inp) inp.style.display = 'none';
  if (pill) pill.style.display = 'flex';
}

function programarBusquedaClientesPos() {
  clearTimeout(timerCliPos);
  timerCliPos = setTimeout(buscarClientesPos, 180);
}

async function buscarClientesPos() {
  const inp = document.getElementById('inputBuscarClientePos');
  const dd = document.getElementById('posClienteDropdown');
  if (!inp || !dd) return;

  const q = inp.value.trim();
  dd.style.display = 'block';
  dd.innerHTML = '<div style="padding:10px 12px; color:#888; font-size:0.82rem;">Buscando clientes...</div>';

  try {
    const res = await fetch(`procesar_facturacion.php?accion=buscar_clientes&q=${encodeURIComponent(q)}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();
    const listaDb = Array.isArray(data.clientes) ? data.clientes : [];

    // Incluir Consumidor Final primero si la búsqueda está vacía o coincide con su texto
    const incluirCf = (!q || 'consumidor final mostrador'.includes(q.toLowerCase()));
    resultadosClientesPos = incluirCf ? [{ ...CLIENTE_CONSUMIDOR_FINAL }, ...listaDb] : listaDb;

    if (resultadosClientesPos.length === 0) {
      dd.innerHTML = '<div style="padding:10px 12px; color:#888; font-size:0.82rem;">Sin coincidencias. Usa <strong>+ Cliente</strong> para registrarlo.</div>';
      return;
    }

    dd.innerHTML = resultadosClientesPos.map((cli, idx) => {
      if (cli.cliente_id === 'CONSUMIDOR_FINAL') {
        return `
          <div class="pos-client-opt" onmousedown="seleccionarClientePosPorIndice(${idx})" style="background:#faf7f2;">
            <div style="font-weight:700; color:#5a3818;">👤 Consumidor Final (Mostrador)</div>
            <div style="font-size:0.74rem; color:#8a7b70;">Venta rápida sin RNC (Comprobante B02)</div>
          </div>
        `;
      }
      const credBadge = cli.permite_credito
        ? `<span style="font-size:0.68rem; background:#e8f5e9; color:#2e7d32; padding:1px 6px; border-radius:4px; font-weight:700; margin-left:4px;">★ Crédito</span>`
        : '';
      return `
        <div class="pos-client-opt" onmousedown="seleccionarClientePosPorIndice(${idx})">
          <div style="font-weight:700; color:#211813;">${escaparHtml(cli.nombre_razon_social)}${credBadge}</div>
          <div style="font-size:0.74rem; color:#7a6b5d; font-family:monospace;">
            ${escaparHtml(cli.codigo_cliente || '')} • ${escaparHtml(cli.tipo_documento)}: ${escaparHtml(cli.documento_identidad)}
            ${cli.telefono_principal ? ' • ' + escaparHtml(cli.telefono_principal) : ''}
          </div>
        </div>
      `;
    }).join('');
  } catch (err) {
    dd.innerHTML = '<div style="padding:10px 12px; color:#c62828; font-size:0.82rem;">Error de red al buscar clientes.</div>';
  }
}

function teclaBusquedaClientesPos(e) {
  if (e.key === 'Enter') {
    e.preventDefault();
    if (resultadosClientesPos.length > 0) {
      seleccionarClientePosPorIndice(0);
    }
  }
}

function seleccionarClientePosPorIndice(idx) {
  const cli = resultadosClientesPos[idx];
  if (!cli) return;
  seleccionarClientePos(cli);
}

function seleccionarClientePos(cli) {
  clienteSeleccionadoPos = {
    cliente_id: cli.cliente_id || 'CONSUMIDOR_FINAL',
    codigo_cliente: cli.codigo_cliente || '',
    nombre_razon_social: cli.nombre_razon_social || 'Consumidor Final',
    tipo_documento: (cli.tipo_documento || 'CEDULA').toUpperCase(),
    documento_identidad: cli.documento_identidad || '',
    permite_credito: Boolean(cli.permite_credito),
    limite_credito: Number(cli.limite_credito || 0)
  };

  document.getElementById('selectCliente').value = clienteSeleccionadoPos.cliente_id;
  const lbl = document.getElementById('posClientePillTexto');
  if (lbl) {
    if (clienteSeleccionadoPos.cliente_id === 'CONSUMIDOR_FINAL') {
      lbl.innerHTML = '<strong>Consumidor Final</strong> <span style="font-size:0.74rem; color:#7a6b5d;">(Mostrador)</span>';
    } else {
      const cred = clienteSeleccionadoPos.permite_credito ? ' <span style="color:#2e7d32; font-weight:700;">★</span>' : '';
      const doc = clienteSeleccionadoPos.documento_identidad
        ? ` <span style="font-size:0.74rem; color:#7a6b5d; font-family:monospace;">(${escaparHtml(clienteSeleccionadoPos.tipo_documento)}: ${escaparHtml(clienteSeleccionadoPos.documento_identidad)})</span>`
        : '';
      lbl.innerHTML = `<strong>${escaparHtml(clienteSeleccionadoPos.nombre_razon_social)}</strong>${cred}${doc}`;
    }
  }

  cerrarBuscadorClientePos();
  alCambiarCliente();
}

/* ==========================================================================
   CABECERA: CLIENTE, NCF, CONDICIÓN DE VENTA Y MONEDA
   ========================================================================== */
function alCambiarCliente() {
  const selNcf = document.getElementById('selectTipoNcf');
  if (!selNcf) return;

  if (clienteSeleccionadoPos.cliente_id === 'CONSUMIDOR_FINAL') {
    if (selNcf.value === 'B01') selNcf.value = 'B02';
  } else if (clienteSeleccionadoPos.tipo_documento === 'RNC') {
    selNcf.value = 'B01';
  }

  actualizarInfoNcf();
  if (cuponCodigoActivo) recalcularCarritoServidor();
}

function actualizarInfoNcf() {
  const selNcf = document.getElementById('selectTipoNcf');
  const badge = document.getElementById('badgeNcfActivo');
  if (!selNcf || !badge) return;

  const tipo = selNcf.value;
  if (tipo === 'CONSUMO_INTERNO') {
    badge.textContent = 'USO INTERNO';
    return;
  }
  const info = (window.POS_NCF_INFO || {})[tipo];
  badge.textContent = (info && info.ncf_proximo) ? ('NCF: ' + info.ncf_proximo) : ('SIN SECUENCIA ' + tipo);
}

function alCambiarCondicionVenta() {
  const cond = document.getElementById('selectCondicionVenta')?.value || 'CONTADO';
  const btn = document.getElementById('btnGuardarFactura');
  if (btn && window.POS_PUEDE_FACTURAR) {
    btn.textContent = (cond === 'CREDITO') ? '📋 Emitir Factura a Crédito' : '💳 Emitir Factura';
  }
}

function alCambiarMoneda() {
  renderizarTotales(ultimoCalculo);
}

/* ==========================================================================
   ESCÁNER DIRECTO Y MODAL CATÁLOGO / PRESENTACIONES
   ========================================================================== */
async function escanearCodigoPos() {
  const inp = document.getElementById('inputScannerPos');
  const codigo = inp ? inp.value.trim() : '';
  const almacenId = document.getElementById('selectAlmacenPos')?.value || '0';
  if (!codigo) return;

  try {
    const url = `procesar_facturacion.php?accion=buscar_barcode&codigo=${encodeURIComponent(codigo)}&almacen_id=${encodeURIComponent(almacenId)}`;
    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();

    if (!data.ok || !data.item) {
      mostrarToast(data.mensaje || 'Código no encontrado.', 'error');
      inp.select();
      return;
    }

    const it = data.item;
    agregarItemCrudoAlCarrito(Number(it.producto_maestro_id), it.codigo_barra_id ? Number(it.codigo_barra_id) : null, 1);
    inp.value = '';
    inp.focus();
    mostrarToast(`«${it.producto_nombre}» (${it.descripcion_presentacion}) añadido.`);
  } catch (err) {
    mostrarToast('Error de red al escanear el código.', 'error');
  }
}

function abrirModalCatalogo() {
  const selAlm = document.getElementById('selectAlmacenPos');
  const nomAlm = selAlm ? (selAlm.options[selAlm.selectedIndex]?.text || '') : '';
  document.getElementById('subtituloCatalogoPos').textContent = `Almacén de despacho activo: ${nomAlm} • Haz clic en la presentación que deseas facturar`;
  document.getElementById('catFiltroTexto').value = '';
  document.getElementById('catFiltroCategoria').value = '0';
  document.getElementById('catFiltroMarca').value = '0';
  abrirModal('modalCatalogoProductos');
  setTimeout(() => document.getElementById('catFiltroTexto')?.focus(), 80);
  buscarEnCatalogoPos();
}

function programarBusquedaCatalogoPos() {
  clearTimeout(timerCatPos);
  timerCatPos = setTimeout(buscarEnCatalogoPos, 200);
}

async function buscarEnCatalogoPos() {
  const almacenId = document.getElementById('selectAlmacenPos')?.value || '0';
  const q = document.getElementById('catFiltroTexto')?.value.trim() || '';
  const catId = document.getElementById('catFiltroCategoria')?.value || '0';
  const marId = document.getElementById('catFiltroMarca')?.value || '0';
  const soloStock = document.getElementById('catFiltroSoloStock')?.checked ? '1' : '0';

  const tbody = document.getElementById('catTablaResultados');
  const badge = document.getElementById('catContadorResultados');
  tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:22px; color:#888;">Consultando catálogo y existencias...</td></tr>';

  try {
    const params = new URLSearchParams({
      accion: 'buscar_catalogo',
      almacen_id: almacenId,
      q: q,
      categoria_id: catId,
      marca_id: marId,
      solo_con_stock: soloStock
    });
    const res = await fetch(`procesar_facturacion.php?${params.toString()}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();
    const items = Array.isArray(data.items) ? data.items : [];
    badge.textContent = `${items.length} producto(s)`;

    if (!data.ok || items.length === 0) {
      tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:24px; color:#888;">No se encontraron productos con los filtros actuales.</td></tr>';
      return;
    }

    tbody.innerHTML = items.map((item) => {
      const stock = parseInt(item.stock_disponible || 0, 10);
      const stockColor = stock > 0 ? '#2e7d32' : '#c62828';
      const badgeLote = item.requiere_lote
        ? '<span style="font-size:0.68rem; background:#fff3e0; color:#e65100; padding:1px 5px; border-radius:4px; font-weight:700;">FEFO</span>'
        : '';

      const presentaciones = Array.isArray(item.presentaciones) ? item.presentaciones : [];
      const tienePresentacionBase = presentaciones.some((pr) => Number(pr.factor_conversion) === 1);

      let btns = tienePresentacionBase ? '' : `
        <button type="button" class="btn-sm btn-outline"
          onclick="agregarDesdeCatalogoPos(${item.producto_maestro_id}, null, '${escaparHtml(item.producto_nombre)}')"
          style="margin: 2px; font-size: 0.78rem; padding: 4px 8px;">
          Unidad Base (x1) • <strong>RD$ ${Number(item.precio_base_unidad || 0).toFixed(2)}</strong>
        </button>
      `;

      presentaciones.forEach((pr) => {
        btns += `
          <button type="button" class="btn-sm btn-outline"
            onclick="agregarDesdeCatalogoPos(${item.producto_maestro_id}, ${pr.codigo_barra_id}, '${escaparHtml(pr.descripcion_presentacion)}')"
            style="margin: 2px; font-size: 0.78rem; padding: 4px 8px; background: #fff8ee; border-color: #d4a359; color: #5a3818;">
            <strong>${escaparHtml(pr.descripcion_presentacion)}</strong> (x${pr.factor_conversion}) • RD$ ${Number(pr.precio_venta || 0).toFixed(2)}
          </button>
        `;
      });

      return `
        <tr>
          <td>
            <div style="font-weight: 700; color: #211813;">${escaparHtml(item.producto_nombre)}</div>
            <div style="font-size: 0.76rem; color: #a66e2e; font-family: monospace;">${escaparHtml(item.sku)} ${badgeLote}</div>
          </td>
          <td>
            <div style="font-size: 0.82rem; font-weight: 600;">${escaparHtml(item.categoria_nombre)}</div>
            <div style="font-size: 0.74rem; color: #8a7b70;">${escaparHtml(item.marca_nombre)}</div>
          </td>
          <td style="text-align: center; white-space: nowrap;">
            <strong style="color: ${stockColor};">${stock} uds.</strong>
          </td>
          <td><div style="display: flex; flex-wrap: wrap; gap: 2px;">${btns}</div></td>
        </tr>
      `;
    }).join('');
  } catch (err) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:#c62828;">Error de red al cargar el catálogo.</td></tr>';
  }
}

function agregarDesdeCatalogoPos(pmId, cbId, etiqueta) {
  agregarItemCrudoAlCarrito(pmId, cbId, 1);
  mostrarToast(`«${etiqueta}» agregado al carrito.`);
}

/* ==========================================================================
   GESTIÓN DEL CARRITO + RECÁLCULO EN SERVIDOR (PROMOS, CUPÓN, IMPUESTOS)
   ========================================================================== */
function agregarItemCrudoAlCarrito(pmId, cbId, cantidad = 1) {
  const existente = carritoRaw.find(
    (it) => Number(it.producto_maestro_id) === Number(pmId) && Number(it.codigo_barra_id || 0) === Number(cbId || 0)
  );
  if (existente) {
    existente.cantidad += Number(cantidad) || 1;
  } else {
    carritoRaw.push({
      producto_maestro_id: Number(pmId),
      codigo_barra_id: cbId ? Number(cbId) : null,
      cantidad: Math.max(1, Number(cantidad) || 1)
    });
  }
  recalcularCarritoServidor();
}

function cambiarCantidadProducto(pmId, delta) {
  const idx = carritoRaw.findIndex((it) => Number(it.producto_maestro_id) === Number(pmId));
  if (idx === -1) return;

  const nuevaCant = Number(carritoRaw[idx].cantidad) + delta;
  if (nuevaCant <= 0) {
    quitarProductoDelCarrito(pmId);
    return;
  }
  carritoRaw[idx].cantidad = nuevaCant;
  recalcularCarritoServidor();
}

function fijarCantidadProducto(pmId, valor) {
  const cant = parseInt(valor, 10);
  if (isNaN(cant) || cant <= 0) {
    quitarProductoDelCarrito(pmId);
    return;
  }
  const primera = carritoRaw.find((it) => Number(it.producto_maestro_id) === Number(pmId));
  if (!primera) return;
  carritoRaw = carritoRaw.filter((it) => Number(it.producto_maestro_id) !== Number(pmId));
  carritoRaw.push({
    producto_maestro_id: Number(pmId),
    codigo_barra_id: primera.codigo_barra_id,
    cantidad: cant
  });
  recalcularCarritoServidor();
}

function quitarProductoDelCarrito(pmId) {
  carritoRaw = carritoRaw.filter((it) => Number(it.producto_maestro_id) !== Number(pmId));
  recalcularCarritoServidor();
}

function limpiarCarritoConConfirmacion() {
  if (carritoRaw.length === 0) return;
  if (!confirm('¿Deseas vaciar todos los productos del carrito?')) return;
  carritoRaw = [];
  cuponCodigoActivo = '';
  document.getElementById('inputCodigoCupon').value = '';
  document.getElementById('btnQuitarCupon').style.display = 'none';
  desvincularCotizacion();
  recalcularCarritoServidor();
}

async function recalcularCarritoServidor() {
  if (carritoRaw.length === 0) {
    ultimoCalculo = null;
    renderizarCarritoDesdeCalculo(null);
    return;
  }

  const almacenId = parseInt(document.getElementById('selectAlmacenPos')?.value || '0', 10);
  const clienteId = clienteSeleccionadoPos.cliente_id || 'CONSUMIDOR_FINAL';
  const origen = pedidoDeliveryConfig.activo ? 'DELIVERY' : 'MOSTRADOR';
  const zonaId = pedidoDeliveryConfig.activo ? parseInt(pedidoDeliveryConfig.zona_id || '0', 10) : 0;

  try {
    const res = await fetch('procesar_facturacion.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        accion: 'evaluar_carrito',
        partidas: carritoRaw,
        almacen_id: almacenId,
        cupon_codigo: cuponCodigoActivo,
        origen_venta: origen,
        zona_id: zonaId,
        cliente_id: clienteId
      })
    });
    const data = await res.json();

    if (!data.ok) {
      if (cuponCodigoActivo && (data.mensaje || '').toLowerCase().includes('cupón')) {
        cuponCodigoActivo = '';
        document.getElementById('btnQuitarCupon').style.display = 'none';
      }
      mostrarToast(data.mensaje || 'Error al evaluar el carrito.', 'error');
      return;
    }

    ultimoCalculo = data.calculo;
    renderizarCarritoDesdeCalculo(ultimoCalculo);
  } catch (err) {
    mostrarToast('Error de red al calcular totales del carrito.', 'error');
  }
}

function renderizarCarritoDesdeCalculo(calc) {
  const tbody = document.getElementById('tbodyCarrito');
  const badge = document.getElementById('badgeArticulosCarrito');
  const footer = document.getElementById('footerResumenPartidas');
  const btnLimp = document.getElementById('btnLimpiarCarrito');
  const banCb = document.getElementById('bannerCashbackPreview');
  const banStock = document.getElementById('bannerAlertasStock');

  if (!calc || !Array.isArray(calc.lineas) || calc.lineas.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="8" class="text-center" style="padding: 34px 20px; color: var(--text-muted);">
          Escanea un producto o presiona <strong>🔍 Catálogo y Presentaciones</strong> para comenzar a facturar.
        </td>
      </tr>
    `;
    if (badge) badge.textContent = '0 Artículos';
    if (footer) footer.textContent = '0 líneas registradas en la factura actual';
    if (btnLimp) btnLimp.style.display = 'none';
    if (banCb) banCb.style.display = 'none';
    if (banStock) banStock.style.display = 'none';
    renderizarTotales(null);
    return;
  }

  if (btnLimp) btnLimp.style.display = 'inline-flex';

  let totalUds = 0;
  tbody.innerHTML = calc.lineas.map((ln) => {
    totalUds += Number(ln.cantidad_unidades || 0);
    const descPromo = Number(ln.monto_descuento_promo || 0);
    const impLinea = Number(ln.monto_impuestos || 0);
    const totLinea = Number(ln.monto_total_linea || 0);
    const sinStock = Number(ln.cantidad_unidades) > Number(ln.stock_disponible_almacen);

    const impPills = (ln.impuestos_detalle || []).map((di) =>
      `<span class="pos-tax-pill">${escaparHtml(di.siglas)}: ${formatearMoneda(di.monto_impuesto)}</span>`
    ).join('');

    return `
      <tr class="pos-cart-row" style="${sinStock ? 'background:#fff5f5;' : ''}">
        <td><span class="pos-sku-code">${escaparHtml(ln.sku)}</span></td>
        <td>
          <div style="font-weight: 700; color: #211813;">${escaparHtml(ln.producto_nombre)}</div>
          <div style="font-size: 0.76rem; color: #7a6b5d;">
            ${escaparHtml(ln.descripcion_presentacion)} • ${ln.cantidad_unidades} uds. base
            ${sinStock ? `<strong style="color:#c62828;"> (Disp: ${ln.stock_disponible_almacen})</strong>` : ''}
          </div>
          ${ln.etiqueta_promo ? `<span class="pos-promo-tag">🏷️ ${escaparHtml(ln.etiqueta_promo)}</span>` : ''}
          ${ln.promo_hint ? `<div style="font-size:0.72rem; color:#a66e2e; margin-top:2px;">💡 ${escaparHtml(ln.promo_hint)}</div>` : ''}
        </td>
        <td class="text-center">
          <div class="pos-qty-control">
            <button type="button" class="pos-qty-btn" onclick="cambiarCantidadProducto(${ln.producto_maestro_id}, -1)">−</button>
            <input type="number" min="1" class="pos-qty-input" value="${ln.cantidad_presentacion}"
                   onchange="fijarCantidadProducto(${ln.producto_maestro_id}, this.value)" />
            <button type="button" class="pos-qty-btn" onclick="cambiarCantidadProducto(${ln.producto_maestro_id}, 1)">+</button>
          </div>
        </td>
        <td class="text-right" style="font-family: monospace;">${formatearMoneda(ln.precio_unitario_his)}</td>
        <td class="text-right" style="font-family: monospace; color: ${descPromo > 0 ? '#2e7d32' : '#8a7b70'}; font-weight: ${descPromo > 0 ? '700' : '400'};">
          ${descPromo > 0 ? '-' + formatearMoneda(descPromo) : formatearMoneda(0)}
        </td>
        <td class="text-right">
          <div style="font-family: monospace; font-weight: 600;">${formatearMoneda(impLinea)}</div>
          <div>${impPills}</div>
        </td>
        <td class="text-right" style="font-family: monospace; font-weight: 800; color: #211813;">
          ${formatearMoneda(totLinea)}
        </td>
        <td class="text-right">
          <button type="button" class="pos-btn-quitar" onclick="quitarProductoDelCarrito(${ln.producto_maestro_id})">✕</button>
        </td>
      </tr>
    `;
  }).join('');

  if (badge) badge.textContent = `${totalUds} ${totalUds === 1 ? 'Unidad' : 'Unidades'}`;
  if (footer) footer.textContent = `${calc.lineas.length} línea(s) consolidada(s) (${totalUds} uds. base)`;

  if (banCb) {
    if (calc.cupon_cashback_prev) {
      const cb = calc.cupon_cashback_prev;
      banCb.style.display = 'block';
      banCb.innerHTML = `🎁 <strong>¡Compra califica para Cupón Cashback!</strong> Al emitir esta factura se generará automáticamente un cupón de <strong>${formatearMoneda(cb.monto_credito_ganado)}</strong> (${escaparHtml(cb.nombre_promocion)}).`;
    } else {
      banCb.style.display = 'none';
    }
  }

  if (banStock) {
    if (Array.isArray(calc.alertas_stock) && calc.alertas_stock.length > 0) {
      banStock.style.display = 'block';
      banStock.innerHTML = '⚠️ <strong>Stock insuficiente en el almacén seleccionado:</strong> ' + calc.alertas_stock.map(escaparHtml).join(' | ');
    } else {
      banStock.style.display = 'none';
    }
  }

  const lblCup = document.getElementById('estadoCuponTexto');
  if (calc.cupon_aplicado) {
    document.getElementById('btnQuitarCupon').style.display = 'inline-block';
    lblCup.innerHTML = `<span style="color:#2e7d32; font-weight:700;">✅ Cupón ${escaparHtml(calc.cupon_aplicado.codigo_cupon)} aplicado (-${formatearMoneda(calc.cupon_aplicado.monto_aplicado)} sobre base imponible)</span>`;
  } else {
    document.getElementById('btnQuitarCupon').style.display = 'none';
    lblCup.innerHTML = 'Si el cliente presenta una <strong>Nota de Crédito B04</strong>, se aplica como medio de pago en el modal de Cobro.';
  }

  renderizarTotales(calc);
}

function renderizarTotales(calc) {
  const elTotal = document.getElementById('displayTotalFactura');
  const elBruto = document.getElementById('displaySubtotalBruto');
  const elDesc = document.getElementById('displayDescuentoTotal');
  const spDesc = document.getElementById('spanDescuentoResumen');
  const elDel = document.getElementById('displayTarifaDelivery');
  const spDel = document.getElementById('spanDeliveryResumen');
  const elImp = document.getElementById('displayImpuestosTotal');
  const pills = document.getElementById('wrapPillsImpuestos');

  if (!calc) {
    if (elTotal) elTotal.textContent = formatearMoneda(0);
    if (elBruto) elBruto.textContent = formatearMoneda(0);
    if (elImp) elImp.textContent = formatearMoneda(0);
    if (spDesc) spDesc.style.display = 'none';
    if (spDel) spDel.style.display = 'none';
    if (pills) pills.innerHTML = '';
    return;
  }

  elTotal.textContent = formatearMoneda(calc.monto_total);
  elBruto.textContent = formatearMoneda(calc.monto_subtotal);
  elImp.textContent = formatearMoneda(calc.monto_impuestos);

  if (Number(calc.monto_descuento || 0) > 0) {
    spDesc.style.display = 'inline';
    elDesc.textContent = '-' + formatearMoneda(calc.monto_descuento);
  } else {
    spDesc.style.display = 'none';
  }

  if (Number(calc.tarifa_delivery || 0) > 0) {
    spDel.style.display = 'inline';
    elDel.textContent = '+' + formatearMoneda(calc.tarifa_delivery);
  } else {
    spDel.style.display = 'none';
  }

  if (pills) {
    pills.innerHTML = (calc.resumen_impuestos || []).map((ri) =>
      `<span class="pos-tax-pill">${escaparHtml(ri.siglas)}: ${formatearMoneda(ri.monto)}</span>`
    ).join('');
  }
}

/* ==========================================================================
   CUPÓN PROMOCIONAL (REBAJA BASE IMPONIBLE ANTES DE IMPUESTOS)
   ========================================================================== */
async function aplicarCuponPos() {
  const codigo = (document.getElementById('inputCodigoCupon')?.value || '').trim().toUpperCase();
  if (!codigo) {
    mostrarToast('Ingresa o escanea un código de cupón.', 'error');
    return;
  }
  if (carritoRaw.length === 0) {
    mostrarToast('Agrega productos al carrito antes de aplicar un cupón.', 'error');
    return;
  }

  try {
    const res = await fetch(`procesar_facturacion.php?accion=validar_cupon&codigo_cupon=${encodeURIComponent(codigo)}`);
    const data = await res.json();
    if (!data.ok) {
      mostrarToast(data.mensaje || 'Cupón no válido.', 'error');
      return;
    }
    cuponCodigoActivo = codigo;
    await recalcularCarritoServidor();
    mostrarToast(data.mensaje || 'Cupón aplicado.');
  } catch (err) {
    mostrarToast('Error de red al validar el cupón.', 'error');
  }
}

function quitarCuponPos() {
  cuponCodigoActivo = '';
  document.getElementById('inputCodigoCupon').value = '';
  recalcularCarritoServidor();
  mostrarToast('Cupón retirado de la factura.');
}

/* ==========================================================================
   DELIVERY CON TRIPLE FILTRO EN VIVO (ZONA + HORARIO + CAPACIDAD)
   ========================================================================== */
function abrirModalPedidoDelivery() {
  const selZona = document.getElementById('selectZonaDelivery');
  const inpDir = document.getElementById('inputDireccionDelivery');
  if (pedidoDeliveryConfig.activo) {
    selZona.value = String(pedidoDeliveryConfig.zona_id || '');
    inpDir.value = pedidoDeliveryConfig.direccion_destino || '';
    cargarRepartidoresZonaTripleFiltro(pedidoDeliveryConfig.repartidor_id);
  } else {
    selZona.value = '';
    inpDir.value = '';
    document.getElementById('inputRepartidorSeleccionado').value = '';
    document.getElementById('listaRepartidoresZonaModal').innerHTML =
      '<div class="pos-rep-empty-msg">Selecciona una zona arriba para evaluar repartidores en turno y con capacidad libre.</div>';
    document.getElementById('badgeCountRepZona').textContent = 'Seleccione una zona';
  }
  abrirModal('modalPedidoDelivery');
}

async function cargarRepartidoresZonaTripleFiltro(preseleccionadoId = '') {
  const zonaId = document.getElementById('selectZonaDelivery')?.value || '';
  const cont = document.getElementById('listaRepartidoresZonaModal');
  const badge = document.getElementById('badgeCountRepZona');
  const inpRep = document.getElementById('inputRepartidorSeleccionado');

  if (!zonaId) {
    inpRep.value = '';
    badge.textContent = 'Seleccione una zona';
    cont.innerHTML = '<div class="pos-rep-empty-msg">Selecciona una zona arriba.</div>';
    return;
  }

  cont.innerHTML = '<div class="pos-rep-empty-msg">Evaluando Triple Filtro (Zona + Horario + Capacidad)...</div>';

  try {
    const res = await fetch(`procesar_facturacion.php?accion=repartidores_zona&zona_id=${encodeURIComponent(zonaId)}`);
    const data = await res.json();
    const lista = Array.isArray(data.repartidores) ? data.repartidores : [];
    const elegibles = lista.filter((r) => r.elegible_triple);

    badge.textContent = `${elegibles.length} elegible(s) de ${lista.length}`;

    if (lista.length === 0) {
      inpRep.value = '';
      cont.innerHTML = '<div class="pos-rep-empty-msg" style="color:#c62828;">No hay repartidores asignados a esta zona. Configúralos en Despacho Delivery.</div>';
      return;
    }

    let elegido = preseleccionadoId;
    if (!elegido || !elegibles.some((r) => r.repartidor_id === elegido)) {
      elegido = elegibles.length > 0 ? elegibles[0].repartidor_id : '';
    }
    inpRep.value = elegido;

    cont.innerHTML = lista.map((r) => {
      const ok = Boolean(r.elegible_triple);
      const sel = ok && r.repartidor_id === elegido;
      const motivoNo = !r.en_horario_activo
        ? '⏰ Fuera de horario laboral'
        : (!r.tiene_capacidad ? `📦 Lleno (${r.pedidos_activos}/${r.capacidad_pedidos})` : '');

      return `
        <div class="pos-rep-card ${sel ? 'selected' : ''}"
             data-rep-id="${escaparHtml(r.repartidor_id)}"
             data-rep-nombre="${escaparHtml(r.codigo_empleado + ' • ' + r.nombre_completo)}"
             style="${!ok ? 'opacity:0.55; cursor:not-allowed; background:#fafafa;' : ''}"
             onclick="${ok ? `seleccionarTarjetaRepartidor('${escaparHtml(r.repartidor_id)}')` : ''}">
          <div class="pos-rep-check">${sel ? '✓' : (ok ? '' : '✕')}</div>
          <div class="pos-rep-body">
            <div class="pos-rep-top-line">
              <span class="pos-rep-name">${escaparHtml(r.codigo_empleado)} • ${escaparHtml(r.nombre_completo)}</span>
              ${r.es_ruta_principal
          ? '<span class="pos-rep-route-pill principal">★ Ruta Principal</span>'
          : '<span class="pos-rep-route-pill secundaria">Cobertura</span>'}
            </div>
            <div class="pos-rep-sub-line">
              <span>🛵 ${escaparHtml(r.tipo_vehiculo)} • Carga: ${r.pedidos_activos}/${r.capacidad_pedidos}</span>
              <span style="font-weight:700; color:${ok ? '#2e7d32' : '#c62828'};">
                ${ok ? '● En turno y disponible' : motivoNo}
              </span>
            </div>
          </div>
        </div>
      `;
    }).join('');
  } catch (err) {
    cont.innerHTML = '<div class="pos-rep-empty-msg" style="color:#c62828;">Error de red consultando repartidores.</div>';
  }
}

function seleccionarTarjetaRepartidor(repId) {
  document.getElementById('inputRepartidorSeleccionado').value = repId;
  document.querySelectorAll('#listaRepartidoresZonaModal .pos-rep-card').forEach((c) => {
    const es = c.getAttribute('data-rep-id') === repId;
    c.classList.toggle('selected', es);
    const chk = c.querySelector('.pos-rep-check');
    if (chk && chk.textContent !== '✕') chk.textContent = es ? '✓' : '';
  });
}

function confirmarPedidoDeliveryModal(e) {
  e.preventDefault();
  const selZona = document.getElementById('selectZonaDelivery');
  const repId = document.getElementById('inputRepartidorSeleccionado').value.trim();
  const dirTxt = document.getElementById('inputDireccionDelivery').value.trim();

  if (!selZona.value || !repId || !dirTxt) {
    mostrarToast('Selecciona una zona con repartidor elegible en turno y escribe la dirección.', 'error');
    return;
  }

  const opt = selZona.options[selZona.selectedIndex];
  const card = document.querySelector(`#listaRepartidoresZonaModal .pos-rep-card[data-rep-id="${CSS.escape(repId)}"]`);

  pedidoDeliveryConfig = {
    activo: true,
    zona_id: selZona.value,
    zona_nombre: opt.getAttribute('data-nombre') || opt.textContent,
    zona_tarifa: parseFloat(opt.getAttribute('data-tarifa') || '0'),
    repartidor_id: repId,
    repartidor_nombre: card?.getAttribute('data-rep-nombre') || 'Repartidor Asignado',
    direccion_destino: dirTxt
  };

  actualizarVistaResumenDelivery();
  cerrarModal('modalPedidoDelivery');
  recalcularCarritoServidor();
  mostrarToast(`Delivery activo (${pedidoDeliveryConfig.zona_nombre} • +${formatearMoneda(pedidoDeliveryConfig.zona_tarifa)}).`);
}

function quitarConfiguracionDelivery() {
  pedidoDeliveryConfig = { activo: false, zona_id: '', zona_nombre: '', zona_tarifa: 0, repartidor_id: '', repartidor_nombre: '', direccion_destino: '' };
  actualizarVistaResumenDelivery();
  recalcularCarritoServidor();
}

function actualizarVistaResumenDelivery() {
  const banner = document.getElementById('bannerPedidoDeliveryActivo');
  const texto = document.getElementById('textoResumenDelivery');
  const btn = document.getElementById('btnAbrirPedidoDelivery');

  if (pedidoDeliveryConfig.activo) {
    banner.style.display = 'flex';
    texto.innerHTML = `<strong>Zona:</strong> ${escaparHtml(pedidoDeliveryConfig.zona_nombre)} (+${formatearMoneda(pedidoDeliveryConfig.zona_tarifa)}) • <strong>Repartidor:</strong> ${escaparHtml(pedidoDeliveryConfig.repartidor_nombre)} • <strong>Dir.:</strong> ${escaparHtml(pedidoDeliveryConfig.direccion_destino)}`;
    btn.innerHTML = `🛵 Delivery: ${escaparHtml(pedidoDeliveryConfig.zona_nombre)}`;
  } else {
    banner.style.display = 'none';
    btn.innerHTML = '🛵 Pedido a Domicilio (Delivery)';
  }
}

/* ==========================================================================
   MODAL DE COBRO: SPLIT UNIVERSAL DE PAGOS Y CRÉDITO COMERCIAL
   ========================================================================== */
function abrirModalCobro() {
  if (!window.POS_PUEDE_FACTURAR) {
    mostrarToast('No tienes una sesión de caja ABIERTA asignada.', 'error');
    return;
  }
  if (!ultimoCalculo || !ultimoCalculo.lineas || ultimoCalculo.lineas.length === 0) {
    mostrarToast('Agrega al menos un producto al carrito antes de cobrar.', 'error');
    return;
  }
  if (Array.isArray(ultimoCalculo.alertas_stock) && ultimoCalculo.alertas_stock.length > 0) {
    mostrarToast('Corrige las cantidades sin stock disponible antes de emitir la factura.', 'error');
    return;
  }

  const cliId = clienteSeleccionadoPos.cliente_id;
  const tipoNcf = document.getElementById('selectTipoNcf').value;
  const cond = document.getElementById('selectCondicionVenta').value;

  if (tipoNcf === 'B01' && cliId === 'CONSUMIDOR_FINAL') {
    mostrarToast('El comprobante B01 exige seleccionar un cliente registrado con RNC o Cédula.', 'error');
    return;
  }
  if (cond === 'CREDITO' && cliId === 'CONSUMIDOR_FINAL') {
    mostrarToast('Las facturas a Crédito Comercial requieren un cliente registrado.', 'error');
    return;
  }

  const bloqueEdad = document.getElementById('bloqueVerificacionEdad');
  const chkEdad = document.getElementById('chkConfirmoMayoriaEdad');
  const pedirEdad = Boolean(ultimoCalculo.contiene_alcohol) && cliId === 'CONSUMIDOR_FINAL';
  bloqueEdad.style.display = pedirEdad ? 'block' : 'none';
  if (chkEdad) chkEdad.checked = !pedirEdad;

  pagosSplit = [];
  creditoB04Verificado = null;
  document.getElementById('sfInfoResultado').innerHTML = '';
  document.getElementById('sfCodigoCredito').value = '';

  const panelContado = document.getElementById('panelCobroContado');
  const panelCredito = document.getElementById('panelCobroCredito');

  if (cond === 'CREDITO') {
    panelContado.style.display = 'none';
    panelCredito.style.display = 'block';
    document.getElementById('tituloModalCobro').textContent = 'Confirmar Factura a Crédito (CxC)';
    document.getElementById('textoResumenCredito').innerHTML = `
      <strong>Cliente:</strong> ${escaparHtml(clienteSeleccionadoPos.nombre_razon_social)}<br>
      <strong>Límite autorizado:</strong> ${formatearMoneda(clienteSeleccionadoPos.limite_credito)} • <strong>Plazo:</strong> 30 días calendario<br>
      <strong>Monto a cargar a Cuentas por Cobrar:</strong> <strong>${formatearMoneda(ultimoCalculo.monto_total)}</strong>
    `;
  } else {
    panelContado.style.display = 'block';
    panelCredito.style.display = 'none';
    document.getElementById('tituloModalCobro').textContent = 'Cobro de Factura • Split Universal de Pagos';
    alCambiarMetodoSplit();
  }

  actualizarResumenSplit();
  abrirModal('modalCobroSplit');
}

function alCambiarMetodoSplit() {
  const sel = document.getElementById('splitMetodoPago');
  const cod = sel?.options[sel.selectedIndex]?.getAttribute('data-codigo') || 'EFECTIVO';

  document.getElementById('subtipoTarjeta').style.display = (cod === 'TARJETA') ? 'block' : 'none';
  document.getElementById('subtipoTransferencia').style.display = (cod === 'TRANSFERENCIA') ? 'block' : 'none';
  document.getElementById('subtipoCheque').style.display = (cod === 'CHEQUE') ? 'block' : 'none';
  document.getElementById('subtipoSaldoFavor').style.display = (cod === 'SALDO_FAVOR') ? 'block' : 'none';

  const pendiente = obtenerSaldoPendienteSplit();
  document.getElementById('splitMontoPago').value = pendiente > 0 ? pendiente.toFixed(2) : '';
}

function obtenerSaldoPendienteSplit() {
  const total = Number(ultimoCalculo?.monto_total || 0);
  const cubierto = pagosSplit.reduce((acc, p) => acc + Number(p.aplicado || 0), 0);
  return Math.max(0, Number((total - cubierto).toFixed(2)));
}

async function validarCreditoB04EnCobro() {
  const codigo = (document.getElementById('sfCodigoCredito')?.value || '').trim().toUpperCase();
  const monId = document.getElementById('selectMoneda')?.value || '1';
  const info = document.getElementById('sfInfoResultado');
  creditoB04Verificado = null;

  if (!codigo) {
    info.innerHTML = '<span style="color:#c62828;">Ingresa el código de crédito o NCF B04.</span>';
    return;
  }

  info.innerHTML = 'Verificando vigencia y saldo...';
  try {
    const res = await fetch(`procesar_facturacion.php?accion=validar_credito_b04&codigo_credito=${encodeURIComponent(codigo)}&moneda_id=${encodeURIComponent(monId)}`);
    const data = await res.json();
    if (!data.ok || !data.credito) {
      info.innerHTML = `<span style="color:#c62828; font-weight:700;">❌ ${escaparHtml(data.mensaje || 'Crédito no válido.')}</span>`;
      return;
    }

    creditoB04Verificado = data.credito;
    const saldoB04 = Number(data.credito.saldo_disponible || 0);
    const pendiente = obtenerSaldoPendienteSplit();
    const sugerido = Math.min(saldoB04, pendiente);

    document.getElementById('splitMontoPago').value = sugerido.toFixed(2);
    info.innerHTML = `<span style="color:#2e7d32; font-weight:700;">✅ ${escaparHtml(data.credito.ncf_nota_credito)} • Saldo disponible: ${formatearMoneda(saldoB04)} (Vence: ${escaparHtml(data.credito.fecha_vencimiento_saldo)})</span>`;
  } catch (err) {
    info.innerHTML = '<span style="color:#c62828;">Error de red al consultar la Nota de Crédito.</span>';
  }
}

function agregarPagoSplit() {
  const pendiente = obtenerSaldoPendienteSplit();
  if (pendiente <= 0) {
    mostrarToast('El total de la factura ya está cubierto.', 'error');
    return;
  }

  const sel = document.getElementById('splitMetodoPago');
  const mpId = parseInt(sel.value, 10);
  const cod = sel.options[sel.selectedIndex]?.getAttribute('data-codigo') || 'EFECTIVO';
  const desc = sel.options[sel.selectedIndex]?.textContent || cod;
  const monto = Number(parseFloat(document.getElementById('splitMontoPago').value || '0').toFixed(2));

  if (isNaN(monto) || monto <= 0) {
    mostrarToast('Ingresa un monto mayor a 0.', 'error');
    return;
  }

  const obj = {
    metodo_pago_id: mpId,
    metodo_codigo: cod,
    metodo_label: desc,
    monto_pagado: monto,
    aplicado: 0,
    devuelta: 0,
    resumen: ''
  };

  if (cod === 'EFECTIVO') {
    obj.aplicado = Math.min(monto, pendiente);
    obj.devuelta = Number(Math.max(0, monto - obj.aplicado).toFixed(2));
    obj.resumen = 'Caja física (DOP)';
  } else {
    if (monto > pendiente + 0.01) {
      mostrarToast(`El monto de ${cod} no puede superar el saldo pendiente (${formatearMoneda(pendiente)}).`, 'error');
      return;
    }
    obj.aplicado = Math.min(monto, pendiente);
    obj.monto_pagado = obj.aplicado;

    if (cod === 'TARJETA') {
      const ult4 = document.getElementById('tjUlt4').value.trim();
      const aut = document.getElementById('tjAutorizacion').value.trim();
      if (ult4.length !== 4 || !aut) {
        mostrarToast('En pagos con Tarjeta ingresa los últimos 4 dígitos y el N° de autorización.', 'error');
        return;
      }
      obj.tipo_tarjeta = document.getElementById('tjTipo').value;
      obj.marca_tarjeta = document.getElementById('tjMarca').value;
      obj.ultimos_4_digitos = ult4;
      obj.numero_autorizacion = aut;
      obj.numero_lote = document.getElementById('tjLote').value.trim();
      obj.resumen = `${obj.marca_tarjeta} ****${ult4} • Aut: ${aut}`;
    } else if (cod === 'TRANSFERENCIA') {
      const banco = document.getElementById('trBancoOrigen').value.trim();
      const cuenta = document.getElementById('trCuentaId').value;
      const ref = document.getElementById('trReferencia').value.trim();
      if (!banco || !cuenta || !ref) {
        mostrarToast('En Transferencia indica Banco origen, Cuenta receptora y N° de referencia.', 'error');
        return;
      }
      obj.banco_origen = banco;
      obj.cuenta_bancaria_id = parseInt(cuenta, 10);
      obj.numero_referencia = ref;
      obj.fecha_transferencia = document.getElementById('trFecha').value;
      obj.titular_origen = document.getElementById('trTitular').value.trim();
      obj.resumen = `${banco} • Ref: ${ref}`;
    } else if (cod === 'CHEQUE') {
      const numCh = document.getElementById('chNumero').value.trim();
      const banCh = document.getElementById('chBanco').value.trim();
      const titCh = document.getElementById('chTitular').value.trim();
      if (!numCh || !banCh || !titCh) {
        mostrarToast('En Cheque indica N° de cheque, Banco emisor y Titular.', 'error');
        return;
      }
      obj.numero_cheque = numCh;
      obj.banco_emisor = banCh;
      obj.titular_cheque = titCh;
      obj.fecha_emision = document.getElementById('chFecha').value;
      obj.cuenta_bancaria_id = document.getElementById('chCuentaId').value ? parseInt(document.getElementById('chCuentaId').value, 10) : null;
      obj.resumen = `Cheque #${numCh} • ${banCh}`;
    } else if (cod === 'SALDO_FAVOR') {
      const codCred = document.getElementById('sfCodigoCredito').value.trim().toUpperCase();
      if (!codCred) {
        mostrarToast('Ingresa y verifica el código de la Nota de Crédito B04.', 'error');
        return;
      }
      if (creditoB04Verificado && obj.aplicado > Number(creditoB04Verificado.saldo_disponible)) {
        mostrarToast('El monto excede el saldo disponible de la Nota de Crédito B04.', 'error');
        return;
      }
      obj.codigo_credito = codCred;
      obj.resumen = `Nota Crédito B04 (${codCred})`;
    }
  }

  pagosSplit.push(obj);
  actualizarResumenSplit();
  alCambiarMetodoSplit();
}

function quitarPagoSplit(idx) {
  pagosSplit.splice(idx, 1);
  actualizarResumenSplit();
  alCambiarMetodoSplit();
}

function actualizarResumenSplit() {
  const total = Number(ultimoCalculo?.monto_total || 0);
  const cubierto = Number(pagosSplit.reduce((acc, p) => acc + Number(p.aplicado || 0), 0).toFixed(2));
  const devuelta = Number(pagosSplit.reduce((acc, p) => acc + Number(p.devuelta || 0), 0).toFixed(2));
  const pend = Number(Math.max(0, total - cubierto).toFixed(2));

  document.getElementById('splitTotalFactura').textContent = formatearMoneda(total);
  document.getElementById('splitTotalCubierto').textContent = formatearMoneda(cubierto);

  const lblPC = document.getElementById('splitLblPendienteCambio');
  const valPC = document.getElementById('splitPendienteCambio');

  if (pend > 0) {
    lblPC.textContent = 'Saldo Pendiente';
    valPC.textContent = formatearMoneda(pend);
    valPC.style.color = '#c62828';
  } else {
    lblPC.textContent = 'Devuelta / Cambio';
    valPC.textContent = formatearMoneda(devuelta);
    valPC.style.color = '#2e7d32';
  }

  const tbody = document.getElementById('tbodyPagosSplit');
  if (pagosSplit.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:14px; color:#888;">Agrega uno o varios pagos arriba para cubrir el total de la factura.</td></tr>';
    return;
  }

  tbody.innerHTML = pagosSplit.map((p, idx) => `
    <tr>
      <td><strong>${escaparHtml(p.metodo_codigo)}</strong></td>
      <td style="font-size: 0.82rem; color: #5a3818;">${escaparHtml(p.resumen)}</td>
      <td style="text-align: right; font-family: monospace;">${formatearMoneda(p.monto_pagado)}</td>
      <td style="text-align: right; font-family: monospace; font-weight: 700;">${formatearMoneda(p.aplicado)}</td>
      <td style="text-align: right; font-family: monospace; color: #2e7d32;">${formatearMoneda(p.devuelta)}</td>
      <td style="text-align: right;"><button type="button" class="btn-sm btn-danger" onclick="quitarPagoSplit(${idx})">Quitar</button></td>
    </tr>
  `).join('');
}

async function confirmarYGuardarFactura() {
  const cond = document.getElementById('selectCondicionVenta').value;
  const cliId = clienteSeleccionadoPos.cliente_id;

  if (ultimoCalculo?.contiene_alcohol && cliId === 'CONSUMIDOR_FINAL') {
    const chk = document.getElementById('chkConfirmoMayoriaEdad');
    if (!chk || !chk.checked) {
      mostrarToast('Confirma la mayoría de edad (>= 18 años) para vender bebidas alcohólicas.', 'error');
      return;
    }
  }

  if (cond === 'CONTADO') {
    if (pagosSplit.length === 0) {
      agregarPagoSplit();
    }
    if (obtenerSaldoPendienteSplit() > 0.01) {
      mostrarToast('Los pagos agregados aún no cubren el 100% del total de la factura.', 'error');
      return;
    }
  }

  const btn = document.getElementById('btnConfirmarEmisionFinal');
  const txtOrig = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'Emitiendo factura...';

  try {
    const payload = {
      accion: 'guardar_factura',
      almacen_id: parseInt(document.getElementById('selectAlmacenPos').value, 10),
      cliente_id: cliId,
      tipo_ncf: document.getElementById('selectTipoNcf').value,
      condicion_pago: cond,
      moneda_id: parseInt(document.getElementById('selectMoneda').value || '1', 10),
      origen_venta: pedidoDeliveryConfig.activo ? 'DELIVERY' : 'MOSTRADOR',
      cupon_codigo: cuponCodigoActivo,
      cotizacion_origen: cotizacionOrigenId,
      confirmo_mayoria_edad: Boolean(document.getElementById('chkConfirmoMayoriaEdad')?.checked),
      zona_id: pedidoDeliveryConfig.activo ? parseInt(pedidoDeliveryConfig.zona_id, 10) : 0,
      repartidor_id: pedidoDeliveryConfig.activo ? pedidoDeliveryConfig.repartidor_id : '',
      direccion_destino: pedidoDeliveryConfig.activo ? pedidoDeliveryConfig.direccion_destino : '',
      partidas: carritoRaw,
      pagos: cond === 'CONTADO' ? pagosSplit : []
    };

    const res = await fetch('procesar_facturacion.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();

    if (!data.ok) {
      mostrarToast(data.mensaje || 'Error al emitir la factura.', 'error');
      btn.disabled = false;
      btn.textContent = txtOrig;
      return;
    }

    cerrarModal('modalCobroSplit');
    mostrarToast(data.mensaje || 'Factura emitida con éxito.');
    if (data.cupon_emitido) {
      alert(`🎁 CUPÓN CASHBACK GENERADO\n\nCódigo: ${data.cupon_emitido.codigo_cupon}\nValor: ${formatearMoneda(data.cupon_emitido.monto_credito)}\nVence: ${data.cupon_emitido.fecha_vencimiento}`);
    }

    setTimeout(() => window.location.reload(), 900);
  } catch (err) {
    mostrarToast('Error de red al emitir la factura.', 'error');
    btn.disabled = false;
    btn.textContent = txtOrig;
  }
}

/* ==========================================================================
   COTIZACIONES Y CLIENTE RÁPIDO
   ========================================================================== */
function abrirModalCotizaciones() { abrirModal('modalImportarCotizacion'); }
function abrirModalHistorial() { abrirModal('modalHistorialFacturas'); }
function abrirModalNuevoCliente() {
  document.getElementById('formClienteRapido')?.reset();
  abrirModal('modalNuevoClienteRapido');
}

async function cargarCotizacionEnCarrito(cotizacionId) {
  const almId = document.getElementById('selectAlmacenPos')?.value || '0';
  try {
    const res = await fetch(`procesar_facturacion.php?accion=obtener_cotizacion&cotizacion_id=${encodeURIComponent(cotizacionId)}&almacen_id=${encodeURIComponent(almId)}`);
    const data = await res.json();
    if (!data.ok) {
      mostrarToast(data.mensaje || 'No se pudo cargar la cotización.', 'error');
      return;
    }

    if (data.cotizacion?.cliente_id) {
      seleccionarClientePos({
        cliente_id: data.cotizacion.cliente_id,
        codigo_cliente: data.cotizacion.codigo_cliente || '',
        nombre_razon_social: data.cotizacion.cliente_nombre,
        tipo_documento: data.cotizacion.cliente_tipo_doc || 'CEDULA',
        documento_identidad: data.cotizacion.cliente_doc || '',
        permite_credito: Boolean(data.cotizacion.permite_credito),
        limite_credito: Number(data.cotizacion.limite_credito || 0)
      });
    }

    carritoRaw = (data.detalles || []).map((d) => ({
      producto_maestro_id: Number(d.producto_maestro_id),
      codigo_barra_id: d.codigo_barra_id ? Number(d.codigo_barra_id) : null,
      cantidad: Math.max(1, Number(d.cantidad) || 1)
    }));
    cotizacionOrigenId = Number(data.cotizacion.id);

    document.getElementById('bannerCotizacionActiva').style.display = 'flex';
    document.getElementById('textoCotizacionActiva').textContent = `${data.cotizacion.numero_cotizacion} • ${data.cotizacion.cliente_nombre}`;
    cerrarModal('modalImportarCotizacion');
    await recalcularCarritoServidor();

    if (Array.isArray(data.alertas_stock) && data.alertas_stock.length > 0) {
      mostrarToast('Cotización cargada con alertas de stock insuficiente en este almacén.', 'error');
    } else {
      mostrarToast(`Cotización ${data.cotizacion.numero_cotizacion} cargada al carrito.`);
    }
  } catch (err) {
    mostrarToast('Error de red al importar cotización.', 'error');
  }
}

function desvincularCotizacion() {
  cotizacionOrigenId = null;
  const b = document.getElementById('bannerCotizacionActiva');
  if (b) b.style.display = 'none';
}

function aplicarMascaraDocClienteRapido() {
  const tipo = document.getElementById('cliTipoDoc')?.value || 'CEDULA';
  const inp = document.getElementById('cliDocumento');
  if (!inp) return;
  if (tipo === 'PASAPORTE') {
    inp.maxLength = 20;
    inp.value = inp.value.replace(/[^a-zA-Z0-9-]/g, '').toUpperCase();
    return;
  }
  const dig = inp.value.replace(/\D/g, '');
  if (tipo === 'RNC') {
    inp.maxLength = 11;
    const d = dig.slice(0, 9);
    inp.value = d.length > 8 ? `${d.slice(0, 3)}-${d.slice(3, 8)}-${d.slice(8, 9)}` : (d.length > 3 ? `${d.slice(0, 3)}-${d.slice(3)}` : d);
  } else {
    inp.maxLength = 13;
    const d = dig.slice(0, 11);
    inp.value = d.length > 10 ? `${d.slice(0, 3)}-${d.slice(3, 10)}-${d.slice(10, 11)}` : (d.length > 3 ? `${d.slice(0, 3)}-${d.slice(3)}` : d);
  }
}

function formatearTelefonoRapido(input) {
  const d = input.value.replace(/\D/g, '').slice(0, 10);
  if (!d) input.value = '';
  else if (d.length <= 3) input.value = `(${d}`;
  else if (d.length <= 6) input.value = `(${d.slice(0, 3)}) ${d.slice(3)}`;
  else input.value = `(${d.slice(0, 3)}) ${d.slice(3, 6)}-${d.slice(6, 10)}`;
}

async function guardarClienteRapido(e) {
  e.preventDefault();
  const btn = document.getElementById('btnSubmitClienteRapido');
  btn.disabled = true;
  try {
    const res = await fetch('procesar_facturacion.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        accion: 'crear_cliente_rapido',
        tipo_documento: document.getElementById('cliTipoDoc').value,
        documento_identidad: document.getElementById('cliDocumento').value,
        nombre_razon_social: document.getElementById('cliNombre').value,
        telefono_principal: document.getElementById('cliTelefono').value,
        fecha_nacimiento: document.getElementById('cliFechaNac').value,
        categoria_cliente: document.getElementById('cliCategoria').value
      })
    });
    const data = await res.json();
    if (!data.ok || !data.cliente) {
      mostrarToast(data.mensaje || 'Error al registrar cliente.', 'error');
      return;
    }
    seleccionarClientePos(data.cliente);
    cerrarModal('modalNuevoClienteRapido');
    mostrarToast(data.mensaje || 'Cliente creado.');
  } catch (err) {
    mostrarToast('Error de red al guardar cliente.', 'error');
  } finally {
    btn.disabled = false;
  }
}

/* ==========================================================================
   HISTORIAL: VER DETALLE Y ANULACIÓN CON PIN DE SUPERVISOR
   ========================================================================== */
async function verDetalleFactura(facturaId) {
  const titulo = document.getElementById('detFacTitulo');
  const body = document.getElementById('detFacBody');
  titulo.textContent = 'Detalle de Factura';
  body.innerHTML = '<p style="color:#888;">Consultando factura...</p>';
  abrirModal('modalDetalleFactura');

  try {
    const res = await fetch(`procesar_facturacion.php?accion=detalle_factura&factura_id=${encodeURIComponent(facturaId)}`);
    const data = await res.json();
    if (!data.ok) {
      body.innerHTML = `<p style="color:#c62828;">${escaparHtml(data.mensaje)}</p>`;
      return;
    }

    const c = data.cabecera;
    titulo.textContent = `${c.numero_factura} • ${c.ncf}`;

    const detHtml = (data.detalles || []).map((d) => `
      <tr>
        <td><strong>${escaparHtml(d.sku)}</strong> — ${escaparHtml(d.producto_nombre)}${d.nombre_promocion ? `<div style="font-size:0.72rem; color:#2e7d32;">🏷️ ${escaparHtml(d.nombre_promocion)}</div>` : ''}</td>
        <td style="text-align:center;">${d.cantidad_presentacion} (x${d.factor_conversion} = ${d.cantidad_unidades} uds.)</td>
        <td style="text-align:right; font-family:monospace;">RD$ ${Number(d.monto_subtotal).toFixed(2)}</td>
        <td style="text-align:right; font-family:monospace; color:#2e7d32;">-RD$ ${Number(d.monto_descuento).toFixed(2)}</td>
        <td style="text-align:right; font-family:monospace;">RD$ ${Number(d.monto_impuestos).toFixed(2)}</td>
        <td style="text-align:right; font-family:monospace; font-weight:700;">RD$ ${Number(d.monto_total).toFixed(2)}</td>
      </tr>
    `).join('');

    const pagHtml = (data.pagos || []).map((p) => `
      <li><strong>${escaparHtml(p.metodo_codigo)}</strong>: RD$ ${Number(p.monto_pagado).toFixed(2)}${Number(p.monto_devuelta) > 0 ? ` (Devuelta: RD$ ${Number(p.monto_devuelta).toFixed(2)})` : ''}
        ${p.ultimos_4_digitos ? ` • ${escaparHtml(p.marca_tarjeta)} ****${escaparHtml(p.ultimos_4_digitos)} (Aut: ${escaparHtml(p.numero_autorizacion)})` : ''}
        ${p.ref_transferencia ? ` • ${escaparHtml(p.banco_origen)} Ref: ${escaparHtml(p.ref_transferencia)}` : ''}
        ${p.numero_cheque ? ` • Cheque #${escaparHtml(p.numero_cheque)} (${escaparHtml(p.banco_emisor)})` : ''}
        ${p.origen_saldo ? ` • ${escaparHtml(p.origen_saldo)}` : ''}
      </li>
    `).join('');

    const cupGenHtml = (data.cupones_generados || []).map((cg) =>
      `<div style="background:#edf7ed; border:1px solid #b7dfb9; padding:8px 12px; border-radius:6px; margin-top:8px; font-size:0.84rem; color:#1b5e20;">🎁 Cupón emitido: <strong>${escaparHtml(cg.codigo_cupon)}</strong> por RD$ ${Number(cg.monto_credito).toFixed(2)} (Vence: ${escaparHtml(cg.fecha_vencimiento)} • ${escaparHtml(cg.estado)})</div>`
    ).join('');

    body.innerHTML = `
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; background:#faf7f2; border:1px solid #e7dcc8; border-radius:8px; padding:12px; margin-bottom:12px; font-size:0.86rem;">
        <div><strong>Cliente:</strong> ${escaparHtml(c.cliente_nombre)} ${c.cliente_doc ? '(' + escaparHtml(c.cliente_doc) + ')' : ''}</div>
        <div><strong>Estado:</strong> <strong>${escaparHtml(c.estado)}</strong></div>
        <div><strong>Condición / Origen:</strong> ${escaparHtml(c.condicion_pago)} / ${escaparHtml(c.origen_venta)}</div>
        <div><strong>Fecha:</strong> ${escaparHtml(c.fecha_fmt)}</div>
        <div><strong>Cajero:</strong> ${escaparHtml(c.vendedor_nombre || '—')}</div>
        <div><strong>Cupón redimido:</strong> ${escaparHtml(c.cupon_redimido_codigo || 'Ninguno')}</div>
      </div>
      <table class="carrito-reglas-table">
        <thead><tr><th>Producto</th><th style="text-align:center;">Cant.</th><th style="text-align:right;">Subtotal</th><th style="text-align:right;">Desc.</th><th style="text-align:right;">Imp.</th><th style="text-align:right;">Total</th></tr></thead>
        <tbody>${detHtml}</tbody>
      </table>
      <div style="margin-top:12px; font-size:0.86rem;">
        <strong>Pagos registrados:</strong>
        <ul style="margin:6px 0 0 18px;">${pagHtml || '<li>Venta a Crédito (Cuentas por Cobrar)</li>'}</ul>
      </div>
      ${cupGenHtml}
    `;
  } catch (err) {
    body.innerHTML = '<p style="color:#c62828;">Error de red al consultar el detalle.</p>';
  }
}

function abrirModalAnularFactura(facturaId, numeroFactura) {
  document.getElementById('anularFacturaId').value = String(facturaId);
  document.getElementById('anularFacturaNumero').textContent = numeroFactura;
  document.getElementById('anularJustificacion').value = '';
  document.getElementById('anularPin').value = '';
  abrirModal('modalAnularFactura');
}

async function confirmarAnulacionFactura(e) {
  e.preventDefault();
  const btn = document.getElementById('btnConfirmarAnulacion');
  btn.disabled = true;
  btn.textContent = 'Anulando...';
  try {
    const res = await fetch('procesar_facturacion.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        accion: 'anular_factura',
        factura_id: parseInt(document.getElementById('anularFacturaId').value, 10),
        justificacion: document.getElementById('anularJustificacion').value.trim(),
        supervisor_id: document.getElementById('anularSupervisorId').value,
        pin_autorizacion: document.getElementById('anularPin').value.trim()
      })
    });
    const data = await res.json();
    if (!data.ok) {
      mostrarToast(data.mensaje || 'No se pudo anular la factura.', 'error');
      btn.disabled = false;
      btn.textContent = 'Confirmar Anulación';
      return;
    }
    cerrarModal('modalAnularFactura');
    mostrarToast(data.mensaje || 'Factura anulada.');
    setTimeout(() => window.location.reload(), 900);
  } catch (err) {
    mostrarToast('Error de red al anular la factura.', 'error');
    btn.disabled = false;
    btn.textContent = 'Confirmar Anulación';
  }
}

/* ==========================================================================
   CONTROL DE CAJA EN FACTURACIÓN: ARQUEO CIEGO Y CAJA CHICA (FASE 5)
   ========================================================================== */
function abrirModalArqueoFacturacion() {
  const wrap = document.getElementById('posDenominacionesWrap');
  if (wrap) {
    wrap.innerHTML = DENOMINACIONES_DOP_POS.map((d) => {
      const esMon = d.tipo === 'Moneda';
      return `
        <div class="denom-item-row">
          <div class="denom-badge-type ${esMon ? 'denom-type-moneda' : 'denom-type-billete'}">
            <span>${esMon ? '🪙' : '💵'}</span>
            <span>RD$ ${d.valor.toLocaleString()}</span>
          </div>
          <div style="display:flex; align-items:center; gap:10px;">
            <input type="number" min="0" step="1" value="0" data-denominacion="${d.valor}" class="denom-qty-input"
                   onkeydown="if(['e','E','+','-','.'].includes(event.key)) event.preventDefault();"
                   oninput="recalcularEfectivoFacturacion()" />
            <span class="denom-subtotal-text subtotal-dop">RD$ 0.00</span>
          </div>
        </div>
      `;
    }).join('');
  }
  recalcularEfectivoFacturacion();
  document.getElementById('posVeredictoBox').style.display = 'none';
  abrirModal('modalArqueoCiegoFacturacion');
}

function recalcularEfectivoFacturacion() {
  let total = 0;
  document.querySelectorAll('#posDenominacionesWrap input[data-denominacion]').forEach((inp) => {
    const cant = parseInt(inp.value || '0', 10) || 0;
    const val = parseFloat(inp.getAttribute('data-denominacion')) || 0;
    const sub = cant * val;
    total += sub;
    const sp = inp.parentElement.querySelector('.subtotal-dop');
    if (sp) sp.textContent = 'RD$ ' + sub.toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  });
  document.getElementById('posTotalEfectivoLbl').textContent = 'RD$ ' + total.toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  document.getElementById('pos_arq_efectivo_declarado').value = total.toFixed(2);
}

async function enviarArqueoFacturacion(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('formArqueoFacturacion'));
  fd.set('accion', 'procesar_arqueo');
  const btn = document.getElementById('posBtnSubmitArqueo');
  btn.disabled = true;
  btn.textContent = 'Calculando auditoría...';

  try {
    const res = await fetch('../ControlCaja/procesar_caja.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success && data.status !== 'success') {
      mostrarToast(data.message || data.mensaje || 'Error al procesar arqueo.', 'error');
      btn.disabled = false;
      btn.textContent = '🔒 Finalizar Turno y Cuadrar';
      return;
    }

    const vBox = document.getElementById('posVeredictoBox');
    const dif = parseFloat(data.diferencia ?? data.descuadre_efectivo ?? 0) || 0;
    vBox.style.display = 'block';
    if (dif === 0) {
      vBox.style.background = '#e8f5e9'; vBox.style.color = '#2e7d32';
      vBox.innerHTML = '✅ CUADRE EXACTO: El conteo físico coincide al centavo con el sistema.';
    } else if (dif < 0) {
      vBox.style.background = '#ffebee'; vBox.style.color = '#c62828';
      vBox.innerHTML = `⚠️ FALTANTE DETECTADO: -RD$ ${Math.abs(dif).toLocaleString('es-DO', { minimumFractionDigits: 2 })}`;
    } else {
      vBox.style.background = '#fff8e1'; vBox.style.color = '#b25e09';
      vBox.innerHTML = `⚠️ SOBRANTE DETECTADO: +RD$ ${dif.toLocaleString('es-DO', { minimumFractionDigits: 2 })}`;
    }
    setTimeout(() => window.location.reload(), 1600);
  } catch (err) {
    mostrarToast('Error de red al procesar el arqueo.', 'error');
    btn.disabled = false;
    btn.textContent = '🔒 Finalizar Turno y Cuadrar';
  }
}

function abrirModalCajaChicaFacturacion() {
  document.getElementById('formCajaChicaFacturacion')?.reset();
  abrirModal('modalCajaChicaFacturacion');
}

async function enviarCajaChicaFacturacion(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('formCajaChicaFacturacion'));
  fd.append('accion', 'movimiento_caja_chica');
  const btn = document.getElementById('posBtnSubmitCajaChica');
  btn.disabled = true;
  btn.textContent = 'Autorizando...';

  try {
    const res = await fetch('../ControlCaja/procesar_caja.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success && data.status !== 'success') {
      mostrarToast(data.message || data.mensaje || 'Error al registrar movimiento.', 'error');
      return;
    }
    mostrarToast(data.message || 'Movimiento de caja chica registrado.');
    cerrarModal('modalCajaChicaFacturacion');
  } catch (err) {
    mostrarToast('Error de conexión al registrar caja chica.', 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Registrar con PIN';
  }
}