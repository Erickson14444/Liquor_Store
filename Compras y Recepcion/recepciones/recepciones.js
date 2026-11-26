/**
 * ============================================================================
 * JavaScript: Recepciones de Compra (Directas y con Orden de Compra Multi-Lote)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Compras y Recepcion/recepciones/recepciones.js
 * ============================================================================
 */

let ORDEN_ACTUAL = null;
const MAPA_CATALOGO_CACHE = new Map();
const MAPA_PRODUCTOS_RECEPCION = new Map();

/**
 * Muestra un modal de mensaje o confirmación centrado, compacto y con el estilo visual institucional de House & Ice
 */
function mostrarMensajeModal(opciones = {}) {
    const {
        tipo = 'info',
        titulo = '',
        mensaje = '',
        detalle = '',
        esConfirmacion = false,
        textoBoton = 'Aceptar',
        textoCancelar = 'Cancelar',
        alConfirmar = null,
        alCerrar = null
    } = opciones;

    let modal = document.getElementById('modalMensajeSistema');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'modalMensajeSistema';
        modal.className = 'modal-overlay';
        modal.style.cssText = 'display: none; position: fixed; inset: 0; z-index: 99999; align-items: center; justify-content: center; background: rgba(20, 14, 9, 0.65); backdrop-filter: blur(4px);';
        modal.innerHTML = `
            <div class="modal-box" style="max-width: 390px; width: 90%; background: #ffffff; border-radius: 14px; border: 1.5px solid #d4c1a7; box-shadow: 0 18px 45px rgba(28, 18, 12, 0.35); padding: 0; overflow: hidden; animation: modalPop 0.22s ease-out; margin: auto; text-align: center;">
                <div id="msgModalIconoCont" style="padding-top: 22px;"></div>
                <div style="padding: 6px 22px 18px;">
                    <h3 id="msgModalTitulo" style="margin: 0 0 8px 0; font-family: Georgia, serif; font-size: 1.18rem; color: #211813; font-weight: 700;"></h3>
                    <div id="msgModalTexto" style="font-size: 0.9rem; color: #554436; line-height: 1.5;"></div>
                    <div id="msgModalDetalle" style="margin-top: 10px; font-size: 0.82rem; color: #735d48; background: #faf5ee; border: 1px solid #ebdcc5; border-radius: 6px; padding: 7px 10px; display: none;"></div>
                </div>
                <div id="msgModalFooter" style="background: #fbf8f3; border-top: 1px solid #ebdcc5; padding: 12px 20px; display: flex; justify-content: center; gap: 10px;">
                    <button type="button" class="btn btn-secondary" id="msgModalBtnCancel" style="display: none; padding: 8px 18px; font-weight: 600; border-radius: 6px; font-size: 0.9rem;">
                        Cancelar
                    </button>
                    <button type="button" class="btn btn-gold" id="msgModalBtnOk" style="min-width: 110px; padding: 8px 22px; font-weight: 700; border-radius: 6px; font-size: 0.9rem; box-shadow: 0 2px 6px rgba(166,110,46,0.25);">
                        Aceptar
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        if (!document.getElementById('styleModalPop')) {
            const st = document.createElement('style');
            st.id = 'styleModalPop';
            st.innerHTML = `
                @keyframes modalPop {
                    from { opacity: 0; transform: scale(0.92) translateY(6px); }
                    to { opacity: 1; transform: scale(1) translateY(0); }
                }
            `;
            document.head.appendChild(st);
        }
    }

    const iconoCont = document.getElementById('msgModalIconoCont');
    let tituloPorDefecto = 'Notificación';
    if (tipo === 'exito') {
        iconoCont.innerHTML = `<div style="width: 52px; height: 52px; border-radius: 50%; background: #eaf5ea; border: 2px solid #82c785; color: #2e7d32; display: flex; align-items: center; justify-content: center; font-size: 26px; font-weight: bold; margin: 0 auto;">✓</div>`;
        tituloPorDefecto = '¡Operación Exitosa!';
    } else if (tipo === 'error') {
        iconoCont.innerHTML = `<div style="width: 52px; height: 52px; border-radius: 50%; background: #fdeeee; border: 2px solid #f59e9e; color: #c62828; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: bold; margin: 0 auto;">✕</div>`;
        tituloPorDefecto = 'Atención';
    } else if (tipo === 'advertencia' || tipo === 'pregunta') {
        iconoCont.innerHTML = `<div style="width: 52px; height: 52px; border-radius: 50%; background: #fff8e6; border: 2px solid #f5cf7a; color: #b78103; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: bold; margin: 0 auto;">⚠️</div>`;
        tituloPorDefecto = esConfirmacion ? 'Confirmación' : 'Información Requerida';
    } else {
        iconoCont.innerHTML = `<div style="width: 52px; height: 52px; border-radius: 50%; background: #f5f0e8; border: 2px solid #d4c0a5; color: #7f5e39; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: bold; margin: 0 auto;">ℹ️</div>`;
        tituloPorDefecto = 'Aviso del Sistema';
    }

    document.getElementById('msgModalTitulo').textContent = titulo || tituloPorDefecto;
    document.getElementById('msgModalTexto').innerHTML = (mensaje || '').replace(/\n/g, '<br>');

    const detalleDiv = document.getElementById('msgModalDetalle');
    if (detalle && detalle.trim() !== '') {
        detalleDiv.innerHTML = detalle.replace(/\n/g, '<br>');
        detalleDiv.style.display = 'block';
    } else {
        detalleDiv.style.display = 'none';
        detalleDiv.innerHTML = '';
    }

    const btnOk = document.getElementById('msgModalBtnOk');
    const btnCancel = document.getElementById('msgModalBtnCancel');

    btnOk.textContent = textoBoton || (esConfirmacion ? 'Confirmar' : 'Aceptar');

    if (esConfirmacion) {
        btnCancel.style.display = 'inline-block';
        btnCancel.textContent = textoCancelar || 'Cancelar';
        btnCancel.onclick = function () {
            modal.style.display = 'none';
        };
        btnOk.onclick = function () {
            modal.style.display = 'none';
            if (typeof alConfirmar === 'function') {
                alConfirmar();
            }
        };
    } else {
        btnCancel.style.display = 'none';
        btnOk.onclick = function () {
            modal.style.display = 'none';
            if (typeof alCerrar === 'function') {
                alCerrar();
            }
        };
    }

    modal.style.display = 'flex';
    btnOk.focus();
}

document.addEventListener('DOMContentLoaded', function () {
    // 1. Precargar caché de catálogo
    if (typeof CATALOGO_PRODUCTOS !== 'undefined' && Array.isArray(CATALOGO_PRODUCTOS)) {
        CATALOGO_PRODUCTOS.forEach(p => {
            if (p && p.id) MAPA_CATALOGO_CACHE.set(parseInt(p.id, 10), p);
        });
    }

    // 2. Renderizar panel inicial de catálogo
    buscarProductosCatalogo('');

    // 3. Si viene orden preseleccionada por GET
    if (typeof ORDEN_ID_INICIAL !== 'undefined' && ORDEN_ID_INICIAL > 0) {
        importarOrdenDesdeModal(ORDEN_ID_INICIAL);
    }
});

/**
 * Abre el modal para registrar una nueva recepción
 */
function abrirModalNuevaRecepcion(limpiar = true) {
    if (limpiar) {
        iniciarRecepcionDirecta();
    }
    const modal = document.getElementById('modalNuevaRecepcion');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('open');
    }
}

/**
 * Cierra el modal de nueva recepción
 */
function cerrarModalNuevaRecepcion() {
    const modal = document.getElementById('modalNuevaRecepcion');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('open');
    }
}

/**
 * Filtro de búsqueda en la tabla de historial de recepciones
 */
function filtrarTablaRecepciones() {
    const input = document.getElementById('inputBuscarTablaRecepciones');
    const query = (input ? input.value : '').toLowerCase().trim();
    const rows = document.querySelectorAll('#tablaHistorialRecepciones tbody tr');
    let visibles = 0;
    rows.forEach(tr => {
        const txt = tr.dataset.texto || tr.textContent.toLowerCase();
        const match = txt.includes(query);
        tr.style.display = match ? '' : 'none';
        if (match) visibles++;
    });
    const badge = document.getElementById('badgeTotalRecepciones');
    if (badge) badge.textContent = `${visibles} registros`;
}

/**
 * Inicia una nueva recepción directa (sin orden de compra obligatoria)
 */
function iniciarRecepcionDirecta() {
    ORDEN_ACTUAL = null;
    MAPA_PRODUCTOS_RECEPCION.clear();

    const inpOrden = document.getElementById('inputOrdenCompraId');
    if (inpOrden) inpOrden.value = '';

    limpiarProveedorSeleccionadoRecep();

    const selAlm = document.getElementById('selectAlmacenRecepcion');
    if (selAlm && selAlm.options.length > 0) selAlm.selectedIndex = 0;

    const banner = document.getElementById('bannerOrdenImportada');
    if (banner) banner.style.display = 'none';

    const cajaInfo = document.getElementById('cajaInfoOrden');
    if (cajaInfo) cajaInfo.style.display = 'none';

    const badgeModo = document.getElementById('badgeModoRecepcion');
    if (badgeModo) {
        badgeModo.textContent = 'RECEPCIÓN DIRECTA (SIN ORDEN)';
        badgeModo.style.color = '#7f5e39';
        badgeModo.style.background = '#fdf5ea';
    }

    const numFac = document.getElementById('inputNumFactura');
    if (numFac) numFac.value = '';

    const ncf = document.getElementById('inputNcf');
    if (ncf) ncf.value = '';

    const radioCredito = document.querySelector('input[name="condicion_pago"][value="CREDITO"]');
    if (radioCredito) radioCredito.checked = true;

    const selMet = document.getElementById('selectMetodoPagoContado');
    if (selMet && selMet.options.length > 0) selMet.selectedIndex = 0;

    const inpRefContado = document.getElementById('inputRefPagoContado');
    if (inpRefContado) inpRefContado.value = '';

    const inpUlt4 = document.getElementById('inputUlt4TarjetaContado');
    if (inpUlt4) inpUlt4.value = '';
    const inpAut = document.getElementById('inputAutTarjetaContado');
    if (inpAut) inpAut.value = '';

    const inputBuscar = document.getElementById('inputBuscarCatalogo');
    if (inputBuscar) inputBuscar.value = '';

    renderizarContenedorProductos();
    buscarProductosCatalogo('');
    actualizarNotaCondicionPago();
    calcularGranTotalRecepcion();
}

// ============================================================================
// SELECTOR BUSCABLE DE PROVEEDORES (CHECK-IN / RECEPCIÓN)
// ============================================================================
function abrirDropdownProveedoresRecep() {
    filtrarProveedoresRecep(document.getElementById('inputBuscarProveedorRecep')?.value || '');
    const dd = document.getElementById('dropdownProveedoresRecep');
    if (dd) dd.style.display = 'block';
}

function filtrarProveedoresRecep(query) {
    const dd = document.getElementById('dropdownProveedoresRecep');
    if (!dd) return;

    const lista = (typeof PROVEEDORES_LISTA !== 'undefined' && Array.isArray(PROVEEDORES_LISTA)) ? PROVEEDORES_LISTA : [];
    const q = (query || '').toLowerCase().trim();

    const filtrados = q === ''
        ? lista.slice(0, 30)
        : lista.filter(p => {
            const nom = (p.nombre_razon_social || '').toLowerCase();
            const rnc = (p.rnc || p.documento_identidad || '').toLowerCase();
            return nom.includes(q) || rnc.includes(q);
        }).slice(0, 30);

    if (filtrados.length === 0) {
        dd.innerHTML = '<div style="padding: 10px 14px; text-align: center; color: var(--text-muted); font-size: 0.80rem;">No se encontraron proveedores coincidentes.</div>';
        dd.style.display = 'block';
        return;
    }

    let html = '';
    filtrados.forEach(p => {
        const rnc = p.rnc || p.documento_identidad || 'S/RNC';
        const dias = p.dias_credito_habitual || 0;
        html += `
            <div class="recep-prov-option" onclick="seleccionarProveedorRecep('${p.persona_id}')" style="padding: 8px 12px; border-bottom: 1px solid #f2ede4; cursor: pointer; transition: background 0.15s ease;" onmouseover="this.style.background='#faf5eb'" onmouseout="this.style.background='#ffffff'">
                <div style="font-weight: 700; color: #211813; font-size: 0.84rem;">${p.nombre_razon_social}</div>
                <div style="display: flex; gap: 10px; font-size: 0.73rem; color: #7a6b5d; margin-top: 2px;">
                    <span>RNC: <strong>${rnc}</strong></span>
                    <span>•</span>
                    <span style="color: #2e7d52; font-weight: 600;">Crédito: ${dias} días</span>
                </div>
            </div>
        `;
    });
    dd.innerHTML = html;
    dd.style.display = 'block';
}

function seleccionarProveedorRecep(personaId, bloqueado = false) {
    const lista = (typeof PROVEEDORES_LISTA !== 'undefined' && Array.isArray(PROVEEDORES_LISTA)) ? PROVEEDORES_LISTA : [];
    const prv = lista.find(p => String(p.persona_id) === String(personaId));
    if (!prv) return;

    const inpHidden = document.getElementById('selectProveedorRecepcion');
    if (inpHidden) inpHidden.value = prv.persona_id;

    const boxBusq = document.getElementById('boxBusquedaProveedorRecep');
    const badge = document.getElementById('badgeProveedorElegidoRecep');
    const txtNom = document.getElementById('txtNombreProvRecep');
    const txtDet = document.getElementById('txtDetalleProvRecep');
    const btnCambiar = document.getElementById('btnCambiarProvRecep');
    const dd = document.getElementById('dropdownProveedoresRecep');
    const lblInfo = document.getElementById('infoProveedorRecepcion');

    if (txtNom) txtNom.textContent = prv.nombre_razon_social;
    const rnc = prv.rnc || prv.documento_identidad || 'S/RNC';
    const dias = prv.dias_credito_habitual || 0;
    if (txtDet) txtDet.textContent = `(RNC: ${rnc} • ${dias} d. crédito)`;

    if (btnCambiar) btnCambiar.style.display = bloqueado ? 'none' : 'inline-block';
    if (boxBusq) boxBusq.style.display = 'none';
    if (badge) badge.style.display = 'flex';
    if (dd) dd.style.display = 'none';

    if (lblInfo) {
        lblInfo.textContent = `Días crédito habitual: ${dias} días | RNC: ${rnc}`;
        lblInfo.style.color = '#2e7d52';
    }
    actualizarNotaCondicionPago();
}

function limpiarProveedorSeleccionadoRecep() {
    const inpHidden = document.getElementById('selectProveedorRecepcion');
    if (inpHidden) inpHidden.value = '';

    const boxBusq = document.getElementById('boxBusquedaProveedorRecep');
    const badge = document.getElementById('badgeProveedorElegidoRecep');
    const input = document.getElementById('inputBuscarProveedorRecep');
    const dd = document.getElementById('dropdownProveedoresRecep');
    const lblInfo = document.getElementById('infoProveedorRecepcion');

    if (boxBusq) boxBusq.style.display = 'block';
    if (badge) badge.style.display = 'none';
    if (dd) dd.style.display = 'none';
    if (input) input.value = '';

    if (lblInfo) {
        lblInfo.textContent = 'Días habituales: --';
        lblInfo.style.color = 'var(--text-muted)';
    }
    actualizarNotaCondicionPago();
}

// Cerrar dropdown de proveedor al hacer clic fuera
document.addEventListener('click', (e) => {
    const box = document.getElementById('boxBusquedaProveedorRecep');
    const dd = document.getElementById('dropdownProveedoresRecep');
    if (box && dd && !box.contains(e.target)) {
        dd.style.display = 'none';
    }
});

function alCambiarProveedorManual() {
    actualizarInfoProveedorManual();
    actualizarNotaCondicionPago();
}

function actualizarInfoProveedorManual() {
    const hidden = document.getElementById('selectProveedorRecepcion');
    const lbl = document.getElementById('infoProveedorRecepcion');
    if (!hidden || !lbl) return;

    const provId = hidden.value;
    const lista = (typeof PROVEEDORES_LISTA !== 'undefined' && Array.isArray(PROVEEDORES_LISTA)) ? PROVEEDORES_LISTA : [];
    const p = lista.find(item => String(item.persona_id) === String(provId));

    if (p) {
        const dias = p.dias_credito_habitual || 0;
        const rnc = p.rnc || p.documento_identidad || 'N/A';
        lbl.textContent = `Días crédito habitual: ${dias} días | RNC: ${rnc}`;
        lbl.style.color = '#2e7d52';
    } else {
        lbl.textContent = 'Días habituales: --';
        lbl.style.color = 'var(--text-muted)';
    }
}

/**
 * Muestra u oculta el panel de liquidación inmediata según la condición de pago (CONTADO vs CRÉDITO)
 */
function actualizarNotaCondicionPago() {
    const radios = document.getElementsByName('condicion_pago');
    let valor = 'CREDITO';
    for (const r of radios) {
        if (r.checked) {
            valor = r.value;
            break;
        }
    }

    const panel = document.getElementById('panelPagoContadoRecep');
    if (panel) {
        panel.style.display = (valor === 'CONTADO') ? 'block' : 'none';
    }
    alCambiarMetodoPagoContado();
}

/**
 * Muestra los campos bancarios/referencia cuando el pago al contado es Transferencia o Cheque
 */
function alCambiarMetodoPagoContado() {
    const sel = document.getElementById('selectMetodoPagoContado');
    const wrapBanco = document.getElementById('wrapBancoContado');
    const wrapRef = document.getElementById('wrapRefContado');
    const lblRef = document.getElementById('lblRefContado');
    const inpRef = document.getElementById('inputRefPagoContado');
    if (!sel || !wrapBanco || !wrapRef) return;

    const cod = String(sel.options[sel.selectedIndex]?.getAttribute('data-codigo') || 'EFECTIVO').toUpperCase();
    const esBanco = (cod === 'TRANSFERENCIA' || cod === 'CHEQUE');

    wrapBanco.style.display = esBanco ? 'block' : 'none';
    wrapRef.style.display = esBanco ? 'block' : 'none';

    if (lblRef) {
        lblRef.textContent = (cod === 'CHEQUE') ? 'No. Cheque *' : 'No. Referencia TRF *';
    }
    if (inpRef) {
        inpRef.placeholder = (cod === 'CHEQUE') ? 'Ej: CHQ-00125' : 'Ej: TRF-00491';
    }

    const wrapTarj = document.getElementById('wrapTarjetaContado');
    if (wrapTarj) {
        wrapTarj.style.display = (cod === 'TARJETA') ? 'grid' : 'none';
    }
}

/**
 * Abre el modal de Importar Orden de Compra
 */
function abrirModalImportarOrden() {
    const modal = document.getElementById('modalImportarOrdenCompra');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('open');
        const filtro = document.getElementById('filtroModalOrdenes');
        if (filtro) {
            filtro.value = '';
            filtrarOrdenesModal('');
            filtro.focus();
        }
    }
}

/**
 * Cierra el modal de Importar Orden de Compra
 */
function cerrarModalImportarOrden() {
    const modal = document.getElementById('modalImportarOrdenCompra');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('open');
    }
}

/**
 * Filtra las órdenes en el modal según búsqueda
 */
function filtrarOrdenesModal(query) {
    const q = (query || '').toLowerCase().trim();
    const filas = document.querySelectorAll('.fila-modal-orden');
    filas.forEach(f => {
        const txt = f.dataset.texto || '';
        f.style.display = txt.includes(q) ? '' : 'none';
    });
}

/**
 * Carga la orden seleccionada desde el modal de órdenes de compra
 */
async function importarOrdenDesdeModal(ordenId) {
    cerrarModalImportarOrden();
    const id = parseInt(ordenId, 10);
    if (!id) return;

    const contenedor = document.getElementById('contenedorLineasRecepcion');
    if (contenedor) {
        contenedor.innerHTML = '<div style="text-align: center; padding: 35px; color: var(--text-muted);"><div style="font-size: 1.8rem; margin-bottom: 8px;">⏳</div>Cargando productos pactados de la orden...</div>';
    }

    try {
        const resp = await fetch(`procesar_recepciones.php?accion=obtener_lineas_orden&orden_id=${id}`);
        const data = await resp.json();

        if (!data || !data.ok) {
            throw new Error(data.mensaje || 'Error al obtener líneas pendientes de la orden.');
        }

        ORDEN_ACTUAL = data.orden;
        const lineas = data.lineas || [];

        const inpOrden = document.getElementById('inputOrdenCompraId');
        if (inpOrden) inpOrden.value = id;

        if (ORDEN_ACTUAL.proveedor_id) {
            seleccionarProveedorRecep(ORDEN_ACTUAL.proveedor_id, true);
        }

        const banner = document.getElementById('bannerOrdenImportada');
        if (banner) {
            const texto = document.getElementById('textoOrdenVinculada');
            const sub = document.getElementById('subtextoOrdenVinculada');
            if (texto) texto.textContent = `${ORDEN_ACTUAL.numero_orden} — ${ORDEN_ACTUAL.proveedor_nombre}`;
            if (sub) sub.textContent = `RNC: ${ORDEN_ACTUAL.proveedor_rnc || 'N/A'} | Días Crédito: ${ORDEN_ACTUAL.dias_credito_habitual || 30} días | Emisión: ${ORDEN_ACTUAL.fecha_emision || ''}`;
            banner.style.display = 'flex';
        }

        const badgeModo = document.getElementById('badgeModoRecepcion');
        if (badgeModo) {
            badgeModo.textContent = `ORDEN: ${ORDEN_ACTUAL.numero_orden}`;
            badgeModo.style.color = '#1565c0';
            badgeModo.style.background = '#e3f2fd';
        }

        MAPA_PRODUCTOS_RECEPCION.clear();
        lineas.forEach(l => {
            const prodId = parseInt(l.producto_maestro_id, 10);
            const cantPend = parseInt(l.cantidad_pendiente || 0, 10);
            const costoPact = parseFloat(l.costo_pactado_unitario || l.costo_unitario_pactado || 0);

            MAPA_PRODUCTOS_RECEPCION.set(prodId, {
                id: prodId,
                nombre: l.producto_nombre,
                sku: l.sku,
                costo: costoPact,
                pendiente: cantPend,
                lotes: [
                    {
                        cant: cantPend,
                        costo: costoPact,
                        lote: generarNumeroLoteSugerido(l.sku),
                        venc: ''
                    }
                ]
            });
        });

        renderizarContenedorProductos();
        buscarProductosCatalogo(document.getElementById('inputBuscarCatalogo')?.value || '');
        actualizarNotaCondicionPago();
        calcularGranTotalRecepcion();

        abrirModalNuevaRecepcion(false);

    } catch (e) {
        console.error(e);
        mostrarMensajeModal({
            tipo: 'error',
            titulo: 'Error al Importar Orden',
            mensaje: e.message
        });
        iniciarRecepcionDirecta();
    }
}

/**
 * Desvincula la orden de compra actual y convierte la recepción en directa
 */
function desvincularOrdenCompra() {
    ORDEN_ACTUAL = null;
    const inpOrden = document.getElementById('inputOrdenCompraId');
    if (inpOrden) inpOrden.value = '';

    const btnCambiar = document.getElementById('btnCambiarProvRecep');
    if (btnCambiar) btnCambiar.style.display = 'inline-block';

    const banner = document.getElementById('bannerOrdenImportada');
    if (banner) banner.style.display = 'none';

    const badgeModo = document.getElementById('badgeModoRecepcion');
    if (badgeModo) {
        badgeModo.textContent = 'RECEPCIÓN DIRECTA (SIN ORDEN)';
        badgeModo.style.color = '#7f5e39';
        badgeModo.style.background = '#fdf5ea';
    }

    MAPA_PRODUCTOS_RECEPCION.forEach(item => {
        item.pendiente = 999999;
    });

    renderizarContenedorProductos();
    calcularGranTotalRecepcion();
}

/**
 * Genera un número de lote sugerido basado en la fecha
 */
function generarNumeroLoteSugerido(sku) {
    const hoy = new Date();
    const yyyy = hoy.getFullYear();
    const mm = String(hoy.getMonth() + 1).padStart(2, '0');
    return `LOT-${yyyy}${mm}`;
}

/**
 * Busca productos en el catálogo según texto
 */
function buscarProductosCatalogo(query) {
    const panel = document.getElementById('panelResultadosCatalogo');
    if (!panel) return;

    const q = (query || '').toLowerCase().trim();
    const lista = typeof CATALOGO_PRODUCTOS !== 'undefined' ? CATALOGO_PRODUCTOS : [];

    let filtrados = lista;
    if (q) {
        filtrados = lista.filter(p => {
            const nom = (p.nombre || '').toLowerCase();
            const sku = (p.sku || '').toLowerCase();
            return nom.includes(q) || sku.includes(q);
        });
    }

    if (!filtrados || filtrados.length === 0) {
        panel.innerHTML = '<div style="padding: 12px; color: var(--text-muted); font-size: 0.82rem; text-align: center;">No se encontraron productos coincidentes.</div>';
        return;
    }

    let html = '';
    filtrados.forEach(p => {
        const prodId = parseInt(p.id, 10);
        const yaAgregado = MAPA_PRODUCTOS_RECEPCION.has(prodId);
        const costo = parseFloat(p.costo_promedio || 0).toFixed(2);

        html += `
            <div class="item-catalogo-busqueda" data-prod-id="${prodId}" style="display: flex; justify-content: space-between; align-items: center; padding: 7px 10px; border-bottom: 1px solid #f2ede4; background: ${yaAgregado ? '#fcf9f2' : '#ffffff'}; border-radius: 4px; margin-bottom: 3px; transition: background 0.15s ease;">
                <div style="flex: 1; min-width: 0; padding-right: 10px;">
                    <strong style="color: var(--text-dark); font-size: 0.84rem; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        ${p.nombre}
                    </strong>
                    <div style="font-size: 0.72rem; color: var(--text-muted); display: flex; gap: 8px;">
                        <span style="font-family: var(--font-mono); font-weight: 600;">${p.sku}</span>
                        <span>•</span>
                        <span>Costo Prom: RD$ ${costo}</span>
                    </div>
                </div>

                <div class="col-boton-accion">
                    ${yaAgregado
                ? `<button type="button" class="btn btn-sm btn-danger" onclick="quitarProductoDeRecepcion(${prodId})" style="padding: 3px 10px; font-size: 0.76rem; white-space: nowrap;">✕ Quitar</button>`
                : `<button type="button" class="btn btn-sm btn-gold" onclick="agregarProductoARecepcion(${prodId})" style="padding: 3px 10px; font-size: 0.76rem; white-space: nowrap;">+ Agregar</button>`
            }
                </div>
            </div>
        `;
    });

    panel.innerHTML = html;
}

/**
 * Agrega un producto a la recepción
 */
function agregarProductoARecepcion(productoId) {
    const prodId = parseInt(productoId, 10);
    if (!prodId || MAPA_PRODUCTOS_RECEPCION.has(prodId)) return;

    const prod = MAPA_CATALOGO_CACHE.get(prodId) || (typeof CATALOGO_PRODUCTOS !== 'undefined' ? CATALOGO_PRODUCTOS.find(p => parseInt(p.id, 10) === prodId) : null);
    if (!prod) return;

    const costoRef = parseFloat(prod.costo_promedio || 0) > 0
        ? parseFloat(prod.costo_promedio)
        : (parseFloat(prod.precio_base_unidad || 0) > 0 ? parseFloat(prod.precio_base_unidad) : 0);

    MAPA_PRODUCTOS_RECEPCION.set(prodId, {
        id: prodId,
        sku: prod.sku,
        nombre: prod.nombre,
        costo: costoRef,
        pendiente: 999999,
        lotes: [
            {
                cant: 1,
                costo: costoRef,
                lote: generarNumeroLoteSugerido(prod.sku),
                venc: ''
            }
        ]
    });

    renderizarContenedorProductos();
    actualizarBotonEnPanelCatalogo(prodId, true);
    calcularGranTotalRecepcion();
}

/**
 * Quita un producto de la recepción
 */
function quitarProductoDeRecepcion(productoId) {
    const prodId = parseInt(productoId, 10);
    if (!prodId) return;

    MAPA_PRODUCTOS_RECEPCION.delete(prodId);
    renderizarContenedorProductos();
    actualizarBotonEnPanelCatalogo(prodId, false);
    calcularGranTotalRecepcion();
}

/**
 * Actualiza el botón Agregar/Quitar de un producto en el panel izquierdo
 */
function actualizarBotonEnPanelCatalogo(productoId, agregado) {
    const item = document.querySelector(`.item-catalogo-busqueda[data-prod-id="${productoId}"]`);
    if (!item) return;

    item.style.background = agregado ? '#fcf9f2' : '#ffffff';
    const colBtn = item.querySelector('.col-boton-accion');
    if (colBtn) {
        colBtn.innerHTML = agregado
            ? `<button type="button" class="btn btn-sm btn-danger" onclick="quitarProductoDeRecepcion(${productoId})" style="padding: 3px 10px; font-size: 0.76rem; white-space: nowrap;">✕ Quitar</button>`
            : `<button type="button" class="btn btn-sm btn-gold" onclick="agregarProductoARecepcion(${productoId})" style="padding: 3px 10px; font-size: 0.76rem; white-space: nowrap;">+ Agregar</button>`;
    }
}

/**
 * Renderiza todos los productos agregados en el contenedor de recepción
 */
function renderizarContenedorProductos() {
    const contenedor = document.getElementById('contenedorLineasRecepcion');
    if (!contenedor) return;

    if (MAPA_PRODUCTOS_RECEPCION.size === 0) {
        contenedor.innerHTML = `
            <div style="text-align: center; padding: 40px 20px; color: var(--text-muted); background: #fbf9f5; border: 1.5px dashed #dfd4c4; border-radius: 8px;">
                <div style="font-size: 2rem; margin-bottom: 6px;">🛒</div>
                <div style="font-weight: 600; color: #5a4b3d; margin-bottom: 4px;">No has agregado productos a esta recepción aún.</div>
                <p style="font-size: 0.80rem; margin: 0; color: var(--text-muted);">
                    Haz clic en el botón <strong>+ Agregar</strong> del producto en la lista de la izquierda o importa una orden.
                </p>
            </div>
        `;
        calcularGranTotalRecepcion();
        return;
    }

    let filasHtml = '';

    MAPA_PRODUCTOS_RECEPCION.forEach(item => {
        const prodId = item.id;
        const tieneOrden = (ORDEN_ACTUAL && item.pendiente < 999999);
        const badgePendiente = tieneOrden
            ? `<span style="background: #fef9e7; color: #b78103; border: 1px solid #f9e79f; font-size: 0.68rem; font-weight: 700; padding: 1px 6px; border-radius: 4px; margin-left: 4px;">Pend: ${item.pendiente} u.</span>`
            : '';

        item.lotes.forEach((lot, lotIdx) => {
            const cant = parseInt(lot.cant || 0, 10) || 0;
            const costo = parseFloat(lot.costo || 0) || 0;
            const subtotal = cant * costo;
            const esPrimerLote = (lotIdx === 0);

            filasHtml += `
                <tr class="fila-lote" data-producto-id="${prodId}" data-lote-idx="${lotIdx}" style="border-bottom: 1px solid #ebdcc5; background: #ffffff;">
                    <td style="padding: 6px 10px; vertical-align: middle;">
                        ${esPrimerLote ? `
                            <div style="font-weight: 700; color: #211813; font-size: 0.82rem; line-height: 1.2;">${item.nombre}</div>
                            <div style="font-size: 0.70rem; color: var(--text-muted); font-family: var(--font-mono); margin-top: 1px;">
                                ${item.sku} ${badgePendiente}
                                <button type="button" onclick="dividirLote(${prodId})" style="background: none; border: none; color: #a66e2e; font-size: 0.70rem; font-weight: 700; cursor: pointer; padding: 0 4px; text-decoration: underline;" title="Agregar otra partición de lote">+ Lote</button>
                            </div>
                        ` : `
                            <div style="font-size: 0.70rem; color: #a66e2e; font-style: italic; padding-left: 12px;">↳ Lote adicional #${lotIdx + 1}</div>
                        `}
                    </td>
                    <td style="padding: 6px 4px; text-align: center; vertical-align: middle;">
                        <input type="number" class="form-control text-center input-lote-cant" min="1" step="1" value="${lot.cant}" required oninput="alCambiarDatoLote(${prodId})" style="height: 28px; width: 65px; font-size: 0.82rem; font-weight: 700; font-family: var(--font-mono); padding: 2px;" />
                    </td>
                    <td style="padding: 6px 4px; text-align: right; vertical-align: middle;">
                        <input type="number" class="form-control text-right input-lote-costo" min="0.01" step="0.01" value="${lot.costo.toFixed(2)}" required oninput="alCambiarDatoLote(${prodId})" style="height: 28px; width: 80px; font-size: 0.82rem; font-weight: 600; font-family: var(--font-mono); padding: 2px 4px;" />
                    </td>
                    <td style="padding: 6px 4px; vertical-align: middle;">
                        <input type="text" class="form-control input-lote-num" maxlength="50" value="${lot.lote || ''}" placeholder="LOT-..." required oninput="alCambiarDatoLote(${prodId})" style="height: 28px; width: 100px; font-size: 0.78rem; font-family: var(--font-mono); text-transform: uppercase; padding: 2px 4px;" />
                    </td>
                    <td style="padding: 6px 4px; text-align: center; vertical-align: middle;">
                        <input type="date" class="form-control input-lote-venc" value="${lot.venc || ''}" required oninput="alCambiarDatoLote(${prodId})" style="height: 28px; width: 120px; font-size: 0.75rem; padding: 2px;" />
                    </td>
                    <td style="padding: 6px 8px; text-align: right; vertical-align: middle;">
                        <span class="subtotal-lote" style="font-family: var(--font-mono); font-weight: 700; font-size: 0.82rem; color: #211813;">RD$ ${subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                    </td>
                    <td style="padding: 6px 4px; text-align: center; vertical-align: middle;">
                        ${esPrimerLote && item.lotes.length === 1 ? `
                            <button type="button" class="btn btn-sm btn-outline" onclick="quitarProductoDeRecepcion(${prodId})" title="Quitar de la recepción" style="height: 24px; width: 24px; padding: 0; color: #b71c1c; border-color: #f5c6cb; font-size: 0.75rem; display: inline-flex; align-items: center; justify-content: center;">✕</button>
                        ` : `
                            <button type="button" class="btn btn-sm btn-outline" onclick="eliminarSubLote(${prodId}, ${lotIdx})" title="Quitar esta partición" style="height: 24px; width: 24px; padding: 0; color: #b71c1c; border-color: #f5c6cb; font-size: 0.75rem; display: inline-flex; align-items: center; justify-content: center;">✕</button>
                        `}
                    </td>
                </tr>
            `;
        });
    });

    contenedor.innerHTML = `
        <table class="table-compacta-orden" style="width: 100%; border-collapse: collapse; font-size: 0.80rem; margin: 0;">
            <thead style="position: sticky; top: 0; z-index: 5;">
                <tr style="background: #f6efe4; border-bottom: 1px solid #ebdcc5; color: #5c5043; font-size: 0.70rem; font-weight: 700; text-transform: uppercase; white-space: nowrap;">
                    <th style="padding: 7px 8px; text-align: left; white-space: nowrap;">PRODUCTO SELECCIONADO</th>
                    <th style="padding: 7px 4px; text-align: center; width: 65px; white-space: nowrap;">CANT. *</th>
                    <th style="padding: 7px 4px; text-align: right; width: 110px; white-space: nowrap;">COSTO (RD$) *</th>
                    <th style="padding: 7px 4px; text-align: left; width: 105px; white-space: nowrap;">LOTE *</th>
                    <th style="padding: 7px 4px; text-align: center; width: 120px; white-space: nowrap;">VENCIMIENTO *</th>
                    <th style="padding: 7px 8px; text-align: right; width: 95px; white-space: nowrap;">SUBTOTAL</th>
                    <th style="padding: 7px 4px; text-align: center; width: 30px;"></th>
                </tr>
            </thead>
            <tbody>
                ${filasHtml}
            </tbody>
        </table>
    `;

    calcularGranTotalRecepcion();
}

function dividirLote(productoId) {
    const item = MAPA_PRODUCTOS_RECEPCION.get(productoId);
    if (!item) return;

    item.lotes.push({
        cant: 1,
        costo: item.costo,
        lote: `${generarNumeroLoteSugerido(item.sku)}-B${item.lotes.length + 1}`,
        venc: ''
    });

    renderizarContenedorProductos();
}

function eliminarSubLote(productoId, loteIdx) {
    const item = MAPA_PRODUCTOS_RECEPCION.get(productoId);
    if (!item || item.lotes.length <= 1) return;

    item.lotes.splice(loteIdx, 1);
    renderizarContenedorProductos();
}

function alCambiarDatoLote(productoId) {
    const item = MAPA_PRODUCTOS_RECEPCION.get(productoId);
    if (!item) return;

    const filas = document.querySelectorAll(`.fila-lote[data-producto-id="${productoId}"]`);
    const nuevosLotes = [];

    filas.forEach((f) => {
        const cantInp = f.querySelector('.input-lote-cant');
        const costoInp = f.querySelector('.input-lote-costo');
        const numInp = f.querySelector('.input-lote-num');
        const vencInp = f.querySelector('.input-lote-venc');

        nuevosLotes.push({
            cant: parseInt(cantInp?.value || 0, 10) || 0,
            costo: parseFloat(costoInp?.value || 0) || 0,
            lote: (numInp?.value || '').trim(),
            venc: (vencInp?.value || '').trim()
        });
    });

    item.lotes = nuevosLotes;
    recalcularProducto(productoId);
    calcularGranTotalRecepcion();
}

function recalcularProducto(productoId) {
    const item = MAPA_PRODUCTOS_RECEPCION.get(productoId);
    if (!item) return;

    const filas = document.querySelectorAll(`.fila-lote[data-producto-id="${productoId}"]`);

    filas.forEach(f => {
        const cantInp = f.querySelector('.input-lote-cant');
        const costoInp = f.querySelector('.input-lote-costo');
        const subtotalSpan = f.querySelector('.subtotal-lote');

        const cant = parseInt(cantInp?.value || 0, 10) || 0;
        const costo = parseFloat(costoInp?.value || 0) || 0;
        const subtotal = cant * costo;

        if (subtotalSpan) {
            subtotalSpan.textContent = 'RD$ ' + subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    });
}

function calcularGranTotalRecepcion() {
    let totalUnidades = 0;
    let montoTotal = 0;
    let productosConMercancia = 0;

    MAPA_PRODUCTOS_RECEPCION.forEach(item => {
        let unidadesProd = 0;
        item.lotes.forEach(lot => {
            const c = parseInt(lot.cant || 0, 10) || 0;
            const cost = parseFloat(lot.costo || 0) || 0;
            if (c > 0) {
                totalUnidades += c;
                montoTotal += (c * cost);
                unidadesProd += c;
            }
        });
        if (unidadesProd > 0) productosConMercancia++;
    });

    const lblUnidades = document.getElementById('lblTotalUnidadesRecepcion');
    const lblProds = document.getElementById('lblTotalProductosRecepcion');
    const lblMonto = document.getElementById('lblMontoTotalRecepcion');

    const lblLineasRecep = document.getElementById('lblTotalLineasRecep');
    const lblUnidadesRecep = document.getElementById('lblTotalUnidadesRecep');
    const lblMontoRecep = document.getElementById('lblMontoTotalEstimadoRecep');

    if (lblUnidades) lblUnidades.textContent = totalUnidades.toLocaleString();
    if (lblProds) lblProds.textContent = productosConMercancia;
    if (lblMonto) {
        lblMonto.textContent = 'RD$ ' + montoTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    if (lblLineasRecep) lblLineasRecep.textContent = MAPA_PRODUCTOS_RECEPCION.size;
    if (lblUnidadesRecep) lblUnidadesRecep.textContent = totalUnidades.toLocaleString();
    if (lblMontoRecep) {
        lblMontoRecep.textContent = 'RD$ ' + montoTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
}

/**
 * Guarda la recepción en la base de datos (y liquida CxP automáticamente si es CONTADO)
 */
async function guardarRecepcion(event) {
    event.preventDefault();

    const inpOrden = document.getElementById('inputOrdenCompraId');
    const ordenId = inpOrden && inpOrden.value ? parseInt(inpOrden.value, 10) : null;

    const selProv = document.getElementById('selectProveedorRecepcion');
    const proveedorId = selProv ? selProv.value.trim() : '';

    const selAlm = document.getElementById('selectAlmacenRecepcion');
    const almacenId = selAlm && selAlm.value ? parseInt(selAlm.value, 10) : 0;
    const almacenNombre = (selAlm && selAlm.selectedIndex >= 0)
        ? selAlm.options[selAlm.selectedIndex].text.trim()
        : '';

    const numFactura = document.getElementById('inputNumFactura').value.trim();
    const ncf = document.getElementById('inputNcf').value.trim();
    const btnGuardar = document.getElementById('btnGuardarRecepcion');

    if (!proveedorId) {
        mostrarMensajeModal({
            tipo: 'advertencia',
            titulo: 'Proveedor Obligatorio',
            mensaje: 'Debe seleccionar el proveedor del cual se recibe la mercancía.'
        });
        document.getElementById('inputBuscarProveedorRecep')?.focus();
        return;
    }

    if (!almacenId || almacenId <= 0) {
        mostrarMensajeModal({
            tipo: 'advertencia',
            titulo: 'Almacén Destino Requerido',
            mensaje: 'Debe seleccionar el almacén donde ingresará físicamente la mercancía.'
        });
        selAlm?.focus();
        return;
    }

    if (!numFactura) {
        mostrarMensajeModal({
            tipo: 'advertencia',
            titulo: 'Número de Factura Requerido',
            mensaje: 'Debe ingresar el número de la factura física del proveedor.'
        });
        document.getElementById('inputNumFactura')?.focus();
        return;
    }

    if (!ncf) {
        mostrarMensajeModal({
            tipo: 'advertencia',
            titulo: 'NCF Requerido',
            mensaje: 'Debe ingresar el NCF del proveedor para fines fiscales de DGII (Formato 606).'
        });
        document.getElementById('inputNcf')?.focus();
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

    let metodoPagoId = null;
    let metodoPagoLabel = '';
    let cuentaBancariaId = null;
    let referenciaPago = '';
    let tarjetaDatos = null;

    if (condicionPago === 'CONTADO') {
        const selMet = document.getElementById('selectMetodoPagoContado');
        const codMet = String(selMet?.options[selMet.selectedIndex]?.getAttribute('data-codigo') || 'EFECTIVO').toUpperCase();
        metodoPagoId = selMet ? parseInt(selMet.value, 10) : null;
        metodoPagoLabel = selMet?.options[selMet.selectedIndex]?.textContent?.trim() || codMet;

        if (codMet === 'TRANSFERENCIA' || codMet === 'CHEQUE') {
            cuentaBancariaId = parseInt(document.getElementById('selectCuentaBancoContado')?.value || '0', 10);
            referenciaPago = (document.getElementById('inputRefPagoContado')?.value || '').trim();

            if (!cuentaBancariaId) {
                mostrarMensajeModal({
                    tipo: 'advertencia',
                    titulo: 'Cuenta Bancaria Requerida',
                    mensaje: `Seleccione la cuenta bancaria en DOP desde la cual se realizó el pago por ${codMet}.`
                });
                return;
            }
            if (!referenciaPago) {
                mostrarMensajeModal({
                    tipo: 'advertencia',
                    titulo: codMet === 'CHEQUE' ? 'Número de Cheque Requerido' : 'Referencia Bancaria Requerida',
                    mensaje: `Ingrese el ${codMet === 'CHEQUE' ? 'número de cheque' : 'número de referencia bancaria'} del pago al contado.`
                });
                document.getElementById('inputRefPagoContado')?.focus();
                return;
            }
        }

        else if (codMet === 'TARJETA') {
            const ult4 = (document.getElementById('inputUlt4TarjetaContado')?.value || '').trim();
            const numAut = (document.getElementById('inputAutTarjetaContado')?.value || '').trim();

            if (ult4.length !== 4) {
                mostrarMensajeModal({
                    tipo: 'advertencia',
                    titulo: 'Últimos 4 Dígitos Requeridos',
                    mensaje: 'Ingrese los últimos 4 dígitos de la tarjeta utilizada en el pago.'
                });
                document.getElementById('inputUlt4TarjetaContado')?.focus();
                return;
            }
            if (!numAut) {
                mostrarMensajeModal({
                    tipo: 'advertencia',
                    titulo: 'Autorización Requerida',
                    mensaje: 'Ingrese el número de autorización o voucher del pago con tarjeta.'
                });
                document.getElementById('inputAutTarjetaContado')?.focus();
                return;
            }

            tarjetaDatos = {
                tipo_tarjeta: document.getElementById('selectTipoTarjetaContado')?.value || 'DEBITO',
                marca_tarjeta: document.getElementById('selectMarcaTarjetaContado')?.value || 'VISA',
                ultimos_4_digitos: ult4,
                numero_autorizacion: numAut
            };
        }






    }

    if (MAPA_PRODUCTOS_RECEPCION.size === 0) {
        mostrarMensajeModal({
            tipo: 'advertencia',
            titulo: 'Sin Mercancía',
            mensaje: 'Debe agregar al menos un producto a la recepción desde el catálogo o importar una orden de compra.'
        });
        return;
    }

    // Validar líneas
    const lineasPayload = [];
    let errorValidacion = null;

    MAPA_PRODUCTOS_RECEPCION.forEach(item => {
        if (errorValidacion) return;

        let totalRecibidoItem = 0;
        const lotesVistos = new Set();

        item.lotes.forEach(lot => {
            if (errorValidacion) return;

            const cant = parseInt(lot.cant || 0, 10);
            const costo = parseFloat(lot.costo || 0);
            const numLote = (lot.lote || '').trim().toUpperCase();
            const venc = (lot.venc || '').trim();

            if (cant > 0) {
                totalRecibidoItem += cant;

                if (!numLote) {
                    errorValidacion = `El producto '${item.nombre}' tiene unidades a recibir pero le falta el número de lote.`;
                    return;
                }

                if (lotesVistos.has(numLote)) {
                    errorValidacion = `El lote '${numLote}' está duplicado para el producto '${item.nombre}'. Cada lote debe ser único.`;
                    return;
                }
                lotesVistos.add(numLote);

                if (!venc) {
                    errorValidacion = `Debe ingresar la fecha de vencimiento (FEFO) para el lote '${numLote}' de '${item.nombre}'.`;
                    return;
                }

                if (costo <= 0) {
                    errorValidacion = `El costo unitario para '${item.nombre}' debe ser mayor a RD$ 0.00.`;
                    return;
                }

                lineasPayload.push({
                    producto_maestro_id: item.id,
                    cantidad_recibida_buena: cant,
                    costo_unitario_facturado: costo,
                    numero_lote: numLote,
                    fecha_vencimiento: venc
                });
            }
        });

        if (ordenId && item.pendiente < 999999 && totalRecibidoItem > item.pendiente) {
            errorValidacion = `Para el producto '${item.nombre}' ingresó ${totalRecibidoItem} unidades, superando el saldo pendiente de ${item.pendiente} unidades.`;
        }
    });

    if (errorValidacion) {
        mostrarMensajeModal({
            tipo: 'advertencia',
            titulo: 'Verifique los Datos',
            mensaje: errorValidacion
        });
        return;
    }

    if (lineasPayload.length === 0) {
        mostrarMensajeModal({
            tipo: 'advertencia',
            titulo: 'Sin Cantidades Recibidas',
            mensaje: 'Debe ingresar al menos una cantidad mayor a 0 para registrar la entrada a almacén.'
        });
        return;
    }

    const textoContable = (condicionPago === 'CONTADO')
        ? `Se registrará la entrada física a inventario y se <strong>saldará automáticamente</strong> la CxP al contado (${metodoPagoLabel}).`
        : `Se registrará la entrada física a inventario (Kardex) y la obligación pendiente en Cuentas por Pagar.`;

    mostrarMensajeModal({
        tipo: 'pregunta',
        esConfirmacion: true,
        titulo: '¿Confirmar Recepción de Mercancía?',
        mensaje: `${textoContable}<br><br><strong>Almacén Destino:</strong> ${almacenNombre}<br><strong>Factura:</strong> ${numFactura} | <strong>NCF:</strong> ${ncf}<br><strong>Condición:</strong> ${condicionPago} | <strong>Líneas:</strong> ${lineasPayload.length}`,
        textoBoton: 'Sí, Registrar Entrada',
        textoCancelar: 'Revisar',
        alConfirmar: async () => {
            if (btnGuardar) {
                btnGuardar.disabled = true;
                btnGuardar.textContent = 'Procesando entrada a almacén...';
            }

            try {
                const payload = {
                    accion: 'registrar_recepcion',
                    orden_compra_id: ordenId,
                    proveedor_id: proveedorId,
                    almacen_id: almacenId,
                    numero_factura_proveedor: numFactura,
                    ncf_proveedor: ncf,
                    condicion_pago: condicionPago,
                    metodo_pago_id: metodoPagoId,
                    cuenta_bancaria_id: cuentaBancariaId,
                    referencia_pago: referenciaPago,
                    tarjeta_datos: tarjetaDatos,
                    lineas: lineasPayload
                };

                const resp = await fetch('procesar_recepciones.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const textoResp = await resp.text();
                let data;
                try {
                    data = JSON.parse(textoResp);
                } catch (parseErr) {
                    throw new Error('Respuesta no válida del servidor: ' + textoResp.replace(/<[^>]+>/g, ' ').trim().slice(0, 220));
                }

                if (data && data.ok) {
                    mostrarMensajeModal({
                        tipo: 'exito',
                        titulo: '¡Recepción Registrada con Éxito!',
                        mensaje: data.mensaje || 'Entrada a inventario y cuenta por pagar generadas correctamente.',
                        alCerrar: () => {
                            window.location.href = 'recepciones.php';
                        }
                    });
                } else {
                    mostrarMensajeModal({
                        tipo: 'error',
                        titulo: 'Error en Recepción',
                        mensaje: data.mensaje || 'No se pudo procesar la recepción.'
                    });
                }
            } catch (e) {
                mostrarMensajeModal({
                    tipo: 'error',
                    titulo: 'Error de Conexión',
                    mensaje: 'No se pudo conectar con el servidor: ' + e.message
                });
            } finally {
                if (btnGuardar) {
                    btnGuardar.disabled = false;
                    btnGuardar.textContent = '💾 Registrar Recepción y Entrada a Inventario';
                }
            }
        }
    });
}

/**
 * Consulta y muestra el comprobante fiscal y físico de una recepción histórica
 */
async function verComprobanteRecepcion(recepcionId) {
    const modal = document.getElementById('modalVerRecepcion');
    const cuerpo = document.getElementById('cuerpoVerRecepcion');
    const titulo = document.getElementById('recModalTitulo');

    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('open');
    }
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
        const egreso = data.egreso || null;

        if (titulo) {
            titulo.textContent = `Comprobante de Recepción #${rec.id} — Factura ${rec.numero_factura_proveedor}`;
        }

        let lineasHtml = '';
        let totalUnidades = 0;

        det.forEach(d => {
            const cant = parseInt(d.cantidad_recibida_buena || 0, 10);
            const costo = parseFloat(d.costo_unitario_facturado || 0);
            const subtotal = parseFloat(d.subtotal_linea || 0);

            totalUnidades += cant;

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

        let cxpHtml = '';
        if (cxp) {
            cxpHtml = `
                <div style="background: #fdfcf9; border: 1px solid #e7dcc8; border-radius: 8px; padding: 12px 16px; margin-top: 16px;">
                    <strong style="color: #6e6254; font-size: 0.85rem; text-transform: uppercase;">Cuenta por Pagar Generada (Formato 606)</strong>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-top: 6px; font-size: 0.85rem;">
                        <div>ID Deuda: <strong>#${cxp.id}</strong></div>
                        <div>Monto Deuda: <strong>RD$ ${parseFloat(cxp.monto_original).toFixed(2)}</strong></div>
                        <div>Saldo Pendiente: <strong>RD$ ${parseFloat(cxp.saldo_pendiente).toFixed(2)}</strong></div>
                        <div>Fecha Vencimiento: <strong style="color: #b78103;">${cxp.fecha_vencimiento}</strong></div>
                        <div>Estado: <span style="font-weight: 700; color: ${cxp.estado === 'PAGADA' ? '#2e7d52' : '#b78103'};">${cxp.estado}</span></div>
                        ${egreso ? `<div>Comprobante Pago: <strong style="font-family: var(--font-mono); color: #2e7d52;">${egreso.numero_comprobante}</strong></div>` : ''}
                    </div>
                </div>
            `;
        }

        if (cuerpo) {
            cuerpo.innerHTML = `
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
                            <div style="font-weight: 600; font-family: var(--font-mono);">${rec.numero_orden || 'Sin Orden (Directa)'}</div>
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

function cerrarModalRecepcion() {
    const modal = document.getElementById('modalVerRecepcion');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('open');
    }
}