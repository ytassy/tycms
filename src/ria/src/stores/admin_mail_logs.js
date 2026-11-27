import { defineStore } from 'pinia'

const base = '/administrator/api/mail-logs'

export const useAdminMailLogsStore = {
	get(_vueApp, application) {
		return defineStore('admin_mail_logs', {
			state: () => ({
				status: null,
				items: [],
				truncated: false,
				loading: false,
				busy: false,
				failure: '',
				details: {},
			}),
			actions: {
				async load() {
					this.failure = ''
					const [status, list] = await Promise.allSettled([
						this.loadStatus(),
						this.loadList(),
					])
					if (status.status === 'rejected' || list.status === 'rejected') {
						this.failure = 'Le journal des e-mails est indisponible.'
						throw list.status === 'rejected' ? list.reason : status.reason
					}
				},
				async loadStatus() {
					try {
						this.status = await application.api.get(`${base}/status`)
					} catch (exception) {
						this.status = null
						throw exception
					}
				},
				async loadList() {
					this.loading = true
					try {
						const result = await application.api.get(base)
						this.items = result.items ?? []
						this.truncated = Boolean(result.truncated)
						// Le détail est rechargé à la prochaine ouverture.
						this.details = {}
					} catch (exception) {
						this.items = []
						this.truncated = false
						throw exception
					} finally {
						this.loading = false
					}
				},
				async loadDetail(id) {
					const current = this.details[id]
					if (current?.loading || current?.item) return

					this.details[id] = { loading: true, item: null, error: '' }
					try {
						const result = await application.api.get(`${base}/${id}`)
						this.details[id] = { loading: false, item: result.item, error: '' }
					} catch (exception) {
						this.details[id] = {
							loading: false,
							item: null,
							error: 'Détail de l’e-mail indisponible.',
						}
						throw exception
					}
				},
				async purge(days) {
					this.busy = true
					try {
						return await application.api.post(`${base}/purge`, { days })
					} finally {
						this.busy = false
					}
				},
			},
		})
	},
}
