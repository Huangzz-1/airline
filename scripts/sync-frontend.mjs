import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const files = [
  ['frontend/app.css', 'backend/public/app.css'],
  ['frontend/app.js', 'backend/public/app.js'],
  ['frontend/modules.css', 'backend/public/modules.css'],
  ['frontend/modules.js', 'backend/public/modules.js'],
  ['node_modules/bootstrap/dist/css/bootstrap.min.css', 'backend/public/bootstrap.min.css'],
];

for (const [source, destination] of files) {
  const sourcePath = resolve(root, source);
  const destinationPath = resolve(root, destination);
  mkdirSync(dirname(destinationPath), { recursive: true });
  copyFileSync(sourcePath, destinationPath);
  console.log('Synced', source, '->', destination);
}
