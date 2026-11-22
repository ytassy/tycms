// Ces opérations modifient seulement le brouillon de l'éditeur de rôles.
// Le serveur fournit les prérequis transitifs ; les ACL ne les calculent jamais.
export function missingPermissionRequirements(selected, catalog) {
    const present = new Set(selected)
    const definitions = new Map(catalog.map(permission => [permission.name, permission]))
    return [...new Set(selected.flatMap(name => definitions.get(name)?.required_permissions ?? []))]
        .filter(name => !present.has(name))
}

export function permissionDependents(selected, name, catalog) {
    const present = new Set(selected)
    return catalog.filter(permission => present.has(permission.name)
        && permission.required_permissions?.includes(name))
}

export function planPermissionSelection(selected, names, catalog, canAdd) {
    const definitions = new Map(catalog.map(permission => [permission.name, permission]))
    const additions = [...new Set(names.flatMap(name => [name, ...(definitions.get(name)?.required_permissions ?? [])]))]
        .filter(name => !selected.includes(name))

    // Refuser le lot entier si un prérequis n'est pas attribuable par l'éditeur.
    const unavailable = additions.filter(name => !definitions.has(name) || !canAdd(definitions.get(name)))
    return {
        permissions: unavailable.length ? [...selected] : [...new Set([...selected, ...additions])],
        unavailable,
    }
}
