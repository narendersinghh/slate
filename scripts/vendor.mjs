// Copy pinned browser libraries from node_modules into admin/assets/vendor/ (committed, so hosting needs no Node).
// Run after changing a version in package.json:  npm ci && npm run vendor
import { cpSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const libs = [
  { name: 'grapesjs', files: { 'dist/grapes.min.js': 'grapes.min.js', 'dist/css/grapes.min.css': 'grapes.min.css', 'LICENSE': 'LICENSE' } },
];
for (const lib of libs) {
  const src = join(root, 'node_modules', lib.name);
  const version = JSON.parse(readFileSync(join(src, 'package.json'), 'utf8')).version;
  const dest = join(root, 'admin/assets/vendor', lib.name);
  mkdirSync(dest, { recursive: true });
  for (const [from, to] of Object.entries(lib.files)) cpSync(join(src, from), join(dest, to));
  writeFileSync(join(dest, 'VERSION'), version + '\n');
  console.log(`${lib.name} ${version} -> admin/assets/vendor/${lib.name}`);
}
