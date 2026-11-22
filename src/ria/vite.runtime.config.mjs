import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { readCoreAssets } from '../tools/core-assets.mjs';

const directory = fileURLToPath(new URL('.', import.meta.url));

/** Le runtime est compilé une fois pour tous les bundles, y compris avec deux Vite. */
export default ({ mode }) => ({
    publicDir: false,
    define: {
        'process.env.NODE_ENV': JSON.stringify(mode === 'development' ? 'development' : 'production'),
        __VUE_OPTIONS_API__: true,
        __VUE_PROD_DEVTOOLS__: false,
        __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false,
    },
    build: {
        outDir: resolve(readCoreAssets().directory, 'runtime', mode === 'development' ? 'dev' : 'prod'),
        emptyOutDir: true,
        minify: mode !== 'development',
        lib: {
            entry: Object.fromEntries(['vue', 'pinia', 'vue-i18n', 'vue-router'].map(name => [name, resolve(directory, 'runtime', `${name}.js`)])),
            formats: ['es'],
            fileName: (_format, name) => `${name}.js`,
        },
    },
});
