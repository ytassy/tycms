<template>
    <div v-if="missingRequirements.length" class="alert alert-warning" role="alert">
        <p class="mb-2">Ce rôle est incomplet. Prérequis manquants : {{ permissionLabels(missingRequirements) }}.</p>
        <button v-if="editable" type="button" class="btn btn-outline-primary btn-sm"
            :disabled="repairPlan.unavailable.length > 0" @click="completeRequirements">
            Ajouter les prérequis au brouillon
        </button>
        <p v-if="editable && repairPlan.unavailable.length" class="small mb-0 mt-2">
            Vous ne pouvez pas attribuer : {{ permissionLabels(repairPlan.unavailable) }}.
        </p>
    </div>
    <tabs>
        <tab v-for="tab in groupedTabs" :key="tab.name" :label="tab.name" icon="">
            <section v-for="group in tab.groups" :key="group.name" class="mb-4">
                <h4 class="h6 mb-2">{{ group.name }}</h4>

                <div class="role-permissions">
                    <label v-for="permission in group.permissions" :key="permission.name"
                        class="role-permission border rounded" :class="{ 'role-permission--readonly': !canChange(permission) }">
                        <input type="checkbox" class="role-permission__checkbox" :checked="modelValue.includes(permission.name)"
                            :disabled="!canChange(permission)" @change="toggle(permission, $event.target.checked)" />
                        <span class="role-permission__text">
                            <strong>{{ permission.label }}</strong>
                            <span v-if="permission.description" class="d-block text-body-secondary">{{ permission.description }}</span>
                            <span v-if="permission.required_permissions?.length" class="d-block text-body-secondary small">
                                Nécessite : {{ permissionLabels(permission.direct_required_permissions ?? permission.required_permissions) }}.
                            </span>
                            <span v-if="modelValue.includes(permission.name) && dependents(permission).length" class="d-block text-body-secondary small">
                                Requise par : {{ dependents(permission).map(item => item.label).join(', ') }}.
                            </span>
                            <span v-else-if="canToggle(permission) && !modelValue.includes(permission.name) && selectionPlan([permission.name]).unavailable.length"
                                class="d-block text-body-secondary small">
                                Prérequis non attribuables : {{ permissionLabels(selectionPlan([permission.name]).unavailable) }}.
                            </span>
                        </span>
                    </label>
                </div>
            </section>
        </tab>
    </tabs>
</template>

<script>
import { missingPermissionRequirements, permissionDependents, planPermissionSelection } from '../../../application/permissionRequirements.js'

export default {
    props: {
        modelValue: { type: Array, required: true },
        permissions: { type: Array, required: true },
        spaceId: { type: Number, default: null },
        roleId: { type: Number, default: null },
        editable: { type: Boolean, default: false },
    },
    emits: ['update:modelValue'],

    computed: {
        missingRequirements() { return missingPermissionRequirements(this.modelValue, this.permissions) },
        repairPlan() { return this.selectionPlan(this.missingRequirements) },
        groupedTabs() {
            const tabs = new Map()

            // Conserver l'ordre des permissions de la définition PHP dans chaque groupe.
            for (const permission of this.permissions) {
                const tabName = permission.tab || 'Extensions'
                const groupName = permission.group || 'Autres permissions'

                if (!tabs.has(tabName)) tabs.set(tabName, { name: tabName, groups: new Map() })
                const tab = tabs.get(tabName)
                if (!tab.groups.has(groupName)) {
                    tab.groups.set(groupName, { name: groupName, permissions: [] })
                }
                tab.groups.get(groupName).permissions.push(permission)
            }

            return [...tabs.values()]
                .map(tab => ({ name: tab.name, groups: [...tab.groups.values()] }))
                .sort((left, right) => left.name.localeCompare(right.name, 'fr'))
        },
    },

    methods: {
        permissionLabels(names) {
            return names.map(name => this.permissions.find(permission => permission.name === name)?.label ?? name).join(', ')
        },
        dependents(permission) { return permissionDependents(this.modelValue, permission.name, this.permissions) },
        selectionPlan(names) {
            return planPermissionSelection(this.modelValue, names, this.permissions, permission => this.canToggle(permission))
        },
        canChange(permission) {
            if (!this.canToggle(permission)) return false
            return this.modelValue.includes(permission.name)
                ? this.dependents(permission).length === 0
                : this.selectionPlan([permission.name]).unavailable.length === 0
        },
        canToggle(permission) {
            if (!this.editable) return false
            if (this.roleId === 5 && permission.name === 'plg_core__MEMBER') return false
            if (this.roleId === 2 && permission.name === 'plg_core__ROOT') return false

            return permission.manageable_space_ids.includes(this.spaceId)
        },

        toggle(permission, checked) {
            if (!this.canChange(permission)) return

            // Les ajouts sont explicites dans le brouillon, puis soumis à la validation du serveur.
            const selected = checked ? this.selectionPlan([permission.name]).permissions
                : this.modelValue.filter(name => name !== permission.name)
            this.$emit('update:modelValue', selected)
        },
        completeRequirements() {
            if (!this.editable || this.repairPlan.unavailable.length) return
            this.$emit('update:modelValue', this.repairPlan.permissions)
        },
    },
}
</script>

<style scoped>
.role-permissions {
    display: grid;
    gap: 0.5rem;
    min-width: 0;
}

.role-permission {
    display: flex;
    align-items: center;
    gap: 0.9rem;
    min-width: 0;
    padding: 0.7rem 0.85rem;
    cursor: pointer;
}

.role-permission--readonly {
    opacity: 0.55;
    cursor: default;
}

.role-permission__checkbox {
    flex: 0 0 auto;
    width: 1.6rem;
    height: 1.6rem;
    margin: 0;
    accent-color: #176b72;
    cursor: inherit;
}

.role-permission__text {
    min-width: 0;
    overflow-wrap: anywhere;
}
</style>
