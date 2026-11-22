import { defineStore } from 'pinia'

export const useAdminAiStore = {
	get(vueApp, application) {
		return defineStore('admin_ai', {
			state: () => ({ spaceId: null, providers: [], models: {}, requestId: 0 }),
			actions: {
				async load(spaceId) {
					const requestId = ++this.requestId
					const response = await application.api.get('/administrator/api/ai/configuration', { spaceId })
					if (requestId !== this.requestId) return false
					this.spaceId = spaceId
					this.providers = response.providers ?? []
					this.models = response.models ?? {}
					return true
				},
				async create(spaceId, data) {
					await application.api.post('/administrator/api/ai/providers/create', { spaceId, data })
					await this.load(spaceId)
				},
				async update(spaceId, providerId, data) {
					await application.api.post('/administrator/api/ai/providers/update', { providerId, data })
					await this.load(spaceId)
				},
				async remove(spaceId, providerId) {
					await application.api.post('/administrator/api/ai/providers/delete', { providerId })
					await this.load(spaceId)
				},
				async saveModels(spaceId, models) {
					await application.api.post('/administrator/api/ai/models/save', { spaceId, models })
					await this.load(spaceId)
				},
			},
		})
	},
}
