<template>
	<div class="page-panel-content">
		<h2 class="h5 mb-3">Propriétés</h2>
		<p v-if="!selected" class="text-muted small">Sélectionnez un élément dans l’aperçu ou l’arborescence.</p>
		<template v-else>
			<div class="d-flex align-items-center gap-2 mb-3">
				<strong class="flex-grow-1">{{ label }}</strong>
				<small class="text-muted page-node-id">{{ selected.node.id }}</small>
			</div>
			<label class="page-field d-flex align-items-center gap-2 mb-3">
				<input type="checkbox" class="form-check-input" :checked="!isNodeHidden(selected.node)" :disabled="!store.current.canEdit"
					@change="store.setNodeVisibility(selected.node.id, $event.target.checked)">
				Afficher le composant
				<span v-if="isNodeHidden(selected.node)" class="page-node-hidden-badge">Masqué</span>
			</label>
			<tabs :key="selected.node.id">
				<!-- Les slots peuvent être réévalués pendant le démontage, après la perte de sélection. -->
				<tab label="Général" icon="fa-solid fa-pen"><PagePropertiesGeneral v-if="selected" :selected="selected" /></tab>
				<tab label="Styles" icon="fa-solid fa-palette"><PagePropertiesStyle v-if="selected" :node="selected.node" /></tab>
				<tab label="Avancé" icon="fa-solid fa-gear"><PagePropertiesAdvanced v-if="selected" :selected="selected" /></tab>
			</tabs>
		</template>
	</div>
</template>

<script>
import PagePropertiesGeneral from './PagePropertiesGeneral.vue'
import PagePropertiesStyle from './PagePropertiesStyle.vue'
import PagePropertiesAdvanced from './PagePropertiesAdvanced.vue'
import { isNodeHidden } from './pageVisibility'
import { nodeLabel } from './pageEditorModel'

export default {
	components: { PagePropertiesGeneral, PagePropertiesStyle, PagePropertiesAdvanced },
	methods: { isNodeHidden },
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		selected() { return this.store.selected },
		label() { return this.selected ? nodeLabel(this.selected.node, this.store.components, this.selected.kind) : '' },
	},
}
</script>
