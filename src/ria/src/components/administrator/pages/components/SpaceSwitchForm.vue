<template>
    <div>
        <h3 class="page-panel-heading">Espaces proposés</h3>
        <p class="small text-muted mb-2">Les sources se cumulent sans doublons.</p>
        <label v-for="source in sources" :key="source.key" class="page-field d-flex gap-2 align-items-center">
            <input v-model="node.props[source.key]" type="checkbox" class="form-check-input" :disabled="disabled">
            {{ source.label }}
        </label>
        <div v-if="node.props.from_list" class="page-field">
            <label>Espaces de la liste</label>
            <div class="d-flex flex-wrap gap-1 mb-2">
                <span v-for="id in selectedIds" :key="id" class="badge text-bg-secondary d-inline-flex align-items-center gap-2">
                    {{ spaceName(id) }}
                    <button type="button" class="btn-close btn-close-white" :disabled="disabled"
                        :aria-label="'Retirer ' + spaceName(id)" @click="removeSpace(id)" />
                </span>
            </div>
            <dropdown_list v-if="availableSpaces.length && selectedIds.length < 100" :items="availableSpaces"
                field="label" variant="outline-primary" @select="addSpace">Ajouter un espace</dropdown_list>
            <small v-else class="text-muted">{{ selectedIds.length >= 100 ? 'Limite de 100 espaces atteinte.' : 'Aucun autre espace disponible.' }}</small>
        </div>

        <h3 class="page-panel-heading">Affichage</h3>
        <label v-for="field in displayFields" :key="field.key" class="page-field d-flex gap-2 align-items-center">
            <input v-model="node.props[field.key]" type="checkbox" class="form-check-input" :disabled="disabled">
            {{ field.label }}
        </label>
        <template v-if="node.props.show_logo">
            <label class="page-field">Centrage du logo
                <select v-model="node.props.logo_alignment" class="form-select form-select-sm" :disabled="disabled">
                    <option value="left">À gauche</option>
                    <option value="center">Centré</option>
                    <option value="right">À droite</option>
                </select>
            </label>
            <label class="page-field">Largeur maximale du logo (px)
                <input v-model.number="node.props.logo_max_width" type="number" min="0" max="1000" step="10"
                    class="form-control form-control-sm" :disabled="disabled" @change="normalizeLogoMaxWidth">
                <small class="text-muted">0 conserve la pleine largeur de la carte.</small>
            </label>
        </template>

        <h3 class="page-panel-heading">Navigation</h3>
        <label class="page-field">Destination après le choix
            <select v-model="node.props.destination" class="form-select form-select-sm" :disabled="disabled">
                <option value="user">Espace utilisateur, sinon accueil</option>
                <option value="home">Accueil du Space</option>
            </select>
        </label>
        <label class="page-field d-flex gap-2 align-items-start">
            <input v-model="node.props.hide_closed" type="checkbox" class="form-check-input" :disabled="disabled">
            Masquer les espaces sans accès dont les inscriptions sont fermées
        </label>
        <div v-if="!node.props.hide_closed" class="page-field">
            <LocalizedTextField v-model="node.props.closed_text" label="Texte des inscriptions fermées"
                :locales="context.locales" :locale="context.locale" :space-id="context.spaceId"
                :max-length="200" :disabled="disabled" />
        </div>
    </div>
</template>

<script>
import LocalizedTextField from '../../../common/LocalizedTextField.vue'

export default {
    components: { LocalizedTextField },
    props: {
        selected: { type: Object, required: true },
        node: { type: Object, required: true },
        context: { type: Object, required: true },
    },
    data: () => ({
        sources: [
            { key: 'from_domain', label: 'Du domaine courant' },
            { key: 'from_member', label: 'Accessibles avec les droits de l’utilisateur' },
            { key: 'from_list', label: 'Une liste définie' },
        ],
        displayFields: [
            { key: 'show_logo', label: 'Afficher le logo' },
            { key: 'show_name', label: 'Afficher le nom' },
            { key: 'show_short_description', label: 'Afficher la description courte' },
            { key: 'show_description', label: 'Afficher la description longue' },
        ],
    }),
    computed: {
        disabled() { return this.context.readonly === true },
        spaces() { return this.context.plg_core_switch_spaces ?? [] },
        selectedIds() { return this.node.props.space_ids ?? [] },
        availableSpaces() { return this.spaces.filter(space => !this.selectedIds.includes(space.value)) },
    },
    methods: {
        spaceName(id) { return this.spaces.find(space => space.value === id)?.label ?? `#${id}` },
        addSpace(space) {
            // Le brouillon conserve des IDs numériques, validés à nouveau côté serveur.
            if (this.disabled || this.selectedIds.length >= 100 || this.selectedIds.includes(space.value)) return
            this.node.props.space_ids = [...this.selectedIds, space.value]
        },
        removeSpace(id) {
            if (!this.disabled) this.node.props.space_ids = this.selectedIds.filter(value => value !== id)
        },
        normalizeLogoMaxWidth() {
            // Une saisie vide ou hors bornes revient à la pleine largeur de la carte.
            const width = Number.parseInt(this.node.props.logo_max_width, 10)
            this.node.props.logo_max_width = Number.isFinite(width) ? Math.min(1000, Math.max(0, width)) : 0
        },
    },
}
</script>
