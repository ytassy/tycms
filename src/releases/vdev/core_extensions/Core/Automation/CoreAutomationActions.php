<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationParameterDefinition;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;
use YTassy\Tycms\CoreExts\Automation\Permissions as AutomationPermissions;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\AddRoleAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\AddToGroupAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\ListUsersAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\NotifyAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\RemoveFromGroupAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\RemoveRoleAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\SendDiscordAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\SendMailAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\SendSlackAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\SendTelegramAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\SessionReadAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\SessionRemoveAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\SessionWriteAction;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions as CorePermissions;

/** Actions Core exposées au langage de scénarios. */
final class CoreAutomationActions
{
    /** @return list<AutomationActionDefinition> */
    public static function definitions(): array
    {
        return [
            new AutomationActionDefinition(
                id: 'core::sendMail',
                label: 'Envoyer un e-mail',
                handlerClass: SendMailAction::class,
                description: 'Envoie un modèle d’e-mail déclaré dans le Space.',
                parameters: [
                    new AutomationParameterDefinition(
                        'template',
                        'string',
                        true,
                        label: 'Modèle',
                        description: 'Clé du modèle d’e-mail déclaré dans le Space.',
                    ),
                    new AutomationParameterDefinition(
                        'to',
                        'string',
                        true,
                        label: 'Destinataire',
                        description: 'Adresse e-mail du destinataire.',
                    ),
                    new AutomationParameterDefinition(
                        'vars',
                        'array',
                        false,
                        [],
                        'Variables',
                        'Variables du modèle, sous forme de map.',
                    ),
                ],
                returnType: 'bool',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [CorePermissions::SPACE_ADMIN],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::notify',
                label: 'Envoyer une notification',
                handlerClass: NotifyAction::class,
                description: 'Envoie un message aux canaux de notification de scénario du Space.',
                parameters: [
                    new AutomationParameterDefinition(
                        'message',
                        'string',
                        true,
                        label: 'Message',
                        description: 'Texte à envoyer aux canaux.',
                    ),
                    new AutomationParameterDefinition(
                        'title',
                        'string',
                        false,
                        null,
                        'Titre',
                        'Titre facultatif ; « Automatisation » par défaut.',
                    ),
                    new AutomationParameterDefinition(
                        'channels',
                        'array',
                        false,
                        null,
                        'Canaux',
                        'Liste parmi telegram, slack et discord ; tous les canaux activés par défaut.',
                    ),
                ],
                returnType: 'bool',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::sendTelegram',
                label: 'Envoyer sur Telegram',
                handlerClass: SendTelegramAction::class,
                description: 'Envoie un message sur Telegram via le canal de scénario ou un profil nommé.',
                parameters: [
                    new AutomationParameterDefinition(
                        'message',
                        'string',
                        true,
                        label: 'Message',
                        description: 'Texte à envoyer.',
                    ),
                    new AutomationParameterDefinition(
                        'title',
                        'string',
                        false,
                        null,
                        'Titre',
                        'Titre facultatif ; « Automatisation » par défaut.',
                    ),
                    new AutomationParameterDefinition(
                        'target',
                        'string',
                        false,
                        null,
                        'Profil',
                        'Clé d’un profil de notification ; sans valeur, le canal de scénario Telegram est utilisé.',
                    ),
                ],
                returnType: 'bool',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::sendSlack',
                label: 'Envoyer sur Slack',
                handlerClass: SendSlackAction::class,
                description: 'Envoie un message sur Slack via le canal de scénario ou un profil nommé.',
                parameters: [
                    new AutomationParameterDefinition(
                        'message',
                        'string',
                        true,
                        label: 'Message',
                        description: 'Texte à envoyer.',
                    ),
                    new AutomationParameterDefinition(
                        'title',
                        'string',
                        false,
                        null,
                        'Titre',
                        'Titre facultatif ; « Automatisation » par défaut.',
                    ),
                    new AutomationParameterDefinition(
                        'target',
                        'string',
                        false,
                        null,
                        'Profil',
                        'Clé d’un profil de notification ; sans valeur, le canal de scénario Slack est utilisé.',
                    ),
                ],
                returnType: 'bool',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::sendDiscord',
                label: 'Envoyer sur Discord',
                handlerClass: SendDiscordAction::class,
                description: 'Envoie un message sur Discord via le canal de scénario ou un profil nommé.',
                parameters: [
                    new AutomationParameterDefinition(
                        'message',
                        'string',
                        true,
                        label: 'Message',
                        description: 'Texte à envoyer.',
                    ),
                    new AutomationParameterDefinition(
                        'title',
                        'string',
                        false,
                        null,
                        'Titre',
                        'Titre facultatif ; « Automatisation » par défaut.',
                    ),
                    new AutomationParameterDefinition(
                        'target',
                        'string',
                        false,
                        null,
                        'Profil',
                        'Clé d’un profil de notification ; sans valeur, le canal de scénario Discord est utilisé.',
                    ),
                ],
                returnType: 'bool',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::listUsers',
                label: 'Lister des utilisateurs',
                handlerClass: ListUsersAction::class,
                description: 'Liste les comptes visibles dans les Spaces autorisés.',
                parameters: [
                    new AutomationParameterDefinition(
                        'page',
                        'int',
                        false,
                        1,
                        'Page',
                        'Page demandée, à partir de 1.',
                    ),
                    new AutomationParameterDefinition(
                        'perPage',
                        'int',
                        false,
                        20,
                        'Comptes par page',
                        'Valeur acceptée : 10, 20, 50, 100 ou 500.',
                    ),
                    new AutomationParameterDefinition(
                        'search',
                        'string',
                        false,
                        '',
                        'Recherche',
                        'Filtre sur le nom ou l’adresse e-mail.',
                    ),
                ],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::USERS_READ],
                targetSpace: 'resource',
            ),
            new AutomationActionDefinition(
                id: 'core::addRole',
                label: 'Attribuer un rôle',
                handlerClass: AddRoleAction::class,
                description: 'Attribue un rôle à un utilisateur dans un Space.',
                parameters: [
                    new AutomationParameterDefinition(
                        'userId',
                        'int',
                        true,
                        label: 'Compte',
                        description: 'Identifiant du compte ciblé.',
                    ),
                    new AutomationParameterDefinition(
                        'roleId',
                        'int',
                        true,
                        label: 'Rôle',
                        description: 'Identifiant du rôle concerné.',
                    ),
                    new AutomationParameterDefinition(
                        'spaceId',
                        'int',
                        true,
                        label: 'Space',
                        description: 'Space dans lequel le rôle est attribué ou retiré.',
                    ),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::USER_ROLES_CRUD],
                targetSpace: 'resource',
            ),
            new AutomationActionDefinition(
                id: 'core::removeRole',
                label: 'Retirer un rôle',
                handlerClass: RemoveRoleAction::class,
                description: 'Retire un rôle à un utilisateur dans un Space.',
                parameters: [
                    new AutomationParameterDefinition(
                        'userId',
                        'int',
                        true,
                        label: 'Compte',
                        description: 'Identifiant du compte ciblé.',
                    ),
                    new AutomationParameterDefinition(
                        'roleId',
                        'int',
                        true,
                        label: 'Rôle',
                        description: 'Identifiant du rôle concerné.',
                    ),
                    new AutomationParameterDefinition(
                        'spaceId',
                        'int',
                        true,
                        label: 'Space',
                        description: 'Space dans lequel le rôle est attribué ou retiré.',
                    ),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::USER_ROLES_CRUD],
                targetSpace: 'resource',
            ),
            new AutomationActionDefinition(
                id: 'core::addToGroup',
                label: 'Ajouter à un groupe',
                handlerClass: AddToGroupAction::class,
                description: 'Ajoute un utilisateur à un groupe.',
                parameters: [
                    new AutomationParameterDefinition(
                        'userId',
                        'int',
                        true,
                        label: 'Compte',
                        description: 'Identifiant du compte ciblé.',
                    ),
                    new AutomationParameterDefinition(
                        'groupId',
                        'int',
                        true,
                        label: 'Groupe',
                        description: 'Identifiant du groupe utilisateur concerné.',
                    ),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::USER_GROUPS_ASSIGN],
                targetSpace: 'resource',
            ),
            new AutomationActionDefinition(
                id: 'core::removeFromGroup',
                label: 'Retirer d’un groupe',
                handlerClass: RemoveFromGroupAction::class,
                description: 'Retire un utilisateur d’un groupe.',
                parameters: [
                    new AutomationParameterDefinition(
                        'userId',
                        'int',
                        true,
                        label: 'Compte',
                        description: 'Identifiant du compte ciblé.',
                    ),
                    new AutomationParameterDefinition(
                        'groupId',
                        'int',
                        true,
                        label: 'Groupe',
                        description: 'Identifiant du groupe utilisateur concerné.',
                    ),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::USER_GROUPS_ASSIGN],
                targetSpace: 'resource',
            ),
            new AutomationActionDefinition(
                id: 'core::sessionRead',
                label: 'Lire une donnée de session',
                handlerClass: SessionReadAction::class,
                description: 'Lit une donnée rangée dans la session du navigateur du Space du run. '
                    . 'La valeur écrite par le scénario en cours de requête n’est pas encore visible.',
                parameters: [
                    new AutomationParameterDefinition(
                        'key',
                        'string',
                        true,
                        label: 'Clé',
                        description: 'Clé de travail, sans préfixe : la session reçoit '
                            . 'automatiquement un espace de noms du Space puis le préfixe automation_.',
                    ),
                    // La valeur par défaut précède le chemin : en appel
                    // positionnel comme dans la v1 du langage, elle reste
                    // atteignable sans remplir un chemin intermédiaire.
                    new AutomationParameterDefinition(
                        'default',
                        'mixed',
                        false,
                        null,
                        'Valeur par défaut',
                        'Résultat lorsque la clé ou le chemin est absent.',
                    ),
                    new AutomationParameterDefinition(
                        'path',
                        'string',
                        false,
                        '',
                        'Chemin',
                        'Chemin d’accès dans une map, par exemple « contact.email » ; '
                            . 'sans valeur, la donnée entière est retournée.',
                    ),
                ],
                returnType: 'mixed',
                effect: FunctionEffect::READ,
                requiredPermissions: [AutomationPermissions::PUBLISH],
            ),
            new AutomationActionDefinition(
                id: 'core::sessionWrite',
                label: 'Écrire une donnée de session',
                handlerClass: SessionWriteAction::class,
                description: 'Écrit une donnée dans la session du navigateur du Space du run. '
                    . 'La valeur sera visible à la requête suivante du même visiteur.',
                parameters: [
                    new AutomationParameterDefinition(
                        'key',
                        'string',
                        true,
                        label: 'Clé',
                        description: 'Clé de travail, préfixée automatiquement par la session.',
                    ),
                    new AutomationParameterDefinition(
                        'value',
                        'mixed',
                        true,
                        label: 'Valeur',
                        description: 'Valeur à ranger : texte, nombre, booléen, date ou map ; '
                            . '16384 octets sérialisés au maximum.',
                    ),
                ],
                returnType: 'bool',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [AutomationPermissions::PUBLISH],
            ),
            new AutomationActionDefinition(
                id: 'core::sessionRemove',
                label: 'Effacer une donnée de session',
                handlerClass: SessionRemoveAction::class,
                description: 'Efface une donnée de la session du navigateur du Space du run.',
                parameters: [
                    new AutomationParameterDefinition(
                        'key',
                        'string',
                        true,
                        label: 'Clé',
                        description: 'Clé de travail à effacer ; son absence n’est pas une erreur.',
                    ),
                ],
                returnType: 'bool',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [AutomationPermissions::PUBLISH],
            ),
            ...GoogleSheetsAutomationActions::definitions(),
            ...GoogleCalendarAutomationActions::definitions(),
        ];
    }
}
