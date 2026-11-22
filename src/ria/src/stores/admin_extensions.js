import { defineStore } from 'pinia'

const UPDATE_STATUS_ORDER = {
	update_available: 0,
	pending_update: 1,
	update_blocked: 2,
	incompatible: 3,
	inconsistent: 4,
	source_unavailable: 5,
	error: 6,
	unmanaged: 7,
	up_to_date: 8,
}

export const useAdminExtensionsStore = {
	get(vueApp, application) {
		return defineStore('admin_extensions', {
	state: () => ({
		extensions: null,
		spaces: {},
		selectedExtensionName: null,
		selectedSpaceId: null,
		loading: false,
		saving: false,
		error: null,
		migrations: null,
		migrationsLoading: false,
		migrationsRunning: null,
		migrationsError: null,
		updates: null,
		updatesLoading: false,
		updatesError: null,
		updateRunning: null,
		updateError: null,
		uninstalling: null,
		uninstallError: null,
	}),

	getters: {
		app: () => application.stores.useAppStore(),

		extensionList: (state) => Object.values(state.extensions ?? {})
			.sort((first, second) => (first.title || first.name).localeCompare(second.title || second.name)),

		migrationList: (state) => Object.values(state.migrations ?? {})
			.sort((first, second) => Number(second.core) - Number(first.core)
				|| (first.title || first.name).localeCompare(second.title || second.name)),

		updateList: (state) => Object.values(state.updates ?? {})
			.sort((first, second) => (UPDATE_STATUS_ORDER[first.status] ?? 99)
				- (UPDATE_STATUS_ORDER[second.status] ?? 99)
				|| (first.title || first.name).localeCompare(second.title || second.name)),

		spaceList: (state) => Object.values(state.spaces ?? {})
			.filter(space => state.selectedExtensionName
				&& state.extensions?.[state.selectedExtensionName]?.space_ids?.includes(Number(space.id)))
			.sort((first, second) => (first.title || `#${first.id}`).localeCompare(second.title || `#${second.id}`)),

		selectedExtension: (state) => state.selectedExtensionName
			? state.extensions?.[state.selectedExtensionName] ?? null
			: null,

		selectedSpace: (state) => state.selectedSpaceId !== null
			? state.spaces?.[state.selectedSpaceId] ?? null
			: null,

		selectedConfiguration() {
			if (!this.selectedExtension || this.selectedSpaceId === null) return null

			return this.selectedExtension.conf_by_spaces?.[this.selectedSpaceId] ?? null
		},
	},

	actions: {
		async loadExtensions() {
			this.loading = true
			this.error = null

			try {
				const response = await application.api.get('/administrator/api/ext')
				this.extensions = response.extensions ?? {}
				this.spaces = response.spaces ?? {}
				this.ensureSelection()
			} catch (exception) {
				this.error = exception instanceof Error ? exception.message : 'Impossible de charger les extensions.'
				throw exception
			} finally {
				this.loading = false
			}
		},

		async saveConfiguration(spaceId, extensionName, params) {
			this.saving = true
			this.error = null

			try {
				this.app.showModaleWait();

				const response = await application.api.post('/administrator/api/ext/save', {
					spaceId,
					extension: extensionName,
					params,
				})

				this.app.showModaleSuccess("enregistré");

				const configuration = response.configuration

				if (!configuration) {
					throw new Error('La configuration enregistrée est absente de la réponse.')
				}

				const extension = this.extensions?.[extensionName]
				if (extension) {
					extension.conf_by_spaces ??= {}
					extension.conf_by_spaces[spaceId] = configuration
				}

				return configuration
			} catch (exception) {
				this.error = exception instanceof Error ? exception.message : 'Impossible d’enregistrer la configuration.'
				throw exception
			} finally {
				this.saving = false
				this.app.hideModaleWait()
			}
		},

		async sendTemplateTestMail(spaceId, recipient, mode, params) {
			return await application.api.post('/administrator/api/templates/main/mail/test', {
				spaceId, recipient, mode, params,
			})
		},

		async loadMigrations(silent = false) {
			if (!silent) this.migrationsLoading = true
			this.migrationsError = null

			try {
				const response = await application.api.get('/administrator/api/ext/migrations')
				this.migrations = response.extensions ?? {}
			} catch (exception) {
				this.migrationsError = exception instanceof Error ? exception.message : 'Impossible de charger l’état des migrations.'
				throw exception
			} finally {
				if (!silent) this.migrationsLoading = false
			}
		},

		async runMigrations(extensionName) {
			if (this.migrationsRunning !== null) return null

			this.migrationsRunning = extensionName
			this.migrationsError = null

			try {
				const response = await application.api.post('/administrator/api/ext/migrations/run', {
					extension: extensionName,
				})

				// Le serveur relit l'état réel après l'exécution ; ne pas le deviner.
				const entry = this.migrations?.[extensionName]
				if (entry) entry.pending_migrations = response.pending_migrations ?? []

				return response
			} catch (exception) {
				this.migrationsError = exception instanceof Error ? exception.message : 'Impossible d’exécuter les migrations.'
				throw exception
			} finally {
				this.migrationsRunning = null
			}
		},

		async loadUpdates() {
			this.updatesLoading = true
			this.updatesError = null

			try {
				const response = await application.api.get('/administrator/api/ext/updates')
				this.updates = response.extensions ?? {}
			} catch (exception) {
				this.updatesError = exception instanceof Error ? exception.message : 'Impossible de charger les mises à jour.'
				throw exception
			} finally {
				this.updatesLoading = false
			}
		},

		async runUpdate(identifier, sourceId, version) {
			if (this.updateRunning !== null) return null

			this.updateRunning = identifier
			this.updateError = null

			try {
				const prepared = await application.api.post(
					'/administrator/api/extension-sources/update',
					{ data: { sourceId, identifier, version } },
				)

				try {
					const result = await application.api.post(
						'/administrator/api/extension-sources/update/finalize',
						{ data: { identifier, operationId: prepared.operation_id } },
					)
					await this.refreshAfterUpdate()

					return result
				} catch (exception) {
					// Recharger l'état pour afficher une éventuelle reprise.
					await this.refreshAfterUpdate()
					throw exception
				}
			} catch (exception) {
				this.updateError = exception instanceof Error ? exception.message : 'La mise à jour a échoué.'
				throw exception
			} finally {
				this.updateRunning = null
			}
		},

		async resumeUpdate(identifier, operationId) {
			if (this.updateRunning !== null) return null

			this.updateRunning = identifier
			this.updateError = null

			try {
				const result = await application.api.post(
					'/administrator/api/extension-sources/update/finalize',
					{ data: { identifier, operationId } },
				)
				await this.refreshAfterUpdate()

				return result
			} catch (exception) {
				this.updateError = exception instanceof Error ? exception.message : 'La finalisation de la mise à jour a échoué.'
				await this.refreshAfterUpdate()
				throw exception
			} finally {
				this.updateRunning = null
			}
		},

		async rollbackUpdate(identifier, operationId) {
			if (this.updateRunning !== null) return null

			this.updateRunning = identifier
			this.updateError = null

			try {
				const result = await application.api.post(
					'/administrator/api/extension-sources/update/rollback',
					{ data: { identifier, operationId } },
				)
				await this.refreshAfterUpdate()

				return result
			} catch (exception) {
				this.updateError = exception instanceof Error ? exception.message : 'La restauration de l’ancienne version a échoué.'
				await this.refreshAfterUpdate()
				throw exception
			} finally {
				this.updateRunning = null
			}
		},

		async refreshAfterUpdate() {
			try {
				await this.loadMigrations()
			} catch {
				// Le store conserve l'erreur de chargement.
			}
			try {
				await this.loadUpdates()
			} catch {
				// Le store conserve l'erreur de chargement.
			}
		},

		async uninstall(identifier) {
			if (this.uninstalling !== null) return null

			this.uninstalling = identifier
			this.uninstallError = null

			try {
				const result = await application.api.post(
					'/administrator/api/ext/uninstall',
					{ extension: identifier },
				)

				// L'extension disparaît de la liste au prochain chargement.
				try {
					await this.loadMigrations()
				} catch {
					// Le store conserve l'erreur de chargement.
				}

				return result
			} catch (exception) {
				this.uninstallError = exception instanceof Error ? exception.message : 'La désinstallation a échoué.'
				throw exception
			} finally {
				this.uninstalling = null
			}
		},

		selectExtension(extensionName) {
			this.selectedExtensionName = extensionName
			this.ensureSelection()
		},

		clearSelectedExtension() {
			this.selectedExtensionName = null
			this.selectedSpaceId = null
		},

		selectSpace(spaceId) {
			this.selectedSpaceId = spaceId
		},

		clearError() {
			this.error = null
		},

		ensureSelection() {
			const extensionNames = Object.keys(this.extensions ?? {})
			if (this.selectedExtensionName !== null && !extensionNames.includes(this.selectedExtensionName)) {
				this.selectedExtensionName = null
			}

			const spaceIds = this.selectedExtension?.space_ids?.map(String) ?? []
			if (this.selectedExtensionName === null) {
				this.selectedSpaceId = null
				return
			}

			if (!spaceIds.includes(String(this.selectedSpaceId))) {
				this.selectedSpaceId = spaceIds[0] ?? null
			}
		},
		},
		})
	},
}
