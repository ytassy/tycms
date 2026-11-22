<template>
    <template v-if="authenticated">
        <common_app />
        <p v-if="loading" role="status">Chargement du compte…</p>
        <div v-else-if="loadError" class="alert alert-warning" role="alert">
            {{ loadError }} <button type="button" class="btn btn-link" @click="load">Réessayer</button>
        </div>
        <div v-else-if="account" class="my-account">
            <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
            <AccountDetails :rows="detailRows" :layout="settings.layout ?? 'simple'" />
            <button v-if="editableFields.length" type="button" class="btn btn-primary" @click="open('profile')">Modifier</button>
            <div v-if="settings.show_password && !settings.readonly && settings.edit_password" class="mt-3">
                <button v-if="account.password_change_allowed && account.has_local_password" type="button" class="btn btn-outline-secondary" @click="open('password')">Modifier le mot de passe</button>
                <a v-else-if="account.password_change_allowed" href="/forgot-password">Définir un mot de passe local par e-mail</a>
                <p v-else class="small text-body-secondary">Le mot de passe local est désactivé par votre espace principal.</p>
            </div>

            <section v-if="settings.show_sso" class="mt-4">
                <h3 class="h5">Connexions SSO</h3>
                <p v-if="!account.sso_links.length" class="text-body-secondary">Aucune connexion SSO.</p>
                <ul v-else><li v-for="link in account.sso_links" :key="link.id">{{ link.name }}</li></ul>
                <button v-if="!settings.readonly && (settings.add_sso || settings.remove_sso)" type="button" class="btn btn-outline-primary" @click="open('sso')">Modifier les connexions SSO</button>
            </section>
            <section v-if="settings.show_integrations" class="mt-4">
                <h3 class="h5">Mes intégrations</h3>
                <p v-if="!account.integrations.length" class="text-body-secondary">Aucune intégration disponible dans cet espace.</p>
                <ul v-else><li v-for="item in account.integrations" :key="item.id">{{ item.name }} · {{ item.connected ? 'Compte autorisé' : 'Compte non autorisé' }}</li></ul>
                <button v-if="!settings.readonly && (settings.add_integrations || settings.remove_integrations)" type="button" class="btn btn-outline-primary" @click="open('integrations')">Modifier mes intégrations</button>
            </section>

            <ProfileEditor v-if="dialog === 'profile'" :account="account" :fields="editableFields" :saving="saving" :error="error" @close="close" @save="saveProfile" />
            <PasswordEditor v-if="dialog === 'password'" :saving="saving" :error="error" @close="close" @save="savePassword" />
            <ConnectionsEditor v-if="dialog === 'sso' || dialog === 'integrations'" :integrations="dialog === 'integrations'"
                :items="dialog === 'integrations' ? account.integrations : account.sso_links" :providers="account.sso_providers"
                :allow-add="dialog === 'integrations' ? settings.add_integrations : settings.add_sso"
                :allow-remove="dialog === 'integrations' ? settings.remove_integrations : settings.remove_sso"
                :saving="saving" :error="error" :notice="dialogNotice" @close="close" @add="startConnection" @remove="removeConnection" />
        </div>
    </template>
</template>

<script>
import AccountDetails from '../features/account/AccountDetails.vue'
import ProfileEditor from '../features/account/ProfileEditor.vue'
import PasswordEditor from '../features/account/PasswordEditor.vue'
import ConnectionsEditor from '../features/account/ConnectionsEditor.vue'

export default {
    components: { AccountDetails, ProfileEditor, PasswordEditor, ConnectionsEditor },
    props: { authenticated: Boolean, settings: { type: Object, required: true } },
    data() {
        return {
            loading: true, saving: false, loadError: '', error: '', notice: '', dialogNotice: '', dialog: '',
            fields: [{ name: 'first_name', label: 'Prénom', autocomplete: 'given-name' },
                { name: 'last_name', label: 'Nom', autocomplete: 'family-name' },
                { name: 'email', label: 'E-mail', autocomplete: 'email' }, { name: 'timezone', label: 'Fuseau horaire' }],
        }
    },
    computed: {
        store() { return this.stores.useMyAccountStore() },
        account() { return this.store.account },
        visibleFields() { return this.fields.filter(field => this.settings[`show_${field.name}`]) },
        editableFields() { return this.settings.readonly ? [] : this.visibleFields.filter(field => this.settings[`edit_${field.name}`]) },
        detailRows() {
            // La même projection alimente les trois dispositions, avec échappement Vue des valeurs.
            const rows = this.visibleFields.map(field => ({ ...field, value: this.account[field.name] }))
            if (this.settings.show_password) {
                rows.push({ name: 'password', label: 'Mot de passe', value: this.account.has_local_password ? '••••••••' : 'Connexion SSO' })
            }
            return rows
        },
    },
    mounted() { if (this.authenticated) this.load() },
    methods: {
        async load() {
            this.loading = true
            this.loadError = ''
            try { await this.store.load() }
            catch (error) { this.loadError = error?.message ?? 'Impossible de charger votre compte.' }
            finally { this.loading = false }
        },
        open(dialog) { this.error = ''; this.dialogNotice = ''; this.notice = ''; this.dialog = dialog },
        close() { if (!this.saving) this.dialog = '' },
        async run(action) {
            if (this.saving) return
            this.saving = true
            this.error = ''
            this.dialogNotice = ''
            try { await action() }
            catch (error) { this.error = error?.message ?? 'Cette modification est impossible.' }
            finally { this.saving = false }
        },
        saveProfile(data) {
            return this.run(async () => { await this.store.save(data); this.dialog = ''; this.notice = 'Votre compte a été modifié.' })
        },
        savePassword({ current, password, confirmation }) {
            return this.run(async () => {
                const result = await this.store.changePassword(current, password, confirmation)
                this.dialog = ''
                window.location.assign(result.url)
            })
        },
        startConnection(providerId) {
            return this.run(async () => {
                // Le serveur valide et conserve cette destination dans le flux à usage unique.
                const returnPath = window.location.pathname + window.location.search
                const result = this.dialog === 'integrations'
                    ? await this.store.startIntegration(providerId, returnPath)
                    : await this.store.startSso(providerId, returnPath)
                window.location.assign(result.url)
            })
        },
        removeConnection(id) {
            return this.run(async () => {
                if (this.dialog === 'integrations') await this.store.disconnect(id)
                else await this.store.unlink(id)
                this.dialogNotice = 'La connexion a été supprimée.'
            })
        },
    },
}
</script>
