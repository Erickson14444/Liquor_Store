/**
 * ============================================================================
 * Lógica Reactiva: Auditoría del Sistema (Filtros Completos + Scroll Sincronizado)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Seguridad y Control/AuditoriaForense/auditoria.js
 * ============================================================================
 */

let TIMER_BUSQUEDA_AUD = null;
let AUD_PAGINA_ACTUAL = window.AUD_INIT_STATE?.pagina || 1;
let AUD_TOTAL_PAGINAS = window.AUD_INIT_STATE?.totalPaginas || 1;
let AUD_LIMITE = window.AUD_INIT_STATE?.limite || 50;
let SYNC_SCROLL_LOCK = false;

// ============================================================================
// DICCIONARIOS A LENGUAJE HUMANO
// ============================================================================
const DICCIONARIO_TABLAS = {
    'comprobantes_egreso_cxp': 'Comprobantes CxP',
    'comprobante_egreso_retenciones': 'Retenciones DGII',
    'cuentas_por_pagar': 'Cuentas por Pagar (CxP)',
    'ajustes_cxp': 'Ajustes CxP',
    'movimientos_inventario': 'Movimientos Stock',
    'traslados_internos': 'Traslados Internos',
    'consignaciones': 'Consignaciones',
    'consignaciones_detalle': 'Retorno Consignaciones',
    'cupones_descuento': 'Cupones Descuento',
    'promociones_campanas': 'Promociones y Campañas',
    'facturas': 'Facturación / Ventas',
    'facturas_detalles': 'Detalle Factura',
    'cotizaciones': 'Cotizaciones',
    'devoluciones': 'Devoluciones (B04)',
    'ordenes_compra': 'Órdenes de Compra',
    'recepciones': 'Recepciones Mercancía',
    'recepciones_compra': 'Recepciones Mercancía',
    'sesiones_caja': 'Sesiones de Caja',
    'caja_sesiones': 'Sesiones de Caja',
    'movimiento_caja': 'Movimientos de Caja',
    'caja_movimientos': 'Movimientos de Caja',
    'movimientos_bancarios': 'Movimientos Bancarios',
    'depositos_bancarios': 'Depósitos Bancarios',
    'cuentas_por_cobrar': 'Cuentas por Cobrar (CxC)',
    'recibos_ingreso_cxc': 'Recibos de Ingreso CxC',
    'ajustes_cxc': 'Ajustes CxC',
    'notas_debito': 'Notas de Débito (B03)',
    'usuarios': 'Usuarios',
    'roles': 'Roles y Permisos',
    'productos_maestro': 'Catálogo Productos',
    'articulos': 'Catálogo Artículos',
    'clientes': 'Clientes',
    'proveedores': 'Proveedores',
    'personas': 'Clientes y Proveedores'
};

const DICCIONARIO_ACCIONES = {
    'LIQUIDAR_EGRESO_CXP': 'Liquidación Egreso CxP',
    'REGISTRAR_GASTO_SERVICIO_CXP': 'Gasto / Servicio CxP',
    'REGISTRAR_AJUSTE_CXP': 'Ajuste / Anulación CxP',
    'LIQUIDACION_RETENCIONES_DGII': 'Liquidación Retenciones DGII',
    'REGISTRAR_RECIBO_CXC': 'Recibo de Ingreso CxC',
    'EMITIR_NOTA_DEBITO_B03': 'Nota de Débito (B03)',
    'LIQUIDAR_CONSIGNACION': 'Liquidación Consignación',
    'CREAR_CONSIGNACION': 'Creación Consignación',
    'REGISTRAR_RETORNO_CONSIGNACION': 'Retorno Físico Consignación',
    'POS_EMITIR_FACTURA': 'Emisión de Factura POS',
    'POS_ANULAR_FACTURA': 'Anulación de Factura POS',
    'POS_CREAR_CLIENTE_RAPIDO': 'Alta Rápida de Cliente',
    'INVENTARIO_TRASLADO_EMISION': 'Emisión Traslado Stock',
    'INVENTARIO_AJUSTE_ENTRADA': 'Entrada por Ajuste Stock',
    'INVENTARIO_AJUSTE_SALIDA': 'Salida por Ajuste Stock',
    'APERTURA_CAJA': 'Apertura de Caja',
    'CIERRE_CAJA': 'Cierre / Arqueo de Caja',
    'DEVOLUCION_VENTA': 'Devolución de Venta (B04)',
    'RECEPCION_MERCANCIA': 'Recepción de Mercancía',
    'OVERRIDE_PIN': 'Autorización con PIN'
};

const DICCIONARIO_CAMPOS = {
    'numero_comprobante': 'Nº de Comprobante',
    'numero_recibo': 'Nº de Recibo',
    'numero_factura': 'Nº de Factura',
    'numero_consignacion': 'Nº Consignación',
    'ncf_nota_debito': 'NCF Nota de Débito',
    'ncf_factura_modificada': 'NCF Factura Modificada',
    'total_pagado': 'Total Pagado',
    'monto_total_recibido': 'Total Cobrado',
    'monto_total_liquidado': 'Total Liquidado',
    'total_retenciones': 'Total Retenciones',
    'facturas_afectadas': 'Facturas Afectadas',
    'ncf': 'Comprobante Fiscal (NCF)',
    'estado': 'Estado de la Operación',
    'estado_final': 'Estado Final',
    'numero_traslado': 'Nº de Traslado',
    'almacen_origen': 'Almacén de Origen',
    'almacen_destino': 'Almacén de Destino',
    'tipo_movimiento': 'Tipo de Movimiento',
    'justificacion': 'Justificación / Motivo',
    'concepto': 'Concepto',
    'concepto_cargo': 'Concepto del Cargo',
    'monto': 'Monto',
    'monto_total': 'Monto Total',
    'monto_subtotal': 'Subtotal',
    'monto_impuestos': 'Impuestos',
    'subtotal_consumo': 'Subtotal Consumo',
    'impuestos_consumo': 'Impuestos Consumo',
    'total_facturado': 'Total Facturado',
    'monto_fianza': 'Fianza en Garantía',
    'cliente_nombre': 'Cliente',
    'proveedor': 'Proveedor',
    'banco': 'Banco',
    'referencia_dgii': 'Autorización DGII',
    'periodo_fiscal': 'Período Fiscal',
    'detalles': 'Artículos Incluidos',
    'detalles_retorno': 'Artículos Retornados',
    'facturas_liquidadas': 'Facturas Liquidadas'
};

document.addEventListener('DOMContentLoaded', () => {
    inicializarScrollSincronizadoAud();

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') cerrarModal('modalInspectorForense');
    });
});

// ============================================================================
// SCROLL HORIZONTAL SINCRONIZADO (ARRIBA + ABAJO + SHIFT+WHEEL + BOTONES ◀ ▶)
// ============================================================================
function inicializarScrollSincronizadoAud() {
    const topScroll = document.getElementById('audTopScroll');
    const wrapper = document.getElementById('audTableWrapper');
    if (!topScroll || !wrapper) return;

    topScroll.addEventListener('scroll', () => {
        if (SYNC_SCROLL_LOCK) return;
        SYNC_SCROLL_LOCK = true;
        wrapper.scrollLeft = topScroll.scrollLeft;
        SYNC_SCROLL_LOCK = false;
    });

    wrapper.addEventListener('scroll', () => {
        if (SYNC_SCROLL_LOCK) return;
        SYNC_SCROLL_LOCK = true;
        topScroll.scrollLeft = wrapper.scrollLeft;
        SYNC_SCROLL_LOCK = false;
    });

    // Shift + Rueda del ratón para desplazar horizontalmente sin buscar la barra
    wrapper.addEventListener('wheel', (e) => {
        if (e.shiftKey && Math.abs(e.deltaY) > 0) {
            e.preventDefault();
            wrapper.scrollLeft += e.deltaY;
        }
    }, { passive: false });

    window.addEventListener('resize', sincronizarAnchoScrollTopAud);
    sincronizarAnchoScrollTopAud();
}

function sincronizarAnchoScrollTopAud() {
    const topScroll = document.getElementById('audTopScroll');
    const inner = document.getElementById('audTopScrollInner');
    const wrapper = document.getElementById('audTableWrapper');
    const table = document.getElementById('tablaAuditoria');
    if (!topScroll || !inner || !wrapper || !table) return;

    const anchoTabla = table.scrollWidth;
    const anchoVista = wrapper.clientWidth;

    inner.style.width = anchoTabla + 'px';
    // Mostrar barra superior únicamente si hay desborde horizontal real
    topScroll.style.display = (anchoTabla > anchoVista + 4) ? 'block' : 'none';
}

function desplazarTablaAud(deltaPx) {
    const wrapper = document.getElementById('audTableWrapper');
    if (!wrapper) return;
    wrapper.scrollBy({ left: deltaPx, behavior: 'smooth' });
}

// ============================================================================
// FORMATEADORES HUMAN-FRIENDLY
// ============================================================================
function formatearSnakeCase(str) {
    return String(str).replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
}

function formatearNombreTabla(tablaRaw) {
    if (!tablaRaw) return 'General';
    const limpia = tablaRaw.includes('.') ? tablaRaw.split('.').pop() : tablaRaw;
    return DICCIONARIO_TABLAS[limpia.toLowerCase()] || formatearSnakeCase(limpia);
}

function formatearAccion(accionRaw) {
    if (!accionRaw) return 'Operación';
    return DICCIONARIO_ACCIONES[accionRaw.toUpperCase()] || formatearSnakeCase(accionRaw.toLowerCase());
}

function formatearNombreCampo(campoRaw) {
    if (!campoRaw) return '';
    return DICCIONARIO_CAMPOS[campoRaw.toLowerCase()] || formatearSnakeCase(campoRaw);
}

function esCampoIdTecnico(campo) {
    if (!campo) return false;
    const lower = campo.toLowerCase();
    return lower === 'id' || lower === 'uuid' || lower.endsWith('_id') || lower.endsWith('_uuid');
}

function esMonetario(campo, valor) {
    const c = String(campo).toLowerCase();
    return (
        /(monto|total|precio|costo|pagado|retencion|saldo|itbis|descuento|fianza|reembolso|facturado|recibido|liquidado)/i.test(c)
        && valor !== '' && valor !== null && !isNaN(Number(valor))
    );
}

function generarResumenAmigable(datos) {
    if (!datos || typeof datos !== 'object') return '(Sin datos adicionales)';

    const partes = [];
    for (const [k, v] of Object.entries(datos)) {
        if (esCampoIdTecnico(k)) continue;
        if (Array.isArray(v)) {
            partes.push(`Ítems: ${v.length}`);
            continue;
        }
        if (v === null || v === undefined || typeof v === 'object' || String(v).trim() === '') continue;

        const etiqueta = formatearNombreCampo(k);
        let valStr = String(v);
        if (esMonetario(k, v)) {
            valStr = `RD$ ${Number(v).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }
        partes.push(`${etiqueta}: ${valStr}`);
        if (partes.length >= 4) break;
    }
    return partes.length > 0 ? partes.join(' • ') : '(Detalles registrados)';
}

// ============================================================================
// FILTROS, RANGOS RÁPIDOS DE FECHA Y PAGINACIÓN
// ============================================================================
function formatoFechaIsoLocal(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function marcarPillRangoActivo(rango) {
    document.querySelectorAll('.aud-pill-btn').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-rango') === rango);
    });
}

function aplicarRangoRapidoAud(rango) {
    const inpIni = document.getElementById('filtroFechaInicio');
    const inpFin = document.getElementById('filtroFechaFin');
    const hoy = new Date();

    if (rango === 'hoy') {
        const str = formatoFechaIsoLocal(hoy);
        inpIni.value = str;
        inpFin.value = str;
    } else if (rango === '7dias') {
        const desde = new Date(hoy);
        desde.setDate(hoy.getDate() - 6);
        inpIni.value = formatoFechaIsoLocal(desde);
        inpFin.value = formatoFechaIsoLocal(hoy);
    } else if (rango === 'mes') {
        const inicioMes = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
        inpIni.value = formatoFechaIsoLocal(inicioMes);
        inpFin.value = formatoFechaIsoLocal(hoy);
    } else {
        inpIni.value = '';
        inpFin.value = '';
    }

    marcarPillRangoActivo(rango);
    recargarAuditoria(1);
}

function alCambiarFechaManualAud() {
    marcarPillRangoActivo('');
    const ini = document.getElementById('filtroFechaInicio').value;
    const fin = document.getElementById('filtroFechaFin').value;
    if (ini && fin && ini > fin) {
        document.getElementById('filtroFechaFin').value = ini;
    }
    recargarAuditoria(1);
}

function filtrarSoloOverridesRapido() {
    const chk = document.getElementById('filtroSoloOverrides');
    if (chk) {
        chk.checked = true;
        recargarAuditoria(1);
    }
}

function alCambiarFiltroAuditoria(inmediato = false) {
    clearTimeout(TIMER_BUSQUEDA_AUD);
    if (inmediato) {
        recargarAuditoria(1);
    } else {
        TIMER_BUSQUEDA_AUD = setTimeout(() => recargarAuditoria(1), 240);
    }
}

function alCambiarLimiteAud() {
    AUD_LIMITE = parseInt(document.getElementById('filtroLimite')?.value || '50', 10);
    recargarAuditoria(1);
}

function irPaginaAud(nuevaPagina) {
    const p = Math.min(Math.max(1, nuevaPagina), AUD_TOTAL_PAGINAS);
    if (p === AUD_PAGINA_ACTUAL) return;
    recargarAuditoria(p);
}

function limpiarFiltrosAuditoria() {
    document.getElementById('filtroBusqueda').value = '';
    document.getElementById('filtroFechaInicio').value = '';
    document.getElementById('filtroFechaFin').value = '';
    document.getElementById('filtroOrden').value = 'fecha_desc';
    document.getElementById('filtroTabla').value = '';
    document.getElementById('filtroCategoria').value = '';
    document.getElementById('filtroAccion').value = '';
    document.getElementById('filtroUsuario').value = '';
    document.getElementById('filtroSupervisor').value = '';
    document.getElementById('filtroSoloOverrides').checked = false;
    document.getElementById('filtroSoloCambios').checked = false;
    marcarPillRangoActivo('todo');
    recargarAuditoria(1);
}

function construirQueryParamsAud(pagina = 1) {
    return new URLSearchParams({
        busqueda: (document.getElementById('filtroBusqueda')?.value || '').trim(),
        fecha_inicio: document.getElementById('filtroFechaInicio')?.value || '',
        fecha_fin: document.getElementById('filtroFechaFin')?.value || '',
        orden: document.getElementById('filtroOrden')?.value || 'fecha_desc',
        tabla: document.getElementById('filtroTabla')?.value || '',
        categoria_evento: document.getElementById('filtroCategoria')?.value || '',
        tipo_accion: document.getElementById('filtroAccion')?.value || '',
        usuario_id: document.getElementById('filtroUsuario')?.value || '',
        supervisor_id: document.getElementById('filtroSupervisor')?.value || '',
        solo_overrides: document.getElementById('filtroSoloOverrides')?.checked ? '1' : '',
        solo_cambios: document.getElementById('filtroSoloCambios')?.checked ? '1' : '',
        limite: String(AUD_LIMITE),
        pagina: String(pagina)
    });
}

function exportarAuditoriaCsv() {
    const params = construirQueryParamsAud(1);
    params.set('accion', 'exportar_csv');
    window.location.href = `procesar_auditoria.php?${params.toString()}`;
}

async function leerJsonSeguroAud(resp) {
    const txt = await resp.text();
    try {
        return JSON.parse(txt);
    } catch (e) {
        throw new Error('Respuesta inesperada del servidor: ' + txt.replace(/<[^>]+>/g, ' ').trim().slice(0, 200));
    }
}

// ============================================================================
// CONSULTA ASÍNCRONA DE REGISTROS
// ============================================================================
async function recargarAuditoria(pagina = 1) {
    const tbody = document.getElementById('tbodyAuditoria');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 24px; color: #888;">Consultando registros de auditoría...</td></tr>';

    try {
        const params = construirQueryParamsAud(pagina);
        params.set('accion', 'listar_auditoria');

        const resp = await fetch(`procesar_auditoria.php?${params.toString()}`);
        const json = await leerJsonSeguroAud(resp);

        if (json.status !== 'success' || !Array.isArray(json.data)) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding: 18px; color:#b71c1c;">${escapeHtml(json.message || 'Error cargando datos')}</td></tr>`;
            return;
        }

        const registros = json.data;
        AUD_PAGINA_ACTUAL = Number(json.pagina || 1);
        AUD_TOTAL_PAGINAS = Number(json.total_paginas || 1);
        const total = Number(json.total || 0);

        const badge = document.getElementById('badgeTotalAud');
        if (badge) badge.textContent = `${total.toLocaleString('es-DO')} registro(s)`;

        actualizarControlesPaginacionAud(total, registros.length);

        if (registros.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 30px; color: var(--text-muted);">No se encontraron registros con los filtros seleccionados.</td></tr>';
            sincronizarAnchoScrollTopAud();
            return;
        }

        tbody.innerHTML = registros.map(r => {
            const accUp = (r.accion || '').toUpperCase();
            let claseAccion = 'update';
            if (accUp.includes('INSERT') || accUp.includes('CREAR') || accUp.includes('APERTURA') || accUp.includes('REGISTRAR_GASTO')) {
                claseAccion = 'insert';
            } else if (accUp.includes('DELETE') || accUp.includes('ANUL') || accUp.includes('CANCEL') || accUp.includes('INCOBRABLE')) {
                claseAccion = 'delete';
            } else if (accUp.includes('B03') || accUp.includes('FISCAL') || accUp.includes('FACTURA') || accUp.includes('DGII')) {
                claseAccion = 'fiscal';
            } else if (r.es_override) {
                claseAccion = 'override';
            }

            const tablaAmigable = formatearNombreTabla(r.tabla_afectada);
            const accionAmigable = formatearAccion(r.accion);
            const resumenTxt = generarResumenAmigable(r.datos_nuevos);

            const overrideHtml = r.es_override
                ? `<span class="badge-override-pin" title="Autorizado con PIN de Supervisor">🔒 ${escapeHtml(r.supervisor_username || 'Supervisor')}</span>`
                : `<span style="color:#bbb; font-size: 0.74rem;">—</span>`;

            return `
                <tr class="fila-aud" data-id="${r.auditoria_id}" ondblclick="inspeccionarForense(${r.auditoria_id})" title="Doble clic para ver detalle completo">
                    <td class="col-fecha">
                        <span style="font-size: 0.78rem; font-weight: 600; font-family: var(--font-mono);">${escapeHtml(r.fecha_fmt)}</span>
                    </td>
                    <td class="col-modulo">
                        <span class="badge-tabla" title="${escapeHtml(r.tabla_afectada || '')}">${escapeHtml(tablaAmigable)}</span>
                    </td>
                    <td class="col-accion">
                        <span class="badge-accion ${claseAccion}">${escapeHtml(accionAmigable)}</span>
                    </td>
                    <td class="col-usuario">
                        <strong>${escapeHtml(r.usuario_username)}</strong>
                        <small style="display: block; font-size: 0.70rem; color: var(--text-muted);">${escapeHtml(r.usuario_nombre)}</small>
                    </td>
                    <td class="col-supervisor">${overrideHtml}</td>
                    <td class="col-resumen">
                        <div class="aud-resumen-cell" title="${escapeHtml(resumenTxt)}">${escapeHtml(resumenTxt)}</div>
                    </td>
                    <td class="col-detalle col-sticky-right text-center">
                        <button type="button" class="btn btn-sm btn-outline" onclick="inspeccionarForense(${r.auditoria_id})" style="padding: 3px 9px; font-weight: 600; font-size: 0.75rem;">
                            🔍 Ver
                        </button>
                    </td>
                </tr>`;
        }).join('');

        // Subir scroll vertical de la tabla al cambiar de página y resincronizar barra superior
        const wrapper = document.getElementById('audTableWrapper');
        if (wrapper) wrapper.scrollTop = 0;
        sincronizarAnchoScrollTopAud();
    } catch (e) {
        console.error(e);
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding: 20px; color:#b71c1c;">${escapeHtml(e.message)}</td></tr>`;
    }
}

function actualizarControlesPaginacionAud(total, cantEnPagina) {
    const info = document.getElementById('audInfoPaginacion');
    const ind = document.getElementById('audIndicadorPagina');
    const bPrim = document.getElementById('btnPagPrimera');
    const bAnt = document.getElementById('btnPagAnterior');
    const bSig = document.getElementById('btnPagSiguiente');
    const bUlt = document.getElementById('btnPagUltima');

    if (info) {
        if (total === 0) {
            info.textContent = 'Sin registros coincidentes';
        } else {
            const desde = ((AUD_PAGINA_ACTUAL - 1) * AUD_LIMITE) + 1;
            const hasta = desde + cantEnPagina - 1;
            info.textContent = `Mostrando ${desde}–${hasta} de ${total.toLocaleString('es-DO')} registros`;
        }
    }
    if (ind) ind.textContent = `Página ${AUD_PAGINA_ACTUAL} de ${AUD_TOTAL_PAGINAS}`;
    if (bPrim) bPrim.disabled = (AUD_PAGINA_ACTUAL <= 1);
    if (bAnt) bAnt.disabled = (AUD_PAGINA_ACTUAL <= 1);
    if (bSig) bSig.disabled = (AUD_PAGINA_ACTUAL >= AUD_TOTAL_PAGINAS);
    if (bUlt) bUlt.disabled = (AUD_PAGINA_ACTUAL >= AUD_TOTAL_PAGINAS);
}

// ============================================================================
// MODAL INSPECTOR AMIGABLE
// ============================================================================
function abrirModal(id) {
    const m = document.getElementById(id);
    if (m) { m.classList.add('active'); document.body.style.overflow = 'hidden'; }
}

function cerrarModal(id) {
    const m = document.getElementById(id);
    if (m) { m.classList.remove('active'); document.body.style.overflow = ''; }
}

function cerrarModalBackdrop(event, id) {
    if (event.target === document.getElementById(id)) cerrarModal(id);
}

async function inspeccionarForense(id) {
    const body = document.getElementById('bodyInspectorForense');
    if (!body) return;

    body.innerHTML = '<div style="text-align: center; padding: 24px; color: #888;">Cargando información del registro...</div>';
    abrirModal('modalInspectorForense');

    try {
        const resp = await fetch(`procesar_auditoria.php?accion=obtener_detalle&id=${encodeURIComponent(id)}`);
        const json = await leerJsonSeguroAud(resp);

        if (json.status !== 'success' || !json.data) {
            body.innerHTML = `<div class="alert alert-danger">${escapeHtml(json.message || 'Error cargando datos')}</div>`;
            return;
        }

        const aud = json.data.auditoria;
        const ant = json.data.datos_anteriores;
        const nue = json.data.datos_nuevos;
        const diffs = json.data.diferencias || [];

        const tablaAmigable = formatearNombreTabla(aud.tabla_afectada);
        const accionAmigable = formatearAccion(aud.accion);

        const htmlHeader = `
            <div style="background: #faf7f2; border: 1px solid #ebd9bf; border-radius: 6px; padding: 10px 14px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 8px; font-size: 0.80rem;">
                    <div><strong>Módulo:</strong> <span class="badge-tabla">${escapeHtml(tablaAmigable)}</span></div>
                    <div><strong>Operación:</strong> <span class="badge-accion ${aud.supervisor_id ? 'override' : 'update'}">${escapeHtml(accionAmigable)}</span></div>
                    <div><strong>Fecha:</strong> ${escapeHtml(aud.fecha_fmt)}</div>
                    <div><strong>Operador:</strong> ${escapeHtml(aud.usuario_username)} (${escapeHtml(aud.usuario_nombre)})</div>
                    <div><strong>Autorización:</strong> ${aud.supervisor_id ? `<span class="badge-override-pin">🔒 ${escapeHtml(aud.supervisor_username)}</span>` : 'No requerida'}</div>
                </div>
            </div>`;

        let bannerHtml = '';
        if (!ant && nue) {
            bannerHtml = `<div class="aud-status-banner insert"><span>✨ Registro Nuevo Creado</span><span style="font-weight: normal; font-size: 0.76rem;">— Información registrada exitosamente</span></div>`;
        } else if (ant && nue) {
            bannerHtml = `<div class="aud-status-banner update"><span>📝 Modificación de Registro</span><span style="font-weight: normal; font-size: 0.76rem;">— Comparativa Antes vs. Después</span></div>`;
        } else if (ant && !nue) {
            bannerHtml = `<div class="aud-status-banner delete"><span>🗑️ Registro Anulado o Eliminado</span></div>`;
        }

        let htmlDiffs = '';
        const diffsFiltrados = diffs.filter(d => !esCampoIdTecnico(d.campo));
        if (diffsFiltrados.length > 0) {
            const filasDiff = diffsFiltrados.map(d => `
                <tr>
                    <td style="font-weight: 700; color: #4a3c31;">${escapeHtml(formatearNombreCampo(d.campo))}</td>
                    <td style="color: #b53d32; background: #fff5f5;">${formatearValorAmigable(d.campo, d.antes)}</td>
                    <td style="color: #2e7d52; background: #f2fbf4; font-weight: 700;">${formatearValorAmigable(d.campo, d.despues)}</td>
                </tr>`).join('');

            htmlDiffs = `
                <div>
                    <div class="aud-section-title">⚖️ Comparativa de Cambios Realizados</div>
                    <table class="diff-table">
                        <thead><tr><th>Campo</th><th style="color: #b53d32;">Valor Anterior</th><th style="color: #2e7d52;">Valor Nuevo</th></tr></thead>
                        <tbody>${filasDiff}</tbody>
                    </table>
                </div>`;
        }

        const datosParaCards = nue || ant || {};
        let cardsHtml = '';
        let tablasArticulosHtml = '';

        if (typeof datosParaCards === 'object' && Object.keys(datosParaCards).length > 0) {
            let cardsProps = '';
            for (const [k, v] of Object.entries(datosParaCards)) {
                if (esCampoIdTecnico(k)) continue;
                if (Array.isArray(v) && v.length > 0 && typeof v[0] === 'object') {
                    tablasArticulosHtml += renderizarSubtablaArticulos(k, v);
                    continue;
                }
                if (v === null || v === undefined || typeof v === 'object' || String(v).trim() === '') continue;

                const etiqueta = formatearNombreCampo(k);
                let valStr = String(v);
                let claseValor = 'aud-prop-value';
                if (esMonetario(k, v)) {
                    valStr = `RD$ ${Number(v).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                    claseValor += ' money';
                } else if (k.includes('numero') || k.includes('ncf') || k.includes('codigo') || k.includes('referencia')) {
                    claseValor += ' code';
                }

                cardsProps += `
                    <div class="aud-prop-card">
                        <span class="aud-prop-label">${escapeHtml(etiqueta)}</span>
                        <span class="${claseValor}">${escapeHtml(valStr)}</span>
                    </div>`;
            }

            if (cardsProps !== '') {
                cardsHtml = `<div><div class="aud-section-title">📋 Información y Valores Registrados</div><div class="aud-prop-grid">${cardsProps}</div></div>`;
            }
        }

        const htmlAcordeonTecnico = `
            <details class="aud-technical-details">
                <summary>🛠️ Ver registro técnico JSON completo (Antes / Después)</summary>
                <div class="json-viewer-box">${escapeHtml(JSON.stringify({ antes: ant, despues: nue }, null, 2))}</div>
            </details>`;

        body.innerHTML = htmlHeader + bannerHtml + htmlDiffs + cardsHtml + tablasArticulosHtml + htmlAcordeonTecnico;
    } catch (e) {
        console.error(e);
        body.innerHTML = `<div class="alert alert-danger">${escapeHtml(e.message)}</div>`;
    }
}

function renderizarSubtablaArticulos(clave, lista) {
    if (!lista || lista.length === 0) return '';
    const titulo = formatearNombreCampo(clave);
    const filas = lista.map(item => {
        const ref = item.sku || item.producto || item.nombre || item.metodo || (item.cxc_id ? `CxC #${item.cxc_id}` : '(Ítem)');
        const extra = item.numero_lote || item.lote || (item.cantidad_devuelta !== undefined ? `Dev: ${item.cantidad_devuelta}` : '—');
        const cant = item.cantidad_movida ?? item.cantidad_enviada ?? item.cantidad ?? item.cantidad_consumida ?? '';
        const mto = item.costo_unitario_ref ?? item.monto_aplicado ?? item.aplicado ?? item.costo ?? item.precio ?? null;
        const mtoF = (mto !== null && !isNaN(Number(mto)))
            ? `RD$ ${Number(mto).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
            : '—';

        return `
            <tr>
                <td><strong>${escapeHtml(String(ref))}</strong></td>
                <td>${escapeHtml(String(extra))}</td>
                <td>${cant !== '' ? `<strong>${escapeHtml(String(cant))}</strong>` : '—'}</td>
                <td style="color:#206b3c; font-family:monospace; font-weight:700;">${escapeHtml(mtoF)}</td>
            </tr>`;
    }).join('');

    return `
        <div>
            <div class="aud-section-title">📦 ${escapeHtml(titulo)} (${lista.length} ítems)</div>
            <table class="aud-subtable">
                <thead><tr><th>Referencia / SKU</th><th>Lote / Detalle</th><th>Cant.</th><th>Monto / Costo</th></tr></thead>
                <tbody>${filas}</tbody>
            </table>
        </div>`;
}

function formatearValorAmigable(campo, val) {
    if (val === null || val === undefined) return '<em style="color:#aaa;">(Vacío)</em>';
    if (typeof val === 'object') return escapeHtml(JSON.stringify(val));
    if (esMonetario(campo, val)) {
        return `RD$ ${Number(val).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }
    return escapeHtml(String(val));
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}