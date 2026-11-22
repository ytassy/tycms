import { defineStore } from 'pinia'

export const useAdminTyCatalogStore = {
	get(vueApp, application) {
		return defineStore('admin_ty_catalog', {
			state: () => ({
				overview: null,
				loading: false,
				saving: false,
				error: null,
				versionsCatalog: null,
				versions: [],
				versionsCanEdit: false,
				versionsCanPublish: false,
				versionsLoading: false,
				versionsError: null,
				depositSaving: false,
				tokens: [],
				tokensLoading: false,
				tokensError: null,
				tokenSaving: false,
				createdToken: null,
				ficheSaving: false,
			}),

			actions: {
				async load(silent = false) {
					if (!silent) this.loading = true
					this.error = null

					try {
						this.overview = await application.api.get('/administrator/api/ty-catalog')
					} catch (exception) {
						this.error = exception instanceof Error ? exception.message : 'Impossible de charger les catalogues.'
						throw exception
					} finally {
						if (!silent) this.loading = false
					}
				},

				async save(data) {
					this.saving = true
					this.error = null

					try {
						const result = await application.api.post('/administrator/api/ty-catalog/save', { data })
						await this.load(true)
						return result
					} catch (exception) {
						this.error = exception instanceof Error ? exception.message : 'Impossible d’enregistrer le catalogue.'
						throw exception
					} finally {
						this.saving = false
					}
				},

				async loadVersions(catalogId, silent = false) {
					if (!silent) this.versionsLoading = true
					this.versionsError = null

					try {
						const result = await application.api.get(
							`/administrator/api/ty-catalog/versions?catalogId=${encodeURIComponent(catalogId)}`,
						)
						this.versionsCatalog = result.catalog ?? null
						this.versions = result.versions ?? []
						this.versionsCanEdit = result.can_edit === true
						this.versionsCanPublish = result.can_publish === true
					} catch (exception) {
						this.versionsError = exception instanceof Error ? exception.message : 'Impossible de charger les versions.'
						throw exception
					} finally {
						if (!silent) this.versionsLoading = false
					}
				},

				async depositLocal(catalogId, files) {
					const data = new FormData()
					data.append('catalogId', String(catalogId))
					if (files.displayName) data.append('displayName', files.displayName)
					if (files.description) data.append('description', files.description)
					data.append('archive', files.archive)
					data.append('notes', files.notes)
					data.append('signature', files.signature)

					return this.deposit('/administrator/api/ty-catalog/versions/upload', data, catalogId)
				},

				async depositReference(payload) {
					return this.deposit(
						'/administrator/api/ty-catalog/versions/reference',
						{ data: payload },
						payload.catalogId,
					)
				},

				async deposit(url, payload, catalogId) {
					this.depositSaving = true
					this.versionsError = null

					try {
						const result = await application.api.post(url, payload)
						await this.loadVersions(catalogId, true)
						return result
					} catch (exception) {
						this.versionsError = exception instanceof Error ? exception.message : 'Le dépôt a échoué.'
						throw exception
					} finally {
						this.depositSaving = false
					}
				},

				async versionAction(action, catalogId, versionId) {
					this.depositSaving = true
					this.versionsError = null

					try {
						const result = await application.api.post(
							`/administrator/api/ty-catalog/versions/${action}`,
							{ data: { catalogId, versionId } },
						)
						await this.loadVersions(catalogId, true)
						return result
					} catch (exception) {
						this.versionsError = exception instanceof Error ? exception.message : 'L’opération a échoué.'
						throw exception
					} finally {
						this.depositSaving = false
					}
				},

				async cleanupVersions(catalogId) {
					this.depositSaving = true
					this.versionsError = null

					try {
						const result = await application.api.post(
							'/administrator/api/ty-catalog/versions/cleanup',
							{ data: { catalogId } },
						)
						await this.loadVersions(catalogId, true)
						return result
					} catch (exception) {
						this.versionsError = exception instanceof Error ? exception.message : 'Le nettoyage a échoué.'
						throw exception
					} finally {
						this.depositSaving = false
					}
				},

				async saveExtensionFiche(payload) {
					this.ficheSaving = true
					this.versionsError = null

					try {
						const result = await application.api.post(
							'/administrator/api/ty-catalog/extensions/save',
							{ data: payload },
						)
						await this.loadVersions(payload.catalogId, true)
						return result
					} catch (exception) {
						this.versionsError = exception instanceof Error ? exception.message : 'Impossible d’enregistrer la fiche.'
						throw exception
					} finally {
						this.ficheSaving = false
					}
				},

				async loadTokens(catalogId, silent = false) {
					if (!silent) this.tokensLoading = true
					this.tokensError = null

					try {
						const result = await application.api.get(
							`/administrator/api/ty-catalog/tokens?catalogId=${encodeURIComponent(catalogId)}`,
						)
						this.tokens = result.tokens ?? []
					} catch (exception) {
						this.tokensError = exception instanceof Error ? exception.message : 'Impossible de charger les jetons.'
						throw exception
					} finally {
						if (!silent) this.tokensLoading = false
					}
				},

				async createToken(payload) {
					this.tokenSaving = true
					this.tokensError = null
					this.createdToken = null

					try {
						const result = await application.api.post(
							'/administrator/api/ty-catalog/tokens/create',
							{ data: payload },
						)
						// Le jeton en clair n'est renvoyé qu'ici : le conserver à l'écran.
						this.createdToken = result.token ?? null
						await this.loadTokens(payload.catalogId, true)
						return result
					} catch (exception) {
						this.tokensError = exception instanceof Error ? exception.message : 'Impossible de créer le jeton.'
						throw exception
					} finally {
						this.tokenSaving = false
					}
				},

				async revokeToken(catalogId, tokenId) {
					this.tokenSaving = true
					this.tokensError = null

					try {
						await application.api.post(
							'/administrator/api/ty-catalog/tokens/revoke',
							{ data: { catalogId, tokenId } },
						)
						await this.loadTokens(catalogId, true)
					} catch (exception) {
						this.tokensError = exception instanceof Error ? exception.message : 'Impossible de révoquer le jeton.'
						throw exception
					} finally {
						this.tokenSaving = false
					}
				},

				clearTokensError() {
					this.tokensError = null
				},

				clearCreatedToken() {
					this.createdToken = null
				},

				clearError() {
					this.error = null
				},

				clearVersionsError() {
					this.versionsError = null
				},
			},
		})
	},
}
