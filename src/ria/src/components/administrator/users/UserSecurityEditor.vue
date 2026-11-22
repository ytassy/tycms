<template>
    <modal :visible="true" width="600px" :closable="!saving" @close="close">
        <template #header>Connexion et sécurité</template>
        <template #body>
            <p class="text-body-secondary">{{ user.first_name }} {{ user.last_name }} · {{ user.email }}</p>
            <div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
            <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>

            <form id="user-password-form" @submit.prevent="save">
                <label for="user-new-password" class="form-label">Nouveau mot de passe</label>
                <input id="user-new-password" v-model="newPassword" type="password" class="form-control"
                    required minlength="12" maxlength="72" @input="validateNewPasswordInput" autocomplete="new-password"
                    aria-describedby="user-password-help" :disabled="saving" />
                <div id="user-password-help" class="form-text">12 caractères minimum, 72 octets maximum.</div>
                <button type="submit" class="btn btn-primary mt-3" :disabled="saving || newPassword.length < 12">
                    Enregistrer le mot de passe
                </button>
            </form>

            <section class="border-top mt-4 pt-3">
                <h3 class="h6">Connexions SSO</h3>
                <p v-if="!user.sso_links?.length" class="text-body-secondary mb-0">Aucune connexion SSO.</p>
                <div v-for="link in user.sso_links || []" :key="link.id" class="d-flex align-items-center gap-2 mb-2">
                    <span>{{ link.name }} · {{ spaceName(link.space_id) }}</span>
                    <button type="button" class="btn btn-outline-danger btn-sm" :disabled="saving" @click="$emit('unlink-sso', link)">Supprimer la connexion SSO</button>
                </div>
            </section>
        </template>
        <template #footer>
            <button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="close">Fermer</button>
        </template>
    </modal>
</template>

<script>
import { validateNewPasswordInput } from '../../../application/passwordPolicy'

export default {
    props: {
        user: { type: Object, required: true },
        directory: { type: Object, required: true },
        saving: { type: Boolean, default: false },
        error: { type: String, default: '' },
        notice: { type: String, default: '' },
    },
    emits: ['close', 'save-password', 'unlink-sso'],
    data() {
        return { newPassword: '' }
    },
    beforeUnmount() {
        // Effacer aussi le secret lorsque le parent ferme la modale après un succès.
        this.newPassword = ''
    },
    methods: {
        validateNewPasswordInput,
        spaceName(id) { return this.directory.spaces.find(space => space.id === id)?.title ?? `#${id}` },
        close() {
            if (this.saving) return
            this.newPassword = ''
            this.$emit('close')
        },
        save() {
            if (this.saving || !this.user.can_admin || this.newPassword.length < 12) return
            this.$emit('save-password', this.newPassword)
        },
    },
}
</script>
