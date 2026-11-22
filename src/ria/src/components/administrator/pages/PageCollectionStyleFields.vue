<template>
	<details class="mt-3">
		<summary class="page-panel-heading">Personnaliser l’apparence</summary>
		<template v-for="field in fields" :key="field">
			<div v-if="!collectionSizes[field] && field !== 'shadow'" class="page-field">
				<label>{{ labels[field] }}</label>
				<PageColorInput :model-value="modelValue[field] ?? ''" size="sm" allow-short-hex :disabled="disabled" @update:model-value="set(field, $event)" />
			</div>
			<label v-else-if="collectionSizes[field]" class="page-field">{{ labels[field] }} (px)
				<input type="number" class="form-control form-control-sm" :value="modelValue[field] ?? ''" :min="collectionSizes[field][0]"
					:max="collectionSizes[field][1]" step="1" :disabled="disabled" placeholder="Par défaut" @change="setNumber(field, $event)">
			</label>
			<label v-else class="page-field">Ombre
				<select :value="modelValue.shadow ?? ''" class="form-select form-select-sm" :disabled="disabled" @change="set(field, $event.target.value)">
					<option value="">Par défaut</option><option value="none">Aucune</option><option value="soft">Légère</option><option value="medium">Marquée</option>
				</select>
			</label>
		</template>
	</details>
</template>
<script>
import PageColorInput from './PageColorInput.vue'
import { collectionSizes } from '../../common/pageCollectionStyle.js'

export default {
	components: { PageColorInput },
	props: { modelValue: { type: Object, default: () => ({}) }, fields: Array, disabled: Boolean },
	emits: ['update:modelValue'],
	data: () => ({ collectionSizes, labels: {
		background_color: 'Fond des cartes', color: 'Texte', border_color: 'Bordure', accent_color: 'Étoiles et navigation',
		author_color: 'Auteur', muted_color: 'Fonction / entreprise', line_color: 'Axe', marker_color: 'Repères', label_color: 'Libellés des étapes',
		gap: 'Espacement', padding: 'Marge intérieure des cartes', radius: 'Arrondis', border_width: 'Épaisseur de bordure',
		portrait_size: 'Taille du portrait', text_size: 'Taille du témoignage', author_size: 'Taille du nom',
		line_width: 'Épaisseur de l’axe', marker_size: 'Taille des repères', label_size: 'Taille des libellés',
	} }),
	methods: {
		set(key, value) {
			if (this.disabled) return
			const next = { ...this.modelValue }
			if (value === '') delete next[key]
			else next[key] = value
			this.$emit('update:modelValue', next)
		},
		setNumber(key, event) {
			const value = event.target.value
			const [min, max] = collectionSizes[key]
			if (value === '') this.set(key, '')
			else if (/^\d+$/.test(value) && Number(value) >= min && Number(value) <= max) this.set(key, Number(value))
			else event.target.value = this.modelValue[key] ?? ''
		},
	},
}
</script>
