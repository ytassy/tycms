import { MAX_ZONE_BODIES, childZones, cloneNodeWithNewIds, replaceNodeReference, findNode, validTree } from './pageEditorModel.js'

const format = 'tycms.page-node'
// La validation du document collé applique ensuite la limite configurée de la page.
const maxLength = 5_000_000
const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value)

export function serializePageNode(document, id) {
	const found = findNode(document, id)
	if (!found) throw new Error('Élément à copier introuvable.')
	const text = JSON.stringify({ format, version: 2, kind: found.kind, zoneName: found.zoneName, node: found.node })
	if (new TextEncoder().encode(text).length > maxLength) throw new Error('Cet élément est trop volumineux pour être copié.')
	return text
}

export function parsePageNode(text) {
	if (typeof text !== 'string' || new TextEncoder().encode(text).length > maxLength) {
		throw new Error('Le contenu du presse-papiers est trop volumineux.')
	}
	try {
		// Le presse-papiers reste une entrée externe, même s’il provient de cet éditeur.
		const payload = JSON.parse(text, (key, value) => {
			if (['__proto__', 'constructor', 'prototype'].includes(key)) throw new Error()
			return value
		})
		if (payload?.format !== format || payload.version !== 2 || !isObject(payload.node)
			|| !['item', 'column', 'tab', 'panel', 'zone'].includes(payload.kind)) throw new Error()
		const { kind, node } = payload
		if (kind === 'column' && !isObject(node.sizes)) throw new Error()
		let count = 0
		const ids = new Set()
		function inspect(node, zone, depth) {
			if (!isObject(node) || !isObject(node.props) || typeof node.id !== 'string'
				|| ids.has(node.id) || ++count > 500 || depth > 26) throw new Error()
			ids.add(node.id)
			if (zone) {
				if (node.type !== undefined || !Array.isArray(node.items)) throw new Error()
				for (const item of node.items) inspect(item, false, depth + 1)
			} else {
				if (typeof node.type !== 'string' || node.items !== undefined) throw new Error()
				const zones = childZones(node)
				if (!Array.isArray(zones) || Object.values(node.zones ?? {}).some(bodies => !Array.isArray(bodies) || bodies.length > MAX_ZONE_BODIES)
					|| !isObject(node.zones)) throw new Error()
				for (const child of zones) inspect(child, true, depth + 1)
			}
		}
		inspect(node, kind !== 'item', 0)
		return payload
	} catch {
		throw new Error('Le presse-papiers ne contient pas un élément de page valide.')
	}
}

export function pastePageNode(document, targetId, payload, mode = 'replace') {
	if (!['replace', 'before', 'after'].includes(mode)) return null
	const next = JSON.parse(JSON.stringify(document))
	const target = findNode(next, targetId)
	if (!target || target.kind !== payload.kind
		|| (target.kind !== 'item' && target.zoneName !== payload.zoneName)
		|| (target.parentId === 'page' && payload.node.type !== 'core.row')) return null
	const pasted = cloneNodeWithNewIds(payload.node)
	const replacing = mode === 'replace'
	target.siblings.splice(target.index + (mode === 'after' ? 1 : 0), replacing ? 1 : 0, pasted)

	// Préserver le choix du corps par défaut lorsque ce corps est remplacé.
    if (replacing && target.kind !== 'item') replaceNodeReference(findNode(next, target.parentId).node, targetId, pasted.id)
	return validTree(next) ? { document: next, nodeId: pasted.id } : null
}

