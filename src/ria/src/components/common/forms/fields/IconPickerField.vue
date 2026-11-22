<template>
	<BaseField :field="field" :errors="errors" v-slot="{ inputId }">
		<icon_picker
			:id="inputId"
			:model-value="modelValue ?? ''"
			:disabled="Boolean(field.disabled) || Boolean(field.readonly)"
			@update:model-value="$emit('update:modelValue', $event)"
		/>
	</BaseField>
</template>

<script setup>
import BaseField from './BaseField.vue'

defineProps({
	field: { type: Object, required: true },
	modelValue: { type: String, default: '' },
	errors: { type: Array, default: () => [] },
})

defineEmits(['update:modelValue'])
</script>
