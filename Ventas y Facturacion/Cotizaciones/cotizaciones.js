/**
 * ============================================================================
 * Controlador Frontend • Proceso 2: Cotizaciones Comerciales
 * Ubicación: Ventas y Facturacion/Cotizaciones/cotizaciones.js
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

let carritoCotRaw = []; // [{producto_maestro_id, codigo_barra_id, cantidad, monto_descuento}]
let ultimoCalcCot = null;
let timerCatCot = null;
let timerQtyCot = null;
let timerDescCot = null;
let timerCliCot = null;
let resultadosClientesCot = [];

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function mostrarToast(msg, isError = false) {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'toast show ' + (isError ? 'toast-error' : 'toast-success');
    clearTimeout(window._toastCotTimer);
    window._toastCotTimer = setTimeout(() => t.classList.remove('show'), 3600);
}

function abrirModal(id) { document.getElementById(id)?.classList.add('open', 'active'); }
function cerrarModal(id) { document.getElementById(id)?.classList.remove('open', 'active'); }

function obtenerSimboloCot() {
    const sel = document.getElementById('cot_moneda_id');
    return sel?.options[sel.selectedIndex]?.getAttribute('data-simbolo') || 'RD$';
}

function fmtMonedaCot(v) {
    return obtenerSimboloCot() + ' ' + (Number(v) || 0).toLocaleString('es-DO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const dd = document.getElementById('cot_cliente_dropdown');
        if (dd && dd.style.display === 'block') {
            dd.style.display = 'none';
            return;
        }
        ['modalClienteRapidoCot', 'modalCatalogoCot', 'modalDetalleCot', 'modalCancelarCot'].forEach(cerrarModal);
    }
});

document.addEventListener('click', (e) => {
    const wrap = document.getElementById('cotClientePickerWrap');
    const dd = document.getElementById('cot_cliente_dropdown');
    if (wrap && dd && !wrap.contains(e.target)) {
        dd.style.display = 'none';
    }
});

// ============================================================================
// BUSCADOR EN VIVO DE CLIENTES (ESCALABLE A CIENTOS/MILES DE CLIENTES)
// ============================================================================
function programarBusquedaClientesCot() {
    clearTimeout(timerCliCot);
    timerCliCot = setTimeout(buscarClientesCot, 180);
}

async function buscarClientesCot() {
    const inp = document.getElementById('cot_cliente_buscar');
    const dd = document.getElementById('cot_cliente_dropdown');
    if (!inp || !dd) return;

    const q = inp.value.trim();
    dd.style.display = 'block';
    dd.innerHTML = '<div style="padding:10px 12px; color:#888; font-size:0.82rem;">Buscando clientes...</div>';

    try {
        const res = await fetch(`procesar_cotizaciones.php?accion=buscar_clientes&q=${encodeURIComponent(q)}`);
        const data = await res.json();
        resultadosClientesCot = Array.isArray(data.clientes) ? data.clientes : [];

        if (!data.ok || resultadosClientesCot.length === 0) {
            dd.innerHTML = '<div style="padding:10px 12px; color:#888; font-size:0.82rem;">No se encontraron clientes. Usa <strong>+ Cliente</strong> para crearlo.</div>';
            return;
        }

        dd.innerHTML = resultadosClientesCot.map((cli, idx) => `
            <div class="cliente-opt-item" onmousedown="seleccionarClienteCotPorIndice(${idx})">
                <div style="font-weight:700; color:#211813;">${escapeHtml(cli.nombre_razon_social)}</div>
                <div style="font-size:0.75rem; color:#7a6b5d; font-family:monospace;">
                    ${escapeHtml(cli.codigo_cliente || '')} • ${escapeHtml(cli.tipo_documento)}: ${escapeHtml(cli.documento_identidad)}
                    ${cli.telefono_principal ? ' • ' + escapeHtml(cli.telefono_principal) : ''}
                </div>
            </div>
        `).join('');
    } catch (err) {
        dd.innerHTML = '<div style="padding:10px 12px; color:#c62828; font-size:0.82rem;">Error de red al buscar clientes.</div>';
    }
}

function teclaBusquedaClientesCot(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        if (resultadosClientesCot.length > 0) {
            seleccionarClienteCotPorIndice(0);
        }
    }
}

function seleccionarClienteCotPorIndice(idx) {
    const cli = resultadosClientesCot[idx];
    if (!cli) return;
    seleccionarClienteCot(cli);
}

function seleccionarClienteCot(cli) {
    document.getElementById('cot_cliente_id').value = cli.cliente_id || '';
    const pill = document.getElementById('cot_cliente_pill');
    const txt = document.getElementById('cot_cliente_pill_texto');
    const inp = document.getElementById('cot_cliente_buscar');
    const dd = document.getElementById('cot_cliente_dropdown');

    const docLabel = cli.documento_identidad
        ? `${cli.tipo_documento || 'DOC'}: ${cli.documento_identidad}`
        : '';

    txt.innerHTML = `<strong>${escapeHtml(cli.nombre_razon_social)}</strong>` +
        (docLabel ? ` <span style="font-size:0.76rem; color:#7a6b5d; font-family:monospace;">(${escapeHtml(docLabel)})</span>` : '');

    pill.style.display = 'flex';
    inp.style.display = 'none';
    inp.value = '';
    dd.style.display = 'none';
}

function limpiarClienteCot(enfocar = false) {
    document.getElementById('cot_cliente_id').value = '';
    const pill = document.getElementById('cot_cliente_pill');
    const inp = document.getElementById('cot_cliente_buscar');
    const dd = document.getElementById('cot_cliente_dropdown');
    if (pill) pill.style.display = 'none';
    if (dd) dd.style.display = 'none';
    if (inp) {
        inp.style.display = '';
        inp.value = '';
        if (enfocar) {
            inp.focus();
            buscarClientesCot();
        }
    }
}

// ============================================================================
// MODAL CREAR / EDITAR COTIZACIÓN
// ============================================================================
function abrirModalNuevaCotizacion() {
    carritoCotRaw = [];
    ultimoCalcCot = null;

    document.getElementById('cot_id_edicion').value = '0';
    document.getElementById('tituloModalCot').textContent = 'Nueva Cotización Comercial';
    document.getElementById('btnGuardarCot').textContent = 'Guardar Cotización';
    document.getElementById('cot_observaciones').value = '';
    document.getElementById('cot_scanner').value = '';
    limpiarClienteCot(false);

    const hoyMas7 = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
    document.getElementById('cot_fecha_vencimiento').value = hoyMas7;

    renderizarCarritoCot(null);
    abrirModal('modalCotizacion');
}

async function abrirEditarCotizacion(cotizacionId) {
    try {
        const res = await fetch(`procesar_cotizaciones.php?accion=detalle_cotizacion&cotizacion_id=${encodeURIComponent(cotizacionId)}`);
        const data = await res.json();
        if (!data.ok || !data.cabecera) {
            mostrarToast(data.mensaje || 'No se pudo cargar la cotización.', true);
            return;
        }

        const c = data.cabecera;
        document.getElementById('cot_id_edicion').value = String(c.id);
        document.getElementById('tituloModalCot').textContent = `Editar Cotización ${c.numero_cotizacion}`;
        document.getElementById('btnGuardarCot').textContent = 'Actualizar Cotización';
        document.getElementById('cot_fecha_vencimiento').value = c.fecha_vencimiento || '';
        document.getElementById('cot_moneda_id').value = String(c.moneda_id || 1);
        document.getElementById('cot_observaciones').value = c.observaciones || '';

        seleccionarClienteCot({
            cliente_id: c.cliente_id,
            nombre_razon_social: c.cliente_nombre,
            tipo_documento: c.cliente_tipo_doc,
            documento_identidad: c.cliente_doc
        });

        carritoCotRaw = (data.detalles || []).map((d) => ({
            producto_maestro_id: Number(d.producto_maestro_id),
            codigo_barra_id: d.codigo_barra_id ? Number(d.codigo_barra_id) : null,
            cantidad: Math.max(1, Number(d.cantidad) || 1),
            monto_descuento: Number(d.monto_descuento) || 0
        }));

        abrirModal('modalCotizacion');
        await recalcularCotizacionServidor();
    } catch (err) {
        mostrarToast('Error de red al cargar cotización para editar.', true);
    }
}

function cerrarModalCotizacion(forzar = false) {
    if (!forzar && carritoCotRaw.length > 0 && !confirm('Tienes productos en la cotización. ¿Seguro que deseas cerrar sin guardar?')) {
        return;
    }
    cerrarModal('modalCotizacion');
}

// ============================================================================
// ESCÁNER Y CATÁLOGO FILTRADO DE PRESENTACIONES
// ============================================================================
async function escanearCodigoCot() {
    const inp = document.getElementById('cot_scanner');
    const cod = inp ? inp.value.trim() : '';
    if (!cod) return;

    try {
        const res = await fetch(`procesar_cotizaciones.php?accion=buscar_barcode&codigo=${encodeURIComponent(cod)}`);
        const data = await res.json();
        if (!data.ok || !data.item) {
            mostrarToast(data.mensaje || 'Código no encontrado.', true);
            inp.select();
            return;
        }
        agregarItemCot(Number(data.item.producto_maestro_id), data.item.codigo_barra_id ? Number(data.item.codigo_barra_id) : null, 1);
        inp.value = '';
        inp.focus();
        mostrarToast(`«${data.item.producto_nombre}» agregado al presupuesto.`);
    } catch (err) {
        mostrarToast('Error de red al escanear.', true);
    }
}

function abrirModalCatalogoCot() {
    document.getElementById('catFiltroTexto').value = '';
    document.getElementById('catFiltroCategoria').value = '0';
    document.getElementById('catFiltroMarca').value = '0';
    abrirModal('modalCatalogoCot');
    setTimeout(() => document.getElementById('catFiltroTexto')?.focus(), 80);
    buscarEnCatalogoCot();
}

function programarBusquedaCatCot() {
    clearTimeout(timerCatCot);
    timerCatCot = setTimeout(buscarEnCatalogoCot, 200);
}

async function buscarEnCatalogoCot() {
    const q = document.getElementById('catFiltroTexto')?.value.trim() || '';
    const catId = document.getElementById('catFiltroCategoria')?.value || '0';
    const marId = document.getElementById('catFiltroMarca')?.value || '0';
    const stock = document.getElementById('catFiltroSoloStock')?.checked ? '1' : '0';

    const tbody = document.getElementById('catTablaResultados');
    const badge = document.getElementById('catContadorResultados');
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:#888;">Buscando productos...</td></tr>';

    try {
        const params = new URLSearchParams({ accion: 'buscar_catalogo', q, categoria_id: catId, marca_id: marId, solo_con_stock: stock });
        const res = await fetch(`procesar_cotizaciones.php?${params.toString()}`);
        const data = await res.json();
        const items = Array.isArray(data.items) ? data.items : [];
        badge.textContent = `${items.length} producto(s)`;

        if (!data.ok || items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:22px; color:#888;">Sin productos con los filtros actuales.</td></tr>';
            return;
        }

        tbody.innerHTML = items.map((item) => {
            const stk = parseInt(item.stock_disponible || 0, 10);
            const presentaciones = Array.isArray(item.presentaciones) ? item.presentaciones : [];
            const tienePresentacionBase = presentaciones.some((pr) => Number(pr.factor_conversion) === 1);

            let btns = tienePresentacionBase ? '' : `
        <button type="button" class="btn-sm btn-outline"
          onclick="agregarDesdeCatalogoCot(${item.producto_maestro_id}, null, '${escapeHtml(item.producto_nombre)}')"
          style="margin: 2px; font-size: 0.78rem; padding: 4px 8px;">
          Unidad Base (x1) • <strong>RD$ ${Number(item.precio_base_unidad || 0).toFixed(2)}</strong>
        </button>
      `;

            presentaciones.forEach((pr) => {
                btns += `
          <button type="button" class="btn-sm btn-outline"
            onclick="agregarDesdeCatalogoCot(${item.producto_maestro_id}, ${pr.codigo_barra_id}, '${escapeHtml(pr.descripcion_presentacion)}')"
            style="margin: 2px; font-size: 0.78rem; padding: 4px 8px; background:#fff8ee; border-color:#d4a359; color:#5a3818;">
            <strong>${escapeHtml(pr.descripcion_presentacion)}</strong> (x${pr.factor_conversion}) • RD$ ${Number(pr.precio_venta || 0).toFixed(2)}
          </button>
        `;
            });

            return `
        <tr>
          <td>
            <div style="font-weight:700; color:#211813;">${escapeHtml(item.producto_nombre)}</div>
            <div style="font-size:0.75rem; color:#a66e2e; font-family:monospace;">${escapeHtml(item.sku)}</div>
          </td>
          <td>
            <div style="font-size:0.82rem; font-weight:600;">${escapeHtml(item.categoria_nombre)}</div>
            <div style="font-size:0.74rem; color:#8a7b70;">${escapeHtml(item.marca_nombre)}</div>
          </td>
          <td style="text-align:center; white-space:nowrap;">
            <strong style="color:${stk > 0 ? '#2e7d32' : '#999'};">${stk} uds.</strong>
          </td>
          <td><div style="display:flex; flex-wrap:wrap; gap:2px;">${btns}</div></td>
        </tr>
      `;
        }).join('');
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:#c62828;">Error de red al consultar catálogo.</td></tr>';
    }
}

function agregarDesdeCatalogoCot(pmId, cbId, etiqueta) {
    agregarItemCot(pmId, cbId, 1);
    mostrarToast(`«${etiqueta}» agregado al presupuesto.`);
}

// ============================================================================
// CARRITO Y CÁLCULO EN SERVIDOR (CON STEPPER −/+ Y ACTUALIZACIÓN AUTOMÁTICA)
// ============================================================================
function agregarItemCot(pmId, cbId, cantidad = 1) {
    const ex = carritoCotRaw.find(
        (it) => Number(it.producto_maestro_id) === Number(pmId) && Number(it.codigo_barra_id || 0) === Number(cbId || 0)
    );
    if (ex) {
        ex.cantidad += Number(cantidad) || 1;
    } else {
        carritoCotRaw.push({
            producto_maestro_id: Number(pmId),
            codigo_barra_id: cbId ? Number(cbId) : null,
            cantidad: Math.max(1, Number(cantidad) || 1),
            monto_descuento: 0
        });
    }
    recalcularCotizacionServidor();
}

function ajustarCantidadCot(pmId, delta) {
    clearTimeout(timerQtyCot);
    const lineaActual = ultimoCalcCot?.lineas?.find((l) => Number(l.producto_maestro_id) === Number(pmId));
    const prevRaw = carritoCotRaw.find((it) => Number(it.producto_maestro_id) === Number(pmId));
    if (!prevRaw) return;

    const cantActual = lineaActual ? Number(lineaActual.cantidad) : Number(prevRaw.cantidad);
    const nueva = cantActual + Number(delta);
    if (nueva <= 0) {
        quitarLineaCot(pmId);
        return;
    }
    cambiarCantidadCot(pmId, nueva);
}

function programarCambioCantidadCot(pmId, val) {
    clearTimeout(timerQtyCot);
    if (String(val).trim() === '') return; // Permitir borrar para escribir un número nuevo
    const cant = parseInt(val, 10);
    if (isNaN(cant) || cant < 1) return;
    timerQtyCot = setTimeout(() => {
        cambiarCantidadCot(pmId, cant);
    }, 450);
}

function cambiarCantidadCot(pmId, val) {
    clearTimeout(timerQtyCot);
    const cant = parseInt(val, 10);
    const prev = carritoCotRaw.find((it) => Number(it.producto_maestro_id) === Number(pmId));
    if (!prev) return;

    const cantFinal = (isNaN(cant) || cant < 1) ? 1 : cant;
    const cbConservado = ultimoCalcCot?.lineas?.find((l) => Number(l.producto_maestro_id) === Number(pmId))?.codigo_barra_id ?? prev.codigo_barra_id;
    const descTotal = carritoCotRaw
        .filter((it) => Number(it.producto_maestro_id) === Number(pmId))
        .reduce((acc, it) => acc + Number(it.monto_descuento || 0), 0);

    carritoCotRaw = carritoCotRaw.filter((it) => Number(it.producto_maestro_id) !== Number(pmId));
    carritoCotRaw.push({
        producto_maestro_id: Number(pmId),
        codigo_barra_id: cbConservado ? Number(cbConservado) : null,
        cantidad: cantFinal,
        monto_descuento: descTotal
    });
    recalcularCotizacionServidor();
}

function programarCambioDescuentoCot(pmId, val) {
    clearTimeout(timerDescCot);
    if (String(val).trim() === '') return;
    timerDescCot = setTimeout(() => {
        cambiarDescuentoCot(pmId, val);
    }, 500);
}

function cambiarDescuentoCot(pmId, val) {
    clearTimeout(timerDescCot);
    if (!window.COT_PUEDE_DESC_MANUAL) return;
    const desc = Math.max(0, Number(parseFloat(val || '0').toFixed(2)) || 0);
    const item = carritoCotRaw.find((it) => Number(it.producto_maestro_id) === Number(pmId));
    if (!item) return;
    carritoCotRaw.forEach((it) => {
        if (Number(it.producto_maestro_id) === Number(pmId)) it.monto_descuento = 0;
    });
    item.monto_descuento = desc;
    recalcularCotizacionServidor();
}

function quitarLineaCot(pmId) {
    clearTimeout(timerQtyCot);
    carritoCotRaw = carritoCotRaw.filter((it) => Number(it.producto_maestro_id) !== Number(pmId));
    recalcularCotizacionServidor();
}

async function recalcularCotizacionServidor() {
    if (carritoCotRaw.length === 0) {
        ultimoCalcCot = null;
        renderizarCarritoCot(null);
        return;
    }
    try {
        const res = await fetch('procesar_cotizaciones.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'evaluar_cotizacion', partidas: carritoCotRaw })
        });
        const data = await res.json();
        if (!data.ok) {
            mostrarToast(data.mensaje || 'Error al calcular cotización.', true);
            return;
        }
        ultimoCalcCot = data.calculo;
        renderizarCarritoCot(ultimoCalcCot);
    } catch (err) {
        mostrarToast('Error de red al calcular impuestos.', true);
    }
}

function renderizarCarritoCot(calc) {
    const tbody = document.getElementById('cotTablaCarritoBody');
    const badge = document.getElementById('cotContadorCarrito');

    if (!calc || !Array.isArray(calc.lineas) || calc.lineas.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px; color:#888;">El presupuesto está vacío. Escanea un producto o usa <strong>🔍 Explorar Catálogo y Presentaciones</strong>.</td></tr>';
        badge.textContent = '0 líneas';
        renderizarTotalesCot();
        return;
    }

    badge.textContent = `${calc.lineas.length} línea(s)`;

    tbody.innerHTML = calc.lineas.map((ln) => {
        const pills = (ln.impuestos_detalle || []).map((di) =>
            `<span class="cot-tax-pill">${escapeHtml(di.siglas)}: ${fmtMonedaCot(di.monto_impuesto)}</span>`
        ).join('');

        const stockAlert = (ln.unidades_base > ln.stock_global_disponible)
            ? `<span style="color:#e65100; font-size:0.72rem; font-weight:700;">⚠️ Ref. stock actual: ${ln.stock_global_disponible} uds.</span>`
            : `<span style="color:#2e7d32; font-size:0.72rem;">Stock ref.: ${ln.stock_global_disponible} uds.</span>`;

        const descInput = window.COT_PUEDE_DESC_MANUAL
            ? `<input type="number" step="0.01" min="0" max="${ln.monto_bruto}" value="${Number(ln.monto_descuento || 0).toFixed(2)}"
                class="form-control" style="width:98px; text-align:right; font-family:monospace; height:30px; margin-left:auto;"
                oninput="programarCambioDescuentoCot(${ln.producto_maestro_id}, this.value)"
                onchange="cambiarDescuentoCot(${ln.producto_maestro_id}, this.value)" />`
            : `<span style="font-family:monospace; color:#888;">${fmtMonedaCot(ln.monto_descuento)}</span>`;

        return `
      <tr>
        <td>
          <div style="font-weight:700; color:#211813;">${escapeHtml(ln.sku)} — ${escapeHtml(ln.producto_nombre)}</div>
          <div style="font-size:0.75rem; color:#7a6b5d;">${escapeHtml(ln.descripcion_presentacion)} (${ln.unidades_base} uds. base) • ${stockAlert}</div>
        </td>
        <td style="text-align:center;">
          <div class="cot-qty-control">
            <button type="button" class="cot-qty-btn" onclick="ajustarCantidadCot(${ln.producto_maestro_id}, -1)" title="Disminuir">−</button>
            <input type="number" min="1" step="1" value="${ln.cantidad}" class="cot-qty-input pos-qty-input"
                   oninput="programarCambioCantidadCot(${ln.producto_maestro_id}, this.value)"
                   onchange="cambiarCantidadCot(${ln.producto_maestro_id}, this.value)" />
            <button type="button" class="cot-qty-btn" onclick="ajustarCantidadCot(${ln.producto_maestro_id}, 1)" title="Aumentar">+</button>
          </div>
        </td>
        <td style="text-align:right; font-family:monospace;">${fmtMonedaCot(ln.precio_unitario)}</td>
        <td style="text-align:right;">${descInput}</td>
        <td style="text-align:right;">
          <div style="font-family:monospace; font-weight:600;">${fmtMonedaCot(ln.monto_impuestos)}</div>
          <div>${pills}</div>
        </td>
        <td style="text-align:right; font-family:monospace; font-weight:800;">${fmtMonedaCot(ln.monto_total)}</td>
        <td style="text-align:right;">
          <button type="button" class="btn-sm btn-danger" onclick="quitarLineaCot(${ln.producto_maestro_id})">✕</button>
        </td>
      </tr>
    `;
    }).join('');

    renderizarTotalesCot();
}

function renderizarTotalesCot() {
    const c = ultimoCalcCot;
    document.getElementById('cotTotBruto').textContent = fmtMonedaCot(c ? c.monto_bruto : 0);
    document.getElementById('cotTotDesc').textContent = '-' + fmtMonedaCot(c ? c.monto_descuentos : 0);
    document.getElementById('cotTotImp').textContent = fmtMonedaCot(c ? c.monto_impuestos : 0);
    document.getElementById('cotTotGeneral').textContent = fmtMonedaCot(c ? c.monto_total : 0);

    const pills = document.getElementById('cotPillsImp');
    if (pills) {
        pills.innerHTML = (c?.resumen_impuestos || []).map((ri) =>
            `<span class="cot-tax-pill">${escapeHtml(ri.siglas)}: ${fmtMonedaCot(ri.monto)}</span>`
        ).join('');
    }
}

async function guardarCotizacion(e) {
    e.preventDefault();
    const clienteId = document.getElementById('cot_cliente_id').value.trim();
    if (!clienteId) {
        mostrarToast('Busca y selecciona un cliente antes de guardar la cotización.', true);
        document.getElementById('cot_cliente_buscar')?.focus();
        return;
    }
    if (carritoCotRaw.length === 0) {
        mostrarToast('Agrega al menos un producto a la cotización.', true);
        return;
    }

    const btn = document.getElementById('btnGuardarCot');
    const txt = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Guardando...';

    try {
        const res = await fetch('procesar_cotizaciones.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                accion: 'guardar_cotizacion',
                cotizacion_id: parseInt(document.getElementById('cot_id_edicion').value || '0', 10),
                cliente_id: clienteId,
                fecha_vencimiento: document.getElementById('cot_fecha_vencimiento').value,
                moneda_id: parseInt(document.getElementById('cot_moneda_id').value || '1', 10),
                observaciones: document.getElementById('cot_observaciones').value.trim(),
                partidas: carritoCotRaw
            })
        });
        const data = await res.json();
        if (!data.ok) {
            mostrarToast(data.mensaje || 'Error al guardar cotización.', true);
            btn.disabled = false;
            btn.textContent = txt;
            return;
        }

        cerrarModalCotizacion(true);
        mostrarToast(data.mensaje || 'Cotización guardada.');
        setTimeout(() => window.location.reload(), 700);
    } catch (err) {
        mostrarToast('Error de red al guardar cotización.', true);
        btn.disabled = false;
        btn.textContent = txt;
    }
}

// ============================================================================
// DETALLE IMPRIMIBLE + VALIDACIÓN EN VIVO DE STOCK + CONVERSIÓN A POS
// ============================================================================
async function verDetalleCotizacion(cotizacionId) {
    const tit = document.getElementById('detCotTitulo');
    const body = document.getElementById('areaImpresionCotizacion');
    const footer = document.getElementById('detCotFooter');

    tit.textContent = 'Detalle de Cotización';
    body.innerHTML = '<p style="color:#888;">Consultando presupuesto y existencias en vivo...</p>';
    footer.innerHTML = '<button type="button" class="btn btn-secondary" onclick="cerrarModal(\'modalDetalleCot\')">Cerrar</button>';
    abrirModal('modalDetalleCot');

    try {
        const res = await fetch(`procesar_cotizaciones.php?accion=detalle_cotizacion&cotizacion_id=${encodeURIComponent(cotizacionId)}`);
        const data = await res.json();
        if (!data.ok || !data.cabecera) {
            body.innerHTML = `<p style="color:#c62828;">${escapeHtml(data.mensaje || 'Error al cargar.')}</p>`;
            return;
        }

        const c = data.cabecera;
        const dets = Array.isArray(data.detalles) ? data.detalles : [];
        const sim = c.simbolo || 'RD$';
        const alertas = Array.isArray(data.alertas_stock) ? data.alertas_stock : [];

        tit.textContent = `Cotización ${c.numero_cotizacion}`;

        const alertaHtml = (c.estado === 'PENDIENTE' && alertas.length > 0) ? `
      <div class="no-print" style="background:#fff3e0; border:1px solid #ffb74d; color:#8d4500; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:0.84rem;">
        <strong>⚠️ Alerta de Stock en Vivo (antes de facturar):</strong><br>
        ${alertas.map(escapeHtml).join('<br>')}
      </div>
    ` : '';

        const filas = dets.map((d) => {
            const okStk = Boolean(d.stock_suficiente);
            return `
        <tr>
          <td>
            <strong>${escapeHtml(d.sku)}</strong> — ${escapeHtml(d.producto_nombre)}
            <div style="font-size:0.75rem; color:#7a6b5d;">${escapeHtml(d.descripcion_presentacion)} (${d.unidades_requeridas} uds. base)</div>
          </td>
          <td style="text-align:center;"><strong>${d.cantidad}</strong></td>
          <td style="text-align:right; font-family:monospace;">${sim} ${Number(d.precio_unitario).toFixed(2)}</td>
          <td style="text-align:right; font-family:monospace; color:#2e7d32;">-${sim} ${Number(d.monto_descuento).toFixed(2)}</td>
          <td style="text-align:right; font-family:monospace;">${sim} ${Number(d.monto_impuestos).toFixed(2)}</td>
          <td style="text-align:right; font-family:monospace; font-weight:700;">${sim} ${Number(d.monto_total).toFixed(2)}</td>
          <td class="no-print" style="text-align:center; font-size:0.76rem; font-weight:700; color:${okStk ? '#2e7d32' : '#c62828'};">
            ${d.stock_disponible_actual} disp.
          </td>
        </tr>
      `;
        }).join('');

        const impPills = (data.resumen_impuestos || []).map((ri) =>
            `<span class="cot-tax-pill">${escapeHtml(ri.siglas)}: ${sim} ${Number(ri.monto).toFixed(2)}</span>`
        ).join('');

        body.innerHTML = `
      ${alertaHtml}
      <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #d4a359; padding-bottom:10px; margin-bottom:14px;">
        <div>
          <h2 style="margin:0; color:#211813;">HOUSE &amp; ICE • COTIZACIÓN COMERCIAL</h2>
          <div style="font-size:0.82rem; color:#7a6b5d;">Presupuesto referencial • No constituye comprobante fiscal ni reserva inventario</div>
        </div>
        <div style="text-align:right;">
          <div style="font-family:monospace; font-size:1.1rem; font-weight:800; color:#a66e2e;">${escapeHtml(c.numero_cotizacion)}</div>
          <div style="font-size:0.78rem; font-weight:700;">Estado: ${escapeHtml(c.estado)}</div>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; background:#faf7f2; border:1px solid #e7dcc8; border-radius:8px; padding:12px; margin-bottom:14px; font-size:0.85rem;">
        <div><strong>Cliente:</strong> ${escapeHtml(c.cliente_nombre)}</div>
        <div><strong>Documento:</strong> ${escapeHtml(c.cliente_tipo_doc)}: ${escapeHtml(c.cliente_doc)}</div>
        <div><strong>Teléfono:</strong> ${escapeHtml(c.cliente_telefono || '—')}</div>
        <div><strong>Vendedor:</strong> ${escapeHtml(c.vendedor_nombre || '—')}</div>
        <div><strong>Emitida:</strong> ${escapeHtml(c.fecha_emision_fmt)}</div>
        <div><strong>Válida hasta:</strong> <strong>${escapeHtml(c.fecha_vencimiento_fmt)}</strong></div>
        ${c.factura_generada_numero ? `<div style="grid-column:1/-1; color:#2e7d32;"><strong>Convertida a Factura:</strong> ${escapeHtml(c.factura_generada_numero)}</div>` : ''}
        ${c.observaciones ? `<div style="grid-column:1/-1;"><strong>Observaciones:</strong> ${escapeHtml(c.observaciones)}</div>` : ''}
      </div>

      <table class="carrito-reglas-table">
        <thead>
          <tr>
            <th>Producto</th>
            <th style="text-align:center;">Cant.</th>
            <th style="text-align:right;">P. Unit.</th>
            <th style="text-align:right;">Desc.</th>
            <th style="text-align:right;">Imp.</th>
            <th style="text-align:right;">Total</th>
            <th class="no-print" style="text-align:center;">Stock Vivo</th>
          </tr>
        </thead>
        <tbody>${filas}</tbody>
      </table>

      <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-top:14px; flex-wrap:wrap; gap:10px;">
        <div>${impPills}</div>
        <div style="text-align:right; font-family:monospace; font-size:0.94rem;">
          <div>Subtotal Neto: <strong>${sim} ${Number(c.monto_subtotal).toFixed(2)}</strong></div>
          <div>Impuestos: <strong>${sim} ${Number(c.monto_impuestos).toFixed(2)}</strong></div>
          <div style="font-size:1.15rem; color:#a66e2e; margin-top:4px;">TOTAL: <strong>${sim} ${Number(c.monto_total).toFixed(2)}</strong></div>
        </div>
      </div>
    `;

        let botonesFooter = `<button type="button" class="btn btn-outline" onclick="window.print()">🖨️ Imprimir / PDF</button>`;
        if (c.estado === 'PENDIENTE') {
            botonesFooter += `<a href="../Facturacion/facturacion.php?cotizacion_id=${c.id}" class="btn btn-gold" style="text-decoration:none;">🛒 Convertir a Factura en POS</a>`;
        }
        botonesFooter += `<button type="button" class="btn btn-secondary" onclick="cerrarModal('modalDetalleCot')">Cerrar</button>`;
        footer.innerHTML = botonesFooter;
    } catch (err) {
        body.innerHTML = '<p style="color:#c62828;">Error de red al consultar detalle.</p>';
    }
}

// ============================================================================
// CLIENTE RÁPIDO, CANCELACIÓN Y FILTROS DE TABLA
// ============================================================================
function abrirModalClienteRapidoCot() {
    document.getElementById('formClienteRapidoCot')?.reset();
    abrirModal('modalClienteRapidoCot');
}

async function guardarClienteRapidoCot(e) {
    e.preventDefault();
    const btn = document.getElementById('btnGuardarCliCot');
    btn.disabled = true;
    try {
        const res = await fetch('procesar_cotizaciones.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                accion: 'crear_cliente_rapido',
                tipo_documento: document.getElementById('cliCotTipoDoc').value,
                documento_identidad: document.getElementById('cliCotDoc').value.trim(),
                nombre_razon_social: document.getElementById('cliCotNombre').value.trim(),
                telefono_principal: document.getElementById('cliCotTel').value.trim(),
                email: document.getElementById('cliCotEmail').value.trim()
            })
        });
        const data = await res.json();
        if (!data.ok || !data.cliente) {
            mostrarToast(data.mensaje || 'No se pudo registrar el cliente.', true);
            return;
        }
        seleccionarClienteCot(data.cliente);
        cerrarModal('modalClienteRapidoCot');
        mostrarToast(data.mensaje || 'Cliente creado.');
    } catch (err) {
        mostrarToast('Error de red al crear cliente.', true);
    } finally {
        btn.disabled = false;
    }
}

function abrirModalCancelarCot(id, numero) {
    document.getElementById('cancelarCotId').value = String(id);
    document.getElementById('cancelarCotNumero').textContent = numero;
    document.getElementById('cancelarCotMotivo').value = '';
    abrirModal('modalCancelarCot');
    setTimeout(() => document.getElementById('cancelarCotMotivo')?.focus(), 80);
}

async function confirmarCancelacionCot(e) {
    e.preventDefault();
    const btn = document.getElementById('btnConfirmarCancelarCot');
    btn.disabled = true;
    try {
        const res = await fetch('procesar_cotizaciones.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                accion: 'cancelar_cotizacion',
                cotizacion_id: parseInt(document.getElementById('cancelarCotId').value, 10),
                motivo: document.getElementById('cancelarCotMotivo').value.trim()
            })
        });
        const data = await res.json();
        if (!data.ok) {
            mostrarToast(data.mensaje || 'Error al cancelar cotización.', true);
            btn.disabled = false;
            return;
        }
        cerrarModal('modalCancelarCot');
        mostrarToast(data.mensaje || 'Cotización cancelada.');
        setTimeout(() => window.location.reload(), 700);
    } catch (err) {
        mostrarToast('Error de red al cancelar cotización.', true);
        btn.disabled = false;
    }
}

function filtrarTablaCotizaciones() {
    const q = (document.getElementById('buscador')?.value || '').toLowerCase().trim();
    const est = (document.getElementById('filtroEstado')?.value || '').toUpperCase();
    const filas = document.querySelectorAll('.fila-cotizacion');
    let visibles = 0;

    filas.forEach((f) => {
        const eF = (f.getAttribute('data-estado') || '').toUpperCase();
        const tx = f.getAttribute('data-texto') || '';
        if ((!est || eF === est) && (!q || tx.includes(q))) {
            f.style.display = '';
            visibles++;
        } else {
            f.style.display = 'none';
        }
    });

    document.getElementById('badgeCount').textContent = `${visibles} registros`;
    document.getElementById('footerInfo').textContent = `Mostrando ${visibles} cotizaciones`;
}