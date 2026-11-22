<template>
	<template v-if="selected.kind === 'tab'">
		<LocalizedTextField v-model="node.label" label="Libellé de l’onglet" :locales="store.currentSpaceLanguages" :locale="store.previewLocale"
			:space-id="store.current.space_id" :disabled="disabled" />
		<div class="page-field"><label>Icône facultative</label><icon_picker v-model="node.icon" :disabled="disabled" /></div>
		<button type="button" class="btn btn-outline-primary btn-sm" @click="store.selectNode(selected.parentId)">Réglages du composant Tabs</button>
		<p class="small text-muted mt-2">Ajoutez des composants depuis le catalogue ou glissez-les dans le corps de cet onglet.</p>
	</template>
	<template v-else>
		<label class="page-field">Style
			<select v-model="node.props.mode" class="form-select form-select-sm"><option value="bootstrap">Bootstrap</option><option value="simple">Simple</option><option value="custom">Personnalisé</option></select>
		</label>
		<label class="page-field">Onglet ouvert par défaut
			<select :value="node.props.default_tab ?? ''" class="form-select form-select-sm" @change="setDefault($event.target.value)">
				<option value="">Premier onglet</option>
				<option v-for="(tab, index) in node.zones.tabs" :key="tab.id" :value="tab.id">{{ text(tab.label) || `Onglet ${index + 1}` }}</option>
			</select>
		</label>
		<div v-for="(tab, index) in node.zones.tabs" :key="tab.id" class="d-flex gap-1 mb-2 align-items-center">
			<button type="button" class="btn btn-outline-primary btn-sm flex-grow-1 text-start" @click="store.selectNode(tab.id)">
				<i v-if="tab.icon" :class="tab.icon" aria-hidden="true"></i> {{ text(tab.label) || `Onglet ${index + 1}` }}
			</button>
			<button type="button" class="btn btn-outline-secondary btn-sm" title="Monter" :disabled="index === 0" @click="store.moveSibling(tab.id, -1)">↑</button>
			<button type="button" class="btn btn-outline-secondary btn-sm" title="Descendre" :disabled="index === node.zones.tabs.length - 1" @click="store.moveSibling(tab.id, 1)">↓</button>
			<button type="button" class="btn btn-outline-secondary btn-sm" title="Dupliquer l’onglet et son contenu" :disabled="!store.canDuplicateNode(tab.id)" @click="store.duplicateNode(tab.id)"><i class="fa-solid fa-copy" aria-hidden="true"></i></button>
			<button type="button" class="btn btn-outline-danger btn-sm" title="Supprimer l’onglet et son contenu" :disabled="!store.canRemoveNode(tab.id)" @click="removeTab(tab)">×</button>
		</div>

		<PageContainerStyleFields v-if="node.props.mode === 'custom'" :style="style" :disabled="disabled" @change="setStyle" />
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
		style() { return this.node.props.tabs_style ?? {} },
	},
	methods: {
		text(value) { return selectLocalizedText(value, this.store.previewLocale) },
		setDefault(value) {
			if (value) this.node.props.default_tab = value
			else delete this.node.props.default_tab
		},
		setStyle(key, value) {
			if (!this.node.props.tabs_style) this.node.props.tabs_style = {}
			if (value.trim()) this.node.props.tabs_style[key] = value.trim()
			else delete this.node.props.tabs_style[key]
		},
		async removeTab(tab) {
			const parentId = this.node.id
			const removed = await this.store.requestRemoveNode(tab.id, message => this.askYesNoAsync(message), { confirmEmpty: true })
			if (removed) this.store.selectNode(parentId)
		},
	},
}
</script>
