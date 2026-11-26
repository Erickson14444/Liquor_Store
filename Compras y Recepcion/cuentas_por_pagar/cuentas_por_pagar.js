/**
 * ============================================================================
 * JAVASCRIPT DINÁMICO • PROCESO 2: CUENTAS POR PAGAR (CXP) Y RETENCIONES DGII
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/cuentas_por_pagar/cuentas_por_pagar.js
 * ============================================================================
 */

let FACTURAS_SELECCIONADAS = new Map();
let PROVEEDOR_ACTUAL_SELECCION = null;
let MONEDA_ACTUAL_SELECCION = null;
let SIMBOLO_ACTUAL_SELECCION = 'RD$';
let AJUSTE_SALDO_ACTUAL_NUM = 0.00;
let procesandoDesembolso = false;

let RETENCIONES_SELECCIONADAS = [];
let PAGOS_SPLIT_LINEAS = [];
let RESUMEN_DGII_PERIODO = { itbis_monto: 0, itbis_count: 0, isr_monto: 0, isr_count: 0, total_monto: 0, total_count: 0 };

document.addEventListener('DOMContentLoaded', () => {
    aplicarFiltrosCxp();

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            ['modalGastoManual', 'modalDesembolso', 'modalAjuste', 'modalDetalleEgreso', 'modalLiquidarRetencionesDGII']
                .forEach(cerrarModal);
        }
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

function fmtMoney(val, sim = SIMBOLO_ACTUAL_SELECCION || 'RD$') {
    return `${sim} ${(Number(val) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function round2(n) {
    return Math.round((Number(n) || 0) * 100) / 100;
}

// ============================================================================
// TABS Y FILTROS
// ============================================================================

function cambiarTab(tabId, btn) {
    document.querySelectorAll('.cxp-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.cxp-tab-content').forEach(c => c.classList.remove('active'));
    if (btn) btn.classList.add('active');
    const target = document.getElementById(tabId);
    if (target) target.classList.add('active');
}

function limpiarFiltrosCxp() {
    document.getElementById('filtroProveedor').value = '';
    document.getElementById('filtroEstado').value = 'PENDIENTES';
    document.getElementById('filtroBusqueda').value = '';
    aplicarFiltrosCxp();
}

function aplicarFiltrosCxp() {
    const provId = document.getElementById('filtroProveedor')?.value || '';
    const estado = document.getElementById('filtroEstado')?.value || 'PENDIENTES';
    const busq = (document.getElementById('filtroBusqueda')?.value || '').toLowerCase().trim();

    const filas = document.querySelectorAll('.fila-cxp');
    let visibles = 0;

    filas.forEach(f => {
        const fProv = f.getAttribute('data-proveedor-id');
        const fEstado = f.getAttribute('data-estado');
        const fVencido = f.getAttribute('data-vencido') === '1';
        const fHoy = f.getAttribute('data-hoy') === '1';
        const fTexto = f.textContent.toLowerCase();

        let visible = true;
        if (provId && fProv !== provId) visible = false;

        if (visible && estado === 'PENDIENTES') {
            visible = (fEstado === 'PENDIENTE' || fEstado === 'PAGADA_PARCIAL');
        } else if (visible && estado === 'VENCIDAS') {
            visible = (fEstado === 'PENDIENTE' || fEstado === 'PAGADA_PARCIAL') && fVencido;
        } else if (visible && estado === 'HOY') {
            visible = (fEstado === 'PENDIENTE' || fEstado === 'PAGADA_PARCIAL') && fHoy;
        }

        if (visible && busq) visible = fTexto.includes(busq);

        f.style.display = visible ? '' : 'none';
        if (visible) visibles++;
    });

    const badge = document.getElementById('badgeTotalCxp');
    if (badge) badge.textContent = `${visibles} obligación(es)`;
}

// ============================================================================
// SELECCIÓN CONSOLIDADA DE FACTURAS
// ============================================================================

function toggleSelectAll(masterChk) {
    const visibleChks = document.querySelectorAll('.fila-cxp:not([style*="display: none"]) .chk-cxp-item');
    if (!masterChk.checked) {
        limpiarSeleccion();
        return;
    }

    let primerProv = null;
    let primeraMon = null;
    visibleChks.forEach(chk => {
        const fila = chk.closest('tr');
        const prov = chk.getAttribute('data-proveedor');
        const mon = parseInt(fila.getAttribute('data-moneda-id') || '1', 10);

        if (!primerProv) {
            primerProv = prov;
            primeraMon = mon;
        }
        if (prov === primerProv && mon === primeraMon) {
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
    const saldo = parseFloat(chk.getAttribute('data-saldo')) || 0;
    const fila = chk.closest('tr');
    const provNombre = fila.getAttribute('data-proveedor-nombre') || 'Proveedor';
    const factura = fila.getAttribute('data-factura') || 'S/F';
    const ncf = fila.getAttribute('data-ncf') || 'N/A';
    const monedaId = parseInt(fila.getAttribute('data-moneda-id') || '1', 10);
    const monedaSim = fila.getAttribute('data-moneda-simbolo') || 'RD$';
    const monedaIso = fila.getAttribute('data-moneda-iso') || 'DOP';
    const tasa = parseFloat(fila.getAttribute('data-tasa') || '1') || 1;

    if (chk.checked) {
        if (
            (PROVEEDOR_ACTUAL_SELECCION && PROVEEDOR_ACTUAL_SELECCION !== provId) ||
            (MONEDA_ACTUAL_SELECCION && MONEDA_ACTUAL_SELECCION !== monedaId)
        ) {
            alert('Solo puede consolidar facturas de un mismo suplidor y una misma moneda. Se reiniciará la selección anterior.');
            limpiarSeleccion();
            chk.checked = true;
        }

        PROVEEDOR_ACTUAL_SELECCION = provId;
        MONEDA_ACTUAL_SELECCION = monedaId;
        SIMBOLO_ACTUAL_SELECCION = monedaSim;

        FACTURAS_SELECCIONADAS.set(cxpId, {
            id: cxpId,
            proveedor_id: provId,
            proveedor_nombre: provNombre,
            numero_factura: factura,
            ncf: ncf,
            saldo: saldo,
            moneda_id: monedaId,
            moneda_simbolo: monedaSim,
            moneda_iso: monedaIso,
            tasa: tasa
        });
    } else {
        FACTURAS_SELECCIONADAS.delete(cxpId);
        if (FACTURAS_SELECCIONADAS.size === 0) {
            PROVEEDOR_ACTUAL_SELECCION = null;
            MONEDA_ACTUAL_SELECCION = null;
            SIMBOLO_ACTUAL_SELECCION = 'RD$';
        }
    }

    if (actualizar) actualizarBarraSeleccion();
}

function actualizarBarraSeleccion() {
    const count = FACTURAS_SELECCIONADAS.size;
    let total = 0.00;
    let provNom = '—';

    FACTURAS_SELECCIONADAS.forEach(item => {
        total += item.saldo;
        provNom = item.proveedor_nombre;
    });

    const bar = document.getElementById('cxpSelectionBar');
    const countTop = document.getElementById('countSeleccionadasTop');
    const btnTop = document.getElementById('btnDesembolsoTop');

    if (countTop) countTop.textContent = count;
    if (btnTop) btnTop.disabled = (count === 0);

    if (count > 0) {
        bar.style.display = 'flex';
        document.getElementById('txtSeleccionadasCount').textContent = count;
        document.getElementById('txtSeleccionadasProveedor').textContent = provNom;
        document.getElementById('txtSeleccionadasTotal').textContent = fmtMoney(total, SIMBOLO_ACTUAL_SELECCION);
    } else {
        bar.style.display = 'none';
    }
}

function limpiarSeleccion() {
    FACTURAS_SELECCIONADAS.clear();
    PROVEEDOR_ACTUAL_SELECCION = null;
    MONEDA_ACTUAL_SELECCION = null;
    SIMBOLO_ACTUAL_SELECCION = 'RD$';
    document.querySelectorAll('.chk-cxp-item').forEach(c => c.checked = false);
    const master = document.getElementById('chkSelectAll');
    if (master) master.checked = false;
    actualizarBarraSeleccion();
}

function liquidarFacturaIndividual(cxpId) {
    limpiarSeleccion();
    const chk = document.querySelector(`.chk-cxp-item[value="${cxpId}"]`);
    if (chk) {
        chk.checked = true;
        alCambiarSeleccionItem(chk, true);
        abrirModalDesembolso();
    }
}

// ============================================================================
// MODAL GASTO / SERVICIO DIRECTO
// ============================================================================

function abrirModalGastoManual() {
    document.getElementById('formGastoManual').reset();
    limpiarProveedorSeleccionadoCxp();
    alCambiarCondicionGasto();
    document.getElementById('modalGastoManual').classList.add('active');
}

function getProveedoresListaCxp() {
    if (Array.isArray(window.PROVEEDORES_LISTA)) return window.PROVEEDORES_LISTA;
    if (typeof PROVEEDORES_LISTA !== 'undefined' && Array.isArray(PROVEEDORES_LISTA)) return PROVEEDORES_LISTA;
    return [];
}

function abrirDropdownProveedoresCxp() {
    const dropdown = document.getElementById('dropdownProveedoresCxp');
    if (!dropdown) return;
    filtrarProveedoresCxp(document.getElementById('inputBuscarProveedorCxp')?.value || '');
    dropdown.style.display = 'block';
}

function filtrarProveedoresCxp(query) {
    const dropdown = document.getElementById('dropdownProveedoresCxp');
    if (!dropdown) return;

    const q = (query || '').toLowerCase().trim();
    const filtrados = getProveedoresListaCxp().filter(p => {
        if (!q) return true;
        return (p.nombre_razon_social || '').toLowerCase().includes(q)
            || (p.documento_identidad || p.rnc || '').toLowerCase().includes(q);
    });

    if (filtrados.length === 0) {
        dropdown.innerHTML = '<div style="padding: 10px 14px; color: var(--text-muted); font-size: 0.80rem; text-align: center;">No se encontraron suplidores coincidentes</div>';
    } else {
        dropdown.innerHTML = filtrados.slice(0, 40).map(p => {
            const dias = parseInt(p.dias_credito_habitual || 0, 10);
            const rnc = p.documento_identidad || p.rnc || 'S/RNC';
            return `
                <div class="opcion-prov-item" onclick="seleccionarProveedorCxp('${escHtml(p.persona_id)}')" style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f2ede4; display: flex; justify-content: space-between; align-items: center;" onmouseover="this.style.background='#faf4ea'" onmouseout="this.style.background='transparent'">
                    <div>
                        <strong style="font-size: 0.82rem; color: #211813;">${escHtml(p.nombre_razon_social)}</strong>
                        <div style="font-size: 0.72rem; color: #7a6b5d; font-family: var(--font-mono);">RNC: ${escHtml(rnc)}</div>
                    </div>
                    <span style="font-size: 0.70rem; background: #eef5fb; color: #1e6091; font-weight: 600; padding: 2px 6px; border-radius: 4px;">${dias} d. crédito</span>
                </div>
            `;
        }).join('');
    }
    dropdown.style.display = 'block';
}

function seleccionarProveedorCxp(proveedorId) {
    document.getElementById('suplidorGastoId').value = proveedorId;
    const p = getProveedoresListaCxp().find(item => String(item.persona_id) === String(proveedorId));
    if (p) {
        const rnc = p.documento_identidad || p.rnc || 'S/RNC';
        const dias = parseInt(p.dias_credito_habitual || 0, 10);
        document.getElementById('badgeProveedorNombreCxp').textContent = p.nombre_razon_social || '';
        document.getElementById('badgeProveedorRncCxp').textContent = `(${rnc} • ${dias} días crédito)`;
        const inpDias = document.getElementById('diasCreditoGasto');
        if (inpDias && dias > 0 && document.getElementById('condicionPagoGasto').value === 'CREDITO') {
            inpDias.value = dias;
        }
    }
    document.getElementById('boxBusquedaProveedorCxp').style.display = 'none';
    document.getElementById('badgeProveedorElegidoCxp').style.display = 'flex';
    document.getElementById('dropdownProveedoresCxp').style.display = 'none';
}

function limpiarProveedorSeleccionadoCxp() {
    document.getElementById('suplidorGastoId').value = '';
    const input = document.getElementById('inputBuscarProveedorCxp');
    if (input) input.value = '';
    document.getElementById('badgeProveedorElegidoCxp').style.display = 'none';
    document.getElementById('boxBusquedaProveedorCxp').style.display = 'block';
    document.getElementById('dropdownProveedoresCxp').style.display = 'none';
}

document.addEventListener('click', (e) => {
    const box = document.getElementById('boxBusquedaProveedorCxp');
    const dropdown = document.getElementById('dropdownProveedoresCxp');
    if (dropdown && box && !box.contains(e.target)) dropdown.style.display = 'none';
});

function alCambiarCondicionGasto() {
    const cond = document.getElementById('condicionPagoGasto').value;
    const inpDias = document.getElementById('diasCreditoGasto');
    if (cond === 'CONTADO') {
        inpDias.value = 0;
        inpDias.disabled = true;
    } else {
        inpDias.disabled = false;
        if (parseInt(inpDias.value, 10) <= 0) {
            const provId = document.getElementById('suplidorGastoId').value;
            const p = getProveedoresListaCxp().find(item => String(item.persona_id) === String(provId));
            inpDias.value = (p && parseInt(p.dias_credito_habitual, 10) > 0) ? parseInt(p.dias_credito_habitual, 10) : 30;
        }
    }
}

async function guardarGastoManual(e) {
    e.preventDefault();
    if (!document.getElementById('suplidorGastoId').value) {
        alert('Debe seleccionar un suplidor de la lista.');
        document.getElementById('inputBuscarProveedorCxp')?.focus();
        return;
    }

    const btn = document.getElementById('btnGuardarGasto');
    btn.disabled = true;
    btn.textContent = 'Guardando...';

    const formData = new FormData(e.target);
    formData.append('accion', 'registrar_gasto_manual');

    try {
        const resp = await fetch('procesar_cxp.php', { method: 'POST', body: formData });
        const data = await leerJsonSeguro(resp);
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
// MODAL DESEMBOLSO CONSOLIDADO (SPLIT Y RETENCIONES)
// ============================================================================

function obtenerBancosCompatiblesMoneda() {
    const monId = MONEDA_ACTUAL_SELECCION || 1;
    const filtrados = CATALOGO_BANCOS.filter(b => parseInt(b.moneda_id, 10) === parseInt(monId, 10));
    return filtrados.length > 0 ? filtrados : CATALOGO_BANCOS;
}

function calcularBaseSugeridaRetencion(baseCalculo, totalBruto) {
    if (String(baseCalculo || 'SUBTOTAL').toUpperCase() === 'ITBIS') {
        return round2((totalBruto / 1.18) * 0.18);
    }
    return round2(totalBruto);
}

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
    const sim = SIMBOLO_ACTUAL_SELECCION || 'RD$';

    FACTURAS_SELECCIONADAS.forEach(item => {
        provNombre = item.proveedor_nombre;
        totalBruto += item.saldo;

        const tr = document.createElement('tr');
        tr.className = 'fila-desembolso-factura';
        tr.setAttribute('data-cxp-id', item.id);
        tr.setAttribute('data-saldo-anterior', item.saldo);

        tr.innerHTML = `
            <td><strong style="color: var(--text-dark);">${escHtml(item.numero_factura || 'Documento')}</strong></td>
            <td><span style="font-family: var(--font-mono); font-size: 0.8rem; color: var(--accent-gold); font-weight: 600;">${escHtml(item.ncf || '—')}</span></td>
            <td style="text-align: right; font-family: var(--font-mono);">${fmtMoney(item.saldo, sim)}</td>
            <td style="text-align: right;">
                <input type="number" step="0.01" class="form-control inp-monto-aplicado"
                       value="${item.saldo.toFixed(2)}" max="${item.saldo}" min="0.01"
                       style="font-size: 0.85rem; font-weight: 700; text-align: right;"
                       oninput="recalcularTotalesDesembolso()" />
            </td>
            <td style="text-align: right; font-family: var(--font-mono); color: #b78103;" class="celda-retencion-factura">${fmtMoney(0, sim)}</td>
            <td style="text-align: right; font-family: var(--font-mono); font-weight: 700; color: #2e7d52;" class="celda-neto-factura">${fmtMoney(item.saldo, sim)}</td>
            <td style="text-align: right; font-family: var(--font-mono); font-weight: 700;" class="celda-saldo-restante">${fmtMoney(0, sim)}</td>
        `;
        tbody.appendChild(tr);
    });

    document.getElementById('modalDesembolsoProveedorNombre').textContent = provNombre;

    try {
        const resp = await fetch(`procesar_cxp.php?accion=obtener_facturas_proveedor&proveedor_id=${encodeURIComponent(PROVEEDOR_ACTUAL_SELECCION)}`);
        const data = await leerJsonSeguro(resp);
        if (data.ok && Array.isArray(data.retenciones_sugeridas)) {
            data.retenciones_sugeridas.forEach(rs => {
                agregarLineaRetencion(parseInt(rs.id, 10), rs.base_calculo, parseFloat(rs.porcentaje), false);
            });
        }
    } catch (e) {
        console.warn('No se pudieron cargar retenciones sugeridas:', e);
    }

    const metTransf = CATALOGO_METODOS.find(m => String(m.codigo).toUpperCase() === 'TRANSFERENCIA') || CATALOGO_METODOS[0];
    const metodoDefId = metTransf ? parseInt(metTransf.id, 10) : 1;

    renderizarContenedorRetenciones();
    agregarLineaSplitPago(metodoDefId, totalBruto);

    recalcularTotalesDesembolso();
    document.getElementById('modalDesembolso').classList.add('active');
}

function agregarLineaRetencion(retId = null, base = 'SUBTOTAL', porc = 0, renderizar = true) {
    if (!CATALOGO_RETENCIONES || CATALOGO_RETENCIONES.length === 0) {
        alert('No hay retenciones fiscales activas en el catálogo.');
        return;
    }

    const yaUsadas = new Set(RETENCIONES_SELECCIONADAS.map(r => parseInt(r.retencion_id, 10)));
    if (!retId) {
        const disponible = CATALOGO_RETENCIONES.find(c => !yaUsadas.has(parseInt(c.id, 10)));
        if (!disponible) {
            alert('Ya agregó todas las retenciones fiscales disponibles del catálogo.');
            return;
        }
        retId = parseInt(disponible.id, 10);
        base = disponible.base_calculo;
        porc = parseFloat(disponible.porcentaje);
    } else if (yaUsadas.has(parseInt(retId, 10))) {
        return;
    }

    RETENCIONES_SELECCIONADAS.push({
        id_temp: Date.now() + Math.floor(Math.random() * 10000),
        retencion_id: parseInt(retId, 10),
        base_calculo: String(base || 'SUBTOTAL').toUpperCase(),
        monto_base: 0.00,
        base_manual: false,
        porcentaje: parseFloat(porc) || 0,
        monto_retenido: 0.00
    });

    if (renderizar) {
        renderizarContenedorRetenciones();
        recalcularTotalesDesembolso();
    }
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
        cont.innerHTML = `<div style="font-size: 0.8rem; color: var(--text-muted); padding: 8px 12px; background: #fdfcf9; border: 1px dashed #eae3d5; border-radius: 6px;">No se han aplicado retenciones fiscales a este desembolso (0.00%).</div>`;
        return;
    }

    cont.innerHTML = '';
    const sim = SIMBOLO_ACTUAL_SELECCION || 'RD$';

    RETENCIONES_SELECCIONADAS.forEach(r => {
        const div = document.createElement('div');
        div.className = 'retencion-card';
        div.style.gap = '10px';
        div.style.flexWrap = 'wrap';

        let optionsRet = '';
        CATALOGO_RETENCIONES.forEach(cat => {
            const sel = (parseInt(cat.id, 10) === parseInt(r.retencion_id, 10)) ? 'selected' : '';
            optionsRet += `<option value="${cat.id}" data-base="${escHtml(cat.base_calculo)}" data-porc="${cat.porcentaje}" ${sel}>${escHtml(cat.siglas)} - ${escHtml(cat.nombre)} (${cat.porcentaje}%)</option>`;
        });

        div.innerHTML = `
            <div style="display: flex; gap: 8px; align-items: center; flex: 1; min-width: 240px;">
                <select class="form-control" style="font-size: 0.82rem;" onchange="alCambiarSelectRetencion(${r.id_temp}, this)">
                    ${optionsRet}
                </select>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <span style="font-size: 0.74rem; color: var(--text-muted); white-space: nowrap;">Base (${escHtml(r.base_calculo)}):</span>
                <input type="number" step="0.01" min="0" class="form-control" id="inpBaseRet_${r.id_temp}"
                       value="${r.monto_base.toFixed(2)}"
                       style="width: 112px; font-family: var(--font-mono); font-size: 0.82rem; text-align: right; padding: 5px 8px;"
                       oninput="alCambiarBaseRetencion(${r.id_temp}, this.value)" />
                <div style="font-family: var(--font-mono); font-weight: 700; color: #b78103; min-width: 96px; text-align: right;" id="lblMontoRet_${r.id_temp}">
                    ${fmtMoney(r.monto_retenido, sim)}
                </div>
                <button type="button" class="btn btn-sm btn-outline" onclick="eliminarLineaRetencion(${r.id_temp})" style="color: #b53d32;">✕</button>
            </div>
        `;
        cont.appendChild(div);
    });
}

function alCambiarSelectRetencion(idTemp, select) {
    const nuevoRetId = parseInt(select.value, 10);
    if (RETENCIONES_SELECCIONADAS.some(r => r.id_temp !== idTemp && parseInt(r.retencion_id, 10) === nuevoRetId)) {
        alert('Esa retención fiscal ya está seleccionada en otra línea.');
        renderizarContenedorRetenciones();
        return;
    }

    const item = RETENCIONES_SELECCIONADAS.find(r => r.id_temp === idTemp);
    if (!item) return;

    const opt = select.options[select.selectedIndex];
    item.retencion_id = nuevoRetId;
    item.base_calculo = String(opt.getAttribute('data-base') || 'SUBTOTAL').toUpperCase();
    item.porcentaje = parseFloat(opt.getAttribute('data-porc') || 0);
    item.base_manual = false;

    renderizarContenedorRetenciones();
    recalcularTotalesDesembolso();
}

function alCambiarBaseRetencion(idTemp, val) {
    const item = RETENCIONES_SELECCIONADAS.find(r => r.id_temp === idTemp);
    if (!item) return;
    item.base_manual = true;
    item.monto_base = Math.max(0, parseFloat(val) || 0);
    recalcularTotalesDesembolso();
}

function agregarLineaSplitPago(metodoId = null, monto = 0.00) {
    const metDef = metodoId || (CATALOGO_METODOS.find(m => m.codigo === 'TRANSFERENCIA')?.id) || (CATALOGO_METODOS[0]?.id) || 1;
    const bancos = obtenerBancosCompatiblesMoneda();
    const bancoId = bancos[0] ? parseInt(bancos[0].id, 10) : 0;

    PAGOS_SPLIT_LINEAS.push({
        id_temp: Date.now() + Math.floor(Math.random() * 10000),
        metodo_pago_id: parseInt(metDef, 10),
        monto: Number(monto) || 0,
        cuenta_bancaria_id: bancoId,
        referencia: '',
        titular: 'House & Ice',
        num_cheque: ''
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

    const bancosCompatibles = obtenerBancosCompatiblesMoneda();

    PAGOS_SPLIT_LINEAS.forEach(p => {
        const div = document.createElement('div');
        div.className = 'split-pago-row';

        let optionsMet = '';
        CATALOGO_METODOS.forEach(m => {
            const sel = (parseInt(m.id, 10) === parseInt(p.metodo_pago_id, 10)) ? 'selected' : '';
            optionsMet += `<option value="${m.id}" data-codigo="${escHtml(m.codigo)}" ${sel}>${escHtml(m.descripcion)}</option>`;
        });

        const metSel = CATALOGO_METODOS.find(m => parseInt(m.id, 10) === parseInt(p.metodo_pago_id, 10));
        const codMetodo = metSel ? String(metSel.codigo).toUpperCase() : 'EFECTIVO';

        if (bancosCompatibles.length > 0 && !bancosCompatibles.some(b => parseInt(b.id, 10) === parseInt(p.cuenta_bancaria_id, 10))) {
            p.cuenta_bancaria_id = parseInt(bancosCompatibles[0].id, 10);
        }

        let camposExtras = '';
        if (codMetodo === 'TRANSFERENCIA' || codMetodo === 'CHEQUE') {
            let optionsBancos = '';
            bancosCompatibles.forEach(b => {
                const selB = (parseInt(b.id, 10) === parseInt(p.cuenta_bancaria_id, 10)) ? 'selected' : '';
                optionsBancos += `<option value="${b.id}" ${selB}>${escHtml(b.nombre_banco)} (${escHtml(b.numero_cuenta)} • ${escHtml(b.moneda_iso || 'DOP')})</option>`;
            });

            if (codMetodo === 'TRANSFERENCIA') {
                camposExtras = `
                    <div style="display: flex; gap: 8px; flex: 1;">
                        <select class="form-control" style="font-size: 0.8rem;" onchange="alCambiarBancoSplit(${p.id_temp}, this)">${optionsBancos}</select>
                        <input type="text" class="form-control" placeholder="No. Referencia TRF *" value="${escHtml(p.referencia)}" style="font-size: 0.8rem; font-family: var(--font-mono);" oninput="alCambiarReferenciaSplit(${p.id_temp}, this.value)" required />
                    </div>`;
            } else {
                camposExtras = `
                    <div style="display: flex; gap: 8px; flex: 1;">
                        <select class="form-control" style="font-size: 0.8rem;" onchange="alCambiarBancoSplit(${p.id_temp}, this)">${optionsBancos}</select>
                        <input type="text" class="form-control" placeholder="No. Cheque *" value="${escHtml(p.num_cheque)}" style="font-size: 0.8rem; font-family: var(--font-mono);" oninput="alCambiarChequeSplit(${p.id_temp}, this.value)" required />
                    </div>`;
            }
        } else {
            camposExtras = `<div style="font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center; padding-left: 6px;">Egreso en Efectivo desde Bóveda / Tesorería Externa</div>`;
        }

        div.innerHTML = `
            <div><select class="form-control" style="font-size: 0.82rem; font-weight: 600;" onchange="alCambiarMetodoSplit(${p.id_temp}, this)">${optionsMet}</select></div>
            ${camposExtras}
            <div>
                <input type="number" step="0.01" class="form-control inp-monto-split" value="${p.monto.toFixed(2)}" min="0.01"
                       style="font-family: var(--font-mono); font-weight: 700; text-align: right; font-size: 0.88rem;"
                       oninput="alCambiarMontoSplit(${p.id_temp}, this.value)" />
            </div>
            <div><button type="button" class="btn btn-sm btn-outline" onclick="eliminarLineaSplit(${p.id_temp})" style="color: #b53d32; padding: 4px 8px;">✕</button></div>
        `;
        cont.appendChild(div);
    });
}

function alCambiarMetodoSplit(idTemp, select) {
    const item = PAGOS_SPLIT_LINEAS.find(p => p.id_temp === idTemp);
    if (!item) return;
    item.metodo_pago_id = parseInt(select.value, 10);
    renderizarSplitPagos();
    recalcularTotalesDesembolso();
}

function alCambiarBancoSplit(idTemp, select) {
    const item = PAGOS_SPLIT_LINEAS.find(p => p.id_temp === idTemp);
    if (item) item.cuenta_bancaria_id = parseInt(select.value, 10);
}

function autoCuadrarSplitConNeto() {
    let totalBruto = 0.00;
    document.querySelectorAll('.fila-desembolso-factura').forEach(f => {
        totalBruto += parseFloat(f.querySelector('.inp-monto-aplicado').value) || 0.00;
    });
    let totalRet = 0.00;
    RETENCIONES_SELECCIONADAS.forEach(r => { totalRet += r.monto_retenido || 0.00; });

    const neto = Math.max(0, round2(totalBruto - totalRet));
    if (PAGOS_SPLIT_LINEAS.length > 0) {
        PAGOS_SPLIT_LINEAS[0].monto = neto;
        for (let i = 1; i < PAGOS_SPLIT_LINEAS.length; i++) PAGOS_SPLIT_LINEAS[i].monto = 0.00;
        renderizarSplitPagos();
        recalcularTotalesDesembolso();
    }
}

function alCambiarReferenciaSplit(idTemp, val) {
    const item = PAGOS_SPLIT_LINEAS.find(p => p.id_temp === idTemp);
    if (item) item.referencia = val.trim();
}

function alCambiarChequeSplit(idTemp, val) {
    const item = PAGOS_SPLIT_LINEAS.find(p => p.id_temp === idTemp);
    if (item) item.num_cheque = val.trim();
}

function alCambiarMontoSplit(idTemp, val) {
    const item = PAGOS_SPLIT_LINEAS.find(p => p.id_temp === idTemp);
    if (!item) return;
    item.monto = parseFloat(val) || 0.00;
    recalcularTotalesDesembolso();
}

function recalcularTotalesDesembolso() {
    const sim = SIMBOLO_ACTUAL_SELECCION || 'RD$';
    let totalBrutoAplicado = 0.00;

    const filasFacturas = document.querySelectorAll('.fila-desembolso-factura');
    filasFacturas.forEach(f => {
        const saldoAnt = parseFloat(f.getAttribute('data-saldo-anterior')) || 0;
        const inpMonto = f.querySelector('.inp-monto-aplicado');
        let monto = parseFloat(inpMonto.value) || 0.00;
        if (monto > saldoAnt) {
            monto = saldoAnt;
            inpMonto.value = saldoAnt.toFixed(2);
        }
        totalBrutoAplicado = round2(totalBrutoAplicado + monto);
    });

    let totalRetenido = 0.00;
    RETENCIONES_SELECCIONADAS.forEach(r => {
        if (!r.base_manual) {
            r.monto_base = calcularBaseSugeridaRetencion(r.base_calculo, totalBrutoAplicado);
            const inpBase = document.getElementById(`inpBaseRet_${r.id_temp}`);
            if (inpBase && document.activeElement !== inpBase) inpBase.value = r.monto_base.toFixed(2);
        }
        const montoRet = round2(r.monto_base * (r.porcentaje / 100));
        r.monto_retenido = montoRet;
        totalRetenido = round2(totalRetenido + montoRet);

        const lbl = document.getElementById(`lblMontoRet_${r.id_temp}`);
        if (lbl) lbl.textContent = fmtMoney(montoRet, sim);
    });

    let acumRetVisual = 0.00;
    filasFacturas.forEach((f, idx) => {
        const saldoAnt = parseFloat(f.getAttribute('data-saldo-anterior')) || 0;
        const montoBruto = parseFloat(f.querySelector('.inp-monto-aplicado').value) || 0.00;
        const esUltima = (idx === filasFacturas.length - 1);

        let retFactura = 0.00;
        if (esUltima) {
            retFactura = Math.max(0, round2(totalRetenido - acumRetVisual));
        } else {
            const prop = totalBrutoAplicado > 0 ? (montoBruto / totalBrutoAplicado) : 0;
            retFactura = round2(totalRetenido * prop);
            acumRetVisual = round2(acumRetVisual + retFactura);
        }

        const netoFactura = Math.max(0, round2(montoBruto - retFactura));
        const saldoRestante = Math.max(0, round2(saldoAnt - montoBruto));

        const celdaRet = f.querySelector('.celda-retencion-factura');
        const celdaNeto = f.querySelector('.celda-neto-factura');
        const celdaSaldo = f.querySelector('.celda-saldo-restante');

        if (celdaRet) celdaRet.textContent = fmtMoney(retFactura, sim);
        if (celdaNeto) celdaNeto.textContent = fmtMoney(netoFactura, sim);
        if (celdaSaldo) {
            celdaSaldo.textContent = fmtMoney(saldoRestante, sim);
            celdaSaldo.style.color = (saldoRestante === 0) ? '#2e7d52' : '#b53d32';
        }
    });

    const netoDesembolsar = Math.max(0, round2(totalBrutoAplicado - totalRetenido));

    document.getElementById('modalTotalBrutoFacturas').textContent = fmtMoney(totalBrutoAplicado, sim);
    document.getElementById('modalTotalRetenciones').textContent = fmtMoney(totalRetenido, sim);
    document.getElementById('modalTotalNetoDesembolsar').textContent = fmtMoney(netoDesembolsar, sim);

    if (PAGOS_SPLIT_LINEAS.length === 1) {
        PAGOS_SPLIT_LINEAS[0].monto = netoDesembolsar;
        const inpMontoUnico = document.querySelector('.split-pago-row .inp-monto-split');
        if (inpMontoUnico && document.activeElement !== inpMontoUnico) {
            inpMontoUnico.value = netoDesembolsar.toFixed(2);
        }
    }

    let totalSplit = 0.00;
    PAGOS_SPLIT_LINEAS.forEach(p => { totalSplit += (Number(p.monto) || 0); });
    totalSplit = round2(totalSplit);

    document.getElementById('lblTotalSplit').textContent = fmtMoney(totalSplit, sim);

    const dif = round2(Math.abs(totalSplit - netoDesembolsar));
    const lblCuadre = document.getElementById('lblEstadoCuadreSplit');
    const btnConfirmar = document.getElementById('btnConfirmarDesembolso');

    if (totalRetenido > totalBrutoAplicado) {
        lblCuadre.innerHTML = `<span style="color: #b53d32;">⚠️ Las retenciones superan el total a cancelar</span>`;
        if (btnConfirmar) btnConfirmar.disabled = true;
    } else if (totalBrutoAplicado > 0 && dif <= 0.02) {
        lblCuadre.innerHTML = `<span style="color: #2e7d52;">● Cuadre Exacto (${fmtMoney(totalSplit, sim)})</span>`;
        if (btnConfirmar) btnConfirmar.disabled = false;
    } else if (totalSplit < netoDesembolsar) {
        lblCuadre.innerHTML = `<span style="color: #b53d32;">⚠️ Faltan ${fmtMoney(netoDesembolsar - totalSplit, sim)} por asignar en el Split</span>`;
        if (btnConfirmar) btnConfirmar.disabled = true;
    } else {
        lblCuadre.innerHTML = `<span style="color: #b78103;">⚠️ El Split excede el Neto por ${fmtMoney(totalSplit - netoDesembolsar, sim)}</span>`;
        if (btnConfirmar) btnConfirmar.disabled = true;
    }
}

async function procesarLiquidacionDesembolso(e) {
    e.preventDefault();
    if (procesandoDesembolso) return;

    const btn = document.getElementById('btnConfirmarDesembolso');
    const txtOriginal = btn ? btn.innerHTML : 'CONFIRMAR Y EMITIR PAGO';

    procesandoDesembolso = true;
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Procesando Desembolso...';
    }

    const facturasPayload = [];
    const filas = Array.from(document.querySelectorAll('.fila-desembolso-factura'));

    let totalBrutoAplicar = 0.00;
    filas.forEach(f => {
        totalBrutoAplicar = round2(totalBrutoAplicar + (parseFloat(f.querySelector('.inp-monto-aplicado').value) || 0));
    });

    const acumPorRet = new Map();
    RETENCIONES_SELECCIONADAS.forEach(r => acumPorRet.set(r.id_temp, 0.00));

    filas.forEach((f, idx) => {
        const cxpId = parseInt(f.getAttribute('data-cxp-id'), 10);
        const montoBruto = round2(parseFloat(f.querySelector('.inp-monto-aplicado').value) || 0);
        const prop = totalBrutoAplicar > 0 ? (montoBruto / totalBrutoAplicar) : 0;
        const esUltima = (idx === filas.length - 1);

        let totalRetFactura = 0.00;
        const retsFactura = RETENCIONES_SELECCIONADAS.map(r => {
            const yaAcum = acumPorRet.get(r.id_temp) || 0.00;
            const montoRetItem = esUltima
                ? Math.max(0, round2(r.monto_retenido - yaAcum))
                : round2(r.monto_retenido * prop);
            acumPorRet.set(r.id_temp, round2(yaAcum + montoRetItem));
            totalRetFactura = round2(totalRetFactura + montoRetItem);

            return {
                retencion_id: r.retencion_id,
                base_calculo: r.base_calculo,
                monto_base: round2(r.monto_base * prop),
                porcentaje: r.porcentaje,
                monto_retenido: montoRetItem
            };
        });

        const netoDesembolso = Math.max(0, round2(montoBruto - totalRetFactura));

        facturasPayload.push({
            cxp_id: cxpId,
            monto_bruto: montoBruto,
            monto_aplicado: netoDesembolso,
            retenciones: retsFactura
        });
    });

    const payload = {
        accion: 'liquidar_desembolso',
        proveedor_id: PROVEEDOR_ACTUAL_SELECCION,
        facturas: facturasPayload,
        pagos: PAGOS_SPLIT_LINEAS
    };

    try {
        const resp = await fetch('procesar_cxp.php?accion=liquidar_desembolso', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await leerJsonSeguro(resp);

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
        procesandoDesembolso = false;
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = txtOriginal;
        }
    }
}

// ============================================================================
// MODAL AJUSTE, DESCUENTO Y ANULACIÓN CON PIN DE SUPERVISOR
// ============================================================================

function abrirModalAjuste(cxpId, proveedorNombre, saldoActual, simbolo = 'RD$') {
    AJUSTE_SALDO_ACTUAL_NUM = Number(saldoActual) || 0;
    document.getElementById('formAjuste').reset();
    document.getElementById('ajusteCxpId').value = cxpId;
    document.getElementById('ajusteProveedorNombre').textContent = proveedorNombre;
    document.getElementById('ajusteSaldoActual').textContent = fmtMoney(AJUSTE_SALDO_ACTUAL_NUM, simbolo);

    const inpMonto = document.getElementById('inputMontoAjuste');
    inpMonto.value = AJUSTE_SALDO_ACTUAL_NUM.toFixed(2);
    inpMonto.setAttribute('max', AJUSTE_SALDO_ACTUAL_NUM.toFixed(2));

    alCambiarTipoAjuste();
    document.getElementById('modalAjuste').classList.add('active');
}

function alCambiarTipoAjuste() {
    const tipo = document.getElementById('tipoAjusteSelect').value;
    const grpNcf = document.getElementById('grupoNcfNotaCredito');
    const inpMonto = document.getElementById('inputMontoAjuste');
    const ayudaMonto = document.getElementById('lblAyudaMontoAjuste');
    const inpNcf = document.getElementById('inputNcfNotaCredito');

    grpNcf.style.display = (tipo === 'NOTA_CREDITO_PROVEEDOR') ? 'block' : 'none';
    inpNcf.required = (tipo === 'NOTA_CREDITO_PROVEEDOR');

    if (tipo === 'ANULACION') {
        inpMonto.value = AJUSTE_SALDO_ACTUAL_NUM.toFixed(2);
        inpMonto.readOnly = true;
        ayudaMonto.textContent = 'La anulación congela permanentemente la obligación cancelando el 100% del saldo pendiente.';
    } else {
        inpMonto.readOnly = false;
        ayudaMonto.textContent = 'Rebaja el saldo de la obligación sin salida de efectivo.';
    }
}

async function guardarAjusteCxp(e) {
    e.preventDefault();
    const btn = document.getElementById('btnGuardarAjuste');
    btn.disabled = true;
    btn.textContent = 'Autorizando...';

    const formData = new FormData(e.target);
    formData.append('accion', 'registrar_ajuste');

    try {
        const resp = await fetch('procesar_cxp.php', { method: 'POST', body: formData });
        const data = await leerJsonSeguro(resp);
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
// MODAL DETALLE COMPROBANTE DE EGRESO
// ============================================================================

async function verDetalleEgreso(egresoId) {
    const modal = document.getElementById('modalDetalleEgreso');
    const cuerpo = document.getElementById('cuerpoDetalleEgreso');
    cuerpo.innerHTML = '<div style="text-align: center; padding: 30px; color: var(--text-muted);">Cargando comprobante...</div>';
    modal.classList.add('active');

    try {
        const resp = await fetch(`procesar_cxp.php?accion=ver_detalle_egreso&egreso_id=${encodeURIComponent(egresoId)}`);
        const data = await leerJsonSeguro(resp);
        if (!data.ok) {
            cuerpo.innerHTML = `<div style="color: #b53d32; padding: 20px;">${escHtml(data.mensaje)}</div>`;
            return;
        }

        const cab = data.cabecera;
        const sim = cab.moneda_simbolo || 'RD$';
        document.getElementById('txtDetalleNumComprobante').textContent = cab.numero_comprobante;

        const htmlDetalles = (data.detalles || []).map(d => `
            <tr>
                <td><strong>${escHtml(d.numero_factura)}</strong></td>
                <td style="font-family: var(--font-mono); color: var(--accent-gold);">${escHtml(d.ncf)}</td>
                <td style="text-align: right; font-family: var(--font-mono);">${fmtMoney(d.saldo_anterior, sim)}</td>
                <td style="text-align: right; font-family: var(--font-mono); font-weight: 700; color: #2e7d52;">${fmtMoney(d.monto_aplicado, sim)}</td>
                <td style="text-align: right; font-family: var(--font-mono); color: #b78103;">${fmtMoney(d.monto_retenciones, sim)}</td>
                <td style="text-align: right; font-family: var(--font-mono);">${fmtMoney(d.saldo_restante, sim)}</td>
            </tr>
        `).join('');

        const htmlRet = (Array.isArray(data.retenciones) && data.retenciones.length > 0)
            ? data.retenciones.map(r => `
                <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0ede6; font-size: 0.82rem;">
                    <span>${escHtml(r.siglas)} (${r.porcentaje_aplicado}%) - CxP #${r.cuenta_por_pagar_id} [${escHtml(r.estado || 'PENDIENTE')}]</span>
                    <strong style="font-family: var(--font-mono); color: #b78103;">${fmtMoney(r.monto_retenido, sim)}</strong>
                </div>`).join('')
            : '<div style="font-size: 0.8rem; color: #888;">Sin retenciones fiscales registradas.</div>';

        const htmlPagos = (data.pagos || []).map(p => {
            const infoExtra = p.numero_referencia
                ? `(${escHtml(p.nombre_banco || p.banco_origen || '')} • Ref: ${escHtml(p.numero_referencia)})`
                : (p.numero_cheque ? `(${escHtml(p.banco_emisor || '')} • Cheque #${escHtml(p.numero_cheque)})` : '');
            return `
                <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0ede6; font-size: 0.84rem;">
                    <span><strong>${escHtml(p.metodo_nombre)}</strong> ${infoExtra}</span>
                    <strong style="font-family: var(--font-mono); color: #2e7d52;">${fmtMoney(p.monto_pagado, sim)}</strong>
                </div>`;
        }).join('');

        cuerpo.innerHTML = `
            <div style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px; flex-wrap: wrap; gap: 8px;">
                    <span>Proveedor: <strong>${escHtml(cab.proveedor_nombre)}</strong> (RNC: ${escHtml(cab.proveedor_rnc)})</span>
                    <span>Fecha: <strong>${escHtml(String(cab.fecha_pago || '').slice(0, 16))}</strong></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--text-muted); flex-wrap: wrap; gap: 8px;">
                    <span>Registrado por: <strong>${escHtml(cab.usuario_registro_nombre)}</strong></span>
                    <span>Total Pagado: <strong style="font-family: var(--font-mono); font-size: 1.1rem; color: #2e7d52;">${fmtMoney(cab.monto_total_pagado, sim)}</strong></span>
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
                <div><h4 style="font-size: 0.88rem; font-weight: 700; margin-bottom: 6px;">Retenciones Fiscales DGII</h4>${htmlRet}</div>
                <div><h4 style="font-size: 0.88rem; font-weight: 700; margin-bottom: 6px;">Distribución de Pagos (Split)</h4>${htmlPagos}</div>
            </div>
        `;
    } catch (err) {
        cuerpo.innerHTML = `<div style="color: #b53d32; padding: 20px;">Error al cargar comprobante: ${escHtml(err.message)}</div>`;
    }
}

function cerrarModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
}

// ============================================================================
// LIQUIDACIÓN DE RETENCIONES DGII (POR PERÍODO Y FORMULARIO IT-1 / IR-17)
// ============================================================================

function abrirModalLiquidarDGII() {
    const modal = document.getElementById('modalLiquidarRetencionesDGII');
    if (!modal) return;
    modal.classList.add('active');
    actualizarResumenRetencionesDGII();
}

async function actualizarResumenRetencionesDGII() {
    const periodo = document.getElementById('dgiiPeriodoFiscal')?.value || '';
    const btn = document.getElementById('btnGuardarLiquidacionDGII');
    const lblCnt = document.getElementById('dgiiPrevSelCnt');

    if (btn) btn.disabled = true;
    if (lblCnt) lblCnt.textContent = 'Consultando período...';

    try {
        const resp = await fetch(`procesar_cxp.php?accion=resumen_retenciones_dgii&periodo_fiscal=${encodeURIComponent(periodo)}`);
        const data = await leerJsonSeguro(resp);
        if (!data.ok) {
            if (lblCnt) lblCnt.textContent = data.mensaje || 'Error al consultar período';
            return;
        }

        RESUMEN_DGII_PERIODO = {
            itbis_monto: Number(data.itbis_monto || 0),
            itbis_count: Number(data.itbis_count || 0),
            isr_monto: Number(data.isr_monto || 0),
            isr_count: Number(data.isr_count || 0),
            total_monto: Number(data.total_monto || 0),
            total_count: Number(data.total_count || 0)
        };

        document.getElementById('dgiiPrevItbis').textContent = fmtMoney(RESUMEN_DGII_PERIODO.itbis_monto, 'RD$');
        document.getElementById('dgiiPrevItbisCnt').textContent = `${RESUMEN_DGII_PERIODO.itbis_count} retención(es)`;
        document.getElementById('dgiiPrevIsr').textContent = fmtMoney(RESUMEN_DGII_PERIODO.isr_monto, 'RD$');
        document.getElementById('dgiiPrevIsrCnt').textContent = `${RESUMEN_DGII_PERIODO.isr_count} retención(es)`;

        actualizarMontoSeleccionadoDGII();
    } catch (err) {
        if (lblCnt) lblCnt.textContent = 'Error de conexión al consultar retenciones';
    }
}

function actualizarMontoSeleccionadoDGII() {
    const tipo = document.getElementById('dgiiTipoImpuesto')?.value || 'AMBOS';
    const lblTot = document.getElementById('txtModalTotalRetencionesDGII');
    const lblCnt = document.getElementById('dgiiPrevSelCnt');
    const btn = document.getElementById('btnGuardarLiquidacionDGII');

    let monto = 0.00;
    let cnt = 0;

    if (tipo === 'ITBIS') {
        monto = RESUMEN_DGII_PERIODO.itbis_monto;
        cnt = RESUMEN_DGII_PERIODO.itbis_count;
    } else if (tipo === 'ISR') {
        monto = RESUMEN_DGII_PERIODO.isr_monto;
        cnt = RESUMEN_DGII_PERIODO.isr_count;
    } else {
        monto = RESUMEN_DGII_PERIODO.total_monto;
        cnt = RESUMEN_DGII_PERIODO.total_count;
    }

    if (lblTot) lblTot.textContent = fmtMoney(monto, 'RD$');
    if (lblCnt) {
        lblCnt.textContent = cnt > 0
            ? `${cnt} retención(es) seleccionada(s)`
            : 'Sin retenciones pendientes para esta selección';
    }
    if (btn) {
        btn.disabled = !(cnt > 0 && monto > 0 && (typeof TIENE_BANCOS_DOP === 'undefined' || TIENE_BANCOS_DOP));
    }
}

async function procesarLiquidacionDGII(e) {
    e.preventDefault();
    const btn = document.getElementById('btnGuardarLiquidacionDGII');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Procesando liquidación DGII...';
    }

    const formData = new FormData(e.target);
    formData.append('accion', 'liquidar_retenciones_dgii');

    try {
        const resp = await fetch('procesar_cxp.php', { method: 'POST', body: formData });
        const data = await leerJsonSeguro(resp);
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
            btn.textContent = '🏛️ Liquidar y Registrar Pago DGII';
        }
        actualizarMontoSeleccionadoDGII();
    }
}