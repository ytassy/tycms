<template>
	<div class="page-panel-content">
		<h2 class="h5 mb-3">Paramètres de la page</h2>
		<fieldset :disabled="!current.canEdit">
			<label class="page-field">Espace
				<select v-model.number="current.space_id" class="form-select form-select-sm" :disabled="current.id != null" @change="spaceChanged">
					<option v-for="space in editableSpaces" :key="space.id" :value="space.id">{{ space.title }}</option>
				</select>
			</label>
			<label class="page-field">Nom
				<input v-model="current.name" maxlength="100" class="form-control form-control-sm" required>
			</label>
			<label class="page-field">Description
				<textarea v-model="current.description" class="form-control form-control-sm" rows="3" maxlength="5000" />
			</label>
			<label class="page-field">Type
				<select :value="current.type" class="form-select form-select-sm" @change="store.setPageType($event.target.value)">
					<option :value="2">Éditeur visuel</option>
					<option v-if="acl.isRoot()" :value="1">HTML brut</option>
				</select>
			</label>
			<label v-if="current.type === 2" class="form-check mb-3">
				<input v-model="current.data.container.fluid" type="checkbox" class="form-check-input"> Largeur fluide
			</label>
			<div v-if="current.type === 1" class="page-field">HTML brut
				<code_editor v-model="current.raw_html" syntax="html" :read-only="!current.canEdit" aria-label="HTML brut" class="page-code-editor" />
				<small class="text-muted">Le HTML est nettoyé à l’enregistrement et dans l’aperçu ; les scripts et les balises non autorisées sont supprimés.</small>
			</div>
			<div class="page-field">SCSS personnalisé
				<code_editor v-model="current.raw_scss" syntax="scss" :read-only="!current.canEdit" aria-label="SCSS personnalisé" class="page-code-editor" />
				<small class="text-muted">Styles limités à cette page</small>
			</div>
		</fieldset>
	</div>
</template>

<script>

export default {
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		current() { return this.store.current },
		editableSpaces() { return this.store.spaces.filter(space => space.canEdit || space.id === this.current.space_id) },
	},
	methods: {
		async spaceChanged() {
			const space = this.store.spaces.find(space => space.id === this.current.space_id)

			// Le brouillon d'une nouvelle page adopte immédiatement la langue de
			// son nouveau Space pour garder les deux aperçus cohérents.
			this.current.roles = space?.roles ?? []
			this.store.previewLocale = space?.defaultLocale ?? 'fr'
			await this.store.loadEditorContext(this.current.space_id)
		},
	},
}
</script>
