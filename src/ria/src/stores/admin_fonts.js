import { defineStore } from 'pinia'

export const useAdminFontsStore = {
	get(vueApp, application) {
		return defineStore('admin_fonts', {
			state: () => ({ families: [], installed: {}, canManage: false, spaceIds: [], catalogUpdatedAt: null, loading: false, busy: false, refreshing: false, error: '' }),
			actions: {
				async load() {
					this.loading = true
					this.error = ''
					try {
						const response = await application.api.get('/administrator/api/fonts')
						if (!response || !Array.isArray(response.families) || typeof response.canManage !== 'boolean'
							|| !Array.isArray(response.spaceIds) || !response.installed || typeof response.installed !== 'object' || Array.isArray(response.installed)) {
							throw new Error('Réponse invalide du serveur : la bibliothèque des polices n’a pas pu être vérifiée.')
						}
						this.families = response.families
						this.installed = response.installed ?? {}
						this.canManage = response.canManage === true
						this.spaceIds = response.spaceIds ?? []
						this.catalogUpdatedAt = response.catalogUpdatedAt ?? null
					} catch (error) {
						this.error = error.response?.data?.message || error.message || 'Impossible de charger les polices.'
					} finally { this.loading = false }
				},
				async refreshCatalog() {
					if (this.busy) return null
					this.busy = true
					this.refreshing = true
					this.error = ''
					try {
						const response = await application.api.post('/administrator/api/fonts/catalog/refresh', {})
						if (response?.refreshed !== true || !Number.isInteger(response.catalog?.count) || response.catalog.count < 1
							|| typeof response.catalog.updatedAt !== 'string') {
							throw new Error('Le serveur n’a pas confirmé l’actualisation du catalogue.')
						}
						await this.load()
						if (this.error) return null
						if (this.families.length !== response.catalog.count || this.catalogUpdatedAt !== response.catalog.updatedAt) {
							throw new Error('Le catalogue rechargé ne correspond pas à l’actualisation confirmée.')
						}
						return response.catalog
					} catch (error) {
						this.error = error.response?.data?.message || error.message || 'Impossible d’actualiser le catalogue.'
						return null
					} finally {
						this.busy = false
						this.refreshing = false
					}
				},
				async mutate(action, params) {
					if (this.busy) return false
					this.busy = true
					this.error = ''
					try {
						const response = await application.api.post(`/administrator/api/fonts/${action}`, params)
						// Un HTTP 200 contenant un warning PHP ou un résultat incomplet n'est pas un succès.
						if (!response || typeof response !== 'object'
							|| (action === 'install' && (response.font?.id !== params.id || response.font?.verified !== true))
							|| (action === 'enabled' && response.updated !== true)
							|| (action === 'delete' && response.deleted !== true)) {
							throw new Error('Le serveur n’a pas confirmé la modification des polices.')
						}
						await this.load()
						if (this.error) return false
						if ((action === 'install' && this.installed[params.id]?.version !== response.font.version)
							|| (action === 'enabled' && this.installed[params.id]?.enabled !== params.enabled)
							|| (action === 'delete' && this.installed[params.id])) {
							throw new Error('La modification n’est pas confirmée dans la bibliothèque. Rechargez la page pour vérifier son état.')
						}
						return true
					} catch (error) {
						this.error = error.response?.data?.message || error.message || 'La bibliothèque n’a pas pu être modifiée.'
						return false
					} finally { this.busy = false }
				},
			},
		})
	},
}
