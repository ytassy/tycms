import { pageCatalogue } from './fixtures/pageCatalogue.js'
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPinia, setActivePinia } from 'pinia'
import { useAdminPagesStore } from '../src/stores/admin_pages.js'
import { createColumn, createItem, findNode } from '../src/components/administrator/pages/pageEditorModel.js'

function fixture() {
	setActivePinia(createPinia())
	const store = useAdminPagesStore.get(null, {})()
    store.components = pageCatalogue
	const row = createItem('core.row')
	const heading = createItem('core.heading')
	const tabs = createItem('core.tabs')
	const accordion = createItem('core.accordion')
	row.zones.columns[0].items.push(heading, tabs, accordion)
	store.current = { id: 7, type: 2, canEdit: true, data: { items: [row] } }
	return { store, row, heading, tabs, accordion }
}

test('feuilles et conteneurs exigent une confirmation avant suppression', async () => {
	const { store, row, heading } = fixture()
	let confirmations = 0
	assert.equal(await store.requestRemoveNode(heading.id), false)
	assert.equal(await store.requestRemoveNode(heading.id, () => false), false)
	assert.ok(findNode(store.current.data, heading.id))
	assert.equal(await store.requestRemoveNode(heading.id, () => { confirmations++; return true }), true)
	assert.equal(confirmations, 1)
	assert.equal(findNode(store.current.data, heading.id), null)
	assert.equal(await store.requestRemoveNode(row.id), false)
	assert.equal(await store.requestRemoveNode(row.id, message => {
		confirmations++
		assert.match(message, /tout son contenu/)
		return false
	}), false)
	assert.ok(findNode(store.current.data, row.id))
	assert.equal(await store.requestRemoveNode(row.id, () => true), true)
	assert.deepEqual(store.current.data.items, [])
})

test('la même règle protège la dernière colonne et les derniers corps dans toutes les commandes', async () => {
	const { store, row, tabs, accordion } = fixture()
	tabs.zones.tabs.pop()
	accordion.zones.panels.pop()
	for (const id of [row.zones.columns[0].id, tabs.zones.tabs[0].id, accordion.zones.panels[0].id]) {
		assert.equal(store.canRemoveNode(id), false)
		assert.equal(store.removeNode(id), false)
		assert.equal(await store.requestRemoveNode(id, () => assert.fail('Aucune confirmation ne doit être ouverte')), false)
	}
	assert.equal(store.canRemoveNode('absent'), false)
})

test('les panneaux de propriétés peuvent conserver la confirmation des corps vides', async () => {
	const { store, tabs, accordion } = fixture()
	let message = ''
	assert.equal(await store.requestRemoveNode(tabs.zones.tabs[0].id, text => { message = text; return false }, { confirmEmpty: true }), false)
	assert.match(message, /onglet/)
	assert.ok(findNode(store.current.data, tabs.zones.tabs[0].id))
	assert.equal(await store.requestRemoveNode(accordion.zones.panels[0].id, text => { message = text; return true }, { confirmEmpty: true }), true)
	assert.match(message, /panneau/)
	assert.equal(findNode(store.current.data, accordion.zones.panels[0].id), null)
})

test('une confirmation différée revérifie la page, la session et les droits avant de supprimer', async () => {
	for (const change of [
		store => { store.editorSession++ },
		store => { store.current = JSON.parse(JSON.stringify(store.current)) },
		store => { store.current.canEdit = false },
		store => { store.saving = true },
	]) {
		const { store, row } = fixture()
		let resolve
		const pending = store.requestRemoveNode(row.id, () => new Promise(done => { resolve = done }))
		change(store)
		resolve(true)
		assert.equal(await pending, false)
		assert.ok(findNode(store.current.data, row.id))
	}
})

test('la dernière colonne est protégée même si elle le devient pendant la confirmation', async () => {
	const { store, row } = fixture()
	const other = createColumn()
	row.zones.columns.push(other)
	let resolve
	const pending = store.requestRemoveNode(row.zones.columns[0].id, () => new Promise(done => { resolve = done }))
	assert.equal(store.removeNode(other.id), true)
	resolve(true)
	assert.equal(await pending, false)
	assert.equal(findNode(store.current.data, row.id).node.zones.columns.length, 1)
})

test('lecture seule et sauvegarde empêchent même la préparation de suppression', async () => {
	for (const state of ['readonly', 'saving']) {
		const { store, heading } = fixture()
		if (state === 'readonly') store.current.canEdit = false
		else store.saving = true
		assert.equal(store.canRemoveNode(heading.id), false)
		assert.equal(await store.requestRemoveNode(heading.id, () => assert.fail('Aucune modale attendue')), false)
		assert.ok(findNode(store.current.data, heading.id))
	}
})
