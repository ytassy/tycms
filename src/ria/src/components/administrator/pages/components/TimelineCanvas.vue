<template>
	<ol class="page-timeline" :class="classes" :style="variables">
		<li v-for="(step, index) in steps" :key="step.id" class="page-timeline-step">
			<span class="page-timeline-marker" aria-hidden="true">
				<template v-if="item.props.marker === 'number'">{{ index + 1 }}</template>
				<i v-else-if="item.props.marker === 'icon'" :class="step.icon || 'fa-solid fa-circle'"></i>
			</span>
			<div class="page-timeline-content">
				<div class="page-timeline-label">{{ text(step.label) }}</div>
				<div class="page-timeline-card"><slot name="zone" :zone="step" /></div>
			</div>
		</li>
	</ol>
</template>
<script>
import { collectionVariables, timelineStyleFields } from '../../../common/pageCollectionStyle.js'
import { visibleInLocale } from '../pageVisibility.js'
import { selectLocalizedText } from '../../../common/localizedText.js'

export default {
	props: { item: Object, node: Object, context: Object, components: Array, viewport: String },
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		steps() { return this.item.zones.steps.filter(step => visibleInLocale(step, this.store.previewLocale)) },
		classes() {
			const p = this.item.props
			return [`page-timeline--${p.layout}`, `page-timeline--break-${p.mobile_breakpoint}`,
				{ 'page-timeline--pointer': p.show_pointer, 'page-timeline--labels-opposite': p.label_position === 'opposite' }]
		},
		variables() { return { ...collectionVariables(this.item.props.timeline_style, timelineStyleFields), '--pc-line-style': this.item.props.line_style } },
	},
	methods: { text(value) { return selectLocalizedText(value, this.store.previewLocale) } },
}
</script>
