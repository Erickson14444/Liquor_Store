/**
 * ============================================================================
 * Gestión y Auditoría de Cupones & Descuentos (Lógica Frontend JavaScript)
 * Ubicación: c:\xampp\htdocs\Liquor_Store\Gestion de Promociones\cupones.js
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

let filtroEstadoActual = 'TODOS';
let modalEmision = null;
let modalDetalle = null;
let modalTicket = null;

document.addEventListener('DOMContentLoaded', () => {
  modalEmision = document.getElementById('modalEmision');
  modalDetalle = document.getElementById('modalDetalle');
  modalTicket  = document.getElementById('modalTicket');

  // Inicializar renderizado con datos precargados
  actualizarContadoresKPI();
  renderizarCupones();

  // Activar por defecto la pestaña de Cupones
  const tabCup = document.getElementById('tabBtnAuditCupones');
  if (tabCup) {
    cambiarVistaAuditoria('CUPONES', tabCup);
  }

  // Cerrar modales con tecla Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      cerrarModalEmision();
      cerrarModalDetalle();
      cerrarModalTicket();
    }
  });
});

/**
 * Cambia la vista de auditoría entre Cupones de Descuento y Promociones en Curso (2 Pestañas)
 */
function cambiarVistaAuditoria(modo, btn) {
  const tabs = [
    document.getElementById('tabBtnAuditCupones'),
    document.getElementById('tabBtnAuditDescuentos')
  ];
  tabs.forEach(t => {
    if (t) {
      t.style.background = '#fbf9f5';
      t.style.color = 'var(--text-muted)';
      t.style.border = '1px solid var(--border-light)';
      t.style.borderBottom = 'none';
    }
  });

  if (btn) {
    btn.style.background = '#ffffff';
    btn.style.color = 'var(--text-dark)';
    btn.style.border = '1.5px solid #d4c1a7';
    btn.style.borderBottom = 'none';
  }

  const contCupones = document.getElementById('contenedorTablaCupones');
  const contDescuentos = document.getElementById('contenedorTablaDescuentos');
  const titulo = document.getElementById('tituloTablaAuditoria');
  const subtitulo = document.getElementById('subtituloTablaAuditoria');

  if (modo === 'DESCUENTOS') {
    if (contCupones) contCupones.style.display = 'none';
    if (contDescuentos) contDescuentos.style.display = 'block';
    return;
  }

  // Modo Cupones
  if (contDescuentos) contDescuentos.style.display = 'none';
  if (contCupones) contCupones.style.display = 'block';

  if (titulo) titulo.textContent = 'Auditoría Integral de Cupones Emitidos';
  if (subtitulo) subtitulo.textContent = 'Trazabilidad de cupones, emisión, saldo y canje en facturación POS';
}

/**
 * Filtro interactivo mediante las tarjetas KPI superiores
 */
function seleccionarFiltroKpi(estado) {
  filtroEstadoActual = estado;

  // Actualizar clases de tarjetas activas
  document.querySelectorAll('.kpi-card-cupon').forEach(card => card.classList.remove('active-kpi'));
  
  if (estado === 'TODOS') document.getElementById('kpiCardTodos')?.classList.add('active-kpi');
  else if (estado === 'DISPONIBLE') document.getElementById('kpiCardDisponibles')?.classList.add('active-kpi');
  else if (estado === 'POR_VENCER') document.getElementById('kpiCardPorVencer')?.classList.add('active-kpi');
  else if (estado === 'USADO') document.getElementById('kpiCardUsados')?.classList.add('active-kpi');
  else if (estado === 'VENCIDO_ANULADO') document.getElementById('kpiCardVencidos')?.classList.add('active-kpi');

  // Sincronizar select si aplica
  const selectEstado = document.getElementById('filtroEstado');
  if (selectEstado) {
    if (estado === 'VENCIDO_ANULADO') {
      selectEstado.value = '';
    } else {
      selectEstado.value = (estado === 'TODOS') ? '' : estado;
    }
  }

  renderizarCupones();
}

/**
 * Cambio desde el dropdown de estados
 */
function cambioSelectEstado() {
  const val = document.getElementById('filtroEstado').value;
  filtroEstadoActual = val ? val : 'TODOS';

  document.querySelectorAll('.kpi-card-cupon').forEach(card => card.classList.remove('active-kpi'));
  if (!val) document.getElementById('kpiCardTodos')?.classList.add('active-kpi');
  else if (val === 'DISPONIBLE') document.getElementById('kpiCardDisponibles')?.classList.add('active-kpi');
  else if (val === 'POR_VENCER') document.getElementById('kpiCardPorVencer')?.classList.add('active-kpi');
  else if (val === 'USADO') document.getElementById('kpiCardUsados')?.classList.add('active-kpi');

  renderizarCupones();
}

/**
 * Restablecer todos los filtros a su estado inicial
 */
function restablecerFiltros() {
  document.getElementById('buscadorCupones').value = '';
  document.getElementById('filtroEstado').value = '';
  document.getElementById('filtroCampana').value = '';
  document.getElementById('ordenarPor').value = 'vencimiento_asc';
  filtroEstadoActual = 'TODOS';

  document.querySelectorAll('.kpi-card-cupon').forEach(card => card.classList.remove('active-kpi'));
  document.getElementById('kpiCardTodos')?.classList.add('active-kpi');

  renderizarCupones();
}

/**
 * Actualiza los contadores en las tarjetas KPI
 */
function actualizarContadoresKPI() {
  const data = window.CUPONES_DATA || [];
  let cntTotal = data.length;
  let mtoTotal = 0;

  let cntDisp = 0, mtoDisp = 0;
  let cntPorVencer = 0, mtoPorVencer = 0;
  let cntUsados = 0, mtoUsados = 0;
  let cntVencidos = 0, mtoVencidos = 0;

  data.forEach(c => {
    const mto = parseFloat(c.monto_credito || 0);
    const dias = parseInt(c.dias_restantes, 10);
    mtoTotal += mto;

    if (c.estado === 'DISPONIBLE') {
      if (dias >= 0) {
        cntDisp++;
        mtoDisp += mto;
        if (dias <= 7) {
          cntPorVencer++;
          mtoPorVencer += mto;
        }
      } else {
        cntVencidos++;
        mtoVencidos += mto;
      }
    } else if (c.estado === 'USADO') {
      cntUsados++;
      mtoUsados += mto;
    } else if (c.estado === 'VENCIDO' || c.estado === 'ANULADO') {
      cntVencidos++;
      mtoVencidos += mto;
    }
  });

  const setHtml = (id, val) => { const el = document.getElementById(id); if (el) el.innerHTML = val; };

  setHtml('kpiValTotal', cntTotal);
  setHtml('kpiSubTotal', 'RD$ ' + formatMonto(mtoTotal) + ' emitidos');

  setHtml('kpiValDisponibles', cntDisp);
  setHtml('kpiSubDisponibles', 'RD$ ' + formatMonto(mtoDisp) + ' en crédito');

  setHtml('kpiValPorVencer', cntPorVencer);
  setHtml('kpiSubPorVencer', 'RD$ ' + formatMonto(mtoPorVencer) + ' por caducar');

  setHtml('kpiValUsados', cntUsados);
  setHtml('kpiSubUsados', 'RD$ ' + formatMonto(mtoUsados) + ' redimidos');

  setHtml('kpiValVencidos', cntVencidos);
  setHtml('kpiSubVencidos', 'RD$ ' + formatMonto(mtoVencidos) + ' caducados/anulados');
}

/**
 * Renderizado de filas con filtrado en tiempo real
 */
function renderizarCupones() {
  const data = window.CUPONES_DATA || [];
  const query = (document.getElementById('buscadorCupones')?.value || '').toLowerCase().trim();
  const campFiltro = document.getElementById('filtroCampana')?.value || '';
  const orden = document.getElementById('ordenarPor')?.value || 'vencimiento_asc';

  let filtrados = data.filter(c => {
    const dias = parseInt(c.dias_restantes, 10);

    // Filtro por Estado
    if (filtroEstadoActual === 'DISPONIBLE') {
      if (c.estado !== 'DISPONIBLE' || dias < 0) return false;
    } else if (filtroEstadoActual === 'POR_VENCER') {
      if (c.estado !== 'DISPONIBLE' || dias < 0 || dias > 7) return false;
    } else if (filtroEstadoActual === 'USADO') {
      if (c.estado !== 'USADO') return false;
    } else if (filtroEstadoActual === 'VENCIDO') {
      if (c.estado !== 'VENCIDO' && !(c.estado === 'DISPONIBLE' && dias < 0)) return false;
    } else if (filtroEstadoActual === 'ANULADO') {
      if (c.estado !== 'ANULADO') return false;
    } else if (filtroEstadoActual === 'VENCIDO_ANULADO') {
      if (c.estado !== 'VENCIDO' && c.estado !== 'ANULADO' && !(c.estado === 'DISPONIBLE' && dias < 0)) return false;
    }

    // Filtro por Campaña
    if (campFiltro && String(c.promocion_id) !== campFiltro) {
      return false;
    }

    // Filtro por Buscador de Texto
    if (query) {
      const matchCodigo = (c.codigo_cupon || '').toLowerCase().includes(query);
      const matchCliente = (c.cliente_nombre || '').toLowerCase().includes(query) || (c.cliente_documento || '').toLowerCase().includes(query);
      const matchCampana = (c.campana_nombre || '').toLowerCase().includes(query);
      const matchFacOrig = (c.factura_origen_numero || '').toLowerCase().includes(query) || (c.factura_origen_ncf || '').toLowerCase().includes(query);
      const matchFacCanj = (c.factura_canje_numero || '').toLowerCase().includes(query) || (c.factura_canje_ncf || '').toLowerCase().includes(query);
      if (!matchCodigo && !matchCliente && !matchCampana && !matchFacOrig && !matchFacCanj) {
        return false;
      }
    }

    return true;
  });

  // Ordenación
  filtrados.sort((a, b) => {
    if (orden === 'vencimiento_asc') {
      return new Date(a.fecha_vencimiento) - new Date(b.fecha_vencimiento);
    } else if (orden === 'vencimiento_desc') {
      return new Date(b.fecha_vencimiento) - new Date(a.fecha_vencimiento);
    } else if (orden === 'monto_desc') {
      return parseFloat(b.monto_credito || 0) - parseFloat(a.monto_credito || 0);
    } else if (orden === 'monto_asc') {
      return parseFloat(a.monto_credito || 0) - parseFloat(b.monto_credito || 0);
    } else if (orden === 'id_desc') {
      return parseInt(b.id, 10) - parseInt(a.id, 10);
    }
    return 0;
  });

  const tbody = document.getElementById('tablaCuponesBody');
  const badgeContador = document.getElementById('badgeContadorCupones');
  if (badgeContador) badgeContador.innerText = `${filtrados.length} de ${data.length} cupones`;

  if (!tbody) return;

  if (filtrados.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="8" style="text-align: center; padding: 40px 20px; color: #8a7b70;">
          <div style="font-size: 2.2rem; margin-bottom: 8px;">🏷️</div>
          <div style="font-weight: 700; font-size: 1rem; color: #3d332a;">No se encontraron cupones con los filtros aplicados</div>
          <div style="font-size: 0.85rem; margin-top: 4px;">Intenta cambiar el término de búsqueda o restablecer los filtros.</div>
        </td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = filtrados.map(c => {
    const dias = parseInt(c.dias_restantes, 10);
    const mtoFmt = formatMonto(c.monto_credito);

    // Semáforo de días y estado
    let badgeEstadoHtml = '';
    let semaforoDiasHtml = '';

    if (c.estado === 'DISPONIBLE') {
      if (dias >= 0) {
        badgeEstadoHtml = `<span class="badge-status status-disponible">● DISPONIBLE</span>`;
        if (dias === 0) {
          semaforoDiasHtml = `<span style="color: #b25e09; font-weight: 700;">⚠️ Vence Hoy</span>`;
        } else if (dias <= 7) {
          semaforoDiasHtml = `<span style="color: #b25e09; font-weight: 700;">🟠 En ${dias} ${dias === 1 ? 'día' : 'días'}</span>`;
        } else {
          semaforoDiasHtml = `<span style="color: #2e7d32; font-weight: 600;">🟢 En ${dias} días</span>`;
        }
      } else {
        badgeEstadoHtml = `<span class="badge-status status-vencido">● VENCIDO</span>`;
        semaforoDiasHtml = `<span style="color: #c62828; font-weight: 600;">🔴 Venció hace ${Math.abs(dias)}d</span>`;
      }
    } else if (c.estado === 'USADO') {
      badgeEstadoHtml = `<span class="badge-status status-usado">✓ USADO</span>`;
      semaforoDiasHtml = `<span style="color: #1565c0; font-weight: 500;">Canjeado</span>`;
    } else if (c.estado === 'VENCIDO') {
      badgeEstadoHtml = `<span class="badge-status status-vencido">● VENCIDO</span>`;
      semaforoDiasHtml = `<span style="color: #c62828; font-weight: 600;">Expirado</span>`;
    } else if (c.estado === 'ANULADO') {
      badgeEstadoHtml = `<span class="badge-status status-anulado">✕ ANULADO</span>`;
      semaforoDiasHtml = `<span style="color: #757575;">Inactivo</span>`;
    }

    // Cliente beneficiario
    let clienteHtml = '';
    if (c.cliente_nombre) {
      clienteHtml = `
        <div style="font-weight: 600; color: #211813;">${escapeHtml(c.cliente_nombre)}</div>
        <div style="font-size: 0.76rem; color: #8a7b70;">${escapeHtml(c.cliente_documento || 'Sin RNC')}</div>
      `;
    } else {
      clienteHtml = `
        <span style="font-size: 0.82rem; color: #8a7b70; font-style: italic;">👤 Al Portador (General)</span>
      `;
    }

    // Factura de origen
    let origenHtml = '';
    if (c.factura_origen_numero) {
      origenHtml = `
        <div style="font-weight: 600; font-size: 0.82rem; color: #3d332a;">${escapeHtml(c.factura_origen_numero)}</div>
        <div style="font-size: 0.74rem; color: #8a7b70;">${escapeHtml(c.factura_origen_ncf || c.factura_origen_fecha || '')}</div>
      `;
    } else {
      origenHtml = `<span style="font-size: 0.78rem; color: #a66e2e; font-weight: 600;">⭐ Emisión Directa</span>`;
    }

    // Canje / Factura Destino
    let canjeHtml = '';
    if (c.estado === 'USADO' && c.factura_canje_numero) {
      canjeHtml = `
        <div style="font-weight: 600; font-size: 0.82rem; color: #1565c0;">${escapeHtml(c.factura_canje_numero)}</div>
        <div style="font-size: 0.74rem; color: #8a7b70;">${escapeHtml(c.factura_canje_fecha || '')}</div>
      `;
    } else if (c.estado === 'DISPONIBLE') {
      canjeHtml = `<span style="font-size: 0.78rem; color: #2e7d32; font-weight: 500;">⏳ Pendiente</span>`;
    } else {
      canjeHtml = `<span style="font-size: 0.78rem; color: #9e9e9e;">—</span>`;
    }

    return `
      <tr class="fila-cupon" id="filaCupon_${c.id}">
        <!-- CÓDIGO -->
        <td>
          <div style="display: flex; align-items: center; gap: 6px;">
            <span class="cupon-code-pill">${escapeHtml(c.codigo_cupon)}</span>
            <button type="button" class="btn-icon-tiny" title="Copiar código al portapapeles" onclick="copiarCodigoCupon('${escapeHtml(c.codigo_cupon)}', this)">
              📋
            </button>
          </div>
          <div style="font-size: 0.73rem; color: #8a7b70; margin-top: 2px;">${escapeHtml(c.campana_nombre || 'General')}</div>
        </td>

        <!-- CLIENTE -->
        <td>${clienteHtml}</td>

        <!-- MONTO CRÉDITO -->
        <td>
          <div style="font-size: 1.05rem; font-weight: 800; font-family: monospace; color: #211813;">
            RD$ ${mtoFmt}
          </div>
        </td>

        <!-- FACTURA ORIGEN -->
        <td>${origenHtml}</td>

        <!-- VENCIMIENTO -->
        <td>
          <div style="font-weight: 600; font-size: 0.85rem; color: #211813;">${escapeHtml(c.fecha_vencimiento_fmt || c.fecha_vencimiento)}</div>
          <div style="font-size: 0.75rem;">${semaforoDiasHtml}</div>
        </td>

        <!-- ESTADO -->
        <td style="text-align: center;">${badgeEstadoHtml}</td>

        <!-- CANJE DESTINO -->
        <td>${canjeHtml}</td>

        <!-- ACCIONES -->
        <td style="text-align: right; white-space: nowrap;">
          <div style="display: inline-flex; gap: 4px;">
            <button type="button" class="btn-action-cupon btn-view" title="Ver ficha y trazabilidad" onclick="verDetalleCupon(${c.id})">
              🔍 Ver
            </button>
            <button type="button" class="btn-action-cupon btn-print" title="Imprimir ticket para cliente" onclick="imprimirTicketCupon(${c.id})">
              🖨️
            </button>
            ${(c.estado === 'DISPONIBLE') ? `
              <button type="button" class="btn-action-cupon btn-cancel" title="Anular este cupón" onclick="anularCupon(${c.id}, '${escapeHtml(c.codigo_cupon)}')">
                🚫
              </button>
            ` : ''}
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

/**
 * Copiar código de cupón al portapapeles
 */
function copiarCodigoCupon(codigo, btn) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(codigo).then(() => {
      mostrarFeedbackBoton(btn, '✅');
    }).catch(() => {
      fallbackCopiar(codigo, btn);
    });
  } else {
    fallbackCopiar(codigo, btn);
  }
}

function fallbackCopiar(codigo, btn) {
  const ta = document.createElement('textarea');
  ta.value = codigo;
  ta.style.position = 'fixed';
  ta.style.left = '-9999px';
  document.body.appendChild(ta);
  ta.select();
  try {
    document.execCommand('copy');
    mostrarFeedbackBoton(btn, '✅');
  } catch (err) {}
  document.body.removeChild(ta);
}

function mostrarFeedbackBoton(btn, txt) {
  const original = btn.innerText;
  btn.innerText = txt;
  btn.style.borderColor = '#2e7d32';
  btn.style.color = '#2e7d32';
  setTimeout(() => {
    btn.innerText = original;
    btn.style.borderColor = '';
    btn.style.color = '';
  }, 1400);
}

/**
 * Formatear número a moneda RD$
 */
function formatMonto(monto) {
  const num = parseFloat(monto || 0);
  return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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

/**
 * MODAL: EMISIÓN DE CUPÓN
 */
function abrirModalEmision() {
  if (!modalEmision) modalEmision = document.getElementById('modalEmision');
  document.getElementById('formEmisionCupon')?.reset();
  
  // Asignar código sugerido
  generarCodigoAleatorioInput();
  
  // Fecha vencimiento sugerida: 30 días a partir de hoy
  const hoy = new Date();
  hoy.setDate(hoy.getDate() + 30);
  const yyyy = hoy.getFullYear();
  const mm = String(hoy.getMonth() + 1).padStart(2, '0');
  const dd = String(hoy.getDate()).padStart(2, '0');
  const inpFecha = document.getElementById('emision_fecha_vencimiento');
  if (inpFecha) inpFecha.value = `${yyyy}-${mm}-${dd}`;

  modalEmision.style.display = 'flex';
  modalEmision.classList.add('open');
}

function cerrarModalEmision() {
  if (modalEmision) {
    modalEmision.style.display = 'none';
    modalEmision.classList.remove('open');
  }
}

function generarCodigoAleatorioInput() {
  const chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
  let rand = '';
  for (let i = 0; i < 6; i++) {
    rand += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  const inp = document.getElementById('emision_codigo_cupon');
  if (inp) inp.value = 'CUP-' + rand;
}

/**
 * Envío del formulario de emisión mediante AJAX
 */
function guardarEmisionCupon(e) {
  e.preventDefault();
  const form = document.getElementById('formEmisionCupon');
  const formData = new FormData(form);
  formData.append('accion', 'emitir');
  formData.append('format', 'json');

  const btnSubmit = document.getElementById('btnGuardarCupon');
  if (btnSubmit) {
    btnSubmit.disabled = true;
    btnSubmit.innerText = 'Emitiendo...';
  }

  fetch('procesar_cupones.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === 'success') {
      alert(data.message || 'Cupón emitido exitosamente.');
      cerrarModalEmision();
      window.location.reload();
    } else {
      alert('Error: ' + (data.message || 'No se pudo emitir el cupón.'));
      if (btnSubmit) {
        btnSubmit.disabled = false;
        btnSubmit.innerText = 'Guardar y Emitir Cupón';
      }
    }
  })
  .catch(err => {
    alert('Error de conexión al emitir el cupón.');
    if (btnSubmit) {
      btnSubmit.disabled = false;
      btnSubmit.innerText = 'Guardar y Emitir Cupón';
    }
  });
}

/**
 * MODAL: DETALLE Y TRAZABILIDAD COMPLETA
 */
function verDetalleCupon(cuponId) {
  if (!modalDetalle) modalDetalle = document.getElementById('modalDetalle');
  
  // Buscar en array local o consultar por AJAX
  const c = (window.CUPONES_DATA || []).find(item => item.id == cuponId);
  if (!c) {
    alert('No se pudo encontrar el detalle del cupón.');
    return;
  }

  const setEl = (id, val) => { const el = document.getElementById(id); if (el) el.innerHTML = val; };

  setEl('detCodigoCupon', escapeHtml(c.codigo_cupon));
  setEl('detMontoCredito', 'RD$ ' + formatMonto(c.monto_credito));
  setEl('detEstado', c.estado);
  setEl('detCampana', escapeHtml(c.campana_nombre || 'General'));
  setEl('detVencimiento', escapeHtml(c.fecha_vencimiento_fmt || c.fecha_vencimiento));
  
  const dias = parseInt(c.dias_restantes, 10);
  let diasTxt = '';
  if (c.estado === 'DISPONIBLE') {
    diasTxt = (dias >= 0) ? `${dias} días restantes` : `Vencido hace ${Math.abs(dias)} días`;
  } else if (c.estado === 'USADO') {
    diasTxt = `Canjeado exitosamente`;
  } else {
    diasTxt = c.estado;
  }
  setEl('detDiasRestantes', diasTxt);

  // Cliente
  const clienteNom = c.cliente_nombre ? `${escapeHtml(c.cliente_nombre)} (${escapeHtml(c.cliente_documento || 'Sin doc')})` : 'Público General / Al Portador';
  setEl('detCliente', clienteNom);
  setEl('detClienteTel', escapeHtml(c.cliente_telefono || c.cliente_email || 'No registrado'));

  // Factura Origen
  const facOrig = c.factura_origen_numero ? `${escapeHtml(c.factura_origen_numero)} - NCF: ${escapeHtml(c.factura_origen_ncf || 'N/A')} (${escapeHtml(c.factura_origen_fecha || '')})` : 'Emisión Administrativa / Cortesía';
  setEl('detFacturaOrigen', facOrig);

  // Factura Canje
  const facCanj = (c.estado === 'USADO' && c.factura_canje_numero) ? `${escapeHtml(c.factura_canje_numero)} - NCF: ${escapeHtml(c.factura_canje_ncf || 'N/A')} (${escapeHtml(c.factura_canje_fecha || '')})` : 'Aún no canjeado';
  setEl('detFacturaCanje', facCanj);

  // Botón directo para imprimir
  const btnPrint = document.getElementById('btnImprimirDesdeDetalle');
  if (btnPrint) {
    btnPrint.onclick = () => {
      cerrarModalDetalle();
      imprimirTicketCupon(cuponId);
    };
  }

  modalDetalle.style.display = 'flex';
  modalDetalle.classList.add('open');
}

function cerrarModalDetalle() {
  if (modalDetalle) {
    modalDetalle.style.display = 'none';
    modalDetalle.classList.remove('open');
  }
}

/**
 * MODAL / VISTA DE TICKET TÉRMICO PARA IMPRESIÓN
 */
function imprimirTicketCupon(cuponId) {
  if (!modalTicket) modalTicket = document.getElementById('modalTicket');
  const c = (window.CUPONES_DATA || []).find(item => item.id == cuponId);
  if (!c) {
    alert('No se pudo cargar el cupón para impresión.');
    return;
  }

  const setEl = (id, val) => { const el = document.getElementById(id); if (el) el.innerHTML = val; };

  setEl('tktCodigo', escapeHtml(c.codigo_cupon));
  setEl('tktMonto', 'RD$ ' + formatMonto(c.monto_credito));
  setEl('tktVencimiento', escapeHtml(c.fecha_vencimiento_fmt || c.fecha_vencimiento));
  setEl('tktCliente', escapeHtml(c.cliente_nombre || 'AL PORTADOR'));
  setEl('tktCampana', escapeHtml(c.campana_nombre || 'PROMOCIÓN ESPECIAL'));
  setEl('tktBarcode', escapeHtml(c.codigo_cupon));

  modalTicket.style.display = 'flex';
  modalTicket.classList.add('open');
}

function cerrarModalTicket() {
  if (modalTicket) {
    modalTicket.style.display = 'none';
    modalTicket.classList.remove('open');
  }
}

function ejecutarImpresionTicket() {
  window.print();
}

/**
 * ANULAR CUPÓN DISPONIBLE
 */
function anularCupon(cuponId, codigo) {
  if (!confirm(`¿Está seguro de que desea anular el cupón promocional [${codigo}]?\n\nEsta acción cambiará su estado a ANULADO y ya no podrá ser canjeado en facturación.`)) {
    return;
  }

  const formData = new FormData();
  formData.append('accion', 'anular');
  formData.append('cupon_id', cuponId);
  formData.append('format', 'json');

  fetch('procesar_cupones.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === 'success') {
      alert(data.message || 'Cupón anulado.');
      window.location.reload();
    } else {
      alert('Error: ' + (data.message || 'No se pudo anular el cupón.'));
    }
  })
  .catch(err => {
    alert('Error de conexión al anular el cupón.');
  });
}
