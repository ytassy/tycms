<template>
	<BaseField :field="field" :errors="errors" v-slot="{ inputId, invalid }">
		<color_input :id="inputId" :model-value="modelValue" :allow-alpha="field.allowAlpha !== false"
			:palette="field.palette" :readonly="Boolean(field.readonly)" :disabled="Boolean(field.disabled)"
			:invalid="invalid" @update:model-value="$emit('update:modelValue', $event)" />
	</BaseField>
</template>

<script setup>
import BaseField from './BaseField.vue'

defineProps({
	field: { type: Object, required: true },
	modelValue: { type: String, default: '#000000' },
	errors: { type: Array, default: () => [] },
})
defineEmits(['update:modelValue'])
</script>
