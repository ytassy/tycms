<template>
    <section style="width: max-content;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h3 mb-0">Routes</h2>
            <span class="badge text-bg-primary rounded-pill">{{ routes.length }} route<span v-if="routes.length !== 1">s</span></span>
        </div>

        <div v-for="group in routeGroups" :key="group.name" class="mb-4">
            <h3 class="h5 mb-2">{{ group.name }} <span class="badge text-bg-secondary">{{ group.routes.length }}</span></h3>
            <div class="card shadow-sm border-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Nom</th>
                            <th scope="col">Méthode</th>
                            <th scope="col">Chemin</th>
                            <th scope="col">Contrôleur</th>
                            <th scope="col">Paramètres</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="route in group.routes" :key="route.name">
                            <td class="fw-semibold">{{ route.name }}</td>
                            <td><span class="badge" :class="methodClass(route.method)">{{ route.method }}</span></td>
                            <td><code>{{ route.path }}</code></td>
                            <td>
                                <div class="text-break small">{{ route.controller }}</div>
                                <div v-if="route.controllerMethod" class="text-muted small">→ {{ route.controllerMethod }}()</div>
                            </td>
                            <td>
                                <template v-if="route.params.length">
                                    <span v-for="parameter in route.params" :key="parameter" class="badge text-bg-light border text-dark me-1">{{ parameter }}</span>
                                </template>
                                <span v-else class="text-muted">—</span>
                            </td>
                        </tr>
                        <tr v-if="group.routes.length === 0">
                            <td colspan="5" class="text-center text-muted py-4">Aucune route enregistrée.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
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

    computed: {
        routes() {
            return Object.entries(this.data ?? {}).map(([name, route]) => ({
                name: route.name ?? name,
                method: String(route.method?.value ?? route.method ?? ''),
                path: route.path ?? '',
                isApi: route.isApi === true,
                controller: typeof route.controller === 'string' ? route.controller : '',
                controllerMethod: route.controllerMethod ?? '',
                params: Array.isArray(route.params) ? route.params : [],
            }))
        },
        routeGroups() {
            return [
                { name: 'Routes API', routes: this.routes.filter(route => route.isApi) },
                { name: 'Autres routes', routes: this.routes.filter(route => !route.isApi) },
            ]
        },
    },

    methods: {
        methodClass(method) {
            return {
                GET: 'text-bg-success',
                POST: 'text-bg-warning',
                PUT: 'text-bg-info',
                PATCH: 'text-bg-info',
                DELETE: 'text-bg-danger',
            }[method.toUpperCase()] ?? 'text-bg-secondary'
        },
    },
}
</script>
