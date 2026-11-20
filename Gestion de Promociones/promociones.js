/**
 * Controlador Frontend • Gestión de Promociones y Carrito de Reglas
 * House & Ice • Sistema Administrativo (ERP Licorero)
 */

let carritoReglas = [];
let indiceReglaEditando = -1;

function actualizarControlesDinamicos() {
  const tipo = document.getElementById('tipo_promocion').value;

  const bloqueHorario = document.getElementById('bloqueHorarioCabecera');
  const bloqueMontoMin = document.getElementById('bloqueMontoMinimoCabecera');
  const hintTipo = document.getElementById('hintTipoPromocion');

  const filaAlcance = document.getElementById('filaAlcanceRegla');
  const filaBeneficio = document.getElementById('filaBeneficioRegla');
  const grupoProductoA = document.getElementById('grupoProductoA');
  const grupoMarca = document.getElementById('grupoMarca');
  const grupoCategoria = document.getElementById('grupoCategoria');
  const grupoCantidad = document.getElementById('grupoCantidad');
  const grupoProductoB = document.getElementById('grupoProductoB');
  const labelProductoA = document.getElementById('labelProductoA');
  const labelCantidad = document.getElementById('labelCantidad');
  const labelBeneficioPorc = document.getElementById('labelBeneficioPorcentaje');
  const labelBeneficioMonto = document.getElementById('labelBeneficioMonto');

  // Reset visibilidades base
  bloqueHorario.style.display = 'none';
  bloqueMontoMin.style.display = 'none';
  if (filaAlcance) {
    filaAlcance.style.display = '';
    filaAlcance.className = 'form-row-3';
  }
  if (filaBeneficio) {
    filaBeneficio.className = 'form-row-3';
  }
  grupoProductoA.style.display = '';
  grupoMarca.style.display = '';
  grupoCategoria.style.display = '';
  if (grupoCantidad) grupoCantidad.style.display = '';
  grupoProductoB.style.display = 'none';

  labelProductoA.textContent = 'Producto Específico (Opcional)';
  labelCantidad.textContent = 'Cantidad Mínima Requerida *';
  if (labelBeneficioPorc) labelBeneficioPorc.textContent = '% Descuento (1% - 100%)';
  if (labelBeneficioMonto) labelBeneficioMonto.textContent = 'o Rebaja / Monto Fijo (RD$)';

  if (tipo === 'VOLUMEN') {
    grupoMarca.style.display = 'none';
    document.getElementById('regla_marca_id').value = '';
    if (filaAlcance) filaAlcance.className = 'form-row-2';
    hintTipo.textContent = 'Descuento automático al comprar X o más unidades';
    labelProductoA.textContent = 'Producto por Volumen';
    labelCantidad.textContent = 'Cantidad Mínima (Ej. 3 o más) *';
    if (parseInt(document.getElementById('regla_cantidad').value, 10) < 2) {
      document.getElementById('regla_cantidad').value = '3';
    }
  } else if (tipo === 'HAPPY_HOUR') {
    bloqueHorario.style.display = '';
    hintTipo.textContent = 'Oferta por franja horaria y días (por Marca, Categoría o Producto)';
    labelProductoA.textContent = 'Producto (o dejar en Todos si es por Marca)';
    document.getElementById('regla_cantidad').value = '1';
  } else if (tipo === 'CROSS_SELLING') {
    grupoMarca.style.display = 'none';
    grupoCategoria.style.display = 'none';
    grupoProductoB.style.display = '';
    document.getElementById('regla_marca_id').value = '';
    document.getElementById('regla_categoria_id').value = '';
    hintTipo.textContent = 'Combo / Venta Cruzada: Compra Producto A y recibe descuento en Producto B';
    labelProductoA.textContent = 'Producto A (Condición de Compra) *';
    labelCantidad.textContent = 'Cantidad de Producto A *';
    document.getElementById('regla_cantidad').value = '1';
  } else if (tipo === 'FECHAS') {
    hintTipo.textContent = 'Oferta de temporada orientada a Marcas completas, Categorías o Productos';
    labelProductoA.textContent = 'Producto (o elegir Marca / Categoría)';
    document.getElementById('regla_cantidad').value = '1';
  } else if (tipo === 'CUPON_TICKET') {
    bloqueMontoMin.style.display = '';
    hintTipo.textContent = 'Genera cupón en el ticket con beneficio por Porcentaje (%) o Monto Fijo (RD$)';
    // En CUPON_TICKET no se pide Producto, Marca, Categoría ni Cantidad: solo Beneficio Porcentaje o Beneficio Monto
    if (filaAlcance) filaAlcance.style.display = 'none';
    if (grupoCantidad) grupoCantidad.style.display = 'none';
    if (filaBeneficio) filaBeneficio.className = 'form-row-2';
    document.getElementById('regla_producto_a').value = '0';
    document.getElementById('regla_marca_id').value = '';
    document.getElementById('regla_categoria_id').value = '';
    document.getElementById('regla_producto_b').value = '';
    document.getElementById('regla_cantidad').value = '1';
    if (labelBeneficioPorc) labelBeneficioPorc.textContent = '🎟️ Beneficio Porcentaje del Cupón (1% - 100%) *';
    if (labelBeneficioMonto) labelBeneficioMonto.textContent = '💵 o Beneficio Monto Fijo del Cupón (RD$) *';
  }

  renderizarCarritoReglas();
}

function limpiarControlesRegla() {
  const tipo = document.getElementById('tipo_promocion').value;
  indiceReglaEditando = -1;
  document.getElementById('regla_producto_a').value = '0';
  document.getElementById('regla_marca_id').value = '';
  document.getElementById('regla_categoria_id').value = '';
  document.getElementById('regla_producto_b').value = '';
  document.getElementById('regla_cantidad').value = (tipo === 'VOLUMEN') ? '3' : '1';
  document.getElementById('regla_beneficio_porcentaje').value = '';
  document.getElementById('regla_beneficio_monto_fijo').value = '';

  const titulo = document.getElementById('tituloConstructorRegla');
  const btnAccion = document.getElementById('btnAccionRegla');
  const btnNueva = document.getElementById('btnNuevaReglaAdicional');
  if (titulo) titulo.textContent = 'Controles Dinámicos • Configurar Regla a Agregar';
  if (btnAccion) btnAccion.textContent = '+ Agregar Regla al Carrito';
  if (btnNueva) btnNueva.style.display = 'none';
}

function prepararNuevaRegla() {
  limpiarControlesRegla();
  renderizarCarritoReglas();
}

function cargarReglaEnControles(index) {
  const r = carritoReglas[index];
  if (!r) return;

  indiceReglaEditando = index;
  document.getElementById('regla_producto_a').value = String(r.condicion_producto_id || 0);
  document.getElementById('regla_marca_id').value = r.marca_id ? String(r.marca_id) : '';
  document.getElementById('regla_categoria_id').value = r.condicion_categoria_id ? String(r.condicion_categoria_id) : '';
  document.getElementById('regla_producto_b').value = r.beneficio_producto_id ? String(r.beneficio_producto_id) : '';
  document.getElementById('regla_cantidad').value = String(r.cantidad_requerida || 1);
  document.getElementById('regla_beneficio_porcentaje').value = (r.beneficio_porcentaje !== null && r.beneficio_porcentaje !== undefined) ? String(r.beneficio_porcentaje) : '';
  document.getElementById('regla_beneficio_monto_fijo').value = (r.beneficio_monto_fijo !== null && r.beneficio_monto_fijo !== undefined) ? String(r.beneficio_monto_fijo) : '';

  const titulo = document.getElementById('tituloConstructorRegla');
  const btnAccion = document.getElementById('btnAccionRegla');
  const btnNueva = document.getElementById('btnNuevaReglaAdicional');
  if (titulo) titulo.textContent = `Controles Dinámicos • Editando Regla #${index + 1}`;
  if (btnAccion) btnAccion.textContent = `✓ Actualizar Regla #${index + 1}`;
  if (btnNueva) btnNueva.style.display = 'inline-flex';

  renderizarCarritoReglas();
}

function agregarReglaAlCarrito(silencioso = false) {
  const tipo = document.getElementById('tipo_promocion').value;

  const selProdA = document.getElementById('regla_producto_a');
  const selMarca = document.getElementById('regla_marca_id');
  const selCat = document.getElementById('regla_categoria_id');
  const selProdB = document.getElementById('regla_producto_b');

  const esCupon = (tipo === 'CUPON_TICKET');

  const prodAId = esCupon ? 0 : parseInt(selProdA.value || '0', 10);
  const prodATexto = prodAId > 0 ? (selProdA.options[selProdA.selectedIndex]?.dataset?.texto || selProdA.options[selProdA.selectedIndex]?.text || '') : '';

  const marcaId = (!esCupon && tipo !== 'VOLUMEN' && tipo !== 'CROSS_SELLING' && selMarca.value) ? parseInt(selMarca.value, 10) : null;
  const marcaTexto = marcaId ? (selMarca.options[selMarca.selectedIndex]?.dataset?.texto || selMarca.options[selMarca.selectedIndex]?.text || '') : '';

  const categoriaId = (!esCupon && selCat.value) ? parseInt(selCat.value, 10) : null;
  const categoriaTexto = categoriaId ? (selCat.options[selCat.selectedIndex]?.dataset?.texto || selCat.options[selCat.selectedIndex]?.text || '') : '';

  const prodBId = (tipo === 'CROSS_SELLING' && selProdB.value) ? parseInt(selProdB.value, 10) : null;
  const prodBTexto = prodBId ? (selProdB.options[selProdB.selectedIndex]?.dataset?.texto || selProdB.options[selProdB.selectedIndex]?.text || '') : '';

  const cantidad = esCupon ? 1 : parseInt(document.getElementById('regla_cantidad').value || '1', 10);
  const porcStr = document.getElementById('regla_beneficio_porcentaje').value.trim();
  const montoStr = document.getElementById('regla_beneficio_monto_fijo').value.trim();

  let beneficioPorcentaje = porcStr !== '' ? parseFloat(porcStr) : null;
  if (beneficioPorcentaje !== null && !isNaN(beneficioPorcentaje) && beneficioPorcentaje > 0 && beneficioPorcentaje < 1) {
    beneficioPorcentaje = Math.round(beneficioPorcentaje * 10000) / 100;
  }
  const beneficioMontoFijo = montoStr !== '' ? parseFloat(montoStr) : null;

  // Validaciones según tipo de regla
  if (isNaN(cantidad) || cantidad < 1) {
    if (!silencioso) alert('La cantidad mínima requerida debe ser al menos 1.');
    return false;
  }

  if (tipo === 'CROSS_SELLING') {
    if (!prodAId || prodAId <= 0) {
      if (!silencioso) alert('En Cross-Selling debes seleccionar el Producto A (Condición de Compra).');
      return false;
    }
    if (!prodBId || prodBId <= 0) {
      if (!silencioso) alert('En Cross-Selling debes seleccionar el Producto B (Producto con Beneficio en Combo).');
      return false;
    }
  }

  if ((beneficioPorcentaje === null || isNaN(beneficioPorcentaje) || beneficioPorcentaje <= 0) &&
      (beneficioMontoFijo === null || isNaN(beneficioMontoFijo) || beneficioMontoFijo <= 0)) {
    if (!silencioso) alert('Debes indicar un % de Descuento (1% a 100%) o un Monto Fijo de Rebaja en RD$.');
    return false;
  }

  if (beneficioPorcentaje !== null && (beneficioPorcentaje <= 0 || beneficioPorcentaje > 100)) {
    if (!silencioso) alert('El porcentaje de descuento debe estar entre 0.5% y 100%.');
    return false;
  }

  const nuevaReglaObj = {
    condicion_producto_id: prodAId > 0 ? prodAId : 0,
    producto_a_texto: prodATexto,
    marca_id: marcaId,
    marca_texto: marcaTexto,
    condicion_categoria_id: categoriaId,
    categoria_texto: categoriaTexto,
    cantidad_requerida: cantidad,
    beneficio_producto_id: prodBId,
    producto_b_texto: prodBTexto,
    beneficio_porcentaje: beneficioPorcentaje,
    beneficio_monto_fijo: beneficioMontoFijo
  };

  if (indiceReglaEditando >= 0 && indiceReglaEditando < carritoReglas.length) {
    // Actualizar la regla que se estaba editando sin duplicarla
    carritoReglas[indiceReglaEditando] = nuevaReglaObj;
    if (!silencioso) {
      mostrarToast(`Regla #${indiceReglaEditando + 1} actualizada en el carrito.`);
    }
    cargarReglaEnControles(indiceReglaEditando);
  } else {
    // Verificar si ya existe una regla con exactamente el mismo alcance (Producto + Marca + Categoría + Producto B)
    const idxExistente = carritoReglas.findIndex(r =>
      Number(r.condicion_producto_id || 0) === Number(nuevaReglaObj.condicion_producto_id || 0) &&
      Number(r.marca_id || 0) === Number(nuevaReglaObj.marca_id || 0) &&
      Number(r.condicion_categoria_id || 0) === Number(nuevaReglaObj.condicion_categoria_id || 0) &&
      Number(r.beneficio_producto_id || 0) === Number(nuevaReglaObj.beneficio_producto_id || 0)
    );

    if (idxExistente !== -1) {
      carritoReglas[idxExistente] = nuevaReglaObj;
      cargarReglaEnControles(idxExistente);
    } else {
      carritoReglas.push(nuevaReglaObj);
      limpiarControlesRegla();
      renderizarCarritoReglas();
    }
  }

  return true;
}

function eliminarReglaDelCarrito(index) {
  carritoReglas.splice(index, 1);
  if (indiceReglaEditando === index) {
    limpiarControlesRegla();
  } else if (indiceReglaEditando > index) {
    indiceReglaEditando--;
  }
  renderizarCarritoReglas();
}

function renderizarCarritoReglas() {
  const tbody = document.getElementById('tablaCarritoReglasBody');
  const badge = document.getElementById('contadorCarritoReglas');

  badge.textContent = `${carritoReglas.length} ${carritoReglas.length === 1 ? 'regla en el carrito' : 'reglas en el carrito'}`;

  if (carritoReglas.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" style="text-align: center; padding: 20px; color: #888;">
          El carrito de reglas está vacío. Configura una regla en el paso 2 y presiona <strong>+ Agregar Regla al Carrito</strong>.
        </td>
      </tr>
    `;
    return;
  }

  const tipoActual = document.getElementById('tipo_promocion')?.value || '';
  const esCuponActual = (tipoActual === 'CUPON_TICKET');
  const montoMinActual = parseFloat(document.getElementById('monto_minimo_factura')?.value || '0');

  tbody.innerHTML = carritoReglas.map((r, idx) => {
    let alcanceHtml = '';
    if (esCuponActual) {
      const txtMin = (!isNaN(montoMinActual) && montoMinActual > 0)
        ? ` (Mín. Factura: RD$ ${montoMinActual.toFixed(2)})`
        : '';
      alcanceHtml = `🎟️ <strong>Cupón de Descuento en Factura${escapeHtml(txtMin)}</strong>`;
    } else {
      const partes = [];
      if (r.condicion_producto_id && r.condicion_producto_id > 0) {
        partes.push(`🍾 <strong>${escapeHtml(r.producto_a_texto || ('Producto #' + r.condicion_producto_id))}</strong>`);
      }
      if (r.marca_id && r.marca_id > 0) {
        partes.push(`🏷️ Marca: <strong>${escapeHtml(r.marca_texto || ('#' + r.marca_id))}</strong>`);
      }
      if (r.condicion_categoria_id && r.condicion_categoria_id > 0) {
        partes.push(`📁 Categoría: <strong>${escapeHtml(r.categoria_texto || ('#' + r.condicion_categoria_id))}</strong>`);
      }
      alcanceHtml = partes.length > 0 ? partes.join(' <span style="color:#a66e2e;">+</span> ') : '🌐 <em>Catálogo General (Todos los productos)</em>';
    }

    const cantHtml = esCuponActual
      ? '<span style="color: #8a7b70; font-weight: 600;">Por Factura</span>'
      : `<strong>&ge; ${parseInt(r.cantidad_requerida, 10)} uds.</strong>`;

    const prodBHtml = (!esCuponActual && r.beneficio_producto_id && r.beneficio_producto_id > 0)
      ? `🎁 <strong>${escapeHtml(r.producto_b_texto || ('Producto #' + r.beneficio_producto_id))}</strong>`
      : '<span style="color: #999;">—</span>';

    let beneficioHtml = '';
    if (r.beneficio_porcentaje && parseFloat(r.beneficio_porcentaje) > 0) {
      beneficioHtml = `<span class="rule-chip-benefit">-${parseFloat(r.beneficio_porcentaje)}% OFF</span>`;
    } else if (r.beneficio_monto_fijo && parseFloat(r.beneficio_monto_fijo) > 0) {
      beneficioHtml = `<span class="rule-chip-benefit">-RD$ ${parseFloat(r.beneficio_monto_fijo).toFixed(2)}</span>`;
    }

    const estaEditando = (idx === indiceReglaEditando);
    const estiloFila = estaEditando ? 'style="background: #fdf6ec;"' : '';

    return `
      <tr ${estiloFila}>
        <td><strong>${idx + 1}</strong></td>
        <td>${alcanceHtml}</td>
        <td>${cantHtml}</td>
        <td>${prodBHtml}</td>
        <td>${beneficioHtml}</td>
        <td style="text-align: right; white-space: nowrap;">
          <button type="button" class="btn-sm btn-outline" onclick="cargarReglaEnControles(${idx})" style="margin-right: 4px;">
            ${estaEditando ? 'Editando' : 'Editar'}
          </button>
          <button type="button" class="btn-sm btn-danger" onclick="eliminarReglaDelCarrito(${idx})">Eliminar</button>
        </td>
      </tr>
    `;
  }).join('');
}

function escapeHtml(str) {
  return String(str || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function seleccionarTodosDiasPromo(marcar = true) {
  document.querySelectorAll('.chk-dia-promo').forEach(chk => {
    chk.checked = Boolean(marcar);
  });
}

function marcarDiasPromocion(diasArray = [1, 2, 3, 4, 5, 6, 7]) {
  const setDias = new Set((Array.isArray(diasArray) ? diasArray : []).map(n => parseInt(n, 10)));
  document.querySelectorAll('.chk-dia-promo').forEach(chk => {
    chk.checked = setDias.has(parseInt(chk.value, 10));
  });
}

function obtenerDiasSeleccionados() {
  return Array.from(document.querySelectorAll('.chk-dia-promo:checked'))
    .map(chk => parseInt(chk.value, 10))
    .filter(n => n >= 1 && n <= 7);
}

function abrirModal() {
  document.getElementById('formAccion').value = 'crear';
  document.getElementById('modalTitulo').textContent = 'Nueva Campaña Promocional y Carrito de Reglas';
  document.getElementById('promocion_id').value = '';
  document.getElementById('nombre_promocion').value = '';
  document.getElementById('tipo_promocion').value = 'VOLUMEN';
  document.getElementById('fecha_inicio').value = new Date().toISOString().split('T')[0];
  document.getElementById('fecha_fin').value = new Date(Date.now() + 30 * 86400000).toISOString().split('T')[0];
  document.getElementById('estado_campana').value = 'ACTIVA';
  document.getElementById('hora_inicio').value = '';
  document.getElementById('hora_fin').value = '';
  marcarDiasPromocion([1, 2, 3, 4, 5, 6, 7]);
  document.getElementById('monto_minimo_factura').value = '0.00';

  carritoReglas = [];
  actualizarControlesDinamicos();
  limpiarControlesRegla();
  renderizarCarritoReglas();

  document.getElementById('modalPromo').classList.add('open');
}

function editarPromo(pr) {
  document.getElementById('formAccion').value = 'editar';
  document.getElementById('modalTitulo').textContent = 'Editar Campaña (' + pr.nombre_promocion + ')';
  document.getElementById('promocion_id').value = pr.id;
  document.getElementById('nombre_promocion').value = pr.nombre_promocion || '';
  document.getElementById('tipo_promocion').value = pr.tipo_promocion || 'VOLUMEN';
  document.getElementById('fecha_inicio').value = pr.fecha_inicio || '';
  document.getElementById('fecha_fin').value = pr.fecha_fin || '';
  document.getElementById('estado_campana').value = pr.estado || 'ACTIVA';
  document.getElementById('hora_inicio').value = pr.hora_inicio ? pr.hora_inicio.substring(0, 5) : '';
  document.getElementById('hora_fin').value = pr.hora_fin ? pr.hora_fin.substring(0, 5) : '';
  let diasCampana = [1, 2, 3, 4, 5, 6, 7];
  if (Array.isArray(pr.dias_array) && pr.dias_array.length > 0) {
    diasCampana = pr.dias_array;
  } else if (pr.dias_nums) {
    const parsed = String(pr.dias_nums).split(',').map(x => parseInt(x.trim(), 10)).filter(n => n >= 1 && n <= 7);
    if (parsed.length > 0) diasCampana = parsed;
  }
  marcarDiasPromocion(diasCampana);
  document.getElementById('monto_minimo_factura').value = parseFloat(pr.monto_minimo_factura || 0).toFixed(2);

  actualizarControlesDinamicos();
  limpiarControlesRegla();

  // Cargar las reglas existentes en el carrito temporal
  const reglasExistentes = Array.isArray(pr.reglas) ? pr.reglas : [];
  carritoReglas = reglasExistentes.map(r => ({
    condicion_producto_id: parseInt(r.condicion_producto_id || '0', 10),
    producto_a_texto: (r.producto_sku ? (r.producto_sku + ' - ' + (r.producto_nombre || '')) : ''),
    marca_id: r.marca_id ? parseInt(r.marca_id, 10) : null,
    marca_texto: r.marca_nombre || '',
    condicion_categoria_id: r.condicion_categoria_id ? parseInt(r.condicion_categoria_id, 10) : null,
    categoria_texto: r.categoria_nombre || '',
    cantidad_requerida: parseInt(r.cantidad_requerida || '1', 10),
    beneficio_producto_id: r.beneficio_producto_id ? parseInt(r.beneficio_producto_id, 10) : null,
    producto_b_texto: (r.beneficio_producto_sku ? (r.beneficio_producto_sku + ' - ' + (r.beneficio_producto_nombre || '')) : ''),
    beneficio_porcentaje: r.beneficio_porcentaje !== null && r.beneficio_porcentaje !== '' ? parseFloat(r.beneficio_porcentaje) : null,
    beneficio_monto_fijo: r.beneficio_monto_fijo !== null && r.beneficio_monto_fijo !== '' ? parseFloat(r.beneficio_monto_fijo) : null
  }));

  // Si la campaña tiene reglas, cargar automáticamente la primera regla en el Paso 2 para que se pueda editar directo
  if (carritoReglas.length > 0) {
    cargarReglaEnControles(0);
  } else {
    renderizarCarritoReglas();
  }
  document.getElementById('modalPromo').classList.add('open');
}

function guardarCampanaConReglas(e) {
  e.preventDefault();

  const nombre = document.getElementById('nombre_promocion').value.trim();
  const tipo = document.getElementById('tipo_promocion').value;
  const fechaInicio = document.getElementById('fecha_inicio').value;
  const fechaFin = document.getElementById('fecha_fin').value;
  const estado = document.getElementById('estado_campana').value;
  const horaInicio = document.getElementById('hora_inicio').value || null;
  const horaFin = document.getElementById('hora_fin').value || null;
  const diasSeleccionados = obtenerDiasSeleccionados();
  const diasSemana = (tipo === 'HAPPY_HOUR') ? diasSeleccionados : [1, 2, 3, 4, 5, 6, 7];
  const montoMinimo = parseFloat(document.getElementById('monto_minimo_factura').value || '0');

  if (!nombre) {
    alert('El nombre de la campaña promocional es obligatorio.');
    return;
  }

  if (tipo === 'HAPPY_HOUR') {
    if (!horaInicio || !horaFin) {
      alert('Para una campaña de tipo HAPPY_HOUR debes especificar la Hora de Inicio y la Hora de Fin.');
      return;
    }
    if (diasSeleccionados.length === 0) {
      alert('Para una campaña de tipo HAPPY_HOUR debes marcar al menos un día de la semana.');
      return;
    }
  }

  // Si el usuario está editando una regla en el Paso 2 o escribió un descuento en el Paso 2, aplicarlo automáticamente antes de enviar
  const porcPend = document.getElementById('regla_beneficio_porcentaje').value.trim();
  const montoPend = document.getElementById('regla_beneficio_monto_fijo').value.trim();
  if (indiceReglaEditando >= 0 && (porcPend !== '' || montoPend !== '')) {
    const ok = agregarReglaAlCarrito(true);
    if (!ok) {
      agregarReglaAlCarrito(false);
      return;
    }
  } else if (carritoReglas.length === 0 && (porcPend !== '' || montoPend !== '')) {
    agregarReglaAlCarrito();
  }

  if (carritoReglas.length === 0) {
    alert('Debes agregar al menos una regla al "Carrito de Reglas" (Paso 2 → + Agregar Regla al Carrito) antes de guardar la campaña.');
    return;
  }

  // Empaquetar cabecera y carrito de reglas en un objeto JSON
  const payload = {
    accion: document.getElementById('formAccion').value,
    promocion_id: document.getElementById('promocion_id').value || null,
    nombre_promocion: nombre,
    tipo_promocion: tipo,
    fecha_inicio: fechaInicio,
    fecha_fin: fechaFin,
    hora_inicio: (tipo === 'HAPPY_HOUR') ? horaInicio : (horaInicio || null),
    hora_fin: (tipo === 'HAPPY_HOUR') ? horaFin : (horaFin || null),
    dias_semana: diasSemana,
    monto_minimo_factura: isNaN(montoMinimo) ? 0 : montoMinimo,
    estado: estado,
    reglas: carritoReglas
  };

  const btn = document.getElementById('btnGuardarCampana');
  const textoOriginal = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'Guardando...';

  fetch('procesar_promociones.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: JSON.stringify(payload)
  })
    .then(r => r.json())
    .then(data => {
      if (data.status === 'success') {
        cerrarModal(true);
        mostrarToast(data.message || 'Campaña guardada exitosamente.');
        setTimeout(() => location.reload(), 650);
      } else {
        btn.disabled = false;
        btn.textContent = textoOriginal;
        alert(data.message || 'Error al guardar la campaña promocional.');
      }
    })
    .catch(err => {
      console.error(err);
      btn.disabled = false;
      btn.textContent = textoOriginal;
      alert('Error de red al comunicarse con el servidor.');
    });
}

function cerrarModal(forzar = false) {
  if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
  document.getElementById('modalPromo').classList.remove('open');
}

function cerrarSiFondo(e) {
  if (e.target.id === 'modalPromo') cerrarModal();
}

function toggleEstadoPromo(promoId, isChecked, checkboxEl) {
  const nuevoEstado = isChecked ? 'ACTIVA' : 'INACTIVA';

  fetch('procesar_promociones.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: JSON.stringify({
      accion: 'cambiar_estado',
      promocion_id: promoId,
      estado: nuevoEstado
    })
  })
    .then(r => r.json())
    .then(data => {
      if (data.status === 'success') {
        const label = checkboxEl.closest('.toggle-wrap')?.querySelector('.toggle-text');
        if (label) label.textContent = isChecked ? 'Activa' : 'Inactiva';
        mostrarToast(data.message);
      } else {
        checkboxEl.checked = !isChecked;
        alert(data.message || 'Error al actualizar el estado.');
      }
    })
    .catch(err => {
      console.error(err);
      checkboxEl.checked = !isChecked;
      alert('Error al comunicarse con el servidor.');
    });
}

function filtrarTabla() {
  const q = document.getElementById('buscador').value.toLowerCase();
  const filas = document.querySelectorAll('.fila-promo');
  let visibles = 0;
  filas.forEach(f => {
    const texto = f.getAttribute('data-texto') || '';
    if (texto.includes(q)) {
      f.style.display = '';
      visibles++;
    } else {
      f.style.display = 'none';
    }
  });
  document.getElementById('footerInfo').textContent = `Mostrando ${visibles} campañas`;
}

function mostrarToast(msg) {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3000);
}

(() => {
  const _urlMsg = new URLSearchParams(window.location.search).get('msg');
  if (_urlMsg && typeof mostrarToast === 'function') mostrarToast(_urlMsg);

  const elNombreCamp = document.getElementById('nombre_promocion');
  if (elNombreCamp) {
    elNombreCamp.addEventListener('input', function () {
      if (this.value.length > 0) {
        const pos = this.selectionStart;
        this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1);
        if (typeof pos === 'number') this.setSelectionRange(pos, pos);
      }
    });
  }
})();

