// DATOS DE CAJAS POS
    const _datosCajas = document.getElementById('datosCajas');
let cajasData = _datosCajas ? JSON.parse(_datosCajas.dataset.cajas || '[]') : [];

    let editandoId = null;

    const tablaCajas = document.getElementById("tablaCajas");
    const badgeCount = document.getElementById("badgeCount");
    const footerCount = document.getElementById("footerCount");
    const inputBuscar = document.getElementById("inputBuscar");

    const modalCaja = document.getElementById("modalCaja");
    const modalTitle = document.getElementById("modalTitle");
    const formCaja = document.getElementById("formCaja");
    const cajaIdInput = document.getElementById("cajaId");
    const cajaNombreInput = document.getElementById("cajaNombre");
    const cajaUbicacionInput = document.getElementById("cajaUbicacion");
    const toast = document.getElementById("toast");

    // RENDERIZAR TABLA
    function renderTabla(filtro = "") {
      const q = filtro.toLowerCase().trim();
      const filtrados = cajasData.filter(c => 
        c.nombre_equipo.toLowerCase().includes(q) || 
        (c.ubicacion && c.ubicacion.toLowerCase().includes(q))
      );

      tablaCajas.innerHTML = "";

      if (filtrados.length === 0) {
        tablaCajas.innerHTML = `
          <tr>
            <td colspan="5" style="text-align: center; padding: 24px; color: var(--text-muted);">
              No se encontraron cajas POS que coincidan con la búsqueda.
            </td>
          </tr>
        `;
        badgeCount.textContent = "0 registradas";
        footerCount.textContent = "Mostrando 0 cajas";
        return;
      }

      filtrados.forEach(c => {
        const tr = document.createElement("tr");

        const estadoHtml = `
          <select class="table-select" onchange="cambiarEstadoCaja(${c.id}, this.value)">
            <option value="ABIERTA" ${c.estado === 'ABIERTA' ? 'selected' : ''}>Abierta</option>
            <option value="CERRADA" ${c.estado === 'CERRADA' ? 'selected' : ''}>Cerrada</option>
            <option value="EN_ARQUEO" ${c.estado === 'EN_ARQUEO' ? 'selected' : ''}>En Arqueo</option>
          </select>
        `;

        tr.innerHTML = `
          <td><span class="code-inline">#${c.id}</span></td>
          <td><strong>${escapeHtml(c.nombre_equipo)}</strong></td>
          <td><span style="font-size: 0.83rem; color: #4e4034;">${escapeHtml(c.ubicacion || "Sin ubicación")}</span></td>
          <td class="text-center">${estadoHtml}</td>
          <td style="text-align: right; white-space: nowrap;">
            <div class="action-links" style="justify-content: flex-end;">
              <button type="button" class="link-edit" onclick="abrirEditarCaja(${c.id})">Editar</button>
            </div>
          </td>
        `;

        tablaCajas.appendChild(tr);
      });

      badgeCount.textContent = `${filtrados.length} registradas`;
      footerCount.textContent = `Mostrando ${filtrados.length} de ${cajasData.length} cajas POS`;
    }

    // FILTRAR EN TIEMPO REAL
    function filtrarCajas() {
      renderTabla(inputBuscar.value);
    }

    // CAMBIAR ESTADO DE CAJA DIRECTAMENTE EN LA TABLA
    async function cambiarEstadoCaja(id, nuevoEstado) {
      const fd = new FormData();
      fd.append('action', 'cambiar_estado');
      fd.append('id', id);
      fd.append('estado', nuevoEstado);
      try {
        const res = await fetch('procesar_cajas.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
          const caja = cajasData.find(c => c.id === id);
          if (caja) {
            caja.estado = nuevoEstado;
            const nombreEstado = nuevoEstado === 'ABIERTA' ? 'Abierta' : (nuevoEstado === 'EN_ARQUEO' ? 'En Arqueo' : 'Cerrada');
            showToast(`Caja "${caja.nombre_equipo}" marcada como ${nombreEstado}`);
          }
        } else {
          showToast(data.message || 'Error al cambiar estado');
        }
      } catch (e) {
        showToast('Error de conexión con el servidor');
      }
    }

    // ABRIR MODAL NUEVO
    function abrirNuevaCaja() {
      editandoId = null;
      modalTitle.textContent = "Nueva Caja POS";
      formCaja.reset();

      const nextId = cajasData.length > 0 ? Math.max(...cajasData.map(c => c.id)) + 1 : 1;
      cajaIdInput.value = `#${nextId}`;

      modalCaja.classList.add("open");
      cajaNombreInput.focus();
    }

    // ABRIR MODAL EDITAR
    function abrirEditarCaja(id) {
      const caja = cajasData.find(c => c.id === id);
      if (!caja) return;

      editandoId = id;
      modalTitle.textContent = `Editar Caja: ${caja.nombre_equipo}`;
      cajaIdInput.value = `#${caja.id}`;
      cajaNombreInput.value = caja.nombre_equipo;
      cajaUbicacionInput.value = caja.ubicacion || "";

      modalCaja.classList.add("open");
      cajaNombreInput.focus();
    }

    // CERRAR MODAL
    function cerrarModal() {
      modalCaja.classList.remove("open");
      formCaja.reset();
      editandoId = null;
    }

    modalCaja.addEventListener("click", (e) => {
      if (e.target === modalCaja) cerrarModal();
    });

    // GUARDAR CAJA
    async function guardarCaja(e) {
      e.preventDefault();

      const nombre_equipo = cajaNombreInput.value.trim();
      const ubicacion = cajaUbicacionInput ? cajaUbicacionInput.value.trim() : '';

      if (!nombre_equipo) {
        showToast("El nombre del equipo es obligatorio");
        return;
      }

      const fd = new FormData();
      fd.append('action', 'guardar');
      if (editandoId !== null) fd.append('id', editandoId);
      fd.append('nombre_equipo', nombre_equipo);
      fd.append('estado', 'CERRADA');

      try {
        const res = await fetch('procesar_cajas.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
          if (editandoId !== null) {
            const caja = cajasData.find(c => c.id === editandoId);
            if (caja) {
              caja.nombre_equipo = nombre_equipo;
              caja.ubicacion = ubicacion;
              showToast(`Caja "${nombre_equipo}" actualizada en PostgreSQL`);
            }
          } else {
            cajasData.push({
              id: data.id,
              nombre_equipo,
              ubicacion,
              estado: data.estado || "CERRADA"
            });
            showToast(`Caja "${nombre_equipo}" registrada en PostgreSQL`);
          }
          cerrarModal();
          renderTabla(inputBuscar.value);
        } else {
          showToast(data.message || "Error al guardar la caja");
        }
      } catch (err) {
        showToast("Error de conexión con el servidor");
      }
    }

    // ELIMINAR CAJA
    function eliminarCaja(id) {
      const caja = cajasData.find(c => c.id === id);
      if (!caja) return;

      if (caja.estado === "ABIERTA") {
        showToast(`No se puede eliminar la caja "${caja.nombre_equipo}" porque está ABIERTA`);
        return;
      }

      if (confirm(`¿Estás seguro de que deseas eliminar la caja "${caja.nombre_equipo}"?`)) {
        cajasData = cajasData.filter(c => c.id !== id);
        showToast(`Caja "${caja.nombre_equipo}" eliminada`);
        renderTabla(inputBuscar.value);
      }
    }

    function showToast(msg) {
      toast.textContent = msg;
      toast.classList.add("show");
      setTimeout(() => toast.classList.remove("show"), 3000);
    }

    function escapeHtml(str) {
      return (str || "").replace(/[&<>"']/g, function(m) {
        return {
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#039;"
        }[m];
      });
    }

    // INICIALIZACIÓN
    renderTabla();
