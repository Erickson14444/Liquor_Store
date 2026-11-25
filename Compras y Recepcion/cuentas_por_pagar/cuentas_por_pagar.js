/**
 * ============================================================================
 * JAVASCRIPT DINÁMICO • PROCESO 2: CUENTAS POR PAGAR (CXP)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/cuentas_por_pagar/cuentas_por_pagar.js
 * ============================================================================
 */

// Estado global de facturas seleccionadas para pago consolidado
let FACTURAS_SELECCIONADAS = new Map(); // id -> { id, proveedor_id, proveedor_nombre, saldo }
let PROVEEDOR_ACTUAL_SELECCION = null;

// ============================================================================
// GESTIÓN DE TABS Y FILTROS
// ============================================================================

function cambiarTab(tabId, btn) {
    document.querySelectorAll('.cxp-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.cxp-tab-content').forEach(c => c.classList.remove('active'));

    btn.classList.add('active');
    const target = document.getElementById(tabId);
    if (target) {
        target.classList.add('active');
    }
}

function aplicarFiltrosCxp() {
    const provId = document.getElementById('filtroProveedor').value;
    const estado = document.getElementById('filtroEstado').value;
    const busq = document.getElementById('filtroBusqueda').value.toLowerCase().trim();

    const filas = document.querySelectorAll('.fila-cxp');

    filas.forEach(f => {
        const fProv = f.getAttribute('data-proveedor-id');
        const fEstado = f.getAttribute('data-estado');
        const fVencido = f.getAttribute('data-vencido') === '1';
        const fHoy = f.getAttribute('data-hoy') === '1';
        const fTexto = f.textContent.toLowerCase();

        let visible = true;

        if (provId && fProv !== provId) {
            visible = false;
        }

        if (visible && estado === 'PENDIENTES') {
            visible = (fEstado === 'PENDIENTE' || fEstado === 'PAGADA_PARCIAL');
        } else if (visible && estado === 'VENCIDAS') {
            visible = (fEstado === 'PENDIENTE' || fEstado === 'PAGADA_PARCIAL') && fVencido;
        } else if (visible && estado === 'HOY') {
            visible = (fEstado === 'PENDIENTE' || fEstado === 'PAGADA_PARCIAL') && fHoy;
        }

        if (visible && busq) {
            visible = fTexto.includes(busq);
        }

        f.style.display = visible ? '' : 'none';
    });
}

// ============================================================================
// SELECCIÓN CONSOLIDADA DE FACTURAS (MISMO PROVEEDOR)
// ============================================================================

function toggleSelectAll(masterChk) {
    const visibleChks = document.querySelectorAll('.fila-cxp:not([style*="display: none"]) .chk-cxp-item');
    if (!masterChk.checked) {
        limpiarSeleccion();
        return;
    }

    let primerProv = null;
    visibleChks.forEach(chk => {
        const prov = chk.getAttribute('data-proveedor');
        if (!primerProv) {
            primerProv = prov;
        }
        if (prov === primerProv) {
            chk.checked = true;
            alCambiarSeleccionItem(chk, false);
        } else {
            chk.checked = false;
        }
    });

    actualizarBarraSeleccion();
}

function alCambiarSeleccionItem(chk, actualizar = true) {
    const cxpId = parseInt(chk.value, 10);
    const provId = chk.getAttribute('data-proveedor');
    const saldo = parseFloat(chk.getAttribute('data-saldo'));
    const fila = chk.closest('tr');
    const provNombre = fila.getAttribute('data-proveedor-nombre');
    const factura = fila.getAttribute('data-factura') || 'S/F';
    const ncf = fila.getAttribute('data-ncf') || 'N/A';

    if (chk.checked) {
        if (PROVEEDOR_ACTUAL_SELECCION && PROVEEDOR_ACTUAL_SELECCION !== provId) {
            alert('Solo puede seleccionar facturas de un mismo suplidor para un desembolso consolidado. Se limpiará la selección anterior.');
            limpiarSeleccion();
            chk.checked = true;
        }
        PROVEEDOR_ACTUAL_SELECCION = provId;
        FACTURAS_SELECCIONADAS.set(cxpId, {
            id: cxpId,
            proveedor_id: provId,
            proveedor_nombre: provNombre,
            numero_factura: factura,
            ncf: ncf,
            saldo: saldo,
            fila: fila
        });
    } else {
        FACTURAS_SELECCIONADAS.delete(cxpId);
        if (FACTURAS_SELECCIONADAS.size === 0) {
            PROVEEDOR_ACTUAL_SELECCION = null;
        }
    }

    if (actualizar) {
        actualizarBarraSeleccion();
    }
}

function actualizarBarraSeleccion() {
    const count = FACTURAS_SELECCIONADAS.size;
    let total = 0.00;
    let provNombre = '—';

    FACTURAS_SELECCIONADAS.forEach(item => {
        total += item.saldo;
        provNombre = item.proveedor_nombre;
    });

    const bar = document.getElementById('cxpSelectionBar');
    const countTop = document.getElementById('countSeleccionadasTop');
    const btnTop = document.getElementById('btnDesembolsoTop');

    if (countTop) countTop.textContent = count;
    if (btnTop) btnTop.disabled = (count === 0);

    if (count > 0) {
        bar.style.display = 'flex';
        document.getElementById('txtSeleccionadasCount').textContent = count;
        document.getElementById('txtSeleccionadasProveedor').textContent = provNombre;
        document.getElementById('txtSeleccionadasTotal').textContent = 'RD$ ' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    } else {
        bar.style.display = 'none';
    }
}

function limpiarSeleccion() {
    FACTURAS_SELECCIONADAS.clear();
    PROVEEDOR_ACTUAL_SELECCION = null;
    document.querySelectorAll('.chk-cxp-item').forEach(c => c.checked = false);
    const master = document.getElementById('chkSelectAll');
    if (master) master.checked = false;
    actualizarBarraSeleccion();
}

function liquidarFacturaIndividual(cxpId, provId) {
    limpiarSeleccion();
    const chk = document.querySelector(`.chk-cxp-item[value="${cxpId}"]`);
    if (chk) {
        chk.checked = true;
        alCambiarSeleccionItem(chk, true);
        abrirModalDesembolso();
    }
}

// ============================================================================
// MODAL GASTO / SERVICIO MANUAL (FASE 1)
// ============================================================================

function abrirModalGastoManual() {
    document.getElementById('formGastoManual').reset();
    limpiarProveedorSeleccionadoCxp();
    alCambiarCondicionGasto();
    document.getElementById('modalGastoManual').classList.add('active');
}

/**
 * LÓGICA DE BÚSQUEDA Y SELECCIÓN DE PROVEEDORES (MODAL GASTO / SERVICIO DIRECTO)
 */
function getProveedoresListaCxp() {
    if (typeof window.PROVEEDORES_LISTA !== 'undefined' && Array.isArray(window.PROVEEDORES_LISTA)) return window.PROVEEDORES_LISTA;
    if (typeof PROVEEDORES_LISTA !== 'undefined' && Array.isArray(PROVEEDORES_LISTA)) return PROVEEDORES_LISTA;
    return [];
}

function abrirDropdownProveedoresCxp() {
    const dropdown = document.getElementById('dropdownProveedoresCxp');
    if (!dropdown) return;
    const input = document.getElementById('inputBuscarProveedorCxp');
    filtrarProveedoresCxp(input ? input.value : '');
    dropdown.style.display = 'block';
}

function filtrarProveedoresCxp(query) {
    const dropdown = document.getElementById('dropdownProveedoresCxp');
    if (!dropdown) return;

    const q = (query || '').toLowerCase().trim();
    const lista = getProveedoresListaCxp();

    const filtrados = lista.filter(p => {
        if (!q) return true;
        const nombre = (p.nombre_razon_social || '').toLowerCase();
        const doc = (p.documento_identidad || p.rnc || '').toLowerCase();
        return nombre.includes(q) || doc.includes(q);
    });

    if (filtrados.length === 0) {
        dropdown.innerHTML = '<div style="padding: 10px 14px; color: var(--text-muted); font-size: 0.80rem; text-align: center;">No se encontraron suplidores coincidentes</div>';
    } else {
        dropdown.innerHTML = filtrados.slice(0, 40).map(p => {
            const dias = parseInt(p.dias_credito_habitual || 0, 10);
            const rnc = p.documento_identidad || p.rnc || 'S/RNC';
            return `
                <div class="opcion-prov-item" onclick="seleccionarProveedorCxp('${p.persona_id}')" style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f2ede4; display: flex; justify-content: space-between; align-items: center; transition: background 0.15s;" onmouseover="this.style.background='#faf4ea'" onmouseout="this.style.background='transparent'">
                    <div>
                        <strong style="font-size: 0.82rem; color: #211813;">${p.nombre_razon_social}</strong>
                        <div style="font-size: 0.72rem; color: #7a6b5d; font-family: var(--font-mono);">RNC: ${rnc}</div>
                    </div>
                    <span style="font-size: 0.70rem; background: #eef5fb; color: #1e6091; font-weight: 600; padding: 2px 6px; border-radius: 4px;">${dias} d. crédito</span>
                </div>
            `;
        }).join('');
    }
    dropdown.style.display = 'block';
}

function seleccionarProveedorCxp(proveedorId) {
    const hidden = document.getElementById('suplidorGastoId');
    const box = document.getElementById('boxBusquedaProveedorCxp');
    const badge = document.getElementById('badgeProveedorElegidoCxp');
    const badgeNombre = document.getElementById('badgeProveedorNombreCxp');
    const badgeRnc = document.getElementById('badgeProveedorRncCxp');
    const inpDias = document.getElementById('diasCreditoGasto');
    const dropdown = document.getElementById('dropdownProveedoresCxp');

    if (hidden) hidden.value = proveedorId;

    const lista = getProveedoresListaCxp();
    const p = lista.find(item => String(item.persona_id) === String(proveedorId));

    if (p) {
        if (badgeNombre) badgeNombre.textContent = p.nombre_razon_social || '';
        const rnc = p.documento_identidad || p.rnc || 'S/RNC';
        const dias = parseInt(p.dias_credito_habitual || 0, 10);
        if (badgeRnc) badgeRnc.textContent = `(${rnc} • ${dias} días crédito)`;
        if (inpDias && dias > 0) {
            inpDias.value = dias;
        }
    }

    if (box) box.style.display = 'none';
    if (badge) badge.style.display = 'flex';
    if (dropdown) dropdown.style.display = 'none';
}

function limpiarProveedorSeleccionadoCxp() {
    const hidden = document.getElementById('suplidorGastoId');
    const box = document.getElementById('boxBusquedaProveedorCxp');
    const badge = document.getElementById('badgeProveedorElegidoCxp');
    const input = document.getElementById('inputBuscarProveedorCxp');
    const dropdown = document.getElementById('dropdownProveedoresCxp');

    if (hidden) hidden.value = '';
    if (input) input.value = '';
    if (badge) badge.style.display = 'none';
    if (box) box.style.display = 'block';
    if (dropdown) dropdown.style.display = 'none';
}

// Cierre de dropdown al hacer click afuera
document.addEventListener('click', function(e) {
    const box = document.getElementById('boxBusquedaProveedorCxp');
    const dropdown = document.getElementById('dropdownProveedoresCxp');
    if (dropdown && box && !box.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

function alCambiarCondicionGasto() {
    const cond = document.getElementById('condicionPagoGasto').value;
    const inpDias = document.getElementById('diasCreditoGasto');
    if (cond === 'CONTADO') {
        inpDias.value = 0;
        inpDias.disabled = true;
    } else {
        inpDias.disabled = false;
        if (parseInt(inpDias.value, 10) === 0) {
            inpDias.value = 30;
        }
    }
}

async function guardarGastoManual(e) {
    e.preventDefault();
    const form = e.target;
    const btn = document.getElementById('btnGuardarGasto');
    btn.disabled = true;
    btn.textContent = 'Guardando...';

    const formData = new FormData(form);
    formData.append('accion', 'registrar_gasto_manual');

    try {
        const resp = await fetch('procesar_cxp.php', {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();

        if (data.ok) {
            alert(data.mensaje);
            cerrarModal('modalGastoManual');
            window.location.reload();
        } else {
            alert('Error: ' + data.mensaje);
        }
    } catch (err) {
        alert('Error de conexión al guardar el gasto: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Guardar Obligación';
    }
}

// ============================================================================
// MODAL DESEMBOLSO CONSOLIDADO (FASE 3: SPLIT Y RETENCIONES FISCALES)
// ============================================================================

let RETENCIONES_SELECCIONADAS = []; // [{ retencion_id, base_calculo, monto_base, porcentaje, monto_retenido }]
let PAGOS_SPLIT_LINEAS = [];        // [{ id_temp, metodo_pago_id, monto, cuenta_bancaria_id, referencia, titular, num_cheque, banco_emisor }]

async function abrirModalDesembolso() {
    if (FACTURAS_SELECCIONADAS.size === 0) {
        alert('Debe seleccionar al menos una factura para liquidar.');
        return;
    }

    const tbody = document.getElementById('tbodyFacturasDesembolso');
    tbody.innerHTML = '';
    RETENCIONES_SELECCIONADAS = [];
    PAGOS_SPLIT_LINEAS = [];

    let provNombre = '';
    let totalBruto = 0.00;

    FACTURAS_SELECCIONADAS.forEach(item => {
        provNombre = item.proveedor_nombre;
        totalBruto += item.saldo;

        const tr = document.createElement('tr');
        tr.className = 'fila-desembolso-factura';
        tr.setAttribute('data-cxp-id', item.id);
        tr.setAttribute('data-saldo-anterior', item.saldo);

        tr.innerHTML = `
            <td><strong style="color: var(--text-dark);">${item.numero_factura || 'Documento'}</strong></td>
            <td><span style="font-family: var(--font-mono); font-size: 0.8rem; color: var(--accent-gold); font-weight: 600;">${item.ncf || '—'}</span></td>
            <td style="text-align: right; font-family: var(--font-mono);">RD$ ${item.saldo.toFixed(2)}</td>
            <td style="text-align: right;">
                <input type="number" step="0.01" class="form-control inp-monto-aplicado" 
                       value="${item.saldo.toFixed(2)}" max="${item.saldo}" min="0.01"
                       style="font-size: 0.85rem; font-weight: 700; text-align: right;" 
                       oninput="recalcularTotalesDesembolso()" />
            </td>
            <td style="text-align: right; font-family: var(--font-mono); color: #b78103;" class="celda-retencion-factura">
                RD$ 0.00
            </td>
            <td style="text-align: right; font-family: var(--font-mono); font-weight: 700; color: #2e7d52;" class="celda-neto-factura">
                RD$ ${item.saldo.toFixed(2)}
            </td>
            <td style="text-align: right; font-family: var(--font-mono); font-weight: 700;" class="celda-saldo-restante">
                RD$ 0.00
            </td>
        `;
        tbody.appendChild(tr);
    });

    document.getElementById('modalDesembolsoProveedorNombre').textContent = provNombre;

    // Cargar retenciones sugeridas del proveedor
    try {
        const resp = await fetch(`procesar_cxp.php?accion=obtener_facturas_proveedor&proveedor_id=${PROVEEDOR_ACTUAL_SELECCION}`);
        const data = await resp.json();
        if (data.ok && data.retenciones_sugeridas && data.retenciones_sugeridas.length > 0) {
            data.retenciones_sugeridas.forEach(rs => {
                agregarLineaRetencion(rs.id, rs.base_calculo, rs.porcentaje);
            });
        }
    } catch (e) {
        console.error('Error al obtener retenciones sugeridas:', e);
    }

    // Inicializar Split de pagos con una línea en TRANSFERENCIA o EFECTIVO
    renderizarContenedorRetenciones();
    agregarLineaSplitPago(3, totalBruto); // Transferencia por defecto

    recalcularTotalesDesembolso();
    document.getElementById('modalDesembolso').classList.add('active');
}

// Retenciones Dinámicas DGII
function agregarLineaRetencion(retId = null, base = 'SUBTOTAL', porc = 0) {
    const idTemp = Date.now() + Math.random();
    if (!retId && CATALOGO_RETENCIONES.length > 0) {
        retId = CATALOGO_RETENCIONES[0].id;
        base = CATALOGO_RETENCIONES[0].base_calculo;
        porc = parseFloat(CATALOGO_RETENCIONES[0].porcentaje);
    }

    RETENCIONES_SELECCIONADAS.push({
        id_temp: idTemp,
        retencion_id: retId,
        base_calculo: base,
        monto_base: 0.00,
        porcentaje: porc,
        monto_retenido: 0.00
    });

    renderizarContenedorRetenciones();
    recalcularTotalesDesembolso();
}

function eliminarLineaRetencion(idTemp) {
    RETENCIONES_SELECCIONADAS = RETENCIONES_SELECCIONADAS.filter(r => r.id_temp !== idTemp);
    renderizarContenedorRetenciones();
    recalcularTotalesDesembolso();
}

function renderizarContenedorRetenciones() {
    const cont = document.getElementById('contenedorRetenciones');
    if (!cont) return;

    if (RETENCIONES_SELECCIONADAS.length === 0) {
        cont.innerHTML = `
            <div style="font-size: 0.8rem; color: var(--text-muted); padding: 8px 12px; background: #fdfcf9; border: 1px dashed #eae3d5; border-radius: 6px;">
                No se han aplicado retenciones fiscales a este desembolso (0.00%).
            </div>
        `;
        return;
    }

    cont.innerHTML = '';
    RETENCIONES_SELECCIONADAS.forEach(r => {
        const div = document.createElement('div');
        div.className = 'retencion-card';

        let optionsRet = '';
        CATALOGO_RETENCIONES.forEach(cat => {
            const sel = (parseInt(cat.id, 10) === parseInt(r.retencion_id, 10)) ? 'selected' : '';
            optionsRet += `<option value="${cat.id}" data-base="${cat.base_calculo}" data-porc="${cat.porcentaje}" ${sel}>${cat.siglas} - ${cat.nombre} (${cat.porcentaje}%)</option>`;
        });

        div.innerHTML = `
            <div style="display: flex; gap: 10px; align-items: center; flex: 1;">
                <select class="form-control" style="font-size: 0.82rem;" onchange="alCambiarSelectRetencion(${r.id_temp}, this)">
                    ${optionsRet}
                </select>
                <div style="font-size: 0.78rem; color: var(--text-muted); white-space: nowrap;">
                    Base: <strong>${r.base_calculo}</strong> (${r.porcentaje}%)
                </div>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <div style="font-family: var(--font-mono); font-weight: 700; color: #b78103;" id="lblMontoRet_${r.id_temp}">
                    RD$ ${r.monto_retenido.toFixed(2)}
                </div>
                <button type="button" class="btn btn-sm btn-outline" onclick="eliminarLineaRetencion(${r.id_temp})" style="color: #b53d32;">✕</button>
            </div>
        `;
        cont.appendChild(div);
    });
}

function alCambiarSelectRetencion(idTemp, select) {
    const item = RETENCIONES_SELECCIONADAS.find(r => r.id_temp === idTemp);
    if (!item) return;

    const opt = select.options[select.selectedIndex];
    item.retencion_id = parseInt(select.value, 10);
    item.base_calculo = opt.getAttribute('data-base') || 'SUBTOTAL';
    item.porcentaje = parseFloat(opt.getAttribute('data-porc') || 0);

    renderizarContenedorRetenciones();
    recalcularTotalesDesembolso();
}

// Split de Pagos
function agregarLineaSplitPago(metodoId = 3, monto = 0.00) {
    const idTemp = Date.now() + Math.random();
    PAGOS_SPLIT_LINEAS.push({
        id_temp: idTemp,
        metodo_pago_id: metodoId,
        monto: monto,
        cuenta_bancaria_id: CATALOGO_BANCOS[0] ? CATALOGO_BANCOS[0].id : 1,
        referencia: '',
        titular: 'House & Ice',
        num_cheque: '',
        banco_emisor: 'Banco Popular'
    });

    renderizarSplitPagos();
    recalcularTotalesDesembolso();
}

function eliminarLineaSplit(idTemp) {
    if (PAGOS_SPLIT_LINEAS.length <= 1) {
        alert('Debe mantener al menos un método de pago para el desembolso.');
        return;
    }
    PAGOS_SPLIT_LINEAS = PAGOS_SPLIT_LINEAS.filter(p => p.id_temp !== idTemp);
    renderizarSplitPagos();
    recalcularTotalesDesembolso();
}

function renderizarSplitPagos() {
    const cont = document.getElementById('contenedorSplitPagos');
    if (!cont) return;
    cont.innerHTML = '';

    PAGOS_SPLIT_LINEAS.forEach(p => {
        const div = document.createElement('div');
        div.className = 'split-pago-row';

        let optionsMet = '';
        CATALOGO_METODOS.forEach(m => {
            const sel = (parseInt(m.id, 10) === parseInt(p.metodo_pago_id, 10)) ? 'selected' : '';
            optionsMet += `<option value="${m.id}" data-codigo="${m.codigo}" ${sel}>${m.descripcion}</option>`;
        });

        // Configurar campos adicionales según si es Transferencia o Cheque
        let camposExtras = '';
        const metSeleccionado = CATALOGO_METODOS.find(m => parseInt(m.id, 10) === parseInt(p.metodo_pago_id, 10));
        const codMetodo = metSeleccionado ? metSeleccionado.codigo : 'EFECTIVO';

        if (codMetodo === 'TRANSFERENCIA') {
            let optionsBancos = '';
            CATALOGO_BANCOS.forEach(b => {
                const selB = (parseInt(b.id, 10) === parseInt(p.cuenta_bancaria_id, 10)) ? 'selected' : '';
                optionsBancos += `<option value="${b.id}" ${selB}>${b.nombre_banco} (${b.numero_cuenta})</option>`;
            });

            camposExtras = `
                <div style="display: flex; gap: 8px; flex: 1;">
                    <select class="form-control" style="font-size: 0.8rem;" onchange="alCambiarBancoSplit(${p.id_temp}, this)">
                        ${optionsBancos}
                    </select>
                    <input type="text" class="form-control" placeholder="No. Referencia TRF" value="${p.referencia}" style="font-size: 0.8rem;" oninput="alCambiarReferenciaSplit(${p.id_temp}, this.value)" required />
                </div>
            `;
        } else if (codMetodo === 'CHEQUE') {
            let optionsBancos = '';
            CATALOGO_BANCOS.forEach(b => {
                const selB = (parseInt(b.id, 10) === parseInt(p.cuenta_bancaria_id, 10)) ? 'selected' : '';
                optionsBancos += `<option value="${b.id}" ${selB}>${b.nombre_banco} (${b.numero_cuenta})</option>`;
            });

            camposExtras = `
                <div style="display: flex; gap: 8px; flex: 1;">
                    <select class="form-control" style="font-size: 0.8rem;" onchange="alCambiarBancoSplit(${p.id_temp}, this)">
                        ${optionsBancos}
                    </select>
                    <input type="text" class="form-control" placeholder="No. Cheque" value="${p.num_cheque}" style="font-size: 0.8rem;" oninput="alCambiarChequeSplit(${p.id_temp}, this.value)" required />
                </div>
            `;
        } else {
            camposExtras = `
                <div style="font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center; padding-left: 6px;">
                    Egreso en Efectivo desde Bóveda / Caja Chica Externa
                </div>
            `;
        }

        div.innerHTML = `
            <div>
                <select class="form-control" style="font-size: 0.82rem; font-weight: 600;" onchange="alCambiarMetodoSplit(${p.id_temp}, this)">
                    ${optionsMet}
                </select>
            </div>
            ${camposExtras}
            <div>
                <input type="number" step="0.01" class="form-control" value="${p.monto.toFixed(2)}" min="0.01"
                       style="font-family: var(--font-mono); font-weight: 700; text-align: right; font-size: 0.88rem;"
                       oninput="alCambiarMontoSplit(${p.id_temp}, this.value)" />
            </div>
            <div>
                <button type="button" class="btn btn-sm btn-outline" onclick="eliminarLineaSplit(${p.id_temp})" style="color: #b53d32; padding: 4px 8px;">✕</button>
            </div>
        `;
        cont.appendChild(div);
    });
}

function alCambiarMetodoSplit(idTemp, select) {
    const item = PAGOS_SPLIT_LINEAS.find(p => p.id_temp === idTemp);
    if (!item) return;
    item.metodo_pago_id = parseInt(select.value, 10);
    renderizarSplitPagos();
}

function alCambiarBancoSplit(idTemp, select) {
    const item = PAGOS_SPLIT_LINEAS.find(p => p.id_temp === idTemp);
    if (!item) return;
    item.cuenta_bancaria_id = parseInt(select.value, 10);
}

function autoCuadrarSplitConNeto() {
    let totalBruto = 0.00;
    document.querySelectorAll('.fila-desembolso-factura').forEach(f => {
        const inpMonto = f.querySelector('.inp-monto-aplicado');
        totalBruto += parseFloat(inpMonto.value) || 0.00;
    });

    let totalRet = 0.00;
    RETENCIONES_SELECCIONADAS.forEach(r => {
        totalRet += r.monto_retenido || 0.00;
    });

    const neto = Math.max(0, Math.round((totalBruto - totalRet) * 100) / 100);

    if (PAGOS_SPLIT_LINEAS.length > 0) {
        PAGOS_SPLIT_LINEAS[0].monto = neto;
        renderizarSplitPagos();
        recalcularTotalesDesembolso();
    }
}

function alCambiarReferenciaSplit(idTemp, val) {
    const item = PAGOS_SPLIT_LINEAS.find(p => p.id_temp === idTemp);
    if (!item) return;
    item.referencia = val;
}

function alCambiarChequeSplit(idTemp, val) {
    const item = PAGOS_SPLIT_LINEAS.find(p => p.id_temp === idTemp);
    if (!item) return;
    item.num_cheque = val;
}

function alCambiarMontoSplit(idTemp, val) {
    const item = PAGOS_SPLIT_LINEAS.find(p => p.id_temp === idTemp);
    if (!item) return;
    item.monto = parseFloat(val) || 0.00;
    recalcularTotalesDesembolso();
}

// Recálculo integral de cuadre
function recalcularTotalesDesembolso() {
    let totalBrutoAplicado = 0.00;

    // 1. Recorrer facturas
    const filasFacturas = document.querySelectorAll('.fila-desembolso-factura');
    filasFacturas.forEach(f => {
        const saldoAnt = parseFloat(f.getAttribute('data-saldo-anterior'));
        const inpMonto = f.querySelector('.inp-monto-aplicado');
        let monto = parseFloat(inpMonto.value) || 0.00;

        if (monto > saldoAnt) {
            monto = saldoAnt;
            inpMonto.value = saldoAnt.toFixed(2);
        }
        totalBrutoAplicado += monto;
    });

    // 2. Calcular monto retenido por cada retención activa sobre el monto total aplicado
    let totalRetenido = 0.00;
    RETENCIONES_SELECCIONADAS.forEach(r => {
        // En licorería y compras generales, se asume base = monto si no se desglosa ITBIS individual
        const baseRet = totalBrutoAplicado;
        const montoRet = Math.round((baseRet * (r.porcentaje / 100)) * 100) / 100;
        r.monto_base = baseRet;
        r.monto_retenido = montoRet;
        totalRetenido += montoRet;

        const lbl = document.getElementById(`lblMontoRet_${r.id_temp}`);
        if (lbl) {
            lbl.textContent = 'RD$ ' + montoRet.toFixed(2);
        }
    });

    // Ajustar visualmente retención, neto y saldo restante en cada fila de factura
    filasFacturas.forEach(f => {
        const saldoAnt = parseFloat(f.getAttribute('data-saldo-anterior'));
        const inpMonto = f.querySelector('.inp-monto-aplicado');
        const montoBruto = parseFloat(inpMonto.value) || 0.00;
        const proporcion = totalBrutoAplicado > 0 ? (montoBruto / totalBrutoAplicado) : 0;
        const retFactura = Math.round((totalRetenido * proporcion) * 100) / 100;
        const netoFactura = Math.max(0, Math.round((montoBruto - retFactura) * 100) / 100);
        const saldoRestante = Math.max(0, Math.round((saldoAnt - montoBruto) * 100) / 100);

        const celdaRet = f.querySelector('.celda-retencion-factura');
        if (celdaRet) {
            celdaRet.textContent = 'RD$ ' + retFactura.toFixed(2);
        }

        const celdaNeto = f.querySelector('.celda-neto-factura');
        if (celdaNeto) {
            celdaNeto.textContent = 'RD$ ' + netoFactura.toFixed(2);
        }

        const celdaSaldo = f.querySelector('.celda-saldo-restante');
        if (celdaSaldo) {
            celdaSaldo.textContent = 'RD$ ' + saldoRestante.toFixed(2);
            celdaSaldo.style.color = (saldoRestante === 0) ? '#2e7d52' : '#b53d32';
        }
    });

    // 3. Neto a desembolsar
    const netoDesembolsar = Math.max(0, Math.round((totalBrutoAplicado - totalRetenido) * 100) / 100);

    document.getElementById('modalTotalBrutoFacturas').textContent = 'RD$ ' + totalBrutoAplicado.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('modalTotalRetenciones').textContent = 'RD$ ' + totalRetenido.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('modalTotalNetoDesembolsar').textContent = 'RD$ ' + netoDesembolsar.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Auto-ajuste inteligente: Si solo hay 1 método de pago en el split, sincronizarlo automáticamente al Neto
    if (PAGOS_SPLIT_LINEAS.length === 1) {
        PAGOS_SPLIT_LINEAS[0].monto = netoDesembolsar;
        const inpMontoUnico = document.querySelector('.split-pago-row input[type="number"]');
        if (inpMontoUnico && document.activeElement !== inpMontoUnico) {
            inpMontoUnico.value = netoDesembolsar.toFixed(2);
        }
    }

    // 4. Sumar split de pagos
    let totalSplit = 0.00;
    PAGOS_SPLIT_LINEAS.forEach(p => {
        totalSplit += p.monto;
    });
    totalSplit = Math.round(totalSplit * 100) / 100;

    document.getElementById('lblTotalSplit').textContent = 'RD$ ' + totalSplit.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const dif = Math.round(Math.abs(totalSplit - netoDesembolsar) * 100) / 100;
    const lblCuadre = document.getElementById('lblEstadoCuadreSplit');
    const btnConfirmar = document.getElementById('btnConfirmarDesembolso');
    const btnConfirmarBody = document.getElementById('btnConfirmarDesembolsoBody');

    if (netoDesembolsar > 0 && dif <= 0.02) {
        lblCuadre.innerHTML = '<span style="color: #2e7d52;">● Cuadre Exacto (RD$ ' + totalSplit.toFixed(2) + ')</span>';
        if (btnConfirmar) btnConfirmar.disabled = false;
        if (btnConfirmarBody) btnConfirmarBody.disabled = false;
    } else if (totalSplit < netoDesembolsar) {
        const faltan = (netoDesembolsar - totalSplit).toFixed(2);
        lblCuadre.innerHTML = `<span style="color: #b53d32;">⚠️ Faltan RD$ ${faltan} por asignar en el Split</span>`;
        if (btnConfirmar) btnConfirmar.disabled = true;
        if (btnConfirmarBody) btnConfirmarBody.disabled = true;
    } else {
        const sobran = (totalSplit - netoDesembolsar).toFixed(2);
        lblCuadre.innerHTML = `<span style="color: #b78103;">⚠️ El Split excede el Neto por RD$ ${sobran}</span>`;
        if (btnConfirmar) btnConfirmar.disabled = true;
        if (btnConfirmarBody) btnConfirmarBody.disabled = true;
    }
}

// Enviar Liquidación Desembolso Consolidado
async function procesarLiquidacionDesembolso(e) {
    e.preventDefault();
    const btn = document.getElementById('btnConfirmarDesembolso');
    btn.disabled = true;
    btn.textContent = 'Procesando Desembolso...';

    // Armar facturas con cálculo exacto de neto desembolsado y retenciones
    const facturasPayload = [];
    const filas = document.querySelectorAll('.fila-desembolso-factura');
    
    // Calcular total bruto
    let totalBrutoAplicar = 0.00;
    filas.forEach(f => {
        totalBrutoAplicar += parseFloat(f.querySelector('.inp-monto-aplicado').value) || 0.00;
    });

    filas.forEach(f => {
        const cxpId = parseInt(f.getAttribute('data-cxp-id'), 10);
        const montoBruto = parseFloat(f.querySelector('.inp-monto-aplicado').value) || 0.00;
        const proporcion = totalBrutoAplicar > 0 ? (montoBruto / totalBrutoAplicar) : 0;

        // Distribuir retenciones proporcionalmente a esta factura
        let totalRetFactura = 0.00;
        const retsFactura = RETENCIONES_SELECCIONADAS.map(r => {
            const montoRetItem = Math.round((r.monto_retenido * proporcion) * 100) / 100;
            totalRetFactura += montoRetItem;
            return {
                retencion_id: r.retencion_id,
                base_calculo: r.base_calculo,
                monto_base: montoBruto,
                porcentaje: r.porcentaje,
                monto_retenido: montoRetItem
            };
        });

        // En la base de datos (compras.comprobante_egreso_detalles), monto_aplicado registra el dinero neto desembolsado
        const netoDesembolso = Math.max(0, Math.round((montoBruto - totalRetFactura) * 100) / 100);

        facturasPayload.push({
            cxp_id: cxpId,
            monto_bruto: montoBruto,
            monto_aplicado: netoDesembolso,
            retenciones: retsFactura
        });
    });

    const payload = {
        proveedor_id: PROVEEDOR_ACTUAL_SELECCION,
        facturas: facturasPayload,
        pagos: PAGOS_SPLIT_LINEAS,
        moneda_id: 1,
        tasa_cambio: 1.0000
    };

    try {
        const resp = await fetch('procesar_cxp.php?accion=liquidar_desembolso', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await resp.json();

        if (data.ok) {
            alert('¡Éxito! ' + data.mensaje);
            cerrarModal('modalDesembolso');
            limpiarSeleccion();
            window.location.reload();
        } else {
            alert('Error en Desembolso: ' + data.mensaje);
        }
    } catch (err) {
        alert('Error de conexión en liquidación: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Confirmar y Emitir Comprobante de Egreso';
    }
}

// ============================================================================
// MODAL AJUSTE, DESCUENTO Y ANULACIÓN CON PIN DE SUPERVISOR (FASE 4)
// ============================================================================

function abrirModalAjuste(cxpId, proveedorNombre, saldoActual) {
    document.getElementById('formAjuste').reset();
    document.getElementById('ajusteCxpId').value = cxpId;
    document.getElementById('ajusteProveedorNombre').textContent = proveedorNombre;
    document.getElementById('ajusteSaldoActual').textContent = 'RD$ ' + saldoActual.toFixed(2);
    document.getElementById('inputMontoAjuste').value = saldoActual.toFixed(2);
    document.getElementById('inputMontoAjuste').setAttribute('max', saldoActual);

    alCambiarTipoAjuste();
    document.getElementById('modalAjuste').classList.add('active');
}

function alCambiarTipoAjuste() {
    const tipo = document.getElementById('tipoAjusteSelect').value;
    const grpNcf = document.getElementById('grupoNcfNotaCredito');
    const inpMonto = document.getElementById('inputMontoAjuste');
    const ayudaMonto = document.getElementById('lblAyudaMontoAjuste');
    const inpNcf = document.getElementById('inputNcfNotaCredito');

    if (tipo === 'NOTA_CREDITO_PROVEEDOR') {
        grpNcf.style.display = 'block';
        inpNcf.required = true;
    } else {
        grpNcf.style.display = 'none';
        inpNcf.required = false;
    }

    if (tipo === 'ANULACION') {
        inpMonto.readOnly = true;
        ayudaMonto.textContent = 'La anulación congela permanentemente la obligación cancelando el 100% del saldo pendiente.';
    } else {
        inpMonto.readOnly = false;
        ayudaMonto.textContent = 'Rebaja el saldo de la obligación sin salida de efectivo.';
    }
}

async function guardarAjusteCxp(e) {
    e.preventDefault();
    const form = e.target;
    const btn = document.getElementById('btnGuardarAjuste');
    btn.disabled = true;
    btn.textContent = 'Autorizando...';

    const formData = new FormData(form);
    formData.append('accion', 'registrar_ajuste');

    try {
        const resp = await fetch('procesar_cxp.php', {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();

        if (data.ok) {
            alert('¡Ajuste aplicado! ' + data.mensaje);
            cerrarModal('modalAjuste');
            window.location.reload();
        } else {
            alert('Error al aplicar ajuste: ' + data.mensaje);
        }
    } catch (err) {
        alert('Error de conexión en autorización: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Autorizar y Aplicar';
    }
}

// ============================================================================
// MODAL DETALLE DE COMPROBANTE DE EGRESO
// ============================================================================

async function verDetalleEgreso(egresoId) {
    const modal = document.getElementById('modalDetalleEgreso');
    const cuerpo = document.getElementById('cuerpoDetalleEgreso');
    cuerpo.innerHTML = '<div style="text-align: center; padding: 30px; color: var(--text-muted);">Cargando comprobante...</div>';
    modal.classList.add('active');

    try {
        const resp = await fetch(`procesar_cxp.php?accion=ver_detalle_egreso&egreso_id=${egresoId}`);
        const data = await resp.json();

        if (data.ok) {
            const cab = data.cabecera;
            document.getElementById('txtDetalleNumComprobante').textContent = cab.numero_comprobante;

            let htmlDetalles = '';
            data.detalles.forEach(d => {
                htmlDetalles += `
                    <tr>
                        <td><strong>${d.numero_factura}</strong></td>
                        <td style="font-family: var(--font-mono); color: var(--accent-gold);">${d.ncf}</td>
                        <td style="text-align: right; font-family: var(--font-mono);">RD$ ${parseFloat(d.saldo_anterior).toFixed(2)}</td>
                        <td style="text-align: right; font-family: var(--font-mono); font-weight: 700; color: #2e7d52;">RD$ ${parseFloat(d.monto_aplicado).toFixed(2)}</td>
                        <td style="text-align: right; font-family: var(--font-mono); color: #b78103;">RD$ ${parseFloat(d.monto_retenciones).toFixed(2)}</td>
                        <td style="text-align: right; font-family: var(--font-mono);">RD$ ${parseFloat(d.saldo_restante).toFixed(2)}</td>
                    </tr>
                `;
            });

            let htmlRet = '';
            if (data.retenciones && data.retenciones.length > 0) {
                data.retenciones.forEach(r => {
                    htmlRet += `
                        <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0ede6; font-size: 0.82rem;">
                            <span>${r.siglas} (${r.porcentaje_aplicado}%) - Factura #${r.cuenta_por_pagar_id}</span>
                            <strong style="font-family: var(--font-mono); color: #b78103;">RD$ ${parseFloat(r.monto_retenido).toFixed(2)}</strong>
                        </div>
                    `;
                });
            } else {
                htmlRet = '<div style="font-size: 0.8rem; color: #888;">Sin retenciones fiscales registradas.</div>';
            }

            let htmlPagos = '';
            data.pagos.forEach(p => {
                const infoExtra = p.numero_referencia ? `(Ref: ${p.numero_referencia})` : (p.numero_cheque ? `(Cheque #${p.numero_cheque})` : '');
                htmlPagos += `
                    <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0ede6; font-size: 0.84rem;">
                        <span><strong>${p.metodo_nombre}</strong> ${infoExtra}</span>
                        <strong style="font-family: var(--font-mono); color: #2e7d52;">RD$ ${parseFloat(p.monto_pagado).toFixed(2)}</strong>
                    </div>
                `;
            });

            cuerpo.innerHTML = `
                <div style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                        <span>Proveedor: <strong>${cab.proveedor_nombre}</strong> (RNC: ${cab.proveedor_rnc})</span>
                        <span>Fecha: <strong>${cab.fecha_pago.substr(0, 16)}</strong></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--text-muted);">
                        <span>Registrado por: <strong>${cab.usuario_registro_nombre}</strong></span>
                        <span>Total Pagado: <strong style="font-family: var(--font-mono); font-size: 1.1rem; color: #2e7d52;">RD$ ${parseFloat(cab.monto_total_pagado).toFixed(2)}</strong></span>
                    </div>
                </div>

                <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 8px;">Facturas Liquidadas</h4>
                <div style="overflow-x: auto; margin-bottom: 16px;">
                    <table class="data-table" style="font-size: 0.82rem;">
                        <thead>
                            <tr style="background: #fdfcf9;">
                                <th>Factura</th><th>NCF</th>
                                <th style="text-align: right;">Saldo Ant.</th>
                                <th style="text-align: right;">Aplicado</th>
                                <th style="text-align: right;">Retención</th>
                                <th style="text-align: right;">Restante</th>
                            </tr>
                        </thead>
                        <tbody>${htmlDetalles}</tbody>
                    </table>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <h4 style="font-size: 0.88rem; font-weight: 700; margin-bottom: 6px;">Retenciones Fiscales DGII</h4>
                        ${htmlRet}
                    </div>
                    <div>
                        <h4 style="font-size: 0.88rem; font-weight: 700; margin-bottom: 6px;">Distribución de Pagos (Split)</h4>
                        ${htmlPagos}
                    </div>
                </div>
            `;
        } else {
            cuerpo.innerHTML = `<div style="color: red; padding: 20px;">${data.mensaje}</div>`;
        }
    } catch (err) {
        cuerpo.innerHTML = `<div style="color: red; padding: 20px;">Error al cargar comprobante: ${err.message}</div>`;
    }
}

function cerrarModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
}

// ============================================================================
// LIQUIDACIÓN DE RETENCIONES DGII A TESORERÍA (MOVIMIENTOS BANCARIOS)
// ============================================================================

function abrirModalLiquidarDGII() {
    const modal = document.getElementById('modalLiquidarRetencionesDGII');
    if (!modal) return;
    modal.classList.add('active');
}

async function procesarLiquidacionDGII(e) {
    e.preventDefault();
    const btn = document.getElementById('btnGuardarLiquidacionDGII');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Procesando transferencia DGII...';
    }

    const formData = new FormData(e.target);
    formData.append('accion', 'liquidar_retenciones_dgii');

    try {
        const resp = await fetch('procesar_cxp.php', {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();

        if (data && data.ok) {
            alert('¡Liquidación DGII Exitosa!\n' + data.mensaje);
            cerrarModal('modalLiquidarRetencionesDGII');
            window.location.reload();
        } else {
            alert('Error al liquidar retenciones DGII: ' + (data.mensaje || 'Error desconocido.'));
        }
    } catch (err) {
        alert('Error de conexión con el servidor: ' + err.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = '🏛️ Liquidar y Registrar Transferencia';
        }
    }
}
