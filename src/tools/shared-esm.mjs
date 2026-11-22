/** Dépendances dont le navigateur doit partager une seule instance. */
export const sharedModules = ['vue', 'pinia', 'vue-i18n', 'vue-router', '@tycms/api'];

// Ces identifiants URL sont remappés localement par l'Import Map, sans appel réseau.
export const sharedModuleUrl = (name) => `https://esm.tycms.invalid/${name}`;

/** Vite 7 réécrit les imports en DEV : une URL externe reste remappable par le navigateur. */
export function sharedEsmPlugin() {
    let development = false;

    return {
        name: 'tycms-shared-esm',
        enforce: 'pre',
        configResolved(config) { development = config.command === 'serve'; },
        config() {
            return {
                optimizeDeps: {
                    exclude: sharedModules,
                    esbuildOptions: {
                        plugins: [{
                            name: 'tycms-shared-esm-dependencies',
                            setup(build) {
                                build.onResolve({ filter: /^(vue|pinia|vue-i18n|vue-router|@tycms\/api)$/ }, ({ path }) => ({
                                    path: sharedModuleUrl(path), external: true,
                                }));
                            },
                        }],
                    },
                },
                build: { rollupOptions: { external: sharedModules } },
            };
        },
        resolveId(id) {
            if (sharedModules.includes(id)) {
                return { id: development ? sharedModuleUrl(id) : id, external: true };
            }
        },
    };
}
