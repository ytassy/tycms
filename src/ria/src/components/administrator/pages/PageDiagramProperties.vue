<template>
	<button type="button" class="btn btn-outline-primary btn-sm mb-2" :disabled="disabled" @click="editing = true">
		{{ node.props.svg ? 'Modifier le diagramme' : 'Créer un diagramme' }}
	</button>
	<p class="small text-muted">{{ weight }} / {{ maximumLabel }} maximum, données de réédition comprises. Les images PNG/JPEG sont refusées à l’enregistrement.</p>
	<LocalizedTextField v-model="node.props.alt" label="Texte alternatif" :locales="store.currentSpaceLanguages"
		:locale="store.previewLocale" :space-id="store.current.space_id" :disabled="disabled" />
	<DiagramEditor v-if="editing" :visible="true" name="diagramme.svg" format="xmlsvg" :source="node.props.svg || ''"
		:template-colors="context.plg_core_template_colors ?? []" :export-options="{ embedFonts: false, theme: 'light' }"
		:saving="saving" :save-error="error" @close="editing = false" @save="applyDiagram" />
</template>

<script>
import DiagramEditor from '../../common/DiagramEditor.vue'
import LocalizedTextField from '../../common/LocalizedTextField.vue'
import { diagramSvgFromDataUrl } from './pageDiagram.js'
import { formatPageBytes } from './pageSize.js'

export default {
	components: { DiagramEditor, LocalizedTextField },
	props: { node: { type: Object, required: true }, context: { type: Object, default: () => ({}) }, selected: Object },
	data: () => ({ editing: false, saving: false, error: '' }),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		disabled() { return !this.store.current?.canEdit || this.store.saving },
		maximum() { return this.context.plg_core_diagram_max_size ?? 250_000 },
		maximumLabel() { return formatPageBytes(this.maximum) },
		weight() { return formatPageBytes(new TextEncoder().encode(this.node.props.svg ?? '').length) },
	},
	watch: { 'node.id'() { this.editing = false; this.error = '' } },
	methods: {
		async applyDiagram({ dataUrl, closeAfterSave }) {
			if (this.disabled || this.saving) return
			let svg
			try {
				svg = diagramSvgFromDataUrl(dataUrl, this.maximum)
			} catch (error) {
				// Réutiliser la modale globale pour les erreurs locales, avant tout appel API.
				this.showModaleError(error?.message || 'Export SVG du diagramme invalide.')
				return
			}

			this.saving = true
			this.error = ''
			try {
				// La validation serveur précède l'application au brouillon, sans sauvegarder la page.
				if (!await this.store.updateDiagram(this.node.id, svg)) throw new Error('La page a changé. Rouvrez le diagramme pour réessayer.')
				if (closeAfterSave) this.editing = false
			} catch (error) {
				this.error = error?.message || 'Impossible d’appliquer ce diagramme.'
			} finally { this.saving = false }
		},
	},
}
</script>
