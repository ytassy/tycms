<template>
    <modal :visible="true" width="650px" :closable="!saving" @close="close">
        <template #header>{{ integrations ? 'Mes intégrations' : 'Connexions SSO' }}</template>
        <template #body>
            <div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
            <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
            <p v-if="!items.length" class="text-body-secondary">{{ integrations ? 'Aucune intégration disponible.' : 'Aucune connexion SSO.' }}</p>
            <div v-for="item in items" :key="item.id" class="d-flex align-items-center justify-content-between gap-3 border-bottom py-2">
                <div>{{ item.name }}<span v-if="integrations" class="small text-body-secondary d-block">{{ item.connected ? 'Compte autorisé' : 'Compte non autorisé' }}{{ !item.available ? ' · Fournisseur désactivé' : '' }}</span></div>
                <button v-if="allowRemove && (!integrations || item.connected)" type="button" class="btn btn-sm btn-outline-danger"
                    :disabled="saving" @click="remove(item)">{{ integrations ? 'Déconnecter' : 'Supprimer le lien' }}</button>
                <button v-if="integrations && allowAdd && !item.connected && item.available" type="button" class="btn btn-sm btn-outline-primary"
                    :disabled="saving" @click="$emit('add', item.id)">Autoriser</button>
            </div>
            <template v-if="!integrations && allowAdd">
                <h3 class="h6 mt-4">Ajouter une connexion SSO</h3>
                <p v-if="!providers.length" class="text-body-secondary">Aucun fournisseur SSO disponible dans cet espace.</p>
                <button v-for="provider in providers" :key="provider.id" type="button" class="btn btn-outline-primary me-2 mb-2"
                    :disabled="saving" @click="$emit('add', provider.id)">{{ provider.name }}</button>
            </template>
        </template>
        <template #footer><button type="button" class="btn btn-outline-secondary esc" :disabled="saving" @click="close">Fermer</button></template>
    </modal>
</template>

<script>
export default {
    props: { integrations: Boolean, items: Array, providers: Array, allowAdd: Boolean, allowRemove: Boolean, saving: Boolean, error: String, notice: String },
    emits: ['close', 'add', 'remove'],
    methods: {
        close() { if (!this.saving) this.$emit('close') },
        async remove(item) {
            if (this.saving) return
            const text = this.integrations ? `Déconnecter l’intégration « ${item.name} » ?` : `Supprimer la connexion SSO « ${item.name} » ?`
            if (!await this.askYesNoAsync(text)) return
            if (!this.saving && this.items.some(current => current.id === item.id)) this.$emit('remove', item.id)
        },
    },
}
</script>
