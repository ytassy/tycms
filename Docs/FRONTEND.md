# Frontend et conventions d'interface

Ce guide rassemble les mécanismes à réutiliser pour garder des écrans cohérents.
Sources principales : [mixin global](../src/ria/src/application/mixin.js),
[store app](../src/ria/src/stores/app.js),
[registre des composants](../src/ria/src/application/registerGlobalComponents.js)
et [bootstrap et raccourcis](../src/ria/src/main.js).

## Répartition du code

| Emplacement dans `src/ria/src` | Rôle |
| --- | --- |
| `pages/` | Racines des vues RIA sélectionnées par le bootstrap. |
| `components/administrator/<domaine>/` | Écrans et composants d'administration métier. |
| `components/common/` | Contrôles et utilitaires partagés entre fonctionnalités, sans dépendance à un écran métier. |
| `components/common/forms/` | Renderer et adaptateurs des champs déclaratifs PHP. |
| `components/page/` | Points d'entrée Vue des composants de page publics, enregistrés par le bootstrap. |
| `components/features/<fonctionnalité>/` | Composants internes, comportements et utilitaires propres à une fonctionnalité. |
| `stores/` | État métier partagé et appels API par domaine. |
| `application/` | Contexte, mixin, API, ACL, bootstrap et registres communs. |
| `scss/` | Styles communs et styles des composants de page. |

`components/page/` conserve `MyAccount.vue`, `Authentication.vue`,
`SpaceSwitch.vue` et `AudioPlayer.vue`. Ces points d'entrée peuvent assembler
des composants de `features/` : les vues et modales du compte sont regroupées
dans `features/account/`, la logique audio dans `features/audio/`, les sources
vidéo dans `features/video/`, l'agrandissement des images dans
`features/imageLightbox/` et le carrousel dans `features/testimonials/`.
Les comportements sur le HTML PHP peuvent aussi être activés directement par
`main.js`, sans créer de composant Vue intermédiaire.

Les validations d'URL d'images, les couleurs et les styles de collections
partagés par plusieurs fonctionnalités et le canevas restent dans `common/`.
Le registre de montage public est dans `application/publicPageRegistry.js`.
Cette séparation garde les points d'entrée identifiables et évite de faire
dépendre les contrôles communs des composants de page. `pages/` reste réservé
aux racines RIA sélectionnées par le bootstrap ; il est distinct de
`components/page/`.

Les composants affichent les données, recueillent la saisie et déclenchent les
actions. Les appels API et l'état partagé vivent dans un store Pinia. Garder
localement l'état purement visuel : ouverture d'une modale, filtre temporaire,
chargement d'un panneau. Conserver une sélection par ID et recalculer l'objet
depuis les données du store après rechargement.

## Frontends des extensions

Les bibliothèques ESM des extensions sont chargées avant les montages Vue,
avec le runtime partagé et les points d’enregistrement de `@tycms/api`.
Les pages RIA enregistrées par une extension reçoivent les mêmes mixins,
directives, composants et factories de stores que les pages Core.
Voir le [contrat des extensions](EXTENSIONS.md) pour les builds, l’Import Map,
les stores et le développement Docker. Les instances de contexte et de Pinia
restent distinctes entre racines.

## URL des assets Core

La base `import.meta.env.BASE_URL` vient de Vite et correspond à
`/assets_core_v<APP_VERSION_STRING>/plg_core/`. Le lecteur de version et son
format sont décrits dans [l'architecture](ARCHITECTURE.md#organisation-du-projet).
Utiliser `application/coreAssets.js` pour obtenir la base validée, notamment
pour les imports Mermaid et les URL/CSP des aperçus. Une base absente ou
invalide provoque une erreur explicite ; les tests Node doivent fournir une
base versionnée dans les options des fonctions pures.

Les URL de polices SCSS utilisent `$core-assets-base`, injectée par Vite.
Ne pas réintroduire de chemin `assets_core_vdev` dans le code partagé entre
le développement et les versions livrées.

## Accès fournis par le mixin global

Les composants Options API reçoivent ces propriétés calculées ; aucun import
du mixin n'est nécessaire dans chaque composant.

| Propriété | Usage |
| --- | --- |
| `stores` | Factories déjà enregistrées, par exemple `this.stores.useAdminMenusStore()`. |
| `app` | Contexte applicatif complet. Dans un composant Vue, ce n'est pas le store `app`. |
| `api` | Client HTTP commun. Les appels métier restent normalement dans les stores. |
| `acl` | `hasGlobalPermission('MENU_CRUD', spaceId)`, `isRoot()` ou `hasGlobalRole(roleId, spaceId)` pour une identité de rôle. |
| `conf` | Lecture de la configuration de bootstrap, notamment le Space, le CSRF et `getTemplateColors()` pour les couleurs déclarées par la template courante. |
| `tools` | Utilitaires communs décrits plus bas. |
| `pubsub` | Bus d'événements du contexte applicatif. |

Éviter de redéclarer ces noms dans `data`, `computed` ou les props d'un composant :
une collision avec le mixin peut remplacer l'accès attendu. Pour une donnée
métier, préférer un nom explicite comme `chatTools`.

L'ACL Vue aide à présenter l'interface ; elle ne remplace aucun contrôle PHP.
Passer le Space de la ressource quand il diffère du Space courant. Un nom de
permission sans `__` reçoit le préfixe `plg_core__` ; utiliser le nom qualifié
pour les permissions d'une autre extension. Les rôles et permissions hérités
des Spaces parents sont pris en compte. Utiliser `acl.isRoot()` pour le super
administrateur de l’installation : seule la permission ROOT du Space 1 compte,
quel que soit le rôle qui la porte. Ce contrôle ne donne pas implicitement
les autres permissions.

En Composition API, utiliser `useApplication()` depuis
[`application/context.js`](../src/ria/src/application/context.js) pour
retrouver le même contexte injecté. Chaque racine `[data-vue-app]` crée son
contexte et ses stores ; le bus et l'état Pinia ne sont pas partagés implicitement
entre deux racines distinctes. Les racines publiques de composants de page
(`data-page-vue`) disposent elles aussi de leur propre contexte, de leurs stores,
du mixin, des directives et des composants globaux communs ; leurs appels
utilisent le client API et son CSRF. Chaque racine Vue enregistre ses propres
directives avant son montage ; elles ne sont pas héritées d’une autre racine. `conf` expose `getSpace()`, `getUser()`,
`getLanguage()`, `getTimezones()`, `getAcl()`, `getExtensions()` et `getCsrfToken()`.
`getExtensions()` liste les identifiants des extensions chargées, par exemple
`plg_automation`, pour masquer une entrée d’administration dont l’extension est
désactivée. Le droit ACL reste la seconde condition.

## Raccourcis : classes ctrl-s et esc

```vue
<button type="button" class="btn btn-primary ctrl-s" @click="save">
    Enregistrer
</button>
<button type="button" class="btn btn-outline-primary" @click="saveAndClose">
    Enregistrer et fermer
</button>
<button type="button" class="btn btn-outline-secondary esc" @click="requestClose">
    Fermer
</button>
```

`Ctrl+S` déclenche le clic sur `.ctrl-s` et empêche l'enregistrement de la page
par le navigateur. Cette classe appartient au bouton principal **Enregistrer**,
qui garde l'éditeur ouvert. Ne pas la mettre aussi sur « Enregistrer et fermer ».
Le handler global prend le premier élément correspondant dans le document :
veiller à n'avoir qu'un bouton cible actif et à ne pas laisser un éditeur caché
intercepter le raccourci. TinyMCE transmet lui aussi Ctrl+S au bouton du document.
Le raccourci actuel utilise Ctrl ; aucun équivalent Cmd n'est déclaré.

`Échap` déclenche un bouton visible et actif `.esc` dans la fenêtre au premier
plan, déterminée par son z-index. Donner cette classe au bouton Fermer ou
Annuler. La fermeture doit passer par le même handler que le clic, avec
confirmation si le brouillon a changé. Une fenêtre bloquante sans cible `.esc`
empêche d'agir sur celle située derrière. La croix de `modal` possède déjà la
classe ; `b-dropdown` la met sur son bouton lorsque son menu est ouvert. Un
popover ouvert suspend le traitement global d'Échap.

## Attente, succès et messages

Les méthodes ci-dessous sont exposées directement par le mixin global.

| Méthode | Usage |
| --- | --- |
| `showModaleWait()` / `hideModaleWait()` | Voile commun bloquant les interactions pendant une opération. |
| `showModaleSuccess(text = null)` | Confirmation de réussite, masquée automatiquement après une seconde. |
| `showModaleInfo(text)` | Message d'information. |
| `showModaleConfirm(text)` | Message de confirmation visuelle ; n'attend pas une réponse Oui/Non. |
| `showModaleWarning(text)` / `showModaleDanger(text)` / `showModaleError(text)` | Message adapté à la gravité. |
| `showModaleException(text, exception)` | Présentation d'une exception, sans y ajouter de secret. |
| `hideModaleMessage()` / `hideModaleSuccess()` | Fermeture explicite du message correspondant. |

Patron pour une méthode de composant qui délègue à son store métier :

```js
async save() {
    this.showModaleWait()
    try {
        await this.store.save()
    } finally {
        // Fermer l'attente même si l'API échoue.
        this.hideModaleWait()
    }

    // Une erreur remonte avant cette ligne : aucun faux succès.
    this.showModaleSuccess()
}
```

Utiliser le voile commun pendant l'enregistrement ; ne pas désactiver chaque
champ pour cette attente. La lecture seule liée aux droits reste indépendante.
Le client API affiche déjà les messages d'erreur structurés du serveur : éviter
de les afficher une seconde fois dans chaque appelant. Traiter séparément les
erreurs locales ou réseau qui demandent une information supplémentaire.

`setNextApiUi(textConfirm = null, useSuccessModale = true)` permet aussi au
client API de gérer attente et succès pour un appel. Son état est partagé dans
le store `app` jusqu'à sa remise à zéro : préférer le patron explicite ci-dessus
pour une séquence de plusieurs requêtes ou des appels concurrents. Ne pas
superposer les deux mécanismes pour la même action.

## Questions et saisie courte

```js
async requestClose() {
    if (this.dirty && !await this.askYesNoAsync('Abandonner les modifications ?')) return
    this.close()
}

async rename() {
    const name = await this.askInput('Renommer', 'Nom', this.currentName)
    if (name === null) return // Annulation ; une chaîne vide reste une saisie.
    await this.store.rename(name)
}
```

`askYesNoAsync(text)` renvoie une promesse de booléen. Attendre cette promesse
avant toute mutation ou navigation, y compris dans le parent qui appelle la
méthode de confirmation. Les variantes à callbacks `askYesNo(text, callback)`
et `askYes(text, callback)` restent disponibles ; la seconde appelle le callback
seulement pour Oui. `askInput(title, label, value = '')` renvoie la valeur ou
`null` à l'annulation. La validation métier reste au serveur.

Pour un choix personnalisé, `showModaleQuestion(text, options, callback)`
alimente la même modale : `options` associe chaque valeur de réponse à son
libellé, et le callback reçoit la valeur choisie. Voir
[`modal_question.vue`](../src/ria/src/components/common/modales/modal_question.vue).
Utiliser ces dialogues communs plutôt que `window.confirm`.

Après une attente, revérifier la cible si l'utilisateur a pu changer de page,
de sélection ou de brouillon. Une confirmation ancienne ne doit pas s'appliquer
à une autre ressource. Les commandes communes de l'éditeur de pages appliquent
déjà cette règle ; les réutiliser.

## Stores et client API

Les stores suivent une factory `{ get(vueApp, application) }` enregistrée dans
[`application/stores.js`](../src/ria/src/application/stores.js). Exemple
réel : [`admin_menus.js`](../src/ria/src/stores/admin_menus.js).

```js
export const useExampleStore = {
    get(vueApp, application) {
        return defineStore('example', {
            state: () => ({ items: [], selectedId: null }),
            getters: {
                app: () => application.stores.useAppStore(),
            },
            actions: {
                async load() {
                    const result = await application.api.get('/chemin/api')
                    this.items = result.items ?? []
                },
            },
        })
    },
}
```

Importer `defineStore` depuis `pinia` et ajouter la factory au registre. Ne pas
mettre une instance de store dans `state`. Le getter `app` d'un store métier
désigne le **store global** : `this.app.showModaleWait()` et
`await this.app.askYesNo('…')`. Dans un composant, utiliser le mixin
`askYesNoAsync()` et les autres méthodes directement.

Les actions capturent `application.api` ; elles ne reçoivent pas `api` en
argument. Après une écriture, recharger ou mettre à jour les données du store
pour refléter le résultat serveur, puis retrouver la sélection par ID.

Le [client API](../src/ria/src/application/api.js) expose :

| Appel | Résultat / particularité |
| --- | --- |
| `get(url, params)` | Paramètres de requête, réponse JSON directement. |
| `post(url, params, false, options)` | Envoi des données ; `options` est facultatif. |
| `get(url, params, true)` | Téléchargement : `{ data, fileName, contentType }`, avec `data` de type Blob. |

Le client sérialise les objets imbriqués des paramètres en JSON et modifie
l'objet de paramètres fourni. Construire un payload neuf, par exemple
`{ menuId, data: draft }`, au lieu de lui passer directement l'état réactif à
conserver. Un `FormData` est transmis tel quel. Le CSRF est ajouté aux POST de
même origine via `X-CSRF-Token` ; ne pas recréer un client axios métier qui
perdrait ce mécanisme et les messages communs. Les formats attendus par PHP
restent ceux déclarés dans les paramètres de la route.

## Composants globaux à réutiliser

Les noms ci-dessous viennent de `registerGlobalComponents()`. Les utiliser
directement dans les templates, sans import ni déclaration locale.

| Besoin | Nom enregistré | Repère |
| --- | --- | --- |
| Fenêtre d'édition | `modal` | Slots `header`, `body`, `footer` ; props `visible`, `width`, `closable`, `fullScreen`, `maxHeight` ; événement `close`. |
| Onglets d'interface | `tabs`, `tab` | `tabs :default-index="0"`, puis `tab label="…" icon="…"`. Le contenu reste monté avec `v-show`. |
| Formulaire défini en PHP | `form_renderer` | `v-model`, `definition`, `errors`, `context` ; [contrat](FORMS.md#rendu-vue). |
| Texte riche | `rich_text_editor` | TinyMCE auto-hébergé en mode GPL ; médias et IA selon le Space et les droits. |
| Source HTML / CSS / JSON / Mermaid | `code_editor` | CodeMirror, hauteur naturelle, lecture seule et vraies tabulations ; [contrat](FORMS.md#champ-de-code). |
| Couleur et alpha | `color_input` | Contrôle partagé également par les formulaires ; [formats](FORMS.md#champ-couleur). |
| Booléen | `input_switch` | `v-model` booléen, `disabled`, événement `change`. |
| Icône | `icon_picker` | `v-model` des classes Font Awesome. |
| Sélection multiple par étiquettes | `checkboxes_dropdown` | Options `{ clé: libellé }`, `readOnly` ; les valeurs émises sont des chaînes. Reconvertir les IDs si le serveur exige des entiers. |
| Recherche puis ajout d'une option | `dropdown_list` | Événement `select` ; réutilisé par `core.select` avec `ui.picker`. |
| Menu / aide contextuelle | `b-dropdown`, `b-popover` | Composants internes ; ne pas supposer l'API d'une bibliothèque Bootstrap Vue externe. |
| Tableau en mémoire | `data-table` | Tri, filtres, sélection, pagination et export CSV optionnels. |
| Pagination pilotée par l'écran | `pagination` | Utile pour les listes paginées par l'API, comme les utilisateurs. |
| Suppression avec confirmation locale | `delete_btn` | Popover de confirmation puis événement `click`. Le parent réalise l'écriture. |
| Dépôt de fichiers | `file_dnd` | Contrôles de taille / extension, événement `files` ; la validation PHP reste nécessaire. |
| Copier du texte | `text_copy` | Événements `copied` et `copy-failed`, selon les possibilités du navigateur. |
| État / chargement | `saving_status`, `item_status`, `spinner` | Éviter de refaire ces indicateurs dans chaque écran. |
| Chat et lecteurs | `ai_chat`, `mediaplayer`, `sec_video` | Contrats dans [IA](AI.md) et [Médias](MEDIAS.md). |

Les autres noms, notamment `common_app`, `calendar`, `json_editor`, `CeItem`
et `CeHelperItem`, sont visibles dans le registre. Lire les props du composant
avant usage : certains contrôles ont des conventions distinctes de `disabled`.
`delete_btn` conserve son état de suppression après confirmation ; avec son
spinner par défaut, prévoir son retrait/recréation après succès ou vérifier
le comportement à rétablir en cas d'échec.

Exemple de fenêtre :

```vue
<modal :visible="editorOpen" width="800px" closable @close="requestClose">
    <template #header>Modifier</template>
    <template #body><!-- Champs du brouillon --></template>
    <template #footer>
        <button type="button" class="btn btn-primary ctrl-s" @click="save">Enregistrer</button>
        <button type="button" class="btn btn-outline-secondary esc" @click="requestClose">Fermer</button>
    </template>
</modal>
```

Le parent conserve `editorOpen` et décide de la fermeture : `modal` émet
`close`, sans modifier sa prop. `closeOnBg` est désactivé par défaut ; s'il est
activé, raccorder sa fermeture au même contrôle des modifications.

Pour `data-table`, définir les colonnes avec `key`, `label`, `sortable`, et
éventuellement `filter: 'search' | 'select' | 'custom'`. Les slots de cellule
et d'en-tête sont à vérifier dans le composant avant personnalisation. La
pagination et les filtres internes travaillent sur les éléments déjà chargés ;
ils ne remplacent pas une pagination serveur. Les libellés de colonne sont
rendus en HTML : ils doivent provenir du code de confiance.

Pour déplier un détail sous une ligne, combiner `clickable-row`,
`on-click-open-row` et le slot `nested_row` ; la prop `openedRows` permet
d'ouvrir une ligne depuis un bouton et l'événement `update:row-opened` signale
la ligne ouverte. Le contenu du détail reste à charger par l'écran, qui doit
ignorer une réponse si la ligne ouverte a changé entre-temps.

### Couleurs du texte enrichi

Toutes les instances de `rich_text_editor` utilisent la palette native TinyMCE
pour le texte et le surlignage. Les variables de
`conf.getTemplateColors()` sont proposées en premier via `color_map_raw`, suivies
des 22 teintes fixes natives de TinyMCE 8. Sans catalogue, la palette par défaut
reste utilisée. Aucun paramètre propre à l’éditeur de pages n’est nécessaire.

Le HTML conserve `var(--nom)` ; les variables suivent donc la template au rendu.
Pour l’iframe d’édition, les couleurs du thème du Space courant sont résolues à
l’initialisation en hexadécimaux, puis injectées comme définitions CSS locales.
Cette capture sert uniquement à l’aperçu et ne modifie pas le contenu enregistré.
Après un changement de thème, remonter l’éditeur pour actualiser cette capture.
Le document qui affiche le HTML doit fournir les définitions CSS correspondantes,
y compris hors du site ; une variable absente suit les règles CSS d’héritage.

La purification HTML commune conserve uniquement la forme exacte `var(--nom)`
sur les propriétés `color` et `background-color` des `span`. Le nom respecte
`--[a-zA-Z_][a-zA-Z0-9_-]{0,63}` et sa casse est conservée. Replis, commentaires,
URL, expressions et définitions de variables restent interdits. Cette validation
est syntaxique, sans accès à un Space ni au catalogue ; les styles structurés
des composants de page gardent leur validation supplémentaire par catalogue.

## Contrôles importés localement

Ces composants ne sont pas globaux ; les importer et les déclarer dans le
composant appelant, avec un chemin adapté à son emplacement.

| Composant / utilitaire | Usage |
| --- | --- |
| `common/MediaFileInput.vue` | Choix d'un fichier dans le Space explicite ; [options](FORMS.md#définir-un-formulaire). |
| `common/MediaPicker.vue` | Navigation et sélection ; `directoryOnly` pour choisir un dossier. |
| `common/LocalizedTextField.vue` | Chaîne / variantes par langue / clé ; [contrat](LOCALIZED_TEXT_FIELD.md). |
| `common/localizedText.js` | `selectLocalizedText(value, locale)` pour lire une variante sans modifier la valeur brute. |
| `common/Mermaid.vue` | Moteur partagé de diagrammes ; [contrat](SHORTCODES.md). |

Les formulaires et canevas de composants de page passent par leurs registres
explicites. Les mixins `pageComponentFormMixin.js` et
`pageComponentCanvasMixin.js` regroupent les besoins des composants Core ; ils
dépendent du store `admin_pages` et ne sont pas des mixins génériques pour
toutes les extensions. Voir [Composants de page](PAGE_COMPONENTS.md).

## Utilitaires du mixin et de tools

| Accès | Rôle / limite |
| --- | --- |
| `$ucFirst(text)` | Première lettre en majuscule ; `null` / vide donnent `''`. |
| `$date(date, format)` | Format Moment ; défaut `dddd DD/MM/YYYY à HH:mm`, `null` donne `''`. |
| `utcToLocal(value, options)` | Convertit une date UTC en heure locale du navigateur avec `Intl.DateTimeFormat` ; options de format surchargeables. |
| `tools.uuid()` | Identifiant aléatoire via `crypto.randomUUID()`. |
| `tools.escapeHtml(value)` | Échappe un texte destiné au HTML ; ne valide pas une URL. |
| `tools.html2text(html)` | Extrait le texte ; ne constitue pas un purificateur HTML. |
| `tools.getUrlParameters(name, url = null, decode = false)` | Lit un paramètre, renvoie `false` s'il est absent. `URLSearchParams` décode déjà la valeur ; éviter un second décodage involontaire. |
| `tools.setLocalStorageItem()` / `getLocalStorageItem()` | Préférences locales ; valeurs de type chaîne, sérialisation JSON à faire explicitement si nécessaire. |
| `tools.getLocalStorageItemBoolean(name, defaultValue)` | Seule la chaîne `'true'` donne `true` lorsqu'une valeur existe. |
| `tools.downloadFileFromContent(data, fileName, contentType)` | Crée puis libère une URL Blob de téléchargement. |
| `tools.quickPrintHtml(title, html, style)` | Impression de HTML fourni par le code ; n'assainit pas ses arguments. |

Ces helpers de date suivent la langue et le fuseau du navigateur. Pour un
contenu qui doit suivre la langue publique du Space, utiliser explicitement le
contexte documenté dans [Langues](LANGUAGES.md), sans supposer les deux identiques.
Ne stocker aucun secret dans localStorage.

Le rendu Markdown commun est fourni par
[`application/markdown.js`](../src/ria/src/application/markdown.js) :
`renderMarkdown(source)` utilise `marked` puis `DOMPurify`, sans HTML brut ni
images. Il est partagé par le chat IA et l’aperçu des fiches de catalogue.

`pubsub.on(event, callback)` renvoie une fonction de désabonnement : la conserver
et l'appeler dans `beforeUnmount` / `onBeforeUnmount`. `emit`, `off` et `once`
complètent le bus. L'état métier durable reste dans un store ; le bus sert aux
notifications entre composants du même contexte applicatif.

## Directives communes

Source : [`application/directives.js`](../src/ria/src/application/directives.js).
Elles sont enregistrées sur chaque application Vue.

| Directive | Comportement |
| --- | --- |
| `v-text-ml="text"` | Échappe le texte puis transforme les sauts de ligne en `<br>` ; utiliser `text ?? ''` pour effacer aussi une ancienne valeur devenue nulle. |
| `v-focus` | Donne le focus au montage ; aucun suivi ultérieur de la valeur. |
| `v-visibility="condition"`, `v-visible="condition"` | Bascule `visibility` en conservant la place dans la mise en page. |
| `v-pointer-event-none="condition"` | Retire les interactions au pointeur si vrai ; ne bloque pas toutes les interactions clavier. |
| `v-opacity-50="condition"` | Opacité à 50 % si vrai ; aucun contrôle d'accès. |
| `v-prevent-double` | Suspend les clics au pointeur pendant une seconde. |
| `v-single-click` | Coupe les clics au pointeur après le premier, sans réactivation automatique. |
| `v-single-click-icon` | Même principe, masque l'icône et ajoute un spinner ; prévu pour un élément ensuite retiré ou une navigation. |
| `v-propagate-to-input` | Le clic sur le conteneur actionne son premier lien, bouton ou input ; arrête la propagation. La valeur `false` au montage désactive ce raccordement. |
| `v-acl-perm` | Masque selon une permission ; accepte `'MENU_CRUD'` ou `{ perm: 'MENU_CRUD', spaceId }`. |
| `v-acl-role` | Masque selon un rôle ; accepte son ID ou `{ role: roleId, spaceId }`. |

Ces directives n'assurent ni idempotence, ni autorisation serveur. Les variantes
qui ne se réactivent pas peuvent bloquer une nouvelle tentative après erreur :
préférer le voile d'attente pour un enregistrement réutilisable. `v-acl-perm`
et `v-acl-role` masquent le DOM ; ne pas leur confier la protection de données
qui n'auraient pas dû être envoyées au navigateur.

## Styles et mixins SCSS

Réutiliser les classes Bootstrap et les utilitaires existants dans
[`styles/utils.scss`](../src/ria/src/scss/styles/utils.scss) et
[`styles/tools.scss`](../src/ria/src/scss/styles/tools.scss) avant de
créer une nouvelle classe générale. Garder les styles propres à un composant
près de celui-ci ; les styles publics de page ont leurs feuilles communes.

[`base/_mixins.scss`](../src/ria/src/scss/base/_mixins.scss) fournit
`flex($direction, $justify, $align, $gap)`, `truncate` et `respond-to($bp)`.
Les seuils de `respond-to` viennent de `base/_variables.scss` : `sm=480px`,
`md=768px`, `lg=1024px`. Ils diffèrent des breakpoints Bootstrap ; pour les
formats de l'éditeur, réutiliser `pagePreviewViewports` et la grille existante.
Charger explicitement les mixins avec `@use` dans la feuille qui les emploie,
sans supposer leur injection automatique dans les styles de chaque composant.

### Polices par défaut

Les styles Bootstrap communs utilisent Arial pour le texte et Georgia pour les
titres, avec leurs piles de repli système. Aucune police TTF n'est embarquée par
le thème. Les choix de Main réutilisent la [bibliothèque partagée](FONTS.md) :
les cinq polices système et les familles installées explicitement.

### Menu et pied de page de la template Main

Les champs « Menu principal » et « Page de pied de page » sont facultatifs et
valent `null` par défaut. Le sélecteur permet de revenir à cette valeur ; les
catalogues valident les identifiants proposés et acceptent `null` pour un champ
facultatif. Le menu principal et la page de pied de page doivent appartenir au
Space configuré ou à un de ses parents ; la page doit aussi être publiée. Les
catalogues ne listent que les éléments accessibles avec `MENU_READ` ou
`PAGE_READ`, et l'enregistrement refuse un menu ou une page hors de ce
périmètre. Sans menu, le header affiche une
invitation à le configurer ; sans page de pied de page, le footer n'est pas
rendu. Les valeurs déjà enregistrées restent utilisées. Sur une installation
neuve, la migration initiale fournit explicitement au Space racine la
configuration Main, avec le menu 1 et la page 3 ; les autres configurations
utilisent les défauts du formulaire.

### Couleurs de la template Main

L'onglet « Thème : couleurs » regroupe ses réglages sous six repères visuels :
Palette du thème, Page et composants, Header, Menu, Bandeau du titre et Footer.
Les repères utilisent les notes du formulaire (`core.note`), sans valeur
soumise ni stockée. Les couleurs du menu sont ordonnées par état : fond et
texte au survol, puis fond et texte actifs.

Dans « Thème : couleurs », les fonds de page et de composants sont indépendants :

- `theme_body_bg` (« Fond de page (body) ») définit `--theme-page-bg`.
  Seul le sélecteur `body` l'utilise par défaut.
- `theme_components_bg` (« Fond des composants ») définit `--bs-body-bg`
  et `--bs-body-bg-rgb`, utilisés par les champs, cartes, modales et autres
  composants Bootstrap ou de page qui référencent cette couleur de base.
  Les fonds des surfaces claires et secondaires restent des réglages distincts.

Sans `theme_components_bg`, le rendu reprend `theme_body_bg` (blanc par défaut).
Le hook `MainTemplate\Template::prepareConfigForEditor()` initialise aussi le
nouveau champ depuis l'ancienne couleur avant l'application des défauts du
formulaire : ouvrir puis enregistrer une configuration existante conserve son
apparence. Une fois les deux couleurs enregistrées, elles évoluent séparément.
Les valeurs restent validées en hexadécimal opaque par le formulaire et par
`CssHelper` avant leur insertion dans le CSS.

Les palettes de template proposent les deux variables. Les références déjà
enregistrées à `var(--bs-body-bg)` suivent le fond des composants ; utiliser
`var(--theme-page-bg)` pour suivre désormais le fond de page. Un composant
transparent laisse naturellement apparaître le fond placé derrière lui.
Le SCSS personnalisé est chargé après le thème et peut surcharger ces règles.

Le header, le bandeau du titre de page et le footer disposent chacun d'un fond
et d'une couleur de texte, toujours dans « Thème : couleurs » :

| Zone | Paramètres | Variables CSS | Cible de la template |
| --- | --- | --- | --- |
| Logo et navigation | `theme_header_bg`, `theme_header_color` | `--theme-header-bg`, `--theme-header-color` | `.main-template-header` et sa `.navbar` |
| Titre de page | `theme_subheader_bg`, `theme_subheader_color` | `--theme-subheader-bg`, `--theme-subheader-color` | `.main-template-subheader` |
| Pied de page | `theme_footer_bg`, `theme_footer_color` | `--theme-footer-bg`, `--theme-footer-color` | `.main-template-footer` |

Sans réglage dédié, les trois fonds reprennent `theme_surface_bg` ; le texte
du header reprend `theme_body_color`, celui du bandeau reprend
`theme_heading_color` et celui du footer vaut `#212529`. Le hook d'édition
initialise ces reprises avant les défauts du formulaire, puis les couleurs
sauvegardées deviennent indépendantes. Ces six variables sont aussi proposées
dans les palettes de l'éditeur.

Les classes `bg-light` et `text-dark` sont retirées des zones concernées car
leurs règles prioritaires empêcheraient les nouvelles couleurs de s'appliquer.
La navigation conserve les opacités Bootstrap pour ses liens ordinaires (65 %)
et désactivés (30 %) ; le bouton mobile suit aussi la couleur du header.
Les couleurs des liens principaux au survol et à l'état actif possèdent leurs
réglages dédiés décrits ci-dessous. Les menus déroulants conservent leur propre
fond et leurs couleurs de composants Bootstrap.
Le texte du footer s'applique aussi aux titres et liens sans couleur spécifique.
Les couleurs explicitement définies dans la page de footer restent prioritaires ;
un bloc opaque peut aussi masquer son fond. Les sélecteurs sont propres à Main
pour ne pas toucher les headers et footers des modales ou composants de page.

### Couleurs des états du menu principal

Dans « Thème : couleurs », quatre paramètres définissent les couleurs des
entrées principales du menu du header :

| Paramètre | Variable CSS générée | Défaut sans réglage |
| --- | --- | --- |
| `theme_menu_hover_bg` | `--theme-menu-hover-bg` | Blanc `#FFFFFF` |
| `theme_menu_active_bg` | `--theme-menu-active-bg` | Fond du header |
| `theme_menu_hover_color` | `--theme-menu-hover-color` | Texte du header |
| `theme_menu_active_color` | `--theme-menu-active-color` | Texte du header |

Ces couleurs sont validées en hexadécimal opaque côté formulaire et rendu CSS.
Le hook d'édition initialise les valeurs reprises du header avant les défauts
du formulaire ; après sauvegarde, elles évoluent indépendamment. Les quatre
variables sont aussi disponibles dans les palettes de l'éditeur.

`styles/main-template.scss` applique les variables aux `.nav-link` directement
sous les `.nav-item` de la `.navbar-nav` principale dans le header. Le survol
et `:focus-visible` partagent les mêmes couleurs ; `.active` reste prioritaire
même au survol ou au focus. Les entrées désactivées sont exclues. Les titres de
sous-menu sans URL, rendus en `span.nav-link`, suivent les mêmes règles.
Les éléments des listes déroulantes gardent leurs couleurs Bootstrap : les
sélecteurs ne ciblent ni `.dropdown-item`, ni les menus d'autres composants.
Le fond blanc de survol autrefois fixé dans le SCSS est remplacé par la variable.

### Hauteur du header de la template Main

Dans « Thème : composants », `theme_header_height` (« Hauteur du header »)
accepte une valeur de 40 à 200 pixels, avec 80 par défaut. Le formulaire valide
ces bornes côté serveur ; le rendu borne également les valeurs numériques déjà
stockées et utilise le défaut pour une valeur absente ou non numérique.
Le CSS généré définit uniquement `.main-template-header { --header-height: 80px; }`
(avec la valeur choisie). La feuille `styles/main-template.scss` consomme
`var(--header-height)` pour la hauteur du header et de sa navbar.

### Hauteur du logo de la template Main

Dans « Général », juste après le choix du logo, `theme_logo_height` (« Hauteur du logo ») accepte
une valeur de 20 à 200 pixels, avec 40 par défaut. Le formulaire valide ces bornes
côté serveur et le rendu borne aussi les valeurs numériques déjà stockées.
Le CSS généré applique directement `height: 40px` (avec la valeur choisie) et
`width: auto` à `.main-template-header .navbar-brand > img` pour conserver les
proportions de l'image. La hauteur du logo est indépendante de celle du header.

### Variantes de l'interface d'administration

L'apparence de l'administration est indépendante de la palette du site. La
template pose `data-admin-ui="classic|modern"` sur la racine `.vue-app` rendue
par `ria.php`. `AdminView` lit `theme_admin_ui` dans la configuration de la
template du Space courant, n'accepte que ces deux valeurs et retombe sur
`modern` si la configuration est absente ou invalide. L'attribut est donc
présent dès le HTML initial, sans attendre le montage Vue.

Le réglage est exposé dans « Thème : administration » de la configuration
Main (`MainTemplate/Config.php`). L'administration est globale : avec un
réglage par Space, c'est la configuration du Space courant qui s'applique.

Les styles sont dans `src/ria/src/scss/themes/admin/` :

| Fichier | Rôle |
| --- | --- |
| `_tokens.scss` | Contrat des tokens `--admin-*` et mixins de variantes. |
| `_base.scss` | Structure commune : racine, contenu, barre latérale. |
| `_modern.scss` | Surcharges du skin moderne et petites adaptations responsive. |
| `_index.scss` | Importé par `scss/main.scss`. |

Le skin moderne est porté par `body:has([data-admin-ui="modern"])`. Ce
sélecteur atteint le contenu **et** les modales communes, qui sont téléportées
dans `body`, sans ajouter de classe sur chaque composant. Il remplace
localement `--bs-border-radius`, `--bs-box-shadow` et les surfaces : les
classes Bootstrap du HTML restent inchangées. Les couleurs personnalisées du
site continuent de fournir `--bs-body-bg`, `--bs-tertiary-bg`,
`--bs-primary-bg-subtle`, etc.

Les onglets du composant `Tabs.vue` relient leur panneau à la barre d'onglets :
la bordure basse de `.tylms-tabs-nav` forme le haut du cadre, l'onglet actif la
recouvre avec le fond du panneau, et `.tylms-tab-content` porte les bordures
latérales et basse. Le panneau latéral de l'éditeur de pages conserve une
version plus compacte de ce cadre.

Ne pas redéfinir ces tokens sur `:root` : le CSS généré par la template est
chargé après le bundle en production. Une déclaration portée par la racine
admin gagne par héritage, quel que soit l'ordre des feuilles. `classic` reprend
le rendu historique et ne définit que les tokens par défaut.

Pour ajouter une variante : ajouter sa valeur à `AdminView::ADMIN_UI_VALUES`
et aux options du formulaire, puis créer son mixin dans `_tokens.scss` et sa
feuille dans `themes/admin/`. Le HTML et les classes Bootstrap ne changent pas.

## HTML serveur et sécurité

Un composant global n'est utilisable comme composant Vue que dans un template
Vue compilé. Le HTML PHP conservé dans le slot initial et le HTML de `v-html`
ne compilent ni directives, ni expressions Vue. Le bootstrap possède un montage
spécifique pour `sec_video` avec ses seules props littérales : [contrat](MEDIAS.md#balise-sec_video-dans-le-html-php).

Échapper les textes et attributs ; purifier le HTML éditorial selon son contrat
PHP. DOMPurify dans un aperçu Vue ne remplace pas le contrôle serveur. Les
registres Vue, chemins de composants et imports dynamiques restent des listes
de confiance définies par le code. La CSP et l'isolation des aperçus sont
décrites dans [Architecture](ARCHITECTURE.md#politique-de-sécurité-http).

## Navigation dans l’administration

La racine Vue `administrator/index` installe un routeur Vue Router à fragment :
`/administrator#/users` désigne la rubrique Utilisateurs. Le routeur porte
uniquement les rubriques du menu ; les éditeurs et modales internes ne créent
pas de routes. Il ne rend aucun composant : `App` choisit l’écran à partir de
`$route`, et les routes déclarent un composant vide pour satisfaire Vue Router.
Une adresse inconnue, une rubrique indisponible ou une adresse sans rubrique
affiche le tableau de bord, première rubrique accessible. Il présente des
raccourcis vers les autres rubriques accessibles et, pour ROOT, l’état des
mises à jour décrit dans [le guide des mises à jour](SYSTEM_UPDATES.md).

Le menu et l’historique passent par les confirmations de sortie des écrans IA,
modèles d’e-mails et atelier de pages. L’atelier ferme son document uniquement
après une navigation acceptée. Les permissions du menu limitent l’interface ;
les workflows PHP restent responsables de l’autorisation des opérations.
La rubrique `logs`, [Journaux](LOGGING.md), est réservée à ROOT sur le Space 1 ;
son store ne conserve qu’une page en mémoire et est vidé à la sortie.
