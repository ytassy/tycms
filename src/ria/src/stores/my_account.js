import { defineStore } from 'pinia'

/** Les mots de passe restent dans leur modale, jamais dans l'état partagé. */
export const useMyAccountStore = {
    get(vueApp, application) {
        return defineStore('my_account', {
            state: () => ({ account: null }),
            actions: {
                async load() {
                    this.account = await application.api.get('/account/api')
                },
                async save(data) {
                    this.account = await application.api.post('/account/api/save', { data: { ...data } })
                },
                changePassword(current, password, confirmation) {
                    return application.api.post('/account/api/password', { current, password, confirmation })
                },
                async unlink(linkId) {
                    this.account = await application.api.post('/account/api/sso/unlink', { linkId })
                },
                startSso(providerId, returnPath) {
                    return application.api.post('/account/api/sso/start', { providerId, returnPath })
                },
                startIntegration(providerId, returnPath) {
                    return application.api.post('/account/api/integrations/start', { providerId, returnPath })
                },
                async disconnect(providerId) {
                    this.account = await application.api.post('/account/api/integrations/disconnect', { providerId })
                },
            },
        })
    },
}
