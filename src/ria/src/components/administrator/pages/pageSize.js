export const absolutePageMaxSize = 5_000_000

export function pageMaxSize(context = {}) {
	const value = context.plg_core_page_max_size
	return Number.isInteger(value) && value > 0 ? Math.min(value, absolutePageMaxSize) : 1_000_000
}

/** Reprendre les échappements des slash et séparateurs Unicode de la persistance PHP. */
export function pageJsonBytes(document) {
	const json = JSON.stringify(document)
	return new TextEncoder().encode(json.replace(/[\/\u2028\u2029]/g,
		character => character === '/' ? '\\/' : `\\u${character.charCodeAt(0).toString(16).padStart(4, '0')}`)).length
}

export function formatPageBytes(bytes) {
	return bytes < 1_000_000 ? `${Math.ceil(bytes / 1_000)} Ko` : `${(bytes / 1_000_000).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} Mo`
}
