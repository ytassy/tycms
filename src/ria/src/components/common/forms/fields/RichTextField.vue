<template>
    <BaseField :field="field" :errors="errors">
        <rich_text_editor :model-value="modelValue" :media-space-id="context.spaceId ?? 0" expandable
            :disabled="Boolean(field.disabled || field.readonly || context.readonly)"
            @update:model-value="$emit('update:modelValue', $event)" />
    </BaseField>
</template>

<script>
import BaseField from './BaseField.vue'

export default {
    components: { BaseField },
    props: {
        field: { type: Object, required: true },
        modelValue: { type: String, default: '' },
        context: { type: Object, default: () => ({}) },
        errors: { type: Array, default: () => [] },
    },
    emits: ['update:modelValue'],
}
</script>
