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
                <data-table
                    ref="table"
                    :fields="fields"
                    :items="runs"
                    :opened-rows="openedRuns"
                    :clickable-row="true"
                    :on-click-open-row="true"
                    :row-hover="true"
                    :counter-enabled="false"
                    table-class="table-sm table-hover align-middle"
                    @update:row-opened="handleRowsOpened"
                >
                    <template #cell_status="{ item }">
                        <span :class="['badge', statusClass(item.status)]">{{ statusLabel(item.status) }}</span>
                    </template>
                    <template #cell_trigger_id="{ item }">
                        <code class="small">{{ item.trigger_id || '—' }}</code>
                    </template>
                    <template #cell_started_at="{ item }">
                        <span class="small text-nowrap">{{ formatDate(item.started_at || item.created) }}</span>
                    </template>
                    <template #cell_duration_ms="{ item }">
                        <span class="small text-nowrap">
                            {{ item.duration_ms === null ? '—' : `${item.duration_ms} ms` }}
                        </span>
                    </template>
                    <template #cell_steps_count="{ item }">{{ item.steps_count }}</template>
                    <template #cell_actions="{ item }">
                        <button v-if="canRerun(item)" type="button" class="btn btn-outline-primary btn-sm me-1"
                            :disabled="rerunningId === item.id" @click.stop="rerun(item)">Relancer</button>
                        <button type="button" class="btn btn-outline-primary btn-sm"
                            :disabled="loadingDetailId === item.id" @click.stop="showRun(item)">Voir</button>
                    </template>

                    <template #nested_row="{ item }">
                        <div class="automation-run-detail">
                            <div v-if="loadingDetailId === item.id" class="py-3 text-center"><spinner /></div>

                            <template v-else-if="selectedRun && selectedRun.id === item.id">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                    <h3 class="h6 mb-0">Run #{{ selectedRun.id }}</h3>
                                    <button type="button" class="btn-close" aria-label="Fermer le détail"
                                        @click="closeAll" />
                                </div>

                                <div class="row g-2 small mb-3">
                                    <div class="col-md-3"><span class="text-body-secondary">Statut :</span>
                                        <span :class="['badge', statusClass(selectedRun.status)]">
                                            {{ statusLabel(selectedRun.status) }}
                                        </span>
                                    </div>
                                    <div class="col-md-3"><span class="text-body-secondary">Déclencheur :</span>
                                        <code>{{ selectedRun.trigger_id || '—' }}</code>
                                    </div>
                                    <div class="col-md-3"><span class="text-body-secondary">Durée :</span>
                                        {{ selectedRun.duration_ms === null ? '—' : `${selectedRun.duration_ms} ms` }}
                                    </div>
                                    <div class="col-md-3"><span class="text-body-secondary">Actions :</span>
                                        {{ selectedRun.action_calls }}
                                    </div>
                                </div>

                                <div v-if="selectedRun.error_code || selectedRun.error_message"
                                    class="alert alert-danger py-2 small">
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
                                                <td>
                                                    <span :class="['badge', statusClass(step.status)]">
                                                        {{ statusLabel(step.status) }}
                                                    </span>
                                                </td>
                                                <td class="small">
                                                    <span v-if="step.error_code">{{ step.error_code }}</span>
                                                    <span v-if="step.error_message"> — {{ step.error_message }}</span>
                                                    <span v-if="!step.error_code && !step.error_message"
                                                        class="text-body-secondary">—</span>
                                                </td>
                                                <td class="small">{{ step.span_start_line ?? '—' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div v-else class="text-body-secondary small">Aucune étape enregistrée.</div>
                            </template>
                        </div>
                    </template>
                </data-table>

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
            loadingDetailId: null,
            rerunningId: null,
            error: '',
            selectedRun: null,
            openedRuns: [],
            activeRunId: null,
            fields: [
                { key: 'id', label: '#', sortable: true },
                { key: 'status', label: 'Statut', sortable: true },
                { key: 'trigger_id', label: 'Déclencheur', sortable: true },
                { key: 'started_at', label: 'Début', sortable: true },
                { key: 'duration_ms', label: 'Durée' },
                { key: 'steps_count', label: 'Étapes' },
                { key: 'actions', label: 'Détails', head_class: 'text-end', class: 'text-end text-nowrap' },
            ],
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
            await this.load()
        },

        async load() {
            this.loading = true
            this.error = ''
            this.closeDetail()
            this.openedRuns = []

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

        showRun(run) {
            // La prop ouvre la ligne dans le DataTable (une seule à la fois).
            this.activeRunId = run.id
            this.openedRuns = [run]

            if (this.selectedRun?.id !== run.id && this.loadingDetailId !== run.id) {
                this.loadDetail(run)
            }
        },

        handleRowsOpened(items) {
            const run = items[0] ?? null
            this.activeRunId = run?.id ?? null

            if (!run) {
                this.closeDetail()
                return
            }

            if (this.loadingDetailId === run.id || this.selectedRun?.id === run.id) return

            this.loadDetail(run)
        },

        async loadDetail(run) {
            this.loadingDetailId = run.id
            this.error = ''

            try {
                const response = await this.api.get('/administrator/api/automation/run/detail', { runId: run.id })

                // Ignorer une réponse si la ligne ouverte a changé entre-temps.
                if (this.activeRunId !== run.id) return

                this.selectedRun = response.run
                this.steps = response.steps ?? []
            } catch (error) {
                if (this.activeRunId === run.id) {
                    this.error = error?.message ?? 'Impossible de charger ce run.'
                }
            } finally {
                if (this.loadingDetailId === run.id) this.loadingDetailId = null
            }
        },

        closeAll() {
            this.openedRuns = []
            this.$refs.table?.closeAll()
            this.closeDetail()
        },

        closeDetail() {
            this.selectedRun = null
            this.steps = []
            this.activeRunId = null
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
