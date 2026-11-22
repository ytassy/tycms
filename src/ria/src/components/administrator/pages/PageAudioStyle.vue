<template>
	<h3 class="page-panel-heading">Lecteur audio</h3>
	<label class="page-field">Hauteur minimale (px)
		<input type="number" min="44" max="200" step="1" :value="style.height ?? 56" class="form-control form-control-sm"
			@input="setHeight($event.target.value)">
	</label>
	<div v-for="field in colors" :key="field.id" class="page-field">
		<label :for="`page-audio-${field.id}`">{{ field.label }}</label>
		<color_input :id="`page-audio-${field.id}`" :model-value="style[field.id] ?? field.default" size="sm" allow-short-hex
			@update:model-value="setStyle(field.id, $event)" />
	</div>
	<label class="page-field">Arrondis du lecteur
		<input :value="style.border_radius ?? ''" class="form-control form-control-sm" placeholder="8px" @input="setStyle('border_radius', $event.target.value)">
	</label>
</template>
<script>
import PageColorInput from './PageColorInput.vue'
export default {
	components: { color_input: PageColorInput },
	props: { node: { type: Object, required: true } },
	data: () => ({ colors: [
		{ id: 'accent_color', label: 'Bouton de lecture et curseurs', default: '#0d6efd' },
		{ id: 'track_color', label: 'Pistes de progression et de volume', default: '#ced4da' },
		{ id: 'color', label: 'Durée et bouton de volume', default: '#212529' },
		{ id: 'background_color', label: 'Fond du lecteur', default: '#f1f3f5' },
	] }),
	computed: { style() { return this.node.props.audio_style ?? {} } },
	methods: {
		setHeight(value) {
			if (value === '') this.setStyle('height', '')
			else if (/^\d+$/.test(value) && Number(value) >= 44 && Number(value) <= 200) this.setStyle('height', Number(value))
		},
		setStyle(key, value) {
			if (!this.node.props.audio_style) this.node.props.audio_style = {}
			if (value === '') delete this.node.props.audio_style[key]
			else this.node.props.audio_style[key] = typeof value === 'string' ? value.trim() : value
		},
	},
}
</script>
