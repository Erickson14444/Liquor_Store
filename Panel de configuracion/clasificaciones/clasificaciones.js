// Colecciones cargadas desde PostgreSQL
    const _datosClasif = document.getElementById('datosClasificaciones');
let categoriasData = _datosClasif ? JSON.parse(_datosClasif.dataset.categorias || '[]') : [];
    let marcasData = _datosClasif ? JSON.parse(_datosClasif.dataset.marcas || '[]') : [];

    let pestanaActiva = "categorias"; // 'categorias' | 'marcas'
    let editandoId = null;

    // Referencias DOM
    const tabCategorias = document.getElementById("tabCategorias");
    const tabMarcas = document.getElementById("tabMarcas");
    const badgeTabCat = document.getElementById("badgeTabCat");
    const badgeTabMar = document.getElementById("badgeTabMar");
    const btnNuevo = document.getElementById("btnNuevo");
    const cardTitle = document.getElementById("cardTitle");
    const badgeCount = document.getElementById("badgeCount");
    const inputBuscar = document.getElementById("inputBuscar");
    const thNombre = document.getElementById("thNombre");
    const tablaBody = document.getElementById("tablaBody");
    const footerCount = document.getElementById("footerCount");
    const footerSchema = document.getElementById("footerSchema");

    const modalClasificacion = document.getElementById("modalClasificacion");
    const formClasificacion = document.getElementById("formClasificacion");
    const modalTitle = document.getElementById("modalTitle");
    const labelNombre = document.getElementById("labelNombre");
    const itemId = document.getElementById("itemId");
    const itemNombre = document.getElementById("itemNombre");
    const btnGuardar = document.getElementById("btnGuardar");

    document.addEventListener("DOMContentLoaded", () => {
      cambiarPestana("categorias");
    });

    function cambiarPestana(pestana) {
      pestanaActiva = pestana;
      inputBuscar.value = "";

      if (pestana === "categorias") {
        tabCategorias.classList.add("active");
        tabMarcas.classList.remove("active");
        btnNuevo.textContent = "+ Nueva Categoría";
        cardTitle.textContent = "Listado de Categorías";
        inputBuscar.placeholder = "Buscar categoría por nombre o ID...";
        thNombre.textContent = "NOMBRE DE LA CATEGORÍA";
        footerSchema.innerHTML = "Tabla: <strong>inventario.categoria_producto</strong> &bull; Llave: <code>id (BIGINT)</code> &bull; Estado: <code>estado (VARCHAR)</code>";
      } else {
        tabMarcas.classList.add("active");
        tabCategorias.classList.remove("active");
        btnNuevo.textContent = "+ Nueva Marca";
        cardTitle.textContent = "Listado de Marcas Comerciales";
        inputBuscar.placeholder = "Buscar marca por nombre o ID...";
        thNombre.textContent = "NOMBRE DE LA MARCA";
        footerSchema.innerHTML = "Tabla: <strong>inventario.marca</strong> &bull; Llave: <code>id (BIGINT)</code> &bull; Estado: <code>estado (VARCHAR)</code>";
      }

      actualizarBadgesPestanas();
      renderTabla();
    }

    function actualizarBadgesPestanas() {
      badgeTabCat.textContent = categoriasData.length;
      badgeTabMar.textContent = marcasData.length;
    }

    function renderTabla(filtro = "") {
      const q = filtro.toLowerCase().trim();
      const coleccion = pestanaActiva === "categorias" ? categoriasData : marcasData;
      const singular = pestanaActiva === "categorias" ? "categoría" : "marca";
      const plural = pestanaActiva === "categorias" ? "categorías" : "marcas";

      const filtrados = coleccion.filter(item => 
        item.nombre.toLowerCase().includes(q) || 
        String(item.id).includes(q)
      );

      tablaBody.innerHTML = "";

      if (filtrados.length === 0) {
        tablaBody.innerHTML = `
          <tr>
            <td colspan="4" style="text-align: center; padding: 28px; color: var(--text-muted);">
              No se encontraron ${plural} registradas en PostgreSQL.
            </td>
          </tr>
        `;
        badgeCount.textContent = `0 registradas`;
        footerCount.textContent = `Mostrando 0 ${plural}`;
        return;
      }

      filtrados.forEach(item => {
        const isActivo = item.activo === true || item.activo === 't' || item.activo === 1 || item.activo === '1';
        const tr = document.createElement("tr");

        const estadoHtml = `
          <label class="toggle-wrap" title="Cambiar estado en PostgreSQL (Soft Delete)">
            <input type="checkbox" ${isActivo ? "checked" : ""} onchange="toggleEstado(${item.id}, this.checked)" />
            <span class="toggle-slider"></span>
            <span class="toggle-text">${isActivo ? "Activo" : "Inactivo"}</span>
          </label>
        `;

        tr.innerHTML = `
          <td><span class="code-inline">#${item.id}</span></td>
          <td><strong style="color: var(--text-dark);">${escapeHtml(item.nombre)}</strong></td>
          <td class="text-center">${estadoHtml}</td>
          <td style="text-align: right; white-space: nowrap;">
            <div class="action-links" style="justify-content: flex-end;">
              <button type="button" class="link-edit" onclick="abrirEditarRegistro(${item.id})">Editar</button>
            </div>
          </td>
        `;

        tablaBody.appendChild(tr);
      });

      badgeCount.textContent = `${filtrados.length} registradas`;
      footerCount.textContent = `Mostrando ${filtrados.length} de ${coleccion.length} ${plural}`;
      actualizarBadgesPestanas();
    }

    // TOGGLE ESTADO CON PERSISTENCIA REAL EN POSTGRESQL (SOFT DELETE)
    async function toggleEstado(id, checked) {
      const coleccion = pestanaActiva === "categorias" ? categoriasData : marcasData;
      const singular = pestanaActiva === "categorias" ? "Categoría" : "Marca";
      const tipo = pestanaActiva === "categorias" ? "categoria" : "marca";
      const item = coleccion.find(i => i.id === id);

      if (!item) return;

      const fd = new FormData();
      fd.append('action', 'toggle_estado');
      fd.append('tipo', tipo);
      fd.append('id', id);
      fd.append('activo', checked);

      try {
        const res = await fetch('procesar_clasificaciones.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
          item.activo = checked;
          showToast(`${singular} "${item.nombre}" actualizada en base de datos: ${checked ? 'ACTIVA' : 'INACTIVA'}`);
          renderTabla(inputBuscar.value);
        } else {
          showToast('Error: ' + data.message, true);
        }
      } catch (err) {
        showToast('Error de conexión al servidor.', true);
      }
    }

    function filtrarDatos() {
      renderTabla(inputBuscar.value);
    }

    function abrirModalNuevo() {
      editandoId = null;
      formClasificacion.reset();

      const coleccion = pestanaActiva === "categorias" ? categoriasData : marcasData;
      const nextId = coleccion.length > 0 ? Math.max(...coleccion.map(i => i.id)) + 1 : 1;
      itemId.value = `#${nextId}`;

      if (pestanaActiva === "categorias") {
        modalTitle.textContent = "Nueva Categoría";
        labelNombre.textContent = "NOMBRE DE LA CATEGORÍA *";
        itemNombre.placeholder = "Ej. Ron, Whisky, Cerveza...";
        btnGuardar.textContent = "Guardar";
      } else {
        modalTitle.textContent = "Nueva Marca";
        labelNombre.textContent = "NOMBRE DE LA MARCA *";
        itemNombre.placeholder = "Ej. Brugal, Barceló, Johnnie Walker...";
        btnGuardar.textContent = "Guardar";
      }

      modalClasificacion.classList.add("open");
      itemNombre.focus();
    }

    function abrirEditarRegistro(id) {
      const coleccion = pestanaActiva === "categorias" ? categoriasData : marcasData;
      const item = coleccion.find(i => i.id === id);
      if (!item) return;

      editandoId = id;
      itemId.value = `#${item.id}`;
      itemNombre.value = item.nombre;

      if (pestanaActiva === "categorias") {
        modalTitle.textContent = "Editar Categoría";
        labelNombre.textContent = "NOMBRE DE LA CATEGORÍA *";
        btnGuardar.textContent = "Guardar";
      } else {
        modalTitle.textContent = "Editar Marca";
        labelNombre.textContent = "NOMBRE DE LA MARCA *";
        btnGuardar.textContent = "Guardar";
      }

      modalClasificacion.classList.add("open");
      itemNombre.focus();
    }

    function cerrarModal() {
      modalClasificacion.classList.remove("open");
      editandoId = null;
    }

    // GUARDAR EN POSTGRESQL VÍA AJAX
    async function guardarRegistro(e) {
      e.preventDefault();
      const nombre = itemNombre.value.trim();
      if (!nombre) return;

      const coleccion = pestanaActiva === "categorias" ? categoriasData : marcasData;
      const singular = pestanaActiva === "categorias" ? "Categoría" : "Marca";
      const tipo = pestanaActiva === "categorias" ? "categoria" : "marca";

      const fd = new FormData();
      fd.append('action', 'guardar');
      fd.append('tipo', tipo);
      if (editandoId) fd.append('id', editandoId);
      fd.append('nombre', nombre);

      btnGuardar.disabled = true;
      btnGuardar.textContent = "Guardando...";

      try {
        const res = await fetch('procesar_clasificaciones.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.status === 'success') {
          if (editandoId) {
            const item = coleccion.find(i => i.id === editandoId);
            if (item) item.nombre = data.nombre;
            showToast(`${singular} "${data.nombre}" actualizada en PostgreSQL.`);
          } else {
            coleccion.push({ id: data.id, nombre: data.nombre, activo: true });
            showToast(`${singular} "${data.nombre}" creada y guardada en PostgreSQL.`);
          }
          cerrarModal();
          renderTabla(inputBuscar.value);
        } else {
          showToast('Error: ' + data.message, true);
        }
      } catch (err) {
        showToast('Error al conectar con el servidor.', true);
      } finally {
        btnGuardar.disabled = false;
        btnGuardar.textContent = editandoId ? "Guardar Cambios" : "Guardar";
      }
    }

    function showToast(mensaje, isError = false) {
      const toast = document.getElementById("toast");
      toast.textContent = mensaje;
      toast.style.backgroundColor = isError ? "var(--accent-danger)" : "var(--accent-gold)";
      toast.classList.add("show");
      setTimeout(() => toast.classList.remove("show"), 3000);
    }

    function escapeHtml(str) {
      return String(str).replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      })[m]);
    }
