<template>
	
    
		
	<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-2">
		<button type="button" class="btn btn-primary btn-sm" @click="openCreate">
			<i class="fa-solid fa-plus" aria-hidden="true"></i> Ajouter un élément
		</button>
		<!-- Garder les filtres de statut directement accessibles au-dessus de la liste. -->
		<div class="d-flex flex-wrap align-items-center gap-3" role="group" aria-label="Filtrer par statut">
			<div class="form-check form-switch mb-0">
				<input id="menu-items-filter-archived" v-model="showArchived" class="form-check-input" type="checkbox">
				<label class="form-check-label" for="menu-items-filter-archived">Afficher les archivés</label>
			</div>
			<div class="form-check form-switch mb-0">
				<input id="menu-items-filter-trashed" v-model="showTrashed" class="form-check-input" type="checkbox">
				<label class="form-check-label" for="menu-items-filter-trashed">Afficher la corbeille</label>
			</div>
		</div>
	</div>

	<div class="card shadow-sm border-0">
		<table class="table table-hover align-middle mb-0">
				<thead class="table-light">
					<tr>
						<th scope="col">Titre</th>
						<th scope="col">Type</th>
						<th scope="col">Langues</th>
						<th scope="col">Rôle d’accès</th>
						<th scope="col">Alias</th>
						<th scope="col" class="text-center">Ordre</th>
						<th scope="col" class="text-center">Statut</th>
						<th scope="col" class="text-end"></th>
						<th scope="col" class="text-end">Actions</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="item in orderedItems" :key="item.id">
						<td>
							<span v-if="item.depth" class="text-muted me-2" :style="{ marginLeft: `${item.depth * 1.5}rem` }">└</span>
							<span class="fw-semibold">{{ item.title }}</span>
							<span v-if="item.children.length" class="badge text-bg-light border text-dark ms-2">{{ item.children.length }}</span>
						</td>
						<td><span class="badge text-bg-secondary">{{ item.type }}</span></td>
						<td>
							<div v-if="enabledLanguages(item).length" class="d-flex flex-wrap gap-1">
								<span v-for="locale in enabledLanguages(item)" :key="locale" class="badge text-bg-light border text-dark">{{ locale.toUpperCase() }}</span>
							</div>
							<span v-else class="text-muted">Aucune</span>
							<div v-if="hasInheritedLanguageRestriction(item)" class="small text-muted mt-1">
								Après parents : {{ visibleLanguages(item).map(locale => locale.toUpperCase()).join(', ') || 'aucune' }}
							</div>
						</td>
						<td>{{ getAccessRoleLabel(item) }}</td>
						<td><code v-if="item.alias">{{ item.alias }}</code><span v-else class="text-muted">-</span></td>
						<td class="text-center">{{ item.ordering }}</td>
						<td class="text-center"><item_status :status="item.status" /></td>
						<td class="text-center">
							<button type="button" class="btn btn-outline-primary btn-sm" title="Modifier" :disabled="processingItemId !== null" @click="openEdit(item)">
								<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i><span class="visually-hidden">Modifier</span>
							</button>
						</td>
						<td class="text-end">
							<div class="d-inline-flex gap-1">
								<div v-if="canMoveUp(item) || canMoveDown(item)" class="btn-group btn-group-sm">
									<button v-if="canMoveUp(item)" type="button" class="btn btn-outline-secondary" title="Monter" :disabled="processingItemId !== null" @click="moveUp(item)">
										<i class="fa-solid fa-arrow-up" aria-hidden="true"></i><span class="visually-hidden">Monter</span>
									</button>
									<button v-if="canMoveDown(item)" type="button" class="btn btn-outline-secondary" title="Descendre" :disabled="processingItemId !== null" @click="moveDown(item)">
										<i class="fa-solid fa-arrow-down" aria-hidden="true"></i><span class="visually-hidden">Descendre</span>
									</button>
								</div>
								<div class="btn-group btn-group-sm">
									<button type="button" class="btn btn-outline-secondary dropdown-toggle" title="Changer le statut" :disabled="processingItemId !== null" data-bs-toggle="dropdown" aria-expanded="false">
										<i class="fa-solid fa-pen" aria-hidden="true"></i><span class="visually-hidden">Changer le statut</span>
									</button>
									<ul class="dropdown-menu dropdown-menu-end">
										<li v-for="status in statuses" :key="status.value">
											<button type="button" class="dropdown-item" :disabled="processingItemId !== null || Number(item.status) === status.value" @click="changeStatus(item, status.value)">
												<item_status :status="status.value" />
											</button>
										</li>
									</ul>
								</div>
								<delete_btn v-if="Number(item.status) === -2" text_class="text-danger" :confirm_text="`Supprimer définitivement « ${item.title} » ?`" @click="deleteItem(item)"></delete_btn>
							</div>
						</td>
					</tr>
					<tr v-if="orderedItems.length === 0"><td colspan="9" class="text-center text-muted py-4">Aucun élément dans ce menu.</td></tr>
				</tbody>
		</table>
	</div>

			<MenuItemEditor
				:visible="editorVisible"
				:menu="menu"
				:item="editingItem"
				:saving="savingItem"
		@close="closeEditor"
		@save="saveItem"
	/>
    
</template>

<script>

import MenuItemEditor from './item_editor.vue'


export default {

	components: { MenuItemEditor },
	props: {
		menu: { type: Object, required: true },
	},

	data() {
		return {
			processingItemId: null,
			editorVisible: false,
			editingItemId: null,
			savingItem: false,
			showArchived: false,
			showTrashed: false,
			statuses: [
				{ value: 1 },
				{ value: 0 },
				{ value: -1 },
				{ value: -2 },
			],
		}
	},

	computed: {
		editingItem() {
			return (this.menu?.items ?? []).find(item => item.id === this.editingItemId) ?? null
		},

		orderedItems() {
			const items = (Array.isArray(this.menu?.items) ? this.menu.items : [])
				.filter(item => this.isStatusVisible(item.status))
			const childrenByParent = new Map()
			const itemIds = new Set(items.map(item => item.id))

			items.forEach(item => {
				const parentId = item.parent_id && itemIds.has(item.parent_id) ? item.parent_id : null
				if (!childrenByParent.has(parentId)) childrenByParent.set(parentId, [])
				childrenByParent.get(parentId).push(item)
			})

			childrenByParent.forEach(children => children.sort((a, b) => a.ordering - b.ordering || a.id - b.id))

			const flatten = (parentId = null, depth = 0) => (childrenByParent.get(parentId) ?? []).flatMap(item => [
				{ ...item, depth, children: childrenByParent.get(item.id) ?? [] },
				...flatten(item.id, depth + 1),
			])

			return flatten()
		},

		accessRoles() {
			return this.stores.useAdminMenusStore().roles ?? {}
		},

		spaces() {
			return this.stores.useAdminMenusStore().spaces ?? {}
		},
	},

	async mounted(){

		/*const response = await this.api.get('/administrator/api/menus')
		this.dataMenus = response.menus;
		this.dataDefinitions = response.definitions;*/
	},

	methods: {
		enabledLanguages(item) {
			return (item.languages ?? []).filter(language => language.enabled).map(language => language.locale)
		},

		visibleLanguages(item) {
			return (item.languages ?? []).filter(language => language.visible).map(language => language.locale)
		},

		hasInheritedLanguageRestriction(item) {
			return (item.languages ?? []).some(language => language.enabled && !language.visible)
		},

		openCreate() {
			this.editingItemId = null
			this.editorVisible = true
		},

		openEdit(item) {
			this.editingItemId = item.id
			this.editorVisible = true
		},

		closeEditor() {
			if (this.savingItem) return

			this.editorVisible = false
			this.editingItemId = null
		},

		async saveItem(data) {
			if (this.savingItem) return

			this.savingItem = true
			try {
				const store = this.stores.useAdminMenusStore()
				if (this.editingItemId === null) {
					await store.createMenuItem(this.menu.id, data)
				} else {
					await store.updateMenuItem(this.editingItemId, data)
				}

				this.editorVisible = false
				this.editingItemId = null
			} finally {
				this.savingItem = false
			}
		},

		isStatusVisible(status) {
			const value = Number(status)
			return value === 1 || value === 0 || (value === -1 && this.showArchived) || (value === -2 && this.showTrashed)
		},

		getAccessRoleLabel(item) {
			const roleId = Number(item.access_role_id)
			if (!roleId) return '-'

			const role = this.accessRoles[roleId]
			if (!role) return `Rôle #${roleId}`

			const spaceTitle = role.space_title ?? this.spaces[role.space_id]?.title
			return spaceTitle ? `${spaceTitle} - ${role.name}` : role.name
		},

		getSiblings(item) {
			const items = Array.isArray(this.menu?.items) ? this.menu.items : []
			const itemIds = new Set(items.map(menuItem => menuItem.id))
			const parentId = item.parent_id && itemIds.has(item.parent_id) ? item.parent_id : null

			return items
				.filter(menuItem => (menuItem.parent_id && itemIds.has(menuItem.parent_id) ? menuItem.parent_id : null) === parentId)
				.sort((a, b) => a.ordering - b.ordering || a.id - b.id)
		},

		canMoveUp(item) {
			return this.getSiblings(item).findIndex(sibling => sibling.id === item.id) > 0
		},

		canMoveDown(item) {
			const siblings = this.getSiblings(item)
			return siblings.findIndex(sibling => sibling.id === item.id) < siblings.length - 1
		},

		async moveUp(item) {
			await this.moveItem(item, 'up')
		},

		async moveDown(item) {
			await this.moveItem(item, 'down')
		},

		async moveItem(item, direction) {
			await this.processItem(item, () => this.stores.useAdminMenusStore().moveMenuItem(item.id, direction))
		},

		async changeStatus(item, status) {
			await this.processItem(item, () => this.stores.useAdminMenusStore().changeMenuItemStatus(item.id, status))
		},

		async deleteItem(item) {
			await this.processItem(item, () => this.stores.useAdminMenusStore().deleteMenuItem(item.id))
		},

		async processItem(item, action) {
			if (this.processingItemId !== null) return

			this.processingItemId = item.id
			try {
				await action()
			} finally {
				this.processingItemId = null
			}
		},
	},

}
</script>

