<template>
	<BaseField :field="field" :errors="errors" :label-in-control="condensed" v-slot="{ inputId, invalid }">
		<div class="form-check" :class="{ 'form-switch': !condensed }">
			<input
				:id="inputId"
				:checked="Boolean(modelValue)"
				:disabled="Boolean(field.disabled) || Boolean(field.readonly)"
				:class="['form-check-input', { 'is-invalid': invalid }]"
				type="checkbox"
				:role="condensed ? undefined : 'switch'"
				@change="$emit('update:modelValue', $event.target.checked)"
			>
			<label v-if="condensed && field.label" :for="inputId" class="form-check-label">
				{{ field.label }}
				<span v-if="field.required" class="text-danger" aria-hidden="true">*</span>
			</label>
		</div>
	</BaseField>
</template>

<script setup>
import { computed, inject } from 'vue'
import BaseField from './BaseField.vue'
import { condensedFormKey } from '../formDisplay'

const condensed = inject(condensedFormKey, computed(() => false))

defineProps({
	field: { type: Object, required: true },
	modelValue: { type: Boolean, default: false },
	errors: { type: Array, default: () => [] },
})

defineEmits(['update:modelValue'])
</script>
