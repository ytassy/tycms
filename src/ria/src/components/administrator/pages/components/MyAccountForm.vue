<template>
    <div>
        <h3 class="page-panel-heading">Présentation</h3>
        <label class="page-field">Disposition des informations
            <select v-model="node.props.layout" class="form-select form-select-sm" :disabled="disabled">
                <option value="simple">Simple — libellé au-dessus de la valeur</option>
                <option value="list">Liste — libellé et valeur sur la même ligne</option>
                <option value="table">Tableau — deux colonnes</option>
            </select>
        </label>
        <label class="page-field d-flex gap-2 align-items-center">
            <input v-model="node.props.readonly" type="checkbox" class="form-check-input" :disabled="disabled">
            Lecture seule
        </label>

        <h3 class="page-panel-heading">Informations personnelles</h3>
        <p class="small text-muted mb-2">Choisissez les informations visibles et celles que l’utilisateur peut modifier depuis ce composant.</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-2">
                <thead><tr><th scope="col">Information</th><th scope="col" class="text-center">Afficher</th><th scope="col" class="text-center">Modifier</th></tr></thead>
                <tbody>
                    <tr v-for="field in fields" :key="field.key">
                        <th scope="row" class="fw-normal">{{ field.label }}</th>
                        <td class="text-center"><input v-model="node.props[`show_${field.key}`]" type="checkbox" class="form-check-input"
                            :aria-label="`Afficher : ${field.label}`" :disabled="disabled"></td>
                        <td class="text-center"><input v-model="node.props[`edit_${field.key}`]" type="checkbox" class="form-check-input"
                            :aria-label="`Modifier : ${field.label}`" :disabled="disabled || node.props.readonly || !node.props[`show_${field.key}`]"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section v-for="group in connectionGroups" :key="group.key">
            <h3 class="page-panel-heading">{{ group.label }}</h3>
            <label class="page-field d-flex gap-2 align-items-center">
                <input v-model="node.props[`show_${group.key}`]" type="checkbox" class="form-check-input" :disabled="disabled">
                Afficher la section
            </label>
            <label v-for="action in group.actions" :key="action.key" class="page-field d-flex gap-2 align-items-center">
                <input v-model="node.props[action.key]" type="checkbox" class="form-check-input"
                    :disabled="disabled || node.props.readonly || !node.props[`show_${group.key}`]">
                {{ action.label }}
            </label>
        </section>
    </div>
</template>

<script>
export default {
    props: {
        selected: { type: Object, required: true },
        node: { type: Object, required: true },
        context: { type: Object, required: true },
    },
    data: () => ({
        fields: [{ key: 'first_name', label: 'Prénom' }, { key: 'last_name', label: 'Nom' },
            { key: 'email', label: 'E-mail' }, { key: 'timezone', label: 'Fuseau horaire' }, { key: 'password', label: 'Mot de passe' }],
        connectionGroups: [
            { key: 'sso', label: 'Connexions SSO', actions: [
                { key: 'add_sso', label: 'Autoriser l’ajout d’une connexion' },
                { key: 'remove_sso', label: 'Autoriser la suppression d’une connexion' },
            ] },
            { key: 'integrations', label: 'Mes intégrations', actions: [
                { key: 'add_integrations', label: 'Autoriser une nouvelle intégration' },
                { key: 'remove_integrations', label: 'Autoriser la déconnexion d’une intégration' },
            ] },
        ],
    }),
    computed: {
        disabled() { return this.context.readonly === true },
    },
}
</script>
