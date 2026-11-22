<template>
	<div ref="root" class="dropdown">
		<button :class="buttonClass" type="button" :disabled="disabled" :aria-expanded="open" @click="open = !open">
			<slot name="button-content" />
		</button>

		<component :is="tag" :class="['dropdown-menu', { show: open }]">
			<slot v-if="!lazy || open" />
		</component>
	</div>
</template>

<script>
export default {
	props: {
		variant: { type: String, default: null },
		noCaret: { type: Boolean, default: false },
		lazy: { type: Boolean, default: false },
		tag: { type: String, default: 'ul' },
		disabled: { type: Boolean, default: false },
	},
	emits: ['show', 'hide'],

	data() {
		return { open: false }
	},

	computed: {
		buttonClass() {
			return [
				'btn',
				this.open ? 'esc' : null,
				this.noCaret ? null : 'dropdown-toggle',
				`btn-${this.variant ?? 'primary'}`,
			]
		},
	},

	watch: {
		disabled(value) {
			if (value) this.hide()
		},
		open(value, previousValue) {
			if (value === previousValue) return
			this.$emit(value ? 'show' : 'hide')
		},
	},

	mounted() {
		document.addEventListener('click', this.onDocumentClick)
	},

	beforeUnmount() {
		document.removeEventListener('click', this.onDocumentClick)
	},

	methods: {
		hide() {
			this.open = false
		},

		onDocumentClick(event) {
			if (this.$refs.root !== null && event.target instanceof Node && !this.$refs.root.contains(event.target)) this.hide()
		},
	},
}
</script>
