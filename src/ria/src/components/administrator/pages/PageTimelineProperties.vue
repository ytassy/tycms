<template>
	<template v-if="selected.kind !== 'item'">
		<LocalizedTextField v-model="node.label" label="Libellé de l’étape (phase, date…)" :locales="store.currentSpaceLanguages"
			:locale="store.previewLocale" :space-id="store.current.space_id" :disabled="disabled" />
		<div class="page-field"><label>Icône du repère</label><icon_picker v-model="node.icon" :disabled="disabled" /></div>
		<p class="small text-muted">Glissez vos images, titres, textes et boutons dans cette étape. Son onglet Style règle son contenu.</p>
		<button type="button" class="btn btn-outline-primary btn-sm" @click="store.selectNode(selected.parentId)">Réglages de la timeline</button>
	</template>
	<template v-else>
		<label class="page-field">Disposition des cartes
			<select v-model="node.props.layout" class="form-select form-select-sm">
				<option value="alternate">Alternée gauche / droite</option><option value="right">À droite de l’axe</option><option value="left">À gauche de l’axe</option>
			</select>
		</label>
		<label class="page-field">Position du nom de l’étape
			<select v-model="node.props.label_position" class="form-select form-select-sm">
				<option value="above">Au-dessus de la carte</option><option value="opposite">De l’autre côté du repère</option>
			</select>
			<small class="text-muted">Sur petit écran, le nom revient au-dessus de la carte.</small>
		</label>
		<label class="page-field">Passage sur une colonne en dessous de
			<select v-model="node.props.mobile_breakpoint" class="form-select form-select-sm">
				<option value="sm">576 px</option><option value="md">768 px</option><option value="lg">992 px</option>
			</select>
		</label>
		<label class="page-field">Repères
			<select v-model="node.props.marker" class="form-select form-select-sm">
				<option value="dot">Points</option><option value="number">Numéros automatiques</option><option value="icon">Icônes des étapes</option>
			</select>
		</label>
		<label class="page-field">Trait de l’axe
			<select v-model="node.props.line_style" class="form-select form-select-sm">
				<option value="solid">Continu</option><option value="dashed">Tirets</option><option value="dotted">Points</option>
			</select>
		</label>
		<label class="page-field"><input v-model="node.props.show_pointer" type="checkbox" class="form-check-input me-2">Pointe des cartes vers l’axe</label>
		<p class="small text-muted">{{ node.zones.steps.length }} / 100 étapes. Chaque étape contient librement vos composants.</p>
		<div v-for="(step, index) in node.zones.steps" :key="step.id" class="d-flex gap-1 mb-2">
			<button type="button" class="btn btn-outline-primary btn-sm flex-grow-1 text-start" @click="store.selectNode(step.id)">{{ text(step.label) || `Étape ${index + 1}` }}</button>
			<button type="button" class="btn btn-outline-secondary btn-sm" title="Monter" :disabled="disabled || index === 0" @click="store.moveSibling(step.id, -1)">↑</button>
			<button type="button" class="btn btn-outline-secondary btn-sm" title="Descendre" :disabled="disabled || index === node.zones.steps.length - 1" @click="store.moveSibling(step.id, 1)">↓</button>
			<button type="button" class="btn btn-outline-secondary btn-sm" title="Dupliquer l’étape" :disabled="disabled || !store.canDuplicateNode(step.id)" @click="store.duplicateNode(step.id)"><i class="fa-solid fa-copy" aria-hidden="true"></i></button>
			<button type="button" class="btn btn-outline-danger btn-sm" title="Supprimer l’étape" :disabled="disabled || !store.canRemoveNode(step.id)" @click="removeStep(step)">×</button>
		</div>
		<PageCollectionStyleFields v-model="node.props.timeline_style" :fields="timelineStyleFields" :disabled="disabled" />
	</template>
</template>
<script>
import LocalizedTextField from '../../common/LocalizedTextField.vue'
import { selectLocalizedText } from '../../common/localizedText.js'
import PageCollectionStyleFields from './PageCollectionStyleFields.vue'
import { timelineStyleFields } from '../../common/pageCollectionStyle.js'

export default {
	components: { LocalizedTextField, PageCollectionStyleFields },
	props: { selected: Object, node: Object, context: Object },
	data: () => ({ timelineStyleFields }),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		disabled() { return !this.store.current.canEdit },
	},
	methods: {
		text(value) { return selectLocalizedText(value, this.store.previewLocale) },
		async removeStep(step) {
			const parentId = this.node.id
			if (await this.store.requestRemoveNode(step.id, message => this.askYesNoAsync(message), { confirmEmpty: true })) this.store.selectNode(parentId)
		},
	},
}
</script>
