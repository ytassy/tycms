export const mermaidBaseOptions = { width: '95%', align: 'center', look: 'auto', theme: 'auto', sketch: '1', wrapwidth: '120' }

export function mermaidDefaultsForPage(store) {
	// Le Space de la page peut différer de celui de la session d'administration.
	return { ...mermaidBaseOptions, ...(store.spaces.find(space => space.id === store.current?.space_id)?.mermaidOptions ?? {}) }
}

export function setMermaidOverride(props, name, value) {
	const options = { ...(props.options ?? {}) }
	if (value === '') delete options[name]
	else options[name] = name === 'wrapwidth' ? Number(value) : value
	props.options = options
}
