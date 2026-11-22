<template>
	<div class="page-field">
		<div class="clearfix">
			<label>Code Mermaid</label>
			<a href="https://mermaid.live/" target="_blank" rel="noopener noreferrer" class="float-end small">Ouvrir Mermaid Live</a>
		</div>
		<code_editor v-model="node.props.diagram" syntax="mermaid" :max-bytes="30000" :read-only="disabled" aria-label="Code Mermaid" class="page-code-editor" />
	</div>
	<p class="small text-muted">Les paramètres laissés sur « Défaut du Space » suivent la configuration Core.</p>
	<label class="page-field">Largeur
		<input :value="node.props.options?.width ?? ''" :placeholder="`Défaut du Space : ${defaults.width}`" class="form-control form-control-sm" @input="setOption('width', $event.target.value.trim())">
		<small class="text-muted">1 à 10000 px, ou 1 à 100 %. Vider pour hériter.</small>
	</label>
	<label v-for="field in selects" :key="field.name" class="page-field">{{ field.label }}
		<select :value="node.props.options?.[field.name] ?? ''" class="form-select form-select-sm" @change="setOption(field.name, $event.target.value)">
			<option value="">Défaut du Space : {{ defaults[field.name] }}</option>
			<option v-for="value in field.values" :key="value" :value="value">{{ value }}</option>
		</select>
	</label>
	<label class="page-field">Retour à la ligne flowchart (px)
		<input :value="node.props.options?.wrapwidth ?? ''" type="number" min="40" max="1000" step="1" :placeholder="`Défaut du Space : ${defaults.wrapwidth}`" class="form-control form-control-sm" @input="setOption('wrapwidth', $event.target.value)">
	</label>
	<button type="button" class="btn btn-outline-secondary btn-sm" @click="node.props.options = {}">Tout réinitialiser aux défauts du Space</button>
</template>
<script>
import { mermaidDefaultsForPage, setMermaidOverride } from './pageMermaidOptions.js'

export default {
	props: { selected: Object, context: Object, node: { type: Object, required: true } },
	data: () => ({ selects: [
		{ name: 'align', label: 'Alignement', values: ['left', 'center', 'right'] },
		{ name: 'look', label: 'Rendu natif', values: ['auto', 'classic', 'handDrawn', 'neo'] },
		{ name: 'theme', label: 'Thème', values: ['auto', 'default', 'base', 'dark', 'forest', 'neutral', 'neo', 'neo-dark', 'redux', 'redux-dark', 'redux-color', 'redux-dark-color'] },
		{ name: 'sketch', label: 'Effet croquis : 0 = non, 1 = oui', values: ['0', '1'] },
	] }),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		disabled() { return !this.store.current.canEdit },
		defaults() { return mermaidDefaultsForPage(this.store) },
	},
	methods: {
		setOption(name, value) { if (!this.disabled) setMermaidOverride(this.node.props, name, value) },
	},
}
</script>
