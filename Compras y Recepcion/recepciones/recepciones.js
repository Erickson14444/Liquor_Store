/**
 * ============================================================================
 * JavaScript: Recepciones de Compra (Entregas Parciales y Multi-Lote)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/recepciones/recepciones.js
 * ============================================================================
 */

let ORDEN_ACTUAL = null;
let LINEAS_ORDEN_ACTUAL = [];

document.addEventListener('DOMContentLoaded', function () {
    // Si viene orden_id preseleccionada desde la URL (por ejemplo desde Órdenes de Compra)
    if (typeof ORDEN_ID_INICIAL !== 'undefined' && ORDEN_ID_INICIAL > 0) {
        const select = document.getElementById('selectOrdenCompra');
        if (select) {
            select.value = ORDEN_ID_INICIAL;
            alCambiarOrdenSeleccionada();
        }
    }
});

/**
 * Actualiza la nota informativa según la condición de pago
 */
function actualizarNotaCondicionPago() {
    const radios = document.getElementsByName('condicion_pago');
    const nota = document.getElementById('notaCondicionPago');
    if (!nota) return;

    let valor = 'CREDITO';
    for (const r of radios) {
        if (r.checked) {
            valor = r.value;
            break;
        }
    }

    if (valor === 'CONTADO') {
        nota.textContent = 'ℹ️ Contado: Se creará la cuenta por pagar con vencimiento de hoy (liquidación inmediata).';
        nota.style.color = '#2e7d52';
    } else {
        const dias = (ORDEN_ACTUAL && ORDEN_ACTUAL.dias_credito_habitual) ? ORDEN_ACTUAL.dias_credito_habitual : 30;
        nota.textContent = `ℹ️ Crédito: Se creará la cuenta por pagar con vencimiento a ${dias} días (según acuerdo con el suplidor).`;
        nota.style.color = '#1976d2';
    }
}

/**
 * Evento al seleccionar una orden de compra en el dropdown
 */
function alCambiarOrdenSeleccionada() {
    const select = document.getElementById('selectOrdenCompra');
    const ordenId = parseInt(select ? select.value : 0);

    if (!ordenId) {
        ORDEN_ACTUAL = null;
        LINEAS_ORDEN_ACTUAL = [];
        document.getElementById('cajaInfoOrden').style.display = 'none';
        document.getElementById('cajaTotalesRecepcion').style.display = 'none';
        document.getElementById('contenedorLineasRecepcion').innerHTML = `
            <div style="text-align: center; padding: 24px; color: var(--text-muted); background: #fbf9f5; border: 1px dashed #e2dacb; border-radius: 8px;">
                Seleccione una orden de compra arriba para cargar los productos con saldo pendiente.
            </div>
        `;
        return;
    }

    cargarLineasOrden(ordenId);
}

/**
 * Consulta vía Fetch únicamente las líneas con cantidad_pendiente > 0
 */
async function cargarLineasOrden(ordenId) {
    const contenedor = document.getElementById('contenedorLineasRecepcion');
    const cajaInfo = document.getElementById('cajaInfoOrden');
    const cajaTotales = document.getElementById('cajaTotalesRecepcion');

    if (contenedor) {
        contenedor.innerHTML = '<div style="text-align: center; padding: 30px; color: var(--text-muted);"><div style="font-size: 1.5rem; margin-bottom: 8px;">⏳</div>Consultando líneas pendientes de la orden...</div>';
    }

    try {
        const resp = await fetch(`procesar_recepciones.php?accion=obtener_lineas_orden&orden_id=${ordenId}`);
        const data = await resp.json();

        if (!data || !data.ok) {
            throw new Error(data.mensaje || 'Error al obtener líneas pendientes de la orden.');
        }

        ORDEN_ACTUAL = data.orden;
        LINEAS_ORDEN_ACTUAL = data.lineas || [];

        // Actualizar tarjeta resumen del proveedor
        if (cajaInfo) {
            document.getElementById('infoOrdenProveedor').textContent = ORDEN_ACTUAL.proveedor_nombre || '--';
            document.getElementById('infoOrdenRnc').textContent = `RNC: ${ORDEN_ACTUAL.proveedor_rnc || 'N/A'} | Días Crédito: ${ORDEN_ACTUAL.dias_credito_habitual || 30}`;
            cajaInfo.style.display = 'block';
        }

        actualizarNotaCondicionPago();

        // Renderizar productos
        renderizarLineasMultiLote(LINEAS_ORDEN_ACTUAL);

        if (cajaTotales) {
            cajaTotales.style.display = 'block';
        }

    } catch (e) {
        if (contenedor) {
            contenedor.innerHTML = `<div style="text-align: center; padding: 24px; color: #c62828; background: #ffebee; border-radius: 8px;">❌ ${e.message}</div>`;
        }
        if (cajaInfo) cajaInfo.style.display = 'none';
        if (cajaTotales) cajaTotales.style.display = 'none';
    }
}

/**
 * Renderiza el detalle multi-lote por cada producto de la orden
 */
function renderizarLineasMultiLote(lineas) {
    const contenedor = document.getElementById('contenedorLineasRecepcion');
    if (!contenedor) return;

    if (!lineas || lineas.length === 0) {
        contenedor.innerHTML = '<div style="text-align: center; padding: 24px; color: #2e7d52; background: #e8f8f0; border-radius: 8px;">✅ Todos los productos de esta orden de compra ya han sido recibidos en su totalidad.</div>';
        return;
    }

    let html = '';

    lineas.forEach((prod, pIdx) => {
        const prodId = prod.producto_maestro_id;
        const cantPendiente = parseInt(prod.cantidad_pendiente || 0);
        const costoPactado = parseFloat(prod.costo_pactado_unitario || 0).toFixed(2);

        html += `
            <div class="card-producto-recepcion" data-producto-id="${prodId}" data-pendiente="${cantPendiente}" id="cardProd_${prodId}">
                <!-- Cabecera del Producto -->
                <div class="card-producto-header">
                    <div>
                        <strong style="color: var(--text-dark); font-size: 0.95rem;">${prod.producto_nombre}</strong>
                        <span style="font-family: var(--font-mono); font-size: 0.75rem; color: var(--text-muted); margin-left: 6px;">[SKU: ${prod.sku}]</span>
                    </div>

                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span class="badge-pendiente">Pendiente: ${cantPendiente} u.</span>
                        <span style="font-size: 0.8rem; color: #6e6254;">Costo Pactado: <strong>RD$ ${costoPactado}</strong></span>
                        <button type="button" class="btn btn-sm btn-outline" onclick="dividirLoteProducto(${prodId}, ${costoPactado})">
                            + Dividir Lote
                        </button>
                    </div>
                </div>

                <!-- Tabla de Lotes del Producto -->
                <div style="padding: 10px 14px;">
                    <table style="width: 100%; border-collapse: collapse; table-layout: fixed; border: 1px solid var(--border-light); border-radius: 6px; overflow: hidden;">
                        <thead>
                            <tr style="background: #f6efe4; border-bottom: 1px solid #e4d5be;">
                                <th style="width: 17%; text-align: center; padding: 8px 6px; font-size: 0.72rem; font-weight: 700; color: #5a3e24; text-transform: uppercase;">Cant. Recibida *</th>
                                <th style="width: 18%; text-align: right; padding: 8px 6px; font-size: 0.72rem; font-weight: 700; color: #5a3e24; text-transform: uppercase;">Costo Facturado *</th>
                                <th style="width: 25%; text-align: left; padding: 8px 8px; font-size: 0.72rem; font-weight: 700; color: #5a3e24; text-transform: uppercase;">Número de Lote *</th>
                                <th style="width: 20%; text-align: left; padding: 8px 6px; font-size: 0.72rem; font-weight: 700; color: #5a3e24; text-transform: uppercase;">Vencimiento *</th>
                                <th style="width: 15%; text-align: right; padding: 8px 8px; font-size: 0.72rem; font-weight: 700; color: #5a3e24; text-transform: uppercase;">Subtotal</th>
                                <th style="width: 5%; text-align: center; padding: 8px 2px;"></th>
                            </tr>
                        </thead>
                        <tbody class="tbody-lotes-producto" data-producto-id="${prodId}">
                            <tr class="fila-lote" data-producto-id="${prodId}" style="border-bottom: 1px solid #f2ede4;">
                                <td style="padding: 6px;">
                                    <input type="number" class="form-control input-lote-cant text-center" min="0" max="${cantPendiente}" step="1" value="${cantPendiente}" placeholder="0" oninput="alCambiarDatoLote(${prodId})" style="height: 32px; font-size: 0.85rem; padding: 2px 4px; font-family: var(--font-mono); font-weight: 600;" />
                                </td>
                                <td style="padding: 6px;">
                                    <input type="number" class="form-control input-lote-costo text-right" min="0.01" step="0.01" value="${costoPactado}" required oninput="alCambiarDatoLote(${prodId})" style="height: 32px; font-size: 0.85rem; padding: 2px 6px; font-family: var(--font-mono); font-weight: 600;" />
                                </td>
                                <td style="padding: 6px;">
                                    <input type="text" class="form-control input-lote-num" maxlength="50" placeholder="Ej: LOT-${new Date().getFullYear()}-01" oninput="alCambiarDatoLote(${prodId})" style="height: 32px; font-size: 0.85rem; padding: 2px 6px; font-family: var(--font-mono);" />
                                </td>
                                <td style="padding: 6px;">
                                    <input type="date" class="form-control input-lote-venc" oninput="alCambiarDatoLote(${prodId})" style="height: 32px; font-size: 0.82rem; padding: 2px 4px;" />
                                </td>
                                <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; padding: 6px 8px; font-size: 0.88rem; color: #211813;">
                                    <span class="subtotal-lote">RD$ 0.00</span>
                                </td>
                                <td class="text-center" style="padding: 6px 2px;">
                                    <!-- Primera fila fija -->
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Indicador y Alerta de Exceso -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                        <div style="font-size: 0.8rem; color: #5c5043;">
                            Total recibido para este producto: <strong class="lbl-total-recibido-prod">0</strong> de ${cantPendiente} u.
                        </div>
                        <div class="alerta-exceso" id="alertaExceso_${prodId}">
                            ⚠️ La suma recibida supera el saldo pendiente de ${cantPendiente} unidades.
                        </div>
                    </div>
                </div>
            </div>
        `;
    });

    contenedor.innerHTML = html;

    // Calcular valores iniciales de cada producto
    lineas.forEach(prod => {
        recalcularProducto(prod.producto_maestro_id);
    });

    calcularGranTotalRecepcion();
}

/**
 * Acción "+ Dividir Lote": agrega una nueva sub-fila para el mismo producto
 */
function dividirLoteProducto(productoId, costoPactado) {
    const tbody = document.querySelector(`.tbody-lotes-producto[data-producto-id="${productoId}"]`);
    if (!tbody) return;

    const tr = document.createElement('tr');
    tr.className = 'fila-lote fila-lote-dividida';
    tr.dataset.productoId = productoId;

    tr.style.borderBottom = '1px solid #f2ede4';
    tr.innerHTML = `
        <td style="padding: 6px;">
            <input type="number" class="form-control input-lote-cant text-center" min="0" step="1" value="0" placeholder="0" oninput="alCambiarDatoLote(${productoId})" style="height: 32px; font-size: 0.85rem; padding: 2px 4px; font-family: var(--font-mono); font-weight: 600;" />
        </td>
        <td style="padding: 6px;">
            <input type="number" class="form-control input-lote-costo text-right" min="0.01" step="0.01" value="${costoPactado}" required oninput="alCambiarDatoLote(${productoId})" style="height: 32px; font-size: 0.85rem; padding: 2px 6px; font-family: var(--font-mono); font-weight: 600;" />
        </td>
        <td style="padding: 6px;">
            <input type="text" class="form-control input-lote-num" maxlength="50" placeholder="Otro lote..." oninput="alCambiarDatoLote(${productoId})" style="height: 32px; font-size: 0.85rem; padding: 2px 6px; font-family: var(--font-mono);" />
        </td>
        <td style="padding: 6px;">
            <input type="date" class="form-control input-lote-venc" oninput="alCambiarDatoLote(${productoId})" style="height: 32px; font-size: 0.82rem; padding: 2px 4px;" />
        </td>
        <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; padding: 6px 8px; font-size: 0.88rem; color: #211813;">
            <span class="subtotal-lote">RD$ 0.00</span>
        </td>
        <td class="text-center" style="padding: 6px 2px;">
            <button type="button" class="btn btn-sm btn-danger" title="Eliminar este sub-lote" onclick="eliminarSubLote(this, ${productoId})" style="padding: 2px 6px; height: 28px; width: 28px; line-height: 1; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; border-radius: 4px;">✕</button>
        </td>
    `;

    tbody.appendChild(tr);
    alCambiarDatoLote(productoId);
}

/**
 * Elimina un sub-lote adicional
 */
function eliminarSubLote(btn, productoId) {
    const fila = btn.closest('tr');
    if (fila) {
        fila.remove();
        alCambiarDatoLote(productoId);
    }
}

/**
 * Evento disparado al modificar cualquier input en los lotes de un producto
 */
function alCambiarDatoLote(productoId) {
    recalcularProducto(productoId);
    calcularGranTotalRecepcion();
}

/**
 * Recalcula subtotales y valida techo de cantidad_pendiente para un producto
 */
function recalcularProducto(productoId) {
    const card = document.getElementById(`cardProd_${productoId}`);
    if (!card) return;

    const cantPendiente = parseInt(card.dataset.pendiente || 0);
    const filas = card.querySelectorAll('.fila-lote');
    const alertaExceso = document.getElementById(`alertaExceso_${productoId}`);
    const lblTotalProd = card.querySelector('.lbl-total-recibido-prod');

    let sumaCantProd = 0;

    filas.forEach(f => {
        const cantInp = f.querySelector('.input-lote-cant');
        const costoInp = f.querySelector('.input-lote-costo');
        const subtotalSpan = f.querySelector('.subtotal-lote');

        const cant = parseInt(cantInp ? cantInp.value : 0) || 0;
        const costo = parseFloat(costoInp ? costoInp.value : 0) || 0;

        sumaCantProd += cant;
        const subtotal = cant * costo;

        if (subtotalSpan) {
            subtotalSpan.textContent = 'RD$ ' + subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    });

    if (lblTotalProd) {
        lblTotalProd.textContent = sumaCantProd;
        lblTotalProd.style.color = (sumaCantProd > cantPendiente) ? '#c62828' : '#211813';
    }

    // Alerta visual de exceso
    if (alertaExceso) {
        if (sumaCantProd > cantPendiente) {
            alertaExceso.style.display = 'block';
            card.style.borderColor = '#ef9a9a';
        } else {
            alertaExceso.style.display = 'none';
            card.style.borderColor = '#eae3d5';
        }
    }
}

/**
 * Recalcula el resumen global de la recepción
 */
function calcularGranTotalRecepcion() {
    const filasLotes = document.querySelectorAll('.fila-lote');
    let totalUnidades = 0;
    let montoTotal = 0;
    const productosConMercancia = new Set();

    filasLotes.forEach(f => {
        const cantInp = f.querySelector('.input-lote-cant');
        const costoInp = f.querySelector('.input-lote-costo');

        const cant = parseInt(cantInp ? cantInp.value : 0) || 0;
        const costo = parseFloat(costoInp ? costoInp.value : 0) || 0;

        if (cant > 0) {
            totalUnidades += cant;
            montoTotal += (cant * costo);
            productosConMercancia.add(f.dataset.productoId);
        }
    });

    const lblUnidades = document.getElementById('lblTotalUnidadesRecepcion');
    const lblProds = document.getElementById('lblTotalProductosRecepcion');
    const lblMonto = document.getElementById('lblMontoTotalRecepcion');

    if (lblUnidades) lblUnidades.textContent = totalUnidades.toLocaleString();
    if (lblProds) lblProds.textContent = productosConMercancia.size;
    if (lblMonto) {
        lblMonto.textContent = 'RD$ ' + montoTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
}

/**
 * Guarda la recepción en la base de datos con validaciones estrictas
 */
async function guardarRecepcion(event) {
    event.preventDefault();

    const selectOrden = document.getElementById('selectOrdenCompra');
    const ordenId = parseInt(selectOrden ? selectOrden.value : 0);
    const numFactura = document.getElementById('inputNumFactura').value.trim();
    const ncf = document.getElementById('inputNcf').value.trim();
    const btnGuardar = document.getElementById('btnGuardarRecepcion');

    if (!ordenId) {
        alert('Debe seleccionar una orden de compra pendiente.');
        selectOrden.focus();
        return;
    }
    if (!numFactura) {
        alert('Debe ingresar el número de la factura física del proveedor.');
        document.getElementById('inputNumFactura').focus();
        return;
    }
    if (!ncf) {
        alert('Debe ingresar el NCF del proveedor para fines fiscales (DGII).');
        document.getElementById('inputNcf').focus();
        return;
    }

    let condicionPago = 'CREDITO';
    const radios = document.getElementsByName('condicion_pago');
    for (const r of radios) {
        if (r.checked) {
            condicionPago = r.value;
            break;
        }
    }

    // Validar líneas y multi-lote
    const cards = document.querySelectorAll('.card-producto-recepcion');
    const lineasPayload = [];
    let alMenosUnoRecibido = false;

    for (let c = 0; c < cards.length; c++) {
        const card = cards[c];
        const prodId = parseInt(card.dataset.productoId);
        const cantPendiente = parseInt(card.dataset.pendiente);
        const prodNombre = card.querySelector('.card-producto-header strong').textContent;

        const filasLote = card.querySelectorAll('.fila-lote');
        let sumaRecibidaProd = 0;
        const lotesVistosProd = new Set();

        for (let l = 0; l < filasLote.length; l++) {
            const f = filasLote[l];
            const cantInput = f.querySelector('.input-lote-cant');
            const costoInput = f.querySelector('.input-lote-costo');
            const loteInput = f.querySelector('.input-lote-num');
            const vencInput = f.querySelector('.input-lote-venc');

            const cant = parseInt(cantInput ? cantInput.value : 0) || 0;
            const costo = parseFloat(costoInput ? costoInput.value : 0) || 0;
            const lote = (loteInput ? loteInput.value : '').trim();
            const venc = (vencInput ? vencInput.value : '').trim();

            if (cant > 0) {
                alMenosUnoRecibido = true;
                sumaRecibidaProd += cant;

                // Validación de lote obligatorio
                if (!lote) {
                    alert(`El producto '${prodNombre}' tiene una cantidad de ${cant} unidades pero no tiene número de lote.`);
                    loteInput.focus();
                    return;
                }

                // REGLA: No permitir número de lote repetido para el mismo producto
                const loteKey = lote.toUpperCase();
                if (lotesVistosProd.has(loteKey)) {
                    alert(`El lote '${lote}' está duplicado para el producto '${prodNombre}'. Si dividió el producto en varios lotes, cada uno debe tener un número de lote distinto.`);
                    loteInput.focus();
                    return;
                }
                lotesVistosProd.add(loteKey);

                // Validación de vencimiento
                if (!venc) {
                    alert(`Debe ingresar la fecha de vencimiento para el lote '${lote}' del producto '${prodNombre}'.`);
                    vencInput.focus();
                    return;
                }

                if (costo <= 0) {
                    alert(`El costo unitario facturado para el producto '${prodNombre}' debe ser mayor a 0.`);
                    costoInput.focus();
                    return;
                }

                lineasPayload.push({
                    producto_maestro_id: prodId,
                    cantidad_recibida_buena: cant,
                    costo_unitario_facturado: costo,
                    numero_lote: lote,
                    fecha_vencimiento: venc
                });
            }
        }

        // REGLA: La suma de cantidad_recibida_buena de todos los lotes de un producto NO puede exceder su cantidad_pendiente
        if (sumaRecibidaProd > cantPendiente) {
            alert(`⚠️ ERROR: Para el producto '${prodNombre}' ha ingresado ${sumaRecibidaProd} unidades en total, lo cual excede su saldo pendiente de ${cantPendiente} unidades.`);
            return;
        }
    }

    // REGLA: Exigir que al menos una línea tenga cantidad_recibida_buena > 0 para guardar
    if (!alMenosUnoRecibido || lineasPayload.length === 0) {
        alert('Debe ingresar al menos una línea con cantidad recibida mayor a 0 para registrar la recepción.');
        return;
    }

    if (!confirm(`¿Confirma registrar la Recepción de Mercancía?\nFactura: ${numFactura}\nNCF: ${ncf}\nCondición: ${condicionPago}\nTotal Líneas de Lote: ${lineasPayload.length}`)) {
        return;
    }

    if (btnGuardar) {
        btnGuardar.disabled = true;
        btnGuardar.textContent = 'Procesando entrada a inventario...';
    }

    try {
        const payload = {
            accion: 'registrar_recepcion',
            orden_compra_id: ordenId,
            numero_factura_proveedor: numFactura,
            ncf_proveedor: ncf,
            condicion_pago: condicionPago,
            lineas: lineasPayload
        };

        const resp = await fetch('procesar_recepciones.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const data = await resp.json();

        if (data && data.ok) {
            alert('✅ ' + (data.mensaje || 'Recepción registrada exitosamente.'));
            window.location.href = 'recepciones.php';
        } else {
            alert('❌ Error al procesar la recepción: ' + (data.mensaje || 'Error del servidor.'));
        }
    } catch (e) {
        console.error('Error:', e);
        alert('❌ Error de comunicación con el servidor: ' + e.message);
    } finally {
        if (btnGuardar) {
            btnGuardar.disabled = false;
            btnGuardar.textContent = '💾 Registrar Recepción y Entrada a Inventario';
        }
    }
}

/**
 * Consulta y muestra el comprobante fiscal y físico de una recepción histórica
 */
async function verComprobanteRecepcion(recepcionId) {
    const modal = document.getElementById('modalVerRecepcion');
    const cuerpo = document.getElementById('cuerpoVerRecepcion');
    const titulo = document.getElementById('recModalTitulo');

    if (modal) modal.classList.add('open');
    if (cuerpo) {
        cuerpo.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-muted);"><div style="font-size: 1.5rem; margin-bottom: 8px;">⏳</div>Cargando comprobante...</div>';
    }

    try {
        const resp = await fetch(`procesar_recepciones.php?accion=obtener_detalle_recepcion&recepcion_id=${recepcionId}`);
        const data = await resp.json();

        if (!data || !data.ok) {
            throw new Error(data.mensaje || 'No se pudo cargar el comprobante.');
        }

        const rec = data.recepcion;
        const det = data.detalles || [];
        const cxp = data.cxp || null;

        if (titulo) {
            titulo.textContent = `Comprobante de Recepción #${rec.id} — Factura ${rec.numero_factura_proveedor}`;
        }

        let lineasHtml = '';
        let totalUnidades = 0;
        let totalCosto = 0;

        det.forEach(d => {
            const cant = parseInt(d.cantidad_recibida_buena || 0);
            const costo = parseFloat(d.costo_unitario_facturado || 0);
            const subtotal = parseFloat(d.subtotal_linea || 0);

            totalUnidades += cant;
            totalCosto += subtotal;

            lineasHtml += `
                <tr>
                    <td>
                        <strong>${d.producto_nombre}</strong><br />
                        <small style="color: var(--text-muted); font-family: var(--font-mono);">${d.sku}</small>
                    </td>
                    <td style="font-family: var(--font-mono); font-weight: 700; color: var(--accent-gold);">${d.numero_lote}</td>
                    <td>${d.fecha_vencimiento}</td>
                    <td class="text-center" style="font-family: var(--font-mono); font-weight: 600;">${cant}</td>
                    <td class="text-right" style="font-family: var(--font-mono);">RD$ ${costo.toFixed(2)}</td>
                    <td class="text-right" style="font-family: var(--font-mono); font-weight: 700;">RD$ ${subtotal.toFixed(2)}</td>
                </tr>
            `;
        });

        // Bloque de cuenta por pagar
        let cxpHtml = '';
        if (cxp) {
            cxpHtml = `
                <div style="background: #fdfcf9; border: 1px solid #e7dcc8; border-radius: 8px; padding: 12px 16px; margin-top: 16px;">
                    <strong style="color: #6e6254; font-size: 0.85rem; text-transform: uppercase;">Cuenta por Pagar Generada (Formato 606)</strong>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin-top: 6px; font-size: 0.85rem;">
                        <div>ID Deuda: <strong>#${cxp.id}</strong></div>
                        <div>Monto Deuda: <strong>RD$ ${parseFloat(cxp.monto_original).toFixed(2)}</strong></div>
                        <div>Saldo Pendiente: <strong>RD$ ${parseFloat(cxp.saldo_pendiente).toFixed(2)}</strong></div>
                        <div>Fecha Vencimiento: <strong style="color: #b78103;">${cxp.fecha_vencimiento}</strong></div>
                        <div>Estado: <span style="font-weight: 700; color: #2e7d32;">${cxp.estado}</span></div>
                    </div>
                </div>
            `;
        }

        if (cuerpo) {
            cuerpo.innerHTML = `
                <!-- Datos Generales -->
                <div style="background: #faf8f5; border: 1px solid #eae3d5; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                        <div>
                            <small style="color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase;">Proveedor</small>
                            <div style="font-weight: 700;">${rec.proveedor_nombre}</div>
                            <small style="color: var(--text-muted);">RNC: ${rec.proveedor_rnc || 'N/A'}</small>
                        </div>
                        <div>
                            <small style="color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase;">Factura Física / NCF</small>
                            <div style="font-weight: 700; font-family: var(--font-mono);">${rec.numero_factura_proveedor}</div>
                            <small style="font-family: var(--font-mono); color: var(--accent-gold);">NCF: ${rec.ncf_proveedor || 'N/A'}</small>
                        </div>
                        <div>
                            <small style="color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase;">Orden de Compra</small>
                            <div style="font-weight: 600; font-family: var(--font-mono);">${rec.numero_orden || 'Sin Orden'}</div>
                        </div>
                        <div>
                            <small style="color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase;">Condición de Pago</small>
                            <div>
                                <span style="font-size: 0.72rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: ${rec.condicion_pago === 'CONTADO' ? '#e8f5e9' : '#e3f2fd'}; color: ${rec.condicion_pago === 'CONTADO' ? '#2e7d32' : '#1565c0'};">
                                    ${rec.condicion_pago}
                                </span>
                            </div>
                        </div>
                        <div>
                            <small style="color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase;">Recibido Por / Fecha</small>
                            <div style="font-weight: 600;">${rec.receptor_nombre_completo || rec.usuario_receptor}</div>
                            <small style="color: var(--text-muted);">${new Date(rec.fecha_recepcion).toLocaleString()}</small>
                        </div>
                    </div>
                </div>

                <!-- Lotes e Inventario -->
                <div class="form-section-bar">
                    <span class="form-section-label">Lotes Ingresados al Inventario (FEFO)</span>
                    <span style="font-size: 0.75rem; color: var(--text-muted);">${det.length} lote(s) registrado(s)</span>
                </div>

                <div class="table-wrapper" style="margin-top: 6px; border: 1px solid var(--border-light); border-radius: 6px;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Número de Lote</th>
                                <th>Fecha Vencimiento</th>
                                <th class="text-center">Cant. Recibida</th>
                                <th class="text-right">Costo Facturado</th>
                                <th class="text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${lineasHtml}
                        </tbody>
                        <tfoot>
                            <tr style="background: #fdfcf9; font-weight: 700;">
                                <td colspan="3">TOTALES FACTURADOS</td>
                                <td class="text-center" style="font-family: var(--font-mono);">${totalUnidades}</td>
                                <td></td>
                                <td class="text-right" style="font-family: var(--font-mono); color: var(--accent-gold);">
                                    RD$ ${parseFloat(rec.monto_total_factura).toFixed(2)}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                ${cxpHtml}
            `;
        }

    } catch (e) {
        if (cuerpo) {
            cuerpo.innerHTML = `<div style="text-align: center; padding: 30px; color: #c62828;">❌ Error al cargar comprobante: ${e.message}</div>`;
        }
    }
}

/**
 * Cierre del modal de recepción
 */
function cerrarModalRecepcion() {
    const modal = document.getElementById('modalVerRecepcion');
    if (modal) {
        modal.classList.remove('open');
    }
}
