import { defineStore } from 'pinia'

export const useAdminSsoProvidersStore = {
	get(vueApp, application) {
		return defineStore('admin_sso_providers', {
			state: () => ({ providers: null, spaces: [] }),
			actions: {
				async load() {
					const result = await application.api.get('/administrator/api/sso-providers')
					this.providers = result.providers ?? []
					this.spaces = result.spaces ?? []
				},
				async create(data) {
					await application.api.post('/administrator/api/sso-providers/create', { data })
					await this.load()
				},
				async update(providerId, data) {
					await application.api.post('/administrator/api/sso-providers/update', { providerId, data })
					await this.load()
				},
			},
		})
	},
}
