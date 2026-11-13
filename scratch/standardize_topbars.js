const fs = require('fs');
const path = require('path');

const dirs = ['catalogo', 'directorio', 'finanzas', 'logistica', 'seguridad'];
let modifiedCount = 0;

const targetTopbarRegex = /<header class="topbar">[\s\S]*?<\/header>/;

const newTopbar = `  <header class="topbar">
    <div style="display: flex; align-items: center; gap: 10px;">
      <button type="button" class="mobile-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Abrir Menú">
        <span></span>
        <span></span>
        <span></span>
      </button>
      <a href="../../Login y Menu/menu.php" class="topbar-brand" style="text-decoration: none;">
        <span class="brand-title">CAVA &amp; BARRICA</span>
        <span class="brand-dot">•</span>
        <span class="brand-subtitle">Sistema Administrativo</span>
      </a>
    </div>
    <div class="topbar-actions">
      <div class="topbar-user">
        <div class="user-avatar">NM</div>
        <div class="user-info-col">
          <div class="user-name">Nathalia Meléndez</div>
          <div class="user-sub-line">
            <span class="user-role-text">Administrador</span>
            <span class="user-bullet">•</span>
            <span class="user-caja-pill open">
              <span class="pill-dot green"></span>
              Caja 01
            </span>
          </div>
        </div>
      </div>
    </div>
  </header>`;

dirs.forEach(d => {
  const p = path.join(__dirname, '..', 'Panel de configuracion', d);
  if (!fs.existsSync(p)) return;
  const files = fs.readdirSync(p).filter(f => f.endsWith('.php') && !f.startsWith('procesar_') && f !== 'buscar_persona.php');
  files.forEach(f => {
    const full = path.join(p, f);
    let content = fs.readFileSync(full, 'utf8');
    if (targetTopbarRegex.test(content)) {
      content = content.replace(targetTopbarRegex, newTopbar);
      fs.writeFileSync(full, content, 'utf8');
      console.log('Updated topbar in:', f);
      modifiedCount++;
    } else {
      console.warn('Topbar regex did not match in:', f);
    }
  });
});

console.log(`Finished: ${modifiedCount} maintenance screens updated with unified clean topbar.`);

