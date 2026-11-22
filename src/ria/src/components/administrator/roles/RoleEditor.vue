<template>
    <modal :visible="true" width="900px" :large-body="true" :closable="!saving" @close="$emit('close')">
        <template #header>
            <span>{{ role ? `Modifier ${role.name}` : 'Ajouter un rôle' }}</span>
            <span v-if="role?.technical_name" class="badge text-bg-secondary ms-2">{{ role.technical_name }}</span>
        </template>

        <template #body>
            <form id="role-editor-form" @submit.prevent="submit">
                <div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
                <div v-if="role?.assignment_notice" class="alert alert-info">{{ role.assignment_notice }}</div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="role-name">Nom</label>
                        <input id="role-name" v-model.trim="draft.name" class="form-control"
                            maxlength="100" required :disabled="saving || !canEdit || role?.can_edit_details === false" />
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="role-space">Espace propriétaire</label>
                        <select id="role-space" v-model.number="draft.space_id" class="form-select"
                            :disabled="saving || role !== null">
                            <option v-for="space in spaces" :key="space.id" :value="space.id">{{ space.title }}</option>
                            <option v-if="role && !spaces.some(space => space.id === role.space_id)"
                                :value="role.space_id">{{ role.space_title }}</option>
                        </select>
                        <div v-if="role" class="form-text">L’espace reste fixe après la création.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="role-type">Type</label>
                        <select id="role-type" v-model.number="draft.type" class="form-select"
                            :disabled="saving || role !== null">
                            <option :value="0">Global</option>
                            <option :value="1">Local</option>
                            <option :value="2">Global et local</option>
                        </select>
                        <div v-if="role" class="form-text">Le type reste fixe après la création.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="role-description">Description</label>
                        <textarea id="role-description" v-model="draft.description" class="form-control"
                            rows="2" maxlength="5000" :disabled="saving || !canEdit || role?.can_edit_details === false" />
                    </div>
                </div>

                <h3 class="h6 mt-4">Permissions</h3>
                <p class="text-body-secondary small">Les permissions grisées sont visibles, mais seuls les droits autorisés et possédés peuvent être modifiés.</p>

                <RolePermissionList :model-value="draft.permissions" :permissions="permissions"
                    :space-id="draft.space_id" :role-id="role?.id ?? null" :editable="canEdit && !saving"
                    @update:model-value="draft.permissions = $event" />

                <div v-if="role?.id === 5" class="alert alert-info mt-3 mb-0">La permission MEMBER du rôle 5 est obligatoire.</div>
                <div v-if="role?.id === 2" class="alert alert-info mt-3 mb-0">La permission ROOT du rôle système 2 est obligatoire.</div>
            </form>
        </template>

        <template #footer>
            <button v-if="role?.can_delete" type="button" class="btn btn-outline-danger me-2"
                :disabled="saving" @click="$emit('delete')">Supprimer</button>
            <button type="button" class="btn btn-outline-secondary me-2" :disabled="saving" @click="$emit('close')">
                {{ canEdit ? 'Annuler' : 'Fermer' }}
            </button>
            <button v-if="canEdit" type="submit" form="role-editor-form" class="btn btn-primary" :disabled="saving">
                {{ role ? 'Enregistrer' : 'Ajouter' }}
            </button>
        </template>
    </modal>
</template>

<script>
import RolePermissionList from './RolePermissionList.vue'

export default {
    components: { RolePermissionList },

    props: {
        role: { type: Object, default: null },
        spaces: { type: Array, required: true },
        permissions: { type: Array, required: true },
        saving: { type: Boolean, default: false },
        error: { type: String, default: '' },
    },
    emits: ['close', 'save', 'delete'],

    data() {
        // Le brouillon appartient à la modale : fermer l'éditeur l'abandonne.
        return {
            draft: this.role
                ? {
                    space_id: this.role.space_id,
                    type: this.role.type,
                    name: this.role.name,
                    description: this.role.description,
                    permissions: [...this.role.permissions],
                }
                : { space_id: this.spaces[0]?.id ?? null, type: 0, name: '', description: '', permissions: [] },
        }
    },

    computed: {
        canEdit() { return this.role === null || this.role.can_edit },
    },

    methods: {
        submit() {
            if (this.saving || !this.canEdit) return

            const data = {
                name: this.draft.name,
                description: this.draft.description,
                permissions: [...this.draft.permissions],
            }

            if (!this.role) {
                data.space_id = this.draft.space_id
                data.type = this.draft.type
            }

            this.$emit('save', data)
        },
    },
}
</script>
