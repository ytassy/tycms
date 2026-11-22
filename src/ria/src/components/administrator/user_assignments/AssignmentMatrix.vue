<template>
    <div class="assignment-scroll" role="region" aria-label="Tableau des attributions utilisateurs" tabindex="0"
        @mouseleave="clearHover">
        <table class="assignment-matrix">
            <caption class="visually-hidden">Attributions directes de rôles et de groupes dans {{ spaceName }}</caption>
            <thead>
                <tr class="matrix-band-row">
                    <th class="matrix-user matrix-corner" :rowspan="columns.length ? 2 : 1" scope="col" @mouseenter="clearHover">Utilisateur</th>
                    <th v-if="roles.length" class="matrix-band matrix-roles-band" :colspan="roles.length" scope="colgroup"
                        @mouseenter="clearHover"><i class="fa-solid fa-user-shield me-2" aria-hidden="true" />Rôles</th>
                    <th v-if="groups.length" class="matrix-band matrix-groups-band" :colspan="groups.length" scope="colgroup"
                        @mouseenter="clearHover"><i class="fa-solid fa-user-group me-2" aria-hidden="true" />Groupes</th>
                    <th v-if="!columns.length" class="matrix-band" scope="col" @mouseenter="clearHover">Attributions</th>
                </tr>
                <tr v-if="columns.length">
                    <th v-for="column in columns" :key="column.key" scope="col" class="matrix-column-head"
                        :class="{ 'matrix-column-hover': hoveredColumn === column.key, 'matrix-group-start': column.groupStart }"
                        @mouseenter="hoveredUser = null; hoveredColumn = column.key">{{ column.name }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="user in users" :key="user.id">
                    <th scope="row" class="matrix-user" :class="{ 'matrix-row-hover': hoveredUser === user.id }"
                        @mouseenter="hoveredUser = user.id; hoveredColumn = null">
                        <div class="fw-semibold">{{ user.first_name }} {{ user.last_name }}</div>
                        <div class="small text-body-secondary matrix-email">{{ user.email }}</div>
                    </th>
                    <td v-for="column in columns" :key="column.key" class="matrix-cell"
                        :class="{ 'matrix-row-hover': hoveredUser === user.id, 'matrix-column-hover': hoveredColumn === column.key,
                            'matrix-intersection': hoveredUser === user.id && hoveredColumn === column.key,
                            'matrix-group-start': column.groupStart }"
                        :title="cellTitle(user, column)" @mouseenter="hoveredUser = user.id; hoveredColumn = column.key">
                        <button type="button" tabindex="-1" class="matrix-toggle"
                            :class="{ 'matrix-checked': isAssigned(user, column) }"
                            :disabled="blocked || !!assignmentBlockReason(user, column)"
                            :aria-pressed="isAssigned(user, column)" :aria-label="cellTitle(user, column)"
                            @keydown.enter.prevent @keydown.space.prevent @click="toggle($event, user, column)">
                            <i :class="isSaving(user, column) ? 'fa-solid fa-spinner fa-spin'
                                : isAssigned(user, column) ? 'fa-solid fa-square-check' : 'fa-regular fa-square'" aria-hidden="true" />
                        </button>
                    </td>
                    <td v-if="!columns.length" class="text-body-secondary px-3" @mouseenter="hoveredUser = user.id; hoveredColumn = null">—</td>
                </tr>
                <tr v-if="!users.length"><td :colspan="Math.max(2, columns.length + 1)" class="text-center text-body-secondary p-4">
                    Aucun utilisateur trouvé.
                </td></tr>
            </tbody>
        </table>
    </div>
</template>

<script>
import { assignmentBlockReason, isAssignmentMouseClick } from './assignmentInteraction.js'

export default {
    props: {
        users: { type: Array, required: true },
        roles: { type: Array, required: true },
        groups: { type: Array, required: true },
        spaceName: { type: String, required: true },
        blocked: { type: Boolean, default: false },
        saving: { type: Object, default: null },
    },
    emits: ['toggle'],
    data: () => ({ hoveredUser: null, hoveredColumn: null }),
    computed: {
        columns() {
            return [
                ...this.roles.map(role => ({ ...role, kind: 'role', key: `role-${role.id}` })),
                ...this.groups.map((group, index) => ({ ...group, kind: 'group', key: `group-${group.id}`,
                    groupStart: index === 0 && this.roles.length > 0 })),
            ]
        },
    },
    methods: {
        assignmentBlockReason,
        isAssigned(user, column) { return user[column.kind === 'role' ? 'role_ids' : 'group_ids'].includes(column.id) },
        isSaving(user, column) {
            return this.saving?.userId === user.id && this.saving.kind === column.kind && this.saving.itemId === column.id
        },
        cellTitle(user, column) {
            const reason = assignmentBlockReason(user, column)
            return reason || `${this.isAssigned(user, column) ? 'Retirer' : 'Attribuer'} ${column.name} · ${user.first_name} ${user.last_name}`
        },
        toggle(event, user, column) {
            // Ne pas accepter le clic synthétique émis par le clavier ou un raccourci global.
            if (!isAssignmentMouseClick(event) || this.blocked || assignmentBlockReason(user, column)) return
            this.$emit('toggle', user.id, column.kind, column.id)
        },
        clearHover() { this.hoveredUser = null; this.hoveredColumn = null },
    },
}
</script>

<style scoped>
.assignment-scroll {
    max-height: 68vh;
    overflow: auto;
    border: 1px solid var(--bs-border-color);
    border-radius: .375rem;
    isolation: isolate;
}
.assignment-matrix { width: 100%; border-collapse: separate; border-spacing: 0; }
.assignment-matrix th, .assignment-matrix td {
    border-right: 1px solid var(--bs-border-color);
    border-bottom: 1px solid var(--bs-border-color);
    background: var(--bs-body-bg, white);
}
.assignment-matrix tr > :last-child { border-right: 0; }
.assignment-matrix tbody tr:last-child > * { border-bottom: 0; }

/* Les fonds opaques et les niveaux distincts conservent la lecture au croisement des zones fixes. */
.matrix-user { position: sticky; left: 0; z-index: 1; min-width: 240px; width: 280px; max-width: 320px; padding: .65rem 1rem; }
.matrix-email { overflow-wrap: anywhere; font-weight: normal; }
.matrix-corner { top: 0; z-index: 4; vertical-align: middle; }
.matrix-band { position: sticky; top: 0; z-index: 2; height: 40px; padding: 8px 12px; font-size: .875rem; line-height: 20px; white-space: nowrap; }
.assignment-matrix .matrix-roles-band { background: var(--bs-primary-bg-subtle, #cfe2ff); }
.assignment-matrix .matrix-groups-band { background: var(--bs-info-bg-subtle, #cff4fc); }
.matrix-column-head { position: sticky; top: 40px; z-index: 2; min-width: 110px; max-width: 180px; padding: .75rem; text-align: center; overflow-wrap: anywhere; }
.matrix-cell { position: relative; padding: 0; text-align: center; height: 58px; }
.assignment-matrix .matrix-group-start { border-left: 2px solid var(--bs-secondary-color); }
.matrix-toggle { position: absolute; inset: 0; display: block; width: 100%; height: 100%; border: 0; padding: .6rem; background: transparent; color: var(--bs-secondary-color); font-size: 1.8rem; line-height: 1; cursor: pointer; }
.matrix-toggle.matrix-checked { color: var(--bs-primary); }
.matrix-toggle:disabled { cursor: not-allowed; opacity: .4; }

/* Le survol porte sur les cellules complètes, y compris les deux axes fixes. */
.assignment-matrix .matrix-row-hover, .assignment-matrix .matrix-column-hover { background: var(--bs-tertiary-bg, #f0f3f6); }
.assignment-matrix .matrix-intersection { background: var(--bs-primary-bg-subtle, #cfe2ff); box-shadow: inset 0 0 0 2px var(--bs-primary); }
</style>
