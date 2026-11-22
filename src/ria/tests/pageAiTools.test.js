import { pageCatalogue } from './fixtures/pageCatalogue.js'
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createItem, findNode } from '../src/components/administrator/pages/pageEditorModel.js'
import { createPageAiTools } from '../src/components/administrator/pages/ai/pageAiTools.js'

function fixture(components = []) {
	const row = createItem('core.row')
	const current = { id: 7, space_id: 3, type: 2, canEdit: true,
		data: { version: 3, container: { fluid: false }, items: [row] } }
	const store = {
		current, components: [...pageCatalogue, ...components], catalogue: [...pageCatalogue, ...components],
		editorSession: 1, selectedId: null, saving: false, validated: 0, sanitized: 0,
		async validateAiDraft() { this.validated++ },
		async sanitizeAiHtml(html) { this.sanitized++; return html.replace(/<script[^>]*>.*?<\/script>/gis, '') },
		applyAiDraft(document, selectedId) { this.current.data = document; this.selectedId = selectedId; return true },
	}
	return { store, row, tools: createPageAiTools({ store }) }
}

const tool = (tools, name) => tools.find(item => item.name === name)

test('a proposed component is validated and remains unsaved until accepted', async () => {
	const { store, row, tools } = fixture()
	tool(tools, 'get_page_outline').execute({})
	const add = tool(tools, 'add_component')
	const args = { type: 'core.heading', parent_id: row.zones.columns[0].id, index: 0, fields: { text: 'Bienvenue' } }
	const preview = await add.preview(args)
	assert.equal(preview.format, 'structure')
	assert.equal(store.validated, 1)
	assert.equal(store.current.data.items[0].zones.columns[0].items.length, 0)
	const result = add.execute(args)
	assert.equal(result.applied, true)
	assert.equal(findNode(store.current.data, result.id).node.props.text, 'Bienvenue')
	assert.equal(store.selectedId, result.id)
})

test('adding a component at the root creates a row and selects the inserted component', async () => {
	const { store, tools } = fixture()
	tool(tools, 'get_page_outline').execute({})
	const add = tool(tools, 'add_component')
	const args = { type: 'core.heading', parent_id: 'page', index: 1, fields: { text: 'Suite' } }
	await add.preview(args)
	const { id } = add.execute(args)
	assert.equal(store.current.data.items.length, 2)
	assert.equal(store.current.data.items[1].type, 'core.row')
	assert.equal(findNode(store.current.data, id).node.props.text, 'Suite')
	assert.equal(store.selectedId, id)
})

test('extension fields use the catalogue and still pass through server validation', async () => {
	const extension = { id: 'lms.course-list', label: 'Cours', editorFields: [
		{ name: 'category_id', type: 'number' }, { name: 'secret', type: 'text', readonly: true },
	] }
	const { store, row, tools } = fixture([extension])
	tool(tools, 'get_page_outline').execute({})
	const add = tool(tools, 'add_component')
	const target = { type: extension.id, parent_id: row.zones.columns[0].id, index: 0 }
	await assert.rejects(add.preview({ ...target, fields: { secret: 'bad' } }), /non modifiable/)
	const preview = await add.preview({ ...target, fields: { category_id: 42 } })
	assert.match(preview.after, /category_id/)
	const result = add.execute({ ...target, fields: { category_id: 42 } })
	assert.equal(findNode(store.current.data, result.id).node.props.category_id, 42)
	assert.equal(store.validated, 1)
})

test('an extension field declared as HTML is cleaned before entering the draft', async () => {
	const extension = { id: 'lms.description', label: 'Description', editorFields: [
		{ name: 'content', type: 'html' },
	] }
	const { store, row, tools } = fixture([extension])
	tool(tools, 'get_page_outline').execute({})
	const add = tool(tools, 'add_component')
	const args = { type: extension.id, parent_id: row.zones.columns[0].id, index: 0,
		fields: { content: '<p>Cours</p><script>alert(1)</script>' } }
	const preview = await add.preview(args)
	assert.doesNotMatch(preview.after, /<script>/)
	assert.equal(store.sanitized, 1)
	const { id } = add.execute(args)
	assert.equal(findNode(store.current.data, id).node.props.content, '<p>Cours</p>')
})

test('model HTML is cleaned before preview and application', async () => {
	const { store, row, tools } = fixture()
	tool(tools, 'get_page_outline').execute({})
	const add = tool(tools, 'add_component')
	const target = { type: 'core.html', parent_id: row.zones.columns[0].id, index: 0 }
	await add.preview(target)
	const { id } = add.execute(target)
	const update = tool(tools, 'update_component')
	const args = { id, fields: { content: '<p>Bonjour</p><script>alert(1)</script>' } }
	const preview = await update.preview(args)
	assert.equal(store.sanitized, 1)
	assert.doesNotMatch(preview.after, /<script>/)
	assert.equal(findNode(store.current.data, id).node.props.content, '')
	update.execute(args)
	assert.equal(findNode(store.current.data, id).node.props.content, '<p>Bonjour</p>')
})

test('a concurrent editor change invalidates a pending proposal', async () => {
	const { store, row, tools } = fixture()
	tool(tools, 'get_page_outline').execute({})
	const add = tool(tools, 'add_component')
	const args = { type: 'core.heading', parent_id: row.zones.columns[0].id, index: 0 }
	await add.preview(args)
	store.current.data.container.fluid = true
	assert.throws(() => add.execute(args), /changé/)
	assert.equal(store.current.data.items[0].zones.columns[0].items.length, 0)
})

test('a long HTML field can be read in segments and changed by an exact fragment', async () => {
	const { store, row, tools } = fixture()
	const html = createItem('core.html')
	html.props.content = `<p>${'A'.repeat(13_000)} unique phrase</p>`
	row.zones.columns[0].items.push(html)
	const segment = tool(tools, 'get_component_content').execute({ id: html.id, offset: 12_995, length: 100 })
	assert.match(segment.content, /unique phrase/)
	assert.equal(segment.totalLength, html.props.content.length)
	const replace = tool(tools, 'replace_content_fragment')
	const args = { id: html.id, old_text: 'unique phrase', new_text: 'nouvelle phrase<script>alert(1)</script>' }
	const preview = await replace.preview(args)
	assert.doesNotMatch(preview.after, /<script>/)
	assert.match(findNode(store.current.data, html.id).node.props.content, /unique phrase/)
	replace.execute(args)
	assert.match(findNode(store.current.data, html.id).node.props.content, /nouvelle phrase/)
	assert.doesNotMatch(findNode(store.current.data, html.id).node.props.content, /<script>/)
})

test('common style can be proposed for a column without changing its content', async () => {
	const { store, row, tools } = fixture()
	tool(tools, 'get_page_outline').execute({})
	const update = tool(tools, 'update_common_style')
	const args = { id: row.zones.columns[0].id, values: { background_color: '#abcdef' } }
	await update.preview(args)
	assert.equal(store.current.data.items[0].zones.columns[0].props.common, undefined)
	update.execute(args)
	assert.equal(store.current.data.items[0].zones.columns[0].props.common.style.background_color, '#abcdef')
})

test('AI can traverse accordion panels and update true/false options without replacing them with text', async () => {
	const { store, row, tools } = fixture([{ id: 'core.accordion', label: 'Accordéon' }, { id: 'core.image', label: 'Image' }])
	const accordion = createItem('core.accordion')
	accordion.props.single_open = false
	accordion.props.open_panels = accordion.zones.panels.map(panel => panel.id)
	const image = createItem('core.image')
	accordion.zones.panels[0].items.push(image)
	row.zones.columns[0].items.push(accordion)
	const outline = tool(tools, 'get_page_outline').execute({})
	assert.ok(outline.nodes.some(node => node.id === image.id && node.parentId === accordion.zones.panels[0].id))
	const update = tool(tools, 'update_component')
	const imageArgs = { id: image.id, fields: { click_action: 'enlarge' } }
	await assert.rejects(update.preview({ id: image.id, fields: { click_action: true } }), /Valeur invalide/)
	await update.preview(imageArgs)
	update.execute(imageArgs)
	assert.equal(findNode(store.current.data, image.id).node.props.click_action, 'enlarge')
	tool(tools, 'get_page_outline').execute({})
	const accordionArgs = { id: accordion.id, fields: { single_open: true } }
	await update.preview(accordionArgs)
	update.execute(accordionArgs)
	assert.deepEqual(findNode(store.current.data, accordion.id).node.props.open_panels, [accordion.zones.panels[0].id])
})
