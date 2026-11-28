<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Event;

/**
 * Catalogue des types d’événements émis par le Core.
 *
 * Les définitions vivent dans le code, comme les types de jobs ou les
 * composants de page. Cette classe isole le catalogue du plugin : `Plugin` ne
 * fait que l’enregistrer, et les prochains événements du Core s’ajoutent ici.
 *
 * Les textes ne portent aucune audience : le workflow émetteur décrit
 * l’audience dans `EventAudience` et l’émetteur la résout. Le lien est figé à
 * l’émission, car un job n’a pas de routes ; un run d’automatisation, sans
 * routes, obtient un chemin nul.
 */
final class CoreEventDefinitions
{
    /**
     * Définitions du Core.
     *
     * @param callable(string): ?string $administratorPage
     *        Chemin interne vers une page de l’administration, ou null lorsque
     *        l’application n’a pas de routes.
     * @return list<EventDefinition>
     */
    public static function all(callable $administratorPage): array
    {
        $assignmentsLink = static fn (Event $event): ?string => $administratorPage('user_assignments');

        return [
            new EventDefinition(
                id: 'core.user.role.assigned',
                label: 'Rôle attribué',
                description: 'Un rôle est attribué à un compte sur un Space.',
                category: EventDefinition::CATEGORY_USER,
                severity: EventDefinition::SEVERITY_INFO,
                notifiable: true,
                title: 'Rôle attribué',
                bodySelf: 'Vous avez attribué le rôle {role.name} à {user.name}.',
                bodyOthers: '{actor.name} a attribué le rôle {role.name} à {user.name}.',
                link: $assignmentsLink,
            ),
            new EventDefinition(
                id: 'core.user.role.removed',
                label: 'Rôle retiré',
                description: 'Un rôle est retiré à un compte sur un Space.',
                category: EventDefinition::CATEGORY_USER,
                severity: EventDefinition::SEVERITY_INFO,
                notifiable: true,
                title: 'Rôle retiré',
                bodySelf: 'Vous avez retiré le rôle {role.name} à {user.name}.',
                bodyOthers: '{actor.name} a retiré le rôle {role.name} à {user.name}.',
                link: $assignmentsLink,
            ),
        ];
    }
}
