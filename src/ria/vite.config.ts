import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { readCoreAssets } from '../tools/core-assets.mjs';
import { sharedEsmPlugin } from '../tools/shared-esm.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const scssDir = path.resolve(__dirname, './src/scss').replace(/\\/g, '/');
const coreAssets = readCoreAssets();
export default defineConfig({
  // PHP lit le manifeste pour servir les entrées et les CSS de leurs chunks communs.
  base: coreAssets.base,
  plugins: [vue(), sharedEsmPlugin(), {
    name: 'tycms-preview-css',
    apply: 'build',
    writeBundle() {
      // Les aperçus isolés gardent leur URL CSS stable sans charger de JavaScript applicatif.
      const manifest = JSON.parse(readFileSync(path.join(coreAssets.directory, '.vite/manifest.json'), 'utf8'));
      const styles = new Set<string>();
      const seen = new Set<string>();
      const collect = (key: string) => {
        if (seen.has(key)) return;
        seen.add(key);
        const entry = manifest[key];
        for (const dependency of entry.imports ?? []) collect(dependency);
        for (const css of entry.css ?? []) styles.add(css);
      };
      collect('main.js');
      collect('extension-api.js');
      writeFileSync(path.join(coreAssets.directory, 'css/index.css'), [...styles].map(file => `@import "../${file}";`).join('\n'));
    },
  }],
  root: './src',
  resolve: { dedupe: ['vue', 'pinia'] },
  build: {
    outDir: coreAssets.directory,
    manifest: true,
    minify: false,
    emptyOutDir: false,
    rollupOptions: {
      input: { index: path.resolve(__dirname, 'src/main.js'), 'extension-api': path.resolve(__dirname, 'src/extension-api.js') },
      output: {
        // The template can always load this entry point. Other chunks retain
        // their content hash for cache invalidation.
        entryFileNames: 'js/[name].js',
        chunkFileNames: 'js/[name]-[hash].js',
        // Le manifeste associe chaque entrée à ses feuilles de style versionnées.
        assetFileNames: (assetInfo) =>
          assetInfo.name?.endsWith('.css')
            ? 'css/[name]-[hash][extname]'
            : 'assets/[name]-[hash][extname]',
      },
    },
  },
  server: {
    fs: {
      strict: true, allow: [path.resolve(__dirname, 'src')],
      deny: ['**/.env', '**/.env.*', '**/*.{crt,pem,key}', '**/.git/**', '**/.npmrc', '**/auth.json', '**/*.php'],
    },
    port: 3000,
    origin: 'http://localhost:3000',
    strictPort: true,
    host: true, // équivalent de 0.0.0.0
    cors: {
      // Docker transmet l’origine Apache avec le port choisi dans son .env.
      origin: [
        'http://tycms.fr',
        'https://tycms.fr',
        ...(process.env.VITE_BACKEND_ORIGIN ? [process.env.VITE_BACKEND_ORIGIN] : []),
      ],
    },
  },
  css: {
    preprocessorOptions: {
      scss: {
        quietDeps: true,
        silenceDeprecations: ['color-functions', 'global-builtin', 'import'],
        additionalData: (source: string, filename: string) => {
          if (filename.includes('node_modules')) return source;
          return `@use '${scssDir}/shared' as *;\n$core-assets-base: ${JSON.stringify(coreAssets.base)};\n${source}`;
        },
      },
    },
  },
});
