<template>
	<div class="row g-2 page-canvas-row">
		<div v-for="zone in item.zones.columns" :key="zone.id" class="page-canvas-column-wrap" :style="columnStyle(zone)">
			<slot name="zone" :zone="zone" />
		</div>
	</div>
</template>
<script>
import { columnSizeAtViewport } from '../pagePreviewViewports.js'
export default {
	props: { node: Object, context: Object, components: Array, viewport: String, item: { type: Object, required: true } },
	methods: { columnStyle(zone) { return { width: `${columnSizeAtViewport(zone.sizes, this.viewport) / 12 * 100}%` } } },
}
</script>
