import { MAX_ZONE_BODIES, collectNodes, childZones, zoneDefinition, replaceNodeReference, createColumn, findNode, placeNode } from '../pageEditorModel.js'

const copy = value => JSON.parse(JSON.stringify(value))
const EDITABLE_ID = /^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/
const BREAKPOINTS = ['xs', 'sm', 'md', 'lg', 'xl', 'xxl']
const STYLE_FIELDS = new Set([
	'border_style', 'border_color', 'border_radius', 'color', 'link_color', 'background_color', 'background_image',
	'background_position', 'background_size', 'background_image_opacity', 'background_image_tint', 'text_align', 'position',
	...['margin', 'padding', 'border', 'offset'].flatMap(prefix =>
		['top', 'right', 'bottom', 'left'].map(side => `${prefix}_${side}`)),
])
function fieldsFor(type, catalogue) {
    const definition = catalogue.find(entry => entry.id === type)
    if (!definition) return null
    const fields = {}
    for (const [name, schema] of Object.entries(definition.propsSchema?.properties ?? {})) {
        if (!EDITABLE_ID.test(name)) continue
        const valueType = schema.type
        if (['string', 'boolean', 'number', 'integer'].includes(valueType)) fields[name] = valueType === 'integer' ? 'number' : valueType
    }
    for (const field of definition.editorFields ?? []) {
        if (!EDITABLE_ID.test(field.name) || field.readonly || field.disabled) continue

        fields[field.name] = ['number', 'core.number'].includes(field.type) ? 'number'
            : ['html', 'rich'].includes(field.type) ? 'html' : ['boolean', 'core.switch'].includes(field.type) ? 'boolean' : 'string'
    }
    return fields
}

function outline(document, offset, limit, catalogue) {
	const nodes = []
	const visit = (items, parentId) => {
		for (let index = 0; index < items.length; index++) {
			const item = items[index]
			nodes.push({ id: item.id, type: item.type,
				label: catalogue.find(type => type.id === item.type)?.label ?? item.type, parentId, index })
            for (const [name, zones] of Object.entries(item.zones)) {
                for (let index = 0; index < zones.length; index++) {
                    const zone = zones[index]
                    nodes.push({ id: zone.id, type: 'zone', zoneName: name, label: zone.label, parentId: item.id, index, sizes: zone.sizes })
                    visit(zone.items, zone.id)
                }
            }
		}
	}
	visit(document.items, 'page')
	return { total: nodes.length, nodes: nodes.slice(offset, offset + limit),
		nextOffset: offset + limit < nodes.length ? offset + limit : null }
}

function requiredString(value, label) {
	if (typeof value !== 'string' || !value.trim()) throw new Error(`${label} invalide.`)
	return value
}

function requiredIndex(value) {
	if (!Number.isInteger(value) || value < 0) throw new Error('Position invalide.')
	return value
}

function sizesFrom(value) {
	if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('Largeurs de colonne invalides.')
	const sizes = {}
	for (const [key, size] of Object.entries(value)) {
		if (!BREAKPOINTS.includes(key) || !Number.isInteger(size) || size < 1 || size > 12) {
			throw new Error('Largeur de colonne invalide.')
		}
		sizes[key] = size
	}
	if (!Object.keys(sizes).length) throw new Error('Au moins une largeur est requise.')
	return sizes
}

function targetFor(document, parentId, index, isColumn = false) {
	requiredIndex(index)
	if (isColumn) {
		const parent = findNode(document, requiredString(parentId, 'Ligne cible'))
		if (parent?.node.type !== 'core.row') throw new Error('Une colonne doit être placée dans une ligne.')
		return { kind: 'columns', parentId, index }
	}
	if (parentId === 'page') return { kind: 'items', parentId, index }
	const parent = findNode(document, requiredString(parentId, 'Colonne cible'))
	if (!parent || parent.kind === 'item') throw new Error('Un composant doit être placé dans une colonne, une section ou à la racine.')
	return { kind: 'items', parentId, index }
}

function editableFields(fields, type, catalogue) {
	if (!fields || typeof fields !== 'object' || Array.isArray(fields)) throw new Error('Champs de composant invalides.')
	const allowed = fieldsFor(type, catalogue)
	if (!allowed) throw new Error('Type de composant indisponible.')
	const entries = Object.entries(fields)
	if (!entries.length) throw new Error('Aucun champ à modifier.')
	for (const [name, value] of entries) {
		if (!Object.hasOwn(allowed, name)) throw new Error(`Champ non modifiable : ${name}.`)
		if (value !== null && (allowed[name] === 'number' ? !Number.isFinite(value) : typeof value !== allowed[name].replace('html', 'string'))) {
			throw new Error(`Valeur invalide pour ${name}.`)
		}
		if (typeof value === 'string' && value.length > 500_000) throw new Error(`Valeur trop longue pour ${name}.`)
	}
	return entries
}

function review(before, after, summary, details = []) {
	return { before: JSON.stringify(before, null, 2), after: JSON.stringify(after, null, 2),
		format: 'structure', summary, details }
}

/** Page-specific Tools. The chat transports schemas; all state changes remain in the editor. */
export function createPageAiTools({ store }) {
	const session = store.editorSession
	let readSnapshot = null
	let pending = null
	const current = () => {
		if (store.editorSession !== session || !store.current || store.current.type !== 2) {
			throw new Error('La page active a changé. Ouvrez une nouvelle conversation IA.')
		}
		return store.current
	}
	const snapshot = () => JSON.stringify(current().data)
	const markRead = () => { readSnapshot = snapshot() }
	const writable = () => {
		const page = current()
		if (!page.canEdit) throw new Error('Le brouillon est en lecture seule.')
		if (readSnapshot === null) throw new Error('Lisez la structure ou le composant avant de le modifier.')
		if (readSnapshot !== snapshot()) throw new Error('Le brouillon a changé depuis sa lecture. Relisez-le.')
		return page
	}
	const checkPending = args => {
		writable()
		if (!pending || pending.signature !== JSON.stringify(args) || pending.before !== snapshot()) {
			throw new Error('La proposition a changé. Relancez la demande.')
		}
		return pending
	}
	const propose = (name, build) => ({
		name,
		mode: 'review',
		preview: async args => {
			const page = writable()
			const before = snapshot()
			const document = copy(page.data)
			const result = await build(document, args)
			if (before !== snapshot()) throw new Error('Le brouillon a changé pendant la préparation. Relisez-le.')
			if (JSON.stringify(document) === before) throw new Error('La proposition ne change pas la page.')
			await store.validateAiDraft(document)
			if (before !== snapshot()) throw new Error('Le brouillon a changé pendant la validation. Relisez-le.')
			pending = { signature: JSON.stringify(args), before, document, selectedId: result.selectedId,
				result: result.result }
			return review(result.before, result.after, result.summary, result.details)
		},
		execute: args => {
			const proposal = checkPending(args)
			if (!store.applyAiDraft(proposal.document, proposal.selectedId)) throw new Error('Application de la proposition impossible.')
			readSnapshot = snapshot()
			pending = null
			return { applied: true, ...proposal.result }
		},
	})

	return [
		{
			name: 'get_page_outline', description: 'Lire le plan paginé de la page, ses ID de nœuds, types, parents et positions avant toute modification.',
			parameters: { type: 'object', properties: {
				offset: { type: 'integer', minimum: 0 }, limit: { type: 'integer', minimum: 1, maximum: 100 },
			}, additionalProperties: false },
			execute: args => {
				const page = current()
				const offset = args.offset ?? 0, limit = args.limit ?? 80
				if (!Number.isInteger(offset) || offset < 0 || !Number.isInteger(limit) || limit < 1 || limit > 100) {
					throw new Error('Pagination invalide.')
				}
				markRead()
				return { pageId: page.id, name: page.name, selectedId: store.selectedId,
					...outline(page.data, offset, limit, store.catalogue) }
			},
		},
		{
			name: 'get_component_types', description: 'Lister les types de composants disponibles dans le catalogue, y compris les extensions.',
			parameters: { type: 'object', properties: {}, additionalProperties: false },
			execute: () => ({ types: store.catalogue.map(type => ({ id: type.id, label: type.label,
				fields: Object.keys(fieldsFor(type.id, store.catalogue) ?? {}) })) }),
		},
		{
			name: 'get_component_type', description: 'Lire les champs éditables déclarés pour un type du catalogue. Le serveur contrôle les valeurs proposées.',
			parameters: { type: 'object', properties: { type: { type: 'string' } }, required: ['type'], additionalProperties: false },
			execute: args => {
				const type = store.catalogue.find(item => item.id === args.type)
				if (!type) throw new Error('Type de composant indisponible.')
				return { id: type.id, label: type.label, fields: fieldsFor(type.id, store.catalogue),
					editorFields: type.editorFields ?? [] }
			},
		},
		{
			name: 'get_component', description: 'Lire un composant ou une colonne de la page par son ID, avec ses paramètres actuels.',
			parameters: { type: 'object', properties: { id: { type: 'string' } }, required: ['id'], additionalProperties: false },
			execute: args => {
				const found = findNode(current().data, requiredString(args.id, 'Composant'))
				if (!found) throw new Error('Composant introuvable.')
				markRead()
				const node = copy(found.node)
                for (const [name, zones] of Object.entries(node.zones ?? {})) {
                    node.zones[name] = zones.map(zone => ({ ...zone, items: zone.items.map(item => ({ id: item.id, type: item.type })) }))
                }
                if (found.kind !== 'item') node.items = node.items.map(item => ({ id: item.id, type: item.type }))
				for (const [field, value] of Object.entries(node.props ?? {})) {
					if (typeof value === 'string' && value.length > 12_000) node.props[field] = `${value.slice(0, 12_000)}… [tronqué, ${value.length} caractères]`
				}
				return { kind: found.kind, parentId: found.parentId, index: found.index, node }
			},
		},
		{
			name: 'get_component_content', description: 'Lire un segment de HTML lorsque le contenu d’un composant est trop long pour get_component.',
			parameters: { type: 'object', properties: { id: { type: 'string' }, offset: { type: 'integer', minimum: 0 },
				length: { type: 'integer', minimum: 1, maximum: 10_000 } }, required: ['id'], additionalProperties: false },
			execute: args => {
				const found = findNode(current().data, requiredString(args.id, 'Composant'))
				if (!['core.html', 'core.rich-text'].includes(found?.node.type)) throw new Error('Ce composant ne contient pas de HTML éditable.')
				const offset = args.offset ?? 0, length = args.length ?? 8_000
				if (!Number.isInteger(offset) || offset < 0 || !Number.isInteger(length) || length < 1 || length > 10_000) {
					throw new Error('Segment invalide.')
				}
				const content = String(found.node.props.content ?? '')
				markRead()
				return { id: args.id, offset, totalLength: content.length, content: content.slice(offset, offset + length) }
			},
		},
		{
			...propose('add_component', async (document, args) => {
				const type = requiredString(args.type, 'Type')
				if (!store.catalogue.some(entry => entry.id === type)) throw new Error('Type de composant indisponible.')
				const target = targetFor(document, args.parent_id, args.index)
				const beforeIds = new Set(collectNodes(document).map(entry => entry.node.id))
				const next = placeNode(document, { kind: 'catalog', type }, target, store.components)
				if (!next) throw new Error('Insertion impossible à cette position.')
				const inserted = collectNodes(next).find(entry => entry.node.type === type && !beforeIds.has(entry.node.id))
				if (!inserted) throw new Error('Nouveau composant introuvable.')
				const placed = findNode(next, inserted.node.id)
				const node = placed.node
				if (args.fields !== undefined) await applyFields(node, args.fields, type, store)
				Object.assign(document, next)
				return { before: { target, type }, after: { id: node.id, type, parentId: placed.parentId, props: node.props },
					summary: `Ajouter ${type}`, selectedId: node.id, result: { id: node.id } }
			}),
			description: 'Ajouter un composant du catalogue à la racine ou dans une colonne ou un onglet. Les champs initiaux déclarés sont facultatifs.',
			parameters: { type: 'object', properties: {
				type: { type: 'string' }, parent_id: { type: 'string' }, index: { type: 'integer', minimum: 0 },
				fields: { type: 'object' },
			}, required: ['type', 'parent_id', 'index'], additionalProperties: false },
		},
		{
			...propose('add_column', async (document, args) => {
				const row = findNode(document, requiredString(args.row_id, 'Ligne'))
				if (row?.node.type !== 'core.row' || row.node.zones.columns.length >= MAX_ZONE_BODIES) throw new Error('Ligne invalide ou déjà complète.')
				const index = requiredIndex(args.index)
				if (index > row.node.zones.columns.length) throw new Error('Position de colonne invalide.')
				const column = createColumn(args.sizes === undefined ? { xs: 12 } : sizesFrom(args.sizes))
				row.node.zones.columns.splice(index, 0, column)
				return { before: { rowId: row.node.id, columns: row.node.zones.columns.length - 1 },
					after: { id: column.id, rowId: row.node.id, index, sizes: column.sizes },
					summary: 'Ajouter une colonne', selectedId: column.id, result: { id: column.id } }
			}),
			description: 'Ajouter une colonne dans une ligne, jusqu’à 100 colonnes.',
			parameters: { type: 'object', properties: { row_id: { type: 'string' }, index: { type: 'integer', minimum: 0 },
				sizes: { type: 'object' } }, required: ['row_id', 'index'], additionalProperties: false },
		},
		{
			...propose('move_component', async (document, args) => {
				const source = findNode(document, requiredString(args.id, 'Composant'))
				if (!source) throw new Error('Composant introuvable.')
				const before = { id: args.id, parentId: source.parentId, index: source.index }
				const target = source.kind !== 'item'
					? { kind: 'zones', zoneName: source.zoneName, parentId: args.parent_id, index: requiredIndex(args.index) }
					: targetFor(document, args.parent_id, args.index, source.kind === 'column')
				const next = placeNode(document, { kind: 'node', id: args.id }, target, store.components)
				if (!next) throw new Error('Déplacement impossible.')
				Object.assign(document, next)
				const moved = findNode(document, args.id)
				return { before, after: { id: args.id, parentId: moved.parentId, index: moved.index },
					summary: 'Déplacer un élément', selectedId: args.id, result: { id: args.id } }
			}),
			description: 'Déplacer une ligne, une colonne ou un composant en conservant son ID et ses données.',
			parameters: { type: 'object', properties: { id: { type: 'string' }, parent_id: { type: 'string' },
				index: { type: 'integer', minimum: 0 } }, required: ['id', 'parent_id', 'index'], additionalProperties: false },
		},
		{
			...propose('update_component', async (document, args) => {
				const found = findNode(document, requiredString(args.id, 'Composant'))
				if (!found || ['column', 'tab', 'panel'].includes(found.kind) || found.node.type === 'core.row') throw new Error('Composant non éditable par ce Tool.')
				const before = copy(found.node.props)
				await applyFields(found.node, args.fields, found.node.type, store)
				if (found.node.type === 'core.accordion' && found.node.props.single_open !== false) found.node.props.open_panels = (found.node.props.open_panels ?? []).slice(0, 1)
				return { before, after: copy(found.node.props), summary: `Modifier ${found.node.type}`,
					selectedId: args.id, result: { id: args.id } }
			}),
			description: 'Modifier uniquement les champs déclarés d’un composant Core ou d’extension. Le HTML est nettoyé côté serveur.',
			parameters: { type: 'object', properties: { id: { type: 'string' }, fields: { type: 'object' } },
				required: ['id', 'fields'], additionalProperties: false },
		},
		{
			...propose('replace_content_fragment', async (document, args) => {
				const found = findNode(document, requiredString(args.id, 'Composant'))
				if (!['core.html', 'core.rich-text'].includes(found?.node.type)) throw new Error('Ce composant ne contient pas de HTML éditable.')
				const oldText = requiredString(args.old_text, 'Texte à remplacer')
				if (typeof args.new_text !== 'string') throw new Error('Nouveau texte invalide.')
				const before = String(found.node.props.content ?? '')
				const first = before.indexOf(oldText)
				if (first < 0 || before.indexOf(oldText, first + oldText.length) >= 0) {
					throw new Error('Le fragment doit apparaître une seule fois dans le contenu actuel.')
				}
				const proposed = before.slice(0, first) + args.new_text + before.slice(first + oldText.length)
				found.node.props.content = await store.sanitizeAiHtml(proposed)
				return { before: { content: before }, after: { content: found.node.props.content,
					cleanedHtml: found.node.props.content !== proposed }, summary: 'Remplacer un fragment de contenu HTML',
					selectedId: args.id, result: { id: args.id } }
			}),
			description: 'Remplacer une occurrence exacte et unique dans un long contenu HTML, puis nettoyer le résultat côté serveur.',
			parameters: { type: 'object', properties: { id: { type: 'string' }, old_text: { type: 'string' },
				new_text: { type: 'string' } }, required: ['id', 'old_text', 'new_text'], additionalProperties: false },
		},
		{
			...propose('update_common_style', async (document, args) => {
				const found = findNode(document, requiredString(args.id, 'Élément'))
				if (!found) throw new Error('Élément introuvable.')
				if (!args.values || typeof args.values !== 'object' || Array.isArray(args.values)
					|| Object.keys(args.values).length === 0) throw new Error('Styles invalides.')
				const common = found.node.props.common ??= {}
				const style = common.style ??= {}
				const before = copy(style)
				for (const [name, value] of Object.entries(args.values)) {
					if (!STYLE_FIELDS.has(name) || (value !== null && typeof value !== 'string')) {
						throw new Error(`Style non modifiable : ${name}.`)
					}
					if (value === null || value === '') delete style[name]
					else style[name] = value
				}
				return { before, after: copy(style), summary: 'Modifier le style commun',
					selectedId: args.id, result: { id: args.id } }
			}),
			description: 'Modifier les styles communs structurés d’une ligne, colonne ou composant. Le serveur valide les valeurs.',
			parameters: { type: 'object', properties: { id: { type: 'string' }, values: { type: 'object' } },
				required: ['id', 'values'], additionalProperties: false },
		},
		{
			...propose('update_column_sizes', async (document, args) => {
				const found = findNode(document, requiredString(args.id, 'Colonne'))
				if (found?.kind !== 'column') throw new Error('Colonne introuvable.')
				const before = copy(found.node.sizes)
				found.node.sizes = sizesFrom(args.sizes)
				return { before, after: found.node.sizes, summary: 'Modifier les largeurs de colonne',
					selectedId: args.id, result: { id: args.id } }
			}),
			description: 'Définir les largeurs responsive d’une colonne, de 1 à 12 pour chaque breakpoint fourni.',
			parameters: { type: 'object', properties: { id: { type: 'string' }, sizes: { type: 'object' } },
				required: ['id', 'sizes'], additionalProperties: false },
		},
		{
			...propose('remove_component', async (document, args) => {
				const found = findNode(document, requiredString(args.id, 'Composant'))
				if (!found) throw new Error('Composant introuvable.')
				if (found.kind !== 'item' && found.siblings.length <= (zoneDefinition(document, found)?.min ?? 0)) throw new Error('Le dernier corps ne peut pas être supprimé.')
				const before = { id: args.id, type: found.node.type ?? 'column', parentId: found.parentId,
					index: found.index, childCount: found.kind === 'column' ? found.node.items.length
						: found.node.type === 'core.row' ? found.node.zones.columns.length : 0 }
				found.siblings.splice(found.index, 1)
                if (found.kind !== 'item') replaceNodeReference(findNode(document, found.parentId).node, args.id)
				return { before, after: { removed: args.id }, summary: 'Supprimer un élément et son contenu',
					result: { id: args.id } }
			}),
			description: 'Supprimer un composant, une colonne ou une ligne, y compris ses descendants. Action soumise à confirmation.',
			parameters: { type: 'object', properties: { id: { type: 'string' } }, required: ['id'], additionalProperties: false },
		},
	]
}

async function applyFields(node, fields, type, store) {
	for (const [name, value] of editableFields(fields, type, store.catalogue)) {
		if (value === null) { delete node.props[name]; continue }
        if (node.props[name] && typeof node.props[name] === 'object') throw new Error('Ce champ nécessite une modification structurée ou par langue.')
		node.props[name] = fieldsFor(type, store.catalogue)[name] === 'html'
			? await store.sanitizeAiHtml(value) : value
	}
}
