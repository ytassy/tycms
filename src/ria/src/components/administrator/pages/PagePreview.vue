<template>
	<div class="page-preview-backdrop" role="presentation" @click.self="$emit('close')">
		<section class="page-preview-dialog" role="dialog" aria-modal="true" aria-label="Rendu fidèle de la page">
			<header class="page-preview-header">
				<div><h2 class="h5 mb-0">Rendu fidèle du brouillon</h2><small class="text-muted">Rendu PHP et CSS sans enregistrement</small></div>
				<button type="button" class="btn btn-outline-secondary btn-sm esc" @click="$emit('close')">Fermer</button>
			</header>
			<div v-if="loading" class="p-4 text-muted">Préparation de l’aperçu…</div>
			<div v-else-if="error" class="alert alert-danger m-3" role="alert">{{ error }}</div>
			<iframe v-else class="page-preview-frame" title="Rendu fidèle de la page" sandbox="allow-scripts" :srcdoc="documentHtml" />
		</section>
	</div>
</template>

<script>
import { pagePreviewDocument } from './pagePreviewDocument.js'
export default {
	emits: ['close'],
	data: () => ({ loading: true, error: '', result: null }),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		documentHtml() {
			return pagePreviewDocument(this.result, this.store.previewLocale, window.location.origin)
		},
	},
	async mounted() {
		try { this.result = await this.store.preview() } catch (error) { this.error = error.message } finally { this.loading = false }
	},
}
</script>
