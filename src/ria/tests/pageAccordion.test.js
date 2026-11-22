import { pageCatalogue } from './fixtures/pageCatalogue.js'
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPinia, setActivePinia } from 'pinia'
import { useAdminPagesStore } from '../src/stores/admin_pages.js'
import { createItem, collectNodes, findNode, placeNode, validTree, nearestInsertTarget } from '../src/components/administrator/pages/pageEditorModel.js'
import { safePageImageUrl } from '../src/components/common/safePageImageUrl.js'
import { accordionVariables } from '../src/components/administrator/pages/pageTabsStyle.js'

function fixture() {
	const row = createItem('core.row')
	const accordion = createItem('core.accordion')
	row.zones.columns[0].items.push(accordion)
	return { page: { items: [row] }, accordion }
}

test('accordion bodies accept nested tabs and rows, and reject cycles and leaving their owner', () => {
	const { page, accordion } = fixture()
	const target = nearestInsertTarget(page, accordion.id, 'core.tabs')
	assert.equal(target.parentId, accordion.zones.panels[0].id)
	const next = placeNode(page, { kind: 'catalog', type: 'core.tabs' }, target)
	assert.equal(collectNodes(next).length, 8)
	assert.equal(findNode(next, accordion.zones.panels[0].id).kind, 'panel')
	assert.ok(placeNode(next, { kind: 'catalog', type: 'core.row' }, { ...target, index: 1 }))
	assert.equal(placeNode(next, { kind: 'node', id: accordion.id }, target), null)
	assert.equal(placeNode(next, { kind: 'node', id: accordion.zones.panels[0].id }, { kind: 'items', parentId: 'page', index: 0 }), null)
	assert.ok(placeNode(next, { kind: 'node', id: accordion.zones.panels[1].id }, { kind: 'panels', parentId: accordion.id, index: 0 }))
})

test('single_open is per component and incompatible initial selections are refused', () => {
	const { page, accordion } = fixture()
	accordion.props.open_panels = accordion.zones.panels.map(panel => panel.id)
	assert.equal(validTree(page), false)
	accordion.props.single_open = false
	assert.equal(validTree(page), true)
	accordion.props.open_panels = ['absent']
	assert.equal(validTree(page), false)
})

test('store selects nested panels and cleans initial selection when deleting a panel', () => {
	setActivePinia(createPinia())
	const store = useAdminPagesStore.get(null, {})()
    store.components = pageCatalogue
	const { page, accordion } = fixture()
	store.current = { canEdit: true, type: 2, data: page }
	store.selectNode(accordion.zones.panels[0].id)
	assert.equal(store.activeZones[accordion.id], accordion.zones.panels[0].id)
	assert.equal(store.addType('core.image'), true)
	assert.equal(store.selected.parentId, accordion.zones.panels[0].id)
	assert.equal(store.addPanel(accordion.id), true)
	const panelId = store.selectedId
	findNode(store.current.data, accordion.id).node.props.open_panels = [panelId]
	assert.equal(store.removeNode(panelId), true)
	assert.deepEqual(findNode(store.current.data, accordion.id).node.props.open_panels, [])
	assert.equal(store.moveSibling(accordion.zones.panels[1].id, -1), true)
	assert.equal(store.removeNode(accordion.zones.panels[1].id), true)
	assert.equal(store.removeNode(accordion.zones.panels[0].id), false)
})

test('accordion custom styles share validated fields with Tabs', () => {
	assert.deepEqual(accordionVariables({ mode: 'custom', accordion_style: { tab_color: '#abc', body_padding_top: '2rem', hover_color: 'url(evil)' } }), {
		'--pt-tab-color': '#abc', '--pt-body-padding-top': '2rem',
	})
	assert.deepEqual(accordionVariables({ mode: 'simple', accordion_style: { tab_color: '#abc' } }), {})
})

test('lightbox image source only accepts local paths and blocks browser authority escapes', () => {
	assert.equal(safePageImageUrl('/medias/1/photo.png'), '/medias/1/photo.png')
	for (const value of ['//evil.test/a.png', '/\\evil.test/a.png', '/medias/../secret', '/image\n.png', 'javascript:alert(1)', 'https://evil.test/a.png', null, {}]) {
		assert.equal(safePageImageUrl(value), '')
	}
})


test('arrow rotation applies to every preset, supports negative and zero angles and rejects unsafe values', () => {
	for (const angle of [-360, -90, 0, 90, 180, 360]) {
		assert.equal(accordionVariables({ mode: 'simple', arrow_rotation: angle })['--page-accordion-arrow-rotation'], `${angle}deg`)
	}
	for (const angle of [361, -361, '90', '90;display:none', 1.5]) {
		assert.equal(accordionVariables({ mode: 'custom', arrow_rotation: angle })['--page-accordion-arrow-rotation'], undefined)
	}
})
