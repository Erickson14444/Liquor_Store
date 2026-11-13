function abrirModal() {
      document.getElementById('formAccion').value = 'crear';
      document.getElementById('modalTitulo').textContent = 'Nuevo Usuario';
      document.getElementById('usuario_id').value = '';
      document.getElementById('grupoPersona').style.display = 'block';
      document.getElementById('persona_id').value = '';
      document.getElementById('username').value = '';
      document.getElementById('password').value = '';
      document.getElementById('password').required = true;
      document.getElementById('lblPassword').textContent = 'Contraseña de Seguridad *';
      document.getElementById('hintPassword').style.display = 'none';
      document.getElementById('es_activo').value = 'true';
      document.getElementById('modalUsuario').classList.add('open');
    }

    function editarUsuario(u) {
      document.getElementById('formAccion').value = 'editar';
      document.getElementById('modalTitulo').textContent = 'Editar Usuario (' + u.username + ')';
      document.getElementById('usuario_id').value = u.usuario_id;
      document.getElementById('grupoPersona').style.display = 'none';
      document.getElementById('username').value = u.username;
      document.getElementById('rol_id').value = u.rol_id || '';
      document.getElementById('password').value = '';
      document.getElementById('password').required = false;
      document.getElementById('lblPassword').textContent = 'Cambiar Contraseña (Opcional)';
      document.getElementById('hintPassword').style.display = 'block';
      document.getElementById('es_activo').value = (u.es_activo === true || u.es_activo === 't') ? 'true' : 'false';
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
