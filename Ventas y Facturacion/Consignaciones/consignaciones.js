/**
 * ============================================================================
 * Lógica Reactiva: Proceso 5 - Consignaciones (Eventos y Bodas)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/Consignaciones/consignaciones.js
 * ============================================================================
 */

let CARRITO_DESPACHO = [];
let CATALOGO_CONSIGNACION_CACHE = [];
let SEARCH_TIMEOUT_CSG = null;
let CONSIGNACION_ACTUAL = null;
let LIQ_PAGOS_SPLIT = [];
let LIQ_DIFERENCIA_OBJETIVO = 0.00;
let procesandoAccionCsg = false;

document.addEventListener('DOMContentLoaded', () => {
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            ['modalNuevaConsignacion', 'modalRetornoFisico', 'modalLiquidacionFinanciera', 'modalDetalleConsignacion']
                .forEach(cerrarModal);
        }
    });

    document.addEventListener('click', (e) => {
        const box = document.getElementById('boxBusquedaClienteCsg');
        const dd = document.getElementById('dropdownClientesCsg');
        if (box && dd && !box.contains(e.target)) dd.style.display = 'none';
    });
});

async function leerJsonSeguro(resp) {
    const texto = await resp.text();
    try {
        return JSON.parse(texto);
    } catch (e) {
        const limpio = texto.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 260);
        throw new Error('Respuesta no válida del servidor: ' + (limpio || 'vacía'));
    }
}

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function fmtMoney(v) {
    return 'RD$ ' + (Number(v) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function round2(n) {
    return Math.round((Number(n) || 0) * 100) / 100;
}

function obtenerMetodoPorCodigoCsg(cod) {
    return CATALOGO_METODOS.find(m => String(m.codigo).toUpperCase() === cod) || CATALOGO_METODOS[0];
}

// ============================================================================
// TABS, FILTROS Y MODALES
// ============================================================================
function cambiarTabConsignacion(tabId, btn) {
    document.querySelectorAll('.csg-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.csg-tab-content').forEach(c => c.classList.remove('active'));
    if (btn) btn.classList.add('active');
    const target = document.getElementById(`tab-${tabId}`);
    if (target) target.classList.add('active');
}

function filtrarTablaActivas() {
    const texto = (document.getElementById('filtroTextoActivas')?.value || '').toLowerCase().trim();
    const filas = document.querySelectorAll('#tablaActivas .fila-csg');
    let visibles = 0;

    filas.forEach(f => {
        const num = (f.getAttribute('data-numero') || '').toLowerCase();
        const cli = (f.getAttribute('data-cliente') || '').toLowerCase();
        const eve = (f.getAttribute('data-evento') || '').toLowerCase();
        if (!texto || num.includes(texto) || cli.includes(texto) || eve.includes(texto)) {
            f.style.display = '';
            visibles++;
        } else {
            f.style.display = 'none';
        }
    });

    const badge = document.getElementById('badgeTotalActivas');
    if (badge) badge.textContent = `${visibles} evento(s)`;
}

function abrirModal(modalId) { document.getElementById(modalId)?.classList.add('active'); }
function cerrarModal(modalId) { document.getElementById(modalId)?.classList.remove('active'); }

// ============================================================================
// FASE 1: NUEVA CONSIGNACIÓN, FIANZA Y DESPACHO
// ============================================================================
function abrirModalNuevaConsignacion() {
    document.getElementById('formNuevaConsignacion').reset();
    limpiarClienteSeleccionadoCsg();
    CARRITO_DESPACHO = [];
    renderizarCarritoDespacho();

    document.getElementById('csgMontoFianza').value = '0.00';
    document.getElementById('csgFechaRetorno').value = new Date(Date.now() + 7 * 86400000).toISOString().split('T')[0];

    actualizarSubpanelFianza();
    cargarCatalogoAlmacenCsg();
    abrirModal('modalNuevaConsignacion');
}

function actualizarSubpanelFianza() {
    const monto = parseFloat(document.getElementById('csgMontoFianza')?.value) || 0;
    const sel = document.getElementById('csgMetodoFianza');
    const cod = sel?.options[sel.selectedIndex]?.getAttribute('data-codigo') || 'EFECTIVO';
    const panel = document.getElementById('csgSubpanelFianza');
    if (!panel) return;

    if (monto <= 0) {
        panel.style.display = 'none';
        panel.innerHTML = '';
        return;
    }

    panel.style.display = 'block';
    const optBancos = CATALOGO_BANCOS.map(b =>
        `<option value="${b.id}">${escHtml(b.nombre_banco)} (${escHtml(b.numero_cuenta)} • DOP)</option>`
    ).join('');

    if (cod === 'EFECTIVO') {
        panel.innerHTML = (SESION_CAJA_ACTIVA && SESION_CAJA_ACTIVA.id)
            ? `<span style="color: #2e7d52; font-weight: 600;">🟢 Se registrará un <strong>INGRESO de ${fmtMoney(monto)}</strong> en ${escHtml(SESION_CAJA_ACTIVA.nombre_equipo)} (blindado para Arqueo Ciego).</span>`
            : `<span style="color: #b53d32; font-weight: 700;">⚠️ No tienes un turno de caja ABIERTO. Abre caja o recibe la fianza por Transferencia Bancaria.</span>`;
    } else if (cod === 'TRANSFERENCIA') {
        panel.innerHTML = `
            <div style="display: grid; grid-template-columns: 1.3fr 1fr 1fr; gap: 6px;">
                <select id="fianzaCuentaId" class="form-control" style="height: 28px; font-size: 0.78rem;">${optBancos || '<option value="">Sin cuentas DOP</option>'}</select>
                <input type="text" id="fianzaBancoOrigen" class="form-control" placeholder="Banco Origen" style="height: 28px; font-size: 0.78rem;" />
                <input type="text" id="fianzaRefTrf" class="form-control" placeholder="No. Referencia *" style="height: 28px; font-size: 0.78rem; font-family: var(--font-mono);" />
            </div>`;
    } else if (cod === 'TARJETA') {
        panel.innerHTML = `
            <div style="display: grid; grid-template-columns: 95px 105px 85px 1fr; gap: 6px;">
                <select id="fianzaTipoTarj" class="form-control" style="height: 28px; font-size: 0.78rem;">
                    <option value="CREDITO">Crédito</option><option value="DEBITO">Débito</option>
                </select>
                <select id="fianzaMarcaTarj" class="form-control" style="height: 28px; font-size: 0.78rem;">
                    <option value="VISA">Visa</option><option value="MASTERCARD">Mastercard</option><option value="AMEX">Amex</option><option value="OTRA">Otra</option>
                </select>
                <input type="text" id="fianzaUlt4" class="form-control" maxlength="4" placeholder="Últ. 4 *" style="height: 28px; font-size: 0.78rem; font-family: var(--font-mono);" oninput="this.value=this.value.replace(/\\D/g,'').slice(0,4)" />
                <input type="text" id="fianzaAutTarj" class="form-control" placeholder="No. Autorización *" style="height: 28px; font-size: 0.78rem;" />
            </div>`;
    } else if (cod === 'CHEQUE') {
        panel.innerHTML = `
            <div style="display: grid; grid-template-columns: 1fr 110px 1.2fr; gap: 6px;">
                <input type="text" id="fianzaBancoChq" class="form-control" placeholder="Banco Emisor *" style="height: 28px; font-size: 0.78rem;" />
                <input type="text" id="fianzaNumChq" class="form-control" placeholder="No. Cheque *" style="height: 28px; font-size: 0.78rem; font-family: var(--font-mono);" />
                <select id="fianzaCuentaChq" class="form-control" style="height: 28px; font-size: 0.78rem;">
                    <option value="0">-- En custodia --</option>${optBancos}
                </select>
            </div>`;
    }
}

function abrirDropdownClientesCsg() {
    filtrarClientesCsg(document.getElementById('inputBuscarClienteCsg')?.value || '');
    const dd = document.getElementById('dropdownClientesCsg');
    if (dd) dd.style.display = 'block';
}

function filtrarClientesCsg(query) {
    const dd = document.getElementById('dropdownClientesCsg');
    if (!dd) return;

    const lista = Array.isArray(CLIENTES_LISTA) ? CLIENTES_LISTA : [];
    const q = (query || '').toLowerCase().trim();
    const filtrados = (q === ''
        ? lista
        : lista.filter(c =>
            (c.nombre_razon_social || '').toLowerCase().includes(q)
            || (c.documento_identidad || '').toLowerCase().includes(q)
            || (c.categoria_cliente || '').toLowerCase().includes(q)
        )).slice(0, 30);

    if (filtrados.length === 0) {
        dd.innerHTML = '<div style="padding: 10px 14px; text-align: center; color: var(--text-muted); font-size: 0.80rem;">No se encontraron clientes coincidentes.</div>';
        dd.style.display = 'block';
        return;
    }

    dd.innerHTML = filtrados.map(c => `
        <div class="csg-client-option" onclick="seleccionarClienteCsg('${escHtml(c.persona_id)}')" style="padding: 8px 12px; border-bottom: 1px solid #f2ede4; cursor: pointer;" onmouseover="this.style.background='#faf5eb'" onmouseout="this.style.background='#ffffff'">
            <div style="font-weight: 700; color: #211813; font-size: 0.84rem;">${escHtml(c.nombre_razon_social)}</div>
            <div style="display: flex; gap: 10px; font-size: 0.73rem; color: #7a6b5d; margin-top: 2px;">
                <span>RNC/Céd: <strong>${escHtml(c.documento_identidad || 'N/A')}</strong></span>
                <span>•</span>
                <span style="font-weight: 600; color: #a66e2e;">${escHtml(c.categoria_cliente || 'GENERAL')}</span>
            </div>
        </div>
    `).join('');
    dd.style.display = 'block';
}

function seleccionarClienteCsg(personaId) {
    const cl = (Array.isArray(CLIENTES_LISTA) ? CLIENTES_LISTA : []).find(c => String(c.persona_id) === String(personaId));
    if (!cl) return;

    document.getElementById('csgCliente').value = cl.persona_id;
    document.getElementById('txtNombreClienteCsg').textContent = cl.nombre_razon_social;
    document.getElementById('txtDetalleClienteCsg').textContent = `(${cl.documento_identidad || 'S/D'} • ${cl.categoria_cliente})`;
    document.getElementById('boxBusquedaClienteCsg').style.display = 'none';
    document.getElementById('badgeClienteElegidoCsg').style.display = 'flex';
    document.getElementById('dropdownClientesCsg').style.display = 'none';
}

function limpiarClienteSeleccionadoCsg() {
    document.getElementById('csgCliente').value = '';
    document.getElementById('boxBusquedaClienteCsg').style.display = 'block';
    document.getElementById('badgeClienteElegidoCsg').style.display = 'none';
    document.getElementById('dropdownClientesCsg').style.display = 'none';
    const inp = document.getElementById('inputBuscarClienteCsg');
    if (inp) inp.value = '';
}

async function cargarCatalogoAlmacenCsg(termino = '') {
    const panel = document.getElementById('panelResultadosCatalogoCsg');
    if (!panel) return;

    const almacenId = document.getElementById('csgAlmacen')?.value || 1;
    panel.innerHTML = '<div style="text-align: center; padding: 25px; color: var(--text-muted); font-size: 0.80rem;">⏳ Cargando catálogo...</div>';

    try {
        const resp = await fetch(`procesar_consignaciones.php?accion=buscar_productos&termino=${encodeURIComponent(termino)}&almacen_id=${encodeURIComponent(almacenId)}`);
        const res = await leerJsonSeguro(resp);
        if (res.ok && Array.isArray(res.data)) {
            CATALOGO_CONSIGNACION_CACHE = res.data;
            renderizarPanelCatalogoCsg();
        } else {
            panel.innerHTML = '<div style="text-align: center; padding: 25px; color: var(--text-muted); font-size: 0.80rem;">No hay artículos con stock disponible en este almacén.</div>';
        }
    } catch (e) {
        panel.innerHTML = `<div style="text-align: center; padding: 20px; color: #b53d32; font-size: 0.80rem;">${escHtml(e.message)}</div>`;
    }
}

function alFiltrarCatalogoCsg(query) {
    clearTimeout(SEARCH_TIMEOUT_CSG);
    SEARCH_TIMEOUT_CSG = setTimeout(() => cargarCatalogoAlmacenCsg(query), 240);
}

function renderizarPanelCatalogoCsg() {
    const panel = document.getElementById('panelResultadosCatalogoCsg');
    if (!panel) return;

    if (CATALOGO_CONSIGNACION_CACHE.length === 0) {
        panel.innerHTML = '<div style="text-align: center; padding: 20px; color: var(--text-muted); font-size: 0.80rem;">Sin productos con stock disponible.</div>';
        return;
    }

    panel.innerHTML = CATALOGO_CONSIGNACION_CACHE.map(p => {
        const enCarrito = CARRITO_DESPACHO.some(item => parseInt(item.producto_id, 10) === parseInt(p.id, 10));
        const stock = parseInt(p.stock_disponible || 0, 10);
        const precio = parseFloat(p.precio_base_unidad || 0);

        return `
            <div style="padding: 7px 10px; background: ${enCarrito ? '#faf5ec' : '#ffffff'}; border: 1px solid #ebd9c2; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                <div style="flex: 1; min-width: 0;">
                    <div style="font-weight: 700; font-size: 0.82rem; color: #211813; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escHtml(p.nombre)}</div>
                    <div style="font-size: 0.72rem; color: #7a6b5d; margin-top: 1px;">
                        <span>${escHtml(p.sku)}</span> •
                        <span style="font-family: var(--font-mono); font-weight: 600;">${fmtMoney(precio)}</span> •
                        <span style="color: ${stock > 0 ? '#2e7d52' : '#b53d32'}; font-weight: 700;">Disp: ${stock}</span>
                    </div>
                </div>
                <div>
                    ${enCarrito
                ? `<button type="button" class="btn btn-sm btn-danger" onclick="quitarProductoDesdeCatalogoCsg(${p.id})" style="padding: 3px 8px; font-size: 0.74rem;">✕ Quitar</button>`
                : `<button type="button" class="btn btn-sm btn-gold" onclick="agregarProductoDesdeCatalogoCsg(${p.id})" ${stock <= 0 ? 'disabled' : ''} style="padding: 3px 8px; font-size: 0.74rem; font-weight: 700;">+ Agregar</button>`
            }
                </div>
            </div>`;
    }).join('');
}

function agregarProductoDesdeCatalogoCsg(prodId) {
    const prod = CATALOGO_CONSIGNACION_CACHE.find(p => parseInt(p.id, 10) === parseInt(prodId, 10));
    if (!prod) return;

    const stockTotalProd = parseInt(prod.stock_disponible || 0, 10);
    let loteId = 0;
    let numLote = 'SIN LOTE (GENÉRICO)';
    let loteDisp = stockTotalProd;

    if (Array.isArray(prod.lotes) && prod.lotes.length > 0) {
        loteId = parseInt(prod.lotes[0].lote_id, 10);
        numLote = prod.lotes[0].numero_lote || ('LOTE-' + loteId);
        loteDisp = parseInt(prod.lotes[0].disponible || 0, 10);
    }

    const existe = CARRITO_DESPACHO.find(i => parseInt(i.producto_id, 10) === parseInt(prod.id, 10) && parseInt(i.lote_id, 10) === loteId);
    if (existe) {
        alCambiarCantidadItemDespacho(CARRITO_DESPACHO.indexOf(existe), existe.cantidad + 1);
    } else {
        const precio = round2(parseFloat(prod.precio_base_unidad || 0));
        CARRITO_DESPACHO.push({
            producto_id: parseInt(prod.id, 10),
            sku: prod.sku,
            nombre: prod.nombre,
            lote_id: loteId,
            numero_lote: numLote,
            disponible: loteDisp,
            stock_total_producto: stockTotalProd,
            cantidad: 1,
            precio: precio,
            subtotal: precio,
            lotes_disponibles: prod.lotes || []
        });
        renderizarCarritoDespacho();
    }

    renderizarPanelCatalogoCsg();
}

function quitarProductoDesdeCatalogoCsg(prodId) {
    CARRITO_DESPACHO = CARRITO_DESPACHO.filter(item => parseInt(item.producto_id, 10) !== parseInt(prodId, 10));
    renderizarCarritoDespacho();
    renderizarPanelCatalogoCsg();
}

function renderizarCarritoDespacho() {
    const tbody = document.getElementById('csgTbodyCarrito');
    if (!tbody) return;

    if (CARRITO_DESPACHO.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 24px; color: var(--text-muted); font-size: 0.80rem;">Haz clic en <strong>+ Agregar</strong> en el catálogo de la izquierda.</td></tr>';
        actualizarTotalesDespacho();
        return;
    }

    tbody.innerHTML = CARRITO_DESPACHO.map((item, idx) => {
        let lotesOptions = `<option value="0" ${item.lote_id === 0 ? 'selected' : ''}>SIN LOTE (GENÉRICO)</option>`;
        if (Array.isArray(item.lotes_disponibles) && item.lotes_disponibles.length > 0) {
            lotesOptions = item.lotes_disponibles.map(l =>
                `<option value="${l.lote_id}" ${parseInt(l.lote_id, 10) === parseInt(item.lote_id, 10) ? 'selected' : ''}>${escHtml(l.numero_lote)} (Disp: ${l.disponible})</option>`
            ).join('');
        }

        const totalProd = item.stock_total_producto || item.disponible;
        const infoTotal = (totalProd > item.disponible)
            ? ` • <span style="color: #1e6091;">Total: ${totalProd}</span>`
            : '';

        return `
            <tr>
                <td style="padding: 4px 6px;">
                    <strong style="font-size: 0.80rem; color: #251c16;">${escHtml(item.nombre)}</strong>
                    <div style="font-size: 0.70rem; color: #8c8173;">${escHtml(item.sku)} • <strong style="color: #2e7d52;">Lote: ${item.disponible}</strong>${infoTotal}</div>
                </td>
                <td style="padding: 4px;">
                    <select class="form-control" style="font-size: 0.74rem; height: 28px; padding: 1px 4px;" onchange="alCambiarLoteItemDespacho(${idx}, this.value)">${lotesOptions}</select>
                </td>
                <td class="text-center" style="padding: 4px;">
                    <input type="number" min="1" max="${totalProd}" value="${item.cantidad}" class="form-control" style="width: 64px; height: 28px; text-align: center; margin: 0 auto; font-weight: 700; font-size: 0.80rem;" onchange="alCambiarCantidadItemDespacho(${idx}, this.value)" />
                </td>
                <td class="text-right" style="padding: 4px;">
                    <input type="number" step="0.01" min="0" value="${item.precio.toFixed(2)}" class="form-control" style="width: 84px; height: 28px; text-align: right; margin-left: auto; font-family: var(--font-mono); font-weight: 700; font-size: 0.80rem;" onchange="alCambiarPrecioItemDespacho(${idx}, this.value)" />
                </td>
                <td class="text-right" style="padding: 4px 6px; font-family: var(--font-mono); font-weight: 700; font-size: 0.82rem;">${fmtMoney(item.subtotal)}</td>
                <td class="text-center" style="padding: 4px 2px;">
                    <button type="button" class="split-pago-del-btn" style="width: 24px; height: 24px; font-size: 0.72rem;" onclick="quitarItemDespacho(${idx})">✕</button>
                </td>
            </tr>`;
    }).join('');

    actualizarTotalesDespacho();
}

function alCambiarLoteItemDespacho(idx, nuevoLoteId) {
    const item = CARRITO_DESPACHO[idx];
    if (!item) return;
    const loteNum = parseInt(nuevoLoteId, 10);

    // Si otra fila ya usa ese mismo producto y lote, consolidarlas
    const otraFila = CARRITO_DESPACHO.find(
        (x, i) => i !== idx && parseInt(x.producto_id, 10) === parseInt(item.producto_id, 10) && parseInt(x.lote_id, 10) === loteNum
    );
    if (otraFila) {
        otraFila.cantidad = Math.min(otraFila.disponible, otraFila.cantidad + item.cantidad);
        otraFila.subtotal = round2(otraFila.cantidad * otraFila.precio);
        CARRITO_DESPACHO.splice(idx, 1);
        renderizarCarritoDespacho();
        return;
    }

    item.lote_id = loteNum;
    const loteObj = (item.lotes_disponibles || []).find(l => parseInt(l.lote_id, 10) === loteNum);
    if (loteObj) {
        item.disponible = parseInt(loteObj.disponible, 10);
        item.numero_lote = loteObj.numero_lote;
        if (item.cantidad > item.disponible) {
            item.cantidad = item.disponible;
            item.subtotal = round2(item.cantidad * item.precio);
        }
    }
    renderizarCarritoDespacho();
}

function alCambiarCantidadItemDespacho(idx, val) {
    const item = CARRITO_DESPACHO[idx];
    if (!item) return;

    let c = parseInt(val, 10);
    if (isNaN(c) || c <= 0) c = 1;

    // Si alcanza dentro del lote actual, actualizar directo
    if (c <= item.disponible) {
        item.cantidad = c;
        item.subtotal = round2(item.cantidad * item.precio);
        renderizarCarritoDespacho();
        return;
    }

    // Si supera el lote actual, distribuir automáticamente el resto en orden FEFO
    let restante = c - item.disponible;
    item.cantidad = item.disponible;
    item.subtotal = round2(item.cantidad * item.precio);

    let insertOffset = 1;
    const lotes = Array.isArray(item.lotes_disponibles) ? item.lotes_disponibles : [];

    for (const lote of lotes) {
        if (restante <= 0) break;
        const lId = parseInt(lote.lote_id, 10);
        if (lId === parseInt(item.lote_id, 10)) continue;

        const dispLote = parseInt(lote.disponible || 0, 10);
        const filaExistente = CARRITO_DESPACHO.find(
            x => parseInt(x.producto_id, 10) === parseInt(item.producto_id, 10) && parseInt(x.lote_id, 10) === lId
        );
        const yaUsado = filaExistente ? parseInt(filaExistente.cantidad, 10) : 0;
        const libre = Math.max(0, dispLote - yaUsado);
        if (libre <= 0) continue;

        const tomar = Math.min(restante, libre);
        if (filaExistente) {
            filaExistente.cantidad += tomar;
            filaExistente.subtotal = round2(filaExistente.cantidad * filaExistente.precio);
        } else {
            CARRITO_DESPACHO.splice(idx + insertOffset, 0, {
                producto_id: item.producto_id,
                sku: item.sku,
                nombre: item.nombre,
                lote_id: lId,
                numero_lote: lote.numero_lote || ('LOTE-' + lId),
                disponible: dispLote,
                stock_total_producto: item.stock_total_producto,
                cantidad: tomar,
                precio: item.precio,
                subtotal: round2(tomar * item.precio),
                lotes_disponibles: item.lotes_disponibles
            });
            insertOffset++;
        }
        restante -= tomar;
    }

    if (restante > 0) {
        alert(`Solo hay ${c - restante} unidades disponibles entre todos los lotes del almacén. Se asignó el máximo en orden FEFO.`);
    }

    renderizarCarritoDespacho();
}

function alCambiarPrecioItemDespacho(idx, val) {
    const item = CARRITO_DESPACHO[idx];
    if (!item) return;
    let p = parseFloat(val);
    if (isNaN(p) || p < 0) p = 0;
    item.precio = round2(p);
    item.subtotal = round2(item.cantidad * item.precio);
    renderizarCarritoDespacho();
}

function quitarItemDespacho(idx) {
    CARRITO_DESPACHO.splice(idx, 1);
    renderizarCarritoDespacho();
    renderizarPanelCatalogoCsg();
}

function limpiarCarritoDespacho() {
    CARRITO_DESPACHO = [];
    renderizarCarritoDespacho();
}

function actualizarTotalesDespacho() {
    let bot = 0, val = 0;
    CARRITO_DESPACHO.forEach(i => { bot += i.cantidad; val = round2(val + i.subtotal); });
    document.getElementById('csgTxtTotalBotellas').textContent = String(bot);
    document.getElementById('csgTxtTotalValor').textContent = fmtMoney(val);
}

async function guardarNuevaConsignacion(e) {
    e.preventDefault();
    if (procesandoAccionCsg) return;

    if (CARRITO_DESPACHO.length === 0) {
        alert('Debe agregar al menos un artículo para el despacho.');
        return;
    }

    const clienteId = document.getElementById('csgCliente')?.value;
    const evento = document.getElementById('csgNombreEvento')?.value.trim();
    const responsable = document.getElementById('csgResponsable')?.value.trim();
    const fechaRetorno = document.getElementById('csgFechaRetorno')?.value;
    const almacenId = parseInt(document.getElementById('csgAlmacen')?.value, 10);
    const montoFianza = round2(parseFloat(document.getElementById('csgMontoFianza')?.value) || 0);
    const selMet = document.getElementById('csgMetodoFianza');
    const metodoFianza = parseInt(selMet?.value || '0', 10);
    const codFianza = selMet?.options[selMet.selectedIndex]?.getAttribute('data-codigo') || 'EFECTIVO';
    const supervisorId = document.getElementById('csgSupervisor')?.value;
    const pin = document.getElementById('csgPin')?.value.trim();

    if (!clienteId || !evento || !responsable || !fechaRetorno || !supervisorId || !pin) {
        alert('Complete todos los campos obligatorios y la autorización del supervisor.');
        return;
    }

    const fianzaDatos = {};
    if (montoFianza > 0) {
        if (codFianza === 'TRANSFERENCIA') {
            fianzaDatos.cuenta_bancaria_id = parseInt(document.getElementById('fianzaCuentaId')?.value || '0', 10);
            fianzaDatos.banco_origen = (document.getElementById('fianzaBancoOrigen')?.value || '').trim();
            fianzaDatos.numero_referencia = (document.getElementById('fianzaRefTrf')?.value || '').trim();
        } else if (codFianza === 'TARJETA') {
            fianzaDatos.tipo_tarjeta = document.getElementById('fianzaTipoTarj')?.value || 'CREDITO';
            fianzaDatos.marca_tarjeta = document.getElementById('fianzaMarcaTarj')?.value || 'VISA';
            fianzaDatos.ultimos_4 = (document.getElementById('fianzaUlt4')?.value || '').trim();
            fianzaDatos.numero_autorizacion = (document.getElementById('fianzaAutTarj')?.value || '').trim();
        } else if (codFianza === 'CHEQUE') {
            fianzaDatos.banco_emisor = (document.getElementById('fianzaBancoChq')?.value || '').trim();
            fianzaDatos.numero_cheque = (document.getElementById('fianzaNumChq')?.value || '').trim();
            fianzaDatos.cuenta_bancaria_id = parseInt(document.getElementById('fianzaCuentaChq')?.value || '0', 10);
        }
    }

    const btn = document.getElementById('btnGuardarNuevaConsignacion');
    procesandoAccionCsg = true;
    btn.disabled = true;
    btn.textContent = 'Procesando despacho y reserva...';

    try {
        const resp = await fetch('procesar_consignaciones.php?accion=crear_consignacion', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                accion: 'crear_consignacion',
                cliente_id: clienteId,
                nombre_evento: evento,
                responsable_evento: responsable,
                fecha_estimada_retorno: fechaRetorno,
                almacen_id: almacenId,
                monto_deposito_garantia: montoFianza,
                metodo_garantia_id: metodoFianza,
                fianza_datos: fianzaDatos,
                supervisor_id: supervisorId,
                pin_autorizacion: pin,
                items: CARRITO_DESPACHO.map(i => ({
                    producto_maestro_id: i.producto_id,
                    lote_id: i.lote_id,
                    cantidad_despachada: i.cantidad,
                    precio_pactado_unitario: i.precio
                }))
            })
        });
        const res = await leerJsonSeguro(resp);
        if (res.ok) {
            alert(res.mensaje);
            window.location.reload();
        } else {
            alert('Error en Consignación: ' + res.mensaje);
        }
    } catch (err) {
        alert('Error de conexión al procesar la consignación: ' + err.message);
    } finally {
        procesandoAccionCsg = false;
        btn.disabled = false;
        btn.textContent = '📦 Despachar y Reservar Stock';
    }
}

// ============================================================================
// FASE 2: REGISTRO DE RETORNO FÍSICO
// ============================================================================
async function abrirModalRetorno(csgId) {
    try {
        const resp = await fetch(`procesar_consignaciones.php?accion=obtener_detalle_consignacion&id=${encodeURIComponent(csgId)}`);
        const res = await leerJsonSeguro(resp);
        if (!res.ok) { alert(res.mensaje); return; }

        const csg = res.data.consignacion;
        const det = res.data.detalles || [];

        document.getElementById('formRetornoFisico').reset();
        document.getElementById('retConsignacionId').value = csg.id;
        document.getElementById('retCabeceraInfo').innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h4 style="font-size: 1rem; color: #251c16; margin-bottom: 4px;">
                        Expediente: <span class="code-inline" style="font-weight: 700;">${escHtml(csg.numero_consignacion)}</span> • ${escHtml(csg.nombre_evento)}
                    </h4>
                    <div style="font-size: 0.8rem; color: #8c8173;">
                        Cliente: <strong>${escHtml(csg.cliente_nombre)}</strong> (${escHtml(csg.cliente_rnc || 'S/D')}) • Contacto: ${escHtml(csg.responsable_evento)}
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 0.78rem; color: #8c8173;">Fianza en Garantía:</div>
                    <strong style="font-size: 1.1rem; color: #2e7d52; font-family: var(--font-mono);">${fmtMoney(csg.monto_deposito_garantia)}</strong>
                </div>
            </div>`;

        document.getElementById('retTbodyArticulos').innerHTML = det.map(d => `
            <tr class="fila-retorno-articulo"
                data-prod-id="${d.producto_maestro_id}"
                data-lote-id="${d.lote_id}"
                data-despachada="${d.cantidad_despachada}"
                data-precio="${d.precio_pactado_unitario}">
                <td><strong>${escHtml(d.producto_nombre)}</strong><div style="font-size: 0.74rem; color: #8c8173;">${escHtml(d.sku)}</div></td>
                <td><span class="code-inline" style="font-size: 0.76rem;">${escHtml(d.numero_lote)}</span></td>
                <td class="text-center" style="font-weight: 700; color: #1e6091;">${d.cantidad_despachada}</td>
                <td class="text-center">
                    <input type="number" min="0" max="${d.cantidad_despachada}" value="${d.cantidad_devuelta}" class="form-control input-cant-devuelta"
                           style="width: 82px; text-align: center; margin: 0 auto; font-weight: 700; border-color: #a66e2e;" oninput="alCambiarInputRetorno()" />
                </td>
                <td class="text-center" style="font-weight: 800; color: #b53d32; font-size: 0.95rem;"><span class="txt-cant-consumida">${d.cantidad_consumida}</span></td>
                <td class="text-right" style="font-family: var(--font-mono);">${fmtMoney(d.precio_pactado_unitario)}</td>
                <td class="text-right" style="font-family: var(--font-mono); font-weight: 700;"><span class="txt-subtotal-consumo">${fmtMoney(d.subtotal_consumido)}</span></td>
            </tr>
        `).join('');

        alCambiarInputRetorno();
        abrirModal('modalRetornoFisico');
    } catch (err) {
        alert('No se pudo abrir el registro de retorno: ' + err.message);
    }
}

function alCambiarInputRetorno() {
    let totalDev = 0, totalCons = 0, totalMonto = 0;
    document.querySelectorAll('.fila-retorno-articulo').forEach(f => {
        const desp = parseInt(f.getAttribute('data-despachada'), 10);
        const precio = parseFloat(f.getAttribute('data-precio'));
        const input = f.querySelector('.input-cant-devuelta');
        let dev = parseInt(input?.value, 10);
        if (isNaN(dev) || dev < 0) dev = 0;
        if (dev > desp) { dev = desp; if (input) input.value = desp; }

        const cons = desp - dev;
        const sub = round2(cons * precio);

        f.querySelector('.txt-cant-consumida').textContent = String(cons);
        f.querySelector('.txt-subtotal-consumo').textContent = fmtMoney(sub);
        totalDev += dev;
        totalCons += cons;
        totalMonto = round2(totalMonto + sub);
    });

    document.getElementById('retTxtTotalDevueltas').textContent = String(totalDev);
    document.getElementById('retTxtTotalConsumidas').textContent = String(totalCons);
    document.getElementById('retTxtTotalConsumoMonto').textContent = fmtMoney(totalMonto);
}

async function guardarRetornoFisico(e) {
    e.preventDefault();
    if (procesandoAccionCsg) return;

    const csgId = parseInt(document.getElementById('retConsignacionId')?.value, 10);
    const supervisorId = document.getElementById('retSupervisor')?.value;
    const pin = document.getElementById('retPin')?.value.trim();

    if (!csgId || !supervisorId || !pin) {
        alert('Debe completar la autorización del supervisor con su PIN.');
        return;
    }

    const retornos = Array.from(document.querySelectorAll('.fila-retorno-articulo')).map(f => ({
        producto_maestro_id: parseInt(f.getAttribute('data-prod-id'), 10),
        lote_id: parseInt(f.getAttribute('data-lote-id'), 10),
        cantidad_devuelta: parseInt(f.querySelector('.input-cant-devuelta')?.value || '0', 10)
    }));

    const btn = document.getElementById('btnGuardarRetorno');
    procesandoAccionCsg = true;
    btn.disabled = true;
    btn.textContent = 'Asentando conteo físico...';

    try {
        const resp = await fetch('procesar_consignaciones.php?accion=registrar_retorno', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'registrar_retorno', consignacion_id: csgId, supervisor_id: supervisorId, pin_autorizacion: pin, retornos })
        });
        const res = await leerJsonSeguro(resp);
        if (res.ok) {
            alert(res.mensaje);
            window.location.reload();
        } else {
            alert('Error en Retorno: ' + res.mensaje);
        }
    } catch (err) {
        alert('Error de conexión al registrar retorno: ' + err.message);
    } finally {
        procesandoAccionCsg = false;
        btn.disabled = false;
        btn.textContent = '💾 Asentar Conteo y Actualizar Consumo';
    }
}

// ============================================================================
// FASE 3: LIQUIDACIÓN FINANCIERA, IMPUESTOS Y SPLIT (EFECTIVO CONSOLIDADO)
// ============================================================================
async function abrirModalLiquidacion(csgId) {
    try {
        const resp = await fetch(`procesar_consignaciones.php?accion=obtener_detalle_consignacion&id=${encodeURIComponent(csgId)}`);
        const res = await leerJsonSeguro(resp);
        if (!res.ok) { alert(res.mensaje); return; }

        CONSIGNACION_ACTUAL = res.data;
        const csg = res.data.consignacion;
        const fiscal = res.data.resumen_fiscal;

        document.getElementById('formLiquidacionFinanciera').reset();
        document.getElementById('liqConsignacionId').value = csg.id;

        // Preseleccionar el almacén real de origen
        const selAlm = document.getElementById('liqAlmacen');
        if (selAlm && res.data.almacen_origen_id) {
            selAlm.value = String(res.data.almacen_origen_id);
        }

        // Sugerir B01 si el cliente tiene RNC
        const selNcf = document.getElementById('liqTipoNcf');
        if (selNcf) {
            selNcf.value = (String(csg.cliente_tipo_doc || '').toUpperCase() === 'RNC') ? 'B01' : 'B02';
        }

        // Resumen del conteo físico
        document.getElementById('liqBannerConteo').innerHTML = `
            <div><strong>${escHtml(csg.numero_consignacion)}</strong> • ${escHtml(csg.cliente_nombre)} (${escHtml(csg.nombre_evento)})</div>
            <div>
                Despachadas: <strong>${fiscal.botellas_despachadas}</strong> •
                Devueltas: <strong style="color: #2e7d52;">${fiscal.botellas_devueltas}</strong> •
                Consumidas: <strong style="color: #b53d32;">${fiscal.botellas_consumidas}</strong>
            </div>`;

        const totalFacturar = Number(fiscal.total_facturar || 0);
        const fianza = Number(csg.monto_deposito_garantia || 0);

        document.getElementById('liqTxtSubtotal').textContent = fmtMoney(fiscal.subtotal_consumo);
        document.getElementById('liqTxtImpuestos').textContent = fmtMoney(fiscal.impuestos_consumo);
        document.getElementById('liqTxtTotalConsumido').textContent = fmtMoney(totalFacturar);
        document.getElementById('liqTxtMontoFianza').textContent = fmtMoney(fianza);

        const pillsImp = (fiscal.resumen_impuestos || []).map(i => `${escHtml(i.siglas)}: ${fmtMoney(i.monto)}`).join(' • ');
        document.getElementById('liqPillsImpuestos').textContent = pillsImp || 'Sin impuestos';

        const secSplit = document.getElementById('liqSeccionSplitDiferencia');
        const secReembolso = document.getElementById('liqSeccionReembolso');
        const lblBal = document.getElementById('liqLblBalance');
        const txtBal = document.getElementById('liqTxtBalanceDiferencia');

        LIQ_PAGOS_SPLIT = [];
        LIQ_DIFERENCIA_OBJETIVO = 0.00;

        if (totalFacturar > fianza) {
            const dif = round2(totalFacturar - fianza);
            LIQ_DIFERENCIA_OBJETIVO = dif;
            lblBal.textContent = 'Diferencia a Cobrar';
            txtBal.textContent = fmtMoney(dif);
            txtBal.style.color = '#b53d32';

            secSplit.style.display = 'block';
            secReembolso.style.display = 'none';
            document.getElementById('liqTxtMontoDiferenciaSpan').textContent = fmtMoney(dif);

            const metIni = (SESION_CAJA_ACTIVA && SESION_CAJA_ACTIVA.id)
                ? obtenerMetodoPorCodigoCsg('EFECTIVO')
                : obtenerMetodoPorCodigoCsg('TRANSFERENCIA');
            agregarFilaSplitLiq(metIni ? metIni.id : null, dif);
        } else if (totalFacturar < fianza) {
            const sobrante = round2(fianza - totalFacturar);
            lblBal.textContent = 'Excedente a Devolver';
            txtBal.textContent = fmtMoney(sobrante);
            txtBal.style.color = '#2e7d52';

            secSplit.style.display = 'none';
            secReembolso.style.display = 'block';
            document.getElementById('liqTxtReembolsoMonto').textContent = fmtMoney(sobrante);
            document.getElementById('liqReembolsoMetodo').value = (SESION_CAJA_ACTIVA && SESION_CAJA_ACTIVA.id) ? 'EFECTIVO' : 'TRANSFERENCIA';
            alCambiarReembolsoMetodo();
            document.getElementById('btnProcesarLiquidacion').disabled = false;
        } else {
            lblBal.textContent = 'Cuadre Exacto con Fianza';
            txtBal.textContent = fmtMoney(0);
            txtBal.style.color = '#2e7d52';
            secSplit.style.display = 'none';
            secReembolso.style.display = 'none';
            document.getElementById('btnProcesarLiquidacion').disabled = false;
        }

        abrirModal('modalLiquidacionFinanciera');
    } catch (err) {
        alert('No se pudo preparar la liquidación: ' + err.message);
    }
}

function alCambiarReembolsoMetodo() {
    const modo = document.getElementById('liqReembolsoMetodo')?.value || 'EFECTIVO';
    const esTrf = (modo === 'TRANSFERENCIA');
    document.getElementById('liqReemCuentaWrap').style.display = esTrf ? 'block' : 'none';
    document.getElementById('liqReemRefWrap').style.display = esTrf ? 'block' : 'none';
}

function obtenerFaltanteLiq() {
    let cub = 0.00;
    LIQ_PAGOS_SPLIT.forEach(p => { cub = round2(cub + Math.max(0, (Number(p.monto) || 0) - (Number(p.monto_devuelta) || 0))); });
    return Math.max(0, round2(LIQ_DIFERENCIA_OBJETIVO - cub));
}

function agregarFilaSplitLiq(metodoId = null, monto = null) {
    const yaEf = LIQ_PAGOS_SPLIT.some(p => p.codigo === 'EFECTIVO');
    let metObj = null;

    if (metodoId) {
        metObj = CATALOGO_METODOS.find(m => parseInt(m.id, 10) === parseInt(metodoId, 10)) || CATALOGO_METODOS[0];
    } else if (!yaEf && SESION_CAJA_ACTIVA && SESION_CAJA_ACTIVA.id) {
        metObj = obtenerMetodoPorCodigoCsg('EFECTIVO');
    } else {
        metObj = CATALOGO_METODOS.find(m => String(m.codigo).toUpperCase() === 'TRANSFERENCIA')
            || CATALOGO_METODOS.find(m => String(m.codigo).toUpperCase() !== 'EFECTIVO')
            || CATALOGO_METODOS[0];
    }

    const cod = metObj ? String(metObj.codigo).toUpperCase() : 'EFECTIVO';
    const mtoIni = (monto !== null) ? Number(monto) : obtenerFaltanteLiq();

    if (cod === 'EFECTIVO' && yaEf) {
        const ef = LIQ_PAGOS_SPLIT.find(p => p.codigo === 'EFECTIVO');
        ef.monto = round2((Number(ef.monto) || 0) + (mtoIni || 0));
        renderizarSplitLiq();
        recalcularCuadreLiq();
        return;
    }

    LIQ_PAGOS_SPLIT.push({
        id_temp: Date.now() + Math.floor(Math.random() * 10000),
        metodo_pago_id: metObj ? parseInt(metObj.id, 10) : 1,
        codigo: cod,
        monto: mtoIni || 0,
        monto_devuelta: 0.00,
        cuenta_bancaria_id: (cod === 'CHEQUE') ? 0 : (CATALOGO_BANCOS[0] ? parseInt(CATALOGO_BANCOS[0].id, 10) : 0),
        numero_referencia: '',
        banco_origen: '',
        numero_cheque: '',
        banco_emisor: '',
        tipo_tarjeta: 'CREDITO',
        marca_tarjeta: 'VISA',
        ultimos_4: '',
        numero_autorizacion: ''
    });

    renderizarSplitLiq();
    recalcularCuadreLiq();
}

function eliminarFilaSplitLiq(idTemp) {
    if (LIQ_PAGOS_SPLIT.length <= 1) {
        alert('Debe mantener al menos un instrumento de pago para cubrir la diferencia.');
        return;
    }
    LIQ_PAGOS_SPLIT = LIQ_PAGOS_SPLIT.filter(p => p.id_temp !== idTemp);
    renderizarSplitLiq();
    recalcularCuadreLiq();
}

function renderizarSplitLiq() {
    const wrap = document.getElementById('liqContenedorSplit');
    if (!wrap) return;
    wrap.innerHTML = '';

    const optBancos = CATALOGO_BANCOS.map(b =>
        `<option value="${b.id}">${escHtml(b.nombre_banco)} (${escHtml(b.numero_cuenta)} • DOP)</option>`
    ).join('');

    LIQ_PAGOS_SPLIT.forEach(p => {
        const div = document.createElement('div');
        div.className = 'split-pago-row-csg';

        const optMet = CATALOGO_METODOS.map(m =>
            `<option value="${m.id}" data-codigo="${escHtml(m.codigo)}" ${parseInt(m.id, 10) === parseInt(p.metodo_pago_id, 10) ? 'selected' : ''}>${escHtml(m.descripcion)}</option>`
        ).join('');

        let subHtml = '';
        if (p.codigo === 'EFECTIVO') {
            const aviso = (SESION_CAJA_ACTIVA && SESION_CAJA_ACTIVA.id)
                ? '<span style="font-size: 0.78rem; color: #2e7d52; font-weight: 700; white-space: nowrap;">💵 Caja Mostrador</span>'
                : '<span style="font-size: 0.75rem; color: #b53d32; font-weight: 700; white-space: nowrap;">⚠️ Sin caja abierta</span>';
            subHtml = `
                <div style="display: flex; gap: 8px; align-items: center; min-width: 0;">
                    ${aviso}
                    <input type="number" step="0.01" min="0" class="form-control" placeholder="Devuelta" value="${p.monto_devuelta || ''}"
                           style="width: 120px; font-family: var(--font-mono); font-size: 0.80rem;"
                           oninput="alCambiarDevueltaLiq(${p.id_temp}, this.value)" />
                </div>`;
        } else if (p.codigo === 'TARJETA') {
            subHtml = `
                <div style="display: flex; gap: 6px; flex-wrap: wrap; min-width: 0;">
                    <select class="form-control" style="width: 88px; font-size: 0.78rem;" onchange="alCambiarPropLiq(${p.id_temp}, 'tipo_tarjeta', this.value)">
                        <option value="CREDITO" ${p.tipo_tarjeta === 'CREDITO' ? 'selected' : ''}>Crédito</option>
                        <option value="DEBITO" ${p.tipo_tarjeta === 'DEBITO' ? 'selected' : ''}>Débito</option>
                    </select>
                    <select class="form-control" style="width: 102px; font-size: 0.78rem;" onchange="alCambiarPropLiq(${p.id_temp}, 'marca_tarjeta', this.value)">
                        <option value="VISA" ${p.marca_tarjeta === 'VISA' ? 'selected' : ''}>Visa</option>
                        <option value="MASTERCARD" ${p.marca_tarjeta === 'MASTERCARD' ? 'selected' : ''}>Mastercard</option>
                        <option value="AMEX" ${p.marca_tarjeta === 'AMEX' ? 'selected' : ''}>Amex</option>
                        <option value="OTRA" ${p.marca_tarjeta === 'OTRA' ? 'selected' : ''}>Otra</option>
                    </select>
                    <input type="text" maxlength="4" placeholder="Últ. 4 *" value="${escHtml(p.ultimos_4)}" class="form-control" style="width: 72px; font-family: var(--font-mono); font-size: 0.78rem;" oninput="this.value=this.value.replace(/\\D/g,'').slice(0,4); alCambiarPropLiq(${p.id_temp}, 'ultimos_4', this.value)" />
                    <input type="text" placeholder="No. Autorización *" value="${escHtml(p.numero_autorizacion)}" class="form-control" style="flex: 1; min-width: 100px; font-size: 0.78rem;" oninput="alCambiarPropLiq(${p.id_temp}, 'numero_autorizacion', this.value.trim())" />
                </div>`;
        } else if (p.codigo === 'TRANSFERENCIA') {
            subHtml = `
                <div style="display: flex; gap: 6px; flex-wrap: wrap; min-width: 0;">
                    <select class="form-control" style="flex: 1.2; min-width: 140px; font-size: 0.78rem;" onchange="alCambiarPropLiq(${p.id_temp}, 'cuenta_bancaria_id', parseInt(this.value, 10))">${optBancos}</select>
                    <input type="text" placeholder="Banco Origen" value="${escHtml(p.banco_origen)}" class="form-control" style="flex: 0.8; min-width: 95px; font-size: 0.78rem;" oninput="alCambiarPropLiq(${p.id_temp}, 'banco_origen', this.value)" />
                    <input type="text" placeholder="No. Referencia *" value="${escHtml(p.numero_referencia)}" class="form-control" style="flex: 0.9; min-width: 105px; font-family: var(--font-mono); font-size: 0.78rem;" oninput="alCambiarPropLiq(${p.id_temp}, 'numero_referencia', this.value.trim())" />
                </div>`;
        } else {
            subHtml = `
                <div style="display: flex; gap: 6px; flex-wrap: wrap; min-width: 0;">
                    <input type="text" placeholder="Banco Emisor *" value="${escHtml(p.banco_emisor)}" class="form-control" style="flex: 1; min-width: 100px; font-size: 0.78rem;" oninput="alCambiarPropLiq(${p.id_temp}, 'banco_emisor', this.value)" />
                    <input type="text" placeholder="No. Cheque *" value="${escHtml(p.numero_cheque)}" class="form-control" style="width: 105px; font-family: var(--font-mono); font-size: 0.78rem;" oninput="alCambiarPropLiq(${p.id_temp}, 'numero_cheque', this.value.trim())" />
                    <select class="form-control" style="flex: 1; min-width: 120px; font-size: 0.76rem;" onchange="alCambiarPropLiq(${p.id_temp}, 'cuenta_bancaria_id', parseInt(this.value, 10))"><option value="0">-- En custodia --</option>${optBancos}</select>
                </div>`;
        }

        div.innerHTML = `
            <div><select class="form-control" style="font-size: 0.80rem; font-weight: 600;" onchange="alCambiarMetodoSplitLiq(${p.id_temp}, this)">${optMet}</select></div>
            ${subHtml}
            <div><input type="number" step="0.01" min="0.01" value="${p.monto.toFixed(2)}" class="form-control" style="font-family: var(--font-mono); font-weight: 700; text-align: right; font-size: 0.86rem;" oninput="alCambiarMontoLiq(${p.id_temp}, this.value)" /></div>
            <div style="display: flex; justify-content: center;"><button type="button" class="split-pago-del-btn" onclick="eliminarFilaSplitLiq(${p.id_temp})">✕</button></div>
        `;
        wrap.appendChild(div);

        // Sincronizar valor seleccionado de banco
        const selB = div.querySelector('select[onchange*="cuenta_bancaria_id"]');
        if (selB && p.cuenta_bancaria_id) selB.value = String(p.cuenta_bancaria_id);
    });
}

function alCambiarPropLiq(idTemp, prop, val) {
    const p = LIQ_PAGOS_SPLIT.find(x => x.id_temp === idTemp);
    if (p) p[prop] = val;
}

function alCambiarMetodoSplitLiq(idTemp, sel) {
    const item = LIQ_PAGOS_SPLIT.find(x => x.id_temp === idTemp);
    if (!item) return;
    const cod = String(sel.options[sel.selectedIndex]?.getAttribute('data-codigo') || 'EFECTIVO').toUpperCase();

    if (cod === 'EFECTIVO') {
        const ef = LIQ_PAGOS_SPLIT.find(x => x.id_temp !== idTemp && x.codigo === 'EFECTIVO');
        if (ef) {
            ef.monto = round2((Number(ef.monto) || 0) + (Number(item.monto) || 0));
            ef.monto_devuelta = round2((Number(ef.monto_devuelta) || 0) + (Number(item.monto_devuelta) || 0));
            LIQ_PAGOS_SPLIT = LIQ_PAGOS_SPLIT.filter(x => x.id_temp !== idTemp);
            renderizarSplitLiq();
            recalcularCuadreLiq();
            alert('Ya existe una línea en Efectivo; el monto se consolidó automáticamente en ella.');
            return;
        }
    }

    item.metodo_pago_id = parseInt(sel.value, 10);
    item.codigo = cod;
    if (cod !== 'EFECTIVO') item.monto_devuelta = 0.00;
    if (cod === 'CHEQUE') item.cuenta_bancaria_id = 0;

    renderizarSplitLiq();
    recalcularCuadreLiq();
}

function alCambiarMontoLiq(idTemp, val) {
    const p = LIQ_PAGOS_SPLIT.find(x => x.id_temp === idTemp);
    if (p) { p.monto = parseFloat(val) || 0; recalcularCuadreLiq(); }
}

function alCambiarDevueltaLiq(idTemp, val) {
    const p = LIQ_PAGOS_SPLIT.find(x => x.id_temp === idTemp);
    if (p) { p.monto_devuelta = Math.max(0, parseFloat(val) || 0); recalcularCuadreLiq(); }
}

function autoCuadrarSplitLiq() {
    if (LIQ_PAGOS_SPLIT.length > 0) {
        LIQ_PAGOS_SPLIT[0].monto = LIQ_DIFERENCIA_OBJETIVO;
        LIQ_PAGOS_SPLIT[0].monto_devuelta = 0.00;
        for (let i = 1; i < LIQ_PAGOS_SPLIT.length; i++) LIQ_PAGOS_SPLIT[i].monto = 0.00;
        renderizarSplitLiq();
        recalcularCuadreLiq();
    }
}

function recalcularCuadreLiq() {
    if (LIQ_DIFERENCIA_OBJETIVO <= 0) return;

    let totalNeto = 0.00;
    LIQ_PAGOS_SPLIT.forEach(p => {
        totalNeto = round2(totalNeto + Math.max(0, (Number(p.monto) || 0) - (Number(p.monto_devuelta) || 0)));
    });

    document.getElementById('liqLblTotalSplit').textContent = fmtMoney(totalNeto);
    const dif = round2(totalNeto - LIQ_DIFERENCIA_OBJETIVO);
    const lbl = document.getElementById('liqLblEstadoCuadre');
    const btn = document.getElementById('btnProcesarLiquidacion');

    if (Math.abs(dif) <= 0.02) {
        lbl.innerHTML = `<span style="color: #2e7d52;">● Cuadre Exacto</span>`;
        if (btn) btn.disabled = false;
    } else if (dif < 0) {
        lbl.innerHTML = `<span style="color: #b53d32;">⚠️ Faltan ${fmtMoney(Math.abs(dif))}</span>`;
        if (btn) btn.disabled = true;
    } else {
        lbl.innerHTML = `<span style="color: #b78103;">⚠️ Excede por ${fmtMoney(dif)} (usa Devuelta si es efectivo)</span>`;
        if (btn) btn.disabled = true;
    }
}

async function guardarLiquidacionFinanciera(e) {
    e.preventDefault();
    if (procesandoAccionCsg) return;

    const csgId = parseInt(document.getElementById('liqConsignacionId')?.value, 10);
    const tipoNcf = document.getElementById('liqTipoNcf')?.value || 'B02';
    const almacenId = parseInt(document.getElementById('liqAlmacen')?.value, 10);
    const supervisorId = document.getElementById('liqSupervisor')?.value;
    const pin = document.getElementById('liqPin')?.value.trim();

    if (!csgId || !supervisorId || !pin) {
        alert('Debe autorizar la liquidación con el supervisor y su PIN.');
        return;
    }

    const btn = document.getElementById('btnProcesarLiquidacion');
    procesandoAccionCsg = true;
    btn.disabled = true;
    btn.textContent = 'Liquidando y emitiendo factura fiscal...';

    try {
        const resp = await fetch('procesar_consignaciones.php?accion=liquidar_consignacion', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                accion: 'liquidar_consignacion',
                consignacion_id: csgId,
                tipo_ncf: tipoNcf,
                almacen_id: almacenId,
                supervisor_id: supervisorId,
                pin_autorizacion: pin,
                pagos_diferencia: LIQ_PAGOS_SPLIT,
                reembolso_metodo: document.getElementById('liqReembolsoMetodo')?.value || 'EFECTIVO',
                reembolso_cuenta_id: parseInt(document.getElementById('liqReembolsoCuenta')?.value || '0', 10),
                reembolso_referencia: (document.getElementById('liqReembolsoRef')?.value || '').trim()
            })
        });
        const res = await leerJsonSeguro(resp);
        if (res.ok) {
            alert(res.mensaje);
            window.location.reload();
        } else {
            alert('Error en Liquidación: ' + res.mensaje);
        }
    } catch (err) {
        alert('Error de conexión al liquidar consignación: ' + err.message);
    } finally {
        procesandoAccionCsg = false;
        btn.disabled = false;
        btn.textContent = '🧾 Emitir Factura Fiscal y Cerrar Consignación';
    }
}

// ============================================================================
// MODAL DETALLE / EXPEDIENTE
// ============================================================================
async function abrirModalDetalle(csgId) {
    try {
        const resp = await fetch(`procesar_consignaciones.php?accion=obtener_detalle_consignacion&id=${encodeURIComponent(csgId)}`);
        const res = await leerJsonSeguro(resp);
        if (!res.ok) { alert(res.mensaje); return; }

        const csg = res.data.consignacion;
        const det = res.data.detalles || [];
        const fiscal = res.data.resumen_fiscal || {};
        const fac = res.data.factura;

        const itemsHtml = det.map(d => `
            <tr>
                <td><strong>${escHtml(d.producto_nombre)}</strong> (${escHtml(d.sku)})</td>
                <td><span class="code-inline" style="font-size: 0.74rem;">${escHtml(d.numero_lote)}</span></td>
                <td class="text-center">${d.cantidad_despachada}</td>
                <td class="text-center" style="color: #2e7d52; font-weight: 700;">${d.cantidad_devuelta}</td>
                <td class="text-center" style="color: #b53d32; font-weight: 800;">${d.cantidad_consumida}</td>
                <td class="text-right" style="font-family: var(--font-mono);">${fmtMoney(d.precio_pactado_unitario)}</td>
                <td class="text-right" style="font-family: var(--font-mono); font-weight: 700;">${fmtMoney(d.subtotal_consumido)}</td>
            </tr>`).join('');

        const facHtml = fac ? `
            <div style="background: #edf4fb; border: 1px solid #c7ddf2; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px;">
                <div style="font-size: 0.78rem; color: #1e6091; font-weight: 700; text-transform: uppercase;">Factura Fiscal Vinculada</div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px; flex-wrap: wrap; gap: 8px;">
                    <div><strong style="font-size: 1.05rem; color: #1e6091;">${escHtml(fac.numero_factura)}</strong> • NCF: <span class="code-inline">${escHtml(fac.ncf)}</span></div>
                    <div style="font-family: var(--font-mono); font-weight: 700; font-size: 1.08rem; color: #1e6091;">
                        ${fmtMoney(fac.monto_total)} <small style="font-weight: 500; color: #556;">(Base: ${fmtMoney(fac.monto_subtotal)} + Imp: ${fmtMoney(fac.monto_impuestos)})</small>
                    </div>
                </div>
            </div>` : '';

        document.getElementById('bodyDetalleConsignacion').innerHTML = `
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; background: #fdfaf6; border: 1px solid var(--border-light); border-radius: 8px; padding: 14px 18px; margin-bottom: 14px;">
                <div>
                    <h4 style="font-size: 1.08rem; margin-bottom: 4px; color: var(--text-dark);">${escHtml(csg.numero_consignacion)} • ${escHtml(csg.nombre_evento)}</h4>
                    <div style="font-size: 0.84rem; color: var(--text-muted);">
                        Cliente: <strong>${escHtml(csg.cliente_nombre)}</strong> (${escHtml(csg.cliente_rnc || 'S/D')})<br />
                        Responsable: ${escHtml(csg.responsable_evento)} • Tel: ${escHtml(csg.cliente_telefono || 'S/D')}<br />
                        Supervisor: 🛡️ ${escHtml(csg.supervisor_username)}
                    </div>
                </div>
                <div style="text-align: right;">
                    <span class="badge ${csg.estado === 'LIQUIDADA' ? 'badge-success' : 'badge-warning'}" style="font-size: 0.82rem;">${escHtml(csg.estado)}</span>
                    <div style="margin-top: 8px;">
                        <span style="font-size: 0.78rem; color: var(--text-muted);">Fianza en Garantía:</span><br />
                        <strong style="font-size: 1.2rem; font-family: var(--font-mono); color: #2e7d52;">${fmtMoney(csg.monto_deposito_garantia)}</strong>
                    </div>
                </div>
            </div>
            ${facHtml}
            <h4 style="font-size: 0.88rem; font-weight: 700; margin-bottom: 8px;">Artículos Despachados, Devueltos y Consumidos</h4>
            <div class="table-wrapper" style="max-height: 260px; overflow-y: auto;">
                <table class="data-table" style="font-size: 0.84rem;">
                    <thead>
                        <tr>
                            <th>Producto</th><th>Lote</th><th class="text-center">Desp.</th>
                            <th class="text-center">Dev.</th><th class="text-center">Cons.</th>
                            <th class="text-right">Precio Pactado</th><th class="text-right">Subtotal Base</th>
                        </tr>
                    </thead>
                    <tbody>${itemsHtml}</tbody>
                    <tfoot>
                        <tr style="background: #faf7f2; font-weight: 700;">
                            <td colspan="2">Totales (Base + Imp: ${fmtMoney(fiscal.impuestos_consumo)} = ${fmtMoney(fiscal.total_facturar)})</td>
                            <td class="text-center">${fiscal.botellas_despachadas || 0}</td>
                            <td class="text-center" style="color: #2e7d52;">${fiscal.botellas_devueltas || 0}</td>
                            <td class="text-center" style="color: #b53d32;">${fiscal.botellas_consumidas || 0}</td>
                            <td></td>
                            <td class="text-right" style="font-family: var(--font-mono);">${fmtMoney(fiscal.subtotal_consumo)}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>`;

        abrirModal('modalDetalleConsignacion');
    } catch (err) {
        alert('No se pudo abrir el detalle: ' + err.message);
    }
}