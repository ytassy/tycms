# Guide des contrôleurs

L'API d'administration des comptes et ses droits par espace sont détaillés dans
`Docs/USERS.md`.

Ce guide complète `ARCHITECTURE.md` et décrit comment écrire un contrôleur dans
Tycms. Un contrôleur est une frontière HTTP : il reçoit une requête déjà
routée et validée, délègue le métier à un WF, puis renvoie une réponse.

## Règles essentielles

- Un contrôleur ne contient pas de SQL.
- Un contrôleur ne contient pas de règle métier.
- Un contrôleur ne décide pas d'une ACL dépendant d'une ressource chargée.
- Les entrées hors chemin d'URL doivent être déclarées avec `ControllerParamN`.
- Une écriture peut utiliser `GET`, sous contrôle du WF et avec une protection
  explicite contre les déclenchements indésirables et les rejouements.
- Une méthode doit déléguer au WF et retourner une `Response`.
- Toute méthode de WF appelée par un contrôleur vérifie les droits nécessaires,
  même si le contrôleur porte déjà une annotation `#[Acl]`.

## Imports usuels

```php
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
```

Un contrôleur du plugin `Core` qui doit utiliser les workflows ajoute aussi
`TraitWF`.

## Déclarer un contrôleur API

```php
#[Controller(
    name: 'administrator_api_',
    isApi: true,
    path: 'administrator/api',
)]
class AdminApiController extends BaseController
{
    use TraitWF;
}
```

Les routes de ses méthodes seront préfixées par `/administrator/api`. Le nom de
route est le préfixe `name` suivi du nom déclaré sur la méthode.

## Endpoint de lecture sans paramètre

Un endpoint de lecture demande simplement les données au WF et les place dans
une réponse JSON.

```php
#[ControllerMethod(name: 'menu_read', path: 'menus')]
public function menu_read(): ApiResponse
{
    return new ApiResponse([
        'menus' => $this->wfMenu->getMenus(),
    ]);
}
```

La méthode HTTP par défaut de `ControllerMethod` est `GET`. Une opération en
lecture n'exige pas de protection supplémentaire liée à la méthode ; une route
`GET` qui écrit doit respecter la règle de sécurité décrite ci-dessous.

## Écriture déclenchée par GET

Le projet autorise un `GET` à modifier la base ou la session. Le contrôleur
reste mince et délègue la décision au WF. Le CSRF automatique des routes API
ne vérifie pas les requêtes `GET` : le parcours doit donc fournir sa propre
preuve d'intention ou de provenance, vérifiée côté serveur avant l'écriture.
Un callback OIDC vérifie notamment un `state` lié à la session et un code à
usage unique. Pour une autre action, définir une protection adaptée et prévoir
qu'un navigateur ou un intermédiaire puisse charger ou répéter l'URL sans clic
explicite de l'utilisateur. Voir `Docs/ARCHITECTURE.md`.

## Paramètres d'URL : `{nom}`

Un segment entre accolades dans `path` devient un paramètre de route. Son nom
doit correspondre exactement à un argument de la méthode PHP. Il n'est pas
nécessaire de lui ajouter un `ControllerParamN` : le routeur l'extrait du chemin
avant l'appel du contrôleur.

```php
#[Controller(name: 'Test', isApi: false, path: 'test')]
class TestController extends BaseController
{
    #[ControllerMethod(
        name: 'UrlParams',
        path: '{id}/test',
        where: ['id' => '\\d+'],
    )]
    public function urlParams(int $id)
    {
        return $this->getViewResponse(new Test($this, $id));
    }
}
```

Cette déclaration correspond à l'URL `/test/42/test`. La contrainte `where`
associe une expression régulière au paramètre : ici, seuls les chiffres sont
acceptés. Si la contrainte ne correspond pas, la route n'est pas résolue.

Plusieurs paramètres sont possibles :

```php
#[ControllerMethod(
    name: 'UrlParams2',
    path: '{id}/{slug}/{section}/test',
    where: [
        'id' => '\\d+',
        'slug' => '[a-zA-Z]+',
    ],
)]
public function urlParams2(int $id, string $slug, string $section)
{
    // URL valide : /test/42/bonjour/details/test
}
```

Sans règle `where`, le segment est capturé de manière permissive. Il est donc
préférable de définir une contrainte pour les IDs, slugs et formats connus. Les
paramètres de route sont transmis par leur nom; l'ordre des arguments PHP n'a
pas d'importance, mais leurs noms doivent correspondre.

Pour recevoir l'objet de requête, utiliser l'argument spécial `_request`, et
non `$request` :

```php
use YTassy\Tycms\Request\Request;

public function urlParams(int $id, Request $_request)
{
    $referer = $_request->header('Referer');
}
```

## Paramètre obligatoire et typé

Les paramètres venant de la query string ou du corps de la requête doivent être
déclarés. Le nom de l'attribut doit correspondre au nom de l'argument PHP. Les
paramètres du chemin utilisent quant à eux la syntaxe `{nom}` décrite ci-dessus.

```php
#[ControllerMethod(
    name: 'menu_move_up',
    path: 'menus/move-up',
    methods: [RequestMethod::POST],
)]
#[ControllerParam1(
    name: 'menuItemId',
    required: true,
    varType: VarType::INT,
)]
public function menu_move_up(int $menuItemId): ApiResponse
{
    return new ApiResponse([
        'moved' => $this->wfMenu->moveMenuItemUp($menuItemId),
    ]);
}
```

Le routeur vérifie la présence et le format de `menuItemId` avant d'exécuter la
méthode. Le WF doit ensuite vérifier que cet ID représente une ressource valide
et que l'action demandée est cohérente.

Types disponibles :

| Type | Usage |
| --- | --- |
| `VarType::INT` | Entiers positifs, par exemple un ID. |
| `VarType::STRING` | Texte (valeur par défaut). |
| `VarType::BOOL` | Booléens JSON `true`/`false`, entiers `1`/`0` ou chaînes `"true"`/`"false"`/`"1"`/`"0"` (sans distinction de casse). |
| `VarType::FLOAT` | Nombre décimal positif. |
| `VarType::JSON` | JSON décodé en objet. |
| `VarType::JSON_ARRAY` | JSON décodé en tableau. |

Les valeurs tableaux/objets reçues à la place d'un scalaire sont refusées
avant les regex ou conversions. `JSON` et `JSON_ARRAY` attendent une **chaîne
JSON sérialisée**, y compris dans un corps HTTP JSON ; les tableaux et objets
contenus dans cette chaîne restent admis selon le schéma. Un paramètre invalide
produit une réponse HTTP 400 avant contrôleur et transaction (JSON d'erreur
pour une API, page d'erreur sinon). Un CSRF API invalide garde sa priorité 403.

## Valider un JSON avec un schéma ObjectValidator

Pour un paramètre JSON structuré, préférer `schemaFile` sur
`ControllerParamN`. Le schéma est chargé côté serveur, puis le routeur appelle
`ObjectValidator::normalizeAndValidate()` avant le contrôleur. `schemaFile`
implique `VarType::JSON` ; ne pas renseigner un autre `varType`.

```php
#[ControllerMethod(
    name: 'plugin_config_save',
    path: 'configuration',
    methods: [RequestMethod::POST],
)]
#[ControllerParam1(
    name: 'params',
    required: true,
    schemaFile: __DIR__ . '/../Schemas/PluginParams.php',
)]
public function plugin_config_save(object $params): ApiResponse
{
    return new ApiResponse([
        'saved' => $this->wfPluginConfig->save($params),
    ]);
}
```

`PluginParams.php` retourne un tableau PHP de confiance :

```php
<?php

return [
    'type' => 'object',
    'properties' => [
        'enabled' => ['type' => 'boolean'],
        'maxItems' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
    ],
    'required' => ['enabled'],
];
```

Le contrôleur reçoit une `stdClass` normalisée. En développement, une erreur
de schéma contient le paramètre et le détail ObjectValidator ; hors
développement, le routeur retourne le message générique du paramètre. Cette
validation ne vérifie ni les ACL ni les règles métier : le WF doit encore
contrôler le périmètre autorisé et la cohérence de la configuration.

Un formulaire déclaré avec `core.json` doit utiliser le même fichier de schéma
pour son éditeur Vue et pour `schemaFile`. Voir `Docs/FORMS.md` pour le contrat
complet.

## Plusieurs paramètres

Utiliser les annotations successives `ControllerParam1`, `ControllerParam2`,
etc. Chaque nom doit correspondre à l'argument de la méthode.

```php
#[ControllerMethod(
    name: 'item_status',
    path: 'menus/item/status',
    methods: [RequestMethod::POST],
)]
#[ControllerParam1(name: 'menuItemId', required: true, varType: VarType::INT)]
#[ControllerParam2(name: 'status', required: true, varType: VarType::STRING)]
public function item_status(int $menuItemId, string $status): ApiResponse
{
    $item = $this->wfMenu->changeItemStatus($menuItemId, $status);

    return new ApiResponse(['item' => $item]);
}
```

Cet exemple montre la forme du contrôleur; la validation et la conversion des
valeurs métier de `status` (`1`, `0`, `-1`, `-2`) appartient à `WFMenu`, pas au
contrôleur. Le validateur actuel de `VarType::INT` accepte uniquement les
entiers strictement positifs, ce qui convient aux IDs mais pas à ce statut.

## ACL globale statique

Une ACL qui ne dépend pas d'un objet précis peut être appliquée directement à
la méthode.

```php
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\AnnotationsController\Acl;

#[ControllerMethod(name: 'info', path: 'infos_system')]
#[Acl(globalPermission: Permissions::ROOT)]
public function infos_system(): ApiResponse
{
    return new ApiResponse($this->wfSystemInfo->getInfos());
}
```

Le runner refuse l'accès avant l'exécution du contrôleur si l'utilisateur ne
dispose pas de la permission. La méthode `WFSystemInfo::getInfos()` vérifie
également `ROOT` avant de retourner les routes et les informations PHP
structurées. Cette vérification reste obligatoire si la méthode est appelée
depuis un autre point d'entrée.

## ACL dépendante d'une ressource

Ne pas mettre une ACL de route lorsque le droit dépend de la ressource, par
exemple de l'espace auquel appartient un menu. Le contrôleur délègue au WF :

```php
#[ControllerMethod(
    name: 'menu_move_down',
    path: 'menus/move-down',
    methods: [RequestMethod::POST],
)]
#[ControllerParam1(name: 'menuItemId', required: true, varType: VarType::INT)]
public function menu_move_down(int $menuItemId): ApiResponse
{
    return new ApiResponse([
        'moved' => $this->wfMenu->moveMenuItemDown($menuItemId),
    ]);
}
```

Puis le WF charge l'item, trouve son menu et vérifie la permission sur
`$menu->space`. Le client ne doit jamais transmettre l'espace comme source
d'autorité.

## Types de retour

Une méthode de contrôleur doit retourner une instance de `Response`. Les trois
cas les plus courants sont l'API JSON, une vue HTML classique et une vue RIA
Vue.js.

### API JSON : `ApiResponse`

Une réponse API normale utilise `ApiResponse` :

```php
return new ApiResponse([
    'item' => $item,
    'updated' => true,
]);
```

Elle est adaptée aux contrôleurs déclarés avec `isApi: true`. Les données sont
sérialisées en JSON et incluent les champs standard `error`, `is_message`,
`message_level` et `message`.

### Vue HTML classique : `BaseView`

Une vue classique hérite de `BaseView`, définie uniquement dans
`src/releases/vdev/core_extensions/Core/ExtensionApi/BaseView.php`, également utilisée par
les extensions. `RiaView` hérite de cette classe.

Par défaut, son template est recherché dans
`tmpl/<nom-de-classe-en-snake_case>.php`, à côté de la classe de vue. Les
propriétés protégées de la vue sont exposées au template.

```php
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseView;

class Article extends BaseView
{
    protected array $article;

    public function __construct(BaseApplicationItem $app, array $article)
    {
        parent::__construct($app);
        $this->article = $article;
    }
}
```

Le contrôleur rend la vue au moyen de `getViewResponse()` :

```php
public function article(int $id)
{
    $article = $this->wfArticle->getArticle($id);

    return $this->getViewResponse(new Article($this, $article));
}
```

`getViewResponse()` produit une `HtmlResponse` et applique le template de site
actif par défaut. Ne pas appeler `render()` directement dans un contrôleur.

### Vue RIA Vue.js : `RiaView`

`RiaView` est une vue HTML spécialisée qui crée le point de montage de Vue et
transmet des props initiales encodées en JSON. Elle est utilisée lorsqu'une page
est rendue par un composant de `src/ria/src/pages`.

```php
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\RiaView;

class Test extends RiaView
{
    public function __construct(BaseApplicationItem $app, int $id)
    {
        parent::__construct(
            $app,
            'tests/test1',
            ['id' => $id],
            false,
            true,
        );
    }
}
```

Les arguments de `RiaView` sont, dans l'ordre :

| Argument | Rôle |
| --- | --- |
| `$app` | Contexte de l'application, généralement `$this` depuis le contrôleur. |
| `$vueComponent` | Nom du composant page, par exemple `tests/test1` pour `src/ria/src/pages/tests/test1.vue`. |
| `$vueProps` | Props initiales sérialisées dans le bootstrap JSON. |
| `$container` | Active ou non le conteneur HTML standard. |
| `$containerFluid` | Utilise ou non la variante fluide du conteneur. |

Le contrôleur utilise la même méthode de retour :

```php
public function urlParams(int $id)
{
    return $this->getViewResponse(new Test($this, $id));
}
```

La template RIA produit un élément avec `data-vue-app`, le nom de composant et
un identifiant de bootstrap unique. `src/ria/src/main.js` détecte cet
élément, charge la page Vue correspondante et lui passe les props. Les props
RIA sont des données initiales; les données partagées ou modifiables doivent
ensuite être gérées dans les stores Pinia et via les API.

### Autres réponses

`HtmlResponse` est généralement construite par `getViewResponse()`. Utiliser
`RedirectResponse` lorsqu'une action doit mener vers une autre URL. Éviter de
retourner une chaîne ou un tableau brut : le runner attend une `Response`.

Le constructeur et les fabriques usuelles de `RedirectResponse` acceptent
uniquement un chemin interne commençant par un seul `/`. Ils refusent `//`,
les antislashs, espaces bruts et caractères de contrôle. Encoder les valeurs
via le routeur ; une query et un fragment correctement encodés sont conservés.
Une cible invalide déclenche `InvalidArgumentException`. Le renvoi après erreur
de formulaire revient à `/` si l'URI brute n'est pas admissible.

Pour un départ externe explicitement autorisé, utiliser
`RedirectResponse::external($url)` : HTTPS uniquement, avec HTTP admis pour
`localhost`, `127.0.0.1` et `[::1]`. Les identifiants dans l'autorité sont refusés.
Cette validation de format ne choisit pas les destinataires autorisés : le WF
doit sélectionner le fournisseur et sa configuration dans le bon Space, comme
pour le départ SSO. La réponse reste dans le framework, sans dépendance Core
ni ajout d'un domaine issu de la requête.

## Erreurs métier

Pour une erreur métier attendue, le WF utilise `ExceptionResponse` :

```php
ExceptionResponse::throwWarning('Élément de menu introuvable.');
```

Le runner convertit cette exception en réponse API structurée. Le contrôleur ne
doit donc pas attraper cette exception pour reconstruire une réponse.

Pour une ressource introuvable, lancer `NotFoundException`. Le runner annule la
transaction puis affiche la page 404 commune pour une route HTML ou renvoie une
réponse JSON d'erreur HTTP 404 pour une API. Le message public est générique :
le détail éventuel de l'exception n'est pas exposé au client.

## Transactions

Par défaut, `ApplicationRouteRunner` encadre l'exécution d'une route par une
transaction. Le contrôleur et le WF ne font pas de `COMMIT` ni de `ROLLBACK`.
Les helpers effectuent leurs écritures dans cette transaction.

Une route exceptionnelle qui ne doit réellement pas être transactionnelle doit
être annotée :

```php
use YTassy\Tycms\Routing\Annotation\NoTransaction;

#[ControllerMethod(name: 'external_ping', path: 'external/ping')]
#[NoTransaction]
public function external_ping(): ApiResponse
{
    return new ApiResponse(['ok' => true]);
}
```

N'ajouter `NoTransaction` que lorsqu'il existe une raison explicite : il ne
doit pas servir à contourner un problème d'écriture en base.

## Checklist avant validation

1. La route et la méthode HTTP sont-elles adaptées ?
2. Tous les paramètres externes sont-ils déclarés et typés ?
3. Le contrôleur appelle-t-il un WF, sans SQL ni logique métier ?
4. Chaque méthode de WF appelée vérifie-t-elle les droits nécessaires, y compris
   quand `#[Acl]` protège déjà la route ? Si le droit dépend d'une ressource, le
   WF utilise-t-il son périmètre réel ?
5. La méthode retourne-t-elle une `Response` cohérente ?
6. Les écritures restent-elles dans la transaction gérée par le runner ?
