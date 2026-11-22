import { isPageColor } from '../../common/pageColors.js'
import { videoMediaUrl } from '../video/videoSources.js'

// Les lecteurs audio et vidéo acceptent les mêmes chemins locaux sûrs.
export const audioMediaUrl = videoMediaUrl

export function audioPlayerStyle(style = {}) {
	const result = {}
	if (!style || typeof style !== 'object' || Array.isArray(style)) return result
	if (Number.isInteger(style.height) && style.height >= 44 && style.height <= 200) result['--page-audio-height'] = `${style.height}px`
	for (const key of ['color', 'background_color', 'accent_color', 'track_color']) {
		if (isPageColor(style[key])) {
			result[`--page-audio-${key.replaceAll('_', '-')}`] = style[key]
		}
	}
	if (typeof style.border_radius === 'string' && /^(?:0|(?:0|[1-9][0-9]{0,3})(?:px|rem|em|%))$/.test(style.border_radius)) {
		result['--page-audio-border-radius'] = style.border_radius
	}
	return result
}

export function audioTime(value) {
	if (!Number.isFinite(value) || value < 0) return '0:00'
	const seconds = Math.floor(value)
	const hours = Math.floor(seconds / 3600)
	const minutes = Math.floor(seconds / 60) % 60
	return `${hours ? `${hours}:${String(minutes).padStart(2, '0')}` : Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}
