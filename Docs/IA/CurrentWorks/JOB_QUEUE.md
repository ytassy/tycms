# Chantier Job Queue

Conception du système générique de tâches différées de TyCMS. Ce document
conserve les décisions arrêtées, les raisons des choix et les lots restants. Le
contrat d'usage destiné aux extensions et à l'exploitation est dans
[File de jobs](../../JOBS.md) ; il décrit le code livré.

## Objectif

Disposer d'une file d'attente persistée en base, exécutée par un worker PHP
déclenché par CRON ou par le trafic du site, sans Redis, sans processus
permanent et sans dépasser les limites d'un hébergement mutualisé. La file doit
être utilisable par le Core, les extensions et les workflows métier, sans
connaître leur logique.

## Décisions d'architecture

### Emplacement dans Core

La file vit dans `plg_core` : `Core/JobQueue/` pour l'implémentation et
`Core/ExtensionApi/Job/` pour l'API exposée aux extensions. La table `jobs`
appartient donc à une migration Core.

Raison : la file est une infrastructure, comme la route CRON. Une extension
désactivable qui porterait la file casserait les producteurs qui l'appellent.
`plg_core` est toujours chargé, et ses migrations sont déjà exécutées sur les
installations existantes.

### Deux CRON, deux secrets

Le worker de file **n'est pas** un hook `ICronProvider` : il ne partage ni la
requête `/cron`, ni le jeton `cron_token`, ni le budget de l'automatisation.

- Route `GET/POST /queue-worker`, jeton `job_worker_token`.
- L'administrateur configure deux lignes de CRON sur son hébergement : la ligne
  du système (`/cron`) et celle de la file (`/queue-worker`).
- L'entretien de la file (purge de rétention, reprise des baux expirés) est
  exécuté par ce worker, puisque c'est le seul point périodique de la file.

Raison : deux budgets indépendants évitent qu'une file chargée affame les
scénarios planifiés, et deux secrets se rotent séparément. Conséquence assumée :
une installation qui oublie la seconde ligne CRON ne draine sa file que par le
trafic.

La vérification du jeton est partagée par `Core/Cron/TokenGuard.php`, réutilisé
par les deux routes : chaque route garde son propre secret, sa propre rotation
et ses propres codes de journal (`CRON_*`, `JOB_WORKER_*`).

### Un seul moteur, trois portes

| Porte | Route | Budget | Authentification |
| --- | --- | --- | --- |
| CRON système | `GET/POST /cron` | — | `cron_token` |
| CRON de la file | `GET/POST /queue-worker` | 20 jobs / 30 s | `job_worker_token` |
| Trafic du site | `POST api/jobs/ping` | 3 jobs / 5 s | aucune, plafonné |
| Administration | `administrator/api/jobs` | aucune exécution | ROOT |

Le CRON système ne déclenche pas la file : les deux routes sont indépendantes.

Les deux portes de la file appellent exactement le même `JobWorker`. Seul le
budget diffère.

### Verrou et réservation

- Un seul worker à la fois : `GET_LOCK('tycms_job_<hash base>', 0)`. Le délai
  vaut zéro : si le verrou est pris, la requête s'arrête et répond
  immédiatement, sans attente. Il s'agit d'un verrou nommé de session, **pas
  d'un verrou de table** : `LOCK TABLES` et un `FOR UPDATE` bloquant sont exclus
  du chemin du worker, car ils bloqueraient la requête au lieu de l'arrêter.
- Le nom du verrou dépend de la base : sur un mutualisé, plusieurs
  installations TyCMS peuvent partager le même serveur MariaDB. Un nom constant
  les sérialiserait entre elles.
- La reservation d'un job est une mise à jour conditionnelle sur une ligne,
  suivie d'une relecture par un jeton de réservation aléatoire. Le nombre de
  lignes affectées fait foi.
- Aucun `FOR UPDATE` bloquant dans le chemin du worker.
  `innodb_lock_wait_timeout` est abaissé sur la connexion du worker pour qu'une
  contention de ligne ne fasse jamais patienter la requête.
- `SELECT ... FOR UPDATE SKIP LOCKED` n'est pas utilisé : absent avant
  MariaDB 10.6, et la production mutualisée n'est pas garantie sur cette version.
- Bail : `reserved_until = now + min_time + marge`. Un job `processing` dont le
  bail est expiré est remis `pending` par le worker suivant. Le nombre de
  tentatives est déjà consommé à la réservation, ce qui borne une boucle de
  plantages.

### `min_time` par type de job

Chaque type déclare `min_time`, la durée attendue de son exécution. Le worker
ne réserve un job que si le budget restant du tick couvre ce `min_time` ; sinon
le job reste `pending` et sera repris plus tard, sans consommer de tentative.

Le même paramètre sert de durée de bail (`min_time + marge`), donc un seul
réglage par type pour deux usages cohérents. Il est borné de 1 à 20 secondes à
l'enregistrement, pour rester exécutable sous le budget CRON de 30 secondes.

`min_time` est une indication de planification, pas une garantie d'arrêt. Sous
Unix, `max_execution_time` compte le temps processeur et n'interrompt pas une
entrée-sortie bloquante ; `set_time_limit()` est fréquemment neutralisé sur un
mutualisé. Un handler qui attend un SMTP lent peut donc dépasser son budget.
La seule protection réelle est que chaque handler porte ses propres délais
(cURL, SMTP, lots bornés). Ce point fait partie du contrat des handlers.

Le worker lit une petite fenêtre de candidats, filtre les types inconnus et les
`min_time` trop grands, puis réserve le premier éligible. Il ne bloque donc pas
sa file derrière un job trop long pour le budget courant.

### Identité d'exécution

Un job porte un principal, choisi à l'enfilement :

| Principal | Contexte d'exécution |
| --- | --- |
| `none` (défaut) | Aucun utilisateur (session invitée), donc aucun droit métier |
| `system` | Compte de service système disposant de tous les droits de l'installation |
| `user` | Utilisateur désigné par `exec_user_id`, avec ses seuls droits |

Le défaut est `none` : obtenir les pleins droits doit être une demande explicite
du producteur, jamais un oubli.

Attention : `ROOT` n'est pas un joker. `AclHelper` vérifie chaque permission
nommée séparément ; un compte qui ne porte que `ROOT` passe `isRoot()` mais se
fait refuser par tous les `hasGlobalPermission(USERS_READ, …)` des workflows.
Le compte de service reçoit donc le jeu complet de permissions (le rôle Super
Admin de l'installation) sur le Space 1, hérité par ses descendants.

**Le privilège du job ne protège pas contre le code.** Une extension installée
est du PHP chargé dans le processus, avec `$this->db`, la configuration et
`Configuration.secret` : elle peut déjà écrire en base sans passer par un
workflow. Lui refuser un principal `system` n'ajouterait aucune sécurité, elle
donnerait seulement l'illusion d'une barrière. La frontière réelle est
l'installation — paquets signés, clés de confiance, approbation ROOT — et la
trace `producer` permet de retrouver l'auteur d'un job.

Règles d'exécution du principal `user` :

- `exec_user_id` vient d'un contexte serveur (session, entité relue), jamais
  d'un paramètre de requête. Un producteur qui relaie une valeur du navigateur
  transforme l'option en usurpation.
- Le contexte reprend le `space_id` du job. Un utilisateur sans droit sur ce
  Space échoue sur ses propres contrôles ACL : fail-closed, sans code ajouté.
- Un utilisateur supprimé ou bloqué au moment de l'exécution fait échouer le job
  avec `JOB_PRINCIPAL_UNAVAILABLE`, sans consommer de tentative. Un compte
  bloqué ne doit pas continuer à agir par sa file.

Raison : un visiteur ne doit jamais fournir le contexte d'exécution. Le worker
construit une `Application` isolée (`clone` de la configuration,
`RESET_DATABASE = false`, `RunSession` portant le principal, `registerRoutes =
false`), sur le modèle du mode `author` de l'automatisation. Une application est
créée par principal distinct et réutilisée pour les jobs du tick qui partagent
ce principal : une ou deux boots en pratique, au plus une par job dans le pire
cas d'un lot entièrement multi-utilisateurs.

Le compte de service système est créé par la migration de la file : identifiant
réservé **2**, type `service`, sans mot de passe, rattaché au groupe Super Admin
(`GROUP_ROOT`), dont le rôle porte toutes les permissions sur le Space 1. L'ID
est libre par construction : l'installation fixe `users.AUTO_INCREMENT = 1000`,
donc aucun compte créé par l'application ne peut l'occuper.

Sa suppression est refusée en dur pour tout identifiant de la plage réservée
(1 à 999), cohérente avec `users.AUTO_INCREMENT = 1000` : dans les deux
workflows d'administration des comptes, et dans le helper de suppression des
utilisateurs, qui sert d'invariant à tout appelant futur. Cela protège aussi
l'administrateur initial. Les droits restent modifiables ; seule la suppression
est verrouillée. Une suppression du compte système faite en SQL direct serait
réparée au passage suivant du worker.

### Enfilement transactionnel

`enqueue()` insère la ligne dans la transaction de l'appelant et ne valide rien.
Un métier annulé ne laisse donc aucun job orphelin : la ligne de job est sa
propre boîte d'envoi, sans second outbox.

### Payload

- JSON uniquement, 32 Ko au maximum, refusé au-delà.
- Des références, jamais une autorité : identifiants, clés de modèle, options
  simples. Jamais un secret, un jeton, ni une entité complète.
- Le handler recharge ce dont il a besoin ; il ne fait jamais confiance au
  payload pour une décision d'autorisation.
- Un handler n'est jamais désigné par un nom de classe stocké en base : le type
  est résolu par le registre. Une ligne dont le type n'est plus fourni est
  marquée `failed` avec un code dédié, sans consommer de tentative.

### Extensibilité

Une extension implémente `IJobProvider` et enregistre ses types sur
`onPrepareExtensions`, comme les registres d'actions et de composants existants.
Chaque type déclare un identifiant stable `extension.job`, un libellé, un
`min_time`, un nombre de tentatives par défaut et une classe de handler. Ces
métadonnées vivent en constantes `JOB_*` sur le handler et l'enregistrement
tient en une ligne : `$registry->register(JobType::for(MonHandler::class));`.
Le handler expose aussi une fabrique qui retourne un `JobRequest` : c'est elle
que les producteurs appellent, jamais un tableau de payload composé à la main.

## Modèle de données

Table `jobs` :

| Colonne | Rôle |
| --- | --- |
| `id` | Identifiant du job |
| `space_id` | Space du job, `NULL` pour un job d'infrastructure |
| `type` | Identifiant du type enregistré |
| `payload` | JSON borné |
| `status` | `pending`, `processing`, `completed`, `failed`, `cancelled` |
| `priority` | Entier 0–100, défaut 50 |
| `attempts`, `max_attempts` | Tentatives consommées et plafond |
| `available_at` | Plus tôt instant d'exécution (date planifiée, backoff) |
| `reserved_until`, `claim` | Bail et jeton de réservation |
| `principal` | `none`, `system` ou `user` |
| `exec_user_id` | Utilisateur d'exécution pour le principal `user` |
| `producer` | Extension productrice, trace déclarative |
| `created_by` | Auteur de l'enfilement, `NULL` pour un job système |
| `dedup_key` | Clé d'idempotence optionnelle, unique par type |
| `cancel_requested` | Annulation coopérative d'un job en cours |
| `last_error` | Message tronqué, sans contenu sensible |
| `created`, `started_at`, `finished_at`, `duration_ms` | Traçabilité |

Index : `(status, available_at, priority)` pour la sélection, `(type, dedup_key)`
en clé unique, `(space_id, created)` pour l'administration.

`space_id` et `created_by` référencent leurs tables en `ON DELETE CASCADE` et
`ON DELETE SET NULL` : supprimer un Space supprime ses jobs en attente, et
supprimer un compte ne perd pas le job.

Une seconde table `job_worker_state` (une ligne) conserve le dernier passage du
worker, le budget consommé et le nombre de jobs traités. Elle alimente un
avertissement dans l'écran Jobs quand aucun passage n'a eu lieu depuis plus de
cinq minutes : c'est le seul moyen de constater qu'une installation a oublié la
seconde ligne CRON, puisque rien d'autre ne s'en plaindrait.

## API publique

```php
JobQueue::enqueue(JobRequest $request): int
JobQueue::status(int $id): ?array
JobQueue::cancel(int $id): bool
JobQueue::hasDueWork(): bool
```

Options d'enfilement : `space_id`, `priority`, `available_at`, `dedup_key`,
`principal` (`none` par défaut, `system` ou `user`), `exec_user_id` (requis avec
`user`, interdit sinon), `producer` (défaut : préfixe du type), `max_attempts`.

Le `JobRequest` vient d'une fabrique statique du handler, jamais d'un tableau
composé à la main : le producteur ne connaît ni les clés du payload ni celles
des options.

```php
$this->coreJobQueue->enqueue(
    SendMailJobHandler::create(UserWelcomeMailHelper::TEMPLATE_LOCAL, $userId, $spaceId, $locale),
);
```

L'accès passe par `getSharedExtensionsClass(JobQueue::class)`, comme les autres
services partagés. `dedup_key` renvoie l'identifiant du job existant au lieu
d'en créer un second.

## Contrat des handlers

Un handler étend `BaseJobHandler` (`Core/ExtensionApi/Job`), qui implémente
`IJobHandler` et compose `TraitCoreSharedHelpers` au-dessus de
`BaseApplicationItem` : il dispose de la base, du journal et des accès partagés
sans revendiquer le rôle d'un helper de couche. `JobType` refuse toute autre
classe. Les actions d'automation, elles, étendent `BaseHelper` ; l'écart est
assumé, un handler n'est pas un helper technique.

Un handler de job est du code exécuté hors de la requête qui l'a enfilé. Il est
donc tenu à quatre règles, à documenter dans le guide :

1. **Idempotence.** La file livre au moins une fois. Un job interrompu entre un
   effet externe et l'écriture du statut sera rejoué. Le handler doit soit
   supporter le doublon, soit le détecter lui-même.
2. **Ses propres délais.** Le worker ne peut pas interrompre un handler bloqué
   dans une entrée-sortie. Chaque appel distant porte son timeout.
3. **Aucune confiance dans le payload.** Le payload porte des références ; les
   droits et les données sont relus au moment de l'exécution.
4. **`min_time` honnête.** Une valeur sous-estimée ne fait pas échouer le job
   proprement : elle fait dépasser le budget du tick.

## Worker

1. Purge de rétention bornée (une fois par heure glissante, mémorisée en base).
2. Reprise des baux expirés, bornée.
3. Lecture d'une fenêtre de candidats, filtrage par type connu et `min_time`.
4. Réservation atomique du premier éligible.
5. Exécution dans l'`Application` isolée du principal, avec `set_time_limit`
   ajusté si la fonction est disponible.
6. Écriture du résultat, du message et de la durée ; backoff exponentiel avec
   gigue en cas d'échec (`min(1 h, 30 s × 2^tentative)`).
7. Arrêt du tick dès que le budget restant ne couvre plus un candidat.

Le worker ne rattrape pas un tick manqué : c'est la date `available_at` qui
porte l'échéance.

## Trafic du site

- Un `EXISTS` indexé sur `(status, available_at)` est exécuté au rendu ; sans
  job dû, aucun tag n'est émis. Un site au repos ne paie rien.
- Le tag émet un appel `POST` asynchrone, sans cookies, avec `keepalive`, et
  avale les erreurs. Il n'apparaît que dans la template publique.
- Réglage `job_traffic_trigger`, actif par défaut : `false` s'en remet au seul
  CRON de la file. Le tag est également omis quand la page ne rend pas l'Import
  Map : sans nonce CSP, le script en ligne serait bloqué, donc inutile.
- Le navigateur applique un délai local (environ 5 minutes par onglet) pour
  éviter un boot PHP à chaque page vue.
- La route ne retourne que des compteurs, jamais une sortie de job, et ne
  vérifie aucune session.
- **Aucun jeton.** La route n'accorde aucune autorité : elle ne peut ni créer,
  ni réordonner, ni modifier un job, ni prolonger une tentative, ni choisir
  celui qui s'exécute. Elle ne fait que drainer ce qui est déjà dû. Un jeton
  lisible dans la page ne protégerait de rien, puisqu'il suffit d'afficher la
  source pour l'obtenir ; il ajouterait une configuration, une rotation et
  l'illusion d'une barrière.
- Deux obstacles sans prétention de frontière : un `Sec-Fetch-Site:
  cross-site` est refusé quand l'en-tête est présent, et la route n'est pas
  exemptée du WAF. Les deux se contournent avec `curl` ; ils évitent seulement
  l'embarquement naïf depuis un site tiers.
- Plafonnement : le verrou non bloquant et le lot borné suffisent. Cent appels
  simultanés donnent un worker et quatre-vingt-dix-neuf réponses immédiates.
  L'amplification maximale est bornée par la taille de la file, pas par le
  nombre d'appelants : le verrou borne la concurrence, pas le volume total de
  travail. Aucun limiteur par IP n'est ajouté par défaut ; un compteur simple
  pourra l'être si les journaux montrent un abus.

## Sécurité

- Jeton absent de la configuration : `503`, comme `/cron`.
- Jeton fourni par plusieurs sources : `400`. Jeton invalide : `401`.
- Comparaison en temps constant, jeton jamais journalisé.
- Routes techniques sans CSRF et sans transaction globale. Seule
  `queue-worker` est exclue des motifs du WAF, parce qu'elle est appelée par un
  CRON externe à jeton ; le ping du trafic reste inspecté et ne reçoit aucun
  paramètre.
- Le compte système donne ROOT : le producteur est du code de confiance, et
  chaque job journalise son type, son principal et son identifiant.
- Aucun payload, aucun secret ni contenu utilisateur dans les journaux.
  Codes stables : `JOB_FAILED`, `JOB_FAILED_FINAL`, `JOB_CLAIM_FAILED`,
  `JOB_LEASE_EXPIRED`, `JOB_TYPE_UNAVAILABLE`, `JOB_PAYLOAD_INVALID`,
  `JOB_PRINCIPAL_UNAVAILABLE`, `JOB_CANCELLED`, `JOB_WORKER_SUMMARY`,
  `JOB_WORKER_BUDGET`, `JOB_WORKER_TOKEN_*`, `JOB_SYSTEM_ACCOUNT_RECREATED`.
- Le bilan de sécurité signale l'absence de `job_worker_token` et un worker sans
  passage récent : ce sont les deux pannes silencieuses d'une installation
  mutualisée.

## Interface

- Écran **Jobs** réservé à ROOT : liste filtrée par statut, type et Space,
  paginée ; détail de l'erreur ; relance (remise `pending` avec tentatives
  remises à zéro) ; annulation ; purge des jobs terminés.
- Écran **Système**, nouvel onglet **Documentation**, réservé à ROOT : contenu
  statique expliquant la configuration des deux CRON, avec l'URL réelle du
  worker et l'état du jeton (configuré ou non, jamais sa valeur), des exemples
  pour cPanel et Infomaniak et une aide au diagnostic.

## Premier consommateur

`core.mail_send` : envoi différé d'un e-mail à partir d'un modèle, d'une langue
et de variables d'affichage. L'habillage et l'envoi passent par les mécanismes
existants au moment de l'exécution. Le payload ne porte que des références et
des valeurs d'affichage : jamais de mot de passe, de jeton ni de contenu
sensible, la ligne de file étant lisible en base.

Sont basculés en v1 les deux messages de bienvenue qui ne contiennent aucun
secret : inscription locale et inscription SSO. L'accueil d'un compte créé par
un administrateur (`sendAdminCreatedAccount`) porte le mot de passe initial et
reste donc synchrone : c'est précisément ce que la règle ci-dessus interdit de
mettre en file. La récupération de mot de passe et les notifications
d'automatisation restent synchrones aussi.

Le type déclare `max_attempts = 2` : un échec SMTP transitoire mérite une
seconde chance, au prix d'un doublon possible si le serveur a accepté le message
avant l'échec d'écriture du statut. L'envoi d'e-mail n'étant pas idempotent, ce
compromis doit être écrit dans le guide plutôt que découvert en production.

## Lots

| Lot | Contenu |
| --- | --- |
| 1 | Migration `jobs`, `JobTypeRegistry` et `IJobProvider`, `JobQueue` (enfilement, dédup, statut, annulation), provisionnement du compte système, tests unitaires |
| 2 | `JobWorker` (verrou non bloquant, candidats, `min_time`, réservation, bail, reprise, backoff), route `queue-worker` et son jeton, écriture du battement `job_worker_state`, tests d'intégration sur la réservation concurrente |
| 3 | Déclenchement par le trafic : `hasDueWork()` au rendu, tag JS limité, route `api/jobs/ping` |
| 4 | Type `core.mail.send` et bascule de l'e-mail de bienvenue, sans régression sur les autres envois |
| 5 | Écran Jobs, onglet Documentation, guide `Docs/JOBS.md`, index, état du projet, `version.md` |

## Limites et hors périmètre

- Livraison **au moins une fois** : un job interrompu après un effet externe
  mais avant l'écriture du statut sera rejoué. Les handlers doivent être
  idempotents. Aucune garantie « exactement une fois ».
- Un seul job à la fois, aucune parallélisation et aucune préemption par
  priorité.
- Pas de chaînage de jobs : les enchaînements métier restent dans
  l'automatisation.
- Pas d'enfilement par API HTTP : les producteurs sont du code PHP de
  l'installation.
- Pas d'alerte e-mail à l'échec définitif : journal, écran et relance manuelle.
- Aucune exécution garantie sous la minute : la granularité reste celle du CRON.
- La file ne remplace pas le worker planifié de l'automatisation. Le drain
  d'événements et les runs planifiés pourraient devenir des types de jobs plus
  tard ; ce n'est pas prévu dans ce chantier.
