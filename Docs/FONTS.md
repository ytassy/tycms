# Bibliothèque partagée de polices

## Responsabilités et parcours

Le plugin **Core** possède le catalogue, les téléchargements, le registre SQL et
le stockage des polices du site. Une template ou une autre extension consomme
ce service ; elle n'installe pas ses propres fichiers. Les e-mails conservent
leur liste fixe et ne dépendent pas de cette bibliothèque.

L'administration expose une rubrique **Polices** : recherche dans le catalogue
local, installation explicite, état activé/désactivé et suppression physique.
L’ajout utilise un DataTable en largeur automatique, avec 10 familles par page
par défaut et les choix 5, 10, 15, 20 et 30. Il propose des filtres sur le nom
et la catégorie, un aperçu et un bouton Installer par ligne. Les familles
déjà installées sont exclues de ce tableau.

Les polices installées disposent d’un second DataTable avec la même largeur
automatique et les mêmes choix de pagination, avec 20 lignes par défaut. Il expose la famille, la
catégorie, l’état activé/désactivé, un aperçu utilisant les fichiers locaux,
les licences et, selon les droits, les actions de gestion. Les deux tableaux
peuvent défiler horizontalement sur les écrans étroits.
Les polices téléchargées sont partagées entre tous les Spaces. Une installation
ne modifie pas les choix de typographie des Spaces.

Les sélecteurs proposent cinq polices système : Arial, Verdana, Georgia,
Times New Roman et Courier New, puis les familles installées dans la bibliothèque.
Elles utilisent les polices disponibles sur l'appareil, avec une pile de repli
sans téléchargement. Arial est le défaut du texte et Georgia celui des titres,
dans Main comme dans les styles Bootstrap communs.

La migration Core **1.0.11** remplace dans les configurations Main de tous les
Spaces `system` et `nunito` par `arial`, et `bona_nova` par `georgia`, dans les
champs texte et titres uniquement. Les autres valeurs et les familles installées
restent conservées. Le rendu et la préparation du formulaire Main reconnaissent
aussi ces anciens identifiants via `normalizeId()`. Ils ne figurent plus parmi
les options ; les fichiers embarqués et leurs déclarations `@font-face` sont retirés.

## Catalogue sans clé Google

`Core/Resources/Fonts/catalog.json` fournit le catalogue initial (1 949 familles
lors de l’intégration). Après une actualisation, PHP utilise en priorité
`<fonts_root>/.catalog.json`, stocké dans le dossier des polices et non suivi
par Git. Il contient les noms, catégories, variantes standard et chemins de
licence, rattachés à une révision immuable de `google/fonts`. Le fichier caché
n’est pas exposé par les règles Apache livrées ; Vue continue à charger la liste
via `GET /administrator/api/fonts`, sans lecture directe de ce fichier.
Ni la consultation du catalogue ni les téléchargements ne nécessitent de clé.
Aucune synchronisation automatique n’a lieu pendant les requêtes publiques.

Le bouton **Actualiser depuis Google**, réservé à `FONT_MANAGE` sur le Space
racine, récupère les métadonnées Google et l’arbre des licences du dépôt officiel.
Chaque réponse est bornée à 8 Mio et 20 secondes ; les redirections sont refusées.
`FontCatalog` refuse un arbre tronqué, un JSON invalide, un catalogue vide, les
identifiants/chemins non autorisés et les licences sans empreinte valide.

Le rafraîchissement partage le verrou des mutations de la bibliothèque. Il
écrit et relit un fichier temporaire caché avant de remplacer atomiquement le
catalogue actif. En cas d’échec, la liste précédente reste disponible. La date
de dernière actualisation et le nombre de familles sont confirmés par l’API,
puis vérifiés par une relecture côté Vue avant d’annoncer un succès.

Les familles déjà présentes dans `font_installations` gardent leurs métadonnées,
notices et révisions Git d’origine, même si Google les modifie ou les retire.
Une révision peut donc être portée par chaque famille, en plus de celle du
catalogue courant. L’actualisation ne modifie ni les fichiers installés, ni
l’état activé/désactivé. Une installation relit le catalogue après acquisition
du verrou pour éviter une concurrence avec l’actualisation.

Sans cache persistant, le catalogue livré reste le repli initial. Un cache
présent mais invalide est refusé explicitement ; il n’est pas ignoré
silencieusement. Les sources Google/GitHub peuvent changer de format ou limiter
les requêtes sans clé ; ces erreurs empêchent la mise à jour, pas l’usage de la
liste précédente.

Les échecs d'installation et d'actualisation remontent avec leurs causes
internes dans le canal `fonts`, sous les codes `FONT_INSTALL_FAILED` et
`FONT_CATALOG_REFRESH_FAILED`, sans nom soumis ni URL de téléchargement.
Voir [Journaux](LOGGING.md).

Le catalogue retient les familles open source dont les métadonnées concordent
avec une licence présente dans le dépôt officiel. Les variantes prises en
charge sont les graisses standard 100 à 900, normales et italiques lorsqu'elles
existent. Il ne s'agit pas d'un éditeur des axes variables personnalisés.

Pour préparer hors ligne une nouvelle version du catalogue livré avec Core,
télécharger les deux sources officielles :

- `https://fonts.google.com/metadata/fonts` ;
- `https://api.github.com/repos/google/fonts/git/trees/main?recursive=1`.

Puis, depuis la racine :

```bash
python3 src/tools/build_font_catalog.py /tmp/font-metadata.json /tmp/font-tree.json
```

Le générateur refuse un arbre tronqué. Les métadonnées Google sont une source
de préparation, et leur format peut évoluer : examiner le diff généré, les
familles déjà installées et les licences avant livraison. Ne pas retirer du
catalogue les entrées encore installées. L'actualisation du catalogue ne met
pas à jour les fichiers déjà installés.

## Aperçus du catalogue dans l’administration

Les aperçus des familles non installées sont chargés directement depuis Google
par le navigateur de l’administrateur, sans clé API et sans stockage serveur.
Seules les lignes présentes dans la page du tableau montent leur composant
d’aperçu : 10 par défaut, jusqu’à 30 selon le choix de pagination. Chaque ligne demande une variante existante, en privilégiant le
romain le plus proche de 400, et uniquement les caractères du texte d’exemple.

La feuille CSS utilise `fonts.googleapis.com` et le fichier de police
`fonts.gstatic.com`. Les déclarations sont retirées du document lorsque la
ligne est démontée (pagination, filtrage ou fermeture de la rubrique).
Le navigateur peut conserver les fichiers dans son cache HTTP normal.
Un échec de CSS ou de fichier est indiqué dans la cellule ; il n’empêche pas
l’installation. Le téléchargement durable reste une action séparée soumise
aux droits habituels et aux contrôles du serveur.

Le plugin Security ajoute les deux sources à `style-src` et `font-src` pour
la seule route résolue `AdminController::home`, hors API. Les routes publiques
et les API gardent la politique précédente. Un rechargement complet de la
page d’administration est nécessaire pour recevoir cette nouvelle CSP.
Les aperçus du champ `core.font_family`, eux, utilisent les fichiers installés
localement. Aucune page publique de licences n’est créée.

## Stockage et registre

Le répertoire par défaut est `src/public/assets/fonts`, configurable avec
`Configuration::$fonts_root`. Une configuration locale ancienne sans cette
propriété garde ce défaut. Si déplacé, le répertoire doit rester exposé sous
`/assets/fonts/` par le serveur HTTP.

```text
assets/fonts/
  .install.lock
  google_<identifiant>/
    <empreinte de version>/
      <sha256>.woff2
      font.css
      OFL.txt ou LICENSE.txt / LICENCE.txt
      NOTICE ou NOTICE.txt, si fourni
      metadata.json
```

Les fichiers téléchargés sont ignorés par Git. La migration Core **1.0.9** crée
`font_installations` : clé de famille, version stockée, état `enabled`, date
de création. Sauvegarder et restaurer **ensemble la table et le répertoire**.
Le cache `.catalog.json` doit être inclus dans ces sauvegardes pour conserver
les familles ajoutées après l’installation initiale. Le stockage doit persister entre déploiements ; en production Docker, prévoir
un volume sur le dossier ou un alias équivalent. Ne pas remplacer tous les
assets en supprimant les fichiers téléchargés.

Le serveur PHP doit pouvoir écrire dans ce seul répertoire, sans rendre tout
`assets/` accessible en écriture. En développement Docker, l'entrypoint donne
au groupe `www-data` l'écriture sur les polices et vérifie une création réelle
sous cet utilisateur. Pour les images déjà construites, reconstruire l'image
pour bénéficier de ce contrôle au prochain démarrage.

Le `.htaccess` livré interdit le listage et l'accès aux chemins cachés, déclare
le MIME WOFF2 et un cache long pour les URL versionnées. Prévoir l'équivalent
avec un autre serveur HTTP. La CSP des pages publiques reste limitée aux
fichiers locaux. La page d'administration autorise les deux domaines Google
Fonts pour les aperçus du catalogue, comme décrit ci-dessous.

## Permissions

| Permission | Portée et actions |
| --- | --- |
| `FONT_ADD` — Ajouter une police | Attribuable par Space ; autorise le téléchargement d'une famille dans la bibliothèque commune. Ne permet ni désactivation ni suppression. |
| `FONT_MANAGE` — Gérer les polices | Vérifiée exclusivement sur le Space racine 1 ; permet l'installation, l'activation/désactivation et la suppression physique. |

Le rôle système 2 reçoit les deux permissions lors de la migration. Un
sous-administrateur doit recevoir `FONT_ADD` dans au moins un Space. Une
attribution de `FONT_MANAGE` sur un enfant n'ouvre pas la gestion globale.

**Désactiver** interdit les nouveaux choix, sans retirer les fichiers ni
modifier les réglages existants. Une configuration utilisant déjà cette
police peut être réenregistrée. Son rendu continue d'importer le CSS local.
Une réinstallation idempotente ne réactive jamais une famille désactivée.

**Supprimer physiquement** exige une désactivation préalable et une
confirmation explicite dans l'interface. Cette action peut affecter tous les
Spaces, le CSS personnalisé et les extensions : aucune analyse exhaustive
des usages n'est possible. Après suppression, Main utilise sa pile de repli.
Les cinq polices système ne sont pas gérées par ce registre et ne sont pas
supprimables par ce gestionnaire.

## API et sécurité

| Route | Contrat |
| --- | --- |
| `GET /administrator/api/fonts` | Catalogue local, installations, droits de gestion, Spaces autorisés à l'ajout et `catalogUpdatedAt`. Exige `FONT_ADD` dans un Space ou `FONT_MANAGE` à la racine. |
| `POST /administrator/api/fonts/catalog/refresh` | Sans paramètre ; exige `FONT_MANAGE` à la racine. Retourne `refreshed: true`, `catalog.count` et `catalog.updatedAt` après publication complète. |
| `POST /administrator/api/fonts/install` | `spaceId`, `id` ; retourne `font` et `font.verified: true` après vérification effective. |
| `POST /administrator/api/fonts/enabled` | `id`, `enabled` booléen ; exige `FONT_MANAGE` à la racine. |
| `POST /administrator/api/fonts/delete` | `id` ; exige `FONT_MANAGE` à la racine et une police désactivée. |

Les POST utilisent le CSRF de session normal. Les mutations sont
`NoTransaction` : le verrou disque sérialise les opérations ; les requêtes SQL
sont courtes et ne gardent pas de transaction ouverte pendant les téléchargements.
Les helpers n'ouvrent aucune transaction.

`FontDownload` n'accepte que les sources HTTPS strictes de Google Fonts et les
chemins de licence du dépôt officiel épinglés par le catalogue. Les
redirections sont refusées ; TLS reste vérifié. Une famille fournie par le
navigateur doit figurer dans le catalogue de confiance.

L'installation vérifie d'abord les licences contre leur empreinte Git, puis
analyse uniquement les descripteurs attendus de la feuille CSS. Les fichiers
sont contrôlés comme WOFF2, et le CSS est reconstruit avec des URL locales.
Aucun CSS distant arbitraire n'est injecté. Les sous-ensembles et variantes qui
partagent un fichier ne le téléchargent qu'une fois.

Limites par installation : 60 secondes au total, 15 secondes par requête,
1 Mio de CSS, 256 déclarations `@font-face`, 4 Mio par fichier WOFF2 et 48 Mio
cumulés. Une famille très volumineuse ou un format non pris en charge est
refusé proprement, sans changement des réglages de thème.

Les écritures passent par un répertoire temporaire caché, puis une publication
atomique dans un dossier versionné. Le registre SQL est écrit ensuite ; une
publication orpheline après échec SQL peut être reprise au prochain essai.
Les erreurs d'installation connues produisent une réponse JSON HTTP 422.
Les erreurs d'accès disque attendues ne sont pas émises comme warnings dans
la réponse HTTP. Un arrêt brutal du processus peut laisser un dossier
`.install-*` caché, à nettoyer après vérification qu'aucune installation ne tourne.

Avant confirmation au client, Core relit le registre, vérifie les fichiers
complets et leurs empreintes SHA-256. Le frontend exige une réponse structurée,
le marqueur `verified` et une relecture concordante du registre. Un HTTP 200
contenant du HTML, un warning PHP ou un objet incomplet n'est pas un succès.

## Utilisation par les extensions

Le trait `TraitCoreSharedHelpers` expose `$this->coreFontHelper` :

- `options()` : options de formulaire, avec les familles désactivées marquées ;
- `assertSelectable($id, $previousId)` : valide un nouveau choix ou la conservation d'un ancien ;
- `normalizeId($id)` : remplace les anciens identifiants par leur équivalent système ;
- `stack($id, $fallback)` : pile CSS sûre et repli sur Arial par défaut ;
- `css([$idTexte, $idTitres])` : imports CSS **locaux**, à placer avant toutes les règles de la feuille ;
- `installed()` : installations et liens locaux des licences ;
- `catalog()` : catalogue persistant actualisé, avec repli sur celui livré avec Core ;
- `refreshCatalog()` : actualisation technique, à appeler seulement après contrôle WF de `FONT_MANAGE`.

Dans les configurations d'extensions, utiliser le type partagé `core.font_family`
avec seulement le nom, le libellé et éventuellement le défaut. Core fournit les
options, leur validation et les métadonnées de recherche/aperçu au composant
Vue `forms/font-family`. `WFExtensions` reconnaît ce type et vérifie qu'une
famille désactivée correspond déjà au réglage enregistré du même champ dans le
même Space. Les autres consommateurs doivent appeler explicitement
`assertSelectable` avant leurs écritures. Voir `Docs/FORMS.md`.

Les options portent un `data` scalaire : pile `fontFamily`, nom `fontName` et
URL locale `fontCssUrl`. Le champ ne charge que la feuille de la famille
sélectionnée, en partageant les chargements entre instances. Il refuse les
URL hors de la bibliothèque locale et affiche un échec si le CSS ou le WOFF2
ne peut pas être chargé. Les polices désactivées restent prévisualisables
lorsqu'elles constituent la valeur courante.

La template Main n'émet que les imports des familles sélectionnées. Le
navigateur télécharge les variantes et sous-ensembles nécessaires à son
contenu ; aucun appel à Google n'a lieu sur les pages publiques. Les données
installées sont mises en cache à l'échelle d'une requête, puis invalidées lors
d'une mutation locale.

## Licences

Chaque installation conserve les textes originaux de licence et les notices
fournies, avec les fichiers de police. Les liens sont présentés dans
l'administration pour permettre la rédaction des mentions légales.
**Aucune page publique dédiée aux licences n'est créée.** Les fichiers de
licence restent accessibles à leur URL statique, comme les polices.
Le gestionnaire ne modifie pas automatiquement les pages de mentions légales.

Les polices système utilisent les installations de l'appareil : aucun fichier
ni licence de ces familles n'est distribué avec Core. Nunito et Bona Nova peuvent
être installées explicitement depuis le catalogue comme toute autre famille ;
ces installations sont distinctes des anciens fichiers embarqués retirés.
