import assert from 'node:assert/strict'
import { test } from 'node:test'
import { backgroundPositionAxes, canvasStyle, hasLayeredBackground, hasLinkColor } from '../src/components/administrator/pages/pageCommonStyle.js'

test('link color uses a validated inherited variable and accepts template colors', () => {
	for (const value of ['#fff', '#FFFFFF80', 'var(--bs-primary)']) {
		assert.deepEqual(canvasStyle({ style: { link_color: value } }), { '--page-link-color': value })
		assert.equal(hasLinkColor({ style: { link_color: value } }), true)
	}
	for (const value of ['', '#fff;display:none', 'url(https://example.com)', 'var(--brand, red)', '#fff\n', null, 123]) {
		assert.deepEqual(canvasStyle({ style: { link_color: value } }), {})
		assert.equal(hasLinkColor({ style: { link_color: value } }), false)
	}
})

test('canvas applies the same structured spacing, border, background and position fields', () => {
	const style = canvasStyle({ style: {
		margin_top: '-2rem', padding_left: '12px', border_bottom: '1px', border_color: '#abc',
		background_image: '/medias/cover.jpg', background_size: 'cover', position: 'relative', offset_top: '4px',
	} })
	assert.deepEqual(style, {
		marginTop: '-2rem', top: '4px', paddingLeft: '12px', borderWidth: '0', borderBottomWidth: '1px',
		borderStyle: 'solid', borderColor: '#abc', backgroundImage: 'url("/medias/cover.jpg")',
		backgroundRepeat: 'no-repeat', backgroundSize: 'cover', position: 'relative',
	})
})

test('canvas ignores an unsafe background image while editing', () => {
	assert.deepEqual(canvasStyle({ style: { background_image: '/x.jpg");color:red', margin_top: '1px;display:none' } }), {})
})

test('canvas accepts unitless zero for spacing and offsets', () => {
	assert.deepEqual(canvasStyle({ style: { margin_top: '0', padding_left: '0', offset_right: '0' } }), {
		marginTop: '0', paddingLeft: '0', right: '0',
	})
})

test('canvas applies only supported common text alignment', () => {
	assert.equal(canvasStyle({ style: { text_align: 'center' } }).textAlign, 'center')
	assert.deepEqual(canvasStyle({ style: {} }), {})
	assert.deepEqual(canvasStyle({ style: { text_align: 'center;display:none' } }), {})
})

test('custom background position accepts only X and Y from 0 to 100 percent', () => {
	assert.deepEqual(backgroundPositionAxes('25% 75%'), { x: 25, y: 75 })
	assert.equal(backgroundPositionAxes('101% 50%'), null)
	assert.equal(backgroundPositionAxes('0% -1%'), null)
	assert.equal(canvasStyle({ style: { background_image: '/cover.jpg', background_position: '25% 75%' } }).backgroundPosition, '25% 75%')
})

test('image and tint opacity use separate canvas layers without dimming the content', () => {
	const common = { style: {
		background_color: '#000000', background_image: '/cover.jpg', background_position: '25% 75%',
		background_size: 'cover', background_image_opacity: '60', background_image_tint: '#FFFFFF4D',
	} }
	assert.equal(hasLayeredBackground(common), true)
	assert.deepEqual(canvasStyle(common), {
		backgroundColor: '#000000', isolation: 'isolate', position: 'relative',
		'--page-background-image': 'url("/cover.jpg")', '--page-background-opacity': '0.6',
		'--page-background-position': '25% 75%', '--page-background-size': 'cover',
		'--page-background-tint-color': '#FFFFFF4D',
	})
	assert.equal(hasLayeredBackground({ style: { background_image: '/cover.jpg', background_image_opacity: '100' } }), false)
	assert.equal(hasLayeredBackground({ style: { background_image: '/cover.jpg', background_image_tint: '#FFFFFF00' } }), false)
	assert.equal(hasLayeredBackground({ style: { background_image: '/cover.jpg', background_image_tint: '#FFFFFF4D' } }), true)
	assert.equal(hasLayeredBackground({ style: { background_image: '/cover.jpg', background_image_tint: '#FFFFFF;display:none' } }), false)
	assert.deepEqual(canvasStyle({ style: { background_image: '/child.jpg', background_image_opacity: '50' } }), {
		isolation: 'isolate', position: 'relative', '--page-background-image': 'url("/child.jpg")',
		'--page-background-opacity': '0.5', '--page-background-position': '0% 0%',
		'--page-background-size': 'auto', '--page-background-tint-color': 'transparent',
	})
})
