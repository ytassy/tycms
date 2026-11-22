<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPayloadFieldDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPrincipalMode;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerDefinition;

/** Déclencheurs de domaine exposés par Core. */
final class CoreAutomationTriggers
{
    public const USER_CREATED = 'core.user.created';
    public const PAGE_BUTTON_CLICKED = 'core.page.button.clicked';

    /** @return list<AutomationTriggerDefinition> */
    public static function definitions(): array
    {
        return [
            new AutomationTriggerDefinition(
                id: self::USER_CREATED,
                label: 'Compte utilisateur créé',
                description: 'Déclenché après la création d’un compte, dans le Space du compte.',
                defaultPrincipalMode: AutomationPrincipalMode::TRIGGER,
                payloadFields: [
                    new AutomationPayloadFieldDefinition(
                        'user.id',
                        'int',
                        true,
                        'Identifiant du compte créé.',
                    ),
                    new AutomationPayloadFieldDefinition(
                        'user.spaceId',
                        'int',
                        true,
                        'Space du compte.',
                    ),
                    new AutomationPayloadFieldDefinition(
                        'user.firstName',
                        'string',
                        true,
                        'Prénom du compte.',
                    ),
                    new AutomationPayloadFieldDefinition(
                        'user.lastName',
                        'string',
                        true,
                        'Nom du compte.',
                    ),
                    new AutomationPayloadFieldDefinition(
                        'user.email',
                        'string',
                        true,
                        'Adresse e-mail du compte.',
                    ),
                ],
                payloadExample: [
                    'user' => [
                        'id' => 42,
                        'spaceId' => 2,
                        'firstName' => 'Ada',
                        'lastName' => 'Lovelace',
                        'email' => 'ada@example.test',
                    ],
                ],
            ),
            new AutomationTriggerDefinition(
                id: self::PAGE_BUTTON_CLICKED,
                label: 'Clic sur un bouton de page',
                description: 'Déclenché lorsqu’un visiteur active un bouton de page lié à un scénario.',
                defaultPrincipalMode: AutomationPrincipalMode::TRIGGER,
                payloadFields: [
                    new AutomationPayloadFieldDefinition(
                        'page.id',
                        'int',
                        true,
                        'Identifiant de la page.',
                    ),
                    new AutomationPayloadFieldDefinition(
                        'button.id',
                        'string',
                        true,
                        'Identifiant du nœud bouton dans la page.',
                    ),
                    new AutomationPayloadFieldDefinition(
                        'locale',
                        'string',
                        true,
                        'Langue de la page.',
                    ),
                    new AutomationPayloadFieldDefinition(
                        'actor.type',
                        'string',
                        true,
                        'Type du visiteur : user ou anonymous.',
                    ),
                    new AutomationPayloadFieldDefinition(
                        'actor.id',
                        'int',
                        false,
                        'Identifiant du visiteur connecté.',
                    ),
                ],
                payloadExample: [
                    'page' => ['id' => 12],
                    'button' => ['id' => 'b_7f3a'],
                    'locale' => 'fr',
                    'actor' => ['type' => 'user', 'id' => 42],
                ],
            ),
        ];
    }
}
