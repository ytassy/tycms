export function visibleInLocale(node, locale) {
	// Une liste absente ou vide signifie « toutes les langues » ; une condition
	// mal formée reste masquée, comme dans le rendu PHP.
	const languages = node?.props?.common?.languages

	if (languages === undefined) return true
	if (!Array.isArray(languages)) return false

	return languages.length === 0 || languages.includes(locale)
}

// Le canevas conserve les nœuds masqués pour pouvoir les réafficher.
export function isNodeHidden(node) {
	return node?.props?.common?.visible === false
}
