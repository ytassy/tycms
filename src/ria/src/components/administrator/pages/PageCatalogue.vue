<template>
	<div class="page-panel-content">
		<h2 class="h5 mb-3">Composants</h2>
		<label class="visually-hidden" for="page-component-search">Rechercher un composant</label>
		<input id="page-component-search" v-model.trim="search" type="search" class="form-control form-control-sm mb-3" placeholder="Rechercher…">
		<p class="small text-muted">Glissez vers une colonne ou sélectionnez une zone, puis utilisez Ajouter.</p>
		<section v-for="group in groups" :key="group.name" class="mb-3">
			<h3 class="page-panel-heading">{{ group.name }}</h3>
			<div v-for="component in group.items" :key="component.id" class="page-catalogue-item"
				:draggable="canEdit" @dragstart="startDrag($event, component.id)" @dragend="store.endDrag()">
				<span class="page-catalogue-grip" aria-hidden="true">⋮⋮</span>
				<span class="flex-grow-1">{{ component.label }}</span>
				<button type="button" class="btn btn-outline-primary btn-sm" :disabled="!canEdit" :aria-label="`Ajouter ${component.label}`"
					@click="store.addType(component.id)">Ajouter</button>
			</div>
		</section>
		<p v-if="groups.length === 0" class="text-muted small">Aucun composant trouvé.</p>
	</div>
</template>

<script>
export default {
	data: () => ({ search: '' }),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		canEdit() { return Boolean(this.store.current?.canEdit) },
		groups() {
			const needle = this.search.toLocaleLowerCase()
			const grouped = new Map()
			for (const component of this.store.catalogue) {
				if (needle && !`${component.label} ${component.id}`.toLocaleLowerCase().includes(needle)) continue
				const category = component.category ?? 'Extensions'
				if (!grouped.has(category)) grouped.set(category, [])
				grouped.get(category).push(component)
			}
			return [...grouped].map(([name, items]) => ({ name, items }))
		},
	},
	methods: {
		startDrag(event, type) {
			if (!this.canEdit) { event.preventDefault(); return }
			this.store.beginDrag({ kind: 'catalog', type })
			event.dataTransfer.effectAllowed = 'copy'
			event.dataTransfer.setData('text/plain', type)
		},
	},
}
</script>
