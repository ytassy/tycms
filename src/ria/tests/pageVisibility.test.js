import { pageCatalogue } from './fixtures/pageCatalogue.js'
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { visibleInLocale } from '../src/components/administrator/pages/pageVisibility.js'

test('la visibilité de la page suit les langues sélectionnées', () => {
	const node = languages => ({ props: { common: { languages } } })

	assert.equal(visibleInLocale({ props: {} }, 'fr'), true)
	assert.equal(visibleInLocale(node([]), 'en'), true)
	assert.equal(visibleInLocale(node(['fr']), 'fr'), true)
	assert.equal(visibleInLocale(node(['fr']), 'en'), false)
	assert.equal(visibleInLocale(node(null), 'fr'), false)
})

test('le masquage manuel reste distinct du filtre de langue du canevas', async () => {
	const { isNodeHidden } = await import('../src/components/administrator/pages/pageVisibility.js')
	const node = { props: { common: { visible: false } } }
	assert.equal(isNodeHidden(node), true)
	assert.equal(visibleInLocale(node, 'fr'), true)
	assert.equal(isNodeHidden({ props: {} }), false)
	node.props.common.visible = true
	node.props.common.languages = ['en']
	assert.equal(isNodeHidden(node), false)
	assert.equal(visibleInLocale(node, 'fr'), false)
})

test('les commandes de visibilité restent dans le brouillon et respectent les états verrouillés', async () => {
	const { createPinia, setActivePinia } = await import('pinia')
	const { useAdminPagesStore } = await import('../src/stores/admin_pages.js')
	const { createItem, collectNodes } = await import('../src/components/administrator/pages/pageEditorModel.js')
	setActivePinia(createPinia())
	const store = useAdminPagesStore.get(null, {})()
    store.components = pageCatalogue
	const row = createItem('core.row')
	row.zones.columns[0].items.push(createItem('core.tabs'), createItem('core.accordion'), createItem('core.heading'))
	store.current = { id: 7, canEdit: true, type: 2, data: { items: [row] } }
	store.baseline = JSON.stringify(store.current)
	const original = JSON.stringify(store.current.data)
	const nodes = collectNodes(store.current.data)
	for (const { node } of nodes) {
		assert.equal(store.setNodeVisibility(node.id, false), true)
		assert.equal(node.props.common.visible, false)
	}
	assert.equal(store.isDirty, true)
	for (const { node } of nodes) assert.equal(store.setNodeVisibility(node.id, true), true)
	assert.equal(JSON.stringify(store.current.data), original)
	assert.equal(store.isDirty, false)
	store.saving = true
	assert.equal(store.setNodeVisibility(row.id, false), false)
	assert.equal(store.removeNode(row.id), false)
	assert.equal(store.moveSibling(row.zones.columns[0].items[1].id, -1), false)
	store.saving = false
	store.current.canEdit = false
	assert.equal(store.setNodeVisibility(row.id, false), false)
	store.current.canEdit = true
	assert.equal(store.setNodeVisibility(row.id, 'false'), false)
	assert.equal(store.setNodeVisibility('absent', false), false)
})
