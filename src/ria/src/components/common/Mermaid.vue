<template>
	<div ref="diagramRoot" :id="diagramId" class="tytools-mermaid" :style="diagramStyle"
		:data-mermaid-look="settings.look" :data-mermaid-theme="settings.theme"
		:data-mermaid-sketch="String(settings.sketch)" :data-mermaid-wrap-width="settings.wrapwidth">
		<pre class="tytools-mermaid-source" hidden>{{ diagram }}</pre>
		<div class="tytools-mermaid-output" role="img" aria-label="Diagramme Mermaid"></div>
	</div>
</template>
<script>
import { getMermaidRuntime, configuredMermaidOptions } from './mermaid/runtime.js'

let nextDiagram = 0
export default {
	props: { diagram: { type: String, required: true }, options: { type: Object, default: () => ({}) } },
	data: () => ({ diagramId: `mermaid-vue-${Date.now()}-${++nextDiagram}` }),
	computed: {
		settings() {
			return { width: '95%', align: 'center', look: 'auto', theme: 'auto', sketch: '1', wrapwidth: 120,
				...configuredMermaidOptions(), ...this.options }
		},
		diagramStyle() {
			const width = /^(?:(?:10000|[1-9][0-9]{0,3})px|100%|[1-9][0-9]?%)$/.test(this.settings.width) ? this.settings.width : '95%'
			return { width, maxWidth: '100%', marginLeft: this.settings.align === 'left' ? '0' : 'auto',
				marginRight: this.settings.align === 'right' ? '0' : 'auto' }
		},
		signature() { return JSON.stringify([this.diagram, this.settings]) },
	},
	watch: { signature() { this.draw() } },
	mounted() { this.draw() },
	methods: {
		async draw() {
			await this.$nextTick()
			const element = this.$refs.diagramRoot
			if (!element) return
			try {
				const runtime = await getMermaidRuntime()
				await runtime.renderElement(element)
			} catch {
				const output = element.querySelector('.tytools-mermaid-output')
				if (element.isConnected && output) output.textContent = 'Impossible de charger Mermaid.'
			}
		},
	},
}
</script>
