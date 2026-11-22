<template>
	<div class="page-canvas-api">
		<span v-if="loading" class="page-canvas-placeholder">Préparation de l’aperçu…</span>
		<span v-else-if="error" class="page-canvas-placeholder">{{ error }}</span>
		<iframe ref="frame" v-else-if="result" :srcdoc="documentHtml" title="Aperçu du composant" sandbox="allow-scripts"
			:style="{ width: '100%', height: `${height}px`, border: 0, pointerEvents: 'none' }" />
	</div>
</template>
<script>
import { pagePreviewDocument } from './pagePreviewDocument.js'
import { findNode } from './pageEditorModel.js'
export default {
	props: { item: { type: Object, required: true } },
	data: () => ({ timer: null, version: 0, loading: false, error: '', result: null, height: 48, resizeToken: '' }),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		requestKey() {
			const page = this.store.current
			const ancestorVisibility = []
			let parentId = findNode(page?.data, this.item.id)?.parentId

			// Le serveur applique aussi la visibilité des conteneurs du composant.
			while (parentId && parentId !== 'page') {
				const parent = findNode(page.data, parentId)
				if (!parent) break
				const common = parent.node.props?.common
				ancestorVisibility.push([common?.visible, common?.languages, common?.role_ids])
				parentId = parent.parentId
			}

			// Comparer le contenu évite aussi un appel après une copie inchangée de l'arbre.
			return JSON.stringify([this.store.editorSession, page?.id, page?.space_id,
				this.store.previewLocale, this.item, ancestorVisibility])
		},
		documentHtml() { return pagePreviewDocument(this.result, this.store.previewLocale, window.location.origin, { resizeToken: this.resizeToken }) },
	},
	watch: { requestKey: { immediate: true, handler() { this.schedule() } } },
	mounted() { window.addEventListener('message', this.receiveSize) },
	beforeUnmount() { clearTimeout(this.timer); this.version++; window.removeEventListener('message', this.receiveSize) },
	methods: {
        receiveSize(event) {
            const value = event.data
            if (event.source !== this.$refs.frame?.contentWindow || event.origin !== 'null'
                || value?.type !== 'tycms:page-canvas-size' || value.token !== this.resizeToken
                || !Number.isInteger(value.height) || value.height < 24 || value.height > 100000) return
            this.height = value.height
        },
		schedule() {
			clearTimeout(this.timer)
			const version = ++this.version
			this.resizeToken = globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`
			this.result = null
			this.error = ''
			this.loading = true
			this.timer = setTimeout(async () => {
				try {
					const result = await this.store.previewComponent(this.item.id)
					if (version === this.version) this.result = result
				} catch (error) { if (version === this.version) this.error = error.message || 'Aperçu indisponible.' }
				finally { if (version === this.version) this.loading = false }
			}, 350)
		},
	},
}
</script>
