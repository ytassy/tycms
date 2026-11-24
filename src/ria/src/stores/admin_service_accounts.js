import { defineStore } from 'pinia'

export const useAdminServiceAccountsStore = {
	get(vueApp, application) {
		return defineStore('admin_service_accounts', {
			state: () => ({
				accounts: [],
				busy: false,
				selectedId: null,
				tokens: [],
				tokensBusy: false,
			}),

			getters: {
				app: () => application.stores.useAppStore(),

				selectedAccount() {
					return this.accounts.find(account => String(account.id) === String(this.selectedId)) ?? null
				},
			},

			actions: {
				async load() {
					this.busy = true
					try {
						const response = await application.api.get('/administrator/api/service-accounts')
						this.accounts = response.accounts ?? []
					} finally {
						this.busy = false
					}
				},

				async createAccount(data) {
					const response = await application.api.post('/administrator/api/service-accounts/create', data)
					await this.load()
					return response.account ?? null
				},

				async loadTokens(userId) {
					this.tokensBusy = true
					try {
						const response = await application.api.get('/administrator/api/service-accounts/tokens', { userId })
						this.tokens = response.tokens ?? []
					} finally {
						this.tokensBusy = false
					}
				},

				async createToken(userId, name, expiresAt) {
					const response = await application.api.post(
						'/administrator/api/service-accounts/token/create',
						{ userId, name, spaceId: null, expiresAt: expiresAt || null },
					)
					await this.loadTokens(userId)
					return response.token ?? null
				},

				async revokeToken(tokenId) {
					await application.api.post('/administrator/api/service-accounts/token/revoke', { tokenId })
					if (this.selectedId !== null) {
						await this.loadTokens(this.selectedId)
					}
				},

				clear() {
					this.accounts = []
					this.tokens = []
					this.selectedId = null
				},
			},
		})
	},
}