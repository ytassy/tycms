<template>
    <section class="card mt-3 automation-history">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h2 class="h5 mb-0">Historique des runs</h2>
            <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="loading" @click="reload">
                <i class="fa-solid fa-arrows-rotate me-1" aria-hidden="true" />Rafraîchir
            </button>
        </div>

        <div class="card-body">
            <div v-if="error" class="alert alert-warning mb-0" role="alert">{{ error }}</div>
            <div v-else-if="loading" class="py-4 text-center"><spinner /></div>
            <div v-else-if="!runs.length" class="text-body-secondary">Aucun run enregistré pour ce scénario.</div>

            <template v-else>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Statut</th>
                                <th scope="col">Déclencheur</th>
                                <th scope="col">Début</th>
                                <th scope="col">Durée</th>
                                <th scope="col">Étapes</th>
                                <th scope="col" class="text-end">Détails</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="run in runs" :key="run.id">
                                <td>{{ run.id }}</td>
                                <td><span :class="['badge', statusClass(run.status)]">{{ statusLabel(run.status) }}</span></td>
                                <td><code class="small">{{ run.trigger_id || '—' }}</code></td>
                                <td class="small text-nowrap">{{ formatDate(run.started_at || run.created) }}</td>
                                <td class="small text-nowrap">{{ run.duration_ms === null ? '—' : `${run.duration_ms} ms` }}</td>
                                <td class="small">{{ run.steps_count }}</td>
                                <td class="text-end text-nowrap">
                                    <button v-if="canRerun(run)" type="button" class="btn btn-outline-primary btn-sm me-1"
                                        :disabled="rerunningId === run.id" @click="rerun(run)">Relancer</button>
                                    <button type="button" class="btn btn-outline-primary btn-sm"
                                        :disabled="loadingDetail" @click="openRun(run)">Voir</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="total > perPage" class="d-flex align-items-center justify-content-between gap-2 mt-3">
                    <span class="small text-body-secondary">{{ total }} run(s) — page {{ page }} / {{ pageCount }}</span>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary" :disabled="page <= 1 || loading"
                            @click="previous">Précédent</button>
                        <button type="button" class="btn btn-outline-secondary" :disabled="page >= pageCount || loading"
                            @click="next">Suivant</button>
                    </div>
                </div>
            </template>

            <div v-if="selectedRun" class="automation-run-detail mt-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <h3 class="h6 mb-0">Run #{{ selectedRun.id }}</h3>
                    <button type="button" class="btn-close" aria-label="Fermer le détail" @click="closeDetail" />
                </div>

                <div class="row g-2 small mb-3">
                    <div class="col-md-3"><span class="text-body-secondary">Statut :</span>
                        <span :class="['badge', statusClass(selectedRun.status)]">{{ statusLabel(selectedRun.status) }}</span>
                    </div>
                    <div class="col-md-3"><span class="text-body-secondary">Déclencheur :</span>
                        <code>{{ selectedRun.trigger_id || '—' }}</code>
                    </div>
                    <div class="col-md-3"><span class="text-body-secondary">Durée :</span>
                        {{ selectedRun.duration_ms === null ? '—' : `${selectedRun.duration_ms} ms` }}
                    </div>
                    <div class="col-md-3"><span class="text-body-secondary">Actions :</span> {{ selectedRun.action_calls }}</div>
                </div>

                <div v-if="selectedRun.error_code || selectedRun.error_message" class="alert alert-danger py-2 small">
                    <strong>{{ selectedRun.error_code || 'Erreur' }}</strong>
                    <span v-if="selectedRun.error_message"> — {{ selectedRun.error_message }}</span>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-lg-6">
                        <label class="form-label small fw-semibold">Entrée</label>
                        <pre class="automation-json">{{ prettyJson(selectedRun.input) || '—' }}</pre>
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label small fw-semibold">Sortie</label>
                        <pre class="automation-json">{{ prettyJson(selectedRun.output) || '—' }}</pre>
                    </div>
                </div>

                <div v-if="steps.length" class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Type</th>
                                <th scope="col">Élément</th>
                                <th scope="col">Statut</th>
                                <th scope="col">Erreur</th>
                                <th scope="col">Ligne</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="step in steps" :key="step.sequence">
                                <td>{{ step.sequence }}</td>
                                <td><code class="small">{{ step.kind }}</code></td>
                                <td><code class="small">{{ step.identifier }}</code></td>
                                <td><span :class="['badge', statusClass(step.status)]">{{ statusLabel(step.status) }}</span></td>
                                <td class="small">
                                    <span v-if="step.error_code">{{ step.error_code }}</span>
                                    <span v-if="step.error_message"> — {{ step.error_message }}</span>
                                    <span v-if="!step.error_code && !step.error_message" class="text-body-secondary">—</span>
                                </td>
                                <td class="small">{{ step.span_start_line ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="text-body-secondary small">Aucune étape enregistrée.</div>
            </div>
        </div>
    </section>
</template>

<script>
import { formatDate, prettyJson, statusClass, statusLabel } from './format.js'

export default {
    props: {
        scenarioId: { type: Number, required: true },
        canManageRuns: { type: Boolean, default: false },
    },

    data() {
        return {
            runs: [],
            steps: [],
            total: 0,
            page: 1,
            perPage: 20,
            loading: false,
            loadingDetail: false,
            rerunningId: null,
            error: '',
            selectedRun: null,
        }
    },

    computed: {
        pageCount() {
            return Math.max(1, Math.ceil(this.total / this.perPage))
        },
    },

    watch: {
        scenarioId() {
            this.reload()
        },
    },

    mounted() {
        this.reload()
    },

    methods: {
        async reload() {
            this.page = 1
            this.selectedRun = null
            this.steps = []
            await this.load()
        },

        async load() {
            this.loading = true
            this.error = ''
            this.selectedRun = null
            this.steps = []

            try {
                const response = await this.api.get('/administrator/api/automation/runs', {
                    scenarioId: this.scenarioId,
                    page: this.page,
                    perPage: this.perPage,
                })
                this.runs = response.runs ?? []
                this.total = response.total ?? 0
                this.page = response.page ?? this.page
                this.perPage = response.per_page ?? this.perPage
            } catch (error) {
                this.error = error?.message ?? 'Impossible de charger l’historique.'
            } finally {
                this.loading = false
            }
        },

        canRerun(run) {
            return this.canManageRuns && ['failed', 'stopped'].includes(run.status)
        },

        async rerun(run) {
            if (this.rerunningId !== null || this.loading) return
            if (!await this.askYesNoAsync(
                `Relancer le run #${run.id} avec la version publiée actuelle et la même entrée ?`,
            )) return

            this.rerunningId = run.id
            this.error = ''

            try {
                await this.api.post('/administrator/api/automation/rerun', { runId: run.id })
                this.showModaleSuccess(`Run #${run.id} relancé.`)
                await this.reload()
            } catch (error) {
                this.error = error?.message ?? 'Impossible de relancer ce run.'
            } finally {
                this.rerunningId = null
            }
        },

        async openRun(run) {
            if (this.loadingDetail) return

            this.loadingDetail = true
            this.error = ''

            try {
                const response = await this.api.get('/administrator/api/automation/run/detail', { runId: run.id })
                this.selectedRun = response.run
                this.steps = response.steps ?? []
            } catch (error) {
                this.error = error?.message ?? 'Impossible de charger ce run.'
            } finally {
                this.loadingDetail = false
            }
        },

        closeDetail() {
            this.selectedRun = null
            this.steps = []
        },

        async previous() {
            if (this.page <= 1) return
            this.page -= 1
            await this.load()
        },

        async next() {
            if (this.page >= this.pageCount) return
            this.page += 1
            await this.load()
        },

        statusLabel,
        statusClass,
        formatDate,
        prettyJson,
    },
}
</script>

<style scoped>
.automation-history {
    width: 100%;
}

.automation-json {
    max-height: 240px;
    overflow: auto;
    background: var(--bs-tertiary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius);
    padding: 0.75rem;
    font-size: 0.8rem;
    white-space: pre-wrap;
    word-break: break-word;
    margin-bottom: 0;
}

.automation-run-detail {
    border-top: 1px solid var(--bs-border-color);
    padding-top: 1rem;
}
</style>
