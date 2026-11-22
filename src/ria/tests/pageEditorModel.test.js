import { pageCatalogue } from './fixtures/pageCatalogue.js'
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createColumn, createItem, findNode, placeNode } from '../src/components/administrator/pages/pageEditorModel.js'

const emptyPage = () => ({ version: 3, container: { fluid: false }, items: [] })

test('node creation uses getRandomValues when randomUUID is unavailable', () => {
	const originalCrypto = Object.getOwnPropertyDescriptor(globalThis, 'crypto')
	Object.defineProperty(globalThis, 'crypto', {
		configurable: true,
		value: { getRandomValues(bytes) { bytes.fill(7); return bytes } },
	})
	try {
		assert.equal(createItem('core.heading').id, `n_${'07'.repeat(16)}`)
	} finally {
		if (originalCrypto) Object.defineProperty(globalThis, 'crypto', originalCrypto)
		else delete globalThis.crypto
	}
})

test('node creation works when randomUUID and Web Crypto are unavailable', () => {
	const originalCrypto = Object.getOwnPropertyDescriptor(globalThis, 'crypto')
	Object.defineProperty(globalThis, 'crypto', { configurable: true, value: undefined })
	try {
		const first = createItem('core.heading')
		const second = createItem('core.heading')
		assert.match(first.id, /^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/)
		assert.notEqual(first.id, second.id)
	} finally {
		if (originalCrypto) Object.defineProperty(globalThis, 'crypto', originalCrypto)
		else delete globalThis.crypto
	}
})

test('a component dropped on an empty page gets a row and a column', () => {
	const page = emptyPage()
	const next = placeNode(page, { kind: 'catalog', type: 'core.heading' }, { kind: 'items', parentId: 'page', index: 0 })
	assert.equal(page.items.length, 0)
	assert.equal(next.items[0].type, 'core.row')
	assert.equal(next.items[0].zones.columns[0].items[0].type, 'core.heading')
	assert.equal(new Set([next.items[0].id, next.items[0].zones.columns[0].id, next.items[0].zones.columns[0].items[0].id]).size, 3)
})

test('moving across columns preserves the node and refuses a cycle', () => {
	const outer = createItem('core.row')
	const second = createColumn()
	outer.zones.columns.push(second)
	const nested = createItem('core.row')
	outer.zones.columns[0].items.push(nested)
	const heading = createItem('core.heading')
	outer.zones.columns[0].items.push(heading)
	const page = { ...emptyPage(), items: [outer] }

	const moved = placeNode(page, { kind: 'node', id: heading.id }, { kind: 'items', parentId: second.id, index: 0 })
	assert.equal(moved.items[0].zones.columns[0].items.length, 1)
	assert.equal(moved.items[0].zones.columns[1].items[0].id, heading.id)
	assert.equal(findNode(moved, heading.id).parentId, second.id)
	assert.equal(placeNode(page, { kind: 'node', id: outer.id }, { kind: 'items', parentId: nested.zones.columns[0].id, index: 0 }), null)
})

test('reordering in one list uses the original insertion position', () => {
	const row = createItem('core.row')
	const first = createItem('core.heading')
	const second = createItem('core.hr')
	const third = createItem('core.button')
	row.zones.columns[0].items = [first, second, third]
	const page = { ...emptyPage(), items: [row] }
	const next = placeNode(page, { kind: 'node', id: first.id }, { kind: 'items', parentId: row.zones.columns[0].id, index: 3 })
	assert.deepEqual(next.items[0].zones.columns[0].items.map(item => item.id), [second.id, third.id, first.id])
})

test('the last column cannot be moved out of its row', () => {
	const first = createItem('core.row')
	const second = createItem('core.row')
	const page = { ...emptyPage(), items: [first, second] }
	assert.equal(placeNode(page, { kind: 'node', id: first.zones.columns[0].id }, { kind: 'columns', parentId: second.id, index: 1 }), null)
})

test('tree depth and node limits match the server contract', () => {
	const page = emptyPage()
	let items = page.items
	let deepestColumnId = null
	for (let depth = 0; depth < 12; depth++) {
		const row = createItem('core.row')
		items.push(row)
		deepestColumnId = row.zones.columns[0].id
		items = row.zones.columns[0].items
	}
	assert.equal(placeNode(page, { kind: 'catalog', type: 'core.row' }, { kind: 'items', parentId: page.items[0].zones.columns[0].id, index: 1 }) !== null, true)
	assert.equal(placeNode(page, { kind: 'catalog', type: 'core.row' }, { kind: 'items', parentId: deepestColumnId, index: 0 }), null)

	const full = { ...emptyPage(), items: Array.from({ length: 250 }, () => createItem('core.row')) }
	assert.equal(placeNode(full, { kind: 'catalog', type: 'core.row' }, { kind: 'items', parentId: 'page', index: 250 }), null)
})
