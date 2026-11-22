/**
 * ============================================================================
 * LÓGICA FRONTEND: DESPACHO DELIVERY (PEDIDOS EN VIVO, HORARIOS Y ZONAS)
 * Archivo: Ventas y Facturacion/Delivery/delivery.js
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

const _datosDelivery = document.getElementById("datosDelivery");
const puedeConfig = _datosDelivery ? (_datosDelivery.dataset.puedeConfig === "1") : false;
let pedidosData = _datosDelivery ? JSON.parse(_datosDelivery.dataset.pedidos || "[]") : [];
let horariosData = _datosDelivery ? JSON.parse(_datosDelivery.dataset.horarios || "[]") : [];
let zonasAsignadasData = _datosDelivery ? JSON.parse(_datosDelivery.dataset.zonasAsignadas || "[]") : [];

let pestanaActiva = "pedidos"; // 'pedidos' | 'horarios' | 'zonas'

const DIAS_SEMANA = {
  1: "Lunes", 2: "Martes", 3: "Miércoles", 4: "Jueves",
  5: "Viernes", 6: "Sábado", 7: "Domingo"
};

const ESTADOS_PEDIDO = {
  PENDIENTE: { label: "PENDIENTE", bg: "#fff3e0", color: "#e65100" },
  EN_CAMINO: { label: "EN CAMINO", bg: "#e3f2fd", color: "#1565c0" },
  ENTREGADO: { label: "ENTREGADO", bg: "#e8f5e9", color: "#2e7d32" },
  CANCELADO: { label: "CANCELADO", bg: "#ffebee", color: "#c62828" }
};

// Referencias DOM
const tabPedidos = document.getElementById("tabPedidos");
const tabHorarios = document.getElementById("tabHorarios");
const tabZonas = document.getElementById("tabZonas");
const badgeTabPedidos = document.getElementById("badgeTabPedidos");
const badgeTabHorarios = document.getElementById("badgeTabHorarios");
const badgeTabZonas = document.getElementById("badgeTabZonas");
const btnNuevo = document.getElementById("btnNuevo");
const kpiGridDelivery = document.getElementById("kpiGridDelivery");
const filtroEstadoPedido = document.getElementById("filtroEstadoPedido");
const cardTitle = document.getElementById("cardTitle");
const badgeCount = document.getElementById("badgeCount");
const inputBuscar = document.getElementById("inputBuscar");
const tablaHead = document.getElementById("tablaHead");
const tablaBody = document.getElementById("tablaBody");
const footerCount = document.getElementById("footerCount");
const footerSchema = document.getElementById("footerSchema");

// Modales Horario y Zona (solo presentes para Admin/Supervisor)
const modalHorario = document.getElementById("modalHorario");
const formHorario = document.getElementById("formHorario");
const modalHorarioTitle = document.getElementById("modalHorarioTitle");
const horOrigRepartidorId = document.getElementById("horOrigRepartidorId");
const horOrigDiaSemana = document.getElementById("horOrigDiaSemana");
const horOrigHoraInicio = document.getElementById("horOrigHoraInicio");
const horRepartidorId = document.getElementById("horRepartidorId");
const horHoraInicio = document.getElementById("horHoraInicio");
const horHoraFin = document.getElementById("horHoraFin");
const btnGuardarHorario = document.getElementById("btnGuardarHorario");

const modalZonaAsignacion = document.getElementById("modalZonaAsignacion");
const formZonaAsignacion = document.getElementById("formZonaAsignacion");
const modalZonaTitle = document.getElementById("modalZonaTitle");
const zonOrigRepartidorId = document.getElementById("zonOrigRepartidorId");
const zonOrigZonaId = document.getElementById("zonOrigZonaId");
const zonRepartidorId = document.getElementById("zonRepartidorId");
const zonZonaId = document.getElementById("zonZonaId");
const zonEsRutaPrincipal = document.getElementById("zonEsRutaPrincipal");
const btnGuardarZona = document.getElementById("btnGuardarZona");

document.addEventListener("DOMContentLoaded", () => {
  const params = new URLSearchParams(window.location.search);
  const tabParam = params.get("tab");
  if (puedeConfig && tabParam === "horarios") {
    cambiarPestana("horarios");
  } else if (puedeConfig && tabParam === "zonas") {
    cambiarPestana("zonas");
  } else {
    cambiarPestana("pedidos");
  }

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      cerrarModalDetallePedido();
    }
  });
});

// ============================================================================
// NAVEGACIÓN ENTRE PESTAÑAS
// ============================================================================
function cambiarPestana(pestana) {
  if (!puedeConfig && pestana !== "pedidos") {
    pestana = "pedidos";
  }
  pestanaActiva = pestana;
  if (inputBuscar) inputBuscar.value = "";

  [tabPedidos, tabHorarios, tabZonas].forEach((t) => {
    if (t) t.classList.remove("active");
  });

  if (pestana === "pedidos") {
    if (tabPedidos) tabPedidos.classList.add("active");
    if (btnNuevo) btnNuevo.style.display = "none";
    if (kpiGridDelivery) kpiGridDelivery.style.display = "grid";
    if (filtroEstadoPedido) filtroEstadoPedido.style.display = "";
    cardTitle.textContent = "Monitor de Pedidos a Domicilio";
    inputBuscar.placeholder = "Buscar por factura, cliente, zona, dirección o motorista...";
    footerSchema.innerHTML = "House &amp; Ice • Despacho en Puerta, Tránsito y Auditoría de Eficiencia";
  } else if (pestana === "horarios") {
    if (tabHorarios) tabHorarios.classList.add("active");
    if (btnNuevo) {
      btnNuevo.style.display = "inline-flex";
      btnNuevo.textContent = "+ Asignar Horario de Trabajo";
    }
    if (kpiGridDelivery) kpiGridDelivery.style.display = "none";
    if (filtroEstadoPedido) filtroEstadoPedido.style.display = "none";
    cardTitle.textContent = "Horarios de Trabajo Asignados";
    inputBuscar.placeholder = "Buscar horario por repartidor, código, día o vehículo...";
    footerSchema.innerHTML = "House &amp; Ice • Control de Horarios y Turnos de Reparto";
  } else {
    if (tabZonas) tabZonas.classList.add("active");
    if (btnNuevo) {
      btnNuevo.style.display = "inline-flex";
      btnNuevo.textContent = "+ Asignar Zona a Repartidor";
    }
    if (kpiGridDelivery) kpiGridDelivery.style.display = "none";
    if (filtroEstadoPedido) filtroEstadoPedido.style.display = "none";
    cardTitle.textContent = "Zonas de Delivery Asignadas a Repartidores";
    inputBuscar.placeholder = "Buscar asignación por repartidor, código o nombre de zona...";
    footerSchema.innerHTML = "House &amp; Ice • Cobertura de Sectores y Rutas de Entrega";
  }

  actualizarBadgesYKpis();
  renderTabla();
}

function actualizarBadgesYKpis() {
  if (badgeTabPedidos) badgeTabPedidos.textContent = pedidosData.length;
  if (badgeTabHorarios) badgeTabHorarios.textContent = horariosData.length;
  if (badgeTabZonas) badgeTabZonas.textContent = zonasAsignadasData.length;

  let pend = 0, cam = 0, ent = 0, ret = 0;
  pedidosData.forEach((p) => {
    const e = String(p.estado_delivery || "").toUpperCase();
    if (e === "PENDIENTE") pend++;
    else if (e === "EN_CAMINO") cam++;
    else if (e === "ENTREGADO") {
      ent++;
      if (p.minutos_viaje !== null && Number(p.minutos_viaje) > Number(p.tiempo_promedio_min || 30)) {
        ret++;
      }
    }
  });

  const setEl = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
  setEl("kpiValPendientes", pend);
  setEl("kpiValEnCamino", cam);
  setEl("kpiValEntregados", ent);
  setEl("kpiValRetrasos", ret);
}

function filtrarDatos() {
  renderTabla(inputBuscar ? inputBuscar.value : "");
}

function renderTabla(filtro = "") {
  const q = filtro.toLowerCase().trim();
  if (pestanaActiva === "pedidos") {
    renderTablaPedidos(q);
  } else if (pestanaActiva === "horarios") {
    renderTablaHorarios(q);
  } else {
    renderTablaZonas(q);
  }
  actualizarBadgesYKpis();
}

// ============================================================================
// PESTAÑA 1: MONITOR DE PEDIDOS Y DESPACHO EN VIVO (FASES 3 Y 4)
// ============================================================================
function renderTablaPedidos(q) {
  tablaHead.innerHTML = `
    <tr>
      <th>FACTURA / FECHA</th>
      <th>CLIENTE Y DIRECCIÓN</th>
      <th>ZONA / REPARTIDOR</th>
      <th class="text-center">TIEMPOS / EFICIENCIA</th>
      <th class="text-center">ESTADO</th>
      <th style="text-align: right;">ACCIONES</th>
    </tr>
  `;

  const estFiltro = (filtroEstadoPedido ? filtroEstadoPedido.value : "").toUpperCase();

  const filtrados = pedidosData.filter((p) => {
    const est = String(p.estado_delivery || "").toUpperCase();
    if (estFiltro && est !== estFiltro) return false;
    if (!q) return true;
    return (
      (p.numero_factura || "").toLowerCase().includes(q) ||
      (p.ncf || "").toLowerCase().includes(q) ||
      (p.cliente_nombre || "").toLowerCase().includes(q) ||
      (p.direccion_destino || "").toLowerCase().includes(q) ||
      (p.zona_nombre || "").toLowerCase().includes(q) ||
      (p.repartidor_nombre || "").toLowerCase().includes(q) ||
      (p.repartidor_codigo || "").toLowerCase().includes(q)
    );
  });

  tablaBody.innerHTML = "";

  if (filtrados.length === 0) {
    tablaBody.innerHTML = `
      <tr>
        <td colspan="6" style="text-align: center; padding: 32px; color: var(--text-muted);">
          No hay pedidos de delivery que coincidan con el filtro actual. Emite una venta con modalidad <strong>🛵 Pedido a Domicilio</strong> desde Facturación (POS).
        </td>
      </tr>
    `;
    badgeCount.textContent = "0 pedidos";
    footerCount.textContent = "Mostrando 0 pedidos de delivery";
    return;
  }

  filtrados.forEach((p) => {
    const est = String(p.estado_delivery || "PENDIENTE").toUpperCase();
    const st = ESTADOS_PEDIDO[est] || { label: est, bg: "#eadecd", color: "#5a3818" };
    const meta = Number(p.tiempo_promedio_min || 30);
    const mins = (p.minutos_viaje !== null && p.minutos_viaje !== undefined) ? Number(p.minutos_viaje) : null;

    let tiempoHtml = `<span style="font-size:0.78rem; color:#8a7b70;">Meta: ~${meta} min</span>`;
    if (est === "EN_CAMINO" && mins !== null) {
      const tarde = mins > meta;
      tiempoHtml = `
        <div style="font-size:0.78rem; font-weight:700; color:${tarde ? "#c62828" : "#1565c0"};">
          ⏱️ ${mins} min en ruta
        </div>
        <div style="font-size:0.72rem; color:#8a7b70;">Meta: ${meta} min • Salió: ${escapeHtml(p.hora_despacho_fmt || "")}</div>
      `;
    } else if (est === "ENTREGADO" && mins !== null) {
      const dif = Number((mins - meta).toFixed(1));
      const ok = dif <= 0;
      tiempoHtml = `
        <span style="display:inline-block; padding:2px 8px; border-radius:99px; font-size:0.74rem; font-weight:700; background:${ok ? "#e8f5e9" : "#ffebee"}; color:${ok ? "#2e7d32" : "#c62828"};">
          ${ok ? "🟢 A tiempo" : `🔴 Retraso +${dif}m`} (${mins}m / ${meta}m)
        </span>
        <div style="font-size:0.71rem; color:#8a7b70; margin-top:2px;">Entregó: ${escapeHtml(p.hora_entrega_fmt || "")}</div>
      `;
    } else if (est === "PENDIENTE") {
      tiempoHtml = `
        <div style="font-size:0.78rem; color:#e65100; font-weight:600;">Esperando salida</div>
        <div style="font-size:0.72rem; color:#8a7b70;">Meta zona: ${meta} min</div>
      `;
    }

    let accionesHtml = `
      <button type="button" class="btn-sm btn-outline" onclick="verDetallePedido(${Number(p.id)})">
        Ver Detalle
      </button>
    `;
    if (est === "PENDIENTE") {
      accionesHtml += `
        <button type="button" class="btn-sm btn-gold" onclick="despacharPedido(${Number(p.id)}, '${escapeJs(p.numero_factura)}')">
          🛵 Despachar
        </button>
      `;
    } else if (est === "EN_CAMINO") {
      accionesHtml += `
        <button type="button" class="btn-sm btn-gold" style="background:#2e7d32; border-color:#2e7d32; color:#fff;"
                onclick="marcarPedidoEntregado(${Number(p.id)}, '${escapeJs(p.numero_factura)}')">
          ✅ Entregado
        </button>
      `;
    }

    const totalFmt = Number(p.monto_total || 0).toLocaleString("es-DO", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });

    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td style="white-space: nowrap;">
        <div style="font-weight: 800; color: #a66e2e; font-family: monospace;">${escapeHtml(p.numero_factura)}</div>
        <div style="font-size: 0.74rem; color: #5a3818; font-weight: 700;">RD$ ${totalFmt} • ${escapeHtml(p.condicion_pago)}</div>
        <div style="font-size: 0.72rem; color: #8a7b70;">${escapeHtml(p.fecha_factura_fmt || "")}</div>
      </td>
      <td>
        <div style="font-weight: 700; color: var(--text-dark);">${escapeHtml(p.cliente_nombre)}</div>
        ${p.cliente_telefono ? `<div style="font-size:0.74rem; color:#794a15; font-weight:600;">📞 ${escapeHtml(p.cliente_telefono)}</div>` : ""}
        <div style="font-size: 0.78rem; color: #555; max-width: 280px;">📍 ${escapeHtml(p.direccion_destino)}</div>
      </td>
      <td>
        <div style="font-weight: 700; color: var(--text-dark); font-size: 0.85rem;">🗺️ ${escapeHtml(p.zona_nombre)}</div>
        <div style="font-size: 0.8rem; color: #4a3b32; margin-top: 2px;">
          <strong>${escapeHtml(p.repartidor_nombre)}</strong>
          <span class="code-inline">${escapeHtml(p.repartidor_codigo)}</span>
        </div>
        <div style="font-size: 0.72rem; color: #8a7b70;">
          🛵 ${escapeHtml(p.tipo_vehiculo)}${p.placa_vehiculo ? ` [${escapeHtml(p.placa_vehiculo)}]` : ""}
        </div>
      </td>
      <td class="text-center">${tiempoHtml}</td>
      <td class="text-center" style="white-space: nowrap;">
        <span class="badge" style="background:${st.bg}; color:${st.color}; font-weight:800; padding:4px 9px; border-radius:99px;">
          ${escapeHtml(st.label)}
        </span>
        <div style="font-size: 0.7rem; color: #8a7b70; margin-top: 3px;">Fac: ${escapeHtml(p.estado_factura)}</div>
      </td>
      <td style="text-align: right; white-space: nowrap;">
        <div class="action-btns">${accionesHtml}</div>
      </td>
    `;
    tablaBody.appendChild(tr);
  });

  badgeCount.textContent = `${filtrados.length} pedidos`;
  footerCount.textContent = `Mostrando ${filtrados.length} de ${pedidosData.length} pedidos de delivery`;
}

async function despacharPedido(pedidoId, numFactura) {
  if (!confirm(`¿Confirmas la salida del motorista para ${numFactura}?\n\nEl pedido pasará a EN_CAMINO y la factura quedará bloqueada en EN_TRANSITO.`)) {
    return;
  }

  const fd = new FormData();
  fd.append("action", "despachar_pedido");
  fd.append("pedido_id", pedidoId);

  try {
    const res = await fetch("procesar_delivery.php", { method: "POST", body: fd });
    const data = await res.json();
    if (data.status === "success") {
      if (Array.isArray(data.pedidos)) pedidosData = data.pedidos;
      showToast(data.message || "Pedido despachado.");
      renderTabla(inputBuscar ? inputBuscar.value : "");
    } else {
      showToast(data.message || "No se pudo despachar el pedido.", true);
    }
  } catch (err) {
    showToast("Error de conexión al despachar el pedido.", true);
  }
}

async function marcarPedidoEntregado(pedidoId, numFactura) {
  if (!confirm(`¿Confirmas que el pedido ${numFactura} fue ENTREGADO al cliente?\n\nSe sellará la hora de entrega, se medirá la eficiencia y se liberará capacidad del repartidor.`)) {
    return;
  }

  const fd = new FormData();
  fd.append("action", "marcar_entregado");
  fd.append("pedido_id", pedidoId);

  try {
    const res = await fetch("procesar_delivery.php", { method: "POST", body: fd });
    const data = await res.json();
    if (data.status === "success") {
      if (Array.isArray(data.pedidos)) pedidosData = data.pedidos;
      showToast(data.message || "Entrega confirmada.");
      renderTabla(inputBuscar ? inputBuscar.value : "");
    } else {
      showToast(data.message || "No se pudo confirmar la entrega.", true);
    }
  } catch (err) {
    showToast("Error de conexión al confirmar la entrega.", true);
  }
}

async function verDetallePedido(pedidoId) {
  const modal = document.getElementById("modalDetallePedido");
  const tit = document.getElementById("detPedTitulo");
  const sub = document.getElementById("detPedSub");
  const body = document.getElementById("detPedBody");

  tit.textContent = "Detalle del Pedido Delivery";
  sub.textContent = "Consultando hoja de ruta...";
  body.innerHTML = '<p style="color:#888;">Cargando información...</p>';
  modal.classList.add("open");

  try {
    const res = await fetch(`procesar_delivery.php?action=detalle_pedido&pedido_id=${encodeURIComponent(pedidoId)}`);
    const data = await res.json();
    if (data.status !== "success" || !data.pedido) {
      body.innerHTML = `<p style="color:#c62828;">${escapeHtml(data.message || "Error al consultar detalle.")}</p>`;
      return;
    }

    const p = data.pedido;
    const dets = Array.isArray(data.detalles) ? data.detalles : [];
    tit.textContent = `Pedido • ${p.numero_factura} (${p.ncf})`;
    sub.textContent = `Estado Delivery: ${p.estado_delivery} • Factura: ${p.estado_factura}`;

    const filas = dets.map((d) => `
      <tr>
        <td><strong>${escapeHtml(d.sku)}</strong> — ${escapeHtml(d.producto_nombre)}</td>
        <td>${escapeHtml(d.presentacion)}</td>
        <td style="text-align:center;"><strong>${d.cantidad_presentacion}</strong> (${d.cantidad_unidades} uds.)</td>
        <td style="text-align:right; font-family:monospace;">RD$ ${Number(d.monto_total || 0).toFixed(2)}</td>
      </tr>
    `).join("");

    body.innerHTML = `
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; background:#faf7f2; border:1px solid #e7dcc8; border-radius:8px; padding:12px 14px; margin-bottom:14px; font-size:0.86rem;">
        <div><strong>Cliente:</strong> ${escapeHtml(p.cliente_nombre)}</div>
        <div><strong>Teléfono:</strong> ${escapeHtml(p.cliente_telefono)}</div>
        <div style="grid-column:1 / -1;"><strong>Dirección de Entrega:</strong> ${escapeHtml(p.direccion_destino)}</div>
        <div><strong>Zona / Tarifa:</strong> ${escapeHtml(p.zona_nombre)} (RD$ ${Number(p.tarifa_envio || 0).toFixed(2)})</div>
        <div><strong>Tiempo Meta:</strong> ${Number(p.tiempo_promedio_min || 30)} minutos</div>
        <div><strong>Motorista:</strong> ${escapeHtml(p.repartidor_nombre)} (${escapeHtml(p.repartidor_codigo)})</div>
        <div><strong>Vehículo:</strong> ${escapeHtml(p.tipo_vehiculo)} ${p.placa_vehiculo ? `[${escapeHtml(p.placa_vehiculo)}]` : ""}</div>
        <div><strong>Hora Despacho:</strong> ${escapeHtml(p.hora_despacho_fmt || "Pendiente")}</div>
        <div><strong>Hora Entrega:</strong> ${escapeHtml(p.hora_entrega_fmt || "Pendiente")}</div>
        <div><strong>Total Factura:</strong> <strong>RD$ ${Number(p.monto_total || 0).toFixed(2)}</strong> (${escapeHtml(p.condicion_pago)})</div>
        <div><strong>Tiempo Real:</strong> ${p.minutos_viaje !== null ? `<strong>${p.minutos_viaje} min</strong>` : "—"}</div>
      </div>

      <table class="carrito-reglas-table">
        <thead>
          <tr>
            <th>Producto</th>
            <th>Empaque</th>
            <th style="text-align:center;">Cantidad</th>
            <th style="text-align:right;">Total Línea</th>
          </tr>
        </thead>
        <tbody>
          ${filas || '<tr><td colspan="4" style="text-align:center; padding:16px; color:#888;">Sin líneas registradas.</td></tr>'}
        </tbody>
      </table>
    `;
  } catch (err) {
    body.innerHTML = '<p style="color:#c62828;">Error de conexión al consultar detalle del pedido.</p>';
  }
}

function cerrarModalDetallePedido() {
  const modal = document.getElementById("modalDetallePedido");
  if (modal) modal.classList.remove("open");
}

// ============================================================================
// PESTAÑA 2 Y 3: HORARIOS DE TRABAJO Y ZONAS DE DELIVERY (PARAMETRIZACIÓN)
// ============================================================================
function renderTablaHorarios(q) {
  tablaHead.innerHTML = `
    <tr>
      <th>REPARTIDOR / EMPLEADO</th>
      <th>VEHÍCULO / ESTADO</th>
      <th class="text-center">DÍA DE LA SEMANA</th>
      <th class="text-center">HORA DE INICIO</th>
      <th class="text-center">HORA DE FIN</th>
      <th style="text-align: right;">ACCIONES</th>
    </tr>
  `;

  const filtrados = horariosData.filter((item) => {
    const diaNombre = (DIAS_SEMANA[Number(item.dia_semana)] || "").toLowerCase();
    return (
      (item.nombre_razon_social || "").toLowerCase().includes(q) ||
      (item.codigo_empleado || "").toLowerCase().includes(q) ||
      (item.tipo_vehiculo || "").toLowerCase().includes(q) ||
      (item.placa_vehiculo || "").toLowerCase().includes(q) ||
      diaNombre.includes(q) ||
      (item.hora_inicio || "").includes(q) ||
      (item.hora_fin || "").includes(q)
    );
  });

  tablaBody.innerHTML = "";
  if (filtrados.length === 0) {
    tablaBody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:30px; color:var(--text-muted);">No hay horarios asignados.</td></tr>`;
    badgeCount.textContent = "0 horarios";
    footerCount.textContent = "Mostrando 0 horarios de trabajo";
    return;
  }

  filtrados.forEach((item) => {
    const diaNum = Number(item.dia_semana);
    const diaTexto = DIAS_SEMANA[diaNum] || `Día ${diaNum}`;
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>
        <div style="font-weight:700; color:var(--text-dark);">${escapeHtml(item.nombre_razon_social)}</div>
        <div style="font-size:0.78rem; color:var(--text-muted);"><span class="code-inline">${escapeHtml(item.codigo_empleado)}</span></div>
      </td>
      <td>
        <div style="font-weight:600; font-size:0.85rem;">🛵 ${escapeHtml(item.tipo_vehiculo || "MOTOCICLETA")} ${item.placa_vehiculo ? `<span class="code-inline">[${escapeHtml(item.placa_vehiculo)}]</span>` : ""}</div>
        <div style="font-size:0.75rem; color:var(--text-muted);">Estado: <strong>${escapeHtml(item.estado_logistico || "BASE")}</strong></div>
      </td>
      <td class="text-center"><span class="badge-pill" style="background:var(--bg-badge); border:1px solid var(--border-badge); font-weight:600; padding:4px 10px; border-radius:999px;">${escapeHtml(diaTexto)}</span></td>
      <td class="text-center"><span class="code-inline" style="font-weight:600;">🕒 ${escapeHtml(item.hora_inicio)}</span></td>
      <td class="text-center"><span class="code-inline" style="font-weight:600;">🏁 ${escapeHtml(item.hora_fin)}</span></td>
      <td style="text-align:right; white-space:nowrap;">
        <div class="action-btns">
          <button type="button" class="link-edit" onclick="abrirEditarHorario('${escapeJs(item.repartidor_id)}', ${diaNum}, '${escapeJs(item.hora_inicio)}')">Editar</button>
          <button type="button" class="btn-sm btn-danger" onclick="eliminarHorario('${escapeJs(item.repartidor_id)}', ${diaNum}, '${escapeJs(item.hora_inicio)}')">Eliminar</button>
        </div>
      </td>
    `;
    tablaBody.appendChild(tr);
  });

  badgeCount.textContent = `${filtrados.length} horarios`;
  footerCount.textContent = `Mostrando ${filtrados.length} de ${horariosData.length} horarios asignados`;
}

function renderTablaZonas(q) {
  tablaHead.innerHTML = `
    <tr>
      <th>REPARTIDOR / MOTORISTA</th>
      <th>VEHÍCULO Y CAPACIDAD</th>
      <th>ZONA DE DELIVERY ASIGNADA</th>
      <th class="text-center">TARIFA / TIEMPO</th>
      <th class="text-center">¿RUTA PRINCIPAL?</th>
      <th style="text-align: right;">ACCIONES</th>
    </tr>
  `;

  const filtrados = zonasAsignadasData.filter((item) =>
    (item.nombre_razon_social || "").toLowerCase().includes(q) ||
    (item.codigo_empleado || "").toLowerCase().includes(q) ||
    (item.zona_nombre || "").toLowerCase().includes(q) ||
    (item.tipo_vehiculo || "").toLowerCase().includes(q)
  );

  tablaBody.innerHTML = "";
  if (filtrados.length === 0) {
    tablaBody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:30px; color:var(--text-muted);">No hay zonas asignadas a repartidores.</td></tr>`;
    badgeCount.textContent = "0 asignaciones";
    footerCount.textContent = "Mostrando 0 zonas asignadas";
    return;
  }

  filtrados.forEach((item) => {
    const esPrincipal = item.es_ruta_principal === true || item.es_ruta_principal === "t" || item.es_ruta_principal === 1 || item.es_ruta_principal === "1";
    const zonaIdNum = Number(item.zona_id);
    const tarifaFmt = Number(item.zona_tarifa || 0).toLocaleString("es-DO", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>
        <div style="font-weight:700; color:var(--text-dark);">${escapeHtml(item.nombre_razon_social)}</div>
        <div style="font-size:0.78rem; color:var(--text-muted);"><span class="code-inline">${escapeHtml(item.codigo_empleado)}</span></div>
      </td>
      <td>
        <div style="font-weight:600; font-size:0.85rem;">🛵 ${escapeHtml(item.tipo_vehiculo || "MOTOCICLETA")} ${item.placa_vehiculo ? `<span class="code-inline">[${escapeHtml(item.placa_vehiculo)}]</span>` : ""}</div>
        <div style="font-size:0.75rem; color:var(--text-muted);">Capacidad: <strong>${Number(item.capacidad_pedidos || 1)} pedido(s)</strong></div>
      </td>
      <td><strong style="color:var(--text-dark);">📍 ${escapeHtml(item.zona_nombre)}</strong></td>
      <td class="text-center">
        <div style="font-weight:700; color:var(--accent-gold);">RD$ ${tarifaFmt}</div>
        <div style="font-size:0.76rem; color:var(--text-muted);">⏱️ ~${Number(item.zona_tiempo || 30)} min</div>
      </td>
      <td class="text-center">
        <label class="toggle-wrap" title="Cambiar prioridad de ruta">
          <input type="checkbox" ${esPrincipal ? "checked" : ""} onchange="toggleRutaPrincipal('${escapeJs(item.repartidor_id)}', ${zonaIdNum}, this.checked)" />
          <span class="toggle-slider"></span>
          <span class="toggle-text">${esPrincipal ? "★ Principal" : "Secundaria"}</span>
        </label>
      </td>
      <td style="text-align:right; white-space:nowrap;">
        <div class="action-btns">
          <button type="button" class="link-edit" onclick="abrirEditarZona('${escapeJs(item.repartidor_id)}', ${zonaIdNum})">Editar</button>
          <button type="button" class="btn-sm btn-danger" onclick="eliminarZonaAsignada('${escapeJs(item.repartidor_id)}', ${zonaIdNum})">Eliminar</button>
        </div>
      </td>
    `;
    tablaBody.appendChild(tr);
  });

  badgeCount.textContent = `${filtrados.length} asignaciones`;
  footerCount.textContent = `Mostrando ${filtrados.length} de ${zonasAsignadasData.length} zonas asignadas`;
}

// ============================================================================
// CONTROLES DE MODALES DE HORARIOS Y ZONAS
// ============================================================================
function actualizarEstiloDias() {
  document.querySelectorAll("#gridDiasSemana .day-check-card").forEach((card) => {
    const chk = card.querySelector("input.chk-dia-horario");
    card.classList.toggle("checked", Boolean(chk && chk.checked));
  });
}

function seleccionarDiasPreset(diasArray) {
  const setDias = new Set((diasArray || []).map(Number));
  document.querySelectorAll("#gridDiasSemana input.chk-dia-horario").forEach((chk) => {
    chk.checked = setDias.has(Number(chk.value));
  });
  actualizarEstiloDias();
}

function obtenerDiasSeleccionados() {
  const seleccionados = [];
  document.querySelectorAll("#gridDiasSemana input.chk-dia-horario:checked").forEach((chk) => {
    const num = parseInt(chk.value, 10);
    if (num >= 1 && num <= 7) seleccionados.push(num);
  });
  return seleccionados;
}

function abrirModalNuevo() {
  if (!puedeConfig) return;
  if (pestanaActiva === "horarios") {
    formHorario.reset();
    horOrigRepartidorId.value = "";
    horOrigDiaSemana.value = "";
    horOrigHoraInicio.value = "";
    seleccionarDiasPreset([]);
    horHoraInicio.value = "08:00";
    horHoraFin.value = "22:00";
    modalHorarioTitle.textContent = "Asignar Horario de Trabajo";
    btnGuardarHorario.textContent = "Guardar Horario";
    modalHorario.classList.add("open");
    horRepartidorId.focus();
  } else if (pestanaActiva === "zonas") {
    formZonaAsignacion.reset();
    zonOrigRepartidorId.value = "";
    zonOrigZonaId.value = "";
    zonEsRutaPrincipal.value = "false";
    modalZonaTitle.textContent = "Asignar Zona de Delivery";
    btnGuardarZona.textContent = "Guardar Asignación";
    modalZonaAsignacion.classList.add("open");
    zonRepartidorId.focus();
  }
}

function abrirEditarHorario(repartidorId, diaSemana, horaInicio) {
  const item = horariosData.find(
    (h) => String(h.repartidor_id) === String(repartidorId) && Number(h.dia_semana) === Number(diaSemana) && String(h.hora_inicio) === String(horaInicio)
  );
  if (!item) return;

  horOrigRepartidorId.value = item.repartidor_id;
  horOrigDiaSemana.value = item.dia_semana;
  horOrigHoraInicio.value = item.hora_inicio;
  horRepartidorId.value = item.repartidor_id;
  seleccionarDiasPreset([Number(item.dia_semana)]);
  horHoraInicio.value = item.hora_inicio;
  horHoraFin.value = item.hora_fin;
  modalHorarioTitle.textContent = "Editar Horario de Trabajo";
  btnGuardarHorario.textContent = "Actualizar Horario";
  modalHorario.classList.add("open");
}

function cerrarModalHorario(forzar = false) {
  if (!forzar && !confirm("¿Estás seguro de que deseas cancelar?")) return;
  if (modalHorario) modalHorario.classList.remove("open");
}

function abrirEditarZona(repartidorId, zonaId) {
  const item = zonasAsignadasData.find(
    (z) => String(z.repartidor_id) === String(repartidorId) && Number(z.zona_id) === Number(zonaId)
  );
  if (!item) return;

  const esPrincipal = item.es_ruta_principal === true || item.es_ruta_principal === "t" || item.es_ruta_principal === 1 || item.es_ruta_principal === "1";
  zonOrigRepartidorId.value = item.repartidor_id;
  zonOrigZonaId.value = item.zona_id;
  zonRepartidorId.value = item.repartidor_id;
  zonZonaId.value = String(item.zona_id);
  zonEsRutaPrincipal.value = esPrincipal ? "true" : "false";
  modalZonaTitle.textContent = "Editar Asignación de Zona";
  btnGuardarZona.textContent = "Actualizar Asignación";
  modalZonaAsignacion.classList.add("open");
}

function cerrarModalZona(forzar = false) {
  if (!forzar && !confirm("¿Estás seguro de que deseas cancelar?")) return;
  if (modalZonaAsignacion) modalZonaAsignacion.classList.remove("open");
}

async function guardarHorario(e) {
  e.preventDefault();
  const repartidorId = horRepartidorId.value.trim();
  const diasSeleccionados = obtenerDiasSeleccionados();
  const horaInicio = horHoraInicio.value.trim();
  const horaFin = horHoraFin.value.trim();

  if (!repartidorId || diasSeleccionados.length === 0 || !horaInicio || !horaFin) {
    showToast("Completa el repartidor, al menos un día y las horas del turno.", true);
    return;
  }
  if (horaFin <= horaInicio) {
    showToast("La Hora de Fin debe ser posterior a la Hora de Inicio.", true);
    return;
  }

  const fd = new FormData();
  fd.append("action", "guardar_horario");
  fd.append("repartidor_id", repartidorId);
  fd.append("dias_semana", diasSeleccionados.join(","));
  fd.append("hora_inicio", horaInicio);
  fd.append("hora_fin", horaFin);
  if (horOrigRepartidorId.value) {
    fd.append("orig_repartidor_id", horOrigRepartidorId.value);
    fd.append("orig_dia_semana", horOrigDiaSemana.value);
    fd.append("orig_hora_inicio", horOrigHoraInicio.value);
  }

  btnGuardarHorario.disabled = true;
  const txtOrig = btnGuardarHorario.textContent;
  btnGuardarHorario.textContent = "Guardando...";

  try {
    const res = await fetch("procesar_delivery.php", { method: "POST", body: fd });
    const data = await res.json();
    if (data.status === "success") {
      if (Array.isArray(data.horarios)) horariosData = data.horarios;
      showToast(data.message || "Horario guardado.");
      cerrarModalHorario(true);
      renderTabla(inputBuscar.value);
    } else {
      showToast(data.message || "Error al guardar horario.", true);
    }
  } catch (err) {
    showToast("Error de conexión con el servidor.", true);
  } finally {
    btnGuardarHorario.disabled = false;
    btnGuardarHorario.textContent = txtOrig;
  }
}

async function eliminarHorario(repartidorId, diaSemana, horaInicio) {
  const diaTexto = DIAS_SEMANA[Number(diaSemana)] || `Día ${diaSemana}`;
  if (!confirm(`¿Eliminar el horario del ${diaTexto} (${horaInicio})?`)) return;

  const fd = new FormData();
  fd.append("action", "eliminar_horario");
  fd.append("repartidor_id", repartidorId);
  fd.append("dia_semana", diaSemana);
  fd.append("hora_inicio", horaInicio);

  try {
    const res = await fetch("procesar_delivery.php", { method: "POST", body: fd });
    const data = await res.json();
    if (data.status === "success") {
      horariosData = horariosData.filter(
        (h) => !(String(h.repartidor_id) === String(repartidorId) && Number(h.dia_semana) === Number(diaSemana) && String(h.hora_inicio) === String(horaInicio))
      );
      showToast(data.message || "Horario eliminado.");
      renderTabla(inputBuscar.value);
    } else {
      showToast(data.message || "Error al eliminar.", true);
    }
  } catch (err) {
    showToast("Error de conexión.", true);
  }
}

async function guardarZonaAsignacion(e) {
  e.preventDefault();
  const repartidorId = zonRepartidorId.value.trim();
  const zonaId = parseInt(zonZonaId.value, 10);
  const esRutaPrincipal = zonEsRutaPrincipal.value === "true";

  if (!repartidorId || !zonaId || zonaId <= 0) {
    showToast("Seleccione tanto el Repartidor como la Zona de Delivery.", true);
    return;
  }

  const fd = new FormData();
  fd.append("action", "guardar_zona");
  fd.append("repartidor_id", repartidorId);
  fd.append("zona_id", zonaId);
  fd.append("es_ruta_principal", esRutaPrincipal ? "true" : "false");
  if (zonOrigRepartidorId.value && zonOrigZonaId.value) {
    fd.append("orig_repartidor_id", zonOrigRepartidorId.value);
    fd.append("orig_zona_id", zonOrigZonaId.value);
  }

  btnGuardarZona.disabled = true;
  const txtOrig = btnGuardarZona.textContent;
  btnGuardarZona.textContent = "Guardando...";

  try {
    const res = await fetch("procesar_delivery.php", { method: "POST", body: fd });
    const data = await res.json();
    if (data.status === "success" && data.registro) {
      if (zonOrigRepartidorId.value && zonOrigZonaId.value) {
        const idx = zonasAsignadasData.findIndex(
          (z) => String(z.repartidor_id) === String(zonOrigRepartidorId.value) && Number(z.zona_id) === Number(zonOrigZonaId.value)
        );
        if (idx !== -1) zonasAsignadasData[idx] = data.registro;
        else zonasAsignadasData.push(data.registro);
      } else {
        zonasAsignadasData.push(data.registro);
      }
      showToast(data.message || "Zona asignada.");
      cerrarModalZona(true);
      renderTabla(inputBuscar.value);
    } else {
      showToast(data.message || "Error al guardar asignación.", true);
    }
  } catch (err) {
    showToast("Error de conexión.", true);
  } finally {
    btnGuardarZona.disabled = false;
    btnGuardarZona.textContent = txtOrig;
  }
}

async function toggleRutaPrincipal(repartidorId, zonaId, checked) {
  const item = zonasAsignadasData.find(
    (z) => String(z.repartidor_id) === String(repartidorId) && Number(z.zona_id) === Number(zonaId)
  );
  if (!item) return;

  const fd = new FormData();
  fd.append("action", "toggle_ruta_principal");
  fd.append("repartidor_id", repartidorId);
  fd.append("zona_id", zonaId);
  fd.append("es_ruta_principal", checked ? "true" : "false");

  try {
    const res = await fetch("procesar_delivery.php", { method: "POST", body: fd });
    const data = await res.json();
    if (data.status === "success") {
      item.es_ruta_principal = checked;
      showToast(data.message || "Prioridad actualizada.");
    } else {
      showToast(data.message || "No se pudo actualizar.", true);
    }
    renderTabla(inputBuscar.value);
  } catch (err) {
    showToast("Error de conexión.", true);
    renderTabla(inputBuscar.value);
  }
}

async function eliminarZonaAsignada(repartidorId, zonaId) {
  if (!confirm("¿Eliminar esta asignación de zona al repartidor?")) return;

  const fd = new FormData();
  fd.append("action", "eliminar_zona");
  fd.append("repartidor_id", repartidorId);
  fd.append("zona_id", zonaId);

  try {
    const res = await fetch("procesar_delivery.php", { method: "POST", body: fd });
    const data = await res.json();
    if (data.status === "success") {
      zonasAsignadasData = zonasAsignadasData.filter(
        (z) => !(String(z.repartidor_id) === String(repartidorId) && Number(z.zona_id) === Number(zonaId))
      );
      showToast(data.message || "Asignación eliminada.");
      renderTabla(inputBuscar.value);
    } else {
      showToast(data.message || "Error al eliminar.", true);
    }
  } catch (err) {
    showToast("Error de conexión.", true);
  }
}

// ============================================================================
// UTILIDADES
// ============================================================================
function showToast(msg, isError = false) {
  const toast = document.getElementById("toast");
  if (!toast) return;
  toast.textContent = msg;
  toast.style.backgroundColor = isError ? "var(--accent-danger)" : "#251c16";
  toast.classList.add("show");
  setTimeout(() => toast.classList.remove("show"), 3600);
}

function escapeHtml(str) {
  return String(str ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function escapeJs(str) {
  return String(str ?? "")
    .replace(/\\/g, "\\\\")
    .replace(/'/g, "\\'")
    .replace(/"/g, '\\"');
}