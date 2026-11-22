<template>
	<section class="page-editor" :class="{ 'page-editor--workspace': current }">
		<p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
		<PageWorkspace v-if="current" @close="close" @save="save" />
		<template v-else>
			<div class="d-flex justify-content-between align-items-center mb-3">
				<h1 class="mb-0">Pages</h1>
				<button type="button" class="btn btn-primary btn-sm" :disabled="!editableSpaces.length" @click="create">+ Nouvelle page</button>
			</div>
			<div v-if="!store.loading && store.spaces.length" class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Filtrer les pages par Space">
				<button type="button" class="badge p-2 rounded-pill border-0" :class="selectedSpaceId === null ? 'text-bg-primary' : 'text-bg-secondary'"
					:aria-pressed="selectedSpaceId === null" @click="selectedSpaceId = null">Tous les Spaces</button>
				<button v-for="space in store.spaces" :key="space.id" type="button" class="badge rounded-pill border-0"
					:class="selectedSpaceId === space.id ? 'text-bg-primary' : 'text-bg-secondary'"
					:aria-pressed="selectedSpaceId === space.id" @click="selectedSpaceId = space.id">{{ space.title }}</button>
			</div>
			<!-- Garder les filtres de statut directement accessibles au-dessus de la liste. -->
			<div v-if="!store.loading" class="d-flex flex-wrap align-items-center gap-3 mb-3" role="group" aria-label="Filtrer par statut">
				<div class="form-check form-switch mb-0">
					<input id="pages-filter-archived" v-model="showArchived" class="form-check-input" type="checkbox">
					<label class="form-check-label" for="pages-filter-archived">Afficher les archivées</label>
				</div>
				<div class="form-check form-switch mb-0">
					<input id="pages-filter-trashed" v-model="showTrashed" class="form-check-input" type="checkbox">
					<label class="form-check-label" for="pages-filter-trashed">Afficher la corbeille</label>
				</div>
			</div>
			<div v-if="store.loading" class="text-muted">Chargement des pages…</div>
			<data-table v-else class="pages-data-table" :fields="fields" :items="filteredPages" :pagination-enabled="true"
				:row-hover="true" :clickable-row="true" :auto-scroll-enabled="true" default-sort-key="name" @click:row="page => open(page.id)">
				<template #cell_status="{ item }"><item_status :status="item.status" /></template>
				<template #cell_actions="{ item }">
					<button type="button" class="btn btn-outline-primary btn-sm" :title="item.canEdit ? 'Modifier' : 'Voir'"
						:aria-label="`${item.canEdit ? 'Modifier' : 'Voir'} ${item.name}`" @click.stop="open(item.id)">
						<i :class="item.canEdit ? 'fa-solid fa-pen-to-square' : 'fa-solid fa-eye'" aria-hidden="true"></i>
					</button>
					<div v-if="item.canEdit" class="btn-group btn-group-sm ms-1" @click.stop>
						<button type="button" class="btn btn-outline-secondary dropdown-toggle" title="Changer le statut"
							:disabled="processingPageId !== null" data-bs-toggle="dropdown" aria-expanded="false">
							<i class="fa-solid fa-pen" aria-hidden="true"></i><span class="visually-hidden">Changer le statut</span>
						</button>
						<ul class="dropdown-menu dropdown-menu-end">
							<li v-for="status in statuses" :key="status"><button type="button" class="dropdown-item"
								:disabled="processingPageId !== null || item.status === status" @click="changeStatus(item, status)"><item_status :status="status" /></button></li>
						</ul>
					</div>
					<span v-if="item.canEdit && item.status === -2" class="ms-2" @click.stop>
						<delete_btn text_class="text-danger" :confirm_text="`Supprimer définitivement « ${item.name} » ?`" @click="deletePage(item)" />
					</span>
				</template>
			</data-table>
		</template>
	</section>
</template>

<script>
import PageWorkspace from './PageWorkspace.vue'

export default {
	components: { PageWorkspace },
	emits: ['workspace-change'],
	data: () => ({
		error: '', selectedSpaceId: null, showArchived: false, showTrashed: false, processingPageId: null,
		statuses: [1, 0, -1, -2],
		fields: [
			{ key: 'id', label: 'Id', sortable: true, filter: 'search' },
			{ key: 'name', label: 'Nom', sortable: true, filter: 'search' },
			{ key: 'space_title', label: 'Space', sortable: true, filter: 'search' },
			{ key: 'status', label: 'Statut', sortable: true },
			{ key: 'actions', label: 'Actions', class: 'text-end' },
		],
	}),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		current() { return this.store.current },
		filteredPages() {
			// Fournir le nom du Space au tableau pour son tri et sa recherche.
			return this.store.pages
				.filter(page => (this.selectedSpaceId === null || page.space_id === this.selectedSpaceId)
					&& (page.status === 1 || page.status === 0 || (page.status === -1 && this.showArchived) || (page.status === -2 && this.showTrashed)))
				.map(page => ({ ...page, space_title: this.spaceName(page.space_id) }))
		},
		editableSpaces() { return this.store.spaces.filter(space => space.canEdit) },
	},
	watch: { current(value) { this.$emit('workspace-change', Boolean(value)) } },
	async mounted() {
		this.$emit('workspace-change', Boolean(this.current))
		try { await this.store.loadDirectory() } catch (error) { this.error = error.message }
	},
	beforeUnmount() { this.$emit('workspace-change', false) },
	methods: {
		spaceName(spaceId) { return this.store.spaces.find(space => space.id === spaceId)?.title ?? `Espace #${spaceId}` },
		async open(id) { this.error = ''; try { await this.store.open(id) } catch (error) { this.error = error.message } },
		async processPage(page, action) {
			if (this.processingPageId !== null) return
			this.processingPageId = page.id
			this.error = ''
			try { await action() } catch (error) { this.error = error.message }
			finally { this.processingPageId = null }
		},
		async changeStatus(page, status) { await this.processPage(page, () => this.store.changeStatus(page.id, status)) },
		async deletePage(page) {
			if (this.processingPageId !== null || !await this.askYesNoAsync(`Supprimer définitivement « ${page.name} » ?`)) return
			await this.processPage(page, () => this.store.deletePage(page.id))
		},
		async create() { this.error = ''; try { await this.store.startNew(this.editableSpaces[0].id) } catch (error) { this.error = error.message } },
		async canLeave() {
			return !this.store.current || !this.store.isDirty || await this.askYesNoAsync('Quitter cette page sans enregistrer les modifications ?')
		},
		async close() {
			if (!await this.canLeave()) return
			this.store.close()
			this.error = ''
		},
		async save() {
			this.error = ''
			this.showModaleWait()
			let saved = false
			try { saved = await this.store.save() }
			catch (error) { this.error = error.message }
			finally { this.hideModaleWait() }
			if (saved) this.showModaleSuccess('Page enregistrée')
		},
	},
}
</script>

<style scoped>
.pages-data-table { width: fit-content; max-width: 100%; }
.pages-data-table :deep(table) { width: auto; }
</style>
