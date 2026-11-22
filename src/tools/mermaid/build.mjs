import { build } from 'esbuild';
import { readFile, writeFile, rm } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { readCoreAssets } from '../core-assets.mjs';

const root = dirname(fileURLToPath(import.meta.url));
const output = resolve(readCoreAssets().directory, 'mermaid');
// Seuls les bundles tiers sont remplacés ; runtime.mjs et preview.mjs restent des sources versionnées.
await rm(resolve(output, 'lib'), { recursive: true, force: true });
await build({ absWorkingDir: root, entryPoints: ['mermaid-entry.mjs'], bundle: true, format: 'esm',
    splitting: true, target: 'es2024', minify: true, outdir: resolve(output, 'lib'),
    outExtension: { '.js': '.mjs' }, entryNames: 'mermaid', chunkNames: 'chunks/[name]-[hash]', legalComments: 'external' });
await build({ absWorkingDir: root, entryPoints: ['svg2roughjs-entry.mjs'], bundle: true, format: 'esm',
    target: 'es2024', minify: true, outfile: resolve(output, 'svg2roughjs.mjs'), legalComments: 'external' });

// Publier les licences complètes avec les commentaires légaux produits par esbuild.
const packages = [['mermaid', 'LICENSE'], ['svg2roughjs', 'LICENSE.md'], ['roughjs', 'LICENSE'],
    ['svg-pathdata', 'LICENSE'], ['tinycolor2', 'LICENSE']];
const notices = await Promise.all(packages.map(async ([name, file]) =>
    `${name}\n\n${await readFile(resolve(root, 'node_modules', name, file), 'utf8')}`));
await writeFile(resolve(output, 'LICENSES.txt'), notices.join('\n\n'));
