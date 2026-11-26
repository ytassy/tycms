# File de jobs différés

`plg_core` fournit une file persistée en base, exécutée par un worker PHP borné.
Elle sert aux traitements différés : un effet métier qu'une requête ne doit pas
attendre. Elle ne remplace pas le moteur d'automatisation, qui garde les
scénarios, leurs déclencheurs et leurs principes d'exécution.

## Choisir entre la file et l'automatisation

| Besoin | Outil |
| --- | --- |
| Exécuter une opération unitaire plus tard, une seule fois | File de jobs |
| Enchaîner des actions, réagir à un événement, décider dans un scénario | [Automatisation](IA/CurrentWorks/AUTOMATION.md) |

Un job est indépendant : il ne connaît ni les autres jobs, ni un scénario, ni
une chaîne d'effets. Un enchaînement métier reste dans l'automatisation.

## Deux déclencheurs

Le worker n'est pas un hook de la route CRON du système : la file a sa propre
route, son propre secret et son propre budget.

```http
GET /queue-worker?job_worker_token=<jeton>
```

```http
POST /queue-worker
Authorization: Bearer <jeton>
```

`job_worker_token` vient de `configuration.php`, vide par défaut. Vide, la route
répond `503` : une file non configurée ne s'ouvre pas. Le jeton est comparé en
temps constant et n'est jamais journalisé ; les règles de sources multiples
(Bearer, query, corps, Basic) sont celles de la route CRON, décrites dans
[CRON](CRON.md#route-et-authentification).

La crontab doit appeler **les deux** routes, chacune avec son jeton :

```cron
* * * * * /chemin/vers/script-cron.sh
* * * * * /chemin/vers/script-queue.sh
```

Le second déclencheur est le trafic du site : une page publique porte un appel
asynchrone quand un job est dû. Il ne crée aucun travail, ne reçoit aucun
paramètre et ne renvoie qu'un compteur. Il est limité par le navigateur (environ
une tentative par onglet toutes les cinq minutes), par le verrou du worker et
par la taille du lot. Aucun jeton n'est utilisé : un secret lisible dans la page
n'apporterait aucune protection.

```php
public bool $job_traffic_trigger = true; // false : seul le CRON agit
```

## Déclarer un type de job

Une extension implémente `IJobProvider` et enregistre ses types pendant
`onPrepareExtensions`, comme les autres registres.

```php
final class Plugin extends BasePlugin implements IJobProvider
{
    public function coreOnRegisterJobTypes(JobTypeRegistry $registry): void
    {
        $registry->register(JobType::for(SendNewsletterHandler::class));
    }
}
```

`JobType::for()` lit les métadonnées déclarées par le handler : un identifiant
unique `extension.job`, un libellé, un `min_time` et un nombre de tentatives.
Un identifiant dupliqué est refusé au démarrage.

## Types fournis par Core

| Type | Rôle |
| --- | --- |
| `core.mail_send` | Bienvenue après inscription locale ou SSO, sans secret dans le payload |
| `core.mail_template` | Envoi d'un modèle, utilisé par l'action `core::sendMailTemplate` |
| `core.mail_html` | Envoi d'un corps HTML libre, utilisé par l'action `core::sendMail` |

Les deux actions d'e-mail de l'automatisation passent donc par la file : le
scénario n'attend plus le SMTP, il rend vrai dès que le job est enfilé.

## Écrire un handler

Un handler étend `BaseJobHandler`. Il n'est pas un helper de couche : c'est
l'exécution différée d'une opération métier, hors de la requête qui l'a enfilée.

```php
final class SendNewsletterHandler extends BaseJobHandler
{
    public const JOB_TYPE = 'plg_newsletter.send';
    public const JOB_LABEL = 'Envoi d’une lettre d’information';
    public const JOB_MIN_TIME = 8;
    public const JOB_MAX_ATTEMPTS = 3;

    /** Fabrique appelée par les producteurs. */
    public static function create(int $issueId): JobRequest
    {
        return new JobRequest(self::JOB_TYPE, ['issue_id' => $issueId]);
    }

    public function handle(JobContext $context, array $payload): JobResult
    {
        $issueId = $payload['issue_id'] ?? null;

        if (!is_int($issueId) || $issueId <= 0) {
            return JobResult::failure('Payload invalide.');
        }

        // Recharger la ressource : le payload ne fait pas autorité.

        return JobResult::success();
    }
}
```

### Les quatre règles du handler

1. **Idempotence.** La file livre au moins une fois. Un job interrompu entre un
   effet externe et l'écriture du statut sera rejoué.
2. **Ses propres délais.** Le worker ne peut pas interrompre une entrée-sortie
   bloquante ; chaque appel distant porte son timeout.
3. **Aucune confiance dans le payload.** Il porte des références ; les données
   protégées et les droits sont relus à l'exécution.
4. **`min_time` honnête.** C'est la durée attendue, entre 1 et 20 secondes. Une
   valeur sous-estimée ne fait pas échouer le job proprement : elle fait
   dépasser le budget du tick, et fixe un bail trop court.

Une exception levée est traitée comme un échec technique et journalisée ; un
`JobResult::failure()` est un échec métier avec un message. Les deux comptent
une tentative.

## Enfiler un job

Le producteur ne compose pas de tableau : la fabrique du handler fait foi.

```php
/** @var JobQueue $queue */
$queue = $this->getSharedExtensionsClass(JobQueue::class);

$queue->enqueue(SendNewsletterHandler::create($issueId));
```

`enqueue()` insère dans la transaction de l'appelant et ne valide rien : un
métier annulé ne laisse aucun job orphelin. La ligne de file est sa propre
boîte d'envoi.

| Méthode | Rôle |
| --- | --- |
| `enqueue(JobRequest $request): int` | Enfile et retourne l'identifiant |
| `status(int $id): ?array` | Lit l'état, l'erreur et les horodatages |
| `cancel(int $id): bool` | Annule un job en attente, ou demande l'arrêt d'un job en cours |
| `hasDueWork(): bool` | Indique s'il reste un job dû, sans charger de ligne |

Options d'enfilement, portées par `JobRequest` : `space_id`, `priority` (0–100,
défaut 50), `available_at`, `dedup_key`, `principal`, `exec_user_id`,
`max_attempts` (0–10), `created_by`, et `producer` (défaut : préfixe du type).

Avec `dedup_key`, un job déjà présent pour le même type et la même clé est
retourné au lieu d'en créer un second : `enqueue()` renvoie l'identifiant
existant.

## Payload

- JSON uniquement, 256 Ko au maximum. Un corps d'e-mail purifié peut être
  volumineux : c'est le seul payload légitimement gros, et il reste borné.
- Des références, jamais une autorité : identifiants, clés de modèle, options
  simples. Jamais de secret, de mot de passe, de jeton, ni d'entité complète.
- Un handler n'est jamais désigné par un nom de classe stocké en base : le type
  est résolu par le registre. Une ligne dont le type n'est plus fourni est
  marquée `failed` sans consommer de tentative.

## Identité d'exécution

| Principal | Contexte |
| --- | --- |
| `none` (défaut) | Aucun utilisateur, donc aucun droit métier |
| `system` | Compte de service système, tous les droits de l'installation |
| `user` | L'utilisateur désigné par `exec_user_id`, avec ses seuls droits |

Le compte système est une ligne `users` réservée : identifiant **2**, type
`service`, sans mot de passe, rattachée au groupe Super Admin. L'identifiant est
libre par construction, l'installation fixant `users.AUTO_INCREMENT = 1000`. Les
identifiants de 1 à 999 sont réservés : leur suppression est refusée en dur.

Chaque exécution construit une `Application` isolée, avec la configuration
clonée et sans routes. Les jobs d'un même tick qui partagent principal et Space
partagent cette application.

## Cycle d'exécution

| Statut | Sens |
| --- | --- |
| `pending` | En attente, à `available_at` |
| `processing` | Réservé par un worker, jusqu'à `reserved_until` |
| `completed` | Terminé |
| `failed` | Échec définitif, tentatives épuisées ou type indisponible |
| `cancelled` | Annulé avant exécution, ou arrêt demandé pendant l'exécution |

Un seul worker tourne à la fois : un verrou nommé non bloquant fait office de
mutualisation, et un appel qui le trouve pris s'arrête immédiatement. La
réservation d'une ligne est atomique ; un bail expiré est remis en attente par
le tick suivant.

Un job n'est réservé que si le budget restant du tick couvre son `min_time` :
un handler lent ne bloque pas les suivants.

| Déclencheur | Budget |
| --- | --- |
| CRON de la file | 20 jobs, 30 secondes |
| Trafic du site | 3 jobs, 5 secondes |

En cas d'échec, le délai avant nouvelle tentative est exponentiel :
30 secondes × 2ⁿ, plafonné à une heure, avec une gigue de 0 à 20 %. Les jobs
terminés sont purgés après 14 jours, au plus une fois par heure.

## Exploitation

Le worker écrit un battement dans `job_worker_state` (dernier passage, source,
budget consommé). Un battement ancien signale une installation dont le second
CRON n'est pas configuré.

Codes de journal, canal `jobs` et `system` : `JOB_FAILED`, `JOB_FAILED_FINAL`,
`JOB_CLAIM_FAILED`, `JOB_LEASE_EXPIRED`, `JOB_TYPE_UNAVAILABLE`,
`JOB_PAYLOAD_INVALID`, `JOB_PRINCIPAL_UNAVAILABLE`, `JOB_CANCELLED`,
`JOB_WORKER_SUMMARY`, `JOB_WORKER_BUDGET`, `JOB_WORKER_TOKEN_*`,
`JOB_SYSTEM_ACCOUNT_RECREATED`. Aucun payload, aucun secret et aucun contenu
utilisateur n'y figure.

## Administration

L'onglet **Jobs** de l'écran **Système**, réservé à ROOT, liste la file et filtre par statut, par type et par Space. Il affiche le détail d'un job — payload compris, en lecture seule — relance un job en échec avec un compteur neuf, annule un job en attente ou en cours, lance un passage du worker (10 jobs,
20 secondes) et purge les jobs terminés. Un avertissement apparaît quand le worker n'a pas tourné depuis cinq minutes.

L'onglet **Documentation** du même écran rappelle les deux lignes de CRON à installer, l'état des jetons (jamais leur valeur) et les plafonds appliqués. Le bilan de sécurité signale un jeton de worker absent ou un worker inactif alors que des jobs attendent.

## Vérification

```bash
curl -i "https://exemple.test/queue-worker?job_worker_token=<jeton>"
```

Sans jeton configuré, la réponse est `503` ; avec un jeton invalide, `401`. Le
corps retourné est un bilan de compteurs (`ran`, `completed`, `failed`,
`deferred`, `unavailable`, `recovered`, `purged`, `busy`, `duration_ms`).

## Limites

- Livraison **au moins une fois**, jamais exactement une fois.
- Un seul job à la fois, sans parallélisme ni préemption par priorité.
- Pas de chaînage de jobs : les enchaînements restent dans l'automatisation.
- Pas d'enfilement par API HTTP : les producteurs sont du code PHP de
  l'installation.
- Aucune exécution garantie sous la minute : la granularité est celle du CRON.
- Un handler bloqué dans une entrée-sortie n'est pas interrompu par le worker.
