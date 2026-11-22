# Shortcodes éditoriaux

Core fournit `ShortCodeHelper`, accessible par la propriété typée
`coreShortCodeHelper` de `TraitCoreSharedHelpers`. Le moteur est indépendant de
l’éditeur de pages et peut être appelé par une extension, notamment un LMS.

```php
$html = $this->coreShortCodeHelper->applyShortCodes($html, $spaceId);
```

Le second argument PHP est facultatif : sans lui, le Space courant est utilisé.
Il sert au contexte de l’appelant ; aucune option de Space n’existe dans les
shortcodes. L’éditeur transmet le Space de la page, y compris pour un brouillon.
Le contenu enregistré conserve les shortcodes ; seul le rendu est transformé.

## Contenu accepté et sécurité

L’appelant doit nettoyer le HTML éditorial avec le sanitizer habituel avant
l’appel. Le helper ne remplace pas les contrôles métier, la visibilité ni les
ACL de l’extension. Il interprète les shortcodes dans le contenu textuel, jamais
dans les attributs, commentaires, scripts, styles, blocs `pre`/`code`, `textarea`,
`template`, SVG, MathML ou iframe. Les sources Mermaid sont opaques aux autres
shortcodes. Les syntaxes inconnues et les anciens `{{…}}` restent littéraux.
`VAR` et les champs utilisateur Joomla `jc_*` ne sont pas pris en charge.

Le helper accepte au plus 500 000 octets de HTML. Une source Mermaid est limitée
à 30 000 octets, avec au plus 50 diagrammes par appel ; le moteur navigateur
limite également la source à 30 000 caractères et le nombre d’arêtes à 500.
Les options incorrectes donnent un message générique de diagramme indisponible.
Un appel peut normaliser la sérialisation du HTML via DOMDocument.

Le résultat dépend du visiteur connecté et peut contenir des URL temporaires :
ne pas le placer dans un cache partagé entre visiteurs. Les réponses HTML avec
template et les réponses d’aperçu des pages portent `Cache-Control: private, no-store`.
Les futures extensions doivent appliquer cette règle à leurs propres réponses.

## Informations utilisateur

| Syntaxe | Résultat |
| --- | --- |
| `[[USER_name]]` | Prénom puis nom, séparés par une espace ; les extrémités vides sont retirées. |
| `[[USER_email]]` | Adresse e-mail du visiteur connecté. |
| `[[USER_id]]` | Identifiant du visiteur connecté. |

Sans utilisateur connecté, ces trois shortcodes deviennent une chaîne vide,
sans avertissement. Les valeurs sont échappées comme du texte HTML.

## Diagrammes Mermaid

```text
[[DIAGRAM,width=600px,align=center,theme=forest,sketch=0]]
flowchart LR
    A[Début] --> B[Fin]
[[/DIAGRAM]]
```

Le code peut être réparti dans les paragraphes d’un éditeur riche ; ses retours
à la ligne et son indentation sont conservés après normalisation des balises
et des espaces insécables. Les marqueurs doivent rester du texte continu et
ne pas être placés dans un bloc de code littéral.

Les réglages sont dans la configuration de l’extension Core, par Space. Son
formulaire est défini par `Core/Helper/Config/FormsHelper`, exposé en interne par
`coreAppConfigFormsHelper`. Il peut accueillir les prochaines sections de Core.

| Option | Défaut | Valeurs |
| --- | --- | --- |
| `width` | `95%` | 1 à 10 000 px ou 1 à 100 %. |
| `align` | `center` | `left`, `center`, `right`. |
| `look` | `auto` | `auto`, `classic`, `handDrawn`, `neo`. |
| `theme` | `auto` | `auto`, `default`, `base`, `dark`, `forest`, `neutral`, `neo`, `neo-dark`, `redux`, `redux-dark`, `redux-color`, `redux-dark-color`. |
| `sketch` | `1` | `0` ou `1` ; conversion Svg2Roughjs distincte du rendu natif `handDrawn`. |
| `wrapwidth` | `120` | 40 à 1 000 px ; retour à la ligne des libellés flowchart. |

Les options du shortcode priment sur la configuration Core. Un frontmatter
Mermaid présent dans la source est conservé : le moteur n’y ajoute alors pas
son frontmatter de présentation. Les options de sécurité restent imposées par
`mermaid.initialize`, avec `securityLevel: strict` et une liste `secure` incluant
les limites, le sanitizer et les réglages CSS sensibles.

Les bundles versionnés sont sous `assets_core_vdev/plg_core/mermaid`. Le moteur commun
`runtime.mjs` charge Mermaid et Svg2Roughjs à la demande et sérialise les dessins.
La compilation reproductible est décrite dans [src/tools/mermaid](../src/tools/mermaid/README.md).

## Composants Vue et chargement public

```vue
<Mermaid :diagram="source" :options="options" />
```

Importer localement `src/ria/src/components/common/Mermaid.vue`. `options`
est facultatif : MainTemplate transmet les seuls réglages Mermaid publics du
Space dans le bootstrap `shortcodes.mermaid`. Un appelant avec un autre contexte
fournit explicitement les options correspondant à son Space.

Le helper PHP produit des conteneurs `data-shortcode-diagram`. Le frontend public
monte dessus le même composant, via `mountMermaidComponents()`. Pour un fragment
chargé dynamiquement, utiliser `ShortCodeHtml.vue` avec le HTML déjà préparé au
serveur, ou monter et démonter explicitement les composants avec les fonctions
de `common/mermaid/mount.js`. `v-html` seul ne compile pas de composants Vue.
MainTemplate charge explicitement la feuille `mermaid/style.css` ; une autre
template qui utilise ces composants doit également charger cette feuille.

## Intégration aux pages et aux aperçus

Le rendu public applique les shortcodes au HTML des pages de type 1 après
nettoyage, et au contenu localisé des composants `core.html` et `core.rich-text`.
Les attributs et les props JSON des composants d’extension ne sont pas parcourus
implicitement. Les extensions restent libres d’appeler le helper sur leurs
propres contenus éditoriaux.

Le canevas affiche le HTML localisé du brouillon, nettoyé par DOMPurify,
en conservant les shortcodes tels quels. Il ne les convertit pas et ne fait
aucun appel API pour leur affichage. Le composant Mermaid commun reste disponible
pour le site public et les extensions qui choisissent de l'utiliser.

L’aperçu fidèle conserve son iframe sans cadre de template. Il utilise
`sandbox="allow-scripts"`, sans `allow-same-origin`, formulaires ni navigation
supérieure. Le document charge uniquement `mermaid/preview.mjs` et ses modules.
Une CSP locale restreint les scripts au répertoire Mermaid, interdit les scripts
en ligne, les gestionnaires d’événements, les connexions API, les formulaires et
les objets embarqués. Le CSS du brouillon est inséré en neutralisant `<`.

Les modules `.mjs` de ce répertoire public sont servis comme JavaScript avec
`Access-Control-Allow-Origin: *`, sans autorisation de credentials, pour les
imports ESM depuis l’origine opaque de l’iframe. Apache applique ces règles via
le `.htaccess` local ; un autre serveur doit reproduire cette configuration.
Cette isolation ne constitue pas une garantie d’absence de cookies dans toute
requête réseau. Aucun jeton de session ou bootstrap d’administration n’est
injecté dans le document de l’aperçu.

## Vidéos Bunny

```text
[[VIDEO, bunny:123/719a10ca-daed-492a-96bb-55990c5ede30]]
```

Core configure la hauteur (500 px par défaut), la validité des liens signés
(7 200 secondes, de 900 à 604 800) et un objet JSON de clés par bibliothèque,
saisi comme une chaîne JSON dans CodeMirror (`core.code`, syntaxe JSON).
La signature reste `SHA-256(clé + identifiant vidéo + expiration)`.
Sans clé pour la bibliothèque, l’URL est produite sans signature ; les droits de
lecture restent ceux configurés dans Bunny. Le shortcode ne crée pas d’ACL vidéo.

Les clés sont stockées en clair dans `config.params.video_bunny_tokens`, sous
forme de chaîne JSON. Les anciennes valeurs chiffrées restent lisibles et sont
migrées lors de la prochaine sauvegarde. Le JSON est réaffiché au chargement
et après sauvegarde dans CodeMirror pour les éditeurs autorisés du Space.
Les réponses de configuration sont privées et non mises en cache. Les clés
sont absentes des rendus publics et des aperçus.
Vide conserve les clés, `{}` les supprime, un objet non vide remplace l’ensemble.
Conserver `Configuration.secret` tant que des valeurs anciennes chiffrées
restent en base.
La CSP autorise uniquement le lecteur `https://player.mediadelivery.net` en plus
des fournisseurs déjà autorisés ; aucun domaine de script externe n’est ajouté.

## Composants dédiés de l’éditeur

Les composants `core.video` et `core.mermaid` permettent d’éditer directement
leurs données sans shortcode. Le composant Mermaid possède un aperçu dans le
canevas et des surcharges de style héritant du Space ; Bunny y reste un cadre
statique. Voir [Composants Vidéo et Mermaid](PAGE_EDITOR.md#composants-vidéo-et-mermaid).
Le helper `bunnyUrl()` et le constructeur `DiagramShortCode::renderSource()`
sont partagés avec ces composants pour conserver les mêmes règles de rendu.
