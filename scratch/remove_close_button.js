const fs = require('fs');
const path = require('path');

const dirs = [
  'Panel de configuracion/catalogo',
  'Panel de configuracion/directorio',
  'Panel de configuracion/finanzas',
  'Panel de configuracion/logistica',
  'Panel de configuracion/seguridad'
];

let modifiedCount = 0;

dirs.forEach(dir => {
  const fullDir = path.join(__dirname, '..', dir);
  if (!fs.existsSync(fullDir)) return;
  const files = fs.readdirSync(fullDir).filter(f => f.endsWith('.php'));
  files.forEach(file => {
    const filePath = path.join(fullDir, file);
    let content = fs.readFileSync(filePath, 'utf8');
    const regex = /\s*<button type="button" class="modal-close" onclick="cerrarModal\(\)">&times;<\/button>/g;
    if (regex.test(content)) {
      content = content.replace(regex, '');
      fs.writeFileSync(filePath, content, 'utf8');
      console.log(`Removed close button from: ${file}`);
      modifiedCount++;
    }
  });
});

console.log(`Total files updated: ${modifiedCount}`);

