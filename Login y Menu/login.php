<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Al entrar al login, limpiar cualquier sesión o cookie previa para exigir usuario y contraseña siempre
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    @setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
if (!empty($_COOKIE)) {
    foreach ($_COOKIE as $k => $v) {
        @setcookie($k, '', time() - 42000, '/');
    }
}
@session_destroy();

require_once __DIR__ . '/../config/conexion.php';

$errorMsg = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Acceso al Sistema • House &amp; Ice</title>
  <link rel="stylesheet" href="styles.css">
  <script>
    try { sessionStorage.removeItem('hi_tab_token'); } catch (e) {}
  </script>
</head>
<body class="login-body">

  <div class="login-card-compact">
    
    <!-- Encabezado Compacto y Elegante -->
    <div class="login-header-compact">
      <h1 class="brand-title-compact">HOUSE &amp; ICE</h1>
      <p class="brand-sub-compact">Sistema Administrativo • Acceso de Personal</p>
    </div>

    <!-- Alerta de Notificación de Error -->
    <?php if ($errorMsg): ?>
      <div class="alert-compact error" style="display: flex;">
        <span>⚠️</span>
        <span><?= htmlspecialchars($errorMsg) ?></span>
      </div>
    <?php endif; ?>

    <!-- Formulario apuntando a procesar_login.php -->
    <form method="POST" action="procesar_login.php" autocomplete="off">
      <div class="form-group-compact">
        <label class="form-label-compact" for="usernameInput">Usuario / Correo / Código Empleado</label>
        <div class="input-wrap-compact">
          <span class="input-icon-svg">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
              <circle cx="12" cy="7" r="4"></circle>
            </svg>
          </span>
          <input 
            type="text" 
            name="username" 
            id="usernameInput" 
            class="form-control-compact" 
            placeholder="Ej. admin, admin1 o EMP-001" 
            value="" 
            required
            autofocus
            autocomplete="off"
          >
        </div>
      </div>

      <div class="form-group-compact">
        <label class="form-label-compact" for="passwordInput">Contraseña</label>
        <div class="input-wrap-compact">
          <span class="input-icon-svg">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
          </span>
          <input 
            type="password" 
            name="password" 
            id="passwordInput" 
            class="form-control-compact" 
            placeholder="••••••••••••" 
            required
            autocomplete="new-password"
          >
          <button type="button" class="password-toggle-compact" onclick="togglePasswordVisibility()" title="Mostrar u ocultar contraseña">
            <svg id="eyeSvg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
              <circle cx="12" cy="12" r="3"></circle>
            </svg>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-submit-compact" id="submitBtn" style="margin-top: 8px;">
        <span>Iniciar Sesión</span>
        <span>→</span>
      </button>
    </form>

    <!-- Pie de Tarjeta -->
    <div class="card-bottom-compact" style="justify-content: center;">
      <span class="footer-meta-compact">House &amp; Ice S.R.L. • Santiago, R.D.</span>
    </div>

  </div>

  <script>
    let pwdVisible = false;
    function togglePasswordVisibility() {
      const pwdInput = document.getElementById('passwordInput');
      const eyeSvg = document.getElementById('eyeSvg');
      pwdVisible = !pwdVisible;

      if (pwdVisible) {
        pwdInput.type = 'text';
        eyeSvg.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
      } else {
        pwdInput.type = 'password';
        eyeSvg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
      }
    }
  </script>
</body>
</html>
