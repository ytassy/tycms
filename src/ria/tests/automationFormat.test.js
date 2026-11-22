import test from 'node:test'
import assert from 'node:assert/strict'
import { prettyJson, slugify, statusLabel } from '../src/components/administrator/automation/format.js'

test('slugify produces a server-compatible key', () => {
    assert.equal(slugify('Bienvenue à l’inscription'), 'bienvenue-a-l-inscription')
    assert.equal(slugify('  Événement  '), 'evenement')
    assert.equal(slugify('123 test'), 'scenario-123-test')
    assert.equal(slugify('Crème brûlée'), 'creme-brulee')
    assert.equal(slugify('a'.repeat(80)).length, 64)
    assert.match(slugify('Crème brûlée'), /^[a-z][a-z0-9_-]*$/)
})

test('slugify returns an empty value when nothing remains', () => {
    assert.equal(slugify('---'), '')
    assert.equal(slugify(''), '')
})

test('status labels stay user-facing', () => {
    assert.equal(statusLabel('success'), 'Succès')
    assert.equal(statusLabel('failed'), 'Échec')
    assert.equal(statusLabel('inconnu'), 'inconnu')
})

test('prettyJson formats JSON strings without throwing on plain text', () => {
    assert.equal(prettyJson('{"a":1}'), '{\n  "a": 1\n}')
    assert.equal(prettyJson('not json'), 'not json')
    assert.equal(prettyJson(null), '')
})
