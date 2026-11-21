/**
 * ============================================================================
 * Controlador Frontend • Traslados Internos entre Almacenes
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

let carritoTraslado = [];
let productoActualInfo = null;
let lotesActuales = [];
let timerBusquedaCatalogo = null;

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function mostrarToast(msg) {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3200);
}

// ============================================================================
// MODAL 1: EMITIR NUEVO TRASLADO
// ============================================================================
function abrirModalNuevoTraslado() {
    carritoTraslado = [];
    productoActualInfo = null;
    lotesActuales = [];

    const selOrigen = document.getElementById('almacen_origen_id');
    const selDestino = document.getElementById('almacen_destino_id');
    if (selOrigen && selDestino && selOrigen.options.length >= 3) {
        selOrigen.selectedIndex = 1;
        selDestino.selectedIndex = 2;
    }

    limpiarConstructorLinea();
    renderizarCarrito();
    document.getElementById('modalNuevoTraslado').classList.add('open');
}

function cerrarModalNuevoTraslado(forzar = false) {
    if (!forzar && carritoTraslado.length > 0 && !confirm('Tienes líneas en el carrito del traslado. ¿Seguro que deseas cancelar?')) {
        return;
    }
    document.getElementById('modalNuevoTraslado').classList.remove('open');
}

function alCambiarAlmacenOrigen() {
    if (carritoTraslado.length > 0) {
        carritoTraslado = [];
        renderizarCarrito();
        mostrarToast('Se vació el carrito al cambiar el almacén de origen.');
    }
    if (document.getElementById('linea_producto_id').value) {
        cargarDatosProductoEnOrigen();
    }
}

async function buscarPorCodigoBarra() {
    const origenId = document.getElementById('almacen_origen_id').value;
    const codigo = document.getElementById('linea_barcode').value.trim();

    if (!origenId) {
        alert('Primero selecciona el almacén de origen en el Paso 1.');
        document.getElementById('almacen_origen_id').focus();
        return;
    }
    if (!codigo) return;

    try {
        const res = await fetch(`procesar_traslados.php?accion=buscar_barcode&codigo=${encodeURIComponent(codigo)}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (data.status !== 'success' || !data.match) {
            mostrarToast(data.message || 'Código no encontrado.');
            return;
        }

        document.getElementById('linea_producto_id').value = String(data.match.producto_maestro_id);
        await cargarDatosProductoEnOrigen(data.match.codigo_barra || '');
        document.getElementById('linea_barcode').value = '';
        document.getElementById('linea_cantidad').focus();
        document.getElementById('linea_cantidad').select();
    } catch (err) {
        console.error(err);
        alert('Error de red al buscar el código de barras.');
    }
}

async function cargarDatosProductoEnOrigen(codigoBarraPreseleccion = '') {
    const origenId = document.getElementById('almacen_origen_id').value;
    const productoId = document.getElementById('linea_producto_id').value;

    productoActualInfo = null;
    lotesActuales = [];

    const selPres = document.getElementById('linea_presentacion');
    const selLote = document.getElementById('linea_lote_id');
    const lblEstado = document.getElementById('lblEstadoLote');
    const hint = document.getElementById('hintLoteStock');

    selPres.innerHTML = '<option value="" data-factor="1">Unidad Base (x1)</option>';
    selLote.innerHTML = '<option value="">Cargando lotes disponibles...</option>';
    hint.textContent = '';

    if (!productoId) {
        selLote.innerHTML = '<option value="">-- Selecciona un producto --</option>';
        lblEstado.textContent = '📦 Lote Disponible en Almacén Origen (Orden FEFO)';
        actualizarPreviewUnidades();
        return;
    }
    if (!origenId) {
        alert('Selecciona primero el almacén de origen en el Paso 1.');
        document.getElementById('linea_producto_id').value = '';
        selLote.innerHTML = '<option value="">-- Primero selecciona Almacén Origen --</option>';
        return;
    }

    try {
        const url = `procesar_traslados.php?accion=stock_lotes&almacen_id=${encodeURIComponent(origenId)}&producto_id=${encodeURIComponent(productoId)}`;
        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();

        if (data.status !== 'success') {
            alert(data.message || 'No se pudieron consultar los lotes.');
            return;
        }

        productoActualInfo = data.producto;
        lotesActuales = Array.isArray(data.lotes) ? data.lotes : [];

        let optsPres = '<option value="" data-factor="1">Unidad Base (x1)</option>';
        (data.presentaciones || []).forEach(pr => {
            const f = parseInt(pr.factor_multiplicador || 1, 10);
            const selected = (codigoBarraPreseleccion && pr.codigo_barra === codigoBarraPreseleccion) ? 'selected' : '';
            optsPres += `<option value="${escapeHtml(pr.codigo_barra)}" data-factor="${f}" ${selected}>
                ${escapeHtml(pr.descripcion_presentacion)} (x${f} uds.) [${escapeHtml(pr.codigo_barra)}]
            </option>`;
        });
        selPres.innerHTML = optsPres;
        actualizarPreviewUnidades();

        if (!productoActualInfo.requiere_lote) {
            lblEstado.textContent = '📦 Producto sin caducidad • Usa Lote Comodín (#0)';
            const l0 = lotesActuales[0] || { id: 0, stock_fisico: 0, stock_comprometido: 0, stock_disponible: 0 };
            selLote.innerHTML = `<option value="0" data-disponible="${l0.stock_disponible}" data-numero="COMODIN-0" data-venc="">
                Lote Comodín #0 — Disponible en origen: ${l0.stock_disponible} uds. (Físico: ${l0.stock_fisico}, Comp: ${l0.stock_comprometido})
            </option>`;
            hint.textContent = l0.stock_disponible > 0
                ? 'Stock disponible listo para trasladar.'
                : '⚠️ Sin existencias disponibles en el almacén de origen.';
        } else {
            lblEstado.textContent = '⏳ Producto con Caducidad • Lotes con Stock en Origen (FEFO)';
            if (lotesActuales.length === 0) {
                selLote.innerHTML = '<option value="">⚠️ Sin lotes con stock disponible en el almacén origen</option>';
                hint.textContent = 'No hay existencias disponibles de este artículo en el almacén seleccionado.';
            } else {
                selLote.innerHTML = lotesActuales.map((l, idx) => {
                    const tag = idx === 0 ? ' [FEFO - Más próximo]' : '';
                    return `<option value="${l.id}"
                        data-disponible="${l.stock_disponible}"
                        data-numero="${escapeHtml(l.numero_lote)}"
                        data-venc="${escapeHtml(l.fecha_vencimiento || '')}">
                        Lote ${escapeHtml(l.numero_lote)} • Vence: ${escapeHtml(l.fecha_vencimiento || 'N/A')} — Disponible: ${l.stock_disponible} uds.${tag}
                    </option>`;
                }).join('');
                hint.textContent = 'Ordenados bajo regla FEFO para despachar primero el lote más próximo a vencer.';
            }
        }
    } catch (err) {
        console.error(err);
        alert('Error de red al consultar stock en almacén origen.');
    }
}

function actualizarPreviewUnidades() {
    const selPres = document.getElementById('linea_presentacion');
    const opt = selPres.options[selPres.selectedIndex];
    const factor = parseInt(opt?.dataset?.factor || '1', 10) || 1;
    const cant = parseInt(document.getElementById('linea_cantidad').value || '0', 10) || 0;
    const total = cant * factor;
    const el = document.getElementById('previewUnidadesBase');
    if (el) {
        el.textContent = `${total} ${total === 1 ? 'unidad base' : 'unidades base'}` + (factor > 1 ? ` (${cant} × ${factor})` : '');
    }
}

function limpiarConstructorLinea() {
    document.getElementById('linea_barcode').value = '';
    document.getElementById('linea_producto_id').value = '';
    document.getElementById('linea_presentacion').innerHTML = '<option value="" data-factor="1">Unidad Base (x1)</option>';
    document.getElementById('linea_cantidad').value = '1';
    document.getElementById('linea_lote_id').innerHTML = '<option value="">-- Primero selecciona Almacén Origen y Producto --</option>';
    document.getElementById('hintLoteStock').textContent = '';
    document.getElementById('lblEstadoLote').textContent = '📦 Lote Disponible en Almacén Origen (Orden FEFO)';
    productoActualInfo = null;
    lotesActuales = [];
    actualizarPreviewUnidades();
}

function agregarLineaAlCarrito() {
    const origenId = document.getElementById('almacen_origen_id').value;
    if (!origenId) {
        alert('Primero selecciona el almacén de origen en el Paso 1.');
        return;
    }
    if (!productoActualInfo) {
        alert('Selecciona un producto maestro antes de agregar al traslado.');
        return;
    }

    const selPres = document.getElementById('linea_presentacion');
    const optPres = selPres.options[selPres.selectedIndex];
    const codigoBarra = selPres.value || '';
    const factor = parseInt(optPres?.dataset?.factor || '1', 10) || 1;
    const cantPres = parseInt(document.getElementById('linea_cantidad').value || '0', 10);

    if (isNaN(cantPres) || cantPres < 1) {
        alert('La cantidad debe ser un número entero mayor o igual a 1.');
        return;
    }

    const selLote = document.getElementById('linea_lote_id');
    if (selLote.value === '') {
        alert('Debes seleccionar un lote con stock disponible.');
        return;
    }

    const optLote = selLote.options[selLote.selectedIndex];
    const loteId = parseInt(selLote.value, 10);
    const disponible = parseInt(optLote?.dataset?.disponible || '0', 10);
    const loteTexto = optLote?.dataset?.numero || (loteId === 0 ? 'COMODIN-0' : `Lote #${loteId}`);
    const vencTexto = optLote?.dataset?.venc || 'Sin vencimiento';
    const totalUnidades = cantPres * factor;

    const clave = `${productoActualInfo.id}_${loteId}`;
    const existente = carritoTraslado.find(l => l.clave === clave);
    const acumuladas = (existente ? existente.cantidad_unidades : 0) + totalUnidades;

    if (acumuladas > disponible) {
        alert(`Stock disponible insuficiente para ${productoActualInfo.sku} (${loteTexto}). Disponible: ${disponible} uds., intentando trasladar: ${acumuladas} uds.`);
        return;
    }

    if (existente) {
        existente.cantidad_unidades = acumuladas;
        existente.cantidad_presentacion = acumuladas;
        existente.codigo_barra = '';
        existente.factor = 1;
        mostrarToast(`Cantidad consolidada para ${productoActualInfo.sku} (${acumuladas} uds.).`);
    } else {
        carritoTraslado.push({
            clave: clave,
            producto_maestro_id: parseInt(productoActualInfo.id, 10),
            sku: productoActualInfo.sku,
            nombre: productoActualInfo.nombre,
            codigo_barra: codigoBarra,
            factor: factor,
            cantidad_presentacion: cantPres,
            cantidad_unidades: totalUnidades,
            lote_id: loteId,
            lote_texto: loteTexto,
            venc_texto: vencTexto
        });
    }

    limpiarConstructorLinea();
    renderizarCarrito();
}

function eliminarLineaCarrito(idx) {
    carritoTraslado.splice(idx, 1);
    renderizarCarrito();
}

function renderizarCarrito() {
    const tbody = document.getElementById('tablaCarritoBody');
    const badge = document.getElementById('contadorCarrito');
    const totalUds = carritoTraslado.reduce((acc, l) => acc + l.cantidad_unidades, 0);

    badge.textContent = `${carritoTraslado.length} línea(s) • ${totalUds} uds.`;

    if (carritoTraslado.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" style="text-align: center; padding: 20px; color: #888;">
                    El carrito está vacío. Configura un producto en el paso 2 y presiona <strong>+ Agregar Línea al Traslado</strong>.
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = carritoTraslado.map((l, idx) => `
        <tr>
            <td><strong>${idx + 1}</strong></td>
            <td><strong>${escapeHtml(l.sku)}</strong> — ${escapeHtml(l.nombre)}</td>
            <td>
                <div style="font-weight: 600; color: #4a3b32;">${escapeHtml(l.lote_texto)}</div>
                <div style="font-size: 0.76rem; color: #8a7b70;">Vence: ${escapeHtml(l.venc_texto)}</div>
            </td>
            <td><strong>${l.cantidad_unidades} uds.</strong></td>
            <td style="text-align: right;">
                <button type="button" class="btn-sm btn-danger" onclick="eliminarLineaCarrito(${idx})">Quitar</button>
            </td>
        </tr>
    `).join('');
}

async function emitirTraslado(e) {
    e.preventDefault();

    const origenId = parseInt(document.getElementById('almacen_origen_id').value || '0', 10);
    const destinoId = parseInt(document.getElementById('almacen_destino_id').value || '0', 10);

    if (origenId <= 0 || destinoId <= 0) {
        alert('Debes seleccionar almacén origen y almacén destino.');
        return;
    }
    if (origenId === destinoId) {
        alert('El almacén origen y el almacén destino no pueden ser el mismo.');
        return;
    }
    if (carritoTraslado.length === 0) {
        alert('Agrega al menos un producto al carrito del traslado.');
        return;
    }

    const btn = document.getElementById('btnEmitirTraslado');
    const txtOriginal = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Emitiendo traslado...';

    try {
        const res = await fetch('procesar_traslados.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                accion: 'emitir_traslado',
                almacen_origen_id: origenId,
                almacen_destino_id: destinoId,
                lineas: carritoTraslado.map(l => ({
                    producto_maestro_id: l.producto_maestro_id,
                    codigo_barra: l.codigo_barra,
                    cantidad_presentacion: l.cantidad_presentacion,
                    lote_id: l.lote_id
                }))
            })
        });

        const data = await res.json();
        if (data.status === 'success') {
            cerrarModalNuevoTraslado(true);
            mostrarToast(data.message || 'Traslado emitido correctamente.');
            setTimeout(() => location.reload(), 700);
        } else {
            btn.disabled = false;
            btn.textContent = txtOriginal;
            alert(data.message || 'Error al emitir traslado.');
        }
    } catch (err) {
        console.error(err);
        btn.disabled = false;
        btn.textContent = txtOriginal;
        alert('Error de red al comunicarse con el servidor.');
    }
}

// ============================================================================
// MODAL 2: BUSCADOR FILTRADO DE CATÁLOGO Y PRESENTACIONES
// ============================================================================
function abrirModalCatalogo() {
    const selAlm = document.getElementById('almacen_origen_id');
    const origenId = selAlm ? selAlm.value : '';

    if (!origenId) {
        alert('Primero selecciona el almacén de origen en el Paso 1 para consultar existencias reales.');
        if (selAlm) selAlm.focus();
        return;
    }

    const nombreAlm = selAlm.options[selAlm.selectedIndex]?.text || '';
    document.getElementById('subtituloCatalogoAlmacen').textContent = `Almacén origen: ${nombreAlm} • Haz clic en la presentación que deseas trasladar`;
    document.getElementById('catFiltroTexto').value = '';
    document.getElementById('catFiltroCategoria').value = '0';
    document.getElementById('catFiltroMarca').value = '0';
    document.getElementById('catFiltroSoloStock').checked = true;

    document.getElementById('modalBuscarCatalogo').classList.add('open');
    document.getElementById('catFiltroTexto').focus();
    buscarEnCatalogo();
}

function cerrarModalCatalogo() {
    document.getElementById('modalBuscarCatalogo').classList.remove('open');
}

function programarBusquedaCatalogo() {
    clearTimeout(timerBusquedaCatalogo);
    timerBusquedaCatalogo = setTimeout(buscarEnCatalogo, 220);
}

async function buscarEnCatalogo() {
    const origenId = document.getElementById('almacen_origen_id').value;
    const q = document.getElementById('catFiltroTexto').value.trim();
    const catId = document.getElementById('catFiltroCategoria').value;
    const marId = document.getElementById('catFiltroMarca').value;
    const soloStock = document.getElementById('catFiltroSoloStock').checked ? '1' : '0';

    const tbody = document.getElementById('catTablaResultados');
    const badge = document.getElementById('catContadorResultados');
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:22px; color:#888;">Buscando productos...</td></tr>';

    try {
        const params = new URLSearchParams({
            accion: 'buscar_catalogo',
            almacen_id: origenId,
            q: q,
            categoria_id: catId,
            marca_id: marId,
            solo_con_stock: soloStock
        });

        const res = await fetch(`procesar_traslados.php?${params.toString()}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (data.status !== 'success') {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:20px; color:#c62828;">${escapeHtml(data.message || 'Error al buscar.')}</td></tr>`;
            return;
        }

        const items = Array.isArray(data.items) ? data.items : [];
        badge.textContent = `${items.length} producto(s)`;

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:24px; color:#888;">No se encontraron productos con los filtros actuales.</td></tr>';
            return;
        }

        tbody.innerHTML = items.map(item => {
            const stock = parseInt(item.stock_disponible || 0, 10);
            const stockColor = stock > 0 ? '#2e7d32' : '#999';
            const badgeLote = item.requiere_lote
                ? '<span style="font-size:0.68rem; background:#fff3e0; color:#e65100; padding:1px 5px; border-radius:4px; font-weight:700;">FEFO</span>'
                : '<span style="font-size:0.68rem; background:#f0ebe3; color:#7a6b5d; padding:1px 5px; border-radius:4px;">Sin lote</span>';

            let botonesPres = `
                <button type="button" class="btn-sm btn-outline" data-prod-id="${item.id}" data-barcode=""
                    onclick="seleccionarDesdeCatalogo(this)" style="margin: 2px; font-size: 0.78rem; padding: 4px 8px;">
                    Unidad Base (x1)
                </button>
            `;

            (item.presentaciones || []).forEach(pr => {
                const factor = parseInt(pr.factor_multiplicador || 1, 10);
                botonesPres += `
                    <button type="button" class="btn-sm btn-outline" data-prod-id="${item.id}" data-barcode="${escapeHtml(pr.codigo_barra)}"
                        onclick="seleccionarDesdeCatalogo(this)"
                        style="margin: 2px; font-size: 0.78rem; padding: 4px 8px; background: #fff8ee; border-color: #d4a359; color: #5a3818;">
                        <strong>${escapeHtml(pr.descripcion_presentacion)}</strong> (x${factor})
                        <span style="font-family: monospace; color: #8a7b70; font-size: 0.72rem;">[${escapeHtml(pr.codigo_barra)}]</span>
                    </button>
                `;
            });

            return `
                <tr>
                    <td>
                        <div style="font-weight: 700; color: #211813;">${escapeHtml(item.nombre)}</div>
                        <div style="font-size: 0.76rem; color: #a66e2e; font-family: monospace; display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                            <span>${escapeHtml(item.sku)}</span> ${badgeLote}
                        </div>
                    </td>
                    <td>
                        <div style="font-size: 0.82rem; font-weight: 600; color: #4a3b32;">${escapeHtml(item.categoria_nombre)}</div>
                        <div style="font-size: 0.75rem; color: #8a7b70;">${escapeHtml(item.marca_nombre)}</div>
                    </td>
                    <td style="text-align: center; white-space: nowrap;">
                        <strong style="color: ${stockColor}; font-size: 0.92rem;">${stock} uds.</strong>
                    </td>
                    <td><div style="display: flex; flex-wrap: wrap; gap: 2px;">${botonesPres}</div></td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        console.error(err);
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:#c62828;">Error de red al consultar el catálogo.</td></tr>';
    }
}

async function seleccionarDesdeCatalogo(btnEl) {
    const prodId = btnEl.dataset.prodId || '';
    const barcode = btnEl.dataset.barcode || '';
    if (!prodId) return;

    cerrarModalCatalogo();
    document.getElementById('linea_producto_id').value = String(prodId);
    await cargarDatosProductoEnOrigen(barcode);

    const inpCant = document.getElementById('linea_cantidad');
    if (inpCant) {
        inpCant.focus();
        inpCant.select();
    }
}

// ============================================================================
// MODAL 3: CONFIRMAR RECEPCIÓN EN DESTINO (+ DETECCIÓN AUTOMÁTICA DE MERMA)
// ============================================================================
async function abrirModalRecibir(trasladoId) {
    document.getElementById('recibirTrasladoId').value = String(trasladoId);
    document.getElementById('recibirTitulo').textContent = 'Confirmar Recepción de Traslado';
    document.getElementById('recibirResumen').innerHTML = 'Cargando mercancía en tránsito...';
    document.getElementById('recibirTablaBody').innerHTML = '';
    document.getElementById('recibirJustificacion').value = '';
    document.getElementById('recibirPin').value = '';
    document.getElementById('bloqueMermaRecepcion').style.display = 'none';
    document.getElementById('modalRecibirTraslado').classList.add('open');

    try {
        const res = await fetch(`procesar_traslados.php?accion=detalle&id=${encodeURIComponent(trasladoId)}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (data.status !== 'success') {
            alert(data.message || 'No se pudo cargar el traslado.');
            cerrarModalRecibir();
            return;
        }

        const cab = data.cabecera;
        const dets = Array.isArray(data.detalles) ? data.detalles : [];

        document.getElementById('recibirTitulo').textContent = `Recibir Traslado ${cab.numero_traslado}`;
        document.getElementById('recibirResumen').innerHTML = `
            <strong>Ruta:</strong> ${escapeHtml(cab.almacen_origen_nombre)} → <strong>${escapeHtml(cab.almacen_destino_nombre)}</strong>
            &nbsp;|&nbsp; <strong>Emitido por:</strong> ${escapeHtml(cab.emisor_nombre || '—')}
            &nbsp;|&nbsp; <strong>Fecha:</strong> ${escapeHtml(cab.fecha_emision || '—')}
        `;

        document.getElementById('recibirTablaBody').innerHTML = dets.map((d, idx) => {
            const enviada = parseInt(d.cantidad_enviada || 0, 10);
            const loteTxt = parseInt(d.lote_id, 10) === 0
                ? 'Comodín #0'
                : `${escapeHtml(d.numero_lote || '')} (${escapeHtml(d.fecha_vencimiento || 'N/A')})`;

            return `
                <tr>
                    <td><strong>${escapeHtml(d.sku)}</strong> — ${escapeHtml(d.producto_nombre)}</td>
                    <td style="font-size: 0.84rem;">${loteTxt}</td>
                    <td style="text-align: center;"><strong>${enviada}</strong></td>
                    <td style="text-align: center;">
                        <input
                            type="number"
                            class="form-control inp-cantidad-recibida"
                            data-prod-id="${d.producto_maestro_id}"
                            data-lote-id="${d.lote_id}"
                            data-enviada="${enviada}"
                            data-idx="${idx}"
                            min="0"
                            max="${enviada}"
                            step="1"
                            value="${enviada}"
                            style="width: 95px; margin: 0 auto; text-align: center; font-weight: 700;"
                            oninput="evaluarFaltantesRecepcion()"
                        />
                    </td>
                    <td style="text-align: center;" id="celdaFaltante_${idx}">
                        <span style="color: #2e7d32; font-weight: 600;">0</span>
                    </td>
                </tr>
            `;
        }).join('');

        evaluarFaltantesRecepcion();
    } catch (err) {
        console.error(err);
        alert('Error de red al cargar el traslado.');
        cerrarModalRecibir();
    }
}

function evaluarFaltantesRecepcion() {
    const inputs = document.querySelectorAll('.inp-cantidad-recibida');
    let totalFaltantes = 0;

    inputs.forEach(inp => {
        const enviada = parseInt(inp.dataset.enviada || '0', 10);
        let rec = parseInt(inp.value || '0', 10);
        if (isNaN(rec) || rec < 0) rec = 0;
        if (rec > enviada) {
            rec = enviada;
            inp.value = String(enviada);
        }
        const faltante = enviada - rec;
        totalFaltantes += faltante;

        const celda = document.getElementById(`celdaFaltante_${inp.dataset.idx}`);
        if (celda) {
            celda.innerHTML = faltante > 0
                ? `<strong style="color: #c62828;">-${faltante} merma</strong>`
                : `<span style="color: #2e7d32; font-weight: 600;">0</span>`;
        }
    });

    const bloqueMerma = document.getElementById('bloqueMermaRecepcion');
    const inpJust = document.getElementById('recibirJustificacion');
    const selSup = document.getElementById('recibirSupervisorId');
    const inpPin = document.getElementById('recibirPin');

    if (totalFaltantes > 0) {
        bloqueMerma.style.display = 'block';
        inpJust.required = true;
        selSup.required = true;
        inpPin.required = true;
    } else {
        bloqueMerma.style.display = 'none';
        inpJust.required = false;
        selSup.required = false;
        inpPin.required = false;
        inpPin.value = '';
    }
}

function cerrarModalRecibir() {
    document.getElementById('modalRecibirTraslado').classList.remove('open');
}

async function confirmarRecepcion(e) {
    e.preventDefault();

    const trasladoId = parseInt(document.getElementById('recibirTrasladoId').value || '0', 10);
    const inputs = Array.from(document.querySelectorAll('.inp-cantidad-recibida'));
    let hayFaltantes = false;

    const lineas = inputs.map(inp => {
        const enviada = parseInt(inp.dataset.enviada || '0', 10);
        const recibida = parseInt(inp.value || '0', 10);
        if (recibida < enviada) hayFaltantes = true;
        return {
            producto_maestro_id: parseInt(inp.dataset.prodId, 10),
            lote_id: parseInt(inp.dataset.loteId, 10),
            cantidad_recibida: recibida
        };
    });

    const justificacion = document.getElementById('recibirJustificacion').value.trim();
    const supervisorId = document.getElementById('recibirSupervisorId').value.trim();
    const pin = document.getElementById('recibirPin').value.trim();

    if (hayFaltantes) {
        if (justificacion.length < 5) {
            alert('Indica la justificación del faltante o rotura (mínimo 5 caracteres).');
            document.getElementById('recibirJustificacion').focus();
            return;
        }
        if (!supervisorId) {
            alert('Selecciona el supervisor que autoriza la merma automática.');
            document.getElementById('recibirSupervisorId').focus();
            return;
        }
        if (!/^\d{4,6}$/.test(pin)) {
            alert('Ingresa un PIN de supervisor válido (4 a 6 dígitos).');
            document.getElementById('recibirPin').focus();
            return;
        }
    }

    const btn = document.getElementById('btnConfirmarRecepcion');
    const txtOriginal = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Confirmando recepción...';

    try {
        const res = await fetch('procesar_traslados.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                accion: 'recibir_traslado',
                traslado_id: trasladoId,
                lineas: lineas,
                justificacion_merma: hayFaltantes ? justificacion : '',
                supervisor_id: hayFaltantes ? supervisorId : '',
                pin_autorizacion: hayFaltantes ? pin : ''
            })
        });

        const data = await res.json();
        if (data.status === 'success') {
            cerrarModalRecibir();
            mostrarToast(data.message || 'Traslado recibido.');
            setTimeout(() => location.reload(), 700);
        } else {
            btn.disabled = false;
            btn.textContent = txtOriginal;
            alert(data.message || 'Error al confirmar la recepción.');
        }
    } catch (err) {
        console.error(err);
        btn.disabled = false;
        btn.textContent = txtOriginal;
        alert('Error de red al comunicarse con el servidor.');
    }
}

// ============================================================================
// MODAL 4: CANCELAR TRASLADO EN TRÁNSITO
// ============================================================================
function abrirModalCancelar(trasladoId, numeroTraslado) {
    document.getElementById('cancelarTrasladoId').value = String(trasladoId);
    document.getElementById('cancelarNumeroTexto').textContent = numeroTraslado;
    document.getElementById('cancelarJustificacion').value = '';
    document.getElementById('cancelarPin').value = '';
    document.getElementById('modalCancelarTraslado').classList.add('open');
    document.getElementById('cancelarJustificacion').focus();
}

function cerrarModalCancelar() {
    document.getElementById('modalCancelarTraslado').classList.remove('open');
}

async function confirmarCancelacion(e) {
    e.preventDefault();

    const trasladoId = parseInt(document.getElementById('cancelarTrasladoId').value || '0', 10);
    const justificacion = document.getElementById('cancelarJustificacion').value.trim();
    const supervisorId = document.getElementById('cancelarSupervisorId').value.trim();
    const pin = document.getElementById('cancelarPin').value.trim();

    if (justificacion.length < 5) {
        alert('Indica el motivo de cancelación (mínimo 5 caracteres).');
        return;
    }
    if (!supervisorId || !/^\d{4,6}$/.test(pin)) {
        alert('Debes seleccionar un supervisor y digitar un PIN válido (4 a 6 dígitos).');
        return;
    }

    const btn = document.getElementById('btnConfirmarCancelacion');
    const txtOriginal = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Cancelando...';

    try {
        const res = await fetch('procesar_traslados.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                accion: 'cancelar_traslado',
                traslado_id: trasladoId,
                justificacion: justificacion,
                supervisor_id: supervisorId,
                pin_autorizacion: pin
            })
        });

        const data = await res.json();
        if (data.status === 'success') {
            cerrarModalCancelar();
            mostrarToast(data.message || 'Traslado cancelado.');
            setTimeout(() => location.reload(), 700);
        } else {
            btn.disabled = false;
            btn.textContent = txtOriginal;
            alert(data.message || 'Error al cancelar el traslado.');
        }
    } catch (err) {
        console.error(err);
        btn.disabled = false;
        btn.textContent = txtOriginal;
        alert('Error de red al cancelar el traslado.');
    }
}

// ============================================================================
// MODAL 5: VER DETALLE DEL TRASLADO Y FILTROS DE TABLA PRINCIPAL
// ============================================================================
async function verDetalleTraslado(trasladoId) {
    const modal = document.getElementById('modalDetalleTraslado');
    const titulo = document.getElementById('detalleTitulo');
    const body = document.getElementById('detalleContenido');

    titulo.textContent = 'Detalle de Traslado Interno';
    body.innerHTML = '<p style="color: #888;">Cargando información...</p>';
    modal.classList.add('open');

    try {
        const res = await fetch(`procesar_traslados.php?accion=detalle&id=${encodeURIComponent(trasladoId)}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (data.status !== 'success') {
            body.innerHTML = `<p style="color: #c62828;">${escapeHtml(data.message || 'Error al consultar detalle.')}</p>`;
            return;
        }

        const cab = data.cabecera;
        const dets = Array.isArray(data.detalles) ? data.detalles : [];
        const merma = data.merma;

        titulo.textContent = `Detalle del Traslado • ${cab.numero_traslado}`;

        let totEnv = 0;
        let totRec = 0;

        const filas = dets.map(d => {
            const env = parseInt(d.cantidad_enviada || 0, 10);
            const rec = parseInt(d.cantidad_recibida || 0, 10);
            const dif = cab.estado === 'RECIBIDO' ? (env - rec) : 0;
            totEnv += env;
            totRec += rec;

            const loteTxt = parseInt(d.lote_id, 10) === 0
                ? 'Comodín #0'
                : `${escapeHtml(d.numero_lote || '')} (${escapeHtml(d.fecha_vencimiento || 'N/A')})`;

            return `
                <tr>
                    <td><strong>${escapeHtml(d.sku)}</strong> — ${escapeHtml(d.producto_nombre)}</td>
                    <td>${loteTxt}</td>
                    <td style="text-align: center;"><strong>${env}</strong></td>
                    <td style="text-align: center;"><strong>${cab.estado === 'RECIBIDO' ? rec : '—'}</strong></td>
                    <td style="text-align: center; color: ${dif > 0 ? '#c62828' : '#2e7d32'}; font-weight: 700;">
                        ${cab.estado === 'RECIBIDO' ? (dif > 0 ? '-' + dif : '0') : '—'}
                    </td>
                </tr>
            `;
        }).join('');

        const bloqueMermaHtml = merma ? `
            <div style="margin-top: 12px; padding: 12px 14px; background: #fff5f5; border: 1px solid #ffcdd2; border-radius: 8px; font-size: 0.86rem; color: #b71c1c;">
                <div><strong>⚠️ Merma Automática Generada en Kardex (MERMA_ROTURA):</strong> ${parseInt(merma.total_merma_unidades || 0, 10)} uds. • RD$ ${parseFloat(merma.total_merma_costo || 0).toFixed(2)}</div>
                <div style="margin-top: 4px;"><strong>Autorizado por:</strong> ${escapeHtml(merma.supervisor_nombre || '—')}</div>
                <div style="margin-top: 4px;"><strong>Motivo:</strong> ${escapeHtml(merma.justificacion || '—')}</div>
            </div>
        ` : '';

        body.innerHTML = `
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background: #faf7f2; border: 1px solid #e7dcc8; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; font-size: 0.88rem;">
                <div><strong>N° Traslado:</strong> ${escapeHtml(cab.numero_traslado)}</div>
                <div><strong>Estado:</strong> <strong>${escapeHtml(cab.estado)}</strong></div>
                <div><strong>Almacén Origen:</strong> ${escapeHtml(cab.almacen_origen_nombre)}</div>
                <div><strong>Almacén Destino:</strong> ${escapeHtml(cab.almacen_destino_nombre)}</div>
                <div><strong>Emitido por:</strong> ${escapeHtml(cab.emisor_nombre || '—')}</div>
                <div><strong>Recibido por:</strong> ${escapeHtml(cab.receptor_nombre || 'Pendiente')}</div>
                <div style="grid-column: 1 / -1;"><strong>Fecha de Emisión:</strong> ${escapeHtml(cab.fecha_emision || '—')}</div>
            </div>

            <table class="carrito-reglas-table">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Lote / Vencimiento</th>
                        <th style="text-align: center;">Enviadas</th>
                        <th style="text-align: center;">Recibidas</th>
                        <th style="text-align: center;">Merma</th>
                    </tr>
                </thead>
                <tbody>
                    ${filas || '<tr><td colspan="5" style="text-align:center; padding:16px; color:#888;">Sin líneas registradas.</td></tr>'}
                </tbody>
                <tfoot>
                    <tr style="background: #f8f4ec;">
                        <td colspan="2" style="text-align: right;"><strong>TOTALES:</strong></td>
                        <td style="text-align: center;"><strong>${totEnv} uds.</strong></td>
                        <td style="text-align: center;"><strong>${cab.estado === 'RECIBIDO' ? totRec + ' uds.' : '—'}</strong></td>
                        <td style="text-align: center;"><strong>${cab.estado === 'RECIBIDO' ? (totEnv - totRec) + ' uds.' : '—'}</strong></td>
                    </tr>
                </tfoot>
            </table>
            ${bloqueMermaHtml}
        `;
    } catch (err) {
        console.error(err);
        body.innerHTML = '<p style="color: #c62828;">Error de red al consultar el detalle.</p>';
    }
}

function cerrarModalDetalle() {
    document.getElementById('modalDetalleTraslado').classList.remove('open');
}

function filtrarTablaTraslados() {
    const q = (document.getElementById('buscador')?.value || '').toLowerCase().trim();
    const est = (document.getElementById('filtroEstado')?.value || '').toUpperCase();
    const filas = document.querySelectorAll('.fila-traslado');
    let visibles = 0;

    filas.forEach(f => {
        const eFila = (f.getAttribute('data-estado') || '').toUpperCase();
        const texto = f.getAttribute('data-texto') || '';
        if ((!est || eFila === est) && (!q || texto.includes(q))) {
            f.style.display = '';
            visibles++;
        } else {
            f.style.display = 'none';
        }
    });

    const info = document.getElementById('footerInfo');
    const badge = document.getElementById('badgeCount');
    if (info) info.textContent = `Mostrando ${visibles} traslados`;
    if (badge) badge.textContent = `${visibles} registros`;
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const mCat = document.getElementById('modalBuscarCatalogo');
        if (mCat && mCat.classList.contains('open')) {
            cerrarModalCatalogo();
        }
    }
});