import assert from 'node:assert/strict'
import test from 'node:test'
import aclFactory from '../src/application/acl.js'

function acl(permissions, roles = {}) {
    return aclFactory.getAcl({ conf: {
        getSpace: () => ({ id: 99 }),
        getAcl: () => ({ user_global_permissions: permissions, user_global_roles: roles,
            space_parent_ids: { 1: null, 2: 1, 99: null } }),
    } })
}

test('ROOT au Space 1 est global, sans exiger le rôle système ni accorder les autres permissions', () => {
    const rights = acl({ 1: ['plg_core__ROOT'] }, { 1: [1000] })
    assert.equal(rights.isRoot(), true)
    assert.equal(rights.hasGlobalPermission('ROOT'), true)
    assert.equal(rights.hasGlobalPermission('plg_core__ROOT', 2), true)
    assert.equal(rights.hasGlobalPermission('ROOT', 99), true)
    assert.equal(rights.hasGlobalRole(2, 1), false)
    assert.equal(rights.hasGlobalPermission('PAGE_CRUD', 2), false)
})

test('ni un rôle nommé Root ni une permission ROOT sur un enfant ne donnent ROOT installation', () => {
    const rights = acl({ 2: ['plg_core__ROOT'] }, { 1: [2] })
    assert.equal(rights.isRoot(), false)
    assert.equal(rights.hasGlobalPermission('ROOT', 2), false)
    assert.equal(rights.hasGlobalPermission('ROOT', 1), false)
    assert.equal(rights.hasGlobalRole(2, 1), true)
})

test('les permissions ordinaires conservent leur héritage et ne complètent pas leurs prérequis', () => {
    const rights = acl({ 1: ['plg_core__PAGE_CRUD'] })
    assert.equal(rights.hasGlobalPermission('PAGE_CRUD', 2), true)
    assert.equal(rights.hasGlobalPermission('PAGE_CRUD', 99), false)
    assert.equal(rights.hasGlobalPermission('PAGE_READ', 2), false)
    assert.equal(rights.isRoot(), false)
})
