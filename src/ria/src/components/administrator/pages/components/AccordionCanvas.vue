<template>
	<div class="page-accordion" :class="[`page-accordion--${item.props.mode ?? 'bootstrap'}`, `page-accordion--arrow-${item.props.arrow_position ?? 'right'}`]" :style="accordionVariables(item.props)">
		<template v-for="zone in item.zones.panels" :key="zone.id">
			<details v-if="visibleInLocale(zone, store.previewLocale)" class="page-accordion-panel" :open="open.includes(zone.id)">
				<summary class="page-accordion-heading" @click.stop.prevent="toggle(zone)">
					<i v-if="zone.icon" :class="zone.icon" aria-hidden="true"></i> {{ text(zone.label) || 'Panneau' }}
					<span v-if="item.props.show_arrow !== false" class="page-accordion-arrow" aria-hidden="true"><i :class="item.props.arrow_icon || 'fa-solid fa-chevron-right'"></i></span>
				</summary>
				<slot name="zone" :zone="zone" />
			</details>
		</template>
	</div>
</template>
<script>
import { accordionVariables } from '../pageTabsStyle.js'
import { visibleInLocale } from '../pageVisibility.js'
import { selectLocalizedText } from '../../../common/localizedText.js'
export default {
	props: { node: Object, context: Object, components: Array, viewport: String, item: { type: Object, required: true } },
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		open() { return this.store.previewAccordion[this.item.id] ?? this.item.props.open_panels ?? [] },
	},
    watch: { 'store.activeZones': { deep: true, handler() { const id = this.store.activeZones[this.item.id]; if (id) { this.store.previewAccordion[this.item.id] = this.item.props.single_open !== false ? [id] : [...new Set([...this.open, id])] } } } },
	methods: {
		accordionVariables, visibleInLocale,
		text(value) { return selectLocalizedText(value, this.store.previewLocale) },
		toggle(zone) {
            const wasOpen = this.open.includes(zone.id)
            const next = wasOpen ? this.open.filter(id => id !== zone.id)
                : this.item.props.single_open !== false ? [zone.id] : [...this.open, zone.id]
            this.store.selectNode(zone.id)
            this.store.previewAccordion[this.item.id] = next
		},
	},
}
</script>
