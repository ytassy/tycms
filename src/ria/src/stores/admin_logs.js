import { defineStore } from 'pinia'

export const useAdminLogsStore = {
	get(_vueApp, application) {
		let revision = 0
		return defineStore('admin_logs', {
			state: () => ({ status: null, entries: [], cursor: null, busy: false, failure: '', corruptLines: 0, applied: '' }),
			actions: {
				clear() {
					revision++
					this.$reset()
				},
				async load(filters, next = false) {
					if (this.busy) return
					const serialized = JSON.stringify(filters)
					if (next && (!this.cursor || this.applied !== serialized)) return
					this.busy = true
					this.failure = ''
					const current = ++revision
					try {
						const payload = { filters: serialized }
						if (next) payload.cursor = this.cursor
						const result = await application.api.post('/administrator/api/logs/read', payload)
						if (current !== revision) return
						// Une seule page en mémoire : poursuivre ne charge pas tout l'historique.
						this.entries = result.entries
						this.cursor = result.cursor
						this.corruptLines = result.corrupt_lines
						this.applied = serialized
						this.failure = result.storage_error ?? ''
						const status = await application.api.get('/administrator/api/logs/status')
						if (current === revision) this.status = status
					} catch (_) {
						if (current !== revision) return
						this.entries = []
						this.status = null
						this.cursor = null
						this.failure = 'Lecture impossible. Actualisez les journaux.'
					} finally {
						if (current === revision) this.busy = false
					}
				},
			},
		})
	},
}
