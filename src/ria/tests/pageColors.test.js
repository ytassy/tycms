import assert from 'node:assert/strict'
import { test } from 'node:test'
import { isPageColor, templateColorName, hasVisibleTint } from '../src/components/common/pageColors.js'
import { canvasStyle, hasLayeredBackground } from '../src/components/administrator/pages/pageCommonStyle.js'
import { buttonStyle } from '../src/components/administrator/pages/pageButtonStyle.js'
import { tabsVariables, accordionVariables } from '../src/components/administrator/pages/pageTabsStyle.js'
import { audioPlayerStyle } from '../src/components/features/audio/audioPlayer.js'

test('les couleurs restent des références CSS dans tous les composants', () => {
	const value = 'var(--bs-primary)'
	assert.equal(templateColorName(value), '--bs-primary')
	assert.equal(isPageColor('#abc'), true)
	assert.equal(isPageColor('#abc', false), false)
	assert.equal(canvasStyle({ style: { color: value } }).color, value)
	assert.equal(buttonStyle({ button_style: { hover_color: value } })['--bs-btn-hover-color'], value)
	assert.equal(tabsVariables({ mode: 'custom', tabs_style: { tab_color: value } })['--pt-tab-color'], value)
	assert.equal(accordionVariables({ mode: 'custom', accordion_style: { body_color: value } })['--pt-body-color'], value)
	assert.equal(audioPlayerStyle({ accent_color: value })['--page-audio-accent-color'], value)
})

test('un filigrane variable reste visible sans décoder un alpha hexadécimal', () => {
	const common = { style: { background_image: '/cover.jpg', background_image_tint: 'var(--bs-primary)' } }
	assert.equal(hasLayeredBackground(common), true)
	assert.equal(canvasStyle(common)['--page-background-tint-color'], 'var(--bs-primary)')
	assert.equal(hasVisibleTint('#FFFFFF00'), false)
})

test('le canevas refuse les expressions, replis, injections et retours à la ligne', () => {
	for (const value of ['var(--brand);display:none', 'var(--brand, red)', 'var( --brand)', 'var(--brand )', 'var(--brand)/*x*/',
		'url(https://evil.test)', 'var(--brand);--x:url(https://evil.test)', 'var(--brand)\n', '#fff\n', 'var(--)']) {
		assert.equal(isPageColor(value), false, value)
		assert.deepEqual(canvasStyle({ style: { color: value } }), {})
		assert.deepEqual(audioPlayerStyle({ accent_color: value }), {})
	}
})
