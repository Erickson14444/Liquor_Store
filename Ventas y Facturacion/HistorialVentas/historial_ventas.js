/**
 * ============================================================================
 * Controlador Frontend • Reporte: Historial de Ventas
 * Ubicación: Ventas y Facturacion/HistorialVentas/historial_ventas.js
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

let timerHist = null;

const ESTADOS_FAC_BADGE = {
    PAGADA: { bg: '#e8f5e9', color: '#2e7d32', label: 'PAGADA' },
    EMITIDA: { bg: '#fff3e0', color: '#e65100', label: 'EMITIDA' },
    EN_TRANSITO: { bg: '#e3f2fd', color: '#1565c0', label: 'EN TRÁNSITO' },
    ENTREGADA: { bg: '#e0f2f1', color: '#00695c', label: 'ENTREGADA' },
    ANULADA: { bg: '#ffebee', color: '#c62828', label: 'ANULADA' }
};

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function fmtMoney(v, sim = 'RD$') {
    return `${sim} ${(Number(v) || 0).toLocaleString('es-DO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    })}`;
}

function mostrarToast(msg, isError = false) {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'toast show ' + (isError ? 'toast-error' : 'toast-success');
    clearTimeout(window._toastHvTimer);
    window._toastHvTimer = setTimeout(() => t.classList.remove('show'), 4000);
}

function abrirModal(id) { document.getElementById(id)?.classList.add('open', 'active'); }
function cerrarModal(id) { document.getElementById(id)?.classList.remove('open', 'active'); }

document.addEventListener('DOMContentLoaded', () => {
    cargarHistorialVentas();

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            cerrarModal('modalComprobanteVenta');
            cerrarModal('modalAnularHistorial');
        }
    });

    window.addEventListener('afterprint', () => {
        document.body.classList.remove('imprimiendo-comprobante');
    });
});

// ============================================================================
// FILTROS, PRESETS DE FECHA Y CARGA AJAX
// ============================================================================
function formatoFechaIso(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function aplicarPresetFecha(preset) {
    const hoy = new Date();
    const inpDesde = document.getElementById('hv_fecha_desde');
    const inpHasta = document.getElementById('hv_fecha_hasta');

    if (preset === 'hoy') {
        const f = formatoFechaIso(hoy);
        inpDesde.value = f;
        inpHasta.value = f;
    } else if (preset === '7d') {
        const hace7 = new Date(hoy.getTime() - 6 * 86400000);
        inpDesde.value = formatoFechaIso(hace7);
        inpHasta.value = formatoFechaIso(hoy);
    } else if (preset === 'mes') {
        const inicioMes = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
        inpDesde.value = formatoFechaIso(inicioMes);
        inpHasta.value = formatoFechaIso(hoy);
    } else if (preset === 'todo') {
        inpDesde.value = '';
        inpHasta.value = '';
    }

    document.querySelectorAll('.hv-preset-btn[data-preset]').forEach((b) => {
        b.classList.toggle('active', b.getAttribute('data-preset') === preset);
    });

    cargarHistorialVentas();
}

function alCambiarFechaManual() {
    document.querySelectorAll('.hv-preset-btn[data-preset]').forEach((b) => b.classList.remove('active'));
    cargarHistorialVentas();
}

function limpiarFiltrosHistorial() {
    document.getElementById('hv_q').value = '';
    document.getElementById('hv_tipo_ncf').value = '';
    document.getElementById('hv_condicion').value = '';
    document.getElementById('hv_origen').value = '';
    document.getElementById('hv_estado').value = '';
    document.getElementById('hv_metodo').value = '0';
    document.getElementById('hv_vendedor').value = '';
    aplicarPresetFecha('mes');
}

function construirParamsFiltro(accion) {
    return new URLSearchParams({
        accion,
        q: (document.getElementById('hv_q')?.value || '').trim(),
        fecha_desde: document.getElementById('hv_fecha_desde')?.value || '',
        fecha_hasta: document.getElementById('hv_fecha_hasta')?.value || '',
        tipo_ncf: document.getElementById('hv_tipo_ncf')?.value || '',
        condicion_pago: document.getElementById('hv_condicion')?.value || '',
        origen_venta: document.getElementById('hv_origen')?.value || '',
        estado: document.getElementById('hv_estado')?.value || '',
        metodo_pago_id: document.getElementById('hv_metodo')?.value || '0',
        vendedor_id: document.getElementById('hv_vendedor')?.value || ''
    });
}

function programarCargaHistorial() {
    clearTimeout(timerHist);
    timerHist = setTimeout(cargarHistorialVentas, 240);
}

async function cargarHistorialVentas() {
    const tbody = document.getElementById('hvTablaBody');
    tbody.innerHTML = '<tr><td colspan="8" class="text-center" style="padding:28px; color:#888;">Consultando ventas...</td></tr>';

    try {
        const params = construirParamsFiltro('listar');
        const res = await fetch(`procesar_historial_ventas.php?${params.toString()}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (!data.ok) {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center" style="padding:28px; color:#c62828;">${escapeHtml(data.mensaje || 'Error al cargar historial.')}</td></tr>`;
            return;
        }

        renderKpisHistorial(data.kpis || {});
        renderTablaHistorial(data.facturas || [], data.kpis?.total_registros || 0);
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center" style="padding:28px; color:#c62828;">Error de conexión con el servidor.</td></tr>';
    }
}

function renderKpisHistorial(k) {
    document.getElementById('kpiVentasNetas').textContent = fmtMoney(k.ventas_netas_dop);
    document.getElementById('kpiVentasSub').textContent = `${k.facturas_validas || 0} factura(s) válida(s) • Ticket prom.: ${fmtMoney(k.ticket_promedio_dop)}`;

    document.getElementById('kpiImpuestos').textContent = fmtMoney(k.impuestos_dop);
    document.getElementById('kpiDescuentosSub').textContent = `Descuentos y cupones: ${fmtMoney(k.descuentos_dop)}`;

    document.getElementById('kpiContado').textContent = fmtMoney(k.contado_dop);
    document.getElementById('kpiCreditoSub').textContent = `Crédito (CxC): ${fmtMoney(k.credito_dop)}`;

    document.getElementById('kpiDevoluciones').textContent = fmtMoney(k.devoluciones_b04_dop);
    document.getElementById('kpiAnuladasSub').textContent = `${k.facturas_anuladas || 0} anulada(s) (${fmtMoney(k.monto_anulado_dop)})`;

    const bannerMargen = document.getElementById('hvMargenBanner');
    if (bannerMargen) {
        if (k.mostrar_margen && k.utilidad_est_dop !== null && k.utilidad_est_dop !== undefined) {
            bannerMargen.style.display = 'inline-block';
            bannerMargen.textContent = `📈 Utilidad Bruta Est.: ${fmtMoney(k.utilidad_est_dop)} (Costo: ${fmtMoney(k.costo_mercancia_dop)})`;
        } else {
            bannerMargen.style.display = 'none';
        }
    }
}

function renderTablaHistorial(lista, totalGlobal) {
    const tbody = document.getElementById('hvTablaBody');
    const badge = document.getElementById('hvBadgeTotal');
    const footer = document.getElementById('hvFooterInfo');

    badge.textContent = `${totalGlobal} factura(s)`;
    footer.textContent = `Mostrando ${lista.length} de ${totalGlobal} factura(s) coincidentes`;

    if (lista.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center" style="padding:32px; color:#888;">No se encontraron facturas con los filtros seleccionados.</td></tr>';
        return;
    }

    tbody.innerHTML = lista.map((f) => {
        const est = String(f.estado || '').toUpperCase();
        const st = ESTADOS_FAC_BADGE[est] || { bg: '#eadecd', color: '#5a3818', label: est };
        const sim = f.simbolo || 'RD$';
        const metodos = f.metodos_pago_txt
            ? `<span class="hv-badge-pago">${escapeHtml(f.metodos_pago_txt)}</span>`
            : (f.condicion_pago === 'CREDITO' ? '<span class="hv-badge-pago">CXC 30d</span>' : '—');
        const devBadge = Number(f.monto_devuelto || 0) > 0
            ? `<div style="font-size:0.71rem; color:#c62828; font-weight:700;">B04: -${fmtMoney(f.monto_devuelto, sim)}</div>`
            : '';

        const btnAnular = f.puede_anular
            ? `<button type="button" class="btn-sm btn-danger" onclick="abrirModalAnularHist(${Number(f.id)}, '${escapeHtml(f.numero_factura)}')">Anular</button>`
            : '';

        return `
      <tr style="${est === 'ANULADA' ? 'opacity:0.65; background:#fffafa;' : ''}">
        <td style="white-space:nowrap;">
          <strong style="font-family:monospace; color:#a66e2e;">${escapeHtml(f.numero_factura)}</strong>
          <div style="font-size:0.74rem; color:#8a7b70;">${escapeHtml(f.fecha_fmt)}</div>
        </td>
        <td style="white-space:nowrap;">
          <span class="badge-count" style="font-size:0.74rem; font-family:monospace;">${escapeHtml(f.ncf)}</span>
          <div style="font-size:0.72rem; color:#8a7b70; margin-top:2px;">${escapeHtml(f.tipo_ncf)} • ${f.total_unidades} ud(s).</div>
        </td>
        <td>
          <div style="font-weight:700; color:#211813;">${escapeHtml(f.cliente_nombre)}</div>
          ${f.cliente_doc ? `<div style="font-size:0.73rem; color:#8a7b70; font-family:monospace;">${escapeHtml(f.cliente_doc)}</div>` : ''}
        </td>
        <td style="font-size:0.82rem;">
          <div style="font-weight:600; color:#211813;">${escapeHtml(f.vendedor_nombre)}</div>
          <div style="font-size:0.72rem; color:#8a7b70;">${escapeHtml(f.caja_nombre)}</div>
        </td>
        <td style="font-size:0.78rem;">
          <div><strong>${escapeHtml(f.condicion_pago)}</strong> • ${escapeHtml(f.origen_venta)}</div>
          <div style="margin-top:3px;">${metodos}</div>
        </td>
        <td class="text-right" style="white-space:nowrap; font-family:monospace;">
          <div style="font-weight:800; color:#211813;">${fmtMoney(f.monto_total, sim)}</div>
          <div style="font-size:0.72rem; color:#8a7b70;">Imp: ${fmtMoney(f.monto_impuestos, sim)}</div>
          ${devBadge}
        </td>
        <td class="text-center" style="white-space:nowrap;">
          <span class="badge" style="background:${st.bg}; color:${st.color}; font-weight:800; font-size:0.72rem;">
            ${escapeHtml(st.label)}
          </span>
        </td>
        <td class="text-right no-print" style="white-space:nowrap; padding-right:18px;">
          <button type="button" class="btn-sm btn-outline" onclick="verComprobanteVenta(${Number(f.id)})">Ver / Imprimir</button>
          ${btnAnular}
        </td>
      </tr>
    `;
    }).join('');
}

// ============================================================================
// DETALLE COMPLETO Y REIMPRESIÓN DE COMPROBANTE
// ============================================================================
async function verComprobanteVenta(facturaId) {
    const tit = document.getElementById('detFacTitulo');
    const body = document.getElementById('areaImpresionFactura');

    tit.textContent = 'Detalle de Comprobante';
    body.innerHTML = '<p style="color:#888;">Consultando comprobante fiscal...</p>';
    abrirModal('modalComprobanteVenta');

    try {
        const res = await fetch(`procesar_historial_ventas.php?accion=detalle_factura&factura_id=${encodeURIComponent(facturaId)}`);
        const data = await res.json();
        if (!data.ok || !data.cabecera) {
            body.innerHTML = `<p style="color:#c62828;">${escapeHtml(data.mensaje || 'No se pudo cargar el comprobante.')}</p>`;
            return;
        }

        const c = data.cabecera;
        const sim = c.simbolo || 'RD$';
        const dets = Array.isArray(data.detalles) ? data.detalles : [];
        const imps = Array.isArray(data.resumen_impuestos) ? data.resumen_impuestos : [];
        const pags = Array.isArray(data.pagos) ? data.pagos : [];
        const devs = Array.isArray(data.devoluciones) ? data.devoluciones : [];
        const cups = Array.isArray(data.cupones_generados) ? data.cupones_generados : [];
        const del = data.delivery || null;

        tit.textContent = `${c.numero_factura} • ${c.ncf}`;

        const filas = dets.map((d) => `
      <tr>
        <td>
          <strong>${escapeHtml(d.sku)}</strong> — ${escapeHtml(d.producto_nombre)}
          <div style="font-size:0.74rem; color:#7a6b5d;">${escapeHtml(d.presentacion)} (${d.cantidad_unidades} uds. base)</div>
          ${d.nombre_promocion ? `<div style="font-size:0.72rem; color:#2e7d32; font-weight:700;">🏷️ ${escapeHtml(d.nombre_promocion)}</div>` : ''}
        </td>
        <td style="text-align:center;"><strong>${d.cantidad_presentacion}</strong></td>
        <td style="text-align:right; font-family:monospace;">${fmtMoney(d.precio_unitario_his, sim)}</td>
        <td style="text-align:right; font-family:monospace; color:#2e7d32;">-${fmtMoney(d.monto_descuento, sim)}</td>
        <td style="text-align:right; font-family:monospace;">${fmtMoney(d.monto_impuestos, sim)}</td>
        <td style="text-align:right; font-family:monospace; font-weight:700;">${fmtMoney(d.monto_total, sim)}</td>
      </tr>
    `).join('');

        const impPills = imps.map((ri) =>
            `<span class="hv-tax-pill">${escapeHtml(ri.siglas)}: ${fmtMoney(ri.monto, sim)}</span>`
        ).join('');

        const pagHtml = pags.map((p) => `
      <li>
        <strong>${escapeHtml(p.metodo_codigo)}</strong>: ${fmtMoney(p.monto_pagado, sim)}
        ${Number(p.monto_devuelta) > 0 ? ` (Devuelta: ${fmtMoney(p.monto_devuelta, sim)})` : ''}
        ${p.ultimos_4_digitos ? ` • ${escapeHtml(p.marca_tarjeta)} ****${escapeHtml(p.ultimos_4_digitos)} (Aut: ${escapeHtml(p.numero_autorizacion)})` : ''}
        ${p.ref_transferencia ? ` • ${escapeHtml(p.banco_origen)} Ref: ${escapeHtml(p.ref_transferencia)}` : ''}
        ${p.numero_cheque ? ` • Cheque #${escapeHtml(p.numero_cheque)} (${escapeHtml(p.banco_emisor)})` : ''}
        ${p.origen_saldo ? ` • ${escapeHtml(p.origen_saldo)}` : ''}
      </li>
    `).join('');

        const delHtml = del ? `
      <div style="background:#fdfaf4; border:1px solid #e7dcc8; border-radius:8px; padding:10px 12px; margin-top:12px; font-size:0.83rem;">
        <strong>🛵 Delivery (${escapeHtml(del.estado_delivery)}):</strong>
        Zona ${escapeHtml(del.zona_nombre)} (+${fmtMoney(del.tarifa_envio, sim)}) •
        Repartidor: ${escapeHtml(del.repartidor_nombre)} (${escapeHtml(del.codigo_empleado)})<br>
        <strong>Dirección:</strong> ${escapeHtml(del.direccion_destino)}
        ${del.hora_entrega_fmt ? ` • Entregado: ${escapeHtml(del.hora_entrega_fmt)}` : ''}
      </div>
    ` : '';

        const devHtml = devs.length > 0 ? `
      <div style="background:#ffebee; border:1px solid #ef9a9a; border-radius:8px; padding:10px 12px; margin-top:12px; font-size:0.83rem; color:#b71c1c;">
        <strong>🔄 Notas de Crédito B04 aplicadas a esta factura:</strong>
        <ul style="margin:5px 0 0 18px;">
          ${devs.map((dv) => `<li><strong>${escapeHtml(dv.ncf_nota_credito)}</strong> (${escapeHtml(dv.fecha_fmt)}): -${fmtMoney(dv.monto_total, sim)} • ${escapeHtml(dv.metodo_reembolso)}${dv.codigo_credito ? ` [${escapeHtml(dv.codigo_credito)} - ${escapeHtml(dv.estado_saldo)}]` : ''}</li>`).join('')}
        </ul>
      </div>
    ` : '';

        const cupGenHtml = cups.map((cg) =>
            `<div style="background:#edf7ed; border:1px solid #b7dfb9; padding:8px 12px; border-radius:6px; margin-top:10px; font-size:0.83rem; color:#1b5e20;">🎁 Cupón emitido: <strong>${escapeHtml(cg.codigo_cupon)}</strong> por ${fmtMoney(cg.monto_credito, sim)} (Vence: ${escapeHtml(cg.vence_fmt)} • ${escapeHtml(cg.estado)})</div>`
        ).join('');

        body.innerHTML = `
      <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #d4a359; padding-bottom:10px; margin-bottom:14px;">
        <div>
          <h2 style="margin:0; color:#211813;">HOUSE &amp; ICE • FACTURA DE VENTA</h2>
          <div style="font-size:0.82rem; color:#7a6b5d;">Comprobante Fiscal DGII (${escapeHtml(c.tipo_ncf)}) • ${escapeHtml(c.caja_nombre)}</div>
        </div>
        <div style="text-align:right;">
          <div style="font-family:monospace; font-size:1.1rem; font-weight:800; color:#a66e2e;">${escapeHtml(c.numero_factura)}</div>
          <div style="font-family:monospace; font-size:0.84rem; font-weight:700;">NCF: ${escapeHtml(c.ncf)}</div>
          <div style="font-size:0.76rem; font-weight:800; color:${c.estado === 'ANULADA' ? '#c62828' : '#2e7d32'};">Estado: ${escapeHtml(c.estado)}</div>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; background:#faf7f2; border:1px solid #e7dcc8; border-radius:8px; padding:12px; margin-bottom:14px; font-size:0.84rem;">
        <div><strong>Cliente:</strong> ${escapeHtml(c.cliente_nombre)}</div>
        <div><strong>RNC / Cédula:</strong> ${escapeHtml(c.cliente_doc || 'Consumidor Final')}</div>
        <div><strong>Fecha:</strong> ${escapeHtml(c.fecha_fmt)}</div>
        <div><strong>Condición / Canal:</strong> ${escapeHtml(c.condicion_pago)} / ${escapeHtml(c.origen_venta)}</div>
        <div><strong>Cajero / Vendedor:</strong> ${escapeHtml(c.vendedor_nombre)}</div>
        <div><strong>Cupón Redimido:</strong> ${c.cupon_redimido_codigo ? `${escapeHtml(c.cupon_redimido_codigo)} (-${fmtMoney(c.cupon_redimido_monto, sim)})` : 'Ninguno'}</div>
        ${c.cotizacion_numero ? `<div><strong>Cotización Origen:</strong> ${escapeHtml(c.cotizacion_numero)}</div>` : ''}
        ${c.supervisor_anulacion_nombre ? `<div style="color:#c62828;"><strong>Anulada por:</strong> ${escapeHtml(c.supervisor_anulacion_nombre)}</div>` : ''}
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
          </tr>
        </thead>
        <tbody>${filas}</tbody>
      </table>

      <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-top:14px; flex-wrap:wrap; gap:10px;">
        <div>${impPills}</div>
        <div style="text-align:right; font-family:monospace; font-size:0.9rem;">
          <div>Subtotal Bruto: <strong>${fmtMoney(c.monto_subtotal, sim)}</strong></div>
          <div>Descuentos: <strong style="color:#2e7d32;">-${fmtMoney(c.monto_descuento, sim)}</strong></div>
          <div>Impuestos: <strong>${fmtMoney(c.monto_impuestos, sim)}</strong></div>
          <div style="font-size:1.15rem; color:#a66e2e; margin-top:4px;">TOTAL: <strong>${fmtMoney(c.monto_total, sim)}</strong></div>
        </div>
      </div>

      <div style="margin-top:12px; font-size:0.84rem;">
        <strong>Pagos Registrados:</strong>
        <ul style="margin:6px 0 0 18px;">${pagHtml || '<li>Venta a Crédito Comercial (Cuentas por Cobrar)</li>'}</ul>
      </div>

      ${delHtml}
      ${devHtml}
      ${cupGenHtml}
    `;
    } catch (err) {
        body.innerHTML = '<p style="color:#c62828;">Error de red al consultar el comprobante.</p>';
    }
}

function imprimirComprobanteIndividual() {
    document.body.classList.add('imprimiendo-comprobante');
    window.print();
}

function imprimirReporteGeneral() {
    document.body.classList.remove('imprimiendo-comprobante');
    window.print();
}

function exportarHistorialCsv() {
    const params = construirParamsFiltro('exportar_csv');
    window.location.href = `procesar_historial_ventas.php?${params.toString()}`;
}

// ============================================================================
// ANULACIÓN CON PIN (REUTILIZA EL CONTROLADOR OFICIAL DE FACTURACIÓN)
// ============================================================================
function abrirModalAnularHist(facturaId, numeroFactura) {
    document.getElementById('anularHistFacturaId').value = String(facturaId);
    document.getElementById('anularHistNumero').textContent = numeroFactura;
    document.getElementById('anularHistJustificacion').value = '';
    document.getElementById('anularHistPin').value = '';
    abrirModal('modalAnularHistorial');
}

async function confirmarAnulacionHistorial(e) {
    e.preventDefault();
    const btn = document.getElementById('btnConfirmarAnularHist');
    btn.disabled = true;
    btn.textContent = 'Anulando...';

    try {
        const res = await fetch('../Facturacion/procesar_facturacion.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                accion: 'anular_factura',
                factura_id: parseInt(document.getElementById('anularHistFacturaId').value, 10),
                justificacion: document.getElementById('anularHistJustificacion').value.trim(),
                supervisor_id: document.getElementById('anularHistSupervisor').value,
                pin_autorizacion: document.getElementById('anularHistPin').value.trim()
            })
        });
        const data = await res.json();
        if (!data.ok) {
            mostrarToast(data.mensaje || 'No se pudo anular la factura.', true);
            btn.disabled = false;
            btn.textContent = 'Confirmar Anulación';
            return;
        }
        cerrarModal('modalAnularHistorial');
        mostrarToast(data.mensaje || 'Factura anulada.');
        btn.disabled = false;
        btn.textContent = 'Confirmar Anulación';
        cargarHistorialVentas();
    } catch (err) {
        mostrarToast('Error de red al anular la factura.', true);
        btn.disabled = false;
        btn.textContent = 'Confirmar Anulación';
    }
}