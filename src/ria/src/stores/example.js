import { defineStore } from 'pinia'

export const useExampleStore = {

	get(vueApp, application) {
		return defineStore('example', {
			state: () => ({
				myData: null,
			}),

			getters: {
				app: () => application.stores.useAppStore(),
			},

			actions: {
				setMyData(value) {
					this.myData = value
				},
			},
		})
	}

}
