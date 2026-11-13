const _datosCuentas = document.getElementById('datosCuentas');
let cuentas = _datosCuentas ? JSON.parse(_datosCuentas.dataset.cuentas || '[]') : [];
    let editandoId = null;

    function renderizarTabla(filtro = '') {
      const tbody = document.getElementById('tablaBody');
      tbody.innerHTML = '';
      const q = filtro.trim().toLowerCase();
      const filtradas = cuentas.filter(c => c.nombre_banco.toLowerCase().includes(q) || c.numero_cuenta.includes(q));

      if (filtradas.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 28px; color: var(--text-muted);">No hay cuentas bancarias registradas en PostgreSQL. Haz clic en <strong>+ Nueva Cuenta</strong> para crear una.</td></tr>';
        const fc = document.getElementById('footerCount');
        if (fc) fc.textContent = 'Mostrando 0 cuentas';
        return;
      }

      filtradas.forEach(c => {
        const tr = document.createElement('tr');
        const isActiva = c.activa === true || c.activa === 't' || c.activa === 1;
        const saldoFmt = c.moneda === 'USD' ? '$' + Number(c.saldo_actual).toLocaleString('en-US', {minimumFractionDigits: 2}) : 'RD$ ' + Number(c.saldo_actual).toLocaleString('es-DO', {minimumFractionDigits: 2});

        tr.innerHTML = `
          <td><span class="code-inline">#${c.id}</span></td>
          <td><strong>${escapeHtml(c.nombre_banco)}</strong></td>
          <td><span class="code-inline">${escapeHtml(c.numero_cuenta)}</span></td>
          <td><span class="badge badge-neutral">${c.tipo_cuenta}</span></td>
          <td><strong>${c.moneda}</strong></td>
          <td style="text-align: right; font-family: var(--font-mono); font-weight: bold; color: var(--accent-gold);">${saldoFmt}</td>
          <td class="text-center">
            <label class="toggle-wrap">
              <input type="checkbox" ${isActiva ? 'checked' : ''} onchange="toggleEstado(${c.id}, this.checked)" />
              <span class="toggle-slider"></span>
              <span class="toggle-text">${isActiva ? 'Activa' : 'Inactiva'}</span>
            </label>
          </td>
          <td class="text-right">
            <button type="button" class="link-edit" onclick="abrirModal(${c.id})">Editar</button>
          </td>
        `;
        tbody.appendChild(tr);
      });

      document.getElementById('badgeCount').textContent = `${cuentas.length} cuentas`;
      document.getElementById('footerInfo').textContent = `Mostrando ${filtradas.length} de ${cuentas.length} cuentas bancarias`;
    }

    async function toggleEstado(id, checked) {
      const fd = new FormData();
      fd.append('action', 'toggle_estado');
      fd.append('id', id);
      fd.append('activa', checked);
      try {
        await fetch('procesar_cuentas_bancarias.php', { method: 'POST', body: fd });
        const c = cuentas.find(i => i.id === id);
        if (c) c.activa = checked;
        showToast('Estado de cuenta actualizado.');
        renderizarTabla(document.getElementById('buscar').value);
      } catch (e) {
        showToast('Error al conectar.');
      }
    }

    function abrirModal(id = null) {
      editandoId = id;
      const form = document.getElementById('formCuenta');
      form.reset();
      if (id) {
        const c = cuentas.find(i => i.id === id);
        if (c) {
          document.getElementById('modalTitulo').textContent = 'Editar Cuenta Bancaria';
          document.getElementById('nombre_banco').value = c.nombre_banco;
          document.getElementById('numero_cuenta').value = c.numero_cuenta;
          document.getElementById('tipo_cuenta').value = c.tipo_cuenta;
          document.getElementById('moneda').value = c.moneda;
        }
      } else {
        document.getElementById('modalTitulo').textContent = 'Nueva Cuenta Bancaria';
      }
      document.getElementById('modalCuenta').classList.add('open');
    }

    function cerrarModal(forzar = false) {
      if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
      document.getElementById('modalCuenta').classList.remove('open');
      editandoId = null;
    }

    async function guardarCuenta(e) {
      e.preventDefault();
      const fd = new FormData();
      fd.append('action', 'guardar');
      if (editandoId) fd.append('id', editandoId);
      fd.append('nombre_banco', document.getElementById('nombre_banco').value.trim());
      fd.append('numero_cuenta', document.getElementById('numero_cuenta').value.trim());
      fd.append('tipo_cuenta', document.getElementById('tipo_cuenta').value);
      fd.append('moneda', document.getElementById('moneda').value);

      try {
        const res = await fetch('procesar_cuentas_bancarias.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
          if (editandoId) {
            const idx = cuentas.findIndex(i => i.id === editandoId);
            if (idx !== -1) {
              cuentas[idx].nombre_banco = data.nombre_banco;
              cuentas[idx].numero_cuenta = data.numero_cuenta;
              cuentas[idx].tipo_cuenta = data.tipo_cuenta;
              cuentas[idx].moneda = data.moneda;
            }
          } else {
            cuentas.push(data);
          }
          cerrarModal(true);
          showToast('Cuenta guardada en PostgreSQL exitosamente.');
          renderizarTabla(document.getElementById('buscar').value);
        } else {
          showToast('Error: ' + data.message);
        }
      } catch (err) {
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
