<template>
    <div class="role-page">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h1>Rôles</h1>
                <p class="text-body-secondary">Gérez les rôles et leurs permissions par espace.</p>
            </div>
            <button v-if="creatableSpaces.length" type="button" class="btn btn-primary btn-sm"
                :disabled="saving" @click="openCreate">Ajouter un rôle</button>
        </div>

        <div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
        <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
        <div v-if="loading" class="py-5 text-center"><spinner /></div>

        <RoleList v-else-if="directory" :roles="directory.roles" :busy="saving" @open="openEdit" />

        <RoleEditor v-if="editorVisible" :role="editingRole" :spaces="creatableSpaces"
            :permissions="directory.permissions" :saving="saving" :error="editorError"
            @close="closeEditor" @save="saveRole" @delete="deleteRole" />
    </div>
</template>

<script>
import RoleEditor from './RoleEditor.vue'
import RoleList from './RoleList.vue'

export default {
    components: { RoleEditor, RoleList },

    data() {
        return {
            directory: null,
            loading: false,
            saving: false,
            error: '',
            editorError: '',
            notice: '',
            editorVisible: false,
            editingRole: null,
        }
    },

    computed: {
        creatableSpaces() { return this.directory?.spaces.filter(space => space.can_create) ?? [] },
    },

    mounted() { this.load() },

    methods: {
        async load() {
            this.loading = true
            this.error = ''

            try {
                this.directory = await this.api.get('/administrator/api/roles')
            } catch (error) {
                this.error = error?.message ?? 'Impossible de charger les rôles.'
            } finally {
                this.loading = false
            }
        },

        openCreate() {
            if (!this.creatableSpaces.length || this.saving) return

            this.editingRole = null
            this.editorError = ''
            this.notice = ''
            this.editorVisible = true
        },

        openEdit(role) {
            if (this.saving) return

            this.editingRole = role
            this.editorError = ''
            this.notice = ''
            this.editorVisible = true
        },

        closeEditor() {
            if (this.saving) return

            this.editorVisible = false
            this.editingRole = null
            this.editorError = ''
        },

        async saveRole(data) {
            if (this.saving || !this.editorVisible) return

            this.saving = true
            this.editorError = ''

            try {
                if (this.editingRole) {
                    await this.api.post('/administrator/api/roles/update', {
                        roleId: this.editingRole.id,
                        data,
                    })
                } else {
                    await this.api.post('/administrator/api/roles/create', { data })
                }

                this.editorVisible = false
                this.editingRole = null
                await this.load()
                this.notice = 'Rôle enregistré.'
            } catch (error) {
                this.editorError = error?.message ?? 'Impossible d’enregistrer ce rôle.'
            } finally {
                this.saving = false
            }
        },

        async deleteRole() {
            if (this.saving || !this.editingRole?.can_delete) return
            if (!await this.askYesNoAsync(`Supprimer le rôle « ${this.editingRole.name} » ?`)) return

            this.saving = true
            this.editorError = ''

            try {
                await this.api.post('/administrator/api/roles/delete', { roleId: this.editingRole.id })

                this.editorVisible = false
                this.editingRole = null
                await this.load()
                this.notice = 'Rôle supprimé.'
            } catch (error) {
                this.editorError = error?.message ?? 'Impossible de supprimer ce rôle.'
            } finally {
                this.saving = false
            }
        },
    },
}
</script>

<style scoped>
.role-page {
    width: 100%;
    min-width: 0;
}
</style>
