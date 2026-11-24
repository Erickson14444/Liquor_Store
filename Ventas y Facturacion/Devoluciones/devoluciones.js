/**
 * ============================================================================
 * Controlador Frontend • Proceso 6: Devoluciones y Notas de Crédito (B04)
 * Ubicación: Ventas y Facturacion/Devoluciones/devoluciones.js
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

let timerBusFac = null;
let facturasBuscadas = [];
let facturaCargada = null; // { cabecera, cxc, lineas, tiene_caja_open }
let estadoDevolucion = [];   // [{ producto_maestro_id, cantidad, reingresado_a_inventario }]

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function fmtDinero(v, sim = 'RD$') {
    return `${sim} ${(Number(v) || 0).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function mostrarToast(msg, isError = false) {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'toast show ' + (isError ? 'toast-error' : 'toast-success');
    clearTimeout(window._toastDevTimer);
    window._toastDevTimer = setTimeout(() => t.classList.remove('show'), 4000);
}

function abrirModal(id) { document.getElementById(id)?.classList.add('open', 'active'); }
function cerrarModal(id) { document.getElementById(id)?.classList.remove('open', 'active'); }

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const dd = document.getElementById('dev_fac_dropdown');
        if (dd && dd.style.display === 'block') {
            dd.style.display = 'none';
            return;
        }
        cerrarModal('modalDetalleB04');
    }
});

document.addEventListener('click', (e) => {
    const wrap = document.getElementById('facPickerWrap');
    const dd = document.getElementById('dev_fac_dropdown');
    if (wrap && dd && !wrap.contains(e.target)) {
        dd.style.display = 'none';
    }
});

// ============================================================================
// MODAL NUEVA DEVOLUCIÓN Y BUSCADOR DE FACTURAS
// ============================================================================
function abrirModalNuevaDevolucion() {
    document.getElementById('formNuevaDevolucion')?.reset();
    limpiarFacturaDev(false);
    actualizarNotaMetodoDev();
    abrirModal('modalNuevaDevolucion');
    setTimeout(() => document.getElementById('dev_fac_buscar')?.focus(), 90);
}

function cerrarModalDevolucion(forzar = false) {
    const totalSel = estadoDevolucion.reduce((a, b) => a + (Number(b.cantidad) || 0), 0);
    if (!forzar && totalSel > 0 && !confirm('Tienes artículos marcados para devolución. ¿Cerrar sin emitir la B04?')) {
        return;
    }
    cerrarModal('modalNuevaDevolucion');
}

function programarBusquedaFacturasDev() {
    clearTimeout(timerBusFac);
    timerBusFac = setTimeout(buscarFacturasDev, 200);
}

async function buscarFacturasDev() {
    const inp = document.getElementById('dev_fac_buscar');
    const dd = document.getElementById('dev_fac_dropdown');
    if (!inp || !dd) return;

    const q = inp.value.trim();
    dd.style.display = 'block';
    dd.innerHTML = '<div style="padding:10px 14px; color:#888; font-size:0.82rem;">Buscando facturas...</div>';

    try {
        const res = await fetch(`procesar_devoluciones.php?accion=buscar_facturas&q=${encodeURIComponent(q)}`);
        const data = await res.json();
        facturasBuscadas = Array.isArray(data.facturas) ? data.facturas : [];

        if (!data.ok || facturasBuscadas.length === 0) {
            dd.innerHTML = '<div style="padding:10px 14px; color:#888; font-size:0.82rem;">No se encontraron facturas elegibles.</div>';
            return;
        }

        dd.innerHTML = facturasBuscadas.map((f) => {
            const yaDev = Number(f.monto_ya_devuelto || 0);
            return `
        <div class="fac-opt-item" onmousedown="seleccionarFacturaDev(${Number(f.id)})">
          <div>
            <strong style="font-family:monospace; color:#a66e2e;">${escapeHtml(f.numero_factura)}</strong>
            <span style="font-size:0.74rem; background:#f3ece1; color:#5a3818; padding:1px 6px; border-radius:4px; margin-left:4px; font-weight:700;">${escapeHtml(f.ncf)}</span>
            <div style="font-size:0.78rem; color:#211813; margin-top:2px;">
              ${escapeHtml(f.cliente_nombre)} ${f.cliente_doc ? `(${escapeHtml(f.cliente_doc)})` : ''}
            </div>
            <div style="font-size:0.72rem; color:#8a7b70;">${escapeHtml(f.fecha_fmt)} • ${escapeHtml(f.condicion_pago)} / ${escapeHtml(f.estado)}</div>
          </div>
          <div style="text-align:right; font-family:monospace;">
            <strong>${fmtDinero(f.monto_total, f.simbolo || 'RD$')}</strong>
            ${yaDev > 0 ? `<div style="font-size:0.71rem; color:#c62828;">Devuelto: ${fmtDinero(yaDev, f.simbolo || 'RD$')}</div>` : ''}
          </div>
        </div>
      `;
        }).join('');
    } catch (err) {
        dd.innerHTML = '<div style="padding:10px 14px; color:#c62828; font-size:0.82rem;">Error de red al buscar facturas.</div>';
    }
}

async function seleccionarFacturaDev(facturaId) {
    document.getElementById('dev_fac_dropdown').style.display = 'none';
    const tbody = document.getElementById('devTablaLineasBody');
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:22px; color:#888;">Cargando líneas y lotes de la factura...</td></tr>';

    try {
        const res = await fetch(`procesar_devoluciones.php?accion=cargar_factura&factura_id=${encodeURIComponent(facturaId)}`);
        const data = await res.json();
        if (!data.ok || !data.cabecera) {
            mostrarToast(data.mensaje || 'No se pudo cargar la factura.', true);
            limpiarFacturaDev(false);
            return;
        }

        facturaCargada = data;
        const c = data.cabecera;
        document.getElementById('dev_factura_id').value = String(c.id);

        const pill = document.getElementById('dev_fac_pill');
        const txt = document.getElementById('dev_fac_pill_texto');
        const inp = document.getElementById('dev_fac_buscar');

        let cxcTxt = '';
        if (data.cxc) {
            cxcTxt = ` • <strong style="color:#5e35b1;">Saldo CxC: ${fmtDinero(data.cxc.saldo_pendiente, c.simbolo)}</strong>`;
        }

        txt.innerHTML = `
      <div>
        <strong style="font-family:monospace; color:#a66e2e;">${escapeHtml(c.numero_factura)}</strong>
        (${escapeHtml(c.ncf)}) • <strong>${escapeHtml(c.cliente_nombre)}</strong>
      </div>
      <div style="font-size:0.76rem; color:#7a6b5d; margin-top:2px;">
        Fecha: ${escapeHtml(c.fecha_fmt)} • Condición: <strong>${escapeHtml(c.condicion_pago)}</strong>
        • Total: ${fmtDinero(c.monto_total, c.simbolo)}${cxcTxt}
      </div>
    `;
        pill.style.display = 'flex';
        inp.style.display = 'none';

        // Alerta DGII > 30 días
        const al30 = document.getElementById('devAlerta30Dias');
        if (Number(c.dias_transcurridos || 0) > 30) {
            al30.style.display = 'block';
            al30.innerHTML = `⚠️ <strong>Aviso Fiscal DGII (Norma > 30 días):</strong> Esta factura tiene <strong>${c.dias_transcurridos} días</strong> desde su emisión. La Nota de Crédito B04 puede emitirse, pero el ITBIS fuera de los 30 días no es deducible en el 607.`;
        } else {
            al30.style.display = 'none';
        }

        // Habilitar/deshabilitar Abono CxC según condición y saldo pendiente
        const optCxc = document.getElementById('optMetodoCxc');
        const selMet = document.getElementById('dev_metodo_reembolso');
        const puedeCxc = (String(c.condicion_pago).toUpperCase() === 'CREDITO' && data.cxc && Number(data.cxc.saldo_pendiente) > 0);
        if (optCxc) {
            optCxc.disabled = !puedeCxc;
            if (puedeCxc) {
                selMet.value = 'ABONO_CXC';
            } else if (selMet.value === 'ABONO_CXC') {
                selMet.value = 'SALDO_FAVOR';
            }
        }
        actualizarNotaMetodoDev();

        estadoDevolucion = (data.lineas || []).map((ln) => ({
            producto_maestro_id: Number(ln.producto_maestro_id),
            cantidad: 0,
            reingresado_a_inventario: true
        }));

        const hayDisp = (data.lineas || []).some((ln) => Number(ln.disponible_devolver) > 0);
        document.getElementById('btnDevolverTodo').style.display = hayDisp ? 'inline-block' : 'none';

        renderizarLineasDevolucion();
    } catch (err) {
        mostrarToast('Error de red al cargar la factura.', true);
        limpiarFacturaDev(false);
    }
}

function limpiarFacturaDev(enfocar = false) {
    facturaCargada = null;
    estadoDevolucion = [];
    document.getElementById('dev_factura_id').value = '0';
    document.getElementById('dev_fac_pill').style.display = 'none';
    document.getElementById('dev_fac_dropdown').style.display = 'none';
    document.getElementById('devAlerta30Dias').style.display = 'none';
    document.getElementById('btnDevolverTodo').style.display = 'none';

    const inp = document.getElementById('dev_fac_buscar');
    if (inp) {
        inp.style.display = '';
        inp.value = '';
        if (enfocar) {
            inp.focus();
            buscarFacturasDev();
        }
    }

    document.getElementById('devTablaLineasBody').innerHTML =
        '<tr><td colspan="6" style="text-align:center; padding:22px; color:#888;">Busca y selecciona una factura arriba para ver los productos disponibles para devolución.</td></tr>';
    recalcularTotalDevolucion();
}

// ============================================================================
// TABLA DE ARTÍCULOS A DEVOLVER Y CÁLCULO EN VIVO
// ============================================================================
function renderizarLineasDevolucion() {
    const tbody = document.getElementById('devTablaLineasBody');
    if (!facturaCargada || !Array.isArray(facturaCargada.lineas) || facturaCargada.lineas.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#888;">Sin líneas registradas.</td></tr>';
        recalcularTotalDevolucion();
        return;
    }

    const sim = facturaCargada.cabecera?.simbolo || 'RD$';

    tbody.innerHTML = facturaCargada.lineas.map((ln, idx) => {
        const est = estadoDevolucion[idx] || { cantidad: 0, reingresado_a_inventario: true };
        const disp = Number(ln.disponible_devolver || 0);
        const sub = Number((Number(est.cantidad) * Number(ln.valor_unitario_neto)).toFixed(2));
        const desh = disp <= 0;

        return `
      <tr style="${Number(est.cantidad) > 0 ? 'background:#fdfaf4;' : ''}${desh ? 'opacity:0.55;' : ''}">
        <td>
          <div style="font-weight:700; color:#211813;">${escapeHtml(ln.sku)} — ${escapeHtml(ln.producto_nombre)}</div>
          <div style="font-size:0.74rem; color:#7a6b5d;">
            ${escapeHtml(ln.presentacion)}${ln.lotes_origen ? ` • Lote origen: <strong>${escapeHtml(ln.lotes_origen)}</strong>` : ''}
          </div>
        </td>
        <td style="text-align:center; font-family:monospace; font-size:0.84rem;">
          ${ln.cantidad_unidades} / <strong style="color:${disp > 0 ? '#2e7d32' : '#c62828'};">${disp}</strong>
          ${Number(ln.ya_devuelto) > 0 ? `<div style="font-size:0.7rem; color:#c62828;">Ya dev: ${ln.ya_devuelto}</div>` : ''}
        </td>
        <td style="text-align:center;">
          ${desh ? '<span style="font-size:0.76rem; color:#888;">Devuelto 100%</span>' : `
            <div class="dev-qty-control">
              <button type="button" class="dev-qty-btn" onclick="ajustarCantDev(${idx}, -1)">−</button>
              <input type="number" min="0" max="${disp}" step="1" value="${est.cantidad}" class="dev-qty-input"
                     oninput="fijarCantDev(${idx}, this.value)" />
              <button type="button" class="dev-qty-btn" onclick="ajustarCantDev(${idx}, 1)">+</button>
            </div>
          `}
        </td>
        <td style="text-align:center;">
          <select class="form-control" style="height:30px; font-size:0.78rem; padding:2px 6px;" ${desh ? 'disabled' : ''}
                  onchange="cambiarCondicionDev(${idx}, this.value)">
            <option value="1" ${est.reingresado_a_inventario ? 'selected' : ''}>✅ Sellada (Reingresa Stock)</option>
            <option value="0" ${!est.reingresado_a_inventario ? 'selected' : ''}>⚠️ Dañada/Rota (No Reingresa)</option>
          </select>
        </td>
        <td style="text-align:right; font-family:monospace;">${fmtDinero(ln.valor_unitario_neto, sim)}</td>
        <td style="text-align:right; font-family:monospace; font-weight:800; color:${sub > 0 ? '#c62828' : '#8a7b70'};">
          ${sub > 0 ? '-' : ''}${fmtDinero(sub, sim)}
        </td>
      </tr>
    `;
    }).join('');

    recalcularTotalDevolucion();
}

function ajustarCantDev(idx, delta) {
    if (!facturaCargada || !estadoDevolucion[idx]) return;
    const max = Number(facturaCargada.lineas[idx].disponible_devolver || 0);
    const nva = Math.max(0, Math.min(max, Number(estadoDevolucion[idx].cantidad || 0) + delta));
    estadoDevolucion[idx].cantidad = nva;
    renderizarLineasDevolucion();
}

function fijarCantDev(idx, val) {
    if (!facturaCargada || !estadoDevolucion[idx]) return;
    const max = Number(facturaCargada.lineas[idx].disponible_devolver || 0);
    let num = parseInt(val, 10);
    if (isNaN(num) || num < 0) num = 0;
    if (num > max) num = max;
    estadoDevolucion[idx].cantidad = num;
    recalcularTotalDevolucion();
    // Actualizar solo el subtotal de la fila sin perder el foco del input
    const sim = facturaCargada.cabecera?.simbolo || 'RD$';
    const rows = document.querySelectorAll('#devTablaLineasBody tr');
    if (rows[idx]) {
        const sub = Number((num * Number(facturaCargada.lineas[idx].valor_unitario_neto)).toFixed(2));
        const lastTd = rows[idx].querySelector('td:last-child');
        if (lastTd) {
            lastTd.textContent = (sub > 0 ? '-' : '') + fmtDinero(sub, sim);
            lastTd.style.color = sub > 0 ? '#c62828' : '#8a7b70';
        }
    }
}

function cambiarCondicionDev(idx, val) {
    if (!estadoDevolucion[idx]) return;
    estadoDevolucion[idx].reingresado_a_inventario = (val === '1');
}

function marcarDevolverTodo() {
    if (!facturaCargada) return;
    facturaCargada.lineas.forEach((ln, idx) => {
        estadoDevolucion[idx].cantidad = Number(ln.disponible_devolver || 0);
    });
    renderizarLineasDevolucion();
}

function recalcularTotalDevolucion() {
    const sim = facturaCargada?.cabecera?.simbolo || 'RD$';
    let uds = 0;
    let tot = 0;

    if (facturaCargada && Array.isArray(facturaCargada.lineas)) {
        facturaCargada.lineas.forEach((ln, idx) => {
            const cant = Number(estadoDevolucion[idx]?.cantidad || 0);
            if (cant > 0) {
                uds += cant;
                tot += cant * Number(ln.valor_unitario_neto || 0);
            }
        });
    }

    document.getElementById('devResumenUnidades').textContent = `${uds} unidad(es) seleccionada(s) para devolución`;
    document.getElementById('devTotalReembolso').textContent = fmtDinero(Number(tot.toFixed(2)), sim);
}

function actualizarNotaMetodoDev() {
    const met = document.getElementById('dev_metodo_reembolso')?.value || 'SALDO_FAVOR';
    const nota = document.getElementById('devNotaMetodo');
    if (!nota) return;

    if (met === 'SALDO_FAVOR') {
        nota.textContent = 'Genera código CRD-XXXXXX con 15 días de vigencia para canje en el Split Universal de Facturación.';
    } else if (met === 'EFECTIVO') {
        nota.innerHTML = facturaCargada && !facturaCargada.tiene_caja_open
            ? '<span style="color:#c62828; font-weight:700;">⚠️ No tienes una caja ABIERTA asignada; abre un turno o elige Saldo a Favor.</span>'
            : 'Registra un egreso automático en tu caja activa (afecta el Arqueo Ciego).';
    } else if (met === 'TARJETA') {
        nota.innerHTML = facturaCargada && !facturaCargada.tiene_caja_open
            ? '<span style="color:#c62828; font-weight:700;">⚠️ Requiere turno de caja ABIERTA para ajustar los vouchers esperados.</span>'
            : 'Rebaja el monto esperado de tarjetas en tu sesión de caja actual.';
    } else {
        nota.textContent = 'Descuenta directamente del saldo pendiente de la factura en Cuentas por Cobrar (CxC).';
    }
}

// ============================================================================
// EMITIR NOTA DE CRÉDITO B04
// ============================================================================
async function guardarDevolucion(e) {
    e.preventDefault();
    const facId = parseInt(document.getElementById('dev_factura_id').value || '0', 10);
    if (facId <= 0) {
        mostrarToast('Busca y selecciona la factura original.', true);
        return;
    }

    const itemsEnviar = estadoDevolucion.filter((it) => Number(it.cantidad) > 0);
    if (itemsEnviar.length === 0) {
        mostrarToast('Indica al menos 1 unidad a devolver en la tabla.', true);
        return;
    }

    const btn = document.getElementById('btnEmitirB04');
    const txt = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Emitiendo B04...';

    try {
        const res = await fetch('procesar_devoluciones.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                accion: 'procesar_devolucion',
                factura_id: facId,
                metodo_reembolso: document.getElementById('dev_metodo_reembolso').value,
                justificacion_motivo: document.getElementById('dev_justificacion').value.trim(),
                supervisor_id: document.getElementById('dev_supervisor_id').value,
                pin_autorizacion: document.getElementById('dev_pin').value.trim(),
                items: itemsEnviar
            })
        });
        const data = await res.json();
        if (!data.ok) {
            mostrarToast(data.mensaje || 'No se pudo emitir la devolución.', true);
            btn.disabled = false;
            btn.textContent = txt;
            return;
        }

        cerrarModalDevolucion(true);
        mostrarToast(data.mensaje || 'Nota de Crédito B04 emitida.');

        if (data.codigo_credito) {
            alert(
                `♻️ NOTA DE CRÉDITO B04 EMITIDA\n\n` +
                `NCF: ${data.ncf_nota_credito}\n` +
                `Código Saldo a Favor: ${data.codigo_credito}\n` +
                `Monto disponible: RD$ ${Number(data.saldo_disponible || 0).toFixed(2)}\n` +
                `Vence: ${data.fecha_vencimiento_saldo} (15 días)`
            );
        }

        setTimeout(() => window.location.reload(), 900);
    } catch (err) {
        mostrarToast('Error de red al emitir la Nota de Crédito.', true);
        btn.disabled = false;
        btn.textContent = txt;
    }
}

// ============================================================================
// VER / IMPRIMIR COMPROBANTE B04 Y FILTRO DE TABLA
// ============================================================================
async function verDetalleDevolucion(devolucionId) {
    const tit = document.getElementById('detB04Titulo');
    const body = document.getElementById('areaImpresionB04');

    tit.textContent = 'Nota de Crédito B04';
    body.innerHTML = '<p style="color:#888;">Consultando Nota de Crédito...</p>';
    abrirModal('modalDetalleB04');

    try {
        const res = await fetch(`procesar_devoluciones.php?accion=detalle_devolucion&devolucion_id=${encodeURIComponent(devolucionId)}`);
        const data = await res.json();
        if (!data.ok || !data.cabecera) {
            body.innerHTML = `<p style="color:#c62828;">${escapeHtml(data.mensaje || 'Error al cargar.')}</p>`;
            return;
        }

        const c = data.cabecera;
        const dets = Array.isArray(data.detalles) ? data.detalles : [];
        const sim = c.simbolo || 'RD$';
        tit.textContent = `Nota de Crédito • ${c.ncf_nota_credito}`;

        const filas = dets.map((d) => `
      <tr>
        <td><strong>${escapeHtml(d.sku)}</strong> — ${escapeHtml(d.producto_nombre)}</td>
        <td style="font-family:monospace; font-size:0.8rem;">${escapeHtml(d.numero_lote)}</td>
        <td style="text-align:center;"><strong>${d.cantidad}</strong></td>
        <td style="text-align:center; font-size:0.78rem; font-weight:700; color:${d.reingresado_a_inventario ? '#2e7d32' : '#c62828'};">
          ${d.reingresado_a_inventario ? 'Reingresado' : 'Dañado / No Reingresa'}
        </td>
        <td style="text-align:right; font-family:monospace; font-weight:700;">${fmtDinero(d.monto_reembolsado, sim)}</td>
      </tr>
    `).join('');

        const boxCredito = c.codigo_credito ? `
      <div style="background:#edf7ed; border:1.5px dashed #66bb6a; border-radius:8px; padding:12px 14px; margin-top:14px; color:#1b5e20;">
        <div style="font-weight:800; font-size:0.9rem;">♻️ CRÉDITO A FAVOR EN TIENDA (CANJEABLE EN POS)</div>
        <div style="font-family:monospace; font-size:1.1rem; font-weight:900; margin:4px 0;">
          Código: ${escapeHtml(c.codigo_credito)} • Saldo Disponible: ${fmtDinero(c.saldo_disponible, sim)}
        </div>
        <div style="font-size:0.8rem;">
          Válido hasta: <strong>${escapeHtml(c.fecha_vencimiento_fmt || '—')}</strong> • Estado actual: <strong>${escapeHtml(c.estado_saldo || '')}</strong>
        </div>
      </div>
    ` : '';

        body.innerHTML = `
      <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #d4a359; padding-bottom:10px; margin-bottom:14px;">
        <div>
          <h2 style="margin:0; color:#211813;">HOUSE &amp; ICE • NOTA DE CRÉDITO (B04)</h2>
          <div style="font-size:0.82rem; color:#7a6b5d;">Comprobante Fiscal Modificatorio DGII</div>
        </div>
        <div style="text-align:right;">
          <div style="font-family:monospace; font-size:1.15rem; font-weight:800; color:#a66e2e;">${escapeHtml(c.ncf_nota_credito)}</div>
          <div style="font-size:0.78rem; color:#7a6b5d;">Modifica NCF: <strong>${escapeHtml(c.ncf_factura_modificada)}</strong></div>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; background:#faf7f2; border:1px solid #e7dcc8; border-radius:8px; padding:12px; margin-bottom:14px; font-size:0.85rem;">
        <div><strong>Cliente:</strong> ${escapeHtml(c.cliente_nombre)} ${c.cliente_doc ? '(' + escapeHtml(c.cliente_doc) + ')' : ''}</div>
        <div><strong>Factura Original:</strong> ${escapeHtml(c.numero_factura)} (${escapeHtml(c.condicion_pago)})</div>
        <div><strong>Fecha Emisión:</strong> ${escapeHtml(c.fecha_emision_fmt)}</div>
        <div><strong>Reembolso:</strong> <strong>${escapeHtml(c.metodo_reembolso)}</strong></div>
        <div><strong>Autorizó (PIN):</strong> ${escapeHtml(c.supervisor_nombre)}</div>
        <div><strong>Motivo:</strong> ${escapeHtml(c.justificacion_motivo)}</div>
      </div>

      <table class="carrito-reglas-table">
        <thead>
          <tr>
            <th>Producto</th>
            <th>Lote</th>
            <th style="text-align:center;">Cant.</th>
            <th style="text-align:center;">Destino Físico</th>
            <th style="text-align:right;">Reembolso</th>
          </tr>
        </thead>
        <tbody>${filas}</tbody>
      </table>

      <div style="text-align:right; font-family:monospace; font-size:1.15rem; color:#c62828; margin-top:12px;">
        TOTAL NOTA DE CRÉDITO: <strong>-${fmtDinero(c.monto_total, sim)}</strong>
      </div>
      ${boxCredito}
    `;
    } catch (err) {
        body.innerHTML = '<p style="color:#c62828;">Error de red al consultar detalle de la B04.</p>';
    }
}

function filtrarTablaDevoluciones() {
    const q = (document.getElementById('buscadorDev')?.value || '').toLowerCase().trim();
    const met = (document.getElementById('filtroMetodoDev')?.value || '').toUpperCase();
    const filas = document.querySelectorAll('.fila-devolucion');
    let vis = 0;

    filas.forEach((f) => {
        const mF = (f.getAttribute('data-metodo') || '').toUpperCase();
        const tx = f.getAttribute('data-texto') || '';
        if ((!met || mF === met) && (!q || tx.includes(q))) {
            f.style.display = '';
            vis++;
        } else {
            f.style.display = 'none';
        }
    });

    document.getElementById('badgeCountDev').textContent = `${vis} registros`;
    document.getElementById('footerInfoDev').textContent = `Mostrando ${vis} devoluciones`;
}