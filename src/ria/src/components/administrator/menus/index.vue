<template>
	<h1>Menus</h1>
    <div v-if="menus != null" class="max-content">
		
		<div v-if="selectedMenu == null">
			<div class="d-flex justify-content-end mb-2">
				<button type="button" class="btn btn-primary btn-sm" @click="openCreate">
					<i class="fa-solid fa-plus" aria-hidden="true"></i> Ajouter un menu
				</button>
			</div>
            <data-table
                :fields="menuFields"
                :items="menus"
                :pagination-enabled="true"
                :clickable-row="true"
                :row-hover="true"
                default-sort-key="name"
                @click:row="clickMenu"
			>
				<template #cell_actions="{ item }">
					<button
						type="button"
						class="btn btn-outline-secondary btn-sm me-1"
						title="Voir les éléments du menu"
						:aria-label="`Voir les éléments du menu ${item.name}`"
						@click.stop="clickMenu(item)"
					>
						<i class="fa-solid fa-list" aria-hidden="true"></i>
					</button>
					<button
						type="button"
						class="btn btn-outline-primary btn-sm"
						title="Modifier"
						:disabled="savingMenu || !item.user_defined"
						@click.stop="openEdit(item)"
					>
						<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i><span class="visually-hidden">Modifier</span>
					</button>
					<button
						v-if="item.user_defined"
						type="button"
						class="btn btn-outline-danger btn-sm ms-1"
						:title="item.itemsCount > 0 ? 'Un menu doit être vide pour être supprimé' : 'Supprimer'"
						:aria-label="`Supprimer le menu ${item.name}`"
						:disabled="savingMenu || deletingMenuId !== null || item.itemsCount > 0"
						@click.stop="deleteMenu(item)"
					>
						<spinner v-if="deletingMenuId === item.id" size="sm" />
						<i v-else class="fa-solid fa-trash" aria-hidden="true"></i>
					</button>
				</template>
			</data-table>
        </div>
		<div v-else>
			<a class="btn btn-outline-primary btn-sm mb-4" @click="adminMenusStore.clearSelectedMenu()"><i class="fa-solid fa-arrow-left-long"></i> Retour</a>
			<div class="d-flex justify-content-between align-items-center mb-3">
				<h2 class="mb-0">{{ selectedMenu.name }}</h2>
				<div class="d-flex gap-2">
					<button type="button" class="btn btn-outline-primary btn-sm" :disabled="savingMenu || !selectedMenu.user_defined" @click="openEdit(selectedMenu)">
						<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Modifier le menu
					</button>
					<button v-if="selectedMenu.user_defined" type="button" class="btn btn-outline-danger btn-sm"
						:title="selectedMenu.itemsCount > 0 ? 'Un menu doit être vide pour être supprimé' : 'Supprimer'"
						:disabled="savingMenu || deletingMenuId !== null || selectedMenu.itemsCount > 0"
						@click="deleteMenu(selectedMenu)">
						<spinner v-if="deletingMenuId === selectedMenu.id" size="sm" />
						<i v-else class="fa-solid fa-trash" aria-hidden="true"></i> Supprimer
					</button>
				</div>
			</div>

			<comp_menu :menu="selectedMenu"></comp_menu>
        </div>

		<MenuEditor
			:visible="editorVisible"
			:menu="editingMenu"
			:saving="savingMenu"
			@close="closeEditor"
			@save="saveMenu"
		/>

    </div>
</template>

<script>

import comp_menu from './menu.vue'
import MenuEditor from './editor.vue'

export default {

	components: { comp_menu, MenuEditor },

	data() {
		return {
			menuFields: [
				{ key: 'name', label: 'Nom', sortable: true, filter: 'search' },
				{ key: 'space_title', label: 'Espace', sortable: true, filter: 'search' },
				{ key: 'alias', label: 'Alias', sortable: true, filter: 'search',
					formatter: (menu) => menu.alias || '/' },
				{ key: 'itemsCount', label: 'Éléments', sortable: true },
				{ key: 'actions', label: '', class: 'text-end' },
			],
			editorVisible: false,
			editingMenuId: null,
			savingMenu: false,
			deletingMenuId: null,

		}
	},

	computed: {
		adminMenusStore() {
			return this.stores.useAdminMenusStore()
		},

		menus() {
			return this.adminMenusStore.menuList
		},

		selectedMenu() {
			return this.adminMenusStore.selectedMenu
		},

		editingMenu() {
			return this.menus.find(menu => menu.id === this.editingMenuId) ?? null
		},
	},

	async mounted(){
		await this.adminMenusStore.loadMenus()
	},

	methods: {
		openCreate() {
			this.editingMenuId = null
			this.editorVisible = true
		},

		openEdit(menu) {
			if (!menu.user_defined) return

			this.editingMenuId = menu.id
			this.editorVisible = true
		},

		closeEditor() {
			if (this.savingMenu) return

			this.editorVisible = false
			this.editingMenuId = null
		},

		async saveMenu(data) {
			if (this.savingMenu) return

			this.savingMenu = true
			try {
				if (this.editingMenuId === null) {
					await this.adminMenusStore.createMenu(data)
				} else {
					await this.adminMenusStore.updateMenu(this.editingMenuId, data)
				}

				this.editorVisible = false
				this.editingMenuId = null
			} finally {
				this.savingMenu = false
			}
		},

		async deleteMenu(menu) {
			if (this.deletingMenuId !== null || !menu.user_defined || menu.itemsCount > 0) return
			if (!await this.askYesNoAsync(`Supprimer définitivement le menu vide « ${menu.name} » ?`)) return

			this.deletingMenuId = menu.id
			try {
				await this.adminMenusStore.deleteMenu(menu.id)
			} finally {
				this.deletingMenuId = null
			}
		},

		clickMenu(menu) {
			this.adminMenusStore.selectMenu(menu.id)
		},
	},
}
</script>

