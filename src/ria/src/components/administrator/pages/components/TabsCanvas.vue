<template>
	<div class="page-tabs" :class="`page-tabs--${item.props.mode ?? 'bootstrap'}`" :style="tabsVariables(item.props)" role="group" aria-label="Onglets">
		<template v-for="zone in item.zones.tabs" :key="zone.id">
			<template v-if="visibleInLocale(zone, store.previewLocale)">
				<input :id="`canvas-${item.id}-${zone.id}`" class="page-tabs-radio visually-hidden" type="radio" :name="`canvas-tabs-${item.id}`"
					:checked="activeTab === zone.id" @change="select(zone)" @click.stop>
				<label :for="`canvas-${item.id}-${zone.id}`" class="page-tabs-label" @click.stop="select(zone)">
					<i v-if="zone.icon" :class="zone.icon" aria-hidden="true"></i> {{ text(zone.label) || 'Onglet' }}
				</label>
				<section class="page-tabs-panel"><slot name="zone" :zone="zone" /></section>
			</template>
		</template>
	</div>
</template>
<script>
import { tabsVariables } from '../pageTabsStyle.js'
import { visibleInLocale } from '../pageVisibility.js'
import { selectLocalizedText } from '../../../common/localizedText.js'
export default {
	props: { node: Object, context: Object, components: Array, viewport: String, item: { type: Object, required: true } },
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		activeTab() {
			const zones = this.item.zones.tabs.filter(zone => visibleInLocale(zone, this.store.previewLocale))
			const id = this.store.previewTabs[this.item.id] ?? this.item.props.default_tab
			return zones.find(zone => zone.id === id)?.id ?? zones[0]?.id
		},
	},
    watch: { 'store.activeZones': { deep: true, handler() { const id = this.store.activeZones[this.item.id]; if (id) { this.store.previewTabs[this.item.id] = id } } } },
	methods: {
		tabsVariables, visibleInLocale,
		text(value) { return selectLocalizedText(value, this.store.previewLocale) },
		select(zone) { this.store.previewTabs[this.item.id] = zone.id; this.store.selectNode(zone.id) },
	},
}
</script>
