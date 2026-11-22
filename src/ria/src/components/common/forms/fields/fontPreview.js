export const FONT_PREVIEW_SAMPLE = 'Je suis une belle police à votre entière disposition. 01 02 03 456789'

// Une feuille locale est partagée entre les champs et ne se charge qu'à la demande.
const stylesheets = new Map()

export function localFontStylesheet(url) {
	if (typeof url !== 'string' || !/^\/assets\/fonts\/google_[a-z0-9]+\/[a-f0-9]{24}\/font\.css$/.test(url)) {
		throw new Error('La feuille de police doit provenir de la bibliothèque locale.')
	}
	return url
}

export function matchingFonts(options, query, selectedValue) {
	const search = query.trim().toLocaleLowerCase()
	return options.filter(option => option.value === selectedValue
		|| (!option.disabled && option.label.toLocaleLowerCase().includes(search)))
}

export async function loadFontPreview(option, sample) {
	const url = option?.data?.fontCssUrl
	if (!url) return
	const path = localFontStylesheet(url)

	if (!stylesheets.has(path)) {
		const request = new Promise((resolve, reject) => {
			const link = document.createElement('link')
			link.rel = 'stylesheet'
			link.href = path
			const timer = setTimeout(() => fail(), 15000)
			function fail() {
				clearTimeout(timer)
				link.remove()
				reject(new Error('La feuille de la police n’a pas pu être chargée.'))
			}
			link.onload = () => { clearTimeout(timer); resolve() }
			link.onerror = fail
			document.head.append(link)
		}).catch(error => {
			stylesheets.delete(path)
			throw error
		})
		stylesheets.set(path, request)
	}
	await stylesheets.get(path)

	// Attendre le fichier WOFF2 pour ne pas présenter une police de repli comme un aperçu réussi.
	if (document.fonts) {
		const faces = await document.fonts.load(`400 20px ${JSON.stringify(option.data.fontName)}`, sample)
		if (!faces.length) throw new Error('Le fichier de cette police n’est pas disponible pour l’aperçu.')
	}
}
