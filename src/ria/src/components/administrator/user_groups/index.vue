<template>
    <div class="user-groups-page">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h1>Groupes d’utilisateurs</h1>
                <p class="text-body-secondary">Organisez les groupes par espace.</p>
            </div>
            <button v-if="creatableSpaces.length" type="button" class="btn btn-primary btn-sm"
                :disabled="saving" @click="openCreate">Ajouter un groupe</button>
        </div>

        <div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
        <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
        <div v-if="loading" class="py-5 text-center"><spinner /></div>

        <div v-else-if="directory">
            <div v-if="!directory.groups.length" class="alert alert-info">Aucun groupe visible.</div>
            <data-table v-else class="groups-table" :fields="fields" :items="directory.groups" :pagination-enabled="true" :row-hover="true">
                <template #cell_name="{ item }">
                    <span class="fw-semibold">{{ item.name }}</span>
                    <span v-if="item.technical_name" class="badge text-bg-secondary ms-2">{{ item.technical_name }}</span>
                </template>
                <template #cell_parent_id="{ item }">{{ parentName(item) }}</template>
                <template #cell_roles="{ item }">
                    <span v-for="role in item.roles" :key="role.id"
                        :class="['badge me-1', role.space_id === item.space_id && role.type !== 1 ? 'text-bg-primary' : 'text-bg-secondary']">
                        {{ role.name }}
                    </span>
                    <span v-if="!item.roles.length" class="text-body-secondary">—</span>
                </template>
                <template #cell_description="{ item }">
                    <span class="group-description" :title="item.description">{{ item.description || '—' }}</span>
                </template>
                <template #cell_actions="{ item }">
                    <button type="button" class="btn btn-outline-primary btn-sm" :disabled="saving"
                        :aria-label="((item.can_edit || item.can_assign_roles) ? 'Modifier ' : 'Voir ') + item.name" @click="openEdit(item)">
                        <i :class="(item.can_edit || item.can_assign_roles) ? 'fa-solid fa-pen-to-square' : 'fa-solid fa-eye'" aria-hidden="true" />
                    </button>
                </template>
            </data-table>
        </div>

        <modal v-if="editorVisible" :visible="true" width="800px" :closable="!saving" @close="closeEditor">
            <template #header>{{ editingGroup ? 'Groupe « ' + editingGroup.name + ' »' : 'Ajouter un groupe' }}</template>
            <template #body>
                <form id="user-group-editor-form" @submit.prevent="saveGroup">
                    <div v-if="editorError" class="alert alert-warning" role="alert">{{ editorError }}</div>
                    <div v-if="editingGroup?.assignment_notice" class="alert alert-info">{{ editingGroup.assignment_notice }}</div>
                    <div class="mb-3">
                        <label for="user-group-name" class="form-label">Nom</label>
                        <input id="user-group-name" v-model.trim="draft.name" class="form-control" maxlength="100"
                            required :disabled="saving || !canEdit" />
                    </div>
                    <div class="mb-3">
                        <label for="user-group-space" class="form-label">Espace propriétaire</label>
                        <select id="user-group-space" v-model.number="draft.space_id" class="form-select"
                            :disabled="saving || editingGroup !== null">
                            <option v-for="space in creatableSpaces" :key="space.id" :value="space.id">{{ space.title }}</option>
                            <option v-if="editingGroup && !creatableSpaces.some(space => space.id === editingGroup.space_id)"
                                :value="editingGroup.space_id">{{ editingGroup.space_title }}</option>
                        </select>
                        <div v-if="editingGroup" class="form-text">L’espace reste fixe après la création.</div>
                    </div>
                    <div class="mb-3">
                        <label for="user-group-parent" class="form-label">Groupe parent</label>
                        <select id="user-group-parent" v-model.number="draft.parent_id" class="form-select"
                            :disabled="saving || !canEdit">
                            <option :value="0">Aucun — groupe de premier niveau</option>
                            <option v-for="group in parentOptions" :key="group.id" :value="group.id">{{ group.name }}</option>
                        </select>
                        <div class="form-text">Un groupe hérite des droits attribués à ses parents.</div>
                    </div>
                    <div>
                        <label for="user-group-description" class="form-label">Description</label>
                        <textarea id="user-group-description" v-model="draft.description" class="form-control"
                            rows="3" maxlength="5000" :disabled="saving || !canEdit" />
                    </div>

                    <div class="mt-4 border-top pt-3">
                        <h3 class="h6">Rôles du groupe</h3>
                        <p class="text-body-secondary small">Seuls les rôles globaux du même espace peuvent être attribués.</p>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span v-for="role in selectedRoles" :key="role.id" class="badge text-bg-primary d-inline-flex align-items-center gap-2">
                                {{ role.name }}
                                <button v-if="canAssignRoles && !isRequiredRole(role.id)" type="button"
                                    class="btn-close btn-close-white" :disabled="saving"
                                    :aria-label="'Retirer ' + role.name" @click="removeRole(role.id)" />
                            </span>
                            <DropdownList v-if="canAssignRoles && !saving && unassignedRoles.length"
                                :items="unassignedRoles" field="name" :search-on-fields="['name']"
                                variant="outline-secondary" @select="addRole">
                                Ajouter un rôle
                            </DropdownList>
                            <span v-if="!selectedRoles.length && !unassignedRoles.length" class="text-body-secondary small">Aucun rôle global disponible.</span>
                        </div>
                        <p v-if="editingGroup?.id === 3" class="form-text">Le rôle Root est obligatoire pour le groupe Super Admin.</p>
                        <div v-if="unmanagedRoles.length" class="mt-3">
                            <span class="text-body-secondary small">Attributions existantes hors de ce périmètre :</span>
                            <span v-for="role in unmanagedRoles" :key="role.id" class="badge text-bg-secondary ms-1">{{ role.name }}</span>
                        </div>
                    </div>
                </form>
            </template>
            <template #footer>
                <button v-if="editingGroup?.can_delete" type="button" class="btn btn-outline-danger me-2"
                    :disabled="saving" @click="deleteGroup">Supprimer</button>
                <button type="button" class="btn btn-outline-secondary me-2" :disabled="saving" @click="closeEditor">
                    {{ canEdit || canAssignRoles ? 'Annuler' : 'Fermer' }}
                </button>
                <button v-if="canEdit || canAssignRoles" type="submit" form="user-group-editor-form" class="btn btn-primary"
                    :disabled="saving">{{ editingGroup ? 'Enregistrer' : 'Ajouter' }}</button>
            </template>
        </modal>
    </div>
</template>

<script>
import DropdownList from '../../common/DropdownList.vue'

export default {
    components: { DropdownList },
    data() {
        return {
            fields: [
                { key: 'name', label: 'Nom', filter: 'search' },
                { key: 'space_title', label: 'Espace', filter: 'search' },
                { key: 'parent_id', label: 'Parent' },
                { key: 'roles', label: 'Rôles' },
                { key: 'description', label: 'Description', filter: 'search' },
                { key: 'actions', label: '', class: 'text-end' },
            ],
            directory: null, loading: false, saving: false, error: '', editorError: '', notice: '',
            editorVisible: false, editingGroup: null,
            draft: { space_id: null, parent_id: 0, name: '', description: '', role_ids: [] },
        }
    },
    computed: {
        creatableSpaces() { return this.directory?.spaces.filter(space => space.can_create) ?? [] },
        canEdit() { return this.editingGroup === null || this.editingGroup.can_edit },
        canAssignRoles() {
            if (this.editingGroup !== null) return this.editingGroup.can_assign_roles === true
            return this.directory?.spaces.find(space => space.id === this.draft.space_id)?.can_assign_roles === true
        },
        availableRoles() {
            return (this.directory?.roles ?? []).filter(role => role.space_id === this.draft.space_id)
        },
        selectedRoles() {
            // Les liens système restent lisibles bien qu'ils soient absents du catalogue attribuable.
            return this.draft.role_ids.map(id => this.availableRoles.find(role => role.id === id)
                ?? this.editingGroup?.roles.find(role => role.id === id)).filter(Boolean)
        },
        unassignedRoles() {
            return this.availableRoles.filter(role => !this.draft.role_ids.includes(role.id))
        },
        unmanagedRoles() {
            return (this.editingGroup?.roles ?? []).filter(role => role.space_id !== this.draft.space_id || ![0, 2].includes(role.type))
        },
        parentOptions() {
            return (this.directory?.groups ?? []).filter(group => group.space_id === this.draft.space_id
                && !group.technical_name && group.id !== this.editingGroup?.id
                && !this.isDescendant(group.id, this.editingGroup?.id))
        },
    },
    mounted() { this.load() },
    methods: {
        async load() {
            this.loading = true
            this.error = ''
            try {
                this.directory = await this.api.get('/administrator/api/user-groups')
            } catch (error) {
                this.error = error?.message ?? 'Impossible de charger les groupes.'
            } finally {
                this.loading = false
            }
        },
        parentName(group) {
            return this.directory?.groups.find(parent => parent.id === group.parent_id)?.name ?? '—'
        },
        isDescendant(candidateId, ancestorId) {
            if (!ancestorId) return false
            const seen = new Set()
            let group = this.directory?.groups.find(item => item.id === candidateId)
            while (group?.parent_id && !seen.has(group.id)) {
                if (group.parent_id === ancestorId) return true
                seen.add(group.id)
                group = this.directory.groups.find(item => item.id === group.parent_id)
            }
            return false
        },
        openCreate() {
            if (this.saving || !this.creatableSpaces.length) return
            this.editingGroup = null
            this.draft = { space_id: this.creatableSpaces[0].id, parent_id: 0, name: '', description: '', role_ids: [] }
            this.editorError = ''
            this.notice = ''
            this.editorVisible = true
        },
        openEdit(group) {
            if (this.saving) return
            this.editingGroup = group
            this.draft = {
                space_id: group.space_id, parent_id: group.parent_id ?? 0,
                name: group.name, description: group.description, role_ids: [...group.role_ids],
            }
            this.editorError = ''
            this.notice = ''
            this.editorVisible = true
        },
        closeEditor() {
            if (this.saving) return
            this.editorVisible = false
            this.editingGroup = null
            this.editorError = ''
        },
        isRequiredRole(roleId) { return this.editingGroup?.id === 3 && roleId === 2 },
        addRole(role) {
            if (!this.canAssignRoles || this.saving || this.draft.role_ids.includes(role.id)) return
            this.draft.role_ids = [...this.draft.role_ids, role.id]
        },
        removeRole(roleId) {
            if (!this.canAssignRoles || this.saving || this.isRequiredRole(roleId)) return
            this.draft.role_ids = this.draft.role_ids.filter(id => id !== roleId)
        },
        async saveGroup() {
            if (this.saving || !this.editorVisible || (!this.canEdit && !this.canAssignRoles)) return
            this.saving = true
            this.editorError = ''
            const data = {
                parent_id: this.draft.parent_id,
                name: this.draft.name,
                description: this.draft.description,
            }
            if (this.canAssignRoles) data.role_ids = [...this.draft.role_ids]
            try {
                if (this.editingGroup && !this.canEdit) {
                    await this.api.post('/administrator/api/user-groups/roles/update', {
                        groupId: this.editingGroup.id, roleIds: data.role_ids ?? [],
                    })
                } else if (this.editingGroup) {
                    await this.api.post('/administrator/api/user-groups/update', {
                        groupId: this.editingGroup.id, data,
                    })
                } else {
                    data.space_id = this.draft.space_id
                    await this.api.post('/administrator/api/user-groups/create', { data })
                }
                this.editorVisible = false
                this.editingGroup = null
                await this.load()
                this.notice = 'Groupe enregistré.'
            } catch (error) {
                this.editorError = error?.message ?? 'Impossible d’enregistrer ce groupe.'
            } finally {
                this.saving = false
            }
        },
        async deleteGroup() {
            if (this.saving || !this.editingGroup?.can_delete) return
            if (!await this.askYesNoAsync('Supprimer le groupe « ' + this.editingGroup.name + ' » ?')) return
            this.saving = true
            this.editorError = ''
            try {
                await this.api.post('/administrator/api/user-groups/delete', { groupId: this.editingGroup.id })
                this.editorVisible = false
                this.editingGroup = null
                await this.load()
                this.notice = 'Groupe supprimé.'
            } catch (error) {
                this.editorError = error?.message ?? 'Impossible de supprimer ce groupe.'
            } finally {
                this.saving = false
            }
        },
    },
}
</script>

<style scoped>
.groups-table { width: max-content; max-width: 100%; }
.group-description { display: inline-block; max-width: 22rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle; }
</style>
