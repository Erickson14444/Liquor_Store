/**
 * ============================================================================
 * LÓGICA FRONTEND Y VALIDACIONES: PROCESO DE DELIVERY (HORARIOS Y ZONAS)
 * Archivo: Ventas y Facturacion/Delivery/delivery.js
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

const _datosDelivery = document.getElementById("datosDelivery");
let horariosData = _datosDelivery ? JSON.parse(_datosDelivery.dataset.horarios || "[]") : [];
let zonasAsignadasData = _datosDelivery ? JSON.parse(_datosDelivery.dataset.zonasAsignadas || "[]") : [];

let pestanaActiva = "horarios"; // 'horarios' | 'zonas'

const DIAS_SEMANA = {
  1: "Lunes",
  2: "Martes",
  3: "Miércoles",
  4: "Jueves",
  5: "Viernes",
  6: "Sábado",
  7: "Domingo"
};

// Referencias DOM Principales
const tabHorarios = document.getElementById("tabHorarios");
const tabZonas = document.getElementById("tabZonas");
const badgeTabHorarios = document.getElementById("badgeTabHorarios");
const badgeTabZonas = document.getElementById("badgeTabZonas");
const btnNuevo = document.getElementById("btnNuevo");
const cardTitle = document.getElementById("cardTitle");
const badgeCount = document.getElementById("badgeCount");
const inputBuscar = document.getElementById("inputBuscar");
const tablaHead = document.getElementById("tablaHead");
const tablaBody = document.getElementById("tablaBody");
const footerCount = document.getElementById("footerCount");
const footerSchema = document.getElementById("footerSchema");

// Referencias Modal 1: Horario
const modalHorario = document.getElementById("modalHorario");
const formHorario = document.getElementById("formHorario");
const modalHorarioTitle = document.getElementById("modalHorarioTitle");
const horOrigRepartidorId = document.getElementById("horOrigRepartidorId");
const horOrigDiaSemana = document.getElementById("horOrigDiaSemana");
const horOrigHoraInicio = document.getElementById("horOrigHoraInicio");
const horRepartidorId = document.getElementById("horRepartidorId");
const horDiaSemana = document.getElementById("horDiaSemana");
const horHoraInicio = document.getElementById("horHoraInicio");
const horHoraFin = document.getElementById("horHoraFin");
const btnGuardarHorario = document.getElementById("btnGuardarHorario");

// Referencias Modal 2: Zona de Delivery
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
  if (tabParam === "zonas") {
    cambiarPestana("zonas");
  } else {
    cambiarPestana("horarios");
  }
});

// ============================================================================
// CAMBIO DE PESTAÑA (TABS: HORARIOS DE TRABAJO vs ASIGNACIÓN DE ZONAS)
// ============================================================================
function cambiarPestana(pestana) {
  pestanaActiva = pestana;
  if (inputBuscar) inputBuscar.value = "";

  if (pestana === "horarios") {
    tabHorarios.classList.add("active");
    tabZonas.classList.remove("active");
    btnNuevo.textContent = "+ Asignar Horario de Trabajo";
    cardTitle.textContent = "Horarios de Trabajo Asignados";
    inputBuscar.placeholder = "Buscar horario por repartidor, código, día o vehículo...";
    footerSchema.innerHTML =
      "House &amp; Ice • Control de Horarios y Turnos de Reparto";
  } else {
    tabZonas.classList.add("active");
    tabHorarios.classList.remove("active");
    btnNuevo.textContent = "+ Asignar Zona a Repartidor";
    cardTitle.textContent = "Zonas de Delivery Asignadas a Repartidores";
    inputBuscar.placeholder = "Buscar asignación por repartidor, código o nombre de zona...";
    footerSchema.innerHTML =
      "House &amp; Ice • Cobertura de Sectores y Rutas de Entrega";
  }

  actualizarBadgesPestanas();
  renderTabla();
}

function actualizarBadgesPestanas() {
  if (badgeTabHorarios) badgeTabHorarios.textContent = horariosData.length;
  if (badgeTabZonas) badgeTabZonas.textContent = zonasAsignadasData.length;
}

function filtrarDatos() {
  renderTabla(inputBuscar ? inputBuscar.value : "");
}

// ============================================================================
// RENDERIZADO DINÁMICO DE TABLA SEGÚN PESTAÑA ACTIVA
// ============================================================================
function renderTabla(filtro = "") {
  const q = filtro.toLowerCase().trim();

  if (pestanaActiva === "horarios") {
    renderTablaHorarios(q);
  } else {
    renderTablaZonas(q);
  }
  actualizarBadgesPestanas();
}

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

  const filtrados = horariosData.filter(item => {
    const diaNombre = (DIAS_SEMANA[Number(item.dia_semana)] || "").toLowerCase();
    return (
      (item.nombre_razon_social || "").toLowerCase().includes(q) ||
      (item.codigo_empleado || "").toLowerCase().includes(q) ||
      (item.tipo_vehiculo || "").toLowerCase().includes(q) ||
      (item.placa_vehiculo || "").toLowerCase().includes(q) ||
      diaNombre.includes(q) ||
      String(item.dia_semana).includes(q) ||
      (item.hora_inicio || "").includes(q) ||
      (item.hora_fin || "").includes(q)
    );
  });

  tablaBody.innerHTML = "";

  if (filtrados.length === 0) {
    tablaBody.innerHTML = `
      <tr>
        <td colspan="6" style="text-align: center; padding: 30px; color: var(--text-muted);">
          No hay horarios de trabajo asignados. Haz clic en <strong>+ Asignar Horario de Trabajo</strong>.
        </td>
      </tr>
    `;
    badgeCount.textContent = "0 horarios";
    footerCount.textContent = "Mostrando 0 horarios de trabajo";
    return;
  }

  filtrados.forEach(item => {
    const diaNum = Number(item.dia_semana);
    const diaTexto = DIAS_SEMANA[diaNum] || `Día ${diaNum}`;
    const tr = document.createElement("tr");

    tr.innerHTML = `
      <td>
        <div style="font-weight: 700; color: var(--text-dark);">${escapeHtml(item.nombre_razon_social)}</div>
        <div style="font-size: 0.78rem; color: var(--text-muted);">
          <span class="code-inline">${escapeHtml(item.codigo_empleado)}</span>
        </div>
      </td>
      <td>
        <div style="font-weight: 600; font-size: 0.85rem;">
          🛵 ${escapeHtml(item.tipo_vehiculo || "MOTOCICLETA")}
          ${item.placa_vehiculo ? `<span class="code-inline">[${escapeHtml(item.placa_vehiculo)}]</span>` : ""}
        </div>
        <div style="font-size: 0.75rem; color: var(--text-muted);">
          Estado: <strong>${escapeHtml(item.estado_logistico || "BASE")}</strong>
        </div>
      </td>
      <td class="text-center">
        <span class="badge-pill" style="background: var(--bg-badge); border: 1px solid var(--border-badge); color: var(--text-dark); font-weight: 600; padding: 4px 10px; border-radius: 999px;">
          #${diaNum} • ${escapeHtml(diaTexto)}
        </span>
      </td>
      <td class="text-center">
        <span class="code-inline" style="font-size: 0.9rem; font-weight: 600;">🕒 ${escapeHtml(item.hora_inicio)}</span>
      </td>
      <td class="text-center">
        <span class="code-inline" style="font-size: 0.9rem; font-weight: 600;">🏁 ${escapeHtml(item.hora_fin)}</span>
      </td>
      <td style="text-align: right; white-space: nowrap;">
        <div class="action-btns">
          <button
            type="button"
            class="link-edit"
            onclick="abrirEditarHorario('${escapeJs(item.repartidor_id)}', ${diaNum}, '${escapeJs(item.hora_inicio)}')"
          >
            Editar
          </button>
          <button
            type="button"
            class="btn-sm btn-danger"
            onclick="eliminarHorario('${escapeJs(item.repartidor_id)}', ${diaNum}, '${escapeJs(item.hora_inicio)}')"
          >
            Eliminar
          </button>
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

  const filtrados = zonasAsignadasData.filter(item => {
    return (
      (item.nombre_razon_social || "").toLowerCase().includes(q) ||
      (item.codigo_empleado || "").toLowerCase().includes(q) ||
      (item.zona_nombre || "").toLowerCase().includes(q) ||
      String(item.zona_id).includes(q) ||
      (item.tipo_vehiculo || "").toLowerCase().includes(q)
    );
  });

  tablaBody.innerHTML = "";

  if (filtrados.length === 0) {
    tablaBody.innerHTML = `
      <tr>
        <td colspan="6" style="text-align: center; padding: 30px; color: var(--text-muted);">
          No hay zonas de delivery asignadas a repartidores. Haz clic en <strong>+ Asignar Zona a Repartidor</strong>.
        </td>
      </tr>
    `;
    badgeCount.textContent = "0 asignaciones";
    footerCount.textContent = "Mostrando 0 zonas asignadas";
    return;
  }

  filtrados.forEach(item => {
    const esPrincipal =
      item.es_ruta_principal === true ||
      item.es_ruta_principal === "t" ||
      item.es_ruta_principal === 1 ||
      item.es_ruta_principal === "1";

    const zonaIdNum = Number(item.zona_id);
    const tarifaFmt = Number(item.zona_tarifa || 0).toLocaleString("es-DO", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });

    const toggleRutaHtml = `
      <label class="toggle-wrap" title="Cambiar prioridad de ruta">
        <input
          type="checkbox"
          ${esPrincipal ? "checked" : ""}
          onchange="toggleRutaPrincipal('${escapeJs(item.repartidor_id)}', ${zonaIdNum}, this.checked)"
        />
        <span class="toggle-slider"></span>
        <span class="toggle-text">${esPrincipal ? "★ Principal" : "Secundaria"}</span>
      </label>
    `;

    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>
        <div style="font-weight: 700; color: var(--text-dark);">${escapeHtml(item.nombre_razon_social)}</div>
        <div style="font-size: 0.78rem; color: var(--text-muted);">
          <span class="code-inline">${escapeHtml(item.codigo_empleado)}</span>
        </div>
      </td>
      <td>
        <div style="font-weight: 600; font-size: 0.85rem;">
          🛵 ${escapeHtml(item.tipo_vehiculo || "MOTOCICLETA")}
          ${item.placa_vehiculo ? `<span class="code-inline">[${escapeHtml(item.placa_vehiculo)}]</span>` : ""}
        </div>
        <div style="font-size: 0.75rem; color: var(--text-muted);">
          Capacidad: <strong>${Number(item.capacidad_pedidos || 1)} pedido(s)</strong>
        </div>
      </td>
      <td>
        <span class="code-inline">#${zonaIdNum}</span>
        <strong style="color: var(--text-dark); margin-left: 4px;">📍 ${escapeHtml(item.zona_nombre)}</strong>
      </td>
      <td class="text-center">
        <div style="font-weight: 700; color: var(--accent-gold);">RD$ ${tarifaFmt}</div>
        <div style="font-size: 0.76rem; color: var(--text-muted);">⏱️ ~${Number(item.zona_tiempo || 30)} min</div>
      </td>
      <td class="text-center">${toggleRutaHtml}</td>
      <td style="text-align: right; white-space: nowrap;">
        <div class="action-btns">
          <button
            type="button"
            class="link-edit"
            onclick="abrirEditarZona('${escapeJs(item.repartidor_id)}', ${zonaIdNum})"
          >
            Editar
          </button>
          <button
            type="button"
            class="btn-sm btn-danger"
            onclick="eliminarZonaAsignada('${escapeJs(item.repartidor_id)}', ${zonaIdNum})"
          >
            Eliminar
          </button>
        </div>
      </td>
    `;

    tablaBody.appendChild(tr);
  });

  badgeCount.textContent = `${filtrados.length} asignaciones`;
  footerCount.textContent = `Mostrando ${filtrados.length} de ${zonasAsignadasData.length} zonas asignadas`;
}

// ============================================================================
// CONTROL VISUAL DE CHECKBOXES DE DÍAS DE LA SEMANA
// ============================================================================
function actualizarEstiloDias() {
  document.querySelectorAll("#gridDiasSemana .day-check-card").forEach(card => {
    const chk = card.querySelector("input.chk-dia-horario");
    if (chk && chk.checked) {
      card.classList.add("checked");
    } else {
      card.classList.remove("checked");
    }
  });
}

function seleccionarDiasPreset(diasArray) {
  const setDias = new Set((diasArray || []).map(Number));
  document.querySelectorAll("#gridDiasSemana input.chk-dia-horario").forEach(chk => {
    chk.checked = setDias.has(Number(chk.value));
  });
  actualizarEstiloDias();
}

function obtenerDiasSeleccionados() {
  const seleccionados = [];
  document.querySelectorAll("#gridDiasSemana input.chk-dia-horario:checked").forEach(chk => {
    const num = parseInt(chk.value, 10);
    if (num >= 1 && num <= 7) seleccionados.push(num);
  });
  return seleccionados;
}

// ============================================================================
// APERTURA Y CIERRE DE MODALES (CON CONFIRMACIÓN AL CANCELAR, SIN X)
// ============================================================================
function abrirModalNuevo() {
  if (pestanaActiva === "horarios") {
    formHorario.reset();
    horOrigRepartidorId.value = "";
    horOrigDiaSemana.value = "";
    horOrigHoraInicio.value = "";
    seleccionarDiasPreset([]);
    horHoraInicio.value = "08:00";
    horHoraFin.value = "17:00";
    modalHorarioTitle.textContent = "Asignar Horario de Trabajo";
    btnGuardarHorario.textContent = "Guardar Horario";
    modalHorario.classList.add("open");
    horRepartidorId.focus();
  } else {
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
    h =>
      String(h.repartidor_id) === String(repartidorId) &&
      Number(h.dia_semana) === Number(diaSemana) &&
      String(h.hora_inicio) === String(horaInicio)
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
  modalHorario.classList.remove("open");
}

function abrirEditarZona(repartidorId, zonaId) {
  const item = zonasAsignadasData.find(
    z => String(z.repartidor_id) === String(repartidorId) && Number(z.zona_id) === Number(zonaId)
  );
  if (!item) return;

  const esPrincipal =
    item.es_ruta_principal === true ||
    item.es_ruta_principal === "t" ||
    item.es_ruta_principal === 1 ||
    item.es_ruta_principal === "1";

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
  modalZonaAsignacion.classList.remove("open");
}

// ============================================================================
// OPERACIONES AJAX PESTAÑA 1: HORARIOS DE TRABAJO
// ============================================================================
async function guardarHorario(e) {
  e.preventDefault();

  const repartidorId = horRepartidorId.value.trim();
  const diasSeleccionados = obtenerDiasSeleccionados();
  const horaInicio = horHoraInicio.value.trim();
  const horaFin = horHoraFin.value.trim();

  if (!repartidorId) {
    showToast("Seleccione el Repartidor.", true);
    horRepartidorId.focus();
    return;
  }

  if (diasSeleccionados.length === 0) {
    showToast("Seleccione al menos un Día de la Semana marcando las casillas.", true);
    return;
  }

  if (!horaInicio || !horaFin) {
    showToast("Complete la Hora de Inicio y la Hora de Fin.", true);
    return;
  }

  // Validación cliente: hora_fin > hora_inicio
  if (horaFin <= horaInicio) {
    showToast("Error: La Hora de Fin debe ser posterior a la Hora de Inicio.", true);
    horHoraFin.focus();
    return;
  }

  // Validación: Comprobar si ya existe horario asignado para este repartidor en alguno de los días seleccionados
  const esEdicion = Boolean(horOrigRepartidorId.value);
  const diasConflictivos = [];

  diasSeleccionados.forEach(dia => {
    const yaExiste = horariosData.find(h => {
      if (String(h.repartidor_id) !== String(repartidorId) || Number(h.dia_semana) !== Number(dia)) {
        return false;
      }
      if (
        esEdicion &&
        String(h.repartidor_id) === String(horOrigRepartidorId.value) &&
        Number(h.dia_semana) === Number(horOrigDiaSemana.value) &&
        String(h.hora_inicio).substring(0, 5) === String(horOrigHoraInicio.value).substring(0, 5)
      ) {
        return false;
      }
      return true;
    });

    if (yaExiste) {
      const nombreDia = DIAS_SEMANA[dia] || `Día ${dia}`;
      const ini = String(yaExiste.hora_inicio).substring(0, 5);
      const fin = String(yaExiste.hora_fin).substring(0, 5);
      diasConflictivos.push(`${nombreDia} (${ini} - ${fin})`);
    }
  });

  if (diasConflictivos.length > 0) {
    showToast(`El repartidor ya tiene un horario existente para: ${diasConflictivos.join(', ')}. No se permiten horarios repetidos.`, true);
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
  const txtOriginal = btnGuardarHorario.textContent;
  btnGuardarHorario.textContent = "Guardando...";

  try {
    const res = await fetch("procesar_delivery.php", { method: "POST", body: fd });
    const data = await res.json();

    if (data.status === "success") {
      if (Array.isArray(data.horarios)) {
        horariosData = data.horarios;
      }
      showToast(data.message || "Horario guardado correctamente.");
      cerrarModalHorario(true);
      renderTabla(inputBuscar.value);
    } else {
      showToast("Error: " + (data.message || "No se pudo guardar el horario."), true);
    }
  } catch (err) {
    showToast("Error de conexión con el servidor.", true);
  } finally {
    btnGuardarHorario.disabled = false;
    btnGuardarHorario.textContent = txtOriginal;
  }
}

async function eliminarHorario(repartidorId, diaSemana, horaInicio) {
  const diaTexto = DIAS_SEMANA[Number(diaSemana)] || `Día ${diaSemana}`;
  if (!confirm(`¿Estás seguro de eliminar el horario del ${diaTexto} (${horaInicio})?`)) return;

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
        h =>
          !(
            String(h.repartidor_id) === String(repartidorId) &&
            Number(h.dia_semana) === Number(diaSemana) &&
            String(h.hora_inicio) === String(horaInicio)
          )
      );
      showToast(data.message || "Horario eliminado.");
      renderTabla(inputBuscar.value);
    } else {
      showToast("Error: " + (data.message || "No se pudo eliminar."), true);
    }
  } catch (err) {
    showToast("Error de conexión al eliminar horario.", true);
  }
}

// ============================================================================
// OPERACIONES AJAX PESTAÑA 2: ASIGNACIÓN DE ZONAS (ventas.repartidor_zonas)
// ============================================================================
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
  const txtOriginal = btnGuardarZona.textContent;
  btnGuardarZona.textContent = "Guardando...";

  try {
    const res = await fetch("procesar_delivery.php", { method: "POST", body: fd });
    const data = await res.json();

    if (data.status === "success" && data.registro) {
      if (zonOrigRepartidorId.value && zonOrigZonaId.value) {
        const idx = zonasAsignadasData.findIndex(
          z =>
            String(z.repartidor_id) === String(zonOrigRepartidorId.value) &&
            Number(z.zona_id) === Number(zonOrigZonaId.value)
        );
        if (idx !== -1) {
          zonasAsignadasData[idx] = data.registro;
        } else {
          zonasAsignadasData.push(data.registro);
        }
      } else {
        zonasAsignadasData.push(data.registro);
      }

      showToast(data.message || "Zona asignada correctamente.");
      cerrarModalZona(true);
      renderTabla(inputBuscar.value);
    } else {
      showToast("Error: " + (data.message || "No se pudo guardar la asignación."), true);
    }
  } catch (err) {
    showToast("Error de conexión con el servidor.", true);
  } finally {
    btnGuardarZona.disabled = false;
    btnGuardarZona.textContent = txtOriginal;
  }
}

async function toggleRutaPrincipal(repartidorId, zonaId, checked) {
  const item = zonasAsignadasData.find(
    z => String(z.repartidor_id) === String(repartidorId) && Number(z.zona_id) === Number(zonaId)
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
      showToast(data.message || "Prioridad de ruta actualizada.");
      renderTabla(inputBuscar.value);
    } else {
      showToast("Error: " + (data.message || "No se pudo actualizar."), true);
      renderTabla(inputBuscar.value);
    }
  } catch (err) {
    showToast("Error de conexión con el servidor.", true);
    renderTabla(inputBuscar.value);
  }
}

async function eliminarZonaAsignada(repartidorId, zonaId) {
  if (!confirm("¿Estás seguro de que deseas eliminar esta asignación de zona al repartidor?")) return;

  const fd = new FormData();
  fd.append("action", "eliminar_zona");
  fd.append("repartidor_id", repartidorId);
  fd.append("zona_id", zonaId);

  try {
    const res = await fetch("procesar_delivery.php", { method: "POST", body: fd });
    const data = await res.json();

    if (data.status === "success") {
      zonasAsignadasData = zonasAsignadasData.filter(
        z => !(String(z.repartidor_id) === String(repartidorId) && Number(z.zona_id) === Number(zonaId))
      );
      showToast(data.message || "Asignación de zona eliminada.");
      renderTabla(inputBuscar.value);
    } else {
      showToast("Error: " + (data.message || "No se pudo eliminar."), true);
    }
  } catch (err) {
    showToast("Error de conexión al eliminar la asignación.", true);
  }
}

// ============================================================================
// UTILIDADES DE ESCAPE Y NOTIFICACIONES TOAST
// ============================================================================
function showToast(msg, isError = false) {
  const toast = document.getElementById("toast");
  if (!toast) return;
  toast.textContent = msg;
  toast.style.backgroundColor = isError ? "var(--accent-danger)" : "#251c16";
  toast.classList.add("show");
  setTimeout(() => toast.classList.remove("show"), 3200);
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

