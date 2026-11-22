<template><div ref="content" v-html="html"></div></template>
<script>
import { mountMermaidComponents, unmountMermaidComponents } from './mermaid/mount.js'

// Le HTML reçu doit provenir du helper serveur, après purification du contenu éditorial.
export default {
	props: { html: { type: String, default: '' } },
	mounted() { mountMermaidComponents(this.$refs.content) },
	beforeUpdate() { unmountMermaidComponents(this.$refs.content) },
	updated() { mountMermaidComponents(this.$refs.content) },
	beforeUnmount() { unmountMermaidComponents(this.$refs.content) },
}
</script>
