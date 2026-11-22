<template>
	<BaseField :field="field" :errors="errors" v-slot="{ inputId, invalid }">
		<input
			:id="inputId"
			type="number"
			:value="modelValue ?? ''"
			:min="field.validation?.minimum ?? field.min"
			:max="field.validation?.maximum ?? field.max"
			:step="field.ui?.step ?? field.step ?? 'any'"
			:readonly="Boolean(field.readonly)"
			:disabled="Boolean(field.disabled)"
			:class="['form-control', { 'is-invalid': invalid }]"
			@input="updateValue"
		>
	</BaseField>
</template>

<script setup>
import BaseField from './BaseField.vue'

defineProps({
	field: { type: Object, required: true },
	modelValue: { default: null },
	errors: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:modelValue'])

function updateValue(event) {
	const value = event.target.value
	emit('update:modelValue', value === '' ? null : Number(value))
}
</script>
