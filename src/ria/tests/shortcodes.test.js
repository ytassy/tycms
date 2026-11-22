import assert from 'node:assert/strict'
import { test } from 'node:test'
import { pagePreviewDocument } from '../src/components/administrator/pages/pagePreviewDocument.js'

const assetsBase = '/assets_core_v1_2_3/plg_core/'

test('the isolated preview imports only the Mermaid renderer and escapes the inline CSS boundary', () => {
	const html = pagePreviewDocument({ html: '<p>Bonjour</p>', css: '</style><script>bad()</script>' }, 'fr', 'https://site.test', { assetsBase })
	assert.ok(html.includes('src="https://site.test/assets_core_v1_2_3/plg_core/mermaid/preview.mjs"'))
	assert.ok(html.includes('script-src https://site.test/assets_core_v1_2_3/plg_core/mermaid/;'))
	assert.ok(html.includes("connect-src 'none'"))
	assert.ok(html.includes('<p>Bonjour</p>'))
	assert.ok(!html.includes('</style><script>bad()'))
	assert.ok(!html.includes('main.js'))
})

test('the preview language cannot inject markup', () => {
	const html = pagePreviewDocument({ html: '' }, 'fr"><script>bad()</script>', 'https://site.test', { assetsBase })
	assert.ok(html.includes('lang="fr&quot;&gt;&lt;script&gt;'))
})
