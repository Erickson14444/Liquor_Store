/**
 * Controlador Frontend Dinámico (Solo Consulta) • Lotes y Vencimientos FEFO
 * House & Ice • Sistema Administrativo (ERP Licorero)
 */

const _datosLotesEl = document.getElementById('datosLotes');
const lotesOriginales = _datosLotesEl ? JSON.parse(_datosLotesEl.dataset.lotes || '[]') : [];
let estadoFefoSeleccionado = 'TODOS';

function clasificarEstadoFefo(dias) {
  const d = parseInt(dias, 10);
  if (d < 0) return 'VENCIDO';
  if (d <= 30) return 'POR_VENCER';
  return 'VIGENTE';
}

function actualizarIndicadoresKpi() {
  let totalUnidades = 0;
  let vigentes = 0;
  let porVencer = 0;
  let vencidos = 0;

  lotesOriginales.forEach(lt => {
    const cant = parseInt(lt.cantidad_actual || 0, 10);
    totalUnidades += Math.max(0, cant);
    const est = clasificarEstadoFefo(lt.dias_restantes);
    if (est === 'VENCIDO') vencidos++;
    else if (est === 'POR_VENCER') porVencer++;
    else vigentes++;
  });

  document.getElementById('kpiTotalLotes').textContent = lotesOriginales.length;
  document.getElementById('kpiTotalUnidades').textContent = `${totalUnidades.toLocaleString('es-DO')} unidades en lotes`;
  document.getElementById('kpiVigentes').textContent = vigentes;
  document.getElementById('kpiPorVencer').textContent = porVencer;
  document.getElementById('kpiVencidos').textContent = vencidos;
}

function seleccionarEstadoFefo(estado) {
  estadoFefoSeleccionado = estado;
  const mapaCards = {
    'TODOS': 'kpiCardTodos',
    'VIGENTE': 'kpiCardVigente',
    'POR_VENCER': 'kpiCardPorVencer',
    'VENCIDO': 'kpiCardVencido'
  };
  document.querySelectorAll('.fefo-kpi-card').forEach(c => c.classList.remove('active-kpi'));
  const cardActiva = document.getElementById(mapaCards[estado] || 'kpiCardTodos');
  if (cardActiva) cardActiva.classList.add('active-kpi');
  renderizarConsultaLotes();
}

function limpiarFiltrosLotes() {
  document.getElementById('buscadorLotes').value = '';
  document.getElementById('filtroCategoria').value = '';
  document.getElementById('filtroMarca').value = '';
  document.getElementById('filtroStock').value = 'TODOS';
  document.getElementById('ordenLotes').value = 'FEFO_ASC';
  seleccionarEstadoFefo('TODOS');
}

function renderizarConsultaLotes() {
  const tbody = document.getElementById('tablaLotesBody');
  const q = (document.getElementById('buscadorLotes').value || '').trim().toLowerCase();
  const cat = document.getElementById('filtroCategoria').value;
  const mar = document.getElementById('filtroMarca').value;
  const stk = document.getElementById('filtroStock').value;
  const ord = document.getElementById('ordenLotes').value;

  let filtrados = lotesOriginales.filter(lt => {
    const est = clasificarEstadoFefo(lt.dias_restantes);
    if (estadoFefoSeleccionado !== 'TODOS' && est !== estadoFefoSeleccionado) return false;
    if (cat && String(lt.categoria_nombre) !== cat) return false;
    if (mar && String(lt.marca_nombre) !== mar) return false;

    const cant = parseInt(lt.cantidad_actual || 0, 10);
    if (stk === 'CON_STOCK' && cant <= 0) return false;
    if (stk === 'AGOTADOS' && cant > 0) return false;

    if (q) {
      const texto = `${lt.numero_lote} ${lt.sku} ${lt.producto_nombre} ${lt.marca_nombre} ${lt.categoria_nombre} ${lt.almacenes_ubicacion}`.toLowerCase();
      if (!texto.includes(q)) return false;
    }
    return true;
  });

  filtrados.sort((a, b) => {
    if (ord === 'FEFO_ASC') return parseInt(a.dias_restantes, 10) - parseInt(b.dias_restantes, 10);
    if (ord === 'FEFO_DESC') return parseInt(b.dias_restantes, 10) - parseInt(a.dias_restantes, 10);
    if (ord === 'STOCK_DESC') return parseInt(b.cantidad_actual, 10) - parseInt(a.cantidad_actual, 10);
    if (ord === 'STOCK_ASC') return parseInt(a.cantidad_actual, 10) - parseInt(b.cantidad_actual, 10);
    if (ord === 'SKU_ASC') return String(a.sku).localeCompare(String(b.sku));
    return 0;
  });

  document.getElementById('badgeCount').textContent = `${filtrados.length} lotes`;
  document.getElementById('footerInfo').textContent = `Mostrando ${filtrados.length} de ${lotesOriginales.length} lotes registrados`;

  if (filtrados.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="8" class="text-center" style="padding: 32px; color: #8a7b70;">
          No se encontraron lotes que coincidan con los filtros seleccionados.
        </td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = filtrados.map((lt, index) => {
    const dias = parseInt(lt.dias_restantes, 10);
    const cant = parseInt(lt.cantidad_actual || 0, 10);
    const est = clasificarEstadoFefo(dias);

    let badgeStyle = 'background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9;';
    let labelFefo = `VIGENTE (${dias} días)`;
    let barColor = '#2e7d32';
    let barPct = Math.min(100, Math.max(15, Math.round((dias / 180) * 100)));

    if (est === 'VENCIDO') {
      badgeStyle = 'background: #fdecea; color: #c62828; border: 1px solid #f5c6cb;';
      labelFefo = `VENCIDO (hace ${Math.abs(dias)}d)`;
      barColor = '#c62828';
      barPct = 100;
    } else if (est === 'POR_VENCER') {
      badgeStyle = 'background: #fff4e5; color: #b25e09; border: 1px solid #ffd599;';
      labelFefo = dias === 0 ? 'VENCE HOY' : `POR VENCER (${dias} días)`;
      barColor = '#d97706';
      barPct = Math.max(12, Math.round((dias / 30) * 100));
    }

    return `
      <tr class="fila-lote-click" onclick="verDetalleLote(${Number(lt.id)})">
        <td>
          <div style="display: flex; align-items: center; gap: 6px;">
            <span style="font-size: 0.72rem; font-weight: 700; color: #8a7b70; background: #f3ece2; padding: 2px 6px; border-radius: 4px;">#${index + 1}</span>
            <span class="code-inline" style="font-weight: 700; color: #a66e2e;">${escapeHtml(lt.numero_lote)}</span>
          </div>
        </td>
        <td>
          <div style="font-weight: 700; color: #211813;">${escapeHtml(lt.producto_nombre)}</div>
          <div style="font-size: 0.76rem; font-family: monospace; color: #794a15; font-weight: 700;">${escapeHtml(lt.sku)}</div>
        </td>
        <td>
          <span class="badge" style="background: #eadecd; color: #5a3818;">${escapeHtml(lt.categoria_nombre)}</span>
          <div style="font-size: 0.78rem; color: #666; margin-top: 2px;">${escapeHtml(lt.marca_nombre)}</div>
        </td>
        <td style="font-size: 0.82rem; color: #4a3b32; font-weight: 600;">
          🏢 ${escapeHtml(lt.almacenes_ubicacion)}
        </td>
        <td class="text-center" style="font-family: monospace; font-weight: 700; color: #211813;">
          📅 ${escapeHtml(lt.fecha_vencimiento_fmt)}
        </td>
        <td class="text-center">
          <span class="badge" style="${badgeStyle} font-weight: 700; font-size: 0.73rem; padding: 3px 9px; border-radius: 6px;">
            ${escapeHtml(labelFefo)}
          </span>
          <div class="fefo-progress-wrap" title="Indicador visual de vida útil restante">
            <div class="fefo-progress-bar" style="width: ${barPct}%; background: ${barColor};"></div>
          </div>
        </td>
        <td class="text-right" style="font-family: monospace; font-weight: 800; font-size: 0.96rem; color: ${cant > 0 ? '#211813' : '#c62828'};">
          ${cant.toLocaleString('es-DO')} uds.
        </td>
        <td class="text-right">
          <button type="button" class="btn-sm btn-outline" onclick="event.stopPropagation(); verDetalleLote(${Number(lt.id)})">
            👁️ Consultar
          </button>
        </td>
      </tr>
    `;
  }).join('');
}

function verDetalleLote(id) {
  const lt = lotesOriginales.find(x => Number(x.id) === Number(id));
  if (!lt) return;

  const dias = parseInt(lt.dias_restantes, 10);
  const cant = parseInt(lt.cantidad_actual || 0, 10);
  const costo = parseFloat(lt.costo_promedio || 0);
  const precio = parseFloat(lt.precio_base_unidad || 0);
  const valorCostoTotal = cant * costo;

  let estadoTexto = `🟢 VIGENTE (${dias} días restantes)`;
  if (dias < 0) estadoTexto = `🔴 VENCIDO (hace ${Math.abs(dias)} días)`;
  else if (dias <= 30) estadoTexto = `🟠 ALERTA FEFO: PRÓXIMO A VENCER (${dias} días restantes)`;

  document.getElementById('detTituloLote').textContent = `Ficha del Lote • ${lt.numero_lote}`;
  document.getElementById('detCuerpoLote').innerHTML = `
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; background: #faf6f0; padding: 14px; border-radius: 8px; border: 1px solid #eadecd;">
      <div>
        <small style="color: #8a7b70; font-weight: 700; display: block;">CÓDIGO DE LOTE</small>
        <strong style="font-family: monospace; font-size: 1.05rem; color: #a66e2e;">${escapeHtml(lt.numero_lote)}</strong>
      </div>
      <div>
        <small style="color: #8a7b70; font-weight: 700; display: block;">SKU PRODUCTO</small>
        <strong style="font-family: monospace; font-size: 1rem; color: #211813;">${escapeHtml(lt.sku)}</strong>
      </div>
      <div style="grid-column: span 2;">
        <small style="color: #8a7b70; font-weight: 700; display: block;">DESCRIPCIÓN DEL PRODUCTO</small>
        <strong style="font-size: 0.98rem; color: #211813;">${escapeHtml(lt.producto_nombre)}</strong>
        <div style="font-size: 0.8rem; color: #666; margin-top: 2px;">Categoría: <strong>${escapeHtml(lt.categoria_nombre)}</strong> • Marca: <strong>${escapeHtml(lt.marca_nombre)}</strong></div>
      </div>
      <div>
        <small style="color: #8a7b70; font-weight: 700; display: block;">FECHA DE VENCIMIENTO</small>
        <strong style="font-family: monospace; color: #211813;">📅 ${escapeHtml(lt.fecha_vencimiento_fmt)}</strong>
      </div>
      <div>
        <small style="color: #8a7b70; font-weight: 700; display: block;">ESTADO DE CADUCIDAD (FEFO)</small>
        <strong>${escapeHtml(estadoTexto)}</strong>
      </div>
      <div>
        <small style="color: #8a7b70; font-weight: 700; display: block;">EXISTENCIA EN LOTE</small>
        <strong style="font-family: monospace; font-size: 1.05rem; color: #211813;">${cant.toLocaleString('es-DO')} unidades</strong>
      </div>
      <div>
        <small style="color: #8a7b70; font-weight: 700; display: block;">UBICACIÓN EN ALMACÉN</small>
        <strong>🏢 ${escapeHtml(lt.almacenes_ubicacion)}</strong>
      </div>
      <div>
        <small style="color: #8a7b70; font-weight: 700; display: block;">COSTO UNITARIO / VALOR LOTE</small>
        <strong style="font-family: monospace;">RD$ ${costo.toFixed(2)} (Total: RD$ ${valorCostoTotal.toLocaleString('es-DO', {minimumFractionDigits: 2})})</strong>
      </div>
      <div>
        <small style="color: #8a7b70; font-weight: 700; display: block;">PRECIO BASE DE VENTA</small>
        <strong style="font-family: monospace;">RD$ ${precio.toFixed(2)}</strong>
      </div>
    </div>
  `;
  document.getElementById('modalDetalleLote').classList.add('open');
}

function cerrarDetalleLote() {
  document.getElementById('modalDetalleLote').classList.remove('open');
}

function escapeHtml(str) {
  return String(str || '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m]);
}

document.addEventListener('DOMContentLoaded', () => {
  actualizarIndicadoresKpi();
  renderizarConsultaLotes();
});
