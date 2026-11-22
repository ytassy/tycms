import { defineStore } from 'pinia'

export const useAdminSpacesStore = {
	get(vueApp, application) {
		return defineStore('admin_spaces', {
			state: () => ({ spaces: null }),

			actions: {
				async loadSpaces() {
					const response = await application.api.get('/administrator/api/spaces')
					this.spaces = response.spaces ?? []
				},

				async createSpace(data) {
					await application.api.post('/administrator/api/spaces/create', { data })
					await this.loadSpaces()
				},

				async updateSpace(spaceId, data) {
					await application.api.post('/administrator/api/spaces/update', { spaceId, data })
					await this.loadSpaces()
				},
				async getLanguages(spaceId) {
					return await application.api.get('/administrator/api/spaces/languages', { spaceId })
				},
				async saveLanguages(spaceId, languages) {
					return await application.api.post('/administrator/api/spaces/languages/save', { spaceId, languages })
				},
				async getNavigation(spaceId) {
					return await application.api.get('/administrator/api/spaces/navigation', { spaceId })
				},
				async saveNavigation(spaceId, navigation) {
					return await application.api.post('/administrator/api/spaces/navigation/save', { spaceId, navigation })
				},

				async sendTestMail(spaceId, recipient, settings) {
					return await application.api.post('/administrator/api/spaces/mail/test', { spaceId, recipient, settings })
				},

				async deleteSpace(spaceId) {
					await application.api.post('/administrator/api/spaces/delete', { spaceId })
					await this.loadSpaces()
				},
			},
		})
	},
}
