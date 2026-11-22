<template>
	<BaseField :field="field" :errors="errors" v-slot="{ inputId, invalid }">
		<textarea
			:id="inputId"
			:value="modelValue ?? ''"
			:rows="field.ui?.rows ?? field.rows ?? 4"
			:placeholder="field.ui?.placeholder ?? field.placeholder"
			:maxlength="field.validation?.maxLength ?? field.maxLength"
			:readonly="Boolean(field.readonly)"
			:disabled="Boolean(field.disabled)"
			:class="['form-control', { 'is-invalid': invalid }]"
			@input="$emit('update:modelValue', $event.target.value)"
		/>
	</BaseField>
</template>

<script setup>
import BaseField from './BaseField.vue'

defineProps({
	field: { type: Object, required: true },
	modelValue: { default: '' },
	errors: { type: Array, default: () => [] },
})

defineEmits(['update:modelValue'])
</script>
