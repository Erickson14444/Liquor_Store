/**
 * ============================================================================
 * Lógica Reactiva: Proceso 7 - Cuentas por Cobrar (CxC)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Ventas y Facturacion/CuentasPorCobrar/cuentas_por_cobrar.js
 * ============================================================================
 */

let CLIENTE_ACTUAL_SELECCION = null;
let FACTURAS_SELECCIONADAS = new Map(); // id -> { id, cliente_id, cliente_nombre, documento, ncf, saldo }
let PAGOS_SPLIT_COBROS = [];            // [{ id_temp, metodo_pago_id, codigo, monto, monto_devuelta, ... }]

document.addEventListener('DOMContentLoaded', () => {
    // Inicializar filtros
    filtrarTablaCxc();
});

// ============================================================================
// TABS Y NAVEGACIÓN
// ============================================================================
function cambiarTabCxc(tabId, btn) {
    document.querySelectorAll('.cxc-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.cxc-tab-content').forEach(c => c.classList.remove('active'));

    if (btn) btn.classList.add('active');
    const target = document.getElementById(`tab-${tabId}`);
    if (target) target.classList.add('active');
}

// ============================================================================
// FILTRADO DINÁMICO DE CARTERA
// ============================================================================
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

        // 1. Filtro texto
        const coincideTexto = !texto || rowDoc.includes(texto) || rowNcf.includes(texto) || rowNom.includes(texto);

        // 2. Filtro cliente
        const coincideCliente = !clienteId || rowClienteId === clienteId;

        // 3. Filtro estado
        let coincideEstado = true;
        if (estado === 'PENDIENTES') {
            coincideEstado = ['PENDIENTE', 'PAGADA_PARCIAL'].includes(rowEstado);
        } else if (estado === 'VENCIDAS') {
            coincideEstado = ['PENDIENTE', 'PAGADA_PARCIAL'].includes(rowEstado) && rowVencido;
        } else if (estado === 'PAGADA') {
            coincideEstado = rowEstado === 'PAGADA';
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
    if (badge) badge.textContent = `${visibles} deudas`;
}

function limpiarFiltrosCxc() {
    const txt = document.getElementById('filtroTexto');
    const cli = document.getElementById('filtroCliente');
    const est = document.getElementById('filtroEstado');
    if (txt) txt.value = '';
    if (cli) cli.value = '';
    if (est) est.value = 'PENDIENTES';
    filtrarTablaCxc();
}

// ============================================================================
// SELECCIÓN CONSOLIDADA DE FACTURAS POR CLIENTE (FASE 2)
// ============================================================================
function alCambiarSeleccionItemCxc(chk) {
    const id = parseInt(chk.value, 10);
    const clienteId = chk.getAttribute('data-cliente');
    const saldo = parseFloat(chk.getAttribute('data-saldo')) || 0.00;

    const fila = chk.closest('tr');
    const clienteNom = fila ? fila.getAttribute('data-cliente-nombre') : '';
    const documento = fila ? fila.getAttribute('data-documento') : '';
    const ncf = fila ? fila.getAttribute('data-ncf') : '';

    if (chk.checked) {
        // Validar que no se mezclen clientes
        if (CLIENTE_ACTUAL_SELECCION && CLIENTE_ACTUAL_SELECCION !== clienteId) {
            alert('Solo puede seleccionar deudas de un mismo cliente para consolidar el cobro en un único recibo.');
            chk.checked = false;
            return;
        }

        CLIENTE_ACTUAL_SELECCION = clienteId;
        FACTURAS_SELECCIONADAS.set(id, {
            id: id,
            cliente_id: clienteId,
            cliente_nombre: clienteNom,
            documento: documento,
            ncf: ncf,
            saldo: saldo
        });
    } else {
        FACTURAS_SELECCIONADAS.delete(id);
        if (FACTURAS_SELECCIONADAS.size === 0) {
            CLIENTE_ACTUAL_SELECCION = null;
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
    let tieneVariosClientes = false;

    filasVisibles.forEach(f => {
        const chk = f.querySelector('.chk-cxc-item');
        if (!chk) return;
        const cliId = chk.getAttribute('data-cliente');
        if (!primerCliente) {
            primerCliente = cliId;
        } else if (primerCliente !== cliId) {
            tieneVariosClientes = true;
        }
    });

    if (tieneVariosClientes) {
        alert('Las deudas visibles pertenecen a varios clientes distintos. Para seleccionar en lote, primero filtre por un cliente específico.');
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
        if (txtTot) txtTot.textContent = 'RD$ ' + sumaSaldo.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        if (bar) bar.classList.add('active');
        if (btnTop) btnTop.style.display = 'inline-flex';
    } else {
        if (bar) bar.classList.remove('active');
        if (btnTop) btnTop.style.display = 'none';
    }
}

function iniciarCobroUnico(id, clienteId) {
    limpiarSeleccionCxc();
    const chk = document.querySelector(`.chk-cxc-item[value="${id}"]`);
    if (chk) {
        chk.checked = true;
        alCambiarSeleccionItemCxc(chk);
    }
    abrirModalCobro();
}

// ============================================================================
// MODAL COBRO: SPLIT UNIVERSAL (FASES 2 & 3)
// ============================================================================
async function abrirModalCobro() {
    if (FACTURAS_SELECCIONADAS.size === 0) {
        alert('Debe seleccionar al menos una factura para cobrar.');
        return;
    }

    const tbody = document.getElementById('tbodyFacturasCobro');
    if (!tbody) return;
    tbody.innerHTML = '';
    PAGOS_SPLIT_COBROS = [];

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
            <td><strong style="color: var(--text-dark);">${item.documento}</strong></td>
            <td><span style="font-family: var(--font-mono); font-size: 0.8rem; color: var(--accent-gold); font-weight: 600;">${item.ncf || '—'}</span></td>
            <td><div style="font-size: 0.82rem;">Deuda Crédito</div></td>
            <td style="text-align: right; font-family: var(--font-mono);">RD$ ${item.saldo.toFixed(2)}</td>
            <td style="text-align: right;">
                <input type="number" step="0.01" class="form-control inp-monto-cobrar"
                       value="${item.saldo.toFixed(2)}" max="${item.saldo}" min="0.01"
                       style="font-size: 0.88rem; font-weight: 700; text-align: right;"
                       oninput="recalcularTotalesCobro()" />
            </td>
            <td style="text-align: right; font-family: var(--font-mono); font-weight: 700;" class="celda-saldo-restante-cobro">
                RD$ 0.00
            </td>
        `;
        tbody.appendChild(tr);
    });

    document.getElementById('modalCobroClienteNombre').textContent = clienteNombre;

    // Inicializar Split con 1 método: si hay caja abierta, EFECTIVO por defecto; si no, TRANSFERENCIA
    const metodoInicial = (SESION_CAJA_ACTIVA && SESION_CAJA_ACTIVA.id) ? 1 : 3;
    agregarLineaSplitCobro(metodoInicial, totalCobrar);

    recalcularTotalesCobro();
    document.getElementById('modalCobro').classList.add('active');
}

// Split Universal de Pagos
function agregarLineaSplitCobro(metodoId = 1, monto = 0.00) {
    const metObj = CATALOGO_METODOS.find(m => parseInt(m.id, 10) === parseInt(metodoId, 10)) || CATALOGO_METODOS[0];
    const cod = metObj ? metObj.codigo : 'EFECTIVO';

    const idTemp = Date.now() + Math.random();
    PAGOS_SPLIT_COBROS.push({
        id_temp: idTemp,
        metodo_pago_id: metObj ? metObj.id : 1,
        codigo: cod,
        monto: monto,
        monto_devuelta: 0.00,
        cuenta_bancaria_id: CATALOGO_BANCOS[0] ? CATALOGO_BANCOS[0].id : 1,
        referencia: '',
        titular: '',
        banco_origen: '',
        num_cheque: '',
        banco_emisor: '',
        tipo_tarjeta: 'CREDITO',
        marca_tarjeta: 'VISA',
        ultimos_4: '',
        autorizacion: '',
        lote: '1'
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

    PAGOS_SPLIT_COBROS.forEach(p => {
        const div = document.createElement('div');
        div.className = 'split-pago-row';

        let optionsMet = '';
        CATALOGO_METODOS.forEach(m => {
            const sel = (parseInt(m.id, 10) === parseInt(p.metodo_pago_id, 10)) ? 'selected' : '';
            optionsMet += `<option value="${m.id}" data-codigo="${m.codigo}" ${sel}>${m.descripcion}</option>`;
        });

        // Configurar campos según el método seleccionado
        let camposExtras = '';
        const cod = p.codigo;

        if (cod === 'EFECTIVO') {
            camposExtras = `
                <div style="display: flex; gap: 8px; align-items: center; flex: 1;">
                    <span style="font-size: 0.78rem; color: #2e7d52; font-weight: 700;">💵 Caja Mostrador</span>
                    <input type="number" step="0.01" class="form-control" placeholder="Devuelta (Cambio)" value="${p.monto_devuelta || ''}"
                           style="font-size: 0.8rem; font-family: var(--font-mono); max-width: 140px;"
                           oninput="alCambiarDevueltaSplit(${p.id_temp}, this.value)" title="Monto de devuelta entregado al cliente" />
                </div>
            `;
        } else if (cod === 'TARJETA') {
            camposExtras = `
                <div style="display: flex; gap: 6px; flex: 1; flex-wrap: wrap;">
                    <select class="form-control" style="font-size: 0.78rem; width: 90px;" onchange="alCambiarTipoTarjetaSplit(${p.id_temp}, this.value)">
                        <option value="CREDITO" ${p.tipo_tarjeta === 'CREDITO' ? 'selected' : ''}>Crédito</option>
                        <option value="DEBITO" ${p.tipo_tarjeta === 'DEBITO' ? 'selected' : ''}>Débito</option>
                    </select>
                    <select class="form-control" style="font-size: 0.78rem; width: 100px;" onchange="alCambiarMarcaTarjetaSplit(${p.id_temp}, this.value)">
                        <option value="VISA" ${p.marca_tarjeta === 'VISA' ? 'selected' : ''}>Visa</option>
                        <option value="MASTERCARD" ${p.marca_tarjeta === 'MASTERCARD' ? 'selected' : ''}>Mastercard</option>
                        <option value="AMEX" ${p.marca_tarjeta === 'AMEX' ? 'selected' : ''}>Amex</option>
                        <option value="OTRA" ${p.marca_tarjeta === 'OTRA' ? 'selected' : ''}>Otra</option>
                    </select>
                    <input type="text" class="form-control" placeholder="Últimos 4" value="${p.ultimos_4}" maxlength="4" style="font-size: 0.78rem; width: 80px; font-family: var(--font-mono);" oninput="alCambiarUlt4TarjetaSplit(${p.id_temp}, this.value)" required />
                    <input type="text" class="form-control" placeholder="No. Autorización" value="${p.autorizacion}" style="font-size: 0.78rem; flex: 1; min-width: 110px;" oninput="alCambiarAutTarjetaSplit(${p.id_temp}, this.value)" required />
                </div>
            `;
        } else if (cod === 'TRANSFERENCIA') {
            let optionsBancos = '';
            CATALOGO_BANCOS.forEach(b => {
                const selB = (parseInt(b.id, 10) === parseInt(p.cuenta_bancaria_id, 10)) ? 'selected' : '';
                optionsBancos += `<option value="${b.id}" ${selB}>${b.nombre_banco} (${b.numero_cuenta})</option>`;
            });

            camposExtras = `
                <div style="display: flex; gap: 6px; flex: 1;">
                    <select class="form-control" style="font-size: 0.78rem;" onchange="alCambiarBancoSplit(${p.id_temp}, this.value)">
                        ${optionsBancos}
                    </select>
                    <input type="text" class="form-control" placeholder="No. Referencia TRF" value="${p.referencia}" style="font-size: 0.78rem; flex: 1;" oninput="alCambiarRefSplit(${p.id_temp}, this.value)" required />
                </div>
            `;
        } else if (cod === 'CHEQUE') {
            camposExtras = `
                <div style="display: flex; gap: 6px; flex: 1;">
                    <input type="text" class="form-control" placeholder="Banco Emisor" value="${p.banco_emisor}" style="font-size: 0.78rem; flex: 1;" oninput="alCambiarBancoEmisorSplit(${p.id_temp}, this.value)" required />
                    <input type="text" class="form-control" placeholder="No. Cheque" value="${p.num_cheque}" style="font-size: 0.78rem; width: 110px; font-family: var(--font-mono);" oninput="alCambiarNumChequeSplit(${p.id_temp}, this.value)" required />
                </div>
            `;
        } else {
            camposExtras = `<div style="font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center;">Aplicación directa a saldo</div>`;
        }

        div.innerHTML = `
            <div>
                <select class="form-control" style="font-size: 0.82rem; font-weight: 600;" onchange="alCambiarMetodoSplitCobro(${p.id_temp}, this)">
                    ${optionsMet}
                </select>
            </div>
            ${camposExtras}
            <div>
                <input type="number" step="0.01" class="form-control" value="${p.monto.toFixed(2)}" min="0.01"
                       style="font-family: var(--font-mono); font-weight: 700; text-align: right; font-size: 0.88rem;"
                       oninput="alCambiarMontoSplitCobro(${p.id_temp}, this.value)" />
            </div>
            <div>
                <button type="button" class="btn btn-sm btn-outline" onclick="eliminarLineaSplitCobro(${p.id_temp})" style="color: #b53d32; padding: 4px 8px;">✕</button>
            </div>
        `;
        cont.appendChild(div);
    });
}

function alCambiarMetodoSplitCobro(idTemp, select) {
    const item = PAGOS_SPLIT_COBROS.find(p => p.id_temp === idTemp);
    if (!item) return;

    const opt = select.options[select.selectedIndex];
    const codigo = opt.getAttribute('data-codigo') || 'EFECTIVO';

    // Validación de caja abierta para efectivo o tarjeta
    if (['EFECTIVO', 'TARJETA'].includes(codigo) && (!SESION_CAJA_ACTIVA || !SESION_CAJA_ACTIVA.id)) {
        alert('⚠️ Atención: Para registrar cobros físicos en mostrador (Efectivo o Tarjeta), debe abrir un turno de caja en Control de Caja. Si el cliente pagó por banco, use Transferencia o Cheque.');
    }

    item.metodo_pago_id = parseInt(select.value, 10);
    item.codigo = codigo;

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
    item.monto_devuelta = parseFloat(val) || 0.00;
    recalcularTotalesCobro();
}

function alCambiarTipoTarjetaSplit(idTemp, val) {
    const item = PAGOS_SPLIT_COBROS.find(p => p.id_temp === idTemp);
    if (item) item.tipo_tarjeta = val;
}

function alCambiarMarcaTarjetaSplit(idTemp, val) {
    const item = PAGOS_SPLIT_COBROS.find(p => p.id_temp === idTemp);
    if (item) item.marca_tarjeta = val;
}

function alCambiarUlt4TarjetaSplit(idTemp, val) {
    const item = PAGOS_SPLIT_COBROS.find(p => p.id_temp === idTemp);
    if (item) item.ultimos_4 = val;
}

function alCambiarAutTarjetaSplit(idTemp, val) {
    const item = PAGOS_SPLIT_COBROS.find(p => p.id_temp === idTemp);
    if (item) item.autorizacion = val;
}

function alCambiarBancoSplit(idTemp, val) {
    const item = PAGOS_SPLIT_COBROS.find(p => p.id_temp === idTemp);
    if (item) item.cuenta_bancaria_id = parseInt(val, 10);
}

function alCambiarRefSplit(idTemp, val) {
    const item = PAGOS_SPLIT_COBROS.find(p => p.id_temp === idTemp);
    if (item) item.referencia = val;
}

function alCambiarBancoEmisorSplit(idTemp, val) {
    const item = PAGOS_SPLIT_COBROS.find(p => p.id_temp === idTemp);
    if (item) item.banco_emisor = val;
}

function alCambiarNumChequeSplit(idTemp, val) {
    const item = PAGOS_SPLIT_COBROS.find(p => p.id_temp === idTemp);
    if (item) item.num_cheque = val;
}

function autoCuadrarSplitCobro() {
    let totalCobrar = 0.00;
    document.querySelectorAll('.fila-cobro-factura').forEach(f => {
        totalCobrar += parseFloat(f.querySelector('.inp-monto-cobrar').value) || 0.00;
    });

    if (PAGOS_SPLIT_COBROS.length > 0) {
        PAGOS_SPLIT_COBROS[0].monto = totalCobrar;
        PAGOS_SPLIT_COBROS[0].monto_devuelta = 0.00;
        renderizarSplitCobros();
        recalcularTotalesCobro();
    }
}

// Recálculo global de cuadre y saldos
function recalcularTotalesCobro() {
    let totalCobrarFacturas = 0.00;

    // 1. Recorrer facturas
    const filasFacturas = document.querySelectorAll('.fila-cobro-factura');
    filasFacturas.forEach(f => {
        const saldoAnt = parseFloat(f.getAttribute('data-saldo-anterior'));
        const inpMonto = f.querySelector('.inp-monto-cobrar');
        let monto = parseFloat(inpMonto.value) || 0.00;

        if (monto > saldoAnt) {
            monto = saldoAnt;
            inpMonto.value = saldoAnt.toFixed(2);
        }
        totalCobrarFacturas += monto;

        const saldoRestante = Math.max(0, Math.round((saldoAnt - monto) * 100) / 100);
        const celdaSaldo = f.querySelector('.celda-saldo-restante-cobro');
        if (celdaSaldo) {
            celdaSaldo.textContent = 'RD$ ' + saldoRestante.toFixed(2);
            celdaSaldo.style.color = (saldoRestante === 0) ? '#2e7d52' : '#b53d32';
        }
    });

    totalCobrarFacturas = Math.round(totalCobrarFacturas * 100) / 100;

    // 2. Sumar instrumentos de pago (Split)
    let totalRecibidoNeto = 0.00;
    PAGOS_SPLIT_COBROS.forEach(p => {
        const neto = Math.max(0, p.monto - (p.monto_devuelta || 0));
        totalRecibidoNeto += neto;
    });
    totalRecibidoNeto = Math.round(totalRecibidoNeto * 100) / 100;

    // 3. Excedente (Escenario D: Saldo a favor)
    const excedente = Math.max(0, Math.round((totalRecibidoNeto - totalCobrarFacturas) * 100) / 100);

    // Actualizar cabecera resumen modal
    document.getElementById('modalTotalCobrar').textContent = 'RD$ ' + totalCobrarFacturas.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('modalTotalRecibidoSplit').textContent = 'RD$ ' + totalRecibidoNeto.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('modalTotalExcedente').textContent = 'RD$ ' + excedente.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('lblTotalInstrumentos').textContent = 'RD$ ' + totalRecibidoNeto.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Estado del botón y cuadre
    const lblCuadre = document.getElementById('lblEstadoCuadreCobro');
    const btnConfirmar = document.getElementById('btnConfirmarCobro');

    if (totalCobrarFacturas > 0 && totalRecibidoNeto >= totalCobrarFacturas) {
        if (excedente > 0) {
            lblCuadre.innerHTML = `<span style="color: #b78103;">✨ Excedente de RD$ ${excedente.toFixed(2)} (Se guardará como Saldo a Favor)</span>`;
        } else {
            lblCuadre.innerHTML = `<span style="color: #2e7d52;">● Cuadre Exacto (RD$ ${totalRecibidoNeto.toFixed(2)})</span>`;
        }
        if (btnConfirmar) btnConfirmar.disabled = false;
    } else {
        const faltan = (totalCobrarFacturas - totalRecibidoNeto).toFixed(2);
        lblCuadre.innerHTML = `<span style="color: #b53d32;">⚠️ Faltan RD$ ${faltan} por cubrir</span>`;
        if (btnConfirmar) btnConfirmar.disabled = true;
    }
}

// Enviar Liquidación de Cobro (Generar Recibo de Ingreso)
async function procesarLiquidacionCobro(e) {
    e.preventDefault();
    const btn = document.getElementById('btnConfirmarCobro');
    btn.disabled = true;
    btn.textContent = 'Procesando Recibo...';

    const facturasPayload = [];
    document.querySelectorAll('.fila-cobro-factura').forEach(f => {
        const cxcId = parseInt(f.getAttribute('data-cxc-id'), 10);
        const monto = parseFloat(f.querySelector('.inp-monto-cobrar').value) || 0.00;
        if (monto > 0) {
            facturasPayload.push({ cxc_id: cxcId, monto_aplicado: monto });
        }
    });

    const payload = {
        cliente_id: CLIENTE_ACTUAL_SELECCION,
        facturas: facturasPayload,
        pagos: PAGOS_SPLIT_COBROS,
        moneda_id: 1,
        tasa_cambio: 1.0000
    };

    try {
        const resp = await fetch('procesar_cxc.php?accion=registrar_recibo_ingreso', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await resp.json();

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
        btn.disabled = false;
        btn.textContent = 'EMITIR RECIBO DE INGRESO';
    }
}

// ============================================================================
// MODAL AJUSTE, CONDONACIÓN E INCOBRABLES (FASE 4)
// ============================================================================
function abrirModalAjusteCxc(cxcId, clienteNombre, saldoActual) {
    document.getElementById('formAjusteCxc').reset();
    document.getElementById('ajusteCxcId').value = cxcId;
    document.getElementById('ajusteClienteNombre').textContent = clienteNombre;
    document.getElementById('ajusteSaldoActual').textContent = 'RD$ ' + saldoActual.toFixed(2);
    document.getElementById('inputMontoAjusteCxc').value = saldoActual.toFixed(2);
    document.getElementById('inputMontoAjusteCxc').setAttribute('max', saldoActual);

    alCambiarTipoAjusteCxc();
    document.getElementById('modalAjusteCxc').classList.add('active');
}

function alCambiarTipoAjusteCxc() {
    const tipo = document.getElementById('tipoAjusteCxc').value;
    const inpMonto = document.getElementById('inputMontoAjusteCxc');
    const saldoMax = parseFloat(inpMonto.getAttribute('max')) || 0;

    if (tipo === 'INCOBRABLE') {
        inpMonto.value = saldoMax.toFixed(2);
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

    const form = document.getElementById('formAjusteCxc');
    const formData = new FormData(form);

    try {
        const resp = await fetch('procesar_cxc.php?accion=registrar_ajuste', {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();

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
// MODAL REGISTRO DE CARGO / NOTA DE DÉBITO B03 (FASE 1)
// ============================================================================
function abrirModalCargoNotaDebito() {
    document.getElementById('formCargo').reset();
    document.getElementById('modalCargo').classList.add('active');
}

async function procesarRegistroCargo(e) {
    e.preventDefault();
    const btn = document.getElementById('btnGuardarCargo');
    btn.disabled = true;
    btn.textContent = 'Emitiendo...';

    const form = document.getElementById('formCargo');
    const formData = new FormData(form);

    try {
        const resp = await fetch('procesar_cxc.php?accion=registrar_nota_debito_cargo', {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();

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
        btn.textContent = 'Emitir Nota de Débito';
    }
}

// ============================================================================
// MODAL VER DETALLE DE RECIBO DE INGRESO
// ============================================================================
async function verDetalleRecibo(reciboId) {
    const modal = document.getElementById('modalDetalleRecibo');
    const body = document.getElementById('bodyDetalleRecibo');
    body.innerHTML = '<div style="text-align: center; padding: 24px;">Cargando detalle del recibo...</div>';
    modal.classList.add('active');

    try {
        const resp = await fetch(`procesar_cxc.php?accion=ver_detalle_recibo&recibo_id=${reciboId}`);
        const data = await resp.json();

        if (!data.ok) {
            body.innerHTML = `<div style="color: #b53d32; padding: 16px;">Error: ${data.mensaje}</div>`;
            return;
        }

        const r = data.recibo;
        let htmlFacturas = '';
        data.facturas.forEach(f => {
            htmlFacturas += `
                <tr>
                    <td><strong>${f.numero_documento}</strong></td>
                    <td style="font-family: var(--font-mono);">${f.ncf || '—'}</td>
                    <td style="text-align: right; font-family: var(--font-mono);">RD$ ${parseFloat(f.saldo_anterior).toFixed(2)}</td>
                    <td style="text-align: right; font-family: var(--font-mono); font-weight: 700; color: #2e7d52;">RD$ ${parseFloat(f.monto_aplicado).toFixed(2)}</td>
                    <td style="text-align: right; font-family: var(--font-mono);">${parseFloat(f.saldo_restante).toFixed(2)}</td>
                </tr>
            `;
        });

        let htmlPagos = '';
        data.pagos.forEach(p => {
            let detalle = '';
            if (p.metodo_codigo === 'TARJETA') {
                detalle = `${p.tipo_tarjeta} ${p.marca_tarjeta} (****${p.ultimos_4_digitos}) Aut: ${p.numero_autorizacion}`;
            } else if (p.metodo_codigo === 'TRANSFERENCIA') {
                detalle = `${p.nombre_banco || 'Banco'} Ref: ${p.numero_referencia}`;
            } else if (p.metodo_codigo === 'CHEQUE') {
                detalle = `Chq #${p.numero_cheque} (${p.banco_emisor})`;
            } else {
                detalle = (p.sesion_caja_id) ? 'Gaveta de Caja POS' : 'Cobro Efectivo';
            }

            htmlPagos += `
                <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #eae3d5; font-size: 0.85rem;">
                    <div><strong>${p.metodo_nombre}</strong> <span style="color: var(--text-muted); font-size: 0.78rem;">(${detalle})</span></div>
                    <div style="font-family: var(--font-mono); font-weight: 700;">RD$ ${parseFloat(p.monto_pagado).toFixed(2)}</div>
                </div>
            `;
        });

        body.innerHTML = `
            <div style="border-bottom: 1px solid #eae3d5; padding-bottom: 14px; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <div>
                    <h2 style="font-size: 1.25rem; font-weight: 700; color: #a66e2e; margin-bottom: 2px;">Recibo de Ingreso ${r.numero_recibo}</h2>
                    <div style="font-size: 0.82rem; color: var(--text-muted);">Fecha: ${r.fecha_recibo}</div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 0.76rem; color: var(--text-muted); text-transform: uppercase;">Total Cobrado</div>
                    <div style="font-size: 1.4rem; font-weight: 700; font-family: var(--font-mono); color: #2e7d52;">RD$ ${parseFloat(r.monto_total_recibido).toFixed(2)}</div>
                    ${parseFloat(r.saldo_a_favor_generado) > 0 ? `<div style="font-size: 0.78rem; color: #b78103; font-weight: 700;">+ RD$ ${parseFloat(r.saldo_a_favor_generado).toFixed(2)} Saldo a Favor</div>` : ''}
                </div>
            </div>

            <div style="background: #fbf9f5; border: 1px solid #eae3d5; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 0.85rem;">
                <div>Cliente: <strong>${r.cliente_nombre}</strong></div>
                <div>RNC / Cédula: <strong>${r.cliente_rnc || '—'}</strong></div>
                <div>Cajero: <strong>${r.cajero_nombre || r.cajero_username}</strong></div>
                <div>Canal: <strong>${r.caja_nombre ? `Caja: ${r.caja_nombre}` : 'Canal Bancario / Externo'}</strong></div>
            </div>

            <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 8px;">Facturas y Deudas Saldadas</h4>
            <div class="table-wrapper" style="margin-bottom: 16px; border: 1px solid #eae3d5; border-radius: 6px;">
                <table class="data-table" style="font-size: 0.82rem;">
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>NCF</th>
                            <th class="text-right">Saldo Ant.</th>
                            <th class="text-right">Monto Aplicado</th>
                            <th class="text-right">Saldo Restante</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${htmlFacturas}
                    </tbody>
                </table>
            </div>

            <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 8px;">Instrumentos de Pago Recibidos (Split)</h4>
            <div style="background: #ffffff; border: 1px solid #eae3d5; border-radius: 6px; padding: 10px 14px;">
                ${htmlPagos}
            </div>
        `;
    } catch (e) {
        body.innerHTML = `<div style="color: #b53d32; padding: 16px;">Error de conexión: ${e.message}</div>`;
    }
}

// Utilidad para cerrar modales
function cerrarModal(modalId) {
    const m = document.getElementById(modalId);
    if (m) m.classList.remove('active');
}
