/** Lire uniquement les deux props littérales autorisées dans le HTML PHP. */
export function readSecureVideoHtmlProps(element) {
	const src = element.getAttribute('src') ?? ''
	const spaceId = element.getAttribute('space-id') ?? ''

	// La validation complète de la source et du Space appartient au serveur.
	if (!src || src.length > 2048 || /[\x00-\x20\x7f]/.test(src)
		|| !/^[1-9]/.test(spaceId) || /\D/.test(spaceId)) return null

	// Ne jamais transmettre les autres attributs, directives ou événements à Vue.
	return { src, spaceId }
}
