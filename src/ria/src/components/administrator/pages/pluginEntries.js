import * as extensionApi from '../../../extension-api.js'

/** PHP fournit uniquement les identifiants des extensions installées et leurs Import Maps. */
export async function loadPageComponentPlugins() {
    const element = document.getElementById('tycms-extensions')
    const entries = JSON.parse(element?.textContent ?? '[]')
    if (!Array.isArray(entries)) throw new Error('Catalogue frontend des extensions invalide.')

    for (const entry of entries) {
        if (typeof entry !== 'string' || !/^@extensions\/(plg|mod|tpl)_[a-z][a-z0-9_]*$/.test(entry)) {
            throw new Error('Identifiant frontend d’extension invalide.')
        }
        const module = await import(/* @vite-ignore */ entry)
        if (typeof module.registerFrontend !== 'function') throw new Error(`Entrée frontend invalide : ${entry}`)
        await module.registerFrontend(extensionApi)
    }
}
