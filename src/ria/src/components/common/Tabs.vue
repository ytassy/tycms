<template>
	<div class="tylms-tabs">
		<div class="tylms-tabs-nav nav nav-tabs">
			<button
				v-for="(label, index) in state.labels"
				:key="`${label}-${index}`"
				type="button"
				:class="['nav-link', { active: state.activeIndex === index }]"
				@click="state.activeIndex = index"
			>
				<i v-if="label.icon != ''" :class="label.icon"></i>
				{{ label.label }}
			</button>
		</div>
		<div class="tylms-tab-content pt-3"><slot /></div>
	</div>
</template>

<script>
import { tabsKey } from './tabsContext'

export default {
	props: {
		defaultIndex: { type: Number, default: 0 },
	},

	data() {
		return {
			state: {
				activeIndex: this.defaultIndex,
				labels: [],
			},
		}
	},

	provide() {
		return { [tabsKey]: this.state }
	},
}
</script>
