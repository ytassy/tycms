<template>
	<component :is="canvas" v-if="canvas" :item="item" :node="item" :context="context" :viewport="viewport" :components="components">
		<template #zone="scope"><slot name="zone" v-bind="scope" /></template>
	</component>
	<template v-else>
		<PageCanvasApi v-if="definition?.canvasMode === 'api'" :item="item" />
		<div v-else class="page-canvas-placeholder">{{ definition?.label ?? item.type }} · aperçu indisponible</div>
		<!-- Même sans son plugin, le contenu d'un conteneur reste accessible. -->
		<template v-for="zone in childZones(item)" :key="zone.id"><slot name="zone" :zone="zone" /></template>
	</template>
</template>
<script>
import { resolvePageCanvas } from './componentRegistry.js'
import { childZones } from './pageEditorModel.js'
import PageCanvasApi from './PageCanvasApi.vue'
export default {
	components: { PageCanvasApi },
	props: { item: { type: Object, required: true }, components: { type: Array, default: () => [] }, viewport: String },
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		definition() { return this.components.find(entry => entry.id === this.item.type) },
		canvas() { return resolvePageCanvas(this.definition?.canvasVue) },
		context() { return { ...this.store.editContext, spaceId: this.store.current.space_id, locale: this.store.previewLocale } },
	},
	methods: { childZones },
}
</script>
