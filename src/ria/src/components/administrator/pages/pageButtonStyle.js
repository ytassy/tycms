import { isPageColor } from '../../common/pageColors.js'

export const buttonVariants = [
	{ value: 'primary', label: 'Primaire' }, { value: 'secondary', label: 'Secondaire' },
	{ value: 'success', label: 'Succès' }, { value: 'danger', label: 'Danger' },
	{ value: 'warning', label: 'Avertissement' }, { value: 'info', label: 'Information' },
	{ value: 'light', label: 'Clair' }, { value: 'dark', label: 'Sombre' },
	{ value: 'link', label: 'Lien' },
	{ value: 'outline-primary', label: 'Contour primaire' },
	{ value: 'outline-secondary', label: 'Contour secondaire' },
	{ value: 'outline-success', label: 'Contour succès' },
	{ value: 'outline-danger', label: 'Contour danger' },
	{ value: 'outline-warning', label: 'Contour avertissement' },
	{ value: 'outline-info', label: 'Contour information' },
	{ value: 'outline-light', label: 'Contour clair' },
	{ value: 'outline-dark', label: 'Contour sombre' },
]

const variables = {
	color: '--bs-btn-color', background_color: '--bs-btn-bg', border_color: '--bs-btn-border-color',
	hover_color: '--bs-btn-hover-color', hover_background_color: '--bs-btn-hover-bg',
	hover_border_color: '--bs-btn-hover-border-color', border_width: '--bs-btn-border-width',
	border_radius: '--bs-btn-border-radius',
}
const width = /^(?:0|(?:0|[1-9][0-9]{0,3})(?:px|rem|em))$/
const radius = /^(?:0|(?:0|[1-9][0-9]{0,3})(?:px|rem|em|%))$/

export function buttonClasses(props) {
	const variant = buttonVariants.some(option => option.value === props?.variant) ? props.variant : 'primary'
	return ['btn', `btn-${variant}`, ...(['sm', 'lg'].includes(props?.size) ? [`btn-${props.size}`] : [])]
}

export function buttonStyle(props) {
	const result = {}
	for (const [key, variable] of Object.entries(variables)) {
		const value = props?.button_style?.[key]
		const valid = key === 'border_width' ? typeof value === 'string' && width.test(value)
			: key === 'border_radius' ? typeof value === 'string' && radius.test(value) : isPageColor(value)
		if (valid) result[variable] = value
	}
	return result
}
