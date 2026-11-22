/**
 * ============================================================================
 * Módulo 4: Ventas • Proceso 1: Control de Caja (Lógica Frontend JavaScript)
 * Ubicación: c:\xampp\htdocs\Liquor_Store\Ventas y Facturacion\ControlCaja\control_caja.js
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

// Denominaciones de billetes y monedas dominicanas (DOP)
const DENOMINACIONES = [2000, 1000, 500, 200, 100, 50, 25, 10, 5, 1];

document.addEventListener('DOMContentLoaded', () => {
  construirCalculadoraDenominaciones();

  // Escuchar eventos en la calculadora
  const contCalc = document.getElementById('contenedorDenominaciones');
  if (contCalc) {
    contCalc.addEventListener('input', recalcularTotalEfectivoDeclarado);
  }

  // Cerrar modales con Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      cerrarModalApertura();
      cerrarModalArqueo();
      cerrarModalCajaChica();
      cerrarModalDetalle();
    }
  });
});

/**
 * Genera dinámicamente las filas de conteo de billetes y monedas DOP
 */
function construirCalculadoraDenominaciones() {
  const cont = document.getElementById('contenedorDenominaciones');
  if (!cont) return;

  cont.innerHTML = DENOMINACIONES.map(d => {
    const esMoneda = d <= 25;
    const tipoTxt = esMoneda ? '🪙 Moneda' : '💵 Billete';
    return `
      <div class="denom-row" style="display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; border-bottom: 1px solid #f0e6d6;">
        <div style="min-width: 140px; font-weight: 600; font-size: 0.86rem; color: #3d332a;">
          <span style="font-size: 0.74rem; color: #8a7b70; margin-right: 4px;">${tipoTxt}</span>
          RD$ ${d.toLocaleString('en-US')}
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
          <input
            type="number"
            min="0"
            step="1"
            class="form-control denom-input"
            data-valor="${d}"
            placeholder="0"
            style="width: 85px; text-align: center; height: 32px; font-weight: 700;"
            onkeydown="if(['e','E','+','-','.'].includes(event.key)) event.preventDefault();"
          />
          <span class="denom-subtotal" id="subtotalDenom_${d}" style="width: 105px; text-align: right; font-family: monospace; font-size: 0.85rem; font-weight: 700; color: #794a15;">
            RD$ 0.00
          </span>
        </div>
      </div>
    `;
  }).join('');
}

/**
 * Recalcula en vivo el total de efectivo declarado por el cajero
 */
function recalcularTotalEfectivoDeclarado() {
  const inputs = document.querySelectorAll('.denom-input');
  let suma = 0;

  inputs.forEach(inp => {
    const val = parseFloat(inp.getAttribute('data-valor') || 0);
    const cant = parseInt(inp.value || 0, 10);
    const sub = val * (cant > 0 ? cant : 0);
    suma += sub;

    const spanSub = document.getElementById(`subtotalDenom_${val}`);
    if (spanSub) {
      spanSub.innerText = 'RD$ ' + sub.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
  });

  // Mostrar el total contado por el cajero (sin revelar si falta o sobra)
  const lblTotal = document.getElementById('lblTotalEfectivoDeclarado');
  if (lblTotal) {
    lblTotal.innerText = 'RD$ ' + suma.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  const hiddenEfectivo = document.getElementById('arq_efectivo_declarado');
  if (hiddenEfectivo) {
    hiddenEfectivo.value = suma.toFixed(2);
  }
}

/**
 * MODAL 1: APERTURA Y ASIGNACIÓN DE CAJA
 */
function abrirModalApertura(cajaId = null, nombreCaja = '', ubicacion = '') {
  const modal = document.getElementById('modalAperturaTurno');
  if (!modal) return;

  const form = document.getElementById('formAperturaTurno');
  if (form) form.reset();

  const hiddenCaja = document.getElementById('apertura_caja_id');
  if (hiddenCaja && cajaId) {
    hiddenCaja.value = String(cajaId);
  }

  const lblNombre = document.getElementById('lblModalCajaNombre');
  if (lblNombre) {
    lblNombre.textContent = nombreCaja ? `Caja #${cajaId} - ${nombreCaja}` : `Caja #${cajaId || ''}`;
  }

  const lblUbicacion = document.getElementById('lblModalCajaUbicacion');
  if (lblUbicacion) {
    lblUbicacion.textContent = ubicacion || 'Mostrador Principal';
  }

  const errBox = modal.querySelector('.modal-error-box');
  if (errBox) errBox.style.display = 'none';

  modal.style.display = 'flex';
}

function cerrarModalApertura() {
  const modal = document.getElementById('modalAperturaTurno');
  if (modal) {
    modal.style.display = 'none';
    const errBox = modal.querySelector('.modal-error-box');
    if (errBox) errBox.style.display = 'none';
  }
}

function mostrarErrorEnModal(modalId, mensaje) {
  const modal = document.getElementById(modalId);
  if (!modal) return;
  let box = modal.querySelector('.modal-error-box');
  if (!box) {
    box = document.createElement('div');
    box.className = 'modal-error-box';
    box.style.cssText = 'background: #fde8e8; border: 1px solid #f8b4b4; color: #9b1c1c; padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 12px; font-weight: 600; text-align: left;';
    const container = modal.querySelector('form') || modal.querySelector('.modal-body') || modal.querySelector('.modal-content');
    if (container) container.insertBefore(box, container.firstChild);
  }
  box.textContent = '⚠️ ' + mensaje;
  box.style.display = 'block';
}

function guardarAperturaTurno(e) {
  e.preventDefault();
  const form = document.getElementById('formAperturaTurno');
  const fd = new FormData(form);
  fd.append('accion', 'abrir_turno');

  const btn = document.getElementById('btnSubmitApertura');
  if (btn) {
    btn.disabled = true;
    btn.innerText = 'Abriendo...';
  }

  fetch('procesar_caja.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
      if (data.status === 'success' || data.success) {
        cerrarModalApertura();
        window.location.reload();
      } else {
        mostrarErrorEnModal('modalAperturaTurno', data.message || data.mensaje || 'Error al abrir turno.');
        if (btn) {
          btn.disabled = false;
          btn.innerText = 'Abrir Turno';
        }
      }
    })
    .catch(() => {
      mostrarErrorEnModal('modalAperturaTurno', 'Error de conexión con el servidor.');
      if (btn) {
        btn.disabled = false;
        btn.innerText = 'Abrir Turno';
      }
    });
}

/**
 * MODAL 2: INICIAR Y PROCESAR ARQUEO CIEGO
 */
function abrirModalArqueo(sesionId, cajaNombre, cajeroNombre) {
  const modal = document.getElementById('modalArqueoCiego');
  if (!modal) return;

  document.getElementById('formArqueoCiego')?.reset();
  document.getElementById('arq_sesion_id').value = sesionId;

  const lblSesion = document.getElementById('lblArqueoSesion');
  if (lblSesion) lblSesion.innerText = `Sesión #${sesionId} • ${cajaNombre} (${cajeroNombre})`;

  // Limpiar campos de calculadora
  document.querySelectorAll('.denom-input').forEach(i => i.value = '');
  recalcularTotalEfectivoDeclarado();

  modal.style.display = 'flex';
}

function cerrarModalArqueo() {
  const modal = document.getElementById('modalArqueoCiego');
  if (modal) modal.style.display = 'none';
}

function guardarArqueoCiego(e) {
  e.preventDefault();
  const form = document.getElementById('formArqueoCiego');
  const fd = new FormData(form);
  fd.append('accion', 'procesar_arqueo');

  const btn = document.getElementById('btnSubmitArqueo');
  if (btn) {
    btn.disabled = true;
    btn.innerText = 'Procesando Cuadre...';
  }

  fetch('procesar_caja.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
      if (data.status === 'success' || data.success) {
        cerrarModalArqueo();
        window.location.reload();
      } else {
        mostrarErrorEnModal('modalArqueoCiego', data.message || data.mensaje || 'Error al procesar el arqueo.');
        if (btn) {
          btn.disabled = false;
          btn.innerText = 'Confirmar y Liquidar Turno';
        }
      }
    })
    .catch(() => {
      mostrarErrorEnModal('modalArqueoCiego', 'Error de conexión al procesar el arqueo.');
      if (btn) {
        btn.disabled = false;
        btn.innerText = 'Confirmar y Liquidar Turno';
      }
    });
}

/**
 * MODAL 3: REGISTRAR MOVIMIENTO DE CAJA CHICA (CON PIN)
 */
function abrirModalCajaChica(sesionId, cajaNombre) {
  const modal = document.getElementById('modalCajaChica');
  if (!modal) return;

  document.getElementById('formCajaChica')?.reset();
  document.getElementById('mov_sesion_id').value = sesionId;

  const lbl = document.getElementById('lblMovimientoCaja');
  if (lbl) lbl.innerText = `Sesión #${sesionId} (${cajaNombre})`;

  modal.style.display = 'flex';
}

function cerrarModalCajaChica() {
  const modal = document.getElementById('modalCajaChica');
  if (modal) modal.style.display = 'none';
}

function guardarMovimientoCajaChica(e) {
  e.preventDefault();
  const form = document.getElementById('formCajaChica');
  const fd = new FormData(form);
  fd.append('accion', 'movimiento_caja_chica');

  const btn = document.getElementById('btnSubmitMovimiento');
  if (btn) {
    btn.disabled = true;
    btn.innerText = 'Autorizando...';
  }

  fetch('procesar_caja.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
      if (data.status === 'success' || data.success) {
        cerrarModalCajaChica();
        window.location.reload();
      } else {
        mostrarErrorEnModal('modalCajaChica', data.message || data.mensaje || 'Error al registrar movimiento.');
        if (btn) {
          btn.disabled = false;
          btn.innerText = 'Registrar y Autorizar con PIN';
        }
      }
    })
    .catch(() => {
      mostrarErrorEnModal('modalCajaChica', 'Error de conexión.');
      if (btn) {
        btn.disabled = false;
        btn.innerText = 'Registrar y Autorizar con PIN';
      }
    });
}

/**
 * MODAL 4: DESGLOSE COMPLETO DE AUDITORÍA Y MONTO ESPERADO
 */
function verDesgloseArqueo(sesionId) {
  const modal = document.getElementById('modalDesgloseArqueo');
  if (!modal) return;

  const setT = (id, val) => { const el = document.getElementById(id); if (el) el.innerHTML = val; };
  const fmt = (val) => 'RD$ ' + Number(val || 0).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // Estado inicial mientras carga
  setT('desNumSesion', `Consultando auditoría de Sesión #${sesionId}...`);
  setT('desVeredictoTxt', 'Cargando desglose contable...');
  modal.style.display = 'flex';

  fetch(`procesar_caja.php?accion=consultar_desglose&sesion_id=${sesionId}`)
    .then(res => res.json())
    .then(data => {
      if (data.status !== 'success' || !data.data) {
        setT('desVeredictoTxt', `<span style="color: #c62828;">⚠️ ${escapeHtml(data.message || data.mensaje || 'Error al obtener desglose')}</span>`);
        return;
      }

      const d = data.data;
      const s = d.sesion || {};
      const p = d.partidas_efectivo || {};
      const t = d.partidas_tarjeta || {};
      const movs = d.movimientos_caja_chica || [];

      // Encabezados
      setT('desNumSesion', `Sesión #${s.id} • ${escapeHtml(s.nombre_equipo || 'Caja')} (${escapeHtml(s.ubicacion || 'Punto de Venta')})`);
      setT('desCajero', `${escapeHtml(s.cajero_nombre || '—')} <span style="color:#8a7b70; font-size:0.72rem;">(${escapeHtml(s.codigo_empleado || '')})</span>`);
      setT('desTerminal', escapeHtml(s.nombre_equipo || 'Caja'));
      setT('desApertura', escapeHtml(s.fecha_apertura_fmt || '—'));
      setT('desCierre', escapeHtml(s.fecha_cierre_fmt || 'En curso...'));

      // Veredicto
      const dif = parseFloat(p.diferencia || 0);
      const card = document.getElementById('desVeredictoCard');
      if (s.estado === 'ABIERTA') {
        if (card) { card.style.background = '#e8f5e9'; card.style.borderColor = '#a5d6a7'; }
        setT('desVeredictoTxt', `<span style="color: #2e7d32;">🟢 TURNO ABIERTO (EN OPERACIÓN)</span>`);
      } else if (s.estado === 'EN_CUADRE') {
        if (card) { card.style.background = '#fff8e1'; card.style.borderColor = '#ffe082'; }
        setT('desVeredictoTxt', `<span style="color: #b25e09;">🟠 EN ARQUEO CIEGO</span>`);
      } else if (dif === 0) {
        if (card) { card.style.background = '#e8f5e9'; card.style.borderColor = '#a5d6a7'; }
        setT('desVeredictoTxt', `<span style="color: #2e7d32;">🟢 CUADRE EXACTO (RD$ 0.00)</span>`);
      } else if (dif < 0) {
        if (card) { card.style.background = '#ffebee'; card.style.borderColor = '#ef9a9a'; }
        setT('desVeredictoTxt', `<span style="color: #c62828;">🔴 FALTANTE EN CAJA: - ${fmt(Math.abs(dif))}</span>`);
      } else {
        if (card) { card.style.background = '#fff8e1'; card.style.borderColor = '#ffe082'; }
        setT('desVeredictoTxt', `<span style="color: #b25e09;">🟡 SOBRANTE EN CAJA: + ${fmt(dif)}</span>`);
      }

      // Matriz Contable de Efectivo
      setT('desFondoInicial', fmt(p.fondo_inicial));
      setT('desFacturasCount', `(${d.total_facturas || 0} facturas emitidas)`);
      setT('desVentasEf', fmt(p.ventas_efectivo));
      setT('desCxcEf', fmt(p.cxc_efectivo));
      setT('desConsignacionesEf', fmt(p.consignaciones_efectivo));
      setT('desIngresosCajaChica', fmt(p.ingresos_caja_chica));
      setT('desDevolucionesEf', `- ${fmt(p.devoluciones_efectivo)}`);
      setT('desEgresosCajaChica', `- ${fmt(p.egresos_caja_chica)}`);
      setT('desTotalEsperadoEf', fmt(p.total_esperado));
      setT('desTotalDeclaradoEf', fmt(p.total_declarado));

      const difSpan = document.getElementById('desDiferenciaEf');
      if (difSpan) {
        if (dif === 0) {
          difSpan.style.color = '#2e7d32';
          difSpan.innerHTML = 'RD$ 0.00 (EXACTO)';
        } else if (dif < 0) {
          difSpan.style.color = '#c62828';
          difSpan.innerHTML = `- ${fmt(Math.abs(dif))} (FALTANTE)`;
        } else {
          difSpan.style.color = '#b25e09';
          difSpan.innerHTML = `+ ${fmt(dif)} (SOBRANTE)`;
        }
      }

      // Tarjetas y Vouchers
      const arqTarj = (d.arqueo_detalles || []).find(x => x.codigo === 'TARJETA') || {};
      setT('desTarjetaEsperada', fmt(t.total_esperado));
      setT('desTarjetaDeclarada', fmt(arqTarj.monto_declarado_cajero || 0));
      setT('desCantVouchers', `${arqTarj.cantidad_vouchers || 0} comprobantes físicos`);

      // Tabla de Movimientos de Caja Chica
      const tbody = document.getElementById('desTbodyCajaChica');
      if (tbody) {
        if (movs.length === 0) {
          tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 12px; color: #8a7b70;">Sin movimientos de caja chica en este turno.</td></tr>';
        } else {
          tbody.innerHTML = movs.map(m => `
            <tr style="border-bottom: 1px solid #f0f0f0;">
              <td style="padding: 6px 10px; font-family: monospace;">${escapeHtml(m.hora_fmt || '—')}</td>
              <td style="padding: 6px 10px;">
                <span style="font-weight: 700; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; ${m.tipo_movimiento === 'INGRESO' ? 'background:#e8f5e9; color:#2e7d32;' : 'background:#ffebee; color:#c62828;'}">
                  ${escapeHtml(m.tipo_movimiento)}
                </span>
              </td>
              <td style="padding: 6px 10px;">${escapeHtml(m.concepto)}</td>
              <td style="padding: 6px 10px; color: #794a15; font-weight: 600;">${escapeHtml(m.supervisor_nombre)}</td>
              <td style="padding: 6px 10px; text-align: right; font-family: monospace; font-weight: 700; color: ${m.tipo_movimiento === 'INGRESO' ? '#2e7d32' : '#c62828'};">
                ${m.tipo_movimiento === 'INGRESO' ? '+' : '-'} ${fmt(m.monto)}
              </td>
            </tr>
          `).join('');
        }
      }
    })
    .catch(err => {
      console.error(err);
      const v = document.getElementById('desVeredictoTitulo');
      if (v) v.innerHTML = `<span style="color: #c62828;">Error de conexión: ${escapeHtml(err.message)}</span>`;
    });
}

function cerrarModalDesglose() {
  const modal = document.getElementById('modalDesgloseArqueo');
  if (modal) modal.style.display = 'none';
}

function verDetalleArqueo(sesionId) {
  verDesgloseArqueo(sesionId);
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
