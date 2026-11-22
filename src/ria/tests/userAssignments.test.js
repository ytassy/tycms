import assert from 'node:assert/strict'
import test from 'node:test'
import { createPinia, setActivePinia } from 'pinia'
import { useAdminUserAssignmentsStore } from '../src/stores/admin_user_assignments.js'
import { assignmentBlockReason, isAssignmentMouseClick } from '../src/components/administrator/user_assignments/assignmentInteraction.js'

const endpoint = '/administrator/api/user-assignments'
const copy = value => structuredClone(value)
const spaces = [1, 2].map(id => ({ id, title: `Space ${id}`, can_assign_roles: true, can_assign_groups: true }))
const user = () => ({ id: 7, space_id: 2, first_name: 'Alice', last_name: 'Test', email: 'alice@example.test',
    can_assign_roles: true, can_assign_groups: true, role_ids: [], group_ids: [], protected_group_ids: [] })
const page = (query, users = [user()]) => ({ space_id: query.spaceId, page: query.page, per_page: query.perPage,
    roles: [{ id: 11, name: 'Rôle' }], groups: [{ id: 21, name: 'Groupe' }], users, total: users.length })
function deferred() {
    let resolve, reject
    const promise = new Promise((yes, no) => { resolve = yes; reject = no })
    return { promise, resolve, reject }
}
function harness({ get, post, actorId = 99 } = {}) {
    setActivePinia(createPinia())
    const reads = [], writes = []
    const app = { conf: { getUser: () => ({ id: actorId }) }, api: {
        async get(path, query) {
            reads.push({ path, query })
            if (get) return get(path, query)
            return path.endsWith('/directory') ? { spaces: copy(spaces) } : page(query)
        },
        async post(path, { data }) {
            writes.push({ path, data })
            return post ? post(path, data) : data
        },
    } }
    return { store: useAdminUserAssignmentsStore.get(null, app)(), reads, writes }
}

test('le Space filtre les colonnes sans filtrer les utilisateurs reçus par leur espace principal', async () => {
    const { store, reads } = harness()
    assert.equal(await store.initialize(1), true)
    assert.equal(store.spaceId, 1)
    assert.equal(store.users[0].space_id, 2)
    assert.deepEqual(reads[1].query, { spaceId: 1, page: 1, perPage: 20, search: '' })
    await store.setFilters({ spaceId: 2, perPage: 50, search: ' Alice ' })
    assert.deepEqual(reads.at(-1).query, { spaceId: 2, page: 1, perPage: 50, search: 'Alice' })
})

test('une case attend la confirmation et les doubles clics ne lancent pas deux écritures', async () => {
    const pending = deferred()
    const { store, writes } = harness({ post: () => pending.promise })
    await store.initialize(1)
    const saving = store.toggle(7, 'role', 11)
    assert.deepEqual(store.users[0].role_ids, [])
    assert.deepEqual(store.saving, { userId: 7, kind: 'role', itemId: 11 })
    assert.equal(await store.toggle(7, 'role', 11), false)
    assert.equal(await store.setFilters({ spaceId: 2 }), false)
    assert.equal(writes.length, 1)
    assert.equal(writes[0].path, `${endpoint}/save`)
    assert.deepEqual(writes[0].data, { user_id: 7, space_id: 1, kind: 'role', item_id: 11, before: false, assigned: true })
    pending.resolve(writes[0].data)
    assert.equal(await saving, true)
    assert.deepEqual(store.users[0].role_ids, [11])
    assert.equal(store.saving, null)
})

test('les permissions de groupes restent indépendantes des rôles et le groupe protégé ne peut être retiré', async () => {
    const { store, writes } = harness()
    await store.initialize(1)
    store.users[0].can_assign_groups = false
    assert.equal(await store.toggle(7, 'group', 21), false)
    assert.equal(writes.length, 0)
    assert.equal(await store.toggle(7, 'role', 11), true)
    store.users[0].can_assign_groups = true
    store.users[0].group_ids = [21]
    store.users[0].protected_group_ids = [21]
    assert.match(assignmentBlockReason(store.users[0], { kind: 'group', id: 21 }), /Super Admin/)
    assert.equal(await store.toggle(7, 'group', 21), false)
    assert.equal(await store.toggle(7, 'role', 999), false)
    assert.equal(writes.length, 1)
})

test('une confirmation incohérente entraîne une relecture de l’état réel', async () => {
    let assigned = false
    const { store, reads } = harness({
        get: (path, query) => path.endsWith('/directory') ? { spaces: copy(spaces) }
            : page(query, [{ ...user(), role_ids: assigned ? [11] : [] }]),
        post: () => { assigned = true; return '<html>Réponse tronquée</html>' },
    })
    await store.initialize(1)
    assert.equal(await store.toggle(7, 'role', 11), false)
    assert.match(store.error, /pas confirmé/)
    assert.deepEqual(store.users[0].role_ids, [11])
    assert.equal(reads.filter(read => read.path.endsWith('/directory')).length, 2)
    assert.equal(store.saving, null)
})

test('un refus d’écriture suivi d’un échec de relecture ne laisse aucune case active', async () => {
    let failed = false
    const { store } = harness({
        get: (path, query) => {
            if (failed) throw new Error('Accès retiré')
            return path.endsWith('/directory') ? { spaces: copy(spaces) } : page(query)
        },
        post: () => { failed = true; throw new Error('État obsolète') },
    })
    await store.initialize(1)
    assert.equal(await store.toggle(7, 'group', 21), false)
    assert.match(store.error, /État obsolète.*Accès retiré/)
    assert.deepEqual(store.users, [])
    assert.equal(store.spaceId, null)
    assert.equal(store.loading, false)
    assert.equal(store.saving, null)
})

test('les réponses anciennes ne peuvent pas remplacer le Space sélectionné', async () => {
    const first = deferred(), second = deferred()
    let waiting = false
    const { store } = harness({ get: (path, query) => {
        if (path.endsWith('/directory')) return { spaces: copy(spaces) }
        if (!waiting) return page(query)
        return query.spaceId === 1 ? first.promise : second.promise
    } })
    await store.initialize(1)
    waiting = true
    const oldRead = store.loadPage()
    const newRead = store.setFilters({ spaceId: 2 })
    second.resolve(page({ spaceId: 2, page: 1, perPage: 20 }, [{ ...user(), id: 8 }]))
    assert.equal(await newRead, true)
    first.resolve(page({ spaceId: 1, page: 1, perPage: 20 }))
    assert.equal(await oldRead, false)
    assert.equal(store.spaceId, 2)
    assert.equal(store.users[0].id, 8)
    assert.equal(store.loading, false)
})

test('une page devenue hors limites revient à la dernière page disponible', async () => {
    const queries = []
    const { store } = harness({ get: (path, query) => {
        if (path.endsWith('/directory')) return { spaces: copy(spaces) }
        queries.push(query)
        return page(query, query.page === 1 ? [user()] : [])
    } })
    await store.initialize(1)
    store.page = 3
    assert.equal(await store.loadPage(), true)
    assert.equal(store.page, 1)
    assert.deepEqual(queries.map(query => query.page), [1, 3, 1])
    assert.equal(store.users[0].id, 7)
})

test('modifier ses propres attributions recharge les permissions et les colonnes', async () => {
    let changed = false
    const { store } = harness({ actorId: 7,
        get: (path, query) => path.endsWith('/directory') ? { spaces: copy(spaces) }
            : { ...page(query), roles: changed ? [] : [{ id: 11, name: 'Rôle' }] },
        post: (_path, data) => { changed = true; return data },
    })
    await store.initialize(1)
    assert.equal(await store.toggle(7, 'role', 11), true)
    assert.deepEqual(store.roles, [])
    assert.equal(await store.toggle(7, 'role', 11), false)
})

test('les clics clavier, synthétiques et secondaires ne modifient pas les attributions', () => {
    for (const event of [undefined, {}, { button: 0, detail: 0 }, { button: 1, detail: 1 }, { button: 2, detail: 1 }]) {
        assert.equal(isAssignmentMouseClick(event), false)
    }
    assert.equal(isAssignmentMouseClick({ button: 0, detail: 1 }), true)
})
