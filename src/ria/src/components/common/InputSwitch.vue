<template>
	<div class="form-check form-switch d-print-none">
		<input
			:id="id"
			v-model="switchValue"
			class="form-check-input"
			type="checkbox"
			role="switch"
			:disabled="disabled"
			:name="name ?? undefined"
		>
		<label class="form-check-label" :for="id"><slot /></label>
	</div>
</template>

<script>
let switchId = 0

export default {
	props: {
		checked: { type: Boolean, default: null },
		modelValue: { type: Boolean, default: null },
		name: { type: String, default: null },
		disabled: { type: Boolean, default: false },
	},
	emits: ['update:modelValue', 'change'],

	data() {
		switchId += 1
		return { id: `on-off-switch-${switchId}` }
	},

	computed: {
		switchValue: {
			get() {
				return this.checked ?? this.modelValue ?? false
			},
			set(checked) {
				this.$emit('update:modelValue', checked)
				this.$emit('change', checked)
			},
		},
	},
}
</script>
