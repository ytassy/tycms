import { defineStore } from 'pinia'

export const useAdminMenusStore = {
	get(vueApp, application) {
		return defineStore('admin_menus', {
	state: () => ({
		menus: null,
		definitions: {},
		spaces: {},
		roles: {},
		selectedMenuId: null,
	}),

	getters: {
		app: () => application.stores.useAppStore(),

		menuList: (state) => Object.entries(state.menus ?? {}).flatMap(([spaceId, menus]) =>
			Object.values(menus ?? {}).map(menu => ({
				...menu,
				space_id: menu.space_id ?? Number(spaceId),
				space_title: state.spaces[menu.space_id ?? spaceId]?.title ?? `Espace #${menu.space_id ?? spaceId}`,
				itemsCount: Array.isArray(menu.items) ? menu.items.length : 0,
			})),
		),

		selectedMenu() {
			return this.menuList.find(menu => String(menu.id) === String(this.selectedMenuId)) ?? null
		},
	},

		actions: {
		async loadMenus() {
			const response = await application.api.get('/administrator/api/menus')
			this.menus = response.menus ?? {}
			this.definitions = response.definitions ?? {}
			this.spaces = response.spaces ?? {}
			this.roles = response.roles ?? {}
		},

		async createMenu(data) {
			await application.api.post('/administrator/api/menus/create', { data })
			await this.loadMenus()
		},

		async updateMenu(menuId, data) {
			await application.api.post('/administrator/api/menus/update', { menuId, data })
			await this.loadMenus()
		},

		async moveMenuItem(menuItemId, direction) {
			await application.api.post(`/administrator/api/menus/move-${direction}`, { menuItemId })
			await this.loadMenus()
		},

		async changeMenuItemStatus(menuItemId, status) {
			await application.api.post('/administrator/api/menus/item/status', { menuItemId, status: String(status) })
			await this.loadMenus()
		},

		async deleteMenuItem(menuItemId) {
			await application.api.post('/administrator/api/menus/item/delete', { menuItemId })
			await this.loadMenus()
		},

		async createMenuItem(menuId, data) {
			await application.api.post('/administrator/api/menus/item/create', { menuId, data })
			await this.loadMenus()
		},

		async updateMenuItem(menuItemId, data) {
			await application.api.post('/administrator/api/menus/item/update', { menuItemId, data })
			await this.loadMenus()
		},

		selectMenu(menuId) {
			this.selectedMenuId = menuId
		},

		clearSelectedMenu() {
			this.selectedMenuId = null
		},
		},
		})
	},
}
