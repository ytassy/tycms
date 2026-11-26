const configViews = new Map()

/**
 * Enregistrer la vue de configuration d’une extension avant le montage de
 * l’écran d’administration.
 *
 * Le serveur ne fournit qu’un identifiant logique : le registre est la seule
 * liste blanche des composants réellement chargés par les bundles de confiance.
 */
export function registerExtensionConfigView(id, component) {
	if (!/^[a-z][a-z0-9_-]*\/(?:[a-zA-Z0-9_-]+)(?:\/[a-zA-Z0-9_-]+)*$/.test(id) || id.startsWith('core/') || configViews.has(id)) {
		throw new Error(`Vue de configuration d’extension invalide ou déjà enregistrée : ${id}`)
	}

	configViews.set(id, component)
}

export const resolveExtensionConfigView = id => configViews.get(id) ?? null
