function alternarVisibilidadPin() {
  const inp = document.getElementById('pin_autorizacion');
  if (!inp) return;
  inp.type = (inp.type === 'password') ? 'text' : 'password';
}

function soloNumerosPin(e) {
  const k = e.key;
  if (['Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(k) || e.ctrlKey || e.metaKey) {
    return true;
  }
  if (!/^[0-9]$/.test(k)) {
    e.preventDefault();
    return false;
  }
  return true;
}

function validarInputPinEnVivo(input) {
  const limpio = (input.value || '').replace(/[^0-9]/g, '').slice(0, 6);
  if (input.value !== limpio) {
    input.value = limpio;
  }
  const errEl = document.getElementById('errorPinUsuario');
  if (!errEl) return;

  if (limpio.length === 0) {
    errEl.style.display = 'none';
    errEl.textContent = '';
    return;
  }

  if (limpio.length < 4) {
    errEl.style.color = 'var(--accent-danger)';
    errEl.textContent = `⚠️ Faltan ${4 - limpio.length} dígito(s) (mín. 4).`;
    errEl.style.display = 'inline';
  } else {
    errEl.style.color = 'var(--accent-gold)';
    errEl.textContent = `✓ PIN válido (${limpio.length} dígitos)`;
    errEl.style.display = 'inline';
  }
}

function marcarRolesUsuario(rolesIds = []) {
  const setIds = new Set((Array.isArray(rolesIds) ? rolesIds : []).map(x => String(x)));
  document.querySelectorAll('.chk-rol-usuario').forEach(chk => {
    chk.checked = setIds.has(String(chk.value));
  });
  evaluarPermisoPinUsuario();
}

function evaluarPermisoPinUsuario() {
  const chksMarcados = Array.from(document.querySelectorAll('.chk-rol-usuario:checked'));
  const algunRolConPin = chksMarcados.some(c => c.dataset.tienePin === '1');
  const bloquePin = document.getElementById('bloquePinUsuario');
  const inputPin = document.getElementById('pin_autorizacion');
  const lblPin = document.getElementById('lblPinAutorizacion');
  const hintPin = document.getElementById('hintPinUsuario');
  const errPin = document.getElementById('errorPinUsuario');
  const tienePinGuardado = document.getElementById('tiene_pin_guardado')?.value === '1';
  const esEditar = document.getElementById('formAccion')?.value === 'editar';

  if (!bloquePin || !inputPin) return;

  if (algunRolConPin) {
    bloquePin.style.display = 'block';
    if (esEditar && tienePinGuardado) {
      inputPin.required = false;
      inputPin.placeholder = '••••••';
      if (lblPin) lblPin.textContent = '🔐 Cambiar PIN (Opcional)';
      if (hintPin) hintPin.textContent = 'Deja vacío para conservar el PIN cifrado actual';
    } else {
      inputPin.required = true;
      inputPin.placeholder = '4 a 6 dígitos';
      if (lblPin) lblPin.textContent = '🔐 PIN (4 a 6 dígitos) *';
      if (hintPin) hintPin.textContent = 'Solo números (4 a 6 dígitos)';
    }
  } else {
    bloquePin.style.display = 'none';
    inputPin.required = false;
    inputPin.value = '';
    if (errPin) {
      errPin.style.display = 'none';
      errPin.textContent = '';
    }
  }
}

function validarFormularioUsuario(e) {
  const chksMarcados = Array.from(document.querySelectorAll('.chk-rol-usuario:checked'));
  if (chksMarcados.length === 0) {
    e.preventDefault();
    mostrarToast('Error: Debes seleccionar al menos un rol para el usuario.');
    return false;
  }

  const algunRolConPin = chksMarcados.some(c => c.dataset.tienePin === '1');
  const inputPin = document.getElementById('pin_autorizacion');
  const pinVal = (inputPin ? inputPin.value : '').trim();
  const tienePinGuardado = document.getElementById('tiene_pin_guardado')?.value === '1';
  const esEditar = document.getElementById('formAccion')?.value === 'editar';

  if (algunRolConPin) {
    if (pinVal === '' && (!esEditar || !tienePinGuardado)) {
      e.preventDefault();
      mostrarToast('Error: El rol seleccionado tiene permiso de PIN. Ingresa un PIN numérico de 4 a 6 dígitos.');
      if (inputPin) inputPin.focus();
      return false;
    }
    if (pinVal !== '' && !/^\d{4,6}$/.test(pinVal)) {
      e.preventDefault();
      mostrarToast('Error: El PIN debe contener solo números y tener entre 4 y 6 dígitos.');
      if (inputPin) inputPin.focus();
      return false;
    }
  }

  return true;
}

function abrirModal() {
  document.getElementById('formAccion').value = 'crear';
  document.getElementById('modalTitulo').textContent = 'Nuevo Usuario';
  document.getElementById('usuario_id').value = '';
  document.getElementById('tiene_pin_guardado').value = '0';
  document.getElementById('grupoPersona').style.display = 'block';
  document.getElementById('persona_id').value = '';
  document.getElementById('persona_id').required = true;
  document.getElementById('username').value = '';
  document.getElementById('password').value = '';
  document.getElementById('password').required = true;
  document.getElementById('lblPassword').textContent = 'Contraseña de Seguridad *';
  document.getElementById('hintPassword').style.display = 'none';
  document.getElementById('es_activo').value = 'true';

  const inpPin = document.getElementById('pin_autorizacion');
  inpPin.type = 'password';
  inpPin.value = '';
  const errPin = document.getElementById('errorPinUsuario');
  if (errPin) errPin.style.display = 'none';

  marcarRolesUsuario([]);
  document.getElementById('modalUsuario').classList.add('open');
}

function editarUsuario(u) {
  document.getElementById('formAccion').value = 'editar';
  document.getElementById('modalTitulo').textContent = 'Editar Usuario (' + u.username + ')';
  document.getElementById('usuario_id').value = u.usuario_id;
  const tienePin = (u.tiene_pin_guardado === true || u.tiene_pin_guardado === 't' || u.tiene_pin_guardado === 1);
  document.getElementById('tiene_pin_guardado').value = tienePin ? '1' : '0';
  document.getElementById('grupoPersona').style.display = 'none';
  document.getElementById('persona_id').required = false;
  document.getElementById('username').value = u.username;
  document.getElementById('password').value = '';
  document.getElementById('password').required = false;
  document.getElementById('lblPassword').textContent = 'Cambiar Contraseña (Opcional)';
  document.getElementById('hintPassword').style.display = 'block';
  document.getElementById('es_activo').value = (u.es_activo === true || u.es_activo === 't') ? 'true' : 'false';

  const inpPin = document.getElementById('pin_autorizacion');
  inpPin.type = 'password';
  inpPin.value = '';
  const errPin = document.getElementById('errorPinUsuario');
  if (errPin) errPin.style.display = 'none';

  const rolesAsignados = Array.isArray(u.roles_ids) ? u.roles_ids : (u.rol_id ? [u.rol_id] : []);
  marcarRolesUsuario(rolesAsignados);
  document.getElementById('modalUsuario').classList.add('open');
}

function cerrarModal(forzar = false) {
  if (!forzar && !confirm('¿Estás seguro de que deseas cancelar?')) return;
  document.getElementById('modalUsuario').classList.remove('open');
}

function cerrarSiFondo(e) {
  if (e.target.id === 'modalUsuario') cerrarModal();
}

function toggleEstadoUsuario(usuarioId, checked, checkboxEl) {
  const toggleText = checkboxEl ? checkboxEl.parentElement.querySelector('.toggle-text') : null;
  const fd = new FormData();
  fd.append('accion', 'cambiar_estado');
  fd.append('usuario_id', usuarioId);
  fd.append('format', 'json');

  fetch('procesar_usuarios.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.status === 'success') {
        if (toggleText) toggleText.textContent = checked ? 'Activo' : 'Inactivo';
        mostrarToast(data.message);
      } else {
        if (checkboxEl) checkboxEl.checked = !checked;
        if (toggleText) toggleText.textContent = (!checked) ? 'Activo' : 'Inactivo';
        alert(data.message || 'Error al cambiar estado');
      }
    })
    .catch(err => {
      if (checkboxEl) checkboxEl.checked = !checked;
      if (toggleText) toggleText.textContent = (!checked) ? 'Activo' : 'Inactivo';
      console.error(err);
      alert('Error al comunicarse con el servidor.');
    });
}

function filtrarTabla() {
  const q = document.getElementById('buscador').value.toLowerCase();
  const filas = document.querySelectorAll('.fila-usuario');
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
  document.getElementById('footerInfo').textContent = `Mostrando ${visibles} usuarios`;
}

function mostrarToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3000);
}

(() => {
  const _urlMsg = new URLSearchParams(window.location.search).get('msg');
  if (_urlMsg && typeof mostrarToast === 'function') mostrarToast(_urlMsg);
})();