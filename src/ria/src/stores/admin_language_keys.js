import { defineStore } from 'pinia'

export const useAdminLanguageKeysStore = {
	get(vueApp, application) {
		// Mutualiser les requêtes simultanées des champs d'une même page.
		const pending = new Map()

		return defineStore('admin_language_keys', {
			state: () => ({ entriesByContext: {}, loadingByContext: {}, errorsByContext: {} }),

			actions: {
				contextKey(spaceId, locale) { return `${spaceId}:${locale}` },

				entriesFor(spaceId, locale) {
					return this.entriesByContext[this.contextKey(spaceId, locale)] ?? []
				},

				isLoading(spaceId, locale) {
					return Boolean(this.loadingByContext[this.contextKey(spaceId, locale)])
				},

				errorFor(spaceId, locale) {
					return this.errorsByContext[this.contextKey(spaceId, locale)] ?? ''
				},

				async load(spaceId, locale) {
					if (!Number.isInteger(spaceId) || spaceId < 1 || !locale) return

					const context = this.contextKey(spaceId, locale)
					if (pending.has(context)) return pending.get(context)

					// Recharger à chaque ouverture : une surcharge a pu changer ailleurs.
					this.loadingByContext[context] = true
					this.errorsByContext[context] = ''

					const request = (async () => {
						try {
							const response = await application.api.get('/administrator/api/languages/selectable-keys', { spaceId, locale })
							this.entriesByContext[context] = response.entries ?? []
						} catch {
							this.entriesByContext[context] = []
							this.errorsByContext[context] = 'Impossible de charger les clés de langue.'
						} finally {
							this.loadingByContext[context] = false
							pending.delete(context)
						}
					})()

					pending.set(context, request)
					return request
				},
			},
		})
	},
}
