<template>
	<BaseField :field="field" :errors="errors" v-slot="{ inputId, invalid }">
		<div v-if="field.multiple && field.ui?.picker === 'dropdown-list'" :id="inputId"
			:class="['d-flex flex-wrap align-items-center gap-2', { 'is-invalid': invalid }]">
			<span v-for="option in selectedOptions" :key="String(option.value)" class="badge text-bg-secondary d-inline-flex align-items-center gap-2">
				{{ option.label }}
				<button type="button" class="btn-close btn-close-white" :disabled="Boolean(field.disabled)"
					:aria-label="'Retirer ' + option.label" @click="remove(option.value)" />
			</span>
			<DropdownList v-if="!field.disabled && availableOptions.length" :items="availableOptions"
				field="label" :search-on-fields="['label']" variant="outline-secondary" @select="add">
				{{ field.ui?.addLabel ?? 'Ajouter une option' }}
			</DropdownList>
			<span v-if="!selectedOptions.length && !availableOptions.length" class="text-body-secondary small">Aucun groupe disponible.</span>
		</div>
		<select
			v-else
			:id="inputId"
			v-model="selection"
			:multiple="Boolean(field.multiple)"
			:size="field.ui?.size"
			:disabled="Boolean(field.disabled)"
			:class="['form-select', { 'is-invalid': invalid }]"
		>
			<option v-if="field.placeholder" :value="null" :disabled="field.required">{{ field.placeholder }}</option>
			<option v-for="option in field.options ?? []" :key="String(option.value)" :value="option.value" :disabled="option.disabled">
				{{ option.label }}
			</option>
		</select>
	</BaseField>
</template>

<script setup>
import { computed } from 'vue'
import DropdownList from '../../DropdownList.vue'
import BaseField from './BaseField.vue'

const props = defineProps({
	field: { type: Object, required: true },
	modelValue: { default: null },
	errors: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:modelValue'])

const selection = computed({
	get: () => props.modelValue,
	set: value => emit('update:modelValue', value),
})

const selectedOptions = computed(() => (props.field.options ?? [])
	.filter(option => (props.modelValue ?? []).includes(option.value)))
const availableOptions = computed(() => (props.field.options ?? [])
	.filter(option => !option.disabled && !(props.modelValue ?? []).includes(option.value)))

function add(option) {
	emit('update:modelValue', [...(props.modelValue ?? []), option.value])
}

function remove(value) {
	emit('update:modelValue', (props.modelValue ?? []).filter(item => item !== value))
}
</script>
