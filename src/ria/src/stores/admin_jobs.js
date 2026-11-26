import { defineStore } from 'pinia'

const base = '/administrator/api/jobs'

export const useAdminJobsStore = {
	get(_vueApp, application) {
		return defineStore('admin_jobs', {
			state: () => ({
				loading: false,
				filters: { status: '', type: '', spaceId: '' },
				page: 1,
				result: null,
				detail: null,
				documentation: null,
			}),
			actions: {
				async load() {
					this.loading = true
					try {
						this.result = await application.api.get(base, {
							status: this.filters.status || undefined,
							type: this.filters.type || undefined,
							space_id: this.filters.spaceId || undefined,
							page: this.page,
						})
					} finally {
						this.loading = false
					}
				},
				async openDetail(id) {
					this.detail = await application.api.get(`${base}/${id}`)
				},
				async retry(id) {
					await application.api.post(`${base}/${id}/retry`, {})
					await this.load()
				},
				async cancel(id) {
					await application.api.post(`${base}/${id}/cancel`, {})
					await this.load()
				},
				async purge(days) {
					const result = await application.api.post(`${base}/purge`, { days })
					await this.load()
					return result
				},
				async loadDocumentation() {
					this.documentation = await application.api.get(`${base}/documentation`)
				},
			},
		})
	},
}
