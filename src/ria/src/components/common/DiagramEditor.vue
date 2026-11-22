<template>
	<modal :visible="visible" full-screen :closable="!saving" @close="close">
		<template #header>
			<div>{{ title }}</div>
			<small class="text-body-secondary fw-normal">Édition sur le site externe
				<a href="https://www.diagrams.net/" target="_blank" rel="noopener noreferrer">diagrams.net</a>.
			</small>
		</template>
		<template #body>
			<div ref="editor" class="diagram-editor">
				<div v-if="loading" class="diagram-editor__loading"><spinner /> Chargement de diagram.net…</div>
				<iframe ref="frame" class="diagram-editor__frame" :src="frameUrl" title="Éditeur diagram.net" />
			</div>
		</template>
		<template #footer>
			<span v-if="error || saveError" class="text-danger me-auto">{{ error || saveError }}</span>
			<button type="button" class="btn btn-outline-secondary esc" :disabled="saving" @click="close">Fermer</button>
		</template>
	</modal>
</template>

<script>

import { resolveTemplatePalette } from './templateColorPalette.js'
import { diagramPresetColors } from './diagramPalette.js'

const DIAGRAM_ORIGIN = 'https://embed.diagrams.net'

export default {
	props: {
		visible: { type: Boolean, default: false },
		entry: { type: Object, default: null },
		name: { type: String, required: true },
		format: { type: String, required: true },
		saving: { type: Boolean, default: false },
		saveError: { type: String, default: '' },
		source: { type: String, default: '' },
		templateColors: { type: Array, default: null },
		exportOptions: { type: Object, default: () => ({}) },
	},
	emits: ['close', 'save'],
	computed: {
		title() { return `${this.entry || this.source ? 'Modifier' : 'Créer'} un diagramme : ${this.name}` },
	},

	data() {
		return {
			frameUrl: `${DIAGRAM_ORIGIN}/?embed=1&proto=json&spin=1&libraries=1&ui=kennedy&configure=1`,
			diagramData: '', loading: false, error: '', frameReady: false, dataReady: false, loadSent: false,
			exportPending: false, closeAfterSave: false,
		}
	},

	watch: {
		visible(value) {
			if (value) this.start()
			else this.reset()
		},
	},
	mounted() { if (this.visible) this.start() },

	beforeUnmount() {
		window.removeEventListener('message', this.handleMessage)
	},

	methods: {
		async start() {
			this.reset()
			this.loading = true
			window.addEventListener('message', this.handleMessage)
			try {
				this.diagramData = this.source || (this.entry?.url ? await this.fetchAsDataUrl(this.entry.url) : '')
				this.dataReady = true
				this.loadDiagram()
			} catch (_) {
				this.error = 'Impossible de charger ce diagramme.'
			} finally {
				this.loading = false
			}
		},

		reset() {
			window.removeEventListener('message', this.handleMessage)
			this.diagramData = ''
			this.loading = false
			this.error = ''
			this.frameReady = false
			this.dataReady = false
			this.loadSent = false
			this.exportPending = false
			this.closeAfterSave = false
		},

		close() {
			if (!this.saving && !this.exportPending) this.$emit('close')
		},

		async fetchAsDataUrl(url) {
			const response = await fetch(url)
			if (!response.ok) throw new Error('Unable to load diagram')
			const blob = await response.blob()
			return new Promise((resolve, reject) => {
				const reader = new FileReader()
				reader.onload = () => resolve(reader.result)
				reader.onerror = reject
				reader.readAsDataURL(blob)
			})
		},

		handleMessage(event) {
			if (event.origin !== DIAGRAM_ORIGIN || event.source !== this.$refs.frame?.contentWindow || typeof event.data !== 'string') return
			let message
			try { message = JSON.parse(event.data) } catch (_) { return }
			if (!message?.event) return

			if (message.event === 'configure') {
				// Les couleurs CSS deviennent des teintes fixes, utilisables dans l'iframe externe.
				const palette = resolveTemplatePalette(this.$refs.editor, this.templateColors ?? this.conf.getTemplateColors())
				this.post({ action: 'configure', config: {
					customPresetColors: diagramPresetColors(palette),
					passThroughKeys: [{ key: 's', ctrl: true, command: 'save' }],
				} })
			} else if (message.event === 'init') {
				this.frameReady = true
				this.loadDiagram()
			} else if (message.event === 'shortcut' && message.command === 'save') {
				if (!this.exportPending && !this.saving) this.post({ action: 'invokeAction', actionName: 'save' })
			} else if (message.event === 'save') {
				if (this.exportPending || this.saving) return
				this.error = ''
				this.exportPending = true
				this.closeAfterSave = message.exit === true
				this.post({ ...this.exportOptions, action: 'export', format: this.format, xml: message.xml, spinKey: 'export' })
			} else if (message.event === 'export' && this.exportPending) {
				this.exportPending = false
				if (typeof message.data === 'string') this.$emit('save', { dataUrl: message.data, name: this.name, closeAfterSave: this.closeAfterSave })
				else this.error = 'Impossible d’exporter le diagramme.'
			} else if (message.event === 'exit') {
				this.close()
			}
		},

		post(message) {
			this.$refs.frame?.contentWindow?.postMessage(JSON.stringify(message), DIAGRAM_ORIGIN)
		},

		loadDiagram() {
			if (!this.frameReady || !this.dataReady || this.loadSent) return
			this.loadSent = true
			this.post({
				action: 'load',
				autosave: 0,
				saveAndExit: '1',
				modified: 'unsavedChanges',
				xml: this.diagramData,
				title: this.name,
			})
		},
	},
}
</script>

<style scoped>
.diagram-editor { height: calc(100vh - 13rem); min-height: 500px; position: relative; }
.diagram-editor__frame { width: 100%; height: 100%; border: 0; }
.diagram-editor__loading { position: absolute; inset: 0; z-index: 1; display: grid; place-content: center; gap: .75rem; background: white; }
</style>
