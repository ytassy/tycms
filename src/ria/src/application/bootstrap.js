export function parseBootstrap(element, label) {
	if (!element) {
		console.warn(`Bootstrap ${label} introuvable.`)
		return {}
	}

	try {
		return JSON.parse(element.textContent || '{}')
	} catch {
		console.warn(`Bootstrap ${label} invalide.`)
		return {}
	}
}
