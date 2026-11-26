<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationParameterDefinition;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;
use YTassy\Tycms\CoreExts\Automation\Permissions as AutomationPermissions;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\AddRoleAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\AddToGroupAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GetUserAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\CreateUserAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\DeleteUserAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\UpdateUserAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\ListMenuTypesAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\CreateGroupAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\DeleteGroupAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GetGroupAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\ListGroupsAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\SetGroupRolesAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\UpdateGroupAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\CreateRoleAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\AbstractCreateMenuItemAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\CreateMenuAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\CreatePageMenuItemAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\CreateSeparatorMenuItemAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\CreateTextMenuItemAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\DeleteMenuItemAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\ListMenusAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\UpdateMenuAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\CreateMediaDirectoryAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\CreateMediaFileAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\DeleteMediaAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\ListMediaAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\ListMediaSpaceAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\MoveMediaAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\ReadMediaFileAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\RenameMediaAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\SaveMediaFileAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\DeleteRoleAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GetRoleAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\ListPermissionsAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\ListRolesAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\UpdateRoleAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\ListPageComponentsAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\ListPageHistoryAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\ReadPageVersionAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\ListUsersAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\NotifyAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\RemoveFromGroupAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\RemoveRoleAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\SendDiscordAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\SendMailAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\SendMailTemplateAction;
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
            // Envoi libre : le corps est purifié avec le profil e-mail du site
            // avant d'être inséré dans l'habillage du Space.
            new AutomationActionDefinition(
                id: 'core::sendMail',
                label: 'Envoyer un e-mail',
                handlerClass: SendMailAction::class,
                description: 'Envoie un message dont le corps HTML est fourni par le scénario, '
                    . 'après purification, avec l’habillage e-mail du Space.',
                parameters: [
                    new AutomationParameterDefinition(
                        'to',
                        'string',
                        true,
                        label: 'Destinataire',
                        description: 'Adresse e-mail du destinataire.',
                    ),
                    new AutomationParameterDefinition(
                        'subject',
                        'string',
                        true,
                        label: 'Objet',
                        description: 'Objet du message, sur une seule ligne.',
                    ),
                    new AutomationParameterDefinition(
                        'htmlBody',
                        'string',
                        true,
                        label: 'Corps HTML',
                        description: 'Contenu HTML du message ; il est purifié avant l’envoi.',
                    ),
                ],
                returnType: 'bool',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [CorePermissions::SPACE_ADMIN],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::sendMailTemplate',
                label: 'Envoyer un modèle d’e-mail',
                handlerClass: SendMailTemplateAction::class,
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
            // Découverte des contrats de composition : un agent qui veut créer
            // une page ou une entrée de menu a besoin de connaître les types
            // disponibles et leurs paramètres avant d'écrire.
            new AutomationActionDefinition(
                id: 'core::listPageComponents',
                label: 'Lister les composants de page',
                handlerClass: ListPageComponentsAction::class,
                description: 'Renvoie le catalogue des composants utilisables dans une page : '
                    . 'identifiant de type, zones acceptées, propriétés attendues et exemples de valeurs.',
                parameters: [],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::PAGE_READ],
                timeoutSeconds: 10,
            ),
            new AutomationActionDefinition(
                id: 'core::listPageHistory',
                label: 'Lister l’historique d’une page',
                handlerClass: ListPageHistoryAction::class,
                description: 'Renvoie les versions conservées d’une page, de la plus récente à la plus '
                    . 'ancienne, avec leur date, leur auteur et leur taille. Le contenu d’une version se '
                    . 'lit ensuite avec readPageVersion.',
                parameters: [
                    new AutomationParameterDefinition('pageId', 'int', true, null, 'Page', 'Page lisible, à partir de 1.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::PAGE_READ],
                timeoutSeconds: 10,
            ),
            new AutomationActionDefinition(
                id: 'core::readPageVersion',
                label: 'Lire une version de page',
                handlerClass: ReadPageVersionAction::class,
                description: 'Renvoie le contenu restaurable d’une version : nom, description, type, '
                    . 'HTML brut, SCSS et document JSON. La version doit appartenir à la page demandée.',
                parameters: [
                    new AutomationParameterDefinition('pageId', 'int', true, null, 'Page', 'Page lisible, à partir de 1.'),
                    new AutomationParameterDefinition('versionId', 'int', true, null, 'Version', 'Identifiant retourné par listPageHistory.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::PAGE_READ],
                timeoutSeconds: 10,
            ),
            new AutomationActionDefinition(
                id: 'core::listMenuTypes',
                label: 'Lister les types d’entrée de menu',
                handlerClass: ListMenuTypesAction::class,
                description: 'Renvoie les types d’entrée de menu proposés à l’IA, avec l’action de '
                    . 'création associée et le schéma de leurs paramètres.',
                parameters: [],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::MENU_READ],
                timeoutSeconds: 10,
            ),
            new AutomationActionDefinition(
                id: 'core::getUser',
                label: 'Lire un utilisateur',
                handlerClass: GetUserAction::class,
                description: 'Retourne les informations visibles d’un compte.',
                parameters: [
                    new AutomationParameterDefinition(
                        'userId',
                        'int',
                        true,
                        null,
                        'Identifiant du compte',
                        'Identifiant du compte à lire.',
                    ),
                ],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::USERS_READ],
                targetSpace: 'resource',
                timeoutSeconds: 10,
            ),
            // Rôles et permissions. Le catalogue des permissions se lit avant
            // d'écrire un rôle : un identifiant inventé serait refusé par le WF.
            new AutomationActionDefinition(
                id: 'core::listPermissions',
                label: 'Lister les permissions assignables',
                handlerClass: ListPermissionsAction::class,
                description: 'Renvoie les permissions que l’appelant peut attribuer, avec leur libellé, '
                    . 'leur groupe et leurs dépendances. À lire avant de créer ou modifier un rôle.',
                parameters: [],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::ROLE_READ],
                timeoutSeconds: 10,
            ),
            new AutomationActionDefinition(
                id: 'core::listRoles',
                label: 'Lister les rôles',
                handlerClass: ListRolesAction::class,
                description: 'Renvoie les rôles lisibles par l’appelant, avec leurs permissions.',
                parameters: [],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::ROLE_READ],
                timeoutSeconds: 10,
            ),
            new AutomationActionDefinition(
                id: 'core::getRole',
                label: 'Lire un rôle',
                handlerClass: GetRoleAction::class,
                description: 'Renvoie un rôle précis, s’il est lisible par l’appelant.',
                parameters: [
                    new AutomationParameterDefinition(
                        'roleId',
                        'int',
                        true,
                        null,
                        'Rôle',
                        'Identifiant du rôle à lire.',
                    ),
                ],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::ROLE_READ],
                timeoutSeconds: 10,
            ),
            new AutomationActionDefinition(
                id: 'core::createRole',
                label: 'Créer un rôle',
                handlerClass: CreateRoleAction::class,
                description: 'Crée un rôle dans un Space. Les permissions proviennent de '
                    . 'core::listPermissions ; le type vaut 0 (global), 1 (propre au Space) ou 2 (système).',
                parameters: [
                    new AutomationParameterDefinition('spaceId', 'int', true, null, 'Space', 'Space du rôle.'),
                    new AutomationParameterDefinition('type', 'int', true, null, 'Type', '0 global, 1 propre au Space, 2 système.'),
                    new AutomationParameterDefinition('name', 'string', true, null, 'Nom', 'Nom du rôle, unique dans le Space.'),
                    new AutomationParameterDefinition('description', 'string', false, '', 'Description', 'Description facultative.'),
                    new AutomationParameterDefinition('permissions', 'array', false, [], 'Permissions', 'Liste d’identifiants de permission.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::ROLE_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::updateRole',
                label: 'Modifier un rôle',
                handlerClass: UpdateRoleAction::class,
                description: 'Remplace le nom, la description et la liste complète des permissions d’un rôle. '
                    . 'Les rôles système ne laissent modifier que leurs permissions.',
                parameters: [
                    new AutomationParameterDefinition('roleId', 'int', true, null, 'Rôle', 'Identifiant du rôle.'),
                    new AutomationParameterDefinition('name', 'string', true, null, 'Nom', 'Nom du rôle.'),
                    new AutomationParameterDefinition('description', 'string', false, '', 'Description', 'Description.'),
                    new AutomationParameterDefinition('permissions', 'array', false, [], 'Permissions', 'Liste complète des permissions.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::ROLE_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::deleteRole',
                label: 'Supprimer un rôle',
                handlerClass: DeleteRoleAction::class,
                description: 'Supprime un rôle non système, non attribué et non référencé.',
                parameters: [
                    new AutomationParameterDefinition('roleId', 'int', true, null, 'Rôle', 'Identifiant du rôle.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::ROLE_CRUD],
                timeoutSeconds: 15,
            ),
            // Groupes d'utilisateurs. La gestion du groupe et l'attribution de
            // ses rôles relèvent de deux permissions distinctes.
            new AutomationActionDefinition(
                id: 'core::listGroups',
                label: 'Lister les groupes',
                handlerClass: ListGroupsAction::class,
                description: 'Renvoie les groupes d’utilisateurs lisibles par l’appelant, avec leurs rôles.',
                parameters: [],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::USER_GROUPS_READ],
                timeoutSeconds: 10,
            ),
            new AutomationActionDefinition(
                id: 'core::getGroup',
                label: 'Lire un groupe',
                handlerClass: GetGroupAction::class,
                description: 'Renvoie un groupe précis, s’il est lisible par l’appelant.',
                parameters: [
                    new AutomationParameterDefinition('groupId', 'int', true, null, 'Groupe', 'Identifiant du groupe.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::USER_GROUPS_READ],
                timeoutSeconds: 10,
            ),
            new AutomationActionDefinition(
                id: 'core::createGroup',
                label: 'Créer un groupe',
                handlerClass: CreateGroupAction::class,
                description: 'Crée un groupe dans un Space. parentId vaut 0 pour un groupe racine ; '
                    . 'les rôles se lisent avec core::listRoles et doivent appartenir au même Space.',
                parameters: [
                    new AutomationParameterDefinition('spaceId', 'int', true, null, 'Space', 'Space du groupe.'),
                    new AutomationParameterDefinition('name', 'string', true, null, 'Nom', 'Nom du groupe, unique à ce niveau.'),
                    new AutomationParameterDefinition('description', 'string', false, '', 'Description', 'Description facultative.'),
                    new AutomationParameterDefinition('parentId', 'int', false, 0, 'Groupe parent', '0 pour un groupe racine.'),
                    new AutomationParameterDefinition('roleIds', 'array', false, [], 'Rôles', 'Identifiants des rôles du groupe.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::USER_GROUPS_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::updateGroup',
                label: 'Modifier un groupe',
                handlerClass: UpdateGroupAction::class,
                description: 'Modifie le nom, la description et le parent d’un groupe. Les rôles ne changent '
                    . 'que si roleIds est fourni.',
                parameters: [
                    new AutomationParameterDefinition('groupId', 'int', true, null, 'Groupe', 'Identifiant du groupe.'),
                    new AutomationParameterDefinition('name', 'string', true, null, 'Nom', 'Nom du groupe.'),
                    new AutomationParameterDefinition('description', 'string', false, '', 'Description', 'Description.'),
                    new AutomationParameterDefinition('parentId', 'int', false, 0, 'Groupe parent', '0 pour un groupe racine.'),
                    new AutomationParameterDefinition('roleIds', 'array', false, null, 'Rôles', 'Liste complète des rôles, si modifiée.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::USER_GROUPS_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::setGroupRoles',
                label: 'Attribuer les rôles d’un groupe',
                handlerClass: SetGroupRolesAction::class,
                description: 'Remplace l’ensemble des rôles d’un groupe. Les groupes Invité et Utilisateurs ont des rôles fixes.',
                parameters: [
                    new AutomationParameterDefinition('groupId', 'int', true, null, 'Groupe', 'Identifiant du groupe.'),
                    new AutomationParameterDefinition('roleIds', 'array', true, null, 'Rôles', 'Liste complète des rôles.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::GROUP_ROLES_ASSIGN],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::deleteGroup',
                label: 'Supprimer un groupe',
                handlerClass: DeleteGroupAction::class,
                description: 'Supprime un groupe non utilisé. Refusé s’il porte encore des adhésions, des droits '
                    . 'ou une référence de Space.',
                parameters: [
                    new AutomationParameterDefinition('groupId', 'int', true, null, 'Groupe', 'Identifiant du groupe.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::USER_GROUPS_CRUD],
                timeoutSeconds: 15,
            ),
            // Comptes utilisateurs. Aucune action ne manipule de mot de passe :
            // la création en génère un qui ne sort jamais, et il ne circule que
            // par le message de bienvenue.
            new AutomationActionDefinition(
                id: 'core::createUser',
                label: 'Créer un utilisateur',
                handlerClass: CreateUserAction::class,
                description: 'Crée un compte dans un Space. Le mot de passe est généré par le serveur et n’est '
                    . 'jamais renvoyé : il part par le message de bienvenue, activé par défaut. Sans cet envoi, '
                    . 'le compte doit être réinitialisé par un administrateur.',
                parameters: [
                    new AutomationParameterDefinition('spaceId', 'int', true, null, 'Space', 'Space du compte.'),
                    new AutomationParameterDefinition('firstName', 'string', true, null, 'Prénom', 'Prénom du titulaire.'),
                    new AutomationParameterDefinition('lastName', 'string', true, null, 'Nom', 'Nom du titulaire.'),
                    new AutomationParameterDefinition('email', 'string', true, null, 'E-mail', 'Adresse, unique sur l’instance.'),
                    new AutomationParameterDefinition('timezone', 'string', false, 'Europe/Paris', 'Fuseau', 'Fuseau IANA du compte.'),
                    new AutomationParameterDefinition('blocked', 'bool', false, false, 'Bloqué', 'Compte bloqué dès la création.'),
                    new AutomationParameterDefinition('sendEmail', 'bool', false, true, 'Recevoir les e-mails', 'Réglage du compte.'),
                    new AutomationParameterDefinition('sendWelcomeEmail', 'bool', false, true, 'Message de bienvenue', 'Seul moyen pour le titulaire de recevoir son mot de passe.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::USERS_CRUD],
                timeoutSeconds: 20,
            ),
            new AutomationActionDefinition(
                id: 'core::updateUser',
                label: 'Modifier un utilisateur',
                handlerClass: UpdateUserAction::class,
                description: 'Modifie les informations d’un compte : identité, e-mail, fuseau, blocage, e-mails. '
                    . 'Ni mot de passe ni SSO.',
                parameters: [
                    new AutomationParameterDefinition('userId', 'int', true, null, 'Compte', 'Identifiant du compte.'),
                    new AutomationParameterDefinition('firstName', 'string', true, null, 'Prénom', 'Prénom du titulaire.'),
                    new AutomationParameterDefinition('lastName', 'string', true, null, 'Nom', 'Nom du titulaire.'),
                    new AutomationParameterDefinition('email', 'string', true, null, 'E-mail', 'Adresse, unique sur l’instance.'),
                    new AutomationParameterDefinition('timezone', 'string', false, 'Europe/Paris', 'Fuseau', 'Fuseau IANA du compte.'),
                    new AutomationParameterDefinition('blocked', 'bool', false, false, 'Bloqué', 'Bloquer le compte.'),
                    new AutomationParameterDefinition('sendEmail', 'bool', false, true, 'Recevoir les e-mails', 'Réglage du compte.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::USERS_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::deleteUser',
                label: 'Supprimer un utilisateur',
                handlerClass: DeleteUserAction::class,
                description: 'Supprime un compte. Refusé pour un compte ROOT ou encore référencé.',
                parameters: [
                    new AutomationParameterDefinition('userId', 'int', true, null, 'Compte', 'Identifiant du compte.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::USERS_CRUD],
                timeoutSeconds: 15,
            ),
            // Médias : lecture et arborescence, puis édition des seuls formats
            // texte (SCSS et TXT). Aucun envoi binaire : le langage ne
            // transporte pas de flux, et l'écriture de JS dans les médias
            // exposerait une exécution chez le visiteur.
            new AutomationActionDefinition(
                id: 'core::listMediaSpace',
                label: 'Catalogue des médias d’un Space',
                handlerClass: ListMediaSpaceAction::class,
                description: 'Renvoie les Spaces accessibles, les extensions autorisées et éditables '
                    . '(SCSS et TXT) et les tailles maximales.',
                parameters: [],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::MEDIA_READ],
                timeoutSeconds: 10,
            ),
            new AutomationActionDefinition(
                id: 'core::listMedia',
                label: 'Lister un dossier de médias',
                handlerClass: ListMediaAction::class,
                description: 'Renvoie le contenu d’un dossier de médias et l’arborescence des sous-dossiers.',
                parameters: [
                    new AutomationParameterDefinition('spaceId', 'int', true, null, 'Space', 'Space des médias.'),
                    new AutomationParameterDefinition('path', 'string', false, '', 'Dossier', 'Chemin relatif ; vide pour la racine.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::MEDIA_READ],
                timeoutSeconds: 10,
            ),
            new AutomationActionDefinition(
                id: 'core::readMediaFile',
                label: 'Lire un fichier de médias',
                handlerClass: ReadMediaFileAction::class,
                description: 'Lit un fichier SCSS ou TXT et renvoie son contenu avec sa version.',
                parameters: [
                    new AutomationParameterDefinition('spaceId', 'int', true, null, 'Space', 'Space des médias.'),
                    new AutomationParameterDefinition('path', 'string', true, null, 'Chemin', 'Chemin relatif du fichier.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::MEDIA_READ],
                timeoutSeconds: 10,
            ),
            new AutomationActionDefinition(
                id: 'core::createMediaDirectory',
                label: 'Créer un dossier de médias',
                handlerClass: CreateMediaDirectoryAction::class,
                description: 'Crée un dossier dans les médias d’un Space.',
                parameters: [
                    new AutomationParameterDefinition('spaceId', 'int', true, null, 'Space', 'Space des médias.'),
                    new AutomationParameterDefinition('path', 'string', false, '', 'Dossier parent', 'Chemin relatif ; vide pour la racine.'),
                    new AutomationParameterDefinition('name', 'string', true, null, 'Nom', 'Nom du nouveau dossier.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::MEDIA_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::createMediaFile',
                label: 'Créer un fichier de médias',
                handlerClass: CreateMediaFileAction::class,
                description: 'Crée un fichier texte éditable (.scss ou .txt) dans les médias.',
                parameters: [
                    new AutomationParameterDefinition('spaceId', 'int', true, null, 'Space', 'Space des médias.'),
                    new AutomationParameterDefinition('path', 'string', false, '', 'Dossier parent', 'Chemin relatif ; vide pour la racine.'),
                    new AutomationParameterDefinition('name', 'string', true, null, 'Nom', 'Nom du fichier avec son extension.'),
                    new AutomationParameterDefinition('content', 'string', false, '', 'Contenu', 'Contenu initial du fichier.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::MEDIA_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::saveMediaFile',
                label: 'Enregistrer un fichier de médias',
                handlerClass: SaveMediaFileAction::class,
                description: 'Enregistre un fichier SCSS ou TXT. La version reçue à la lecture est exigée : '
                    . 'une modification concurrente est refusée plutôt qu’écrasée.',
                parameters: [
                    new AutomationParameterDefinition('spaceId', 'int', true, null, 'Space', 'Space des médias.'),
                    new AutomationParameterDefinition('path', 'string', true, null, 'Chemin', 'Chemin relatif du fichier.'),
                    new AutomationParameterDefinition('content', 'string', true, null, 'Contenu', 'Nouveau contenu complet.'),
                    new AutomationParameterDefinition('version', 'string', true, null, 'Version', 'Version renvoyée par la lecture.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::MEDIA_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::renameMedia',
                label: 'Renommer un média',
                handlerClass: RenameMediaAction::class,
                description: 'Renomme un fichier ou un dossier de médias.',
                parameters: [
                    new AutomationParameterDefinition('spaceId', 'int', true, null, 'Space', 'Space des médias.'),
                    new AutomationParameterDefinition('path', 'string', true, null, 'Chemin', 'Chemin relatif de l’élément.'),
                    new AutomationParameterDefinition('name', 'string', true, null, 'Nouveau nom', 'Nouveau nom de l’élément.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::MEDIA_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::moveMedia',
                label: 'Déplacer un média',
                handlerClass: MoveMediaAction::class,
                description: 'Déplace un fichier ou un dossier de médias vers un autre dossier.',
                parameters: [
                    new AutomationParameterDefinition('spaceId', 'int', true, null, 'Space', 'Space des médias.'),
                    new AutomationParameterDefinition('path', 'string', true, null, 'Chemin', 'Chemin relatif de l’élément.'),
                    new AutomationParameterDefinition('destinationPath', 'string', true, null, 'Destination', 'Dossier de destination.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::MEDIA_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::deleteMedia',
                label: 'Supprimer un média',
                handlerClass: DeleteMediaAction::class,
                description: 'Supprime un fichier ou un dossier de médias.',
                parameters: [
                    new AutomationParameterDefinition('spaceId', 'int', true, null, 'Space', 'Space des médias.'),
                    new AutomationParameterDefinition('path', 'string', true, null, 'Chemin', 'Chemin relatif de l’élément.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::MEDIA_CRUD],
                timeoutSeconds: 15,
            ),
            // Menus. La lecture renvoie la hiérarchie par parentId ; l'écriture
            // d'une entrée passe par une action par type déclaré, pour que
            // l'agent n'ait jamais à assembler extension/type/params à l'aveugle.
            new AutomationActionDefinition(
                id: 'core::listMenus',
                label: 'Lister les menus',
                handlerClass: ListMenusAction::class,
                description: 'Renvoie les menus des Spaces lisibles, avec leurs entrées et la hiérarchie '
                    . 'par parentId.',
                parameters: [],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [CorePermissions::MENU_READ],
                timeoutSeconds: 10,
            ),
            new AutomationActionDefinition(
                id: 'core::createMenu',
                label: 'Créer un menu',
                handlerClass: CreateMenuAction::class,
                description: 'Crée un menu dans un Space. Les entrées se créent ensuite par type '
                    . '(createPageMenuItem, createTextMenuItem, createSeparatorMenuItem).',
                parameters: [
                    new AutomationParameterDefinition('spaceId', 'int', true, null, 'Space', 'Space du menu.'),
                    new AutomationParameterDefinition('name', 'string', true, null, 'Nom', 'Nom du menu, unique dans le Space.'),
                    new AutomationParameterDefinition('alias', 'string', false, '', 'Alias', 'Alias technique facultatif.'),
                    new AutomationParameterDefinition('description', 'string', false, '', 'Description', 'Description facultative.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::MENU_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::updateMenu',
                label: 'Modifier un menu',
                handlerClass: UpdateMenuAction::class,
                description: 'Modifie le nom, l’alias ou la description d’un menu. Le Space n’est pas modifiable.',
                parameters: [
                    new AutomationParameterDefinition('menuId', 'int', true, null, 'Menu', 'Identifiant du menu.'),
                    new AutomationParameterDefinition('name', 'string', true, null, 'Nom', 'Nom du menu.'),
                    new AutomationParameterDefinition('alias', 'string', false, '', 'Alias', 'Alias technique.'),
                    new AutomationParameterDefinition('description', 'string', false, '', 'Description', 'Description.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::MENU_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::createPageMenuItem',
                label: 'Créer une entrée de menu vers une page',
                handlerClass: CreatePageMenuItemAction::class,
                description: 'Crée une entrée ouvrant une page du même Space que le menu.',
                parameters: [
                    new AutomationParameterDefinition('menuId', 'int', true, null, 'Menu', 'Menu d’accueil de l’entrée.'),
                    new AutomationParameterDefinition('title', 'string', true, null, 'Titre', 'Libellé affiché.'),
                    new AutomationParameterDefinition('pageId', 'int', true, null, 'Page', 'Page ciblée, du même Space.'),
                    new AutomationParameterDefinition('parentId', 'int', false, null, 'Entrée parente', 'Parent pour un sous-menu.'),
                    new AutomationParameterDefinition('alias', 'string', false, '', 'Alias', 'Alias technique facultatif.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::MENU_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::createTextMenuItem',
                label: 'Créer une entrée de menu de texte',
                handlerClass: CreateTextMenuItemAction::class,
                description: 'Crée une entrée de texte sans destination, utile comme libellé de sous-menu.',
                parameters: [
                    new AutomationParameterDefinition('menuId', 'int', true, null, 'Menu', 'Menu d’accueil de l’entrée.'),
                    new AutomationParameterDefinition('title', 'string', true, null, 'Titre', 'Libellé affiché.'),
                    new AutomationParameterDefinition('parentId', 'int', false, null, 'Entrée parente', 'Parent pour un sous-menu.'),
                    new AutomationParameterDefinition('alias', 'string', false, '', 'Alias', 'Alias technique facultatif.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::MENU_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::createSeparatorMenuItem',
                label: 'Créer un séparateur de menu',
                handlerClass: CreateSeparatorMenuItemAction::class,
                description: 'Crée un séparateur visuel entre deux groupes d’entrées.',
                parameters: [
                    new AutomationParameterDefinition('menuId', 'int', true, null, 'Menu', 'Menu d’accueil de l’entrée.'),
                    new AutomationParameterDefinition('title', 'string', true, null, 'Titre', 'Libellé technique du séparateur.'),
                    new AutomationParameterDefinition('parentId', 'int', false, null, 'Entrée parente', 'Parent pour un sous-menu.'),
                    new AutomationParameterDefinition('alias', 'string', false, '', 'Alias', 'Alias technique facultatif.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::MENU_CRUD],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'core::deleteMenuItem',
                label: 'Supprimer une entrée de menu',
                handlerClass: DeleteMenuItemAction::class,
                description: 'Supprime une entrée placée dans la corbeille (statut -2) et non référencée '
                    . 'par la navigation du Space.',
                parameters: [
                    new AutomationParameterDefinition('menuItemId', 'int', true, null, 'Entrée', 'Identifiant de l’entrée.'),
                ],
                returnType: 'array',
                effect: FunctionEffect::WRITE,
                requiredPermissions: [CorePermissions::MENU_CRUD],
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
                    new AutomationParameterDefinition(
                        'type',
                        'string',
                        false,
                        'user',
                        'Type de compte',
                        '« user » pour les comptes ordinaires, « service » pour les comptes '
                            . 'de service, « all » pour les deux.',
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
