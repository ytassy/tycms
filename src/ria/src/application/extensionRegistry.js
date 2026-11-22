const pages = new Map();
const stores = new Map();

/** Enregistrer les pages de confiance avant le montage des racines Vue. */
export function registerRiaPage(id, component) {
    if (!/^[a-z][a-z0-9_-]*\/[a-zA-Z0-9_/-]+$/.test(id) || id.startsWith('core/') || pages.has(id)) {
        throw new Error(`Identifiant de page d’extension invalide ou déjà enregistré : ${id}`);
    }
    pages.set(id, component);
}

export const resolveRiaPage = id => pages.get(id) ?? null;

/** Même contrat get(vueApp, application) que les factories de stores du Core. */
export function registerExtensionStore(name, factory) {
    if (!/^use[A-Z][a-zA-Z0-9]*Store$/.test(name) || typeof factory?.get !== 'function' || stores.has(name)) {
        throw new Error(`Factory de store d’extension invalide ou déjà enregistrée : ${name}`);
    }
    stores.set(name, factory);
}

export function createExtensionStores(vueApp, application, reservedNames) {
    return Object.fromEntries([...stores].map(([name, factory]) => {
        if (reservedNames.includes(name)) throw new Error(`Un store Core ne peut pas être remplacé : ${name}`);
        return [name, factory.get(vueApp, application)];
    }));
}
