<template>
	<div class="page-tree-items">
		<template v-for="(item, index) in items" :key="item.id">
			<div class="page-tree-entry" :class="dropClass(item.id)"
				@dragover="dragOverEntry($event, 'items', parentId, index, item.id)"
				@dragleave="leaveEntry($event, item.id)"
				@drop="dropOnEntry($event, 'items', parentId, index)">
				<span class="page-tree-grip" :draggable="canEdit" aria-hidden="true" :title="canEdit ? 'Glisser pour déplacer' : null"
					@dragstart.stop="startDrag($event, item.id)" @dragend.stop="endDrag">⋮⋮</span>
				<button type="button" class="page-tree-node" :class="{ 'page-tree-node--selected': store.selectedId === item.id }"
					@click="store.selectNode(item.id, { showProperties: false })">
					<span class="page-tree-node-label">{{ nodeLabel(item, store.components) }}</span><span v-if="isNodeHidden(item)" class="page-node-hidden-badge">Masqué</span>
					<small v-if="item.type === 'core.heading' && text(item.props.text)" class="text-truncate">{{ text(item.props.text) }}</small>
				</button>
				<button type="button" class="page-tree-action" :disabled="!canEdit || index === 0" :aria-label="`Monter ${nodeLabel(item, store.components)}`" title="Monter" @click="store.moveSibling(item.id, -1)"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button>
				<button type="button" class="page-tree-action" :disabled="!canEdit || index === items.length - 1" :aria-label="`Descendre ${nodeLabel(item, store.components)}`" title="Descendre" @click="store.moveSibling(item.id, 1)"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></button>
				<button type="button" class="page-tree-action" :aria-label="`Propriétés de ${nodeLabel(item, store.components)}`" title="Propriétés" @click="store.selectNode(item.id)"><i class="fa-solid fa-sliders" aria-hidden="true"></i></button>
			</div>
            <div v-for="(zones, name) in item.zones" :key="name" class="page-tree-children">
                <template v-for="(zone, zoneIndex) in zones" :key="zone.id">
                    <div class="page-tree-entry" :class="dropClass(zone.id)"
                        @dragover="dragOverEntry($event, name, item.id, zoneIndex, zone.id)"
                        @dragleave="leaveEntry($event, zone.id)" @drop="dropOnEntry($event, name, item.id, zoneIndex, zone.id)">
                        <span class="page-tree-grip" :draggable="canEdit" @dragstart.stop="startDrag($event, zone.id)" @dragend.stop="endDrag">⋮⋮</span>
                        <button type="button" class="page-tree-node" :class="{ 'page-tree-node--selected': store.selectedId === zone.id }"
                            @click="store.selectNode(zone.id, { showProperties: false })">
                            <i v-if="zone.icon" :class="zone.icon" aria-hidden="true"></i>
                            <span class="page-tree-node-label">{{ text(zone.label) || zoneLabel(item, name, zoneIndex) }}</span>
                            <span v-if="isNodeHidden(zone)" class="page-node-hidden-badge">Masqué</span>
                        </button>
                        <button type="button" class="page-tree-action" :disabled="!canEdit || zoneIndex === 0" title="Monter" @click="store.moveSibling(zone.id, -1)">↑</button>
                        <button type="button" class="page-tree-action" :disabled="!canEdit || zoneIndex === zones.length - 1" title="Descendre" @click="store.moveSibling(zone.id, 1)">↓</button>
                        <button type="button" class="page-tree-action" title="Propriétés" @click="store.selectNode(zone.id)"><i class="fa-solid fa-sliders" aria-hidden="true"></i></button>
                    </div>
                    <div class="page-tree-children"><PageTreeItems :items="zone.items" :parent-id="zone.id" /></div>
                </template>
            </div>
		</template>
	</div>
</template>

<script>
import { childZones, findNode, nodeLabel } from './pageEditorModel'
import { isNodeHidden } from './pageVisibility'
import { selectLocalizedText } from '../../common/localizedText'

export default {
	name: 'PageTreeItems',
	props: { items: { type: Array, required: true }, parentId: { type: String, required: true } },
	data: () => ({ dropHint: null }),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		canEdit() { return Boolean(this.store.current?.canEdit) },
	},
	methods: {
		nodeLabel, childZones, isNodeHidden,
        zoneLabel(item, name, index) {
            return `${this.store.components.find(entry => entry.id === item.type)?.zones?.[name]?.label ?? name} ${index + 1}`
        },
		text(value) { return selectLocalizedText(value, this.store.previewLocale) },
		startDrag(event, id) {
			if (!this.canEdit) { event.preventDefault(); return }
			event.dataTransfer.effectAllowed = 'move'
			event.dataTransfer.setData('text/plain', id)
			this.store.beginDrag({ kind: 'node', id })
		},
		endDrag() {
			this.dropHint = null
			this.store.endDrag()
		},
		dropClass(id) {
			return this.store.dragPayload && this.dropHint?.id === id
				? `page-tree-entry--drop-${this.dropHint.position}` : null
		},
		targetAtPointer(event, kind, parentId, index, id) {
			const payload = this.store.dragPayload
			const dragged = payload?.kind === 'node' ? findNode(this.store.current.data, payload.id) : null
			if (kind !== 'items' && (payload?.kind === 'catalog' || dragged?.kind === 'item')) {
				const column = findNode(this.store.current.data, id)?.node
				return { position: 'inside', target: { kind: 'items', parentId: id, index: column?.items.length ?? 0 } }
			}
			const bounds = event.currentTarget.getBoundingClientRect()
			const position = event.clientY < bounds.top + bounds.height / 2 ? 'before' : 'after'
			return { position, target: { kind, parentId, index: index + (position === 'after' ? 1 : 0) } }
		},
		dragOverEntry(event, kind, parentId, index, id) {
			if (!this.store.dragPayload) return
			const { position, target } = this.targetAtPointer(event, kind, parentId, index, id)
			if (!this.store.canDrop(target)) { this.dropHint = null; return }
			event.preventDefault()
			event.stopPropagation()
			event.dataTransfer.dropEffect = 'move'
			this.dropHint = { id, position }
		},
		leaveEntry(event, id) {
			if (this.dropHint?.id === id && !event.currentTarget.contains(event.relatedTarget)) this.dropHint = null
		},
		dropOnEntry(event, kind, parentId, index, id) {
			if (!this.store.dragPayload) return
			const { target } = this.targetAtPointer(event, kind, parentId, index, id)
			if (!this.store.canDrop(target)) return
			event.preventDefault()
			event.stopPropagation()
			this.dropHint = null
			this.store.drop(target)
		},
	},
}
</script>
