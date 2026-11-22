'use strict';

(async () => {
    const loading = document.getElementById('updater-loading');
    const token = document.querySelector('meta[name="updater-csrf"]').content;
    const styleNonce = document.querySelector('meta[name="updater-style-nonce"]').content;
    const assets = new URL('/assets_updater/', location.origin);

    try {
        // Charger uniquement les composants locaux livrés avec le moteur.
        const options = {
            moduleCache: { vue: Vue },
            async getFile(path) {
                const url = new URL(path, assets);
                if (url.origin !== assets.origin || !url.pathname.startsWith(assets.pathname)) {
                    throw new Error('Ressource de mise à jour invalide.');
                }
                const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
                if (!response.ok) throw new Error('Ressource de mise à jour indisponible.');
                return { getContentData: binary => binary ? response.arrayBuffer() : response.text() };
            },
            addStyle(css) {
                const style = document.createElement('style');
                style.setAttribute('nonce', styleNonce);
                style.textContent = css;
                document.head.appendChild(style);
            },
        };

        const component = await window['vue3-sfc-loader'].loadModule(new URL('app/App.vue', assets).href, options);
        const app = Vue.createApp(component, { csrfToken: token });
        app.mount('#updater-app');
    } catch (error) {
        // Ne pas afficher les détails techniques qui pourraient contenir le lien secret.
        loading.textContent = 'Impossible de charger l’interface. Vérifiez les fichiers de mise à jour puis rechargez cette page.';
        loading.setAttribute('role', 'alert');
    }
})();
