import { pageCatalogue } from './fixtures/pageCatalogue.js'
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createItem, collectNodes, findNode, placeNode, nearestInsertTarget, validTree } from '../src/components/administrator/pages/pageEditorModel.js'
import { tabsVariables } from '../src/components/administrator/pages/pageTabsStyle.js'

function fixture() {
	const row = createItem('core.row')
	const tabs = createItem('core.tabs')
	row.zones.columns[0].items.push(tabs)
	return { page: { items: [row] }, tabs, column: row.zones.columns[0] }
}

test('tab bodies accept components and rows and participate in selection and traversal', () => {
	const { page, tabs } = fixture()
	const target = nearestInsertTarget(page, tabs.id, 'core.heading')
	assert.equal(target.parentId, tabs.zones.tabs[0].id)
	const next = placeNode(page, { kind: 'catalog', type: 'core.heading' }, target)
	const heading = next.items[0].zones.columns[0].items[0].zones.tabs[0].items[0]
	assert.equal(findNode(next, heading.id).parentId, tabs.zones.tabs[0].id)
	assert.equal(findNode(next, tabs.zones.tabs[0].id).kind, 'tab')
	assert.equal(collectNodes(next).length, 6)
	assert.ok(placeNode(next, { kind: 'catalog', type: 'core.row' }, { ...target, index: 1 }))
	assert.ok(validTree(next))
})

test('tabs can be reordered but bodies cannot become columns or leave their owner', () => {
	const { page, tabs, column } = fixture()
	tabs.props.default_tab = tabs.zones.tabs[1].id
	const moved = placeNode(page, { kind: 'node', id: tabs.zones.tabs[1].id }, { kind: 'tabs', parentId: tabs.id, index: 0 })
	assert.equal(findNode(moved, tabs.id).node.zones.tabs[0].id, tabs.props.default_tab)
	assert.equal(placeNode(page, { kind: 'node', id: tabs.zones.tabs[0].id }, { kind: 'items', parentId: column.id, index: 0 }), null)
	assert.equal(placeNode(page, { kind: 'node', id: tabs.id }, { kind: 'items', parentId: tabs.zones.tabs[0].id, index: 0 }), null)
})

test('tab descendants cannot bypass page node and depth limits', () => {
	const { page, tabs } = fixture()
	tabs.zones.tabs[0].items = Array.from({ length: 496 }, () => createItem('core.heading'))
	assert.equal(validTree(page), false)
	const nested = createItem('core.tabs')
	let current = nested
	for (let i = 0; i < 13; i++) {
		const next = createItem('core.tabs')
		current.zones.tabs[0].items.push(next)
		current = next
	}
	tabs.zones.tabs[0].items = [nested]
	assert.equal(validTree(page), false)
})

test('only validated custom styles become variables; presets ignore stored overrides', () => {
	const tabs_style = { tab_color: '#080d55', active_background_color: '#e5e5e5', body_padding_top: '2rem',
		body_background_color: 'url(https://invalid)', tab_border_width: '-1px', unknown: 'red' }
	assert.deepEqual(tabsVariables({ mode: 'simple', tabs_style }), {})
	assert.deepEqual(tabsVariables({ mode: 'custom', tabs_style }), {
		'--pt-tab-color': '#080d55', '--pt-active-background-color': '#e5e5e5', '--pt-body-padding-top': '2rem',
	})
})

test('store keeps tab selection usable after adding, reordering and deleting a default body', async () => {
	const { createPinia, setActivePinia } = await import('pinia')
	const { useAdminPagesStore } = await import('../src/stores/admin_pages.js')
	setActivePinia(createPinia())
	const { page, tabs } = fixture()
	const store = useAdminPagesStore.get(null, {})()
    store.components = pageCatalogue
	store.current = { canEdit: true, type: 2, data: page }
	assert.equal(store.addTab(tabs.id), true)
	let container = findNode(store.current.data, tabs.id).node
	const tabId = store.selectedId
	container.props.default_tab = tabId
	assert.equal(store.moveSibling(tabId, -1), true)
	assert.equal(store.removeNode(tabId), true)
	container = findNode(store.current.data, tabs.id).node
	assert.equal(container.props.default_tab, undefined)
	assert.equal(store.previewTabs[tabs.id], undefined)
	store.selectNode(tabs.id)
	assert.equal(store.addType('core.heading'), true)
	assert.equal(store.selected.parentId, container.zones.tabs[0].id)
	assert.ok(validTree(store.current.data))
	const remaining = findNode(store.current.data, tabs.id).node.zones.tabs
	assert.equal(store.removeNode(remaining[1].id), true)
	assert.equal(store.removeNode(remaining[0].id), false)
})
