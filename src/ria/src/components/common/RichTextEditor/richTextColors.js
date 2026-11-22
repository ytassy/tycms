import { templateColorName } from '../cssColorVariable.js'

// Teintes natives de TinyMCE 8 : color_map_raw remplace la palette au lieu de la compléter.
const nativeColors = [
	'#BFEDD2', 'Light Green', '#FBEEB8', 'Light Yellow', '#F8CAC6', 'Light Red',
	'#ECCAFA', 'Light Purple', '#C2E0F4', 'Light Blue', '#2DC26B', 'Green',
	'#F1C40F', 'Yellow', '#E03E2D', 'Red', '#B96AD9', 'Purple', '#3598DB', 'Blue',
	'#169179', 'Dark Turquoise', '#E67E23', 'Orange', '#BA372A', 'Dark Red',
	'#843FA1', 'Dark Purple', '#236FA1', 'Dark Blue', '#ECF0F1', 'Light Gray',
	'#CED4D9', 'Medium Gray', '#95A5A6', 'Gray', '#7E8C8D', 'Dark Gray',
	'#34495E', 'Navy Blue', '#000000', 'Black', '#FFFFFF', 'White',
]

/** Les références appartiennent au contenu ; leurs teintes résolues ne servent qu’à l’aperçu. */
export function richTextColorOptions(catalogue, resolvedColors = []) {
	if (!Array.isArray(catalogue)) return { options: {}, contentCss: '' }
	const seen = new Set()
	const entries = catalogue.filter(entry => {
		if (!entry || typeof entry.variable !== 'string' || templateColorName(`var(${entry.variable})`) !== entry.variable
			|| typeof entry.label !== 'string' || !entry.label.trim() || seen.has(entry.variable)) return false
		seen.add(entry.variable)
		return true
	})
	if (!entries.length) return { options: {}, contentCss: '' }

	// Injecter seulement des noms validés et des hexadécimaux, jamais du CSS reçu tel quel.
	const declarations = (Array.isArray(resolvedColors) ? resolvedColors : []).flatMap(entry =>
		entry && seen.has(entry.variable) && typeof entry.color === 'string' && /^#[A-Fa-f0-9]{6}(?:[A-Fa-f0-9]{2})?$/.test(entry.color)
			&& !/[\r\n]/.test(entry.color) ? [`${entry.variable}:${entry.color}`] : [])
	return {
		options: { color_map_raw: [...entries.flatMap(entry => [`var(${entry.variable})`, `${entry.label} (template)`]), ...nativeColors] },
		contentCss: declarations.length ? `:root{${declarations.join(';')}}` : '',
	}
}
