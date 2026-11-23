import test from 'node:test'
import assert from 'node:assert/strict'
import { filterRoutes, groupRoutes } from '../src/components/administrator/infos_system/routes.js'

const routes = [
    {
        name: 'user_list',
        method: 'GET',
        path: '/api/users',
        isApi: true,
        controller: 'Core\\WF\\WFAdminUser',
        controllerMethod: 'getUsers',
        params: [],
    },
    {
        name: 'user_save',
        method: 'POST',
        path: '/api/users/{id}',
        isApi: true,
        controller: 'Core\\WF\\WFAdminUser',
        controllerMethod: 'saveUser',
        params: ['id'],
    },
    {
        name: 'page_show',
        method: 'GET',
        path: '/page/{slug}',
        isApi: false,
        controller: 'MainTemplate\\WF\\WFPage',
        controllerMethod: 'show',
        params: ['slug'],
    },
    {
        name: 'Paramètres du site',
        method: 'PATCH',
        path: '/admin/parametres',
        isApi: false,
        controller: 'Core\\WF\\WFAdminSettings',
        controllerMethod: 'saveSettings',
        params: [],
    },
]

test('une recherche vide conserve toutes les routes', () => {
    assert.equal(filterRoutes(routes, ''), routes)
    assert.equal(filterRoutes(routes, '   '), routes)
})

test('la recherche couvre nom, chemin, méthode, contrôleur et paramètres', () => {
    assert.deepEqual(filterRoutes(routes, 'getusers').map(route => route.name), ['user_list'])
    assert.deepEqual(filterRoutes(routes, '/page/').map(route => route.name), ['page_show'])
    assert.deepEqual(filterRoutes(routes, 'post').map(route => route.name), ['user_save'])
    assert.deepEqual(filterRoutes(routes, 'maintemplate').map(route => route.name), ['page_show'])
    assert.deepEqual(filterRoutes(routes, 'wfadminuser').map(route => route.name), ['user_list', 'user_save'])
    assert.deepEqual(filterRoutes(routes, 'id').map(route => route.name), ['user_save'])
})

test('la recherche ignore la casse et les accents', () => {
    assert.deepEqual(filterRoutes(routes, 'PARAMETRES').map(route => route.name), ['Paramètres du site'])
    assert.deepEqual(filterRoutes(routes, 'paramètres').map(route => route.name), ['Paramètres du site'])
})

test('plusieurs termes peuvent venir de champs différents et dans un autre ordre', () => {
    assert.deepEqual(filterRoutes(routes, 'admin post').map(route => route.name), ['user_save'])
    assert.deepEqual(filterRoutes(routes, 'post admin').map(route => route.name), ['user_save'])
})

test('aucune route ne correspond à une recherche absente des données', () => {
    assert.deepEqual(filterRoutes(routes, 'route inexistante'), [])
})

test('le regroupement ne conserve que les groupes contenant des routes filtrées', () => {
    const groups = groupRoutes(filterRoutes(routes, 'api users'))

    assert.deepEqual(groups.map(group => group.name), ['Routes API'])
    assert.deepEqual(groups[0].routes.map(route => route.name), ['user_list', 'user_save'])
})

test('le regroupement sépare les routes API des autres routes', () => {
    const groups = groupRoutes(routes)

    assert.deepEqual(groups.map(group => group.name), ['Routes API', 'Autres routes'])
    assert.deepEqual(groups.map(group => group.routes.length), [2, 2])
    assert.deepEqual(groupRoutes([]), [])
})
