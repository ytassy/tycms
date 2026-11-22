<template>
	<section class="admin-dashboard">
		<h1>Tableau de bord</h1>
		<p class="text-muted">Accédez aux rubriques de votre administration.</p>

		<section v-if="canCheckUpdates" class="update-status mb-4" aria-live="polite">
			<h2 class="h5">Mises à jour du système</h2>
			<p v-if="loading" class="mb-2">Vérification en cours…</p>
			<p v-else-if="error" class="alert alert-warning">{{ error }}</p>
			<template v-else-if="context">
				<p class="mb-2">
					Version installée : <strong>{{ context.installed_version }}</strong>
					<span v-if="result && !result.available_update" class="badge text-bg-success ms-2">À jour</span>
				</p>
				<p v-if="context.configuration_error" class="alert alert-warning">{{ context.configuration_error }}</p>
				<template v-else-if="result">
					<p v-if="result.simulation" class="alert alert-info">Développement : résultat simulé depuis la version {{ result.source_version }}. La version proposée est destinée aux essais.</p>
					<p v-if="result.available_update" class="alert alert-info">
						{{ result.dev_preview ? 'Version disponible pour essai' : 'Mise à jour disponible' }} : <strong>{{ result.available_update.version }}</strong>.
					</p>
					<p v-if="result.failures?.length" class="alert alert-warning">Certains serveurs de mise à jour sont indisponibles. Consultez les détails dans la rubrique Système.</p>
				</template>
			</template>
			<p v-if="context?.cleanup_available" class="alert alert-warning">Une opération de mise à jour terminée attend son nettoyage. La sauvegarde locale sera supprimée lors du nettoyage.</p>
			<router-link :to="{ name: 'admin-page', params: { page: 'info_system' } }">Voir les mises à jour du système</router-link>
		</section>

		<section v-if="canCheckUpdates" class="update-status mb-4" aria-live="polite">
			<h2 class="h5">Mises à jour des extensions</h2>
			<p v-if="extensionsLoading" class="mb-2">Vérification en cours…</p>
			<p v-else-if="extensionsError" class="alert alert-warning">{{ extensionsError }}</p>
			<template v-else-if="extensionUpdates">
				<div v-if="extensionsToUpdate.length === 0" class="alert alert-success">
					Toutes les extensions installées sont à jour.
				</div>
				<template v-else>
					<p class="mb-2">
						<span class="badge text-bg-warning">
							{{ extensionsToUpdate.length }} extension{{ extensionsToUpdate.length > 1 ? 's' : '' }} à mettre à jour
						</span>
					</p>
					<ul class="list-unstyled mb-2">
						<li v-for="extension in extensionsToUpdate" :key="extension.name" class="mb-1">
							<strong>{{ extension.title || extension.name }}</strong>
							<span class="text-body-secondary small ms-1">
								v{{ extension.version }} → v{{ extension.latest_version }}
							</span>
							<span v-if="extension.source_name" class="badge text-bg-light border ms-1">
								{{ extension.source_name }}
							</span>
						</li>
					</ul>
				</template>
				<p v-if="extensionProblems" class="alert alert-warning">
					Certaines extensions n’ont pas pu être vérifiées. Consultez l’onglet Mises à jour.
				</p>
				<router-link :to="{ name: 'admin-page', params: { page: 'extensions' }, query: { tab: 'updates' } }">
					Gérer les extensions
				</router-link>
			</template>
		</section>

		<section v-for="group in groups" :key="group.title" class="mb-4">
			<h2 class="h5">{{ group.title }}</h2>
			<div class="dashboard-links">
				<router-link v-for="item in group.items" :key="item.page" class="dashboard-link"
					:to="{ name: 'admin-page', params: { page: item.page } }">
					<i :class="item.icon" aria-hidden="true"></i>
					<span>{{ item.label }}</span>
				</router-link>
			</div>
		</section>
	</section>
</template>

<script>
export default {
	props: {
		groups: { type: Array, required: true },
		canCheckUpdates: { type: Boolean, default: false },
	},
	data: () => ({
		context: null,
		result: null,
		loading: false,
		error: '',
		extensionUpdates: null,
		extensionsLoading: false,
		extensionsError: '',
	}),

	computed: {
		extensionsToUpdate() {
			return Object.values(this.extensionUpdates ?? {})
				.filter((extension) => extension.status === 'update_available')
				.sort((first, second) => (first.title || first.name).localeCompare(second.title || second.name))
		},

		extensionProblems() {
			return Object.values(this.extensionUpdates ?? {})
				.filter((extension) => ['error', 'source_unavailable', 'inconsistent'].includes(extension.status))
				.length
		},
	},

	async mounted() {
		if (!this.canCheckUpdates) return

		// Les deux vérifications sont indépendantes : une source de catalogue
		// indisponible ne doit pas masquer l'état du système.
		await Promise.all([this.loadSystemUpdates(), this.loadExtensionUpdates()])
	},

	methods: {
		async loadSystemUpdates() {
			// Une consultation isolée ne modifie ni la recherche ni les actions de l'écran détaillé.
			this.loading = true
			try {
				this.context = await this.api.get('/administrator/api/system-updates')
				if (!this.context.configuration_error) {
					// En DEV, le service exige une source simulée, comme l'écran détaillé.
					const payload = this.context.simulation_allowed ? { sourceVersion: '1.0.0' } : {}
					this.result = await this.api.post('/administrator/api/system-updates/check', payload)
				}
			} catch {
				this.error = 'La vérification des mises à jour a échoué. Consultez la rubrique Système.'
			} finally {
				this.loading = false
			}
		},

		async loadExtensionUpdates() {
			this.extensionsLoading = true
			this.extensionsError = ''
			try {
				const response = await this.api.get('/administrator/api/ext/updates')
				this.extensionUpdates = response.extensions ?? {}
			} catch {
				this.extensionsError = 'La vérification des mises à jour des extensions a échoué. Consultez l’onglet Mises à jour.'
			} finally {
				this.extensionsLoading = false
			}
		},
	},
}
</script>

<style scoped>
.update-status { max-width: 70rem; }
.dashboard-links { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem; }
.dashboard-link {
	aspect-ratio: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: .75rem;
	padding: 1rem;
	border: 1px solid #d5dce3;
	border-radius: .5rem;
	color: inherit;
	text-align: center;
	text-decoration: none;
}
.dashboard-link:hover, .dashboard-link:focus-visible { border-color: #496786; background: #f2f6fa; }
.dashboard-link i { font-size: 1.8rem; }
</style>
