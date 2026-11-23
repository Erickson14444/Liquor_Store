/**
 * ============================================================================
 * Lógica Reactiva: Proceso 5 - Consignaciones (Eventos y Bodas)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/Consignaciones/consignaciones.js
 * ============================================================================
 */

let CARRITO_DESPACHO = []; // [{ producto_id, sku, nombre, lote_id, numero_lote, disponible, cantidad, precio, subtotal }]
let PRODUCTOS_BUSQUEDA_CACHE = [];
let SEARCH_TIMEOUT = null;
let CONSIGNACION_ACTUAL = null;

document.addEventListener('DOMContentLoaded', () => {
    // Cerrar dropdown de búsqueda al hacer clic fuera
    document.addEventListener('click', (e) => {
        const wrap = document.getElementById('csgResultadosBusqueda');
        const input = document.getElementById('csgInputBuscarProd');
        if (wrap && input && !wrap.contains(e.target) && e.target !== input) {
            wrap.style.display = 'none';
        }
    });
});

// ============================================================================
// NAVEGACIÓN ENTRE PESTAÑAS (TABS)
// ============================================================================
function cambiarTabConsignacion(tabId, btn) {
    document.querySelectorAll('.csg-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.csg-tab-content').forEach(c => c.classList.remove('active'));

    if (btn) btn.classList.add('active');
    const target = document.getElementById(`tab-${tabId}`);
    if (target) target.classList.add('active');
}

// ============================================================================
// FILTRADO DINÁMICO DE ACTIVAS
// ============================================================================
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
    if (badge) badge.textContent = `${visibles} eventos`;
}

// ============================================================================
// CONTROL DE MODALES
// ============================================================================
function abrirModal(modalId) {
    const m = document.getElementById(modalId);
    if (m) m.classList.add('active');
}

function cerrarModal(modalId) {
    const m = document.getElementById(modalId);
    if (m) m.classList.remove('active');
}

// ============================================================================
// FASE 1: NUEVA CONSIGNACIÓN Y DESPACHO
// ============================================================================
function abrirModalNuevaConsignacion() {
    CARRITO_DESPACHO = [];
    renderizarCarritoDespacho();

    document.getElementById('formNuevaConsignacion').reset();
    document.getElementById('csgMontoFianza').value = '0.00';
    document.getElementById('csgFechaRetorno').value = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

    abrirModal('modalNuevaConsignacion');
}

function alCambiarMontoFianza() {
    const fianza = parseFloat(document.getElementById('csgMontoFianza')?.value) || 0;
    const metodo = parseInt(document.getElementById('csgMetodoFianza')?.value, 10);
    const aviso = document.getElementById('lblAvisoFianzaCaja');

    if (aviso) {
        if (metodo === 1 && fianza > 0) {
            aviso.innerHTML = SESION_CAJA_ACTIVA 
                ? `🟢 Se registrará automáticamente un <strong>INGRESO de RD$ ${fianza.toFixed(2)}</strong> en ${SESION_CAJA_ACTIVA.nombre_equipo} para blindar el Arqueo Ciego.`
                : `⚠️ No tienes sesión de caja abierta en mostrador.`;
            aviso.style.color = SESION_CAJA_ACTIVA ? '#2e7d52' : '#b53d32';
        } else {
            aviso.innerHTML = `Fianza asentada como depósito contable de tesorería.`;
            aviso.style.color = '#794a15';
        }
    }
}

function alBuscarProductoCsg(e) {
    clearTimeout(SEARCH_TIMEOUT);
    const termino = e.target.value.trim();
    const wrap = document.getElementById('csgResultadosBusqueda');

    if (termino.length < 2) {
        wrap.style.display = 'none';
        return;
    }

    const almacenId = document.getElementById('csgAlmacen')?.value || 1;

    SEARCH_TIMEOUT = setTimeout(async () => {
        try {
            const resp = await fetch(`procesar_consignaciones.php?accion=buscar_productos&termino=${encodeURIComponent(termino)}&almacen_id=${almacenId}`);
            const res = await resp.json();

            if (res.status === 'success' && res.data.length > 0) {
                PRODUCTOS_BUSQUEDA_CACHE = res.data;
                let html = '';
                res.data.forEach(p => {
                    const dispColor = p.stock_disponible > 0 ? '#2e7d52' : '#b53d32';
                    html += `
                        <div class="csg-search-item" onclick="seleccionarProductoParaDespacho(${p.id})">
                            <div>
                                <strong style="font-size: 0.88rem; color: #251c16;">${p.nombre}</strong>
                                <div style="font-size: 0.74rem; color: #8c8173;">SKU: ${p.sku} • Precio Base: RD$ ${p.precio_base_unidad.toFixed(2)}</div>
                            </div>
                            <div style="text-align: right;">
                                <span style="font-size: 0.76rem; font-weight: 700; color: ${dispColor};">
                                    Disponible: ${p.stock_disponible} bot.
                                </span>
                                <div style="font-size: 0.72rem; color: #8c8173;">Comprometido: ${p.stock_comprometido}</div>
                            </div>
                        </div>
                    `;
                });
                wrap.innerHTML = html;
                wrap.style.display = 'block';
            } else {
                wrap.innerHTML = `<div style="padding: 12px; text-align: center; color: #8c8173; font-size: 0.82rem;">No se encontraron artículos con inventario disponible.</div>`;
                wrap.style.display = 'block';
            }
        } catch (err) {
            console.error('Error buscando productos:', err);
        }
    }, 250);
}

function seleccionarProductoParaDespacho(prodId) {
    const prod = PRODUCTOS_BUSQUEDA_CACHE.find(p => p.id === prodId);
    if (!prod) return;

    document.getElementById('csgResultadosBusqueda').style.display = 'none';
    document.getElementById('csgInputBuscarProd').value = '';

    // Seleccionar el primer lote disponible o lote 0
    let loteId = 0;
    let numLote = 'GENERICO - SIN LOTE';
    let loteDisp = prod.stock_disponible;

    if (prod.lotes && prod.lotes.length > 0) {
        const primerLote = prod.lotes[0];
        loteId = primerLote.lote_id;
        numLote = primerLote.numero_lote || 'LOTE-' + loteId;
        loteDisp = primerLote.disponible;
    }

    // Verificar si ya está en el carrito
    const existe = CARRITO_DESPACHO.find(item => item.producto_id === prod.id && item.lote_id === loteId);
    if (existe) {
        if (existe.cantidad + 1 <= existe.disponible) {
            existe.cantidad++;
            existe.subtotal = existe.cantidad * existe.precio;
        } else {
            alert(`No hay más stock disponible para este artículo (Disponible: ${existe.disponible}).`);
        }
    } else {
        CARRITO_DESPACHO.push({
            producto_id: prod.id,
            sku: prod.sku,
            nombre: prod.nombre,
            lote_id: loteId,
            numero_lote: numLote,
            disponible: loteDisp,
            cantidad: 1,
            precio: prod.precio_base_unidad,
            subtotal: prod.precio_base_unidad,
            lotes_disponibles: prod.lotes || []
        });
    }

    renderizarCarritoDespacho();
}

function renderizarCarritoDespacho() {
    const tbody = document.getElementById('csgTbodyCarrito');
    if (!tbody) return;

    if (CARRITO_DESPACHO.length === 0) {
        tbody.innerHTML = `
            <tr id="csgFilaVaciaCarrito">
                <td colspan="7" style="text-align: center; padding: 24px; color: var(--text-muted);">
                    No hay artículos agregados al despacho. Utiliza el buscador para agregar botellas.
                </td>
            </tr>
        `;
        actualizarTotalesDespacho();
        return;
    }

    let html = '';
    CARRITO_DESPACHO.forEach((item, idx) => {
        // Selector de lotes si tiene varios
        let lotesOptions = `<option value="0" ${item.lote_id === 0 ? 'selected' : ''}>GENERICO - SIN LOTE</option>`;
        if (item.lotes_disponibles && item.lotes_disponibles.length > 0) {
            lotesOptions = '';
            item.lotes_disponibles.forEach(l => {
                const sel = (l.lote_id === item.lote_id) ? 'selected' : '';
                lotesOptions += `<option value="${l.lote_id}" ${sel}>${l.numero_lote} (Disp: ${l.disponible})</option>`;
            });
        }

        html += `
            <tr>
                <td>
                    <strong>${item.nombre}</strong>
                    <div style="font-size: 0.74rem; color: #8c8173;">SKU: ${item.sku}</div>
                </td>
                <td>
                    <select class="form-control" style="font-size: 0.78rem; padding: 2px 6px;" onchange="alCambiarLoteItemDespacho(${idx}, this.value)">
                        ${lotesOptions}
                    </select>
                </td>
                <td class="text-center" style="font-weight: 700; color: #2e7d52;">
                    ${item.disponible} bot.
                </td>
                <td class="text-center">
                    <input type="number" min="1" max="${item.disponible}" value="${item.cantidad}" class="form-control" style="width: 80px; text-align: center; margin: 0 auto; font-weight: 700;" onchange="alCambiarCantidadItemDespacho(${idx}, this.value)" />
                </td>
                <td class="text-right">
                    <input type="number" step="0.01" min="0" value="${item.precio.toFixed(2)}" class="form-control" style="width: 100px; text-align: right; margin-left: auto; font-family: var(--font-mono); font-weight: 700;" onchange="alCambiarPrecioItemDespacho(${idx}, this.value)" />
                </td>
                <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: var(--text-dark);">
                    RD$ ${item.subtotal.toFixed(2)}
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline" style="color: #b53d32; border-color: #f9ccc7;" onclick="quitarItemDespacho(${idx})">✕</button>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
    actualizarTotalesDespacho();
}

function alCambiarLoteItemDespacho(idx, nuevoLoteId) {
    const item = CARRITO_DESPACHO[idx];
    if (!item) return;

    const loteNum = parseInt(nuevoLoteId, 10);
    item.lote_id = loteNum;

    const loteObj = item.lotes_disponibles.find(l => l.lote_id === loteNum);
    if (loteObj) {
        item.disponible = loteObj.disponible;
        item.numero_lote = loteObj.numero_lote;
        if (item.cantidad > item.disponible) {
            item.cantidad = item.disponible;
            item.subtotal = item.cantidad * item.precio;
        }
    }
    renderizarCarritoDespacho();
}

function alCambiarCantidadItemDespacho(idx, val) {
    const item = CARRITO_DESPACHO[idx];
    if (!item) return;

    let c = parseInt(val, 10);
    if (isNaN(c) || c <= 0) c = 1;
    if (c > item.disponible) {
        alert(`La cantidad no puede superar el disponible (${item.disponible}).`);
        c = item.disponible;
    }
    item.cantidad = c;
    item.subtotal = item.cantidad * item.precio;
    renderizarCarritoDespacho();
}

function alCambiarPrecioItemDespacho(idx, val) {
    const item = CARRITO_DESPACHO[idx];
    if (!item) return;

    let p = parseFloat(val);
    if (isNaN(p) || p < 0) p = 0;
    item.precio = p;
    item.subtotal = item.cantidad * item.precio;
    renderizarCarritoDespacho();
}

function quitarItemDespacho(idx) {
    CARRITO_DESPACHO.splice(idx, 1);
    renderizarCarritoDespacho();
}

function limpiarCarritoDespacho() {
    CARRITO_DESPACHO = [];
    renderizarCarritoDespacho();
}

function actualizarTotalesDespacho() {
    let botellas = 0;
    let valor = 0;

    CARRITO_DESPACHO.forEach(item => {
        botellas += item.cantidad;
        valor += item.subtotal;
    });

    const txtBot = document.getElementById('csgTxtTotalBotellas');
    const txtVal = document.getElementById('csgTxtTotalValor');

    if (txtBot) txtBot.textContent = botellas.toString();
    if (txtVal) txtVal.textContent = 'RD$ ' + valor.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function guardarNuevaConsignacion(e) {
    e.preventDefault();

    if (CARRITO_DESPACHO.length === 0) {
        alert('Debe agregar al menos un artículo para el despacho.');
        return;
    }

    const clienteId = document.getElementById('csgCliente')?.value;
    const evento = document.getElementById('csgNombreEvento')?.value.trim();
    const responsable = document.getElementById('csgResponsable')?.value.trim();
    const fechaRetorno = document.getElementById('csgFechaRetorno')?.value;
    const almacenId = parseInt(document.getElementById('csgAlmacen')?.value, 10);
    const montoFianza = parseFloat(document.getElementById('csgMontoFianza')?.value) || 0;
    const metodoFianza = parseInt(document.getElementById('csgMetodoFianza')?.value, 10);
    const supervisorId = document.getElementById('csgSupervisor')?.value;
    const pin = document.getElementById('csgPin')?.value.trim();

    if (!clienteId || !evento || !responsable || !fechaRetorno || !supervisorId || !pin) {
        alert('Por favor complete todos los campos obligatorios y la autorización del supervisor.');
        return;
    }

    const payload = {
        cliente_id: clienteId,
        nombre_evento: evento,
        responsable_evento: responsable,
        fecha_estimada_retorno: fechaRetorno,
        almacen_id: almacenId,
        monto_deposito_garantia: montoFianza,
        metodo_garantia_id: metodoFianza,
        supervisor_id: supervisorId,
        pin_autorizacion: pin,
        items: CARRITO_DESPACHO.map(i => ({
            producto_maestro_id: i.producto_id,
            lote_id: i.lote_id,
            cantidad_despachada: i.cantidad,
            precio_pactado_unitario: i.precio
        }))
    };

    const btn = document.getElementById('btnGuardarNuevaConsignacion');
    btn.disabled = true;
    btn.innerHTML = 'Procesando despacho y reserva de stock...';

    try {
        const resp = await fetch('procesar_consignaciones.php?accion=crear_consignacion', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const res = await resp.json();

        if (res.status === 'success') {
            alert(res.message || 'Consignación creada exitosamente.');
            window.location.reload();
        } else {
            alert('Error en Consignación: ' + (res.message || 'No se pudo crear.'));
            btn.disabled = false;
            btn.innerHTML = '<span>📦</span> Despachar y Reservar Stock';
        }
    } catch (err) {
        console.error('Error al guardar:', err);
        alert('Ocurrió un error inesperado al procesar la consignación.');
        btn.disabled = false;
        btn.innerHTML = '<span>📦</span> Despachar y Reservar Stock';
    }
}

// ============================================================================
// FASE 2: REGISTRO DE RETORNO FÍSICO
// ============================================================================
async function abrirModalRetorno(csgId) {
    try {
        const resp = await fetch(`procesar_consignaciones.php?accion=obtener_detalle_consignacion&id=${csgId}`);
        const res = await resp.json();

        if (res.status !== 'success') {
            alert(res.message || 'Error al cargar expediente.');
            return;
        }

        const csg = res.data.consignacion;
        const det = res.data.detalles;

        document.getElementById('retConsignacionId').value = csg.id;

        document.getElementById('retCabeceraInfo').innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h4 style="font-size: 1rem; color: #251c16; margin-bottom: 4px;">
                        Expediente: <span class="code-inline" style="font-weight: 700;">${csg.numero_consignacion}</span> • ${csg.nombre_evento}
                    </h4>
                    <div style="font-size: 0.8rem; color: #8c8173;">
                        Cliente: <strong>${csg.cliente_nombre}</strong> (${csg.cliente_rnc || 'S/D'}) • Contacto: ${csg.responsable_evento}
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 0.78rem; color: #8c8173;">Fianza en Garantía:</div>
                    <strong style="font-size: 1.1rem; color: #2e7d52; font-family: var(--font-mono);">RD$ ${parseFloat(csg.monto_deposito_garantia).toFixed(2)}</strong>
                </div>
            </div>
        `;

        let html = '';
        det.forEach((d, idx) => {
            html += `
                <tr class="fila-retorno-articulo" 
                    data-prod-id="${d.producto_maestro_id}" 
                    data-lote-id="${d.lote_id}" 
                    data-despachada="${d.cantidad_despachada}" 
                    data-precio="${d.precio_pactado_unitario}">
                    <td>
                        <strong>${d.producto_nombre}</strong>
                        <div style="font-size: 0.74rem; color: #8c8173;">SKU: ${d.sku}</div>
                    </td>
                    <td><span class="code-inline" style="font-size: 0.76rem;">${d.numero_lote}</span></td>
                    <td class="text-center" style="font-weight: 700; color: #1e6091;">
                        ${d.cantidad_despachada}
                    </td>
                    <td class="text-center">
                        <input type="number" min="0" max="${d.cantidad_despachada}" value="${d.cantidad_devuelta}" class="form-control input-cant-devuelta" style="width: 85px; text-align: center; margin: 0 auto; font-weight: 700; border-color: #a66e2e;" oninput="alCambiarInputRetorno()" />
                    </td>
                    <td class="text-center" style="font-weight: 800; color: #b53d32; font-size: 0.95rem;">
                        <span class="txt-cant-consumida">${d.cantidad_consumida}</span>
                    </td>
                    <td class="text-right" style="font-family: var(--font-mono);">
                        RD$ ${parseFloat(d.precio_pactado_unitario).toFixed(2)}
                    </td>
                    <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: var(--text-dark);">
                        <span class="txt-subtotal-consumo">RD$ ${(d.cantidad_consumida * d.precio_pactado_unitario).toFixed(2)}</span>
                    </td>
                </tr>
            `;
        });

        document.getElementById('retTbodyArticulos').innerHTML = html;
        alCambiarInputRetorno();

        abrirModal('modalRetornoFisico');
    } catch (err) {
        console.error('Error al abrir retorno:', err);
        alert('No se pudo abrir el registro de retorno.');
    }
}

function alCambiarInputRetorno() {
    const filas = document.querySelectorAll('.fila-retorno-articulo');
    let totalDev = 0;
    let totalCons = 0;
    let totalMontoCons = 0;

    filas.forEach(f => {
        const desp = parseInt(f.getAttribute('data-despachada'), 10);
        const precio = parseFloat(f.getAttribute('data-precio'));
        const input = f.querySelector('.input-cant-devuelta');
        let dev = parseInt(input?.value, 10);

        if (isNaN(dev) || dev < 0) dev = 0;
        if (dev > desp) {
            dev = desp;
            if (input) input.value = desp;
        }

        const cons = desp - dev;
        const sub = cons * precio;

        const txtCons = f.querySelector('.txt-cant-consumida');
        const txtSub = f.querySelector('.txt-subtotal-consumo');

        if (txtCons) txtCons.textContent = cons.toString();
        if (txtSub) txtSub.textContent = 'RD$ ' + sub.toFixed(2);

        totalDev += dev;
        totalCons += cons;
        totalMontoCons += sub;
    });

    document.getElementById('retTxtTotalDevueltas').textContent = totalDev.toString();
    document.getElementById('retTxtTotalConsumidas').textContent = totalCons.toString();
    document.getElementById('retTxtTotalConsumoMonto').textContent = 'RD$ ' + totalMontoCons.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function guardarRetornoFisico(e) {
    e.preventDefault();

    const csgId = parseInt(document.getElementById('retConsignacionId')?.value, 10);
    const supervisorId = document.getElementById('retSupervisor')?.value;
    const pin = document.getElementById('retPin')?.value.trim();

    if (!csgId || !supervisorId || !pin) {
        alert('Debe completar la autorización del supervisor con su PIN.');
        return;
    }

    const retornos = [];
    document.querySelectorAll('.fila-retorno-articulo').forEach(f => {
        retornos.push({
            producto_maestro_id: parseInt(f.getAttribute('data-prod-id'), 10),
            lote_id: parseInt(f.getAttribute('data-lote-id'), 10),
            cantidad_devuelta: parseInt(f.querySelector('.input-cant-devuelta')?.value || 0, 10)
        });
    });

    const btn = document.getElementById('btnGuardarRetorno');
    btn.disabled = true;
    btn.innerHTML = 'Asentando conteo físico...';

    try {
        const resp = await fetch('procesar_consignaciones.php?accion=registrar_retorno', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                consignacion_id: csgId,
                supervisor_id: supervisorId,
                pin_autorizacion: pin,
                retornos: retornos
            })
        });
        const res = await resp.json();

        if (res.status === 'success') {
            alert(res.message || 'Conteo físico asentado correctamente.');
            window.location.reload();
        } else {
            alert('Error en Retorno: ' + (res.message || 'No se pudo guardar.'));
            btn.disabled = false;
            btn.innerHTML = '<span>💾</span> Asentar Conteo y Actualizar Consumo';
        }
    } catch (err) {
        console.error('Error guardando retorno:', err);
        alert('Ocurrió un error inesperado al registrar el retorno.');
        btn.disabled = false;
        btn.innerHTML = '<span>💾</span> Asentar Conteo y Actualizar Consumo';
    }
}

// ============================================================================
// FASE 3: LIQUIDACIÓN FINANCIERA, FACTURA Y SPLIT
// ============================================================================
async function abrirModalLiquidacion(csgId) {
    try {
        const resp = await fetch(`procesar_consignaciones.php?accion=obtener_detalle_consignacion&id=${csgId}`);
        const res = await resp.json();

        if (res.status !== 'success') {
            alert(res.message || 'Error al cargar expediente.');
            return;
        }

        CONSIGNACION_ACTUAL = res.data;
        const csg = res.data.consignacion;
        const det = res.data.detalles;

        let totalConsumido = 0;
        det.forEach(d => {
            totalConsumido += parseFloat(d.subtotal_consumido) || 0;
        });

        const fianza = parseFloat(csg.monto_deposito_garantia) || 0;

        document.getElementById('liqConsignacionId').value = csg.id;
        document.getElementById('liqTxtTotalConsumido').textContent = 'RD$ ' + totalConsumido.toFixed(2);
        document.getElementById('liqTxtMontoFianza').textContent = 'RD$ ' + fianza.toFixed(2);

        const secSplit = document.getElementById('liqSeccionSplitDiferencia');
        const secReembolso = document.getElementById('liqSeccionReembolso');
        const lblBal = document.getElementById('liqLblBalance');
        const txtBal = document.getElementById('liqTxtBalanceDiferencia');

        if (totalConsumido > fianza) {
            const dif = totalConsumido - fianza;
            lblBal.textContent = 'Diferencia a Cobrar';
            txtBal.textContent = 'RD$ ' + dif.toFixed(2);
            txtBal.style.color = '#b53d32';

            secSplit.style.display = 'block';
            secReembolso.style.display = 'none';
            document.getElementById('liqTxtMontoDiferenciaSpan').textContent = 'RD$ ' + dif.toFixed(2);

            // Iniciar split con un método sugerido por el total de la diferencia
            document.getElementById('liqContenedorSplit').innerHTML = '';
            agregarFilaSplitLiq(dif);
        } else if (totalConsumido < fianza) {
            const sobrante = fianza - totalConsumido;
            lblBal.textContent = 'Excedente a Devolver';
            txtBal.textContent = 'RD$ ' + sobrante.toFixed(2);
            txtBal.style.color = '#2e7d52';

            secSplit.style.display = 'none';
            secReembolso.style.display = 'block';
            document.getElementById('liqTxtReembolsoMonto').textContent = 'RD$ ' + sobrante.toFixed(2);
        } else {
            lblBal.textContent = 'Cuadre Exacto con Fianza';
            txtBal.textContent = 'RD$ 0.00';
            txtBal.style.color = '#2e7d52';

            secSplit.style.display = 'none';
            secReembolso.style.display = 'none';
        }

        abrirModal('modalLiquidacionFinanciera');
    } catch (err) {
        console.error('Error abriendo liquidación:', err);
        alert('No se pudo preparar la liquidación.');
    }
}

function agregarFilaSplitLiq(montoSugerido = 0) {
    const wrap = document.getElementById('liqContenedorSplit');
    if (!wrap) return;

    let metOptions = '';
    CATALOGO_METODOS.forEach(m => {
        metOptions += `<option value="${m.id}">${m.descripcion}</option>`;
    });

    let bancoOptions = '';
    CATALOGO_BANCOS.forEach(b => {
        bancoOptions += `<option value="${b.id}">${b.nombre_banco} (${b.numero_cuenta})</option>`;
    });

    const div = document.createElement('div');
    div.className = 'fila-split-liq';
    div.style.cssText = 'display: grid; grid-template-columns: 160px 1fr 140px 36px; gap: 8px; align-items: center; margin-bottom: 8px;';
    div.innerHTML = `
        <select class="form-control sel-split-metodo" onchange="alCambiarMetodoSplitLiq(this)">
            ${metOptions}
        </select>
        <div class="wrap-split-subtipo">
            <span style="font-size: 0.78rem; color: #8c8173;">Efectivo en mostrador (impacta caja activa)</span>
        </div>
        <input type="number" step="0.01" min="0.01" value="${montoSugerido > 0 ? montoSugerido.toFixed(2) : ''}" placeholder="0.00" class="form-control input-split-monto" style="font-family: var(--font-mono); font-weight: 700; text-align: right;" />
        <button type="button" class="btn btn-sm btn-outline" style="color: #b53d32;" onclick="this.closest('.fila-split-liq').remove()">✕</button>
    `;

    wrap.appendChild(div);
}

function alCambiarMetodoSplitLiq(sel) {
    const fila = sel.closest('.fila-split-liq');
    const wrap = fila.querySelector('.wrap-split-subtipo');
    const metodoId = parseInt(sel.value, 10);

    if (metodoId === 1) {
        wrap.innerHTML = `<span style="font-size: 0.78rem; color: #8c8173;">Efectivo en mostrador (impacta caja activa)</span>`;
    } else if (metodoId === 2) {
        wrap.innerHTML = `
            <div style="display: flex; gap: 4px;">
                <input type="text" placeholder="Últimos 4" maxlength="4" class="form-control split-tarj-4" style="width: 80px;" />
                <input type="text" placeholder="No. Autorización" class="form-control split-tarj-aut" style="flex: 1;" />
            </div>
        `;
    } else if (metodoId === 3) {
        let bancoOptions = '';
        CATALOGO_BANCOS.forEach(b => {
            bancoOptions += `<option value="${b.id}">${b.nombre_banco} (${b.numero_cuenta})</option>`;
        });
        wrap.innerHTML = `
            <div style="display: flex; gap: 4px;">
                <select class="form-control split-transf-banco" style="flex: 1;">${bancoOptions}</select>
                <input type="text" placeholder="Ref. Bancaria" class="form-control split-transf-ref" style="width: 100px;" />
            </div>
        `;
    } else {
        wrap.innerHTML = `<input type="text" placeholder="No. Cheque / Doc" class="form-control split-cheque-ref" />`;
    }
}

async function guardarLiquidacionFinanciera(e) {
    e.preventDefault();

    const csgId = parseInt(document.getElementById('liqConsignacionId')?.value, 10);
    const tipoNcf = document.getElementById('liqTipoNcf')?.value || 'B02';
    const almacenId = parseInt(document.getElementById('liqAlmacen')?.value, 10);
    const supervisorId = document.getElementById('liqSupervisor')?.value;
    const pin = document.getElementById('liqPin')?.value.trim();

    if (!csgId || !supervisorId || !pin) {
        alert('Debe autorizar la liquidación con el supervisor y su PIN.');
        return;
    }

    const pagosDiferencia = [];
    document.querySelectorAll('.fila-split-liq').forEach(f => {
        const metId = parseInt(f.querySelector('.sel-split-metodo')?.value, 10);
        const monto = parseFloat(f.querySelector('.input-split-monto')?.value) || 0;
        if (monto > 0) {
            pagosDiferencia.push({
                metodo_pago_id: metId,
                monto: monto,
                ultimos_cuatro: f.querySelector('.split-tarj-4')?.value || '0000',
                numero_autorizacion: f.querySelector('.split-tarj-aut')?.value || 'AUT-POS',
                cuenta_bancaria_id: f.querySelector('.split-transf-banco')?.value || 1,
                numero_referencia: f.querySelector('.split-transf-ref')?.value || 'REF-CSG'
            });
        }
    });

    const btn = document.getElementById('btnProcesarLiquidacion');
    btn.disabled = true;
    btn.innerHTML = 'Liquidando y emitiendo factura fiscal...';

    try {
        const resp = await fetch('procesar_consignaciones.php?accion=liquidar_consignacion', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                consignacion_id: csgId,
                tipo_ncf: tipoNcf,
                almacen_id: almacenId,
                supervisor_id: supervisorId,
                pin_autorizacion: pin,
                pagos_diferencia: pagosDiferencia
            })
        });
        const res = await resp.json();

        if (res.status === 'success') {
            alert(res.message || 'Liquidación completada exitosamente.');
            window.location.reload();
        } else {
            alert('Error en Liquidación: ' + (res.message || 'No se pudo liquidar.'));
            btn.disabled = false;
            btn.innerHTML = '<span>🧾</span> Emitir Factura Fiscal y Cerrar Consignación';
        }
    } catch (err) {
        console.error('Error en liquidación:', err);
        alert('Ocurrió un error inesperado al liquidar la consignación.');
        btn.disabled = false;
        btn.innerHTML = '<span>🧾</span> Emitir Factura Fiscal y Cerrar Consignación';
    }
}

// ============================================================================
// MODAL DETALLE / EXPEDIENTE
// ============================================================================
async function abrirModalDetalle(csgId) {
    try {
        const resp = await fetch(`procesar_consignaciones.php?accion=obtener_detalle_consignacion&id=${csgId}`);
        const res = await resp.json();

        if (res.status !== 'success') {
            alert(res.message || 'Error al cargar expediente.');
            return;
        }

        const csg = res.data.consignacion;
        const det = res.data.detalles;
        const fac = res.data.factura;

        let itemsHtml = '';
        let totalDesp = 0;
        let totalDev = 0;
        let totalCons = 0;
        let totalMontoCons = 0;

        det.forEach(d => {
            totalDesp += parseInt(d.cantidad_despachada, 10);
            totalDev += parseInt(d.cantidad_devuelta, 10);
            totalCons += parseInt(d.cantidad_consumida, 10);
            totalMontoCons += parseFloat(d.subtotal_consumido) || 0;

            itemsHtml += `
                <tr>
                    <td><strong>${d.producto_nombre}</strong> (${d.sku})</td>
                    <td><span class="code-inline" style="font-size: 0.74rem;">${d.numero_lote}</span></td>
                    <td class="text-center">${d.cantidad_despachada}</td>
                    <td class="text-center" style="color: #2e7d52; font-weight: 700;">${d.cantidad_devuelta}</td>
                    <td class="text-center" style="color: #b53d32; font-weight: 800;">${d.cantidad_consumida}</td>
                    <td class="text-right" style="font-family: var(--font-mono);">RD$ ${parseFloat(d.precio_pactado_unitario).toFixed(2)}</td>
                    <td class="text-right" style="font-family: var(--font-mono); font-weight: 700;">RD$ ${parseFloat(d.subtotal_consumido).toFixed(2)}</td>
                </tr>
            `;
        });

        let facHtml = '';
        if (fac) {
            facHtml = `
                <div style="background: #edf4fb; border: 1px solid #c7ddf2; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px;">
                    <div style="font-size: 0.78rem; color: #1e6091; font-weight: 700; text-transform: uppercase;">Factura Fiscal Vinculada</div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
                        <div>
                            <strong style="font-size: 1.05rem; color: #1e6091;">${fac.numero_factura}</strong> • NCF: <span class="code-inline">${fac.ncf}</span>
                        </div>
                        <div style="font-family: var(--font-mono); font-weight: 700; font-size: 1.1rem; color: #1e6091;">
                            RD$ ${parseFloat(fac.monto_total).toFixed(2)}
                        </div>
                    </div>
                </div>
            `;
        }

        document.getElementById('bodyDetalleConsignacion').innerHTML = `
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; background: #fdfaf6; border: 1px solid var(--border-light); border-radius: 8px; padding: 14px 18px; margin-bottom: 14px;">
                <div>
                    <h4 style="font-size: 1.1rem; margin-bottom: 4px; color: var(--text-dark);">
                        ${csg.numero_consignacion} • ${csg.nombre_evento}
                    </h4>
                    <div style="font-size: 0.84rem; color: var(--text-muted);">
                        Cliente: <strong>${csg.cliente_nombre}</strong> (${csg.cliente_rnc || 'S/D'})<br />
                        Responsable: ${csg.responsable_evento} • Tel: ${csg.cliente_telefono || 'S/D'}<br />
                        Supervisor Autorizante: 🛡️ ${csg.supervisor_username}
                    </div>
                </div>
                <div style="text-align: right;">
                    <span class="badge ${csg.estado === 'LIQUIDADA' ? 'badge-success' : 'badge-warning'}" style="font-size: 0.82rem;">${csg.estado}</span>
                    <div style="margin-top: 8px;">
                        <span style="font-size: 0.78rem; color: var(--text-muted);">Fianza en Garantía:</span><br />
                        <strong style="font-size: 1.25rem; font-family: var(--font-mono); color: #2e7d52;">RD$ ${parseFloat(csg.monto_deposito_garantia).toFixed(2)}</strong>
                    </div>
                </div>
            </div>

            ${facHtml}

            <h4 style="font-size: 0.88rem; font-weight: 700; margin-bottom: 8px;">Artículos Despachados, Devueltos y Consumidos</h4>
            <div class="table-wrapper" style="max-height: 250px; overflow-y: auto; margin-bottom: 14px;">
                <table class="data-table" style="font-size: 0.84rem;">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Lote</th>
                            <th class="text-center">Despachadas</th>
                            <th class="text-center">Devueltas</th>
                            <th class="text-center">Consumidas</th>
                            <th class="text-right">Precio Pactado</th>
                            <th class="text-right">Subtotal Consumo</th>
                        </tr>
                    </thead>
                    <tbody>${itemsHtml}</tbody>
                    <tfoot>
                        <tr style="background: #faf7f2; font-weight: 700;">
                            <td colspan="2">Totales</td>
                            <td class="text-center">${totalDesp}</td>
                            <td class="text-center" style="color: #2e7d52;">${totalDev}</td>
                            <td class="text-center" style="color: #b53d32;">${totalCons}</td>
                            <td></td>
                            <td class="text-right" style="font-family: var(--font-mono); font-size: 0.95rem;">RD$ ${totalMontoCons.toFixed(2)}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        `;

        abrirModal('modalDetalleConsignacion');
    } catch (err) {
        console.error('Error cargando detalle:', err);
        alert('No se pudo abrir el detalle de la consignación.');
    }
}
