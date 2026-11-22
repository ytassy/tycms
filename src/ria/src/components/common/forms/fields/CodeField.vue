<template>
	<BaseField :field="field" :errors="errors" v-slot="{ inputId, invalid }">
		<code_editor
			:input-id="inputId"
			:aria-label="field.label || field.name || 'Éditeur de code'"
			:model-value="modelValue ?? ''"
			:syntax="field.syntax"
			:max-bytes="field.validation?.maxBytes"
			:read-only="Boolean(field.readonly) || Boolean(field.disabled)"
			:invalid="invalid"
			@update:model-value="$emit('update:modelValue', $event)"
		/>
		<div v-if="field.validation?.maxBytes" class="form-text">
			Maximum : {{ Math.floor(field.validation.maxBytes / 1024) }} Ko
		</div>
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
