<template>
	<fieldset :disabled="!store.current.canEdit">
		<h3 class="page-panel-heading">Visibilité</h3>
		<div class="page-field">Rôles visibles
			<div class="mt-1"><checkboxes_dropdown :model-value="common.role_ids ?? []" :options="roleOptions"
				:read-only="!store.current.canEdit" empty-label="Visible pour tous" add-label="Ajouter un rôle"
				@update:model-value="setCommon('role_ids', $event.map(Number))" /></div>
			<small class="text-muted">Aucun rôle : visible pour tous. Un rôle sélectionné suffit.</small>
		</div>
		<div class="page-field">Langues visibles
			<div class="mt-1"><checkboxes_dropdown :model-value="common.languages ?? []" :options="languageOptions"
				:read-only="!store.current.canEdit" empty-label="Toutes les langues" add-label="Ajouter une langue"
				@update:model-value="setCommon('languages', $event)" /></div>
			<small class="text-muted">Aucune langue sélectionnée : visible dans toutes les langues actives. Un parent masqué masque aussi ses enfants.</small>
		</div>
		<div class="page-field">Masquer aux tailles
			<div class="mt-1"><checkboxes_dropdown :model-value="common.hidden_at ?? []" :options="hiddenOptions"
				:read-only="!store.current.canEdit" empty-label="Aucune taille masquée" add-label="Masquer à une taille"
				@update:model-value="setCommon('hidden_at', $event)" /></div>
		</div>
		<h3 class="page-panel-heading mt-3">Technique</h3>
		<label class="page-field">Classe CSS
			<input :value="common.class ?? ''" class="form-control form-control-sm" @input="setCommon('class', $event.target.value)">
		</label>
		<h3 class="page-panel-heading mt-3">Déplacer</h3>
		<div class="d-flex gap-2 mb-2">
			<button type="button" class="btn btn-outline-secondary btn-sm" :disabled="selected.index === 0" @click="store.moveSibling(node.id, -1)">↑ Monter</button>
			<button type="button" class="btn btn-outline-secondary btn-sm" :disabled="selected.index === selected.siblings.length - 1" @click="store.moveSibling(node.id, 1)">↓ Descendre</button>
		</div>
		<label class="page-field">Déplacer dans
			<select v-model="destination" class="form-select form-select-sm">
				<option value="">Choisir une destination</option>
				<option v-for="option in destinations" :key="option.value" :value="option.value">{{ option.label }}</option>
			</select>
		</label>
		<button type="button" class="btn btn-outline-secondary btn-sm mb-3" :disabled="!destination" @click="moveToDestination">Déplacer à la fin</button>
		<div class="d-flex flex-wrap gap-2">
			<button type="button" class="btn btn-outline-secondary btn-sm" :disabled="!store.canDuplicateNode(node.id)" @click="store.duplicateNode(node.id)">Dupliquer cet élément</button>
			<button type="button" class="btn btn-outline-danger btn-sm" :disabled="!store.canRemoveNode(node.id)" @click="remove">Supprimer cet élément</button>
		</div>
	</fieldset>
</template>

<script>
import { collectNodes, placeNode } from './pageEditorModel'

export default {
	props: { selected: { type: Object, required: true } },
	data: () => ({ destination: '', hiddenOptions: { sm: 'Petit écran', md: 'Tablette', lg: 'Bureau', xl: 'Grand écran', xxl: 'Très grand écran' } }),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		node() { return this.selected.node },
		common() { return this.node.props.common ?? {} },
		roleOptions() { return Object.fromEntries((this.store.current.roles ?? []).map(role => [role.id, role.name])) },
		languageOptions() {
			const configured = this.store.currentSpaceConfiguredLanguages
			const selected = Array.isArray(this.common.languages) ? this.common.languages : []
			const locales = [...new Set([...configured, ...selected])]

			return Object.fromEntries(locales.map(locale => [
				locale,
				!configured.includes(locale) ? `${locale} (non configurée)`
					: this.store.currentSpaceLanguages.includes(locale) ? locale : `${locale} (désactivée)`,
			]))
		},
		destinations() {
			const document = this.store.current.data
			const nodes = collectNodes(document)
			const candidates = ['tab', 'panel'].includes(this.selected.kind)
				? [] : this.selected.kind === 'column'
				? nodes.filter(entry => entry.node.type === 'core.row').map(entry => ({
					kind: 'columns', parentId: entry.node.id, index: entry.node.zones.columns.length,
					label: `Ligne ${entry.node.id}`,
				}))
				: [
					...(this.node.type === 'core.row' ? [{ kind: 'items', parentId: 'page', index: document.items.length, label: 'Racine de la page' }] : []),
					...nodes.filter(entry => !entry.node.type && entry.node.items).map(entry => ({
						kind: 'items', parentId: entry.node.id, index: entry.node.items.length,
						label: `${entry.node.label !== undefined ? 'Section' : 'Colonne'} ${entry.node.id}`,
					})),
				]
			return candidates.filter(target => placeNode(document, { kind: 'node', id: this.node.id }, target, this.store.components))
				.map(target => ({ ...target, value: `${target.kind}:${target.parentId}` }))
		},
	},
	methods: {
		setCommon(field, value) {
			if (!this.node.props.common) this.node.props.common = {}
			this.node.props.common[field] = value
		},
		moveToDestination() {
			const target = this.destinations.find(option => option.value === this.destination)
			if (target) this.store.moveNode(this.node.id, target)
			this.destination = ''
		},
		remove() {
			return this.store.requestRemoveNode(this.node.id, message => this.askYesNoAsync(message))
		},
	},
}
</script>
