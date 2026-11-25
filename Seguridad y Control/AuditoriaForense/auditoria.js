/**
 * ============================================================================
 * Lógica Reactiva: Auditoría Forense
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Seguridad y Control/AuditoriaForense/auditoria.js
 * ============================================================================
 */

let TIMER_BUSQUEDA_AUD = null;

function abrirModal(id) {
    const m = document.getElementById(id);
    if (m) m.classList.add('active');
}

function cerrarModal(id) {
    const m = document.getElementById(id);
    if (m) m.classList.remove('active');
}

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

async function recargarAuditoria() {
    const busqueda = document.getElementById('filtroBusqueda')?.value.trim() || '';
    const tabla = document.getElementById('filtroTabla')?.value || '';
    const accion = document.getElementById('filtroAccion')?.value || '';
    const usuarioId = document.getElementById('filtroUsuario')?.value || '';
    const soloOverrides = document.getElementById('filtroSoloOverrides')?.checked ? '1' : '';

    const tbody = document.getElementById('tbodyAuditoria');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 24px; color: #888;">Cargando eventos de auditoría forense...</td></tr>';

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
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding: 20px; color:#b71c1c;">${json.message || 'Error cargando datos'}</td></tr>`;
            return;
        }

        const registros = json.data;
        const badge = document.getElementById('badgeTotalAud');
        if (badge) badge.textContent = `${json.total} eventos`;

        if (registros.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 36px; color: var(--text-muted);">No se encontraron eventos con los filtros seleccionados.</td></tr>';
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

            // Resumen de datos nuevos
            let resumenTxt = '';
            if (r.datos_nuevos && typeof r.datos_nuevos === 'object') {
                const partes = [];
                for (const [k, v] of Object.entries(r.datos_nuevos)) {
                    if (typeof v !== 'object') {
                        partes.push(`${k}: ${v}`);
                    }
                    if (partes.length >= 3) break;
                }
                resumenTxt = partes.join(' • ');
            }

            const overrideHtml = r.es_override
                ? `<span class="badge-override-pin" title="Autorizado con PIN">🔒 ${r.supervisor_username || 'Supervisor'}</span>`
                : `<span style="color:#bbb; font-size: 0.76rem;">—</span>`;

            html += `
                <tr class="fila-aud" data-id="${r.auditoria_id}">
                    <td style="font-family: var(--font-mono); font-weight: 700; color: #888;">#${r.auditoria_id}</td>
                    <td>
                        <span style="font-size: 0.82rem; font-weight: 600;">${r.fecha_fmt}</span>
                    </td>
                    <td>
                        <span class="badge-tabla">${r.tabla_afectada}</span>
                    </td>
                    <td>
                        <span class="badge-accion ${claseAccion}">${r.accion}</span>
                    </td>
                    <td>
                        <strong>${r.usuario_username}</strong>
                        <small style="display: block; font-size: 0.72rem; color: var(--text-muted);">${r.usuario_nombre}</small>
                    </td>
                    <td>${overrideHtml}</td>
                    <td>
                        <div style="font-size: 0.78rem; color: #555; max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${resumenTxt.replace(/"/g, '&quot;')}">
                            ${resumenTxt || '(Sin detalles adicionales)'}
                        </div>
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline" onclick="inspeccionarForense(${r.auditoria_id})" style="padding: 3px 8px; font-weight: 600;">
                            🔍 Inspeccionar
                        </button>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    } catch (e) {
        console.error(e);
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding: 20px; color:#b71c1c;">Error al consultar el servidor.</td></tr>';
    }
}

// ============================================================================
// INSPECTOR FORENSE DE JSONB Y DIFF
// ============================================================================
async function inspeccionarForense(id) {
    const txtId = document.getElementById('txtModalAudId');
    if (txtId) txtId.textContent = id;

    const body = document.getElementById('bodyInspectorForense');
    if (!body) return;

    body.innerHTML = '<div style="text-align: center; padding: 30px;">Cargando registro forense...</div>';
    abrirModal('modalInspectorForense');

    try {
        const resp = await fetch(`procesar_auditoria.php?accion=obtener_detalle&id=${id}`);
        const json = await resp.json();

        if (json.status !== 'success' || !json.data) {
            body.innerHTML = `<div class="alert alert-danger">${json.message || 'Error cargando datos'}</div>`;
            return;
        }

        const aud = json.data.auditoria;
        const ant = json.data.datos_anteriores;
        const nue = json.data.datos_nuevos;
        const diffs = json.data.diferencias || [];

        // Tarjeta de metadatos
        let htmlHeader = `
            <div style="background: #faf7f2; border: 1px solid #ebd9bf; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; font-size: 0.84rem;">
                    <div><strong>Tabla Afectada:</strong> <span class="badge-tabla">${aud.tabla_afectada}</span></div>
                    <div><strong>Acción:</strong> <span class="badge-accion ${aud.supervisor_id ? 'override' : 'update'}">${aud.accion}</span></div>
                    <div><strong>Fecha/Hora:</strong> ${aud.fecha_fmt}</div>
                    <div><strong>Operador:</strong> ${aud.usuario_username} (${aud.usuario_nombre})</div>
                    <div><strong>Supervisor PIN:</strong> ${aud.supervisor_id ? `<span class="badge-override-pin">🔒 ${aud.supervisor_username}</span>` : 'No requerido'}</div>
                </div>
            </div>
        `;

        // Tabla de diferencias resaltadas si hubo UPDATE
        let htmlDiffs = '';
        if (diffs.length > 0) {
            let filasDiff = '';
            diffs.forEach(d => {
                filasDiff += `
                    <tr>
                        <td style="font-weight: 700; color: #4a3c31;">${d.campo}</td>
                        <td style="color: #b53d32; background: #fff5f5;">${formatearValorDiff(d.antes)}</td>
                        <td style="color: #2e7d52; background: #f2fbf4;">${formatearValorDiff(d.despues)}</td>
                    </tr>
                `;
            });

            htmlDiffs = `
                <div style="margin-bottom: 16px;">
                    <h4 style="font-size: 0.88rem; color: #4a3c31; margin-bottom: 8px;">
                        ⚖️ Campos Modificados (Diff Automático)
                    </h4>
                    <table class="diff-table">
                        <thead>
                            <tr>
                                <th>Campo</th>
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

        // Bloque comparativo de JSONB crudo
        const jsonAntStr = ant ? JSON.stringify(ant, null, 2) : '(Sin datos anteriores - Operación tipo INSERT)';
        const jsonNueStr = nue ? JSON.stringify(nue, null, 2) : '(Sin datos nuevos)';

        let htmlJson = `
            <div class="diff-grid">
                <div class="diff-col">
                    <h5>
                        <span class="diff-tag-antes">◀ Datos Anteriores (JSONB)</span>
                    </h5>
                    <div class="json-viewer-box">${escapeHtml(jsonAntStr)}</div>
                </div>
                <div class="diff-col">
                    <h5>
                        <span class="diff-tag-despues">▶ Datos Nuevos (JSONB)</span>
                    </h5>
                    <div class="json-viewer-box">${escapeHtml(jsonNueStr)}</div>
                </div>
            </div>
        `;

        body.innerHTML = htmlHeader + htmlDiffs + htmlJson;

    } catch (e) {
        console.error(e);
        body.innerHTML = '<div class="alert alert-danger">Error al consultar el detalle forense.</div>';
    }
}

function formatearValorDiff(val) {
    if (val === null || val === undefined) return '<em style="color:#aaa;">null</em>';
    if (typeof val === 'object') return JSON.stringify(val);
    return String(val);
}

function escapeHtml(str) {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
