<template>
	<section>
		<p class="text-body-secondary">
			Les migrations appliquent les changements de schéma attendus par le code chargé.
			Les extensions Core actives sont listées avec l’état de leur table <code>migrations</code>.
			Les extensions installées depuis un catalogue peuvent être désinstallées ici ; leur hook de nettoyage est exécuté.
		</p>

		<div v-if="store.migrationsLoading" class="py-5 text-center">
			<spinner />
		</div>

		<template v-else>
			<div v-if="store.migrationsError" class="alert alert-danger" role="alert">
				{{ store.migrationsError }}
			</div>
			<div v-if="store.uninstallError" class="alert alert-warning" role="alert">
				{{ store.uninstallError }}
			</div>

			<template v-if="store.migrations !== null">
				<div v-if="extensions.length" class="d-flex flex-wrap gap-2 mb-3"
					role="group" aria-label="Filtrer les extensions par provenance">
					<button v-for="filter in provenanceFilters" :key="filter.key" type="button"
						:class="['btn', 'btn-sm', 'rounded-pill',
							provenanceFilter === filter.key ? 'btn-primary' : 'btn-outline-secondary']"
						@click="provenanceFilter = filter.key">
						{{ filter.label }}
						<span :class="['badge', 'ms-1', provenanceFilter === filter.key ? 'text-bg-light' : 'text-bg-secondary']">
							{{ filter.count }}
						</span>
					</button>
				</div>

				<div v-if="extensions.length === 0" class="alert alert-info mb-0">
					Aucune extension n’est chargée sur cette instance.
				</div>
				<div v-else-if="filteredExtensions.length === 0" class="alert alert-info mb-0">
					Aucune extension ne correspond à cette provenance.
				</div>

				<div v-for="extension in filteredExtensions" :key="extension.name" class="card mb-3">
					<div class="card-body d-flex flex-wrap align-items-center gap-3">
						<div class="flex-grow-1">
							<div class="d-flex flex-wrap align-items-center gap-2">
								<i :class="extension.icon" aria-hidden="true" />
								<h2 class="h5 mb-0">{{ extension.title || extension.name }}</h2>
								<span class="badge" :class="extension.core ? 'text-bg-primary' : 'text-bg-secondary'">
									{{ extension.core ? 'Core' : 'Installée' }}
								</span>
								<span v-if="extension.provenance === 'catalog'" class="badge text-bg-light border">
									{{ extension.source_name }}
								</span>
								<span v-if="!extension.core && extension.provenance !== 'catalog'"
									class="badge text-bg-secondary">
									Désinstallation manuelle
								</span>
								<template v-else-if="extension.core">
									<span v-if="extension.pending_migrations.length === 0" class="badge text-bg-success">
										Migrations à jour
									</span>
									<span v-else class="badge text-bg-warning">
										{{ extension.pending_migrations.length }} migration{{ extension.pending_migrations.length > 1 ? 's' : '' }} en attente
									</span>
								</template>
							</div>

							<p v-if="extension.description" class="text-body-secondary mb-0 mt-1">{{ extension.description }}</p>
							<small class="text-body-secondary">{{ extension.name }} · v{{ extension.version }}</small>

							<p v-if="extension.pending_migrations.length" class="mb-0 mt-1">
								Versions :
								<code v-for="version in extension.pending_migrations" :key="version" class="me-3">{{ version }}</code>
							</p>
							<p v-if="!extension.core && extension.pending_migrations.length"
								class="text-body-secondary small mb-0 mt-1">
								Les migrations des extensions installées sont exécutées par l’onglet Mises à jour.
							</p>
						</div>

						<div v-if="extension.migrations_managed && extension.pending_migrations.length" class="text-end">
							<button type="button" class="btn btn-primary"
								:disabled="store.migrationsRunning !== null || store.uninstalling !== null"
								@click="run(extension)">
								<span v-if="store.migrationsRunning === extension.name" class="spinner-border spinner-border-sm me-2" aria-hidden="true" />
								Lancer les migrations
							</button>
						</div>

						<div v-else-if="!extension.core && extension.provenance === 'catalog'" class="text-end">
							<button type="button" class="btn btn-outline-danger"
								:disabled="store.migrationsRunning !== null || store.uninstalling !== null"
								@click="uninstall(extension)">
								<span v-if="store.uninstalling === extension.name"
									class="spinner-border spinner-border-sm me-2" aria-hidden="true" />
								<i v-else class="fa-solid fa-trash-can me-1" aria-hidden="true" /> Désinstaller
							</button>
						</div>
					</div>
				</div>
			</template>
		</template>
	</section>
</template>

<script>
export default {
	props: {
		active: { type: Boolean, default: false },
	},

	data() {
		return {
			provenanceFilter: 'all',
		}
	},

	computed: {
		store() {
			return this.stores.useAdminExtensionsStore()
		},

		extensions() {
			return this.store.migrationList
		},

		filteredExtensions() {
			if (this.provenanceFilter === 'all') return this.extensions

			return this.extensions.filter((extension) => this.provenanceKey(extension) === this.provenanceFilter)
		},

		provenanceFilters() {
			const filters = new Map()
			filters.set('all', { key: 'all', label: 'Toutes', count: this.extensions.length })

			for (const extension of this.extensions) {
				const key = this.provenanceKey(extension)
				if (!filters.has(key)) {
					filters.set(key, { key, label: this.provenanceLabel(extension), count: 0 })
				}
				filters.get(key).count++
			}

			return [...filters.values()]
		},
	},

	watch: {
		active(value) {
			if (value) this.refresh()
		},
	},

	async mounted() {
		if (this.active) await this.refresh()
	},

	methods: {
		async refresh() {
			try {
				// Une fois la liste chargée, rafraîchir sans masquer son contenu.
				await this.store.loadMigrations(this.store.migrations !== null)
			} catch {
				// Le store conserve le message ; ne pas masquer l’état déjà affiché.
			}
		},

		provenanceKey(extension) {
			if (extension.provenance === 'core') return 'core'
			if (extension.provenance === 'catalog') return `source:${extension.source_id ?? 0}`

			return 'unmanaged'
		},

		provenanceLabel(extension) {
			if (extension.provenance === 'core') return 'Core'
			if (extension.provenance === 'catalog') return extension.source_name || 'Catalogue'

			return 'Hors catalogue'
		},

		async uninstall(extension) {
			if (this.store.uninstalling !== null || this.store.migrationsRunning !== null) return

			const label = extension.title || extension.name
			const confirmed = await this.askYesNoAsync(
				`Désinstaller « ${label} » ? Le code, les assets et la provenance seront supprimés. `
				+ 'La méthode de nettoyage de l’extension sera exécutée : elle peut supprimer des tables, '
				+ 'des données, la configuration et des ACL. Les migrations SQL déjà exécutées ne sont pas annulables.',
			)
			if (!confirmed) return

			this.showModaleWait()
			try {
				await this.store.uninstall(extension.name)
				this.showModaleSuccess('Extension désinstallée.')
			} catch {
				// Le store conserve le message d'échec.
			} finally {
				this.hideModaleWait()
			}
		},

		async run(extension) {
			if (this.store.migrationsRunning !== null || this.store.uninstalling !== null) return

			const label = extension.title || extension.name
			const confirmed = await this.askYesNoAsync(
				`Lancer les migrations en attente de « ${label} » ? Les changements de schéma s’appliquent immédiatement à toute l’instance.`,
			)
			if (!confirmed) return

			this.showModaleWait()
			try {
				const result = await this.store.runMigrations(extension.name)

				if (result?.pending_migrations?.length) {
					this.showModaleWarning('Certaines migrations restent en attente. Consultez le détail dans la liste.')
				} else {
					this.showModaleSuccess('Migrations exécutées.')
				}
			} catch {
				// Le client API présente la cause interne ; l’état affiché reste celui du serveur.
			} finally {
				this.hideModaleWait()
			}
		},
	},
}
</script>
