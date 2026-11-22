<template>
	<div v-show="tabs?.activeIndex === index"><slot :active="tabs?.activeIndex === index" /></div>
</template>

<script>
import { tabsKey } from './tabsContext'

export default {
	props: {
		label: { type: String, required: true },
		icon: { type: String, required: false },
	},
	inject: {
		tabs: { from: tabsKey, default: null },
	},

	data() {
		return { index: -1 }
	},

	mounted() {
		if (this.tabs === null) throw new Error('<tab> doit être utilisé dans <tabs>.')
		this.index = this.tabs.labels.length
		this.tabs.labels.push({
			label: this.label,
			icon: this.icon,
		})
	},

	beforeUnmount() {
		if (this.tabs === null || this.index < 0) return
		this.tabs.labels.splice(this.index, 1)
		if (this.tabs.activeIndex >= this.tabs.labels.length) this.tabs.activeIndex = Math.max(0, this.tabs.labels.length - 1)
	},
}
</script>
