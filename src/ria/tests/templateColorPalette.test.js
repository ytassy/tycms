import assert from 'node:assert/strict'
import { test } from 'node:test'
import { rgbaToHex, resolveTemplatePalette } from '../src/components/common/templateColorPalette.js'

test('une couleur opaque devient un hexadécimal à six chiffres', () => {
	assert.equal(rgbaToHex(new Uint8ClampedArray([26, 107, 107, 255])), '#1A6B6B')
	assert.equal(rgbaToHex(new Uint8ClampedArray([0, 1, 255, 255])), '#0001FF')
})

test('la transparence suit le format hexadécimal déjà accepté par la palette', () => {
	assert.equal(rgbaToHex(new Uint8ClampedArray([255, 255, 255, 128])), '#FFFFFF80')
	assert.equal(rgbaToHex(new Uint8ClampedArray([0, 0, 0, 0])), '#00000000')
})

test('un contrôle sans catalogue ne déclenche aucune résolution DOM', () => {
	assert.deepEqual(resolveTemplatePalette(null, []), [])
	assert.deepEqual(resolveTemplatePalette({}, null), [])
	assert.deepEqual(resolveTemplatePalette({}, []), [])
})
