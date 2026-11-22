# Langues des Spaces

Ce guide décrit le socle du multilangue, son administration, le routage public
préfixé, la visibilité des menus, l'accueil et l'espace utilisateur par langue.
Les pages peuvent être dupliquées et reliées à des entrées de menu distinctes ;
la projection explicite des champs ORM est disponible. Les limites et pistes
ouvertes sont regroupées dans les [travaux restants](IA/TRAVAUX_RESTANTS.md).

## Stockage et migration

La migration Core `1.0.3` crée `space_languages` avec `space_id`, `locale`,
`is_default`, `enabled` et `sort_order`. Chaque couple `(space_id, locale)` est
unique. Un index unique sur `(space_id, default_marker)` empêche deux langues
par défaut : `default_marker` vaut `1` uniquement pour la ligne par défaut et
`NULL` pour les autres. Une contrainte `CHECK` impose que la langue par défaut
soit active. La clé étrangère vers `spaces` supprime les langues avec leur Space.

La migration attribue `fr`, active et par défaut, à tous les Spaces existants,
sans modifier leurs contenus. `SpaceAdminHelper::createSpace()` attribue aussi
`fr` à chaque nouveau Space. Ces deux chemins assurent la présence initiale
d'une langue par défaut ; l'index garantit au plus une langue par défaut.
L'API d'administration conserve au moins une langue active par défaut et valide
les codes de langue avant écriture.

## Lecture

Le helper partagé `SpaceLanguageHelper`, accessible par la propriété typée
`$coreSpaceLanguageHelper`, expose :

- `listForSpace($spaceId)` : toutes les langues du Space dans l'ordre défini ;
- `listActiveForSpace($spaceId)` : les seules langues actives ;
- `getDefaultForSpace($spaceId)` et `getDefaultLocale($spaceId)` : la langue par
  défaut active, avec exception technique si la configuration est absente ;
- `getHomeMenuItemId($spaceId, $locale)` : l'accueil de cette langue ou, sans
  exception, l'accueil général historique du Space ;
- `getUserSpaceMenuItemId($spaceId, $locale)` : l'entrée d'espace utilisateur
  propre à la langue ou l'entrée générale du Space.

Les méthodes renvoient des objets ORM `SpaceLanguage` bruts. `PublicLocaleHelper`
choisit la langue publique après la fixation du Space et avant l'enregistrement
des routes de menu.

## Routage public préfixé

Le Space sélectionné en session reste prioritaire sur l'hôte. Ensuite,
`PublicLocaleHelper` lit les langues configurées pour ce Space. Un préfixe actif
dans l'URL fixe la langue, indépendamment du navigateur ; une route publique de
menu effectivement résolue mémorise cette langue dans `public_locale` en session.
Un préfixe configuré mais inactif n'enregistre aucune route publique et ne
modifie pas la session. Sans préfixe configuré, une langue mémorisée et active
dans le Space courant gagne. Sinon, le helper lit `Accept-Language` avec ses
poids `q`, essaie une correspondance exacte puis la langue principale (`en-US`
vers `en`), et utilise la langue par défaut active du Space si rien ne
correspond. Une langue mémorisée devenue inactive est conservée en session,
mais ignorée tant qu'elle n'est pas active dans le Space courant. Seules les
langues configurées réservent un premier segment : un ancien chemin tel que
`/cgu` reste une page.

Les routes de pages issues des menus sont enregistrées sous `/{locale}/...` ;
leurs noms ne changent pas et les liens produits par le routeur utilisent le
préfixe de la requête. Les anciens chemins publics connus en `GET` ou `HEAD`
redirigent temporairement en 302 vers ce préfixe, en conservant la chaîne de
requête. Ces réponses portent `Vary: Accept-Language` et
`Cache-Control: private, no-store`. Un chemin inconnu reste une 404. Les routes
techniques, notamment administration, API, CSS, choix du Space et callbacks,
gardent leurs chemins actuels. Le logo de la template MainTemplate et le retour après
déconnexion pointent vers l'accueil préfixé. `html[lang]` suit la langue choisie.
Une route technique utilise donc la préférence mémorisée quand elle est active,
sans jamais la modifier elle-même.

Le routage ne réécrit pas les liens internes saisis dans le contenu. La
traduction des champs est explicite, selon les contrats décrits plus bas.
La disponibilité publique d'une page est contrôlée par
l'entrée de menu qui la référence, sans statut de page séparé par langue.
Il n'ajoute aucune interface de choix de langue.

## Visibilité des entrées de menu par langue

La migration Core `1.0.5` crée `menu_item_languages(menu_item_id, locale,
enabled)`. Une ligne n'est enregistrée que pour masquer une entrée dans une
langue active du Space du menu. Sans ligne, l'entrée est visible : les menus
existants et les nouvelles langues continuent ainsi de fonctionner. Une entrée
masquée disparaît à la fois du HTML et des routes publiques ; ses enfants sont
également masqués dans cette langue. Les titres et alias restent communs aux
langues. Les entrées d'accueil et d'espace utilisateur effectives,
ainsi que leurs parents, ne peuvent pas être masquées ou dépubliées dans leur
langue.

Dans l'éditeur d'entrée, le champ « Langues » se trouve à la fin de l'onglet
« Général », après les paramètres du type d'entrée, notamment la page
sélectionnée. Les langues sont affichées verticalement avec un switch chacune.
Un switch activé rend l'entrée visible dans cette langue. Le bouton
d'enregistrement de l'entrée sauvegarde ces choix dans la transaction
de la route. L'écriture exige `MENU_CRUD` sur le Space du menu et rejette les
langues inconnues, inactives ou en double. Les lignes d'une langue devenue
inactive restent conservées pour une éventuelle réactivation.

La liste Vue des entrées affiche dans la colonne « Langues » les langues actives
cochées pour chaque entrée. Si un parent la masque dans l'une d'elles, une
seconde ligne « Après parents » indique les langues encore visibles. L'API
`/administrator/api/menus` fournit pour chaque entrée `languages`, une liste
ordonnée de `{locale, enabled, visible}` ; `visible` inclut les restrictions
des parents et ne remplace pas le statut de publication.

## Accueil par langue et navigation du Space

La migration Core `1.0.6` ajoute `menu_item_home_id` et
`menu_item_user_space_id` nullables à `space_languages`. Les entrées générales
restent dans `spaces`. Pour chaque langue active, une valeur locale choisie
prime ; `NULL` utilise l'entrée générale. Les anciens Spaces gardent donc leur
navigation sans modification. Le routage enregistre `/{locale}/` avec l'accueil
effectif. Sans accueil configuré, cette URL affiche une page provisoire
« Configurez votre page Home » ; les autres routes de menu restent accessibles.
Les deux entrées générales sont facultatives pour permettre la création d'un
Space avant ses menus.

La stratégie de contenu retenue est une page distincte et une entrée de menu
distincte par langue. L'éditeur de menu choisit la page cible et ses switches
contrôlent les langues où l'entrée apparaît. Le panneau **Navigation**, placé
sous **Langues par espace** sur la page **Espaces**, permet de choisir l'accueil
général, l'entrée d'espace utilisateur commune et leurs variantes par langue,
ainsi que les pages communes de connexion et d’inscription. Ces deux dernières
n’ont pas de surcharge par langue ; leurs textes de composants sont localisables.
La sélection du Space utilise les mêmes boutons dans les deux panneaux. Les
réglages Navigation ne figurent plus dans la modale générale du Space.

`GET /administrator/api/spaces/navigation?spaceId=…` retourne ces choix, les
entrées disponibles et leurs capacités d'accueil. `POST
/administrator/api/spaces/navigation/save` reçoit `spaceId` et `navigation`
avec `{home_item_id, user_space_item_id, login_item_id, register_item_id,
languages:[{locale,home_item_id,user_space_item_id}]}` ; `0` signifie « aucun »
pour un choix général et « utiliser le choix général » pour une langue.
`SPACE_EDIT` ou `SPACE_ADMIN` est nécessaire. La sauvegarde valide le Space
propriétaire, la publication, une route GET utilisable et la visibilité de
chaque entrée effective dans sa langue. L'accueil exige une route publique
créée par l'entrée de menu ; l'espace utilisateur accepte aussi une route
technique GET. Les deux liens d’authentification restent libres : le serveur
vérifie leur référence et leur appartenance au Space, sans imposer de type
Page ni d’accès public. Toutes les langues actives doivent figurer une seule fois.
L'écriture se fait dans une transaction sous verrou du Space. Les redirections
après connexion et SSO utilisent l'espace utilisateur propre à la langue
courante, puis l'entrée générale ; sans aucune entrée, elles vont à l'accueil
préfixé. Après avoir choisi une autre entrée anglaise, l'ancienne peut être
masquée en anglais tout en restant requise en français.

## Administration

La page **Espaces** affiche sous le tableau un panneau « Langues par espace ».
Des boutons, identiques au choix de Space des extensions, sélectionnent l'espace
à configurer. Le panneau permet d'ajouter un code de langue, d'activer ou de
désactiver une langue, de choisir la langue par défaut et de modifier l'ordre.
La sauvegarde du panneau est indépendante du formulaire général du Space. Un
changement de Space avec des modifications non enregistrées demande confirmation.

`GET /administrator/api/spaces/languages?spaceId=…` retourne les langues dans
l'ordre et `canEdit`. La lecture exige `SPACE_EDIT` ou `SPACE_ADMIN` sur ce Space.
`POST /administrator/api/spaces/languages/save` reçoit `spaceId` et un tableau
JSON `languages` de 1 à 20 objets `{locale, enabled, is_default}`. L'écriture
exige `SPACE_ADMIN` sur ce Space. Le code est normalisé en minuscules et accepte
deux ou trois lettres suivies de sous-étiquettes de deux à huit caractères
alphanumériques, séparées par `-` (par exemple `fr`, `en`, `fr-ca`). Les doublons
sont refusés. Une seule langue doit être active et par défaut. Une langue déjà
enregistrée ne peut pas être supprimée : elle peut être désactivée, sauf si elle
est la langue par défaut. La modification s'exécute dans la transaction de la
route, avec verrou sur la ligne du Space et retrait de l'ancien indicateur par
défaut avant d'attribuer le nouveau.

## Catalogues des extensions et clés

Chaque extension qui implémente `IExtensionEvents` peut retourner des chemins de
fichiers PHP avec `coreLanguageCatalogFiles()` : `['fr' => __DIR__ . '/Languages/fr.php',
'en' => __DIR__ . '/Languages/en.php']`. Elle retourne `[]` si elle n'a pas de
catalogue. Chaque fichier retourne un tableau `clé => texte`. `fr.php` est la
source obligatoire ; les autres langues peuvent omettre des clés et utilisent
alors la valeur française. Le registre vérifie les fichiers, le format des clés,
les types des valeurs et les collisions entre extensions au chargement. Les
catalogues sont versionnés avec le code et ne sont jamais modifiés par l'UI.

Le catalogue source `fr.php` accepte aussi une déclaration enrichie pour
documenter les clés proposées à la personnalisation :

```php
return [
    'LG_CORE_MENU_HOME' => 'Accueil',
    'LG_CORE_EXEMPLE' => [
        'value' => '',
        'recommended' => true,
        'description' => 'Connexion : contenu affiché après le message de bienvenue, avant le formulaire.',
    ],
];
```

`value` est une chaîne obligatoire, éventuellement vide. `recommended` est un
booléen facultatif, faux par défaut ; `description` est une chaîne facultative,
vide par défaut. Les champs inconnus et les types incorrects sont refusés au
chargement. Ce format est réservé au catalogue français ; les autres langues
conservent des chaînes simples. Le registre extrait les métadonnées et ne
transmet que des chaînes au moteur de traduction et aux dictionnaires publics.

Les métadonnées sont communes à tous les Spaces et à toutes les langues. Elles
sont définies dans le code de l'extension, sans stockage SQL ni modification par
l'écran de surcharge. Les clés personnalisées créées dans un Space ont par
défaut `recommended: false` et une description vide. Les lectures administratives
`/entries` et `/matrix` renvoient `recommended` et `description` avec chaque clé.

Dans **Traductions**, un badge « Recommandé » apparaît à droite du nom de la
clé, sur la même ligne ; la description figure en dessous et dans l'éditeur.
Le filtre **Toutes** est actif par défaut ; **Recommandées** limite la liste
aux clés marquées, et **Clés d’espace** aux clés créées par un utilisateur
(`owner: space`), y compris celles héritées d'un Space parent. Ces dernières
portent un badge « Clé d’espace » à droite de leur nom. Une surcharge d'une clé
d'extension conserve son propriétaire d'origine et ne devient pas une clé
d'espace. Les recommandations précèdent les autres clés, chaque groupe
étant classé par nom. La recherche couvre aussi les descriptions, affichées
comme texte échappé. Le badge reste présent après personnalisation : il signale
un emplacement prévu à cet effet, sans obligation de remplir sa valeur.

Les dix anciennes clés de personnalisation de connexion et d’inscription
restent au catalogue pour retrouver les textes et les surcharges existants.
Elles ne sont plus injectées automatiquement : les composants Login et Register
utilisent leurs propres champs localisés. Une ancienne clé peut être choisie
explicitement dans un champ compatible du composant ; aucun contenu n’est migré.

Une clé suit `LG_` puis des segments ASCII majuscules séparés par `_`, par
exemple `LG_CORE_MENU_HOME`. La convention est de commencer par le domaine de
l'extension (`CORE`, `MAIN`, etc.). Le moteur ne traduit que les chaînes qui
correspondent entièrement à ce format, sur appel explicite
`LanguageTranslator::resolve($value, $locale, $spaceId)`. Une clé inconnue
reste telle quelle hors développement et est entourée de crochets en
développement. Les modèles ORM et les requêtes SQL continuent à fournir les
valeurs brutes.

Le raccourci `TraitCoreLang::_()`, également disponible dans les templates PHP
via `$_('LG_...')`, utilise `SpaceHelper::getCurrentSpace()->id`, comme la
template du site. Les pages admises sans Space sélectionné en session utilisent
ainsi Root sans enregistrer de choix implicite. La langue publique résolue
prime ; si elle est absente, la langue par défaut de ce Space est utilisée.
Un code null ou vide retourne une chaîne vide sans résoudre le contexte.

L'écran **Traductions** permet aussi de déclarer une clé propre à un Space,
avec son texte source français, dans `language_custom_keys`. La clé est unique
sur le site et visible dans le Space où elle est déclarée et ses descendants.
Le formulaire accepte seulement les clés au format `LG_...` (les minuscules
sont converties en majuscules) ; le WF refait cette validation avant écriture.
La valeur source et toutes les surcharges acceptent du HTML. L'administration
utilise CodeMirror par défaut pour la source française et les surcharges ; le
tableau montre un aperçu en texte seul. Chaque surcharge propose les boutons
**HTML** et **Texte riche**, alignés à gauche à côté de son libellé de langue et
de Space. Le second mode réutilise `rich_text_editor` (TinyMCE) sur le même
brouillon HTML. Le choix est indépendant par langue, conservé après sauvegarde
et réinitialisé à HTML après changement de Space ou rechargement de la page.
Le changement de mode ne sauvegarde rien. TinyMCE peut normaliser le HTML lors
d'une modification en mode riche. Les deux éditeurs sont en lecture seule
pendant la sauvegarde ou lorsqu'un retrait de surcharge est préparé. Le
sélecteur de médias du mode riche utilise les droits du Space édité.
`HtmlSanitizer::sanitize()` purifie les sources et surcharges créées par les API avant écriture. Les valeurs en base sont ensuite
lues telles quelles. Les catalogues versionnés proviennent du code de
l'extension et sont considérés fiables : ils ne sont pas purifiés au chargement.
Le purificateur est celui déjà utilisé pour le contenu des pages. Le rendu qui
insère une traduction dans un attribut HTML doit toujours échapper cette valeur.
Une surcharge ne peut être enregistrée que pour une clé déjà déclarée dans un
catalogue ou visible depuis ce Space.

## Surcharges et héritage

La migration Core `1.0.4` crée `language_custom_keys` et
`language_overrides` et donne `LANG_OVERRIDE_CRUD` au rôle super administrateur.
Chaque surcharge porte obligatoirement un `space_id`, une langue, une clé et
une valeur ; `(space_id, locale, lang_key)` est unique. La valeur vide est une
surcharge valide. La supprimer restaure la valeur héritée ou celle du catalogue.

Pour résoudre une clé, le moteur cherche une surcharge dans le Space demandé,
puis chez ses parents du plus proche à la racine, puis dans le catalogue de la
langue, puis dans le catalogue source français. Les surcharges des ancêtres ne
sont pas limitées aux langues actives chez eux : la racine peut, par exemple,
définir `en` pour un enfant qui active cette langue. Le moteur charge les
surcharges d'un Space et de ses ancêtres en bloc pour une langue donnée et
invalide son cache après une écriture.

L'UI propose les Spaces autorisés par `LANG_OVERRIDE_CRUD`, leurs langues
actives et celles de leurs descendants. Le tableau présente une clé par ligne
et une colonne par langue, avec la valeur effective. L'icône d'édition ouvre
les éditeurs de toutes les langues de cette clé, initialement en mode HTML.
Chaque champ montre la source commune, l'origine de la valeur, son catalogue, son éventuelle
surcharge héritée et la date de modification locale. Une langue inchangée
n'est pas écrite ; une valeur vide modifiée crée une surcharge vide. Le retrait
d'une surcharge se prépare par langue, puis le bouton unique enregistre les
modifications
de la ligne dans une même transaction. Les éditeurs ouverts occupent toute la
largeur, replient les longues lignes et grandissent avec leur contenu jusqu'à
70 % de la hauteur de la fenêtre, puis défilent. Leur hauteur initiale minimale
est de 120 px.

`/administrator/api/languages/matrix?spaceId=...` fournit toutes les colonnes
en une requête. `POST /administrator/api/languages/overrides/save-many` reçoit
une clé et au plus 20 changements (`locale`, `action=save|delete`, et `value`
pour `save`) ; le WF vérifie toutes les langues et les droits avant l'écriture.
Les routes unitaires `/entries`, `/overrides/save` et `/overrides/delete`
restent disponibles. `/spaces` et `/custom/create` servent au choix du Space
et à la déclaration des clés. Les écritures `POST` sont transactionnelles et
protégées par le jeton CSRF de l'administration.

Le sélecteur de clés de l'éditeur de pages lit
`GET /administrator/api/languages/selectable-keys?spaceId=…&locale=…`.
Cette route renvoie les clés déclarées et leurs valeurs effectives pour une
langue active du Space demandé. Elle exige `PAGE_READ` sur ce
Space ; `LANG_OVERRIDE_CRUD` reste réservé à l'édition des traductions.
La valeur affichée est informative : le document de page conserve la clé `LG_`
brute. Voir `Docs/LOCALIZED_TEXT_FIELD.md`.

## Dictionnaire dans Vue

`LanguageTranslator::dictionaryForSpace($spaceId, $locale)` calcule en bloc les
valeurs effectives des clés déclarées. Il applique exactement la priorité du
moteur PHP : surcharge locale, parent le plus proche, catalogue de la langue,
puis source française. Une surcharge vide reste vide. La méthode charge les
surcharges de la branche en une requête pour la langue demandée.

Les clés destinées à `Vue i18n` portent le préfixe `LG_RIA_` ; par exemple
`LG_RIA_CORE_LOGIN` ou `LG_RIA_MAIN_CONTACT`. Le préfixe est global, puis le nom
du propriétaire et du message évitent les collisions entre extensions.
`riaDictionaryForSpace()` filtre ces seules clés avant leur injection dans le
bootstrap. Les clés `LG_` ordinaires restent utilisables dans les pages, les
catalogues, le sélecteur de clés et la projection PHP. À ce jour, aucun
composant Vue applicatif n'emploie encore une clé `LG_RIA_`.

`BaseTemplate::getRiaGlobalVariable()` prépare la langue de la requête, la langue
par défaut, les langues actives et le sous-dictionnaire `LG_RIA_` résolu.
`MainTemplate/Template.php` transmet cette configuration dans `vueGlobalConf.language`. Le
script JSON de `MainTemplate/base.php` conserve les protections `JSON_HEX_*`, notamment
pour les traductions HTML. Aucun appel API n'est nécessaire au montage de Vue.
Les clés `LG_RIA_` sont transmises au navigateur sur chaque page : n'y placer
aucun secret. Si ce sous-ensemble grandit fortement, une sélection par page
pourra encore réduire la taille du bootstrap.

Chaque racine Vue, y compris un composant de page monté séparément, installe
Vue i18n avec la langue et les messages du bootstrap. Dans un template Vue,
`{{ $t('LG_RIA_CORE_LOGIN') }}` affiche le texte échappé ; `useI18n()` fournit
`t()` aux composants Composition API. Pour une clé HTML, le composant doit
choisir explicitement un rendu HTML : les catalogues d'extension sont du code
de confiance et les surcharges saisies dans l'administration sont purifiées à
l'enregistrement. Les paramètres interpolés sont échappés par Vue i18n.
Le caractère `@` brut est protégé à l'initialisation pour conserver les adresses
courriel ; les autres syntaxes de message Vue i18n, comme `{name}` et `|`, gardent
leur sens d'interpolation ou de pluriel.

## Projection explicite des champs ORM

`FieldType::TEXT`, `FieldType::JSON_TEXT` et `FieldType::JSON_ARRAY_TEXT` sont des
annotations d'éligibilité à la traduction. L'ORM lit et écrit toujours les
valeurs brutes : `TEXT` s'hydrate comme `STRING`, `JSON_TEXT` comme `JSON` (objet),
et `JSON_ARRAY_TEXT` comme `JSON_ARRAY` (tableau). Aucun remplacement de clé ne se
fait automatiquement pendant un `SELECT`, un `INSERT` ou un `UPDATE`.

`LanguageProjectionHelper::projectOrm($objet, $locale, $spaceId)` retourne un
**tableau de présentation** issu de `jsonSerialize()` ; il ne modifie jamais le
modèle ORM. Les champs masqués par le modèle restent absents. Seules les
propriétés annotées avec l'un des trois nouveaux types sont projetées.
`projectFieldValue($valeur, $type, $locale, $spaceId)` permet une projection
explicite d'une valeur isolée. Une chaîne est remplacée seulement si elle est
exactement une clé `LG_` déclarée ; les autres textes restent identiques.

Dans les deux types JSON, la projection parcourt les valeurs des tableaux et
objets imbriqués, préserve leur forme et ne change jamais les clés JSON. Les
sous-arbres dont la clé désigne un identifiant technique (`id`, `url`, `alias`,
`class`, `css`, `route`, `type`, `permission`, etc.) restent bruts. Les noms en
`snake_case` et en `camelCase`, comme `imageUrl` ou `modelId`, sont traités de la
même manière. Une profondeur supérieure à 32, plus de 100 000 nœuds, plus de
16 Mio de texte ou une référence
cyclique provoquent une exception technique ; l'objet source reste intact.
`LanguageProjectionHelper::projectLocalizedTextValue($valeur, $locale,
$spaceId)` sélectionne la variante d'un objet `{ "fr": "...", "en": "..." }`
dans un champ déclaré comme texte, puis résout une éventuelle clé `LG_`.
Une variante absente reste vide. Le document de page utilise cette convention
pour les champs de texte déclarés par les composants Core ; voir
[Composants de page](PAGE_COMPONENTS.md) et [Éditeur](PAGE_EDITOR.md) ;
les autres propriétés du JSON restent brutes.

Les composants de page fournis par une extension peuvent déclarer
`localized: true` sur un `editorField` textuel, y compris de type `url`. Le
schéma de l'extension valide chaque valeur résolue par langue ; le renderer PHP
et les props Vue ne reçoivent que celle de la requête. La validation et la
sécurité propres aux URL restent définies dans le schéma et le renderer de
l'extension. Voir `Docs/PAGE_EDITOR.md`.

Les lignes, colonnes et composants de page peuvent aussi déclarer
`props.common.languages`. Une liste absente ou vide les affiche dans toutes les
langues ; une liste de langues configurées limite leur visibilité. Masquer une
ligne ou une colonne masque ses descendants. Les langues désactivées restent
éditables dans la condition, mais ne sont pas proposées pour l'aperçu.

Aucun champ de modèle métier existant n'a encore été reclassé en `TEXT` ou en
type JSON traduisible. L'activation sur un modèle doit être explicite, champ
par champ, en vérifiant les appelants de sa projection.

## Tests

Le préparateur d'intégration installe `fr` sur le Space racine et `fr`/`en`
actifs sur le Space site. `SpaceLanguageIntegrationTest` vérifie la migration,
la création et la suppression d'un Space, la lecture des langues actives, ainsi
que les contraintes de langue par défaut. Voir
`src/tests/Integration/README.md` pour préparer la base dédiée.
`SpaceLanguageAdminIntegrationTest` exerce les routes HTTP de lecture et
d'écriture, la réorganisation, le changement de langue par défaut, les refus
de données invalides, l'accès invité et le jeton CSRF.
`LanguageTranslationIntegrationTest` couvre les catalogues, l'ordre de
résolution, l'héritage root → enfant, une valeur vide, la déclaration explicite
d'une clé personnalisée et la portée du droit par Space.
