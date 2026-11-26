/**
 * ============================================================================
 * Lógica Reactiva: Auditoría del Sistema (Human-Friendly & Práctica)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Seguridad y Control/AuditoriaForense/auditoria.js
 * ============================================================================
 */

let TIMER_BUSQUEDA_AUD = null;

// ============================================================================
// DICCIONARIOS Y TRADUCTORES A LENGUAJE HUMANO (SIN CÓDIGO NI IDs)
// ============================================================================
const DICCIONARIO_TABLAS = {
    'comprobantes_egreso_cxp': 'Comprobantes CxP',
    'movimientos_inventario': 'Movimientos Stock',
    'traslados_internos': 'Traslados Internos',
    'consignaciones': 'Consignaciones',
    'cupones_descuento': 'Cupones Descuento',
    'promociones_campanas': 'Promociones y Campañas',
    'facturas': 'Facturación / Ventas',
    'facturas_detalles': 'Detalle Factura',
    'cotizaciones': 'Cotizaciones',
    'devoluciones': 'Devoluciones',
    'ordenes_compra': 'Órdenes de Compra',
    'recepciones': 'Recepciones Mercancía',
    'caja_sesiones': 'Sesiones de Caja',
    'caja_movimientos': 'Movimientos de Caja',
    'cuentas_por_cobrar': 'Cuentas por Cobrar (CxC)',
    'notas_debito': 'Notas de Débito',
    'usuarios': 'Usuarios',
    'roles': 'Roles y Permisos',
    'articulos': 'Catálogo Artículos',
    'personas': 'Clientes y Proveedores'
};

const DICCIONARIO_ACCIONES = {
    'LIQUIDAR_EGRESO_CXP': 'Liquidación Egreso CxP',
    'LIQUIDAR_CONSIGNACION': 'Liquidación Consignación',
    'CREAR_CONSIGNACION': 'Creación Consignación',
    'INVENTARIO_TRASLADO_EMISION': 'Emisión Traslado Stock',
    'INVENTARIO_AJUSTE_ENTRADA': 'Entrada por Ajuste Stock',
    'INVENTARIO_AJUSTE_SALIDA': 'Salida por Ajuste Stock',
    'FACTURACION_B01': 'Factura Crédito Fiscal (B01)',
    'FACTURACION_B02': 'Factura Consumo (B02)',
    'FACTURACION_B03': 'Nota de Débito Fiscal (B03)',
    'FACTURACION_B04': 'Nota de Crédito / Devolución',
    'APERTURA_CAJA': 'Apertura de Caja',
    'CIERRE_CAJA': 'Cierre de Caja',
    'DEVOLUCION_VENTA': 'Devolución de Venta',
    'RECEPCION_MERCANCIA': 'Recepción de Mercancía',
    'ANULACION_VENTA': 'Anulación de Venta',
    'OVERRIDE_PIN': 'Autorización con PIN'
};

const DICCIONARIO_CAMPOS = {
    'numero_comprobante': 'Nº de Comprobante',
    'total_pagado': 'Total Pagado',
    'total_retenciones': 'Total Retenciones',
    'facturas_afectadas': 'Facturas Afectadas',
    'ncf': 'Comprobante Fiscal (NCF)',
    'estado': 'Estado de la Operación',
    'numero': 'Número / Folio',
    'numero_traslado': 'Nº de Traslado',
    'almacen_origen': 'Almacén de Origen',
    'almacen_destino': 'Almacén de Destino',
    'almacen_nombre': 'Almacén',
    'tipo_movimiento': 'Tipo de Movimiento',
    'justificacion': 'Justificación / Motivo',
    'monto': 'Monto',
    'monto_total': 'Monto Total',
    'total': 'Total',
    'subtotal': 'Subtotal',
    'descuento': 'Descuento',
    'itbis': 'ITBIS / Impuesto',
    'cliente': 'Cliente',
    'proveedor': 'Proveedor',
    'usuario': 'Usuario',
    'cantidad': 'Cantidad',
    'cantidad_enviada': 'Cantidad Enviada',
    'cantidad_movida': 'Cantidad Movida',
    'costo_unitario_ref': 'Costo Unitario',
    'costo': 'Costo',
    'precio': 'Precio',
    'stock_fisico_despues': 'Stock Final',
    'stock_fisico_antes': 'Stock Inicial',
    'lote_cantidad_despues': 'Cantidad Lote Final',
    'lote_cantidad_antes': 'Cantidad Lote Inicial',
    'numero_lote': 'Número de Lote',
    'saldo': 'Saldo Pendiente',
    'codigo_cupon': 'Código de Cupón',
    'porcentaje_descuento': '% Descuento',
    'monto_descuento': 'Monto Descuento',
    'fecha_vencimiento': 'Fecha de Vencimiento',
    'detalles': 'Artículos Incluidos',
    'detalles_nuevos': 'Artículos Modificados',
    'saldos_previos': 'Saldos Anteriores',
    'saldos_origen': 'Saldos Origen'
};

function formatearNombreTabla(tablaRaw) {
    if (!tablaRaw) return 'General';
    const limpia = tablaRaw.includes('.') ? tablaRaw.split('.').pop() : tablaRaw;
    const lower = limpia.toLowerCase();
    return DICCIONARIO_TABLAS[lower] || formatearSnakeCase(limpia);
}

function formatearAccion(accionRaw) {
    if (!accionRaw) return 'Operación';
    const up = accionRaw.toUpperCase();
    return DICCIONARIO_ACCIONES[up] || formatearSnakeCase(accionRaw);
}

function formatearNombreCampo(campoRaw) {
    if (!campoRaw) return '';
    const lower = campoRaw.toLowerCase();
    return DICCIONARIO_CAMPOS[lower] || formatearSnakeCase(campoRaw);
}

function formatearSnakeCase(str) {
    return String(str)
        .replace(/_/g, ' ')
        .replace(/\b\w/g, l => l.toUpperCase());
}

function esCampoIdTecnico(campo) {
    if (!campo) return false;
    const lower = campo.toLowerCase();
    return (
        lower === 'id' ||
        lower === 'uuid' ||
        lower.endsWith('_id') ||
        lower.endsWith('_uuid')
    );
}

function esMonetario(campo, valor) {
    const c = String(campo).toLowerCase();
    return (
        (c.includes('monto') ||
         c.includes('total') ||
         c.includes('precio') ||
         c.includes('costo') ||
         c.includes('pagado') ||
         c.includes('retencion') ||
         c.includes('saldo') ||
         c.includes('itbis') ||
         c.includes('descuento')) &&
        !isNaN(Number(valor))
    );
}

function generarResumenAmigable(datos) {
    if (!datos || typeof datos !== 'object') {
        return '(Sin datos adicionales)';
    }

    const partes = [];
    for (const [k, v] of Object.entries(datos)) {
        if (esCampoIdTecnico(k)) continue;

        if (Array.isArray(v)) {
            partes.push(`Artículos: ${v.length}`);
            continue;
        }

        if (v === null || v === undefined || typeof v === 'object' || String(v).trim() === '') {
            continue;
        }

        const etiqueta = formatearNombreCampo(k);
        let valStr = String(v);

        if (esMonetario(k, v)) {
            valStr = `RD$ ${Number(v).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }

        partes.push(`${etiqueta}: ${valStr}`);
        if (partes.length >= 3) break;
    }

    return partes.length > 0 ? partes.join(' • ') : '(Detalles registrados)';
}

// ============================================================================
// CONTROL DEL MODAL
// ============================================================================
function abrirModal(id) {
    const m = document.getElementById(id);
    if (m) {
        m.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function cerrarModal(id) {
    const m = document.getElementById(id);
    if (m) {
        m.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function cerrarModalBackdrop(event, id) {
    if (event.target === document.getElementById(id)) {
        cerrarModal(id);
    }
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        cerrarModal('modalInspectorForense');
    }
});

function alCambiarFiltroAuditoria() {
    clearTimeout(TIMER_BUSQUEDA_AUD);
    TIMER_BUSQUEDA_AUD = setTimeout(() => {
        recargarAuditoria();
    }, 250);
}

function limpiarFiltrosAuditoria() {
    document.getElementById('filtroBusqueda').value = '';
    document.getElementById('filtroTabla').value = '';
    document.getElementById('filtroAccion').value = '';
    document.getElementById('filtroUsuario').value = '';
    document.getElementById('filtroSoloOverrides').checked = false;
    recargarAuditoria();
}

// ============================================================================
// CONSULTA ASÍNCRONA DE REGISTROS DE AUDITORÍA
// ============================================================================
async function recargarAuditoria() {
    const busqueda = document.getElementById('filtroBusqueda')?.value.trim() || '';
    const tabla = document.getElementById('filtroTabla')?.value || '';
    const accion = document.getElementById('filtroAccion')?.value || '';
    const usuarioId = document.getElementById('filtroUsuario')?.value || '';
    const soloOverrides = document.getElementById('filtroSoloOverrides')?.checked ? '1' : '';

    const tbody = document.getElementById('tbodyAuditoria');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 20px; color: #888;">Cargando registros de auditoría...</td></tr>';

    try {
        const url = `procesar_auditoria.php?accion=listar_auditoria` +
                    `&busqueda=${encodeURIComponent(busqueda)}` +
                    `&tabla=${encodeURIComponent(tabla)}` +
                    `&tipo_accion=${encodeURIComponent(accion)}` +
                    `&usuario_id=${encodeURIComponent(usuarioId)}` +
                    `&solo_overrides=${encodeURIComponent(soloOverrides)}`;

        const resp = await fetch(url);
        const json = await resp.json();

        if (json.status !== 'success' || !json.data) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding: 18px; color:#b71c1c;">${escapeHtml(json.message || 'Error cargando datos')}</td></tr>`;
            return;
        }

        const registros = json.data;
        const badge = document.getElementById('badgeTotalAud');
        if (badge) badge.textContent = `${json.total} registros`;

        if (registros.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 28px; color: var(--text-muted);">No se encontraron registros con los filtros seleccionados.</td></tr>';
            return;
        }

        let html = '';
        registros.forEach(r => {
            const accUp = (r.accion || '').toUpperCase();
            let claseAccion = 'update';
            if (accUp.includes('INSERT') || accUp.includes('CREAR') || accUp.includes('APERTURA')) {
                claseAccion = 'insert';
            } else if (accUp.includes('DELETE') || accUp.includes('ANULAC') || accUp.includes('CANCEL')) {
                claseAccion = 'delete';
            } else if (accUp.includes('B03') || accUp.includes('FISCAL') || accUp.includes('B02') || accUp.includes('B01')) {
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

            html += `
                <tr class="fila-aud" data-id="${r.auditoria_id}">
                    <td>
                        <span style="font-size: 0.78rem; font-weight: 600;">${r.fecha_fmt}</span>
                    </td>
                    <td>
                        <span class="badge-tabla" title="${escapeHtml(r.tabla_afectada || '')}">${escapeHtml(tablaAmigable)}</span>
                    </td>
                    <td>
                        <span class="badge-accion ${claseAccion}">${escapeHtml(accionAmigable)}</span>
                    </td>
                    <td>
                        <strong>${escapeHtml(r.usuario_username)}</strong>
                        <small style="display: block; font-size: 0.70rem; color: var(--text-muted);">${escapeHtml(r.usuario_nombre)}</small>
                    </td>
                    <td>${overrideHtml}</td>
                    <td>
                        <div style="font-size: 0.75rem; color: #444; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtml(resumenTxt)}">
                            ${escapeHtml(resumenTxt)}
                        </div>
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline" onclick="inspeccionarForense(${r.auditoria_id})" style="padding: 2px 7px; font-weight: 600; font-size: 0.75rem;">
                            🔍 Ver
                        </button>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    } catch (e) {
        console.error(e);
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding: 20px; color:#b71c1c;">Error al consultar el servidor.</td></tr>';
    }
}

// ============================================================================
// INSPECTOR AMIGABLE: TRANSFORMA JSON EN TARJETAS Y TABLAS ENTENDIBLES
// ============================================================================
async function inspeccionarForense(id) {
    const body = document.getElementById('bodyInspectorForense');
    if (!body) return;

    body.innerHTML = '<div style="text-align: center; padding: 24px; color: #888;">Cargando información del registro...</div>';
    abrirModal('modalInspectorForense');

    try {
        const resp = await fetch(`procesar_auditoria.php?accion=obtener_detalle&id=${id}`);
        const json = await resp.json();

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

        // 1. Tarjeta de Metadatos
        let htmlHeader = `
            <div style="background: #faf7f2; border: 1px solid #ebd9bf; border-radius: 6px; padding: 10px 14px; margin-bottom: 10px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 8px; font-size: 0.80rem;">
                    <div><strong>Módulo:</strong> <span class="badge-tabla" title="${escapeHtml(aud.tabla_afectada || '')}">${escapeHtml(tablaAmigable)}</span></div>
                    <div><strong>Operación:</strong> <span class="badge-accion ${aud.supervisor_id ? 'override' : 'update'}">${escapeHtml(accionAmigable)}</span></div>
                    <div><strong>Fecha:</strong> ${escapeHtml(aud.fecha_fmt)}</div>
                    <div><strong>Operador:</strong> ${escapeHtml(aud.usuario_username)}</div>
                    <div><strong>Autorización:</strong> ${aud.supervisor_id ? `<span class="badge-override-pin">🔒 ${escapeHtml(aud.supervisor_username)}</span>` : 'No requerida'}</div>
                </div>
            </div>
        `;

        // 2. Banner de Estado
        let bannerHtml = '';
        if (!ant && nue) {
            bannerHtml = `
                <div class="aud-status-banner insert">
                    <span>✨ Registro Nuevo Creado</span>
                    <span style="font-weight: normal; font-size: 0.76rem; color: #2e7d32;">— Información registrada exitosamente en el sistema</span>
                </div>
            `;
        } else if (ant && nue) {
            bannerHtml = `
                <div class="aud-status-banner update">
                    <span>📝 Modificación de Registro</span>
                    <span style="font-weight: normal; font-size: 0.76rem; color: #1e6091;">— Se detectaron campos actualizados</span>
                </div>
            `;
        } else if (ant && !nue) {
            bannerHtml = `
                <div class="aud-status-banner delete">
                    <span>🗑️ Registro Anulado o Cancelado</span>
                </div>
            `;
        }

        // 3. Tabla de Diferencias (si hubo UPDATE)
        let htmlDiffs = '';
        const diffsFiltrados = diffs.filter(d => !esCampoIdTecnico(d.campo));
        if (diffsFiltrados.length > 0) {
            let filasDiff = '';
            diffsFiltrados.forEach(d => {
                const nombreCampo = formatearNombreCampo(d.campo);
                const valorAnt = formatearValorAmigable(d.campo, d.antes);
                const valorNue = formatearValorAmigable(d.campo, d.despues);

                filasDiff += `
                    <tr>
                        <td style="font-weight: 700; color: #4a3c31;">${escapeHtml(nombreCampo)}</td>
                        <td style="color: #b53d32; background: #fff5f5;">${valorAnt}</td>
                        <td style="color: #2e7d52; background: #f2fbf4; font-weight: 700;">${valorNue}</td>
                    </tr>
                `;
            });

            htmlDiffs = `
                <div style="margin-bottom: 12px;">
                    <div class="aud-section-title">⚖️ Comparativa de Cambios Realizados</div>
                    <table class="diff-table">
                        <thead>
                            <tr>
                                <th>Dato / Campo</th>
                                <th style="color: #b53d32;">Valor Anterior</th>
                                <th style="color: #2e7d52;">Valor Nuevo</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${filasDiff}
                        </tbody>
                    </table>
                </div>
            `;
        }

        // 4. Tarjetas con los Datos Registrados (Human-Readable)
        const datosParaCards = nue || ant || {};
        let cardsHtml = '';
        let tablasArticulosHtml = '';

        if (typeof datosParaCards === 'object' && Object.keys(datosParaCards).length > 0) {
            let cardsProps = '';
            for (const [k, v] of Object.entries(datosParaCards)) {
                if (esCampoIdTecnico(k)) continue;

                // Si es un arreglo de artículos/detalles, renderizar como sub-tabla bonita
                if (Array.isArray(v) && v.length > 0 && typeof v[0] === 'object') {
                    tablasArticulosHtml += renderizarSubtablaArticulos(k, v);
                    continue;
                }

                if (v === null || v === undefined || typeof v === 'object' || String(v).trim() === '') {
                    continue;
                }

                const etiqueta = formatearNombreCampo(k);
                const esMonto = esMonetario(k, v);
                const esCodigo = (k.includes('numero') || k.includes('ncf') || k.includes('codigo'));

                let valStr = String(v);
                let claseValor = 'aud-prop-value';
                if (esMonto) {
                    valStr = `RD$ ${Number(v).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                    claseValor += ' money';
                } else if (esCodigo) {
                    claseValor += ' code';
                }

                cardsProps += `
                    <div class="aud-prop-card">
                        <span class="aud-prop-label">${escapeHtml(etiqueta)}</span>
                        <span class="${claseValor}">${escapeHtml(valStr)}</span>
                    </div>
                `;
            }

            if (cardsProps !== '') {
                cardsHtml = `
                    <div style="margin-bottom: 10px;">
                        <div class="aud-section-title">📋 Información y Valores Registrados</div>
                        <div class="aud-prop-grid">
                            ${cardsProps}
                        </div>
                    </div>
                `;
            }
        }

        // 5. Acordeón Opcional para ver el JSON Crudo (oculto por defecto)
        const jsonCrudoStr = JSON.stringify(datosParaCards, null, 2);
        let htmlAcordeonTecnico = `
            <details class="aud-technical-details">
                <summary>🛠️ Ver registro técnico JSON (Opcional)</summary>
                <div class="json-viewer-box">${escapeHtml(jsonCrudoStr)}</div>
            </details>
        `;

        body.innerHTML = htmlHeader + bannerHtml + htmlDiffs + cardsHtml + tablasArticulosHtml + htmlAcordeonTecnico;

    } catch (e) {
        console.error(e);
        body.innerHTML = '<div class="alert alert-danger">Error al consultar el detalle de auditoría.</div>';
    }
}

function renderizarSubtablaArticulos(clave, lista) {
    if (!lista || lista.length === 0) return '';
    const titulo = formatearNombreCampo(clave);

    let cabeceras = ['Producto / SKU', 'Lote', 'Cantidad', 'Costo / Precio'];
    let filas = '';

    lista.forEach(item => {
        const sku = item.sku || item.producto || item.nombre || '(Sin SKU)';
        const lote = item.numero_lote || item.lote || '—';
        const cant = item.cantidad_movida ?? item.cantidad_enviada ?? item.cantidad ?? item.origen_fisico_despues ?? '1';
        const precio = item.costo_unitario_ref ?? item.costo ?? item.precio ?? null;
        const precioFmt = precio !== null
            ? `RD$ ${Number(precio).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
            : '—';

        filas += `
            <tr>
                <td><strong>${escapeHtml(sku)}</strong></td>
                <td>${escapeHtml(lote)}</td>
                <td><span style="font-weight:700;">${escapeHtml(String(cant))}</span> unid.</td>
                <td style="color:#206b3c; font-family:monospace; font-weight:700;">${escapeHtml(precioFmt)}</td>
            </tr>
        `;
    });

    return `
        <div style="margin-top: 10px; margin-bottom: 10px;">
            <div class="aud-section-title">📦 ${escapeHtml(titulo)} (${lista.length} ítems)</div>
            <table class="aud-subtable">
                <thead>
                    <tr>
                        ${cabeceras.map(c => `<th>${escapeHtml(c)}</th>`).join('')}
                    </tr>
                </thead>
                <tbody>
                    ${filas}
                </tbody>
            </table>
        </div>
    `;
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
