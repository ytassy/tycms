# Formulaires déclaratifs

`Form` décrit un formulaire de confiance côté PHP. Il n'est ni enregistré en
base, ni automatiquement exposé par une route : le plugin, module ou template
propriétaire choisit son endpoint de lecture et son workflow de sauvegarde.

Une même définition est matérialisée pour Vue avec `getDefForRia()`. Cette
méthode résout les options dynamiques côté serveur et ne retourne que des
données JSON. Les requêtes SQL, callbacks et objets PHP n'atteignent jamais le
navigateur.

## Convention de développement : PHP et API

Définir chaque formulaire en PHP dans un `FormsHelper` du namespace métier qui
possède la ressource. Un même plugin peut donc avoir plusieurs classes nommées
`FormsHelper`, par exemple `Core\Helper\Menu\FormsHelper`,
`Core\Helper\Space\FormsHelper` et `Core\Helper\SsoProvider\FormsHelper`.
Ne pas regrouper les formulaires de ressources distinctes dans un helper
central. Les types de champs partagés et leur enregistrement restent séparés
des définitions de formulaires, dans `FormTypesHelper` pour Core.

Ces helpers de formulaires sont internes au plugin propriétaire. Dans Core,
leurs accès typés (`coreMenuFormsHelper`, `coreSpaceFormsHelper`,
`coreSsoProviderFormsHelper` et `coreFormTypesHelper`) sont déclarés dans
`Helper/TraitAllHelpers.php`, réservé au code interne de Core. Ne pas les
ajouter à `ExtensionApi/Traits/TraitCoreSharedHelpers.php`, qui expose les helpers Core
destinés aux autres extensions. Chaque extension définit ses propres
formulaires dans ses namespaces métier et les expose par son API.

Le parcours recommandé est le suivant :

1. Le helper métier construit le `Form` en PHP, y compris ses options calculées
   côté serveur. Le WF vérifie les droits sur la ressource concernée.
2. Un endpoint de lecture renvoie par API `getDefForRia($initialValues)` ; le
   client reçoit la définition et les valeurs initiales, puis les affiche avec
   `FormRenderer.vue`.
3. Le client soumet les valeurs à un endpoint d'écriture. Le WF reconstruit le
   formulaire adapté à la ressource et appelle
   `validateSubmittedValues($data)` avant les validations métier et la
   persistance. Le navigateur ne fournit jamais la définition de confiance.

Le contrôleur se limite à l'adaptation HTTP et à `ApiResponse`. Les contrôles
ACL et la validation métier restent dans le WF ; les écritures SQL/ORM restent
dans les helpers. Les formulaires d'espaces, de menus et de fournisseurs SSO de
Core illustrent ce parcours.

## Définir un formulaire

La forme fluide est pratique pour les définitions écrites en PHP :

```php
use YTassy\Tycms\Lib\Forms\Form;

$form = (new Form($this))
    ->setId('catalog.product.edit')
    ->setLabel('Produit')
    ->addSection('general', 'Informations générales')
    ->addField('general', 'title', 'core.text', [
        'label' => 'Titre',
        'required' => true,
        'validation' => ['maxLength' => 255],
    ])
    ->addField('general', 'status', 'core.radio', [
        'label' => 'Statut',
        'default' => 'draft',
        'options' => [
            'draft' => 'Brouillon',
            'published' => 'Publié',
        ],
    ]);
```

Le même contrat peut être fourni sous forme de tableau avec `new Form($this,
$definition)` ou `Form::fromArray($this, $definition)`. Les identifiants de
formulaire, sections et champs sont uniques et acceptent lettres, chiffres,
`_`, `-` et `.`.

Les champs standard disponibles aujourd'hui sont : `core.text`,
`core.textarea`, `core.color`, `core.number`, `core.hidden`, `core.select`,
`core.radio`, `core.switch`, `core.icon-picker`, `core.note`, `core.json`,
`core.code` et `core.computed`. Le plugin Core fournit aussi `core.timezone` et `core.font_family`.

Le type Core `core.rich_text` édite une chaîne HTML avec le `rich_text_editor`
commun. Il reçoit `context.spaceId` pour la médiathèque et respecte les états
`readonly` / `disabled`. La validation de chaîne et les bornes du champ restent
côté formulaire ; le workflow propriétaire doit appeler `HtmlSanitizer::sanitize()`
avant la persistance, comme `WFSpace` pour la description longue. Ce type ne
crée pas de variantes par langue ; utiliser `core.localized_text` pour cela.

Core fournit également `core.media_file`, qui stocke une URL de fichier sous
forme de chaîne. `mediaType: 'image'` limite le sélecteur aux fichiers dont le
MIME est une image ; `mediaType: 'video'` filtre les vidéos ;
`mediaType: 'audio'` filtre les fichiers audio ;
`mediaType: 'all'` (défaut) montre tous les fichiers. Le
sélecteur reçoit l'ID du Space via `FormRenderer` :

```vue
<form_renderer v-model="values" :definition="formDefinition"
    :context="{ spaceId: selectedSpace.id }" />
```

Le contrôle Vue réutilisable `common/MediaFileInput.vue` fournit la saisie et
le sélecteur de médias sans dépendre d'un formulaire. `MediaFileField` l'entoure
de `BaseField` et adapte les options du champ (`mediaType`,
`allowManualInput`, `manualInputMode`) et le `spaceId` du contexte. Pour un
usage hors formulaire, importer `MediaFileInput` avec `space-id` ; `size="sm"`
réduit la hauteur du champ et des boutons.

La question accepte la saisie directe par défaut. `allowManualInput: false`
rend l'URL en lecture seule et laisse le choix par la médiathèque. Par défaut,
`manualInputMode: 'media'` exige une URL `/medias/` correspondant à un fichier
réel de ce Space, et le workflow de sauvegarde doit contrôler `MEDIA_READ` ou
`MEDIA_CRUD` avant cette validation. Avec `manualInputMode: 'any'`, une saisie
directe peut être un chemin local absolu ou une URL HTTP(S) valide ; le type
`image`, `video` ou `audio` ne filtre alors que le sélecteur, car le MIME d'une URL externe ne peut
pas être vérifié sans la télécharger. Cette option requiert la saisie directe.
Le schéma du type borne la valeur à 2 048 caractères ; le workflow propriétaire
reste responsable des contrôles métier. `WFExtensions` applique ces contrôles
aux configurations d'extension.

`core.select` accepte `multiple: true` pour une liste de valeurs. Son rendu Vue
accepte `ui.size` pour fixer la hauteur visible du sélecteur multiple.
Avec `ui.picker: 'dropdown-list'`, un champ multiple affiche les valeurs
choisies comme étiquettes supprimables et utilise le composant commun
`DropdownList` pour rechercher et ajouter une option. Les valeurs et la
validation du formulaire restent celles de `core.select`. `ui.addLabel` peut
personnaliser le libellé du bouton d'ajout.

`core.note` est uniquement informatif et ne porte pas de `name`. Les autres
champs ont un nom et leur valeur est placée dans `initialValues` lors de
l'export RIA. Son option `ui.variant: 'plain'` affiche le repère sans encadré
coloré, pour les formulaires denses où les notes servent de titres ou
d'explications continues. Sans cette option, la note conserve l'encadré
d'information commun.

## Contexte et formulaires de composants de page

`FormRenderer` transmet sa prop `context` à tous les types de champs. Un type
spécialisé peut la déclarer pour lire le Space et les données autorisées.

Le type Core `core.localized_text` utilise le contrôle localisé commun. Il
accepte `mode` (`text`, `textarea`, `html`, `rich`) et `maxLength` (500 par
défaut dans le contrôle). Dans les composants de page, le registre normalise
les langues et purifie les variantes HTML avant validation. Les autres
workflows qui l'utilisent doivent appliquer cette même normalisation métier.

Un composant de page peut fournir un `Form` pour son onglet Général, sans
composant Vue spécifique ni nouvelle compilation pour les champs déjà livrés.
Les données éditent ses propriétés dans le brouillon commun. Contrat complet :
[Composants de page](PAGE_COMPONENTS.md#onglet-général--formulaire-php-ou-vue).

Lors d'un enregistrement, utiliser la modale d'attente commune, fermée dans un
`finally`, puis la modale de succès. Ne pas désactiver les champs pendant la
requête. Voir [Frontend](FRONTEND.md#attente-succès-et-messages).

## Valeurs par défaut

`getDefaultValues()` retourne un jeu de données complet pour les champs
éditables du formulaire. Une clé `default` déclarée sur le champ est toujours
prioritaire. Sans cette clé, le type fournit un défaut implicite ; les notes et
autres champs sans valeur sont ignorés.

| Type | Défaut implicite |
| --- | --- |
| `core.text`, `core.textarea`, `core.code`, `core.hidden` | `''` |
| `core.color` | `'#000000'` |
| `core.switch` | `false` |
| `core.icon-picker` | `''` |
| `core.number`, `core.select`, `core.radio` | `null` : aucune valeur métier n'est inventée |
| `core.timezone` | `'Europe/Paris'` |
| `core.json` | Valeur construite depuis le schéma statique |
| `core.computed` | Aucune : la valeur est calculée côté PHP avant persistance |

Pour `core.json`, l'ordre est : mot-clé JSON Schema `default`, puis `const`,
puis la première valeur de `enum`. Sans ces valeurs, le générateur produit une
chaîne vide, `false`, `0`/`minimum`, un objet rempli de ses propriétés requises
ou un tableau de `minItems`. Un type d'extension peut fournir son propre défaut
implicite avec l'argument nommé `implicitDefault` de `FormFieldType`.

```php
$form = (new Form($this))
    ->setId('catalog.product.create')
    ->addSection('general', 'Général')
    ->addField('general', 'status', 'core.radio', [
        'default' => 'draft',
        'options' => ['draft' => 'Brouillon'],
    ])
    ->addField('general', 'params', 'core.json', [
        'default' => ['maxItems' => 10],
        'schema' => $paramsSchema,
    ]);

// ['status' => 'draft', 'params' => ['maxItems' => 10]]
$defaults = $form->getDefaultValues();
```

`getDefForRia($values)` part de ce même jeu de défauts et le remplace par les
valeurs de l'enregistrement fourni. Cette méthode est donc la référence pour
créer un nouvel enregistrement ou initialiser une page Vue.

## Conditions de visibilité

La clé `visibleWhen` est une règle statique évaluée par `FormRenderer.vue`.

```php
'visibleWhen' => [
    'field' => 'showNavigation',
    'operator' => 'equals',
    'value' => true,
],
```

Les opérateurs initiaux sont `equals`, `notEquals`, `in`, `notIn`, `truthy` et
`falsy`. Les groupes `{ all: [...] }`, `{ any: [...] }` et `{ not: ... }` sont
également admis. Ces règles concernent l'affichage, jamais la sécurité ou la
validation métier.

## Options statiques, callback et SQL

`core.select`, `core.radio`, ou un type personnalisé qui accepte les options,
peuvent recevoir une liste statique sous `options`. Chaque option est exportée
sous la forme `{ value, label, disabled?, data? }`. Le champ facultatif
`data` contient des métadonnées à clés textuelles et valeurs scalaires JSON
(ou `null`), destinées aux composants spécialisés. Les objets et les tableaux
imbriqués y sont refusés.

Pour une liste dépendant du contexte autorisé, utiliser un fournisseur :

```php
use YTassy\Tycms\Lib\Forms\CallbackFormOptionsProvider;

'optionsProvider' => new CallbackFormOptionsProvider(
    static fn ($context) => [
        ['value' => $context->getAttribute('spaceId'), 'label' => 'Espace courant'],
    ],
),
```

`SqlFormOptionsProvider` exécute une requête `SELECT` ou `WITH` de confiance
et exige par défaut les colonnes `value` et `label`. Les paramètres dynamiques
sont fournis séparément et sont substitués par `Database` avec les marqueurs
`%`. Le SQL métier doit de préférence rester dans un helper du plugin, qui peut
alors être appelé par un `CallbackFormOptionsProvider`.

Un type personnalisé peut aussi porter des options communes avec
`defaultOptions`, ou un fournisseur commun avec `defaultOptionsProvider`. Ces
deux propriétés sont exclusives et exigent `acceptsOptions: true`. La
définition du champ reste prioritaire : `options` ou `optionsProvider` sur le
champ remplace entièrement la source d'options du type.

Ne construire ni SQL, ni nom de table ou de colonne avec une valeur HTTP.
L'ACL reste contrôlée par le workflow avant de construire le formulaire.

## Champ fuseau horaire

`core.timezone` est un `SelectField` fourni par le plugin Core. Ses options
proviennent de `timezone_identifiers_list(DateTimeZone::ALL)` : elles suivent
donc la liste de fuseaux de PHP, sans les alias historiques de compatibilité
(`DateTimeZone::ALL_WITH_BC` n'est volontairement pas utilisé). La valeur est
validée côté serveur contre cette même liste.

```php
->addField('general', 'timezone', 'core.timezone', [
    'label' => 'Fuseau horaire',
    'required' => true,
])
```

## Champ couleur

`core.color` produit une chaîne au format `#RRGGBB` ou `#RRGGBBAA` (alpha
optionnel). Son composant Vue s'appuie sur le sélecteur de couleur natif du
navigateur, un réglage d'opacité et une palette de pastilles dans un petit menu
déroulant ; aucune dépendance NPM n'est nécessaire. La valeur hexadécimale est
aussi une saisie éditable :
elle peut être copiée, collée ou modifiée directement.
`common/ColorInput.vue` porte ces contrôles sans dépendre d'un formulaire ;
`ColorField` l'entoure de `BaseField` et adapte `allowAlpha`, `palette` et les
états de validation du formulaire.
`ColorInput` est enregistré globalement sous le nom `color_input` : les vues et
`ColorField` l’utilisent sans import local.

`ColorInput` accepte aussi une prop facultative `templateColors`, liste
`[{variable, label}]`, pour proposer des références CSS `var(--nom)`. `null`
(défaut) désactive ce mode. Deux boutons à icônes à droite du champ permettent
de basculer entre couleur personnalisée et couleur de template ; le mode actif
est indiqué visuellement et par `aria-pressed`. L’opacité et la palette hexadécimale sont masquées
lorsqu’une variable est sélectionnée ; revenir au mode personnalisé restaure
la dernière couleur hexadécimale du contrôle. Une référence retirée du catalogue
est conservée et signalée. Cette option d’interface ne modifie pas le schéma
PHP de `core.color`, qui reste hexadécimal : son appelant doit porter le contrat
serveur adapté. L’éditeur de pages le fait avec `PageColorInput` et `PageColors` ;
voir [Couleurs de la template](PAGE_EDITOR.md#couleurs-de-la-template).

```php
->addField('general', 'accentColor', 'core.color', [
    'label' => 'Couleur principale',
    'default' => '#0D6EFD',
    'allowAlpha' => true, // true par défaut
    'palette' => ['#0D6EFD', '#198754', '#DC3545', '#AABBCC80'],
]);
```

La palette par défaut propose 60 teintes, dont les 12 couleurs initiales,
dans un menu de six colonnes. `palette` est une liste statique facultative de
couleurs valides qui remplace cette palette. Si `templateColors` est fourni,
les couleurs de la template apparaissent en tête de palette en mode personnalisé.
À chaque ouverture, leurs valeurs CSS sont résolues dans le contexte du contrôle,
puis converties en hexadécimal sRGB via un canevas de 1 pixel. Un clic conserve
une couleur fixe `#RRGGBB` (ou `#RRGGBBAA` si elle porte un alpha et si le champ
l’accepte), comme les autres pastilles ; il ne crée pas de référence `var(...)`.
Les variables absentes ou les valeurs non reconnues comme couleurs sont omises.
Sans accès au canevas, la palette habituelle reste disponible. Avec
`allowAlpha: false`, le sélecteur masque l'opacité et l'API n'accepte que le
format `#RRGGBB`.

## Champ de code

Le composant Vue commun `CodeEditor.vue` adapte sa hauteur au contenu par
défaut, y compris dans l'éditeur de pages et pour Mermaid. Un écran peut
encore limiter explicitement cette hauteur par son propre style.
La touche Tab insère un caractère de tabulation (`\t`) au curseur ; une
sélection est indentée en tabulations. Maj+Tab retire une indentation.
Ces commandes respectent la lecture seule et les limites de taille du champ.

`core.code` affiche l'éditeur CodeMirror et produit une chaîne. La propriété
statique `syntax` choisit la coloration et l'indentation : `javascript` (par
défaut), `html`, `css`, `scss`, `json` ou `mermaid`. L'alias `js` est aussi accepté pour
JavaScript. Une autre valeur est refusée lorsque la définition est utilisée.
Le mode `mermaid` utilise `codemirror-lang-mermaid` (version verrouillée) : sa
coloration couvre certains types de diagrammes, sans restreindre leur saisie
ni remplacer la validation du moteur de rendu Mermaid.

```php
->addField('advanced', 'template', 'core.code', [
    'label' => 'Gabarit',
    'syntax' => 'html',
    'default' => '<main></main>',
])
```

Pour limiter exactement le poids d'un contenu soumis, `validation.maxBytes`
applique une limite en octets côté navigateur et côté serveur. `maxLength`
reste une limite en caractères. Par exemple, un SCSS personnalisé est limité à
`100 * 1024` octets :

```php
'validation' => ['maxBytes' => 100 * 1024],
```

## Champ calculé

`core.computed` affiche une valeur produite par PHP, mais ne l'accepte jamais
depuis le navigateur. Il doit définir un `schema` de sortie et un
`computedProvider` de confiance. Ce provider est retiré de la définition RIA,
ne s'exécute pas lors du rendu Vue et ne peut pas être envoyé par HTTP.

```php
use YTassy\Tycms\Lib\Forms\CallbackFormComputedProvider;
use YTassy\Tycms\Lib\Forms\FormComputedContext;

->addField('scss', 'custom_compiled_css', 'core.computed', [
    'label' => 'CSS compilé',
    'syntax' => 'css', // rend un CodeMirror en lecture seule
    'schema' => ['type' => 'string'],
    'computedProvider' => new CallbackFormComputedProvider(
        function (FormComputedContext $context) use ($compiler): string {
            return $compiler->compile($context->getValue('custom_scss', ''));
        },
    ),
])
```

Les syntaxes de `core.code` sont aussi admises : `javascript`/`js`, `html`,
`css`, `scss`, `json` et `mermaid`. Sans `syntax`, la valeur est rendue dans un bloc texte.
La clé `default` est interdite : aucune valeur calculée n'est inventée côté
client.

Après avoir validé le payload éditable, le workflow hydrate les valeurs par
défaut puis résout les champs calculés. `resolveComputedValues()` les traite
dans leur ordre de déclaration et valide le jeu complet avant la persistance :

```php
$validation = $form->validateSubmittedValues($params, $context);
if (!$validation->isValid()) {
    // Retourner l'erreur utilisateur.
}

$values = $form->resolveComputedValues(
    $form->getInitialValues(get_object_vars($validation->value)),
    $context,
);
```

`FormComputedContext` expose les valeurs déjà validées, les champs calculés
précédents et les attributs de contexte de confiance. Un provider ne doit pas
écrire lui-même en base ; il retourne seulement sa valeur. Le même calcul est
utilisé lors de la création automatique d'une configuration et à chaque
sauvegarde. Sans prévisualisation, Vue conserve la dernière valeur enregistrée
jusqu'au retour d'une sauvegarde réussie.

### Imports SCSS locaux

`ScssCompiler::compileScss()` accepte facultativement une racine d'import SCSS
de confiance. Sans cette racine, les imports sont désactivés. Lorsqu'elle est
fournie, `SafeScssImporter` n'autorise que les fichiers `.scss` et `.sass` dont
le chemin réel reste sous cette racine, y compris après résolution des liens
symboliques. Les chemins relatifs contenant `..`, les chemins absolus hors de
cette racine et les schémas d'URL non locaux sont refusés.

```php
$compiledCss = ScssCompiler::compileScss(
    $context->getValue('custom_scss', ''),
    __DIR__ . '/scss',
);
```

La racine doit être un chemin PHP fixe, jamais une valeur fournie par le
navigateur. Ce confinement concerne les lectures de fichiers par PHP ; il ne
filtre pas les `url(...)` ou `@import url(...)` conservés dans le CSS final.
Les erreurs de compilation SCSS renvoyées à l'utilisateur restent génériques
en production ; leur détail n'est ajouté qu'en modes `dev` ou `debug`.

## Champ JSON avec schéma statique

Le type `core.json` délègue à `JsonEditor`, déjà présent dans le frontend. Son
schéma est une donnée statique de la définition :

```php
->addField('advanced', 'params', 'core.json', [
    'label' => 'Paramètres',
    'schema' => [
        'type' => 'object',
        'properties' => [
            'maxItems' => ['type' => 'integer', 'minimum' => 1],
        ],
        'required' => ['maxItems'],
    ],
])
```

Pour fournir des références de schémas à l'éditeur JSON, utiliser la forme
`['schema' => $rootSchema, 'schemas_ref' => $references]`. Le schéma est
transmis tel quel à Vue ; les formats PHP enregistrés dans `ObjectValidator` ne
font pas partie de ce contrat UI. Un schéma utilisé par `core.json` doit donc
rester dans le sous-ensemble JSON Schema portable que l'éditeur sait afficher.

## Validation serveur avec ObjectValidator

Le rendu Vue améliore la saisie, mais n'autorise jamais une donnée. Pour une
ressource au schéma fixe, l'endpoint déclare son schéma JSON de confiance dans
`ControllerParamN`. Le routeur décode le JSON puis appelle
`ObjectValidator::normalizeAndValidate()` **avant** l'exécution du contrôleur.
Le contrôleur reçoit ainsi la valeur normalisée seulement si elle est valide ;
le WF conserve les validations métier, les ACL et la persistance.

Pour un champ `core.json`, le schéma d'édition et le schéma serveur doivent
venir du même fichier PHP. Cela évite que Vue accepte une structure différente
de celle refusée par l'API :

```php
// plugins/Catalog/Schemas/ProductParams.php
return [
    'type' => 'object',
    'properties' => [
        'maxItems' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
    ],
    'required' => ['maxItems'],
];
```

```php
// Définition du champ : même schéma statique pour l'UI.
$paramsSchema = require __DIR__ . '/../Schemas/ProductParams.php';

$form->addField('advanced', 'params', 'core.json', [
    'label' => 'Paramètres',
    'schema' => $paramsSchema,
]);
```

```php
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;

#[ControllerMethod(name: 'product_save', path: 'products/save', methods: [RequestMethod::POST])]
#[ControllerParam1(
    name: 'params',
    required: true,
    schemaFile: __DIR__ . '/../Schemas/ProductParams.php',
)]
public function product_save(object $params): ApiResponse
{
    // $params a déjà été décodé et validé par le routeur.
    return new ApiResponse([
        'saved' => $this->wfProduct->saveParams($params),
    ]);
}
```

`schemaFile` force implicitement `VarType::JSON`; il n'est pas nécessaire de
le redéclarer. Les schémas doivent toujours être des fichiers PHP ou tableaux
connus côté serveur, jamais un schéma reçu dans la requête. Par défaut,
`ObjectValidator` refuse les propriétés non déclarées.

Les formats ou normalizers PHP enregistrés auprès de `ObjectValidator` peuvent
servir à un endpoint strictement serveur, mais ils ne doivent pas être ajoutés
au schéma partagé du champ `core.json` tant que le renderer Vue ne les connaît
pas. Les contraintes portables (`type`, `properties`, `required`, `enum`,
`minimum`, `maximum`, etc.) constituent le contrat commun UI/API.

Pour une définition dynamique, comme la configuration des extensions, le
schéma exact ne peut pas être déclaré dans un attribut de contrôleur : il est
choisi par l'extension autorisée. Le contrôleur vérifie donc d'abord que le
payload est un objet JSON, puis le WF appelle la définition de confiance :

```php
$result = $form->validateSubmittedValues($params, [
    'space_id' => $spaceId,
    'extension' => $extensionName,
]);

if (!$result->isValid()) {
    ExceptionResponse::throwWarning('Configuration invalide.');
}

$params = $form->getInitialValues(get_object_vars($result->value));
```

L'accès au formulaire et à ses paramètres est vérifié par `WFExtensions` sur
le Space ciblé. `BaseExtension::getConfigEditPermissions()` renvoie par défaut
`EXTENSION_EDIT` ; `BaseTemplate` le surcharge avec `TEMPLATE_EDIT` pour toutes
les Templates. Une extension peut renvoyer plusieurs permissions : une seule
suffit. Le plugin Sécurité exige `ROOT` ; `EXTENSION_EDIT` seul ne donne accès
ni à son formulaire, ni à ses paramètres, ni à son enregistrement. Une liste
vide ferme l'édition. Le serveur filtre les configurations
et les Spaces transmis selon la permission de chaque extension ; le workflow
revérifie ce droit avant l'enregistrement. L'API de l'essai e-mail de la
Template MainTemplate demande elle aussi `TEMPLATE_EDIT`. Le formulaire et les
contrats du plugin Sécurité sont détaillés dans [Sécurité HTTP](PLUGIN_SECURITE.md).

Le workflow appelle pour toute extension les hooks de `BaseExtension` :
`assertConfigSubmissionAllowed()` avant la validation du formulaire,
`prepareConfigForStorage()` après résolution des valeurs, puis
`prepareConfigForEditor()` avant chaque réponse. Leurs implémentations par défaut
laissent les paramètres inchangés. `getConfigHiddenSections()` fournit, par
Space, les sections que l'éditeur doit masquer ; le serveur doit aussi refuser
les champs interdits dans `assertConfigSubmissionAllowed()`, car le masquage
dans Vue ne protège pas l'API. Core délègue ses règles Bunny et HLS à
`Config/ConfigurationHelper` ; `WFExtensions` ne dépend pas de `plg_core`.

`getSubmissionSchema()` construit un objet fermé (`additionalProperties: false`)
à partir des champs éditables. Il inclut `required`, les contraintes
`validation`, les options résolues de tout type qui les accepte et le schéma du
champ `core.json`. Ainsi, une propriété non déclarée, une option absente ou un
JSON invalide est refusé même si Vue est contourné. Un type personnalisé sans
schéma de valeur reste limité à son nom de champ déclaré ; il peut fournir son
schéma avec `valueSchema` lors de son enregistrement.

## Types de champs d'extension

Après le chargement de toutes les extensions, `Application` appelle
`onRegisterFormTypes(FormFactory $formFactory)`. Un plugin, module ou template
peut y déclarer ses types :

```php
use YTassy\Tycms\Lib\Forms\FormFactory;
use YTassy\Tycms\Lib\Forms\FormFieldType;
use YTassy\Tycms\Lib\Forms\CallbackFormOptionsProvider;

public function onRegisterFormTypes(FormFactory $formFactory): void
{
    $formFactory->registerType(new FormFieldType(
        'catalog.product-picker',
        'catalog/forms/product-picker',
        acceptsOptions: true,
        defaultOptions: [
            'featured' => 'Produit mis en avant',
        ],
        valueSchema: ['type' => ['integer', 'null']],
    ));
}
```

Pour des options communes dépendant du contexte, remplacer `defaultOptions`
par `defaultOptionsProvider: new CallbackFormOptionsProvider(...)`. Les deux
formes ne peuvent pas être combinées.

L'identifiant PHP est qualifié par l'extension pour éviter les collisions. Le
second identifiant est résolu dans `fieldRegistry.js`, qui contient une liste
blanche de composants Vue compilés. Ajouter explicitement le composant Vue de
l'extension à ce registre ; ne jamais laisser PHP fournir un chemin d'import
arbitraire à Vite.

Les tests unitaires qui créent `Application` sans appeler `initialise()` ne
déclenchent pas l'enregistrement des types des extensions. Avant de valider un
formulaire contenant des champs personnalisés, enregistrer ces types dans le
`FormFactory` de l'application du test. Fournir des options de test pour les
champs de sélection dont les valeurs par défaut sont validées. Cette méthode
garde les tests unitaires indépendants de la base de données ; les tests qui
exercent le chargement réel des extensions relèvent de la suite d'intégration.

## Rendu Vue

Chaque section du formulaire est rendue dans un onglet. Le libellé de la
section est utilisé pour l'onglet, avec son identifiant comme valeur de repli;
sa description reste affichée dans le contenu de l'onglet. Pour ne pas afficher
une barre d'onglet inutile sur un formulaire à une seule section, définir
`hideSingleSectionTab` à `true` :

```php
$form->setHideSingleSectionTab();
```

Ou, dans une définition tableau :

```php
[
    'id' => 'catalog.settings',
    'hideSingleSectionTab' => true,
    'sections' => [...],
]
```

Le composant `FormRenderer.vue` reçoit une définition RIA et les valeurs :

```vue
<form_renderer v-model="values" :definition="formDefinition" :errors="errors" />
```

La prop booléenne `condensed` active un affichage compact, sans modifier la
définition, les valeurs ou leur validation :

```vue
<form_renderer v-model="values" :definition="formDefinition" condensed />
```

Les libellés passent au-dessus des champs, les contrôles occupent toute la
largeur et les booléens deviennent des cases à cocher avec leur libellé à droite.
Les champs localisés placent le libellé à côté des commandes de langue, sans
réduire la largeur de l’éditeur riche. Les aides et les erreurs restent affichées.
Sans cette prop, la présentation habituelle en colonnes est conservée.
L’onglet Général de l’atelier active ce mode pour tous ses formulaires
déclaratifs ; les formulaires Vue spécifiques gardent leur propre présentation.
Le renderer transmet ce choix par `condensedFormKey` aux champs partagés ; un
formulaire imbriqué fournit son propre mode. Les champs d’extension peuvent
réutiliser `BaseField` pour suivre la même présentation.

Pour placer un contrôle propre à une section dans son onglet, utiliser le slot
`section-header` avant ses champs ou `section-footer` après ses champs. Les deux
reçoivent la section courante :

```vue
<form_renderer v-model="values" :definition="formDefinition">
    <template #section-header="{ section }">
        <div v-if="section.id === 'email_default'">...</div>
    </template>
    <template #section-footer="{ section }">
        <div v-if="section.id === 'sso'">...</div>
    </template>
</form_renderer>
```

La page initialise normalement `values` avec
`formDefinition.initialValues`. Les erreurs attendent la forme
`{ title: ['Le titre est obligatoire.'] }`.

Le renderer est volontairement générique : il affiche et modifie les données,
mais le contrôleur et le WF gardent la responsabilité de l'autorisation, de la
validation métier et de l'écriture.

## Choix de polices partagées

Core fournit `core.font_family`, enregistré avant les formulaires des extensions.
Il utilise `forms/font-family` : recherche dans les familles installées,
sélection et aperçu du texte normal, gras et italique. Les options et les
métadonnées de l'aperçu sont fournies par `coreFontHelper` ; aucune API de
téléchargement n'est appelée par le champ.

```php
[
    'name' => 'heading_font',
    'type' => 'core.font_family',
    'default' => 'georgia',
    'label' => 'Police des titres',
]
```

Sans défaut explicite, le type choisit `arial`. Les identifiants inconnus ou
non installés sont refusés par la validation des options. `WFExtensions`
reconnaît ce type et valide les nouveaux choix actifs, tout en autorisant la
conservation d'une famille désactivée déjà enregistrée dans ce même champ du
Space. Un autre workflow doit appeler `coreFontHelper->assertSelectable` avec
la valeur précédente pour appliquer la même règle métier.

La recherche masque les familles désactivées sauf la valeur courante. Celle-ci
reste affichée même lorsqu'elle ne correspond pas au filtre. L'aperçu charge
seulement la feuille CSS locale de la famille sélectionnée ; les champs
partagent ces feuilles à l'échelle du document. Les échecs de CSS ou de WOFF2
sont affichés dans l'aperçu, sans bloquer la modification du formulaire.
Les polices historiques utilisent leurs déclarations CSS déjà livrées.

Le type est réutilisable par toute extension. Les fichiers et les installations
restent indépendants du formulaire et de la template. Contrat complet :
[FONTS.md](FONTS.md).

## Formulaire de configuration du plugin Core

Le formulaire du plugin Core est centralisé dans
`Core/Helper/Config/FormsHelper`, appelé par l'accès typé interne
`coreAppConfigFormsHelper`. Il contient les sections Mermaid et Bunny décrites
dans [Shortcodes éditoriaux](SHORTCODES.md). Les clés Bunny sont saisies en JSON
dans CodeMirror (`core.code`, syntaxe JSON), stockées comme chaîne en clair
dans `config.params` et rechargées uniquement pour les éditeurs autorisés du Space.

La section HLS du même formulaire contient `video_hls_libraries`, une chaîne
JSON alias → chemin absolu éditée avec CodeMirror, et `video_hls_password`, mot
de passe facultatif. Seul `SPACE_ADMIN` peut recevoir ou modifier ces deux
valeurs pour le Space ; le workflow retire les champs sensibles des réponses
aux autres éditeurs et préserve leur valeur stockée quand ils enregistrent le
reste du formulaire. Le mot de passe est stocké en clair dans `config.params`. Le
formulaire avertit du risque de clé publique en mode sans mot de passe et de
la perte de lecture si le mot de passe change.
