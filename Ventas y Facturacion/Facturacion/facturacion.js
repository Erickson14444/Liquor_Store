/**
 * Lógica Frontend: Proceso de Facturación (POS)
 * Ubicación: c:\xampp\htdocs\Liquor_Store\Ventas y Facturacion\Facturacion\facturacion.js
 * House & Ice • Sistema Administrativo
 */

let carrito = [];
let cotizacionOrigenId = null;

// Estado del Pedido a Domicilio (Delivery) configurado desde la ventana modal
let pedidoDeliveryConfig = {
  activo: false,
  zona_id: '',
  zona_nombre: '',
  zona_tarifa: 0,
  repartidor_id: '',
  repartidor_nombre: '',
  direccion_destino: ''
};

document.addEventListener('DOMContentLoaded', () => {
  actualizarInfoNcf();
  alCambiarCondicionPago();
  renderizarCarrito();
});

/* ==========================================================================
   NOTIFICACIONES TOAST
   ========================================================================== */
function mostrarToast(mensaje, tipo = 'exito') {
  const toast = document.getElementById('toast');
  if (!toast) return;

  toast.textContent = mensaje;
  toast.className = 'toast show ' + (tipo === 'error' ? 'toast-error' : 'toast-success');

  clearTimeout(window._toastTimerPos);
  window._toastTimerPos = setTimeout(() => {
    toast.classList.remove('show');
  }, 3800);
}

function formatearMoneda(valor) {
  const num = Number(valor) || 0;
  return 'RD$ ' + num.toLocaleString('es-DO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

/* ==========================================================================
   GESTIÓN DE CLIENTE, COMPROBANTE FISCAL (NCF) Y CONDICIÓN DE PAGO
   ========================================================================== */
function alCambiarCliente() {
  const selCliente = document.getElementById('selectCliente');
  const selNcf     = document.getElementById('selectTipoNcf');
  if (!selCliente || !selNcf) return;

  const opt = selCliente.options[selCliente.selectedIndex];
  if (!opt) return;

  const tipoDoc = (opt.getAttribute('data-tipo-doc') || '').toUpperCase();
  const valCli  = selCliente.value;

  if (valCli === 'CONSUMIDOR_FINAL') {
    if (selNcf.value === 'B01') {
      selNcf.value = 'B02';
    }
  } else if (tipoDoc === 'RNC') {
    selNcf.value = 'B01';
  }

  actualizarInfoNcf();
}

function actualizarInfoNcf() {
  const selNcf   = document.getElementById('selectTipoNcf');
  const badgeNcf = document.getElementById('badgeNcfActivo');
  if (!selNcf || !badgeNcf) return;

  const tipo = selNcf.value;
  const infoMap = window.POS_NCF_INFO || {};

  if (tipo === 'CONSUMO_INTERNO') {
    badgeNcf.textContent = 'USO INTERNO';
    return;
  }

  if (infoMap[tipo] && infoMap[tipo].ncf_proximo) {
    badgeNcf.textContent = 'NCF: ' + infoMap[tipo].ncf_proximo;
  } else {
    badgeNcf.textContent = 'NCF: ' + tipo;
  }
}

function alCambiarCondicionPago() {
  const selMetodo = document.getElementById('selectMetodoPago');
  const bloqueRef = document.getElementById('bloqueReferenciaPago');
  const inputRef  = document.getElementById('inputReferenciaPago');
  if (!selMetodo) return;

  const opt = selMetodo.options[selMetodo.selectedIndex];
  const condicion = opt ? (opt.getAttribute('data-condicion') || 'CONTADO') : 'CONTADO';
  const reqRef    = opt ? (opt.getAttribute('data-req-ref') === '1') : false;

  if (bloqueRef && inputRef) {
    if (condicion === 'CONTADO' && reqRef) {
      bloqueRef.style.display = 'block';
    } else {
      bloqueRef.style.display = 'none';
      inputRef.value = '';
    }
  }

  calcularTotalesCarrito();
}

/* ==========================================================================
   VENTANA MODAL DE PEDIDO A DOMICILIO (DELIVERY) Y REPARTIDORES POR ZONA
   ========================================================================== */
function abrirModalPedidoDelivery() {
  const modal = document.getElementById('modalPedidoDelivery');
  if (!modal) return;

  const selZona = document.getElementById('selectZonaDelivery');
  const inpDir  = document.getElementById('inputDireccionDelivery');
  const inpRep  = document.getElementById('inputRepartidorSeleccionado');

  if (pedidoDeliveryConfig.activo) {
    if (selZona) selZona.value = String(pedidoDeliveryConfig.zona_id || '');
    if (inpDir)  inpDir.value  = pedidoDeliveryConfig.direccion_destino || '';
    filtrarRepartidoresPorZonaModal(pedidoDeliveryConfig.repartidor_id);
  } else {
    if (selZona) selZona.value = '';
    if (inpDir)  inpDir.value  = '';
    if (inpRep)  inpRep.value  = '';
    filtrarRepartidoresPorZonaModal('');
  }

  modal.classList.add('open', 'active');
}

function filtrarRepartidoresPorZonaModal(preseleccionadoId = '') {
  const selZona    = document.getElementById('selectZonaDelivery');
  const contenedor = document.getElementById('listaRepartidoresZonaModal');
  const badgeCount = document.getElementById('badgeCountRepZona');
  const inpRep     = document.getElementById('inputRepartidorSeleccionado');

  if (!selZona || !contenedor) return;

  const zonaId = parseInt(selZona.value, 10);
  if (isNaN(zonaId) || zonaId <= 0) {
    if (inpRep) inpRep.value = '';
    if (badgeCount) badgeCount.textContent = 'Seleccione una zona';
    contenedor.innerHTML = `
      <div class="pos-rep-empty-msg">
        Selecciona primero una <strong>Zona de Entrega</strong> arriba para ver los repartidores disponibles asignados a esa zona.
      </div>
    `;
    return;
  }

  const asignadosZona = Array.isArray(window.POS_REPARTIDORES_ZONAS)
    ? window.POS_REPARTIDORES_ZONAS.filter((r) => Number(r.zona_id) === zonaId)
    : [];

  if (asignadosZona.length === 0) {
    if (inpRep) inpRep.value = '';
    if (badgeCount) badgeCount.textContent = '0 disponibles en esta zona';
    contenedor.innerHTML = `
      <div class="pos-rep-empty-msg" style="color: #b53d32; background: #fff6f5; border-color: #f2c7c7;">
        No hay repartidores disponibles asignados a esta zona en este momento. Puedes asignarlos desde <strong>Despacho Delivery ▸ Asignar Zona de Delivery</strong>.
      </div>
    `;
    return;
  }

  if (badgeCount) {
    badgeCount.textContent = `${asignadosZona.length} ${asignadosZona.length === 1 ? 'repartidor disponible' : 'repartidores disponibles'}`;
  }

  // Determinar cuál repartidor queda seleccionado
  let seleccionadoActual = preseleccionadoId || (inpRep ? inpRep.value : '');
  const existeEnLista = asignadosZona.some((r) => String(r.repartidor_id) === String(seleccionadoActual));
  if (!existeEnLista) {
    seleccionadoActual = String(asignadosZona[0].repartidor_id);
  }
  if (inpRep) inpRep.value = seleccionadoActual;

  let html = '';
  asignadosZona.forEach((rep) => {
    const repId     = String(rep.repartidor_id);
    const activo    = (repId === String(seleccionadoActual));
    const esPrinc   = Boolean(rep.es_ruta_principal);
    const enBase    = (String(rep.estado_logistico).toUpperCase() === 'BASE');
    const vehiculo  = formatearVehiculo(rep.tipo_vehiculo);
    const placaTxt  = rep.placa_vehiculo ? ` • Placa ${rep.placa_vehiculo}` : '';

    html += `
      <div class="pos-rep-card ${activo ? 'selected' : ''}"
           data-rep-id="${escaparHtml(repId)}"
           data-rep-nombre="${escaparHtml(rep.codigo_empleado + ' • ' + rep.nombre_completo)}"
           onclick="seleccionarTarjetaRepartidor('${escaparHtml(repId)}')">
        <div class="pos-rep-check">${activo ? '✓' : ''}</div>
        <div class="pos-rep-body">
          <div class="pos-rep-top-line">
            <span class="pos-rep-name">${escaparHtml(rep.codigo_empleado)} • ${escaparHtml(rep.nombre_completo)}</span>
            ${esPrinc
              ? '<span class="pos-rep-route-pill principal">★ Ruta Principal</span>'
              : '<span class="pos-rep-route-pill secundaria">Ruta de Cobertura</span>'}
          </div>
          <div class="pos-rep-sub-line">
            <span>${vehiculo}${escaparHtml(placaTxt)}</span>
            <span class="pos-rep-status ${enBase ? 'base' : 'ruta'}">
              ● ${enBase ? 'Disponible en Local' : 'En Ruta con Espacio'}
            </span>
          </div>
        </div>
      </div>
    `;
  });

  contenedor.innerHTML = html;
}

function seleccionarTarjetaRepartidor(repartidorId) {
  const inpRep = document.getElementById('inputRepartidorSeleccionado');
  if (inpRep) inpRep.value = repartidorId;

  const cards = document.querySelectorAll('#listaRepartidoresZonaModal .pos-rep-card');
  cards.forEach((card) => {
    const esEste = card.getAttribute('data-rep-id') === String(repartidorId);
    card.classList.toggle('selected', esEste);
    const chk = card.querySelector('.pos-rep-check');
    if (chk) chk.textContent = esEste ? '✓' : '';
  });
}

function formatearVehiculo(tipo) {
  const t = String(tipo || '').toUpperCase();
  if (t === 'MOTOCICLETA') return '🏍️ Motocicleta';
  if (t === 'FURGONETA')   return '🚐 Furgoneta';
  if (t === 'AUTOMOVIL')   return '🚗 Automóvil';
  return '🛵 ' + (tipo || 'Vehículo');
}

function confirmarPedidoDeliveryModal(event) {
  event.preventDefault();

  const selZona = document.getElementById('selectZonaDelivery');
  const inpRep  = document.getElementById('inputRepartidorSeleccionado');
  const inpDir  = document.getElementById('inputDireccionDelivery');

  const zonaId = selZona ? selZona.value : '';
  const repId  = inpRep ? inpRep.value.trim() : '';
  const dirTxt = inpDir ? inpDir.value.trim() : '';

  if (!zonaId) {
    mostrarToast('Selecciona la Zona de Entrega del pedido.', 'error');
    return;
  }
  if (!repId) {
    mostrarToast('Selecciona un Repartidor disponible para la zona.', 'error');
    return;
  }
  if (!dirTxt) {
    mostrarToast('Ingresa la dirección exacta del pedido.', 'error');
    if (inpDir) inpDir.focus();
    return;
  }

  const optZona    = selZona.options[selZona.selectedIndex];
  const zonaNombre = optZona ? (optZona.getAttribute('data-nombre') || optZona.textContent) : '';
  const zonaTarifa = optZona ? (parseFloat(optZona.getAttribute('data-tarifa')) || 0) : 0;

  const cardSel = document.querySelector(`#listaRepartidoresZonaModal .pos-rep-card[data-rep-id="${CSS.escape(repId)}"]`);
  const repNombre = cardSel ? (cardSel.getAttribute('data-rep-nombre') || 'Repartidor Asignado') : 'Repartidor Asignado';

  pedidoDeliveryConfig = {
    activo: true,
    zona_id: zonaId,
    zona_nombre: zonaNombre,
    zona_tarifa: zonaTarifa,
    repartidor_id: repId,
    repartidor_nombre: repNombre,
    direccion_destino: dirTxt
  };

  actualizarVistaResumenDelivery();

  const modal = document.getElementById('modalPedidoDelivery');
  if (modal) modal.classList.remove('open', 'active');

  mostrarToast(`Pedido Delivery configurado para la zona «${zonaNombre}».`);
}

function quitarConfiguracionDelivery() {
  pedidoDeliveryConfig = {
    activo: false,
    zona_id: '',
    zona_nombre: '',
    zona_tarifa: 0,
    repartidor_id: '',
    repartidor_nombre: '',
    direccion_destino: ''
  };
  actualizarVistaResumenDelivery();
  mostrarToast('Modalidad cambiada a Entrega en Mostrador.');
}

function actualizarVistaResumenDelivery() {
  const banner = document.getElementById('bannerPedidoDeliveryActivo');
  const texto  = document.getElementById('textoResumenDelivery');
  const btnDel = document.getElementById('btnAbrirPedidoDelivery');

  if (pedidoDeliveryConfig.activo) {
    if (banner) banner.style.display = 'flex';
    if (texto) {
      texto.innerHTML =
        `<strong>Zona:</strong> ${escaparHtml(pedidoDeliveryConfig.zona_nombre)} &nbsp;•&nbsp; ` +
        `<strong>Repartidor:</strong> ${escaparHtml(pedidoDeliveryConfig.repartidor_nombre)} &nbsp;•&nbsp; ` +
        `<strong>Dirección:</strong> ${escaparHtml(pedidoDeliveryConfig.direccion_destino)}`;
    }
    if (btnDel) {
      btnDel.innerHTML = `🛵 Pedido Delivery Activo (${escaparHtml(pedidoDeliveryConfig.zona_nombre)})`;
      btnDel.style.borderColor = 'var(--accent-gold)';
      btnDel.style.background = '#fbf5ea';
      btnDel.style.color = '#7a4d18';
    }
  } else {
    if (banner) banner.style.display = 'none';
    if (btnDel) {
      btnDel.innerHTML = '🛵 Pedido a Domicilio (Delivery)';
      btnDel.style.borderColor = '';
      btnDel.style.background = '';
      btnDel.style.color = '';
    }
  }
}

/* ==========================================================================
   EVALUACIÓN AUTOMÁTICA DE PROMOCIONES ACTIVAS
   ========================================================================== */
function evaluarPromocionParaItem(item) {
  const promos = Array.isArray(window.POS_PROMOCIONES) ? window.POS_PROMOCIONES : [];
  if (promos.length === 0) {
    return { promocion_id: null, etiqueta: '', descuento: 0, faltan_unidades: 0, beneficio_texto: '' };
  }

  const pmId    = Number(item.producto_maestro_id) || 0;
  const catId   = Number(item.categoria_id) || 0;
  const marcaId = Number(item.marca_id) || 0;
  const cant    = Number(item.cantidad) || 0;
  const precio  = Number(item.precio_unitario) || 0;

  let promoPendiente = null;

  for (const p of promos) {
    const condProd  = Number(p.condicion_producto_id) || 0;
    const condCat   = Number(p.condicion_categoria_id) || 0;
    const condMarca = Number(p.marca_id) || 0;
    const cantReq   = Math.max(1, Number(p.cantidad_requerida) || 1);

    const coincideProducto  = (condProd === 0 || condProd === pmId);
    const coincideCategoria = (condCat === 0 || condCat === catId);
    const coincideMarca     = (condMarca === 0 || condMarca === marcaId);

    if (coincideProducto && coincideCategoria && coincideMarca) {
      let porc = Number(p.beneficio_porcentaje) || 0;
      if (porc > 0 && porc < 1) {
        porc = Math.round(porc * 10000) / 100;
      }
      const fijo = Number(p.beneficio_monto_fijo) || 0;
      const beneficioTxt = porc > 0 ? `-${porc}%` : (fijo > 0 ? `-RD$ ${fijo.toFixed(2)}` : '');

      if (cant >= cantReq) {
        const bruto = cant * precio;
        let desc = 0;

        if (porc > 0) {
          desc = bruto * (porc / 100.0);
        } else if (fijo > 0) {
          const bloques = Math.floor(cant / cantReq);
          desc = bloques * fijo;
        }

        desc = Math.min(bruto, Math.max(0, Number(desc.toFixed(2))));
        return {
          promocion_id: Number(p.promocion_id),
          etiqueta: p.nombre_campana || 'Promoción Activa',
          descuento: desc,
          faltan_unidades: 0,
          beneficio_texto: beneficioTxt
        };
      } else if (!promoPendiente) {
        promoPendiente = {
          promocion_id: null,
          etiqueta: p.nombre_campana || '',
          descuento: 0,
          faltan_unidades: cantReq - cant,
          beneficio_texto: beneficioTxt
        };
      }
    }
  }

  return promoPendiente || { promocion_id: null, etiqueta: '', descuento: 0, faltan_unidades: 0, beneficio_texto: '' };
}

/* ==========================================================================
   GESTIÓN DEL CARRITO DE FACTURACIÓN
   ========================================================================== */
function iconoVisualCategoria(categoriaNombre, productoNombre) {
  const txt = ((categoriaNombre || '') + ' ' + (productoNombre || '')).toLowerCase();
  if (txt.includes('cerveza') || txt.includes('presidente') || txt.includes('six-pack') || txt.includes('corona')) {
    return '🍺';
  }
  if (txt.includes('vino') || txt.includes('champagne') || txt.includes('espumante') || txt.includes('cava')) {
    return '🍷';
  }
  if (txt.includes('whisky') || txt.includes('ron') || txt.includes('brugal') || txt.includes('macallan') || txt.includes('buchanan')) {
    return '🥃';
  }
  return '🍾';
}

function agregarItemDesdeCatalogo(indiceCatalogo) {
  const catalogo = Array.isArray(window.POS_CATALOGO) ? window.POS_CATALOGO : [];
  const prod = catalogo[indiceCatalogo];
  if (!prod) return;

  insertarOIncrementarEnCarrito({
    producto_maestro_id: Number(prod.producto_maestro_id),
    codigo_barra_id: prod.codigo_barra_id ? Number(prod.codigo_barra_id) : null,
    sku: prod.sku || '',
    codigo_barra: prod.codigo_barra || '',
    producto_nombre: prod.producto_nombre || prod.sku,
    descripcion_presentacion: prod.descripcion_presentacion || 'Unidad Individual',
    categoria_id: Number(prod.categoria_id) || 0,
    marca_id: Number(prod.marca_id) || 0,
    categoria_nombre: prod.categoria_nombre || 'General',
    factor_conversion: Math.max(1, Number(prod.factor_conversion) || 1),
    precio_unitario: Math.max(0, Number(prod.precio_venta) || 0),
    porcentaje_itbis: Math.max(0, Number(prod.porcentaje_itbis) || 18),
    cantidad: 1
  });

  mostrarToast(`«${prod.producto_nombre}» agregado al carrito.`);
}

function insertarOIncrementarEnCarrito(nuevoItem) {
  const existente = carrito.find(
    (it) => Number(it.producto_maestro_id) === Number(nuevoItem.producto_maestro_id)
  );

  if (existente) {
    existente.cantidad += Number(nuevoItem.cantidad) || 1;
    if (nuevoItem.codigo_barra_id && existente.codigo_barra_id !== nuevoItem.codigo_barra_id) {
      existente.codigo_barra_id = nuevoItem.codigo_barra_id;
      existente.descripcion_presentacion = nuevoItem.descripcion_presentacion;
      existente.factor_conversion = nuevoItem.factor_conversion;
      existente.precio_unitario = nuevoItem.precio_unitario;
    }
  } else {
    carrito.push({
      producto_maestro_id: Number(nuevoItem.producto_maestro_id),
      codigo_barra_id: nuevoItem.codigo_barra_id ? Number(nuevoItem.codigo_barra_id) : null,
      sku: nuevoItem.sku || '',
      codigo_barra: nuevoItem.codigo_barra || '',
      producto_nombre: nuevoItem.producto_nombre || '',
      descripcion_presentacion: nuevoItem.descripcion_presentacion || 'Unidad Individual',
      categoria_id: Number(nuevoItem.categoria_id) || 0,
      marca_id: Number(nuevoItem.marca_id) || 0,
      categoria_nombre: nuevoItem.categoria_nombre || 'General',
      factor_conversion: Math.max(1, Number(nuevoItem.factor_conversion) || 1),
      precio_unitario: Math.max(0, Number(nuevoItem.precio_unitario) || 0),
      porcentaje_itbis: Math.max(0, Number(nuevoItem.porcentaje_itbis) || 18),
      cantidad: Math.max(1, Number(nuevoItem.cantidad) || 1),
      promocion_id: null,
      etiqueta_promo: '',
      monto_descuento: 0
    });
  }

  renderizarCarrito();
}

function cambiarCantidadItem(index, delta) {
  if (!carrito[index]) return;
  const nuevaCant = (Number(carrito[index].cantidad) || 1) + delta;
  if (nuevaCant <= 0) {
    quitarItemCarrito(index);
    return;
  }
  carrito[index].cantidad = nuevaCant;
  renderizarCarrito();
}

function fijarCantidadManual(index, valorInput) {
  if (!carrito[index]) return;
  const cant = parseInt(valorInput, 10);
  if (isNaN(cant) || cant <= 0) {
    carrito[index].cantidad = 1;
  } else {
    carrito[index].cantidad = cant;
  }
  renderizarCarrito();
}

function quitarItemCarrito(index) {
  if (!carrito[index]) return;
  const eliminado = carrito.splice(index, 1)[0];
  renderizarCarrito();
  if (eliminado) {
    mostrarToast(`«${eliminado.producto_nombre}» quitado del carrito.`);
  }
}

function limpiarCarritoConConfirmacion() {
  if (carrito.length === 0) return;
  if (!confirm('¿Estás seguro de que deseas vaciar todos los productos del carrito?')) {
    return;
  }
  carrito = [];
  desvincularCotizacion();
  renderizarCarrito();
  mostrarToast('Carrito vaciado correctamente.');
}

/* ==========================================================================
   RENDERIZADO DE LA TABLA Y CÁLCULO DE TOTALES EN VIVO
   ========================================================================== */
function renderizarCarrito() {
  const tbody = document.getElementById('tbodyCarrito');
  const badgeArticulos = document.getElementById('badgeArticulosCarrito');
  const footerResumen = document.getElementById('footerResumenPartidas');
  const btnLimpiar = document.getElementById('btnLimpiarCarrito');

  if (!tbody) return;

  if (carrito.length === 0) {
    tbody.innerHTML = `
      <tr id="filaCarritoVacio">
        <td colspan="7" class="text-center" style="padding: 34px 20px; color: var(--text-muted);">
          No hay productos en el carrito. Haz clic en <strong>+ Agregar Producto</strong> para comenzar a facturar.
        </td>
      </tr>
    `;
    if (badgeArticulos) badgeArticulos.textContent = '0 Artículos';
    if (footerResumen) footerResumen.textContent = '0 líneas registradas en la factura actual';
    if (btnLimpiar) btnLimpiar.style.display = 'none';
    calcularTotalesCarrito();
    return;
  }

  if (btnLimpiar) btnLimpiar.style.display = 'inline-flex';

  let totalUnidades = 0;
  let html = '';

  carrito.forEach((item, index) => {
    const evalPromo = evaluarPromocionParaItem(item);
    item.promocion_id    = evalPromo.promocion_id;
    item.etiqueta_promo  = evalPromo.etiqueta;
    item.monto_descuento = evalPromo.descuento;

    const cant = Math.max(1, Number(item.cantidad) || 1);
    const precioUnit = Math.max(0, Number(item.precio_unitario) || 0);
    const bruto = cant * precioUnit;
    const descMonto = Math.max(0, Number(item.monto_descuento) || 0);
    const subtotalLinea = Math.max(0, bruto - descMonto);

    totalUnidades += cant;

    const icono = iconoVisualCategoria(item.categoria_nombre, item.producto_nombre);
    const mostrarPres =
      item.descripcion_presentacion &&
      item.descripcion_presentacion.toLowerCase() !== item.producto_nombre.toLowerCase();

    let celdaDescuentoHtml = '';
    if (descMonto > 0) {
      const badgeBeneficio = evalPromo.beneficio_texto ? ` (${escaparHtml(evalPromo.beneficio_texto)})` : '';
      celdaDescuentoHtml = `
        <div class="pos-discount-cell">
          <span class="pos-discount-amount">-${formatearMoneda(descMonto)}${badgeBeneficio}</span>
          <div class="pos-discount-restante">Restante: <strong>${formatearMoneda(subtotalLinea)}</strong></div>
        </div>
      `;
    } else if (evalPromo.faltan_unidades > 0) {
      celdaDescuentoHtml = `
        <div class="pos-discount-cell">
          <span class="pos-discount-zero">RD$ 0.00</span>
          <div class="pos-discount-hint">Faltan ${evalPromo.faltan_unidades} ud(s). para ${escaparHtml(evalPromo.beneficio_texto)}</div>
        </div>
      `;
    } else {
      celdaDescuentoHtml = `<span class="pos-discount-zero">RD$ 0.00</span>`;
    }

    html += `
      <tr class="pos-cart-row">
        <td>
          <span class="pos-sku-code">${escaparHtml(item.sku)}</span>
        </td>
        <td>
          <div class="pos-prod-cell">
            <div class="pos-prod-thumb">${icono}</div>
            <div class="pos-prod-info">
              <div class="pos-prod-title">${escaparHtml(item.producto_nombre)}</div>
              ${mostrarPres ? `<div class="pos-prod-sub">${escaparHtml(item.descripcion_presentacion)}</div>` : ''}
              ${item.etiqueta_promo ? `<span class="pos-promo-tag">${escaparHtml(item.etiqueta_promo)}</span>` : ''}
            </div>
          </div>
        </td>
        <td class="text-center">
          <div class="pos-qty-control">
            <button type="button" class="pos-qty-btn" onclick="cambiarCantidadItem(${index}, -1)" title="Disminuir">−</button>
            <input
              type="number"
              min="1"
              class="pos-qty-input"
              value="${cant}"
              onchange="fijarCantidadManual(${index}, this.value)"
            />
            <button type="button" class="pos-qty-btn" onclick="cambiarCantidadItem(${index}, 1)" title="Aumentar">+</button>
          </div>
        </td>
        <td class="text-right">
          <span class="pos-price-cell">${formatearMoneda(precioUnit)}</span>
        </td>
        <td class="text-right">
          ${celdaDescuentoHtml}
        </td>
        <td class="text-right">
          ${descMonto > 0 ? `<div class="pos-subtotal-old">${formatearMoneda(bruto)}</div>` : ''}
          <span class="pos-subtotal-cell">${formatearMoneda(subtotalLinea)}</span>
        </td>
        <td class="text-right">
          <button type="button" class="pos-btn-quitar" onclick="quitarItemCarrito(${index})">
            Quitar
          </button>
        </td>
      </tr>
    `;
  });

  tbody.innerHTML = html;

  if (badgeArticulos) {
    badgeArticulos.textContent = `${totalUnidades} ${totalUnidades === 1 ? 'Artículo' : 'Artículos'}`;
  }
  if (footerResumen) {
    footerResumen.textContent = `${carrito.length} ${carrito.length === 1 ? 'partida registrada' : 'partidas registradas'} (${totalUnidades} uds. en total)`;
  }

  calcularTotalesCarrito();
}

function calcularTotalesCarrito() {
  let sumaNeto      = 0;
  let sumaDescuento = 0;
  let sumaItbis     = 0;
  let sumaTotal     = 0;

  carrito.forEach((item) => {
    const cant       = Math.max(1, Number(item.cantidad) || 1);
    const precioUnit = Math.max(0, Number(item.precio_unitario) || 0);
    const bruto      = cant * precioUnit;
    const desc       = Math.min(bruto, Math.max(0, Number(item.monto_descuento) || 0));
    const base       = Math.max(0, bruto - desc);
    const tasaItbis  = Math.max(0, Number(item.porcentaje_itbis) || 18) / 100.0;

    const netoLinea  = base / (1.0 + tasaItbis);
    const itbisLinea = base - netoLinea;

    sumaNeto      += netoLinea;
    sumaDescuento += desc;
    sumaItbis     += itbisLinea;
    sumaTotal     += base;
  });

  sumaNeto      = Number(sumaNeto.toFixed(2));
  sumaDescuento = Number(sumaDescuento.toFixed(2));
  sumaItbis     = Number(sumaItbis.toFixed(2));
  sumaTotal     = Number(sumaTotal.toFixed(2));

  const elTotal  = document.getElementById('displayTotalFactura');
  const elNeto   = document.getElementById('displaySubtotalNeto');
  const elItbis  = document.getElementById('displayItbisTotal');
  const elDesc   = document.getElementById('displayDescuentoTotal');
  const spanDesc = document.getElementById('spanDescuentoResumen');

  if (elTotal) elTotal.textContent = formatearMoneda(sumaTotal);
  if (elNeto)  elNeto.textContent  = formatearMoneda(sumaNeto);
  if (elItbis) elItbis.textContent = formatearMoneda(sumaItbis);

  if (spanDesc && elDesc) {
    if (sumaDescuento > 0) {
      spanDesc.style.display = 'inline';
      elDesc.textContent = '-' + formatearMoneda(sumaDescuento);
    } else {
      spanDesc.style.display = 'none';
    }
  }
}

/* ==========================================================================
   MODALES (CIERRE DIRECTO CON BOTÓN X Y CANCELAR SIN CONFIRMACIÓN)
   ========================================================================== */
function cerrarModalConConfirmacion(modalId) {
  const modal = document.getElementById(modalId);
  if (!modal) return;
  modal.classList.remove('open', 'active');
}

function abrirModalCatalogo() {
  const modal = document.getElementById('modalCatalogoProductos');
  if (!modal) return;
  modal.classList.add('open', 'active');
  const buscador = document.getElementById('buscadorModalCatalogo');
  if (buscador) {
    buscador.value = '';
    filtrarCatalogoModal();
    setTimeout(() => buscador.focus(), 80);
  }
}

function filtrarCatalogoModal() {
  const buscador = document.getElementById('buscadorModalCatalogo');
  const filtro = buscador ? buscador.value.trim().toLowerCase() : '';
  const filas = document.querySelectorAll('.fila-catalogo-modal');

  filas.forEach((tr) => {
    const texto = tr.getAttribute('data-search') || '';
    tr.style.display = texto.includes(filtro) ? '' : 'none';
  });
}

function abrirModalCotizaciones() {
  const modal = document.getElementById('modalImportarCotizacion');
  if (modal) modal.classList.add('open', 'active');
}

function abrirModalHistorial() {
  const modal = document.getElementById('modalHistorialFacturas');
  if (modal) modal.classList.add('open', 'active');
}

function abrirModalNuevoCliente() {
  const form = document.getElementById('formClienteRapido');
  if (form) form.reset();
  const modal = document.getElementById('modalNuevoClienteRapido');
  if (modal) modal.classList.add('open', 'active');
}

/* ==========================================================================
   IMPORTACIÓN DE COTIZACIÓN AL CARRITO
   ========================================================================== */
async function cargarCotizacionEnCarrito(cotizacionId) {
  try {
    const resp = await fetch(`procesar_facturacion.php?accion=obtener_cotizacion&cotizacion_id=${encodeURIComponent(cotizacionId)}`);
    const data = await resp.json();

    if (!data.ok) {
      mostrarToast(data.mensaje || 'No se pudo importar la cotización.', 'error');
      return;
    }

    const cab = data.cotizacion;
    const det = Array.isArray(data.detalles) ? data.detalles : [];

    const selCli = document.getElementById('selectCliente');
    if (selCli && cab.cliente_id) {
      const existeOpt = Array.from(selCli.options).some((o) => o.value === cab.cliente_id);
      if (existeOpt) {
        selCli.value = cab.cliente_id;
        alCambiarCliente();
      }
    }

    carrito = det.map((d) => ({
      producto_maestro_id: Number(d.producto_maestro_id),
      codigo_barra_id: d.codigo_barra_id ? Number(d.codigo_barra_id) : null,
      sku: d.sku || '',
      codigo_barra: d.sku || '',
      producto_nombre: d.producto_nombre || d.sku,
      descripcion_presentacion: d.descripcion_presentacion || 'Unidad Individual',
      categoria_id: 0,
      marca_id: 0,
      categoria_nombre: 'General',
      factor_conversion: Math.max(1, Number(d.factor_conversion) || 1),
      precio_unitario: Math.max(0, Number(d.precio_unitario) || 0),
      porcentaje_itbis: Math.max(0, Number(d.porcentaje_itbis) || 18),
      cantidad: Math.max(1, Number(d.cantidad) || 1),
      promocion_id: null,
      etiqueta_promo: '',
      monto_descuento: Math.max(0, Number(d.monto_descuento) || 0)
    }));

    cotizacionOrigenId = Number(cab.id);

    const bannerCot = document.getElementById('bannerCotizacionActiva');
    const txtCot = document.getElementById('textoCotizacionActiva');
    if (bannerCot && txtCot) {
      bannerCot.style.display = 'flex';
      txtCot.textContent = `${cab.numero_cotizacion} • ${cab.cliente_nombre}`;
    }

    const modal = document.getElementById('modalImportarCotizacion');
    if (modal) modal.classList.remove('open', 'active');

    renderizarCarrito();
    mostrarToast(`Cotización ${cab.numero_cotizacion} cargada en el carrito.`);
  } catch (err) {
    mostrarToast('Error de red al importar la cotización.', 'error');
  }
}

function desvincularCotizacion() {
  cotizacionOrigenId = null;
  const bannerCot = document.getElementById('bannerCotizacionActiva');
  if (bannerCot) bannerCot.style.display = 'none';
}

/* ==========================================================================
   CREACIÓN RÁPIDA DE CLIENTE (+ CLIENTE)
   ========================================================================== */
function aplicarMascaraDocClienteRapido() {
  const selTipo = document.getElementById('cliTipoDoc');
  const inpDoc  = document.getElementById('cliDocumento');
  if (!selTipo || !inpDoc) return;

  const tipo = selTipo.value;
  if (tipo === 'PASAPORTE') {
    inpDoc.maxLength = 20;
    inpDoc.placeholder = 'A12345678';
    inpDoc.value = inpDoc.value.replace(/[^a-zA-Z0-9-]/g, '').toUpperCase();
    return;
  }

  const digitos = inpDoc.value.replace(/\D/g, '');
  if (tipo === 'RNC') {
    inpDoc.maxLength = 11;
    inpDoc.placeholder = '101-12345-6';
    const d = digitos.slice(0, 9);
    let out = d;
    if (d.length > 8) {
      out = `${d.slice(0, 3)}-${d.slice(3, 8)}-${d.slice(8, 9)}`;
    } else if (d.length > 3) {
      out = `${d.slice(0, 3)}-${d.slice(3)}`;
    }
    inpDoc.value = out;
  } else {
    inpDoc.maxLength = 13;
    inpDoc.placeholder = '001-1234567-8';
    const d = digitos.slice(0, 11);
    let out = d;
    if (d.length > 10) {
      out = `${d.slice(0, 3)}-${d.slice(3, 10)}-${d.slice(10, 11)}`;
    } else if (d.length > 3) {
      out = `${d.slice(0, 3)}-${d.slice(3)}`;
    }
    inpDoc.value = out;
  }
}

function formatearTelefonoRapido(input) {
  if (!input) return;
  const digitos = input.value.replace(/\D/g, '').slice(0, 10);
  if (digitos.length === 0) {
    input.value = '';
  } else if (digitos.length <= 3) {
    input.value = `(${digitos}`;
  } else if (digitos.length <= 6) {
    input.value = `(${digitos.slice(0, 3)}) ${digitos.slice(3)}`;
  } else {
    input.value = `(${digitos.slice(0, 3)}) ${digitos.slice(3, 6)}-${digitos.slice(6, 10)}`;
  }
}

async function guardarClienteRapido(event) {
  event.preventDefault();
  const btn = document.getElementById('btnSubmitClienteRapido');
  if (btn) btn.disabled = true;

  try {
    const payload = {
      accion: 'crear_cliente_rapido',
      tipo_documento: document.getElementById('cliTipoDoc')?.value || 'CEDULA',
      documento_identidad: document.getElementById('cliDocumento')?.value || '',
      nombre_razon_social: document.getElementById('cliNombre')?.value || '',
      telefono_principal: document.getElementById('cliTelefono')?.value || '',
      categoria_cliente: document.getElementById('cliCategoria')?.value || 'REGULAR'
    };

    const resp = await fetch('procesar_facturacion.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await resp.json();

    if (!data.ok) {
      mostrarToast(data.mensaje || 'Error al registrar el cliente.', 'error');
      if (btn) btn.disabled = false;
      return;
    }

    const cli = data.cliente;
    const selCli = document.getElementById('selectCliente');
    if (selCli && cli) {
      const opt = document.createElement('option');
      opt.value = cli.cliente_id;
      opt.setAttribute('data-doc', cli.documento_identidad || '');
      opt.setAttribute('data-nombre', cli.nombre_razon_social || '');
      opt.setAttribute('data-tipo-doc', cli.tipo_documento || 'CEDULA');
      opt.setAttribute('data-credito', '0');
      opt.setAttribute('data-limite', '0');
      opt.textContent = `${cli.nombre_razon_social} (${cli.tipo_documento}: ${cli.documento_identidad})`;
      selCli.appendChild(opt);
      selCli.value = cli.cliente_id;
      alCambiarCliente();
    }

    const modal = document.getElementById('modalNuevoClienteRapido');
    if (modal) modal.classList.remove('open', 'active');

    mostrarToast(data.mensaje || 'Cliente registrado con éxito.');
  } catch (err) {
    mostrarToast('Error de red al guardar el cliente.', 'error');
  } finally {
    if (btn) btn.disabled = false;
  }
}

/* ==========================================================================
   GUARDAR FACTURA COMPLETA
   ========================================================================== */
async function procesarGuardadoFactura() {
  if (typeof window.POS_TIENE_CAJA_ACTIVA !== 'undefined' && window.POS_TIENE_CAJA_ACTIVA === false) {
    mostrarToast('No tienes una caja asignada. Por favor, solicita a tu supervisor que abra tu turno en Control de Caja.', 'error');
    return;
  }

  if (carrito.length === 0) {
    mostrarToast('Agrega al menos un producto al carrito antes de guardar la factura.', 'error');
    return;
  }

  const selCliente = document.getElementById('selectCliente');
  const selMetodo  = document.getElementById('selectMetodoPago');
  const selTipoNcf = document.getElementById('selectTipoNcf');
  const inputRef   = document.getElementById('inputReferenciaPago');

  const clienteId   = selCliente ? selCliente.value : 'CONSUMIDOR_FINAL';
  const tipoNcf     = selTipoNcf ? selTipoNcf.value : 'B02';
  const origenVenta = pedidoDeliveryConfig.activo ? 'DELIVERY' : 'MOSTRADOR';

  const optMetodo     = selMetodo ? selMetodo.options[selMetodo.selectedIndex] : null;
  const condicionPago = optMetodo ? (optMetodo.getAttribute('data-condicion') || 'CONTADO') : 'CONTADO';
  const reqRef        = optMetodo ? (optMetodo.getAttribute('data-req-ref') === '1') : false;
  const metodoPagoId  = selMetodo ? selMetodo.value : '';

  if (tipoNcf === 'B01' && clienteId === 'CONSUMIDOR_FINAL') {
    mostrarToast('Para emitir un Comprobante B01 (Crédito Fiscal) debes seleccionar un cliente con RNC o Cédula.', 'error');
    return;
  }

  if (condicionPago === 'CREDITO' && clienteId === 'CONSUMIDOR_FINAL') {
    mostrarToast('Para facturar a Crédito Comercial debes seleccionar un cliente registrado.', 'error');
    return;
  }

  if (condicionPago === 'CONTADO' && reqRef && (!inputRef || inputRef.value.trim() === '')) {
    mostrarToast('Por favor ingresa el número de referencia o voucher del pago.', 'error');
    if (inputRef) inputRef.focus();
    return;
  }

  if (origenVenta === 'DELIVERY') {
    if (!pedidoDeliveryConfig.zona_id || !pedidoDeliveryConfig.repartidor_id || !pedidoDeliveryConfig.direccion_destino) {
      mostrarToast('Completa los datos del Pedido a Domicilio (Zona, Repartidor y Dirección).', 'error');
      abrirModalPedidoDelivery();
      return;
    }
  }

  const btnGuardar = document.getElementById('btnGuardarFactura');
  if (btnGuardar) {
    btnGuardar.disabled = true;
    btnGuardar.textContent = 'Procesando...';
  }

  try {
    const payload = {
      accion: 'guardar_factura',
      cliente_id: clienteId,
      tipo_ncf: tipoNcf,
      condicion_pago: condicionPago,
      metodo_pago_id: metodoPagoId,
      origen_venta: origenVenta,
      monto_recibido: 0,
      referencia_pago: inputRef ? inputRef.value.trim() : '',
      cotizacion_origen: cotizacionOrigenId,
      zona_id: pedidoDeliveryConfig.activo ? pedidoDeliveryConfig.zona_id : '',
      repartidor_id: pedidoDeliveryConfig.activo ? pedidoDeliveryConfig.repartidor_id : '',
      direccion_destino: pedidoDeliveryConfig.activo ? pedidoDeliveryConfig.direccion_destino : '',
      partidas: carrito
    };

    const resp = await fetch('procesar_facturacion.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await resp.json();

    if (!data.ok) {
      mostrarToast(data.mensaje || 'No se pudo registrar la factura.', 'error');
      if (btnGuardar) {
        btnGuardar.disabled = false;
        btnGuardar.textContent = 'Guardar Factura';
      }
      return;
    }

    mostrarToast(data.mensaje || 'Factura emitida exitosamente.');
    carrito = [];
    desvincularCotizacion();
    quitarConfiguracionDelivery();
    renderizarCarrito();

    setTimeout(() => {
      window.location.reload();
    }, 1200);
  } catch (err) {
    mostrarToast('Error de comunicación al guardar la factura.', 'error');
    if (btnGuardar) {
      btnGuardar.disabled = false;
      btnGuardar.textContent = 'Guardar Factura';
    }
  }
}

/* ==========================================================================
   UTILIDADES
   ========================================================================== */
function escaparHtml(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
