<template>
	<h1>Espaces</h1>
	<div v-if="spaces !== null" class="max-content">
		<div class="d-flex justify-content-end mb-2">
			<button v-if="canCreate" type="button" class="btn btn-primary btn-sm" :disabled="saving || languageSaving || navigationSaving" @click="openCreate">
				<i class="fa-solid fa-plus" aria-hidden="true"></i> Ajouter un espace
			</button>
		</div>

		<data-table
			:fields="fields"
			:items="spaces"
			:pagination-enabled="true"
			:row-hover="true"
			default-sort-key="title"
		>
			<template #cell_hosts="{ item }">{{ item.hosts?.join(', ') || '—' }}</template>
			<template #cell_actions="{ item }">
				<button type="button" class="btn btn-outline-primary btn-sm" :disabled="saving || languageSaving || navigationSaving || !item.can_edit" title="Modifier" @click="openEdit(item)">
					<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i><span class="visually-hidden">Modifier</span>
				</button>
			</template>
		</data-table>

		<section class="card mt-4" aria-labelledby="space-languages-heading">
			<div class="card-body">
				<h2 id="space-languages-heading" class="h5 card-title">Langues par espace</h2>
				<p class="text-body-secondary small">Un nouvel espace démarre en français. Choisissez-le ici pour ajouter d’autres langues.</p>
				<div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Espace à configurer">
					<button v-for="space in spaces" :key="space.id" type="button"
						:class="['btn', 'btn-sm', 'rounded-pill', space.id === selectedLanguageSpaceId ? 'btn-primary' : 'btn-outline-primary']"
						:aria-pressed="space.id === selectedLanguageSpaceId" :disabled="languageSaving || navigationSaving"
						@click="selectLanguageSpace(space.id)">{{ space.title || `Espace #${space.id}` }}</button>
				</div>
				<SpaceLanguagesEditor v-if="selectedLanguageSpace" :key="selectedLanguageSpace.id" :space-id="selectedLanguageSpace.id" :busy="saving || navigationSaving" @busy-change="languageSaving = $event" @dirty-change="languagesDirty = $event" @saved="$refs.navigationEditor?.refreshLanguages()" />
			</div>
		</section>

		<section class="card mt-4" aria-labelledby="space-navigation-heading">
			<div class="card-body">
				<h2 id="space-navigation-heading" class="h5 card-title">Navigation</h2>
				<p class="text-body-secondary small">Choisissez l’espace, puis associez son accueil général et ses accueils par langue aux entrées de menu.</p>
				<div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Espace pour la navigation">
					<button v-for="space in spaces" :key="space.id" type="button"
						:class="['btn', 'btn-sm', 'rounded-pill', space.id === selectedLanguageSpaceId ? 'btn-primary' : 'btn-outline-primary']"
						:aria-pressed="space.id === selectedLanguageSpaceId" :disabled="languageSaving || navigationSaving"
						@click="selectLanguageSpace(space.id)">{{ space.title || `Espace #${space.id}` }}</button>
				</div>
				<SpaceNavigationEditor v-if="selectedLanguageSpace" :key="selectedLanguageSpace.id" ref="navigationEditor" :space-id="selectedLanguageSpace.id" :busy="saving || languageSaving" @busy-change="navigationSaving = $event" @dirty-change="navigationDirty = $event" />
			</div>
		</section>

		<SpaceEditor
			:visible="editorVisible"
			:space="editingSpace"
			:saving="saving"
			@close="closeEditor"
			@save="saveSpace"
			@delete="deleteSpace"
		/>
	</div>
</template>

<script>
import SpaceEditor from './editor.vue'
import SpaceLanguagesEditor from './SpaceLanguagesEditor.vue'
import SpaceNavigationEditor from './SpaceNavigationEditor.vue'

export default {
	components: { SpaceEditor, SpaceLanguagesEditor, SpaceNavigationEditor },

	data() {
		return {
			fields: [
				{ key: 'title', label: 'Nom', sortable: true, filter: 'search' },
				{ key: 'parent_title', label: 'Espace parent', sortable: true, filter: 'search' },
				{ key: 'template', label: 'Template', sortable: true, filter: 'search' },
				{ key: 'hosts', label: 'Hôtes', sortable: false, filter: 'search' },
				{ key: 'actions', label: '', class: 'text-end' },
			],
			editorVisible: false,
			editingSpaceId: null,
			selectedLanguageSpaceId: null,
			languageSaving: false,
			languagesDirty: false,
			navigationSaving: false,
			navigationDirty: false,
			saving: false,
		}
	},

	computed: {
		adminSpacesStore() { return this.stores.useAdminSpacesStore() },
		spaces() {
			const titles = Object.fromEntries((this.adminSpacesStore.spaces ?? []).map(space => [space.id, space.title]))
			return (this.adminSpacesStore.spaces ?? []).map(space => ({
				...space,
				parent_title: space.parent_id === null ? '—' : (titles[space.parent_id] ?? `Espace #${space.parent_id}`),
			}))
		},
		canCreate() { return this.spaces.some(space => space.can_admin) },
		editingSpace() { return this.spaces.find(space => space.id === this.editingSpaceId) ?? null },
		selectedLanguageSpace() { return this.spaces.find(space => space.id === this.selectedLanguageSpaceId) ?? null },
	},

	async mounted() {
		await this.adminSpacesStore.loadSpaces()
		this.selectedLanguageSpaceId = this.spaces[0]?.id ?? null
	},

	methods: {
		async selectLanguageSpace(spaceId) {
			if (this.languageSaving || this.navigationSaving || spaceId === this.selectedLanguageSpaceId) return
			if ((this.languagesDirty || this.navigationDirty) && !await this.askYesNoAsync('Abandonner les modifications non enregistrées ?')) return
			this.languagesDirty = false
			this.navigationDirty = false
			this.selectedLanguageSpaceId = spaceId
		},
		openCreate() { if (this.languageSaving || this.navigationSaving) return; this.editingSpaceId = null; this.editorVisible = true },
		openEdit(space) { if (!this.languageSaving && !this.navigationSaving && space.can_edit) { this.editingSpaceId = space.id; this.editorVisible = true } },
		closeEditor() {
			if (this.saving) return
			this.editorVisible = false
			this.editingSpaceId = null
		},
		async saveSpace(data) {
			if (this.saving) return
			this.saving = true
			try {
				if (this.editingSpaceId === null) await this.adminSpacesStore.createSpace(data)
				else await this.adminSpacesStore.updateSpace(this.editingSpaceId, data)
				this.editorVisible = false
				this.editingSpaceId = null
			} finally {
				this.saving = false
			}
		},
		async deleteSpace() {
			if (this.saving || this.editingSpaceId === null || !await this.askYesNoAsync('Supprimer définitivement cet espace ?')) return
			this.saving = true
			try {
				await this.adminSpacesStore.deleteSpace(this.editingSpaceId)
				if (this.selectedLanguageSpaceId === this.editingSpaceId) this.selectedLanguageSpaceId = null
				this.editorVisible = false
				this.editingSpaceId = null
			} finally {
				this.saving = false
			}
		},
	},
}
</script>
