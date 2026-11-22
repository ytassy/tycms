<template>
	<BaseField :field="field" :errors="errors">
		<json_editor
			:model-value="modelValue"
			:schema="rootSchema"
			:schemas="schemas"
			@update:model-value="$emit('update:modelValue', $event)"
		/>
	</BaseField>
</template>

<script setup>
import { computed } from 'vue'
import BaseField from './BaseField.vue'

const props = defineProps({
	field: { type: Object, required: true },
	modelValue: { default: null },
	errors: { type: Array, default: () => [] },
})

defineEmits(['update:modelValue'])

const rootSchema = computed(() => props.field.schema?.schema ?? props.field.schema)
const schemas = computed(() => props.field.schemas ?? props.field.schema?.schemas_ref ?? {})
</script>
