import assert from 'node:assert/strict'
import { test } from 'node:test'
import { registerExtensionConfigView, resolveExtensionConfigView } from '../src/application/configViewRegistry.js'

test('les vues de configuration sont résolues par identifiant logique', () => {
	const component = { name: 'LmsConfig' }
	registerExtensionConfigView('lms/config', component)

	assert.equal(resolveExtensionConfigView('lms/config'), component)
	assert.equal(resolveExtensionConfigView('lms/inconnue'), null)
	assert.throws(() => registerExtensionConfigView('lms/config', {}))
	assert.throws(() => registerExtensionConfigView('core/config', {}))
	assert.throws(() => registerExtensionConfigView('../config', {}))
})
