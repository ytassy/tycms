<template>
    <modal :visible="true" width="900px" :closable="!saving" @close="close">
        <template #header>{{ user ? `Utilisateur « ${user.first_name} ${user.last_name} »` : 'Ajouter un utilisateur' }}</template>
        <template #body>
            <div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
            <form id="user-editor-form" @submit.prevent="save">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="user-first-name" class="form-label">Prénom</label>
                        <input id="user-first-name" v-model.trim="draft.first_name" class="form-control" required maxlength="100" :disabled="saving || !canEditAccount" />
                    </div>
                    <div class="col-md-6">
                        <label for="user-last-name" class="form-label">Nom</label>
                        <input id="user-last-name" v-model.trim="draft.last_name" class="form-control" required maxlength="100" :disabled="saving || !canEditAccount" />
                    </div>
                    <div class="col-md-6">
                        <label for="user-email" class="form-label">E-mail</label>
                        <input id="user-email" v-model.trim="draft.email" type="email" class="form-control" required maxlength="100" :disabled="saving || !canEditAccount" />
                    </div>
                    <div class="col-md-6">
                        <label for="user-timezone" class="form-label">Fuseau horaire</label>
                        <select id="user-timezone" v-model="draft.timezone" class="form-select" required :disabled="saving || !canEditAccount">
                            <option v-if="draft.timezone && !Object.hasOwn(timezones, draft.timezone)" :value="draft.timezone">{{ draft.timezone }}</option>
                            <option v-for="(label, timezone) in timezones" :key="timezone" :value="timezone">{{ label }}</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="user-primary-space" class="form-label">Espace principal</label>
                        <select id="user-primary-space" v-model.number="draft.space_id" class="form-select" required
                            aria-describedby="user-primary-space-help" :disabled="saving || !!user">
                            <option v-for="space in creatableSpaces" :key="space.id" :value="space.id">{{ space.title }}</option>
                            <option v-if="user && !creatableSpaces.some(space => space.id === user.space_id)"
                                :value="user.space_id">{{ spaceName(user.space_id) }}</option>
                        </select>
                        <div id="user-primary-space-help" class="form-text">Espace de référence pour l’administration et la sécurité du compte, fixé à sa création.</div>
                    </div>
                    <div class="col-md-6 d-flex align-items-end gap-3 pb-2">
                        <label><input v-model="draft.blocked" type="checkbox" class="form-check-input me-1" :disabled="saving || !canEditAccount" />Bloqué</label>
                        <label><input v-model="draft.send_email" type="checkbox" class="form-check-input me-1" :disabled="saving || !canEditAccount" />E-mails</label>
                    </div>
                </div>
                <div v-if="!user" class="mt-3">
                    <label for="user-create-password" class="form-label">Mot de passe initial</label>
                    <div class="input-group">
                        <input id="user-create-password" v-model="draft.password" class="form-control" minlength="12" maxlength="72" @input="validateNewPasswordInput"
                            autocomplete="new-password" placeholder="Laisser vide pour générer (72 octets maximum)" :disabled="saving" />
                        <button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="draft.password = generatePassword()">Générer</button>
                    </div>
                    <div class="form-check mt-2">
                        <input id="user-create-welcome-email" v-model="draft.send_welcome_email" type="checkbox"
                            class="form-check-input" :disabled="saving" />
                        <label for="user-create-welcome-email" class="form-check-label">Envoyer l’e-mail de bienvenue avec le mot de passe initial</label>
                    </div>
                </div>
            </form>
        </template>
        <template #footer>
            <button v-if="user?.can_delete" type="button" class="btn btn-outline-danger me-2" :disabled="saving"
                @click="requestDelete">Supprimer le compte</button>
            <button type="button" class="btn btn-outline-secondary me-2" :disabled="saving" @click="close">Fermer</button>
            <button v-if="canEditAccount" type="submit" form="user-editor-form" class="btn btn-primary" :disabled="saving">
                {{ user ? 'Enregistrer' : 'Créer' }}
            </button>
        </template>
    </modal>
</template>

<script>
import { validateNewPasswordInput } from '../../../application/passwordPolicy'

export default {
    props: {
        user: { type: Object, default: null },
        directory: { type: Object, required: true },
        saving: { type: Boolean, default: false },
        error: { type: String, default: '' },
    },
    emits: ['close', 'save', 'delete'],
    data() {
        // Le brouillon appartient à la modale et ne modifie pas le compte du tableau.
        return {
            draft: this.user ? { ...this.user, password: '' } : {
                first_name: '', last_name: '', email: '', timezone: 'Europe/Paris',
                blocked: false, send_email: false,
                space_id: this.directory.spaces.find(space => space.can_create)?.id ?? null,
                password: '', send_welcome_email: true,
            },
        }
    },
    computed: {
        creatableSpaces() { return this.directory.spaces.filter(space => space.can_create) },
        canEditAccount() { return this.user ? this.user.can_edit : this.creatableSpaces.length > 0 },
        // Le bootstrap commun fournit les mêmes identifiants que ceux validés par PHP.
        timezones() { return this.conf.getTimezones() },
    },
    methods: {
        validateNewPasswordInput,
        spaceName(id) { return this.directory.spaces.find(space => space.id === id)?.title ?? `#${id}` },
        close() {
            if (!this.saving) this.$emit('close')
        },
        requestDelete() {
            if (!this.saving && this.user?.can_delete) this.$emit('delete')
        },
        generatePassword() {
            // La génération reste dans le navigateur ; le serveur ne reçoit que la valeur choisie.
            const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*'
            const values = new Uint32Array(24)
            crypto.getRandomValues(values)
            return Array.from(values, value => alphabet[value % alphabet.length]).join('')
        },
        save() {
            if (this.saving || !this.canEditAccount) return

            // Transmettre uniquement les champs attendus par l'API, jamais le compte complet.
            const data = {
                first_name: this.draft.first_name, last_name: this.draft.last_name,
                email: this.draft.email, timezone: this.draft.timezone,
                blocked: this.draft.blocked, send_email: this.draft.send_email,
            }
            if (!this.user) {
                data.space_id = this.draft.space_id
                data.password = this.draft.password || this.generatePassword()
                data.send_welcome_email = this.draft.send_welcome_email
            }

            this.$emit('save', data)
        },
    },
}
</script>
