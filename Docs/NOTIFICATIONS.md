# Journal d’événements et notifications

Ce guide décrit les contrats PHP du journal d’événements et des notifications
utilisateur. La conception, les lots et les schémas de tables sont dans le
[chantier Journal et notifications](IA/CurrentWorks/EVENT_LOG_NOTIFICATIONS.md).

**État : lot 0.** Les contrats sont écrits et testés ; ils ne sont pas encore
branchés dans `Core\Plugin`. Aucune table n’est créée, aucun événement n’est
encore émis par un workflow, aucune notification n’est produite et aucun envoi
n’est effectué. Les lots 1 à 4 restent à livrer.

La cible est un pipeline en deux temps : le journal et l’enfilement du fan-out
sont **synchrones** dans la transaction métier ; les notifications et les
livraisons sont créées **après le commit**, par le job
`core.notification_fanout`. Voir
[Génération asynchrone](#génération-asynchrone-des-notifications).

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
| `EventEmitter` | `Event/EventEmitter.php` | Point d’émission appelé par les workflows |
| `IEventProvider` | `ExtensionApi/Event/IEventProvider.php` | Déclaration des types par une extension |
| `IEventListenerProvider` | `ExtensionApi/Event/IEventListenerProvider.php` | Déclaration des écouteurs par une extension |
| `IEventListener` | `ExtensionApi/Event/IEventListener.php` | Contrat d’un écouteur |
| `NotificationChannelDefinition` | `Notification/NotificationChannelDefinition.php` | Type de canal, longueur de message, permission |
| `NotificationChannelRegistry` | `Notification/NotificationChannelRegistry.php` | Catalogue des canaux |
| `INotificationChannelProvider` | `ExtensionApi/Notification/INotificationChannelProvider.php` | Déclaration des canaux par une extension |
| `NotificationChannelAccessHelper` | `Helper/Notification/NotificationChannelAccessHelper.php` | Vérifie qu’un compte peut utiliser un canal |

Le lot 2 ajoutera `Notification/NotificationFanoutListener.php` et
`Notification/NotificationFanoutJobHandler.php` ; ces classes n’existent pas
encore. Le lot 1 ajoutera `Helper/Acl/AclUserResolver.php`.

## Déclarer un type d’événement

Une extension implémente `IEventProvider` et enregistre ses définitions pendant
`onPrepareExtensions`. L’appel des fournisseurs sera branché dans `Core\Plugin`
au lot 1.

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
            link: fn (Event $event): string => $this->url(
                'core_user_edit',
                ['id' => $event->data['user']['id']],
            ),
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
/** @var EventEmitter $emitter */
$emitter = $this->getSharedExtensionsClass(EventEmitter::class);

$recipients = $this->coreAclUserResolver->usersWithGlobalPermission(
    Permissions::USER_ROLES_READ,
    $spaceId,
    $this->app->getConf()->notification_max_recipients + 1,
);

$event = $emitter->emit(
    type: 'core.user.role.assigned',
    spaceId: $spaceId,
    data: [
        'user' => ['id' => $userId, 'name' => $userName],
        'role' => ['id' => $roleId, 'name' => $roleName],
    ],
    recipientUserIds: $recipients,
    actorId: $this->session->getUserId(),
    subjectType: 'user',
    subjectId: $userId,
);
```

L’accès typé `coreEventEmitter` sera ajouté dans le trait des helpers partagés
avec le premier workflow émetteur (lot 1). `AclUserResolver` appartient au même
lot.

Le resolver exige un `limit` : il s’arrête à cette borne. Le `+ 1` permet au
listener de fan-out de distinguer une audience au plafond d’une audience qui le
dépasse. Une audience trop grande est journalisée et ne produit aucune
notification ; elle n’est jamais tronquée.

Après `emit()`, la ligne de journal et le job de fan-out sont écrits dans la
transaction métier. Les notifications n’existent qu’après le passage du worker :
la cloche peut apparaître avec le délai du CRON ou du déclencheur trafic.

La charge utile est vérifiée par `Event` :

- map à clés textuelles, profondeur 4, 100 entrées par tableau, chaînes de
  500 caractères, JSON de 65 535 octets au maximum ;
- aucun objet PHP ni ressource ;
- clés manifestement sensibles refusées (`password`, `secret`, `token`,
  `cookie`, `authorization`, `api_key`, `private_key`, `session`).

Le chemin interne est calculé par le `link` au moment de l’émission, quand le
routeur est disponible. Il est validé : pas de schéma, pas d’hôte, pas de `//`,
pas d’antislash, pas de caractère de contrôle, pas de `..`.

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

Les écouteurs Core prévus sont `plg_core.event_journal` (écriture du journal) et
`plg_core.notification_fanout` (enfilement du job de fan-out), livrés aux lots 1
et 2.

## Déclarer un canal de notification

```php
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Notification\INotificationChannelProvider;
use YTassy\Tycms\CoreExts\Core\Notification\NotificationChannelDefinition;
use YTassy\Tycms\CoreExts\Core\Notification\NotificationChannelRegistry;

final class Plugin extends BasePlugin implements INotificationChannelProvider
{
    public function coreOnRegisterNotificationChannels(NotificationChannelRegistry $registry): void
    {
        $registry->register(new NotificationChannelDefinition(
            id: 'webhook',
            label: 'Webhook',
            maxMessageLength: 2000,
            permission: 'plg_webhook__USE_NOTIF_WEBHOOK_CHANNEL',
        ));
    }
}
```

Contrat du lot 0 :

- identifiant stable de 32 caractères au maximum, `^[a-z][a-z0-9_]*$` ;
- libellé obligatoire ;
- longueur maximale de message entre 1 et 65 535 ;
- `permission = null` : canal ouvert à tous les comptes ;
- permission sous la forme `<extension>__<PERMISSION>`, par exemple
  `plg_webhook__USE_NOTIF_WEBHOOK_CHANNEL` ;
- un identifiant de canal déjà enregistré est refusé ;
- les identifiants `ui`, `email`, `telegram`, `slack` et `discord` sont réservés
  à Core ; une extension qui les revendique est refusée au démarrage.

Le formulaire de configuration, les champs secrets, le validateur et la classe
d’envoi seront ajoutés au lot 3 avec le handler de livraison.

## Permission d’un type de canal

Un canal réservé n’est utilisable que par les comptes qui possèdent sa
permission.

| Moment | Comportement |
| --- | --- |
| Enregistrement d’une préférence ou d’une connexion | Contrôle avec `NotificationChannelAccessHelper::isAllowedForUser()` ; refus avec `NOTIFICATION_CHANNEL_FORBIDDEN` |
| Ouverture des préférences | Les canaux devenus interdits sont **supprimés en base** (préférences et connexions), avec `NOTIFICATION_CHANNEL_PURGED` |
| Émission d’un événement | Aucun contrôle |
| Livraison | Aucun contrôle |

Le droit se lit sur le **Space principal du compte** (`users.space_id`), avec
l’héritage normal des Spaces. Il n’est pas évalué sur le Space de l’événement :
une préférence et une connexion sont personnelles, pas rattachées à un Space.

Conséquence assumée : si le droit est retiré, les réglages déjà enregistrés
continuent de fonctionner jusqu’à la prochaine ouverture des préférences. C’est
cette ouverture qui purge la base.

Le registre ne vérifie pas que la permission existe dans le catalogue ACL. Une
faute de frappe rend le canal inutilisable pour tous ; c’est documenté et
assumé.

Canaux intégrés :

| Canal | Permission | Réglable |
| --- | --- | --- |
| `ui` | `null` | Non, toujours actif |
| `email` | `null` | Non, opt-in porté par la définition |
| `telegram` | `null` | Oui |
| `slack` | `null` | Oui |
| `discord` | `null` | Oui |

## Génération asynchrone des notifications

Le `NotificationFanoutListener` (lot 2) ne crée aucune ligne. Il vérifie :

- `Event::$logId` non nul et définition `notifiable = true` ;
- audience non vide et inférieure ou égale à `notification_max_recipients`.

Il enfile ensuite un `JobRequest` de type `core.notification_fanout`, avec une
clé de déduplication `notification_fanout:<event_id>`. Un échec d’enfilement
est critique : la transaction métier est annulée, comme pour le journal.

Payload, références seulement :

```json
{
  "event_id": 123,
  "space_id": 4,
  "event_type": "core.user.role.assigned",
  "url_path": "/administrator/users/12",
  "recipient_user_ids": [5, 8, 12]
}
```

Le handler `NotificationFanoutJobHandler` :

- déclare `JOB_MIN_TIME = 2`, `JOB_MAX_ATTEMPTS = 2` et la priorité normale ;
- relit `event_log` et la définition, puis reconstruit l’`Event` ;
- retire l’acteur si `notifyActor = false`, écarte les comptes bloqués, de
  service et supprimés ;
- applique les préférences ;
- rend le titre et les deux corps avec `event_log.data` ;
- crée les notifications et, au lot 3, les livraisons et les jobs de livraison ;
- travaille par lots de 500 dans une transaction ouverte par le handler ;
- reste idempotent : un rejeu technique ne crée ni doublon ni seconde
  livraison.

Le worker ne fournit pas de transaction au handler : le handler ouvre la sienne.
Après deux tentatives en échec, le job passe en `failed` et reste visible dans
Jobs. L’événement et le journal restent ; aucune notification n’est créée. Un
rejeu manuel depuis Jobs est possible, ce n’est pas un rejeu métier.

L’audience reste résolue en **synchrone** : le job ne recalcule jamais les
droits ACL. Le lien est figé à l’émission et transporté dans le payload, car un
job n’a pas de routes. Les textes, eux, sont rendus par le job ; une définition
modifiée entre l’émission et le job applique le nouveau texte.

## Ce qui reste à livrer

| Lot | Contenu |
| --- | --- |
| 1 | Branchement des registres, `event_log`, `EventJournalListener`, `AclUserResolver` borné par `limit`, purge, `core.user.role.assigned` |
| 2 | `notifications`, `NotificationFanoutListener`, job `core.notification_fanout`, préférences, purge des canaux interdits, cloche, panneau |
| 3 | `user_notification_channels`, `notification_deliveries`, job de livraison, email, Telegram, Slack, Discord ; champs complémentaires des canaux |
| 4 | Page d’administration « Journal », `SYS_EVENT_READ`, rétention, monitoring, tests de sécurité |

## Vérifications

```bash
./docker/dev/php.sh releases/vdev/vendor/bin/phpunit \
  --configuration releases/vdev/phpunit.xml \
  tests/Unit/Plugins/Core/Event tests/Unit/Plugins/Core/Notification
```

Les tests couvrent les validations de définitions, la charge utile, les chemins
internes, les registres, l’ordre et la criticité des écouteurs, la profondeur,
le calcul du lien, les canaux et le contrôle de permission au Space principal.
Les tests du resolver borné et du job de fan-out seront ajoutés avec les lots 1
et 2.
