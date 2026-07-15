import { cp, mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

const src = join(root, 'src', 'renderer', 'index.html');
const destDir = join(root, 'dist', 'renderer');
await mkdir(destDir, { recursive: true });
await cp(src, join(destDir, 'index.html'));
console.log('Copied renderer/index.html -> dist/renderer/index.html');
