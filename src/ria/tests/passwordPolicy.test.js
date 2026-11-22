import test from 'node:test'
import assert from 'node:assert/strict'
import { isValidNewPassword, validateNewPasswordInput, newPasswordMessage } from '../src/application/passwordPolicy.js'

test('la limite bcrypt porte sur les octets UTF-8, sans troncature ni caractère nul', () => {
    for (const value of ['a'.repeat(12), 'a'.repeat(72), 'é'.repeat(36)]) assert.equal(isValidNewPassword(value), true)
    for (const value of ['', 'a'.repeat(11), 'a'.repeat(73), 'é'.repeat(37), 'mot-de-passe\0invalide', null]) {
        assert.equal(isValidNewPassword(value), false)
    }
})

test('la validation native est libérée après correction et lors d’une saisie vide', () => {
    let message
    const target = { value: 'é'.repeat(37), setCustomValidity(value) { message = value } }
    validateNewPasswordInput({ target })
    assert.equal(message, newPasswordMessage)
    target.value = 'a'.repeat(72)
    validateNewPasswordInput({ target })
    assert.equal(message, '')
    target.value = ''
    validateNewPasswordInput({ target })
    assert.equal(message, '')
})
