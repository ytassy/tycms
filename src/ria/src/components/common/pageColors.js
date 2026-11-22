import { templateColorName } from './cssColorVariable.js'

export { templateColorName }

export function isPageColor(value, shortHex = true) {
	return typeof value === 'string' && !/[\r\n]/.test(value) && (templateColorName(value) !== null
		|| (shortHex ? /^#(?:[a-fA-F0-9]{3}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})$/ : /^#[a-fA-F0-9]{6}(?:[a-fA-F0-9]{2})?$/).test(value))
}

export function hasVisibleTint(value) {
	return isPageColor(value, false) && (templateColorName(value) !== null || value.length === 7 || Number.parseInt(value.slice(7), 16) > 0)
}
