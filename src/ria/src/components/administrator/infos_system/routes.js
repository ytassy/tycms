// Normalise une valeur pour comparer sans tenir compte de la casse ni des accents.
function normalizeSearchValue(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
}

// Rassemble les champs visibles d'une route pour une recherche unique.
function routeSearchText(route) {
    const params = Array.isArray(route.params) ? route.params : []

    return [
        route.name,
        route.method,
        route.path,
        route.controller,
        route.controllerMethod,
        ...params,
    ].join(' ')
}

// Chaque terme saisi doit être présent dans les champs de la route : cela permet
// de combiner un nom et une méthode sans dépendre de leur ordre.
export function filterRoutes(routes, query) {
    const terms = normalizeSearchValue(query).split(/\s+/).filter(Boolean)

    if (terms.length === 0) {
        return routes
    }

    return routes.filter(route => {
        const searchTarget = normalizeSearchValue(routeSearchText(route))

        return terms.every(term => searchTarget.includes(term))
    })
}

// Le regroupement reçoit déjà les routes filtrées : aucun groupe vide ni
// compteur faux ne doit rester affiché.
export function groupRoutes(routes) {
    return [
        { name: 'Routes API', routes: routes.filter(route => route.isApi) },
        { name: 'Autres routes', routes: routes.filter(route => !route.isApi) },
    ].filter(group => group.routes.length > 0)
}
