<template>
    <div class="automation-list">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <div class="input-group input-group-sm automation-search">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass" aria-hidden="true" /></span>
                <input v-model.trim="search" type="search" class="form-control" placeholder="Rechercher un scénario…"
                    aria-label="Rechercher un scénario" />
            </div>
            <select v-if="spaceOptions.length > 1" v-model="spaceFilter" class="form-select form-select-sm automation-space-filter"
                aria-label="Filtrer par espace">
                <option value="">Tous les espaces</option>
                <option v-for="space in spaceOptions" :key="space.id" :value="String(space.id)">{{ space.title }}</option>
            </select>
            <span class="text-body-secondary small">{{ filtered.length }} scénario(s)</span>
        </div>

        <div v-if="!scenarios.length" class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span>Aucun scénario pour le moment.</span>
            <button v-if="canCreate" type="button" class="btn btn-primary btn-sm" @click="$emit('create')">
                Créer le premier scénario
            </button>
        </div>

        <div v-else-if="!filtered.length" class="alert alert-light border">Aucun scénario ne correspond au filtre.</div>

        <div v-else class="table-responsive">
            <table class="table table-hover align-middle mb-0 w-auto">
                <thead>
                    <tr>
                        <th scope="col">Scénario</th>
                        <th scope="col">Espace</th>
                        <th scope="col">Déclencheur</th>
                        <th scope="col">Version</th>
                        <th scope="col">Dernier run</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="scenario in filtered" :key="scenario.id" class="automation-row" tabindex="0"
                        @click="$emit('open', scenario)" @keydown.enter.self.prevent="$emit('open', scenario)"
                        @keydown.space.self.prevent="$emit('open', scenario)">
                        <td>
                            <div class="fw-semibold">{{ scenario.name }}</div>
                            <code class="small text-body-secondary">{{ scenario.key }}</code>
                        </td>
                        <td>{{ scenario.space_title }}</td>
                        <td>
                            <div>{{ triggerLabel(scenario.trigger_id) }}</div>
                            <code class="small text-body-secondary">{{ scenario.trigger_id || '—' }}</code>
                        </td>
                        <td>
                            <span v-if="scenario.published_version" class="badge text-bg-success">
                                Publié v{{ scenario.published_version }}
                            </span>
                            <span v-else class="badge text-bg-secondary">Brouillon</span>
                            <div v-if="scenario.published_version && scenario.draft_version > scenario.published_version"
                                class="small text-warning-emphasis mt-1">
                                Modifications non publiées
                            </div>
                        </td>
                        <td>
                            <template v-if="scenario.last_run_status">
                                <span :class="['badge', statusClass(scenario.last_run_status)]">{{ statusLabel(scenario.last_run_status) }}</span>
                                <div class="small text-body-secondary mt-1">{{ formatDate(scenario.last_run_at) }}</div>
                            </template>
                            <span v-else class="text-body-secondary">—</span>
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-outline-primary btn-sm" @click.stop="$emit('open', scenario)">
                                <i class="fa-solid fa-pen-to-square me-1" aria-hidden="true" />Ouvrir
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script>
import { formatDate, statusClass, statusLabel } from './format.js'

export default {
    props: {
        scenarios: { type: Array, default: () => [] },
        triggers: { type: Array, default: () => [] },
        canCreate: { type: Boolean, default: false },
    },
    emits: ['open', 'create'],

    data() {
        return {
            search: '',
            spaceFilter: '',
        }
    },

    computed: {
        triggerLabels() {
            return Object.fromEntries(this.triggers.map(trigger => [trigger.id, trigger.label]))
        },
        spaceOptions() {
            const spaces = new Map()
            for (const scenario of this.scenarios) spaces.set(scenario.space_id, scenario.space_title)

            return [...spaces].map(([id, title]) => ({ id, title })).sort((a, b) => a.title.localeCompare(b.title))
        },
        filtered() {
            const needle = this.search.toLowerCase()

            return this.scenarios.filter(scenario => {
                if (this.spaceFilter !== '' && String(scenario.space_id) !== this.spaceFilter) return false
                if (needle === '') return true

                return [scenario.name, scenario.key, scenario.space_title, scenario.trigger_id,
                    this.triggerLabel(scenario.trigger_id)]
                    .filter(Boolean)
                    .some(value => String(value).toLowerCase().includes(needle))
            })
        },
    },

    methods: {
        triggerLabel(triggerId) {
            return this.triggerLabels[triggerId] ?? (triggerId || '—')
        },
        statusLabel,
        statusClass,
        formatDate,
    },
}
</script>

<style scoped>
.automation-list {
    width: 100%;
    min-width: 0;
}

.automation-search {
    max-width: 360px;
}

.automation-space-filter {
    max-width: 220px;
}

.automation-row {
    cursor: pointer;
}

.automation-row:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: -2px;
}
</style>
