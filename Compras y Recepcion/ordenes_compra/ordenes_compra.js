/**
 * ============================================================================
 * JavaScript: Órdenes de Compra
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/ordenes_compra/ordenes_compra.js
 * ============================================================================
 */

// Mapa en memoria de los productos agregados a la orden actual: id => { id, sku, nombre, cantidad, costo }
const MAPA_ORDEN_PRODUCTOS = new Map();
// Cache en memoria para productos conocidos (evita pérdidas al buscar en servidor con miles de productos)
const MAPA_CATALOGO_CACHE = new Map();
let timerDebounceBusqueda = null;

document.addEventListener('DOMContentLoaded', function () {
    // Precargar cache con catálogo inicial
    if (Array.isArray(CATALOGO_PRODUCTOS)) {
        CATALOGO_PRODUCTOS.forEach(p => {
            if (p && p.id) MAPA_CATALOGO_CACHE.set(parseInt(p.id), p);
        });
    }
});

/**
 * Filtrado dinámico en tiempo real de la tabla de órdenes en el navegador
 */
function filtrarTablaCliente() {
    const input = document.getElementById('inputBuscarTabla');
    const query = (input ? input.value : '').toLowerCase().trim();
    const rows = document.querySelectorAll('#tablaOrdenes tbody tr');
    let visibles = 0;

    rows.forEach(tr => {
        if (!tr.dataset.numero) return;

        const num = tr.dataset.numero || '';
        const prv = tr.dataset.proveedor || '';

        const coincide = num.includes(query) || prv.includes(query);
        tr.style.display = coincide ? '' : 'none';
        if (coincide) visibles++;
    });

    const badge = document.getElementById('badgeTotalOrdenes');
    if (badge) {
        badge.textContent = `${visibles} órdenes`;
    }
}

/**
 * Apertura del modal de creación de nueva orden
 */
function abrirModalNuevaOrden() {
    const modal = document.getElementById('modalNuevaOrden');
    const form = document.getElementById('formNuevaOrden');
    if (form) form.reset();

    MAPA_ORDEN_PRODUCTOS.clear();

    const inputBuscar = document.getElementById('inputBuscarCatalogo');
    if (inputBuscar) inputBuscar.value = '';

    actualizarInfoProveedor();
    generarSiguienteNumeroOrden();
    renderizarTablaOrden();

    // Mostrar de inmediato el catálogo completo con botones "+ Agregar"
    renderizarPanelResultados(CATALOGO_PRODUCTOS || []);

    if (modal) {
        modal.classList.add('open');
    }
}

/**
 * Cierre del modal de creación
 */
function cerrarModalNuevaOrden() {
    const modal = document.getElementById('modalNuevaOrden');
    if (modal) {
        modal.classList.remove('open');
    }
}

/**
 * Consulta del correlativo siguiente para el número de orden
 */
async function generarSiguienteNumeroOrden() {
    const inputNum = document.getElementById('nuevaOrdenNumero');
    if (!inputNum) return;

    try {
        const resp = await fetch('procesar_ordenes_compra.php?accion=obtener_siguiente_numero');
        const data = await resp.json();
        if (data && data.ok && data.numero_orden) {
            inputNum.value = data.numero_orden;
        }
    } catch (e) {
        console.warn('No se pudo generar número correlativo automático:', e);
    }
}

/**
 * Actualiza la información de apoyo del proveedor (días de crédito)
 */
function actualizarInfoProveedor() {
    const select = document.getElementById('nuevaOrdenProveedor');
    const lbl = document.getElementById('infoProveedorDias');
    if (!select || !lbl) return;

    const opt = select.selectedOptions[0];
    if (opt && opt.value) {
        const dias = opt.dataset.dias || '0';
        lbl.textContent = `Días de crédito habitual del suplidor: ${dias} días`;
        lbl.style.color = '#2e7d52';
    } else {
        lbl.textContent = 'Días de crédito habitual: --';
        lbl.style.color = 'var(--text-muted)';
    }
}

/**
 * Busca productos en el catálogo según el texto ingresado.
 * Muestra todos los productos por defecto y filtra al instante al escribir.
 */
function buscarProductosCatalogo(query) {
    const panel = document.getElementById('panelResultadosCatalogo');
    if (!panel) return;

    const q = (query || '').toLowerCase().trim();

    if (!q) {
        // Enseñar absolutamente TODOS los productos del catálogo
        renderizarPanelResultados(CATALOGO_PRODUCTOS || []);
        return;
    }

    // Filtrar al instante en memoria sobre todo el catálogo
    const filtrados = (CATALOGO_PRODUCTOS || []).filter(p => {
        const nom = (p.nombre || '').toLowerCase();
        const sku = (p.sku || '').toLowerCase();
        return nom.includes(q) || sku.includes(q);
    });

    renderizarPanelResultados(filtrados);
}

/**
 * Muestra u oculta el catálogo completo de productos
 */
function toggleVerCatalogoCompleto() {
    const panel = document.getElementById('panelResultadosCatalogo');
    const inputBuscar = document.getElementById('inputBuscarCatalogo');
    if (!panel) return;

    if (inputBuscar) inputBuscar.value = '';
    renderizarPanelResultados(CATALOGO_PRODUCTOS || []);
}

/**
 * Renderiza los items de productos en el panel de búsqueda
 */
function renderizarPanelResultados(lista) {
    const panel = document.getElementById('panelResultadosCatalogo');
    if (!panel) return;

    if (!lista || lista.length === 0) {
        panel.innerHTML = '<div style="padding: 12px; color: var(--text-muted); font-size: 0.82rem; text-align: center;">No se encontraron productos que coincidan con la búsqueda.</div>';
        panel.style.display = 'block';
        return;
    }

    let html = '';
    lista.forEach(p => {
        const prodId = parseInt(p.id);
        const yaAgregado = MAPA_ORDEN_PRODUCTOS.has(prodId);
        const costo = parseFloat(p.costo_promedio || 0).toFixed(2);

        html += `
            <div class="item-catalogo-busqueda" data-prod-id="${prodId}" style="display: flex; justify-content: space-between; align-items: center; padding: 7px 12px; border-bottom: 1px solid #f2ede4; background: ${yaAgregado ? '#fcf9f2' : '#ffffff'}; border-radius: 4px; margin-bottom: 3px; transition: background 0.15s ease;">
                <div style="flex: 1; min-width: 0; padding-right: 12px;">
                    <strong style="color: var(--text-dark); font-size: 0.86rem; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        ${p.nombre}
                    </strong>
                    <div style="font-size: 0.74rem; color: var(--text-muted); display: flex; gap: 8px;">
                        <span style="font-family: var(--font-mono); font-weight: 600;">${p.sku}</span>
                        <span>•</span>
                        <span>Costo Prom: RD$ ${costo}</span>
                    </div>
                </div>

                <div class="col-boton-accion">
                    ${yaAgregado
                        ? `<button type="button" class="btn btn-sm btn-danger" onclick="quitarProductoDeOrden(${prodId})" style="padding: 4px 12px; font-size: 0.78rem; white-space: nowrap;">✕ Desagregar</button>`
                        : `<button type="button" class="btn btn-sm btn-gold" onclick="agregarProductoAOrden(${prodId})" style="padding: 4px 12px; font-size: 0.78rem; white-space: nowrap;">+ Agregar</button>`
                    }
                </div>
            </div>
        `;
    });

    panel.innerHTML = html;
    panel.style.display = 'block';
}

/**
 * Agrega un producto a la orden de compra
 */
function agregarProductoAOrden(productoId) {
    const prodId = parseInt(productoId);
    if (!prodId) return;

    if (MAPA_ORDEN_PRODUCTOS.has(prodId)) {
        return;
    }

    const prod = MAPA_CATALOGO_CACHE.get(prodId) || (CATALOGO_PRODUCTOS || []).find(p => parseInt(p.id) === prodId);
    if (!prod) return;

    const costoRef = parseFloat(prod.costo_promedio || 0) > 0
        ? parseFloat(prod.costo_promedio)
        : (parseFloat(prod.precio_base_unidad || 0) > 0 ? parseFloat(prod.precio_base_unidad) : 0);

    MAPA_ORDEN_PRODUCTOS.set(prodId, {
        id: prodId,
        sku: prod.sku,
        nombre: prod.nombre,
        cantidad: 1,
        costo: costoRef
    });

    renderizarTablaOrden();
    actualizarBotonEnPanel(prodId, true);
}

/**
 * Quita (desagrega) un producto de la orden
 */
function quitarProductoDeOrden(productoId) {
    const prodId = parseInt(productoId);
    if (!prodId) return;

    MAPA_ORDEN_PRODUCTOS.delete(prodId);

    renderizarTablaOrden();
    actualizarBotonEnPanel(prodId, false);
}

/**
 * Actualiza solo el botón de un item en el panel de resultados sin re-renderizar todo
 */
function actualizarBotonEnPanel(productoId, estaAgregado) {
    const item = document.querySelector(`.item-catalogo-busqueda[data-prod-id="${productoId}"]`);
    if (!item) return;

    const colBtn = item.querySelector('.col-boton-accion');
    if (!colBtn) return;

    if (estaAgregado) {
        colBtn.innerHTML = `<button type="button" class="btn btn-sm btn-danger" onclick="quitarProductoDeOrden(${productoId})" style="padding: 3px 10px; font-size: 0.76rem; white-space: nowrap;">✕ Desagregar</button>`;
        item.style.background = '#fcf9f2';
    } else {
        colBtn.innerHTML = `<button type="button" class="btn btn-sm btn-gold" onclick="agregarProductoAOrden(${productoId})" style="padding: 3px 10px; font-size: 0.76rem; white-space: nowrap;">+ Agregar</button>`;
        item.style.background = '#ffffff';
    }
}

/**
 * Actualiza la cantidad de un producto en la orden
 */
function actualizarCantidadItem(productoId, valor) {
    const prodId = parseInt(productoId);
    const item = MAPA_ORDEN_PRODUCTOS.get(prodId);
    if (!item) return;

    const cant = parseInt(valor) || 0;
    item.cantidad = cant;

    const fila = document.querySelector(`tr[data-orden-prod-id="${prodId}"]`);
    if (fila) {
        const subtotal = cant * item.costo;
        const spanSubtotal = fila.querySelector('.subtotal-fila');
        if (spanSubtotal) {
            spanSubtotal.textContent = 'RD$ ' + subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }

    calcularTotalesNuevaOrden();
}

/**
 * Actualiza el costo pactado de un producto en la orden
 */
function actualizarCostoItem(productoId, valor) {
    const prodId = parseInt(productoId);
    const item = MAPA_ORDEN_PRODUCTOS.get(prodId);
    if (!item) return;

    const costo = parseFloat(valor) || 0;
    item.costo = costo;

    const fila = document.querySelector(`tr[data-orden-prod-id="${prodId}"]`);
    if (fila) {
        const subtotal = item.cantidad * costo;
        const spanSubtotal = fila.querySelector('.subtotal-fila');
        if (spanSubtotal) {
            spanSubtotal.textContent = 'RD$ ' + subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }

    calcularTotalesNuevaOrden();
}

/**
 * Renderiza la tabla de productos actualmente agregados a la orden
 */
function renderizarTablaOrden() {
    const tbody = document.getElementById('tbodyLineasNuevaOrden');
    if (!tbody) return;

    if (MAPA_ORDEN_PRODUCTOS.size === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" class="text-center" style="padding: 24px 16px; color: var(--text-muted); font-size: 0.85rem;">
                    No has agregado productos a la orden aún. Escribe en el buscador superior y presiona <strong>+ Agregar</strong>.
                </td>
            </tr>
        `;
        calcularTotalesNuevaOrden();
        return;
    }

    let html = '';
    MAPA_ORDEN_PRODUCTOS.forEach(item => {
        const subtotal = item.cantidad * item.costo;
        html += `
            <tr data-orden-prod-id="${item.id}" style="border-bottom: 1px solid #f0e8db;">
                <td style="padding: 6px 10px; vertical-align: middle;">
                    <strong style="color: var(--text-dark); font-size: 0.84rem; display: block; line-height: 1.2;">${item.nombre}</strong>
                    <small style="font-family: var(--font-mono); color: var(--text-muted); font-size: 0.72rem;">${item.sku}</small>
                </td>
                <td style="padding: 6px 6px; vertical-align: middle; text-align: center;">
                    <input type="number" class="form-control text-center" min="1" step="1" value="${item.cantidad}" required
                        oninput="actualizarCantidadItem(${item.id}, this.value)"
                        style="width: 100%; height: 32px; font-size: 0.85rem; padding: 2px 4px; font-family: var(--font-mono); font-weight: 600;" />
                </td>
                <td style="padding: 6px 6px; vertical-align: middle; text-align: right;">
                    <input type="number" class="form-control text-right" min="0.01" step="0.01" value="${item.costo.toFixed(2)}" required
                        oninput="actualizarCostoItem(${item.id}, this.value)"
                        style="width: 100%; height: 32px; font-size: 0.85rem; padding: 2px 6px; font-family: var(--font-mono); font-weight: 600;" />
                </td>
                <td style="padding: 6px 10px; vertical-align: middle; text-align: right; font-family: var(--font-mono); font-weight: 700; color: #211813; font-size: 0.88rem;">
                    <span class="subtotal-fila">RD$ ${subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                </td>
                <td style="padding: 6px 4px; vertical-align: middle; text-align: center;">
                    <button type="button" class="btn btn-sm btn-danger" title="Quitar de la orden" onclick="quitarProductoDeOrden(${item.id})"
                        style="padding: 2px 6px; height: 28px; width: 28px; line-height: 1; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; border-radius: 4px;">✕</button>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
    calcularTotalesNuevaOrden();
}

/**
 * Calcula y actualiza el resumen general de la orden
 */
function calcularTotalesNuevaOrden() {
    let totalLineas = MAPA_ORDEN_PRODUCTOS.size;
    let totalUnidades = 0;
    let montoTotal = 0;

    MAPA_ORDEN_PRODUCTOS.forEach(item => {
        totalUnidades += item.cantidad;
        montoTotal += (item.cantidad * item.costo);
    });

    const lblLineas = document.getElementById('lblTotalLineas');
    const lblUnidades = document.getElementById('lblTotalUnidades');
    const lblMonto = document.getElementById('lblMontoTotalEstimado');

    if (lblLineas) lblLineas.textContent = totalLineas;
    if (lblUnidades) lblUnidades.textContent = totalUnidades.toLocaleString();
    if (lblMonto) {
        lblMonto.textContent = 'RD$ ' + montoTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
}

/**
 * Guarda y emite la nueva orden de compra hacia el backend
 */
async function guardarNuevaOrden(event) {
    event.preventDefault();

    const btnGuardar = document.getElementById('btnGuardarOrden');
    const proveedorId = document.getElementById('nuevaOrdenProveedor').value;
    const numeroOrden = document.getElementById('nuevaOrdenNumero').value.trim();
    const fechaEsperada = document.getElementById('nuevaOrdenFechaEsperada').value;

    if (!proveedorId) {
        alert('Por favor seleccione un proveedor.');
        return;
    }
    if (!numeroOrden) {
        alert('El número de orden es obligatorio.');
        return;
    }

    if (MAPA_ORDEN_PRODUCTOS.size === 0) {
        alert('Debe agregar al menos un producto a la orden usando la barra de búsqueda superior.');
        const inputBuscar = document.getElementById('inputBuscarCatalogo');
        if (inputBuscar) inputBuscar.focus();
        return;
    }

    const detalles = [];
    for (const [prodId, item] of MAPA_ORDEN_PRODUCTOS.entries()) {
        if (isNaN(item.cantidad) || item.cantidad <= 0) {
            alert(`El producto '${item.nombre}' tiene una cantidad no válida. Debe ser mayor a 0.`);
            return;
        }
        if (isNaN(item.costo) || item.costo <= 0) {
            alert(`El producto '${item.nombre}' tiene un costo pactado no válido. Debe ser mayor a 0.`);
            return;
        }

        detalles.push({
            producto_maestro_id: prodId,
            cantidad_ordenada: item.cantidad,
            costo_pactado_unitario: item.costo
        });
    }

    if (!confirm(`¿Confirma emitir la Orden de Compra ${numeroOrden} con ${detalles.length} producto(s)?`)) {
        return;
    }

    if (btnGuardar) {
        btnGuardar.disabled = true;
        btnGuardar.textContent = 'Guardando orden...';
    }

    try {
        const payload = {
            accion: 'crear_orden',
            proveedor_id: proveedorId,
            numero_orden: numeroOrden,
            fecha_esperada: fechaEsperada || null,
            detalles: detalles
        };

        const resp = await fetch('procesar_ordenes_compra.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const data = await resp.json();

        if (data && data.ok) {
            alert('✅ ' + (data.mensaje || 'Orden de compra creada exitosamente.'));
            cerrarModalNuevaOrden();
            window.location.reload();
        } else {
            alert('❌ Error al crear la orden: ' + (data.mensaje || 'Error desconocido del servidor.'));
        }
    } catch (e) {
        console.error('Error en fetch:', e);
        alert('❌ Error de comunicación con el servidor: ' + e.message);
    } finally {
        if (btnGuardar) {
            btnGuardar.disabled = false;
            btnGuardar.textContent = '💾 Crear y Emitir Orden';
        }
    }
}

/**
 * Consulta y despliega el detalle completo y seguimiento de una orden
 */
async function abrirModalDetalle(ordenId) {
    const modal = document.getElementById('modalDetalleOrden');
    const cuerpo = document.getElementById('cuerpoDetalleOrden');
    const footer = document.getElementById('footerDetalleOrden');
    const titulo = document.getElementById('detModalTitulo');
    const subtitulo = document.getElementById('detModalSubtitulo');

    if (modal) modal.classList.add('open');
    if (cuerpo) {
        cuerpo.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-muted);"><div style="font-size: 1.5rem; margin-bottom: 8px;">⏳</div>Cargando detalle de la orden...</div>';
    }

    try {
        const resp = await fetch(`procesar_ordenes_compra.php?accion=obtener_detalle&orden_id=${ordenId}`);
        const data = await resp.json();

        if (!data || !data.ok) {
            throw new Error(data.mensaje || 'No se pudo cargar la orden.');
        }

        const ord = data.orden;
        const lineas = data.detalles || [];
        const recepciones = data.recepciones || [];

        if (titulo) titulo.textContent = `Orden de Compra: ${ord.numero_orden}`;
        if (subtitulo) subtitulo.textContent = `Proveedor: ${ord.proveedor_nombre} | Emisión: ${new Date(ord.fecha_emision).toLocaleDateString()}`;

        // Badge de estado
        const estadoBadges = {
            'PENDIENTE': { label: 'PENDIENTE', bg: '#fef9e7', color: '#b78103', border: '#f9e79f' },
            'RECIBIDA_PARCIAL': { label: 'RECIBIDA PARCIAL', bg: '#e8f4fd', color: '#1976d2', border: '#bbdefb' },
            'COMPLETADA': { label: 'COMPLETADA', bg: '#e8f8f0', color: '#2e7d52', border: '#c8e6c9' },
            'CANCELADA': { label: 'CANCELADA', bg: '#fdf2f2', color: '#c62828', border: '#ffcdd2' }
        };
        const badge = estadoBadges[ord.estado] || { label: ord.estado, bg: '#eee', color: '#444', border: '#ccc' };

        let lineasHtml = '';
        let sumaOrdenada = 0;
        let sumaRecibida = 0;
        let sumaPendiente = 0;

        lineas.forEach(l => {
            const cantOrd = parseInt(l.cantidad_ordenada || 0);
            const cantRec = parseInt(l.cantidad_recibida_total || 0);
            const cantPend = parseInt(l.cantidad_pendiente || 0);
            const costo = parseFloat(l.costo_pactado_unitario || 0);
            const subtotal = parseFloat(l.subtotal_estimado || 0);

            sumaOrdenada += cantOrd;
            sumaRecibida += cantRec;
            sumaPendiente += cantPend;

            lineasHtml += `
                <tr>
                    <td style="padding: 6px 10px;">
                        <strong style="color: var(--text-dark);">${l.producto_nombre}</strong><br />
                        <small style="color: var(--text-muted); font-family: var(--font-mono);">${l.sku}</small>
                    </td>
                    <td class="text-center" style="font-family: var(--font-mono); font-weight: 600; padding: 6px;">${cantOrd}</td>
                    <td class="text-center" style="font-family: var(--font-mono); font-weight: 600; color: #2e7d52; padding: 6px;">${cantRec}</td>
                    <td class="text-center" style="font-family: var(--font-mono); font-weight: 700; color: ${cantPend > 0 ? '#b78103' : '#8c8173'}; padding: 6px;">
                        ${cantPend}
                    </td>
                    <td class="text-right" style="font-family: var(--font-mono); padding: 6px 8px;">RD$ ${costo.toFixed(2)}</td>
                    <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; padding: 6px 10px;">RD$ ${subtotal.toFixed(2)}</td>
                </tr>
            `;
        });

        // Tabla de Recepciones vinculadas
        let recepcionesHtml = '';
        if (recepciones.length === 0) {
            recepcionesHtml = `
                <tr>
                    <td colspan="7" class="text-center" style="padding: 20px; color: var(--text-muted);">
                        Aún no se han registrado recepciones físicas de mercancía para esta orden.
                    </td>
                </tr>
            `;
        } else {
            recepciones.forEach(r => {
                const fechaRec = new Date(r.fecha_recepcion).toLocaleString();
                const monto = parseFloat(r.monto_total_factura || 0).toFixed(2);
                recepcionesHtml += `
                    <tr>
                        <td style="font-weight: 700; font-family: var(--font-mono); color: var(--accent-gold); padding: 6px 8px;">#${r.recepcion_id}</td>
                        <td style="font-weight: 600; padding: 6px 8px;">${r.numero_factura_proveedor}</td>
                        <td style="font-family: var(--font-mono); padding: 6px 8px;">${r.ncf_proveedor || '<span style="color:#aaa;">N/A</span>'}</td>
                        <td class="text-center" style="padding: 6px 4px;">
                            <span style="font-size: 0.72rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: ${r.condicion_pago === 'CONTADO' ? '#e8f5e9' : '#e3f2fd'}; color: ${r.condicion_pago === 'CONTADO' ? '#2e7d32' : '#1565c0'};">
                                ${r.condicion_pago}
                            </span>
                        </td>
                        <td style="padding: 6px 8px; font-size: 0.8rem;">${fechaRec}</td>
                        <td class="text-center" style="font-family: var(--font-mono); padding: 6px 8px;">${r.total_unidades_recibidas} u. (${r.total_items_recibidos} prod)</td>
                        <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; padding: 6px 10px;">RD$ ${monto}</td>
                    </tr>
                `;
            });
        }

        const fechaEsp = ord.fecha_esperada ? new Date(ord.fecha_esperada).toLocaleDateString() : 'No definida';

        if (cuerpo) {
            cuerpo.innerHTML = `
                <!-- Cabecera Resumen -->
                <div style="background: #faf8f5; border: 1px solid #eae3d5; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                        <div>
                            <small style="color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase;">Proveedor</small>
                            <div style="font-weight: 700; color: var(--text-dark);">${ord.proveedor_nombre}</div>
                            <small style="color: var(--text-muted);">RNC: ${ord.proveedor_rnc || 'N/A'}</small>
                        </div>
                        <div>
                            <small style="color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase;">Estado de la Orden</small>
                            <div>
                                <span class="badge-estado" style="background: ${badge.bg}; color: ${badge.color}; border: 1px solid ${badge.border};">
                                    ${badge.label}
                                </span>
                            </div>
                        </div>
                        <div>
                            <small style="color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase;">Fecha Esperada</small>
                            <div style="font-weight: 600;">${fechaEsp}</div>
                        </div>
                        <div>
                            <small style="color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase;">Emitido Por</small>
                            <div style="font-weight: 600;">${ord.usuario_nombre_completo || ord.usuario_creador}</div>
                        </div>
                        <div>
                            <small style="color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase;">Monto Estimado</small>
                            <div style="font-size: 1.15rem; font-weight: 700; color: var(--accent-gold); font-family: var(--font-mono);">
                                RD$ ${parseFloat(ord.monto_total_estimado).toFixed(2)}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Líneas de Detalle -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <span style="font-size: 0.75rem; font-weight: 700; color: var(--accent-gold); text-transform: uppercase;">
                        Líneas de la Orden y Avance de Recepción
                    </span>
                    <span style="font-size: 0.75rem; color: var(--text-muted);">
                        Pendientes: <strong>${sumaPendiente}</strong> de ${sumaOrdenada} u.
                    </span>
                </div>

                <div style="border: 1px solid #e2dacb; border-radius: 8px; overflow: hidden; background: #ffffff;">
                    <table class="table-compacta-orden">
                        <thead>
                            <tr>
                                <th style="width: 44%;">Producto</th>
                                <th style="width: 13%; text-align: center;">Cant. Ordenada</th>
                                <th style="width: 13%; text-align: center;">Cant. Recibida</th>
                                <th style="width: 14%; text-align: center;">Cant. Pendiente</th>
                                <th style="width: 16%; text-align: right;">Costo Pactado</th>
                                <th style="width: 16%; text-align: right;">Subtotal Estimado</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${lineasHtml}
                        </tbody>
                        <tfoot>
                            <tr style="background: #faf6ee; border-top: 1px solid #e5d8c3; font-weight: 700;">
                                <td style="padding: 8px 10px;">TOTALES</td>
                                <td class="text-center" style="font-family: var(--font-mono);">${sumaOrdenada}</td>
                                <td class="text-center" style="font-family: var(--font-mono); color: #2e7d52;">${sumaRecibida}</td>
                                <td class="text-center" style="font-family: var(--font-mono); color: ${sumaPendiente > 0 ? '#b78103' : '#8c8173'};">${sumaPendiente}</td>
                                <td></td>
                                <td class="text-right" style="font-family: var(--font-mono); color: var(--accent-gold); padding: 8px 10px;">
                                    RD$ ${parseFloat(ord.monto_total_estimado).toFixed(2)}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Recepciones / Facturas Registradas -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 18px; margin-bottom: 6px;">
                    <span style="font-size: 0.75rem; font-weight: 700; color: var(--accent-gold); text-transform: uppercase;">
                        Facturas Físicas y Recepciones Check-in Vinculadas
                    </span>
                    <span style="font-size: 0.75rem; color: var(--text-muted);">
                        Total Recepciones: <strong>${recepciones.length}</strong>
                    </span>
                </div>

                <div style="border: 1px solid #e2dacb; border-radius: 8px; overflow: hidden; background: #ffffff;">
                    <table class="table-compacta-orden">
                        <thead>
                            <tr>
                                <th style="width: 12%;"># Recepción</th>
                                <th style="width: 18%;">N° Factura Física</th>
                                <th style="width: 16%;">NCF Suplidor</th>
                                <th style="width: 12%; text-align: center;">Condición</th>
                                <th style="width: 20%;">Fecha Recepción</th>
                                <th style="width: 12%; text-align: center;">Mercancía</th>
                                <th style="width: 16%; text-align: right;">Monto Facturado</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${recepcionesHtml}
                        </tbody>
                    </table>
                </div>
            `;
        }

        // Botón de recepción directa en el footer
        if (footer) {
            let botonRecibirHtml = '';
            if (['PENDIENTE', 'RECIBIDA_PARCIAL'].includes(ord.estado)) {
                botonRecibirHtml = `
                    <a href="../recepciones/recepciones.php?orden_id=${ord.id}" class="btn btn-gold" style="text-decoration: none;">
                        📥 Recibir Mercancía Ahora
                    </a>
                `;
            }
            footer.innerHTML = `
                <button type="button" class="btn btn-secondary" onclick="cerrarModalDetalle()">Cerrar</button>
                ${botonRecibirHtml}
            `;
        }

    } catch (e) {
        if (cuerpo) {
            cuerpo.innerHTML = `<div style="text-align: center; padding: 30px; color: #c62828;">❌ Error al consultar orden: ${e.message}</div>`;
        }
    }
}

/**
 * Cierre del modal de detalle
 */
function cerrarModalDetalle() {
    const modal = document.getElementById('modalDetalleOrden');
    if (modal) {
        modal.classList.remove('open');
    }
}
