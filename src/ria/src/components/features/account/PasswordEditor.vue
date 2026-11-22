<template>
    <modal :visible="true" width="550px" :closable="!saving" @close="close">
        <template #header>Modifier mon mot de passe</template>
        <template #body>
            <div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
            <form :id="prefix" @submit.prevent="save">
                <div v-for="field in fields" :key="field.name" class="mb-3">
                    <label :for="`${prefix}-${field.name}`" class="form-label">{{ field.label }}</label>
                    <input :id="`${prefix}-${field.name}`" v-model="draft[field.name]" type="password" class="form-control"
                        :autocomplete="field.name === 'current' ? 'current-password' : 'new-password'"
                        :minlength="field.name === 'current' ? undefined : 12" :maxlength="field.name === 'current' ? 4096 : 72"
                        @input="field.name !== 'current' && validateNewPasswordInput($event)" required :disabled="saving">
                </div>
                <p class="form-text">12 caractères minimum, 72 octets maximum. Vous devrez vous reconnecter après l’enregistrement.</p>
            </form>
        </template>
        <template #footer>
            <button type="button" class="btn btn-outline-secondary esc" :disabled="saving" @click="close">Annuler</button>
            <button type="submit" :form="prefix" class="btn btn-primary ctrl-s" :disabled="saving">Enregistrer</button>
        </template>
    </modal>
</template>

<script>
import { validateNewPasswordInput } from '../../../application/passwordPolicy'

export default {
    props: { saving: Boolean, error: String },
    emits: ['close', 'save'],
    data() {
        return {
            prefix: `password-${crypto.randomUUID()}`, draft: { current: '', password: '', confirmation: '' },
            fields: [{ name: 'current', label: 'Mot de passe actuel' }, { name: 'password', label: 'Nouveau mot de passe' },
                { name: 'confirmation', label: 'Confirmer le nouveau mot de passe' }],
        }
    },
    beforeUnmount() { this.clear() },
    methods: {
        validateNewPasswordInput,
        clear() { this.draft.current = ''; this.draft.password = ''; this.draft.confirmation = '' },
        close() {
            if (this.saving) return
            this.clear()
            this.$emit('close')
        },
        save() {
            if (this.saving) return
            if (this.draft.password !== this.draft.confirmation) {
                this.showModaleWarning('Les deux nouveaux mots de passe doivent être identiques.')
                return
            }
            this.$emit('save', { ...this.draft })
        },
    },
}
</script>
