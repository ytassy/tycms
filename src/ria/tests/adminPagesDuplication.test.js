import { pageCatalogue } from './fixtures/pageCatalogue.js'
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPinia, setActivePinia } from 'pinia'
import { useAdminPagesStore } from '../src/stores/admin_pages.js'
import { collectNodes, createItem, createTab, findNode, validTree } from '../src/components/administrator/pages/pageEditorModel.js'

function fixture() {
	setActivePinia(createPinia())
	const store = useAdminPagesStore.get(null, {})()
    store.components = pageCatalogue
	const row = createItem('core.row')
	const tabs = createItem('core.tabs')
	const accordion = createItem('core.accordion')
	const heading = createItem('core.heading')
	heading.props.text = { fr: 'Titre', en: 'Heading' }
	heading.props.common = { visible: false, class: 'custom', role_ids: [3] }
	accordion.zones.panels[0].items.push(heading)
	accordion.props.open_panels = [accordion.zones.panels[1].id]
	tabs.zones.tabs[0].items.push(accordion)
	tabs.props.default_tab = tabs.zones.tabs[1].id
	row.zones.columns[0].items.push(tabs)
	store.current = { id: 7, type: 2, canEdit: true, data: { version: 3, items: [row] } }
	store.baseline = JSON.stringify(store.current)
	store.activeTab = 'advanced'
	return { store, row, tabs, accordion, heading }
}

test('la copie profonde renouvelle les identifiants et les références sans modifier la source', () => {
	const { store, row } = fixture()
	const original = JSON.stringify(row)
	const originalIds = new Set(collectNodes(store.current.data).map(({ node }) => node.id))
	assert.equal(store.duplicateNode(row.id), true)
	const copied = store.current.data.items[1]
	assert.equal(JSON.stringify(store.current.data.items[0]), original)
	assert.equal(store.selectedId, copied.id)
	assert.equal(store.activeTab, 'advanced')
	assert.equal(store.isDirty, true)
	assert.equal(validTree(store.current.data), true)
	for (const { node } of collectNodes({ items: [copied] })) assert.equal(originalIds.has(node.id), false)
	const tabs = copied.zones.columns[0].items[0]
	const accordion = tabs.zones.tabs[0].items[0]
	assert.equal(tabs.props.default_tab, tabs.zones.tabs[1].id)
	assert.deepEqual(accordion.props.open_panels, [accordion.zones.panels[1].id])
	const heading = accordion.zones.panels[0].items[0]
	assert.deepEqual(heading.props, row.zones.columns[0].items[0].zones.tabs[0].items[0].zones.panels[0].items[0].props)
	heading.props.text.fr = 'Copie'
	assert.equal(JSON.stringify(store.current.data.items[0]), original)
})

test('composants, colonnes, onglets et panneaux se copient après la source et sont sélectionnés', () => {
	for (const target of ['heading', 'column', 'tab', 'panel']) {
		const { store, row, tabs, accordion, heading } = fixture()
		const id = { heading: heading.id, column: row.zones.columns[0].id, tab: tabs.zones.tabs[0].id, panel: accordion.zones.panels[0].id }[target]
		const before = findNode(store.current.data, id)
		assert.equal(store.canDuplicateNode(id), true)
		assert.equal(store.duplicateNode(id), true)
		const selected = findNode(store.current.data, store.selectedId)
		assert.equal(selected.parentId, before.parentId)
		assert.equal(selected.index, before.index + 1)
		assert.equal(selected.kind, before.kind)
		assert.equal(validTree(store.current.data), true)
	}
})

test('les limites désactivent la commande sans modifier le brouillon', () => {
	for (const kind of ['columns', 'tabs', 'panels', 'nodes']) {
		const { store, row, tabs, accordion } = fixture()
		let id
		if (kind === 'nodes') {
			store.current.data.items = Array.from({ length: 250 }, () => createItem('core.row'))
			id = store.current.data.items[0].id
		} else {
			const parent = { columns: row, tabs, panels: accordion }[kind]
			while (parent.zones[kind].length < 100) parent.zones[kind].push(kind === 'columns' ? createItem('core.row').zones.columns[0] : createTab())
			id = parent.zones[kind][0].id
		}
		const before = JSON.stringify(store.current)
		assert.equal(store.canDuplicateNode(id), false)
		assert.equal(store.duplicateNode(id), false)
		assert.equal(JSON.stringify(store.current), before)
	}
})

test('lecture seule, sauvegarde, type HTML et cible absente bloquent la duplication', () => {
	for (const change of [
		store => { store.current.canEdit = false },
		store => { store.saving = true },
		store => { store.current.type = 1 },
		store => { store.current = null },
	]) {
		const { store, row } = fixture()
		change(store)
		const before = JSON.stringify(store.current)
		assert.equal(store.canDuplicateNode(row.id), false)
		assert.equal(store.duplicateNode(row.id), false)
		assert.equal(JSON.stringify(store.current), before)
	}
	const { store } = fixture()
	assert.equal(store.duplicateNode('absent'), false)
})
