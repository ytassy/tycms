import assert from 'node:assert/strict'
import test from 'node:test'
import { richTextColorOptions } from '../src/components/common/RichTextEditor/richTextColors.js'

test('la palette native conserve les références de template et les couleurs fixes', () => {
	const { options, contentCss } = richTextColorOptions([
		{ variable: '--Brand_Color', label: 'Marque' }, { variable: '--bs-body-bg', label: 'Fond' },
	], [
		{ variable: '--Brand_Color', color: '#1A6B6B' }, { variable: '--bs-body-bg', color: '#FFFFFF' },
	])
	assert.deepEqual(options.color_map_raw.slice(0, 4), ['var(--Brand_Color)', 'Marque (template)', 'var(--bs-body-bg)', 'Fond (template)'])
	assert.equal(options.color_map_raw.length, 4 + 22 * 2)
	assert.ok(options.color_map_raw.includes('#000000'))
	assert.ok(options.color_map_raw.includes('#FFFFFF'))
	assert.equal(contentCss, ':root{--Brand_Color:#1A6B6B;--bs-body-bg:#FFFFFF}')
})

test('un catalogue absent laisse la palette par défaut de TinyMCE', () => {
	for (const catalogue of [undefined, null, [], {}, [{ variable: 'invalid', label: 'Invalide' }]]) {
		assert.deepEqual(richTextColorOptions(catalogue), { options: {}, contentCss: '' })
	}
})

test('la feuille de l’iframe refuse les noms ou valeurs CSS injectés et les variables non déclarées', () => {
	const { options, contentCss } = richTextColorOptions([
		{ variable: '--brand', label: 'Marque' },
		{ variable: '--brand', label: 'Doublon' },
		{ variable: '--bad);color:red', label: 'Injection' },
	], [
		{ variable: '--brand', color: '#fff;}</style><script>alert(1)</script>' },
		{ variable: '--other', color: '#FFFFFF' },
		{ variable: '--brand', color: '#FFFFFF\n' },
	])
	assert.deepEqual(options.color_map_raw.slice(0, 2), ['var(--brand)', 'Marque (template)'])
	assert.equal(options.color_map_raw.length, 2 + 22 * 2)
	assert.equal(contentCss, '')
})
