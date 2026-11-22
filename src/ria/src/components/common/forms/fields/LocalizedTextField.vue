<template>
    <BaseField :field="field" :errors="errors" :label-in-control="condensed">
        <LocalizedTextInput :model-value="modelValue" :label="condensed ? (field.label ?? '') : ''"
            :required="condensed && Boolean(field.required)"
            :locales="context.locales ?? []" :locale="context.locale ?? 'fr'"
            :space-id="context.spaceId" :mode="field.mode ?? 'text'" :max-length="field.maxLength ?? 500"
            :disabled="Boolean(field.disabled || field.readonly || context.readonly)"
            @update:model-value="$emit('update:modelValue', $event)" />
    </BaseField>
</template>

<script>
import BaseField from './BaseField.vue'
import LocalizedTextInput from '../../LocalizedTextField.vue'
import { condensedFormKey } from '../formDisplay'

export default {
    components: { BaseField, LocalizedTextInput },
    inject: { condensed: { from: condensedFormKey, default: false } },
    props: {
        field: { type: Object, required: true },
        modelValue: { type: [String, Object], default: '' },
        context: { type: Object, default: () => ({}) },
        errors: { type: Array, default: () => [] },
    },
    emits: ['update:modelValue'],
}
</script>
