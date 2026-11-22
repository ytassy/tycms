import { isPageColor } from './pageColors.js'

export const collectionSizes = {
	gap: [0, 160], padding: [0, 120], radius: [0, 100], border_width: [0, 12], portrait_size: [24, 240],
	text_size: [10, 48], author_size: [10, 48], line_width: [1, 12], marker_size: [8, 64], label_size: [10, 48],
}
export const testimonialStyleFields = ['background_color', 'color', 'border_color', 'accent_color', 'author_color', 'muted_color',
	'gap', 'padding', 'radius', 'border_width', 'portrait_size', 'text_size', 'author_size', 'shadow']
export const timelineStyleFields = ['background_color', 'color', 'border_color', 'line_color', 'marker_color', 'label_color',
	'gap', 'padding', 'radius', 'border_width', 'line_width', 'marker_size', 'label_size', 'shadow']

// Le canevas ne reçoit que les variables dont le contrat est aussi validé en PHP.
export function collectionVariables(style = {}, fields = []) {
	const result = {}
	for (const [key, value] of Object.entries(style)) {
		if (!fields.includes(key)) continue
		let cssValue
		if (collectionSizes[key]) {
			const [min, max] = collectionSizes[key]
			if (Number.isInteger(value) && value >= min && value <= max) cssValue = `${value}px`
		} else if (key === 'shadow') {
			const shadows = { none: 'none', soft: '0 4px 18px #0000000D', medium: '0 8px 28px #0000001F' }
			if (Object.hasOwn(shadows, value)) cssValue = shadows[value]
		} else if (isPageColor(value)) cssValue = value
		if (cssValue !== undefined) result[`--pc-${key.replaceAll('_', '-')}`] = cssValue
	}
	return result
}
