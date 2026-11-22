import { pageCatalogue } from './fixtures/pageCatalogue.js'
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPinia, setActivePinia } from 'pinia'
import { useAdminPagesStore } from '../src/stores/admin_pages.js'
import { createItem, findNode, validTree } from '../src/components/administrator/pages/pageEditorModel.js'
import { parsePageNode, pastePageNode, serializePageNode } from '../src/components/administrator/pages/pageClipboard.js'

function fixture(post = async (url, { data }) => ({ data: data.data })) {
	setActivePinia(createPinia())
	const store = useAdminPagesStore.get(null, { api: { post } })()
	const row = createItem('core.row')
	const tabs = createItem('core.tabs')
	const accordion = createItem('core.accordion')
	const heading = createItem('core.heading')
	heading.props.text = 'Source'
	tabs.zones.tabs[0].items.push(heading)
	tabs.props.default_tab = tabs.zones.tabs[0].id
	accordion.props.open_panels = [accordion.zones.panels[0].id]
	row.zones.columns[0].items = [tabs, accordion]
	store.current = { id: 1, space_id: 7, type: 2, canEdit: true, data: { version: 3, items: [row] } }
	return { store, row, tabs, accordion, heading }
}

test('le format système est autonome et les trois collages régénèrent les références internes', () => {
	const { store, tabs, accordion } = fixture()
	const payload = parsePageNode(serializePageNode(store.current.data, tabs.id))
	const original = JSON.stringify(store.current.data)
	for (const mode of ['replace', 'before', 'after']) {
		const result = pastePageNode(store.current.data, accordion.id, payload, mode)
		assert.ok(result)
		assert.equal(validTree(result.document), true)
		const found = findNode(result.document, result.nodeId)
		assert.equal(found.index, mode === 'after' ? 2 : 1)
		assert.equal(found.siblings.length, mode === 'replace' ? 2 : 3)
		assert.equal(found.node.props.default_tab, found.node.zones.tabs[0].id)
		assert.notEqual(found.node.zones.tabs[0].items[0].id, tabs.zones.tabs[0].items[0].id)
		assert.equal(JSON.stringify(store.current.data), original)
	}
})

test('remplacer le corps par défaut adapte les références de son parent', () => {
	const { store, tabs, accordion } = fixture()
	for (const [node, kind, key] of [[tabs, 'tabs', 'default_tab'], [accordion, 'panels', 'open_panels']]) {
		const payload = parsePageNode(serializePageNode(store.current.data, node.zones[kind][1].id))
		const result = pastePageNode(store.current.data, node.zones[kind][0].id, payload)
		const parent = findNode(result.document, node.id).node
		assert.deepEqual(parent.props[key], kind === 'tabs' ? result.nodeId : [result.nodeId])
	}
})

test('les incompatibilités, la racine et les limites sont refusées', () => {
	const { store, row, tabs, heading } = fixture()
	const payload = parsePageNode(serializePageNode(store.current.data, heading.id))
	assert.equal(pastePageNode(store.current.data, row.id, payload), null)
	assert.equal(pastePageNode(store.current.data, row.zones.columns[0].id, payload), null)
	const column = parsePageNode(serializePageNode(store.current.data, row.zones.columns[0].id))
	assert.equal(pastePageNode(store.current.data, tabs.zones.tabs[0].id, column), null)
	while (row.zones.columns.length < 100) row.zones.columns.push(createItem('core.row').zones.columns[0])
	assert.equal(pastePageNode(store.current.data, row.zones.columns[0].id, column, 'before'), null)
	assert.ok(pastePageNode(store.current.data, row.zones.columns[0].id, column, 'replace'))
	const full = { items: Array.from({ length: 250 }, () => createItem('core.row')) }
	const fullPayload = parsePageNode(serializePageNode(full, full.items[0].id))
	assert.equal(pastePageNode(full, full.items[0].id, fullPayload, 'after'), null)
	assert.ok(pastePageNode(full, full.items[0].id, fullPayload))
})

test('les entrées externes mal formées ou dangereuses sont refusées', () => {
	for (const text of ['texte ordinaire', '{}', 'x'.repeat(1_000_001),
		'{"format":"tycms.page-node","version":1,"kind":"item","node":{"id":"x","type":"core.row","props":{},"columns":null}}',
		'{"__proto__":{"polluted":true}}',
	]) assert.throws(() => parsePageNode(text))
	assert.equal({}.polluted, undefined)
})

test('le collage applique uniquement la réponse nettoyée du serveur et sélectionne la copie', async () => {
	const { store, tabs, heading } = fixture(async (url, { data }) => {
		assert.equal(url, '/administrator/api/pages/validate-draft')
		const last = data.data.items[0].zones.columns[0].items[1]
		last.props.text = 'Nettoyé'
		return { data: data.data }
	})
	let clipboardText
	assert.equal(await store.copyNode(heading.id, { writeText: async text => { clipboardText = text } }), true)
	assert.equal(await store.pasteNode(tabs.id, 'after', () => assert.fail('Pas de confirmation pour insérer'), { readText: async () => clipboardText }), true)
	assert.equal(store.selected.node.props.text, 'Nettoyé')
	assert.equal(store.pasting, false)
})

test('le remplacement d’une feuille exige aussi une confirmation', async () => {
	const { store, heading } = fixture()
	const text = serializePageNode(store.current.data, heading.id)
	const clipboard = { readText: async () => text }
	const original = JSON.stringify(store.current)
	assert.equal(await store.pasteNode(heading.id, 'replace', undefined, clipboard), false)
	assert.equal(await store.pasteNode(heading.id, 'replace', () => false, clipboard), false)
	assert.equal(JSON.stringify(store.current), original)
	let confirmations = 0
	assert.equal(await store.pasteNode(heading.id, 'replace', () => { confirmations++; return true }, clipboard), true)
	assert.equal(confirmations, 1)
})

test('le remplacement avec enfants demande confirmation et un refus conserve le brouillon', async () => {
	const { store, tabs, heading } = fixture(() => assert.fail('Aucun appel attendu'))
	const text = serializePageNode(store.current.data, heading.id)
	const original = JSON.stringify(store.current)
	assert.equal(await store.pasteNode(tabs.id, 'replace', () => false, { readText: async () => text }), false)
	assert.equal(JSON.stringify(store.current), original)
})

test('les attentes revérifient la page, la session, les droits et le brouillon', async () => {
	for (const step of ['read', 'confirm', 'validate']) {
		for (const change of [
			store => { store.editorSession++ },
			store => { store.current.canEdit = false },
			store => { store.saving = true },
			store => { store.current.name = 'Modification concurrente' },
			store => { store.current = JSON.parse(JSON.stringify(store.current)) },
		]) {
			const { store, tabs, heading } = fixture(async (url, { data }) => {
				if (step === 'validate') change(store)
				return { data: data.data }
			})
			const original = JSON.stringify(store.current.data)
			const text = serializePageNode(store.current.data, heading.id)
			assert.equal(await store.pasteNode(tabs.id, 'replace', async () => {
				if (step === 'confirm') change(store)
				return true
			}, { readText: async () => { if (step === 'read') change(store); return text } }), false)
			assert.equal(JSON.stringify(store.current.data), original)
			assert.equal(store.pasting, false)
		}
	}
})

test('les refus navigateur et serveur ne modifient pas le brouillon', async () => {
	const { store, heading } = fixture(async () => { throw new Error('Serveur refuse') })
	const original = JSON.stringify(store.current.data)
	const text = serializePageNode(store.current.data, heading.id)
	await assert.rejects(store.copyNode(heading.id, { writeText: async () => { throw new Error() } }), /Copie refusée/)
	await assert.rejects(store.pasteNode(heading.id, 'replace', null, { readText: async () => { throw new Error() } }), /Collage refusé/)
	await assert.rejects(store.pasteNode(heading.id, 'replace', () => true, { readText: async () => text }), /Serveur refuse/)
	assert.equal(JSON.stringify(store.current.data), original)
	assert.equal(store.pasting, false)
	store.current.canEdit = false
	assert.equal(await store.copyNode(heading.id, {}), false)
	assert.equal(await store.pasteNode(heading.id, 'replace', null, {}), false)
})
