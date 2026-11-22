<template>
	<BaseField :field="field" :errors="errors" v-slot="{ inputId, invalid }">
		<code_editor
			v-if="field.syntax"
			:input-id="inputId"
			:aria-label="field.label || field.name || 'Valeur calculée'"
			:model-value="displayValue"
			:syntax="field.syntax"
			:read-only="true"
			:invalid="invalid"
		/>
		<output v-else :id="inputId" class="computed-field form-control d-block">{{ displayValue }}</output>
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

const displayValue = computed(() => {
	if (props.modelValue === null || props.modelValue === undefined) return ''
	if (typeof props.modelValue === 'string') return props.modelValue

	return JSON.stringify(props.modelValue, null, 2)
})
</script>

<style scoped>
.computed-field {
	min-height: calc(1.5em + .75rem + 2px);
	white-space: pre-wrap;
}
</style>
