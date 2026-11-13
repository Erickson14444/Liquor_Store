const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const phpBin = 'C:\\xampp\\php\\php.exe';

function findPhpFiles(dir) {
  let results = [];
  const list = fs.readdirSync(dir);
  list.forEach(file => {
    const fullPath = path.join(dir, file);
    const stat = fs.statSync(fullPath);
    if (stat && stat.isDirectory()) {
      if (file !== '.git' && file !== 'node_modules') {
        results = results.concat(findPhpFiles(fullPath));
      }
    } else if (file.endsWith('.php')) {
      results.push(fullPath);
    }
  });
  return results;
}

const phpFiles = findPhpFiles(root);
let failed = 0;
console.log(`Checking ${phpFiles.length} PHP files for syntax errors...`);

for (const file of phpFiles) {
  try {
    execSync(`"${phpBin}" -l "${file}"`, { stdio: 'pipe' });
  } catch (err) {
    console.error(`ERROR in: ${file}`);
    console.error(err.output ? err.output.toString() : err.message);
    failed++;
  }
}

if (failed === 0) {
  console.log(`SUCCESS: All ${phpFiles.length} PHP files have valid syntax!`);
} else {
  console.log(`FAIL: ${failed} files have errors.`);
  process.exit(1);
}

