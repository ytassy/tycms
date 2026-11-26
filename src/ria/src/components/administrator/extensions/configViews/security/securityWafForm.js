const localWafFields = new Set(['waf_mode', 'waf_skip_root', 'waf_message', 'waf_patterns'])

// Adapter uniquement l’affichage : les valeurs locales restent dans le brouillon et la soumission.
export function securityWafForm(form, spaceId, inherit) {
	if (!form) return form
	return { ...form, sections: form.sections.map(section => {
		if (section.id !== 'waf') return section
		return { ...section, fields: section.fields
			.filter(field => !inherit || !localWafFields.has(field.name))
			.map(field => field.name === 'waf_use_root' && spaceId === 1
				? { ...field, readonly: true, description: 'Le Space Root ne peut pas hériter de lui-même. Configurez les options ci-dessous.' } : field) }
	}) }
}
