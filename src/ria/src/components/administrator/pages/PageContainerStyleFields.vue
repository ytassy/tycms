<template>
	<div>
			<p class="small text-muted">Réglages communs à toutes les sections. Champs vides : valeurs par défaut. Longueurs : 0, px, rem, em ou %.</p>
			<div v-for="group in colorGroups" :key="group.key">
				<h3 class="page-panel-heading">{{ accordion ? group.label.replace('Onglets', 'En-têtes').replace('Onglet actif', 'En-tête ouvert') : group.label }}</h3>
				<div v-for="field in group.fields" :key="field.key" class="page-field">
					<label :for="`page-tabs-${field.key}`">{{ field.label }}</label>
					<color_input :id="`page-tabs-${field.key}`" :model-value="style[field.key] ?? ''" size="sm" allow-short-hex :disabled="disabled" @update:model-value="$emit('change', field.key, $event)" />
				</div>
			</div>
			<template v-for="zone in ['tab', 'body']" :key="zone">
				<h3 class="page-panel-heading">{{ zone === 'tab' ? (accordion ? 'Dimensions des en-têtes' : 'Dimensions des onglets') : 'Dimensions du corps' }}</h3>
				<label class="page-field">Style de bordure
					<select :value="style[`${zone}_border_style`] ?? ''" class="form-select form-select-sm" @change="$emit('change', `${zone}_border_style`, $event.target.value)">
						<option value="">Par défaut</option><option value="none">Aucune</option><option value="solid">Pleine</option><option value="dashed">Tirets</option><option value="dotted">Pointillés</option><option value="double">Double</option>
					</select>
				</label>
				<div class="page-style-grid">
					<label v-for="field in boxFields" :key="field.key" class="page-field">{{ field.label }}
						<input :value="style[`${zone}_${field.key}`] ?? ''" class="form-control form-control-sm" placeholder="Par défaut" @input="$emit('change', `${zone}_${field.key}`, $event.target.value)">
					</label>
				</div>
			</template>
	</div>
</template>
<script>
import PageColorInput from './PageColorInput.vue'
import { tabsColorGroups, tabsBoxFields } from './pageTabsStyle'

export default {
	components: { color_input: PageColorInput },
	props: {
		style: { type: Object, default: () => ({}) },
		disabled: { type: Boolean, default: false },
		accordion: { type: Boolean, default: false },
	},
	emits: ['change'],
	data: () => ({ colorGroups: tabsColorGroups, boxFields: tabsBoxFields }),
}
</script>
