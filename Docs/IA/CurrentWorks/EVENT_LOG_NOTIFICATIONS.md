# Chantier Journal d’événements et notifications

Conception du journal d’activité centralisé et du système de notifications de
TyCMS. Ce document fixe le périmètre, les frontières avec les mécanismes
existants, le modèle de données, les arbitrages actés et les points restant à
préciser. Il ne décrit pas une fonctionnalité livrée : aucune table ni aucun
code de production n’est créé par ce document.

## Objectif

Permettre aux workflows métier du Core, des extensions et de l’automatisation
d’émettre des **événements**, puis :

1. de les historiser dans un journal métier durable ;
2. de notifier les utilisateurs concernés dans l’interface ;
3. de livrer certaines notifications par email, Telegram, Slack ou Discord ;
4. de laisser les extensions brancher du code sur ces événements.

L’émission est explicite dans chaque workflow. L’automatisation n’a pas de
traitement particulier : lorsqu’une action d’automatisation appelle un workflow
métier, ce workflow émet l’événement comme s’il était appelé depuis
l’interface. Si cela duplique des appels `emit()` dans plusieurs workflows,
c’est accepté : la lisibilité et la simplicité priment sur la factorisation.

## Décisions actées

| Sujet | Décision |
| --- | --- |
| Nom du service | Pas de « Domain ». `Core\Event\EventEmitter`, `EventDispatcher`, `Event` |
| Journal | L’écriture du journal fait partie de la transaction métier. Le système doit fonctionner, pas seulement « best effort » |
| Automatisation | Aucun pont dédié. Les workflows appelés par l’automatisation émettent normalement |
| Rejeu | Aucun système de relance, ni automatique métier, ni manuel dans l’administration |
| `dedup_key` | Supprimé : pas de rejeu et émetteurs de confiance |
| Rétention | Configurable par Space dans la configuration de `plg_core` ; `0` = aucune limite, on conserve tout |
| Capacité | Plafond global de destinataires dans `configuration.php` : `notification_max_recipients = 2000` |
| Dépassement | Journaliser et ne pas notifier ; jamais de troncature silencieuse |
| ACL inverses | Nouveaux helpers dédiés, algorithme SQL + PHP optimisé, index revus pendant l’implémentation |
| Interface | Cloche montée en composant global, sans page dédiée ni type de menu ; activée par défaut |
| Boîte de réception | Toutes les notifications de l’utilisateur, tous Spaces confondus, avec badge du Space |
| Email | Aucun email par défaut ; opt-in au cas par cas dans la définition |
| Menu | Aucun type de menu « notifications » ; la cloche est un composant global |
| Préférences | Nouveau composant de l’éditeur de page, distinct de « Mon compte » |
| Validation des payloads | Pas de système `payloadFields` : tout est en code dur |
| Textes | Deux corps distincts : action de l’utilisateur lui-même, action des autres |
| Dispatcher | Oui, minimal : le journal et les notifications sont des écouteurs ; les extensions peuvent aussi s’y brancher |
| Écouteurs | Critiques par défaut : un écouteur qui échoue annule la transaction ; `critical: false` possible pour un effet accessoire |
| Vue / traité | `seen_date` pour « vu », `handled_date` pour « traité ». Bouton « Tout marquer comme traité » |
| Acteur | Inclus ou non selon la définition de l’événement ; l’utilisateur peut choisir « mes actions » / « actions des autres » pour les canaux externes |
| Admin | Page dédiée « Journal » ; `SYS_EVENT_READ` **par Space**, ajoutée au rôle Root par migration, pas de relance |
| Connexions | Telegram : bot d’installation (jeton chiffré dans `plg_core` du Space racine) ou jeton utilisateur, les deux en option |
| Classes de notification | Transport et canaux déplacés dans `plg_core` ; déclarations d’actions conservées dans `plg_automation` |
| Permission d’un canal | Un type de canal peut déclarer une permission ; elle est vérifiée quand l’utilisateur configure ses préférences, sur son Space principal. Aucun contrôle à l’émission ni à la livraison |
| Droit retiré | Une préférence ou une connexion déjà enregistrée reste active tant que l’utilisateur n’ouvre pas ses préférences. À l’ouverture, les canaux devenus interdits sont supprimés en base |

## Frontières avec l’existant

### Ce qu’il ne faut pas recréer

| Mécanisme existant | Rôle actuel | Décision |
| --- | --- | --- |
| [`automation_events`](../../IA/CurrentWorks/AUTOMATION.md) et `AutomationEventBuffer` | Outbox des déclencheurs d’automatisation, drainée après le commit | Ne pas l’utiliser comme journal métier. Aucun pont : les workflows appellent l’émetteur |
| `AutomationNotificationSender` | Alertes d’échec et de scénario, canaux par Space, envoi synchrone | Le conserver pour l’exploitation ; ne pas migrer ses configurations |
| [`mail_logs`](../../MAIL.md) et `MailHelper` | Envoi SMTP, modèles, habillage, journal des tentatives | Réutiliser pour le canal email |
| [File de jobs](../../JOBS.md) | Exécution différée, priorité, worker unique | Toute livraison externe passe par un job |
| [ACL et rôles](../../ROLES.md) | Permissions, rôles, droits de type et d’instance, héritage | S’appuyer dessus ; ajouter les helpers inverses |
| [Menus](../../ARCHITECTURE.md) | Types de menu et routes | Ne pas créer de type de menu « notifications » |
| [RiaView](../../CONTROLLERS.md) et Vue | Pages RIA et composants globaux | La cloche est un composant global monté par un module JS, pas une page |
| [`CryptHelper`](../../CRYPTO.md) | Chiffrement AES-GCM | Chiffrer les secrets des connexions |
| [Journaux applicatifs](../../LOGGING.md) | Diagnostic technique | Le journal d’événements est un historique métier |

### Trois historiques différents

- **Journaux applicatifs** : diagnostic, erreurs, décisions techniques.
- **`automation_events`** : déclencheurs de scénarios, avec leur statut propre.
- **`event_log`** : historique métier durable, source des notifications.

L’événement métier peut alimenter `event_log` et, si un workflow d’automatisation
le décide, appeler l’outbox d’automatisation. Il n’y a pas de synchronisation
automatique entre les deux.

## Architecture

```text
WF / service métier
      │  emit() dans la transaction
      ▼
EventEmitter
      │
      ▼
EventDispatcher
      ├── EventJournalListener     (priorité 10, critique)
      ├── NotificationListener     (priorité 20, critique)
      └── écouteurs d’extensions   (priorité 50, critiques par défaut)
                                      │
                                      ▼
                              notifications + livraisons
                                      │
                                      ▼
                              jobs (priorité basse)
                                      │
                                      ▼
                              Job worker existant
```

### Émission

Un workflow appelle `EventEmitter::emit()` après ses écritures métier et avant
le commit. L’émetteur :

1. résout la définition dans le registre ;
2. construit un objet `Event` (type, Space, acteur, données, date) ;
3. passe l’événement au dispatcher ;
4. laisse les écouteurs écrire le journal, créer les notifications et enfiler
   les livraisons.

Tous les écouteurs sont **critiques par défaut** : si l’un d’eux échoue,
l’exception remonte et la transaction métier est annulée. C’est le contrat
demandé : le journal doit produire une trace fiable, et un écouteur qui s’est
branché sur un événement est nécessaire à la bonne exécution de l’extension.

Le `EventJournalListener` et le `NotificationListener` sont critiques : les
lignes de journal, les notifications, les livraisons et les jobs sont écrits
dans la même transaction que le métier. Si cette écriture échoue, le métier
échoue aussi.

Une définition d’écouteur peut porter `critical: false` pour un effet
strictement accessoire. Ce n’est pas le défaut, et aucun écouteur Core ne
l’utilise.

L’envoi externe est asynchrone : une fois le job enfilé, un échec SMTP ou HTTP
ne remonte pas à la transaction métier. Il est enregistré dans
`notification_deliveries` et dans les journaux, sans rejeu.

### Dispatcher et écouteurs

Le dispatcher est volontairement minimal :

- exécution synchrone, dans la transaction de l’appelant ;
- ordre par priorité croissante ;
- liste explicite des types écoutés, pas de joker ;
- profondeur maximale de 8 pour les événements émis par un écouteur ;
- aucune écoute asynchrone : un écouteur qui veut un effet différé enfile un
  job.

La profondeur ne compte pas le nombre d’écouteurs. Elle compte la **longueur
de la chaîne** : un écouteur qui émet un événement crée un enfant de profondeur
+1. Si A déclenche B, B déclenche C, et ainsi de suite, on s’arrête au huitième
niveau. Cela évite qu’un écouteur qui réémet le même type boucle indéfiniment.
Un enchaînement métier normal utilise rarement plus de deux ou trois niveaux.
Au-delà, l’émission enfant est refusée et `EVENT_DEPTH_EXCEEDED` est journalisé.

Contrats proposés :

```php
interface IEventProvider
{
    public function coreOnRegisterEventTypes(EventDefinitionRegistry $registry): void;
}

interface IEventListenerProvider
{
    public function coreOnRegisterEventListeners(EventListenerRegistry $registry): void;
}

interface IEventListener
{
    public function handle(Event $event): void;
}
```

`EventListenerDefinition` porte : identifiant, types écoutés, classe du
listener, priorité. Le registre refuse un doublon ou un type d’événement
inconnu après l’enregistrement de toutes les définitions.

Écouteurs Core du premier lot :

| Écouteur | Priorité | Critique | Rôle |
| --- | --- | --- | --- |
| `EventJournalListener` | 10 | Oui | Écrit `event_log`, affecte `Event::$logId` |
| `NotificationListener` | 20 | Oui | Crée les notifications, livraisons et jobs |
| Écouteurs d’extensions | 50 | Oui (défaut) | Déclenchent du code métier ; `critical: false` possible |

Le listener de notification ne fait rien si `Event::$logId` est `null`. Une
définition `notifiable = true` exige donc `log = true` ; c’est validé à
l’enregistrement.

Un événement `log = false` reste dispatché aux écouteurs d’extensions. C’est le
cas d’usage « dispatcher » demandé : les extensions peuvent réagir sans écrire
au journal. Il ne produit ni notification ni livraison.

### Pas de pont Automation

Il n’y a pas de `DomainEventSink`, pas de synchronisation automatique avec
`automation_events`, pas de règle « si ça vient de l’automatisation, on ne
notifie pas ». Les actions d’automatisation qui créent un compte, attribuent un
rôle ou publient une page appellent les mêmes workflows que l’interface. Ces
workflows émettent l’événement. Le comportement est identique.

## Déclarations d’événements

### Registre

Les définitions vivent dans le code, comme les types de jobs ou les types de
composants de page. Une table de définitions serait une duplication.

- interface `Core\ExtensionApi\Event\IEventProvider` ;
- méthode `coreOnRegisterEventTypes(EventDefinitionRegistry $registry)` ;
- enregistrement pendant `onPrepareExtensions` ;
- propriétaire réel, refus des doublons ;
- validation à l’enregistrement.

### Identifiant

Format retenu :

```text
^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,4}$
```

Exemples : `core.user.role.assigned`, `core.page.published`,
`catalog.entry.published`.

Le premier segment est un **namespace** déclaré par l’extension, pas
l’identifiant technique `plg_core`. L’identifiant est stable : le renommer casse
les préférences et l’historique.

### Métadonnées

| Champ | Rôle |
| --- | --- |
| `id` | Identifiant stable |
| `label` | Libellé lisible |
| `description` | Explication du déclenchement |
| `category` | `user` ou `technical` |
| `severity` | `info`, `warning`, `error` |
| `log` | Écriture dans `event_log` |
| `notifiable` | Autorise les notifications ; exige `log = true` |
| `notifyActor` | Inclut ou non l’acteur dans l’audience |
| `defaultChannels` | Canaux proposés par défaut, UI seulement au départ |
| `title` | Titre commun |
| `bodySelf` | Corps pour l’utilisateur qui a fait l’action |
| `bodyOthers` | Corps pour les autres destinataires |
| `link` | Callable qui retourne un chemin interne, ou `null` |

Pas de `payloadFields`. Les données sont un tableau PHP écrit en dur dans le
workflow. L’émetteur vérifie seulement que les données forment une map bornée
et ne contiennent pas de clé manifestement sensible.

`notifiable = true` exige `log = true`, `title`, `bodySelf`, `bodyOthers` et
`link`. Une définition incomplète échoue au démarrage.

`log` vaut `true` par défaut. `notifiable` vaut `false` par défaut.
`notifyActor` vaut `true` par défaut : l’utilisateur doit retrouver la trace de
ses propres actions dans l’interface.

### Textes self / others

Le titre est commun. Le corps dépend du destinataire :

```php
bodySelf:   'Vous avez attribué le rôle {role.name} à {user.name}.'
bodyOthers: '{actor.name} a attribué le rôle {role.name} à {user.name}.'
```

Le rendu est fait à l’émission, avec les valeurs de `Event::$data`. Les
variables manquantes deviennent une chaîne vide ; il n’y a pas de validation
de schéma. La notification stocke le corps déjà rendu.

### Lien

Le lien est calculé par un callable de la définition, au moment de l’émission,
quand le routeur est disponible :

```php
link: fn (Event $event): ?string => $this->url(
    'core_user_edit',
    ['id' => $event->data['user']['id']],
)
```

Le résultat est un chemin relatif validé, stocké dans `notifications.url_path`.
Il ne doit contenir ni schéma, ni hôte, ni `//`, ni antislash, ni caractère de
contrôle.

Ce calcul ne peut pas être fait dans le job : l’Application d’un job est
initialisée sans routes. Le chemin est donc figé avant l’enfilement.

## Journal d’événements

### Contenu

Une ligne `event_log` décrit une occurrence :

- Space réel ;
- type d’événement ;
- acteur et type d’acteur ;
- objet concerné, facultatif ;
- charge utile JSON ;
- date UTC.

La charge utile contient des références et des libellés courts. Elle ne doit
pas contenir de mot de passe, jeton, cookie, corps d’email, réponse distante,
objet PHP ou structure récursive. Une garde applicative borne la taille et la
profondeur.

### Table `event_log`

```sql
CREATE TABLE `event_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `space_id` bigint unsigned NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `category` varchar(16) NOT NULL DEFAULT 'user',
  `severity` varchar(16) NOT NULL DEFAULT 'info',
  `actor_id` bigint unsigned NULL DEFAULT NULL,
  `actor_type` varchar(16) NOT NULL DEFAULT 'user',
  `subject_type` varchar(100) NULL DEFAULT NULL,
  `subject_id` bigint unsigned NULL DEFAULT NULL,
  `data` json NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),

  PRIMARY KEY (`id`),
  KEY `idx_event_log_space_created` (`space_id`, `created_at`),
  KEY `idx_event_log_space_type_created` (`space_id`, `event_type`, `created_at`),
  KEY `idx_event_log_actor_created` (`actor_id`, `created_at`),
  KEY `idx_event_log_created` (`created_at`),

  CONSTRAINT `fk_event_log_space` FOREIGN KEY (`space_id`)
    REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_event_log_actor` FOREIGN KEY (`actor_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Pas de `dedup_key`, pas de clé unique d’occurrence. Un double appel de workflow
produit deux événements ; c’est accepté.

## Notifications

### Une notification par destinataire

Une occurrence peut produire plusieurs notifications, une par destinataire.
La notification porte :

- `event_id` (facultatif après purge du journal) ;
- `user_id`, `space_id` ;
- type, catégorie, sévérité ;
- titre, corps rendu, chemin interne ;
- `seen_date`, `handled_date` ;
- date de création.

### Nommage des états

La spec utilisait `view_date` / `done_date`. Noms retenus :

- **`seen_date`** : la notification a été affichée dans le panneau. `NULL` =
  pas encore vue.
- **`handled_date`** : l’utilisateur a cliqué sur la notification ou a utilisé
  « tout marquer comme traité ». `NULL` = pas encore traitée.

`handled_date` est plus précis que `done_date` : la notification n’est pas une
tâche « terminée », elle est prise en compte.

### Table `notifications`

```sql
CREATE TABLE `notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` bigint unsigned NULL DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `space_id` bigint unsigned NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `category` varchar(16) NOT NULL DEFAULT 'user',
  `severity` varchar(16) NOT NULL DEFAULT 'info',
  `title` varchar(255) NOT NULL,
  `body` text NULL,
  `url_path` varchar(500) NULL,
  `seen_date` datetime NULL DEFAULT NULL,
  `handled_date` datetime NULL DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),

  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_notifications_event_user` (`event_id`, `user_id`),
  KEY `idx_notifications_user_created` (`user_id`, `created_at`),
  KEY `idx_notifications_user_unseen` (`user_id`, `seen_date`, `created_at`),
  KEY `idx_notifications_user_unhandled` (`user_id`, `handled_date`, `created_at`),
  KEY `idx_notifications_space_created` (`space_id`, `created_at`),
  KEY `idx_notifications_created` (`created_at`),

  CONSTRAINT `fk_notifications_event` FOREIGN KEY (`event_id`)
    REFERENCES `event_log` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_notifications_space` FOREIGN KEY (`space_id`)
    REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Notes :

- `UNIQUE (event_id, user_id)` évite les doublons quand plusieurs règles
  d’audience se recoupent. Ce n’est pas un mécanisme de rejeu.
- `event_id` passe à `NULL` lors de la purge du journal : la notification garde
  son contenu.
- `space_id` est dénormalisé pour la purge par Space et les filtres
  d’administration.
- Le corps est du texte, jamais du HTML brut.

### Affichage et actions

- À l’ouverture du panneau, les notifications affichées passent à
  `seen_date = now()`.
- Un clic sur une notification, ou le bouton « Tout marquer comme traité »,
  renseigne `handled_date`. `seen_date` est renseigné aussi si nécessaire.
- Le badge de la cloche compte les `seen_date IS NULL`.
- Un second indicateur peut compter les `handled_date IS NULL` ; il ne doit pas
  être confondu avec le badge.
- Les boutons sont nommés « Tout marquer comme traité », pas « Tout marquer
  comme lu », puisque l’action renseigne `handled_date`.

## Livraisons

### Table `notification_deliveries`

C’est le journal des envois externes. La spec l’appelait
`notifications_channels` ; le nom est ambigu, car il mélange configuration et
historique. Le schéma sépare les deux.

```sql
CREATE TABLE `notification_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `notification_id` bigint unsigned NOT NULL,
  `space_id` bigint unsigned NOT NULL,
  `channel` varchar(32) NOT NULL,
  `channel_id` bigint unsigned NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `attempts` smallint unsigned NOT NULL DEFAULT 0,
  `job_id` bigint unsigned NULL DEFAULT NULL,
  `data` json NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime NULL DEFAULT NULL,
  `finished_at` datetime NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_deliveries_notification_channel`
    (`notification_id`, `channel`, `channel_id`),
  KEY `idx_deliveries_status_created` (`status`, `created_at`),
  KEY `idx_deliveries_channel_status` (`channel`, `status`, `created_at`),
  KEY `idx_deliveries_space_created` (`space_id`, `created_at`),
  KEY `idx_deliveries_job` (`job_id`),

  CONSTRAINT `fk_deliveries_notification` FOREIGN KEY (`notification_id`)
    REFERENCES `notifications` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_deliveries_space` FOREIGN KEY (`space_id`)
    REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_deliveries_job` FOREIGN KEY (`job_id`)
    REFERENCES `jobs` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Notes :

- `channel_id = 0` désigne un canal intégré, aujourd’hui l’email. Une valeur
  positive référence `user_notification_channels.id`, sans clé étrangère :
  une livraison est un historique, elle doit survivre à la suppression de la
  connexion.
- `space_id` est dénormalisé pour la purge et le monitoring par Space.
- `attempts` est conservée pour le diagnostic. Aucun système de rejeu ne
  l’utilise.
- `data` contient un code, un `http_status`, un identifiant de message ou
  `mail_log_id`. Jamais de secret, de webhook, de corps de réponse ou d’adresse
  complète.
- Statuts : `pending`, `processing`, `sent`, `failed`, `skipped`.

### Pas de relance

Décision actée : un échec est un échec. Il n’y a :

- ni bouton « Relancer » dans l’administration ;
- ni ré-enfilement automatique d’une livraison échouée ;
- ni nouvelle tentative métier après un échec.

Le job de livraison est déclaré avec **un seul essai**
(`JOB_MAX_ATTEMPTS = 1`). La colonne `attempts` et le statut `failed` restent
pour le diagnostic.

La file de jobs peut néanmoins rejouer un job après un crash de worker, car
elle livre au moins une fois. Le handler doit donc rester idempotent : une
livraison déjà `sent` ne renvoie pas.

## Connexions utilisateur

### Table `user_notification_channels`

```sql
CREATE TABLE `user_notification_channels` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `provider` varchar(32) NOT NULL,
  `label` varchar(100) NOT NULL,
  `config` json NOT NULL,
  `secret` text NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `verified_at` datetime NULL DEFAULT NULL,
  `last_error_code` varchar(50) NULL DEFAULT NULL,
  `last_error_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_channels_label` (`user_id`, `provider`, `label`),
  KEY `idx_user_channels_user` (`user_id`, `enabled`),

  CONSTRAINT `fk_user_channels_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Le secret est chiffré avec `CryptHelper`, usage stable
`notification:channel:v1`. L’API ne renvoie jamais ce champ.

### Telegram : deux modes

Le mode est porté par `config.auth_mode` :

| Mode | Contenu |
| --- | --- |
| `site` | Bot configuré au niveau de l’installation ou du Space racine. La connexion utilisateur ne porte que `chat_id` |
| `user` | L’utilisateur fournit son propre jeton de bot, chiffré dans `secret`, et son `chat_id` |

Le mode `site` est le mode recommandé. Le jeton du bot de site est un réglage
d’installation stocké **chiffré** dans la configuration de `plg_core` du Space
racine (Space 1), sous un champ du type `notification_telegram_bot_token`. Il
est lu par le service de notification quel que soit le Space de l’événement.
Les autres Spaces ne peuvent ni le lire ni le modifier ; le champ n’est rendu
que dans la configuration du Space racine, masqué, et n’est jamais renvoyé par
l’API.

Le chiffrement utilise `CryptHelper` avec un usage stable, par exemple
`notification:telegram:site:v1`. Un changement de `Configuration.secret` rend
le jeton illisible : le canal passe alors en erreur `secret_invalid` et
l’administrateur doit le ressaisir.

Le mode `user` reste possible pour les installations qui le souhaitent :
l’utilisateur fournit son propre jeton de bot, chiffré dans `secret`, et son
`chat_id`. Il ne doit jamais être stocké dans la configuration du site.

Si un jour plusieurs bots par Space sont nécessaires, il faudra introduire une
table de fournisseurs par Space ; ce n’est pas le modèle retenu aujourd’hui.

### Slack et Discord

Le webhook entrant est un secret personnel : il reste dans `secret`. Les URL
sont limitées aux hôtes officiels, sans redirection, avec timeout et taille de
réponse bornée.

## Préférences

### Table `notification_preferences`

```sql
CREATE TABLE `notification_preferences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `channel` varchar(32) NOT NULL,
  `channel_id` bigint unsigned NOT NULL DEFAULT 0,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `include_self` tinyint(1) NOT NULL DEFAULT 0,
  `include_others` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_notification_preferences` (`user_id`, `event_type`, `channel`),
  KEY `idx_notification_preferences_event` (`event_type`, `channel`),
  KEY `idx_notification_preferences_channel` (`channel_id`),

  CONSTRAINT `fk_notification_preferences_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Sémantique :

- aucune ligne : valeurs par défaut de la définition ;
- `enabled = 0` : canal désactivé pour cet événement ;
- `include_self` : recevoir les notifications de ses propres actions ;
- `include_others` : recevoir les notifications des actions des autres ;
- UI : pas de préférence, l’utilisateur doit garder la trace de ses actions si
  la définition autorise l’acteur ;
- email et canaux externes : `include_self = 0` et `include_others = 1` par
  défaut ;
- `notifyActor = false` dans la définition retire l’acteur de l’audience avant
  même d’appliquer les préférences.

### Permission du canal et purge à l’ouverture

Un canal peut déclarer une permission (voir
[Extensibilité des canaux](#extensibilité-des-canaux)). Elle limite l’usage du
canal à certains comptes.

Règles :

- la permission est vérifiée au moment où l’utilisateur **configure** ses
  préférences, avec son **Space principal** (`users.space_id`) ;
- elle n’est pas vérifiée à l’émission de l’événement ni à la livraison : un
  réglage déjà enregistré continue de fonctionner si le droit est retiré ;
- à l’ouverture des préférences, chaque canal auquel l’utilisateur n’a plus
  droit est **supprimé en base** : lignes `notification_preferences` du canal et
  connexions `user_notification_channels` du fournisseur. C’est un nettoyage de
  sécurité, pas un simple masquage ;
- la suppression fait partie du chargement des préférences : si elle échoue, la
  requête échoue plutôt que d’afficher un canal qui devrait avoir disparu ;
- le code journalise `NOTIFICATION_CHANNEL_PURGED` avec l’identifiant du compte,
  le canal et le nombre de lignes supprimées, jamais le secret ;
- une tentative d’enregistrement d’une préférence ou d’une connexion sur un
  canal interdit est refusée avec `NOTIFICATION_CHANNEL_FORBIDDEN`.

Les canaux intégrés `ui`, `email`, Telegram, Slack et Discord ont
`permission = null` : tout le monde peut les utiliser. `ui` et `email` ne sont
pas réglables dans les préférences ; les trois canaux externes le sont, sans
restriction de permission.

Une permission déclarée n’est pas vérifiée contre le catalogue ACL à
l’enregistrement du canal. Une faute de frappe rend simplement le canal
inutilisable pour tous les comptes ; c’est assumé et documenté dans le guide.

Pas de table `notification_settings`, pas d’interrupteur global, pas de données
dans `users.params` pour l’instant.

## Résolution des destinataires

L’audience est résolue par le **workflow émetteur**, pas par le noyau.
`EventEmitter::emit()` reçoit la liste des `user_id` destinataires déjà calculée :
liste directe fournie par le métier, ou résultat de `AclUserResolver` pour un
droit ou un rôle. L’événement porte cette liste ; le `NotificationListener` la
filtre ensuite (comptes bloqués, comptes de service, acteur selon `notifyActor`,
préférences).

La définition d’événement ne porte donc aucune règle d’audience. Ce choix garde
les workflows explicites et évite un second langage de sélection dans les
définitions.

### Méthodes

| Méthode | Signification |
| --- | --- |
| Liste d’IDs | Utilisateurs explicitement désignés par l’émetteur |
| Permission globale + Space | Droit effectif sur ce Space ou un ancêtre |
| Permission sur objet | Droit effectif : global, type d’objet ou instance |
| Rôle + Space | Rôle effectif sur ce Space ou un ancêtre |
| Rôle local sur objet | Rôle effectif sur le type ou l’instance |

L’audience est toujours le **droit effectif**. Pas de mode « exact ».

Une liste d’IDs fournie par l’émetteur est acceptée telle quelle : on fait
confiance aux workflows et extensions, qui sont du code de l’installation. On
ne l’intersecte pas avec `MEMBER`. Un ROOT d’un Space parent doit pouvoir
recevoir la notification. Les comptes bloqués et les comptes de service sont
tout de même écartés.

### Helpers inverses

Il n’existe pas aujourd’hui de méthode `AclHelper` pour énumérer les
utilisateurs. `getSpacesHavingGlobalPermission()` raisonne sur la session, pas
sur tous les comptes. Il faut un helper dédié, par exemple
`Core\Helper\Acl\AclUserResolver`, avec :

- `usersWithGlobalPermission(string $permission, int $spaceId): array`
- `usersWithPermissionOnObject(string $permission, string $extension, string $object, int $objectId, int $spaceId): array`
- `usersWithGlobalRole(int $roleId, int $spaceId): array`
- `usersWithRoleOnObject(int $roleId, string $extension, string $object, int $objectId, int $spaceId): array`

### Algorithme SQL + PHP

L’objectif est d’éviter une boucle « un utilisateur = un contrôle ACL ». Le
calcul se fait en quatre étapes, avec des ensembles en PHP entre les requêtes.

1. **Espaces** : charger l’espace et ses ancêtres une fois par requête
   (`SpaceHelper::getSpaceIdsWithParents`).
2. **Rôles** : pour une permission, lire les `role_id` dans `acl_permissions`
   (`extension`, `name`). Pour un rôle, partir directement de son ID.
3. **Groupes** : lire les `group_id` dans `acl_map` pour ces rôles, les espaces
   ancêtres et, si besoin, le type/objet. Pour un droit sur objet, réunir les
   affectations globales, celles du type (`object_id IS NULL`) et celles de
   l’instance : `AclHelper::hasPermissionOn()` fait de même. Utiliser les index
   existants.
4. **Utilisateurs** : charger une fois l’arbre des groupes
   (`id => parent_id`), calculer en PHP la fermeture descendante des groupes
   bénéficiaires (un rôle sur un groupe parent profite aux enfants), puis lire
   `user_groups_map` par lots de `group_id` avec `FormatIn`.

Filtres finaux : comptes bloqués, comptes de service, déduplication, acteur
selon `notifyActor`, préférences.

Points d’optimisation :

- cacher l’arbre des groupes et les ancêtres de Space pour la requête ;
- cacher le résultat par `(méthode, paramètres, spaceId)` ;
- utiliser `array<int, true>` pour les ensembles ;
- borner et découper les `IN` (par exemple 1 000 IDs) ;
- ne jamais charger tous les utilisateurs pour tester leurs droits un par un.

### Index à examiner

`acl_map` possède déjà onze index. Il ne faut pas en ajouter sans `EXPLAIN`.

| Requête | Index existant |
| --- | --- |
| Permission globale | `acl_map.idx_acl_map4 (space_id, acl_object_id, role_id, group_id)` |
| Permission sur type ou instance | `acl_map.idx_acl_map3 (space_id, acl_object_id, object_id, role_id, group_id)` |
| Rôle + Space | `acl_map.idx_acl_map1 (space_id, role_id, group_id, acl_object_id, object_id)` |
| Permission → rôle | `acl_permissions.idx_acl_permissions1 (extension, name)` |
| Descente des groupes | `user_groups.idx_user_groups_parent1 (parent_id, type, name)` |
| Appartenance | `user_groups_map` unique `(group_id, user_id)` |

Candidats à tester pendant l’implémentation, uniquement si `EXPLAIN` montre un
scan ou un tri coûteux :

- `acl_map (role_id, space_id, acl_object_id, object_id, group_id)` pour le
  chemin inverse permission → rôle → affectation ;
- `acl_permissions (role_id, extension, name)` pour le chemin rôle →
  permissions ;
- un index de couverture sur `user_groups_map (group_id, user_id)` s’il n’est
  pas déjà optimal (l’unique actuel le couvre).

Si l’arbre des groupes devient un goulot d’étranglement, la solution scalable
est une table de fermeture `user_group_ancestors`. Ce n’est pas nécessaire au
premier lot.

### Plafond

`configuration.php` porte :

```php
public int $notification_max_recipients = 2000;
```

Si l’audience dépasse le plafond :

- l’événement est écrit au journal (il est critique) ;
- aucun fan-out de notification n’est effectué ;
- un log technique `NOTIFICATION_AUDIENCE_TOO_LARGE` est écrit ;
- aucun destinataire n’est tronqué silencieusement.

## Indexation

### Boîte de réception

Requêtes principales :

1. liste complète par utilisateur, triée par date ;
2. non-vues (`seen_date IS NULL`) ;
3. compteur de non-vues ;
4. non-traitées (`handled_date IS NULL`) ;
5. marquage groupé ;
6. purge par Space et par date.

Index :

| Index | Usage |
| --- | --- |
| `(user_id, created_at)` | liste complète |
| `(user_id, seen_date, created_at)` | non-vues, compteur, marquage |
| `(user_id, handled_date, created_at)` | non-traitées, marquage |
| `(space_id, created_at)` | purge et administration |
| `(created_at)` | purge globale éventuelle |

`seen_date IS NULL` et `handled_date IS NULL` sont des intervalles d’index
utilisables. Le compteur peut être index-only. Pas d’index sur `title` ou
`body`.

Pagination par keyset :

```sql
WHERE user_id = ?
  AND (created_at < ? OR (created_at = ? AND id < ?))
ORDER BY created_at DESC, id DESC
LIMIT 20
```

### Journal

| Index | Usage |
| --- | --- |
| `(space_id, created_at)` | liste d’un Space |
| `(space_id, event_type, created_at)` | filtre type + date |
| `(actor_id, created_at)` | activité d’un compte |
| `(created_at)` | purge |

Pas d’index sur `data`. Pas d’index sur `subject_type`/`subject_id` au premier
lot.

### Livraisons

| Index | Usage |
| --- | --- |
| unique `(notification_id, channel, channel_id)` | lookup et idempotence |
| `(status, created_at)` | diagnostic |
| `(channel, status, created_at)` | monitoring par canal |
| `(space_id, created_at)` | purge et administration |
| `(job_id)` | clé étrangère |

Pas d’index sur `data`.

## Traitement asynchrone

### Job de livraison

Un seul type :

```php
final class NotificationDeliveryJobHandler extends BaseJobHandler
{
    public const JOB_TYPE = 'core.notification_delivery';
    public const JOB_LABEL = 'Livraison d’une notification';
    public const JOB_MIN_TIME = 2;
    public const JOB_MAX_ATTEMPTS = 1;
    public const JOB_PRIORITY = JobType::PRIORITY_LOW;
}
```

La priorité basse place les emails de notification derrière les traitements
plus urgents. L’enfilement peut aussi passer `priority` dans les options de
`JobRequest`, mais la constante du handler est la valeur par défaut du type.

La charge utile ne contient que `delivery_id`. Le handler recharge la
livraison, la notification, l’utilisateur et la connexion.

### Déroulé

1. Charger la livraison ; si elle est déjà `sent`, retourner un succès.
2. Vérifier la notification, l’utilisateur, la préférence et la connexion.
3. Réclamer la livraison (`pending` → `processing`).
4. Envoyer :
   - email : `MailHelper::sendTemplate('plg_core__notification', ...)` avec
     `jobId` et `userId` pour `mail_logs` ;
   - Telegram, Slack, Discord : transport Core, timeout, pas de redirection.
5. Marquer `sent`, `skipped` ou `failed`.
6. Un échec est terminal : pas de nouvelle tentative.

Le handler reste idempotent pour le rejeu accidentel de la file après un crash.

### Enfilement

`JobQueue::enqueue()` écrit dans la transaction de l’appelant. Un métier annulé
ne laisse aucun job orphelin. Si l’enfilement échoue, la livraison est marquée
`failed` avec `enqueue_failed` ; l’événement et la notification restent. Il n’y
a pas de relance.

### Débit

Le worker est unique. Un envoi SMTP réel prend souvent 1 à 3 secondes, donc
environ 10 à 20 emails par minute. La priorité basse évite de bloquer les
traitements urgents, mais ne résout pas le débit.

Conséquences :

- pas d’email par défaut pour les événements informatifs ;
- plafond de 2 000 destinataires ;
- pas de promesse d’envoi immédiat ;
- surveillance de la file dans l’onglet Jobs.

## Interface

### Cloche et panneau

Pas de page dédiée, pas de type de menu. La cloche est un composant global
monté dans la page, comme `mountSecureVideos` monte `<sec_video>`.

Fonctionnement :

- la template affiche un emplacement de cloche si l’option est active et si
  l’utilisateur est connecté ;
- un module JS `mountNotificationBell()` monte un petit composant Vue dans cet
  emplacement, avec le contexte applicatif, le CSRF et le store de
  notifications ;
- la cloche affiche un badge de non-vues ;
- le panneau affiche toutes les notifications de l’utilisateur, tous Spaces
  confondus, avec un badge indiquant le Space de chaque ligne ;
- un clic ouvre une modale/panneau avec la liste ;
- à l’ouverture, les notifications affichées passent à `seen_date` ;
- un clic sur une notification, ou « Tout marquer comme traité », renseigne
  `handled_date`.

Le composant doit fonctionner sur les pages sans racine Vue applicative : c’est
un montage autonome, pas un composant du page component principal.

### Options de template

Ajouter dans la configuration de MainTemplate :

| Champ | Type | Défaut | Rôle |
| --- | --- | --- | --- |
| `notification_bell_enabled` | `core.switch` | `true` | Afficher la cloche |
| `notification_bell_icon` | `core.icon-picker` | `fa-solid fa-bell` | Icône de la cloche |

La version du formulaire de configuration de la template est incrémentée. Les
Spaces existants reçoivent la cloche activée par défaut après la mise à jour ;
l’administrateur peut la masquer dans la configuration de la template.

### Préférences utilisateur

Nouveau composant de l’éditeur de page, distinct de « Mon compte » :

- classe `Core\PageComponents\NotificationPreferences` ;
- identifiant `core.notification-preferences` ;
- libellé « Préférences de notifications », catégorie « Compte » ;
- composant public Vue `core/notification-preferences` ;
- rendu : pour un visiteur non connecté, rien ou invitation à se connecter ;
- contenu : matrice événements × canaux, CRUD des connexions Telegram, Slack et
  Discord, cases « mes actions » / « actions des autres », test d’envoi.

Le composant est inséré dans une page par l’éditeur. Il n’est pas ajouté à
« Mon compte ».

### Administration

Pour rester simple, la consultation est une **page d’administration dédiée
« Journal »**, et non un nouvel onglet du shell Système. Le shell Système reste
réservé à ROOT et n’est pas modifié.

La page contient deux onglets :

- **Événements** : liste filtrée par Space, type, catégorie, acteur et date ;
  détail en lecture seule ; catalogue des définitions.
- **Livraisons** : liste filtrée par Space, canal, statut et date ; détail des
  erreurs ; aucune relance.

Permission : `SYS_EVENT_READ` **sur le Space concerné**. Elle couvre la
consultation du journal, le détail d’un événement et le monitoring des
livraisons de ce Space. L’héritage normal des Spaces s’applique : la permission
accordée sur un Space parent vaut pour ses descendants. L’utilisateur ne voit
que les Spaces où il possède effectivement cette permission.

Le détail ne recharge pas l’objet référencé pour un contrôle ACL
supplémentaire : `SYS_EVENT_READ` fait foi, conformément à la décision.

`SYS_EVENT_READ` est réservée à ROOT. Une migration de données l’ajoute au rôle
Root système ; elle n’est pas `RoleCrudManageable` et aucun autre rôle n’est
modifié automatiquement. C’est une permission de consultation sensible, car
elle donne accès aux charges utiles du journal.

La page est accessible depuis le menu d’administration si l’utilisateur possède
`SYS_EVENT_READ` sur au moins un Space. Aujourd’hui, la migration ne l’accorde
qu’au rôle Root : la page est donc en pratique réservée à ROOT. Les API et les
filtres sont néanmoins écrits par Space, pour rester corrects si l’attribution
évolue. Le WF vérifie la permission sur le Space demandé. Aucun onglet ROOT du
shell Système n’est exposé par cette page.

Ce lot ne change pas le schéma.

## Sécurité et vie privée

- Les notifications personnelles sont lues avec le `user_id` de la session,
  jamais avec un identifiant fourni par le client.
- Chaque mutation vérifie l’appartenance de la notification à l’utilisateur.
- Les secrets des connexions sont chiffrés et jamais sérialisés.
- Les adresses email complètes ne sont pas recopiées dans les livraisons :
  `mail_logs` les porte déjà.
- La suppression d’un utilisateur supprime ses notifications, préférences et
  connexions ; son `actor_id` devient `NULL` dans le journal.
- Le titre et le corps sont échappés dans l’UI et dans l’email.
- Le sujet d’email est mono-ligne, sans CR/LF.
- Le chemin interne est validé : pas d’URL absolue, pas de `//`, pas de
  caractère de contrôle.
- Les webhooks Slack et Discord sont limités à leurs hôtes officiels, sans
  redirection, avec timeout et taille de réponse bornée.
- Telegram utilise l’hôte fixe de l’API.
- Les routes POST utilisent le jeton CSRF existant.
- Les tests de canal et les écritures de préférences sont limités par un
  mécanisme de débit par utilisateur.
- Un type de canal réservé n’est proposé et enregistré que pour les comptes qui
  possèdent la permission sur leur Space principal.
- À l’ouverture des préférences, les préférences et connexions des canaux
  devenus interdits sont supprimées en base, avec un journal
  `NOTIFICATION_CHANNEL_PURGED` sans secret.
- Les émetteurs sont du code de confiance : on ne réintroduit pas de contrôle
  `MEMBER` sur une liste d’IDs. C’est un choix assumé, documenté.

### Transport HTTP partagé

Le transport HTTP et les classes d’envoi Telegram/Slack/Discord existent
aujourd’hui dans `Automation\Notification`. Core ne doit pas en dépendre :
`plg_automation` est optionnelle. Si le handler de notification Core instancie
une classe d’Automation, alors :

- la fonctionnalité Core dépend d’une extension qui peut être désactivée ;
- un job Core peut échouer dans une installation sans Automation ;
- une évolution d’Automation peut casser les notifications Core.

La solution retenue est de déplacer dans `plg_core` :

- le transport HTTP commun ;
- les classes d’envoi Telegram, Slack et Discord ;
- l’exception d’envoi partagée ;
- le registre des canaux et le handler de livraison des notifications
  utilisateur.

Emplacement proposé : `Core/Notification/` et
`Core/Notification/Transport/`. Automation dépend de Core, jamais l’inverse.

Les **déclarations d’actions** d’automatisation (`core::notify`,
`core::sendTelegram`, `core::sendSlack`, `core::sendDiscord`, etc.) restent
dans `plg_automation`. Elles appartiennent au langage de scénarios et à son
registre d’actions. Elles peuvent continuer à utiliser
`AutomationNotificationSender` pour les alertes de scénario, et celui-ci
s’appuie désormais sur le transport Core. Les notifications utilisateur
utilisent directement les primitives Core.

Ainsi :

- `plg_automation` peut être désactivée sans casser les notifications
  utilisateur ;
- les actions d’automatisation restent déclarées là où elles ont du sens ;
- il n’y a qu’un seul transport HTTP à maintenir.

### Codes de journal

- `EVENT_LOG_WRITE_FAILED`
- `EVENT_PAYLOAD_INVALID`
- `EVENT_LISTENER_FAILED`
- `EVENT_DEPTH_EXCEEDED`
- `NOTIFICATION_AUDIENCE_TOO_LARGE`
- `NOTIFICATION_FANOUT_FAILED`
- `NOTIFICATION_CHANNEL_UNAVAILABLE`
- `NOTIFICATION_CHANNEL_SECRET_INVALID`
- `NOTIFICATION_CHANNEL_FORBIDDEN`
- `NOTIFICATION_CHANNEL_PURGED`
- `NOTIFICATION_CHANNEL_PURGE_FAILED`
- `NOTIFICATION_DELIVERY_ENQUEUE_FAILED`
- `NOTIFICATION_DELIVERY_FAILED`
- `NOTIFICATION_PURGE`

Les contextes contiennent le Space, le type d’événement, le canal et un code,
jamais le contenu du message ni le secret.

## Rétention et configuration

### Configuration par Space

La rétention est configurable par Space dans la configuration de `plg_core`,
section `notifications` du formulaire `plugin.core.config` (version 2) :

| Champ | Défaut | Rôle |
| --- | --- | --- |
| `event_log_retention_days` | `0` | Jours de conservation du journal ; `0` = aucune limite |
| `notification_retention_days` | `0` | Jours de conservation des notifications ; `0` = aucune limite |
| `notification_delivery_retention_days` | `0` | Jours de conservation des livraisons ; `0` = aucune limite |

La valeur par défaut est `0` : on conserve tout. La purge ne s’exécute que si
la valeur est strictement positive.

La purge est un hook `onCronHour` du Core :

- boucle sur les Spaces ;
- lit la configuration du Space ;
- supprime par lots de 500, avec un nombre de lots borné ;
- journalise le nombre de lignes supprimées ;
- ne fait rien si la valeur vaut `0`.

Les index `(space_id, created_at)` de `event_log`, `notifications` et
`notification_deliveries` servent cette purge.

### Configuration globale

`configuration.php` porte le plafond de destinataires :

```php
public int $notification_max_recipients = 2000;
```

Il est documenté dans `configuration.sample.php` et validé à la lecture. Une
valeur absurde est ramenée à une borne basse de 1.

## Extensibilité des canaux

Les extensions peuvent déclarer des canaux, mais le handler de livraison reste
unique. Un canal déclare :

- identifiant stable ;
- libellé ;
- formulaire de configuration ;
- champs secrets ;
- validateur ;
- classe d’envoi ;
- longueur maximale de message ;
- **permission facultative** : `null` = tout le monde, sinon un nom complet de
  permission ACL (`extension__PERMISSION`).

Registre proposé : `NotificationChannelRegistry` et
`INotificationChannelProvider::coreOnRegisterNotificationChannels()`.

Le lot 0 ne fige que l’identifiant, le libellé, la longueur maximale et la
permission. Le formulaire de configuration, les champs secrets, le validateur et
la classe d’envoi seront ajoutés avec le handler de livraison (lot 3).

### Permission d’un type de canal

Un type de canal peut être réservé à des comptes disposant d’une permission :

```php
new NotificationChannelDefinition(
    id: 'webhook',
    label: 'Webhook',
    maxMessageLength: 2000,
    permission: 'plg_webhook__USE_NOTIF_WEBHOOK_CHANNEL',
);
```

Règles :

- `permission = null` : le canal est utilisable par tous les comptes ;
- la permission est un nom complet du catalogue ACL, déclaré par l’extension
  qui fournit le canal ;
- elle est vérifiée **uniquement quand l’utilisateur configure** ses préférences
  ou une connexion, sur son **Space principal** (`users.space_id`), avec
  l’héritage normal des Spaces ;
- elle n’est **pas** vérifiée à l’émission de l’événement ni au moment de la
  livraison ; un réglage existant reste actif si le droit est retiré ;
- à l’ouverture des préférences, les canaux devenus interdits sont purgés de la
  base (préférences et connexions) comme décrit dans la section Préférences ;
- le registre ne vérifie pas que la permission existe dans le catalogue ACL. Une
  permission mal orthographiée rend le canal inutilisable ; c’est un choix
  assumé pour ne pas coupler le registre au catalogue des permissions.

Canaux intégrés :

| Canal | Permission | Réglable par l’utilisateur |
| --- | --- | --- |
| `ui` | `null` | Non : toujours actif |
| `email` | `null` | Non : opt-in porté par la définition d’événement |
| `telegram` | `null` | Oui |
| `slack` | `null` | Oui |
| `discord` | `null` | Oui |

Les droits `ui` et `email` ne sont pas réglables. Telegram, Slack et Discord
sont ouverts à tous ; seuls les canaux d’extension peuvent porter une
permission. Les identifiants `ui`, `email`, `telegram`, `slack` et `discord`
sont réservés à Core : une extension qui les revendique est refusée au
démarrage.

Côté Core, le contrôle est centralisé dans
`Core\Helper\Notification\NotificationChannelAccessHelper::isAllowedForUser()`.
Il est appelé à l’enregistrement et au chargement des préférences, jamais par le
handler de livraison.

Le handler résout la classe d’envoi à l’exécution à partir du `channel` stocké.
Si le fournisseur n’est plus chargé, la livraison est marquée `failed` avec
`channel_unavailable`.

Les canaux intégrés (email, Telegram, Slack, Discord) sont déclarés par Core.
Le transport est dans Core, comme expliqué plus haut.

## Lots proposés

| Lot | Contenu | Dépendances |
| --- | --- | --- |
| 0 | Contrats PHP (événements, écouteurs, canaux avec permission), noms des états, emplacements des classes, guide `Docs/NOTIFICATIONS.md` | Aucune |
| 1 | Registre, dispatcher, `event_log`, `EventJournalListener`, ACL inverses, purge, événement `core.user.role.assigned` | Lot 0 |
| 2 | `notifications`, `NotificationListener`, préférences, cloche, panneau, composant d’éditeur de page | Lot 1 |
| 3 | `user_notification_channels`, `notification_deliveries`, job, email, Telegram/Slack/Discord | Lot 2 |
| 4 | Page admin « Journal », migration `SYS_EVENT_READ` sur le rôle Root, rétention, monitoring, documentation, tests de sécurité | Lot 3 |

Le lot 1 est le socle : journal fiable et résolution d’audience. Le lot 2 est
le plus visible. Le lot 3 est le plus risqué côté exploitation (débit, secrets,
SSRF).

### Premier événement à instrumenter

`core.user.role.assigned` et, si le changement est dans le même workflow,
`core.user.role.removed`. L’assignation de rôle est facile à tester avec le
tableau des attributions et permet de valider :

- l’écriture transactionnelle du journal ;
- l’audience par permission ou rôle ;
- les textes self/others ;
- les préférences UI/email ;
- l’absence de doublon visuel.

## Vérifications prévues

### Unitaires

- registre : identifiants invalides, doublons, propriétaire, définitions
  notifiables incomplètes ;
- dispatcher : ordre des écouteurs, profondeur, écouteur critique qui annule,
  écouteur explicitement non critique qui n’annule pas ;
- rendu : texte self/others, variables manquantes, échappement, chemin invalide,
  sujet mono-ligne ;
- préférences : self/others, absence de ligne, canal désactivé ;
- canaux : permission nulle, permission refusée, identifiant dupliqué, permission
  mal formée refusée à la construction, absence de validation contre le
  catalogue ACL.

### Intégration base

- audience : liste directe, permission globale, permission sur objet, rôle,
  rôle local, héritage Space, héritage groupe, doublons, acteur inclus/exclu ;
- utilisateurs bloqués, comptes de service ;
- transaction : annulation sans journal, notification ni job ;
- journal critique : une erreur d’écriture annule le métier ;
- notification critique : une erreur de fan-out annule le métier ;
- écouteur d’extension critique : une erreur annule le métier ;
- écouteur explicitement non critique : une erreur est journalisée sans annuler ;
- purge : `0` = aucune suppression, valeur positive, par Space, par lots ;
- migration `SYS_EVENT_READ` : ajoutée au rôle Root uniquement, autres rôles
  inchangés ;
- permission de canal : un réglage existant reste actif après retrait du droit ;
  à l’ouverture des préférences, préférences et connexions du canal interdit sont
  supprimées ;
- index : `EXPLAIN` des requêtes de boîte et d’audience.

### Job

- envoi réussi ;
- SMTP désactivé → `skipped` ;
- erreur SMTP ou HTTP → `failed`, pas de nouvelle tentative ;
- job rejoué après crash → pas de double envoi ;
- canal supprimé, secret illisible, préférence désactivée ;
- priorité basse respectée.

### Sécurité

- IDOR : un utilisateur ne peut ni lire ni modifier la notification d’un autre ;
- CSRF sur les mutations ;
- XSS dans le titre, le corps et le lien ;
- injection d’en-têtes email ;
- SSRF sur les webhooks ;
- secrets absents des réponses API et des journaux ;
- `SYS_EVENT_READ` requis sur le Space de l’événement ou de la notification, pour le journal et les livraisons ;
- page « Journal » : un utilisateur ne voit que les Spaces autorisés ; un
  non-ROOT n’accède à aucun onglet ROOT du shell Système.

## Documentation à mettre à jour

- Nouveau guide `Docs/NOTIFICATIONS.md`.
- [`Docs/JOBS.md`](../../JOBS.md) : type `core.notification_delivery`, priorité
  basse, un seul essai.
- [`Docs/ARCHITECTURE.md`](../../ARCHITECTURE.md) : modules `Core/Event` et
  `Core/Notification`, registres et dispatcher.
- [`Docs/LOGGING.md`](../../LOGGING.md) : codes du chantier.
- [`Docs/FRONTEND.md`](../../FRONTEND.md) : cloche, montage global, composant
  d’éditeur de page.
- [`Docs/ROLES.md`](../../ROLES.md) : `SYS_EVENT_READ`.
- [`version.md`](../../../version.md) : changements utiles aux utilisateurs,
  après livraison.
- [`Docs/IA/ETAT_DU_PROJET.md`](../ETAT_DU_PROJET.md) et
  [`Docs/IA/TRAVAUX_RESTANTS.md`](../TRAVAUX_RESTANTS.md) : état réel après
  livraison. Le second indique aujourd’hui que les notifications restent
  synchrones ; ce chantier déplacera les envois externes vers la file.

## Écarts et points de vigilance

- La spec disait `extension.action` ; le format retenu est 2 à 5 segments avec
  namespace, compatible avec `core.user.created`.
- La spec parlait de `notifications_channels` ; le schéma sépare
  `user_notification_channels` et `notification_deliveries`.
- La spec demandait un type de menu « Notification » ; il n’y en a pas. La
  cloche est un composant global et les préférences sont un composant de page.
- La spec demandait une relance ; elle est supprimée. Un échec est terminal.
- La spec prévoyait `payloadFields` ; il n’y en a pas.
- La documentation actuelle dit que les notifications restent synchrones ;
  ce chantier déplace les livraisons externes vers la file.
- Les notifications d’automatisation par Space restent distinctes des
  notifications utilisateur. Aucun pont n’est prévu.

## État du chantier

Le lot 0 est livré : contrats PHP (`Core/Event`, `Core/Notification`,
`Core/ExtensionApi/Event`, `Core/ExtensionApi/Notification`), contrôle de
permission des canaux, tests unitaires et guide
[NOTIFICATIONS.md](../../NOTIFICATIONS.md). Rien n’est encore branché dans
`Core\Plugin` : aucune table, aucun événement émis par un workflow, aucune
notification ni livraison.

Les décisions structurantes sont prises :

- page d’administration dédiée « Journal », sans modification du shell
  Système ;
- `SYS_EVENT_READ` par Space, ajoutée au rôle Root par migration de données ;
- bot Telegram de site dans la configuration chiffrée de `plg_core` du Space
  racine ;
- tous les écouteurs critiques par défaut, avec possibilité explicite de
  `critical: false` ;
- aucun rejeu, aucun `dedup_key`, aucune page de notifications dédiée ;
- permission facultative par type de canal, vérifiée à la configuration des
  préférences sur le Space principal, sans contrôle à la livraison, avec purge
  des canaux interdits à l’ouverture des préférences.

Le prochain travail est le lot 1 : branchement des registres dans
`Core\Plugin`, table `event_log`, `EventJournalListener`, ACL inverses, purge
et premier événement `core.user.role.assigned`.
