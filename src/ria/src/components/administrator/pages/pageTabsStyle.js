import { isPageColor } from '../../common/pageColors.js'

const colors = ['color', 'background_color', 'border_color']
export const tabsColorGroups = [
	{ key: 'tab', label: 'Onglets : état normal' },
	{ key: 'hover', label: 'Onglets : survol' },
	{ key: 'active', label: 'Onglet actif' },
	{ key: 'body', label: 'Corps' },
].map(group => ({ ...group, fields: colors.map((field, index) => ({ key: `${group.key}_${field}`, label: ['Texte', 'Fond', 'Bordure'][index] })) }))

export const tabsBoxFields = [
	{ key: 'border_width', label: 'Épaisseur de bordure' },
	{ key: 'border_radius', label: 'Arrondi' },
	...['top', 'right', 'bottom', 'left'].map((side, index) => ({ key: `padding_${side}`, label: `Padding ${['haut', 'droite', 'bas', 'gauche'][index]}` })),
]
const allowed = new Set([
	...tabsColorGroups.flatMap(group => group.fields.map(field => field.key)),
	...['tab', 'body'].flatMap(zone => [...tabsBoxFields.map(field => `${zone}_${field.key}`), `${zone}_border_style`]),
])

export function tabsVariables(props) {
	if (props?.mode !== 'custom') return {}
	const result = {}
	for (const [key, value] of Object.entries(props.tabs_style ?? {})) {
		if (!allowed.has(key) || typeof value !== 'string' || value === '') continue
		const valid = key.endsWith('color') ? isPageColor(value)
			: key.endsWith('border_style') ? ['none', 'solid', 'dashed', 'dotted', 'double'].includes(value)
				: /^(?:0|(?:0|[1-9][0-9]{0,3})(?:px|rem|em|%))$/.test(value)
		if (valid) result[`--pt-${key.replaceAll('_', '-')}`] = value
	}
	return result
}

export function accordionVariables(props) {
	const result = tabsVariables({ ...props, tabs_style: props?.accordion_style })
	if (Number.isInteger(props?.arrow_rotation) && props.arrow_rotation >= -360 && props.arrow_rotation <= 360) {
		result['--page-accordion-arrow-rotation'] = `${props.arrow_rotation}deg`
	}
	return result
}
