<template>
	<div :class="['form-field', condensed ? 'form-field--condensed' : 'row mb-3']">
		<label v-if="field.label && !labelInControl" :for="inputId" :class="['form-field__label', condensed ? 'form-label' : 'col-form-label']">
			{{ field.label }}
			<span v-if="field.required" class="text-danger" aria-hidden="true">*</span>
		</label>

		<div :class="condensed || field.label ? 'form-field__control' : 'col-12'">
			<slot :input-id="inputId" :invalid="errors.length > 0" />

			<div v-if="field.hint" class="form-text">{{ field.hint }}</div>
			<div v-if="field.description" class="form-text">{{ field.description }}</div>
			<div v-for="error in errors" :key="error" class="invalid-feedback d-block">{{ error }}</div>
		</div>
	</div>
</template>

<script setup>
import { computed, inject } from 'vue'
import { condensedFormKey } from '../formDisplay'

const props = defineProps({
	labelInControl: { type: Boolean, default: false },
	field: {
		type: Object,
		required: true,
	},
	errors: {
		type: Array,
		default: () => [],
	},
})

const condensed = inject(condensedFormKey, computed(() => false))
const inputId = computed(() => `form-field-${props.field.id ?? props.field.name}`)
</script>

<style scoped>
@media (min-width: 768px) {
	.form-field:not(.form-field--condensed) > .form-field__label {
		flex: 0 0 min(33.333333%, 350px);
		width: min(33.333333%, 350px);
	}

	.form-field:not(.form-field--condensed) > .form-field__control {
		flex: 1 0 0;
		width: auto;
	}
}

/* Le mode condensé utilise toute la largeur, même dans un panneau étroit. */
.form-field--condensed { margin-bottom: .9rem; font-size: .84rem; }
.form-field--condensed > .form-field__label {
	display: block;
	margin-bottom: .25rem;
	padding: 0;
	font-size: inherit;
	font-weight: 600;
}
.form-field--condensed > .form-field__control { width: 100%; min-width: 0; }
.form-field--condensed :deep(input.form-control),
.form-field--condensed :deep(textarea.form-control),
.form-field--condensed :deep(select.form-select) {
	min-height: calc(1.5em + .5rem + 2px);
	padding: .25rem .5rem;
	font-size: .875rem;
	border-radius: var(--bs-border-radius-sm, .25rem);
}
.form-field--condensed :deep(select.form-select:not([multiple])) { padding-right: 2.25rem; }
.form-field--condensed :deep(.form-check-label) { font-weight: 600; }
.form-field--condensed :deep(.localized-text-field) { margin-bottom: 0; }
</style>
