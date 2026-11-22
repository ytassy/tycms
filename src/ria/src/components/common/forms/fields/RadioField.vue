<template>
	<BaseField :field="field" :errors="errors" v-slot="{ inputId, invalid }">
		<div :class="{ 'is-invalid': invalid }">
			<div v-for="option in field.options ?? []" :key="String(option.value)" class="form-check">
				<input
					:id="`${inputId}-${String(option.value)}`"
					:name="inputId"
					type="radio"
					:checked="modelValue === option.value"
					:value="option.value"
					:disabled="Boolean(field.disabled) || option.disabled"
					class="form-check-input"
					@change="$emit('update:modelValue', option.value)"
				>
				<label :for="`${inputId}-${String(option.value)}`" class="form-check-label">{{ option.label }}</label>
			</div>
		</div>
	</BaseField>
</template>

<script setup>
import BaseField from './BaseField.vue'

defineProps({
	field: { type: Object, required: true },
	modelValue: { default: null },
	errors: { type: Array, default: () => [] },
})

defineEmits(['update:modelValue'])
</script>
