// Le catalogue est fourni par PHP ; aucun type métier n'est défini une seconde fois ici.
let definitions = []
export function setPageComponentDefinitions(value) { definitions = Array.isArray(value) ? value : [] }
const catalogueFor = components => components?.length ? components : definitions
const definitionFor = (type, components) => catalogueFor(components).find(entry => entry.id === type)
export const MAX_ZONE_BODIES = 100
const copy = value => JSON.parse(JSON.stringify(value))
let fallbackId = 0
const newId = () => {
    if (globalThis.crypto?.getRandomValues) {
        const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16))
        return `n_${Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('')}`
    }
    return `n_${Date.now().toString(36)}_${(fallbackId++).toString(36)}_${Math.random().toString(36).slice(2)}`
}

export const nodeLabel = (node, components = [], kind = '') => !node.type
	? (kind === 'column' ? 'Colonne' : kind === 'panel' ? 'Panneau' : 'Section')
	: definitionFor(node.type, components)?.label ?? node.type

export function createZone(defaults = {}) {
	return { id: newId(), label: '', icon: '', sizes: {}, props: {}, items: [], ...copy(defaults) }
}
export function createColumn(sizes = { xs: 12 }) { return createZone({ sizes }) }
export function createTab(label = 'Onglet') { return createZone({ label }) }

export function createItem(type, components = []) {
	const definition = definitionFor(type, components)
	if (!definition) throw new Error(`Composant indisponible : ${type}`)
	const node = { id: newId(), type, props: copy(definition.defaults ?? {}), zones: {} }
	for (const [name, zone] of Object.entries(definition.zones ?? {})) {
		node.zones[name] = Array.from({ length: zone.initial ?? zone.min ?? 0 }, () => createZone(zone.defaults))
	}
	return node
}

export function childZones(node) { return Object.values(node.zones ?? {}).flat() }

export function findNode(document, id) {
	if (!document || !id || id === 'page') return null
	function visit(items, parentId) {
		for (let index = 0; index < items.length; index++) {
			const item = items[index]
			if (item.id === id) return { node: item, kind: 'item', siblings: items, index, parentId }
			for (const [zoneName, zones] of Object.entries(item.zones ?? {})) {
				for (let zoneIndex = 0; zoneIndex < zones.length; zoneIndex++) {
					const zone = zones[zoneIndex]
					if (zone.id === id) return { node: zone, kind: definitionFor(item.type)?.zones?.[zoneName]?.kind ?? 'zone',
						zoneName, siblings: zones, index: zoneIndex, parentId: item.id }
					const nested = visit(zone.items, zone.id)
					if (nested) return nested
				}
			}
		}
		return null
	}
	return visit(document.items, 'page')
}

export function collectNodes(document) {
	const result = []
	function visit(items, depth = 0) {
		for (const item of items) {
			result.push({ node: item, depth })
			for (const zone of childZones(item)) {
				result.push({ node: zone, depth: depth + 1 })
				visit(zone.items, depth + 2)
			}
		}
	}
	if (document) visit(document.items)
	return result
}

export function zoneDefinition(document, found, components = []) {
	if (!found || found.kind === 'item') return null
	const parent = findNode(document, found.parentId)
	return definitionFor(parent?.node.type, components)?.zones?.[found.zoneName] ?? null
}

export function canDuplicateNode(document, id) {
	const found = findNode(document, id)
	if (!found || (found.kind !== 'item' && found.siblings.length >= (zoneDefinition(document, found)?.max ?? MAX_ZONE_BODIES))) return false
	const size = found.kind === 'item' ? collectNodes({ items: [found.node] }).length : 1 + collectNodes({ items: found.node.items }).length
	return collectNodes(document).length + size <= 500
}

export function cloneNodeWithNewIds(node) {
	const duplicated = copy(node)
	const ids = new Map()
	function renew(node) {
		ids.set(node.id, node.id = newId())
		for (const zone of childZones(node)) renew(zone)
		for (const child of node.items ?? []) renew(child)
	}
	renew(duplicated)
	function remap(node) {
		for (const [field, cardinality] of Object.entries(definitionFor(node.type)?.nodeReferenceFields ?? {})) {
			if (!Object.hasOwn(node.props ?? {}, field)) continue
			if (cardinality === 'many') node.props[field] = node.props[field].map(id => ids.get(id) ?? id)
			else node.props[field] = ids.get(node.props[field]) ?? node.props[field]
		}
		for (const zone of childZones(node)) remap(zone)
		for (const child of node.items ?? []) remap(child)
	}
	remap(duplicated)
	return duplicated
}

// Renouveler ou retirer les références internes déclarées par n'importe quel plugin.
export function replaceNodeReference(node, previousId, nextId = null) {
    for (const [field, cardinality] of Object.entries(definitionFor(node.type)?.nodeReferenceFields ?? {})) {
        if (cardinality === 'many') node.props[field] = (node.props[field] ?? []).flatMap(id => id !== previousId ? [id] : nextId ? [nextId] : [])
        else if (node.props[field] === previousId) {
            if (nextId) node.props[field] = nextId
            else delete node.props[field]
        }
    }
}

export function duplicateNode(document, id) {
	if (!canDuplicateNode(document, id)) return null
	const next = copy(document)
	const found = findNode(next, id)
	const duplicate = cloneNodeWithNewIds(found.node)
	found.siblings.splice(found.index + 1, 0, duplicate)
	return validTree(next) ? { document: next, nodeId: duplicate.id } : null
}

function targetList(document, target) {
	if (target.kind === 'items' && target.parentId === 'page') return document.items
	const parent = findNode(document, target.parentId)
	if (target.kind === 'items' && parent && parent.kind !== 'item') return parent.node.items
	if (target.kind === 'zones' && parent?.kind === 'item') return parent.node.zones?.[target.zoneName] ?? null
	// Les commandes propres aux composants peuvent nommer directement leur zone.
	if (parent?.kind === 'item') return parent.node.zones?.[target.kind] ?? null
	return null
}

export function validTree(document, components = []) {
	let count = 0
	const ids = new Set()
	const checkId = node => node && typeof node.id === 'string' && /^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/.test(node.id)
		&& !ids.has(node.id) && (ids.add(node.id), ++count <= 500)
	function visit(items, depth, root) {
		if (!Array.isArray(items) || depth > 12) return false
		for (const item of items) {
			if (!checkId(item) || typeof item.type !== 'string' || (root && item.type !== 'core.row')
				|| !item.zones || Array.isArray(item.zones)) return false
			const definition = definitionFor(item.type, components)
			if (Object.keys(item.zones).length > 12) return false
			for (const [name, constraint] of Object.entries(definition?.zones ?? {})) {
				if ((item.zones[name]?.length ?? 0) < (constraint.min ?? 0)) return false
			}
			for (const [name, zones] of Object.entries(item.zones)) {
				const constraint = definition?.zones?.[name]
				if ((definition && !constraint) || !Array.isArray(zones) || zones.length > (constraint?.max ?? MAX_ZONE_BODIES)) return false
				for (const zone of zones) {
					if (!checkId(zone) || !visit(zone.items, depth + 1, false)) return false
				}
			}
            for (const [field, flag] of Object.entries(definition?.exclusiveReferenceFields ?? {})) {
                if (item.props?.[flag] !== false && (item.props?.[field]?.length ?? 0) > 1) return false
            }
			for (const [field, cardinality] of Object.entries(definition?.nodeReferenceFields ?? {})) {
				const value = item.props?.[field]
				const references = cardinality === 'many' ? value ?? [] : value ? [value] : []
				if (!Array.isArray(references) || new Set(references).size !== references.length
					|| references.some(id => !childZones(item).some(zone => zone.id === id))) return false
			}
		}
		return true
	}
	return visit(document?.items, 0, true)
}

export function placeNode(document, payload, target, components = []) {
	if (!document || !payload || !target) return null
	const next = copy(document)
	let node, source = null
	if (payload.kind === 'catalog') {
		if (!definitionFor(payload.type, components)) return null
		node = createItem(payload.type, components)
	} else if (payload.kind === 'node') {
		source = findNode(next, payload.id)
		if (!source) return null
		node = source.node
		source.siblings.splice(source.index, 1)
	} else return null
	const destination = targetList(next, target)
	if (!destination || !Number.isInteger(target.index) || target.index < 0
		|| target.index > destination.length + (source?.siblings === destination ? 1 : 0)) return null
	if (source && source.kind !== 'item') {
		if (target.kind === 'items') return null
		const name = target.zoneName ?? target.kind
		if (name !== source.zoneName) return null
		// Seules les colonnes peuvent changer de conteneur.
		if (source.kind !== 'column' && target.parentId !== source.parentId) return null
	} else if (target.kind !== 'items') return null
	let index = target.index
	if (source?.siblings === destination && source.index < index) index--
	if (index < 0 || index > destination.length) return null
	if (target.kind === 'items' && target.parentId === 'page' && node.type !== 'core.row') {
		const row = createItem('core.row', components)
		childZones(row)[0].items.push(node)
		destination.splice(index, 0, row)
	} else destination.splice(index, 0, node)
	return validTree(next, components) ? next : null
}

export function nearestInsertTarget(document, selectedId, type) {
	const selected = findNode(document, selectedId)
	if (selected && selected.kind !== 'item') return { kind: 'items', parentId: selectedId, index: selected.node.items.length }
	if (type === 'core.row' && selected?.node.type === 'core.row') return { kind: 'items', parentId: selected.parentId, index: selected.index + 1 }
	const zone = selected && childZones(selected.node)[0]
	if (zone) return { kind: 'items', parentId: zone.id, index: zone.items.length }
	if (selected?.kind === 'item' && selected.parentId !== 'page') return { kind: 'items', parentId: selected.parentId, index: selected.index + 1 }
	return { kind: 'items', parentId: 'page', index: document.items.length }
}
