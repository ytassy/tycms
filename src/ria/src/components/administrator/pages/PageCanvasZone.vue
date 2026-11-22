<template>
	<div v-if="visibleInLocale(zone, store.previewLocale)" class="page-canvas-column" :data-page-node="zone.id"
		:class="{ 'page-canvas-node--hidden': isNodeHidden(zone), 'page-canvas-column--selected': store.selectedId === zone.id, 'page-canvas-node--link-color': hasLinkColor(zone.props?.common) }"
		:style="canvasStyle(zone.props?.common)" :draggable="store.current.canEdit"
		@click.stop="store.selectNode(zone.id)" @contextmenu.stop="contextMenu"
		@dragstart.stop="startDrag" @dragend.stop="store.endDrag()">
		<span class="page-canvas-badge">{{ label }}</span>
		<PageCanvasItems :items="zone.items" :parent-id="zone.id" :viewport="viewport" />
	</div>
</template>
<script>
import { defineAsyncComponent } from 'vue'
import { findNode, zoneDefinition } from './pageEditorModel.js'
import { canvasStyle, hasLinkColor } from './pageCommonStyle.js'
import { isNodeHidden, visibleInLocale } from './pageVisibility.js'
export default {
	components: { PageCanvasItems: defineAsyncComponent(() => import('./PageCanvasItems.vue')) },
	inject: { openPageContextMenu: { default: null } },
	props: { zone: { type: Object, required: true }, viewport: { type: String, required: true } },
	computed: {
        store() { return this.stores.useAdminPagesStore() },
        label() { return zoneDefinition(this.store.current.data, findNode(this.store.current.data, this.zone.id))?.label ?? 'Section' },
    },
	methods: {
		canvasStyle, hasLinkColor, isNodeHidden, visibleInLocale,
		contextMenu(event) { if (this.store.current.canEdit) this.openPageContextMenu?.(event, this.zone.id) },
		startDrag(event) {
			if (!this.store.current.canEdit) return
			this.store.beginDrag({ kind: 'node', id: this.zone.id })
			event.dataTransfer.effectAllowed = 'move'
			event.dataTransfer.setData('text/plain', this.zone.id)
		},
	},
}
</script>
