const _datosCuentas = document.getElementById('datosCuentas');
let cuentas = _datosCuentas ? JSON.parse(_datosCuentas.dataset.cuentas || '[]') : [];
    let editandoId = null;

    function renderizarTabla(filtro = '') {
      const tbody = document.getElementById('tablaBody');
      tbody.innerHTML = '';
      const q = filtro.trim().toLowerCase();
      const filtradas = cuentas.filter(c => c.nombre_banco.toLowerCase().includes(q) || c.numero_cuenta.includes(q));

      if (filtradas.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 28px; color: var(--text-muted);">No hay cuentas bancarias registradas. Haz clic en <strong>+ Nueva Cuenta Bancaria</strong> para crear una.</td></tr>';
        const fc = document.getElementById('footerCount');
        if (fc) fc.textContent = 'Mostrando 0 cuentas';
        return;
      }

      filtradas.forEach(c => {
        const tr = document.createElement('tr');
        const isActiva = c.activa === true || c.activa === 't' || c.activa === 1;
        const monedaLabel = c.moneda_simbolo
          ? `${escapeHtml(c.moneda)} (${escapeHtml(c.moneda_simbolo)})`
          : escapeHtml(c.moneda || 'DOP');

        tr.innerHTML = `
          <td><strong>${escapeHtml(c.nombre_banco)}</strong></td>
          <td><span class="code-inline">${escapeHtml(c.numero_cuenta)}</span></td>
          <td><span class="badge badge-neutral">${escapeHtml(c.tipo_cuenta)}</span></td>
          <td><strong>${monedaLabel}</strong></td>
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

    function validarInputNumeroCuenta(input) {
      const limpio = input.value.replace(/\D/g, '').slice(0, 20);
      if (input.value !== limpio) {
        input.value = limpio;
      }
      const errEl = document.getElementById('errorNumeroCuenta');
      if (!errEl) return;

      if (limpio.length > 0 && limpio.length < 8) {
        errEl.textContent = `Faltan ${8 - limpio.length} dígito(s) (mínimo 8 dígitos).`;
        errEl.style.display = 'block';
      } else if (/^0+$/.test(limpio)) {
        errEl.textContent = 'El número de cuenta no puede contener solo ceros.';
        errEl.style.display = 'block';
      } else {
        const duplicada = cuentas.some(
          c => String(c.numero_cuenta) === limpio && Number(c.id) !== Number(editandoId)
        );
        if (duplicada) {
          errEl.textContent = '⚠️ Este número de cuenta ya está registrado.';
          errEl.style.display = 'block';
        } else {
          errEl.style.display = 'none';
          errEl.textContent = '';
        }
      }
    }

    function abrirModal(id = null) {
      editandoId = id;
      const form = document.getElementById('formCuenta');
      form.reset();
      const errEl = document.getElementById('errorNumeroCuenta');
      if (errEl) {
        errEl.style.display = 'none';
        errEl.textContent = '';
      }
      if (id) {
        const c = cuentas.find(i => i.id === id);
        if (c) {
          document.getElementById('modalTitulo').textContent = 'Editar Cuenta Bancaria';
          document.getElementById('nombre_banco').value = c.nombre_banco;
          document.getElementById('numero_cuenta').value = c.numero_cuenta;
          document.getElementById('tipo_cuenta').value = c.tipo_cuenta;
          const selMon = document.getElementById('moneda_id');
          if (selMon) {
            if (c.moneda_id) {
              selMon.value = String(c.moneda_id);
            } else {
              const opt = Array.from(selMon.options).find(o => o.dataset.iso === c.moneda);
              if (opt) selMon.value = opt.value;
            }
          }
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
      const nombreBanco = document.getElementById('nombre_banco').value.trim();
      const numeroCuentaInput = document.getElementById('numero_cuenta');
      const numeroCuenta = numeroCuentaInput.value.replace(/\D/g, '').trim();
      const errEl = document.getElementById('errorNumeroCuenta');

      if (!nombreBanco || /^\d+$/.test(nombreBanco)) {
        showToast('Error: Ingresa un nombre de banco válido (no solo números).');
        document.getElementById('nombre_banco').focus();
        return;
      }

      if (!/^\d{8,20}$/.test(numeroCuenta) || /^0+$/.test(numeroCuenta)) {
        if (errEl) {
          errEl.textContent = 'Ingrese un número de cuenta válido (de 8 a 20 dígitos numéricos).';
          errEl.style.display = 'block';
        }
        showToast('Error: El número de cuenta debe tener entre 8 y 20 dígitos numéricos.');
        numeroCuentaInput.focus();
        return;
      }

      const duplicada = cuentas.some(
        c => String(c.numero_cuenta) === numeroCuenta && Number(c.id) !== Number(editandoId)
      );
      if (duplicada) {
        if (errEl) {
          errEl.textContent = '⚠️ Este número de cuenta ya está registrado.';
          errEl.style.display = 'block';
        }
        showToast('Error: Ese número de cuenta ya existe.');
        numeroCuentaInput.focus();
        return;
      }

      const selMon = document.getElementById('moneda_id');
      const monedaIdVal = selMon ? selMon.value : '1';

      const fd = new FormData();
      fd.append('action', 'guardar');
      if (editandoId) fd.append('id', editandoId);
      fd.append('nombre_banco', nombreBanco);
      fd.append('numero_cuenta', numeroCuenta);
      fd.append('tipo_cuenta', document.getElementById('tipo_cuenta').value);
      fd.append('moneda_id', monedaIdVal);

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
              cuentas[idx].moneda_id = data.moneda_id;
              cuentas[idx].moneda = data.moneda;
              cuentas[idx].moneda_nombre = data.moneda_nombre;
              cuentas[idx].moneda_simbolo = data.moneda_simbolo;
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

    document.addEventListener('DOMContentLoaded', () => {
      renderizarTabla();
      const elBanco = document.getElementById('nombre_banco');
      if (elBanco) {
        elBanco.addEventListener('input', function () {
          if (this.value.length > 0) {
            const pos = this.selectionStart;
            this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1);
            if (typeof pos === 'number') this.setSelectionRange(pos, pos);
          }
        });
      }
    });
