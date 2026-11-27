import { defineStore } from 'pinia'

const base = '/administrator/api/mail-logs'

export const useAdminMailLogsStore = {
	get(_vueApp, application) {
		return defineStore('admin_mail_logs', {
			state: () => ({ status: null, busy: false, failure: '' }),
			actions: {
				async load() {
					this.failure = ''
					try {
						this.status = await application.api.get(`${base}/status`)
					} catch (exception) {
						this.status = null
						this.failure = 'État du journal des e-mails indisponible.'
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
