<template>
    <div class="role-list">
        <div v-if="!roles.length" class="alert alert-info">Aucun rôle visible.</div>

        <data-table v-else :fields="fields" :items="roles" :pagination-enabled="true"
            :row-hover="true">
            <template #cell_name="{ item }">
                <span class="fw-semibold">{{ item.name }}</span>
                <span v-if="item.technical_name" class="badge text-bg-secondary ms-2">{{ item.technical_name }}</span>
            </template>
            <template #cell_type="{ item }">{{ typeLabel(item.type) }}</template>
            <template #cell_permissions="{ item }">{{ item.permissions.length }}</template>
            <template #cell_actions="{ item }">
                <button type="button" class="btn btn-outline-primary btn-sm" :disabled="busy"
                    :aria-label="`${item.can_edit ? 'Modifier' : 'Voir'} ${item.name}`" @click="$emit('open', item)">
                    <i :class="item.can_edit ? 'fa-solid fa-pen-to-square' : 'fa-solid fa-eye'" aria-hidden="true" />
                </button>
            </template>
        </data-table>
    </div>
</template>

<script>
export default {
    props: {
        roles: { type: Array, required: true },
        busy: { type: Boolean, default: false },
    },
    emits: ['open'],
    data() {
        return {
            fields: [
                { key: 'name', label: 'Nom', filter: 'search' },
                { key: 'space_title', label: 'Espace', filter: 'search' },
                { key: 'type', label: 'Type' },
                { key: 'permissions', label: 'Permissions' },
                { key: 'actions', label: '', class: 'text-end' },
            ],
        }
    },
    methods: {
        typeLabel(type) { return { 0: 'Global', 1: 'Local', 2: 'Global et local' }[type] ?? 'Inconnu' },
    },
}
</script>

<style scoped>
.role-list {
    width: 100%;
    min-width: 0;
}
</style>
