// DATOS DE MÉTODOS DE PAGO
    const _datosMetodos = document.getElementById('datosMetodos');
let metodosData = _datosMetodos ? JSON.parse(_datosMetodos.dataset.metodos || '[]') : [];

    let editandoId = null;

    const tablaMetodos = document.getElementById("tablaMetodos");
    const badgeCount = document.getElementById("badgeCount");
    const footerCount = document.getElementById("footerCount");
    const inputBuscar = document.getElementById("inputBuscar");

    const modalMetodo = document.getElementById("modalMetodo");
    const modalTitle = document.getElementById("modalTitle");
    const formMetodo = document.getElementById("formMetodo");
    const metodoIdInput = document.getElementById("metodoId");
    const metodoCodigoInput = document.getElementById("metodoCodigo");
    const metodoDescripcionInput = document.getElementById("metodoDescripcion");
    const metodoVaucherInput = document.getElementById("metodoVaucher");
    const toast = document.getElementById("toast");

    // RENDERIZAR TABLA
    function renderTabla(filtro = "") {
      const q = filtro.toLowerCase().trim();
      const filtrados = metodosData.filter(m => 
        m.codigo.toLowerCase().includes(q) || 
        m.descripcion.toLowerCase().includes(q)
      );

      tablaMetodos.innerHTML = "";

      if (filtrados.length === 0) {
        tablaMetodos.innerHTML = `
          <tr>
            <td colspan="5" style="text-align: center; padding: 24px; color: var(--text-muted);">
              No se encontraron métodos de pago que coincidan con la búsqueda.
            </td>
          </tr>
        `;
        badgeCount.textContent = "0 registrados";
        footerCount.textContent = "Mostrando 0 métodos";
        return;
      }

      filtrados.forEach(m => {
        const tr = document.createElement("tr");

        const vaucherBadge = m.requiere_referencia
          ? `<span class="cat-badge vip">Exige Váucher</span>`
          : `<span class="cat-badge regular">Cobro Directo</span>`;

        tr.innerHTML = `
          <td><span class="cell-code" style="font-weight: 700; color: var(--accent-gold);">${escapeHtml(m.codigo)}</span></td>
          <td><strong>${escapeHtml(m.descripcion)}</strong></td>
          <td class="text-center">${vaucherBadge}</td>
          <td class="text-center">
            <label class="toggle-wrap">
              <input type="checkbox" ${m.activo ? "checked" : ""} onchange="toggleActivo(${m.id}, this.checked)" />
              <span class="toggle-slider"></span>
              <span class="toggle-text">${m.activo ? "Activo" : "Inactivo"}</span>
            </label>
          </td>
          <td style="text-align: right; white-space: nowrap;">
            <div class="action-links" style="justify-content: flex-end;">
              <button type="button" class="link-edit" onclick="abrirEditarMetodo(${m.id})">Editar</button>
            </div>
          </td>
        `;

        tablaMetodos.appendChild(tr);
      });

      badgeCount.textContent = `${filtrados.length} registrados`;
      footerCount.textContent = `Mostrando ${filtrados.length} de ${metodosData.length} métodos`;
    }

    // FILTRAR EN TIEMPO REAL
    function filtrarMetodos() {
      renderTabla(inputBuscar.value);
    }

    // TOGGLE ESTADO ACTIVO DIRECTAMENTE EN LA TABLA
    async function toggleActivo(id, checked) {
      const fd = new FormData();
      fd.append('action', 'toggle_estado');
      fd.append('id', id);
      fd.append('activo', checked);
      try {
        const res = await fetch('procesar_metodos_pago.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
          const metodo = metodosData.find(m => m.id === id);
          if (metodo) {
            metodo.activo = checked;
            showToast(`Método "${metodo.descripcion}" ${checked ? 'activado' : 'desactivado'}`);
            renderTabla(inputBuscar.value);
          }
        } else {
          showToast(data.message || 'Error al cambiar estado');
        }
      } catch (e) {
        showToast('Error de conexión con el servidor');
      }
    }

    // ABRIR MODAL NUEVO
    function abrirNuevoMetodo() {
      editandoId = null;
      modalTitle.textContent = "Nuevo Método de Pago";
      formMetodo.reset();

      const nextId = metodosData.length > 0 ? Math.max(...metodosData.map(m => m.id)) + 1 : 1;
      metodoIdInput.value = `#${nextId}`;
      metodoVaucherInput.checked = false;

      modalMetodo.classList.add("open");
      metodoCodigoInput.focus();
    }

    // ABRIR MODAL EDITAR
    function abrirEditarMetodo(id) {
      const metodo = metodosData.find(m => m.id === id);
      if (!metodo) return;

      editandoId = id;
      modalTitle.textContent = `Editar Método: ${metodo.descripcion}`;
      metodoIdInput.value = `#${metodo.id}`;
      metodoCodigoInput.value = metodo.codigo;
      metodoDescripcionInput.value = metodo.descripcion;
      metodoVaucherInput.checked = metodo.requiere_referencia;

      modalMetodo.classList.add("open");
      metodoDescripcionInput.focus();
    }

    // CERRAR MODAL
    function cerrarModal(forzar = false) {
      if (!forzar && !confirm("¿Estás seguro de que deseas cancelar?")) return;
      modalMetodo.classList.remove("open");
      formMetodo.reset();
      editandoId = null;
    }

    modalMetodo.addEventListener("click", (e) => {
      if (e.target === modalMetodo) cerrarModal();
    });

    // GUARDAR MÉTODO
    async function guardarMetodo(e) {
      e.preventDefault();

      const codigo = metodoCodigoInput.value.trim().toUpperCase();
      const descripcion = metodoDescripcionInput.value.trim();
      const requiere_referencia = metodoVaucherInput.checked;

      if (!codigo || !descripcion) {
        showToast("Código y nombre del método son requeridos");
        return;
      }

      // Validar código único
      const duplicado = metodosData.find(m => m.codigo === codigo && m.id !== editandoId);
      if (duplicado) {
        showToast(`El código "${codigo}" ya está registrado`);
        return;
      }

      const fd = new FormData();
      fd.append('action', 'guardar');
      if (editandoId !== null) fd.append('id', editandoId);
      fd.append('codigo', codigo);
      fd.append('descripcion', descripcion);
      fd.append('requiere_referencia', requiere_referencia);

      try {
        const res = await fetch('procesar_metodos_pago.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
          if (editandoId !== null) {
            const metodo = metodosData.find(m => m.id === editandoId);
            if (metodo) {
              metodo.codigo = codigo;
              metodo.descripcion = descripcion;
              metodo.requiere_referencia = requiere_referencia;
              showToast(`Método "${descripcion}" actualizado en PostgreSQL`);
            }
          } else {
            metodosData.push({
              id: data.id,
              codigo,
              descripcion,
              requiere_referencia,
              activo: true
            });
            showToast(`Método "${descripcion}" registrado en PostgreSQL`);
          }
          cerrarModal(true);
          renderTabla(inputBuscar.value);
        } else {
          showToast(data.message || "Error al guardar método");
        }
      } catch (err) {
        showToast("Error de conexión con el servidor");
      }
    }

    // ELIMINAR MÉTODO
    function eliminarMetodo(id) {
      const metodo = metodosData.find(m => m.id === id);
      if (!metodo) return;

      if (confirm(`¿Estás seguro de que deseas eliminar el método "${metodo.descripcion}"?`)) {
        metodosData = metodosData.filter(m => m.id !== id);
        showToast(`Método "${metodo.descripcion}" eliminado`);
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

    if (metodoDescripcionInput) {
      metodoDescripcionInput.addEventListener("input", function () {
        if (this.value.length > 0) {
          const pos = this.selectionStart;
          this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1);
          if (typeof pos === "number") this.setSelectionRange(pos, pos);
        }
      });
    }

    if (metodoCodigoInput) {
      metodoCodigoInput.addEventListener("input", function () {
        const cod = this.value.trim().toUpperCase();
        const fb = document.getElementById("codigoFeedbackMetodo");
        const encontrado = metodosData.find(m => String(m.codigo || "").toUpperCase() === cod);
        if (encontrado) {
          editandoId = encontrado.id;
          if (metodoIdInput) metodoIdInput.value = encontrado.id;
          metodoDescripcionInput.value = encontrado.descripcion || "";
          metodoVaucherInput.checked = Boolean(encontrado.requiere_referencia);
          modalTitle.textContent = `Editar Método: ${encontrado.descripcion}`;
          if (fb) {
            fb.textContent = `✓ Código existente localizado (${encontrado.descripcion}). Datos cargados.`;
            fb.style.display = "block";
          }
        } else {
          if (fb) fb.style.display = "none";
        }
      });
    }
