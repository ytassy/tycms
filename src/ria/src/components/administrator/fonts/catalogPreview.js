import { FONT_PREVIEW_SAMPLE } from '../../common/forms/fields/fontPreview.js'

export function googleFontPreview(font) {
	const family = font?.family
	const variant = font?.previewVariant ?? '400'
	if (typeof family !== 'string' || !family || family.length > 200 || !/^[A-Za-z0-9 &'().+/-]+$/.test(family)
		|| typeof variant !== 'string' || !/^[1-9]00i?$/.test(variant)) {
		throw new Error('Cette famille ne peut pas être prévisualisée.')
	}
	const italic = variant.endsWith('i')
	const weight = Number.parseInt(variant, 10)
	const url = new URL('https://fonts.googleapis.com/css2')
	url.searchParams.set('family', `${family}:ital,wght@${italic ? 1 : 0},${weight}`)
	url.searchParams.set('display', 'swap')
	// Seuls les caractères du texte d'aperçu sont demandés, pour limiter le trafic.
	url.searchParams.set('text', FONT_PREVIEW_SAMPLE)
	return { url: url.href, family: JSON.stringify(family), weight, style: italic ? 'italic' : 'normal' }
}

export function createCatalogPreview(font, doc = document) {
	const info = googleFontPreview(font)
	const link = doc.createElement('link')
	link.rel = 'stylesheet'
	link.href = info.url
	let settle
	const ready = new Promise((resolve, reject) => {
		let finished = false
		const timer = setTimeout(() => settle(new Error('Le chargement de l’aperçu Google a expiré.')), 20000)
		settle = error => {
			if (finished) return
			finished = true
			clearTimeout(timer)
			link.onload = null
			link.onerror = null
			if (error) { link.remove(); reject(error) }
			else resolve()
		}
		link.onerror = () => settle(new Error('L’aperçu Google est indisponible.'))
		link.onload = async () => {
			try {
				if (doc.fonts) {
					const faces = await doc.fonts.load(`${info.style} ${info.weight} 20px ${info.family}`, FONT_PREVIEW_SAMPLE)
					if (!faces.length) throw new Error('Le fichier de l’aperçu Google est indisponible.')
				}
				settle()
			} catch (error) { settle(error) }
		}
	})
	doc.head.append(link)
	return {
		info, ready,
		dispose() {
			// La pagination retire les feuilles des familles qui ne sont plus affichées.
			link.remove()
			settle(new Error('Aperçu fermé.'))
		},
	}
}
