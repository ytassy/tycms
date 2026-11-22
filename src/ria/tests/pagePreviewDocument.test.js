import assert from 'node:assert/strict'
import { test } from 'node:test'
import { pagePreviewDocument } from '../src/components/administrator/pages/pagePreviewDocument.js'

const assetsBase = '/assets_core_v1_2_3/plg_core/'

test('le canevas isolé autorise uniquement son pont de mesure et supprime les marges du document', () => {
	const html = pagePreviewDocument({ html: '<p>Bonjour</p>', css: '' }, 'fr', 'https://site.test', { resizeToken: 'frame-1', assetsBase })
	assert.ok(html.includes('data-page-canvas-token="frame-1"'))
	assert.ok(html.includes('script-src https://site.test/assets_core_v1_2_3/plg_core/mermaid/ https://site.test/assets_core_v1_2_3/plg_core/page-canvas-size.js;'))
	assert.ok(html.includes('body{padding:0;margin:0;border:0}'))
	assert.ok(html.includes('<script defer src="https://site.test/assets_core_v1_2_3/plg_core/page-canvas-size.js"></script>'))
	assert.ok(!html.includes("script-src 'unsafe-inline'"))
})

test('le rendu fidèle conserve son espacement sans activer le protocole de mesure', () => {
	const html = pagePreviewDocument({ html: '<p>Bonjour</p>', css: 'a{content:"</style>"}' }, 'fr', 'https://site.test', { assetsBase })
	assert.ok(html.includes('body{padding:1.5rem;margin:0;border:0}'))
	assert.ok(!html.includes('page-canvas-size.js'))
	assert.ok(!html.includes('content:"</style>"'))
})

test('les iframes HTTPS sont autorisées sans élargir les scripts ni les connexions de l’aperçu', () => {
	const html = pagePreviewDocument({ html: '', css: '', security_sources: { iframe: ['https:'] } }, 'fr', 'https://site.test', { assetsBase })
	assert.ok(html.includes('frame-src https://site.test https:;'))
	assert.ok(html.includes("connect-src 'none';"))
	assert.ok(html.includes("form-action 'none'"))
	assert.ok(!html.includes('script-src https:;'))
})

test('les aperçus utilisent exclusivement les origines validées du Space de la page', () => {
	const html = pagePreviewDocument({ html: '', css: '', security_sources: {
		iframe: ['https://docs.google.com'], image: ['https://images.test'],
		media: ['https://media.test'], style: ['https://fonts.googleapis.com'], font: ['https://fonts.gstatic.com'],
	} }, 'fr', 'https://admin.test', { assetsBase })
	assert.ok(html.includes('frame-src https://admin.test https://docs.google.com;'))
	assert.ok(html.includes('img-src https://admin.test data: https://images.test;'))
	assert.ok(html.includes('media-src https://admin.test https://media.test;'))
	assert.ok(html.includes("style-src https://admin.test 'unsafe-inline' https://fonts.googleapis.com;"))
	assert.ok(html.includes('font-src https://admin.test data: https://fonts.gstatic.com;'))
	assert.ok(!html.includes('frame-src https://admin.test https:;'))
})

test('les paramètres d’aperçu ne peuvent pas injecter de directive CSP', () => {
	const html = pagePreviewDocument({ html: '', css: '', security_sources: {
		iframe: ['https://allowed.test', 'https://evil.test; script-src https:', "'self'", 'data:', 'https://user@evil.test'],
		image: ['https:', 'https://evil.test/path', 'https://evil.test?q=1'],
	} }, 'fr', 'https://site.test', { assetsBase })
	assert.ok(html.includes('frame-src https://site.test https://allowed.test;'))
	assert.ok(!html.includes('evil.test'))
	assert.ok(html.includes("connect-src 'none'"))
})

test('sans politique serveur les aperçus n’autorisent aucune origine externe', () => {
	const html = pagePreviewDocument({ html: '', css: '' }, 'fr', 'https://site.test', { assetsBase })
	// Une directive sans source externe ne doit pas laisser d'espace avant le point-virgule.
	assert.ok(html.includes('frame-src https://site.test;'))
	assert.ok(!html.includes('  '), 'aucun espace double dans la politique')
	assert.ok(!html.includes('frame-src https://site.test https:;'))
})
