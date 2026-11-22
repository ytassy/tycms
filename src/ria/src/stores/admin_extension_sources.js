import { defineStore } from 'pinia'

export const useAdminExtensionSourcesStore = {
	get(vueApp, application) {
		return defineStore('admin_extension_sources', {
			state: () => ({
				sources: [],
				officialUrl: '',
				officialPublicKey: '',
				loading: false,
				saving: false,
				error: null,
				consultation: null,
				consulting: null,
				consultError: null,
				installing: null,
				installError: null,
			}),

			actions: {
				async load() {
					this.loading = true
					this.error = null

					try {
						const result = await application.api.get('/administrator/api/extension-sources')
						this.sources = result.sources ?? []
						this.officialUrl = result.official_url ?? ''
						this.officialPublicKey = result.official_public_key ?? ''
					} catch (exception) {
						this.error = exception instanceof Error ? exception.message : 'Impossible de charger les sources.'
						throw exception
					} finally {
						this.loading = false
					}
				},

				async save(data) {
					this.saving = true
					this.error = null

					try {
						const result = await application.api.post('/administrator/api/extension-sources/save', { data })
						await this.load()
						return result
					} catch (exception) {
						this.error = exception instanceof Error ? exception.message : 'Impossible d’enregistrer la source.'
						throw exception
					} finally {
						this.saving = false
					}
				},

				async consult(id) {
					this.consulting = id
					this.consultation = null
					this.consultError = null

					try {
						this.consultation = await application.api.post(
							'/administrator/api/extension-sources/consult',
							{ data: { id } },
						)
						return this.consultation
					} catch (exception) {
						this.consultError = exception instanceof Error ? exception.message : 'La consultation du catalogue a échoué.'
						throw exception
					} finally {
						this.consulting = null
					}
				},

				async install(sourceId, identifier, version) {
					this.installing = identifier
					this.installError = null

					try {
						const result = await application.api.post(
							'/administrator/api/extension-sources/install',
							{ data: { sourceId, identifier, version } },
						)

						// La nouvelle extension n'est visible qu'à la requête suivante.
						// Un échec de rafraîchissement ne remet pas en cause l'installation.
						try {
							await this.consult(sourceId)
						} catch {
							// Le store conserve l'erreur de consultation.
						}

						return result
					} catch (exception) {
						this.installError = exception instanceof Error ? exception.message : 'L’installation a échoué.'
						throw exception
					} finally {
						this.installing = null
					}
				},

				clearConsultation() {
					this.consultation = null
					this.consultError = null
					this.installError = null
				},

				clearError() {
					this.error = null
				},
			},
		})
	},
}
