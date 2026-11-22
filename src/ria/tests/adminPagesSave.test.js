import { pageCatalogue } from './fixtures/pageCatalogue.js'
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPinia, setActivePinia } from 'pinia'
import { useAdminPagesStore } from '../src/stores/admin_pages.js'
import { validTree } from '../src/components/administrator/pages/pageEditorModel.js'

const visualPage = () => ({
	id: 7, space_id: 1, name: 'Page', description: '', type: 2,
	raw_html: '', raw_scss: '', revision: 1, canEdit: true,
	data: { version: 3, container: { fluid: false }, items: [
		{ id: 'row_1', type: 'core.row', props: {}, zones: { columns: [{
			id: 'column_1', sizes: { xs: 12 }, props: {}, items: [
				{ id: 'node_1', type: 'core.heading', props: { text: 'Titre' }, zones: {} },
			],
		}] } },
	] },
})
const copy = value => JSON.parse(JSON.stringify(value))

function createStore(page) {
	// Les scénarios utilisent le format v3, y compris les zones vides des feuilles.
	if (page.type === 2) assert.equal(validTree(page.data, pageCatalogue), true)

	setActivePinia(createPinia())
	let savedPage = copy(page)
	const application = { api: {
		async get(path) {
			if (path === '/administrator/api/pages') return { spaces: [], pages: [], components: copy(pageCatalogue) }
			if (path === '/administrator/api/pages/read') return { page: copy(savedPage) }
			throw new Error(`Unexpected GET ${path}`)
		},
		async post(path, { data }) {
			assert.equal(path, '/administrator/api/pages/update')
			savedPage = { ...savedPage, ...copy(data), revision: savedPage.revision + 1 }
			return { page: { id: savedPage.id } }
		},
	} }
	// Le vrai contexte de l'éditeur fournit le catalogue nécessaire aux zones.
	const store = useAdminPagesStore.get(null, application)()
	store.components = copy(pageCatalogue)
	return store
}

test('saving a visual page keeps the properties tab and selected component', async () => {
	const store = createStore(visualPage())
	await store.open(7)
	store.selectNode('node_1')
	store.selected.node.props.text = 'Titre modifié'
	assert.equal(await store.save(), true)
	assert.equal(store.activeTab, 'properties')
	assert.equal(store.selectedId, 'node_1')
	assert.equal(store.selected?.node.props.text, 'Titre modifié')
	assert.equal(store.isDirty, false)
})

test('saving page settings keeps the page tab', async () => {
	const store = createStore(visualPage())
	await store.open(7)
	store.activeTab = 'page'
	store.current.name = 'Nouveau titre'
	await store.save()
	assert.equal(store.activeTab, 'page')
	assert.equal(store.current.name, 'Nouveau titre')
})

test('opening another page resets the visual tab, while raw HTML opens its page tab', async () => {
	const store = createStore(visualPage())
	await store.open(7)
	store.selectNode('node_1')
	await store.open(7)
	assert.equal(store.activeTab, 'components')
	assert.equal(store.selectedId, null)

	const rawStore = createStore({ ...visualPage(), type: 1, data: null })
	await rawStore.open(7)
	assert.equal(rawStore.activeTab, 'page')
})

test('tree selection and sibling moves keep Structure open until Properties is requested', async () => {
	const page = visualPage()
	page.data.items = [{
		id: 'row_1', type: 'core.row', props: {}, zones: { columns: [{
			id: 'column_1', sizes: { xs: 12 }, props: {}, items: [
				{ id: 'node_1', type: 'core.heading', props: { text: 'Premier' }, zones: {} },
				{ id: 'node_2', type: 'core.heading', props: { text: 'Second' }, zones: {} },
			],
		}] },
	}]
	const store = createStore(page)
	await store.open(7)
	store.activeTab = 'tree'
	store.selectNode('node_1', { showProperties: false })
	assert.equal(store.activeTab, 'tree')
	assert.equal(store.moveSibling('node_1', 1), true)
	assert.deepEqual(store.current.data.items[0].zones.columns[0].items.map(item => item.id), ['node_2', 'node_1'])
	assert.equal(store.activeTab, 'tree')
	assert.equal(store.selectedId, 'node_1')
	store.selectNode('node_1')
	assert.equal(store.activeTab, 'properties')
})

test('dropping a tree element into another column keeps Structure open', async () => {
	const page = visualPage()
	page.data.items = [{
		id: 'row_1', type: 'core.row', props: {}, zones: { columns: [
			{ id: 'column_1', sizes: { xs: 6 }, props: {}, items: [{ id: 'node_1', type: 'core.heading', props: { text: 'Titre' }, zones: {} }] },
			{ id: 'column_2', sizes: { xs: 6 }, props: {}, items: [] },
		] },
	}]
	const store = createStore(page)
	await store.open(7)
	store.activeTab = 'tree'
	store.beginDrag({ kind: 'node', id: 'node_1' })
	assert.equal(store.drop({ kind: 'items', parentId: 'column_2', index: 0 }), true)
	assert.equal(store.current.data.items[0].zones.columns[1].items[0].id, 'node_1')
	assert.equal(store.activeTab, 'tree')
	assert.equal(store.selectedId, 'node_1')
})
