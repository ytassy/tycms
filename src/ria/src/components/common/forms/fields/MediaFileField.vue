<template>
	<BaseField :field="field" :errors="errors" v-slot="{ inputId, invalid }">
		<MediaFileInput :id="inputId" :model-value="modelValue" :space-id="context.spaceId"
			:media-type="field.mediaType ?? 'all'" :allow-manual-input="field.allowManualInput !== false"
			:manual-input-mode="field.manualInputMode ?? 'media'" :readonly="Boolean(field.readonly)"
			:disabled="Boolean(field.disabled)" :invalid="invalid" :size="size"
			@update:model-value="$emit('update:modelValue', $event)" />
	</BaseField>
</template>

<script>
import BaseField from './BaseField.vue'
import MediaFileInput from '../../MediaFileInput.vue'

export default {
	components: { BaseField, MediaFileInput },
	props: {
		field: { type: Object, required: true },
		modelValue: { type: String, default: '' },
		size: { type: String, default: 'md' },
		context: { type: Object, default: () => ({}) },
		errors: { type: Array, default: () => [] },
	},
	emits: ['update:modelValue'],
}
</script>
