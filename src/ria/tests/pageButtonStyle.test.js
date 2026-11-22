import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buttonClasses, buttonStyle } from '../src/components/administrator/pages/pageButtonStyle.js'

test('button preview uses Bootstrap variants and validated custom overrides', () => {
	assert.deepEqual(buttonClasses({ variant: 'outline-danger', size: 'sm' }), ['btn', 'btn-outline-danger', 'btn-sm'])
	assert.deepEqual(buttonClasses({ variant: 'invalid', size: 'invalid' }), ['btn', 'btn-primary'])
	assert.deepEqual(buttonStyle({ button_style: {
		color: '#abc', hover_background_color: '#12345680', border_width: '2px', border_radius: '25%',
	} }), {
		'--bs-btn-color': '#abc', '--bs-btn-hover-bg': '#12345680',
		'--bs-btn-border-width': '2px', '--bs-btn-border-radius': '25%',
	})
})

test('button preview ignores unsafe custom values', () => {
	assert.deepEqual(buttonStyle({ button_style: {
		color: '#fff;display:none', border_width: '1px;display:none', unknown: '#fff',
	} }), {})
})
