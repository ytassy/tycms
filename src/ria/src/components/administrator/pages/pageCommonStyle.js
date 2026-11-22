import { isPageColor, hasVisibleTint } from '../../common/pageColors.js'

const sides = ['top', 'right', 'bottom', 'left']
const length = /^(?:0|-?(?:0|[1-9][0-9]{0,3})(?:px|rem|em|%))$/
const positiveLength = /^(?:0|(?:0|[1-9][0-9]{0,3})(?:px|rem|em|%))$/
const image = /^\/(?!\/)[a-zA-Z0-9_./%~-]+$/
const positions = ['static', 'relative', 'absolute', 'sticky']
const textAlignments = ['left', 'center', 'right']
const borderStyles = ['solid', 'dashed', 'dotted', 'double']
const backgroundPositions = ['center', 'top', 'bottom', 'left', 'right']
const backgroundSizes = ['auto', 'cover', 'contain']
const percent = /^(?:100|[1-9]?[0-9])$/
const backgroundPercentPosition = /^((?:100|[1-9]?[0-9]))% ((?:100|[1-9]?[0-9]))%$/

export const styleSides = sides.map(id => ({ id, label: { top: 'Haut', right: 'Droite', bottom: 'Bas', left: 'Gauche' }[id] }))

export function backgroundPositionAxes(value) {
	const match = typeof value === 'string' ? backgroundPercentPosition.exec(value) : null
	return match ? { x: Number(match[1]), y: Number(match[2]) } : null
}

export function hasLayeredBackground(common) {
	const source = common?.style ?? {}
	if (typeof source.background_image !== 'string' || !image.test(source.background_image) || source.background_image.includes('..')) return false
	const imageOpacity = typeof source.background_image_opacity === 'string' && percent.test(source.background_image_opacity)
		? Number(source.background_image_opacity) : 100
	const tint = source.background_image_tint
	return imageOpacity < 100 || hasVisibleTint(tint)
}

export function hasLinkColor(common) {
	return isPageColor(common?.style?.link_color)
}

/** Apply only values accepted by the PHP style compiler to the live canvas. */
export function canvasStyle(common) {
	const source = common?.style ?? {}
	const result = {}
	const hasBorderWidth = sides.some(side => typeof source[`border_${side}`] === 'string' && positiveLength.test(source[`border_${side}`]))
	if (hasBorderWidth) result.borderWidth = '0'
	for (const side of sides) {
		for (const [prefix, rule] of [['margin', length], ['padding', positiveLength], ['border', positiveLength]]) {
			const value = source[`${prefix}_${side}`]
			if (typeof value === 'string' && rule.test(value)) result[`${prefix}${side[0].toUpperCase()}${side.slice(1)}${prefix === 'border' ? 'Width' : ''}`] = value
		}
		const offset = source[`offset_${side}`]
		if (typeof offset === 'string' && length.test(offset)) result[side] = offset
	}
	if (hasBorderWidth) result.borderStyle = borderStyles.includes(source.border_style) ? source.border_style : 'solid'
	if (borderStyles.includes(source.border_style)) result.borderStyle = source.border_style
	if (isPageColor(source.border_color)) result.borderColor = source.border_color
	if (typeof source.border_radius === 'string' && positiveLength.test(source.border_radius)) result.borderRadius = source.border_radius
	for (const [field, cssField] of [['color', 'color'], ['background_color', 'backgroundColor']]) {
		if (isPageColor(source[field])) result[cssField] = source[field]
	}
	// La variable est héritée ; un composant enfant peut définir sa propre couleur.
	if (hasLinkColor(common)) result['--page-link-color'] = source.link_color
	if (textAlignments.includes(source.text_align)) result.textAlign = source.text_align
	if (typeof source.background_image === 'string' && image.test(source.background_image) && !source.background_image.includes('..')) {
		const position = backgroundPositions.includes(source.background_position) || backgroundPositionAxes(source.background_position)
			? source.background_position : null
		if (hasLayeredBackground(common)) {
			result.isolation = 'isolate'
			result['--page-background-image'] = `url("${source.background_image}")`
			result['--page-background-opacity'] = String(
				typeof source.background_image_opacity === 'string' && percent.test(source.background_image_opacity)
					? Number(source.background_image_opacity) / 100 : 1)
			result['--page-background-position'] = position ?? '0% 0%'
			result['--page-background-size'] = backgroundSizes.includes(source.background_size) ? source.background_size : 'auto'
			result['--page-background-tint-color'] = isPageColor(source.background_image_tint, false)
				? source.background_image_tint : 'transparent'
		} else {
			result.backgroundImage = `url("${source.background_image}")`
			result.backgroundRepeat = 'no-repeat'
			if (position) result.backgroundPosition = position
			if (backgroundSizes.includes(source.background_size)) result.backgroundSize = source.background_size
		}
	}
	if (positions.includes(source.position)) result.position = source.position
	if (hasLayeredBackground(common) && (!result.position || result.position === 'static')) result.position = 'relative'
	return result
}
