import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPinia, setActivePinia } from 'pinia'
import { pageCatalogue } from './fixtures/pageCatalogue.js'
import { createItem, findNode } from '../src/components/administrator/pages/pageEditorModel.js'
import { useAdminPagesStore } from '../src/stores/admin_pages.js'
import { diagramImageUrl, diagramSvgFromDataUrl } from '../src/components/administrator/pages/pageDiagram.js'
import { diagramPresetColors } from '../src/components/common/diagramPalette.js'
import { pageJsonBytes, pageMaxSize } from '../src/components/administrator/pages/pageSize.js'
import { parsePageNode, serializePageNode } from '../src/components/administrator/pages/pageClipboard.js'

const svg = '<svg xmlns="http://www.w3.org/2000/svg"><text>Été 🌻</text></svg>'

test('le décodage préserve les octets UTF-8 et refuse les exports non SVG ou trop lourds', () => {
	const bytes = new TextEncoder().encode(svg).length
	assert.equal(diagramSvgFromDataUrl(diagramImageUrl(svg), bytes), svg)
	assert.equal(diagramSvgFromDataUrl(`data:image/svg+xml;base64,${Buffer.from(svg).toString('base64')}`, bytes), svg)
	assert.throws(() => diagramSvgFromDataUrl(diagramImageUrl(svg), bytes - 1), /poids/)
	assert.throws(() => diagramSvgFromDataUrl('data:image/png;base64,AAAA', 250_000), /invalide/)
	assert.throws(() => diagramSvgFromDataUrl('data:image/svg+xml;base64,/', 250_000))
	assert.ok(!diagramImageUrl(svg).includes('<svg'))
})

test('la jauge reprend les octets persistés, les slash et séparateurs Unicode échappés', () => {
	assert.equal(pageMaxSize(), 1_000_000)
	assert.equal(pageMaxSize({ plg_core_page_max_size: 9_000_000 }), 5_000_000)
	assert.equal(pageMaxSize({ plg_core_page_max_size: -1 }), 1_000_000)
	const document = { svg: 'été / 🌻', separator: '\u2028' }
	const stored = '{"svg":"été \\/ 🌻","separator":"\\u2028"}'
	assert.equal(pageJsonBytes(document), Buffer.byteLength(stored))
})

test('les couleurs de template sont dédupliquées et converties au format diagrams.net', () => {
	assert.deepEqual(diagramPresetColors([
		{ color: '#2563eb' }, { color: '#2563EBFF' }, { color: '#16A34A' },
		{ color: '#16A34A80' }, { color: 'var(--bs-primary)' }, { color: '#fff;bad' }, null,
	]), ['2563EB', '16A34A'])
})

function fixture(post = async (url, { data }) => ({ data: data.data })) {
	setActivePinia(createPinia())
	const store = useAdminPagesStore.get(null, { api: { post } })()
	const row = createItem('core.row')
	const diagram = createItem('core.diagram')
	row.zones.columns[0].items.push(diagram)
	store.current = { id: 1, space_id: 7, type: 2, canEdit: true,
		data: { version: 3, container: { fluid: false }, items: [row] } }
	return { store, diagram }
}

test('appliquer un diagramme valide le document sur le serveur sans enregistrer la page', async () => {
	const { store, diagram } = fixture(async (url, { data }) => {
		assert.equal(url, '/administrator/api/pages/validate-draft')
		assert.equal(data.space_id, 7)
		assert.equal(findNode(data.data, diagram.id).node.props.svg, svg)
		findNode(data.data, diagram.id).node.props.svg = 'SVG validé'
		return { data: data.data }
	})
	assert.equal(await store.updateDiagram(diagram.id, svg), true)
	assert.equal(findNode(store.current.data, diagram.id).node.props.svg, 'SVG validé')
	assert.equal(store.isDirty, true)
})

test('un refus serveur ou une modification concurrente préserve le brouillon', async () => {
	for (const change of [
		store => { store.editorSession++ },
		store => { store.current.canEdit = false },
		store => { store.current.name = 'Changé' },
		store => { store.saving = true },
		store => { store.current = JSON.parse(JSON.stringify(store.current)) },
	]) {
		const { store, diagram } = fixture(async (url, { data }) => {
			change(store)
			return { data: data.data }
		})
		assert.equal(await store.updateDiagram(diagram.id, svg), false)
		assert.equal(findNode(store.current.data, diagram.id).node.props.svg, '')
	}
	const { store, diagram } = fixture(async () => { throw new Error('PNG interdit') })
	await assert.rejects(store.updateDiagram(diagram.id, svg), /PNG interdit/)
	assert.equal(diagram.props.svg, '')
})

test('la copie d’un grand groupe reste possible au-delà de 1 Mo et est bornée à 5 Mo', () => {
	const { store, diagram } = fixture()
	diagram.props.svg = 'x'.repeat(1_100_000)
	assert.equal(parsePageNode(serializePageNode(store.current.data, diagram.id)).node.props.svg.length, 1_100_000)
	diagram.props.svg = 'x'.repeat(5_000_000)
	assert.throws(() => serializePageNode(store.current.data, diagram.id), /volumineux/)
	assert.throws(() => parsePageNode('x'.repeat(5_000_001)), /volumineux/)
})
