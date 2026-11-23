# Bibliothèque de composants de page

Ce guide décrit le contrat commun aux composants Core et aux extensions :
classes PHP `PageComponent`, JSON v3, contextes et registres explicites.
Le fonctionnement de l'atelier et les options métier sont détaillés dans
[Éditeur de pages](PAGE_EDITOR.md).

## Fournisseur et enregistrement

Une extension qui fournit des composants implémente
`Core\ExtensionApi\Page\IPageComponentProvider`, distinct de `IExtensionEvents`.
Core appelle uniquement les fournisseurs déclarés, après le
chargement des extensions, et associe chaque inscription à son propriétaire réel.

```php
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\IPageComponentProvider;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;

public function coreOnRegisterPageComponents(PageComponentRegistry $registry): void
{
    $registry->register(CourseList::class);
}
```

Le registre accepte uniquement des classes concrètes dérivées de `PageComponent`.
Il refuse les identifiants invalides et les collisions. L'enregistrement et la
lecture du catalogue n'instancient pas les composants et ne chargent pas leurs
données métier. Le JSON stocke un identifiant logique, jamais une classe PHP,
un callback, un template ou un chemin d'import JavaScript.

Les composants Core sont déclarés dans `Core/Plugin.php` et implémentés dans
`Core/PageComponents`. Aucun catalogue Core parallèle n'est maintenu dans Vue.

## Classe de composant

La classe de base se trouve dans `Core/ExtensionApi/Page/PageComponent.php`.
Ses accès partagés héritent de `BaseHelper`. Les helpers spécialisés doivent
être utilisés par leurs propriétés typées ; un composant Core qui en a besoin
compose seulement `TraitCoreAdditionalHelpers`.

Les composants Core `Audio`, `Video`, `Iframe`, `Mermaid` et `Diagram` portent directement leur
validation et leur rendu ; ils n’ont pas de helper intermédiaire ni d’accès
global dans les traits partagés. `Diagram` délègue le contrôle XML/SVG à la
classe locale `Support/DrawioSvg`, sans accès global supplémentaire.
Les règles communes des liens Image/Bouton
résident dans `Core/PageComponents/Support/PageLinks`, classe locale sans
héritage de helper et avec dépendances explicites sur les services Menu,
routes et langues. Le registre et le WF l’instancient aussi pour préparer les
destinations éditoriales. Cette organisation garde les traitements propres
aux composants près de leur contrat, sans étendre la surface des services Core.

Le bouton accepte `button_action = link|automation`. En mode `automation`, il
porte `automation_scenario_key` et un mode de confirmation
`success_message_mode = default|custom|result|none`. En mode `custom`, il porte
aussi `success_message`, un texte localisé limité à 300 caractères ; les langues
et clés `LG_` sont résolues au moment du clic. Un mode personnalisé vide retombe
sur le message par défaut, tandis que `none` n’affiche aucune confirmation.
Le mode `result` exécute le scénario de façon synchrone pendant le clic et
affiche soit une chaîne non vide, soit une réponse `res::*` ; le texte est
tronqué à 500 caractères. L’option `reload_on_close` recharge la page à la
fermeture de la modale, ou immédiatement en mode `none`.
Le bouton référence un scénario publié du même Space avec
le déclencheur `core.page.button.clicked`, et retire les propriétés de lien.
Le rendu public monte le composant Vue `core/automation-button` ; celui-ci
appelle l’API `POST /page/button` protégée par CSRF et jeton signé, puis affiche
la modale standard. Seuls les boutons d’automatisation montent Vue ; les
boutons de lien restent un rendu serveur. L’aperçu et le canevas rendent le
bouton inactif. L’éditeur de page liste les scénarios via
`administrator/api/automation/scenario-choices?triggerId=core.page.button.clicked`.
La validation de la clé, la résolution du scénario et du message sont dans
`PageButtonHelper` ; le déclenchement et les modes d'identité sont décrits dans
[le chantier Automatisation](IA/CurrentWorks/AUTOMATION.md#bouton-de-page).

| Méthode | Contrat |
| --- | --- |
| `getId()` | Identifiant stable qualifié, par exemple `lms.course-list`. |
| `getLabel()`, `getCategory()` | Présentation dans le catalogue. |
| `getDefaultData()` | Valeurs propres à un nouveau nœud, sans options communes ni descendants. |
| `getPropsSchema()` | Schéma des paramètres projetés, ou `null` si le formulaire déclaratif ou les méthodes de validation portent ce contrôle. |
| `getEditorFields()` | Métadonnées des champs ; `localized: true` déclare explicitement une projection multilingue. |
| `getChildZones()` | Définition des collections de corps enfants. |
| `getNodeReferenceFields()` | Propriétés contenant un ID interne (`one`) ou une liste d'IDs (`many`). |
| `getExclusiveReferenceFields()` | Propriété de liste et booléen imposant au plus une référence lorsqu'il n'est pas `false`. |
| `getFormVueComponentName()` | Identifiant logique du formulaire Vue, facultatif. |
| `getCanvasVueComponentName()` | Identifiant logique du canevas Vue, facultatif. |
| `getCanvasMode()` | `VUE`, `API` ou `PLACEHOLDER`. Sans canevas Vue, le défaut est `API`. |
| `getPublicVueComponentName()` | Composant Vue public facultatif commun à tous les nœuds. |
| `getPublicVueComponentNameFor($node)` | Variante par nœud ; par défaut, reprend `getPublicVueComponentName()`. Permet de monter Vue seulement pour certains modes. |
| `getPublicProps()` | Projection publique éphémère après normalisation et projection de langue ; par défaut, copie des propriétés du nœud. |
| `getEditContext()` | Données JSON préchargées pour les formulaires et canevas de l'espace. |
| `getGeneralForm()` | Formulaire PHP de l'onglet Général, facultatif. |
| `normalizeData()` | Retourne les paramètres normalisés, sans persistance. |
| `normalizeZone()` | Ajuste un corps de zone sans parcourir lui-même ses descendants. |
| `validateData()` | Contrôles métier supplémentaires des données entrantes. |
| `validateNode()` | Cohérence entre paramètres et zones, par exemple un onglet ouvert existant. |
| `render()` | Produit le HTML spécifique du nœud. |

Les métadonnées sont statiques. Les comportements utilisent des instances
créées lors de la préparation de l'édition, de la validation d'une entrée ou
du rendu. Une même opération réutilise ses instances par type. Les composants
ne doivent pas conserver de nœud, de langue ou d'utilisateur dans leurs propriétés.

`PageNormalizationContext` fournit le Space et le mode `INPUT`, `EDITOR` ou
`RENDER`. `INPUT` couvre sauvegarde, collage, proposition IA et aperçus de
brouillon : purification et contrôles des références sont indispensables.
`EDITOR` et `RENDER` lisent des données déjà enregistrées sans reconstruire le
contexte administratif ni repurifier le HTML.

La base fournit `normalizeText()`, `escape()` et `assert()` aux implémentations.
Les règles métier et ACL d'une extension restent dans ses workflows/services ;
le composant les appelle pour les références qu'il accepte. Les constructeurs
restent légers et ne chargent aucun catalogue.

## Document JSON v3

Aucune reprise v1/v2 n'est fournie. La migration initiale
`Migration/Mig_1_0_0_p2.php` installe directement des pages et footers v3. Elle
ne se rejoue pas sur une base qui a déjà enregistré sa version. Un ancien document est
refusé dans l'éditeur et omis au rendu, pour éviter qu'un footer obsolète bloque
l'ensemble du site. Il n'est jamais converti ni réécrit automatiquement.

```json
{
  "version": 3,
  "container": { "fluid": false },
  "items": [
    {
      "id": "row_1", "type": "core.row", "props": {},
      "zones": {
        "columns": [
          {
            "id": "column_1", "sizes": { "xs": 12, "md": 6 },
            "label": "", "icon": "", "props": {},
            "items": [
              {
                "id": "courses_1", "type": "lms.course-list",
                "props": { "category_id": 42 }, "zones": {}
              }
            ]
          }
        ]
      }
    }
  ]
}
```

`zones` est toujours un objet dont les clés nomment les collections de corps.
`columns`, `tabs` et `panels` sont donc des noms de zones déclarés par leurs
composants ; le moteur sait aussi parcourir les noms fournis par un plugin.
Chaque corps possède un ID globalement unique, des `props` et des `items`.
Le libellé localisé, l'icône et les largeurs responsive sont des métadonnées
communes facultatives des corps. Les données métier d'un corps vont dans `props`.
Les listes vides restent des tableaux ; les objets vides restent des objets.

```php
public static function getChildZones(): array
{
    return ['lessons' => [
        'label' => 'Section', 'kind' => 'zone',
        'min' => 1, 'max' => 6, 'initial' => 1,
        'defaults' => ['label' => 'Première section'],
    ]];
}
```

Une zone peut déclarer `formVue` pour les propriétés de ses corps. Le moteur
fournit les commandes d'ajout, déplacement, duplication et suppression. Les
références déclarées par `getNodeReferenceFields()` sont renouvelées lors de
la duplication et du collage, puis réparées lors de la suppression d'un corps.
Les corps ordinaires restent attachés à leur propriétaire ; les colonnes
peuvent changer de ligne.

Le document est limité par `Configuration::$page_max_size` (1 000 000 octets
par défaut), avec un plafond absolu de 5 000 000 octets. `PageSize` partage la
sérialisation persistée et les limites entre sauvegarde, collage, propositions
IA et aperçus ; le registre contrôle aussi le poids après normalisation. La
lecture conserve le plafond absolu pour ne pas casser les pages existantes
lorsqu’on réduit la configuration. Le document reste borné à
500 nœuds en comptant les corps, 12 niveaux de
composants imbriqués, 12 collections par composant et 100 corps par collection.
Le composant peut fixer des bornes plus petites. La racine contient des lignes.
Les styles et les conditions de visibilité communs sont contrôlés pour tous
les nœuds et corps. Une extension absente conserve son nœud et ses descendants,
accessibles dans l'arbre et le canevas, et ne produit aucun HTML public.

## Contexte d'édition

Le WF vérifie les droits de la page et du Space avant toute préparation. À
l'ouverture, le registre prépare le contexte de tous les types ajoutables,
y compris ceux qui ne figurent pas encore sur la page. La création d'une page
charge le même contexte ; changer son Space le recharge.

Les clés sont préfixées par l'identifiant du fournisseur :
`plg_core_menu_items`, `plg_lms_course_categories`, etc. Le collecteur refuse
les clés hors préfixe, les collisions et les valeurs non JSON. Les fournisseurs
produisent leurs données indépendamment ; leurs formulaires reçoivent ensuite
le contexte collecté. Le moteur fournit les destinations éditoriales des menus
sous `plg_core_menu_items`. Ce catalogue ne donne pas de droits de gestion de menu.

Les données sont limitées au Space et aux autorisations réelles de l'éditeur.
Aucun secret, callback, service ou modèle ORM complet n'est transmis à Vue.
Les composants Vue peuvent effectuer leurs propres appels API lorsqu'un besoin
métier le justifie. Le contexte navigateur n'est ni enregistré dans le document,
ni accepté comme preuve d'autorisation lors de la validation serveur.

## Onglet Général : formulaire PHP ou Vue

Un composant choisit un seul mode : `getGeneralForm()` ou
`getFormVueComponentName()`. Fournir les deux est une erreur. Sans formulaire,
le moteur affiche l'absence de paramètres généraux. Style et Avancé restent
communs et indépendants du mode choisi.

```php
public function getGeneralForm(PageEditContext $context): ?Form
{
    return (new Form($this))
        ->setId('lms.course-list')->setHideSingleSectionTab()
        ->addSection('general', 'Général')
        ->addField('general', 'category_id', 'core.select', [
            'label' => 'Catégorie', 'default' => 42,
            'options' => $context->get('plg_lms_course_categories', []),
        ]);
}
```

Les champs éditent directement les propriétés spécifiques de `node.props`.
Les valeurs initiales de `getDefForRia()` complètent les défauts du composant ;
les valeurs déjà enregistrées restent prioritaires. Les modifications alimentent
le même brouillon et ne déclenchent aucune sauvegarde indépendante. Le serveur
reconstruit la définition de confiance, normalise et valide les valeurs à chaque
entrée du document. Les options reçues du navigateur ne sont jamais utilisées
pour valider une référence.

Le type partagé `core.localized_text` réutilise `LocalizedTextField` ; ses
modes `text`, `textarea`, `html` et `rich` contrôlent la saisie, et `maxLength`
borne chaque variante. Les modes HTML sont purifiés avant validation. Pour une
projection automatique vers un schéma PHP de chaînes et le Vue public, déclarer
également le champ dans `getEditorFields()` avec `localized: true`.

`FormRenderer` remet le contexte à tous les types de champs. Dans l'éditeur,
ce contexte contient notamment `spaceId`, `locale`, `locales`, `readonly` et les
clés des fournisseurs. Un sélecteur dépendant d'un autre champ n'est pas encore
réévalué automatiquement : utiliser un formulaire Vue si nécessaire.
Voir [Formulaires](FORMS.md) pour les types et options disponibles.

## Rendu et sécurité

`PageDocument` contrôle uniquement la structure commune. Le registre applique
les contrats des composants, y compris dans les zones de plugins. Le WF vérifie
les droits et le Space réels avant sauvegarde, collage et aperçu. Les contrôles
de liens, MIME et périmètre média restent appliqués par les composants Core via
leurs services spécialisés.

`PageRenderer` distribue les nœuds au même contrat pour tous les types. Il
applique la visibilité des ancêtres avant de rendre leurs descendants et retire
les corps interdits avant de les remettre au composant. Chaque composant est
encapsulé dans un `div` portant les classes communes et `data-page-node`.

`PageRenderContext` contient la page, le Space, la langue et le mode
`PUBLIC`, `PREVIEW` ou `CANVAS`. Il reste côté serveur. `text()` projette une
valeur localisée ; `renderItems()` réutilise le moteur et `renderZone()` ajoute
une enveloppe commune au corps. Les composants de structure peuvent générer
leur enveloppe HTML appropriée en conservant l'ID, les classes et les contrôles
communs. Le composant échappe les textes/attributs et contrôle ses URL ; seules
les données éditoriales HTML sont purifiées, pas les balises générées par le code
de confiance. Aucun rendu ne doit effectuer une mutation métier.

Les champs localisés conservent leurs variantes en base. Le registre valide
chaque langue avec le schéma projeté, puis PHP et le Vue public reçoivent la
chaîne de la langue du rendu. Une variante absente reste vide.

## Registres Vue et compilation

Le module `components/administrator/pages/componentRegistry.js` expose
`registerPageForm()` et `registerPageCanvas()`. Les identifiants PHP sont
résolus exclusivement dans ces registres. Les composants Core y utilisent les
mêmes fonctions que les plugins.

Un formulaire Vue reçoit `selected`, `node` et `context`. Un canevas reçoit
`item` (également disponible sous `node`), `context`, `viewport` et le catalogue
`components`. Déclarer les props utilisées, y compris sur une racine fragment.
Un conteneur affiche les corps éditables avec le slot fourni par le moteur :

```vue
<slot v-for="zone in item.zones.lessons" :key="zone.id" name="zone" :zone="zone" />
```

Une extension installée fournit son propre frontend, par exemple
`src/extensions/plugins/Lms/frontend/src/main.js` :

```js
import CourseListForm from './CourseListForm.vue'
import CourseListCanvas from './CourseListCanvas.vue'

export function registerFrontend({ registerPageForm, registerPageCanvas }) {
    registerPageForm('lms/course-list', CourseListForm)
    registerPageCanvas('lms/course-list', CourseListCanvas)
}
```

Le Core charge cette bibliothèque ESM à l’exécution, via l’Import Map et le
manifeste Vite propres à l’extension. `getFrontendEntry()` déclare son entrée.
Le même objet API fournit `registerFormFieldComponent` et
`registerPublicPageComponent` ; ces fonctions peuvent aussi être importées
depuis `@tycms/api`. Vue et Pinia proviennent du runtime partagé du CMS.
L’ancien `assets/page-components.js` intégré au build Core doit être adapté.

Les racines publiques `data-page-vue` installent un contexte applicatif,
leurs stores Pinia, le mixin, les directives et les composants globaux communs,
sans partage implicite d’état entre racines. Le premier montage attend la fin
de l’enregistrement de toutes les extensions. Les paramètres proviennent de
`getPublicProps()` ; ce hook n’expose aucun secret et ne fait aucune mutation.
Ils sont encodés en JSON et échappés comme attributs HTML, sans compilation de
HTML en template Vue.

Une extension PHP utilisant les champs existants, un formulaire déclaratif et
l’aperçu API n’a pas besoin de bundle Vue. Pour une extension Vue, compiler son
frontend indépendamment, ou la sélectionner dans `DEV_EXTENSION` pour le HMR.
Les autres extensions continuent d’utiliser leurs builds sans reconstruire Core.
Voir le [contrat des extensions](EXTENSIONS.md) et [Docker](../docker/dev/README.md).

## Composants Login et Register

`core.login` et `core.register` figurent dans **Authentification**. Leurs options
sont définies par `getGeneralForm()` dans l’onglet Général, avec `core.switch`
et `core.localized_text`, en mode riche pour les mentions et les CGU. Ils
réutilisent le formulaire commun en mode `condensed`, activé dans Général pour
tous les composants déclaratifs, sans panneau Vue spécifique.
Le canevas API et l’aperçu fidèle montrent un formulaire inerte ; le rendu
public monte `page/Authentication.vue` avec son store `authentication`.
JavaScript est nécessaire pour les actions locales. Aucun titre « Connexion »
ou « Inscription » n’est ajouté au formulaire ou à son aperçu ; un titre peut
être composé séparément dans la page.

Login propose le formulaire e-mail/mot de passe, les fournisseurs SSO du Space,
un texte riche entre les champs et « Se connecter », un texte riche avant SSO,
et le lien facultatif `/forgot-password` avec libellé réglable. Register propose
le formulaire, le SSO et une case CGU à texte riche réglable. Lorsque la case est
activée, elle est obligatoire pour le formulaire, l’adhésion et le SSO.
Les textes sont multilingues et purifiés. Les options de moyens et de CGU
restent indépendantes ; couper le formulaire coupe aussi l’adhésion locale.

Le Space peut désigner librement les entrées de menu servant de destinations.
Les composants peuvent être utilisés sur d’autres pages du même Space,
chacun avec ses propres options et CGU. Les références du Space définissent
les destinations des liens et ne conditionnent pas le fonctionnement de Register.
L’ouverture des inscriptions reste contrôlée par le paramètre du Space.
Le serveur relit la page source publiée et l’empreinte des paramètres lors
de chaque action, y compris au retour SSO. Les aperçus n’émettent aucun jeton.
Voir [Inscription](REGISTRATION.md) pour les API, la clé privée de session,
les contrôles, la migration technique et le secours `/login-fb`.

## Composant Mon compte

`core.my-account` figure dans **Authentification**. Son formulaire Vue dédié
regroupe les réglages sous des titres, avec une grille Afficher / Modifier
pour les informations personnelles, les actions SSO/OAuth et la lecture seule.
Les dispositions `simple`, `list` et `table` s'appliquent aux informations
personnelles dans le rendu public et les aperçus. Ces réglages sont uniquement visuels.
Le rendu public monte `page/MyAccount.vue` ; les modales sont séparées sous
`features/account/` et le store `my_account` utilise les API privées du compte.
Un visiteur déconnecté ne voit rien ; les aperçus restent fictifs et inertes.
Voir [Mon compte](MY_ACCOUNT.md) pour les API, la sécurité, la liaison SSO
explicite et le remplacement de l'ancien écran Mes intégrations.

## Composant Changer de Space

`core.space-switch` figure dans **Navigation**. Son formulaire Vue dédié reprend
les champs compacts et les titres des autres composants, en trois groupes :
Espaces proposés, Affichage et Navigation. Il propose trois sources cumulables,
fusionnées sans doublons et triées par nom : correspondance
exacte de l'hôte courant, permission `MEMBER` effective (héritage des Spaces
parents inclus), liste explicite de 100 IDs au maximum. Le filtre d'hôte réutilise
`SpaceHelper::getSpacesMatchingRequestHost()`, y compris les ports localhost en
DEV, sans les replis du choix initial de Space. Le catalogue explicite ne propose
que le Space édité, ceux du domaine et ceux accessibles avec `MEMBER`, `SPACE_EDIT`
ou `SPACE_ADMIN` pour l'éditeur ; la validation serveur reconstruit ces choix.

Logo, nom, descriptions courte et longue sont affichables séparément. Le logo
peut être aligné à gauche, au centre ou à droite de la carte, avec une largeur
maximale en pixels (0 conserve la pleine largeur). Ces réglages ne s'appliquent
qu'au logo, sans déplacer le nom, les descriptions ni le bouton. La
destination des membres est configurable : accueil ou espace utilisateur, avec
repli sur l'accueil si l'entrée utilisateur n'est pas disponible. La langue
courante est conservée si elle est active dans la cible, sinon sa langue par
défaut est utilisée. Les Spaces sans `MEMBER` dont l'inscription est fermée sont
masqués ou montrés désactivés avec un texte localisable (défaut « Inscription
fermée »). Les aperçus sont informatifs et ne modifient jamais la session.

Le rendu public monte `page/SpaceSwitch.vue`. `getPublicProps()` remet les options
d'affichage et un jeton de configuration authentifié, valable 24 heures. Cette
projection ne modifie pas les paramètres persistés. Le jeton limite les sources
éditoriales acceptées par les API ; il ne donne aucun droit sur les Spaces.

`POST /space/api/switch/options` reçoit `token` et renvoie les seules informations
publiques utiles : ID, titre, logo demandé, descriptions demandées, accès avec
`MEMBER`, état des inscriptions et URL de destination. Les configurations SMTP,
SSO et les autres paramètres restent côté serveur. Le store `space_switch` porte
les appels du composant ; ses résultats restent locaux à chaque instance.

`POST /space/api/switch/select` reçoit `token` et `spaceId`. Les deux API utilisent
le CSRF de la session. `WFSpaceSwitch` revalide les sources, le compte non bloqué,
la permission sur le Space réel et l'ouverture des inscriptions avant de changer
le Space en session. Le navigateur effectue ensuite une seule navigation vers
l'URL renvoyée. Sans `MEMBER`, cette URL est celle de `menu_item_register_id`
dans la langue cible ; sans destination configurée et résoluble, le Space est
masqué ou désactivé pour les non-membres et ne peut pas être sélectionné.
Cette destination reste facultative pour les composants Register eux-mêmes.
Aucune inscription automatique n’est effectuée. Un Space fermé est
refusé même si la liste précédente l'affichait ouvert. Toutes les destinations
restent sur l'origine actuelle, comme la sélection de Space en session.

Le logo facultatif est stocké dans `spaces.params.logo` après sélection d'une
image réelle de sa médiathèque. Le choix est disponible après création du Space ;
changer vers une image non vide exige aussi `MEDIA_READ` dans ce
Space. Un logo inchangé est conservé, et l'omission du champ par l'API ne l'efface
pas. Une image supprimée n'est plus affichée. La description longue reste une
chaîne dans `spaces.params.description`, éditée par `core.rich_text` et purifiée
par `WFSpace` avant persistance. Le sélecteur purifie également les descriptions
historiques avant de les rendre ; aucun changement de schéma SQL n'est requis.

## Aperçus API dans le canevas

`POST /administrator/api/pages/canvas` reçoit le brouillon complet, `page_id`,
`space_id`, `node_id` et `locale`. Il contrôle les droits, le Space, le document,
les références et la langue avant de rendre le fragment, en respectant la
visibilité de ses ancêtres. Les conditions de rôle sont ignorées dans ce mode
éditorial : un composant réservé à un rôle que l'éditeur ne possède pas reste
visible pour la sélection et la modification. Aucun identifiant de classe ni
contexte client n'est exécuté. Le client temporise les requêtes et ignore les réponses périmées.
Il recharge le fragment lorsque le contenu du composant (descendants compris),
la visibilité d'un ancêtre, la page, le Space, la langue ou la session d'édition
change. Les modifications des autres composants ne déclenchent aucun appel ;
une copie de l'arbre sans changement de ces données non plus. Le brouillon
complet reste envoyé au serveur pour ses contrôles.

Les réponses d’aperçu fournissent aussi `security_sources` depuis la
configuration Sécurité du Space réel, pour les cadres, images, médias, styles
et polices. Le document isolé applique ces listes sans élargir les scripts
ni les connexions ; voir [Sécurité HTTP](PLUGIN_SECURITE.md).

Le fragment reste dans une iframe `sandbox="allow-scripts"` sans
`allow-same-origin`, avec CSP locale et navigation neutralisée. Les seuls scripts
autorisés sont Mermaid et le fichier statique de mesure du canevas. Un
`ResizeObserver` remet la hauteur au parent par `postMessage`. Le parent exige
la fenêtre exacte de l'iframe, son origine opaque, le jeton du document courant
et un entier entre 24 et 100 000 pixels. Il retire son écouteur au démontage.
Les marges, le padding et la bordure du `body` sont à zéro dans cet aperçu ; les
styles configurés des composants restent appliqués. Le rendu fidèle complet
conserve son espacement habituel et n'active pas le pont de mesure.

## Enregistrement

Utiliser `showModaleWait()`, fermer l'attente dans un `finally` par
`hideModaleWait()`, puis afficher `showModaleSuccess()` en cas de réussite.
Les champs ne sont pas désactivés pendant l'enregistrement. Le contrôle des
droits et la lecture seule restent indépendants. Aucune gestion supplémentaire
d'édition simultanée n'est ajoutée. Voir la convention générale dans
[Frontend](FRONTEND.md#attente-succès-et-messages).
