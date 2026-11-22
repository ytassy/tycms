import { formatPageBytes } from './pageSize.js'

/** Le stockage reste du texte ; l'URL d'image est créée uniquement pour l'affichage. */
export function diagramImageUrl(svg) {
	return typeof svg === 'string' && svg ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}` : ''
}

export function diagramSvgFromDataUrl(dataUrl, maximum) {
	if (typeof dataUrl !== 'string' || !/^data:image\/svg\+xml(?:;charset=utf-8)?(?:;base64)?,/i.test(dataUrl)) {
		throw new Error('Export SVG du diagramme invalide.')
	}
	// Borner l'enveloppe avant décodage, puis mesurer les vrais octets UTF-8.
	const tooLarge = () => new Error(`Le diagramme dépasse la limite de poids de ${formatPageBytes(maximum)}.`)
	if (dataUrl.length > maximum * 3 + 100) throw tooLarge()
	const separator = dataUrl.indexOf(',')
	const header = dataUrl.slice(0, separator)
	const payload = dataUrl.slice(separator + 1)
	const svg = /;base64$/i.test(header)
		? new TextDecoder('utf-8', { fatal: true }).decode(Uint8Array.from(atob(payload), character => character.charCodeAt(0)))
		: decodeURIComponent(payload)
	if (new TextEncoder().encode(svg).length > maximum) throw tooLarge()
	return svg
}
