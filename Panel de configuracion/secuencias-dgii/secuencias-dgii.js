const _datosSecuencias = document.getElementById('datosSecuencias');
let secuencias = _datosSecuencias ? JSON.parse(_datosSecuencias.dataset.secuencias || '[]') : [];
    let editandoId = null;

    function renderizarTabla() {
      const tbody = document.getElementById('tablaBody');
      tbody.innerHTML = '';

      if (secuencias.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 28px; color: var(--text-muted);">No hay secuencias fiscales DGII registradas en PostgreSQL. Haz clic en <strong>+ Nuevo Bloque</strong> para registrar uno.</td></tr>';
        const fc = document.getElementById('footerCount');
        if (fc) fc.textContent = 'Mostrando 0 secuencias fiscales';
        return;
      }
      secuencias.forEach(s => {
        const disponibles = Math.max(0, s.secuencia_hasta - s.secuencia_actual);
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><span class="code-inline">#${s.id}</span></td>
          <td><strong style="font-size: 1.05rem; color: var(--accent-gold);">${s.tipo_comprobante}</strong></td>
          <td class="cell-code">${s.secuencia_desde}</td>
          <td class="cell-code">${s.secuencia_hasta}</td>
          <td class="cell-code" style="color: var(--accent-gold-light); font-weight: bold;">${s.secuencia_actual}</td>
          <td style="font-family: var(--font-mono); font-weight: bold; color: ${disponibles < 500 ? 'var(--accent-danger)' : 'var(--accent-success)'}">${disponibles.toLocaleString()} restantes</td>
          <td>${s.fecha_vencimiento}</td>
          <td class="text-center"><span class="badge ${s.estado === 'ACTIVO' ? 'badge-success' : 'badge-danger'}">${s.estado}</span></td>
          <td class="text-right"><button type="button" class="link-edit" onclick="abrirModal(${s.id})">Editar</button></td>
        `;
        tbody.appendChild(tr);
      });
      document.getElementById('badgeCount').textContent = `${secuencias.length} secuencias`;
    }

    function abrirModal(id = null) {
      editandoId = id;
      const form = document.getElementById('formSecuencia');
      form.reset();
      if (id) {
        const s = secuencias.find(i => i.id === id);
        if (s) {
          document.getElementById('modalTitulo').textContent = 'Editar Bloque Fiscal DGII';
          document.getElementById('tipo_comprobante').value = s.tipo_comprobante;
          document.getElementById('secuencia_desde').value = s.secuencia_desde;
          document.getElementById('secuencia_hasta').value = s.secuencia_hasta;
          document.getElementById('secuencia_actual').value = s.secuencia_actual;
          document.getElementById('fecha_vencimiento').value = s.fecha_vencimiento;
          document.getElementById('estado').value = s.estado;
        }
      } else {
        document.getElementById('modalTitulo').textContent = 'Nuevo Bloque Fiscal DGII';
      }
      document.getElementById('modalSecuencia').classList.add('open');
    }

    function cerrarModal() {
      document.getElementById('modalSecuencia').classList.remove('open');
      editandoId = null;
    }

    async function guardarSecuencia(e) {
      e.preventDefault();
      const fd = new FormData();
      fd.append('action', 'guardar');
      if (editandoId) fd.append('id', editandoId);
      fd.append('tipo_comprobante', document.getElementById('tipo_comprobante').value);
      fd.append('secuencia_desde', document.getElementById('secuencia_desde').value);
      fd.append('secuencia_hasta', document.getElementById('secuencia_hasta').value);
      fd.append('secuencia_actual', document.getElementById('secuencia_actual').value);
      fd.append('fecha_vencimiento', document.getElementById('fecha_vencimiento').value);
      fd.append('estado', document.getElementById('estado').value);

      try {
        const res = await fetch('procesar_secuencias_dgii.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
          if (editandoId) {
            const idx = secuencias.findIndex(i => i.id === editandoId);
            if (idx !== -1) secuencias[idx] = data;
          } else {
            secuencias.push(data);
          }
          cerrarModal();
          showToast('Bloque NCF guardado en PostgreSQL.');
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

    document.addEventListener('DOMContentLoaded', () => renderizarTabla());
