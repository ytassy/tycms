/** La base est fournie par Vite ; les tests Node la passent explicitement. */
export function getCoreAssetsBase(base = import.meta.env?.BASE_URL) {
    if (typeof base !== 'string' || base.trim() !== base
        || !/^\/assets_core_v[A-Za-z0-9_-]+\/plg_core\/$/.test(base)) {
        throw new Error('La base des assets Core est absente ou invalide.');
    }

    return base;
}
