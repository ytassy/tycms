import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPinia, setActivePinia } from 'pinia'
import { pageCatalogue } from './fixtures/pageCatalogue.js'
import { createItem, createZone, validTree, duplicateNode, canDuplicateNode, placeNode, findNode } from '../src/components/administrator/pages/pageEditorModel.js'
import { useAdminPagesStore } from '../src/stores/admin_pages.js'
import { collectionVariables, testimonialStyleFields, timelineStyleFields } from '../src/components/common/pageCollectionStyle.js'
import { testimonialPositions } from '../src/components/features/testimonials/testimonialsCarousel.js'
import { serializePageNode, parsePageNode } from '../src/components/administrator/pages/pageClipboard.js'

function fixture(type = 'core.timeline') {
	const row = createItem('core.row')
	const node = createItem(type)
	row.zones.columns[0].items.push(node)
	return { document: { items: [row] }, node }
}

test('timeline supports nested components, duplication with new IDs, and keeps steps attached to their owner', () => {
	const { document, node } = fixture()
	const step = node.zones.steps[0]
	const nested = placeNode(document, { kind: 'catalog', type: 'core.rich-text' }, { kind: 'items', parentId: step.id, index: 0 })
	assert.ok(nested)
	const copied = duplicateNode(nested, step.id)
	const copy = findNode(copied.document, copied.nodeId).node
	assert.notEqual(copy.id, step.id)
	assert.notEqual(copy.items[0].id, findNode(nested, step.id).node.items[0].id)
	assert.equal(placeNode(document, { kind: 'node', id: step.id }, { kind: 'items', parentId: 'page', index: 0 }), null)
	assert.equal(placeNode(document, { kind: 'node', id: node.id }, { kind: 'items', parentId: step.id, index: 0 }), null)
})

test('all core zone collections accept 100 bodies and reject adding or duplicating the 101st', () => {
	for (const [type, name] of [['core.timeline', 'steps'], ['core.tabs', 'tabs'], ['core.accordion', 'panels'], ['core.row', 'columns']]) {
		const { document, node } = fixture(type)
		node.zones[name] = Array.from({ length: 99 }, () => createZone())
		assert.equal(validTree(document), true)
		assert.equal(canDuplicateNode(document, node.zones[name][0].id), true)
		node.zones[name].push(createZone())
		assert.equal(validTree(document), true)
		assert.equal(canDuplicateNode(document, node.zones[name][0].id), false)
		node.zones[name].push(createZone())
		assert.equal(validTree(document), false)
	}
})

test('the store adds timeline steps up to 100 and enforces readonly', () => {
	setActivePinia(createPinia())
	const store = useAdminPagesStore.get(null, {})()
	const { document, node } = fixture()
	store.components = pageCatalogue
	store.current = { canEdit: true, type: 2, data: document }
	node.zones.steps = Array.from({ length: 99 }, () => createZone())
	assert.equal(store.addZone(node.id, 'steps'), true)
	assert.equal(store.selected.parentId, node.id)
	assert.equal(store.addZone(node.id, 'steps'), false)
	store.current.canEdit = false
	assert.equal(store.addZone(node.id, 'steps'), false)
})

test('duplicating a testimonials instance deeply copies its whole list', () => {
	const { document, node } = fixture('core.testimonials')
	node.props.testimonials[0].text = { fr: 'Merci', en: 'Thanks' }
	const result = duplicateNode(document, node.id)
	const copy = findNode(result.document, result.nodeId).node
	copy.props.testimonials[0].text.fr = 'Modifié'
	assert.equal(node.props.testimonials[0].text.fr, 'Merci')
	assert.equal(copy.props.testimonials.length, 1)
	assert.deepEqual(copy.zones, {})
})

test('clipboard accepts a timeline with 100 steps and rejects 101 before server validation', () => {
	const { document, node } = fixture()
	node.zones.steps = Array.from({ length: 100 }, () => createZone())
	assert.equal(parsePageNode(serializePageNode(document, node.id)).node.zones.steps.length, 100)
	node.zones.steps.push(createZone())
	assert.throws(() => parsePageNode(serializePageNode(document, node.id)))
})

test('adding a column still respects the global 500-node budget', () => {
	setActivePinia(createPinia())
	const store = useAdminPagesStore.get(null, {})()
	store.components = pageCatalogue
	store.current = { canEdit: true, type: 2, data: { items: Array.from({ length: 250 }, () => createItem('core.row')) } }
	const before = JSON.stringify(store.current.data)
	store.addColumn(store.current.data.items[0].id)
	assert.equal(JSON.stringify(store.current.data), before)
})

test('collection styles reject CSS injection and retain zero lengths and declared color syntax', () => {
	assert.deepEqual(collectionVariables({ padding: 0, color: 'var(--primary)', line_width: 3, shadow: 'soft' }, timelineStyleFields), {
		'--pc-padding': '0px', '--pc-color': 'var(--primary)', '--pc-line-width': '3px', '--pc-shadow': '0 4px 18px #0000000D',
	})
	for (const style of [{ padding: -1 }, { padding: '10px' }, { padding: 99999 }, { color: 'red;display:none' }, { shadow: '__proto__' }, { unknown: 5 }]) {
		assert.deepEqual(collectionVariables(style, testimonialStyleFields), {})
	}
})

test('carousel page positions adapt to columns and include the last incomplete page without duplicates', () => {
	assert.deepEqual(testimonialPositions([0, 100, 200, 300, 400], 200, 3), [0, 200])
	assert.deepEqual(testimonialPositions([0, 100, 200, 300, 400], 300, 2), [0, 200, 300])
	assert.deepEqual(testimonialPositions([0, 100], 0, 3), [0])
	assert.deepEqual(testimonialPositions([0, 100, 200], 200, 1), [0, 100, 200])
})
