<template>
    <div>
        <h1>Attributions utilisateurs</h1>
        <p class="text-body-secondary">Attribuez les rôles et les groupes d’un Space aux utilisateurs que vous pouvez consulter.</p>

        <div v-if="store.error" class="alert alert-warning" role="alert">{{ store.error }}</div>

        <div class="d-flex flex-wrap align-items-end gap-3 mb-3">
            <div>
                <label for="assignments-space" class="form-label small mb-1">Space des rôles et groupes</label>
                <select id="assignments-space" class="form-select form-select-sm" :value="store.spaceId"
                    :disabled="busy || !store.spaces.length" @change="setFilters({ spaceId: Number($event.target.value) })">
                    <option v-for="space in store.spaces" :key="space.id" :value="space.id">{{ space.title }}</option>
                </select>
            </div>
            <div>
                <label for="assignments-search" class="form-label small mb-1">Rechercher un utilisateur</label>
                <input id="assignments-search" v-model="search" type="search" maxlength="100" class="form-control form-control-sm"
                    placeholder="Nom ou e-mail" :disabled="busy" @input="scheduleSearch" />
            </div>
            <div>
                <label for="assignments-per-page" class="form-label small mb-1">Lignes par page</label>
                <select id="assignments-per-page" class="form-select form-select-sm" :value="store.perPage" :disabled="busy"
                    @change="setFilters({ perPage: Number($event.target.value) })">
                    <option v-for="size in [10, 20, 50, 100, 500]" :key="size" :value="size">{{ size }}</option>
                </select>
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="busy || store.loading" @click="refresh">
                <i class="fa-solid fa-arrows-rotate me-1" aria-hidden="true" />Actualiser
            </button>
        </div>

        <div class="d-flex flex-wrap gap-3 mb-3" role="group" aria-label="Colonnes affichées">
            <input_switch v-model="showRoles" :disabled="busy">Afficher les rôles</input_switch>
            <input_switch v-model="showGroups" :disabled="busy">Afficher les groupes</input_switch>
        </div>

        <p class="small text-body-secondary mb-2">
            Les cases indiquent les attributions directes. Les accès hérités ou obtenus par un groupe ne sont pas cochés.
            Chaque clic souris est enregistré immédiatement.
        </p>
        <div v-if="store.initializing || store.loading" class="text-center py-5" role="status"><spinner /><span class="visually-hidden">Chargement des attributions</span></div>
        <template v-else-if="store.selectedSpace">
            <div v-if="!store.roles.length && !store.groups.length" class="alert alert-info">
                Aucun rôle ou groupe attribuable n’est visible dans ce Space avec vos droits actuels.
            </div>
            <AssignmentMatrix :users="store.users" :roles="visibleRoles" :groups="visibleGroups"
                :space-name="store.selectedSpace.title" :saving="store.saving" :blocked="busy || searchTimer !== null"
                @toggle="store.toggle" />
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                <span class="small text-body-secondary">{{ store.total }} utilisateur{{ store.total > 1 ? 's' : '' }} · tous les espaces principaux lisibles</span>
                <nav v-if="store.pageCount > 1" class="btn-group btn-group-sm" aria-label="Pages des attributions utilisateurs">
                    <button type="button" class="btn btn-outline-secondary" :disabled="busy || store.page === 1" @click="goPage(1)">«</button>
                    <button type="button" class="btn btn-outline-secondary" :disabled="busy || store.page === 1" @click="goPage(store.page - 1)">Précédent</button>
                    <span class="btn btn-outline-secondary disabled">{{ store.page }} / {{ store.pageCount }}</span>
                    <button type="button" class="btn btn-outline-secondary" :disabled="busy || store.page === store.pageCount" @click="goPage(store.page + 1)">Suivant</button>
                    <button type="button" class="btn btn-outline-secondary" :disabled="busy || store.page === store.pageCount" @click="goPage(store.pageCount)">»</button>
                </nav>
            </div>
        </template>
    </div>
</template>

<script>
import AssignmentMatrix from './AssignmentMatrix.vue'

export default {
    components: { AssignmentMatrix },
    data: () => ({ search: '', searchTimer: null, showRoles: true, showGroups: true }),
    computed: {
        store() { return this.stores.useAdminUserAssignmentsStore() },
        busy() { return this.store.initializing || this.store.saving !== null },
        // Ces filtres locaux masquent les colonnes sans toucher aux attributions ni aux lignes.
        visibleRoles() { return this.showRoles ? this.store.roles : [] },
        visibleGroups() { return this.showGroups ? this.store.groups : [] },
    },
    mounted() {
        this.search = this.store.search
        this.store.initialize(this.conf.getSpace()?.id)
    },
    beforeUnmount() {
        this.clearSearchTimer()
        this.store.cancelReads()
    },
    methods: {
        clearSearchTimer() {
            if (this.searchTimer !== null) clearTimeout(this.searchTimer)
            this.searchTimer = null
        },
        scheduleSearch() {
            this.clearSearchTimer()
            this.searchTimer = setTimeout(() => this.setFilters(), 300)
        },
        setFilters(filters = {}) {
            this.clearSearchTimer()
            return this.store.setFilters({ ...filters, search: this.search })
        },
        goPage(page) {
            // Une recherche en attente doit repartir de la première page.
            if (this.searchTimer !== null) return this.setFilters()
            return this.store.goPage(page)
        },
        async refresh() {
            this.clearSearchTimer()
            this.store.search = this.search.trim()
            await this.store.initialize(this.store.spaceId)
        },
    },
}
</script>
