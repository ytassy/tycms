<template>
    <common_app />
    <div class="authentication">
        <p v-if="!available" class="text-body-secondary" role="status">
            {{ register ? 'L’inscription est indisponible dans cet espace.' : 'Ce formulaire de connexion n’est pas configuré.' }}
        </p>
        <template v-else-if="authenticated && (!register || alreadyMember)">
            <p>{{ register ? 'Votre compte a déjà accès à cet espace.' : 'Vous êtes déjà connecté.' }}</p>
            <a :href="destination" class="btn btn-primary">Continuer</a>
        </template>
        <template v-else>
            <form v-if="settings.form_enabled" @submit.prevent="submit">
                <p v-if="authenticated">Votre compte existe déjà. Vous pouvez rejoindre cet espace.</p>
                <template v-else>
                    <template v-if="settings.form_enabled">
                        <div v-if="register" class="mb-3">
                            <label :for="`${fieldPrefix}-first-name`" class="form-label">Prénom</label>
                            <input :id="`${fieldPrefix}-first-name`" v-model="fields.first_name" class="form-control" maxlength="100" required autocomplete="given-name">
                        </div>
                        <div v-if="register" class="mb-3">
                            <label :for="`${fieldPrefix}-last-name`" class="form-label">Nom</label>
                            <input :id="`${fieldPrefix}-last-name`" v-model="fields.last_name" class="form-control" maxlength="100" required autocomplete="family-name">
                        </div>
                        <div class="mb-3">
                            <label :for="`${fieldPrefix}-email`" class="form-label">Adresse e-mail</label>
                            <input :id="`${fieldPrefix}-email`" v-model="fields.email" type="email" class="form-control" maxlength="100" required autocomplete="username">
                        </div>
                        <div class="mb-3">
                            <label :for="`${fieldPrefix}-password`" class="form-label">Mot de passe</label>
                            <input :id="`${fieldPrefix}-password`" v-model="fields.password" type="password" class="form-control" required
                                :minlength="register ? 12 : undefined" :maxlength="register ? 72 : 4096" @input="register && validateNewPasswordInput($event)" :autocomplete="register ? 'new-password' : 'current-password'">
                            <div v-if="register" class="form-text">12 caractères minimum, 72 octets maximum.</div>
                        </div>
                        <div v-if="register" class="mb-3">
                            <label :for="`${fieldPrefix}-confirmation`" class="form-label">Confirmer le mot de passe</label>
                            <input :id="`${fieldPrefix}-confirmation`" v-model="fields.password_confirmation" type="password" class="form-control"
                                required minlength="12" maxlength="72" autocomplete="new-password" @input="validateNewPasswordInput">
                        </div>
                        <!-- Ces textes viennent uniquement des props purifiées et projetées par le serveur. -->
                        <div v-if="!register && settings.form_text" class="mb-3" v-html="settings.form_text" />
                    </template>
                </template>
                <div v-if="termsRequired" class="d-flex align-items-start gap-2 mb-3">
                    <input :id="`${fieldPrefix}-terms`" v-model="acceptedTerms" type="checkbox" class="form-check-input" required>
                    <label :for="`${fieldPrefix}-terms`" v-html="settings.terms_text" />
                </div>
                <button class="btn btn-primary" type="submit" :disabled="busy || (termsRequired && !acceptedTerms)">
                    {{ authenticated ? 'Rejoindre cet espace' : register ? 'Créer mon compte' : 'Se connecter' }}
                </button>
                <a v-if="!register && settings.show_forgot_password" href="/forgot-password" class="d-block mt-2">{{ settings.forgot_password_text }}</a>
            </form>

            <p v-if="authenticated && !settings.form_enabled" class="text-body-secondary">L’adhésion par formulaire est désactivée.</p>

            <!-- Une seule case couvre les deux parcours ; elle reste visible en mode SSO seul. -->
            <div v-if="!settings.form_enabled && !authenticated && termsRequired" class="d-flex align-items-start gap-2 mb-3">
                <input :id="`${fieldPrefix}-terms`" v-model="acceptedTerms" type="checkbox" class="form-check-input">
                <label :for="`${fieldPrefix}-terms`" v-html="settings.terms_text" />
            </div>

            <template v-if="!authenticated && settings.sso_enabled && providers.length">
                <hr v-if="settings.form_enabled" class="my-4">
                <div v-if="!register && settings.sso_text" class="mb-3" v-html="settings.sso_text" />
                <form action="/sso/start" method="post" @submit="checkSso">
                    <input type="hidden" name="token" :value="configToken">
                    <input type="hidden" name="csrf_token" :value="conf.getCsrfToken()">
                    <input type="hidden" name="acceptedTerms" :value="acceptedTerms ? '1' : '0'">
                    <button v-for="provider in providers" :key="provider.id" type="submit" name="providerId" :value="provider.id"
                        class="btn btn-outline-secondary d-block mb-2" :disabled="busy || (termsRequired && !acceptedTerms)">{{ provider.text }}</button>
                </form>
            </template>
            <!--
            <p v-if="!register && !authenticated && registerUrl" class="small mt-3 mb-0">Vous n’avez pas de compte ? <a :href="registerUrl">S’inscrire</a></p>
            <p v-if="register && !authenticated" class="small mt-3 mb-0">Déjà inscrit ? <a :href="loginUrl">Connectez-vous</a>, puis revenez ici pour rejoindre cet espace.</p>
            -->
        </template>
    </div>
</template>

<script>
import { validateNewPasswordInput } from '../../application/passwordPolicy'

export default {
    props: {
        kind: { type: String, required: true },
        configToken: { type: String, required: true },
        settings: { type: Object, required: true },
        initialCredentials: { type: Object, default: () => ({}) },
        providers: { type: Array, default: () => [] },
        available: Boolean,
        authenticated: Boolean,
        alreadyMember: Boolean,
        loginUrl: { type: String, default: '/login-fb' },
        registerUrl: { type: String, default: '' },
        destination: { type: String, default: '/' },
    },
    data() {
        return {
            fields: {
                first_name: '', last_name: '', password_confirmation: '',
                email: this.kind === 'login' ? (this.initialCredentials.email ?? '') : '',
                password: this.kind === 'login' ? (this.initialCredentials.password ?? '') : '',
            },
            acceptedTerms: false, busy: false, fieldPrefix: `auth-${crypto.randomUUID()}`,
        }
    },
    computed: {
        register() { return this.kind === 'register' },
        termsRequired() { return this.register && this.settings.terms_enabled === true },
    },
    methods: {
        validateNewPasswordInput,
        checkSso(event) {
            if (this.busy || (this.termsRequired && !this.acceptedTerms)) event.preventDefault()
        },
        async submit() {
            if (this.busy || (this.termsRequired && !this.acceptedTerms)) return
            this.busy = true
            this.showModaleWait()
            try {
                const store = this.stores.useAuthenticationStore()
                const result = this.authenticated
                    ? await store.join(this.configToken, this.acceptedTerms)
                    : this.register
                        ? await store.register(this.configToken, this.fields, this.acceptedTerms)
                        : await store.login(this.configToken, this.fields.email, this.fields.password)

                // Le changement d'utilisateur renouvelle aussi le CSRF : recharger le document complet.
                if (typeof result.url !== 'string' || !result.url.startsWith('/') || result.url.startsWith('//') || /[\\\r\n]/.test(result.url)) {
                    this.showModaleError('Destination invalide.')
                    return
                }
                this.fields.password = ''
                this.fields.password_confirmation = ''
                window.location.assign(result.url)
            } catch (_) {
                // Les erreurs métier sont présentées par le client API commun.
            } finally {
                this.busy = false
                this.hideModaleWait()
            }
        },
    },
}
</script>
