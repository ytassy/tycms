// Les cases sensibles ne réagissent qu'à un clic de pointeur, jamais à Entrée ou Espace.
export function isAssignmentMouseClick(event) {
    return event?.button === 0 && event.detail > 0
}

export function assignmentBlockReason(user, column) {
    if (column.kind === 'group') {
        if (user.group_ids.includes(column.id) && user.protected_group_ids.includes(column.id)) {
            return 'Vous ne pouvez pas vous retirer du groupe Super Admin.'
        }
        return user.can_assign_groups === true ? ''
            : 'Vous devez pouvoir attribuer des groupes sur l’espace principal de cet utilisateur.'
    }

    return user.can_assign_roles === true ? ''
        : 'Vous devez pouvoir attribuer des rôles sur l’espace principal de cet utilisateur.'
}
