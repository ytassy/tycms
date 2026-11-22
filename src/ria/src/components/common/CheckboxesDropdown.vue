<template>
	<div class="d-inline-flex flex-wrap align-items-center gap-1">
		<span v-if="!selectedKeys.length && emptyLabel" class="text-muted small">{{ emptyLabel }}</span>
		<span v-for="key in selectedKeys" :key="key" class="badge text-bg-secondary">
			{{ options[key] }}
			<button v-if="!readOnly" type="button" class="btn-close btn-close-white ms-1" aria-label="Retirer" @click="toggle(key)"></button>
		</span>

		<b-dropdown v-if="!readOnly && unselectedKeys.length" ref="dropdown" variant="outline-secondary" no-caret tag="div">
			<template #button-content><i class="fa fa-plus-circle" aria-hidden="true"></i><span class="visually-hidden">{{ addLabel }}</span></template>
			<div class="p-2 checkboxes-dropdown__menu">
				<button
					v-for="key in unselectedKeys"
					:key="key"
					type="button"
					class="btn btn-sm btn-light text-start w-100"
					@click="toggle(key)"
				>
					{{ options[key] }}
				</button>
			</div>
		</b-dropdown>
	</div>
</template>

<script>

export default {
	props: {
		modelValue: { type: Array, default: () => [] },
		options: { type: Object, default: () => ({}) },
		readOnly: { type: Boolean, default: false },
		sortNotSelected: { type: Boolean, default: true },
		emptyLabel: { type: String, default: '' },
		addLabel: { type: String, default: 'Ajouter une option' },
	},
	emits: ['update:modelValue', 'change'],

	computed: {
		selectedKeys() {
			return this.modelValue.map(String).filter((key) => Object.hasOwn(this.options, key))
		},
		unselectedKeys() {
			const keys = Object.keys(this.options).filter((key) => !this.selectedKeys.includes(key))
			return this.sortNotSelected ? keys.sort((left, right) => String(this.options[left]).localeCompare(String(this.options[right]))) : keys
		},
	},

	methods: {
		toggle(key) {
			const value = [...this.selectedKeys]
			const index = value.indexOf(key)
			if (index === -1) value.push(key)
			else value.splice(index, 1)
			this.$emit('update:modelValue', value)
			this.$emit('change', value)
		},
		openAdd() {
			this.$refs.dropdown.open = true
		},
	},
}
</script>

<style scoped>
.checkboxes-dropdown__menu {
	max-height: min(90vh, 30rem);
	min-width: 15rem;
	overflow-y: auto;
}
</style>
