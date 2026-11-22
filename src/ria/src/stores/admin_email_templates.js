import { defineStore } from 'pinia'

export const useAdminEmailTemplatesStore = {
	get(_vueApp, application) {
		return defineStore('admin_email_templates', {
			actions: {
				async spaces() {
					return application.api.get('/administrator/api/email-templates/spaces')
				},
				async entries(spaceId, locale) {
					return application.api.get('/administrator/api/email-templates', { spaceId, locale })
				},
				async save(spaceId, data) {
					return application.api.post('/administrator/api/email-templates/save', { spaceId, data })
				},
				async restore(spaceId, data) {
					return application.api.post('/administrator/api/email-templates/restore', { spaceId, data })
				},
				async preview(spaceId, data) {
					return application.api.post('/administrator/api/email-templates/preview', { spaceId, data })
				},
			},
		})
	},
}
