let tabActivaKardex = 'inventario';
let timerInv = null, timerKar = null, timerFefo = null;

function escapeHtml(str) {
    return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
function fmtMoney(v) {
    return 'RD$ ' + (Number(v) || 0).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

document.addEventListener('DOMContentLoaded', () => {
    cargarInventarioConsolidado();
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrarModalProd(); });
});

function cambiarTabKardex(tab) {
    tabActivaKardex = tab;
    document.querySelectorAll('.kf-tab-btn').forEach((b) => b.classList.toggle('active', b.getAttribute('data-tab') === tab));
    ['inventario', 'kardex', 'fefo'].forEach((t) => {
        const el = document.getElementById('panelTab_' + t);
        if (el) el.style.display = (t === tab) ? 'block' : 'none';
    });
    if (tab === 'inventario') cargarInventarioConsolidado();
    else if (tab === 'kardex') cargarLibroKardex();
    else cargarMatrizFefo();
}

function programarCargaInv() { clearTimeout(timerInv); timerInv = setTimeout(cargarInventarioConsolidado, 220); }
function programarCargaKar() { clearTimeout(timerKar); timerKar = setTimeout(cargarLibroKardex, 220); }
function programarCargaFefo() { clearTimeout(timerFefo); timerFefo = setTimeout(cargarMatrizFefo, 220); }

async function cargarInventarioConsolidado() {
    const tbody = document.getElementById('tbodyInvConsolidado');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:24px; color:#8a7b70;">Calculando existencias y valoración...</td></tr>';

    const params = new URLSearchParams({
        accion: 'resumen_inventario',
        almacen_id: document.getElementById('invAlmacen')?.value || '0',
        categoria_id: document.getElementById('invCategoria')?.value || '0',
        marca_id: document.getElementById('invMarca')?.value || '0',
        solo_con_stock: document.getElementById('invSoloStock')?.checked ? '1' : '0',
        q: (document.getElementById('invBuscar')?.value || '').trim()
    });

    try {
        const data = await (await fetch(`procesar_kardex_fefo.php?${params.toString()}`)).json();
        if (!data.ok) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:24px; color:#c62828;">${escapeHtml(data.mensaje)}</td></tr>`;
            return;
        }

        const k = data.kpis || {};
        document.getElementById('kpiValorCosto').textContent = fmtMoney(k.valor_costo_total);
        document.getElementById('kpiSkusSub').textContent = `${k.skus_con_stock || 0} SKU(s) con stock • ${k.skus_agotados || 0} agotado(s)`;
        document.getElementById('kpiValorVenta').textContent = fmtMoney(k.valor_venta_total);
        document.getElementById('kpiMargenSub').textContent = `Margen bruto potencial: ${fmtMoney(k.margen_bruto_est)}`;
        document.getElementById('kpiUnidadesFisicas').textContent = `${Number(k.unidades_fisicas || 0).toLocaleString('es-DO')} uds.`;
        document.getElementById('kpiUnidadesDispSub').textContent = `${Number(k.unidades_disponibles || 0).toLocaleString('es-DO')} disponibles para venta`;
        document.getElementById('kpiValorRiesgo').textContent = fmtMoney(k.valor_riesgo_30d);
        document.getElementById('kpiRiesgoSub').textContent = `${k.lotes_riesgo_30d || 0} lote(s) • ${k.unidades_riesgo_30d || 0} unidades`;

        const items = Array.isArray(data.items) ? data.items : [];
        document.getElementById('badgeInvCount').textContent = `${items.length} producto(s)`;

        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:26px; color:#8a7b70;">Sin productos con los filtros aplicados.</td></tr>';
            return;
        }

        tbody.innerHTML = items.map((it) => {
            const almPills = (it.almacenes || []).length
                ? it.almacenes.map((a) => `<span class="kf-alm-pill">${escapeHtml(a.almacen_nombre)}: <strong>${a.fisico}</strong></span>`).join('')
                : '<span style="font-size:0.75rem; color:#9e9e9e;">Sin existencias físicas</span>';

            let vencHtml = '<span style="font-size:0.75rem; color:#8a7b70;">Sin lote</span>';
            if (it.proximo_vencimiento) {
                const d = it.dias_para_vencer;
                const [bg, col] = d < 0 ? ['#ffebee', '#b71c1c'] : (d <= 15 ? ['#fff3e0', '#e65100'] : (d <= 45 ? ['#fff8e1', '#b25e09'] : ['#e8f5e9', '#1b5e20']));
                vencHtml = `<span class="kf-badge" style="background:${bg}; color:${col};">${escapeHtml(it.proximo_vencimiento)} (${d}d)</span>`;
            }

            return `
        <tr style="${it.stock_fisico_total === 0 ? 'opacity:0.72;' : ''}">
          <td>
            <div style="font-weight:700; color:#211813;">${escapeHtml(it.producto_nombre)}</div>
            <div style="font-size:0.74rem; color:#794a15; font-family:monospace;">${escapeHtml(it.sku)} • ${escapeHtml(it.categoria_nombre)} / ${escapeHtml(it.marca_nombre)}</div>
          </td>
          <td>${almPills}</td>
          <td style="text-align:center; font-family:monospace; white-space:nowrap;">
            <strong style="font-size:0.96rem; color:${it.stock_fisico_total > 0 ? '#1b5e20' : '#c62828'};">${it.stock_fisico_total}</strong>
            <span style="color:#8a7b70;">/ ${it.stock_disponible_total}</span>
            ${it.lotes_activos > 0 ? `<div style="font-size:0.7rem; color:#8a7b70;">${it.lotes_activos} lote(s)</div>` : ''}
          </td>
          <td style="text-align:center; white-space:nowrap;">${vencHtml}</td>
          <td style="text-align:right; font-family:monospace; white-space:nowrap;">
            <div style="font-weight:700; color:#794a15;">${fmtMoney(it.costo_promedio)}</div>
            <div style="font-size:0.73rem; color:#8a7b70;">PVP: ${fmtMoney(it.precio_base_unidad)}</div>
          </td>
          <td style="text-align:right; font-family:monospace; font-weight:800; color:#211813; white-space:nowrap;">${fmtMoney(it.valor_costo_total)}</td>
          <td style="text-align:right; font-family:monospace; white-space:nowrap;">
            <div style="font-weight:700; color:#1b5e20;">${fmtMoney(it.valor_venta_total)}</div>
            <div style="font-size:0.71rem; color:#2e7d32;">+${fmtMoney(it.margen_potencial)}</div>
          </td>
          <td class="no-print" style="text-align:right; padding-right:16px; white-space:nowrap;">
            <button type="button" class="btn btn-beige btn-sm" onclick="verDetalleProducto(${it.producto_maestro_id})">📦 Lotes</button>
            <button type="button" class="btn btn-gold btn-sm" onclick="abrirKardexDeProducto(${it.producto_maestro_id})">📜 Kardex</button>
          </td>
        </tr>`;
        }).join('');
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:24px; color:#c62828;">Error de red al consultar inventario.</td></tr>';
    }
}

async function verDetalleProducto(pmId) {
    const modal = document.getElementById('modalDetalleProducto');
    const tit = document.getElementById('detProdTitulo');
    const sub = document.getElementById('detProdSubtitulo');
    const body = document.getElementById('detProdBody');

    tit.textContent = 'Detalle de Existencias';
    sub.textContent = 'Consultando almacenes y lotes FEFO...';
    body.innerHTML = '<p style="color:#8a7b70;">Cargando...</p>';
    modal.style.display = 'flex';

    try {
        const data = await (await fetch(`procesar_kardex_fefo.php?accion=detalle_producto_stock&producto_id=${encodeURIComponent(pmId)}`)).json();
        if (!data.ok || !data.producto) { body.innerHTML = `<p style="color:#c62828;">${escapeHtml(data.mensaje || 'Error.')}</p>`; return; }

        const p = data.producto, desglose = data.desglose || [], pres = data.presentaciones || [];
        tit.textContent = `${p.sku} — ${p.nombre}`;
        sub.textContent = `${p.categoria_nombre} • ${p.marca_nombre} • Costo Prom.: ${fmtMoney(p.costo_promedio)} • PVP: ${fmtMoney(p.precio_base_unidad)}`;

        let totUds = 0, totVal = 0;
        const filas = desglose.length ? desglose.map((d) => {
            totUds += Number(d.stock_fisico_unidades || 0);
            totVal += Number(d.valor_costo || 0);
            return `<tr style="border-bottom:1px solid #f0e6d6;">
        <td style="padding:7px 10px;"><strong>${escapeHtml(d.almacen_nombre)}</strong></td>
        <td style="padding:7px 10px; font-family:monospace;">${escapeHtml(d.numero_lote)}</td>
        <td style="padding:7px 10px;">${escapeHtml(d.vencimiento_fmt)}${d.dias_restantes !== null ? ` (${d.dias_restantes}d)` : ''}</td>
        <td style="padding:7px 10px; text-align:center; font-family:monospace; font-weight:700;">${d.stock_fisico_unidades} / ${d.stock_disponible}</td>
        <td style="padding:7px 10px; text-align:right; font-family:monospace; font-weight:700; color:#794a15;">${fmtMoney(d.valor_costo)}</td>
      </tr>`;
        }).join('') : '<tr><td colspan="5" style="padding:16px; text-align:center; color:#8a7b70;">0 unidades físicas en todos los almacenes.</td></tr>';

        const presHtml = pres.length ? `<div style="margin-top:14px;"><div style="font-weight:700; font-size:0.84rem; margin-bottom:6px;">Presentaciones / Códigos:</div><div style="display:flex; flex-wrap:wrap; gap:6px;">${pres.map((pr) => `<span class="kf-alm-pill">${escapeHtml(pr.descripcion_presentacion)} (x${pr.factor_multiplicador}) • ${fmtMoney(pr.precio_venta_presentacion)} [${escapeHtml(pr.codigo_barra)}]</span>`).join('')}</div></div>` : '';

        body.innerHTML = `
      <table style="width:100%; border-collapse:collapse; border:1px solid #eadecd; font-size:0.84rem;">
        <thead style="background:#fdfaf4; border-bottom:1px solid #eadecd;">
          <tr><th style="padding:8px 10px; text-align:left;">Almacén</th><th style="padding:8px 10px; text-align:left;">Lote</th><th style="padding:8px 10px; text-align:left;">Vencimiento</th><th style="padding:8px 10px; text-align:center;">Físico / Disp.</th><th style="padding:8px 10px; text-align:right;">Valor Costo</th></tr>
        </thead>
        <tbody>${filas}</tbody>
        <tfoot style="background:#fdf8ee; font-weight:800;">
          <tr><td colspan="3" style="padding:8px 10px;">TOTAL INVENTARIO DEL PRODUCTO</td><td style="padding:8px 10px; text-align:center; font-family:monospace;">${totUds} uds.</td><td style="padding:8px 10px; text-align:right; font-family:monospace; color:#794a15;">${fmtMoney(totVal)}</td></tr>
        </tfoot>
      </table>${presHtml}`;
    } catch (err) {
        body.innerHTML = '<p style="color:#c62828;">Error de red al consultar detalle.</p>';
    }
}

function cerrarModalProd() { const m = document.getElementById('modalDetalleProducto'); if (m) m.style.display = 'none'; }

function abrirKardexDeProducto(pmId) {
    const sel = document.getElementById('karProducto');
    if (sel) sel.value = String(pmId);
    document.getElementById('karTipo').value = '';
    document.getElementById('karBuscar').value = '';
    cambiarTabKardex('kardex');
}

async function cargarLibroKardex() {
    const tbody = document.getElementById('tbodyLibroKardex');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:24px; color:#8a7b70;">Consultando libro Kardex...</td></tr>';

    const params = new URLSearchParams({
        accion: 'libro_kardex',
        producto_id: document.getElementById('karProducto')?.value || '0',
        tipo_movimiento: document.getElementById('karTipo')?.value || '',
        fecha_desde: document.getElementById('karDesde')?.value || '',
        fecha_hasta: document.getElementById('karHasta')?.value || '',
        q: (document.getElementById('karBuscar')?.value || '').trim()
    });

    try {
        const data = await (await fetch(`procesar_kardex_fefo.php?${params.toString()}`)).json();
        if (!data.ok) { tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:24px; color:#c62828;">${escapeHtml(data.mensaje)}</td></tr>`; return; }

        const r = data.resumen || {};
        document.getElementById('badgeKardexCount').textContent = `${r.total_movimientos || 0} movimiento(s)`;
        document.getElementById('karEntradasLbl').textContent = `+${r.entradas_uds || 0} uds. (${fmtMoney(r.entradas_val)})`;
        document.getElementById('karSalidasLbl').textContent = `-${r.salidas_uds || 0} uds. (${fmtMoney(r.salidas_val)})`;

        const wIni = document.getElementById('karSaldoInicialWrap'), wFin = document.getElementById('karSaldoFinalWrap');
        if (data.con_saldo_corrido) {
            wIni.style.display = 'inline'; wFin.style.display = 'inline';
            document.getElementById('karSaldoInicialLbl').textContent = `${data.saldo_inicial_uds} uds. (${fmtMoney(data.saldo_inicial_val)})`;
            document.getElementById('karSaldoFinalLbl').textContent = `${r.saldo_final_uds} uds. (${fmtMoney(r.saldo_final_val)})`;
        } else {
            wIni.style.display = 'none'; wFin.style.display = 'none';
        }

        const movs = data.movimientos || [];
        if (!movs.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:26px; color:#8a7b70;">Sin movimientos de inventario con los filtros seleccionados.</td></tr>';
            return;
        }

        tbody.innerHTML = movs.map((m) => {
            const bg = m.es_entrada ? '#e8f5e9' : '#ffebee', col = m.es_entrada ? '#1b5e20' : '#b71c1c';
            const saldoHtml = (m.saldo_unidades !== null)
                ? `<div style="font-weight:800; color:#211813;">${m.saldo_unidades} uds.</div><div style="font-size:0.72rem; color:#794a15;">${fmtMoney(m.saldo_valor)}</div>`
                : '<span style="font-size:0.74rem; color:#9e9e9e;">Filtra 1 SKU</span>';

            return `
        <tr>
          <td style="white-space:nowrap;"><div style="font-weight:600; font-size:0.8rem;">${escapeHtml(m.fecha_fmt)}</div><div style="font-size:0.72rem; color:#8a7b70; font-family:monospace;">Mov #${m.movimiento_id}</div></td>
          <td><span class="kf-badge" style="background:${bg}; color:${col};">${escapeHtml(m.tipo_movimiento)}</span><div style="font-size:0.73rem; color:#5a4f46; font-family:monospace; margin-top:2px;">${escapeHtml(m.documento_ref_txt)}</div><div style="font-size:0.71rem; color:#8a7b70; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(m.justificacion)}">${escapeHtml(m.justificacion)}</div></td>
          <td><div style="font-weight:700; color:#211813;">${escapeHtml(m.sku)} — ${escapeHtml(m.producto_nombre)}</div><div style="font-size:0.73rem; color:#794a15; font-family:monospace;">Lote: ${escapeHtml(m.numero_lote)}${m.vencimiento_fmt ? ' • Vence: ' + escapeHtml(m.vencimiento_fmt) : ''}</div></td>
          <td style="text-align:center; font-family:monospace; font-weight:800; color:#1b5e20;">${m.es_entrada ? '+' + m.cantidad_unidades : '—'}</td>
          <td style="text-align:center; font-family:monospace; font-weight:800; color:#c62828;">${!m.es_entrada ? '-' + m.cantidad_unidades : '—'}</td>
          <td style="text-align:right; font-family:monospace;">${fmtMoney(m.costo_unitario_referencia)}</td>
          <td style="text-align:right; font-family:monospace; font-weight:700; color:${col};">${m.es_entrada ? '+' : '-'}${fmtMoney(m.valor_movimiento)}</td>
          <td style="text-align:right; font-family:monospace;">${saldoHtml}</td>
        </tr>`;
        }).join('');
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:24px; color:#c62828;">Error de red al consultar Kardex.</td></tr>';
    }
}

async function cargarMatrizFefo() {
    const tbody = document.getElementById('tbodyMatrizFefo');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:24px; color:#8a7b70;">Evaluando vencimientos FEFO...</td></tr>';

    const params = new URLSearchParams({
        accion: 'matriz_fefo',
        almacen_id: document.getElementById('fefoAlmacen')?.value || '0',
        estado_fefo: document.getElementById('fefoEstado')?.value || '',
        q: (document.getElementById('fefoBuscar')?.value || '').trim()
    });

    try {
        const data = await (await fetch(`procesar_kardex_fefo.php?${params.toString()}`)).json();
        if (!data.ok) { tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:24px; color:#c62828;">${escapeHtml(data.mensaje)}</td></tr>`; return; }

        const r = data.resumen || {};
        document.getElementById('badgeFefoCount').textContent = `${r.total_registros || 0} lote(s)`;
        document.getElementById('fefoResumenTotal').textContent = `Total: ${Number(r.total_unidades || 0).toLocaleString('es-DO')} uds. • ${fmtMoney(r.total_valor_dop)}`;

        const lotes = data.lotes || [];
        if (!lotes.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:26px; color:#8a7b70;">No hay lotes físicos activos con los filtros seleccionados.</td></tr>';
            return;
        }

        const badgeMap = {
            VENCIDO: { bg: '#ffebee', col: '#b71c1c', txt: '🔴 VENCIDO' },
            CRITICO: { bg: '#fff3e0', col: '#e65100', txt: '🟠 CRÍTICO (≤15d)' },
            PROXIMO: { bg: '#fff8e1', col: '#b25e09', txt: '🟡 PRÓXIMO (≤45d)' },
            VIGENTE: { bg: '#e8f5e9', col: '#1b5e20', txt: '🟢 VIGENTE' },
            SIN_LOTE: { bg: '#f5f2ed', col: '#6e645a', txt: '⚪ SIN CADUCIDAD' }
        };

        tbody.innerHTML = lotes.map((l) => {
            const b = badgeMap[l.estado_fefo] || badgeMap.VIGENTE;
            const diasTxt = (l.dias_restantes !== null) ? ` (${l.dias_restantes}d)` : '';
            return `
        <tr>
          <td><strong>${escapeHtml(l.almacen_nombre)}</strong></td>
          <td><div style="font-weight:700; color:#211813;">${escapeHtml(l.producto_nombre)}</div><div style="font-size:0.73rem; color:#794a15; font-family:monospace;">${escapeHtml(l.sku)}</div></td>
          <td style="font-family:monospace; font-weight:700;">${escapeHtml(l.numero_lote)}</td>
          <td style="font-family:monospace; white-space:nowrap;">${escapeHtml(l.vencimiento_fmt)}</td>
          <td style="text-align:center; white-space:nowrap;"><span class="kf-badge" style="background:${b.bg}; color:${b.col};">${b.txt}${diasTxt}</span></td>
          <td style="text-align:center; font-family:monospace;"><strong>${l.stock_fisico_unidades}</strong> / ${l.stock_disponible}</td>
          <td style="text-align:right; font-family:monospace;">${fmtMoney(l.costo_promedio)}</td>
          <td style="text-align:right; padding-right:18px; font-family:monospace; font-weight:800; color:#794a15;">${fmtMoney(l.valor_lote_costo)}</td>
        </tr>`;
        }).join('');
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:24px; color:#c62828;">Error de red al consultar matriz FEFO.</td></tr>';
    }
}

function exportarCsvActivo() {
    const params = new URLSearchParams({ accion: 'exportar_csv', vista: tabActivaKardex });
    if (tabActivaKardex === 'inventario') {
        params.set('almacen_id', document.getElementById('invAlmacen')?.value || '0');
        params.set('categoria_id', document.getElementById('invCategoria')?.value || '0');
        params.set('marca_id', document.getElementById('invMarca')?.value || '0');
        params.set('solo_con_stock', document.getElementById('invSoloStock')?.checked ? '1' : '0');
        params.set('q', (document.getElementById('invBuscar')?.value || '').trim());
    } else if (tabActivaKardex === 'kardex') {
        params.set('producto_id', document.getElementById('karProducto')?.value || '0');
        params.set('tipo_movimiento', document.getElementById('karTipo')?.value || '');
        params.set('fecha_desde', document.getElementById('karDesde')?.value || '');
        params.set('fecha_hasta', document.getElementById('karHasta')?.value || '');
        params.set('q', (document.getElementById('karBuscar')?.value || '').trim());
    } else {
        params.set('almacen_id', document.getElementById('fefoAlmacen')?.value || '0');
        params.set('estado_fefo', document.getElementById('fefoEstado')?.value || '');
        params.set('q', (document.getElementById('fefoBuscar')?.value || '').trim());
    }
    window.location.href = `procesar_kardex_fefo.php?${params.toString()}`;
}