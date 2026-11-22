import { templateColorName } from './cssColorVariable.js'

export function rgbaToHex(channels) {
	const hex = Array.from(channels, channel => channel.toString(16).padStart(2, '0').toUpperCase())
	return `#${hex.slice(0, channels[3] === 255 ? 3 : 4).join('')}`
}

/** Capturer les teintes CSS du contrôle, sans conserver de référence variable. */
export function resolveTemplatePalette(root, entries) {
	if (!root || !Array.isArray(entries) || !entries.length) return []
	const document = root.ownerDocument
	const view = document.defaultView
	const canvas = document.createElement('canvas')
	canvas.width = canvas.height = 1
	let context
	try { context = canvas.getContext('2d', { willReadFrequently: true }) }
	catch { return [] }
	if (!context || !view) return []

	// Le navigateur résout les alias et convertit aussi les couleurs modernes en sRGB.
	const probe = document.createElement('span')
	Object.assign(probe.style, { position: 'absolute', visibility: 'hidden', pointerEvents: 'none' })
	root.append(probe)
	try {
		const styles = view.getComputedStyle(root)
		return entries.flatMap(entry => {
			if (!entry || templateColorName(`var(${entry.variable})`) !== entry.variable) return []
			const value = styles.getPropertyValue(entry.variable).trim()
			if (!value || !view.CSS.supports('color', value)) return []
			probe.style.color = value
			context.clearRect(0, 0, 1, 1)
			context.fillStyle = view.getComputedStyle(probe).color
			context.fillRect(0, 0, 1, 1)
			return [{ ...entry, color: rgbaToHex(context.getImageData(0, 0, 1, 1).data) }]
		})
	} catch {
		// Une restriction du navigateur sur le canevas laisse la palette habituelle utilisable.
		return []
	} finally {
		probe.remove()
	}
}
