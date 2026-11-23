# Éditeur de pages

Le document structuré et la bibliothèque extensible utilisent le JSON v3.
Le contrat de [bibliothèque de composants](PAGE_COMPONENTS.md)
décrit les classes PHP, l'interface de fournisseur, les zones génériques,
les contextes, les formulaires et les registres Vue.

## Stockage et normalisation

`pages.type = 1` utilise `raw_html`. Son édition reste réservée à ROOT dans le
Space de la page. Le sanitizer commun nettoie la saisie avant stockage, dans
l'aperçu et au rendu des anciennes pages HTML brut.

`pages.type = 2` utilise uniquement `data`, au format JSON v3. `zones` contient
les collections de corps `columns`, `tabs`, `panels` ou celles d'une extension.
La migration initiale installe directement cette version ; aucune reprise des
anciens documents structurés n'est fournie. Un ancien document est omis au
rendu pour qu'un footer obsolète ne bloque pas les autres pages.

`PageDocument` valide les IDs, tailles et limites de structure.
`PageComponentRegistry` appelle les méthodes de chaque composant pour
normaliser et contrôler ses paramètres, y compris dans les zones imbriquées.
Le HTML éditorial est purifié avant sauvegarde, collage ou aperçu d'un
brouillon. La lecture dans l'éditeur et le rendu des documents enregistrés
utilisent ensuite les données déjà purifiées.

Les textes localisés acceptent une chaîne commune, une clé `LG_` ou un objet
par langue. Une variante absente ou vide reste vide. La projection dépend du
Space et de la langue de la page ; les URL et paramètres techniques ne sont
pas projetés sans déclaration explicite du composant.

## Poids du document

Les propriétés de `Configuration` sont exprimées en octets :

```php
public int $page_max_size = 1_000_000;
public int $page_diagram_max_size = 250_000;
```

Leurs défauts figurent dans `src/releases/vdev/configuration.base.php` et sont
hérités par `Configuration`. La configuration locale Docker
`src/releases/vdev/configuration.php` les surcharge. Sans ces propriétés, les
installations existantes conservent les mêmes défauts. Les valeurs positives
sont plafonnées à 5 000 000 octets ; la limite d’un diagramme ne peut dépasser
celle de la page. Une valeur absente ou invalide reprend le défaut.

`PageSize` mesure le JSON effectivement persisté (`JSON_UNESCAPED_UNICODE`,
slash échappés), avant traitement et après la normalisation des composants.
Toutes les entrées du document utilisent cette politique. `PageDocument` et
les lectures de documents enregistrés conservent le plafond absolu de 5 Mo :
baisser le réglage n’empêche pas de lire une page existante, mais sa prochaine
validation doit respecter la nouvelle limite.

Une jauge apparaît dans l’en-tête de l’atelier au-delà de 50 % du réglage
courant, avec poids, maximum et pourcentage. Elle passe en orange à 80 % et
rouge à 100 %. Son calcul reprend l’UTF-8 et les échappements PHP des slash et
séparateurs Unicode ; le serveur reste autoritaire sur le document normalisé.
Le poids mesuré couvre `data`, sans les métadonnées de page ni son SCSS.

## Rendu et sécurité

Le moteur applique les options communes et les droits de visibilité avant
d'afficher les descendants. Les rôles sélectionnables appartiennent au Space
ou à ses parents ; une liste de rôles est interprétée en OU. Aucun cache HTML
partagé entre visiteurs ne contourne ces contrôles. Le canevas de l'éditeur
fait exception : il rend les composants soumis à des rôles même si l'éditeur
ne possède pas le rôle choisi, afin qu'ils restent sélectionnables et
modifiables. L'aperçu fidèle et le site public conservent ce filtre.

Les composants Core sont des classes de `Core/PageComponents`, inscrites dans
le même registre que celles des plugins. Les contrôles de liens MenuItem,
URL, médias, MIME et sources HLS sont conservés dans leurs services spécialisés.
Le serveur ne résout aucune classe PHP ni aucun import JavaScript issu du JSON.

Les styles structurés sont contrôlés et compilés dans une feuille propre à
la page. Le SCSS libre est limité à 100 000 octets, enveloppé dans le
sélecteur de la page et compilé avec la racine d'import fixe des médias du
Space. `raw_scss` est la source ; `raw_css` est le résultat servi par la route
CSS. La template collecte également les feuilles du footer.

## Édition et API

La liste affiche par défaut toutes les pages accessibles. Les badges de filtre
permettent de sélectionner un Space exact ou de revenir à tous les Spaces. Le
tableau propose le tri et la recherche par identifiant, nom et Space. Le filtrage
porte sur le répertoire déjà autorisé par le serveur ; il conserve la sélection
au retour de l'atelier tant que la liste reste ouverte.

Les pages sont publiées par défaut ; la migration conserve la publication des
pages existantes. Les statuts sont publié (1), dépublié (0), archivé (-1) et
corbeille (-2). Les archivées et la corbeille sont masquées par défaut et
affichables depuis les filtres. `PAGE_CRUD` dans le Space réel autorise le
changement de statut et la suppression définitive après mise en corbeille ;
les pages HTML brut exigent aussi ROOT, comme pour leur modification. Les
mutations verrouillent la page et passent par le WF et le CSRF.

Le rendu public et la feuille CSS d'une page non publiée sont vides ; l'aperçu
éditeur reste disponible. La suppression est permise même si des références
subsistent : un pied de page absent est vide et une entrée de menu pointant
vers une page absente, non publiée ou appartenant à un autre Space renvoie 404.
Les références ne sont pas effacées. Une entrée de menu de type page ne peut
viser qu'une page publiée du Space du menu ; le formulaire filtre son catalogue
par ce Space et le WF relit la page avant l'enregistrement. Le type de formulaire
`catalog.core_page_item`, utilisé par les menus et le pied de page de la template
Main, ne propose que les pages publiées accessibles ; le formulaire de menu et
les composants de page le filtrent sur leur Space, tandis que le pied de page
accepte le Space configuré ou un de ses parents.
`catalog.core_all_page_item` propose toutes les pages accessibles, quel que
soit leur statut. Si une référence existante n'est plus publiée, il faut choisir
une page publiée pour enregistrer un formulaire utilisant le type standard.

`AdminPageController` adapte les requêtes ; `WFPage` vérifie les droits et le
Space réels avant d'utiliser les helpers et le registre. `PAGE_READ` permet de
lire ; `PAGE_CRUD` permet d'éditer une page visuelle.
`access_edit_role_id` est conservé en base mais ne contrôle plus l'écriture.
L'enregistrement utilise la transaction de la route et conserve le jeton de
révision existant. Aucune gestion supplémentaire d'édition simultanée n'est
ajoutée.

Routes sous `/administrator/api/pages` : répertoire, `read`, `editor-context`,
`link-menu-items`, `create`, `update`, `status`, `delete`, `preview`, `canvas`, `validate-draft`,
`ai/sanitize-html` et `ai/validate-draft`. Les mutations et aperçus POST restent
soumis au CSRF.

La sauvegarde, l’aperçu, le canevas, `validate-draft` et la validation IA
appliquent la même méthode serveur : limite de taille configurée, normalisation
du document v3, contrôle des composants, des médias et du HTML. Les contrôles
de droits et de contexte restent propres à chaque route.

À l'ouverture ou à la création d'une page, le serveur prépare tous les
contextes des types ajoutables et leurs formulaires PHP. Le champ de lien
partagé lit les MenuItem dans ce contexte, sans API propre à chaque occurrence.
Les composants Vue spécialisés peuvent conserver leurs appels métier.

Pendant l'enregistrement, `showModaleWait()` couvre l'écran ; un `finally`
appelle `hideModaleWait()`, puis `showModaleSuccess()` annonce la réussite.
Les champs restent actifs ; la lecture seule liée aux droits est indépendante.

## Atelier visuel

L'édition du type 2 utilise un panneau gauche à quatre onglets verticaux :
catalogue, propriétés du nœud sélectionné, paramètres de page et arborescence.
L'aperçu central Vue permet la sélection par ID, les six formats responsive
Mobile (`xs`), Petit écran (`sm`), Tablette (`md`), Bureau (`lg`), Grand écran
(`xl`) et Très grand écran (`xxl`), ainsi que le glisser-déposer. Chaque format
utilise une largeur représentative de son intervalle Bootstrap ; l'aperçu peut
défiler horizontalement si l'espace disponible est plus étroit. Dès l'ouverture
d'une page, l'atelier occupe toute la fenêtre et bloque le défilement de la page
d'administration. Le panneau gauche et le canevas défilent chacun dans la
hauteur disponible, pour garder les deux zones accessibles sur une longue page.
Les colonnes reprennent la largeur définie pour le format choisi, ou celle du
format inférieur.
Le store `admin_pages` porte le brouillon, la sélection, l'état modifié et les
opérations d'arbre. Le bouton Enregistrer reste l'unique écriture de page.
Ctrl+S l'actionne aussi depuis les champs et l'iframe TinyMCE. Une sauvegarde
réussie affiche la modale de succès commune. Après enregistrement, l'onglet de
l'atelier et le composant sélectionné restent actifs ;
ouvrir une autre page réinitialise ces choix. Quitter un brouillon modifié demande
confirmation.

Le glisser-déposer utilise les collections `zones.columns` et `items` du document v3 : la racine accepte des
lignes ; chaque ligne contient de 1 à 100 colonnes sur une grille de 12 unités ; une colonne accepte des
composants et des lignes imbriquées. Une colonne peut être réordonnée ou déplacée
vers une autre ligne tant que les deux lignes restent valides. Un composant
ordinaire déposé à la racine est enveloppé dans une nouvelle ligne et une
nouvelle colonne. Les destinations formant un cycle, dépassant 12 niveaux ou
500 nœuds sont refusées avant de modifier le brouillon. Les boutons Ajouter,
Monter, Descendre et Déplacer dans offrent des commandes sans glisser-déposer.
Dans l'onglet Structure, cliquer un élément le sélectionne sans quitter
l'arborescence. Une poignée à gauche permet de le glisser sur la moitié haute ou
basse d'un autre élément compatible pour le placer avant ou après, ou directement
sur une colonne pour y déposer un composant ; un repère indique la position.
Les petites commandes Monter et Descendre déplacent les
éléments au clavier ou sans glisser-déposer. Ces actions gardent l'onglet ouvert.
Le bouton Propriétés à droite de chaque élément ouvre explicitement son édition.

Dans les propriétés générales, `core.rich-text` utilise le composant global
`rich_text_editor` (TinyMCE) et conserve son HTML dans `props.content`. Le choix
de police est masqué dans la barre d'outils et le menu Format. La barre ajoute
les commandes d'interligne, de styles,
recherche, ancres, symboles, direction du texte, outils de visualisation et de
collage. La sélection rapide propose gras, italique, souligné, titres 2/3,
lien et citation. Le menu « Formats » fusionne les groupes natifs de TinyMCE et le
groupe « Conteneur » ; `importcss_append` conserve ces formats lorsque le plugin
`importcss` ne trouve aucune feuille de style à importer. Le bouton « Médias du
site » et son entrée dans le menu « Insérer » apparaissent uniquement avec
`MEDIA_READ` pour l'espace de la page. Ils reprennent le
sélecteur commun ; l'URL insérée commence par `/medias/`.
TinyMCE garde ces URL telles quelles (`convert_urls: false`). Une image devient
`<img>`, une vidéo `<video controls>` et un autre fichier un
lien `<a>`. Le sanitizer PHP conserve les vidéos dont la source commence par
`/medias/` et retire leurs attributs autres que `src` et `controls`. Le type
`core.html` garde une saisie de source HTML avec CodeMirror. Le HTML brut et le
SCSS personnalisé de la page utilisent également CodeMirror. Leur hauteur
suit le contenu, comme celle du champ Mermaid ; Tab insère une tabulation et
Maj+Tab retire une indentation. Ces éditeurs sont en lecture seule quand la
page n'est pas modifiable ; le voile commun bloque l'interaction pendant
l'enregistrement.
Le contenu de `core.html` et `core.rich-text` est purifié par `HtmlSanitizer`
à l'entrée du document ; le rendu des données enregistrées ne le repurifie pas. TinyMCE propose les tailles `50%` à `160%`
(par pas de `10%`, hors `100%`) et `0.7em` à `1.7em` (par pas de `0.1em`) ;
seules ces valeurs de `font-size` sur un `span` sont conservées. Les autres
styles de `span` conservés sont `color` et `background-color` avec des couleurs
hexadécimales, `rgb()`/`rgba()` valides ou références simples `var(--nom)`
([contrat commun TinyMCE](FRONTEND.md#couleurs-du-texte-enrichi)). L'interligne est limité aux valeurs
`1`, `1.1`, `1.2`, `1.3`, `1.4`, `1.5` et `2` sur les blocs concernés. L'alignement
du texte est limité à gauche, centre, droite et justifié sur ces blocs. Les images
peuvent utiliser les classes d'alignement proposées dans la fenêtre TinyMCE.
Les autres styles en ligne et les éléments non autorisés sont retirés.
Sans taille explicite, TinyMCE peut afficher `16px`, la taille calculée par le
navigateur ; cette valeur ne fait pas partie du menu et n'est pas enregistrée.
Les feuilles de style et la base d'émojis de TinyMCE sont incluses dans le bundle
frontend ; le composant ne les charge pas depuis des chemins relatifs au site.
L'interface TinyMCE utilise `fr-FR`, avec la traduction communautaire française
auto-hébergée dans `RichTextEditor/langs/fr-FR.js` (paquet officiel TinyMCE 7,
clé de langue adaptée à TinyMCE 8). Les libellés ajoutés depuis cette version
et absents du paquet peuvent rester en anglais.
Sur écran large, la séparation entre le panneau gauche et l'aperçu se déplace
à la souris ou au clavier (flèches, Début, Fin) pour régler la largeur du
panneau. La largeur choisie est mémorisée automatiquement dans le stockage local
du navigateur après ce réglage ; une réduction due à une fenêtre étroite ne
remplace pas cette préférence. Le bouton « Plein écran » du texte riche ouvre
le mode plein écran de TinyMCE sur le même brouillon, sans autre sauvegarde.

L'aperçu Vue montre immédiatement les changements et garde les zones vides
sélectionnables. Le texte riche et le HTML personnalisé y affichent le HTML du brouillon
nettoyé par DOMPurify, avant la purification PHP de l'enregistrement. Cet aperçu
est réservé au brouillon de l'éditeur et ne sert pas au rendu public. Il
utilise les canevas Vue inscrits au registre ou un fragment PHP par API
dans une iframe isolée ; un substitut reste disponible si aucun aperçu n'est fourni. `POST /administrator/api/pages/preview` reçoit le
brouillon non enregistré, vérifie les droits d'édition, normalise et valide le
document, compile le SCSS en mémoire, puis rend avec `PageRenderer`. Le résultat
est montré dans un iframe `sandbox="allow-scripts"` sans `allow-same-origin`.
Seul le moteur Mermaid et ses modules sont autorisés par la CSP locale ; les
composants Vue publics d'extension utilisent leur rendu PHP de repli.
Les shortcodes sont interprétés par le helper Core après nettoyage dans le
rendu fidèle et sur le site public. Le canevas conserve leur syntaxe littérale,
sans conversion ni appel API dédié ; voir `Docs/SHORTCODES.md`.
Le rendu fidèle s'applique aux droits de
l'utilisateur courant ; la simulation d'autres visiteurs n'est pas livrée.

Un type d'extension devenu indisponible reste dans le document et peut être
sauvegardé. Il est signalé dans les propriétés et omis du rendu public.
`PageComponentRegistry` continue de valider les types enregistrés. Le mode HTML
brut reste réservé à la permission ROOT sur le Space 1 et s'édite dans les paramètres de page avec
CodeMirror. Son HTML est nettoyé par le sanitizer commun ; les scripts et
balises arbitraires ne sont pas autorisés.

## Propriétés du composant et styles communs

Le panneau de propriétés utilise les composants Vue globaux `tabs` et `tab` :
Général contient les paramètres du type, y compris les `editorFields` des
extensions ; Styles contient les réglages communs ; Avancé contient les rôles
de visibilité, les tailles masquées, la classe CSS et les actions de déplacement
et suppression. Les listes de rôles et de tailles réutilisent
`checkboxes_dropdown`. Ses clés sont des chaînes ; les rôles sont reconvertis
en entiers avant sauvegarde pour respecter le contrat du serveur.

L'image du composant `core.image`, l'image de fond des styles communs et les
`editorFields` d'extension de type `media_file` utilisent le contrôle commun
`MediaFileInput` avec des champs et boutons de taille `sm`. Les couleurs de
bordure, de texte, de liens, de fond et du filigrane utilisent le contrôle commun
`ColorInput` avec réglage d'alpha. Les couleurs des styles communs acceptent
aussi la forme abrégée `#RGB`, que le contrôle développe en `#RRGGBB` à la
sortie du champ. Dans l’éditeur, `PageColorInput` active aussi le choix d’une
couleur de la template : la valeur stockée reste une référence `var(--nom)`.
Le contrôle d’opacité est masqué dans ce mode ; les couleurs personnalisées
conservent leur alpha. Ces contrôles sont aussi
utilisés par les adaptateurs des formulaires. Le sélecteur de médias parcourt
seulement l'espace de la page. La saisie directe reste disponible ; la
validation existante des pages continue de
s'appliquer à l'enregistrement, notamment le chemin local exigé pour l'image
de fond.

L'onglet Styles propose `text_align` (`left`, `center`, `right`) pour chaque
composant, ligne et colonne. Il s'applique à leur enveloppe `<div>` et donc au
contenu, y compris aux boutons et images. Une colonne conserve son ancien
`props.align` si aucun `text_align` commun n'est défini ; le nouveau style est
prioritaire lorsqu'il est renseigné. L'option « Hérité » supprime la propriété
et ne génère aucune règle `text-align` pour le nœud. Dans l'éditeur, une colonne
historique affiche son ancien alignement dans le même contrôle ; choisir une
valeur ou « Hérité » retire cet ancien champ.

Dans l'onglet Général, le composant `core.button` utilise `text` et les [champs de lien](#liens-des-images-et-boutons), et accepte `variant`
(`primary` par défaut, autres variantes Bootstrap pleines, `outline-*` et
`link`) ainsi que `size` (`sm`, `lg` ou taille normale). Ses surcharges
facultatives sont dans `props.button_style` : `color`, `background_color`,
`border_color`, `hover_color`, `hover_background_color`,
`hover_border_color`, `border_width` et `border_radius`. Les couleurs suivent
les formats des styles communs (hexadécimal ou variable de template) ; les longueurs utilisent `0`,
`px`, `rem` ou `em`, et le rayon accepte aussi `%`. Chaque valeur renseignée
remplace la variable CSS Bootstrap correspondante sur ce seul bouton ; une
valeur absente garde celle de la variante. Les classes et les surcharges sont
validées au serveur, et le CSS est ciblé par l'ID de la page et du nœud.

Les styles sont enregistrés dans `props.common.style` pour les composants, les
lignes et les colonnes. Les noms plats `margin_top`, `padding_left`,
`border_bottom`, `border_style`, `border_color`, `border_radius`, `color`, `link_color`, `text_align`,
`background_color`, `background_image`, `background_position`,
`background_size`, `background_image_opacity`, `background_image_tint`,
`position` et `offset_top` illustrent le schéma. Les quatre côtés existent
pour margin, padding, épaisseur de bordure et décalage. Les longueurs acceptent
`px`, `rem`, `em` ou `%` et au plus quatre chiffres ; `0` sans unité est également
accepté. Seules les marges et les décalages acceptent un signe négatif. Les
couleurs sont des codes hexadécimaux ou des références `var(--nom)` déclarées
par la template, et l'image de fond est un chemin local
sans `..` ni caractère
de contrôle. Le positionnement accepte `static`, `relative`, `absolute` et
`sticky`. Les valeurs inconnues ou invalides sont refusées à la sauvegarde et
à l'aperçu serveur.

Le placement de l'image de fond accepte les cinq positions prédéfinies ou un
couple `X% Y%`, chaque axe étant un entier de 0 à 100. Le mode personnalisé
commence à `50% 50%`. L'opacité de l'image se règle avec un curseur de 0 à
100 % et vaut 100 % par défaut. Un filigrane peut superposer une couleur
hexadécimale `#RRGGBB` ou `#RRGGBBAA`, ou une référence `var(--nom)` de
la template. L’alpha hexadécimal règle son opacité indépendamment de l’image ;
une référence variable ne propose pas de réglage d’opacité. L'ajout initialise le
filigrane à un blanc d'environ 30 % d'opacité ; retirer le filigrane efface la
couleur. L'image et le filigrane sont rendus sur deux couches derrière le
contenu du nœud ; les textes et composants enfants gardent leur opacité. Le
nœud devient positionné (`relative`) si sa position était `static` ou non définie.

`PageCommonStyles` génère des règles ciblées par `#page-ID` et `data-page-node`
pour les nœuds du brouillon. Elles sont ajoutées au CSS compilé de la page,
stockées dans `raw_css` à la sauvegarde et retournées avec le rendu fidèle sans
écriture. `pageCommonStyle.js` applique les mêmes valeurs validées dans l'aperçu
Vue interactif. Les colonnes gardent en plus leur alignement historique.

Le réglage « Couleur des liens » (`link_color`) s'applique aux liens de texte
(`a` sans classe `btn` ni rôle `button`), dans tous leurs états, notamment au
survol et au focus. Les couleurs propres aux boutons restent prioritaires.
La valeur devient la variable héritée `--page-link-color` sur l'enveloppe :
un réglage sur une ligne, colonne ou autre corps s'applique à ses descendants,
et chaque composant enfant peut définir sa propre couleur. Le compilateur
ajoute une règle pour les liens uniquement lorsqu'une valeur est renseignée ;
une valeur absente ou vide garde l'héritage ou les couleurs de la template.
Le canevas Vue utilise la même variable et le même filtre de liens. Les
couleurs fixées directement dans le HTML ou par une règle CSS `!important`
conservent leur priorité.

### Couleurs de la template

Une template peut surcharger `BaseTemplate::getEditorColorVariables()` ; la base
Core propose par défaut les six couleurs Bootstrap de statut, ainsi que les
couleurs du texte et des titres. Son résultat est une liste déclarée en PHP,
sans lecture de feuille CSS ni valeur figée dans le document :

```php
public function getEditorColorVariables(): array
{
    return [
        ['variable' => '--bs-primary', 'label' => 'Principale'],
        ['variable' => '--bs-body-color', 'label' => 'Texte'],
    ];
}
```

Déclarer seulement des variables **globales de couleur**, disponibles dans le CSS
de la template. Les variables `*-rgb`, les dimensions et les variables internes
aux composants (`--bs-btn-*`, `--pt-*`, `--page-audio-*`, etc.) ne conviennent pas :
elles peuvent notamment créer des références circulaires lors des surcharges.
MainTemplate propose les six couleurs de statut et ses couleurs de texte, titres,
fonds et bordures. `BaseTemplate::getRiaGlobalVariable()` utilise cette méthode
pour alimenter `templateColors` ; MainTemplate transmet ce résultat dans
`vueGlobalConf`, accessible aux composants Vue via `conf.getTemplateColors()`.

`TemplateHelper::getEditorColorVariables($spaceId)` lit la template du Space réel
et valide son catalogue (128 entrées au maximum, noms ASCII uniques commençant
par `--`, libellés UTF-8 de 100 caractères au maximum). Seuls `variable` et `label`
sont transmis sous `plg_core_template_colors` dans le contexte d’édition. Les
contrôles de lecture/édition de page existants précèdent cette préparation ;
aucune configuration complète de template n’est exposée.

`PageComponents/Support/PageColors` fournit la grammaire commune aux styles
communs, boutons, Tabs, accordéons et Audio : hexadécimal existant ou exactement
`var(--nom)`, sans repli, expression, commentaire ni CSS libre. La casse du nom
est conservée. À chaque entrée du document, le registre vérifie aussi les
références des nœuds et des corps de zones contre le catalogue reconstruit côté
serveur pour le Space cible ; le catalogue du navigateur ne fait jamais autorité.
Le filigrane accepte la même référence, sans tenter d’en décoder un alpha.

Les rendus et compilateurs CSS conservent les références telles quelles. Ils ne
réinterrogent pas le catalogue au rendu : changer la couleur du thème actualise
les pages qui la référencent sans les réenregistrer. Après un changement de
template, une référence absente reste conservée et est signalée dans le contrôle ;
elle doit être remplacée avant réenregistrement. Sans définition CSS correspondante,
le navigateur applique les règles de repli/héritage CSS normales ; aucun ancien
hexadécimal n’est enregistré comme repli automatique.

En mode couleur personnalisée, la palette commence par les couleurs de la
template. Elles sont résolues à chaque ouverture du menu et leur clic enregistre
une teinte hexadécimale fixe, qui ne suit plus les changements du thème. Le mode
variable conserve son stockage `var(--nom)` et reste accessible par son icône.

Les pastilles et aperçus utilisent le thème du **Space courant**. Éditer un autre
Space peut donc afficher une autre teinte, même si le catalogue et la validation
utilisent bien le Space de la page. L’aperçu fidèle conserve son chargement
existant de `/templates/main/css`. Aucune lecture du CSS ni modification globale
du thème de l’administration n’est nécessaire.

Le mode template du contrôle de couleur est activé pour les réglages de
composants Core. Les champs génériques `core.color` et la configuration des
templates conservent leurs contrats hexadécimaux existants. Tous les TinyMCE
proposent aussi les variables dans leur palette native ; leur HTML suit la
[validation syntaxique commune](FRONTEND.md#couleurs-du-texte-enrichi), tandis que
les styles structurés des composants sont contrôlés avec le catalogue du Space réel.

## Assistance IA de l'atelier

L'onglet IA s'ajoute au panneau gauche pour les pages visuelles, avec
`PAGE_CRUD` et `AI_USE` dans le Space de la page. Son code propre à l'éditeur
réside dans `src/ria/src/components/administrator/pages/ai/`. Les Tools
agissent sur des ID de nœuds stables et seulement sur une copie du brouillon
avant la confirmation de l'utilisateur. Les opérations de structure reprennent
les contraintes de `pageEditorModel.js` ; l'API
`/administrator/api/pages/ai/validate-draft` appelle `PageDocument` et
`PageComponentRegistry` sans rendre ni enregistrer le projet de modification.
Une modification concurrente du brouillon invalide la proposition. La
sauvegarde explicite conserve son contrôle de révision habituel.

Les composants inscrits apparaissent dans les Tools génériques via le catalogue
PHP, ses schémas et ses champs déclarés. Les formulaires PHP complètent ces
métadonnées à l'ouverture. Les champs en lecture seule sont exclus. Un champ
HTML est nettoyé avant d'entrer dans le brouillon ; remplacer directement un
objet multilingue par une chaîne est refusé. Les références et la validation
métier utilisent les mêmes méthodes de `PageComponent` que la sauvegarde.
Un type absent du catalogue ne peut pas être ajouté par ces Tools.

Le HTML proposé par l'IA pour un composant `core.html` ou `core.rich-text`
est nettoyé par `/administrator/api/pages/ai/sanitize-html` avant d'entrer
dans le brouillon. Le serveur applique encore son nettoyage normal à la
sauvegarde et avant l'aperçu serveur. La saisie manuelle conserve son parcours actuel.
Voir `Docs/AI.md` pour le chat, le budget des Tools et la réduction du contexte.


## Composant Tabs

`core.tabs` affiche des onglets horizontaux au-dessus du corps actif. Il contient
une liste `zones.tabs` de 1 à 12 zones `{id, label, icon, props, items}`. Chaque `items`
accepte les composants du catalogue et les lignes avec leurs colonnes, y compris
des Tabs imbriqués. Les ID des corps appartiennent à la même portée que ceux des
lignes, colonnes et composants ; ils participent aux limites de 500 nœuds et de
12 niveaux. Le serveur purifie les contenus imbriqués et vérifie leurs références,
styles et conditions de visibilité. Une zone refusée par les rôles ou la langue
n’émet ni libellé ni contenu public.

`label` utilise le contrat multilingue des textes simples (chaîne ou objet par
langue, 500 caractères). `icon` est facultatif : chaîne vide ou deux classes
Font Awesome `fa-solid|fa-regular|fa-brands fa-…`, choisies avec `icon_picker`.
Le texte reste présent à côté de l’icône. `props.default_tab` désigne l’ID du
corps ouvert par défaut ; sans valeur, ou si ce corps est invisible au visiteur,
le premier corps visible est ouvert. La suppression du corps par l’éditeur
supprime aussi sa référence par défaut. Le dernier corps ne peut pas être retiré.
Les corps se réordonnent au sein de leur Tabs ; leurs composants se déplacent
entre les corps et les colonnes. La sélection d’un descendant dans l’arbre ouvre
ses onglets parents dans le canevas.

`props.mode` accepte `bootstrap` (défaut), `simple` et `custom`.
Le style Simple reprend une barre jointive gris très clair, un onglet actif gris,
du texte bleu foncé, des angles droits et un corps blanc à fine bordure grise.
Le mode Personnalisé utilise `props.tabs_style`, commun à tous les onglets :

- `tab_*`, `hover_*`, `active_*`, `body_*` pour `color`, `background_color`,
  `border_color` ;
- `tab_*` et `body_*` pour `border_width`, `border_style`, `border_radius`,
  `padding_top`, `padding_right`, `padding_bottom`, `padding_left`.

Les couleurs acceptent `#RGB`, `#RRGGBB`, `#RRGGBBAA` ou une référence
`var(--nom)` déclarée par la template. Les longueurs sont
positives : `0`, ou un entier d’au plus quatre chiffres en `px`, `rem`, `em`, `%`.
La bordure accepte `none`, `solid`, `dashed`, `dotted`, `double`.
Les valeurs vides gardent les valeurs par défaut ; les champs inconnus sont
refusés. Les réglages sont conservés en changeant de mode, mais ne s’appliquent
qu’en mode Personnalisé. `PageTabs` génère les variables CSS ciblées par page et
composant. Un Tabs imbriqué réinitialise ces variables pour ne pas hériter de
l’apparence du parent. Les styles communs restent sur les enveloppes habituelles.

Le rendu utilise des groupes radio natifs, libellés échappés et panneaux associés,
avec navigation clavier et focus visible. Le CSS commun `page-tabs.scss` choisit
le corps actif sans script, y compris dans l’iframe d’aperçu fidèle. Sur une
largeur étroite, les libellés peuvent passer à la ligne au-dessus du corps.
Le canevas utilise les mêmes règles et des groupes distincts. Le choix temporaire
d’un corps dans le canevas ne modifie pas l’onglet ouvert par défaut enregistré.

`PageDocument::childZones()` et `pageEditorModel.childZones()` fournissent le
parcours commun des zones de contenu. La validation des enfants, la compilation
des styles et la sélection réutilisent ce parcours pour Tabs, Accordéon et les
conteneurs d’extension.
Les Tools IA savent lire et remplir les corps existants, déplacer leurs composants,
modifier le mode et l’onglet par défaut ; les libellés, icônes et réglages propres
aux onglets restent édités par l’interface dédiée.


## Accordéon et agrandissement des images

### Accordéon

`core.accordion` contient `zones.panels`, une liste de 1 à 12 zones
`{id, label, icon, props, items}`. Les corps reprennent les mêmes validations,
textes multilingues, icônes facultatives, règles de visibilité et possibilités
de composition que Tabs. Les accordéons peuvent être imbriqués dans des Tabs
et réciproquement. Les limites de 500 nœuds et 12 niveaux englobent les panneaux
et leurs descendants. La sélection dans l’arbre ouvre les zones parentes dans
le canevas, sans modifier leur état initial enregistré.

Les paramètres spécifiques dans `props` sont :

- `single_open` : booléen, `true` par défaut. Une case « Un seul panneau ouvert
  à la fois » règle le comportement par composant.
- `open_panels` : liste d’ID de panneaux ouverts au départ, vide par défaut.
  En mode exclusif, elle ne peut contenir qu’un ID. Les doublons et références
  absentes sont refusés. La suppression d’un panneau nettoie cette liste et
  le dernier panneau ne peut pas être supprimé.
- `show_arrow` : booléen, `true` par défaut ; masque ou affiche l’indicateur.
- `arrow_icon` : classes Font Awesome au même format que les icônes de panneau.
  Une chaîne vide utilise `fa-solid fa-chevron-right`. Le sélecteur d’icônes
  permet aussi de revenir à cette valeur par défaut.
- `arrow_position` : `left` ou `right` ; droite par défaut.
- `arrow_rotation` : entier de −360 à +360 degrés, **90° par défaut**. L’angle
  s’applique quand le panneau est ouvert, y compris pour une icône personnalisée
  et dans les trois styles. Zéro conserve une icône fixe. Chaque accordéon
  imbriqué réinitialise sa rotation pour ne pas hériter de celle du parent.
- `mode` : `bootstrap`, `simple` ou `custom`, comme Tabs.
- `accordion_style` : mêmes champs validés que `tabs_style` ; le préfixe
  `tab_*` désigne ici les en-têtes. Les états normal, survol, ouvert et le corps
  sont personnalisables ; bordures, arrondis et quatre paddings sont communs
  aux panneaux. Les styles libres ne sont pas acceptés dans ces réglages.

`PageDocument::childZones()` inclut les panneaux ; `PageAccordion` valide les
paramètres et compile leurs variables CSS par page et nœud. Les contrôles de
styles du panneau de propriétés sont partagés avec Tabs dans
`PageContainerStyleFields.vue`.
Le renderer émet des éléments natifs `details` / `summary` : les groupes `name`
sont distincts par page et composant en mode exclusif, et absents en mode multiple.
Le comportement public sans script, y compris dans l’aperçu fidèle, requiert un
navigateur prenant en charge les groupes `details[name]` pour l’exclusivité.
Les panneaux non autorisés sont omis avec tout leur contenu avant le rendu.
Les Tools IA parcourent les panneaux, y insèrent ou déplacent des composants et
peuvent régler le mode, le booléen d’exclusivité et les paramètres de flèche.

### Images

`core.image.props.click_action` choisit `none` (défaut), `enlarge` ou `link`.
L’éditeur présente « Aucune action », « Agrandir » et « Ouvrir un lien » ; les
modes sont exclusifs. En mode `link`, les champs de lien communs à Image et
Bouton sont affichés (contrat ci-dessous). Aucun script d’agrandissement n’est
attaché à une image en mode lien. Le champ `enlarge_on_click` n’est plus utilisé ;
aucune migration de données n’est réalisée pour cette évolution.

En mode `enlarge`, l’image affichée conserve ses dimensions habituelles ; le
clic ouvre la même source dans une visionneuse adaptée à l’écran,
sur fond noir à 82 % d’opacité, avec un bouton Fermer. Le clic autour de l’image
et Échap ferment aussi la visionneuse.

Le PHP émet un bouton avec l’URL locale validée dans `data-page-image-src` ;
aucun lien ne navigue directement vers le fichier, pour ne pas transformer
un SVG affiché comme image en document actif. Les chemins avec antislash sont
également refusés, au serveur et dans le client, pour éviter une interprétation
en hôte externe par le navigateur. Les dimensions du composant ne s’appliquent
pas à la visionneuse ; aucune autre image en haute définition n’est générée.

`ImageLightbox.vue` partage le rendu entre le canevas et le site public.
`components/features/imageLightbox/imageLightbox.js` installe un seul écouteur
sur les boutons rendus par PHP,
y compris les pages du Footer. La boîte native `dialog.showModal()` assure le
focus modal ; le défilement est bloqué pendant l’ouverture, puis restauré,
avec retour du focus à l’élément d’origine. Les événements clavier de la boîte
ne déclenchent pas la fermeture des fenêtres d’administration situées derrière.
Les URL et textes sont affectés comme attributs DOM, sans interpolation HTML.
Sans JavaScript ou sans support de `dialog.showModal()`, l’image publique reste
lisible mais l’agrandissement est inactif. L’iframe d’aperçu fidèle utilise
`sandbox="allow-scripts"` avec une CSP limitée aux modules Mermaid ; ses liens
et boutons ne sont pas interactifs. L’agrandissement se teste dans le canevas,
et la navigation ainsi que le nouvel onglet se testent sur la page publique.



## Liens des images et boutons

`PageLinkFields.vue` est partagé par Image et Bouton. Il propose « Lien libre »
ou « MenuItem du Space », puis « Ouvrir dans un nouvel onglet ». Le sélecteur de
destination présente directement les entrées regroupées par menu ; les libellés
indiquent la hiérarchie des parents. Seules les entrées publiées (`status=1`)
et navigables dans au moins une langue active du Space sont proposées. Textes,
séparateurs et routes exigeant un paramètre de chemin sont exclus. Une entrée
publiée masquée dans la barre de navigation (`display_in_menu=false`) reste une
destination possible. Les droits du visiteur sont appliqués au rendu, pas à la
liste éditoriale accessible par `PAGE_CRUD`.

Les propriétés communes dans `props` sont :

| Champ | Contrat |
| --- | --- |
| `link_type` | `url` (défaut) ou `menu`. |
| `href` | Chaîne facultative, vide pour un lien à compléter. Chemin local commençant par `/` ou URL HTTPS, au plus 2 048 octets. |
| `menu_item_id` | Entier positif pour une destination ; `0` ou absence représente un choix à compléter. |
| `link_new_tab` | Booléen strict, `false` par défaut. Ajoute `target="_blank" rel="noopener noreferrer"` si activé. |

Changer de type de lien retire la destination inactive. L’image utilise ces
propriétés uniquement avec `click_action=link` ; le bouton les utilise toujours.
Les URL relatives sans `/` initial, les fragments seuls, HTTP, `javascript:`,
`data:`, les URL sans protocole `//`, antislashs, contrôles et échappements de
séparateurs dangereux sont refusés. `PageLinks::validate()` contrôle les types
et les URL lors de la normalisation des composants Image et Bouton par le
registre ; les textes et attributs restent échappés au rendu.
Le canevas conserve la sélection au clic, sans navigation ; l’agrandissement y
reste testable. Le rendu public et l’aperçu fidèle partagent le renderer PHP.
Les dimensions en pourcentage d’une image liée sont appliquées à l’ancre,
puis à 100 % sur son image enfant, pour éviter de multiplier les pourcentages.

`GET /administrator/api/pages/link-menu-items?spaceId=…` exige `PAGE_CRUD` sur
le Space demandé dans `WFPage::linkMenuItems()`, sans exiger `MENU_READ`. La
réponse privée et non mise en cache contient seulement `{items:[{id,menu_id,
menu,label,locales}]}` ; aucun paramètre de contrôleur ni URL n’est exposé.
`MenuRouteHelper::getUrlForContext()` résout les liens avec un Space et une
langue explicites, sans dépendre des routes de la requête d’administration.
Il respecte l’accueil propre à la langue, les alias des parents, les routes
techniques déclarées par les contrôleurs, les restrictions de langue de toute
la chaîne et, au rendu, les rôles du visiteur dans le Space concerné.

`Core/PageComponents/Support/PageLinks` est une classe locale commune aux
composants Image et Bouton, instanciée avec les services Menu, routes et langues
explicites. Elle ne figure pas dans les traits de helpers partagés. Le workflow
fait contrôler toute référence active de MenuItem à la sauvegarde, à l’aperçu et à
la validation d’un collage ou d’un brouillon IA : une référence positive doit
rester publiée, navigable et appartenir exactement au Space de la page, sans
héritage des menus d’un Space parent. Un choix non renseigné reste enregistrable.
Si une destination enregistrée devient indisponible, le rendu conserve l’image
sans ancre ou le bouton comme libellé désactivé, sans repli vers une autre URL.
L’éditeur signale un choix absent du catalogue ou indisponible dans la langue
de l’aperçu ; une référence invalide doit être corrigée avant réenregistrement.

## Liste de fichiers d’un dossier

`core.file-list` liste automatiquement les fichiers directement présents dans
un dossier des médias du Space de la page. Le composant se place dans les mêmes
zones que les autres composants, notamment les Tabs et accordéons. Aucun contenu
de sous-dossier n’est ajouté. Les fichiers et dossiers cachés et les liens
symboliques sont exclus. Le rendu relit les fichiers à chaque requête ; aucun
nom ou instantané du dossier n’est stocké dans le document.

Les paramètres dans `props` sont :

| Champ | Contrat / défaut |
| --- | --- |
| `folder` | Chemin relatif aux médias du Space ; `null` ou absent : non configuré ; chaîne vide : racine explicitement choisie. |
| `compact` | Booléen, `false` : nom puis actions libellées ; `true` : nom et petites icônes sur la même ligne, avec retour à la ligne sur écran étroit. |
| `show_date`, `show_size` | Booléens, `true` : date de modification et taille. |
| `show_thumbnails` | Booléen, `false` : images MIME `image/*` en miniature de 40 × 40 px, proportions conservées, avant les métadonnées. |
| `show_open`, `show_download` | Booléens, `true` : actions Ouvrir et Télécharger. |
| `sort_1`, `sort_2` | `name`, `extension`, `modified`, `size` ; défaut `name`. Le tri 2 départage le premier. |
| `direction_1`, `direction_2` | `asc` ou `desc`, défaut `asc`. |
| `first_1`, `first_2` | Sous-chaînes séparées par des virgules, maximum 500 caractères, chaîne vide par défaut. |

Pour chaque critère, les fichiers correspondant aux priorités sont placés en
tête dans l’ordre des sous-chaînes, indépendamment du sens de tri ; la recherche
porte sur la valeur du critère, sans distinction de casse. Par exemple,
`pdf,png,jpeg,svg` sur le nom ou l’extension groupe ces fichiers en premier.
La comparaison textuelle est naturelle, sans distinction de casse ; les tailles
et dates sont numériques. Le nom fournit un dernier départage déterministe.
Aucune date de création n’est déduite de `ctime` ; son suivi est hors périmètre.
Les dates s’affichent en format court en mode compact, avec année complète et
heure en mode détaillé. La taille utilise des unités de 1024 octets.

Les noms, liens et attributs sont échappés par `PageFileList`. Les deux actions
sont des liens directs vers l’URL média, sans route de prévisualisation ou de
téléchargement : `target="_blank" rel="noopener noreferrer"` pour Ouvrir,
`download="nom du fichier"` pour Télécharger. Le navigateur détermine les formats
prévisualisables et applique le comportement de téléchargement. Les SVG des
miniatures restent des éléments `img` ; aucune source SVG n’est injectée en HTML.
Les miniatures chargent paresseusement le fichier d’origine, sans génération de
fichiers redimensionnés. Les titres autour des listes restent des composants
Titre indépendants, comme les autres contenus de la page.

La validation d’un dossier configuré exige `MEDIA_READ` dans le Space exact de
la page, vérifie le dossier réel et refuse les traversées, chemins absolus,
caractères de contrôle, chemins cachés et liens symboliques.
Un composant non configuré peut être enregistré sans lire les médias.
Le rendu public suit la visibilité commune de la page et du nœud, mais les URL
médias restent publiques comme avant : masquer la liste ne protège pas les
fichiers eux-mêmes. Aucun nouveau mécanisme de fichiers privés n’est introduit.
Un dossier supprimé ou illisible après publication produit une liste absente,
sans interrompre le rendu des autres composants.

Le parcours public utilise `MediaHelper::listDirectory(..., includeTree: false,
maxEntries: 1000)` et n’énumère pas l’arborescence complète des médias. Au-delà de
1000 éléments visibles directement dans le dossier, le chargement est refusé ;
l’éditeur signale l’erreur, le rendu public omet la liste. Le gestionnaire de
médias habituel garde son arborescence et son comportement antérieurs.

Le sélecteur partagé `MediaPicker` accepte `directoryOnly` : les fichiers ne sont
pas sélectionnables et « Choisir ce dossier » valide le dossier courant, y compris
la racine. Les autres usages de sélection de fichiers gardent leur mode initial.
Le champ manuel accepte `/` comme raccourci de racine ; Effacer supprime la
configuration. Pendant la sauvegarde, le sélecteur est fermé et sa sélection
ne peut plus modifier le brouillon.

`POST /administrator/api/medias/page-files` est une lecture réservée à
`MEDIA_READ`, protégée par CSRF. Elle reçoit `spaceId`, `options`
(JSON) et `locale` et retourne les métadonnées et le HTML échappé du même renderer
que le site. Le canevas attend 300 ms après une modification des options et ignore
les réponses périmées. Les styles communs ne relancent pas le chargement.
Les liens sont inactifs dans le canevas pour conserver la sélection du composant ;
les actions se testent sur le site public. L’aperçu fidèle reprend le rendu PHP.
Les Tools IA disposent des champs du composant ; leur validation serveur conserve
les mêmes droits et restrictions sur le dossier.


## Témoignages (Testimonials)

`core.testimonials` conserve toute la liste dans `props.testimonials` : une seule
instance suffit pour saisir de 1 à 100 témoignages, sans source externe. Le
formulaire permet ajout, duplication, réorganisation et suppression avec
confirmation. Chaque entrée contient `text` (HTML localisé, 20 000 caractères
par variante), `author` (300 caractères), `role` (500 caractères), `portrait`
facultatif et `rating` (entier de 0 à 5 ou `null` pour masquer la note).
Les variantes imbriquées sont normalisées par le composant ; le HTML est purifié
à l'entrée uniquement. PHP projette la langue du rendu sans transmettre les
autres variantes au navigateur. Le texte est enveloppé dans un
`div.page-testimonials-text` neutre : le composant ne génère pas de `blockquote`,
afin de réserver le style des citations au contenu éditorial qui en utilise.

Le portrait doit être une image des médias du Space réel de la page. À l'entrée,
le composant exige `MEDIA_READ` puis vérifie le fichier, son MIME
et son appartenance au Space par `MediaHelper::assertSelectableFile()`. Au rendu,
une image supprimée ou devenue invalide est omise sans retirer le témoignage.
Le portrait est décoratif (`alt` vide), le nom restant affiché à côté.

Les modes `grid` et `carousel` partagent les cartes, avec 1 à 6 cartes par écran
(`columns_mobile`, `columns_tablet`, `columns_desktop`, défauts 1/2/3). Les seuils
768 et 992 px portent sur la largeur disponible du composant, afin de fonctionner
aussi dans une colonne étroite ou le canevas réduit. Alignement, position de
l'auteur, position et forme du portrait, hauteur uniforme et guillemets sont
réglables. `testimonials_style` personnalise couleurs, bordure, arrondis, ombre,
espacements, tailles des textes et portraits. Les couleurs utilisent le même
catalogue de template et les mêmes contrôles que les composants existants.

Le carrousel reste une liste HTML rendue par PHP, défilable nativement sans
JavaScript. `components/features/testimonials/testimonialsCarousel.js`, monté par
le bootstrap public, ajoute les flèches, les points par page et la lecture
automatique facultative. Il ne clone
aucun témoignage et recalcule les positions avec `ResizeObserver`. Le délai va
de 2 à 60 secondes (6 par défaut), avec pause au survol, au focus ou lorsque le
document est masqué, bouton de pause et respect de la réduction des animations.
Les flèches gauche/droite, Début et Fin fonctionnent lorsque la liste a le focus.
La lecture automatique est désactivée par défaut. Le canevas et l'aperçu fidèle
isolé conservent le défilement manuel ; ils n'exécutent pas cet enrichissement
public et n'activent donc ni les boutons de navigation ni la lecture automatique.

## Chronologie (Timeline)

`core.timeline` fournit la collection `zones.steps` : de 1 à 100 étapes,
initialement trois. Chaque étape utilise les corps génériques de l'éditeur,
avec libellé localisé, icône, styles et visibilité propres. Son contenu est libre :
images, titres, textes, boutons, lignes ou autres composants. Ajout, duplication,
réorganisation et suppression réutilisent les commandes communes ; une étape
reste attachée à sa timeline.

Le rendu est une liste ordonnée verticale, en alternance gauche/droite ou avec
toutes les cartes d'un même côté de l'axe (`layout`). Sous le seuil
`mobile_breakpoint` (`sm` 576 px, `md` 768 px par défaut, `lg` 992 px), les cartes
passent à droite d'un axe placé à gauche. Le seuil porte sur la largeur du
composant, comme pour les témoignages. Les repères sont des points, des numéros
automatiques ou les icônes des étapes. La numérotation et l'alternance suivent
uniquement les étapes visibles ; un corps refusé et tous ses descendants sont
retirés avant rendu.

`label_position` choisit le nom au-dessus de la carte (`above`, par défaut,
y compris pour les documents existants) ou dans la colonne opposée à la carte,
de l'autre côté du repère (`opposite`). Ce second mode centre l'axe entre les
deux colonnes, pour les trois dispositions. Sous `mobile_breakpoint`, le nom
revient toujours au-dessus de la carte pour préserver sa largeur. Le HTML du
libellé reste unique ; le canevas et le site partagent les mêmes styles.

`line_style` choisit le trait continu, les tirets ou les points ; `show_pointer`
active la pointe des cartes. `timeline_style` règle couleurs des cartes, de
l'axe, des repères et des libellés, espacements, bordure, arrondis, ombre et tailles.
`Support/PageCollectionStyle` borne les champs des deux composants : couleurs
validées, dimensions entières en pixels et ombres prédéfinies, sans CSS libre.
Le canevas applique le même contrat visuel. Les variables sont réinitialisées
sur chaque collection pour éviter d'hériter des réglages d'une timeline parente.

La limite commune de 100 corps s'applique aussi aux colonnes, onglets et panneaux,
y compris à la duplication, au collage et aux propositions IA. Les limites de
500 nœuds (corps compris), 12 niveaux et de poids du document restent actives ;
une timeline de 100 étapes très remplies peut donc atteindre une autre limite.
La grille conserve 12 unités de largeur : ce nombre ne borne pas les corps.

## Affichage global et menu contextuel du canevas

Tous les nœuds du document visuel acceptent `props.common.visible`, booléen
strict, `true` par défaut : composants Core et d’extension, lignes, colonnes,
corps d’onglets et panneaux d’accordéon. Le booléen est vérifié par `PageDocument`
à la normalisation, y compris pour le rendu, puis par `PageComponentRegistry`.
Les chaînes, entiers, tableaux, objets et valeurs `null` explicites sont refusés.
Les documents historiques sans ce champ conservent leur affichage initial.

Quand `visible` vaut `false`, `PageRenderer` omet le nœud et ses descendants
avant de les rendre. Un renderer d’extension masqué n’est pas exécuté. Le site
public et l’aperçu fidèle appliquent la même règle ; une condition de rôle,
de langue ou de taille reste indépendante. Réafficher le nœud ne contourne
pas ces conditions, ni le masquage de son parent. Si l’onglet par défaut est
masqué, le rendu public ouvre le premier onglet visible, comme pour les autres
conditions de visibilité. Tous les corps masqués retirent le conteneur du rendu.

Le canevas interactif conserve les nœuds masqués manuellement : ils sont
estompés et portent un badge « Masqué ». Il rend aussi les nœuds dont la
condition de rôle n'est pas satisfaite par l'éditeur, pour ne pas les faire
disparaître pendant l'édition ; l'aperçu fidèle et le rendu public les masquent
selon les rôles du visiteur. L’opacité des parents masqués ne se
cumule pas avec celle de leurs descendants. Les nœuds restent sélectionnables,
modifiables et déplaçables. Les filtres de langue existants gardent leur effet
sur le canevas ; l’arborescence conserve tous les nœuds et indique aussi leur
masquage manuel. « Afficher le composant », au-dessus des onglets du panneau de
propriétés, permet la même bascule pour chaque type de nœud.

Le clic droit dans le canevas ouvre un seul menu d’actions sur le nœud ciblé.
Des séparateurs discrets distinguent copie/collage/duplication, déplacement,
affichage et suppression ; ils sont ignorés par la navigation clavier :

- Dupliquer : copie le nœud avec ses descendants juste après l’original, puis
  sélectionne la copie sans changer l’onglet du panneau latéral. Les identifiants
  sont renouvelés et les références internes (`default_tab`, `open_panels`)
  adaptées. Les données, styles, langues et conditions de visibilité sont conservés.
  La commande commune `admin_pages.duplicateNode(id)` et sa règle d’activation
  `canDuplicateNode(id)` sont aussi utilisées à côté de Supprimer dans les
  propriétés avancées et les listes d’onglets et de panneaux. La duplication
  est désactivée en lecture seule, pendant la sauvegarde ou si elle dépasserait
  500 nœuds par page ou 100 corps par collection. La validation de l’arbre est
  revérifiée avant application ; aucune sauvegarde automatique n’est effectuée.
- Copier : écrit dans le presse-papiers système un JSON au format
  `tycms.page-node`, version 1, contenant `kind` et le nœud complet.
  La copie reste utilisable après rechargement et entre onglets tant que le
  presse-papiers n’est pas remplacé. Aucun cache local ne remplace silencieusement
  le presse-papiers système.
- Coller (remplacer), Coller avant et Coller après : utilisent le contenu actuel
  du presse-papiers. Les composants peuvent se remplacer ou être insérés entre
  composants ; les colonnes, onglets et panneaux restent dans une liste du même
  genre. À la racine, seules les lignes sont acceptées. Le remplacement de tout
  nœud demande confirmation, y compris une feuille ou un corps vide. Les trois modes copient les descendants,
  renouvellent les identifiants avec le helper commun de duplication et adaptent
  les références internes. Remplacer un onglet par défaut ou un panneau ouvert
  conserve ce choix du parent sur le nouveau corps. La copie collée est sélectionnée
  sans changement d’onglet du panneau latéral. Les limites de structure restent
  applicables, y compris à une insertion avant ou après.
- Supprimer : désactivé pour la dernière colonne ou le dernier corps d’un
  conteneur ; toute suppression demande confirmation, même sans enfant.
- Monter / Descendre : réordonnement dans la liste du parent ; première et
  dernière positions désactivent l’action correspondante.
- Afficher / Masquer : libellé adapté à l’état manuel actuel du nœud.

Les cibles proviennent des handlers Vue sur les nœuds du modèle, jamais d’un
attribut que le HTML du contenu pourrait fournir. Les enfants interceptent le
clic droit avant leurs conteneurs, y compris sur les libellés d’onglets et les
en-têtes d’accordéon. La sélection n’impose pas de changer l’onglet du panneau
gauche. Le menu est téléporté hors du canevas pour ne pas être rogné ou estompé,
et sa position est ramenée dans la fenêtre. Clic extérieur, défilement,
redimensionnement, glisser-déposer et Échap le ferment. Les flèches clavier,
Début et Fin naviguent dans les actions disponibles. Échap ferme uniquement
ce menu et restitue le focus.

Les actions modifient seulement le brouillon, sans écriture immédiate.
La sauvegarde de la page reste nécessaire. Le collage ajoute une validation
serveur avant application, selon le contrat ci-dessous. `setNodeVisibility()`
conserve les autres options communes ; réafficher retire le champ pour revenir
au défaut. La lecture seule et l’enregistrement bloquent les commandes ; les
mutations de déplacement et suppression revérifient l’état du store. Une
confirmation de suppression n’agit pas si une autre session d’éditeur a été
ouverte entre-temps. Le mode HTML brut n’est pas concerné.


### Presse-papiers système et validation du collage

`admin_pages.copyNode()` et `pasteNode()` centralisent les commandes ; le menu
fournit uniquement l’accès utilisateur et la confirmation. Clipboard API exige
un contexte sécurisé et peut demander l’autorisation du navigateur. Sans API,
les actions sont désactivées ; un refus de permission est expliqué par une modale.
Le JSON du presse-papiers est limité à 5 Mo UTF-8, avec format et version explicites,
contrôle des types structurels, profondeur, nombre et unicité des nœuds ; les clés
`__proto__`, `constructor` et `prototype` sont refusées. Un texte ou HTML ordinaire
ne constitue pas un élément collable. Une incompatibilité ou un dépassement des
limites est signalé sans modifier le brouillon.

`POST /administrator/api/pages/validate-draft` reçoit `data` contenant
`page_id` (ou `null` pour une création), `space_id` et le document candidat.
`WFPage::validateDraft()` exige `PAGE_CRUD`, contrôle le Space réel de la page
et son type visuel, applique la limite configurée de la page puis `PageDocument::normalize()`
puis les mêmes validations de composants et de médias que la sauvegarde.
La réponse `{data: documentNormalisé}` est privée, non mise en cache et seule
cette version nettoyée entre dans le canevas. Aucun rendu, aucune écriture en
base, aucune permission `AI_USE` n’est requise. Les références médias, rôles et
langues sont conservées telles quelles et validées dans le contexte cible ;
aucune conversion automatique entre Spaces n’est réalisée.

Le collage est bloqué en lecture seule et pendant une sauvegarde ou un autre
collage. Après chaque attente (lecture du presse-papiers, confirmation, validation
serveur), la page, la session et le contenu complet du brouillon sont revérifiés.
Une modification concurrente annule l’application. Les états d’aperçu Tabs et
Accordéon sont réinitialisés, puis la sélection ouvre les parents de la copie.

### Commande commune de suppression

`admin_pages.canRemoveNode(id)` fournit la règle d’activation commune : page
visuelle modifiable, hors sauvegarde, nœud existant et protection de la dernière
colonne ou du dernier corps. `removeNode()` réutilise cette règle avant la mutation.

`requestRemoveNode(id, confirm, {confirmEmpty: true})` centralise la préparation
utilisée par le menu contextuel, les propriétés avancées, Tabs et Accordéon :
détection des enfants, message de confirmation et contrôle après son attente.
L’interface fournit un callback `confirm(message)` retournant un booléen ou une
promesse ; le store ne dépend pas d’un composant de modale. Une confirmation ne
peut agir si la page ou la session a changé, si les droits ont disparu, si une
sauvegarde est en cours ou si le nœud est devenu le dernier corps entre-temps.

Le menu, les propriétés avancées et les panneaux Tabs et Accordéon demandent
une confirmation même sur une feuille ou un corps vide. C’est le comportement
par défaut de la commande commune. La fermeture du
menu et la sélection du parent après suppression restent propres aux interfaces.
Les déplacements restent centralisés dans `moveSibling()` et `moveNode()`.

## Composant Audio

`core.audio` propose un lecteur personnalisé à partir d’un fichier choisi dans
les médias du Space. `props.src` est une URL locale `/medias/…`, limitée à
2 048 octets. Le sélecteur filtre les MIME `audio/*`. À chaque entrée du document
(sauvegarde, collage, proposition IA et aperçu de brouillon), le composant exige
`MEDIA_READ` dans le Space réel de la page, puis vérifie le
fichier réel, son MIME et son confinement dans les médias de ce Space. Une
extension de fichier seule ne suffit pas ; les sources distantes sont refusées.
Un fichier absent ou devenu invalide après publication est omis du rendu.

Le canevas et le site public partagent `components/page/AudioPlayer.vue`,
inscrit sous `core/audio` dans les registres existants. Le lecteur réutilise le
moteur `<audio>` du navigateur sans dépendance supplémentaire ni lecture
automatique. Il expose lecture/pause, progression déplaçable, temps écoulé,
durée, sourdine et volume. Les boutons et curseurs ont des libellés accessibles
et restent utilisables au clavier. Le curseur de volume disparaît dans les
conteneurs de moins de 380 px ; la sourdine reste disponible. La durée visuelle
est masquée sous 240 px, tandis que le curseur conserve son annonce accessible.
Les erreurs de lecture affichent un message, et un changement de source ou un
démontage interrompt l’ancien lecteur.

L’onglet Styles propose `props.audio_style`, distinct des styles de l’enveloppe :

| Propriété | Contrat / défaut |
| --- | --- |
| `height` | Hauteur minimale entière en pixels, de 44 à 200 ; 56 par défaut. |
| `accent_color` | Bouton de lecture, progression et curseurs ; `#0d6efd`. |
| `track_color` | Partie restante des pistes ; `#ced4da`. |
| `color` | Durée et bouton de volume ; `#212529`. |
| `background_color` | Fond du lecteur ; `#f1f3f5`. |
| `border_radius` | `0` ou longueur positive en `px`, `rem`, `em` ou `%` ; `8px`. |

Les couleurs acceptent `#RGB`, `#RRGGBB`, `#RRGGBBAA` ou une référence
`var(--nom)` déclarée par la template. Les clés inconnues,
les valeurs CSS libres et les hauteurs hors bornes sont refusées au serveur ;
le canevas ne projette que les valeurs autorisées. Supprimer une surcharge
rétablit son défaut. Les messages de chargement ou d’erreur peuvent augmenter
la hauteur du lecteur pour rester lisibles.

Le rendu PHP fournit un lecteur natif de repli sans JavaScript. Le site public
le remplace via le registre Vue public. L’aperçu fidèle isolé conserve ce repli,
avec la hauteur minimale, le fond et les arrondis configurés : il ne charge pas
le lecteur Vue et ne reproduit donc pas les commandes personnalisées. Les
formats MP3, WAV, OGG, M4A, etc. dépendent des codecs du navigateur ; aucune
conversion n’est effectuée. Les règles de téléversement des médias continuent
de s’appliquer et les URL des fichiers restent publiques.

## Composants Vidéo et Mermaid

Deux types Core complètent le catalogue : `core.video` dans Médias et
`core.mermaid` dans Contenu. Ils utilisent les mêmes paramètres communs,
conditions de visibilité et règles de placement que les autres feuilles.

### Vidéo

`core.video` conserve les propriétés suivantes :

| Propriété | Contrat |
| --- | --- |
| `source` | `media` (défaut), `url`, `bunny` ou `hls`. |
| `src` | URL du fichier média ou URL YouTube/Vimeo, jusqu’à 2 048 octets. Vide représente un composant à compléter. |
| `library_id` | Identifiant Bunny sous forme de chaîne numérique positive, jusqu’à 15 chiffres. |
| `video_id` | Identifiant Bunny sous forme de chaîne alphanumérique avec tirets, jusqu’à 100 caractères. |

Le mode Média choisit un fichier dans la bibliothèque du Space avec
`MediaFileInput`, `media-type="video"` et saisie directe désactivée. Le sélecteur
filtre le MIME `video/*`. Le workflow exige `MEDIA_READ` sur le
Space réel de la page avant toute vérification du fichier, à la sauvegarde,
dans l’aperçu serveur et pour la validation des propositions IA. Le composant `Core/PageComponents/Video`
vérifie ensuite l’appartenance au Space, le chemin canonique, la lisibilité et
le MIME effectif via `MediaHelper::assertSelectableFile()`. Le rendu public
revérifie le fichier ; une vidéo supprimée ou illisible est omise.

Le lecteur média est un élément `video` responsive avec contrôles, lecture
inline et `preload="metadata"`, sans lecture automatique. Le canevas et le rendu
fidèle peuvent le lire. Les codecs compatibles restent ceux du navigateur.

Le mode URL accepte uniquement les URL HTTPS YouTube et Vimeo. Les formes
YouTube `watch?v=…`, `youtu.be/…`, `shorts/…` et `embed/…`, ainsi que les URL
Vimeo numériques et `player.vimeo.com/video/…`, deviennent des URL de lecteurs
canoniques. Les paramètres de requête et fragments sont retirés, notamment
`autoplay` ; les liens privés Vimeo avec hash ne sont pas pris en charge dans
ce premier périmètre. `VideoEmbedUrl` partage cette normalisation PHP avec le
sanitizer du texte riche. Le canevas applique la même liste de fournisseurs.
Aucun HTML d’iframe fourni par l’utilisateur n’est exécuté.

Le mode Bunny montre un cadre statique dans le canevas, avec les identifiants.
Le serveur produit le lecteur uniquement pour le site et le rendu fidèle,
avec `ShortCodeHelper::bunnyUrl()`, les clés et l’expiration configurées dans
Core pour le Space de la page. Les clés ne sont jamais incluses dans les props
ou dans le canevas. Les URL intégrées utilisent un cadre responsive 16:9 ;
la hauteur propre au shortcode Bunny n’est pas un paramètre de ce composant.
Sans clé pour la bibliothèque, le lien reste non signé, comme le shortcode.
Les restrictions de lecture restent celles de Bunny.

L’iframe fidèle autorise les médias de l’origine du site et les origines
supplémentaires configurées pour le Space par sa CSP
`media-src`. Les lecteurs distants restent limités aux fournisseurs déjà
admis dans `frame-src`. Les autres protections de l’aperçu sont conservées.

Le mode `hls` accepte une source
`/medias/<dossier-du-space>/<dossier-video>/<playlist>.m3u8` ou
`alias:<dossier-video>/<playlist>.m3u8`. Le formulaire permet de choisir
une playlist `.m3u8` dans un dossier média du Space ; ce sélecteur exige
`MEDIA_READ`. Une référence de bibliothèque externe peut
être saisie directement, même sans droit de consultation des médias. Le
canevas utilise le composant commun `SecureVideo` pour la prévisualisation.
À la sauvegarde et dans l’aperçu serveur, le workflow vérifie que la
playlist existe dans le Space de la page ou dans une bibliothèque
configurée. Il ne demande pas de permission de lecture vidéo. Le rendu
PHP émet le balisage `sec_video` avec les attributs `src` et `space-id`,
par `SecureVideoHelper::render()`. Le montage des balises HTML statiques
est assuré par le bootstrap Vue public ; voir
[Médias](MEDIAS.md#balise-sec_video-dans-le-html-php).

### Mermaid

`core.mermaid` contient `diagram`, une source Mermaid commune de 30 000 octets
maximum, éditée avec CodeMirror en mode `mermaid`, et `options`, un objet de
surcharges. Un lien à droite du libellé « Code Mermaid » ouvre `https://mermaid.live/` dans un nouvel
onglet, sans transmettre la source du diagramme. La source n’utilise pas de marqueurs `[[DIAGRAM]]` ; elle est
échappée sans normalisation HTML, pour conserver les balises de libellés du
langage Mermaid comme texte destiné à son moteur sécurisé.

Les six paramètres `width`, `align`, `look`, `theme`, `sketch` et `wrapwidth`
reprennent les valeurs et limites de la [configuration Core](SHORTCODES.md#diagrammes-mermaid).
Chaque champ propose « Défaut du Space » ; son absence dans `options` exprime
l’héritage. Une surcharge n’est pas écrasée par une modification globale.
Vider un champ ou choisir le défaut retire sa propriété ; le bouton de
réinitialisation remet `options` à `{}`. Aucune copie des valeurs héritées
n’est enregistrée dans la page.

Le serveur résout les valeurs héritées à chaque rendu à partir du Space réel
de la page. Le répertoire de l’éditeur fournit les seuls défauts Mermaid
publics de chaque Space ; aucun secret Bunny n’y est ajouté. Le canevas combine
ces défauts avec les surcharges et appelle le composant commun `Mermaid.vue`.
Après modification de la configuration Core dans un autre écran, recharger
l’éditeur pour actualiser ses défauts affichés.

Le composant `Core/PageComponents/Mermaid` valide la source et une liste fermée de paramètres. Il utilise
`DiagramShortCode::renderSource()` pour produire les mêmes conteneurs sûrs que
les shortcodes, sans interpréter les shortcodes éventuellement présents dans
le code du diagramme. Le site et l’iframe utilisent ainsi le moteur commun
existant. La configuration de présentation propre à la source Mermaid garde
la priorité décrite dans `Docs/SHORTCODES.md` ; les limites et options de
sécurité du moteur restent imposées par l’application.

Les Tools IA connaissent les sources Vidéo et le code Mermaid. Les surcharges
de style Mermaid se règlent dans le formulaire du composant. Les shortcodes
présents dans Texte riche ou HTML personnalisé restent littéraux dans le canevas.

## Iframe

`core.iframe` figure dans Médias. L’onglet Général propose une URL d’intégration
HTTPS (4096 octets maximum, sans identifiants), un titre accessible localisé
(200 caractères), une hauteur entière entre 80 et 4000 pixels (480 par défaut)
et une option plein écran activée par défaut. La largeur vaut 100 %.
Une URL vide n’affiche rien ; les paramètres de requête de l’URL sont conservés.
Pour Google Slides, utiliser l’URL d’intégration publiée fournie par Google,
sans coller le HTML `<iframe>` complet.

Le serveur valide l’URL et les options à l’entrée et au rendu, échappe les
attributs et ne télécharge jamais le contenu distant. Le HTML généré fixe
`loading="lazy"`, `referrerpolicy="no-referrer"` et
`sandbox="allow-scripts allow-forms allow-presentation"`. Aucun paramètre
éditorial ne permet d’ajouter des permissions. L’absence de `allow-same-origin`
isole également une URL du site ; les popups et la navigation du parent sont
interdites. Scripts et formulaires peuvent fonctionner à l’intérieur du cadre
public, sous réserve des restrictions du navigateur et du service distant.

Le canevas Vue affiche uniquement un emplacement avec l’URL, sans requête au
service tiers. L’aperçu fidèle charge l’intégration en conservant son isolation
externe existante : formulaires et certaines fonctions peuvent donc y être
plus restreints que sur le site public. Aucun redimensionnement automatique
selon la hauteur du contenu distant n’est prévu.

Les ROOT configurent les domaines d’iframe par Space dans le
[plugin Sécurité](PLUGIN_SECURITE.md) : tous HTTPS (défaut conservé), origine du site
et liste précise, ou origine du site uniquement. Le champ URL affiche les
origines actuellement autorisées. Le serveur refuse une URL interdite à
l’entrée du brouillon et l’omet au rendu des pages existantes ; la CSP du site
et des aperçus applique également ces restrictions. Une modification de cette
configuration nécessite de rouvrir l’éditeur et de recharger l’administration.
Un service peut refuser l’intégration par ses propres en-têtes ou nécessiter
des permissions non accordées ; son fonctionnement doit être vérifié sur le
site public. L’ouverture HTTPS permet aussi des contenus tiers non fiables :
l’isolation ne garantit pas la fiabilité de leur contenu.

## Diagrammes diagrams.net

`core.diagram` conserve un SVG rééditable dans `props.svg`, comme texte UTF-8,
et un texte alternatif localisé dans `props.alt`. Aucun fichier média ni
source XML parallèle n’est créé. L’export `xmlsvg` contient la source complète
dans l’attribut `content` du SVG ; son poids, source comprise, est borné par
`page_diagram_max_size`. Les échappements JSON comptent aussi dans le poids
global de la page.

Le formulaire ouvre l’éditeur commun `components/common/DiagramEditor.vue`,
aussi utilisé par les Médias. Pour réouvrir un dessin de page, transmettre le
SVG brut à `load.xml`, sans conversion en URL `data:`. Le canevas et le rendu
public utilisent une balise `img` avec une URL `data:image/svg+xml` ; le SVG
n’est jamais injecté avec `v-html`, ni servi comme document actif du site.

« Enregistrer » dans diagrams.net valide le document complet via
`/administrator/api/pages/validate-draft` puis applique uniquement la réponse
serveur au brouillon. La page doit ensuite être enregistrée dans l’atelier.
Les erreurs locales de décodage ou de poids utilisent `showModaleError()` du
mixin global ; les refus API suivent les modales du client commun.
Un refus, un changement de page, de session ou de brouillon pendant la requête
empêche cette application. Le composant ne nécessite pas de permission Média :
les droits `PAGE_CRUD`, le Space réel et le CSRF suivent le parcours de page
existant.

`Support/DrawioSvg` refuse PNG/JPEG et ressources externes, y compris dans la
source, ses pages masquées, les formes personnalisées et les images SVG
imbriquées. Les autres images embarquées acceptées sont SVG, GIF, WebP et AVIF ;
un PNG/JPEG déclaré avec un autre MIME reste refusé par sa signature. DTD,
entités, instructions XML, scripts, événements, éléments actifs, styles avec
chargement externe et CSS échappé sont refusés. L’analyse XML ne résout aucune
ressource externe ; la somme du XML analysé, données décompressées comprises,
reste bornée à vingt fois le poids du SVG et à 5 Mo. Les polices distantes et certains exports avancés hors du sous-ensemble
SVG autorisé sont donc refusés plutôt que chargés automatiquement.

Le collage PNG/JPEG peut rester possible dans l’iframe externe ; c’est la
validation côté serveur qui interdit de conserver ce contenu dans une page.
Les restrictions propres à ce composant ne changent pas l’édition des Médias.
