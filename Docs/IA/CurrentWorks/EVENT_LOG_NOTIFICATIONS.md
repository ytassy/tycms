# Chantier Journal d’événements et notifications

Conception du journal d’activité centralisé et du système de notifications de
TyCMS. Ce document fixe le périmètre, les frontières avec les mécanismes
existants, le modèle de données et les points restant à arbitrer. Il ne décrit
pas une fonctionnalité livrée : aucune table ni aucun code de production n’est
créé par ce document.

## État et objet du chantier

Objectif : permettre aux workflows métier du Core, des extensions et de
l’automatisation d’émettre des **événements de domaine** durables, puis de
notifier les utilisateurs concernés par les canaux UI, email, Telegram, Slack
et Discord.

Le chantier doit répondre à quatre besoins distincts, souvent confondus :

1. **Définir** les types d’événements, de façon déclarative, dans le code.
2. **Historiser** les occurrences dans un journal métier durable.
3. **Notifier** un ou plusieurs destinataires, avec un état personnel
   « vue / non vue » et « traitée / non traitée ».
4. **Livrer** les notifications vers des canaux externes, de façon asynchrone,
   avec tentatives et journal d’envoi.

La séparation entre ces quatre objets est la décision structurante du chantier.

## Décisions recommandées, à confirmer

Les points suivants sont les arbitrages qui changent le schéma ou le
comportement. Ils sont volontairement listés avant l’architecture.

| Sujet | Recommandation | Conséquence si l’on choisit autrement |
| --- | --- | --- |
| Format des identifiants | `namespace.entité.action`, de 2 à 5 segments (`core.page.published`) | Un format strict `extension.action` serait incompatible avec `core.user.created` et `core.page.button.clicked` de l’automatisation |
| Canaux par défaut | UI toujours ; email par défaut seulement pour les événements transactionnels ou importants ; opt-in pour le reste | Un email par défaut sur tout événement produit du bruit et sature la file |
| Telegram | Bot configuré au niveau de l’installation ou du Space racine ; l’utilisateur ne fournit que son `chat_id` | Un bot token par utilisateur est irréaliste et multiplie les secrets sensibles |
| Sémantique d’audience | Droit **effectif** : global, type d’objet, instance, héritage des Spaces et des groupes ; acteur exclu par défaut | Un mode « exact » peut être ajouté par option, mais ne doit pas être le défaut implicite |
| Boîte de réception | Boîte unique pour tous les Spaces, avec badge du Space ; cloche comptant toutes les non-lues | Une boîte par Space exige des index supplémentaires et une UX différente |
| Rétention | 90 jours pour `event_log`, 180 jours pour `notifications`, 90 jours pour `notification_deliveries`, purge horaire par lots | Une rétention trop longue fait grossir la base d’un mutualisé sans intérêt métier |

## Frontières avec l’existant

### Ce qu’il ne faut pas recréer

| Mécanisme existant | Rôle actuel | Décision pour ce chantier |
| --- | --- | --- |
| [`automation_events`](../../IA/CurrentWorks/AUTOMATION.md) et `AutomationEventBuffer` | Outbox des déclencheurs d’automatisation, drainée après le commit | Ne pas l’utiliser comme journal métier : l’extension Automation est optionnelle et sa rétention est liée aux runs |
| `AutomationNotificationSender` | Alertes d’échec et de scénario, canaux configurés par Space, envoi synchrone | Le conserver pour l’exploitation ; ne pas migrer ses configurations dans ce chantier |
| [`mail_logs`](../../MAIL.md) et `MailHelper` | Envoi SMTP, modèles, habillage, journal des tentatives | Réutiliser pour le canal email ; ne pas créer un second journal d’envoi email |
| [File de jobs](../../JOBS.md) | Exécution différée, reprise, backoff, worker unique | Toute livraison externe passe par un job ; aucun worker parallèle |
| [ACL et rôles](../../ROLES.md) | Permissions globales, rôles, droits de type et d’instance, héritage | S’appuyer dessus pour calculer les destinataires ; ne pas réinventer les règles |
| [Menus](../../ARCHITECTURE.md) | Types de menu, routes, visibilité par rôle | Le centre de notifications est un type de menu ; la cloche est un composant de header séparé |
| [RiaView](../../CONTROLLERS.md) et frontend Vue | Pages RIA montées depuis `src/ria/src/pages` | Le centre de notifications est une page RIA classique |
| [`CryptHelper`](../../CRYPTO.md) | Chiffrement AES-GCM avec usage stable | Chiffrer les secrets des connexions utilisateur |
| [Journaux applicatifs](../../LOGGING.md) | Diagnostic technique, codes stables, fichiers | Le journal d’événements est un historique métier, pas un journal de diagnostic |

### Trois historiques différents

Il faut conserver trois historiques séparés, avec des responsabilités claires :

- **Journaux applicatifs** : diagnostic, erreurs, décisions techniques. Ils
  restent dans les fichiers et suivent [`LOGGING.md`](../../LOGGING.md).
- **`automation_events`** : déclencheurs de scénarios, avec un statut de
  traitement propre à l’automatisation.
- **`event_log`** : historique métier durable, lisible par des humains, servant
  de source aux notifications et à l’écran d’administration.

Un événement métier peut alimenter les deux derniers, mais une seule fois, via
un point d’émission commun.

## Architecture

```text
WF / service métier
      │  (dans la transaction de la route)
      ▼
DomainEventEmitter
      ├── event_log                     (1 ligne par occurrence)
      ├── notifications                 (1 ligne par destinataire, UI)
      ├── notification_deliveries       (1 ligne par canal externe)
      ├── jobs                          (1 job par livraison externe)
      └── pont optionnel vers Automation (automation_events)
                                      │
                                      ▼
                              Job worker existant
                                      │
                    email / telegram / slack / discord
```

### Un seul point d’émission

Un service `Core\Event\DomainEventEmitter` est appelé par les workflows après
les écritures métier et avant le commit. Il :

1. résout la définition de l’événement dans le registre ;
2. valide la charge utile contre les champs déclarés ;
3. écrit `event_log` ;
4. résout l’audience ;
5. crée les notifications UI et les livraisons externes ;
6. enfile un job par livraison externe ;
7. signale au pont Automation, si l’extension est chargée, qu’un événement de
   domaine a été émis.

Le point d’émission reste dans la transaction du métier. Un événement émis dans
une transaction annulée ne laisse ni journal, ni notification, ni job.

### Deux régimes d’échec

| Type d’événement | Écriture du journal | Fan-out des notifications |
| --- | --- | --- |
| Métier / audit | Forte : une erreur annule le métier | Best effort : une erreur est journalisée mais n’annule pas le métier |
| Technique / diagnostic | Best effort : une erreur est journalisée | Best effort, généralement sans notification utilisateur |

Cette distinction évite qu’une panne du journal fasse échouer une publication,
tout en garantissant qu’un événement d’audit déclaré critique ne disparaisse pas
silencieusement.

### Pont avec l’automatisation

Core ne doit pas dépendre de `plg_automation`, qui est optionnelle. Le pont
recommandé est une petite interface Core, par exemple `DomainEventSink`,
implémentée par Automation et enregistrée au démarrage. Sans implémentation,
le journal et les notifications fonctionnent seuls.

À moyen terme, les `AutomationTriggerDefinition` peuvent être générées à partir
du registre d’événements Core pour éviter deux catalogues. Ce n’est pas un
prérequis du premier lot.

## Déclarations d’événements

### Registre

Les définitions vivent dans le code, comme les types de jobs, les types de menu
ou les déclencheurs d’automatisation. Une table de définitions serait une
duplication à synchroniser.

Le registre suit le modèle de `JobTypeRegistry` :

- interface `Core\ExtensionApi\Event\IEventProvider` ;
- méthode `coreOnRegisterEventTypes(EventDefinitionRegistry $registry)` ;
- enregistrement pendant `onPrepareExtensions`, une fois toutes les extensions
  chargées et avant les routes ;
- identifiant unique, propriétaire réel, refus des doublons ;
- validation à l’enregistrement, jamais à l’exécution.

### Identifiant

Format recommandé :

```text
^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,4}$
```

Exemples : `core.user.created`, `core.page.published`,
`catalog.entry.published`, `automation.run.failed`.

Le premier segment est un **namespace** déclaré par l’extension, pas
l’identifiant technique `plg_core`. L’identifiant est stable : le renommer casse
les préférences et l’historique. Les anciens événements d’un type supprimé
restent lisibles avec un libellé de repli.

### Métadonnées

Une définition porte au minimum :

| Champ | Rôle |
| --- | --- |
| `id` | Identifiant stable |
| `label` | Libellé lisible dans l’administration |
| `description` | Explication du déclenchement |
| `category` | `user` ou `technical` |
| `severity` | `info`, `warning`, `error` |
| `payloadFields` | Champs attendus, types et obligation |
| `notifiable` | Autorise la création de notifications |
| `defaultChannels` | Canaux proposés par défaut, par exemple `[ui]` |
| `notificationTitle` | Gabarit du titre, avec des variables `{...}` |
| `notificationBody` | Gabarit du corps, texte court |
| `routeName` / `routeParams` | Lien interne et correspondance avec la charge utile |
| `defaultAudience` | Audience proposée si l’émetteur n’en fournit pas |
| `log` | Autorise ou non l’écriture dans le journal |

`notifiable` vaut `false` par défaut. Une définition notifiable sans titre ni
route est refusée à l’enregistrement. Les variables du titre et du corps
doivent correspondre à des champs déclarés : une faute de frappe doit échouer
au démarrage, pas produire une notification vide en production.

### Catégories

- `user` : événement métier visible dans l’historique et éventuellement
  notifiable. Rétention normale.
- `technical` : événement d’exploitation, réservé aux administrateurs, plutôt
  non notifiable. Rétention courte possible.

La catégorie et la sévérité sont recopiées dans l’occurrence pour permettre le
filtrage sans charger la définition.

## Journal d’événements

### Ce qui est écrit

Une ligne `event_log` décrit une occurrence :

- le Space réel de l’événement ;
- le type d’événement ;
- l’acteur, son type et éventuellement l’objet concerné ;
- une charge utile JSON bornée ;
- une clé de déduplication facultative ;
- la date UTC.

La charge utile contient des **références et des libellés courts**, pas des
copies d’entités ni des secrets. Elle ne doit pas contenir :

- mot de passe, jeton, cookie, clé d’API ;
- corps d’email ou de message distant ;
- adresse email ou donnée personnelle qui n’est pas nécessaire au libellé ;
- objet PHP, ressource, closure ou structure récursive.

Une garde applicative refuse les clés sensibles et borne la taille et la
profondeur. Un événement dont la charge utile est invalide est refusé avant
l’écriture.

### Relations

`event_log` référence `spaces` et `users`. La suppression d’un Space supprime
son journal. La suppression d’un utilisateur met `actor_id` à `NULL` : le
libellé de l’acteur devient « compte supprimé », ce qui respecte la suppression
sans perdre la trace de l’action.

Le type d’événement n’a pas de clé étrangère : les définitions sont du code.
L’administration résout le libellé avec le registre et retombe sur l’identifiant
brut si le fournisseur n’est plus chargé.

## Notifications

### Une notification par destinataire

Une occurrence d’événement peut produire plusieurs notifications, une par
destinataire. La notification porte :

- le lien vers l’événement (`event_id`, facultatif après purge) ;
- le destinataire et le Space ;
- le type d’événement, la catégorie et la sévérité ;
- le titre, le corps et le chemin interne figés à l’émission ;
- `view_date` et `done_date`.

`view_date IS NULL` signifie « non vue ». `done_date IS NULL` signifie « non
traitée ». Les deux colonnes sont indépendantes, comme demandé.

### Pourquoi figer le contenu

Le titre, le corps et le lien sont calculés au moment de l’émission, puis
stockés. Trois raisons :

1. un plugin peut être désactivé ou renommé après coup ;
2. une définition peut évoluer sans réécrire l’historique ;
3. le handler d’un job est exécuté dans une Application **sans routes**
   (`initialiseOrThrow(..., registerRoutes: false)`), donc il ne peut pas
   résoudre un nom de route. Le chemin doit être calculé avant l’enfilement.

Le chemin est relatif, commence par `/`, ne contient ni `//`, ni schéma, ni
hôte, ni antislash, ni caractère de contrôle. Il est construit par le routeur
au moment de l’émission, jamais concaténé depuis une valeur non fiable.

### État dans l’interface

- Le compteur de la cloche utilise `COUNT(*) WHERE user_id = ? AND view_date IS NULL`.
- Ouvrir un élément ou cliquer dessus renseigne `view_date`.
- « Traiter » renseigne `done_date` et, si nécessaire, `view_date`.
- « Tout marquer comme lu » ou « tout traiter » utilise une mise à jour
  groupée, jamais une boucle par ligne.

## Résolution des destinataires

### Méthodes demandées

L’émetteur peut fournir une ou plusieurs audiences :

| Méthode | Signification retenue |
| --- | --- |
| Liste d’IDs | Utilisateurs explicitement désignés, validés et restreints au Space |
| Permission globale + Space | Utilisateurs ayant effectivement la permission sur ce Space ou un ancêtre |
| Permission sur objet | Permission effective : globale, sur le type d’objet ou sur l’instance |
| Rôle + Space | Utilisateurs ayant effectivement ce rôle sur ce Space ou un ancêtre |
| Rôle local sur objet | Rôle effectif sur le type ou l’instance, selon le mode retenu |

Les audiences sont réunies, dédupliquées, puis filtrées :

- acteur exclu par défaut ;
- comptes bloqués exclus ;
- comptes de service exclus ;
- utilisateurs sans adresse email valide écartés du canal email ;
- plafond de destinataires, par exemple 500, avec journalisation si dépassé ;
- intersection avec `MEMBER` pour une liste d’IDs fournie par l’émetteur.

Cette dernière règle est importante : sans elle, un plugin ou un futur scénario
pourrait notifier un utilisateur d’un autre Space et lui faire fuiter un titre
ou un lien.

### Requête de permission globale

Exemple pour « permission globale sur un Space » :

```sql
WITH RECURSIVE groupes_beneficiaires (group_id) AS (
    SELECT DISTINCT m.group_id
    FROM acl_map m
    INNER JOIN acl_permissions p ON p.role_id = m.role_id
    WHERE m.space_id IN (/* espace + ancêtres, via FormatIn */)
      AND m.acl_object_id IS NULL
      AND m.object_id IS NULL
      AND p.extension = 'plg_core'
      AND p.name = 'PAGE_READ'

    UNION

    -- Un rôle accordé à un groupe parent profite aux membres des groupes enfants.
    SELECT g.id
    FROM user_groups g
    INNER JOIN groupes_beneficiaires b ON g.parent_id = b.group_id
)
SELECT DISTINCT gm.user_id
FROM user_groups_map gm
INNER JOIN groupes_beneficiaires b ON b.group_id = gm.group_id
INNER JOIN users u ON u.id = gm.user_id
WHERE u.blocked = 0
  AND u.type = 'user';
```

Points d’attention :

- `UNION` (distinct) évite les boucles de groupes ;
- les Spaces ancêtres sont calculés en PHP, comme dans `AclHelper`, puis passés
  avec `FormatIn` ;
- la permission se lit dans `acl_permissions` (extension + nom), pas dans le
  rôle ;
- les lignes globales de `acl_map` ont `acl_object_id` et `object_id` à `NULL` ;
- les index existants suffisent en principe, mais chaque requête doit être
  vérifiée avec `EXPLAIN` sur une base représentative.

### Variantes

**Permission sur objet** : réunir deux branches, car `AclHelper::hasPermissionOn()`
considère les droits globaux, les droits sur le type (`object_id IS NULL`) et
les droits sur l’instance. Ne prendre que l’instance oublierait les
administrateurs qui ont la permission globalement.

**Rôle + Space** : même requête avec `m.role_id = ?` et
`m.acl_object_id IS NULL` / `m.object_id IS NULL`. Le mode par défaut inclut
l’héritage des Spaces ancêtres. Un mode « exact » peut être ajouté par option.

**Rôle local sur objet** : restreindre aux rôles de type local ou mixte
(`acl_roles.type IN (1, 2)`) et au bon Space, puis filtrer `acl_object_id` et
`object_id`.

### Index à réutiliser

`acl_map` possède déjà onze index. Il ne faut pas en ajouter un douzième sans
mesure.

| Requête | Index existant |
| --- | --- |
| Permission globale | `acl_map.idx_acl_map4 (space_id, acl_object_id, role_id, group_id)` |
| Permission sur type ou instance | `acl_map.idx_acl_map3 (space_id, acl_object_id, object_id, role_id, group_id)` |
| Rôle + Space | `acl_map.idx_acl_map1 (space_id, role_id, group_id, acl_object_id, object_id)` |
| Permission → rôle | `acl_permissions.idx_acl_permissions1 (extension, name)` |
| Descente des groupes | `user_groups.idx_user_groups_parent1 (parent_id, type, name)` |
| Appartenance | `user_groups_map` unique `(group_id, user_id)` |

`AclHelper` ne fournit pas de méthode d’énumération des utilisateurs. Il faut
un resolver dédié, avec un cache par requête pour éviter de recalculer la même
audience plusieurs fois.

## Modèle de données

Cinq tables principales. Une sixième table de réglages globaux est facultative.

| Table | Cardinalité | Rôle |
| --- | --- | --- |
| `event_log` | 1 ligne par occurrence | Journal métier durable |
| `notifications` | 1 ligne par (événement, destinataire) | Boîte de réception UI |
| `notification_deliveries` | 1 ligne par (notification, canal, connexion) | Journal des envois externes |
| `user_notification_channels` | 1 ligne par connexion utilisateur | Telegram, Slack, Discord personnels |
| `notification_preferences` | 1 ligne par préférence explicite | Association événement → canal |
| `notification_settings` | 1 ligne par utilisateur | Réglages globaux, facultatif |

La spec parle de `notifications_channels`. Le nom est ambigu : il désigne à la
fois la configuration d’une connexion et le journal d’un envoi. Le schéma
proposé sépare `user_notification_channels` (configuration) et
`notification_deliveries` (journal), pour éviter de mélanger deux cycles de vie.

### `event_log`

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
  `dedup_key` varchar(120) NULL DEFAULT NULL,
  `data` json NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),

  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_event_log_dedup` (`space_id`, `event_type`, `dedup_key`),
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

Notes :

- `dedup_key` est facultative ; la clé unique avec `NULL` autorise plusieurs
  événements sans clé.
- `subject_type` / `subject_id` servent au filtrage et au contrôle d’accès sur
  le détail. Pas d’index au premier lot.
- `created_at` est en UTC, comme le reste du schéma.
- La charge utile est bornée côté application ; MariaDB ne la borne pas.

### `notifications`

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
  `view_date` datetime NULL DEFAULT NULL,
  `done_date` datetime NULL DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),

  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_notifications_event_user` (`event_id`, `user_id`),
  KEY `idx_notifications_user_created` (`user_id`, `created_at`),
  KEY `idx_notifications_user_unread` (`user_id`, `view_date`, `created_at`),
  KEY `idx_notifications_user_todo` (`user_id`, `done_date`, `created_at`),
  KEY `idx_notifications_space` (`space_id`),
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

- `UNIQUE (event_id, user_id)` garantit une notification par destinataire et
  par événement, même si deux règles d’audience se recoupent.
- `event_id` passe à `NULL` lors de la purge du journal : la notification garde
  son titre, son corps et son lien.
- Le corps est du texte, jamais du HTML brut. Il est borné côté application.
- `url_path` est un chemin interne validé.

### `notification_deliveries`

```sql
CREATE TABLE `notification_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `notification_id` bigint unsigned NOT NULL,
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
  KEY `idx_deliveries_job` (`job_id`),

  CONSTRAINT `fk_deliveries_notification` FOREIGN KEY (`notification_id`)
    REFERENCES `notifications` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_deliveries_job` FOREIGN KEY (`job_id`)
    REFERENCES `jobs` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Notes :

- `channel_id = 0` désigne un canal intégré, aujourd’hui l’email. Une valeur
  positive référence `user_notification_channels.id`.
- Pas de clé étrangère sur `channel_id` : une livraison est un historique. Si
  l’utilisateur supprime sa connexion, la trace de l’envoi passé reste lisible.
- `data` ne contient jamais de secret, de webhook, de corps de réponse distant
  ou d’adresse complète. Pour l’email, il contient `mail_log_id` et
  éventuellement `http_status` ; pour les canaux HTTP, un code et un identifiant
  de message.
- Les statuts sont `pending`, `processing`, `sent`, `failed`, `skipped`.

### `user_notification_channels`

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

Notes :

- `config` contient les données non secrètes : `chat_id`, espace de travail,
  libellé du canal.
- `secret` contient la charge chiffrée avec `CryptHelper`, usage stable
  `notification:channel:v1`. L’API ne renvoie jamais ce champ.
- Pour Telegram, le token du bot reste une configuration d’installation ; la
  connexion utilisateur ne porte que le `chat_id`. Un bot token par utilisateur
  est déconseillé.
- Pour Slack et Discord, le webhook entrant est un secret personnel : il reste
  dans `secret`.
- `last_error_code` est un code stable, jamais la réponse brute du fournisseur.

### `notification_preferences`

```sql
CREATE TABLE `notification_preferences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `channel` varchar(32) NOT NULL,
  `channel_id` bigint unsigned NOT NULL DEFAULT 0,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
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

Sémantique recommandée :

- aucune ligne : la valeur par défaut de la définition s’applique ;
- `enabled = 0` : l’utilisateur désactive ce canal pour cet événement ;
- `enabled = 1` avec `channel_id` : l’utilisateur choisit une connexion précise ;
- UI toujours active, même sans ligne ;
- canaux externes désactivés par défaut ;
- email activé par défaut seulement si la définition le déclare.

### `notification_settings` (facultatif)

Pour les interrupteurs globaux, deux options :

- une table `notification_settings (user_id PK, email_enabled, digest, ...)` ;
- ou le JSON `users.params`, qui existe déjà.

La table est plus lisible et plus simple à administrer. Le per-event suffit
cependant au premier lot.

## Indexation

### Boîte de réception

Les requêtes réelles sont :

1. `WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 20` ;
2. `WHERE user_id = ? AND view_date IS NULL ORDER BY created_at DESC` ;
3. `SELECT COUNT(*) WHERE user_id = ? AND view_date IS NULL` ;
4. `WHERE user_id = ? AND done_date IS NULL ORDER BY created_at DESC` ;
5. `UPDATE ... SET view_date = now() WHERE user_id = ? AND view_date IS NULL` ;
6. purge `DELETE ... WHERE created_at < ? LIMIT 500`.

Index correspondants :

| Index | Requêtes servies |
| --- | --- |
| `(user_id, created_at)` | liste complète, tri par date |
| `(user_id, view_date, created_at)` | non-lues, compteur, marquage groupé |
| `(user_id, done_date, created_at)` | à traiter, marquage groupé |
| `(created_at)` | purge |

Pourquoi pas un seul index : `view_date` s’intercale entre `user_id` et
`created_at`, donc `(user_id, view_date, created_at)` ne peut pas servir la
liste « toutes » triée par date. Inversement, `(user_id, created_at)` ne peut
pas filtrer efficacement sur `view_date IS NULL`.

`view_date IS NULL` est un intervalle d’index parfaitement utilisable, et le
compteur peut être satisfait par l’index seul. Il ne faut pas ajouter d’index
sur `view_date` seul ni sur `done_date` seul : trop peu sélectifs.

Pas d’index sur `title` ou `body`. Une recherche plein texte serait un
`FULLTEXT` dédié, pas un `LIKE '%...%'`.

### Pagination

Préférer le keyset :

```sql
WHERE user_id = ?
  AND (created_at < ? OR (created_at = ? AND id < ?))
ORDER BY created_at DESC, id DESC
LIMIT 20
```

`OFFSET` devient coûteux quand la boîte grossit. L’index secondaire InnoDB
contient déjà l’ID primaire, donc `(user_id, created_at)` suffit pour l’ordre
`created_at, id`.

### Filtre par Space

Si la boîte affiche tous les Spaces avec un badge, aucun index supplémentaire
n’est nécessaire. Si elle filtre par Space, ajouter :

- `(user_id, space_id, created_at)` ;
- `(user_id, space_id, view_date, created_at)`.

Ces deux index supplémentaires alourdissent les écritures. La décision doit
être prise avant la migration.

### Journal d’événements

| Index | Usage |
| --- | --- |
| `(space_id, created_at)` | liste d’un Space par date |
| `(space_id, event_type, created_at)` | filtre type + date dans un Space |
| `(actor_id, created_at)` | activité d’un compte |
| `(created_at)` | purge |
| unique `(space_id, event_type, dedup_key)` | idempotence |

Pas d’index sur `data`. Le filtre par objet (`subject_type`, `subject_id`) est
ajouté seulement si l’écran le propose.

### Livraisons

| Index | Usage |
| --- | --- |
| unique `(notification_id, channel, channel_id)` | lookup et idempotence |
| `(status, created_at)` | relances et purge |
| `(channel, status, created_at)` | monitoring par canal |
| `(job_id)` | clé étrangère et diagnostic |

Pas d’index sur `data`.

### ACL

Voir la section « Index à réutiliser ». Les index existants de `acl_map`,
`acl_permissions`, `user_groups` et `user_groups_map` couvrent les requêtes
principales. Toute nouvelle requête doit être mesurée avec `EXPLAIN` avant
d’ajouter un index.

Si l’arbre des groupes devient un goulot d’étranglement, la solution scalable
est une table de fermeture `user_group_ancestors`, maintenue par les workflows
de groupes. Ce n’est pas nécessaire au premier lot.

## Traitement asynchrone

### Un job par livraison externe

Un seul type de job suffit :

```php
final class NotificationDeliveryJobHandler extends BaseJobHandler
{
    public const JOB_TYPE = 'core.notification_delivery';
    public const JOB_LABEL = 'Livraison d’une notification';
    public const JOB_MIN_TIME = 2;
    public const JOB_MAX_ATTEMPTS = 3;
}
```

La charge utile ne contient que `delivery_id`. Le handler recharge la
livraison, la notification, l’utilisateur et la connexion. Le payload ne fait
jamais autorité.

### Déroulé du handler

1. Charger la livraison et vérifier qu’elle existe encore.
2. Vérifier que la notification, l’utilisateur et la préférence sont toujours
   valides.
3. Réclamer la livraison de façon conditionnelle (`pending`/`failed` →
   `processing`).
4. Envoyer :
   - email : `MailHelper::sendTemplate('plg_core__notification', ...)` avec
     `jobId` et `userId` pour `mail_logs` ;
   - Telegram, Slack, Discord : transport existant ou partagé, timeout, pas de
     redirection.
5. Marquer `sent`, `skipped` ou `failed` avec un code stable.
6. Retourner un échec de job seulement pour une erreur transitoire.

### Idempotence

La file livre au moins une fois. Le handler doit être rejouable :

- une livraison déjà `sent` retourne un succès sans renvoyer ;
- une livraison `processing` issue d’un crash doit pouvoir reprendre, en
  s’appuyant sur `job_id` et `attempts` ;
- une erreur permanente (canal supprimé, secret illisible, email invalide)
  donne `skipped` ou `failed` sans retry inutile ;
- une erreur transitoire (SMTP, HTTP 5xx, timeout) donne un échec de job et
  consomme une tentative.

La clé de déduplication initiale est `notification-delivery:{deliveryId}`. Une
relance manuelle doit utiliser une clé qui inclut le numéro de tentative, sinon
`JobQueue::enqueue()` retournera l’ancien job terminé.

### Enfilement et transaction

`JobQueue::enqueue()` écrit dans la transaction de l’appelant. Un métier annulé
ne laisse donc aucun job orphelin. Si l’enfilement échoue pour une livraison
(par exemple type de job absent après un déploiement incomplet), la livraison
est marquée `failed` avec `enqueue_failed` ; l’événement et la notification
restent, et l’administration peut relancer.

### Débit

Le worker est unique et traite un job à la fois. Un envoi SMTP réel prend
souvent 1 à 3 secondes, donc environ 10 à 20 emails par minute. Un événement
destiné à 500 utilisateurs peut prendre 25 à 50 minutes ; 5 000 utilisateurs
prennent plusieurs heures.

Conséquences :

- plafonner l’audience par événement ;
- ne pas activer l’email par défaut pour les événements informatifs ;
- prévoir un digest ou un fan-out par lots pour les événements de masse ;
- surveiller la file dans l’onglet Jobs ;
- ne pas promettre un envoi immédiat.

## Interface

### Cloche

- Rendu serveur dans `MainTemplate/base.php`, à droite du menu.
- Badge = nombre de non-lues, calculé par une requête index-only.
- Dropdown : dernières non-lues, lien vers le centre, « tout marquer comme lu ».
- Un clic sur un élément renseigne `view_date` par POST CSRF, puis navigue.
- Pas de polling. Rafraîchissement à l’ouverture du dropdown et après action.
- Si aucun menu de type `notifications` n’est configuré, masquer la cloche ou
  utiliser une route de secours, selon l’arbitrage retenu.

La cloche ne peut pas être un simple `MenuItem` : un type de menu produit une
route et une entrée de navigation, pas un composant monté dans le header. Il
faut un rendu de header séparé, avec un petit module JS ou un micro-montage Vue.

### Centre de notifications

- Type de menu `plg_core / notifications`, sans paramètres.
- Contrôleur de page rendant `RiaView('notifications')`, avec session et
  vérification de `MEMBER` sur le Space courant.
- Vue `src/ria/src/pages/notifications.vue` et store dédié.
- Filtres : toutes, non lues, à traiter, traitées.
- Pagination keyset, badge du Space, lien vers l’objet.
- Actions : marquer vue, marquer traitée, rouvrir, tout marquer lu/traité.

Le contrôleur peut suivre le modèle de `PageController` : pas d’attributs de
route, passage par `MenuItemDef` avec `['GET' => 'index']`. La route est alors
construite à partir des alias du menu, donc localisée.

### Mon compte

- Section « Notifications » dans le composant Mon compte.
- Matrice événements × canaux : UI, email, puis les connexions Telegram, Slack
  et Discord.
- CRUD des connexions avec les modales communes, test d’envoi asynchrone,
  secret masqué, état de vérification.
- Réutiliser le mixin global, les stores, les modales, `ctrl-s` et `esc`.

### Administration

- Onglet **Événements** dans l’écran Système : liste filtrée par Space, type,
  catégorie, acteur et date ; détail en lecture seule ; catalogue des
  définitions ; purge.
- Onglet **Livraisons** : états, erreurs, relance, purge, connexions en erreur.
  Réservé à ROOT ou à une permission dédiée, sans contenu personnel.
- Les préférences ne sont pas administrées par un tiers : un administrateur ne
  doit pas lire les webhooks ni les notifications d’un utilisateur.

## Sécurité et vie privée

### Contrôle d’accès

- Les notifications personnelles sont lues uniquement avec le `user_id` de la
  session, jamais avec un identifiant fourni par le client.
- Chaque mutation vérifie l’appartenance de la notification à l’utilisateur.
- Le journal est réservé à ROOT ou à une permission dédiée sur le Space.
- Le détail d’un événement recharge l’objet référencé et vérifie le droit sur
  cet objet avant d’afficher des données sensibles.
- Le monitoring des livraisons est ROOT ou permission dédiée.

### Données

- Aucun secret, jeton, webhook ou mot de passe dans `event_log.data`,
  `notification_deliveries.data` ou les logs.
- Les secrets des connexions sont chiffrés et jamais sérialisés.
- Les adresses email complètes ne sont pas recopiées dans les livraisons :
  `mail_logs` les porte déjà.
- La suppression d’un utilisateur supprime ses notifications, ses préférences
  et ses connexions ; son `actor_id` devient `NULL` dans le journal.
- La rétention est appliquée par une purge horaire par lots.

### Entrées non fiables

- Le titre et le corps sont échappés dans l’UI et dans l’email.
- Le sujet d’email est mono-ligne, sans CR/LF.
- Le chemin interne est validé : pas d’URL absolue, pas de `//`, pas de
  contrôle.
- Les webhooks Slack et Discord sont limités à leurs hôtes officiels, sans
  redirection, avec timeout et taille de réponse bornée.
- Telegram utilise l’hôte fixe de l’API.
- Aucune URL de webhook générique fournie par l’utilisateur n’est acceptée sans
  protection SSRF.
- Les routes POST utilisent le jeton CSRF existant.
- Les tests de canal et les écritures de préférences sont limités par un
  mécanisme de débit par utilisateur.

### Journalisation

Codes de journal proposés, à préciser dans le guide des journaux :

- `EVENT_LOG_WRITE_FAILED` ;
- `EVENT_PAYLOAD_INVALID` ;
- `NOTIFICATION_AUDIENCE_TOO_LARGE` ;
- `NOTIFICATION_FANOUT_FAILED` ;
- `NOTIFICATION_CHANNEL_UNAVAILABLE` ;
- `NOTIFICATION_CHANNEL_SECRET_INVALID` ;
- `NOTIFICATION_DELIVERY_ENQUEUE_FAILED` ;
- `NOTIFICATION_DELIVERY_FAILED` ;
- `NOTIFICATION_PURGE`.

Les contextes contiennent le Space, le type d’événement, le canal et un code,
jamais le contenu du message ni le secret.

## Rétention et volume

Valeurs proposées :

| Donnée | Rétention | Remarque |
| --- | --- | --- |
| `event_log` | 90 jours | Les événements techniques peuvent avoir une rétention plus courte |
| `notifications` | 180 jours | La boîte doit rester utilisable |
| `notification_deliveries` | 90 jours | Aligné sur `mail_logs` |
| Canaux inactifs | supprimés avec le compte | Pas de conservation des secrets |

La purge est horaire, par lots de 500, avec un nombre de lots borné. Elle est
idempotente et journalise le nombre de lignes supprimées.

Les événements à fort volume, comme les clics de page, ne doivent pas être
journalisés par défaut. La définition porte un indicateur `log` et une
catégorie `technical` pour permettre une rétention courte.

## Extensibilité des canaux

Les extensions peuvent déclarer des canaux, mais le handler de livraison reste
unique. Un canal déclare :

- un identifiant stable ;
- un libellé ;
- un formulaire de configuration ;
- les champs secrets ;
- un validateur ;
- une classe d’envoi ;
- une longueur maximale de message.

Registre proposé : `NotificationChannelRegistry` et
`INotificationChannelProvider::coreOnRegisterNotificationChannels()`. Le
handler résout la classe d’envoi au moment de l’exécution à partir du
`channel` stocké. Si le fournisseur n’est plus chargé, la livraison est marquée
`failed` avec `channel_unavailable`, sans consommer de tentative inutile.

Les canaux intégrés (email, Telegram, Slack, Discord) sont déclarés par Core.
Le transport HTTP existant de l’automatisation est déjà prudent ; il doit être
déplacé dans Core ou partagé, car Core ne doit pas dépendre d’une extension
optionnelle.

## Lots proposés

| Lot | Contenu | Dépendances |
| --- | --- | --- |
| 0 | Arbitrages, guide `Docs/NOTIFICATIONS.md`, contrats PHP | Aucune |
| 1 | Registre d’événements, `event_log`, émetteur, purge, onglet Événements | Lot 0 |
| 2 | `notifications`, resolver d’audience, préférences, cloche, centre, type de menu | Lot 1 |
| 3 | `user_notification_channels`, `notification_deliveries`, job, email, Telegram/Slack/Discord | Lot 2 |
| 4 | Canaux déclarés par les plugins, pont Automation, monitoring, bilan de sécurité, documentation | Lot 3 |

Le lot 2 est le plus risqué : le resolver ACL et les index doivent être validés
sur une base représentative avant l’interface.

## Vérifications prévues

### Unitaires

- registre : identifiants invalides, doublons, propriétaire, métadonnées
  notifiables incomplètes ;
- rendu : échappement, variables inconnues, sujet mono-ligne, chemin invalide ;
- validateurs de charge utile : taille, profondeur, clés sensibles.

### Intégration base

- audience : liste directe, permission globale, permission sur objet, rôle,
  rôle local, héritage Space, héritage groupe, doublons, acteur exclu ;
- utilisateurs bloqués, comptes de service, utilisateurs sans email ;
- transaction : annulation sans journal, notification ni job ;
- déduplication : même événement émis deux fois ;
- purge : événements, notifications, livraisons, `SET NULL`, `CASCADE` ;
- index : `EXPLAIN` des requêtes de boîte et d’audience.

### Job

- envoi réussi ;
- SMTP désactivé → `skipped` ;
- erreur transitoire → retry ;
- erreur permanente → pas de retry inutile ;
- job rejoué après succès → pas de double envoi ;
- canal supprimé, secret illisible, préférence désactivée ;
- relance manuelle.

### Sécurité

- IDOR : un utilisateur ne peut ni lire ni modifier la notification d’un autre ;
- CSRF sur les mutations ;
- XSS dans le titre, le corps et le lien ;
- injection d’en-têtes email ;
- SSRF sur les webhooks ;
- secrets absents des réponses API et des journaux ;
- accès au journal limité au Space réel.

## Documentation à mettre à jour

- Nouveau guide `Docs/NOTIFICATIONS.md` : contrat des événements, des
  audiences, des canaux, de la rétention et de la sécurité.
- [`Docs/JOBS.md`](../../JOBS.md) : type `core.notification_delivery`,
  débit et relance.
- [`Docs/ARCHITECTURE.md`](../../ARCHITECTURE.md) : modules `Core/Event` et
  `Core/Notification`.
- [`Docs/LOGGING.md`](../../LOGGING.md) : codes du chantier.
- [`Docs/FRONTEND.md`](../../FRONTEND.md) : cloche, store, centre.
- [`Docs/ROLES.md`](../../ROLES.md) : permissions d’administration si elles
  sont ajoutées.
- [`version.md`](../../../version.md) : uniquement les changements utiles aux
  utilisateurs, après livraison.
- [`Docs/IA/ETAT_DU_PROJET.md`](../ETAT_DU_PROJET.md) et
  [`Docs/IA/TRAVAUX_RESTANTS.md`](../TRAVAUX_RESTANTS.md) : état réel après
  livraison. Le second indique aujourd’hui que les notifications restent
  synchrones ; ce chantier changera cette limite.

## Écarts constatés

- La spec dit `extension.action` ; l’existant utilise `core.user.created` et
  `core.page.button.clicked`. Le format à 2–5 segments est recommandé.
- La spec parle de `notifications_channels` pour le journal d’envoi ; le schéma
  sépare la configuration (`user_notification_channels`) du journal
  (`notification_deliveries`).
- La spec demande un `MenuItem` de type Notification « qui importe un fichier
  Vue » ; un type de menu produit une route, pas un montage dans le header. La
  cloche et la page sont deux mécanismes distincts.
- La documentation actuelle indique que les notifications restent synchrones
  (`Docs/IA/TRAVAUX_RESTANTS.md`, `Docs/IA/ETAT_DU_PROJET.md`) ; ce chantier
  déplace les envois externes vers la file de jobs.
- L’automatisation possède déjà un outbox et des notifications par Space. Le
  chantier ne les remplace pas ; il ajoute un journal métier et des
  notifications par utilisateur, avec un pont optionnel.

## Questions ouvertes

1. Format des identifiants : 2–5 segments avec namespace, ou format strict
   `extension.action` ?
2. Périmètre des événements Core du premier lot : lesquels instrumenter ?
3. Email par défaut : tous les événements, ou seulement les événements
   transactionnels et importants ?
4. Telegram : bot d’installation avec `chat_id`, ou bot token par utilisateur ?
5. Audience : droit effectif par défaut, ou mode exact pour certains métiers ?
6. Boîte de réception : tous les Spaces, ou Space courant uniquement ?
7. Rétention : 90 / 180 / 90 jours, ou valeurs différentes ?
8. Cloche : menu obligatoire, ou route de secours fixe si aucun menu n’est
   configuré ?
