# Journal d’événements et notifications

Ce guide décrit le journal d’événements métier et les contrats PHP des
notifications utilisateur. La conception, les lots et les schémas de tables sont
dans le [chantier Journal et notifications](IA/CurrentWorks/EVENT_LOG_NOTIFICATIONS.md).

**État : lot 1 livré.** Les registres sont branchés dans `Core\Plugin`, la table
`event_log` existe, l’écouteur de journal et le resolver d’audience sont
opérationnels, la purge horaire applique la rétention et les workflows
d’attribution de rôle émettent `core.user.role.assigned` et
`core.user.role.removed`. En revanche, **aucune notification n’est encore
créée ni livrée** : le listener et le job de fan-out sont le lot 2, les
livraisons le lot 3 et la page d’administration le lot 4.

La cible est un pipeline en deux temps : le journal et l’enfilement du fan-out
sont **synchrones** dans la transaction métier ; les notifications et les
livraisons sont créées **après le commit**, par le job
`core.notification_fanout`.

## Où se trouve quoi

Les chemins sont relatifs à
`src/releases/vdev/core_extensions/Core/`.

| Élément | Emplacement | Rôle |
| --- | --- | --- |
| `Event` | `Event/Event.php` | Occurrence : type, Space, acteur, objet, données, audience, lien |
| `EventActorType` | `Event/EventActorType.php` | `user`, `system`, `anonymous` |
| `EventDefinition` | `Event/EventDefinition.php` | Catalogue et textes d’un type d’événement |
| `EventDefinitionRegistry` | `Event/EventDefinitionRegistry.php` | Enregistrement des définitions, refus des doublons |
| `EventListenerDefinition` | `Event/EventListenerDefinition.php` | Écouteur : types, classe, priorité, criticité |
| `EventListenerRegistry` | `Event/EventListenerRegistry.php` | Index des écouteurs par type, tri par priorité |
| `EventDispatcher` | `Event/EventDispatcher.php` | Exécution synchrone, profondeur bornée |
| `EventEmitter` | `Event/EventEmitter.php` | Point d’émission ; résout l’audience et applique le plafond |
| `EventAudience` | `Event/EventAudience.php` | Spécification d’audience : permission, rôle, objet ou liste directe |
| `CoreEventDefinitions` | `Event/CoreEventDefinitions.php` | Catalogue des types émis par le Core |
| `EventJournalListener` | `Event/EventJournalListener.php` | Écrit la ligne `event_log`, affecte `Event::$logId` |
| `IEventProvider` | `ExtensionApi/Event/IEventProvider.php` | Déclaration des types par une extension |
| `IEventListenerProvider` | `ExtensionApi/Event/IEventListenerProvider.php` | Déclaration des écouteurs par une extension |
| `IEventListener` | `ExtensionApi/Event/IEventListener.php` | Contrat d’un écouteur |
| `EventLogHelper` | `Helper/Event/EventLogHelper.php` | Écriture du journal et purge de la rétention par Space |
| `AclUserResolver` | `Helper/Acl/AclUserResolver.php` | Résolution inverse d’audience, bornée par `limit` |
| `NotificationChannelDefinition` | `Notification/NotificationChannelDefinition.php` | Type de canal, longueur de message, permission |
| `NotificationChannelRegistry` | `Notification/NotificationChannelRegistry.php` | Catalogue des canaux |
| `INotificationChannelProvider` | `ExtensionApi/Notification/INotificationChannelProvider.php` | Déclaration des canaux par une extension |
| `NotificationChannelAccessHelper` | `Helper/Notification/NotificationChannelAccessHelper.php` | Vérifie qu’un compte peut utiliser un canal |

Accès typés disponibles pour les workflows et écouteurs :
`$this->coreEventEmitter`, `$this->coreEventLogHelper`,
`$this->coreAclUserResolver`, dans `TraitCoreSharedHelpers`.

Tables créées par `Mig_1_1_15` : `event_log`. Les tables `notifications`,
`notification_deliveries`, `user_notification_channels` et
`notification_preferences` arrivent avec les lots 2 à 4.

Le lot 2 ajoutera `Notification/NotificationFanoutListener.php` et le job
`core.notification_fanout`.

## Branchement dans Core

`Core\Plugin` implémente `IEventProvider` et `IEventListenerProvider`. À
`onPrepareExtensions`, seront d’abord collectées toutes les définitions de
toutes les extensions, puis tous les écouteurs, et enfin
`EventListenerRegistry::assertEventTypesKnown()` refuse un écouteur qui vise un
type inconnu. L’ordre est explicite : un écouteur ne peut pas être branché sur
un type déclaré plus tard.

`Core\Plugin` enregistre l’écouteur `plg_core.event_journal` (priorité 10,
critique) sur **tous les types déclarés**. Le registre n’accepte pas de joker :
la liste est construite à partir du catalogue complet, une fois les définitions
connues. Les types du Core sont dans `CoreEventDefinitions`, pour que les
prochains événements n’alourdissent pas le plugin.

## Déclarer un type d’événement

Une extension implémente `IEventProvider` et enregistre ses définitions pendant
`onPrepareExtensions`.

```php
use YTassy\Tycms\CoreExts\Core\Event\Event;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinition;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinitionRegistry;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Event\IEventProvider;

final class Plugin extends BasePlugin implements IEventProvider
{
    public function coreOnRegisterEventTypes(EventDefinitionRegistry $registry): void
    {
        $registry->register(new EventDefinition(
            id: 'core.user.role.assigned',
            label: 'Rôle attribué',
            description: 'Un rôle est attribué à un compte.',
            category: EventDefinition::CATEGORY_USER,
            severity: EventDefinition::SEVERITY_INFO,
            notifiable: true,
            title: 'Rôle attribué',
            bodySelf: 'Vous avez attribué le rôle {role.name} à {user.name}.',
            bodyOthers: '{actor.name} a attribué le rôle {role.name} à {user.name}.',
            link: fn (Event $event): string => $this->url('AdministratorHome') . '#/user_assignments',
        ));
    }
}
```

Règles de validation, appliquées à la construction :

- identifiant stable de 2 à 5 segments, `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,4}$`,
  100 caractères au maximum ;
- `label` et `description` obligatoires ;
- `category` : `user` ou `technical` ; `severity` : `info`, `warning` ou `error` ;
- `log` vaut `true` par défaut, `notifiable` vaut `false`, `notifyActor` vaut
  `true` ;
- une définition `notifiable` exige `log = true`, `title`, `bodySelf`,
  `bodyOthers` et un `link` ;
- `defaultChannels` est une liste de canaux proposés par défaut, sans doublon et
  sans validation contre le registre des canaux.

La définition **ne porte pas d’audience**. Le workflow émetteur résout les
destinataires et les transmet à l’émission.

## Émettre un événement

Un workflow appelle l’émetteur après ses écritures et **avant le commit**. Un
écouteur critique qui échoue fait remonter l’exception et annule la transaction
métier.

```php
$this->coreEventEmitter->emit(
    type: 'core.user.role.assigned',
    spaceId: $spaceId,
    data: [
        'user' => ['id' => $userId, 'name' => $userName],
        'role' => ['id' => $roleId, 'name' => $roleName],
        'actor' => ['id' => $actorId, 'name' => $actorName],
    ],
    audience: EventAudience::globalPermission(Permissions::USER_ROLES_READ, $spaceId),
    actorId: $actorId,
    subjectType: 'user',
    subjectId: $userId,
);
```

Le workflow **décrit** l’audience, il ne calcule ni liste de comptes ni limite :
c’est `EventEmitter` qui appelle `AclUserResolver` avec le plafond configuré
(`plafond + 1`). Une liste directe reste possible avec
`EventAudience::users([…])`, mais c’est un cas marginal : la plupart des
émetteurs désignent un droit et un Space. Une audience au-delà du plafond est
journalisée et ne produit aucune notification ; elle n’est jamais tronquée.

La charge utile est vérifiée par `Event` :

- map à clés textuelles, profondeur 4, 100 entrées par tableau, chaînes de
  500 caractères, JSON de 65 535 octets au maximum ;
- aucun objet PHP ni ressource ;
- clés manifestement sensibles refusées (`password`, `secret`, `token`,
  `cookie`, `authorization`, `api_key`, `private_key`, `session`).

Le chemin interne est calculé par le `link` au moment de l’émission, quand le
routeur est disponible : c’est le seul moment où il peut l’être, car
l’`Application` d’un job n’a pas de routes. Il est validé : pas de schéma, pas
d’hôte, pas de `//`, pas d’antislash, pas de caractère de contrôle, pas de `..`.

Si un écouteur émet un nouvel événement, la chaîne est bornée à 8 niveaux. Au-delà,
l’émission enfant est refusée et `EVENT_DEPTH_EXCEEDED` est journalisé.

## Écouter un événement

```php
use YTassy\Tycms\CoreExts\Core\Event\Event;
use YTassy\Tycms\CoreExts\Core\Event\EventListenerDefinition;
use YTassy\Tycms\CoreExts\Core\Event\EventListenerRegistry;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Event\IEventListener;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Event\IEventListenerProvider;

final class Plugin extends BasePlugin implements IEventListenerProvider
{
    public function coreOnRegisterEventListeners(EventListenerRegistry $registry): void
    {
        $registry->register(new EventListenerDefinition(
            id: 'catalog.listener.publish_mail',
            eventTypes: ['catalog.entry.published'],
            listenerClass: SendCatalogMailListener::class,
            priority: 50,
            critical: true,
        ));
    }
}

final class SendCatalogMailListener extends BaseApplicationItem implements IEventListener
{
    use TraitAllHelpers;

    public function handle(Event $event): void
    {
        // Effet métier de l’extension.
    }
}
```

Contrat :

- la classe est instanciée par le dispatcher avec l’`Application`, comme un
  handler de job ; elle doit étendre `BaseApplicationItem` et implémenter
  `IEventListener` ;
- un identifiant d’écouteur est unique et refusé s’il est déjà pris ;
- les types écoutés doivent être déclarés. La vérification a lieu après
  l’enregistrement de toutes les définitions ;
- priorité de 0 à 1000, ordre croissant, puis ordre d’enregistrement ;
- `critical: true` par défaut : une exception remonte et annule le métier ;
  `critical: false` journalise `EVENT_LISTENER_FAILED` et continue la chaîne.

L’écouteur de journal du Core, `plg_core.event_journal`, couvre tous les types
déclarés. Une extension n’a donc pas à s’y brancher.

## Journal d’événements

`EventJournalListener` écrit `event_log` quand `EventDefinition::$log` vaut
`true` et affecte `Event::$logId`. Il est **critique** : une erreur d’écriture
annule la transaction métier. Un événement `log = false` reste dispatché aux
autres écouteurs mais ne produit ni ligne de journal ni notification.

La table `event_log` décrit une occurrence :

| Colonne | Contenu |
| --- | --- |
| `space_id` | Space réel, jamais le Space de session |
| `event_type` | Identifiant déclaré |
| `category`, `severity` | Repris de la définition |
| `actor_id`, `actor_type` | Compte acteur, ou `system`/`anonymous` sans compte |
| `subject_type`, `subject_id` | Objet concerné, facultatif |
| `data` | Charge utile JSON, références et libellés courts |
| `created_at` | Date UTC |

Index : `(space_id, created_at)`, `(space_id, event_type, created_at)`,
`(actor_id, created_at)` et `(created_at)`. Aucun index sur `data`, ni sur
`subject_type`/`subject_id` au premier lot. La suppression d’un compte met
`actor_id` à `NULL` ; la suppression d’un Space supprime ses lignes.

### Rétention et purge

La rétention se configure **par Space** dans la section « Journal d’événements
et notifications » du formulaire `plugin.core.config` (version 2). La valeur
`0` conserve tout. La purge est un hook `onCronHour` du Core qui délègue à
`EventLogHelper::purgeExpired()` :

- lit la configuration de `plg_core` une fois pour tous les Spaces ;
- ne purge que les Spaces dont `event_log_retention_days` est strictement
  positif ;
- supprime par lots de 500, avec au plus 20 lots par Space et par passage ;
- journalise `EVENT_LOG_PURGE` avec le Space et le nombre de lignes.

`notification_retention_days` et `notification_delivery_retention_days` sont
déjà exposés dans le formulaire ; ils s’appliqueront avec les tables des
lots 2 et 3.

## Résoudre une audience

L’audience est résolue **synchronement**, dans la transaction métier, par
`EventEmitter` au moment de l’émission. Le workflow décrit seulement sa source
dans `EventAudience` ; le job de fan-out ne recalcule jamais les droits. La
définition d’événement ne porte aucune règle d’audience.

```php
EventAudience::globalPermission(string $permission, int $spaceId)
EventAudience::permissionOnObject(string $permission, string $extension, string $object,
    int $objectId, int $spaceId)
EventAudience::globalRole(int $roleId, int $spaceId)
EventAudience::roleOnObject(int $roleId, string $extension, string $object,
    int $objectId, int $spaceId)
EventAudience::users(list<int> $userIds)   // cas marginal, liste directe
```

`EventEmitter` appelle `AclUserResolver::resolve()` avec `plafond + 1`.
`notification_max_recipients` vaut `2000` par défaut dans
`ConfigurationBase` ; une valeur inférieure à 1 est ramenée à 1 à la lecture
(`EventEmitter::maxRecipients()`).

`AclUserResolver` expose les quatre méthodes bornées :

```php
usersWithGlobalPermission(string $permission, int $spaceId, int $limit): array
usersWithPermissionOnObject(string $permission, string $extension, string $object,
    int $objectId, int $spaceId, int $limit): array
usersWithGlobalRole(int $roleId, int $spaceId, int $limit): array
usersWithRoleOnObject(int $roleId, string $extension, string $object,
    int $objectId, int $spaceId, int $limit): array
```

Règles :

- `limit` est obligatoire ; une valeur inférieure à 1 lève une
  `InvalidArgumentException`, pour qu’aucun appelant ne demande une liste
  illimitée ;
- un Space ou un objet invalide retourne une liste vide ; une permission mal
  formée est refusée ;
- les ancêtres du Space sont pris en compte, comme `AclHelper` ;
- un droit sur objet réunit les affectations globales, celles du type
  (`object_id IS NULL`) et celles de l’instance ;
- un rôle affecté à un groupe parent profite aux membres de ses descendants ;
- les comptes bloqués et de service sont écartés, les identifiants dédupliqués.

### Une seule requête, quel que soit le nombre de groupes

Chaque compte possède un groupe personnel (type 1). Un rôle attribué à 3 000
comptes désigne donc 3 000 groupes : il ne faut ni une requête par groupe, ni
le chargement de l’arbre complet en PHP.

La résolution tient en une requête : une CTE récursive part des affectations
utiles (`role_id`, `space_id`, et le périmètre d’objet), descend l’arbre des
groupes (`user_groups.parent_id`), puis joint `user_groups_map` et `users` pour
poser le `LIMIT` sur l’énumération finale des comptes.

```sql
WITH RECURSIVE beneficiaries AS (
    SELECT DISTINCT group_id FROM acl_map
    WHERE role_id IN (…) AND space_id IN (…) AND (…)
    UNION DISTINCT
    SELECT child.id FROM user_groups child FORCE INDEX (idx_user_groups_parent1)
    INNER JOIN beneficiaries parent ON child.parent_id = parent.group_id
)
SELECT STRAIGHT_JOIN DISTINCT gm.user_id
FROM beneficiaries
INNER JOIN user_groups_map gm ON gm.group_id = beneficiaries.group_id
INNER JOIN users u ON u.id = gm.user_id
WHERE u.blocked = 0 AND u.type <> 'service'
ORDER BY gm.user_id
LIMIT …
```

Les ensembles intermédiaires ne sont pas tronqués ; seul le `LIMIT` final
borne la liste. Les `IN` sur les rôles et les Spaces sont découpés par 1 000
identifiants par sécurité, jamais par groupe. `STRAIGHT_JOIN` impose l’ordre
affectations → groupes → comptes : sans lui, l’optimiseur peut partir d’un scan
de `users` et perdre l’intérêt du `LIMIT`. La descente force
`idx_user_groups_parent1` ; `EXPLAIN` montre la graine sur `acl_map` par l’index
du rôle, puis la jointure des comptes par clé primaire.

### Premier événement instrumenté

`WFAdminUser::applyRole()` émet `core.user.role.assigned` ou
`core.user.role.removed` après l’écriture du rôle, donc pour l’interface comme
pour l’action d’automatisation `core::addRole`/`core::removeRole`. Le workflow
décrit l’audience avec
`EventAudience::globalPermission(Permissions::USER_ROLES_READ, $spaceId)` ;
l’émetteur la résout avec le plafond global. La charge utile porte
`user`, `role` et `actor` (libellés courts). Le lien pointe vers
`/administrator#/user_assignments` ; un run d’automatisation, initialisé sans
routes, obtient un chemin nul et l’émission continue. Un appel sans compte
(run d’automatisation sans session) utilise l’acteur `system` et le libellé
« Système ».

## Génération asynchrone des notifications

Le lot 2 livrera `NotificationFanoutListener` et le job
`core.notification_fanout`. Le contrat visé :

- le listener vérifie `Event::$logId`, `notifiable = true`, une audience non
  vide et inférieure ou égale à `notification_max_recipients` ;
- il enfile un `JobRequest` de type `core.notification_fanout`, avec une clé de
  déduplication `notification_fanout:<event_id>` ; un échec d’enfilement est
  critique ;
- le handler relit `event_log` et la définition, retire l’acteur si
  `notifyActor = false`, écarte les comptes bloqués, de service et supprimés,
  applique les préférences, rend les textes avec `event_log.data`, crée les
  notifications par lots de 500 dans sa propre transaction et reste idempotent.

Payload, références seulement :

```json
{
  "event_id": 123,
  "space_id": 4,
  "event_type": "core.user.role.assigned",
  "url_path": "/administrator#/user_assignments",
  "recipient_user_ids": [5, 8, 12]
}
```

## Ce qui reste à livrer

| Lot | Contenu |
| --- | --- |
| 2 | `notifications`, `NotificationFanoutListener`, job `core.notification_fanout`, préférences, purge des canaux interdits, cloche, panneau, composant d’éditeur de page |
| 3 | `user_notification_channels`, `notification_deliveries`, job de livraison, email, Telegram, Slack, Discord ; champs complémentaires des canaux |
| 4 | Page d’administration « Journal », `SYS_EVENT_READ`, rétention des notifications et livraisons, monitoring, tests de sécurité |

## Vérifications

```bash
./docker/dev/php.sh releases/vdev/vendor/bin/phpunit \
  --configuration releases/vdev/phpunit.xml \
  tests/Unit/Plugins/Core/Event tests/Unit/Plugins/Core/Notification \
  tests/Unit/Plugins/Core/Acl

./docker/dev/php.sh releases/vdev/vendor/bin/phpunit \
  --configuration releases/vdev/phpunit.xml \
  tests/Integration/AclUserResolverIntegrationTest.php \
  tests/Integration/EventEmissionIntegrationTest.php \
  tests/Integration/EventLogIntegrationTest.php
```

Les tests unitaires couvrent les validations de définitions, la charge utile,
les chemins internes, les registres, l’ordre et la criticité des écouteurs, la
profondeur, le calcul du lien, les canaux, le contrôle de permission au Space
principal, la spécification d’audience (liste directe sans SQL, délégation avec
la limite reçue, plafond ramené à 1) et le contrat de refus du resolver
(`limit`, permission mal formée, périmètre invalide sans accès SQL).

Les tests d’intégration couvrent l’écriture transactionnelle du journal,
`log = false`, l’annulation par rollback, la purge par Space et par date, la
résolution d’audience (héritage Space, héritage groupe, comptes bloqués et de
service, borne finale, droit global/type/instance) et l’émission des
événements de rôle à travers l’API d’administration, l’émission sans routes
d’un run d’automatisation et les attributions précises sur les groupes
personnels. Ils nécessitent une base de test préparée avec
`tools/test_db_prepare.php --reset` après une migration.
