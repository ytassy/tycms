import { existsSync, lstatSync, realpathSync } from 'node:fs';
import { resolve, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { sharedEsmPlugin } from './shared-esm.mjs';

/** Construire une bibliothèque ESM autonome, avec les dépendances communes fournies par Core. */
export function extensionViteConfig({ directory, id, plugins = [] }) {
    if (!/^(plg|mod|tpl)_[a-z][a-z0-9_]*$/.test(id)) throw new Error('Identifiant d’extension invalide.');

    const sourceRoot = fileURLToPath(new URL('../', import.meta.url));
    const frontend = realpathSync(directory);
    const extensions = realpathSync(resolve(sourceRoot, 'extensions'));
    const localPath = relative(extensions, frontend);
    if (localPath.startsWith(`..${sep}`) || localPath === '..' || !localPath.endsWith(`${sep}frontend`)) {
        throw new Error('Le frontend doit rester dans le dossier des extensions.');
    }
    const parts = localPath.split(sep);
    const expectedId = `${{ plugins: 'plg', modules: 'mod', templates: 'tpl' }[parts[0]]}_${parts[1]?.replace(/[A-Z]/g, (letter, offset) => (offset ? '_' : '') + letter).toLowerCase()}`;
    if (parts.length !== 3 || parts[2] !== 'frontend' || id !== expectedId) {
        throw new Error('L’identifiant du build doit correspondre au type et au dossier de l’extension.');
    }

    const output = resolve(sourceRoot, 'public/assets', id);
    // Ne jamais écrire les bundles dans une cible redirigée par un lien symbolique.
    const assets = resolve(sourceRoot, 'public/assets');
    if (realpathSync(assets) !== assets || lstatSync(output, { throwIfNoEntry: false })?.isSymbolicLink()
        || (existsSync(output) && realpathSync(output) !== output)) throw new Error('Dossier de build symbolique interdit.');
    for (const folder of ['build', 'build/chunks', '.vite']) {
        const target = resolve(output, folder);
        if (lstatSync(target, { throwIfNoEntry: false })?.isSymbolicLink()
            || (existsSync(target) && realpathSync(target) !== target)) throw new Error('Sous-dossier de build symbolique interdit.');
    }

    return {
        root: frontend,
        publicDir: false,
        base: `/assets/${id}/`,
        plugins: [...plugins, sharedEsmPlugin()],
        build: {
            outDir: output,
            // Conserver les médias statiques ; les fichiers générés ont leurs propres chemins.
            emptyOutDir: false,
            manifest: true,
            lib: { entry: resolve(frontend, 'src/main.js'), formats: ['es'], fileName: () => 'build/index.js' },
            cssCodeSplit: true,
            rollupOptions: { output: { chunkFileNames: 'build/chunks/[name]-[hash].js', assetFileNames: 'build/[name]-[hash][extname]' } },
        },
        server: {
            host: '0.0.0.0',
            origin: `http://localhost:${process.env.DEV_EXTENSION_VITE_PORT || 3001}`,
            port: 3001,
            strictPort: true,
            hmr: { clientPort: Number(process.env.DEV_EXTENSION_VITE_PORT || 3001) },
            cors: { origin: process.env.VITE_BACKEND_ORIGIN || 'http://localhost:8088' },
            fs: {
                strict: true, allow: [frontend],
                deny: ['**/.env', '**/.env.*', '**/*.{crt,pem,key}', '**/.git/**', '**/.npmrc', '**/auth.json', '**/*.php'],
            },
        },
    };
}
