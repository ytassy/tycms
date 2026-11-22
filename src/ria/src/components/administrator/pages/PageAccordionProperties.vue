<template>
	<template v-if="selected.kind === 'panel'">
		<LocalizedTextField v-model="node.label" label="Titre du panneau" :locales="store.currentSpaceLanguages" :locale="store.previewLocale"
			:space-id="store.current.space_id" :disabled="disabled" />
		<div class="page-field"><label>Icône facultative</label><icon_picker v-model="node.icon" :disabled="disabled" /></div>
		<button type="button" class="btn btn-outline-primary btn-sm" @click="store.selectNode(selected.parentId)">Réglages de l’accordéon</button>
		<p class="small text-muted mt-2">Ajoutez des composants depuis le catalogue ou glissez-les dans le corps du panneau.</p>
	</template>
	<template v-else>
		<label class="page-field">Style
			<select v-model="node.props.mode" class="form-select form-select-sm"><option value="bootstrap">Bootstrap</option><option value="simple">Simple</option><option value="custom">Personnalisé</option></select>
		</label>
		<label class="page-field d-flex gap-2 align-items-center">
			<input v-model="showArrow" type="checkbox" class="form-check-input"> Afficher la flèche
		</label>
		<div v-if="showArrow" class="page-field">
			<label>Icône de la flèche</label><icon_picker v-model="node.props.arrow_icon" placeholder="Chevron par défaut" :disabled="disabled" />
			<label class="page-field mt-2">Position de la flèche
				<select :value="node.props.arrow_position ?? 'right'" class="form-select form-select-sm" @change="node.props.arrow_position = $event.target.value">
					<option value="left">À gauche</option><option value="right">À droite</option>
				</select>
			</label>
			<label class="page-field">Rotation à l’ouverture (degrés)
				<input :value="node.props.arrow_rotation ?? 90" type="number" min="-360" max="360" step="1" class="form-control form-control-sm" @input="setArrowRotation($event.target.value)">
				<small class="text-muted">0° : fixe. Valeurs négatives : rotation dans l’autre sens.</small>
			</label>
		</div>
		<label class="page-field d-flex gap-2 align-items-center">
			<input type="checkbox" class="form-check-input" :checked="node.props.single_open !== false" @change="setSingleOpen($event.target.checked)"> Un seul panneau ouvert à la fois
		</label>
		<p class="small text-muted">Cochez les panneaux ouverts au départ. Aucun coché : tous fermés.</p>
		<div v-for="(panel, index) in node.zones.panels" :key="panel.id" class="d-flex gap-1 mb-2 align-items-center">
			<input type="checkbox" class="form-check-input me-1" :checked="(node.props.open_panels ?? []).includes(panel.id)" :aria-label="`Ouvrir au départ : ${text(panel.label) || index + 1}`" @change="setInitiallyOpen(panel.id, $event.target.checked)">
			<button type="button" class="btn btn-outline-primary btn-sm flex-grow-1 text-start" @click="store.selectNode(panel.id)">
				<i v-if="panel.icon" :class="panel.icon" aria-hidden="true"></i> {{ text(panel.label) || `Panneau ${index + 1}` }}
			</button>
			<button type="button" class="btn btn-outline-secondary btn-sm" title="Monter" :disabled="index === 0" @click="store.moveSibling(panel.id, -1)">↑</button>
			<button type="button" class="btn btn-outline-secondary btn-sm" title="Descendre" :disabled="index === node.zones.panels.length - 1" @click="store.moveSibling(panel.id, 1)">↓</button>
			<button type="button" class="btn btn-outline-secondary btn-sm" title="Dupliquer le panneau et son contenu" :disabled="!store.canDuplicateNode(panel.id)" @click="store.duplicateNode(panel.id)"><i class="fa-solid fa-copy" aria-hidden="true"></i></button>
			<button type="button" class="btn btn-outline-danger btn-sm" title="Supprimer le panneau et son contenu" :disabled="!store.canRemoveNode(panel.id)" @click="removePanel(panel)">×</button>
		</div>

		<PageContainerStyleFields v-if="node.props.mode === 'custom'" :style="node.props.accordion_style ?? {}" :disabled="disabled" accordion @change="setStyle" />
	</template>
</template>
<script>
import LocalizedTextField from '../../common/LocalizedTextField.vue'
import { selectLocalizedText } from '../../common/localizedText'
import PageContainerStyleFields from './PageContainerStyleFields.vue'

export default {
	components: { LocalizedTextField, PageContainerStyleFields },
	props: { context: Object, selected: { type: Object, required: true }, node: Object },
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		disabled() { return !this.store.current.canEdit },
		showArrow: {
			get() { return this.node.props.show_arrow !== false },
			set(value) { this.node.props.show_arrow = value },
		},
	},
	methods: {
		text(value) { return selectLocalizedText(value, this.store.previewLocale) },
		setArrowRotation(value) {
			if (value === '') delete this.node.props.arrow_rotation
			else this.node.props.arrow_rotation = Number(value)
		},
		setSingleOpen(value) {
			this.node.props.single_open = value
			if (value) {
				this.node.props.open_panels = (this.node.props.open_panels ?? []).slice(0, 1)
				if (this.store.previewAccordion[this.node.id]) this.store.previewAccordion[this.node.id] = this.store.previewAccordion[this.node.id].slice(0, 1)
			}
		},
		setInitiallyOpen(id, value) {
			const open = (this.node.props.open_panels ?? []).filter(panelId => panelId !== id)
			this.node.props.open_panels = value ? (this.node.props.single_open !== false ? [id] : [...open, id]) : open
			delete this.store.previewAccordion[this.node.id]
		},
		setStyle(key, value) {
			const style = this.node.props.accordion_style ??= {}
			if (value.trim()) style[key] = value.trim()
			else delete style[key]
		},
		async removePanel(panel) {
			const parentId = this.node.id
			const removed = await this.store.requestRemoveNode(panel.id, message => this.askYesNoAsync(message), { confirmEmpty: true })
			if (removed) this.store.selectNode(parentId)
		},
	},
}
</script>
