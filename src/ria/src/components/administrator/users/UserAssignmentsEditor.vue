<template>
    <modal :visible="true" width="650px" :closable="!saving" @close="$emit('close')">
        <template #header>Modifier {{ title }}</template>
        <template #body>
            <div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>

            <template v-if="kind === 'groups'">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span v-for="group in selectedGroups" :key="group.id" class="badge text-bg-secondary d-inline-flex align-items-center gap-2">
                        {{ group.name }} · {{ spaceName(group.space_id) }}
                        <button v-if="canManage(group.space_id, group.space_id)" type="button"
                            class="btn-close btn-close-white" :disabled="saving"
                            :aria-label="'Retirer du groupe ' + group.name" @click="removeGroup(group.id)" />
                    </span>
                    <span v-if="!availableGroups.length" class="text-body-secondary small">Aucun autre groupe attribuable avec vos droits actuels.</span>
                </div>
                <div v-if="availableGroups.length" class="mt-3">
                    <label for="assignment-search" class="form-label small mb-1">Ajouter un groupe</label>
                    <input id="assignment-search" v-model.trim="search" type="search" class="form-control form-control-sm mb-2" placeholder="Rechercher un groupe" />
                    <div class="list-group assignment-options">
                        <button v-for="group in filteredGroups" :key="group.id" type="button" class="list-group-item list-group-item-action"
                            :disabled="saving" @click="addGroup(group)">{{ group.name }} · {{ spaceName(group.space_id) }}</button>
                    </div>
                    <span v-if="!filteredGroups.length" class="text-body-secondary small">Aucun résultat.</span>
                </div>
            </template>

            <template v-else>
                <p class="text-body-secondary small">Les rôles directs sont attribués à un espace. La permission MEMBER donne accès à cet espace et à tous ses enfants.</p>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span v-for="role in selectedRoles" :key="`${role.role_id}-${role.space_id}`"
                        class="badge text-bg-primary d-inline-flex align-items-center gap-2">
                        {{ role.name }} · {{ spaceName(role.space_id) }}
                        <button v-if="canManage(role.space_id, role.owner_space_id)" type="button"
                            class="btn-close btn-close-white" :disabled="saving"
                            :aria-label="'Retirer le rôle ' + role.name" @click="removeRole(role)" />
                    </span>
                </div>
                <div v-if="roleTargetSpaces.length" class="mt-3">
                    <div>
                        <label for="assignment-role-space" class="form-label small mb-1">Espace du rôle</label>
                        <select id="assignment-role-space" v-model.number="targetSpaceId" class="form-select form-select-sm">
                            <option v-for="space in roleTargetSpaces" :key="space.id" :value="space.id">{{ space.title }}</option>
                        </select>
                    </div>
                    <div v-if="availableRoles.length" class="mt-3">
                        <label for="assignment-search" class="form-label small mb-1">Ajouter un rôle</label>
                        <input id="assignment-search" v-model.trim="search" type="search" class="form-control form-control-sm mb-2" placeholder="Rechercher un rôle" />
                        <div class="list-group assignment-options">
                            <button v-for="role in filteredRoles" :key="role.id" type="button" class="list-group-item list-group-item-action"
                                :disabled="saving" @click="addRole(role)">{{ role.name }} · {{ spaceName(role.space_id) }}</button>
                        </div>
                        <span v-if="!filteredRoles.length" class="text-body-secondary small">Aucun résultat.</span>
                    </div>
                    <span v-else class="text-body-secondary small">Aucun autre rôle attribuable sur cet espace avec vos droits actuels.</span>
                </div>
            </template>
        </template>
        <template #footer>
            <button type="button" class="btn btn-outline-secondary me-2" :disabled="saving" @click="$emit('close')">Annuler</button>
            <button type="button" class="btn btn-primary" :disabled="saving" @click="save">Enregistrer</button>
        </template>
    </modal>
</template>

<script>
export default {
    props: {
        kind: { type: String, required: true, validator: value => ['groups', 'roles'].includes(value) },
        user: { type: Object, required: true },
        directory: { type: Object, required: true },
        saving: { type: Boolean, default: false },
        error: { type: String, default: '' },
    },
    emits: ['close', 'save'],
    data() {
        // Les sélections locales ne touchent jamais l'utilisateur affiché avant Enregistrer.
        const initial = this.kind === 'groups' ? this.user.groups.map(group => group.id)
            : this.user.roles.map(role => ({ role_id: role.id, space_id: role.target_space_id }))
        return {
            before: initial.map(item => typeof item === 'object' ? { ...item } : item),
            selected: initial,
            targetSpaceId: this.directory.spaces.find(space => space.id === this.user.space_id && space.can_assign)?.id
                ?? this.directory.spaces.find(space => space.can_assign)?.id ?? null,
            search: '',
        }
    },
    computed: {
        title() { return { groups: 'les groupes', roles: 'les rôles' }[this.kind] },
        assignmentCapability() { return this.kind === 'groups' ? 'can_assign_groups' : 'can_assign' },
        selectedGroups() {
            return this.selected.map(id => this.directory.groups.find(group => group.id === id)
                ?? this.user.groups.find(group => group.id === id)).filter(Boolean)
        },
        availableGroups() {
            return this.directory.groups.filter(group => this.canManage(group.space_id, group.space_id)
                && !this.selected.includes(group.id))
        },
        filteredGroups() { return this.filterOptions(this.availableGroups, 'name') },
        selectedRoles() {
            return this.selected.map(assignment => {
                const role = this.directory.roles.find(item => item.id === assignment.role_id)
                    ?? this.user.roles.find(item => item.id === assignment.role_id
                        && item.target_space_id === assignment.space_id)
                return { ...assignment, name: role?.name ?? `#${assignment.role_id}`,
                    owner_space_id: role?.space_id ?? null }
            })
        },
        roleTargetSpaces() {
            return this.directory.spaces.filter(space => this.user.can_assign && space.can_assign)
        },
        availableRoles() {
            return this.directory.roles.filter(role => this.spaceAncestors(this.targetSpaceId).includes(role.space_id)
                && this.canManage(this.targetSpaceId, role.space_id)
                && !this.selected.some(item => item.role_id === role.id && item.space_id === this.targetSpaceId))
        },
        filteredRoles() { return this.filterOptions(this.availableRoles, 'name') },
    },
    methods: {
        filterOptions(items, field) {
            const term = this.search.toLocaleLowerCase()
            return term ? items.filter(item => item[field].toLocaleLowerCase().includes(term)) : items
        },
        spaceName(id) { return this.directory.spaces.find(space => space.id === id)?.title ?? `#${id}` },
        spaceAncestors(id) {
            const ids = []
            const visited = new Set()
            let current = this.directory.spaces.find(space => space.id === id)
            while (current && !visited.has(current.id)) {
                ids.push(current.id)
                visited.add(current.id)
                current = this.directory.spaces.find(space => space.id === current.parent_id)
            }
            return ids
        },
        canManage(targetSpaceId, ownerSpaceId) {
            return this.user[this.assignmentCapability] === true && [targetSpaceId, ownerSpaceId].every(id =>
                this.directory.spaces.some(space => space.id === id && space[this.assignmentCapability] === true))
        },
        addGroup(group) { this.selected = [...this.selected, group.id] },
        removeGroup(id) { this.selected = this.selected.filter(value => value !== id) },
        addRole(role) { this.selected = [...this.selected, { role_id: role.id, space_id: this.targetSpaceId }] },
        removeRole(role) {
            this.selected = this.selected.filter(value => value.role_id !== role.role_id || value.space_id !== role.space_id)
        },
        save() {
            this.$emit('save', { before: this.before, after: this.selected })
        },
    },
}
</script>

<style scoped>
.assignment-options { max-height: 12rem; overflow-y: auto; }
</style>
