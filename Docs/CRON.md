# CRON de l'installation

`plg_core` expose une route CRON unique pour les traitements récurrents des
extensions. Elle n'est pas réservée à l'automatisation.

La file de jobs différés a sa propre route et son propre jeton : elle ne passe
pas par ce dispatcher. Voir [File de jobs](JOBS.md).

## Route et authentification

La route accepte **GET et POST**, car certains hébergeurs comme Infomaniak
déclenchent les CRON en GET.

```http
GET /cron?cron_token=<jeton>
```

```http
POST /cron
Authorization: Bearer <jeton>
Content-Type: application/json

{"cron_token": "<jeton>"}
```

Le jeton vient d'une seule source non vide :

- en-tête `Authorization: Bearer <jeton>` ;
- query `cron_token` pour GET ou POST ;
- corps `cron_token` pour POST JSON ou formulaire.

Fournir plusieurs sources de jeton est refusé (`400`) pour éviter les
ambiguïtés. Le jeton est `cron_token` dans `configuration.php`, comparé en
temps constant avec `hash_equals()`. Il doit être long, aléatoire et rester
hors du dépôt.

### HTTP Basic et Digest

Le jeton applicatif est prioritaire. S'il est absent, la route accepte aussi
**HTTP Basic** avec :

- n'importe quel nom d'utilisateur non vide ;
- le mot de passe égal à `cron_token`.

Cela permet d'utiliser l'option « URL protégée par login / mot de passe »
d'Infomaniak sans ajouter un second secret. Les variables Apache
`PHP_AUTH_USER` / `PHP_AUTH_PW` sont lues lorsque l'en-tête n'est pas transmis
à PHP.

**Digest** doit rester validé par le serveur web : l'application ne connaît pas
le mot de passe en clair et ne gère pas les nonces. Si un en-tête Digest arrive
sans jeton applicatif, la réponse est `401`. En pratique, conserver aussi
`?cron_token=…` dans l'URL du CRON offre une défense en profondeur.

- jeton absent de la configuration : `503` ;
- jeton absent ou invalide : `401` ;
- jeton fourni par plusieurs sources : `400` ;
- jeton valide : exécution des hooks, réponse JSON avec le bilan.

La route est sans CSRF et sans transaction globale.

La crontab doit appeler la route **chaque minute** :

```cron
* * * * * /chemin/vers/script-cron.sh
```

Le script peut lire le jeton depuis un fichier privé ou l'environnement de la
crontab, plutôt que de l'exposer dans la ligne de commande visible par `ps`.
Avec Infomaniak, le jeton est nécessairement dans l'URL du GET : utiliser un
jeton dédié, long, et restreindre l'accès aux journaux HTTP. Une rotation
régulière est recommandée.

## Hooks et contrat d'extension

Une extension participe au CRON en implémentant `ICronProvider`
(`Core\ExtensionApi\ICronProvider`) et en utilisant
`TraitCronProvider` pour obtenir des méthodes vides par défaut. Cela évite
d'implémenter les trois fréquences lorsqu'une seule est utile. Le dispatcher
n'appelle que les extensions qui implémentent réellement l'interface : une
méthode de même nom sans contrat est ignorée.

```php
use YTassy\Tycms\CoreExts\Core\Cron\CronTick;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\ICronProvider;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCronProvider;
use YTassy\Tycms\Extensions\BasePlugin;

final class Plugin extends BasePlugin implements ICronProvider
{
    use TraitCronProvider;

    public function onCronMinute(CronTick $tick): array
    {
        return ['processed' => 3];
    }
}
```

Une extension déclare seulement les méthodes dont elle a besoin :

| Méthode | Fréquence | Appel |
| --- | --- | --- |
| `onCronMinute(CronTick $tick)` | chaque minute | à chaque appel de `/cron` |
| `onCron15Min(CronTick $tick)` | quart d'heure | minutes `00`, `15`, `30`, `45` |
| `onCronHour(CronTick $tick)` | heure | minute `00` |

`CronTick` porte l'instant du tick : `now`, `minute()`, `hour()`,
`isQuarterHour()`, `isHour()` et `iso()`. Il est identique pour tous les hooks
d'une même requête. Les fréquences 15 minutes et horaire suivent l'heure du
serveur ; une extension qui dépend d'un fuseau le résout elle-même.

Le dispatcher appelle les hooks dans l'ordre `minute`, `quarter`, `hour`, pour
chaque extension chargée qui possède la méthode. Une exception dans un hook est
journalisée (`CRON_PROVIDER_FAILED`) et n'empêche pas les autres hooks de
tourner.

## Réponse

```json
{
    "ok": true,
    "ran_at": "2026-11-18T09:00:05+01:00",
    "hooks": ["minute", "quarter", "hour"],
    "results": {
        "minute": {"plg_automation": {"due": 1, "started": 1, "succeeded": 1}},
        "quarter": {},
        "hour": {}
    },
    "errors": []
}
```

Chaque extension décide de la forme de son résultat. Le core agrège les
résultats sans interpréter les compteurs métier. Une erreur isolée produit
`ok: false` et une entrée dans `errors`, avec le hook et l'extension concernés.

## Idempotence et collisions

- Aucun verrou global n'est posé.
- Deux appels sur la même minute exécutent les hooks deux fois : chaque
  extension doit rendre son traitement idempotent ou se protéger par une clé de
  déduplication.
- Le core ne rattrape pas un tick manqué ; une extension qui a besoin d'une
  reprise doit exposer une action manuelle ou sa propre stratégie.
- Aucune transaction globale : chaque extension ouvre et valide ses
  transactions, et un échec partiel reste local à son traitement.

## Vérification

```bash
curl -i "https://exemple.test/cron?cron_token=<jeton>"

curl -i -X POST \
    -H "Authorization: Bearer <jeton>" \
    https://exemple.test/cron
```

Sans jeton configuré, la route répond `503`. Avec un jeton invalide, elle
répond `401`. Le jeton ne doit jamais être journalisé ; en GET, il apparaît
dans l'URL et donc dans les journaux d'accès de l'hébergeur.
