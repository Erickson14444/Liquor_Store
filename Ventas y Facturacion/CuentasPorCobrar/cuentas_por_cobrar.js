/**
 * ============================================================================
 * Lógica Reactiva: Proceso 7 - Cuentas por Cobrar (CxC) y Notas de Débito B03
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/CuentasPorCobrar/cuentas_por_cobrar.js
 * ============================================================================
 */

let CLIENTE_ACTUAL_SELECCION = null;
let MONEDA_ACTUAL_SELECCION = null;
let SIMBOLO_ACTUAL_SELECCION = 'RD$';
let SALDO_FAVOR_CXC_DISPONIBLE = 0.00;
let AJUSTE_SALDO_CXC_ACTUAL = 0.00;
let procesandoCobro = false;

let FACTURAS_SELECCIONADAS = new Map();
let PAGOS_SPLIT_COBROS = [];
let FACTURAS_CARGO_CACHE = [];
let timerBusqFacturaCxc = null;

document.addEventListener('DOMContentLoaded', () => {
    filtrarTablaCxc();

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            ['modalCobro', 'modalAjusteCxc', 'modalCargo', 'modalDetalleRecibo'].forEach(cerrarModal);
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
// TABS Y FILTROS (CARTERA Y NOTAS DE DÉBITO B03)
// ============================================================================
function cambiarTabCxc(tabId, btn) {
    document.querySelectorAll('.cxc-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.cxc-tab-content').forEach(c => c.classList.remove('active'));
    if (btn) btn.classList.add('active');
    const target = document.getElementById(`tab-${tabId}`);
    if (target) target.classList.add('active');
}

function filtrarTablaCxc() {
    const texto = (document.getElementById('filtroTexto')?.value || '').toLowerCase().trim();
    const clienteId = document.getElementById('filtroCliente')?.value || '';
    const estado = document.getElementById('filtroEstado')?.value || 'PENDIENTES';

    const filas = document.querySelectorAll('.fila-cxc');
    let visibles = 0;

    filas.forEach(f => {
        const rowClienteId = f.getAttribute('data-cliente-id') || '';
        const rowDoc = (f.getAttribute('data-documento') || '').toLowerCase();
        const rowNcf = (f.getAttribute('data-ncf') || '').toLowerCase();
        const rowNom = (f.getAttribute('data-cliente-nombre') || '').toLowerCase();
        const rowEstado = f.getAttribute('data-estado') || '';
        const rowVencido = f.getAttribute('data-vencido') === '1';

        const coincideTexto = !texto || rowDoc.includes(texto) || rowNcf.includes(texto) || rowNom.includes(texto);
        const coincideCliente = !clienteId || rowClienteId === clienteId;

        let coincideEstado = true;
        if (estado === 'PENDIENTES') {
            coincideEstado = ['PENDIENTE', 'PAGADA_PARCIAL'].includes(rowEstado);
        } else if (estado === 'VENCIDAS') {
            coincideEstado = ['PENDIENTE', 'PAGADA_PARCIAL'].includes(rowEstado) && rowVencido;
        } else if (estado === 'PAGADA') {
            coincideEstado = (rowEstado === 'PAGADA');
        } else if (estado === 'TERMINALES') {
            coincideEstado = ['ANULADA', 'INCOBRABLE'].includes(rowEstado);
        }

        if (coincideTexto && coincideCliente && coincideEstado) {
            f.style.display = '';
            visibles++;
        } else {
            f.style.display = 'none';
        }
    });

    const badge = document.getElementById('badgeTotalCxc');
    if (badge) badge.textContent = `${visibles} deuda(s)`;
}

function limpiarFiltrosCxc() {
    document.getElementById('filtroTexto').value = '';
    document.getElementById('filtroCliente').value = '';
    document.getElementById('filtroEstado').value = 'PENDIENTES';
    filtrarTablaCxc();
}

function filtrarTablaNotasDebito() {
    const q = (document.getElementById('filtroTextoNd')?.value || '').toLowerCase().trim();
    const estado = document.getElementById('filtroEstadoNd')?.value || 'TODAS';
    const filas = document.querySelectorAll('.fila-nd');
    let visibles = 0;

    filas.forEach(f => {
        const txt = f.getAttribute('data-busqueda') || '';
        const est = f.getAttribute('data-estado') || '';

        const okTxt = !q || txt.includes(q);
        let okEst = true;
        if (estado === 'PENDIENTES') okEst = ['PENDIENTE', 'PAGADA_PARCIAL'].includes(est);
        else if (estado === 'PAGADA') okEst = (est === 'PAGADA');

        if (okTxt && okEst) {
            f.style.display = '';
            visibles++;
        } else {
            f.style.display = 'none';
        }
    });

    const badge = document.getElementById('badgeTotalNd');
    if (badge) badge.textContent = `${visibles} emitida(s)`;
}

// ============================================================================
// SELECCIÓN CONSOLIDADA POR CLIENTE Y MONEDA
// ============================================================================
function alCambiarSeleccionItemCxc(chk) {
    const id = parseInt(chk.value, 10);
    const clienteId = chk.getAttribute('data-cliente');
    const saldo = parseFloat(chk.getAttribute('data-saldo')) || 0.00;
    const fila = chk.closest('tr');
    const clienteNom = fila?.getAttribute('data-cliente-nombre') || '';
    const documento = fila?.getAttribute('data-documento') || '';
    const ncf = fila?.getAttribute('data-ncf') || '';
    const concepto = fila?.getAttribute('data-concepto') || 'Deuda Crédito';
    const monedaId = parseInt(fila?.getAttribute('data-moneda-id') || '1', 10);
    const monedaSim = fila?.getAttribute('data-moneda-simbolo') || 'RD$';

    if (chk.checked) {
        if (
            (CLIENTE_ACTUAL_SELECCION && CLIENTE_ACTUAL_SELECCION !== clienteId) ||
            (MONEDA_ACTUAL_SELECCION && MONEDA_ACTUAL_SELECCION !== monedaId)
        ) {
            alert('Solo puede consolidar deudas de un mismo cliente y una misma moneda en un recibo.');
            chk.checked = false;
            return;
        }

        CLIENTE_ACTUAL_SELECCION = clienteId;
        MONEDA_ACTUAL_SELECCION = monedaId;
        SIMBOLO_ACTUAL_SELECCION = monedaSim;

        FACTURAS_SELECCIONADAS.set(id, {
            id,
            cliente_id: clienteId,
            cliente_nombre: clienteNom,
            documento,
            ncf,
            concepto,
            saldo,
            moneda_id: monedaId,
            moneda_simbolo: monedaSim
        });
    } else {
        FACTURAS_SELECCIONADAS.delete(id);
        if (FACTURAS_SELECCIONADAS.size === 0) {
            CLIENTE_ACTUAL_SELECCION = null;
            MONEDA_ACTUAL_SELECCION = null;
            SIMBOLO_ACTUAL_SELECCION = 'RD$';
        }
    }

    actualizarBarraSeleccionCxc();
}

function toggleSelectAllCxc(masterChk) {
    const filasVisibles = document.querySelectorAll('.fila-cxc:not([style*="display: none"])');
    if (!masterChk.checked) {
        limpiarSeleccionCxc();
        return;
    }

    let primerCliente = null;
    let primeraMoneda = null;
    let mezclado = false;

    filasVisibles.forEach(f => {
        const chk = f.querySelector('.chk-cxc-item');
        if (!chk) return;
        const cli = chk.getAttribute('data-cliente');
        const mon = parseInt(f.getAttribute('data-moneda-id') || '1', 10);
        if (!primerCliente) {
            primerCliente = cli;
            primeraMoneda = mon;
        } else if (primerCliente !== cli || primeraMoneda !== mon) {
            mezclado = true;
        }
    });

    if (mezclado) {
        alert('Las deudas visibles pertenecen a varios clientes o monedas distintas. Filtre primero por un cliente específico.');
        masterChk.checked = false;
        return;
    }

    filasVisibles.forEach(f => {
        const chk = f.querySelector('.chk-cxc-item');
        if (chk && !chk.checked) {
            chk.checked = true;
            alCambiarSeleccionItemCxc(chk);
        }
    });
}

function limpiarSeleccionCxc() {
    FACTURAS_SELECCIONADAS.clear();
    CLIENTE_ACTUAL_SELECCION = null;
    MONEDA_ACTUAL_SELECCION = null;
    SIMBOLO_ACTUAL_SELECCION = 'RD$';
    document.querySelectorAll('.chk-cxc-item').forEach(c => c.checked = false);
    const master = document.getElementById('chkSelectAll');
    if (master) master.checked = false;
    actualizarBarraSeleccionCxc();
}

function actualizarBarraSeleccionCxc() {
    const bar = document.getElementById('cxcFloatingBar');
    const btnTop = document.getElementById('btnTopCobrarSeleccion');
    const txtCantTop = document.getElementById('txtTopCantSeleccionadas');
    const txtCant = document.getElementById('txtSeleccionadasCant');
    const txtCli = document.getElementById('txtSeleccionadasCliente');
    const txtTot = document.getElementById('txtSeleccionadasTotal');

    const totalDocs = FACTURAS_SELECCIONADAS.size;
    let sumaSaldo = 0.00;
    let nombreCli = '—';

    FACTURAS_SELECCIONADAS.forEach(item => {
        sumaSaldo += item.saldo;
        nombreCli = item.cliente_nombre;
    });

    if (totalDocs > 0) {
        if (txtCant) txtCant.textContent = totalDocs;
        if (txtCantTop) txtCantTop.textContent = totalDocs;
        if (txtCli) txtCli.textContent = nombreCli;
        if (txtTot) txtTot.textContent = fmtMoney(sumaSaldo, SIMBOLO_ACTUAL_SELECCION);
        if (bar) bar.classList.add('active');
        if (btnTop) btnTop.style.display = 'inline-flex';
    } else {
        if (bar) bar.classList.remove('active');
        if (btnTop) btnTop.style.display = 'none';
    }
}

function iniciarCobroUnico(id) {
    limpiarSeleccionCxc();
    const chk = document.querySelector(`.chk-cxc-item[value="${id}"]`);
    if (chk) {
        chk.checked = true;
        alCambiarSeleccionItemCxc(chk);
    }
    abrirModalCobro();
}

// ============================================================================
// MODAL COBRO: SPLIT UNIVERSAL (CONSOLIDACIÓN DE EFECTIVO)
// ============================================================================
function obtenerBancosCompatiblesCxc() {
    const monId = MONEDA_ACTUAL_SELECCION || 1;
    const filt = CATALOGO_BANCOS.filter(b => parseInt(b.moneda_id, 10) === parseInt(monId, 10));
    return filt.length > 0 ? filt : CATALOGO_BANCOS;
}

function obtenerMetodoPorCodigo(cod) {
    return CATALOGO_METODOS.find(m => String(m.codigo).toUpperCase() === cod) || CATALOGO_METODOS[0];
}

function obtenerFaltanteCobro() {
    let totalFacturas = 0.00;
    document.querySelectorAll('.fila-cobro-factura').forEach(f => {
        totalFacturas = round2(totalFacturas + (parseFloat(f.querySelector('.inp-monto-cobrar').value) || 0));
    });
    let totalRecibido = 0.00;
    PAGOS_SPLIT_COBROS.forEach(p => {
        totalRecibido = round2(totalRecibido + Math.max(0, (Number(p.monto) || 0) - (Number(p.monto_devuelta) || 0)));
    });
    return Math.max(0, round2(totalFacturas - totalRecibido));
}

async function abrirModalCobro() {
    if (FACTURAS_SELECCIONADAS.size === 0) {
        alert('Debe seleccionar al menos una deuda para cobrar.');
        return;
    }

    const tbody = document.getElementById('tbodyFacturasCobro');
    if (!tbody) return;
    tbody.innerHTML = '';
    PAGOS_SPLIT_COBROS = [];
    SALDO_FAVOR_CXC_DISPONIBLE = 0.00;

    const sim = SIMBOLO_ACTUAL_SELECCION || 'RD$';
    let clienteNombre = '';
    let totalCobrar = 0.00;

    FACTURAS_SELECCIONADAS.forEach(item => {
        clienteNombre = item.cliente_nombre;
        totalCobrar += item.saldo;

        const tr = document.createElement('tr');
        tr.className = 'fila-cobro-factura';
        tr.setAttribute('data-cxc-id', item.id);
        tr.setAttribute('data-saldo-anterior', item.saldo);

        tr.innerHTML = `
            <td><strong style="color: var(--text-dark);">${escHtml(item.documento)}</strong></td>
            <td><span style="font-family: var(--font-mono); font-size: 0.8rem; color: var(--accent-gold); font-weight: 600;">${escHtml(item.ncf || '—')}</span></td>
            <td><div style="font-size: 0.8rem;">${escHtml(item.concepto || 'Deuda Crédito')}</div></td>
            <td style="text-align: right; font-family: var(--font-mono);">${fmtMoney(item.saldo, sim)}</td>
            <td style="text-align: right;">
                <input type="number" step="0.01" class="form-control inp-monto-cobrar"
                       value="${item.saldo.toFixed(2)}" max="${item.saldo}" min="0.01"
                       style="font-size: 0.88rem; font-weight: 700; text-align: right;"
                       oninput="recalcularTotalesCobro()" />
            </td>
            <td style="text-align: right; font-family: var(--font-mono); font-weight: 700;" class="celda-saldo-restante-cobro">
                ${fmtMoney(0, sim)}
            </td>
        `;
        tbody.appendChild(tr);
    });

    document.getElementById('modalCobroClienteNombre').textContent = clienteNombre;
    const lblSf = document.getElementById('modalCobroSaldoFavorInfo');
    if (lblSf) lblSf.textContent = '';

    try {
        const resp = await fetch(`procesar_cxc.php?accion=obtener_deudas_cliente&cliente_id=${encodeURIComponent(CLIENTE_ACTUAL_SELECCION)}&moneda_id=${encodeURIComponent(MONEDA_ACTUAL_SELECCION || 1)}`);
        const data = await leerJsonSeguro(resp);
        if (data.ok && Number(data.saldo_a_favor_disponible || 0) > 0) {
            SALDO_FAVOR_CXC_DISPONIBLE = Number(data.saldo_a_favor_disponible);
            if (lblSf) lblSf.textContent = `✨ Saldo a favor CxC disponible: ${fmtMoney(SALDO_FAVOR_CXC_DISPONIBLE, sim)}`;
        }
    } catch (e) { }

    const metIni = (SESION_CAJA_ACTIVA && SESION_CAJA_ACTIVA.id)
        ? obtenerMetodoPorCodigo('EFECTIVO')
        : obtenerMetodoPorCodigo('TRANSFERENCIA');

    agregarLineaSplitCobro(metIni ? metIni.id : 1, round2(totalCobrar));

    recalcularTotalesCobro();
    document.getElementById('modalCobro').classList.add('active');
}

function agregarLineaSplitCobro(metodoId = null, monto = null) {
    const yaTieneEfectivo = PAGOS_SPLIT_COBROS.some(p => p.codigo === 'EFECTIVO');
    let metObj = null;

    if (metodoId) {
        metObj = CATALOGO_METODOS.find(m => parseInt(m.id, 10) === parseInt(metodoId, 10)) || CATALOGO_METODOS[0];
    } else if (!yaTieneEfectivo && SESION_CAJA_ACTIVA && SESION_CAJA_ACTIVA.id) {
        metObj = obtenerMetodoPorCodigo('EFECTIVO');
    } else {
        metObj = CATALOGO_METODOS.find(m => String(m.codigo).toUpperCase() === 'TRANSFERENCIA')
            || CATALOGO_METODOS.find(m => String(m.codigo).toUpperCase() !== 'EFECTIVO')
            || CATALOGO_METODOS[0];
    }

    const cod = metObj ? String(metObj.codigo).toUpperCase() : 'EFECTIVO';
    const bancos = obtenerBancosCompatiblesCxc();
    const montoInicial = (monto !== null) ? Number(monto) : obtenerFaltanteCobro();

    if (cod === 'EFECTIVO' && yaTieneEfectivo) {
        const efExistente = PAGOS_SPLIT_COBROS.find(p => p.codigo === 'EFECTIVO');
        efExistente.monto = round2((Number(efExistente.monto) || 0) + (montoInicial || 0));
        renderizarSplitCobros();
        recalcularTotalesCobro();
        return;
    }

    PAGOS_SPLIT_COBROS.push({
        id_temp: Date.now() + Math.floor(Math.random() * 10000),
        metodo_pago_id: metObj ? parseInt(metObj.id, 10) : 1,
        codigo: cod,
        monto: montoInicial || 0,
        monto_devuelta: 0.00,
        cuenta_bancaria_id: (cod === 'CHEQUE') ? 0 : (bancos[0] ? parseInt(bancos[0].id, 10) : 0),
        referencia: '',
        banco_origen: '',
        num_cheque: '',
        banco_emisor: '',
        tipo_tarjeta: 'CREDITO',
        marca_tarjeta: 'VISA',
        ultimos_4: '',
        autorizacion: '',
        lote: '',
        origen_saldo: 'RECIBO_CXC_ANTERIOR',
        codigo_credito: ''
    });

    renderizarSplitCobros();
    recalcularTotalesCobro();
}

function eliminarLineaSplitCobro(idTemp) {
    if (PAGOS_SPLIT_COBROS.length <= 1) {
        alert('Debe mantener al menos un instrumento de pago para el cobro.');
        return;
    }
    PAGOS_SPLIT_COBROS = PAGOS_SPLIT_COBROS.filter(p => p.id_temp !== idTemp);
    renderizarSplitCobros();
    recalcularTotalesCobro();
}

function renderizarSplitCobros() {
    const cont = document.getElementById('contenedorSplitCobros');
    if (!cont) return;
    cont.innerHTML = '';

    const bancos = obtenerBancosCompatiblesCxc();
    const sim = SIMBOLO_ACTUAL_SELECCION || 'RD$';

    PAGOS_SPLIT_COBROS.forEach(p => {
        const div = document.createElement('div');
        div.className = 'split-pago-row';

        let optionsMet = '';
        CATALOGO_METODOS.forEach(m => {
            const sel = (parseInt(m.id, 10) === parseInt(p.metodo_pago_id, 10)) ? 'selected' : '';
            optionsMet += `<option value="${m.id}" data-codigo="${escHtml(m.codigo)}" ${sel}>${escHtml(m.descripcion)}</option>`;
        });

        if (p.codigo === 'TRANSFERENCIA' && bancos.length > 0 && !bancos.some(b => parseInt(b.id, 10) === parseInt(p.cuenta_bancaria_id, 10))) {
            p.cuenta_bancaria_id = parseInt(bancos[0].id, 10);
        }

        let camposExtras = '';
        const cod = p.codigo;

        if (cod === 'EFECTIVO') {
            const avisoCaja = (!SESION_CAJA_ACTIVA || !SESION_CAJA_ACTIVA.id)
                ? '<span style="font-size: 0.75rem; color: #b53d32; font-weight: 700; white-space: nowrap;">⚠️ Sin caja abierta</span>'
                : '<span style="font-size: 0.78rem; color: #2e7d52; font-weight: 700; white-space: nowrap;">💵 Caja Mostrador</span>';
            camposExtras = `
                <div style="display: flex; gap: 10px; align-items: center; min-width: 0;">
                    ${avisoCaja}
                    <input type="number" step="0.01" min="0" class="form-control" placeholder="Devuelta (opcional)" value="${p.monto_devuelta || ''}"
                           style="font-size: 0.8rem; font-family: var(--font-mono); width: 155px; flex-shrink: 0;"
                           oninput="alCambiarDevueltaSplit(${p.id_temp}, this.value)" title="Devuelta o cambio entregado al cliente" />
                </div>`;
        } else if (cod === 'TARJETA') {
            camposExtras = `
                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap; min-width: 0;">
                    <select class="form-control" style="font-size: 0.78rem; width: 92px;" onchange="alCambiarPropSplit(${p.id_temp}, 'tipo_tarjeta', this.value)">
                        <option value="CREDITO" ${p.tipo_tarjeta === 'CREDITO' ? 'selected' : ''}>Crédito</option>
                        <option value="DEBITO" ${p.tipo_tarjeta === 'DEBITO' ? 'selected' : ''}>Débito</option>
                    </select>
                    <select class="form-control" style="font-size: 0.78rem; width: 105px;" onchange="alCambiarPropSplit(${p.id_temp}, 'marca_tarjeta', this.value)">
                        <option value="VISA" ${p.marca_tarjeta === 'VISA' ? 'selected' : ''}>Visa</option>
                        <option value="MASTERCARD" ${p.marca_tarjeta === 'MASTERCARD' ? 'selected' : ''}>Mastercard</option>
                        <option value="AMEX" ${p.marca_tarjeta === 'AMEX' ? 'selected' : ''}>Amex</option>
                        <option value="OTRA" ${p.marca_tarjeta === 'OTRA' ? 'selected' : ''}>Otra</option>
                    </select>
                    <input type="text" class="form-control" placeholder="Últ. 4 *" value="${escHtml(p.ultimos_4)}" maxlength="4" style="font-size: 0.78rem; width: 76px; font-family: var(--font-mono);" oninput="this.value=this.value.replace(/\\D/g,'').slice(0,4); alCambiarPropSplit(${p.id_temp}, 'ultimos_4', this.value)" required />
                    <input type="text" class="form-control" placeholder="No. Autorización *" value="${escHtml(p.autorizacion)}" style="font-size: 0.78rem; flex: 1; min-width: 110px;" oninput="alCambiarPropSplit(${p.id_temp}, 'autorizacion', this.value)" required />
                </div>`;
        } else if (cod === 'TRANSFERENCIA') {
            const optionsBancos = bancos.map(b =>
                `<option value="${b.id}" ${parseInt(b.id, 10) === parseInt(p.cuenta_bancaria_id, 10) ? 'selected' : ''}>${escHtml(b.nombre_banco)} (${escHtml(b.numero_cuenta)} • ${escHtml(b.moneda_iso || 'DOP')})</option>`
            ).join('');
            camposExtras = `
                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap; min-width: 0;">
                    <select class="form-control" style="font-size: 0.78rem; flex: 1.2; min-width: 150px;" onchange="alCambiarPropSplit(${p.id_temp}, 'cuenta_bancaria_id', parseInt(this.value, 10))">${optionsBancos}</select>
                    <input type="text" class="form-control" placeholder="Banco Origen" value="${escHtml(p.banco_origen)}" style="font-size: 0.78rem; flex: 0.8; min-width: 100px;" oninput="alCambiarPropSplit(${p.id_temp}, 'banco_origen', this.value)" />
                    <input type="text" class="form-control" placeholder="No. Referencia TRF *" value="${escHtml(p.referencia)}" style="font-size: 0.78rem; flex: 0.9; min-width: 115px; font-family: var(--font-mono);" oninput="alCambiarPropSplit(${p.id_temp}, 'referencia', this.value.trim())" required />
                </div>`;
        } else if (cod === 'CHEQUE') {
            const optionsBancos = '<option value="0">-- En custodia (Sin depositar) --</option>' + bancos.map(b =>
                `<option value="${b.id}" ${parseInt(b.id, 10) === parseInt(p.cuenta_bancaria_id, 10) ? 'selected' : ''}>${escHtml(b.nombre_banco)} (${escHtml(b.moneda_iso || 'DOP')})</option>`
            ).join('');
            camposExtras = `
                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap; min-width: 0;">
                    <input type="text" class="form-control" placeholder="Banco Emisor *" value="${escHtml(p.banco_emisor)}" style="font-size: 0.78rem; flex: 1; min-width: 105px;" oninput="alCambiarPropSplit(${p.id_temp}, 'banco_emisor', this.value)" required />
                    <input type="text" class="form-control" placeholder="No. Cheque *" value="${escHtml(p.num_cheque)}" style="font-size: 0.78rem; width: 105px; font-family: var(--font-mono);" oninput="alCambiarPropSplit(${p.id_temp}, 'num_cheque', this.value.trim())" required />
                    <select class="form-control" style="font-size: 0.76rem; flex: 1; min-width: 130px;" onchange="alCambiarPropSplit(${p.id_temp}, 'cuenta_bancaria_id', parseInt(this.value, 10))">${optionsBancos}</select>
                </div>`;
        } else if (cod === 'SALDO_FAVOR') {
            camposExtras = `
                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap; min-width: 0;">
                    <select class="form-control" style="font-size: 0.78rem; flex: 1; min-width: 150px;" onchange="alCambiarOrigenSaldoSplit(${p.id_temp}, this.value)">
                        <option value="RECIBO_CXC_ANTERIOR" ${p.origen_saldo === 'RECIBO_CXC_ANTERIOR' ? 'selected' : ''}>Excedente CxC (Disp: ${fmtMoney(SALDO_FAVOR_CXC_DISPONIBLE, sim)})</option>
                        <option value="DEVOLUCION_B04" ${p.origen_saldo === 'DEVOLUCION_B04' ? 'selected' : ''}>Nota de Crédito B04 (Devolución)</option>
                    </select>
                    ${p.origen_saldo === 'DEVOLUCION_B04'
                    ? `<input type="text" class="form-control" placeholder="Código / NCF B04 *" value="${escHtml(p.codigo_credito)}" style="font-size: 0.78rem; flex: 1; min-width: 120px; text-transform: uppercase; font-family: var(--font-mono);" oninput="alCambiarPropSplit(${p.id_temp}, 'codigo_credito', this.value.trim().toUpperCase())" required />`
                    : ''}
                </div>`;
        } else {
            camposExtras = `<div style="font-size: 0.8rem; color: var(--text-muted);">Aplicación directa</div>`;
        }

        div.innerHTML = `
            <div>
                <select class="form-control" style="font-size: 0.82rem; font-weight: 600;" onchange="alCambiarMetodoSplitCobro(${p.id_temp}, this)">
                    ${optionsMet}
                </select>
            </div>
            ${camposExtras}
            <div>
                <input type="number" step="0.01" class="form-control inp-monto-split-cxc" value="${p.monto.toFixed(2)}" min="0.01"
                       style="font-family: var(--font-mono); font-weight: 700; text-align: right; font-size: 0.88rem;"
                       oninput="alCambiarMontoSplitCobro(${p.id_temp}, this.value)" />
            </div>
            <div style="display: flex; justify-content: center;">
                <button type="button" class="split-pago-del-btn" onclick="eliminarLineaSplitCobro(${p.id_temp})" title="Quitar instrumento">✕</button>
            </div>
        `;
        cont.appendChild(div);
    });
}

function alCambiarPropSplit(idTemp, prop, val) {
    const item = PAGOS_SPLIT_COBROS.find(p => p.id_temp === idTemp);
    if (item) item[prop] = val;
}

function alCambiarOrigenSaldoSplit(idTemp, val) {
    const item = PAGOS_SPLIT_COBROS.find(p => p.id_temp === idTemp);
    if (!item) return;
    item.origen_saldo = val;
    if (val === 'RECIBO_CXC_ANTERIOR') item.codigo_credito = '';
    renderizarSplitCobros();
}

function alCambiarMetodoSplitCobro(idTemp, select) {
    const item = PAGOS_SPLIT_COBROS.find(p => p.id_temp === idTemp);
    if (!item) return;

    const opt = select.options[select.selectedIndex];
    const codigo = String(opt.getAttribute('data-codigo') || 'EFECTIVO').toUpperCase();

    if (['EFECTIVO', 'TARJETA'].includes(codigo) && (!SESION_CAJA_ACTIVA || !SESION_CAJA_ACTIVA.id)) {
        alert('⚠️ Atención: Para cobros en Efectivo o Tarjeta necesitas un turno de caja ABIERTO en Control de Caja. Para cobros bancarios usa Transferencia o Cheque.');
    }

    if (codigo === 'EFECTIVO') {
        const efExistente = PAGOS_SPLIT_COBROS.find(p => p.id_temp !== idTemp && p.codigo === 'EFECTIVO');
        if (efExistente) {
            efExistente.monto = round2((Number(efExistente.monto) || 0) + (Number(item.monto) || 0));
            efExistente.monto_devuelta = round2((Number(efExistente.monto_devuelta) || 0) + (Number(item.monto_devuelta) || 0));
            PAGOS_SPLIT_COBROS = PAGOS_SPLIT_COBROS.filter(p => p.id_temp !== idTemp);
            renderizarSplitCobros();
            recalcularTotalesCobro();
            alert('Ya existe una línea en Efectivo; el monto se consolidó automáticamente en ella.');
            return;
        }
    }

    item.metodo_pago_id = parseInt(select.value, 10);
    item.codigo = codigo;
    if (codigo !== 'EFECTIVO') item.monto_devuelta = 0.00;
    if (codigo === 'CHEQUE') item.cuenta_bancaria_id = 0;

    renderizarSplitCobros();
    recalcularTotalesCobro();
}

function alCambiarMontoSplitCobro(idTemp, val) {
    const item = PAGOS_SPLIT_COBROS.find(p => p.id_temp === idTemp);
    if (!item) return;
    item.monto = parseFloat(val) || 0.00;
    recalcularTotalesCobro();
}

function alCambiarDevueltaSplit(idTemp, val) {
    const item = PAGOS_SPLIT_COBROS.find(p => p.id_temp === idTemp);
    if (!item) return;
    item.monto_devuelta = Math.max(0, parseFloat(val) || 0.00);
    recalcularTotalesCobro();
}

function autoCuadrarSplitCobro() {
    let totalCobrar = 0.00;
    document.querySelectorAll('.fila-cobro-factura').forEach(f => {
        totalCobrar = round2(totalCobrar + (parseFloat(f.querySelector('.inp-monto-cobrar').value) || 0));
    });

    if (PAGOS_SPLIT_COBROS.length > 0) {
        PAGOS_SPLIT_COBROS[0].monto = totalCobrar;
        PAGOS_SPLIT_COBROS[0].monto_devuelta = 0.00;
        for (let i = 1; i < PAGOS_SPLIT_COBROS.length; i++) {
            PAGOS_SPLIT_COBROS[i].monto = 0.00;
        }
        renderizarSplitCobros();
        recalcularTotalesCobro();
    }
}

function recalcularTotalesCobro() {
    const sim = SIMBOLO_ACTUAL_SELECCION || 'RD$';
    let totalCobrarFacturas = 0.00;

    document.querySelectorAll('.fila-cobro-factura').forEach(f => {
        const saldoAnt = parseFloat(f.getAttribute('data-saldo-anterior')) || 0;
        const inpMonto = f.querySelector('.inp-monto-cobrar');
        let monto = parseFloat(inpMonto.value) || 0.00;

        if (monto > saldoAnt) {
            monto = saldoAnt;
            inpMonto.value = saldoAnt.toFixed(2);
        }
        totalCobrarFacturas = round2(totalCobrarFacturas + monto);

        const saldoRestante = Math.max(0, round2(saldoAnt - monto));
        const celdaSaldo = f.querySelector('.celda-saldo-restante-cobro');
        if (celdaSaldo) {
            celdaSaldo.textContent = fmtMoney(saldoRestante, sim);
            celdaSaldo.style.color = (saldoRestante === 0) ? '#2e7d52' : '#b53d32';
        }
    });

    if (PAGOS_SPLIT_COBROS.length === 1) {
        const inpUnico = document.querySelector('.split-pago-row .inp-monto-split-cxc');
        if (inpUnico && document.activeElement !== inpUnico && PAGOS_SPLIT_COBROS[0].monto_devuelta === 0) {
            PAGOS_SPLIT_COBROS[0].monto = totalCobrarFacturas;
            inpUnico.value = totalCobrarFacturas.toFixed(2);
        }
    }

    let totalRecibidoNeto = 0.00;
    PAGOS_SPLIT_COBROS.forEach(p => {
        totalRecibidoNeto = round2(totalRecibidoNeto + Math.max(0, (Number(p.monto) || 0) - (Number(p.monto_devuelta) || 0)));
    });

    const excedente = Math.max(0, round2(totalRecibidoNeto - totalCobrarFacturas));

    document.getElementById('modalTotalCobrar').textContent = fmtMoney(totalCobrarFacturas, sim);
    document.getElementById('modalTotalRecibidoSplit').textContent = fmtMoney(totalRecibidoNeto, sim);
    document.getElementById('modalTotalExcedente').textContent = fmtMoney(excedente, sim);
    document.getElementById('lblTotalInstrumentos').textContent = fmtMoney(totalRecibidoNeto, sim);

    const lblCuadre = document.getElementById('lblEstadoCuadreCobro');
    const btnConfirmar = document.getElementById('btnConfirmarCobro');

    if (totalCobrarFacturas > 0 && (totalRecibidoNeto + 0.01) >= totalCobrarFacturas) {
        if (excedente > 0) {
            lblCuadre.innerHTML = `<span style="color: #b78103;">✨ Excedente de ${fmtMoney(excedente, sim)} (Se acreditará como Saldo a Favor)</span>`;
        } else {
            lblCuadre.innerHTML = `<span style="color: #2e7d52;">● Cuadre Exacto (${fmtMoney(totalRecibidoNeto, sim)})</span>`;
        }
        if (btnConfirmar) btnConfirmar.disabled = false;
    } else {
        lblCuadre.innerHTML = `<span style="color: #b53d32;">⚠️ Faltan ${fmtMoney(totalCobrarFacturas - totalRecibidoNeto, sim)} por cubrir</span>`;
        if (btnConfirmar) btnConfirmar.disabled = true;
    }
}

async function procesarLiquidacionCobro(e) {
    e.preventDefault();
    if (procesandoCobro) return;

    const btn = document.getElementById('btnConfirmarCobro');
    const txtOri = btn ? btn.innerHTML : 'EMITIR RECIBO DE INGRESO';

    procesandoCobro = true;
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Procesando Recibo...';
    }

    const facturasPayload = [];
    document.querySelectorAll('.fila-cobro-factura').forEach(f => {
        const cxcId = parseInt(f.getAttribute('data-cxc-id'), 10);
        const monto = round2(parseFloat(f.querySelector('.inp-monto-cobrar').value) || 0);
        if (monto > 0) facturasPayload.push({ cxc_id: cxcId, monto_aplicado: monto });
    });

    const payload = {
        accion: 'registrar_recibo_ingreso',
        cliente_id: CLIENTE_ACTUAL_SELECCION,
        facturas: facturasPayload,
        pagos: PAGOS_SPLIT_COBROS
    };

    try {
        const resp = await fetch('procesar_cxc.php?accion=registrar_recibo_ingreso', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await leerJsonSeguro(resp);

        if (data.ok) {
            alert('¡Éxito! ' + data.mensaje);
            cerrarModal('modalCobro');
            limpiarSeleccionCxc();
            window.location.reload();
        } else {
            alert('Error en Cobro: ' + data.mensaje);
        }
    } catch (err) {
        alert('Error de conexión en cobro: ' + err.message);
    } finally {
        procesandoCobro = false;
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = txtOri;
        }
    }
}

// ============================================================================
// MODAL AJUSTE, CONDONACIÓN E INCOBRABLES
// ============================================================================
function abrirModalAjusteCxc(cxcId, clienteNombre, saldoActual, simbolo = 'RD$') {
    AJUSTE_SALDO_CXC_ACTUAL = Number(saldoActual) || 0;
    document.getElementById('formAjusteCxc').reset();
    document.getElementById('ajusteCxcId').value = cxcId;
    document.getElementById('ajusteClienteNombre').textContent = clienteNombre;
    document.getElementById('ajusteSaldoActual').textContent = fmtMoney(AJUSTE_SALDO_CXC_ACTUAL, simbolo);

    const inp = document.getElementById('inputMontoAjusteCxc');
    inp.value = AJUSTE_SALDO_CXC_ACTUAL.toFixed(2);
    inp.setAttribute('max', AJUSTE_SALDO_CXC_ACTUAL.toFixed(2));

    alCambiarTipoAjusteCxc();
    document.getElementById('modalAjusteCxc').classList.add('active');
}

function alCambiarTipoAjusteCxc() {
    const tipo = document.getElementById('tipoAjusteCxc').value;
    const inpMonto = document.getElementById('inputMontoAjusteCxc');
    if (tipo === 'INCOBRABLE') {
        inpMonto.value = AJUSTE_SALDO_CXC_ACTUAL.toFixed(2);
        inpMonto.readOnly = true;
    } else {
        inpMonto.readOnly = false;
    }
}

async function procesarAjusteCxc(e) {
    e.preventDefault();
    const btn = document.getElementById('btnConfirmarAjusteCxc');
    btn.disabled = true;
    btn.textContent = 'Aplicando Ajuste...';

    const formData = new FormData(document.getElementById('formAjusteCxc'));
    formData.append('accion', 'registrar_ajuste');

    try {
        const resp = await fetch('procesar_cxc.php?accion=registrar_ajuste', { method: 'POST', body: formData });
        const data = await leerJsonSeguro(resp);
        if (data.ok) {
            alert('¡Ajuste exitoso! ' + data.mensaje);
            cerrarModal('modalAjusteCxc');
            window.location.reload();
        } else {
            alert('Error al aplicar ajuste: ' + data.mensaje);
        }
    } catch (err) {
        alert('Error de conexión al procesar ajuste: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Aplicar Ajuste Contable';
    }
}

// ============================================================================
// MODAL CARGO / NOTA DE DÉBITO B03 (CON BUSCADOR EN VIVO DE FACTURAS)
// ============================================================================
function abrirModalCargoNotaDebito() {
    document.getElementById('formCargo').reset();
    limpiarClienteSeleccionadoCxc();
    document.getElementById('modalCargo').classList.add('active');
}

function abrirDropdownClientesCxc() {
    filtrarClientesCxc(document.getElementById('inputBuscarClienteCxc')?.value || '');
    const dd = document.getElementById('dropdownClientesCxc');
    if (dd) dd.style.display = 'block';
}

function filtrarClientesCxc(query) {
    const dd = document.getElementById('dropdownClientesCxc');
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
        <div class="cxc-client-option" onclick="seleccionarClienteCxc('${escHtml(c.persona_id)}')" style="padding: 8px 12px; border-bottom: 1px solid #f2ede4; cursor: pointer;" onmouseover="this.style.background='#faf5eb'" onmouseout="this.style.background='#ffffff'">
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

async function seleccionarClienteCxc(personaId) {
    const cl = (Array.isArray(CLIENTES_LISTA) ? CLIENTES_LISTA : []).find(c => String(c.persona_id) === String(personaId));
    if (!cl) return;

    document.getElementById('cxcCargoClienteId').value = cl.persona_id;
    document.getElementById('txtNombreClienteCxc').textContent = cl.nombre_razon_social;
    document.getElementById('txtDetalleClienteCxc').textContent = `(${cl.documento_identidad || 'S/D'} • ${cl.categoria_cliente})`;
    document.getElementById('boxBusquedaClienteCxc').style.display = 'none';
    document.getElementById('badgeClienteElegidoCxc').style.display = 'flex';
    document.getElementById('dropdownClientesCxc').style.display = 'none';

    limpiarFacturaSeleccionadaCxc(false);

    const inpFac = document.getElementById('inputBuscarFacturaCxc');
    if (inpFac) {
        inpFac.disabled = false;
        inpFac.placeholder = '🔍 Buscar por N° factura, NCF, fecha (dd/mm/aaaa) o monto...';
    }

    await buscarFacturasClienteCxc(true);
}

function limpiarClienteSeleccionadoCxc() {
    document.getElementById('cxcCargoClienteId').value = '';
    document.getElementById('boxBusquedaClienteCxc').style.display = 'block';
    document.getElementById('badgeClienteElegidoCxc').style.display = 'none';
    document.getElementById('dropdownClientesCxc').style.display = 'none';
    const input = document.getElementById('inputBuscarClienteCxc');
    if (input) input.value = '';

    FACTURAS_CARGO_CACHE = [];
    limpiarFacturaSeleccionadaCxc(false);

    const inpFac = document.getElementById('inputBuscarFacturaCxc');
    if (inpFac) {
        inpFac.disabled = true;
        inpFac.placeholder = 'Primero seleccione un cliente...';
    }
    const lblCnt = document.getElementById('lblContadorFacturasCargo');
    if (lblCnt) lblCnt.textContent = '';
}

function abrirDropdownFacturasCxc() {
    if (!document.getElementById('cxcCargoClienteId')?.value) return;
    buscarFacturasClienteCxc(false);
}

function programarBusquedaFacturasCxc() {
    clearTimeout(timerBusqFacturaCxc);
    timerBusqFacturaCxc = setTimeout(() => buscarFacturasClienteCxc(false), 200);
}

async function buscarFacturasClienteCxc(autoElegirSiUnica = false) {
    const clienteId = document.getElementById('cxcCargoClienteId')?.value || '';
    const dd = document.getElementById('dropdownFacturasCxc');
    const lblCnt = document.getElementById('lblContadorFacturasCargo');
    const q = (document.getElementById('inputBuscarFacturaCxc')?.value || '').trim();

    if (!clienteId || !dd) return;

    dd.style.display = 'block';
    dd.innerHTML = '<div style="padding: 10px 14px; text-align: center; color: var(--text-muted); font-size: 0.80rem;">Buscando facturas...</div>';

    try {
        const params = new URLSearchParams({
            accion: 'obtener_facturas_cliente',
            cliente_id: clienteId,
            q: q
        });
        const resp = await fetch(`procesar_cxc.php?${params.toString()}`);
        const data = await leerJsonSeguro(resp);
        const facs = Array.isArray(data.facturas) ? data.facturas : [];
        const tot = Number(data.total_facturas ?? facs.length);
        FACTURAS_CARGO_CACHE = facs;

        if (lblCnt) {
            lblCnt.textContent = tot > 0
                ? `${tot} factura(s) fiscal(es) disponible(s)`
                : 'Sin facturas fiscales';
        }

        if (facs.length === 0) {
            dd.innerHTML = `<div style="padding: 10px 14px; text-align: center; color: #b53d32; font-size: 0.80rem;">${q ? 'Sin coincidencias para «' + escHtml(q) + '».' : 'Este cliente no tiene facturas fiscales válidas.'}</div>`;
            return;
        }

        if (autoElegirSiUnica && tot === 1 && facs.length === 1 && q === '') {
            seleccionarFacturaCargoCxc(facs[0].id);
            return;
        }

        dd.innerHTML = facs.map(f => `
            <div onclick="seleccionarFacturaCargoCxc(${f.id})" style="padding: 8px 12px; border-bottom: 1px solid #f2ede4; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 8px;" onmouseover="this.style.background='#faf5eb'" onmouseout="this.style.background='#ffffff'">
                <div style="min-width: 0;">
                    <div style="font-weight: 700; color: #211813; font-size: 0.83rem; font-family: var(--font-mono);">
                        ${escHtml(f.numero_factura)} <span style="color: #a66e2e;">• ${escHtml(f.ncf)}</span>
                    </div>
                    <div style="font-size: 0.72rem; color: #7a6b5d; margin-top: 2px;">
                        Fecha: ${escHtml(f.fecha_fmt)} • ${escHtml(f.condicion_pago || 'CONTADO')} (${escHtml(f.estado || '')})
                    </div>
                </div>
                <div style="font-family: var(--font-mono); font-weight: 700; font-size: 0.82rem; color: #2e7d52; white-space: nowrap;">
                    ${escHtml(f.moneda_simbolo || 'RD$')} ${Number(f.monto_total).toFixed(2)}
                </div>
            </div>
        `).join('');
    } catch (err) {
        dd.innerHTML = '<div style="padding: 10px 14px; text-align: center; color: #b53d32; font-size: 0.80rem;">Error de conexión al buscar facturas.</div>';
    }
}

function seleccionarFacturaCargoCxc(facturaId) {
    const f = FACTURAS_CARGO_CACHE.find(item => parseInt(item.id, 10) === parseInt(facturaId, 10));
    if (!f) return;

    document.getElementById('cxcCargoFacturaId').value = f.id;
    document.getElementById('txtFacturaElegidaCxc').textContent = `${f.numero_factura} • ${f.ncf}`;
    document.getElementById('txtFacturaElegidaDetCxc').textContent = `(${f.fecha_fmt} • ${f.moneda_simbolo || 'RD$'} ${Number(f.monto_total).toFixed(2)})`;
    document.getElementById('boxBusquedaFacturaCxc').style.display = 'none';
    document.getElementById('badgeFacturaElegidaCxc').style.display = 'flex';
    document.getElementById('dropdownFacturasCxc').style.display = 'none';
}

function limpiarFacturaSeleccionadaCxc(enfocar = false) {
    const hid = document.getElementById('cxcCargoFacturaId');
    const inp = document.getElementById('inputBuscarFacturaCxc');
    const box = document.getElementById('boxBusquedaFacturaCxc');
    const bdg = document.getElementById('badgeFacturaElegidaCxc');
    const dd = document.getElementById('dropdownFacturasCxc');

    if (hid) hid.value = '';
    if (inp) inp.value = '';
    if (box) box.style.display = 'block';
    if (bdg) bdg.style.display = 'none';
    if (dd) dd.style.display = 'none';

    if (enfocar && inp && !inp.disabled) {
        inp.focus();
        buscarFacturasClienteCxc(false);
    }
}

document.addEventListener('click', (e) => {
    const boxCli = document.getElementById('boxBusquedaClienteCxc');
    const ddCli = document.getElementById('dropdownClientesCxc');
    if (boxCli && ddCli && !boxCli.contains(e.target)) ddCli.style.display = 'none';

    const boxFac = document.getElementById('boxBusquedaFacturaCxc');
    const ddFac = document.getElementById('dropdownFacturasCxc');
    if (boxFac && ddFac && !boxFac.contains(e.target)) ddFac.style.display = 'none';
});

async function procesarRegistroCargo(e) {
    e.preventDefault();
    if (!document.getElementById('cxcCargoClienteId').value) {
        alert('Debe seleccionar un cliente.');
        return;
    }
    if (!document.getElementById('cxcCargoFacturaId').value) {
        alert('Debe buscar y seleccionar la factura fiscal que modifica esta Nota de Débito (B03).');
        document.getElementById('inputBuscarFacturaCxc')?.focus();
        return;
    }

    const btn = document.getElementById('btnGuardarCargo');
    btn.disabled = true;
    btn.textContent = 'Emitiendo B03...';

    const formData = new FormData(document.getElementById('formCargo'));
    formData.append('accion', 'registrar_nota_debito_cargo');

    try {
        const resp = await fetch('procesar_cxc.php?accion=registrar_nota_debito_cargo', { method: 'POST', body: formData });
        const data = await leerJsonSeguro(resp);
        if (data.ok) {
            alert('¡Éxito! ' + data.mensaje);
            cerrarModal('modalCargo');
            window.location.reload();
        } else {
            alert('Error al registrar cargo: ' + data.mensaje);
        }
    } catch (err) {
        alert('Error de conexión: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Emitir Nota de Débito (B03)';
    }
}

// ============================================================================
// MODAL DETALLE RECIBO DE INGRESO
// ============================================================================
async function verDetalleRecibo(reciboId) {
    const modal = document.getElementById('modalDetalleRecibo');
    const body = document.getElementById('bodyDetalleRecibo');
    body.innerHTML = '<div style="text-align: center; padding: 24px;">Cargando detalle del recibo...</div>';
    modal.classList.add('active');

    try {
        const resp = await fetch(`procesar_cxc.php?accion=ver_detalle_recibo&recibo_id=${encodeURIComponent(reciboId)}`);
        const data = await leerJsonSeguro(resp);
        if (!data.ok) {
            body.innerHTML = `<div style="color: #b53d32; padding: 16px;">Error: ${escHtml(data.mensaje)}</div>`;
            return;
        }

        const r = data.recibo;
        const sim = r.moneda_simbolo || 'RD$';

        const htmlFacturas = (data.facturas || []).map(f => `
            <tr>
                <td><strong>${escHtml(f.numero_documento)}</strong></td>
                <td style="font-family: var(--font-mono);">${escHtml(f.ncf || '—')}</td>
                <td style="text-align: right; font-family: var(--font-mono);">${fmtMoney(f.saldo_anterior, sim)}</td>
                <td style="text-align: right; font-family: var(--font-mono); font-weight: 700; color: #2e7d52;">${fmtMoney(f.monto_aplicado, sim)}</td>
                <td style="text-align: right; font-family: var(--font-mono);">${fmtMoney(f.saldo_restante, sim)}</td>
            </tr>
        `).join('');

        const htmlPagos = (data.pagos || []).map(p => {
            let detalle = '';
            if (p.metodo_codigo === 'TARJETA') {
                detalle = `${escHtml(p.tipo_tarjeta)} ${escHtml(p.marca_tarjeta)} (****${escHtml(p.ultimos_4_digitos)}) Aut: ${escHtml(p.numero_autorizacion)}`;
            } else if (p.metodo_codigo === 'TRANSFERENCIA') {
                detalle = `${escHtml(p.nombre_banco || p.banco_origen || 'Banco')} • Ref: ${escHtml(p.numero_referencia)}`;
            } else if (p.metodo_codigo === 'CHEQUE') {
                detalle = `Chq #${escHtml(p.numero_cheque)} (${escHtml(p.banco_emisor)})`;
            } else if (p.metodo_codigo === 'SALDO_FAVOR') {
                detalle = `${escHtml(p.origen_saldo || 'Saldo a Favor')} #${escHtml(p.documento_origen_id || '')}`;
            } else {
                detalle = p.sesion_caja_id ? 'Gaveta de Caja POS' : 'Cobro Efectivo';
            }
            const devTxt = Number(p.monto_devuelta || 0) > 0 ? ` <span style="color:#8a7b70;">(Devuelta: ${fmtMoney(p.monto_devuelta, sim)})</span>` : '';
            return `
                <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #eae3d5; font-size: 0.85rem;">
                    <div><strong>${escHtml(p.metodo_nombre)}</strong> <span style="color: var(--text-muted); font-size: 0.78rem;">(${detalle})</span>${devTxt}</div>
                    <div style="font-family: var(--font-mono); font-weight: 700;">${fmtMoney(p.monto_pagado, sim)}</div>
                </div>`;
        }).join('');

        body.innerHTML = `
            <div style="border-bottom: 1px solid #eae3d5; padding-bottom: 14px; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h2 style="font-size: 1.25rem; font-weight: 700; color: #a66e2e; margin-bottom: 2px;">Recibo de Ingreso ${escHtml(r.numero_recibo)}</h2>
                    <div style="font-size: 0.82rem; color: var(--text-muted);">Fecha: ${escHtml(String(r.fecha_recibo || '').slice(0, 16))}</div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 0.76rem; color: var(--text-muted); text-transform: uppercase;">Total Cobrado</div>
                    <div style="font-size: 1.4rem; font-weight: 700; font-family: var(--font-mono); color: #2e7d52;">${fmtMoney(r.monto_total_recibido, sim)}</div>
                    ${Number(r.saldo_a_favor_generado) > 0 ? `<div style="font-size: 0.78rem; color: #b78103; font-weight: 700;">+ ${fmtMoney(r.saldo_a_favor_generado, sim)} Saldo a Favor</div>` : ''}
                </div>
            </div>

            <div style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 0.85rem;">
                <div>Cliente: <strong>${escHtml(r.cliente_nombre)}</strong></div>
                <div>RNC / Cédula: <strong>${escHtml(r.cliente_rnc || '—')}</strong></div>
                <div>Cajero: <strong>${escHtml(r.cajero_nombre || r.cajero_username)}</strong></div>
                <div>Canal: <strong>${r.caja_nombre ? 'Caja: ' + escHtml(r.caja_nombre) : 'Canal Bancario / Externo'}</strong></div>
            </div>

            <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 8px;">Facturas y Deudas Saldadas</h4>
            <div class="table-wrapper" style="margin-bottom: 16px; border: 1px solid #eae3d5; border-radius: 6px;">
                <table class="data-table" style="font-size: 0.82rem;">
                    <thead>
                        <tr>
                            <th>Documento</th><th>NCF</th>
                            <th class="text-right">Saldo Ant.</th>
                            <th class="text-right">Monto Aplicado</th>
                            <th class="text-right">Saldo Restante</th>
                        </tr>
                    </thead>
                    <tbody>${htmlFacturas}</tbody>
                </table>
            </div>

            <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 8px;">Instrumentos de Pago Recibidos (Split)</h4>
            <div style="background: #ffffff; border: 1px solid #eae3d5; border-radius: 6px; padding: 10px 14px;">${htmlPagos}</div>
        `;
    } catch (e) {
        body.innerHTML = `<div style="color: #b53d32; padding: 16px;">Error de conexión: ${escHtml(e.message)}</div>`;
    }
}

function cerrarModal(modalId) {
    const m = document.getElementById(modalId);
    if (m) m.classList.remove('active');
}