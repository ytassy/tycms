<template>
	<div class="page-panel-content">
		<h2 class="h5 mb-2">Arborescence</h2>
		<p class="small text-muted">Saisissez la poignée ⋮⋮, puis déposez l'élément avant ou après un autre. Déposez un composant sur une colonne pour l'y placer.</p>
		<button type="button" class="page-tree-root" @click="store.activeTab = 'page'">Page : {{ store.current.name || 'Sans nom' }}</button>
		<PageTreeItems :items="store.current.data.items" parent-id="page" />
		<p v-if="store.current.data.items.length === 0 && !store.dragPayload" class="small text-muted mt-2">Page vide. Ajoutez une ligne ou glissez un composant dans l’aperçu.</p>
	</div>
</template>

<script>
import PageTreeItems from './PageTreeItems.vue'

export default {
	components: { PageTreeItems },
	computed: { store() { return this.stores.useAdminPagesStore() } },
}
</script>
