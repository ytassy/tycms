<template>
	<section>
		<h1>Polices du site</h1>
		<p>Les polices installées sont partagées par tous les espaces. Leurs fichiers sont hébergés sur ce site. Les liens de licence sont disponibles ci-dessous pour préparer les mentions légales du site.</p>
		<div v-if="store.error" class="alert alert-danger" role="alert">{{ store.error }}</div>
		<div v-if="store.loading && !store.families.length" class="py-3"><spinner /></div>
		<template v-else>
			<section class="card mb-4"><div class="card-body">
				<div class="d-flex flex-wrap align-items-center gap-3 mb-2">
					<h2 class="h4 mb-0">Ajouter une police</h2>
					
				</div>

				<p class="form-text">Catalogue des Google Fonts (<a href="https://fonts.google.com" target="_blank">https://fonts.google.com</a>) ci-dessous.
					<span v-if="catalogDate" class="text-body-secondary">Dernière actualisation : {{ catalogDate }}.</span>
					<a v-if="store.canManage" :disabled="store.busy || store.loading" @click="refreshCatalog">
						<span v-if="store.refreshing" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />
						Actualiser les fonts
					</a>
				</p>

				<data-table class="fonts-data-table" :fields="fields" :items="availableFonts" :pagination-enabled="true"
					:initial-per-page="10" :per-page-options="pageSizes" :row-hover="true" :filters-default-opened="true" :auto-scroll-enabled="true"
					default-sort-key="family" table-class="align-middle">
					<template #cell_family="{ item }"><strong>{{ item.family }}</strong></template>
					<template #cell_preview="{ item }"><FontTablePreview :key="item.id" :font="item" /></template>
					<template #cell_actions="{ item }">
						<button type="button" class="btn btn-primary btn-sm text-nowrap" :disabled="store.busy || !store.spaceIds.length" @click="install(item)">
							<span v-if="installingId === item.id" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />
							Installer
						</button>
					</template>
					<template #empty>Aucune police à installer ne correspond aux filtres.</template>
				</data-table>
				<p v-if="success" class="text-success mt-2 mb-0" role="status">{{ success }}</p>
			</div></section>
			<section>
				<h2 class="h4">Polices installées</h2>
				<p v-if="!installed.length">Aucune police téléchargée. Les polices historiques de la template restent disponibles.</p>
				<data-table v-if="installed.length" class="fonts-data-table" :fields="installedFields" :items="installed"
					:pagination-enabled="true" :initial-per-page="20" :per-page-options="pageSizes"
					:row-hover="true" :filters-default-opened="true" :auto-scroll-enabled="true"
					default-sort-key="family" table-class="align-middle">
					<template #cell_family="{ item }"><strong>{{ item.family }}</strong></template>
					<template #cell_status="{ item }">
						<span class="badge" :class="item.enabled ? 'text-bg-success' : 'text-bg-secondary'">{{ item.status }}</span>
					</template>
					<template #cell_preview="{ item }"><FontTablePreview :key="item.id" :font="item" local /></template>
					<template #cell_licenses="{ item }">
						<a v-for="license in item.licenses" :key="license.url" class="d-block small text-nowrap"
							:href="license.url" target="_blank" rel="noopener">{{ license.name }}</a>
					</template>
					<template #cell_actions="{ item }">
						<div class="d-flex justify-content-end gap-2">
							<button type="button" class="btn btn-outline-secondary btn-sm text-nowrap" :disabled="store.busy" @click="setEnabled(item)">{{ item.enabled ? 'Désactiver' : 'Activer' }}</button>
							<button type="button" class="btn btn-outline-danger btn-sm text-nowrap" :disabled="store.busy || item.enabled" @click="remove(item)">Supprimer les fichiers</button>
						</div>
					</template>
					<template #empty>Aucune police installée ne correspond aux filtres.</template>
				</data-table>
				<p v-if="store.canManage" class="form-text">Désactiver retire une police des nouveaux choix et conserve les rendus existants. La suppression physique exige sa désactivation et peut affecter tous les espaces.</p>
			</section>
		</template>
	</section>
</template>

<script>
import FontTablePreview from './FontTablePreview.vue'

export default {
	components: { FontTablePreview },
	data() {
		return {
			success: '', installingId: null,
			pageSizes: [5, 10, 15, 20, 30],
			fields: [
				{ key: 'family', label: 'Famille', filter: 'search', sortable: true },
				{ key: 'category', label: 'Catégorie', filter: 'select', sortable: true },
				{ key: 'preview', label: 'Prévisualisation', style: 'min-width: 20rem; max-width: 32rem;' },
				{ key: 'actions', label: '', class: 'text-end' },
			],
		}
	},
	computed: {
		store() { return this.stores.useAdminFontsStore() },
		catalogDate() {
			if (!this.store.catalogUpdatedAt) return ''
			const date = new Date(this.store.catalogUpdatedAt)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('fr-FR')
		},
		installed() {
			return Object.values(this.store.installed)
				.map(font => ({ ...font, status: font.enabled ? 'Activée' : 'Désactivée' }))
				.sort((a, b) => a.family.localeCompare(b.family))
		},
		installedFields() {
			return [
				{ key: 'family', label: 'Famille', filter: 'search', sortable: true },
				{ key: 'category', label: 'Catégorie', filter: 'select', sortable: true },
				{ key: 'status', label: 'État', filter: 'select', sortable: true },
				{ key: 'preview', label: 'Prévisualisation', style: 'min-width: 20rem; max-width: 32rem;' },
				{ key: 'licenses', label: 'Licences' },
				...(this.store.canManage ? [{ key: 'actions', label: '', class: 'text-end' }] : []),
			]
		},
		availableFonts() { return this.store.families.filter(font => !this.store.installed[font.id]) },
	},
	mounted() { this.store.load() },
	methods: {
		async refreshCatalog() {
			this.success = ''
			const catalog = await this.store.refreshCatalog()
			if (catalog) this.success = `Catalogue actualisé : ${catalog.count} familles disponibles.`
		},
		async install(font) {
			this.success = ''
			const { family, id } = font
			this.installingId = id
			const currentSpaceId = Number(this.conf.getSpace()?.id)
			const spaceId = this.store.spaceIds.includes(currentSpaceId) ? currentSpaceId : this.store.spaceIds[0]
			if (await this.store.mutate('install', { spaceId, id })) {
				this.success = `${family} est disponible dans les choix de police des templates.`
			}
			this.installingId = null
		},
		async setEnabled(font) {
			this.success = ''
			await this.store.mutate('enabled', { id: font.id, enabled: !font.enabled })
		},
		async remove(font) {
			if (!await this.askYesNoAsync(`Supprimer définitivement les fichiers de « ${font.family} » ? Tous les espaces et les extensions qui l’utilisent peuvent être affectés. La désactivation seule conserve leur affichage.`)) return
			this.success = ''
			await this.store.mutate('delete', { id: font.id })
		},
	},
}
</script>

<style scoped>
/* Les tableaux suivent leur contenu, avec défilement horizontal sur les écrans étroits. */
.fonts-data-table { width: fit-content; max-width: 100%; }
.fonts-data-table :deep(table) { width: auto; }
</style>
