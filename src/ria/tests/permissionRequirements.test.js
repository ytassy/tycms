import assert from 'node:assert/strict'
import test from 'node:test'
import { missingPermissionRequirements, permissionDependents, planPermissionSelection } from '../src/application/permissionRequirements.js'

const catalog = [
    { name: 'READ', required_permissions: [] },
    { name: 'WRITE', required_permissions: ['READ'] },
    { name: 'ADMIN', required_permissions: ['WRITE', 'READ'] },
    { name: 'OTHER', required_permissions: [] },
]

test('sélectionner une permission ajoute tous ses prérequis au brouillon, sans modifier la sélection reçue', () => {
    const selected = ['OTHER']
    const plan = planPermissionSelection(selected, ['ADMIN'], catalog, () => true)
    assert.deepEqual(plan.unavailable, [])
    assert.deepEqual(new Set(plan.permissions), new Set(['OTHER', 'ADMIN', 'WRITE', 'READ']))
    assert.deepEqual(selected, ['OTHER'])
    assert.deepEqual(missingPermissionRequirements(plan.permissions, catalog), [])
})

test('un prérequis non délégable bloque tout ajout, y compris celui de la permission demandée', () => {
    const plan = planPermissionSelection(['OTHER'], ['ADMIN'], catalog, permission => permission.name !== 'READ')
    assert.deepEqual(plan.permissions, ['OTHER'])
    assert.deepEqual(plan.unavailable, ['READ'])
})

test('un prérequis déjà attribué peut rester non modifiable', () => {
    const plan = planPermissionSelection(['READ'], ['WRITE'], catalog, permission => permission.name === 'WRITE')
    assert.deepEqual(plan.unavailable, [])
    assert.deepEqual(plan.permissions, ['READ', 'WRITE'])
})

test('le retrait doit libérer les dépendants directs et transitifs avant la permission requise', () => {
    assert.deepEqual(permissionDependents(['READ', 'WRITE', 'ADMIN'], 'READ', catalog).map(item => item.name), ['WRITE', 'ADMIN'])
    assert.deepEqual(permissionDependents(['READ', 'WRITE'], 'WRITE', catalog), [])
    assert.deepEqual(permissionDependents(['READ', 'OTHER'], 'READ', catalog), [])
})

test('un rôle ancien incomplet se répare explicitement en préservant ses permissions inconnues', () => {
    const selected = ['ADMIN', 'legacy__UNKNOWN']
    const missing = missingPermissionRequirements(selected, catalog)
    assert.deepEqual(missing, ['WRITE', 'READ'])
    const plan = planPermissionSelection(selected, missing, catalog, () => true)
    assert.deepEqual(plan.unavailable, [])
    assert.ok(plan.permissions.includes('legacy__UNKNOWN'))
    assert.deepEqual(missingPermissionRequirements(plan.permissions, catalog), [])
})

test('une référence absente du catalogue ne peut pas être ajoutée silencieusement', () => {
    const plan = planPermissionSelection([], ['UNKNOWN'], catalog, () => true)
    assert.deepEqual(plan.permissions, [])
    assert.deepEqual(plan.unavailable, ['UNKNOWN'])
})
