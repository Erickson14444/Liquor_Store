/**
 * ============================================================================
 * Lógica Reactiva: Proceso 8 - Notas de Débito (NCF B03)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/NotasDebito/notas_debito.js
 * ============================================================================
 */

let LINEAS_ND = [];
let FACTURA_ORIGEN_SELECCIONADA = null;
let TIMER_BUSCADOR_FAC = null;

document.addEventListener('DOMContentLoaded', () => {
    filtrarTablaNd();
});

// ============================================================================
// FILTRADO DINÁMICO DE NOTAS DE DÉBITO
// ============================================================================
function filtrarTablaNd() {
    const texto = (document.getElementById('filtroTexto')?.value || '').toLowerCase().trim();
    const estado = document.getElementById('filtroEstado')?.value || 'TODOS';

    const filas = document.querySelectorAll('.fila-nd');
    let visibles = 0;

    filas.forEach(f => {
        const ncf = (f.getAttribute('data-ncf') || '').toLowerCase();
        const ncfMod = (f.getAttribute('data-ncf-mod') || '').toLowerCase();
        const fac = (f.getAttribute('data-factura') || '').toLowerCase();
        const cli = (f.getAttribute('data-cliente') || '').toLowerCase();
        const doc = (f.getAttribute('data-doc') || '').toLowerCase();
        const con = (f.getAttribute('data-concepto') || '').toLowerCase();
        const rowEstado = f.getAttribute('data-estado') || '';

        const coincideTexto = !texto || ncf.includes(texto) || ncfMod.includes(texto) ||
                              fac.includes(texto) || cli.includes(texto) || doc.includes(texto) || con.includes(texto);

        let coincideEstado = true;
        if (estado === 'PENDIENTE') {
            coincideEstado = ['PENDIENTE', 'PAGADA_PARCIAL'].includes(rowEstado);
        } else if (estado === 'PAGADA') {
            coincideEstado = rowEstado === 'PAGADA';
        }

        if (coincideTexto && coincideEstado) {
            f.style.display = '';
            visibles++;
        } else {
            f.style.display = 'none';
        }
    });

    const badge = document.getElementById('badgeTotalNd');
    if (badge) badge.textContent = `${visibles} registros`;
}

function limpiarFiltrosNd() {
    const txt = document.getElementById('filtroTexto');
    const est = document.getElementById('filtroEstado');
    if (txt) txt.value = '';
    if (est) est.value = 'TODOS';
    filtrarTablaNd();
}

// ============================================================================
// MODAL DE EMISIÓN DE NOTA DE DÉBITO
// ============================================================================
function abrirModalEmitirNd() {
    deseleccionarFactura();
    document.getElementById('formEmitirNd')?.reset();
    LINEAS_ND = [];
    agregarLineaCargo();
    abrirModal('modalEmitirNd');
}

function abrirModal(id) {
    const m = document.getElementById(id);
    if (m) m.classList.add('active');
}

function cerrarModal(id) {
    const m = document.getElementById(id);
    if (m) m.classList.remove('active');
}

// ============================================================================
// BÚSQUEDA Y SELECCIÓN DE FACTURA ORIGEN
// ============================================================================
function buscarFacturasOrigen() {
    clearTimeout(TIMER_BUSCADOR_FAC);
    const q = document.getElementById('inputBuscarFactura')?.value.trim();
    const box = document.getElementById('resultadosBusquedaFacturas');
    if (!box) return;

    if (q.length < 2) {
        box.style.display = 'none';
        box.innerHTML = '';
        return;
    }

    TIMER_BUSCADOR_FAC = setTimeout(async () => {
        try {
            const resp = await fetch(`procesar_notas_debito.php?accion=buscar_facturas&termino=${encodeURIComponent(q)}`);
            const json = await resp.json();
            if (json.status !== 'success' || !json.data || json.data.length === 0) {
                box.innerHTML = '<div style="padding: 10px 14px; font-size: 0.84rem; color: #888;">No se encontraron facturas que coincidan.</div>';
                box.style.display = 'block';
                return;
            }

            let html = '';
            json.data.forEach(fac => {
                const facJson = JSON.stringify(fac).replace(/"/g, '&quot;');
                html += `
                    <div style="padding: 9px 14px; border-bottom: 1px solid #eee; cursor: pointer; display: flex; justify-content: space-between; align-items: center;"
                         onmouseover="this.style.background='#fcf8f2'" onmouseout="this.style.background='#fff'"
                         onclick="seleccionarFactura(${facJson})">
                        <div>
                            <strong>Factura #${fac.numero_factura}</strong> • <span class="badge-ncf-mod">${fac.ncf}</span>
                            <div style="font-size: 0.78rem; color: #555;">${fac.cliente_nombre} (${fac.cliente_doc || 'Sin Doc'})</div>
                        </div>
                        <div style="text-align: right;">
                            <strong style="color: #a66e2e;">RD$ ${parseFloat(fac.monto_total).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong>
                            <div style="font-size: 0.72rem; color: #888;">${fac.fecha_fmt}</div>
                        </div>
                    </div>
                `;
            });

            box.innerHTML = html;
            box.style.display = 'block';
        } catch (e) {
            console.error("Error buscando facturas:", e);
        }
    }, 280);
}

function seleccionarFactura(fac) {
    FACTURA_ORIGEN_SELECCIONADA = fac;
    document.getElementById('ndFacturaId').value = fac.id;
    document.getElementById('txtFacNumero').textContent = fac.numero_factura;
    document.getElementById('txtFacNcf').textContent = fac.ncf;
    document.getElementById('txtFacCliente').textContent = fac.cliente_nombre;
    document.getElementById('txtFacDoc').textContent = fac.cliente_doc || 'Sin Doc';
    document.getElementById('txtFacFecha').textContent = fac.fecha_fmt;
    document.getElementById('txtFacMonto').textContent = parseFloat(fac.monto_total).toLocaleString('en-US', {minimumFractionDigits: 2});

    document.getElementById('cardFacturaSeleccionada').classList.add('active');
    document.getElementById('resultadosBusquedaFacturas').style.display = 'none';
    document.getElementById('inputBuscarFactura').value = '';

    // Re-renderizar opciones de productos en las líneas existentes
    renderizarLineasNd();
}

function deseleccionarFactura() {
    FACTURA_ORIGEN_SELECCIONADA = null;
    const fId = document.getElementById('ndFacturaId');
    if (fId) fId.value = '';
    const card = document.getElementById('cardFacturaSeleccionada');
    if (card) card.classList.remove('active');
    renderizarLineasNd();
}

// Preset de conceptos
function aplicarPresetConcepto() {
    const preset = document.getElementById('selectPresetConcepto')?.value;
    const txt = document.getElementById('ndConceptoCargo');
    if (!txt || !preset) return;
    if (preset !== 'OTRO') {
        txt.value = preset;
    } else {
        txt.value = '';
        txt.focus();
    }
}

// ============================================================================
// LÍNEAS DE CARGOS DINÁMICAS
// ============================================================================
function agregarLineaCargo(desc = '', precio = 0.00, cant = 1, llevaItbis = true, prodId = null) {
    LINEAS_ND.push({
        id_temp: Date.now() + Math.random(),
        descripcion_cargo: desc,
        producto_maestro_id: prodId,
        cantidad: cant,
        precio_unitario: precio,
        aplicar_itbis: llevaItbis
    });
    renderizarLineasNd();
}

function eliminarLineaCargo(idTemp) {
    if (LINEAS_ND.length <= 1) {
        alert("Debe mantener al menos una línea de cargo en la Nota de Débito.");
        return;
    }
    LINEAS_ND = LINEAS_ND.filter(l => l.id_temp !== idTemp);
    renderizarLineasNd();
}

function actualizarCampoLinea(idTemp, campo, valor) {
    const lin = LINEAS_ND.find(l => l.id_temp === idTemp);
    if (!lin) return;

    if (campo === 'cantidad') {
        lin.cantidad = Math.max(1, parseInt(valor, 10) || 1);
    } else if (campo === 'precio_unitario') {
        lin.precio_unitario = Math.max(0, parseFloat(valor) || 0);
    } else if (campo === 'aplicar_itbis') {
        lin.aplicar_itbis = Boolean(valor);
    } else if (campo === 'descripcion_cargo') {
        lin.descripcion_cargo = valor;
    } else if (campo === 'producto_maestro_id') {
        lin.producto_maestro_id = valor ? parseInt(valor, 10) : null;
    }

    recalcularTotalesNd();
}

function renderizarLineasNd() {
    const wrap = document.getElementById('contenedorLineasNd');
    if (!wrap) return;

    if (LINEAS_ND.length === 0) {
        agregarLineaCargo();
        return;
    }

    // Opciones de productos si hay factura seleccionada
    let optsProd = '<option value="">-- Sin vincular --</option>';
    if (FACTURA_ORIGEN_SELECCIONADA && Array.isArray(FACTURA_ORIGEN_SELECCIONADA.detalles)) {
        FACTURA_ORIGEN_SELECCIONADA.detalles.forEach(d => {
            optsProd += `<option value="${d.producto_maestro_id}">${d.nombre} (${d.sku})</option>`;
        });
    }

    let html = '';
    LINEAS_ND.forEach((lin, idx) => {
        const subt = lin.cantidad * lin.precio_unitario;
        const itbis = lin.aplicar_itbis ? (subt * 0.18) : 0;
        const tot = subt + itbis;

        html += `
            <div class="nd-line-item">
                <div>
                    <input type="text" class="form-control" style="font-size: 0.85rem;"
                           placeholder="Ej: Comisión bancaria por cheque devuelto..."
                           value="${lin.descripcion_cargo.replace(/"/g, '&quot;')}"
                           oninput="actualizarCampoLinea(${lin.id_temp}, 'descripcion_cargo', this.value)" required />
                </div>
                <div>
                    <select class="form-control" style="font-size: 0.82rem;"
                            onchange="actualizarCampoLinea(${lin.id_temp}, 'producto_maestro_id', this.value)">
                        ${optsProd.replace(`value="${lin.producto_maestro_id}"`, `value="${lin.producto_maestro_id}" selected`)}
                    </select>
                </div>
                <div>
                    <input type="number" class="form-control text-center" min="1" step="1"
                           value="${lin.cantidad}"
                           oninput="actualizarCampoLinea(${lin.id_temp}, 'cantidad', this.value)" />
                </div>
                <div>
                    <input type="number" class="form-control text-right" min="0" step="0.01"
                           value="${lin.precio_unitario ? lin.precio_unitario.toFixed(2) : ''}"
                           placeholder="0.00"
                           oninput="actualizarCampoLinea(${lin.id_temp}, 'precio_unitario', this.value)" required />
                </div>
                <div class="text-center" style="display: flex; align-items: center; justify-content: center; gap: 4px;">
                    <input type="checkbox" id="chkItbis_${lin.id_temp}"
                           ${lin.aplicar_itbis ? 'checked' : ''}
                           onchange="actualizarCampoLinea(${lin.id_temp}, 'aplicar_itbis', this.checked)" />
                    <label for="chkItbis_${lin.id_temp}" style="font-size: 0.74rem; cursor: pointer;">18%</label>
                </div>
                <div class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: #251c16; font-size: 0.9rem;">
                    RD$ ${tot.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                </div>
                <div class="text-center">
                    <button type="button" class="btn btn-sm btn-outline" style="color: #b53d32; border-color: #f9ccc7; padding: 2px 6px;"
                            onclick="eliminarLineaCargo(${lin.id_temp})" title="Eliminar línea">
                        ✕
                    </button>
                </div>
            </div>
        `;
    });

    wrap.innerHTML = html;
    recalcularTotalesNd();
}

function recalcularTotalesNd() {
    let subtotal = 0;
    let impuestos = 0;

    LINEAS_ND.forEach(lin => {
        const subt = (lin.cantidad || 0) * (lin.precio_unitario || 0);
        const itbis = lin.aplicar_itbis ? (subt * 0.18) : 0;
        subtotal += subt;
        impuestos += itbis;
    });

    const total = subtotal + impuestos;

    const lblSub = document.getElementById('lblNdSubtotal');
    const lblImp = document.getElementById('lblNdImpuestos');
    const lblTot = document.getElementById('lblNdTotal');

    if (lblSub) lblSub.textContent = `RD$ ${subtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    if (lblImp) lblImp.textContent = `RD$ ${impuestos.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    if (lblTot) lblTot.textContent = `RD$ ${total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
}

// ============================================================================
// GUARDAR / EMITIR NOTA DE DÉBITO
// ============================================================================
async function guardarNotaDebito(e) {
    e.preventDefault();

    const facturaId = parseInt(document.getElementById('ndFacturaId')?.value, 10);
    if (!facturaId) {
        alert("Por favor busque y seleccione la factura origen a la cual aplicar la Nota de Débito.");
        return;
    }

    const concepto = document.getElementById('ndConceptoCargo')?.value.trim();
    if (!concepto) {
        alert("Debe ingresar el concepto legal del cargo.");
        return;
    }

    if (LINEAS_ND.length === 0) {
        alert("Debe agregar al menos una línea de cargo.");
        return;
    }

    for (let i = 0; i < LINEAS_ND.length; i++) {
        const lin = LINEAS_ND[i];
        if (!lin.descripcion_cargo.trim()) {
            alert(`La línea #${i + 1} no tiene descripción del cargo.`);
            return;
        }
        if (lin.precio_unitario <= 0) {
            alert(`La línea "${lin.descripcion_cargo}" debe tener un precio mayor a RD$ 0.00.`);
            return;
        }
    }

    const supId = document.getElementById('ndSupervisorId')?.value;
    const pin = document.getElementById('ndPinAutorizacion')?.value.trim();
    if (!supId || !pin) {
        alert("Se requiere la autorización y PIN de supervisor para emitir un comprobante fiscal B03.");
        return;
    }

    const diasCredito = parseInt(document.getElementById('ndDiasCredito')?.value, 10) || 30;

    const btn = document.getElementById('btnGuardarNd');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span>⏳</span> Emitiendo B03 en DGII...';
    }

    try {
        const payload = {
            factura_id: facturaId,
            concepto_cargo: concepto,
            dias_credito: diasCredito,
            supervisor_id: supId,
            pin_autorizacion: pin,
            lineas: LINEAS_ND.map(l => ({
                descripcion_cargo: l.descripcion_cargo.trim(),
                producto_maestro_id: l.producto_maestro_id,
                cantidad: l.cantidad,
                precio_unitario: l.precio_unitario,
                aplicar_itbis: l.aplicar_itbis
            }))
        };

        const resp = await fetch('procesar_notas_debito.php?accion=emitir_nota_debito', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const json = await resp.json();
        if (json.status === 'success') {
            alert(`✅ ${json.message}\nNCF: ${json.ncf_nota_debito}\nDeuda en CxC: RD$ ${parseFloat(json.monto_total).toFixed(2)}`);
            cerrarModal('modalEmitirNd');
            window.location.reload();
        } else {
            alert(`✕ Error al emitir Nota de Débito: ${json.message}`);
        }
    } catch (err) {
        console.error(err);
        alert("Ocurrió un error inesperado al comunicarse con el servidor.");
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<span>🔒</span> Emitir Nota de Débito Fiscal (B03)';
        }
    }
}

// ============================================================================
// DETALLE DE NOTA DE DÉBITO
// ============================================================================
async function verDetalleNd(id) {
    const body = document.getElementById('bodyDetalleNd');
    if (!body) return;

    body.innerHTML = '<div style="text-align: center; padding: 30px;">Cargando detalle fiscal...</div>';
    abrirModal('modalDetalleNd');

    try {
        const resp = await fetch(`procesar_notas_debito.php?accion=obtener_detalle_nd&id=${id}`);
        const json = await resp.json();
        if (json.status !== 'success' || !json.data) {
            body.innerHTML = `<div class="alert alert-danger">${json.message || 'Error cargando detalle'}</div>`;
            return;
        }

        const nd = json.data.nota_debito;
        const lineas = json.data.lineas || [];

        let htmlLineas = '';
        lineas.forEach(l => {
            htmlLineas += `
                <tr>
                    <td>
                        <strong>${l.descripcion_cargo}</strong>
                        ${l.producto_nombre ? `<small style="display:block; color:#888;">Ref: ${l.producto_nombre} (${l.producto_sku})</small>` : ''}
                    </td>
                    <td class="text-center">${l.cantidad}</td>
                    <td class="text-right" style="font-family: var(--font-mono);">RD$ ${parseFloat(l.precio_unitario).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                    <td class="text-right" style="font-family: var(--font-mono);">RD$ ${parseFloat(l.monto_impuestos).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                    <td class="text-right" style="font-family: var(--font-mono); font-weight: 700;">RD$ ${parseFloat(l.monto_total).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                </tr>
            `;
        });

        body.innerHTML = `
            <div style="background: #fcfaf6; border: 1px solid #ebd9bf; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <div style="font-size: 1.15rem; font-weight: 800; color: #a66e2e;">
                            Nota de Débito: ${nd.ncf_nota_debito}
                        </div>
                        <div style="font-size: 0.85rem; color: #555; margin-top: 4px;">
                            Afecta Factura: <span class="badge-ncf-mod">${nd.ncf_factura_modificada}</span> (Factura #${nd.factura_numero})
                        </div>
                        <div style="font-size: 0.85rem; color: #555; margin-top: 2px;">
                            Cliente: <strong>${nd.cliente_nombre}</strong> (RNC/Céd: ${nd.cliente_rnc || 'Sin RNC'})
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <span class="badge-status ${nd.cxc_estado === 'PAGADA' ? 'pagada' : 'pendiente'}" style="padding: 4px 10px; border-radius: 12px; font-weight: 700;">
                            ${nd.cxc_estado === 'PAGADA' ? '● Pagada / Saldada' : '⏳ Pendiente de Pago en CxC'}
                        </span>
                        <div style="font-size: 0.78rem; color: #888; margin-top: 4px;">Emisión: ${nd.fecha_fmt}</div>
                        <div style="font-size: 0.75rem; color: #888;">Emisor: ${nd.usuario_emisor}</div>
                    </div>
                </div>

                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed #d8c6b2; font-size: 0.86rem; color: #3d332a;">
                    <strong>Motivo Legal / Concepto:</strong> ${nd.concepto_cargo}
                </div>
            </div>

            <h4 style="font-size: 0.88rem; color: #4a3c31; margin-bottom: 8px;">Desglose de Líneas de Recargo</h4>
            <div class="table-wrapper" style="margin-bottom: 16px;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Descripción del Cargo</th>
                            <th class="text-center">Cantidad</th>
                            <th class="text-right">Precio Unit.</th>
                            <th class="text-right">ITBIS</th>
                            <th class="text-right">Monto Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${htmlLineas}
                    </tbody>
                </table>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 24px; background: #faf7f2; border: 1px solid #ebd9bf; border-radius: 8px; padding: 12px 18px;">
                <div style="text-align: right;">
                    <small style="display: block; font-size: 0.72rem; color: #8c8173; text-transform: uppercase;">Subtotal</small>
                    <strong style="font-family: var(--font-mono); font-size: 1rem;">RD$ ${parseFloat(nd.monto_subtotal).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong>
                </div>
                <div style="text-align: right;">
                    <small style="display: block; font-size: 0.72rem; color: #8c8173; text-transform: uppercase;">Impuestos (ITBIS)</small>
                    <strong style="font-family: var(--font-mono); font-size: 1rem;">RD$ ${parseFloat(nd.monto_impuestos).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong>
                </div>
                <div style="text-align: right;">
                    <small style="display: block; font-size: 0.72rem; color: #8c8173; text-transform: uppercase;">Total B03 Asentado en CxC</small>
                    <strong style="font-family: var(--font-mono); font-size: 1.15rem; color: #a66e2e;">RD$ ${parseFloat(nd.monto_total).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong>
                </div>
            </div>
        `;
    } catch (e) {
        console.error(e);
        body.innerHTML = '<div class="alert alert-danger">Error al cargar el detalle de la Nota de Débito.</div>';
    }
}
