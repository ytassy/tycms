<template>
	<div class="page-canvas-items">
		<PageDropZone :target="{ kind: 'items', parentId, index: 0 }" />
		<template v-for="(item, index) in items" :key="item.id">
			<div v-if="visibleInLocale(item, store.previewLocale)" class="page-canvas-item" :class="{ 'page-canvas-node--hidden': isNodeHidden(item), 'page-canvas-item--selected': store.selectedId === item.id, 'page-canvas-item--layered-background': hasLayeredBackground(item.props?.common), 'page-canvas-node--link-color': hasLinkColor(item.props?.common) }" :style="canvasStyle(item.props?.common)"
				:data-page-node="item.id" :draggable="canEdit" @click.stop="store.selectNode(item.id)" @contextmenu.stop="openContext($event, item.id)"
				@dragstart.stop="startDrag($event, item.id)" @dragend.stop="store.endDrag()">
				<span class="page-canvas-badge">{{ nodeLabel(item, store.components) }} <span v-if="isNodeHidden(item)" class="page-node-hidden-badge">Masqué</span><span v-if="conditionalVisibility(item)"> · visibilité conditionnelle</span></span>
                <PageCanvasLeaf :item="item" :components="store.components" :viewport="viewport">
                    <template #zone="{ zone }"><PageCanvasZone :zone="zone" :viewport="viewport" /></template>
                </PageCanvasLeaf>
			</div>
			<PageDropZone :target="{ kind: 'items', parentId, index: index + 1 }" />
		</template>
		<p v-if="items.length === 0 && !store.dragPayload" class="page-canvas-empty">{{ parentId === 'page' ? 'Page vide : glissez un composant ici ou utilisez Ajouter.' : 'Colonne vide : glissez un composant ici.' }}</p>
		<p v-else-if="!store.dragPayload && !hasVisibleItems()" class="page-canvas-empty">
			Aucun composant visible en {{ store.previewLocale.toUpperCase() }}. Utilisez l’onglet Structure pour modifier leur visibilité.
		</p>
	</div>
</template>

<script>
import PageCanvasLeaf from './PageCanvasLeaf.vue'
import PageCanvasZone from './PageCanvasZone.vue'
import PageDropZone from './PageDropZone.vue'
import { nodeLabel } from './pageEditorModel'
import { canvasStyle, hasLayeredBackground, hasLinkColor } from './pageCommonStyle'
import { columnSizeAtViewport } from './pagePreviewViewports'
import { isNodeHidden, visibleInLocale } from './pageVisibility'

export default {
	name: 'PageCanvasItems',
	inject: { openPageContextMenu: { default: null } },
	components: { PageCanvasLeaf, PageCanvasZone, PageDropZone },
	props: {
		items: { type: Array, required: true }, parentId: { type: String, required: true },
		viewport: { type: String, default: 'lg' },
	},
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		canEdit() { return Boolean(this.store.current?.canEdit) },
	},
	methods: {
		nodeLabel, isNodeHidden,
		openContext(event, id) {
			if (!this.canEdit) return
			event.preventDefault()
			this.openPageContextMenu?.(event, id)
		},
		canvasStyle,
		hasLayeredBackground, hasLinkColor,
		columnDecoration(column) {
			const style = canvasStyle(column.props?.common)
			if (!style.textAlign && column.props?.align) style.textAlign = column.props.align
			return style
		},
		visibleInLocale,
		hasVisibleItems() {
			return this.items.some(item => visibleInLocale(item, this.store.previewLocale))
		},
		conditionalVisibility(node) {
			return Boolean(node.props?.common?.role_ids?.length || node.props?.common?.hidden_at?.length
				|| node.props?.common?.languages?.length)
		},
		columnStyle(column) {
			const size = columnSizeAtViewport(column.sizes, this.viewport)
			return { flex: '0 0 auto', width: `${size / 12 * 100}%` }
		},
		startDrag(event, id) {
			if (!this.canEdit) { event.preventDefault(); return }
			this.store.beginDrag({ kind: 'node', id })
			event.dataTransfer.effectAllowed = 'move'
			event.dataTransfer.setData('text/plain', id)
		},
	},
}
</script>
