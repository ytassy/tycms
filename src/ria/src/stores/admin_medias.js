import { defineStore } from 'pinia'

export const useAdminMediasStore = {
	get(vueApp, application) {
		return defineStore('admin_medias', {
			state: () => ({
				directory: null,
				listing: null,
			}),

			actions: {
				async loadDirectory() {
					this.directory = await application.api.get('/administrator/api/medias')
				},

				async list(spaceId, path = '') {
					this.listing = await application.api.get('/administrator/api/medias/list', { spaceId, path })
				},

				async createFolder(spaceId, path, name) {
					await application.api.post('/administrator/api/medias/folder/create', { spaceId, path, name })
				},

				async upload(spaceId, path, file, overwrite = false, onProgress = null) {
					const data = new FormData()
					data.append('spaceId', String(spaceId))
					data.append('path', path)
					data.append('overwrite', String(overwrite))
					data.append('file', file, file.name)
					await application.api.post('/administrator/api/medias/upload', data, false, onProgress ? { onUploadProgress: onProgress } : {})
				},

				async rename(spaceId, path, name) {
					await application.api.post('/administrator/api/medias/rename', { spaceId, path, name })
				},

				async move(spaceId, path, destinationPath) {
					await application.api.post('/administrator/api/medias/move', { spaceId, path, destinationPath })
				},

				async delete(spaceId, path) {
					await application.api.post('/administrator/api/medias/delete', { spaceId, path })
				},
				async readEditableFile(spaceId, path) {
					return await application.api.get('/administrator/api/medias/file/read', { spaceId, path })
				},
				async createEditableFile(spaceId, path, name, content) {
					return await application.api.post('/administrator/api/medias/file/create', { spaceId, path, name, content })
				},
				async saveEditableFile(spaceId, path, content, version) {
					return await application.api.post('/administrator/api/medias/file/save', { spaceId, path, content, version })
				},
			},
		})
	},
}
