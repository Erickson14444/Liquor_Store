let formatoDgiiActivo = '607';
let timerDgii = null;

function escapeHtml(str) {
    return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
function fmtMoney(v) {
    return 'RD$ ' + (Number(v) || 0).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

document.addEventListener('DOMContentLoaded', cargarFiscalDgii);

function cambiarFormatoDgii(fmt) {
    formatoDgiiActivo = fmt;
    document.querySelectorAll('.dg-tab-btn').forEach((b) => b.classList.toggle('active', b.getAttribute('data-fmt') === fmt));
    ['607', '606', '608'].forEach((f) => {
        const el = document.getElementById('panelFmt_' + f);
        if (el) el.style.display = (f === fmt) ? 'block' : 'none';
    });
}

function programarCargaDgii() {
    clearTimeout(timerDgii);
    timerDgii = setTimeout(cargarFiscalDgii, 240);
}

async function cargarFiscalDgii() {
    const params = new URLSearchParams({
        accion: 'consultar_periodo',
        periodo: document.getElementById('dgPeriodo')?.value || '',
        tipo_ncf: document.getElementById('dgTipoNcf')?.value || '',
        q: (document.getElementById('dgBuscar')?.value || '').trim()
    });

    try {
        const data = await (await fetch(`procesar_fiscal_dgii.php?${params.toString()}`)).json();
        if (!data.ok) {
            ['tbody607', 'tbody606', 'tbody608'].forEach((id) => {
                const el = document.getElementById(id);
                if (el) el.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:24px; color:#c62828;">${escapeHtml(data.mensaje)}</td></tr>`;
            });
            return;
        }
        renderKpisDgii(data.kpis || {});
        renderTabla607(data.registros_607 || []);
        renderTabla606(data.registros_606 || []);
        renderTabla608(data.registros_608 || []);
    } catch (err) {
        document.getElementById('tbody607').innerHTML = '<tr><td colspan="9" style="text-align:center; padding:24px; color:#c62828;">Error de red al consultar reportes DGII.</td></tr>';
    }
}

function renderKpisDgii(k) {
    const totImp = Number((Number(k.itbis_607 || 0) + Number(k.isc_607 || 0)).toFixed(2));
    document.getElementById('kpi607Base').textContent = fmtMoney(k.ventas_base_607);
    document.getElementById('kpi607Sub').textContent = `${k.cant_607 || 0} comprobante(s) • Total c/imp.: ${fmtMoney(k.ventas_netas_607)}`;
    document.getElementById('kpi607Imp').textContent = fmtMoney(totImp);
    document.getElementById('kpi607ImpSub').textContent = `ITBIS: ${fmtMoney(k.itbis_607)} • ISC: ${fmtMoney(k.isc_607)}`;
    document.getElementById('kpi606Total').textContent = fmtMoney(k.compras_606_tot);
    document.getElementById('kpi606Sub').textContent = `${k.cant_606 || 0} comprobante(s) • Bienes: ${fmtMoney(k.compras_bienes)} | Serv.: ${fmtMoney(k.compras_serv)}`;
    document.getElementById('kpi606Ret').textContent = fmtMoney(k.ret_total_606);
    document.getElementById('kpi608Sub').textContent = `ITBIS Ret: ${fmtMoney(k.ret_itbis_606)} • ISR: ${fmtMoney(k.ret_isr_606)} • ${k.cant_608 || 0} anulado(s)`;

    document.getElementById('tabCnt607').textContent = String(k.cant_607 || 0);
    document.getElementById('tabCnt606').textContent = String(k.cant_606 || 0);
    document.getElementById('tabCnt608').textContent = String(k.cant_608 || 0);
}

function renderTabla607(rows) {
    const tbody = document.getElementById('tbody607');
    document.getElementById('badge607').textContent = `${rows.length} registro(s)`;
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:26px; color:#8a7b70;">Sin comprobantes de venta en este periodo.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map((r) => {
        const esNC = Number(r.signo) < 0;
        const [bg, col] = r.tipo_ncf === 'B01' ? ['#e3f2fd', '#0d47a1'] : (r.tipo_ncf === 'B04' ? ['#ffebee', '#b71c1c'] : (r.tipo_ncf === 'B03' ? ['#fff3e0', '#e65100'] : ['#e8f5e9', '#1b5e20']));
        const pagos = [];
        if (r.pag_efectivo > 0) pagos.push('Efec.');
        if (r.pag_banco > 0) pagos.push('Transf/Chq');
        if (r.pag_tarjeta > 0) pagos.push('Tarjeta');
        if (r.pag_credito > 0) pagos.push('Crédito');
        if (r.pag_bono > 0) pagos.push('Saldo B04');
        const pref = esNC ? '-' : '';

        return `
      <tr style="${esNC ? 'background:#fff9f9;' : ''}">
        <td style="white-space:nowrap;"><span class="dg-pill" style="background:${bg}; color:${col};">${escapeHtml(r.ncf)}</span>${r.ncf_modificado ? `<div style="font-size:0.71rem; color:#8a7b70; font-family:monospace;">Mod: ${escapeHtml(r.ncf_modificado)}</div>` : ''}</td>
        <td style="white-space:nowrap; font-family:monospace; font-size:0.82rem;">${escapeHtml(r.fecha_fmt)}</td>
        <td style="font-family:monospace; font-size:0.8rem;">${r.rnc_cedula ? escapeHtml(r.rnc_cedula) : '<span style="color:#9e9e9e;">Consumidor</span>'} <span style="color:#8a7b70;">(T${escapeHtml(r.tipo_id)})</span></td>
        <td><div style="font-weight:700; color:#211813;">${escapeHtml(r.cliente_nombre)}</div><div style="font-size:0.72rem; color:#8a7b70; font-family:monospace;">${escapeHtml(r.documento)}</div>${r.nota_fiscal ? `<div style="font-size:0.71rem; color:#b25e09; font-weight:600;">${escapeHtml(r.nota_fiscal)}</div>` : ''}</td>
        <td style="text-align:right; font-family:monospace; white-space:nowrap;">${pref}${fmtMoney(r.monto_facturado)}</td>
        <td style="text-align:right; font-family:monospace; white-space:nowrap;">${pref}${fmtMoney(r.itbis_facturado)}</td>
        <td style="text-align:right; font-family:monospace; white-space:nowrap;">${pref}${fmtMoney(r.isc_facturado)}</td>
        <td style="text-align:right; font-family:monospace; font-weight:800; color:${esNC ? '#c62828' : '#211813'}; white-space:nowrap;">${pref}${fmtMoney(r.total_dop)}</td>
        <td style="font-size:0.76rem; color:#5a3818; font-weight:600;">${escapeHtml(pagos.join(' + ') || '—')}</td>
      </tr>`;
    }).join('');
}

function renderTabla606(rows) {
    const tbody = document.getElementById('tbody606');
    document.getElementById('badge606').textContent = `${rows.length} registro(s)`;
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:26px; color:#8a7b70;">Sin compras o gastos registrados en este periodo.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map((r) => {
        const esServ = (r.origen_compra === 'SERVICIO');
        return `
      <tr>
        <td style="white-space:nowrap;"><span class="dg-pill" style="background:#fdf5ea; color:#794a15;">${escapeHtml(r.ncf || 'SIN NCF')}</span>${r.ncf_modificado ? `<div style="font-size:0.71rem; color:#8a7b70; font-family:monospace;">NC: ${escapeHtml(r.ncf_modificado)}</div>` : ''}</td>
        <td style="white-space:nowrap; font-size:0.8rem; font-family:monospace;"><div>Comp: ${escapeHtml(r.fecha_fmt)}</div><div style="font-size:0.72rem; color:#8a7b70;">Pago: ${escapeHtml(r.fecha_pago_fmt)}</div></td>
        <td><div style="font-weight:700; color:#211813;">${escapeHtml(r.proveedor_nombre)}</div><div style="font-size:0.73rem; color:#8a7b70; font-family:monospace;">${escapeHtml(r.rnc_cedula || 'S/RNC')} (T${escapeHtml(r.tipo_id)}) • ${escapeHtml(r.documento)}</div></td>
        <td style="text-align:center; white-space:nowrap;"><span class="dg-pill" style="background:${esServ ? '#ede7f6' : '#e8f5e9'}; color:${esServ ? '#5e35b1' : '#1b5e20'};">${escapeHtml(r.tipo_bienes_servicios)} • ${esServ ? 'SERV' : 'BIEN'}</span></td>
        <td style="text-align:right; font-family:monospace; font-size:0.78rem; white-space:nowrap;"><div>B: ${fmtMoney(r.monto_bienes_dop)}</div><div style="color:#8a7b70;">S: ${fmtMoney(r.monto_servicios_dop)}</div></td>
        <td style="text-align:right; font-family:monospace; font-weight:800; color:#211813; white-space:nowrap;">${fmtMoney(r.monto_total_dop)}</td>
        <td style="text-align:right; font-family:monospace; color:${r.itbis_retenido_dop > 0 ? '#c62828' : '#8a7b70'}; font-weight:${r.itbis_retenido_dop > 0 ? '700' : '400'};">${fmtMoney(r.itbis_retenido_dop)}</td>
        <td style="text-align:right; font-family:monospace; color:${r.isr_retenido_dop > 0 ? '#c62828' : '#8a7b70'}; font-weight:${r.isr_retenido_dop > 0 ? '700' : '400'};">${fmtMoney(r.isr_retenido_dop)}</td>
        <td style="font-size:0.76rem;"><strong>${escapeHtml(r.cod_forma_pago_dgii)}</strong> • ${escapeHtml(r.forma_pago_txt)}<div style="font-size:0.71rem; color:#8a7b70;">CxP: ${escapeHtml(r.estado_cxp)}</div></td>
      </tr>`;
    }).join('');
}

function renderTabla608(rows) {
    const tbody = document.getElementById('tbody608');
    document.getElementById('badge608').textContent = `${rows.length} registro(s)`;
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:26px; color:#2e7d32; font-weight:600;">✅ Sin comprobantes fiscales anulados en este periodo.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map((r) => `
    <tr>
      <td><span class="dg-pill" style="background:#ffebee; color:#b71c1c;">${escapeHtml(r.ncf)}</span></td>
      <td style="font-family:monospace; font-size:0.82rem;">${escapeHtml(r.fecha_fmt)}</td>
      <td style="font-size:0.82rem; font-weight:600; color:#5a3818;">${escapeHtml(r.tipo_anulacion)}</td>
      <td style="font-family:monospace; font-weight:700;">${escapeHtml(r.numero_factura)}</td>
      <td>${escapeHtml(r.cliente_nombre)}</td>
      <td style="font-size:0.82rem;">${escapeHtml(r.supervisor_nombre)}</td>
      <td style="text-align:right; padding-right:18px; font-family:monospace; font-weight:700; color:#c62828;">${fmtMoney(r.monto_anulado_dop)}</td>
    </tr>`).join('');
}

function descargarArchivoDgii(accionExport) {
    const params = new URLSearchParams({
        accion: accionExport,
        formato: formatoDgiiActivo,
        periodo: document.getElementById('dgPeriodo')?.value || '',
        tipo_ncf: document.getElementById('dgTipoNcf')?.value || '',
        rnc_empresa: (document.getElementById('dgRncEmpresa')?.value || '131000000').trim(),
        q: (document.getElementById('dgBuscar')?.value || '').trim()
    });
    window.location.href = `procesar_fiscal_dgii.php?${params.toString()}`;
}