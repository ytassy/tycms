<template>
    <div class="automation-page">
        <div class="mb-3">
            <h1>Automatisations</h1>
            <p class="text-body-secondary mb-0">
                Créez des scénarios exécutés par un déclencheur, vérifiez-les puis publiez la version active.
            </p>
            <div v-if="!editorVisible && !maintenanceVisible" class="d-flex flex-wrap gap-2 mt-2">
                <button v-if="canManage" type="button" class="btn btn-outline-secondary btn-sm"
                    :disabled="loading" @click="openMaintenance">
                    <i class="fa-solid fa-screwdriver-wrench me-1" aria-hidden="true" />Maintenance
                </button>
                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm"
                    :disabled="loading" @click="openCreate">
                    <i class="fa-solid fa-plus me-1" aria-hidden="true" />Nouveau scénario
                </button>
            </div>
        </div>

        <div v-if="error" class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2" role="alert">
            <span>{{ error }}</span>
            <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="loading" @click="load()">Réessayer</button>
        </div>
        <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>

        <div v-if="loading" class="py-5 text-center"><spinner /></div>

        <template v-else-if="directory">
            <MaintenancePanel v-if="maintenanceVisible" :spaces="directory.spaces" @close="closeMaintenance" />
            <ScenarioEditor v-else-if="editorVisible" :key="editorKey" :scenario-id="editorScenarioId"
                :directory="directory" @close="closeEditor" @saved="refreshDirectory" />
            <ScenarioList v-else :scenarios="directory.scenarios" :triggers="directory.triggers"
                :can-create="canCreate" @open="openScenario" @create="openCreate" />
        </template>
    </div>
</template>

<script>
import MaintenancePanel from './MaintenancePanel.vue'
import ScenarioEditor from './ScenarioEditor.vue'
import ScenarioList from './ScenarioList.vue'

export default {
    components: { MaintenancePanel, ScenarioEditor, ScenarioList },

    data() {
        return {
            directory: null,
            loading: false,
            error: '',
            notice: '',
            editorVisible: false,
            editorScenarioId: null,
            editorKey: 0,
            maintenanceVisible: false,
        }
    },

    computed: {
        canCreate() {
            return (this.directory?.spaces ?? []).some(space => space.can_create)
        },
        canManage() {
            return (this.directory?.spaces ?? []).some(space => space.can_manage_runs)
        },
    },

    created() {
        this.load()
    },

    methods: {
        async load() {
            this.loading = true
            this.error = ''

            try {
                this.directory = await this.api.get('/administrator/api/automation/directory')
            } catch (error) {
                this.error = error?.message ?? 'Impossible de charger les automatisations.'
            } finally {
                this.loading = false
            }
        },

        async refreshDirectory() {
            try {
                this.directory = await this.api.get('/administrator/api/automation/directory')
            } catch {
                // Le panneau ouvert conserve ses propres erreurs ; un rafraîchissement
                // de fond ne doit pas remplacer l'édition en cours.
            }
        },

        openCreate() {
            if (this.loading || !this.canCreate) return

            this.notice = ''
            this.maintenanceVisible = false
            this.editorScenarioId = null
            this.editorVisible = true
            this.editorKey += 1
        },

        openScenario(scenario) {
            if (this.loading) return

            this.notice = ''
            this.maintenanceVisible = false
            this.editorScenarioId = scenario.id
            this.editorVisible = true
            this.editorKey += 1
        },

        openMaintenance() {
            if (this.loading || !this.canManage) return

            this.notice = ''
            this.editorVisible = false
            this.maintenanceVisible = true
        },

        closeMaintenance() {
            this.maintenanceVisible = false
            this.refreshDirectory()
        },

        closeEditor() {
            this.editorVisible = false
            this.editorScenarioId = null
            this.editorKey += 1
            this.refreshDirectory()
        },
    },
}
</script>

<style scoped>
.automation-page {
    width: 100%;
    min-width: 0;
}
</style>
