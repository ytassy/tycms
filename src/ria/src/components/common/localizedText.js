export function selectLocalizedText(value, locale) {
	// Un texte simple est partagé ; une traduction manquante reste vide.
	if (typeof value === 'string') return value
	if (!value || typeof value !== 'object' || Array.isArray(value)) return ''
	return typeof value[locale] === 'string' ? value[locale] : ''
}
