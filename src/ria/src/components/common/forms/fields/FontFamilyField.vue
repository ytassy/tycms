<template>
	<BaseField :field="field" :errors="errors" v-slot="{ inputId, invalid }">
		<label :for="`${inputId}-search`" class="visually-hidden">Rechercher une police installée</label>
		<input :id="`${inputId}-search`" v-model="search" type="search" class="form-control mb-2"
			placeholder="Rechercher une police installée…" :disabled="Boolean(field.disabled)" />
		<select :id="inputId" v-model="selection" :class="['form-select', { 'is-invalid': invalid }]" :disabled="Boolean(field.disabled)">
			<option v-for="option in filteredOptions" :key="String(option.value)" :value="option.value" :disabled="option.disabled">{{ option.label }}</option>
		</select>
		<div v-if="selectedOption" class="border rounded bg-body-tertiary p-3 mt-2">
			<div class="small text-body-secondary mb-2">Aperçu — {{ selectedOption.label }}</div>
			<div v-if="previewError" class="text-danger small" role="alert">{{ previewError }}</div>
			<div v-else-if="loading" class="small text-body-secondary" role="status">Chargement de la police…</div>
			<div v-else :style="{ fontFamily: selectedOption.data?.fontFamily || 'inherit', fontSize: '20px', lineHeight: 1.5 }">
				<div>{{ sample }}</div>
				<div><strong>Texte en gras</strong> · <em>Texte en italique</em></div>
			</div>
		</div>
		<p v-if="!filteredOptions.length" class="form-text mb-0">Aucune police ne correspond à cette recherche.</p>
	</BaseField>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import BaseField from './BaseField.vue'
import { FONT_PREVIEW_SAMPLE, loadFontPreview, matchingFonts } from './fontPreview.js'

const props = defineProps({
	field: { type: Object, required: true },
	modelValue: { default: 'system' },
	errors: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])
const search = ref('')
const loading = ref(false)
const previewError = ref('')
const mounted = ref(false)
const sample = FONT_PREVIEW_SAMPLE
let previewVersion = 0

const selection = computed({
	get: () => props.modelValue,
	set: value => emit('update:modelValue', value),
})
const selectedOption = computed(() => (props.field.options ?? []).find(option => option.value === props.modelValue))
const filteredOptions = computed(() => matchingFonts(props.field.options ?? [], search.value, props.modelValue))

watch([selectedOption, mounted], async ([option, ready]) => {
	const version = ++previewVersion
	previewError.value = ''
	loading.value = false
	if (!ready || !option) return

	loading.value = true
	try {
		await loadFontPreview(option, sample)
	} catch (error) {
		if (version === previewVersion) previewError.value = error.message || 'L’aperçu est indisponible.'
	} finally {
		if (version === previewVersion) loading.value = false
	}
}, { immediate: true })

onMounted(() => { mounted.value = true })
onBeforeUnmount(() => { previewVersion++ })
</script>
