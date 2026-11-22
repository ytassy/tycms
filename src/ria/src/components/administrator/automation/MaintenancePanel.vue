<template>
    <section class="automation-maintenance">
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <button type="button" class="btn btn-outline-secondary btn-sm esc" :disabled="busy" @click="$emit('close')">
                <i class="fa-solid fa-arrow-left me-1" aria-hidden="true" />Retour
            </button>
            <div class="flex-grow-1">
                <h2 class="h4 mb-0">Maintenance</h2>
                <p class="text-body-secondary mb-0 small">
                    Reprise manuelle des événements bloqués, arrêt des runs interrompus et nettoyage des historiques.
                </p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <select v-if="manageableSpaces.length > 1" v-model.number="spaceId" class="form-select form-select-sm"
                    aria-label="Espace de maintenance" :disabled="busy || loading" @change="load">
                    <option v-for="space in manageableSpaces" :key="space.id" :value="space.id">{{ space.title }}</option>
                </select>
                <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="busy || loading" @click="load">
                    <i class="fa-solid fa-arrows-rotate me-1" aria-hidden="true" />Rafraîchir
                </button>
            </div>
        </div>

        <div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
        <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>

        <div v-if="!manageableSpaces.length" class="alert alert-warning mb-0">
            Vous devez disposer de la permission « Gérer les exécutions » sur au moins un espace.
        </div>

        <div v-else-if="loading" class="py-5 text-center"><spinner /></div>

        <template v-else-if="detail">
            <div class="alert alert-info py-2 small">
                Le cron ne reprend pas automatiquement ces lignes. La reprise exécute l’événement immédiatement ;
                les actions sont réservées à la permission « Gérer les exécutions ».
            </div>

            <div class="row g-3">
                <div class="col-lg-6">
                    <section class="card h-100">
                        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <h3 class="h5 mb-0">Événements bloqués</h3>
                            <span class="badge text-bg-secondary">{{ stuckEvents.length }}</span>
                        </div>
                        <div class="card-body">
                            <p class="small text-body-secondary">
                                En attente depuis {{ detail.thresholds.pending_event_minutes }} min, ou en cours depuis
                                {{ detail.thresholds.processing_event_minutes }} min.
                            </p>

                            <div v-if="!stuckEvents.length" class="text-body-secondary small">
                                Aucun événement bloqué.
                            </div>

                            <div v-else class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col">#</th>
                                            <th scope="col">Déclencheur</th>
                                            <th scope="col">Statut</th>
                                            <th scope="col">Date</th>
                                            <th scope="col">Essais</th>
                                            <th scope="col" class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="event in stuckEvents" :key="event.id">
                                            <td>{{ event.id }}</td>
                                            <td>
                                                <code class="small">{{ event.trigger_id }}</code>
                                                <div v-if="event.target_scenario_key" class="small text-body-secondary">
                                                    {{ event.target_scenario_key }}
                                                </div>
                                            </td>
                                            <td><span :class="['badge', statusClass(event.status)]">{{ statusLabel(event.status) }}</span></td>
                                            <td class="small text-nowrap">{{ formatDate(event.occurred_at) }}</td>
                                            <td class="small">{{ event.attempts }} / {{ detail.thresholds.max_event_attempts }}</td>
                                            <td class="text-end text-nowrap">
                                                <button type="button" class="btn btn-outline-primary btn-sm"
                                                    :disabled="busy || !detail.can_manage || event.attempts >= detail.thresholds.max_event_attempts"
                                                    :title="event.attempts >= detail.thresholds.max_event_attempts
                                                        ? 'Nombre maximal de tentatives atteint ; marquez l’événement en échec.'
                                                        : 'Exécuter cet événement maintenant.'"
                                                    @click="recoverEvent(event)">
                                                    Reprendre
                                                </button>
                                                <button type="button" class="btn btn-outline-danger btn-sm ms-1"
                                                    :disabled="busy || !detail.can_manage" @click="failEvent(event)">
                                                    En échec
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="col-lg-6">
                    <section class="card h-100">
                        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <h3 class="h5 mb-0">Runs bloqués</h3>
                            <span class="badge text-bg-secondary">{{ stuckRuns.length }}</span>
                        </div>
                        <div class="card-body">
                            <p class="small text-body-secondary">
                                Runs en attente ou en cours depuis plus de {{ detail.thresholds.stuck_run_minutes }} min.
                            </p>

                            <div v-if="!stuckRuns.length" class="text-body-secondary small">
                                Aucun run bloqué.
                            </div>

                            <div v-else class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col">#</th>
                                            <th scope="col">Scénario</th>
                                            <th scope="col">Statut</th>
                                            <th scope="col">Début</th>
                                            <th scope="col" class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="run in stuckRuns" :key="run.id">
                                            <td>{{ run.id }}</td>
                                            <td>
                                                <div>{{ run.scenario_name }}</div>
                                                <code class="small text-body-secondary">{{ run.scenario_key }}</code>
                                            </td>
                                            <td><span :class="['badge', statusClass(run.status)]">{{ statusLabel(run.status) }}</span></td>
                                            <td class="small text-nowrap">{{ formatDate(run.started_at || run.queued_at) }}</td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-outline-danger btn-sm"
                                                    :disabled="busy || !detail.can_manage" @click="failRun(run)">
                                                    Marquer en échec
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <section class="card mt-3">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <h3 class="h5 mb-0">Runs en échec récents</h3>
                    <span class="badge text-bg-secondary">{{ failedRuns.length }}</span>
                </div>
                <div class="card-body">
                    <p class="small text-body-secondary">
                        Relancer réexécute la <strong>version publiée actuelle</strong> avec l’entrée du run d’origine.
                        Le brouillon n’est jamais exécuté.
                    </p>

                    <div v-if="!failedRuns.length" class="text-body-secondary small">
                        Aucun run en échec.
                    </div>

                    <div v-else class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Scénario</th>
                                    <th scope="col">Déclencheur</th>
                                    <th scope="col">Statut</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Erreur</th>
                                    <th scope="col" class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="run in failedRuns" :key="run.id">
                                    <td>{{ run.id }}</td>
                                    <td>
                                        <div>{{ run.scenario_name }}</div>
                                        <code class="small text-body-secondary">{{ run.scenario_key }}</code>
                                    </td>
                                    <td><code class="small">{{ run.trigger_id || '—' }}</code></td>
                                    <td><span :class="['badge', statusClass(run.status)]">{{ statusLabel(run.status) }}</span></td>
                                    <td class="small text-nowrap">{{ formatDate(run.finished_at || run.created) }}</td>
                                    <td class="small">
                                        <span v-if="run.error_code">{{ run.error_code }}</span>
                                        <span v-if="run.error_message"> — {{ run.error_message }}</span>
                                        <span v-if="!run.error_code && !run.error_message" class="text-body-secondary">—</span>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-outline-primary btn-sm"
                                            :disabled="busy || !detail.can_manage" @click="rerunRun(run)">
                                            Relancer
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="card mt-3">
                <div class="card-header">
                    <h3 class="h5 mb-0">Rétention et nettoyage</h3>
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <div class="small text-body-secondary">Événements terminés</div>
                            <div class="fw-semibold">{{ detail.retention.event_purgeable }} supprimables</div>
                            <div class="small">Conservation : {{ detail.retention.event_days }} jours</div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-body-secondary">Runs et étapes terminés</div>
                            <div class="fw-semibold">{{ detail.retention.run_purgeable }} supprimables</div>
                            <div class="small">Conservation : {{ detail.retention.run_days }} jours</div>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <button type="button" class="btn btn-outline-danger"
                                :disabled="busy || !detail.can_manage" @click="cleanup">
                                <i class="fa-solid fa-broom me-1" aria-hidden="true" />Nettoyer maintenant
                            </button>
                        </div>
                    </div>
                    <p class="form-text mb-0 mt-2">
                        Seuls les événements et runs terminés sont supprimés. Les statuts actifs ne sont jamais purgés.
                        Les durées se règlent dans la configuration de l’extension.
                    </p>
                </div>
            </section>
        </template>
    </section>
</template>

<script>
import { formatDate, statusClass, statusLabel } from './format.js'

export default {
    props: {
        spaces: { type: Array, default: () => [] },
    },

    emits: ['close'],

    data() {
        return {
            spaceId: 0,
            detail: null,
            loading: false,
            busy: false,
            error: '',
            notice: '',
        }
    },

    computed: {
        manageableSpaces() {
            return this.spaces.filter(space => space.can_manage_runs)
        },
        stuckEvents() {
            return Array.isArray(this.detail?.stuck_events) ? this.detail.stuck_events : []
        },
        stuckRuns() {
            return Array.isArray(this.detail?.stuck_runs) ? this.detail.stuck_runs : []
        },
        failedRuns() {
            return Array.isArray(this.detail?.failed_runs) ? this.detail.failed_runs : []
        },
    },

    created() {
        const first = this.manageableSpaces[0] ?? null
        if (first) {
            this.spaceId = first.id
            this.load()
        }
    },

    methods: {
        async load() {
            if (!this.spaceId) {
                this.detail = null
                return
            }

            this.loading = true
            this.error = ''

            try {
                this.detail = await this.api.get('/administrator/api/automation/maintenance', {
                    spaceId: this.spaceId,
                })
            } catch (error) {
                this.error = error?.message ?? 'Impossible de charger la maintenance.'
            } finally {
                this.loading = false
            }
        },

        async recoverEvent(event) {
            if (this.busy || !this.detail?.can_manage) return
            if (!await this.askYesNoAsync(`Reprendre l’événement #${event.id} ? Il sera exécuté immédiatement.`)) return

            await this.action(
                '/administrator/api/automation/maintenance/recover-event',
                { eventId: event.id },
                response => `Événement #${event.id} repris : statut ${statusLabel(response.status)}, ${response.runs} run(s).`,
            )
        },

        async failEvent(event) {
            if (this.busy || !this.detail?.can_manage) return
            if (!await this.askYesNoAsync(`Marquer l’événement #${event.id} en échec ?`)) return

            await this.action(
                '/administrator/api/automation/maintenance/fail-event',
                { eventId: event.id },
                () => `Événement #${event.id} marqué en échec.`,
            )
        },

        async failRun(run) {
            if (this.busy || !this.detail?.can_manage) return
            if (!await this.askYesNoAsync(
                `Marquer le run #${run.id} en échec ? Une notification d’échec peut être envoyée.`,
            )) return

            await this.action(
                '/administrator/api/automation/maintenance/fail-run',
                { runId: run.id },
                () => `Run #${run.id} marqué en échec.`,
            )
        },

        async rerunRun(run) {
            if (this.busy || !this.detail?.can_manage) return
            if (!await this.askYesNoAsync(
                `Relancer le run #${run.id} avec la version publiée actuelle et la même entrée ?`,
            )) return

            await this.action(
                '/administrator/api/automation/rerun',
                { runId: run.id },
                response => `Run #${run.id} relancé : run #${response.run_id ?? '—'}, `
                    + `statut ${statusLabel(response.status)}.`,
            )
        },

        async cleanup() {
            if (this.busy || !this.detail?.can_manage) return

            const retention = this.detail.retention
            if (!await this.askYesNoAsync(
                `Supprimer les événements de plus de ${retention.event_days} jours et les runs de plus de `
                + `${retention.run_days} jours ? Cette action est irréversible.`,
            )) return

            await this.action(
                '/administrator/api/automation/maintenance/cleanup',
                { spaceId: this.spaceId },
                response => `Nettoyage terminé : ${response.events_deleted} événement(s) et `
                    + `${response.runs_deleted} run(s) supprimé(s).`,
            )
        },

        async action(url, payload, message) {
            this.busy = true
            this.error = ''
            this.notice = ''

            try {
                const response = await this.api.post(url, payload)
                this.notice = message(response)
                await this.load()
            } catch (error) {
                this.error = error?.message ?? 'L’action de maintenance a échoué.'
            } finally {
                this.busy = false
            }
        },

        formatDate,
        statusClass,
        statusLabel,
    },
}
</script>

<style scoped>
.automation-maintenance {
    width: 100%;
    min-width: 0;
}
</style>
