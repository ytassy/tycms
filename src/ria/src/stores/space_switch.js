import { defineStore } from 'pinia'

export const useSpaceSwitchStore = {
    get(vueApp, application) {
        return defineStore('space_switch', {
            actions: {
                async load(configToken) {
                    return application.api.post('/space/api/switch/options', { token: configToken })
                },
                async select(configToken, spaceId) {
                    return application.api.post('/space/api/switch/select', { token: configToken, spaceId })
                },
            },
        })
    },
}
