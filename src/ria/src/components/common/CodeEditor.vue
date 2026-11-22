<template>
	<div
		ref="editor"
		:class="['code-editor', { 'code-editor--invalid': invalid, 'code-editor--readonly': readOnly }]"
	/>
</template>

<script>
import { Compartment, EditorState, Prec } from '@codemirror/state'
import { EditorView, keymap } from '@codemirror/view'
import { indentUnit } from '@codemirror/language'
import { indentLess, insertTab } from '@codemirror/commands'
import { basicSetup } from 'codemirror'
import { mermaid } from 'codemirror-lang-mermaid'
import { css } from '@codemirror/lang-css'
import { html } from '@codemirror/lang-html'
import { javascript } from '@codemirror/lang-javascript'
import { json } from '@codemirror/lang-json'
import { sass } from '@codemirror/lang-sass'

const DEFAULT_SYNTAX = 'javascript'

function getSyntaxId(syntax) {
	const normalizedSyntax = typeof syntax === 'string' ? syntax.toLowerCase() : DEFAULT_SYNTAX

	return normalizedSyntax === 'js' ? DEFAULT_SYNTAX : normalizedSyntax
}

function getLanguageExtension(syntax) {
	switch (getSyntaxId(syntax)) {
		case 'text':
		case 'txt': return []
		case 'html': return html()
		case 'css': return css()
		case 'scss': return sass()
		case 'json': return json()
		case 'mermaid': return mermaid()
		default: return javascript()
	}
}

export default {
	props: {
		modelValue: { type: String, default: '' },
		syntax: { type: String, default: DEFAULT_SYNTAX },
		readOnly: { type: Boolean, default: false },
		maxBytes: { type: Number, default: null },
		wrapLines: { type: Boolean, default: false },
		inputId: { type: String, default: null },
		ariaLabel: { type: String, default: null },
		invalid: { type: Boolean, default: false },
	},
	emits: ['update:modelValue'],

	data() {
		return {
			view: null,
			languageCompartment: new Compartment(),
			editableCompartment: new Compartment(),
			maxBytesCompartment: new Compartment(),
		}
	},

	mounted() {
		if (this.$refs.editor === null) return
		this.view = new EditorView({
			parent: this.$refs.editor,
			state: EditorState.create({
				doc: this.modelValue,
				extensions: [
					basicSetup,
					// Tab insère une vraie tabulation ; les sélections utilisent la même unité.
					indentUnit.of('\t'),
					Prec.high(keymap.of([{
						key: 'Tab',
						run: view => !view.state.readOnly && insertTab(view),
						shift: view => !view.state.readOnly && indentLess(view),
					}])),
					// Les traductions longues replient les lignes sans changer les autres usages.
					...(this.wrapLines ? [EditorView.lineWrapping] : []),
					this.languageCompartment.of(getLanguageExtension(this.syntax)),
					this.editableCompartment.of(this.getEditableExtensions()),
					this.maxBytesCompartment.of(this.getMaxBytesExtensions()),
					EditorView.contentAttributes.of(this.getContentAttributes()),
					EditorView.updateListener.of((update) => {
						if (update.docChanged) this.$emit('update:modelValue', update.state.doc.toString())
					}),
				],
			}),
		})
	},

	watch: {
		modelValue(value) {
			if (this.view === null || value === this.view.state.doc.toString()) return
			this.view.dispatch({ changes: { from: 0, to: this.view.state.doc.length, insert: value } })
		},
		syntax() {
			this.reconfigure(this.languageCompartment, getLanguageExtension(this.syntax))
		},
		readOnly() {
			this.reconfigure(this.editableCompartment, this.getEditableExtensions())
		},
		maxBytes() {
			this.reconfigure(this.maxBytesCompartment, this.getMaxBytesExtensions())
		},
	},

	methods: {
		getEditableExtensions() {
			return [
				EditorState.readOnly.of(this.readOnly),
				EditorView.editable.of(!this.readOnly),
			]
		},
		getMaxBytesExtensions() {
			const maxBytes = Number.isInteger(this.maxBytes) && this.maxBytes >= 0
				? this.maxBytes
				: null

			if (maxBytes === null) return []

			return EditorState.transactionFilter.of((transaction) => {
				if (!transaction.docChanged) return transaction

				return new TextEncoder().encode(transaction.newDoc.toString()).byteLength <= maxBytes
					? transaction
					: []
			})
		},
		getContentAttributes() {
			const attributes = {}

			if (this.inputId) attributes.id = this.inputId
			if (this.ariaLabel) attributes['aria-label'] = this.ariaLabel

			return attributes
		},
		reconfigure(compartment, extension) {
			if (this.view === null) return
			this.view.dispatch({ effects: compartment.reconfigure(extension) })
		},
	},

	beforeUnmount() {
		this.view?.destroy()
	},
}
</script>

<style scoped>
.code-editor :deep(.cm-editor) {
	height: auto;
	border: 1px solid var(--bs-border-color);
	border-radius: var(--bs-border-radius);
}

.code-editor--invalid :deep(.cm-editor) {
	border-color: var(--bs-form-invalid-border-color);
}

.code-editor--readonly :deep(.cm-editor) {
	background-color: var(--bs-secondary-bg);
}
</style>
