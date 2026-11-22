import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { extensionViteConfig } from '../../../../tools/extension-vite.mjs';

export default extensionViteConfig({
    directory: fileURLToPath(new URL('.', import.meta.url)),
    id: 'plg_lms',
    plugins: [vue({ features: { componentIdGenerator: (filepath, _source, _production, hash) => hash(`plg_lms:${filepath}`) } })],
});
