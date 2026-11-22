<template>
    <section>
        <h2 class="h3 mb-3">Informations PHP</h2>

        <div class="vat mb-4 me-4" style="width:max-content">
            <div class="card mb-3 shadow-sm" >
                <div class="card-header bg-light">Environnement</div>
                <div class="card-body">
                    <table class="table table-sm table-striped mb-0">
                        <tbody>
                            <tr><th>Version PHP</th><td>{{ data.version }}</td></tr>
                            <tr><th>Version Zend</th><td>{{ data.zend_version }}</td></tr>
                            <tr><th>Interface serveur</th><td>{{ data.sapi }}</td></tr>
                            <tr><th>Système</th><td>{{ data.os }} ({{ data.architecture }} bits)</td></tr>
                            <tr><th>Threads</th><td>{{ data.thread_safe ? 'Activés' : 'Désactivés' }}</td></tr>
                            <tr><th>Mode debug</th><td>{{ data.debug ? 'Activé' : 'Désactivé' }}</td></tr>
                            <tr><th>Fichier INI chargé</th><td class="text-break">{{ displayValue(data.loaded_ini_file) }}</td></tr>
                            <tr><th>Fichiers INI supplémentaires</th><td class="text-break">{{ displayValue(data.scanned_ini_files) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <template v-for="group in extensionGroups" :key="group.name">
            <div class="d-inline-block vat mb-4 me-4 pe-3"  style="width:max-content; max-height: 400px; overflow-y: auto;">
                <div  class="card mb-3 shadow-sm">
                    <div class="card-header bg-light">
                        {{ group.name }}
                        <span class="badge text-bg-secondary ms-2">{{ Object.keys(group.extensions).length }}</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead><tr><th scope="col">Extension</th><th scope="col">Version</th></tr></thead>
                            <tbody>
                                <tr v-for="(version, name) in group.extensions" :key="name">
                                    <th scope="row" class="font-monospace fw-normal">{{ name }}</th>
                                    <td class="font-monospace">{{ displayValue(version) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </template>

        <h3 class="h4 mb-3">Configuration PHP</h3>

        <div class="mb-3">
            <label for="php-settings-search" class="form-label">Rechercher une option</label>
            <input
                id="php-settings-search"
                v-model="searchQuery"
                type="search"
                class="form-control"
                placeholder="Nom ou valeur…"
            >
        </div>

        <div v-for="category in settingsByCategory" :key="category.name"
			class="card mb-3 shadow-sm" style="width: max-content">
            <div class="card-header bg-light">
                {{ category.name }}
                <span class="badge text-bg-secondary ms-2">{{ category.settings.length }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0 align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Option</th>
                            <th scope="col">Valeur locale</th>
                            <th scope="col">Valeur globale</th>
                            <th scope="col">Accès</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="setting in category.settings" :key="setting.name">
                            <th scope="row" class="font-monospace fw-normal">{{ setting.name }}</th>
                            <td class="font-monospace text-break">{{ displayValue(setting.value.local_value) }}</td>
                            <td class="font-monospace text-break">{{ displayValue(setting.value.global_value) }}</td>
                            <td>{{ setting.value.access }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="settingsByCategory.length === 0" class="alert alert-info" role="status">
            Aucune option ne correspond à votre recherche.
        </div>
    </section>
</template>

<script>


export default {
    props: {
        data: {
            type: Object,
            default: () => ({}),
        },
    },

    data() {
        return {
            searchQuery: '',
        }
    },

    computed: {
        extensionGroups() {
            return [
                { name: 'Extensions PHP', extensions: this.data.extensions ?? {} },
                { name: 'Extensions Zend', extensions: this.data.zend_extensions ?? {} },
            ]
        },
        settingsByCategory() {
            const categories = new Map()
            const query = this.searchQuery.trim().toLocaleLowerCase()

            for (const [name, value] of Object.entries(this.data.settings ?? {})) {
                const searchTarget = `${name} ${value.local_value ?? ''} ${value.global_value ?? ''}`.toLocaleLowerCase()

                if (query && !searchTarget.includes(query)) {
                    continue
                }

                const separator = name.indexOf('.')
                const category = separator === -1 ? 'Général' : name.slice(0, separator)

                if (!categories.has(category)) {
                    categories.set(category, [])
                }

                categories.get(category).push({ name, value })
            }

            return Array.from(categories, ([name, settings]) => ({ name, settings }))
        },
    },

    methods: {
        displayValue(value) {
            return value === '' ? '—' : String(value ?? '—')
        },
    },
}
</script>
