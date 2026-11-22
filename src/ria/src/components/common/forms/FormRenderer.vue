<template>
	<div class="dynamic-form" :class="{ 'dynamic-form--condensed': condensed }">
		<component :is="displayTabs ? 'tabs' : 'div'">
			<component
				:is="displayTabs ? 'tab' : 'div'"
				v-for="section in sections"
				:key="section.id"
				:label="displayTabs ? section.label || section.id : undefined"
				:icon="displayTabs ? '' : undefined"
			>
				<section class="dynamic-form__section">
					<header v-if="section.description" class="mb-3">
						<p class="text-body-secondary mb-0">{{ section.description }}</p>
					</header>
					<slot name="section-header" :section="section" />

					<div v-for="(field, fieldIndex) in section.fields" :key="field.name ?? `${section.id}-${fieldIndex}`" class="dynamic-form__field">
						<component
							:is="getFieldComponent(field)"
							v-if="isFieldVisible(field) && getFieldComponent(field)"
							:field="field"
							:model-value="field.name ? modelValue[field.name] : undefined"
							:context="context"
							:errors="getFieldErrors(field)"
							@update:model-value="updateField(field, $event)"
						/>
						<div v-else-if="isFieldVisible(field)" class="alert alert-danger mb-3">
							Le composant « {{ field.component }} » du champ « {{ field.name }} » n’est pas enregistré dans Vue.
						</div>
					</div>
					<slot name="section-footer" :section="section" />
				</section>
			</component>
		</component>
	</div>
</template>

<script setup>
import { computed, provide } from 'vue'
import { resolveFormFieldComponent } from './fieldRegistry'
import { condensedFormKey } from './formDisplay'

const props = defineProps({
	condensed: { type: Boolean, default: false },
	definition: {
		type: Object,
		required: true,
	},
	modelValue: {
		type: Object,
		default: () => ({}),
	},
	context: {
		type: Object,
		default: () => ({}),
	},
	errors: {
		type: Object,
		default: () => ({}),
	},
})

const emit = defineEmits(['update:modelValue'])

// Chaque formulaire fournit son propre mode, y compris lorsqu'ils sont imbriqués.
provide(condensedFormKey, computed(() => props.condensed))

const sections = computed(() => Array.isArray(props.definition.sections) ? props.definition.sections : [])
const displayTabs = computed(() => !(props.definition.hideSingleSectionTab === true && sections.value.length === 1))

function getFieldComponent(field) {
	return resolveFormFieldComponent(field.component)
}

function updateField(field, value) {
	if (!field.name || field.submittable === false) return

	emit('update:modelValue', {
		...props.modelValue,
		[field.name]: value,
	})
}

function getFieldErrors(field) {
	if (!field.name) return []

	const errors = props.errors[field.name]
	if (Array.isArray(errors)) return errors
	return typeof errors === 'string' ? [errors] : []
}

function isFieldVisible(field) {
	return evaluateCondition(field.visibleWhen, props.modelValue)
}

function evaluateCondition(condition, values) {
	if (!condition) return true
	if (Array.isArray(condition)) return condition.every((item) => evaluateCondition(item, values))
	if (typeof condition !== 'object') return false

	if (Array.isArray(condition.all)) return condition.all.every((item) => evaluateCondition(item, values))
	if (Array.isArray(condition.any)) return condition.any.some((item) => evaluateCondition(item, values))
	if (condition.not) return !evaluateCondition(condition.not, values)

	if (typeof condition.field !== 'string') return false

	const currentValue = values[condition.field]
	const operator = condition.operator ?? 'equals'
	const expectedValue = condition.value

	if (operator === 'equals') return currentValue === expectedValue
	if (operator === 'notEquals') return currentValue !== expectedValue
	if (operator === 'in') return Array.isArray(expectedValue) && expectedValue.includes(currentValue)
	if (operator === 'notIn') return Array.isArray(expectedValue) && !expectedValue.includes(currentValue)
	if (operator === 'truthy') return Boolean(currentValue)
	if (operator === 'falsy') return !currentValue

	return false
}
</script>
