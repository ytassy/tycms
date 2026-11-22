<template>
	<modal :visible="true" full-screen :closable="!saving" @close="close">
		<template #header>{{ entry ? 'Modifier' : 'Créer' }} {{ name }}</template>
		<template #body>
			<div v-if="loading" class="text-muted">Chargement du fichier…</div>
			<div v-else-if="loadError" class="alert alert-warning">{{ loadError }}</div>
			<template v-else>
				<p class="small text-muted mb-2">Texte UTF-8 · {{ maxBytes / 1024 / 1024 }} Mo maximum</p>
				<code_editor v-model="content" :syntax="extension === 'scss' ? 'scss' : 'text'" :max-bytes="maxBytes" :read-only="saving" :aria-label="`Contenu de ${name}`" class="text-file-editor__code" />
			</template>
		</template>
		<template #footer>
			<span v-if="saveError" class="text-danger me-auto">{{ saveError }}</span>
			<button type="button" class="btn btn-outline-secondary esc me-2" :disabled="saving" @click="close">Fermer</button>
			<button type="button" class="btn btn-outline-primary ctrl-s  me-2" :disabled="loading || !!loadError || saving || !canSave" @click="save(false)">Enregistrer</button>
			<button type="button" class="btn btn-primary" :disabled="loading || !!loadError || saving" @click="save(true)">Enregistrer et fermer</button>
		</template>
	</modal>
</template>

<script>

export default {
	props: {
		entry: { type: Object, default: null },
		name: { type: String, required: true },
		spaceId: { type: Number, required: true },
		folderPath: { type: String, required: true },
		maxBytes: { type: Number, required: true },
	},
	emits: ['close', 'saved'],
	data() { return { content: '', savedContent: '', version: null, savedPath: this.entry?.path ?? null, loading: !!this.entry, loadError: '', saveError: '', saving: false } },
	computed: {
		store() { return this.stores.useAdminMediasStore() },
		extension() { return this.name.split('.').pop().toLowerCase() },
		canSave() { return this.savedPath === null || this.content !== this.savedContent },
	},
	mounted() { if (this.entry) this.load() },
	methods: {
		async load() {
			try {
				const result = await this.store.readEditableFile(this.spaceId, this.entry.path)
				this.content = result.content
				this.savedContent = result.content
				this.version = result.version
			} catch (_) { this.loadError = 'Impossible de charger ce fichier pour modification.' }
			finally { this.loading = false }
		},
		async close() {
			if (this.saving) return
			if (this.canSave && !await this.stores.useAppStore().askYesNo('Fermer sans enregistrer les modifications ?')) return
			this.$emit('close')
		},
		async save(closeAfterSave) {
			if (this.saving || this.loading || this.loadError) return
			if (!this.canSave) { if (closeAfterSave) this.$emit('close'); return }
			this.saving = true
			this.saveError = ''
			try {
				const path = this.savedPath ?? (this.folderPath ? `${this.folderPath}/${this.name}` : this.name)
				const result = this.savedPath === null
					? await this.store.createEditableFile(this.spaceId, this.folderPath, this.name, this.content)
					: await this.store.saveEditableFile(this.spaceId, path, this.content, this.version)
				this.version = result.version
				this.savedPath = path
				this.savedContent = this.content
				this.$emit('saved', { path, spaceId: this.spaceId, folderPath: this.folderPath })
				if (closeAfterSave) this.$emit('close')
			} catch (_) { this.saveError = 'Impossible d’enregistrer le fichier. Vérifiez qu’il n’a pas été modifié ailleurs.' }
			finally { this.saving = false }
		},
	},
}
</script>

<style scoped>
.text-file-editor__code :deep(.cm-editor) { min-height: 55vh; }
.text-file-editor__code :deep(.cm-scroller) { max-height: calc(100vh - 18rem); overflow: auto; }
</style>
