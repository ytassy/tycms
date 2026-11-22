import { getCoreAssetsBase } from '../../../application/coreAssets.js'

export function pagePreviewDocument(result, locale, origin, { resizeToken = null, assetsBase } = {}) {
	const escape = value => String(value).replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
	const css = String(result?.css ?? '').replaceAll('<', '\\3C ')
	const coreAssets = `${origin}${getCoreAssetsBase(assetsBase)}`
	const assets = `${coreAssets}mermaid/`
	// Le contenu est nettoyé au serveur ; seuls Mermaid et, pour le canevas, le pont de mesure sont autorisés.
	const resizeScript = `${coreAssets}page-canvas-size.js`
	// Les sources proviennent du serveur et du Space de la page, jamais de la session d’administration.
	// Ne conserver que les origines HTTPS et le jeton contrôlé du mode « tous HTTPS ».
	const sources = resource => {
		const values = result?.security_sources?.[resource]
		if (!Array.isArray(values)) return ''
		return [...new Set(values.filter(value => {
			if (resource === 'iframe' && value === 'https:') return true
			if (typeof value !== 'string') return false
			try {
				const url = new URL(value)
				return url.protocol === 'https:' && url.origin === value
			} catch { return false }
		}))].join(' ')
	}
	const policy = `default-src 'none'; script-src ${assets}${resizeToken ? ` ${resizeScript}` : ''}; script-src-attr 'none'; style-src ${origin} 'unsafe-inline' ${sources('style')}; img-src ${origin} data: ${sources('image')}; font-src ${origin} data: ${sources('font')}; media-src ${origin} ${sources('media')}; frame-src ${origin} ${sources('iframe')}; connect-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'`
	return `<!doctype html><html lang="${escape(locale)}"${resizeToken ? ` data-page-canvas-token="${escape(resizeToken)}"` : ''}><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
		<meta http-equiv="Content-Security-Policy" content="${escape(policy)}">
		<link rel="stylesheet" href="${escape(coreAssets)}css/index.css">
		<link rel="stylesheet" href="${escape(origin)}/templates/main/css">
		<link rel="stylesheet" href="${escape(assets)}style.css">
		<style>${css}</style>${resizeToken ? '<style>html,body{height:auto;min-height:0}body{display:flow-root}</style>' : ''}<style>body{padding:${resizeToken ? '0' : '1.5rem'};margin:0;border:0}a,button,form{pointer-events:none}</style>
		<script type="module" crossorigin="anonymous" src="${escape(assets)}preview.mjs"></script>
		</head><body>${result?.html ?? ''}${resizeToken ? `<script defer src="${escape(resizeScript)}"></script>` : ''}</body></html>`
}
