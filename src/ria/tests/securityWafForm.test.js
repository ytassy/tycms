import assert from 'node:assert/strict'
import { test } from 'node:test'
import { securityWafForm } from '../src/components/administrator/extensions/securityWafForm.js'

const definition = {
	sections: [
		{ id: 'iframes', fields: [{ name: 'iframe_mode' }] },
		{ id: 'waf', fields: [{ type: 'core.note' }, ...['waf_use_root', 'waf_mode', 'waf_skip_root', 'waf_message', 'waf_patterns'].map(name => ({ name }))] },
	],
}

test('l’héritage ne masque que les contrôles locaux WAF sans changer la définition ni la CSP', () => {
	const before = JSON.stringify(definition)
	const inherited = securityWafForm(definition, 2, true)
	assert.deepEqual(inherited.sections[1].fields.filter(field => field.name).map(field => field.name), ['waf_use_root'])
	assert.deepEqual(inherited.sections[0], definition.sections[0])
	assert.equal(JSON.stringify(definition), before)
	assert.deepEqual(securityWafForm(definition, 2, false), definition)
})

test('seul le Space Root ne peut pas sélectionner l’héritage vers lui-même', () => {
	const root = securityWafForm(definition, 1, false)
	assert.equal(root.sections[1].fields.find(field => field.name === 'waf_use_root').readonly, true)
	const child = securityWafForm(definition, 2, false)
	assert.equal(child.sections[1].fields.find(field => field.name === 'waf_use_root').readonly, undefined)
})
