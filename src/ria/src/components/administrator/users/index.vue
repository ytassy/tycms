<template>
    <div class="users-page">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h1>Utilisateurs</h1>
                <p class="text-body-secondary">Comptes rattachés à leur espace principal.</p>
            </div>
            <button v-if="creatableSpaces.length" type="button" class="btn btn-primary btn-sm" @click="openCreate">Ajouter un utilisateur</button>
        </div>

        <div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
        <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
        <div v-if="generatedPassword" class="alert alert-info" role="status">
            Mot de passe initial : <code>{{ generatedPassword }}</code>. Conservez-le : il ne sera plus affiché après fermeture.
            <button type="button" class="btn-close float-end" aria-label="Masquer le mot de passe" @click="generatedPassword = ''" />
        </div>

        <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
            <div>
                <label for="users-search" class="form-label small mb-1">Rechercher</label>
                <input id="users-search" v-model="search" type="search" class="form-control form-control-sm"
                    placeholder="Nom ou e-mail" @input="scheduleSearch" />
            </div>
            <div>
                <label for="users-type" class="form-label small mb-1">Type de compte</label>
                <select id="users-type" v-model="accountType" class="form-select form-select-sm" @change="resetPage">
                    <option value="all">Tous les comptes</option>
                    <option value="user">Utilisateurs</option>
                    <option value="service">Comptes de service</option>
                </select>
            </div>
            <div>
                <label for="users-per-page" class="form-label small mb-1">Lignes par page</label>
                <select id="users-per-page" v-model.number="perPage" class="form-select form-select-sm" @change="resetPage">
                    <option v-for="size in [10, 20, 50, 100]" :key="size" :value="size">{{ size }}</option>
                </select>
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="loading" @click="loadPage">Actualiser</button>
        </div>

        <div v-if="loading && !users.length" class="text-center py-5"><spinner /></div>
        <UserTable v-else :users="users" :directory="directory" :saving="saving"
            @edit="openEdit" @security="openSecurity" @assign="openAssignments" />

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
            <span class="text-body-secondary small">{{ total }} compte{{ total > 1 ? 's' : '' }}</span>
            <nav v-if="total > perPage" aria-label="Pages des utilisateurs" class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-secondary" :disabled="page === 0 || loading" @click="goPage(0)">«</button>
                <button type="button" class="btn btn-outline-secondary" :disabled="page === 0 || loading" @click="goPage(page - 1)">Précédent</button>
                <span class="btn btn-outline-secondary disabled">{{ page + 1 }} / {{ pageCount }}</span>
                <button type="button" class="btn btn-outline-secondary" :disabled="page >= pageCount - 1 || loading" @click="goPage(page + 1)">Suivant</button>
                <button type="button" class="btn btn-outline-secondary" :disabled="page >= pageCount - 1 || loading" @click="goPage(pageCount - 1)">»</button>
            </nav>
        </div>

        <UserAccountEditor v-if="editorVisible" :user="editingUser" :directory="directory"
            :saving="saving" :error="editorError"
            @close="closeEditor" @save="saveAccount" @delete="deleteUser" />

        <UserSecurityEditor v-if="securityUser" :user="securityUser" :directory="directory"
            :saving="saving" :error="securityError" :notice="securityNotice"
            @close="closeSecurity" @save-password="savePassword" @unlink-sso="unlinkSso" />

        <UserAssignmentsEditor v-if="assignmentKind" :kind="assignmentKind" :user="editingUser"
            :directory="directory" :saving="saving" :error="assignmentError"
            @close="closeAssignments" @save="saveAssignments" />
    </div>
</template>

<script>
import UserAccountEditor from './UserAccountEditor.vue'
import UserAssignmentsEditor from './UserAssignmentsEditor.vue'
import UserSecurityEditor from './UserSecurityEditor.vue'
import UserTable from './UserTable.vue'

export default {
    components: { UserAccountEditor, UserAssignmentsEditor, UserSecurityEditor, UserTable },
    data() {
        return {
            directory: null, users: [], total: 0, page: 0, perPage: 20, search: '',
            accountType: 'all',
            loading: true, saving: false, error: '', notice: '', generatedPassword: '',
            editorVisible: false, editingUser: null, editorError: '',
            securityUser: null, securityError: '', securityNotice: '',
            assignmentKind: null, assignmentError: '', searchTimer: null, requestId: 0,
        }
    },
    computed: {
        pageCount() { return Math.max(1, Math.ceil(this.total / this.perPage)) },
        creatableSpaces() { return this.directory?.spaces.filter(space => space.can_create) ?? [] },
        canEditAccount() { return this.editingUser ? this.editingUser.can_edit : this.creatableSpaces.length > 0 },
    },
    mounted() { this.load() },
    beforeUnmount() { if (this.searchTimer) clearTimeout(this.searchTimer) },
    methods: {
        async load() {
            this.error = ''
            try {
                this.directory = await this.api.get('/administrator/api/users/directory')
                await this.loadPage()
            } catch (error) {
                this.error = error?.message ?? 'Impossible de charger les utilisateurs.'
                this.loading = false
            }
        },
        async loadPage() {
            // Seule la dernière réponse de recherche peut remplacer la page visible.
            const requestId = ++this.requestId
            this.loading = true
            this.error = ''
            try {
                const result = await this.api.get('/administrator/api/users',
                    { page: this.page + 1, perPage: this.perPage, search: this.search.trim(), type: this.accountType })
                if (requestId !== this.requestId) return
                this.users = result.users
                this.total = result.total
                if (this.page >= this.pageCount && this.page > 0) {
                    this.page = this.pageCount - 1
                    await this.loadPage()
                }
            } catch (error) {
                if (requestId === this.requestId) this.error = error?.message ?? 'Impossible de charger les utilisateurs.'
            } finally {
                if (requestId === this.requestId) this.loading = false
            }
        },
        scheduleSearch() {
            if (this.searchTimer) clearTimeout(this.searchTimer)
            this.searchTimer = setTimeout(() => this.resetPage(), 300)
        },
        resetPage() { this.page = 0; this.loadPage() },
        goPage(page) { this.page = page; this.loadPage() },
        openCreate() {
            this.editingUser = null
            this.editorError = ''
            this.editorVisible = true
        },
        async openEdit(user) {
            this.editorError = ''
            this.saving = true
            try {
                this.editingUser = (await this.api.get('/administrator/api/users/read', { userId: user.id })).user
                this.editorVisible = true
            } catch (error) {
                this.error = error?.message ?? 'Impossible de charger ce compte.'
            } finally {
                this.saving = false
            }
        },
        closeEditor() {
            if (this.saving) return
            this.editorVisible = false
            this.editingUser = null
            this.editorError = ''
            this.assignmentKind = null
        },
        async saveAccount(data) {
            // La modale transmet son brouillon ; la page centralise l'enregistrement et son résultat.
            if (this.saving || !this.canEditAccount) return
            this.saving = true
            this.editorError = ''
            let welcomeEmailFailed = false
            try {
                if (this.editingUser) {
                    await this.api.post('/administrator/api/users/update',
                        { userId: this.editingUser.id, data })
                    // Fermer l'édition seulement après confirmation de l'enregistrement.
                    this.editorVisible = false
                    this.editingUser = null
                    this.notice = 'Compte enregistré.'
                } else {
                    const result = await this.api.post('/administrator/api/users/create', { data })
                    welcomeEmailFailed = result.user?.welcome_email_sent === false
                    this.generatedPassword = data.password
                    this.editorVisible = false
                    this.editingUser = null
                    this.notice = 'Compte créé.'
                }
                await this.loadPage()
                if (welcomeEmailFailed) this.error = 'Compte créé, mais l’e-mail de bienvenue n’a pas pu être envoyé.'
            } catch (error) {
                this.editorError = error?.message ?? 'Impossible d’enregistrer ce compte.'
            } finally {
                this.saving = false
            }
        },
        async openAssignments(kind, user) {
            if (this.saving || !['groups', 'roles'].includes(kind)) return
            const capability = kind === 'groups' ? 'can_assign_groups' : 'can_assign'
            if (!user?.[capability]) return
            this.saving = true
            this.assignmentError = ''
            try {
                // Recharger aussi le catalogue : un groupe ou rôle peut avoir été créé depuis l'ouverture de la page.
                this.directory = await this.api.get('/administrator/api/users/directory')
                const current = (await this.api.get('/administrator/api/users/read', { userId: user.id })).user
                if (!current[capability]) {
                    this.error = kind === 'groups'
                        ? 'Vous devez pouvoir attribuer des groupes sur l’espace principal de ce compte.'
                        : 'Vous devez pouvoir attribuer des rôles sur l’espace principal de ce compte.'
                    return
                }
                this.editingUser = current
                this.assignmentKind = kind
            } catch (error) {
                this.error = error?.message ?? 'Impossible de charger les attributions.'
            } finally {
                this.saving = false
            }
        },
        closeAssignments() {
            if (this.saving) return
            this.assignmentKind = null
            this.assignmentError = ''
            this.editingUser = null
        },
        async saveAssignments(data) {
            if (this.saving || !this.assignmentKind) return
            this.saving = true
            this.assignmentError = ''
            try {
                // Une seule requête enregistre tout le brouillon de cette petite modale.
                const result = await this.api.post(`/administrator/api/users/${this.assignmentKind}/save`,
                    { userId: this.editingUser.id, data })
                this.editingUser = result.user
                this.assignmentKind = null
                await this.loadPage()
                this.editingUser = null
                this.notice = 'Attributions enregistrées.'
            } catch (error) {
                this.assignmentError = error?.message ?? 'Impossible d’enregistrer ces attributions.'
            } finally {
                this.saving = false
            }
        },
        async openSecurity(user) {
            if (this.saving || !user?.can_admin) return
            this.saving = true
            this.error = ''
            this.securityError = ''
            this.securityNotice = ''
            try {
                // Recharger le compte et ses droits avant d'afficher ses connexions sensibles.
                const current = (await this.api.get('/administrator/api/users/read', { userId: user.id })).user
                if (!current.can_admin) {
                    this.error = 'Le droit USERS_ADMIN sur l’espace principal du compte est nécessaire.'
                    return
                }
                this.securityUser = current
            } catch (error) {
                this.error = error?.message ?? 'Impossible de charger les informations de connexion.'
            } finally {
                this.saving = false
            }
        },
        closeSecurity() {
            if (this.saving) return
            this.securityUser = null
            this.securityError = ''
            this.securityNotice = ''
        },
        async savePassword(password) {
            if (this.saving || !this.securityUser?.can_admin || password.length < 12) return
            this.saving = true
            this.securityError = ''
            this.securityNotice = ''
            try {
                await this.api.post('/administrator/api/users/password',
                    { userId: this.securityUser.id, data: { password } })
                this.securityUser = null
                this.notice = 'Mot de passe modifié.'
            } catch (error) {
                this.securityError = error?.message ?? 'Impossible de modifier le mot de passe.'
            } finally {
                this.saving = false
            }
        },
        async unlinkSso(link) {
            if (this.saving || !this.securityUser?.can_admin) return
            const userId = this.securityUser.id
            if (!await this.askYesNoAsync(`Supprimer la connexion SSO « ${link.name} » ?`)) return
            // La confirmation ne doit pas s'appliquer à un autre compte ouvert entre-temps.
            if (this.saving || this.securityUser?.id !== userId || !this.securityUser.can_admin) return
            this.saving = true
            this.securityError = ''
            this.securityNotice = ''
            try {
                this.securityUser = (await this.api.post('/administrator/api/users/sso/unlink',
                    { userId, linkId: link.id })).user
                this.securityNotice = 'Connexion SSO supprimée.'
            } catch (error) {
                this.securityError = error?.message ?? 'Impossible de supprimer cette connexion SSO.'
            } finally {
                this.saving = false
            }
        },
        async deleteUser() {
            if (!this.editingUser?.can_delete || this.saving) return
            if (!await this.askYesNoAsync(`Supprimer définitivement le compte « ${this.editingUser.email} » ?`)) return
            this.saving = true
            this.editorError = ''
            try {
                await this.api.post('/administrator/api/users/delete', { userId: this.editingUser.id })
                this.editorVisible = false
                this.editingUser = null
                await this.loadPage()
                this.notice = 'Compte supprimé.'
            } catch (error) {
                this.editorError = error?.message ?? 'Impossible de supprimer ce compte.'
            } finally {
                this.saving = false
            }
        },
    },
}
</script>
