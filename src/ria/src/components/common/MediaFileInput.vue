<template>
	<div class="d-flex gap-2 align-items-start">
		<input :id="id" class="form-control" :class="{ 'form-control-sm': size === 'sm', 'is-invalid': invalid }"
			:value="modelValue ?? ''" :readonly="!allowManualInput || readonly" :disabled="disabled"
			:placeholder="allowManualInput ? 'URL ou chemin du fichier' : 'Aucun média sélectionné'"
			@input="$emit('update:modelValue', $event.target.value)" />
		<button type="button" class="btn btn-outline-primary flex-shrink-0" :class="{ 'btn-sm': size === 'sm' }"
			:disabled="disabled || pickerDisabled || readonly || !spaceId" @click="pickerVisible = true">Choisir</button>
		<button v-if="modelValue" type="button" class="btn btn-outline-secondary flex-shrink-0" :class="{ 'btn-sm': size === 'sm' }"
			:disabled="disabled || readonly" @click="$emit('update:modelValue', '')">Effacer</button>
	</div>
	<img v-if="mediaType === 'image' && (modelValue?.startsWith('/medias/') || modelValue?.startsWith('/assets/') || modelValue?.startsWith('/assets_core_'))"
		class="media-file-input__preview mt-2" :src="modelValue" alt="Aperçu du média sélectionné" />
	<p v-if="allowManualInput && manualInputMode === 'media'" class="form-text">Saisissez l’URL d’un média de cet espace.</p>
	<p v-if="!spaceId" class="form-text">Sélectionnez d’abord un espace.</p>
	<MediaPicker :visible="pickerVisible" :space-id="spaceId" :media-type="mediaType" @select="choose" @close="pickerVisible = false" />
</template>

<script>
import MediaPicker from './MediaPicker.vue'

export default {
	components: { MediaPicker },
	props: {
		id: { type: String, default: null },
		modelValue: { type: String, default: '' },
		spaceId: { type: [Number, String], default: null },
		mediaType: { type: String, default: 'all' },
		allowManualInput: { type: Boolean, default: true },
		manualInputMode: { type: String, default: 'media' },
		readonly: { type: Boolean, default: false },
		disabled: { type: Boolean, default: false },
		pickerDisabled: { type: Boolean, default: false },
		invalid: { type: Boolean, default: false },
		size: { type: String, default: 'md' },
	},
	emits: ['update:modelValue'],
	data: () => ({ pickerVisible: false }),
	methods: {
		choose(entry) { this.$emit('update:modelValue', entry.url) },
	},
}
</script>

<style scoped>
.media-file-input__preview { display: block; max-width: 220px; max-height: 100px; object-fit: contain; }
</style>
