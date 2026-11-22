<template>
	<div class="page-canvas-files" @click.capture="preventNavigation">
		<span v-if="options.folder == null" class="page-canvas-placeholder">Liste de fichiers : choisissez un dossier dans les propriétés.</span>
		<span v-else-if="!canReadMedia" class="page-canvas-placeholder">Liste de fichiers : aperçu disponible avec la lecture des médias.</span>
		<span v-else-if="loading" class="page-canvas-placeholder">Chargement des fichiers…</span>
		<span v-else-if="error" class="page-canvas-placeholder">{{ error }}</span>
		<div v-else-if="html" v-html="html"></div>
		<span v-else class="page-canvas-placeholder">Aucun fichier dans ce dossier.</span>
	</div>
</template>
<script>
export default {
	props: { options: { type: Object, required: true } },
	data: () => ({ html: '', error: '', loading: false, timer: null, requestVersion: 0 }),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		canReadMedia() { return this.acl.hasGlobalPermission('MEDIA_READ', this.store.current.space_id) || this.acl.hasGlobalPermission('MEDIA_CRUD', this.store.current.space_id) },
		// Les styles communs ne changent pas le contenu du dossier.
		requestKey() {
			const { common, ...options } = this.options
			return JSON.stringify([this.store.current.space_id, this.store.previewLocale, options])
		},
	},
	watch: {
		requestKey: { immediate: true, handler() { this.schedule() } },
	},
	beforeUnmount() { clearTimeout(this.timer); this.requestVersion++ },
	methods: {
		preventNavigation(event) { if (event.target.closest('a')) event.preventDefault() },
		schedule() {
			clearTimeout(this.timer)
			this.requestVersion++
			this.html = ''
			this.error = ''
			this.loading = false
			if (this.options.folder == null || !this.canReadMedia) return
			this.loading = true
			const version = this.requestVersion
			this.timer = setTimeout(() => this.load(version), 300)
		},
		async load(version) {
			try {
				const { common, ...options } = this.options
				const result = await this.api.post('/administrator/api/medias/page-files', {
					spaceId: this.store.current.space_id, locale: this.store.previewLocale, options,
				})
				// Ce HTML provient uniquement du renderer serveur qui échappe les noms des fichiers.
				if (version === this.requestVersion) this.html = result.html ?? ''
			} catch (error) {
				if (version === this.requestVersion) this.error = error.message || 'Impossible de charger ce dossier.'
			} finally {
				if (version === this.requestVersion) this.loading = false
			}
		},
	},
}
</script>
