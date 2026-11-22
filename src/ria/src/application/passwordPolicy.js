const encoder = new TextEncoder()

export const newPasswordMessage = 'Le mot de passe doit contenir de 12 à 72 octets, sans caractère nul.'

// Un caractère accentué peut occuper plusieurs octets : maxlength ne suffit pas.
export function isValidNewPassword(value) {
    if (typeof value !== 'string' || value.includes('\0')) return false
    const bytes = encoder.encode(value).length
    return bytes >= 12 && bytes <= 72
}

export function validateNewPasswordInput(event) {
    const input = event.target
    input.setCustomValidity(input.value === '' || isValidNewPassword(input.value) ? '' : newPasswordMessage)
}
