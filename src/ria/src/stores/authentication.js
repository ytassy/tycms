import { defineStore } from 'pinia'

/** Aucun mot de passe n'est conservé dans l'état partagé ou dans le stockage du navigateur. */
export const useAuthenticationStore = {
    get(vueApp, application) {
        return defineStore('authentication', {
            actions: {
                login(token, email, password) {
                    return application.api.post('/auth/api/login', { token, email, password })
                },
                register(token, data, acceptedTerms) {
                    return application.api.post('/auth/api/register', { token, data: { ...data }, acceptedTerms })
                },
                join(token, acceptedTerms) {
                    return application.api.post('/auth/api/join', { token, acceptedTerms })
                },
            },
        })
    },
}
