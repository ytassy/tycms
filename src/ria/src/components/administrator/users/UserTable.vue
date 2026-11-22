<template>
    <div class="table-responsive">
        <table class="table table-hover table-bordered table-sm align-middle users-table">
            <thead><tr>
                <th>Nom</th><th>E-mail</th><th>Espace principal</th>
                <th>Groupes</th><th>Rôles</th><th>État</th><th class="text-end">Actions</th>
            </tr></thead>
            <tbody>
                <tr v-for="user in users" :key="user.id">
                    <td class="fw-semibold">{{ user.first_name }} {{ user.last_name }}</td>
                    <td>{{ user.email }}</td>
                    <td>{{ spaceName(user.space_id) }}</td>
                    <td>
                        <span v-for="group in user.groups" :key="group.id" class="badge text-bg-light border text-dark me-1 mb-1"
                            :title="spaceName(group.space_id)">{{ group.name }}</span>
                        <span v-if="!user.groups.length" class="text-body-secondary">—</span>
                        <button v-if="user.can_assign_groups" type="button" class="btn btn-outline-secondary btn-sm ms-1"
                            :disabled="saving" :aria-label="'Modifier les groupes de ' + user.first_name + ' ' + user.last_name"
                            @click="$emit('assign', 'groups', user)"><i class="fa-solid fa-pen" aria-hidden="true" /></button>
                    </td>
                    <td>
                        <span v-for="role in user.roles" :key="`${role.id}-${role.target_space_id}`" class="badge text-bg-primary me-1 mb-1">
                            {{ role.name }} · {{ spaceName(role.target_space_id) }}
                        </span>
                        <span v-if="!user.roles.length" class="text-body-secondary">—</span>
                        <button v-if="user.can_assign" type="button" class="btn btn-outline-secondary btn-sm ms-1"
                            :disabled="saving" :aria-label="'Modifier les rôles de ' + user.first_name + ' ' + user.last_name"
                            @click="$emit('assign', 'roles', user)"><i class="fa-solid fa-pen" aria-hidden="true" /></button>
                    </td>
                    <td><span :class="user.blocked ? 'badge text-bg-warning' : 'badge text-bg-success'">
                        {{ user.blocked ? 'Bloqué' : 'Actif' }}</span></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-outline-primary btn-sm" :disabled="saving"
                            :aria-label="'Ouvrir ' + user.first_name + ' ' + user.last_name" @click="$emit('edit', user)">
                            Modifier <i class="fa-solid fa-pen-to-square" aria-hidden="true" />
                        </button>
                        <button v-if="user.can_admin" type="button" class="btn btn-outline-secondary btn-sm ms-1"
                            :disabled="saving" :aria-label="'Connexion et sécurité de ' + user.first_name + ' ' + user.last_name"
                            @click="$emit('security', user)">
                            <i class="fa-solid fa-key" aria-hidden="true" /> Connexion et sécurité
                        </button>
                    </td>
                </tr>
                <tr v-if="!users.length"><td colspan="7" class="text-center text-body-secondary py-4">Aucun utilisateur trouvé.</td></tr>
            </tbody>
        </table>
    </div>
</template>

<script>
export default {
    props: {
        users: { type: Array, required: true },
        directory: { type: Object, default: null },
        saving: { type: Boolean, default: false },
    },
    emits: ['edit', 'security', 'assign'],
    methods: {
        spaceName(id) { return this.directory?.spaces.find(space => space.id === id)?.title ?? `#${id}` },
    },
}
</script>

<style scoped>
/* La table suit son contenu au lieu de remplir la largeur imposée par Bootstrap. */
.users-table { width: auto; }
.users-table th, .users-table td { max-width: 22rem; }
</style>
