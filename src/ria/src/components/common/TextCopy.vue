<template>
	<span class="text-copy" :class="{ 'text-copy--hover': !alwaysVisible && $slots.default }">
		<span v-if="$slots.default" ref="content"><slot /></span>
		<button type="button" :class="buttonClass" :title="title" :aria-label="title" @click="copy">
			<i :class="copied ? 'fa-solid fa-check text-success' : failed ? 'fa-solid fa-triangle-exclamation text-warning' : iconClass" aria-hidden="true" />
			<template v-if="$slots.button"> <slot name="button" /></template>
			<span v-if="copied && showTextConfirmation" class="ms-1">Copié</span>
		</button>
	</span>
</template>

<script>
export default {
	props: {
		textToCopy: { type: String, default: null },
		arrayToCopy: { type: [Array, Object], default: null },
		valueHtml: { type: Boolean, default: false },
		alwaysVisible: { type: Boolean, default: false },
		showTextConfirmation: { type: Boolean, default: false },
		title: { type: String, default: 'Copier' },
		buttonClass: { type: String, default: 'btn btn-link btn-sm p-0 ms-1' },
		iconClass: { type: String, default: 'fa-regular fa-copy' },
	},
	emits: ['copied', 'copy-failed'],
	data() { return { copied: false, failed: false, resetTimer: null } },
	beforeUnmount() { if (this.resetTimer) clearTimeout(this.resetTimer) },
	methods: {
		getText() {
			if (this.textToCopy !== null) return this.textToCopy
			if (this.arrayToCopy !== null) {
				return Object.values(this.arrayToCopy)
					.filter(value => value !== null && value !== undefined && value !== '')
					.map(value => typeof value === 'object' ? value.name ?? String(value) : String(value))
					.join('\n')
			}
			const content = this.$refs.content
			return (this.valueHtml ? content?.innerText : content?.textContent)?.trim() ?? ''
		},
		copyWithSelection(text) {
			const input = document.createElement('textarea')
			input.value = text
			input.setAttribute('readonly', '')
			input.style.position = 'fixed'
			input.style.opacity = '0'
			document.body.appendChild(input)
			input.select()
			try { return document.execCommand('copy') }
			catch (_) { return false }
			finally { input.remove() }
		},
		async copy() {
			const text = this.getText()
			if (!text) return
			this.failed = false
			let success = false
			if (navigator.clipboard?.writeText) {
				try { await navigator.clipboard.writeText(text); success = true }
				catch (_) { /* Le navigateur peut refuser la copie asynchrone. */ }
			}
			if (!success) success = this.copyWithSelection(text)
			if (!success) {
				this.copied = false
				this.failed = true
				this.$emit('copy-failed', text)
				return
			}
			this.copied = true
			this.$emit('copied', text)
			if (this.resetTimer) clearTimeout(this.resetTimer)
			this.resetTimer = setTimeout(() => { this.copied = false; this.resetTimer = null }, 1500)
		},
	},
}
</script>

<style scoped>
.text-copy--hover > button { opacity: 0; }
.text-copy--hover:hover > button, .text-copy--hover:focus-within > button { opacity: 1; }
</style>
