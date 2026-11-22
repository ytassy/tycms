/** diagrams.net attend des couleurs sRGB opaques, sans dièse. */
export function diagramPresetColors(entries) {
	return [...new Set(entries.flatMap(entry => {
		const color = entry?.color
		return typeof color === 'string' && /^#[0-9a-f]{6}(?:ff)?$/i.test(color)
			? [color.slice(1, 7).toUpperCase()] : []
	}))]
}
