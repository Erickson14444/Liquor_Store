/**
 * ============================================================================
 * Controlador Frontend • Tesorería: Movimientos Bancarios y Depósitos de Caja
 * Ubicación: Tesoreria/MovimientosBancarios/movimientos_bancarios.js
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

let arqueosPendientesCache = [];
const sesionesSeleccionadas = new Set();
let timerFiltroTesoreria = null;

const BADGES_TIPO_MOV = {
    DEPOSITO_CAJA: { bg: '#e8f5e9', color: '#1b5e20', label: '🏦 DEPÓSITO CAJA' },
    TRANSFERENCIA_ENTRADA: { bg: '#e3f2fd', color: '#0d47a1', label: '⬇️ TRANSF. ENTRADA' },
    CHEQUE_ENTRADA: { bg: '#e0f2f1', color: '#00695c', label: '⬇️ CHEQUE ENTRADA' },
    TRANSFERENCIA_SALIDA: { bg: '#ffebee', color: '#b71c1c', label: '⬆️ TRANSF. SALIDA' },
    CHEQUE_SALIDA: { bg: '#fff3e0', color: '#e65100', label: '⬆️ CHEQUE SALIDA' }
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
    clearTimeout(window._toastTesTimer);
    window._toastTesTimer = setTimeout(() => t.classList.remove('show'), 4200);
}

function abrirModal(id) {
    const m = document.getElementById(id);
    if (m) m.style.display = 'flex';
}

function cerrarModal(id) {
    const m = document.getElementById(id);
    if (m) m.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    cargarDatosTesoreria();

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            cerrarModal('modalRegistrarDeposito');
            cerrarModal('modalDetalleMovimiento');
        }
    });
});

function programarCargaTesoreria() {
    clearTimeout(timerFiltroTesoreria);
    timerFiltroTesoreria = setTimeout(cargarDatosTesoreria, 240);
}

function limpiarFiltrosBancos() {
    document.getElementById('filCuenta').value = '0';
    document.getElementById('filTipo').value = '';
    document.getElementById('filDesde').value = '';
    document.getElementById('filHasta').value = '';
    document.getElementById('filBuscar').value = '';
    cargarDatosTesoreria();
}

async function cargarDatosTesoreria() {
    const params = new URLSearchParams({
        accion: 'listar',
        cuenta_bancaria_id: document.getElementById('filCuenta')?.value || '0',
        tipo_movimiento: document.getElementById('filTipo')?.value || '',
        fecha_desde: document.getElementById('filDesde')?.value || '',
        fecha_hasta: document.getElementById('filHasta')?.value || '',
        q: (document.getElementById('filBuscar')?.value || '').trim()
    });

    try {
        const res = await fetch(`procesar_movimientos_bancarios.php?${params.toString()}`);
        const data = await res.json();

        if (!data.ok) {
            mostrarToast(data.mensaje || 'Error al cargar tesorería.', true);
            return;
        }

        arqueosPendientesCache = Array.isArray(data.arqueos_pendientes) ? data.arqueos_pendientes : [];
        // Limpiar del Set las sesiones que ya no están pendientes
        const idsVigentes = new Set(arqueosPendientesCache.map((a) => Number(a.sesion_caja_id)));
        Array.from(sesionesSeleccionadas).forEach((id) => {
            if (!idsVigentes.has(id)) sesionesSeleccionadas.delete(id);
        });

        renderKpis(data.kpis || {});
        renderArqueosPendientes();
        renderMovimientosBancarios(Array.isArray(data.movimientos) ? data.movimientos : []);
    } catch (err) {
        mostrarToast('Error de red al consultar movimientos bancarios.', true);
    }
}

function renderKpis(k) {
    document.getElementById('kpiBovedaDop').textContent = fmtMoney(k.boveda_dop);
    document.getElementById('kpiBovedaSub').textContent = `${k.pendientes_count || 0} arqueo(s) por depositar`;

    document.getElementById('kpiDepositosCaja').textContent = fmtMoney(k.depositos_caja_dop);
    document.getElementById('kpiDepositosSub').textContent = `${k.depositos_count || 0} volante(s) registrado(s)`;

    document.getElementById('kpiEntradasDop').textContent = fmtMoney(k.entradas_dop);
    document.getElementById('kpiSalidasDop').textContent = fmtMoney(k.salidas_dop);
    document.getElementById('kpiFlujoNetoSub').textContent = `Flujo neto: ${fmtMoney(k.flujo_neto_dop)}`;
}

// ============================================================================
// BANDEJA DE ARQUEOS PENDIENTES Y SELECCIÓN MÚLTIPLE
// ============================================================================
function renderArqueosPendientes() {
    const tbody = document.getElementById('tbodyArqueosPendientes');
    const badge = document.getElementById('badgePendientesCount');
    if (badge) badge.textContent = `${arqueosPendientesCache.length} pendientes`;

    if (arqueosPendientesCache.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:26px; color:#2e7d32; font-weight:600;">✅ Todo al día: no hay arqueos de efectivo pendientes de depósito en bóveda.</td></tr>';
        actualizarResumenSeleccion();
        return;
    }

    tbody.innerHTML = arqueosPendientesCache.map((a) => {
        const sid = Number(a.sesion_caja_id);
        const checked = sesionesSeleccionadas.has(sid);
        const dif = Number(a.diferencia || 0);
        let difHtml = '<span style="color:#2e7d32; font-weight:700; font-size:0.78rem;">Exacto</span>';
        if (dif < 0) {
            difHtml = `<span style="color:#c62828; font-weight:700; font-size:0.78rem;">-${fmtMoney(Math.abs(dif))}</span>`;
        } else if (dif > 0) {
            difHtml = `<span style="color:#b25e09; font-weight:700; font-size:0.78rem;">+${fmtMoney(dif)}</span>`;
        }

        const chkHtml = window.TESORERIA_PUEDE_REGISTRAR
            ? `<input type="checkbox" class="chk-arqueo-pend" value="${sid}" ${checked ? 'checked' : ''} onchange="toggleArqueo(${sid}, this.checked)" />`
            : '—';

        return `
      <tr style="${checked ? 'background:#fdfaf2;' : ''}">
        <td style="text-align:center;">${chkHtml}</td>
        <td>
          <div style="font-weight:700; color:#211813;">Sesión #${sid}</div>
          <div style="font-size:0.74rem; color:#8a7b70;">${escapeHtml(a.nombre_equipo)}</div>
        </td>
        <td>
          <div style="font-weight:600; color:#211813;">${escapeHtml(a.cajero_nombre)}</div>
          <div style="font-size:0.73rem; color:#8a7b70;">${escapeHtml(a.codigo_empleado)}</div>
        </td>
        <td style="font-size:0.82rem;">${escapeHtml(a.fecha_cierre_fmt)}</td>
        <td style="text-align:right; font-family:monospace;">${fmtMoney(a.monto_declarado_cajero)}</td>
        <td style="text-align:right; font-family:monospace; color:#794a15;">-${fmtMoney(a.monto_retenido_fondo)}</td>
        <td style="text-align:center; font-family:monospace;">${difHtml}</td>
        <td style="text-align:right; padding-right:18px; font-family:monospace; font-weight:800; color:#1b5e20;">
          ${fmtMoney(a.monto_depositar)}
        </td>
      </tr>
    `;
    }).join('');

    actualizarResumenSeleccion();
}

function toggleArqueo(sesionId, marcado) {
    if (marcado) {
        sesionesSeleccionadas.add(Number(sesionId));
    } else {
        sesionesSeleccionadas.delete(Number(sesionId));
    }
    actualizarResumenSeleccion();
}

function toggleTodosArqueos(marcado) {
    sesionesSeleccionadas.clear();
    if (marcado) {
        arqueosPendientesCache.forEach((a) => sesionesSeleccionadas.add(Number(a.sesion_caja_id)));
    }
    renderArqueosPendientes();
}

function actualizarResumenSeleccion() {
    let total = 0;
    arqueosPendientesCache.forEach((a) => {
        if (sesionesSeleccionadas.has(Number(a.sesion_caja_id))) {
            total += Number(a.monto_depositar || 0);
        }
    });
    total = Number(total.toFixed(2));

    const cant = sesionesSeleccionadas.size;
    const lblCant = document.getElementById('lblCantSeleccionados');
    const lblTot = document.getElementById('lblTotalSeleccionado');
    const btn = document.getElementById('btnAbrirModalDeposito');
    const chkAll = document.getElementById('chkSeleccionarTodosArqueos');

    if (lblCant) lblCant.textContent = String(cant);
    if (lblTot) lblTot.textContent = fmtMoney(total);
    if (btn) btn.disabled = (cant === 0 || total <= 0);
    if (chkAll) chkAll.checked = (arqueosPendientesCache.length > 0 && cant === arqueosPendientesCache.length);
}

// ============================================================================
// MODAL REGISTRAR DEPÓSITO BANCARIO
// ============================================================================
function abrirModalDepositoBancario() {
    if (sesionesSeleccionadas.size === 0) {
        mostrarToast('Marca al menos un arqueo pendiente.', true);
        return;
    }

    let total = 0;
    const ids = [];
    arqueosPendientesCache.forEach((a) => {
        const sid = Number(a.sesion_caja_id);
        if (sesionesSeleccionadas.has(sid)) {
            ids.push(sid);
            total += Number(a.monto_depositar || 0);
        }
    });

    document.getElementById('formRegistrarDeposito')?.reset();
    document.getElementById('depMontoTotalModal').textContent = fmtMoney(total);
    document.getElementById('depResumenSesiones').textContent = `Sesiones incluidas: #${ids.join(', #')} (${ids.length} turno(s))`;
    abrirModal('modalRegistrarDeposito');
    setTimeout(() => document.getElementById('dep_referencia')?.focus(), 80);
}

async function confirmarDepositoBancario(e) {
    e.preventDefault();
    const btn = document.getElementById('btnConfirmarDeposito');
    const txtOrig = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Asentando depósito...';

    try {
        const payload = {
            accion: 'registrar_deposito_arqueos',
            sesiones_ids: Array.from(sesionesSeleccionadas),
            cuenta_bancaria_id: parseInt(document.getElementById('dep_cuenta_id').value || '0', 10),
            referencia: document.getElementById('dep_referencia').value.trim().toUpperCase(),
            fecha_movimiento: document.getElementById('dep_fecha').value,
            observacion: document.getElementById('dep_observacion').value.trim()
        };

        const res = await fetch('procesar_movimientos_bancarios.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (!data.ok) {
            mostrarToast(data.mensaje || 'No se pudo registrar el depósito.', true);
            btn.disabled = false;
            btn.textContent = txtOrig;
            return;
        }

        sesionesSeleccionadas.clear();
        cerrarModal('modalRegistrarDeposito');
        mostrarToast(data.mensaje || 'Depósito registrado correctamente.');
        btn.disabled = false;
        btn.textContent = txtOrig;
        cargarDatosTesoreria();
    } catch (err) {
        mostrarToast('Error de red al registrar el depósito.', true);
        btn.disabled = false;
        btn.textContent = txtOrig;
    }
}

// ============================================================================
// LIBRO HISTÓRICO DE MOVIMIENTOS Y DETALLE IMPRIMIBLE
// ============================================================================
function renderMovimientosBancarios(lista) {
    const tbody = document.getElementById('tbodyMovimientosBancarios');
    const badge = document.getElementById('badgeMovsCount');
    if (badge) badge.textContent = `${lista.length} movimiento(s)`;

    if (lista.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:28px; color:#8a7b70;">No se encontraron movimientos bancarios con los filtros seleccionados.</td></tr>';
        return;
    }

    tbody.innerHTML = lista.map((m) => {
        const b = BADGES_TIPO_MOV[m.tipo_movimiento] || { bg: '#f5f2ed', color: '#4a3b32', label: m.tipo_movimiento };
        const sim = m.simbolo || 'RD$';
        const signo = m.es_entrada ? '+' : '-';
        const colorMonto = m.es_entrada ? '#1b5e20' : '#c62828';
        const extraArq = Number(m.sesiones_vinculadas || 0) > 0
            ? `<div style="font-size:0.72rem; color:#794a15; font-weight:700;">${m.sesiones_vinculadas} arqueo(s) consolidado(s)</div>`
            : '';

        return `
      <tr>
        <td style="white-space:nowrap;">
          <div style="font-weight:700; color:#211813;">${escapeHtml(m.fecha_fmt)}</div>
          <div style="font-size:0.73rem; color:#8a7b70; font-family:monospace;">Mov #${Number(m.id)}</div>
        </td>
        <td>
          <div style="font-weight:700; color:#211813;">${escapeHtml(m.nombre_banco)}</div>
          <div style="font-size:0.74rem; color:#8a7b70; font-family:monospace;">${escapeHtml(m.numero_cuenta)} (${escapeHtml(m.codigo_iso)})</div>
        </td>
        <td style="white-space:nowrap;">
          <span class="badge-mov" style="background:${b.bg}; color:${b.color};">${b.label}</span>
          ${extraArq}
        </td>
        <td style="font-family:monospace; font-weight:700; color:#794a15;">${escapeHtml(m.referencia)}</td>
        <td style="font-size:0.82rem; color:#3d332a; max-width:260px;">${escapeHtml(m.concepto)}</td>
        <td style="font-size:0.82rem;">${escapeHtml(m.usuario_nombre)}</td>
        <td style="text-align:right; white-space:nowrap; font-family:monospace; font-weight:800; color:${colorMonto};">
          ${signo} ${fmtMoney(m.monto, sim)}
        </td>
        <td style="text-align:right; padding-right:18px; white-space:nowrap;">
          <button type="button" class="btn btn-beige btn-sm" onclick="verDetalleMovimiento(${Number(m.id)})">🔍 Ver Volante</button>
        </td>
      </tr>
    `;
    }).join('');
}

async function verDetalleMovimiento(movimientoId) {
    const tit = document.getElementById('detMovTitulo');
    const body = document.getElementById('areaImpresionVolante');

    tit.textContent = `Movimiento Bancario #${movimientoId}`;
    body.innerHTML = '<p style="color:#8a7b70;">Consultando comprobante bancario...</p>';
    abrirModal('modalDetalleMovimiento');

    try {
        const res = await fetch(`procesar_movimientos_bancarios.php?accion=detalle_movimiento&movimiento_id=${encodeURIComponent(movimientoId)}`);
        const data = await res.json();

        if (!data.ok || !data.cabecera) {
            body.innerHTML = `<p style="color:#c62828;">${escapeHtml(data.mensaje || 'Error al cargar movimiento.')}</p>`;
            return;
        }

        const c = data.cabecera;
        const arqs = Array.isArray(data.arqueos) ? data.arqueos : [];
        const sim = c.simbolo || 'RD$';
        tit.textContent = `Comprobante Bancario #${c.id} • ${c.referencia}`;

        let tablaArqHtml = '';
        if (arqs.length > 0) {
            const filas = arqs.map((a) => `
        <tr style="border-bottom:1px solid #f0e6d6;">
          <td style="padding:7px 10px;"><strong>Sesión #${a.sesion_caja_id}</strong> (${escapeHtml(a.nombre_equipo)})</td>
          <td style="padding:7px 10px;">${escapeHtml(a.cajero_nombre)}</td>
          <td style="padding:7px 10px; font-size:0.78rem;">${escapeHtml(a.fecha_cierre_fmt)}</td>
          <td style="padding:7px 10px; text-align:right; font-family:monospace;">${fmtMoney(a.monto_declarado_cajero, sim)}</td>
          <td style="padding:7px 10px; text-align:right; font-family:monospace; color:#794a15;">-${fmtMoney(a.monto_retenido_fondo, sim)}</td>
          <td style="padding:7px 10px; text-align:right; font-family:monospace; font-weight:800; color:#1b5e20;">${fmtMoney(a.monto_depositado, sim)}</td>
        </tr>
      `).join('');

            tablaArqHtml = `
        <div style="margin-top:16px;">
          <div style="font-weight:800; font-size:0.86rem; color:#211813; margin-bottom:6px;">
            Arqueos de Caja Consolidados en este Depósito (${arqs.length}):
          </div>
          <table style="width:100%; border-collapse:collapse; border:1px solid #eadecd; font-size:0.82rem;">
            <thead style="background:#fdfaf4; border-bottom:1px solid #eadecd;">
              <tr>
                <th style="padding:7px 10px; text-align:left;">Sesión / Caja</th>
                <th style="padding:7px 10px; text-align:left;">Cajero</th>
                <th style="padding:7px 10px; text-align:left;">Cierre</th>
                <th style="padding:7px 10px; text-align:right;">Declarado</th>
                <th style="padding:7px 10px; text-align:right;">Retenido</th>
                <th style="padding:7px 10px; text-align:right;">Depositado</th>
              </tr>
            </thead>
            <tbody>${filas}</tbody>
          </table>
        </div>
      `;
        }

        body.innerHTML = `
      <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #d4a359; padding-bottom:10px; margin-bottom:14px;">
        <div>
          <h2 style="margin:0; color:#211813;">HOUSE &amp; ICE • COMPROBANTE DE TESORERÍA</h2>
          <div style="font-size:0.82rem; color:#7a6b5d;">Registro Auxiliar de Movimientos Bancarios</div>
        </div>
        <div style="text-align:right;">
          <div style="font-family:monospace; font-size:1.1rem; font-weight:800; color:#794a15;">${escapeHtml(c.referencia)}</div>
          <div style="font-size:0.78rem; color:#8a7b70;">Mov #${c.id} • ${escapeHtml(c.fecha_fmt)}</div>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; background:#faf7f2; border:1px solid #e7dcc8; border-radius:8px; padding:12px; font-size:0.85rem;">
        <div><strong>Banco:</strong> ${escapeHtml(c.nombre_banco)}</div>
        <div><strong>Cuenta:</strong> <span style="font-family:monospace;">${escapeHtml(c.numero_cuenta)} (${escapeHtml(c.tipo_cuenta)})</span></div>
        <div><strong>Tipo:</strong> <strong>${escapeHtml(c.tipo_movimiento)}</strong></div>
        <div><strong>Registrado por:</strong> ${escapeHtml(c.usuario_nombre)}</div>
        <div style="grid-column:1/-1;"><strong>Concepto:</strong> ${escapeHtml(c.concepto)}</div>
      </div>

      ${tablaArqHtml}

      <div style="text-align:right; font-family:monospace; font-size:1.2rem; color:#1b5e20; margin-top:14px;">
        MONTO ASENTADO: <strong>${fmtMoney(c.monto, sim)}</strong>
      </div>
    `;
    } catch (err) {
        body.innerHTML = '<p style="color:#c62828;">Error de red al consultar el comprobante bancario.</p>';
    }
}