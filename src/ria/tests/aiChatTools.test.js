import test from 'node:test'
import assert from 'node:assert/strict'
import { createHtmlContentTools, prepareTools, parseToolCall } from '../src/components/common/ai/aiTools.js'
import { wordDiff } from '../src/components/common/ai/wordDiff.js'

test('only public Tool schemas are sent to the provider', () => {
	let html = '<p>Bonjor monde</p>'
	const tools = createHtmlContentTools({ read: () => html, write: value => { html = value }, mode: 'review' })
	const { byName, definitions } = prepareTools(tools)
	assert.equal(definitions.length, 2)
	assert.equal(JSON.stringify(definitions).includes('execute'), false)
	assert.equal(tools[0].execute().html, html)
	const call = { id: 'call_1', function: { name: 'replace_content', arguments: '{"html":"<p>Bonjour monde</p>"}' } }
	const { tool, args } = parseToolCall(call, byName)
	assert.deepEqual(tool.preview(args), { before: html, after: args.html, format: 'html' })
	assert.equal(html, '<p>Bonjor monde</p>')
	assert.deepEqual(tool.execute(args), { applied: true })
	assert.equal(html, '<p>Bonjour monde</p>')
})

test('a changed editor cannot be overwritten by an old proposal', () => {
	let html = '<p>Bonjor</p>'
	const tools = createHtmlContentTools({ read: () => html, write: value => { html = value }, mode: 'direct' })
	tools[0].execute()
	html = '<p>Texte modifié par la personne</p>'
	assert.throws(() => tools[1].execute({ html: '<p>Bonjour</p>' }), /a changé/)
	assert.equal(html, '<p>Texte modifié par la personne</p>')
})

test('direct mode applies an authorized replacement after reading', () => {
	let html = '<p>fôte</p>'
	const tools = createHtmlContentTools({ read: () => html, write: value => { html = value }, mode: 'direct' })
	assert.equal(tools[1].mode, 'direct')
	assert.throws(() => tools[1].execute({ html: '<p>faute</p>' }), /doit être lu/)
	tools[0].execute()
	assert.deepEqual(tools[1].execute({ html: '<p>faute</p>' }), { applied: true })
	assert.equal(html, '<p>faute</p>')
})

test('unknown Tools and invalid arguments are rejected', () => {
	const { byName } = prepareTools(createHtmlContentTools({ read: () => '', write: () => {} }))
	assert.throws(() => parseToolCall({ id: 'call_1', function: { name: 'delete_page', arguments: '{}' } }, byName), /non autorisé/)
	assert.throws(() => parseToolCall({ id: 'call_1', function: { name: 'replace_content', arguments: '{' } }, byName), /JSON invalides/)
	assert.throws(() => parseToolCall({ id: 'call_1', function: { name: 'replace_content', arguments: '{"html":42}' } }, byName), /html texte/)
})

test('word comparison marks only the changed words', () => {
	const parts = wordDiff('Bonjour le mone !', 'Bonjour le monde !')
	assert.deepEqual(parts, [
		{ type: 'same', text: 'Bonjour le ' },
		{ type: 'removed', text: 'mone' },
		{ type: 'added', text: 'monde' },
		{ type: 'same', text: ' !' },
	])
})
