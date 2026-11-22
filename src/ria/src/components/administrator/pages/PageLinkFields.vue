<template>
	<div class="item border-start ps-1">
		<label class="page-field">Type de lien
			<select :value="node.props.link_type ?? 'url'" class="form-select form-select-sm" :disabled="disabled" @change="setType($event.target.value)">
				<option value="url">Lien libre</option><option value="menu">Element de menu</option>
			</select>
		</label>
		<label v-if="node.props.link_type !== 'menu'" class="page-field">Lien
			<input v-model="node.props.href" class="form-control form-control-sm" :disabled="disabled" maxlength="2048" placeholder="/page ou https://…">
		</label>
		<template v-else>
			<label class="page-field">Destination
				<select :value="node.props.menu_item_id ?? 0" class="form-select form-select-sm" :disabled="disabled || loading" @change="node.props.menu_item_id = Number($event.target.value)">
					<option :value="0">Choisir une entrée de menu</option>
					<option v-if="missing" :value="node.props.menu_item_id">Destination indisponible (#{{ node.props.menu_item_id }})</option>
					<optgroup v-for="group in groups" :key="group.id" :label="group.label">
						<option v-for="item in group.items" :key="item.id" :value="item.id">{{ item.label }}</option>
					</optgroup>
				</select>
			</label>
			<p v-if="loading" class="small text-muted">Chargement des destinations…</p>
			<div v-else-if="error" class="small text-danger mb-2" role="alert">{{ error }}
				<button type="button" class="btn btn-link btn-sm p-0" :disabled="disabled" @click="load">Réessayer</button>
			</div>
			<p v-else-if="missing" class="small text-warning" role="status">Cette destination est indisponible ou appartient à un autre Space. Choisissez une autre entrée.</p>
			<p v-else-if="selected && !selected.locales.includes(locale)" class="small text-warning" role="status">Cette destination n’est pas disponible dans la langue de l’aperçu. Le lien sera inactif.</p>
			<p v-else-if="items.length === 0" class="small text-muted">Aucune entrée publiée et navigable dans ce Space.</p>
		</template>
		<label class="page-field d-flex gap-2 align-items-center">
			<input type="checkbox" class="form-check-input" :checked="node.props.link_new_tab === true" :disabled="disabled" @change="node.props.link_new_tab = $event.target.checked"> Ouvrir dans un nouvel onglet
		</label>
	</div>
</template>

<style scoped>

</style>
<script>
export default {
	props: {
		node: { type: Object, required: true }, spaceId: { type: Number, required: true },
		locale: { type: String, required: true }, disabled: Boolean,
	},
	data: () => ({ items: [], loading: false, error: '', requestVersion: 0 }),
	computed: {
		selected() { return this.items.find(item => item.id === this.node.props.menu_item_id) },
		missing() { return !this.loading && !this.error && this.node.props.menu_item_id > 0 && !this.selected },
		groups() {
			const groups = new Map()
			for (const item of this.items) {
				if (!groups.has(item.menu_id)) groups.set(item.menu_id, { id: item.menu_id, label: item.menu, items: [] })
				groups.get(item.menu_id).items.push(item)
			}
			return [...groups.values()]
		},
	},
	watch: {
		spaceId: { immediate: true, handler() { this.items = []; this.load() } },
		'node.props.link_type'() { this.load() },
	},
	beforeUnmount() { this.requestVersion++ },
	methods: {
		setType(value) {
			if (this.disabled) return
			this.node.props.link_type = value
			// Un seul type de destination est conservé dans le document.
			if (value === 'menu') delete this.node.props.href
			else delete this.node.props.menu_item_id
		},
        load() {
            this.items = this.stores.useAdminPagesStore().editContext.plg_core_menu_items ?? []
        },
	},
}
</script>
