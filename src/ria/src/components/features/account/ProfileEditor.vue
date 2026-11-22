<template>
    <modal :visible="true" width="650px" :closable="!saving" @close="close">
        <template #header>Modifier mon compte</template>
        <template #body>
            <div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
            <form :id="prefix" @submit.prevent="save">
                <div v-for="field in fields" :key="field.name" class="mb-3">
                    <label :for="`${prefix}-${field.name}`" class="form-label">{{ field.label }}</label>
                    <select v-if="field.name === 'timezone'" :id="`${prefix}-${field.name}`" v-model="draft.timezone"
                        class="form-select" required :disabled="saving">
                        <option v-for="(label, timezone) in conf.getTimezones()" :key="timezone" :value="timezone">{{ label }}</option>
                    </select>
                    <input v-else :id="`${prefix}-${field.name}`" v-model.trim="draft[field.name]" class="form-control"
                        :type="field.name === 'email' ? 'email' : 'text'" :autocomplete="field.autocomplete"
                        required maxlength="100" :disabled="saving">
                </div>
            </form>
        </template>
        <template #footer>
            <button type="button" class="btn btn-outline-secondary esc" :disabled="saving" @click="close">Annuler</button>
            <button type="submit" :form="prefix" class="btn btn-primary ctrl-s" :disabled="saving">Enregistrer</button>
        </template>
    </modal>
</template>

<script>
export default {
    props: { account: Object, fields: Array, saving: Boolean, error: String },
    emits: ['close', 'save'],
    data() {
        return { draft: Object.fromEntries(this.fields.map(field => [field.name, this.account[field.name]])), prefix: `account-${crypto.randomUUID()}` }
    },
    methods: {
        async close() {
            if (this.saving) return
            const dirty = this.fields.some(field => this.draft[field.name] !== this.account[field.name])
            if (dirty && !await this.askYesNoAsync('Abandonner les modifications du compte ?')) return
            if (!this.saving) this.$emit('close')
        },
        save() { if (!this.saving) this.$emit('save', { ...this.draft }) },
    },
}
</script>
