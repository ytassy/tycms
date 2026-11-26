# Configuration du plugin Sécurité

Le plugin `src/releases/vdev/core_extensions/Security` ajoute les en-têtes HTTP
via `onBeforeSendResponse()` et contrôle les motifs du WAF après résolution de
la route. Les domaines, les iframes et la CSP restent propres à chaque Space ;
seul le WAF peut reprendre la configuration du Space Root. Seule la permission
`ROOT` donne accès au formulaire, à ses valeurs et à l’API d’enregistrement ;
`EXTENSION_EDIT` ne suffit pas. Les contrôles restent dans `WFExtensions`.

## Réglages et format des listes

Le formulaire comporte des aides, des exemples complets et des exemples de
saisie sur plusieurs lignes dans les champs vides. Chaque liste contient
**une origine HTTPS exacte par ligne**, séparée par Entrée, jamais par une
virgule, un espace ou un point-virgule. Par exemple, pour les lecteurs intégrés :

```text
https://docs.google.com
https://www.youtube.com
https://player.vimeo.com
https://player.mediadelivery.net
```

Une origine est composée du protocole, du nom d’hôte et d’un éventuel port.
`https://example.org` n’inclut pas `https://www.example.org` ni ses autres
sous-domaines ; les ajouter séparément. `https://media.example.org:8443` est
valide, mais n’autorise pas le port 443. Le port 443 et le `/` final facultatif
sont retirés à la normalisation ; les noms sont mis en minuscules et les
origines dédoublonnées. Les lignes vides sont ignorées.

Les chemins, paramètres, fragments, identifiants, jokers, origines HTTP et
syntaxes CSP (`*`, `https:`, `'self'`, directives) sont refusés. Les noms
internationaux doivent utiliser leur forme ASCII/punycode. Chaque liste est
limitée à 100 origines et 16000 octets ; les valeurs cumulées sont limitées
à 6000 octets pour borner la taille des en-têtes.

| Paramètre | Effet | Défaut |
| --- | --- | --- |
| `iframe_mode` | `all_https` : toute origine HTTPS ; `allowlist` : origine du site et liste ; `self` : origine du site uniquement. | `all_https`, comportement existant conservé. |
| `iframe_origins` | Origines externes des cadres et lecteurs vidéo intégrés ; utilisée uniquement en mode `allowlist`. | Vide. |
| `image_origins` | Sources HTTPS additionnelles de `img-src`. | Vide. |
| `media_origins` | Sources HTTPS additionnelles de `media-src` pour audio/vidéo ; les lecteurs YouTube/Vimeo relèvent des iframes. | Vide. |
| `style_origins` | Sources HTTPS additionnelles de `style-src`. | Vide. |
| `font_origins` | Sources HTTPS additionnelles de `font-src`. | Vide. |

Pour Google Fonts, autoriser `https://fonts.googleapis.com` dans les styles
et `https://fonts.gstatic.com` dans les polices. Le SCSS peut alors référencer
la feuille distante, par exemple `@import url("https://fonts.googleapis.com/css2?family=Roboto");`.
Ces listes ne changent pas les validations métier des URL, les ACL médias,
les purifications HTML ou les règles CORS des services distants. Autoriser une
origine d’image dans la CSP ne rend pas automatiquement son URL sélectionnable
par un composant limité aux médias du Space.

Les ressources de la même origine restent autorisées. Les sources internes
`data:` (images et polices) et `blob:` (médias et worker HLS) sont conservées.
Les scripts et connexions externes, `frame-ancestors`, les permissions
caméra/microphone et les autres en-têtes restent fixés dans le code.
L’ancien champ `enabled`, sans effet sur les en-têtes, est retiré.

`form-action` garde `'self'` et ajoute les origines des fournisseurs de connexion
SSO actifs du Space. Chromium applique cette directive à chaque redirection d’un
POST de formulaire : le `303` de `/sso/start` vers l’IdP serait sinon bloqué,
alors que le message du navigateur désigne l’URL d’origine. Les origines sont
déduites des endpoints validés (`authorization_endpoint`, ou l’endpoint Google
imposé par le client) et ne sont ajoutées qu’aux réponses HTML, seules à porter
le formulaire. Une origine invalide est ignorée. Voir [SSO](SSO.md).

## Application au site et à l’éditeur

La réponse publique utilise le Space choisi avant la résolution de route.
Le helper charge la configuration avec une requête paramétrée et la conserve
uniquement pour la requête, indexée par Space. L’enregistrement invalide cette
entrée afin que les en-têtes de sa réponse utilisent les nouvelles valeurs.
Avant résolution d’une route ou choix de Space, les valeurs de démarrage
reprennent le comportement historique, sans accès à une session non initialisée.

L’éditeur affiche les origines autorisées sous le champ URL du composant Iframe.
Il les recharge avec le formulaire du Space réel de la page ; il faut rouvrir
l’éditeur après une modification de configuration dans une autre fenêtre.
La validation serveur refuse une iframe interdite à la sauvegarde, au collage,
à la validation IA et aux aperçus de brouillons. Le rendu de `core.iframe`
omettant les origines interdites, une restriction ROOT s’applique aussi aux
pages déjà enregistrées, sans les modifier en base. Pour enregistrer un
brouillon contenant une iframe devenue interdite, retirer ou modifier cette
iframe. Les autres iframes, notamment vidéo et HTML historique, restent soumises
à `frame-src` dans le navigateur.

Les API d’aperçu transmettent `security_sources`, construit au serveur avec le
Space réel de la page. `pagePreviewDocument.js` l’utilise pour les images,
médias, styles, polices et cadres, sans élargir les scripts ni les connexions
isolés. En l’absence de ces données, aucune origine externe n’est autorisée.

Un `srcdoc` hérite également de la CSP de sa page parente. La page d’accueil
de l’administration combine donc les sources du Space courant et celles des
Spaces dont les pages sont accessibles avec `PAGE_READ`.
L’aperçu local applique ensuite la politique plus précise du Space cible.
Les exceptions internes Google Fonts (catalogue de l’administration),
diagrams.net (outil d’édition) et Vite en DEV sont conservées dans leur contexte.
Après modification des réglages, recharger l’administration pour actualiser
ses en-têtes, en particulier lors de l’ajout d’une nouvelle origine.

L’isolation `sandbox` du composant Iframe reste fixe : la liste autorisée ne
résout pas à elle seule les incompatibilités Google Slides liées à l’origine
opaque. Les permissions du cadre et les en-têtes des services distants restent
indépendants de la liste de domaines.

## Import Map et serveurs frontend de développement

MainTemplate rend l’Import Map du Core avant les scripts ESM. Le helper
`FrontendAssets` crée alors un nonce aléatoire propre à la réponse, que
`Security` ajoute à `script-src`. Aucun `unsafe-inline` n’est ajouté pour
les scripts. Les entrées réservées et les URL des extensions sont construites
au serveur depuis les extensions chargées et leurs manifestes locaux contrôlés.
Une réponse qui impose sa propre CSP reste responsable d’autoriser ce nonce.

Seulement en DEV, la configuration locale peut activer le Vite Core sur 3000
et un Vite d’extension sur un port explicite. Leurs origines `http://localhost`
et leurs WebSockets sont autorisés exactement ; CSS, polices, images et médias
servent les ressources de ces mêmes frontends. En production, les variables
de sélection sont ignorées, les runtimes et extensions restent locaux et
aucune origine Vite n’est ajoutée. Voir [Extensions](EXTENSIONS.md).

## Pare-feu applicatif (WAF)

Le WAF est un contrôle complémentaire exécuté après la résolution de la route,
avant le contrôleur et toute transaction métier. Il ne remplace ni les ACL, ni
le CSRF, ni les validations des workflows. Il inspecte le chemin, les noms et
valeurs des paramètres d’URL, puis les corps de formulaires et de JSON des
routes résolues publiques et administratives. Une valeur contenant `%` est
aussi examinée sous sa forme décodée, séparément de l’originale. Les cookies,
les en-têtes, les fichiers téléversés, les fichiers statiques, les URL non
résolues et l’updater autonome ne sont pas couverts.

| Paramètre | Effet | Défaut |
| --- | --- | --- |
| `waf_mode` | `off` : aucun contrôle ; `observe` : journalise sans bloquer ; `block` : refuse avec HTTP 403. | `off`. |
| `waf_patterns` | JSON strict `{"strings":[],"regexes":{}}` : chaînes littérales et regex PCRE nommées. | Exemples fournis. |
| `waf_message` | Texte du refus, une ligne de 1 à 500 caractères ; le préfixe `[WAF]` est ajouté. | Message générique. |
| `waf_skip_root` | Exempte les requêtes des utilisateurs ROOT authentifiés. | `true`. |
| `waf_use_root` | Reprend le mode, les motifs, le message et l’exemption ROOT du Space 1. | `false`. |

Les chaînes sont recherchées sans distinction de casse. Les regex sont des
expressions PCRE complètes, avec délimiteurs et options ; chaque nom commence
par une lettre ASCII et comporte au plus 60 caractères. Le JSON est limité à
50 motifs, 256 octets par chaîne, 1024 octets par regex et 32768 octets au
total. Une expression invalide est refusée à l’enregistrement. Éviter les
signatures trop générales, comme `base64`, `iframe`, `#` ou `--`, qui
détecteraient du contenu légitime.

Le jeu livré par défaut reste volontairement ciblé : balises et wrappers PHP,
traversée de répertoire, superglobales, exécution de code, objets sérialisés,
XSS courants, motifs SQL et inclusion de fichiers. Il sert de point de départ
et ne remplace pas une liste adaptée au site. Les écrans qui reçoivent
légitimement du HTML, du SCSS, des modèles d’e-mail ou des prompts IA sont
exemptés dans Core ; les configurations d’extension le sont aussi pour
permettre de modifier les motifs depuis le formulaire.

L’analyse est bornée : 128 Kio par valeur, 512 Kio cumulés, 1024 nœuds,
profondeur 20, 10000 comparaisons et un budget de 50 ms vérifié entre les
comparaisons. Les limites PCRE bornent chaque regex sans garantir un délai
strict à la milliseconde. Un dépassement, une analyse incomplète ou une erreur
PCRE produit un événement ; en Observation il est journalisé, en Blocage il
refuse la requête plutôt que de conclure à une absence de détection. Les
données reçues ne sont ni modifiées ni stockées.

Un événement `WAF_EVENT` au maximum est conservé par requête dans les
[journaux applicatifs](LOGGING.md), canal `security.waf` : INFO en observation,
WARNING en blocage, ERROR si l’analyse est incomplète ou en erreur. Il ne
contient ni URL brute, valeur reçue, cookie ni fragment détecté. En
Blocage, une route HTML reçoit une page 403 avec le message échappé ; une API
ou un client attendant du JSON reçoit le format Core, avec `source=waf` et
`error_code=WAF_BLOCKED`. La réponse porte aussi l’en-tête
`X-Tycms-Blocked-By: WAF`.

L’héritage WAF est direct et limité au Space 1 : les valeurs locales restent
enregistrées et redeviennent actives en décochant l’option ; le Space Root ne
peut pas hériter de lui-même. Comme pour la CSP, l’enregistrement invalide la
configuration en cache pour que la réponse suivante utilise les nouvelles
valeurs.

Un contrôleur ou une action peut être exempté des motifs avec l’attribut
`#[SecurityDoNotCheckPatterns]` de
`Security\ExtensionApi\AnnotationsController\SecurityDoNotCheckPatterns`.
Placé sur la classe, il couvre toutes ses actions ; placé sur une méthode, il
ne couvre que cette action. L’exemption vaut pour tous les appelants de la
route et ne désactive jamais les ACL, le CSRF ou la validation métier. La
préférer par méthode pour les éditeurs de HTML ou de code. L’enregistrement
des configurations d’extension est déjà exempté pour permettre de configurer
le WAF lui-même.

## Bilan d’analyse de sécurité

L’administration expose un bilan en lecture seule, onglet « Sécurité » de
l’écran Système, servi par `GET administrator/api/system/security-report`. Il
est réservé aux comptes ROOT : un bilan de sécurité décrit les faiblesses du
site et ne doit pas être lisible par un administrateur ordinaire.

Un **contrôle** est du code pur : il lit la configuration, la base ou l’état du
site, et renvoie des constats. Rien n’est persisté — pas de table, pas de cache :
le bilan est recalculé à chaque lecture et reflète donc l’état courant. Un
contrôle qui lève une exception ne prive pas l’administrateur du reste du bilan :
son échec devient une alerte, sans exposer la cause interne.

Le contrat est `Core\ExtensionApi\Security\ISecurityCheck` : un groupe
d’affichage et une méthode `check()`. Chaque constat est un `SecurityFinding`
(titre, statut, description, correction, emplacement). Les statuts sont
`critical`, `danger`, `info` et `ok` ; le registre trie les constats par gravité
et place les groupes les plus préoccupants en premier, si bien que l’interface
n’interprète aucun texte.

Le registre `SecurityCheckRegistry` est extensible : les contrôles de Core sont
déclarés dans `Plugin::prepareWebsiteCatalogs()`, et une extension peut ajouter
les siens via `coreOnRegisterSecurityChecks`. Un contrôle peut porter sur
n’importe quel sujet — réglages, comptes, rôles, groupes, médias, contenu — et
pas seulement sur la configuration d’une extension.

**Règle de développement.** Un réglage de configuration qui, mal positionné,
peut créer un risque de sécurité doit être couvert par un contrôle de ce bilan.
Un réglage qui ouvre une exécution différée, élargit une autorisation ou
désactive une protection sans être signalé reste invisible en exploitation : le
bilan est le seul endroit où l’administrateur peut le constater. Le contrôle
compare toujours la configuration réelle, jamais une valeur supposée, et propose
une correction concrète quand il en existe une.

### Cloisonnement du Space racine

Le Space racine porte l’administration. La segmentation par Space sert de
seconde barrière pour les accès d’administration : elle perd sa valeur si des
comptes ordinaires partagent le Space de l’administration, ou si un compte
d’administration est exposé dans un Space de contenu.

Deux recommandations sont donc contrôlées :

- **le Space racine ne devrait contenir que des comptes ROOT.** Un compte
  ordinaire rattaché au Space racine est signalé en `danger` ; il doit être
  déplacé dans un sous-Space de contenu.
- **un compte ROOT ne devrait pas être rattaché à un sous-Space.** Un compte
  d’administration dans un Space de contenu rapproche la compromission du contenu
  de celle de l’administration ; il est signalé en `danger`.

Un compte est ROOT au sens de l’**ACL** : il détient la permission
`plg_core__ROOT`, directement ou par un rôle, son groupe ou un groupe parent. Ce
n’est pas une propriété de `users.space_id`. Le contrôle interroge donc la chaîne
réelle (`users`, `user_groups_map`, `user_groups`, `acl_map`, `acl_permissions`),
sans passer par les helpers d’ACL : une analyse de sécurité doit rester fiable
même si un helper porte un défaut.

### Erreurs PHP

Le contrôle `plg_core.php-error-settings`, dans le groupe « Environnement »,
reprend les trois réglages appliqués par le bootstrap : `display_errors`,
`log_errors` et `error_reporting_level`. Il lit la configuration réelle, jamais
le `php.ini` supposé de l'hébergement.

- `display_errors = true` est signalé en `danger` : les réponses peuvent
  révéler des chemins, des requêtes SQL ou d'autres détails internes.
- Une journalisation serveur désactivée (`log_errors = false`) ou un
  `error_reporting_level` qui exclut `E_ERROR` ou `E_WARNING` est signalé en
  `info` : une panne devient alors difficile à diagnostiquer.

Les corrections renvoient aux clés de `configuration.php`. Les valeurs par
défaut (`display_errors = false`, `log_errors = true`,
`error_reporting_level = E_ALL & ~E_DEPRECATED`) sont conformes.

## Organisation et migration

- `Plugin.php` : points d’entrée, permission ROOT, hooks de configuration et du WAF.
- `ConfigurationForm.php` : formulaire, descriptions et exemples.
- `Configuration.php` : normalisation commune des réglages CSP et WAF.
- `SecurityPolicy.php` : normalisation stricte des origines et correspondance des cadres.
- `Waf/WafConfiguration.php` : contrat des motifs, du message et du JSON.
- `Waf/WafConfigurationForm.php` : sections et explications du WAF.
- `Waf/PatternScanner.php` : parcours borné du chemin, de la requête et du corps.
- `Waf/BoundedRegex.php` : exécution PCRE avec limites et restauration des réglages.
- `Waf/BlockedResponse.php` : réponse 403 HTML ou API.
- `Helper/SecurityPolicyHelper.php` : lecture par Space et accès au contexte HTTP.
- `Helper/WafHelper.php` : héritage, exemptions, journalisation et décision de blocage.
- `Traits/TraitSecurityHelpers.php` : accès typé partagé avec Core.
- `ResponseHeaders.php` : construction des directives et des en-têtes fixes.
- `Migration/Mig_1_0_1.php` : évolution des valeurs CSP persistées.
- `Migration/Mig_1_0_2.php` : ajout des paramètres WAF désactivés.

La migration `plg_security` / `1.0.1` doit être exécutée sur une installation
existante avant d’utiliser ce code. Elle ajoute les paramètres CSP absents avec
les défauts historiques, conserve les choix déjà présents et retire `enabled`.
La migration `1.0.2` ajoute ensuite les paramètres WAF avec le mode `off` et les
exemples de motifs, sans modifier les autres configurations. Elle n’a aucun
effet sur les configurations des autres extensions. Les nouvelles
configurations sont initialisées depuis le formulaire par `ExtensionsHelper`.
La lecture normale exige le contrat complet ; elle ne compense pas une migration
manquante par des replis silencieux.

Le test d’intégration `SecurityConfigurationMigrationTest` utilise uniquement
des tables temporaires sur une connexion de la base de test isolée ; il vérifie
les choix persistés, le retrait d’`enabled`, l’ajout des paramètres WAF et la
création des configurations.
