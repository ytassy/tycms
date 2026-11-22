<template>
	<span>
		<button
			ref="trigger"
			type="button"
			:class="['btn btn-link p-0', text_class]"
			:disabled="deleting"
			@click="showConfirmation = !showConfirmation"
		>
			<spinner v-if="deleting && spinner" size="sm" />
			<template v-else-if="showIcon">
				<i class="fa-solid fa-trash" aria-hidden="true"></i>
			</template>
			<template v-if="text !== null">{{ text }}</template>
			<slot />
		</button>

		<b-popover v-model:show="showConfirmation" :target="$refs.trigger" placement="top">
			<p>{{ effectiveText }}</p>
			<button type="button" class="btn btn-primary btn-sm" @click="confirm">Supprimer</button>
			<button type="button" class="btn btn-outline-primary btn-sm ms-2" @click="showConfirmation = false">Non</button>
		</b-popover>
	</span>
</template>

<script>

export default {
	props: {
		text: { type: String, default: null },
		text_class: { type: String, default: 'text-body' },
		confirm_text: { type: String, default: null },
		showIcon: { type: Boolean, default: true },
		spinner: { type: Boolean, default: true },
	},
	emits: ['click'],

	data() {
		return { deleting: false, showConfirmation: false }
	},

	computed: {
		effectiveText() {
			return this.confirm_text ?? 'Supprimer ?'
		},
	},

	methods: {
		confirm() {
			if (this.deleting) return
			this.deleting = this.spinner
			this.showConfirmation = false
			this.$emit('click')
		},
	},
}
</script>
