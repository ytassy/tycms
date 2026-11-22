<template>
	<!-- Ne pas charger de site tiers pendant la sélection et l’édition du brouillon. -->
	<div class="page-canvas-placeholder border rounded p-4 text-center">
		<i class="fa-solid fa-window-maximize fs-2 d-block mb-2" aria-hidden="true"></i>
		<strong>Iframe</strong>
		<div class="small text-break">{{ item.props.src || 'URL HTTPS à définir' }}</div>
		<small>Contenu disponible dans l’aperçu fidèle et sur le site.</small>
	</div>
</template>
<script>
export default {
	props: { item: { type: Object, required: true }, node: Object, context: Object, viewport: String, components: Array },
}
</script>
