/**
 * ============================================================================
 * Controlador Frontend • Movimientos de Inventario y Kardex
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

let carritoLineas = [];
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
// APERTURA / CIERRE DE MODAL PRINCIPAL
// ============================================================================
function abrirModalMovimiento() {
    carritoLineas = [];
    productoActualInfo = null;
    lotesActuales = [];

    document.getElementById('tipo_movimiento').value = 'AJUSTE_ENTRADA';
    document.getElementById('justificacion').value = '';
    document.getElementById('pin_supervisor').value = '';

    const selAlm = document.getElementById('almacen_id');
    if (selAlm && selAlm.options.length === 2) {
        selAlm.selectedIndex = 1;
    }

    limpiarConstructorLinea();
    alCambiarTipoMovimiento();
    renderizarCarrito();

    document.getElementById('modalMovimiento').classList.add('open');
}

function cerrarModalMovimiento(forzar = false) {
    if (!forzar && carritoLineas.length > 0 && !confirm('Tienes líneas en el carrito. ¿Seguro que deseas cancelar?')) {
        return;
    }
    document.getElementById('modalMovimiento').classList.remove('open');
}

function cerrarSiFondoMov(e) {
    if (e.target.id === 'modalMovimiento') cerrarModalMovimiento();
}

// ============================================================================
// CONTROLES DINÁMICOS DE CABECERA (TIPO DE MOVIMIENTO Y ALMACÉN)
// ============================================================================
function alCambiarTipoMovimiento() {
    const tipo = document.getElementById('tipo_movimiento').value;
    const esSalida = (tipo === 'AJUSTE_SALIDA' || tipo === 'MERMA_ROTURA');

    const bloquePin = document.getElementById('bloquePinSupervisor');
    const inpPin = document.getElementById('pin_supervisor');
    const selSup = document.getElementById('supervisor_id');
    const hint = document.getElementById('hintTipoMov');

    if (esSalida) {
        bloquePin.style.display = 'block';
        inpPin.required = true;
        selSup.required = true;
        if (hint) {
            hint.textContent = (tipo === 'MERMA_ROTURA')
                ? 'Descuenta unidades dañadas o vencidas del stock (requiere PIN de supervisor)'
                : 'Descuenta unidades por consumo interno o faltante (requiere PIN de supervisor)';
        }
    } else {
        bloquePin.style.display = 'none';
        inpPin.required = false;
        selSup.required = false;
        inpPin.value = '';
        if (hint) {
            hint.textContent = 'Suma existencias al almacén (permite crear lotes nuevos si el producto caduca)';
        }
    }

    if (carritoLineas.length > 0) {
        carritoLineas = [];
        renderizarCarrito();
        mostrarToast('Se vació el carrito al cambiar el tipo de movimiento.');
    }

    actualizarControlesLote();
}

function alCambiarAlmacen() {
    if (carritoLineas.length > 0) {
        carritoLineas = [];
        renderizarCarrito();
        mostrarToast('Se vació el carrito al cambiar de almacén.');
    }
    if (document.getElementById('linea_producto_id').value) {
        cargarDatosProductoEnAlmacen();
    }
}

// ============================================================================
// ESCÁNER DE CÓDIGO DE BARRAS Y CARGA ASÍNCRONA DE PRESENTACIONES / LOTES
// ============================================================================
async function buscarPorCodigoBarra() {
    const almacenId = document.getElementById('almacen_id').value;
    const codigo = document.getElementById('linea_barcode').value.trim();

    if (!almacenId) {
        alert('Primero selecciona el almacén físico en el Paso 1.');
        document.getElementById('almacen_id').focus();
        return;
    }
    if (!codigo) return;

    try {
        const url = `procesar_movimientos.php?accion=buscar_barcode&codigo=${encodeURIComponent(codigo)}`;
        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();

        if (data.status !== 'success' || !data.match) {
            mostrarToast(data.message || 'Código no encontrado.');
            return;
        }

        const selProd = document.getElementById('linea_producto_id');
        selProd.value = String(data.match.producto_maestro_id);
        await cargarDatosProductoEnAlmacen(data.match.codigo_barra || '');
        document.getElementById('linea_barcode').value = '';
        document.getElementById('linea_cantidad').focus();
        document.getElementById('linea_cantidad').select();
    } catch (err) {
        console.error(err);
        alert('Error de red al buscar el código de barras.');
    }
}

async function cargarDatosProductoEnAlmacen(codigoBarraPreseleccion = '') {
    const almacenId = document.getElementById('almacen_id').value;
    const productoId = document.getElementById('linea_producto_id').value;

    productoActualInfo = null;
    lotesActuales = [];

    const selPres = document.getElementById('linea_presentacion');
    const selLote = document.getElementById('linea_lote_id');
    selPres.innerHTML = '<option value="" data-factor="1">Unidad Base (x1)</option>';
    selLote.innerHTML = '<option value="">Cargando lotes...</option>';

    if (!productoId) {
        selLote.innerHTML = '<option value="">-- Selecciona un producto --</option>';
        actualizarControlesLote();
        actualizarPreviewUnidades();
        return;
    }
    if (!almacenId) {
        alert('Selecciona primero el almacén físico en el Paso 1.');
        document.getElementById('linea_producto_id').value = '';
        selLote.innerHTML = '<option value="">-- Primero selecciona Almacén --</option>';
        return;
    }

    try {
        const url = `procesar_movimientos.php?accion=stock_lotes&almacen_id=${encodeURIComponent(almacenId)}&producto_id=${encodeURIComponent(productoId)}`;
        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();

        if (data.status !== 'success') {
            alert(data.message || 'No se pudieron cargar los datos del producto.');
            return;
        }

        productoActualInfo = data.producto;
        lotesActuales = Array.isArray(data.lotes) ? data.lotes : [];

        const presentaciones = Array.isArray(data.presentaciones) ? data.presentaciones : [];
        let optsPres = '<option value="" data-factor="1">Unidad Base (x1)</option>';
        presentaciones.forEach(pr => {
            const f = parseInt(pr.factor_multiplicador || 1, 10);
            const selected = (codigoBarraPreseleccion && pr.codigo_barra === codigoBarraPreseleccion) ? 'selected' : '';
            optsPres += `<option value="${escapeHtml(pr.codigo_barra)}" data-factor="${f}" ${selected}>
        ${escapeHtml(pr.descripcion_presentacion)} (x${f} uds.) [${escapeHtml(pr.codigo_barra)}]
      </option>`;
        });
        selPres.innerHTML = optsPres;

        actualizarPreviewUnidades();
        actualizarControlesLote();
    } catch (err) {
        console.error(err);
        alert('Error de red al consultar stock y lotes.');
    }
}

function actualizarPreviewUnidades() {
    const selPres = document.getElementById('linea_presentacion');
    const opt = selPres.options[selPres.selectedIndex];
    const factor = parseInt(opt?.dataset?.factor || '1', 10) || 1;
    const cant = parseInt(document.getElementById('linea_cantidad').value || '0', 10) || 0;
    const totalUds = cant * factor;
    const el = document.getElementById('previewUnidadesBase');
    if (el) {
        el.textContent = `${totalUds} ${totalUds === 1 ? 'unidad base' : 'unidades base'}` + (factor > 1 ? ` (${cant} × ${factor})` : '');
    }
}

function actualizarControlesLote() {
    const tipo = document.getElementById('tipo_movimiento').value;
    const esEntrada = (tipo === 'AJUSTE_ENTRADA');

    const lblEstado = document.getElementById('lblEstadoLote');
    const wrapSwitch = document.getElementById('wrapSwitchModoLote');
    const selLote = document.getElementById('linea_lote_id');
    const hint = document.getElementById('hintLoteStock');

    if (!productoActualInfo) {
        wrapSwitch.style.display = 'none';
        alternarModoLote('existente');
        lblEstado.textContent = '📦 Control de Lote (FEFO)';
        hint.textContent = '';
        return;
    }

    if (!productoActualInfo.requiere_lote) {
        wrapSwitch.style.display = 'none';
        alternarModoLote('existente');
        lblEstado.textContent = '📦 Producto sin caducidad • Usa automáticamente Lote Comodín (#0)';
        const l0 = lotesActuales[0] || { id: 0, stock_fisico: 0, stock_comprometido: 0, stock_disponible: 0 };
        selLote.innerHTML = `<option value="0" data-disponible="${l0.stock_disponible}" data-numero="COMODIN-0" data-venc="">
      Lote Comodín #0 — Disponible en almacén: ${l0.stock_disponible} uds. (Físico: ${l0.stock_fisico}, Comprometido: ${l0.stock_comprometido})
    </option>`;
        hint.textContent = 'Este artículo no requiere fecha de vencimiento; el sistema asigna lote_id = 0 automáticamente.';
        return;
    }

    lblEstado.textContent = '⏳ Producto con Control de Caducidad (FEFO)';
    wrapSwitch.style.display = esEntrada ? 'flex' : 'none';

    const radioExist = document.querySelector('input[name="modo_lote_radio"][value="existente"]');
    const radioNuevo = document.querySelector('input[name="modo_lote_radio"][value="nuevo"]');

    const lotesFiltrados = esEntrada
        ? lotesActuales
        : lotesActuales.filter(l => parseInt(l.stock_disponible || 0, 10) > 0);

    if (lotesFiltrados.length === 0) {
        selLote.innerHTML = esEntrada
            ? '<option value="">No existen lotes registrados aún — crea uno nuevo al vuelo</option>'
            : '<option value="">⚠️ Sin lotes con stock disponible en este almacén</option>';
        if (esEntrada && radioNuevo) {
            radioNuevo.checked = true;
            alternarModoLote('nuevo');
        } else {
            alternarModoLote('existente');
        }
        hint.textContent = esEntrada
            ? 'Crea el primer lote indicando su número y fecha de vencimiento.'
            : 'No hay existencias disponibles para descontar en el almacén seleccionado.';
    } else {
        let opts = '';
        lotesFiltrados.forEach((l, idx) => {
            const etiquetaFefo = (!esEntrada && idx === 0) ? ' [FEFO - Más próximo]' : '';
            opts += `<option value="${l.id}"
        data-disponible="${l.stock_disponible}"
        data-numero="${escapeHtml(l.numero_lote)}"
        data-venc="${escapeHtml(l.fecha_vencimiento || '')}">
        Lote ${escapeHtml(l.numero_lote)} • Vence: ${escapeHtml(l.fecha_vencimiento || 'N/A')} — Disponible: ${l.stock_disponible} uds. (Comp: ${l.stock_comprometido})${etiquetaFefo}
      </option>`;
        });
        selLote.innerHTML = opts;
        if (radioExist) radioExist.checked = true;
        alternarModoLote('existente');
        hint.textContent = esEntrada
            ? 'Ordenados por vencimiento. También puedes elegir "+ Crear lote nuevo al vuelo".'
            : 'Ordenados bajo regla FEFO (primero el lote más próximo a vencer con stock disponible).';
    }
}

function alternarModoLote(modo) {
    const panelExist = document.getElementById('panelLoteExistente');
    const panelNuevo = document.getElementById('panelLoteNuevo');
    if (modo === 'nuevo') {
        panelExist.style.display = 'none';
        panelNuevo.style.display = 'block';
    } else {
        panelExist.style.display = 'block';
        panelNuevo.style.display = 'none';
    }
}

function limpiarConstructorLinea() {
    document.getElementById('linea_barcode').value = '';
    document.getElementById('linea_producto_id').value = '';
    document.getElementById('linea_presentacion').innerHTML = '<option value="" data-factor="1">Unidad Base (x1)</option>';
    document.getElementById('linea_cantidad').value = '1';
    document.getElementById('linea_lote_id').innerHTML = '<option value="">-- Primero selecciona Almacén y Producto --</option>';
    document.getElementById('nuevo_numero_lote').value = '';
    document.getElementById('nueva_fecha_vencimiento').value = '';
    productoActualInfo = null;
    lotesActuales = [];
    actualizarControlesLote();
    actualizarPreviewUnidades();
}

// ============================================================================
// CARRITO DE LÍNEAS (CONSOLIDACIÓN POR PRODUCTO + LOTE)
// ============================================================================
function agregarLineaAlCarrito() {
    const tipo = document.getElementById('tipo_movimiento').value;
    const esEntrada = (tipo === 'AJUSTE_ENTRADA');
    const almacenId = document.getElementById('almacen_id').value;

    if (!almacenId) {
        alert('Selecciona primero el almacén físico en el Paso 1.');
        return;
    }
    if (!productoActualInfo) {
        alert('Selecciona un producto maestro antes de agregar al carrito.');
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

    const totalUnidades = cantPres * factor;
    const requiereLote = Boolean(productoActualInfo.requiere_lote);
    const radioModo = document.querySelector('input[name="modo_lote_radio"]:checked');
    const modoLote = (esEntrada && requiereLote && radioModo && radioModo.value === 'nuevo') ? 'nuevo' : 'existente';

    let loteId = 0;
    let loteTexto = 'Lote Comodín #0';
    let vencTexto = 'Sin vencimiento';
    let nuevoNumLote = '';
    let nuevaFechaVenc = '';
    let stockDisponible = null;

    if (requiereLote) {
        if (modoLote === 'nuevo') {
            nuevoNumLote = document.getElementById('nuevo_numero_lote').value.trim().toUpperCase();
            nuevaFechaVenc = document.getElementById('nueva_fecha_vencimiento').value.trim();

            if (!nuevoNumLote) {
                alert('Escribe el número del nuevo lote.');
                document.getElementById('nuevo_numero_lote').focus();
                return;
            }
            if (!nuevaFechaVenc) {
                alert('Selecciona la fecha de vencimiento del nuevo lote.');
                document.getElementById('nueva_fecha_vencimiento').focus();
                return;
            }
            loteId = null;
            loteTexto = `${nuevoNumLote} (Nuevo)`;
            vencTexto = nuevaFechaVenc;
        } else {
            const selLote = document.getElementById('linea_lote_id');
            if (!selLote.value) {
                alert('Debes seleccionar un lote válido.');
                return;
            }
            const optLote = selLote.options[selLote.selectedIndex];
            loteId = parseInt(selLote.value, 10);
            loteTexto = optLote?.dataset?.numero || `Lote #${loteId}`;
            vencTexto = optLote?.dataset?.venc || 'N/A';
            stockDisponible = parseInt(optLote?.dataset?.disponible || '0', 10);
        }
    } else {
        const selLote = document.getElementById('linea_lote_id');
        const optLote = selLote.options[selLote.selectedIndex];
        stockDisponible = parseInt(optLote?.dataset?.disponible || '0', 10);
    }

    const claveLinea = (modoLote === 'nuevo')
        ? `${productoActualInfo.id}_NEW_${nuevoNumLote}`
        : `${productoActualInfo.id}_ID_${loteId}`;

    const existente = carritoLineas.find(l => l.clave === claveLinea);
    const unidadesAcumuladas = (existente ? existente.cantidad_unidades : 0) + totalUnidades;

    if (!esEntrada && stockDisponible !== null && unidadesAcumuladas > stockDisponible) {
        alert(`Stock disponible insuficiente para ${productoActualInfo.sku} (${loteTexto}). Disponible: ${stockDisponible} uds., intentando sacar: ${unidadesAcumuladas} uds.`);
        return;
    }

    if (existente) {
        existente.cantidad_unidades = unidadesAcumuladas;
        existente.cantidad_presentacion = unidadesAcumuladas;
        existente.codigo_barra = '';
        existente.factor = 1;
        mostrarToast(`Cantidad consolidada para ${productoActualInfo.sku} (${unidadesAcumuladas} uds. base).`);
    } else {
        carritoLineas.push({
            clave: claveLinea,
            producto_maestro_id: parseInt(productoActualInfo.id, 10),
            sku: productoActualInfo.sku,
            nombre: productoActualInfo.nombre,
            costo_promedio: parseFloat(productoActualInfo.costo_promedio || 0),
            codigo_barra: codigoBarra,
            factor: factor,
            cantidad_presentacion: cantPres,
            cantidad_unidades: totalUnidades,
            modo_lote: modoLote,
            lote_id: loteId,
            nuevo_numero_lote: nuevoNumLote,
            nueva_fecha_vencimiento: nuevaFechaVenc,
            lote_texto: loteTexto,
            venc_texto: vencTexto
        });
    }

    limpiarConstructorLinea();
    renderizarCarrito();
}

function eliminarLineaCarrito(idx) {
    carritoLineas.splice(idx, 1);
    renderizarCarrito();
}

function renderizarCarrito() {
    const tbody = document.getElementById('tablaCarritoBody');
    const badge = document.getElementById('contadorCarrito');

    const totalUds = carritoLineas.reduce((acc, l) => acc + l.cantidad_unidades, 0);
    const totalCosto = carritoLineas.reduce((acc, l) => acc + (l.cantidad_unidades * l.costo_promedio), 0);

    badge.textContent = `${carritoLineas.length} línea(s) • ${totalUds} uds. • RD$ ${totalCosto.toFixed(2)}`;

    if (carritoLineas.length === 0) {
        tbody.innerHTML = `
      <tr>
        <td colspan="6" style="text-align: center; padding: 20px; color: #888;">
          El carrito está vacío. Configura un producto en el paso 2 y presiona <strong>+ Agregar Línea al Carrito</strong>.
        </td>
      </tr>
    `;
        return;
    }

    tbody.innerHTML = carritoLineas.map((l, idx) => {
        const subtotal = l.cantidad_unidades * l.costo_promedio;
        return `
      <tr>
        <td><strong>${idx + 1}</strong></td>
        <td>
          <strong>${escapeHtml(l.sku)}</strong> — ${escapeHtml(l.nombre)}
        </td>
        <td>
          <div style="font-weight: 600; color: #4a3b32;">${escapeHtml(l.lote_texto)}</div>
          <div style="font-size: 0.76rem; color: #8a7b70;">Vence: ${escapeHtml(l.venc_texto)}</div>
        </td>
        <td><strong>${l.cantidad_unidades} uds.</strong></td>
        <td style="text-align: right; font-family: monospace;">
          RD$ ${subtotal.toFixed(2)}
          <div style="font-size: 0.72rem; color: #888;">(c/u RD$ ${l.costo_promedio.toFixed(2)})</div>
        </td>
        <td style="text-align: right;">
          <button type="button" class="btn-sm btn-danger" onclick="eliminarLineaCarrito(${idx})">Quitar</button>
        </td>
      </tr>
    `;
    }).join('');
}

// ============================================================================
// GUARDAR TRANSACCIÓN EN BACKEND
// ============================================================================
async function guardarMovimiento(e) {
    e.preventDefault();

    const tipo = document.getElementById('tipo_movimiento').value;
    const esSalida = (tipo === 'AJUSTE_SALIDA' || tipo === 'MERMA_ROTURA');
    const almacenId = parseInt(document.getElementById('almacen_id').value || '0', 10);
    const justificacion = document.getElementById('justificacion').value.trim();
    const supervisorId = document.getElementById('supervisor_id').value.trim();
    const pin = document.getElementById('pin_supervisor').value.trim();

    if (almacenId <= 0) {
        alert('Debes seleccionar un almacén físico.');
        return;
    }
    if (justificacion.length < 5) {
        alert('La justificación es obligatoria (mínimo 5 caracteres).');
        document.getElementById('justificacion').focus();
        return;
    }
    if (esSalida) {
        if (!supervisorId) {
            alert('Selecciona el supervisor que autoriza la operación.');
            document.getElementById('supervisor_id').focus();
            return;
        }
        if (!/^\d{4,6}$/.test(pin)) {
            alert('Ingresa un PIN de autorización válido (4 a 6 dígitos).');
            document.getElementById('pin_supervisor').focus();
            return;
        }
    }
    if (carritoLineas.length === 0) {
        alert('Agrega al menos una línea de producto al carrito antes de registrar el movimiento.');
        return;
    }

    const btn = document.getElementById('btnGuardarMovimiento');
    const txtOriginal = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Registrando en Kardex...';

    try {
        const payload = {
            accion: 'registrar_ajuste',
            tipo_movimiento: tipo,
            almacen_id: almacenId,
            justificacion: justificacion,
            supervisor_id: esSalida ? supervisorId : '',
            pin_autorizacion: esSalida ? pin : '',
            lineas: carritoLineas.map(l => ({
                producto_maestro_id: l.producto_maestro_id,
                codigo_barra: l.codigo_barra,
                cantidad_presentacion: l.cantidad_presentacion,
                modo_lote: l.modo_lote,
                lote_id: l.lote_id,
                nuevo_numero_lote: l.nuevo_numero_lote,
                nueva_fecha_vencimiento: l.nueva_fecha_vencimiento
            }))
        };

        const res = await fetch('procesar_movimientos.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (data.status === 'success') {
            cerrarModalMovimiento(true);
            mostrarToast(data.message || 'Movimiento registrado correctamente.');
            setTimeout(() => location.reload(), 700);
        } else {
            btn.disabled = false;
            btn.textContent = txtOriginal;
            alert(data.message || 'Error al registrar el movimiento.');
        }
    } catch (err) {
        console.error(err);
        btn.disabled = false;
        btn.textContent = txtOriginal;
        alert('Error de red al comunicarse con el servidor.');
    }
}

// ============================================================================
// VER DETALLE DE MOVIMIENTO DEL KARDEX Y FILTROS DE TABLA
// ============================================================================
async function verDetalleMovimiento(movId) {
    const modal = document.getElementById('modalDetalle');
    const titulo = document.getElementById('detalleTitulo');
    const body = document.getElementById('detalleContenido');

    titulo.textContent = 'Detalle del Movimiento de Inventario';
    body.innerHTML = '<p style="color: #888;">Consultando líneas del Kardex...</p>';
    modal.classList.add('open');

    try {
        const res = await fetch(`procesar_movimientos.php?accion=detalle&id=${encodeURIComponent(movId)}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (data.status !== 'success') {
            body.innerHTML = `<p style="color: #c62828;">${escapeHtml(data.message || 'No se pudo cargar el detalle.')}</p>`;
            return;
        }

        const cab = data.cabecera;
        const dets = Array.isArray(data.detalles) ? data.detalles : [];
        titulo.textContent = 'Detalle del Movimiento • ' + (cab.tipo_movimiento || '');

        let sumaUds = 0;
        let sumaCosto = 0;

        const filas = dets.map(d => {
            const u = parseInt(d.cantidad_unidades || 0, 10);
            const c = parseFloat(d.costo_unitario_referencia || 0);
            const sub = u * c;
            sumaUds += u;
            sumaCosto += sub;

            const loteLabel = (parseInt(d.lote_id, 10) === 0)
                ? 'Comodín #0'
                : `${escapeHtml(d.numero_lote || ('#' + d.lote_id))} (${escapeHtml(d.fecha_vencimiento || 'N/A')})`;

            return `
        <tr>
          <td><strong>${escapeHtml(d.sku)}</strong> — ${escapeHtml(d.producto_nombre)}</td>
          <td>${loteLabel}</td>
          <td style="text-align: center;"><strong>${u} uds.</strong></td>
          <td style="text-align: right; font-family: monospace;">RD$ ${c.toFixed(2)}</td>
          <td style="text-align: right; font-family: monospace;"><strong>RD$ ${sub.toFixed(2)}</strong></td>
        </tr>
      `;
        }).join('');

        body.innerHTML = `
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background: #faf7f2; border: 1px solid #e7dcc8; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; font-size: 0.88rem;">
        <div><strong>Tipo:</strong> ${escapeHtml(cab.tipo_movimiento)}</div>
        <div><strong>Fecha:</strong> ${escapeHtml(cab.fecha_movimiento || '—')}</div>
        <div><strong>Almacén:</strong> ${escapeHtml(cab.almacen_nombre || 'Global / Multi-almacén')}</div>
        <div><strong>Supervisor:</strong> ${escapeHtml(cab.supervisor_nombre || 'No requerido')}</div>
        <div style="grid-column: 1 / -1;"><strong>Justificación:</strong> ${escapeHtml(cab.justificacion || '—')}</div>
      </div>

      <table class="carrito-reglas-table">
        <thead>
          <tr>
            <th>Producto</th>
            <th>Lote / Vencimiento</th>
            <th style="text-align: center;">Unidades</th>
            <th style="text-align: right;">Costo Unit.</th>
            <th style="text-align: right;">Subtotal</th>
          </tr>
        </thead>
        <tbody>
          ${filas || '<tr><td colspan="5" style="text-align:center; padding:16px; color:#888;">Sin detalles registrados.</td></tr>'}
        </tbody>
        <tfoot>
          <tr style="background: #f8f4ec;">
            <td colspan="2" style="text-align: right;"><strong>TOTALES:</strong></td>
            <td style="text-align: center;"><strong>${sumaUds} uds.</strong></td>
            <td></td>
            <td style="text-align: right; font-family: monospace;"><strong>RD$ ${sumaCosto.toFixed(2)}</strong></td>
          </tr>
        </tfoot>
      </table>
    `;
    } catch (err) {
        console.error(err);
        body.innerHTML = '<p style="color: #c62828;">Error de red al consultar el detalle.</p>';
    }
}

function cerrarModalDetalle() {
    document.getElementById('modalDetalle').classList.remove('open');
}

function filtrarTablaMovimientos() {
    const q = (document.getElementById('buscador')?.value || '').toLowerCase().trim();
    const tipo = (document.getElementById('filtroTipo')?.value || '').toUpperCase();
    const filas = document.querySelectorAll('.fila-movimiento');
    let visibles = 0;

    filas.forEach(f => {
        const tFila = (f.getAttribute('data-tipo') || '').toUpperCase();
        const texto = f.getAttribute('data-texto') || '';
        const okTipo = (!tipo || tFila === tipo);
        const okTexto = (!q || texto.includes(q));

        if (okTipo && okTexto) {
            f.style.display = '';
            visibles++;
        } else {
            f.style.display = 'none';
        }
    });

    const info = document.getElementById('footerInfo');
    const badge = document.getElementById('badgeCount');
    if (info) info.textContent = `Mostrando ${visibles} movimientos`;
    if (badge) badge.textContent = `${visibles} registros`;
}

// ============================================================================
// MODAL BUSCADOR FILTRADO DE PRODUCTOS Y PRESENTACIONES
// ============================================================================
function abrirModalCatalogo() {
    const selAlm = document.getElementById('almacen_id');
    const almacenId = selAlm ? selAlm.value : '';

    if (!almacenId) {
        alert('Primero selecciona el almacén físico en el Paso 1 para consultar existencias reales.');
        if (selAlm) selAlm.focus();
        return;
    }

    const nombreAlm = selAlm.options[selAlm.selectedIndex]?.text || '';
    const tipo = document.getElementById('tipo_movimiento').value;
    const esSalida = (tipo === 'AJUSTE_SALIDA' || tipo === 'MERMA_ROTURA');

    document.getElementById('subtituloCatalogoAlmacen').textContent = `Almacén activo: ${nombreAlm} • Haz clic en la presentación que deseas agregar`;
    document.getElementById('catFiltroTexto').value = '';
    document.getElementById('catFiltroCategoria').value = '0';
    document.getElementById('catFiltroMarca').value = '0';
    document.getElementById('catFiltroSoloStock').checked = esSalida;

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
    const almacenId = document.getElementById('almacen_id').value;
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
            almacen_id: almacenId,
            q: q,
            categoria_id: catId,
            marca_id: marId,
            solo_con_stock: soloStock
        });

        const res = await fetch(`procesar_movimientos.php?${params.toString()}`, {
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
        <button type="button"
          class="btn-sm btn-outline"
          data-prod-id="${item.id}"
          data-barcode=""
          onclick="seleccionarDesdeCatalogo(this)"
          style="margin: 2px; font-size: 0.78rem; padding: 4px 8px;">
          Unidad Base (x1)
        </button>
      `;

            (item.presentaciones || []).forEach(pr => {
                const factor = parseInt(pr.factor_multiplicador || 1, 10);
                botonesPres += `
          <button type="button"
            class="btn-sm btn-outline"
            data-prod-id="${item.id}"
            data-barcode="${escapeHtml(pr.codigo_barra)}"
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
          <td>
            <div style="display: flex; flex-wrap: wrap; gap: 2px;">
              ${botonesPres}
            </div>
          </td>
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

    const selProd = document.getElementById('linea_producto_id');
    selProd.value = String(prodId);
    await cargarDatosProductoEnAlmacen(barcode);

    const inpCant = document.getElementById('linea_cantidad');
    if (inpCant) {
        inpCant.focus();
        inpCant.select();
    }
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const mCat = document.getElementById('modalBuscarCatalogo');
        if (mCat && mCat.classList.contains('open')) {
            cerrarModalCatalogo();
        }
    }
});