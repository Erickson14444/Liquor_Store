const _datosZonas = document.getElementById('datosZonas');
let zonas = _datosZonas ? JSON.parse(_datosZonas.dataset.zonas || '[]') : [];
    let editandoId = null;

    function renderizarTabla() {
      const tbody = document.getElementById('tablaBody');
      tbody.innerHTML = '';

      if (zonas.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 28px; color: var(--text-muted);">No hay zonas de delivery registradas en PostgreSQL. Haz clic en <strong>+ Nueva Zona</strong> para registrar una.</td></tr>';
        const fc = document.getElementById('footerCount');
        if (fc) fc.textContent = 'Mostrando 0 zonas';
        return;
      }
      zonas.forEach(z => {
        const isActiva = z.activa === true || z.activa === 't' || z.activa === 1;
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><span class="code-inline">#${z.id}</span></td>
          <td><strong>${escapeHtml(z.nombre)}</strong></td>
          <td style="text-align: right; font-family: var(--font-mono); font-weight: bold; color: var(--accent-gold);">RD$ ${Number(z.tarifa_envio).toFixed(2)}</td>
          <td style="text-align: center; font-family: var(--font-mono);">${z.tiempo_promedio_min} min</td>
          <td class="text-center">
            <label class="toggle-wrap">
              <input type="checkbox" ${isActiva ? 'checked' : ''} onchange="toggleEstado(${z.id}, this.checked)" />
              <span class="toggle-slider"></span>
              <span class="toggle-text">${isActiva ? 'Activa' : 'Inactiva'}</span>
            </label>
          </td>
          <td class="text-right"><button type="button" class="link-edit" onclick="abrirModal(${z.id})">Editar</button></td>
        `;
        tbody.appendChild(tr);
      });
      document.getElementById('badgeCount').textContent = `${zonas.length} zonas`;
    }

    async function toggleEstado(id, checked) {
      const fd = new FormData();
      fd.append('action', 'toggle_estado');
      fd.append('id', id);
      fd.append('activa', checked);
      try {
        await fetch('procesar_zonas_delivery.php', { method: 'POST', body: fd });
        const z = zonas.find(i => i.id === id);
        if (z) z.activa = checked;
        showToast('Zona actualizada en PostgreSQL.');
        renderizarTabla();
      } catch (e) {
        showToast('Error al conectar.');
      }
    }

    function abrirModal(id = null) {
      editandoId = id;
      document.getElementById('formZona').reset();
      if (id) {
        const z = zonas.find(i => i.id === id);
        if (z) {
          document.getElementById('modalTitulo').textContent = 'Editar Zona de Delivery';
          document.getElementById('nombre_zona').value = z.nombre;
          document.getElementById('tarifa_envio').value = z.tarifa_envio;
          document.getElementById('tiempo_promedio_min').value = z.tiempo_promedio_min;
        }
      } else {
        document.getElementById('modalTitulo').textContent = 'Nueva Zona de Delivery';
      }
      document.getElementById('modalZona').classList.add('open');
    }

    function cerrarModal(forzar = false) {
      if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
      document.getElementById('modalZona').classList.remove('open');
      editandoId = null;
    }

    async function guardarZona(e) {
      e.preventDefault();
      const fd = new FormData();
      fd.append('action', 'guardar');
      if (editandoId) fd.append('id', editandoId);
      fd.append('nombre', document.getElementById('nombre_zona').value.trim());
      fd.append('tarifa_envio', document.getElementById('tarifa_envio').value);
      fd.append('tiempo_promedio_min', document.getElementById('tiempo_promedio_min').value);

      try {
        const res = await fetch('procesar_zonas_delivery.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
          if (editandoId) {
            const idx = zonas.findIndex(i => i.id === editandoId);
            if (idx !== -1) zonas[idx] = data;
          } else {
            zonas.push(data);
          }
          cerrarModal(true);
          showToast('Zona guardada en PostgreSQL.');
          renderizarTabla();
        }
      } catch (e) {
        showToast('Error de conexión.');
      }
    }

    function showToast(msg) {
      const t = document.getElementById('toast');
      t.textContent = msg;
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), 3000);
    }

    function escapeHtml(str) {
      return String(str).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m]);
    }

    document.addEventListener('DOMContentLoaded', () => renderizarTabla());
