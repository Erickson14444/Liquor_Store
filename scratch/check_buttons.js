const fs = require('fs');
const path = require('path');

const dirs = ['catalogo', 'directorio', 'finanzas', 'logistica', 'seguridad'];
const buttonClasses = new Set();
const actionCells = new Set();

dirs.forEach(d => {
  const p = path.join(__dirname, '..', 'Panel de configuracion', d);
  const files = fs.readdirSync(p).filter(f => f.endsWith('.php') && !f.startsWith('procesar_') && f !== 'buscar_persona.php');
  files.forEach(f => {
    const full = path.join(p, f);
    const content = fs.readFileSync(full, 'utf8');
    const matches = content.match(/class=["']([^"']*btn[^"']*)["']/g);
    if (matches) {
      matches.forEach(m => buttonClasses.add(m));
    }
    const tdMatches = content.match(/<td[^>]*action[^>]*>[\s\S]*?<\/td>/g);
    if (tdMatches) {
      tdMatches.slice(0, 3).forEach(m => actionCells.add(f + ' -> ' + m.trim()));
    }
  });
});

console.log('Button classes:');
buttonClasses.forEach(c => console.log(' ', c));

console.log('\nSample action cells:');
actionCells.forEach(c => console.log(' ', c));

