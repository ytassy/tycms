import { defineStore } from 'pinia'

export const useAdminSystemUpdatesStore = {
	get(_vueApp, application) {
		return defineStore('admin_system_updates', {
			state: () => ({ context: null, result: null, checking: false, downloading: false, downloaded: null, preparing: false, prepared: null, resetting: false, cleaning: false }),
			actions: {
				async load() {
					this.result = null
					this.downloaded = null
					this.context = await application.api.get('/administrator/api/system-updates')
				},
				async check(sourceVersion) {
					if (this.checking || this.downloading || this.preparing || this.resetting) return
					this.result = null
					this.downloaded = null
					this.checking = true
					try {
						const payload = this.context?.simulation_allowed ? { sourceVersion } : {}
						this.result = await application.api.post('/administrator/api/system-updates/check', payload)
					} finally {
						this.checking = false
					}
				},
				async reset() {
					if (this.checking || this.downloading || this.preparing || this.resetting) return false
					this.resetting = true
					// Ne pas conserver un lien dont le serveur peut avoir révoqué l'accès.
					this.result = null
					this.downloaded = null
					this.prepared = null
					try {
						await application.api.post('/administrator/api/system-updates/reset', {})
						// L'état de nettoyage et les sauvegardes restantes ont changé.
						this.context = await application.api.get('/administrator/api/system-updates')
						return true
					} finally {
						this.resetting = false
					}
				},
				async cleanup() {
					if (this.checking || this.downloading || this.preparing || this.resetting || this.cleaning) return false
					this.cleaning = true
					try {
						await application.api.post('/administrator/api/system-updates/cleanup', {})
						this.prepared = null
						this.context = await application.api.get('/administrator/api/system-updates')
						return true
					} finally {
						this.cleaning = false
					}
				},
				async prepare() {
					if (this.checking || this.downloading || this.preparing || this.resetting || !this.downloaded) return
					const receipt = this.downloaded
					const payload = { version: receipt.version, sha256: receipt.sha256 }
					if (receipt.simulation) payload.sourceVersion = receipt.source_version
					this.preparing = true
					try {
						this.prepared = await application.api.post('/administrator/api/system-updates/prepare', payload)
					} finally {
						this.preparing = false
					}
				},
				async download() {
					if (this.checking || this.downloading || this.preparing || this.resetting || !this.result?.available_update) return
					const result = this.result
					const update = result.available_update
					const payload = { version: update.version, sha256: update.archive.sha256 }
					if (result.simulation) payload.sourceVersion = result.source_version
					this.downloaded = null
					this.downloading = true
					try {
						this.downloaded = await application.api.post('/administrator/api/system-updates/download', payload)
					} finally {
						this.downloading = false
					}
				},
			},
		})
	},
}
