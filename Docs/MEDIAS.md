# Gestionnaire de médias

Le gestionnaire de médias de l'administration travaille dans le répertoire
`Configuration::$medias_root` de chaque application. Sa valeur par défaut
conserve le répertoire public de chaque espace :

```text
src/public/medias/<spaces.medias_dir>/
```

Les fichiers sont volontairement accessibles par leur URL publique
`/medias/<medias_dir>/<chemin>`. La configuration Apache de
`src/public/.htaccess` désactive le listage avec `Options -Indexes` ; elle ne
protège pas les fichiers médias par une authentification applicative.
Si `medias_root` pointe ailleurs en production, le serveur web doit exposer ce
répertoire sous `/medias/` (alias ou lien adapté). Un chemin hors du répertoire
public convient directement aux tests, qui n'ont pas besoin de servir ces URL.
`SpaceHelper::load()` recrée le chemin configuré et les sous-répertoires des
Spaces au chargement si ceux-ci ont été supprimés.

Un administrateur doté de `SPACE_ADMIN` peut choisir le nom du dossier à la
création d'un Space ; un nom généré est proposé par défaut. Il peut aussi le
modifier pour un Space existant. Le dossier et ses fichiers sont renommés ;
les URL et références enregistrées ne sont pas réécrites et doivent être
corrigées manuellement. Le nouveau nom contient 1 à 50 lettres ASCII, chiffres,
tirets ou underscores. Les noms sont uniques entre Spaces, sans distinction de
casse, avec une contrainte SQL. Un dossier déjà présent est refusé à la création
ou comme destination d'un renommage ; un lien symbolique est refusé comme source.
La migration `1.0.13` s'arrête si des noms existants sont en conflit : il faut
corriger ces données et leurs fichiers avant de la relancer.

L'édition ouvre sa propre transaction SQL pour coordonner le renommage et
l'enregistrement ; en cas d'erreur elle tente de remettre le dossier à son nom
initial. Une interruption du processus ou une perte de connexion pendant la
validation SQL peut nécessiter une vérification et une réparation manuelles.

## Diagnostic des erreurs de stockage

Le canal `media` distingue les échecs techniques de création, téléversement,
écriture, remplacement, renommage, déplacement et suppression. Les exceptions
qualifiées portent un code stable et le Space réel ; les WF conservent leur
cause pour la capture commune, sans recopier de chemin, nom de fichier ou
contenu. Les noms invalides, doublons et conflits de version restent des
validations ordinaires sans log systématique. `MEDIA_RESTORE_FAILED` signale
séparément un échec de restauration du fichier précédent.

Le canal `space` couvre la création et le renommage du dossier média d'un
Space. `SPACE_MEDIA_RESTORE_FAILED` exige une intervention manuelle après
l'échec d'une compensation ; les causes internes restent disponibles. Le
succès `SPACE_MEDIA_RENAMED` est enregistré seulement après le commit explicite
du WF. Ces traces ne changent ni les contrôles de fichiers ni les tentatives
de compensation. Voir les [codes des journaux](LOGGING.md#diagnostics-des-workflows-core).

## Permissions

- `MEDIA_READ` permet de consulter les dossiers et leurs métadonnées.
- `MEDIA_CRUD` permet aussi de créer un dossier, téléverser, renommer, déplacer
  ou supprimer un fichier. Il implique la lecture dans le workflow.

Les permissions sont toujours vérifiées côté serveur pour l'espace sélectionné.
L'identifiant de l'espace envoyé par Vue ne constitue pas une autorisation.

Le champ `core.media_file` ouvre un sélecteur limité au Space du formulaire.
La liste et les dossiers passent par l'API de médias et exigent `MEDIA_READ` ou
`MEDIA_CRUD`. En mode `image`, seuls les fichiers dont le MIME commence par
`image/` sont proposés. La validation d'une URL média dans la configuration
d'extension vérifie de nouveau le Space, le fichier réel et son MIME côté
serveur. Les options de saisie directe sont décrites dans `Docs/FORMS.md`.

Le même sélecteur est utilisé par le bouton « Médias du site » de TinyMCE et
par son entrée dans le menu « Insérer » de l'éditeur de pages. Ces commandes
ne sont présentes que si l'utilisateur possède
`MEDIA_READ` dans le Space de la page. L'API vérifie de nouveau
ce droit lors de la lecture. Le sélecteur renvoie une URL publique commençant
par `/medias/` ; TinyMCE insère les images en `<img>`, les vidéos en `<video
controls>` et les autres fichiers en lien `<a>`.

## Configuration

Les défauts se trouvent dans `src/releases/vdev/configuration.base.php` et sont
hérités par la configuration locale `src/releases/vdev/configuration.php`, qui
peut les surcharger :

```php
public string $medias_root = APP_ROOT . '/public/medias';
public int $media_max_upload_size = 25 * 1024 * 1024;
public array $media_allowed_extensions = ['jpg', 'png', 'pdf', 'mp3'];
```

`medias_root` est un chemin disque. Les configurations locales anciennes qui
ne déclarent pas cette propriété continuent à utiliser
`src/public/medias`. Pour les tests, chaque instance peut définir un chemin
distinct sous `src/tests/.runtime/`, dossier ignoré par Git et nettoyable
sans toucher aux médias du site.

La taille est exprimée en octets. Les extensions sont écrites sans point et
sont comparées sans tenir compte de la casse. La limite PHP `upload_max_filesize`
et `post_max_size` doit rester au moins égale à la limite applicative.

Les dossiers cachés, les chemins absolus, `..` et les liens symboliques ne sont
pas exposés par l'API. Un dossier ne peut être supprimé que s'il est vide.

La réponse de `administrator/api/medias/list` contient aussi `tree`,
l'arborescence complète des dossiers de l'espace. Elle est recalculée à chaque
chargement de dossier, afin que la navigation et les déplacements dans l'interface
utilisent la même version des données. Les fichiers n'y figurent pas.

Dans l'administration, les fichiers glissés depuis l'Explorateur Windows sur la
zone centrale sont téléversés dans le dossier ouvert. Plusieurs fichiers peuvent
être déposés ensemble ; les mêmes limites d'extension et de taille s'appliquent
qu'avec le bouton de téléversement. Le glisser-déposer d'un média déjà présent
vers l'arborescence de gauche sert au déplacement entre dossiers.

La zone de dépôt utilise le composant global Vue `file_dnd`. Il contrôle les
extensions et la taille avant l'envoi, et affiche une barre de progression
pour l'ensemble des fichiers du lot. La progression mesure les octets transmis
au serveur ; à 100 %, l'interface attend encore la réponse et le rechargement
du dossier.

Le bouton « Ouvrir » affiche une modale de prévisualisation : image, PDF,
audio, vidéo ou texte (jusqu'à 1 Mo). Pour les autres formats, la modale propose
l'ouverture dans un nouvel onglet et le téléchargement. Ces deux actions restent
également disponibles pendant une prévisualisation.
L'aperçu reconnaît notamment BMP/ICO/TIFF/HEIC, FLAC/OGG, MOV/OGV et les
formats texte courants comme Markdown, YAML et les fichiers de code. La lecture
des formats multimédias dépend des codecs disponibles dans le navigateur ; en
cas d'échec, les actions d'ouverture et de téléchargement restent accessibles.

## Fichiers texte éditables

L'administration peut créer et modifier des fichiers `.txt` et `.scss` dans le
dossier courant. La liste des extensions éditables est définie dans
`MediaHelper` et intersectée avec `media_allowed_extensions` : une extension
ajoutée plus tard doit être déclarée dans les deux listes. Les fichiers SCSS
sont enregistrés comme source ; le gestionnaire de médias ne compile pas de CSS.

`MEDIA_READ` permet de charger le contenu par l'API. La création et
l'enregistrement exigent `MEDIA_CRUD` sur l'espace. L'API contrôle les chemins,
refuse les liens symboliques et n'accepte que du texte UTF-8 de 1 Mo maximum.
L'enregistrement transmet la version SHA-256 lue à l'ouverture afin de signaler
une modification concurrente. La création refuse un nom déjà présent, mais
accepte un fichier vide. Les fichiers restent publiquement accessibles par leur
URL média habituelle.

L'éditeur propose « Enregistrer », qui reste ouvert, et « Enregistrer et fermer ».
Le premier bouton porte la classe globale `ctrl-s` pour le raccourci Ctrl+S ;
voir la convention dans `Docs/ARCHITECTURE.md`.
La touche Échap actionne « Fermer » dans les éditeurs de texte et de diagrammes,
ainsi que dans l'aperçu. L'éditeur de texte conserve sa confirmation si des
modifications ne sont pas enregistrées.
Un clic sur un fichier éditable ouvre directement l'éditeur lorsque l'utilisateur
dispose de `MEDIA_CRUD`. L'action « Prévisualiser » de son menu conserve l'aperçu
en lecture seule. Avec `MEDIA_READ` seul, le clic ouvre cet aperçu.

## Diagrammes

Les fichiers PNG et SVG peuvent être ouverts dans l'éditeur embarqué
diagram.net. Une sauvegarde exporte une image au format `xmlpng` ou `xmlsvg` :
les données du diagramme sont conservées dans le fichier, qui peut donc être
ouvert à nouveau par l'éditeur.
Le bouton « Enregistrer » garde l'éditeur ouvert ; « Enregistrer et fermer »
ferme la modale une fois le fichier téléversé. Après la première sauvegarde d'un
nouveau diagramme, les suivantes remplacent le même fichier.
Dans l'éditeur intégré, Ctrl+S déclenche « Enregistrer » sans fermer la modale.

L’éditeur commun réside dans `components/common/DiagramEditor.vue`. Son en-tête
signale que l’édition se déroule sur le site externe diagrams.net. À chaque
initialisation, il résout les couleurs de template avec `resolveTemplatePalette`
et envoie leurs teintes opaques à `customPresetColors` dans le message
`configure`. Les Médias utilisent le catalogue du bootstrap du Space courant ;
le composant de page fournit son catalogue d’édition. Les valeurs sont des
hexadécimaux fixes, sans dièse ni doublon : elles ne suivent pas un changement
ultérieur du thème. Les couleurs habituelles et les styles initiaux des formes
restent disponibles. Les teintes transparentes sont ignorées. Comme les autres
palettes de l’administration, la résolution CSS suit le thème du Space courant.

L'iframe n'accepte les messages que depuis `https://embed.diagrams.net`. La CSP
du plugin Security autorise explicitement cette origine dans `frame-src`.


## Dossiers dans les pages visuelles

Le composant `core.file-list` publie une liste automatique des fichiers d’un
seul dossier des médias du Space de la page, sans sous-dossiers. Le choix et
la validation exigent `MEDIA_READ`. `MediaPicker.directoryOnly`
ajoute la sélection du dossier courant au contrôle existant.

`POST /administrator/api/medias/page-files` lit les fichiers et prépare le rendu
du canevas avec ces mêmes permissions et le CSRF habituel. Il n’existe pas de
nouvel endpoint public d’ouverture ou téléchargement : les liens utilisent
les URL `/medias/` existantes, avec `target="_blank"` ou `download`.
Le lecteur public dépend des capacités du navigateur. Les règles de visibilité
de la liste ne remplacent pas une protection des URL médias, qui restent publiques.

`MediaHelper::listDirectory()` peut omettre l’arborescence (`includeTree: false`)
et borner les éléments du dossier (`maxEntries`). Les valeurs par défaut gardent
le contrat du gestionnaire existant. Le composant de page borne la lecture à
1000 éléments et ne descend pas dans les sous-dossiers.
Contrat complet : [Liste de fichiers](PAGE_EDITOR.md#liste-de-fichiers-dun-dossier).

## Sélection de fichiers vidéo et audio

Le contrôle commun `MediaFileInput` et le champ `core.media_file` acceptent
`mediaType: 'video'` et `mediaType: 'audio'` en plus de `all` et `image`.
Le sélecteur limite les fichiers au MIME `video/*` ou `audio/*`, en conservant
la navigation dans les dossiers. La validation
technique `MediaHelper::assertSelectableFile($space, $url, 'video')` vérifie
le fichier réel et son MIME ; une extension `.mp4` seule ne suffit pas.
Le workflow appelant doit vérifier les droits médias avant cet accès technique.
Le composant de page Vidéo impose ce mode avec un fichier du Space exact ;
son lecteur est décrit dans `Docs/PAGE_EDITOR.md#vidéo`.
Le composant Audio applique le même contrat avec
`MediaHelper::assertSelectableFile($space, $url, 'audio')` : le MIME réel doit
commencer par `audio/`, même si le nom se termine par `.mp3` ou `.wav`.
Les fichiers audio restent publics, comme les autres médias ; leur format
et leur codec doivent être pris en charge par le navigateur. Voir
[Audio](PAGE_EDITOR.md#composant-audio).

## Flux vidéo HLS par l’extension Core

Le sélecteur de médias du composant Vidéo possède un filtre HLS dédié : il
affiche les playlists `.m3u8` dans les sous-dossiers du Space, indépendamment
du MIME détecté. La saisie manuelle d’un alias externe reste disponible.

Le helper `SecureVideoHelper` accepte une playlist `.m3u8` dans le dossier
média du Space demandé ou dans une bibliothèque externe déclarée par alias. La
playlist, les segments `segment_N.ts` et la clé AES-128 passent par
`/secure-video/stream`. La source est validée, chaque chemin est résolu sous
sa racine autorisée et les liens symboliques sont refusés. La route est
publique ; connaître une source suffit à lire le flux.

Sans mot de passe, le fichier `video.key` de 16 octets reste en clair sur le
disque. Avec un mot de passe Core, le service lit `video.key.crypt` au format
historique `VKEY1` ou convertit `video.key` lors du premier accès. Dans
les médias publics, la clé claire reste téléchargeable directement avant cette
conversion, et tout doublon comme `video copy.key` le reste après. Préparer
les clés chiffrées avant publication évite cette fenêtre. La réponse API
utilise le format `==B64` de l’ancien lecteur ; le composant Vue la décode
avant Video.js. Ce transport ne constitue pas un contrôle d’accès. Le lecteur
8.23.4 et sa feuille CSS sont chargés par MainTemplate depuis `assets_core_vdev`.
Pour ces playlists AES-128 sans DRM, le composant débloque la file d'ajout VHS
lorsqu'elle reste en attente après la préparation du segment.

### Réutiliser le service HLS

Source : `Core/Helper/Video/SecureVideoHelper.php`. Utiliser l'accès partagé
`$this->coreSecureVideoHelper` fourni par `TraitCoreSharedHelpers` :

```php
$html = $this->coreSecureVideoHelper->render(
    source: 'cours:kenyoku/playlist.m3u8',
    spaceId: $space->id,
);
```

`render()` valide la forme de la source et le Space, puis échappe le balisage.
`assertSourceInSpace($spaceId, $source)` vérifie aussi la playlist réelle.
Passer un Space explicite s'il diffère de celui de la requête. Le HTML PHP
doit conserver une balise fermante `</sec_video>`.

Le formulaire Core porte `video_hls_libraries` (chaîne JSON alias → chemin
disque absolu) et `video_hls_password` (mot de passe facultatif). Leur lecture
sensible et leur écriture exigent `SPACE_ADMIN`, y compris via l'API générique.
Les autres éditeurs ne les reçoivent pas et leur sauvegarde préserve ces valeurs.

```json
{
  "cours": "/srv/bibliotheques/videos",
  "archives": "/mnt/archives/cours"
}
```

Les alias suivent `[a-z][a-z0-9_-]{0,31}`, au plus 100 bibliothèques. Les racines
doivent exister et être lisibles par PHP. Les chemins physiques et le mot de
passe restent hors du HTML, des props et des erreurs publiques. Le mot de
passe est stocké en clair dans `config.params` selon le contrat Core ; les
anciennes valeurs chiffrées restent lisibles, puis sont normalisées à la
prochaine sauvegarde. Il ne dépend pas de `Configuration.secret`.

### Format des sources et du flux

La source désigne la playlist dans un dossier vidéo :
`/medias/<medias_dir>/<dossier>/playlist.m3u8` ou
`alias:<dossier>/playlist.m3u8`. Les segments de chemin utilisent l'encodage
canonique de `rawurlencode` ; par exemple un espace devient `%20`.
La source est limitée à 2 048 octets. Un alias inconnu, une URL distante, un
wrapper PHP, un chemin arbitraire, une traversée ou un lien symbolique de
sortie ne sont jamais admis comme repli.

`SecureVideoController` sert `GET /secure-video/stream?spaceId=…&source=…&file=…`
en déléguant au helper. Réponses `no-store` avec MIME explicite ; erreurs 404
génériques sans détail disque ni secret. `file` accepte seulement
`playlist.m3u8`, `segment_N.ts` et `video.key`. La playlist est bornée à
1 000 000 octets et 10 000 lignes, un segment à 32 000 000 octets. Les
références sont réécrites vers cette API.

Le parseur accepte le sous-ensemble du lecteur fourni : playlist UTF-8
`#EXTM3U`, segments `segment_N.ts`, balise AES-128 avec
`URI="[URL_OF_KEY]"` et IV hexadécimal facultatif, métadonnées prévues par
`SecureVideoHelper::playlist()`. Les autres instructions sont refusées ; ce
service n'importe pas de playlists HLS arbitraires et ne transcode pas de vidéo.

### Protection de la clé et limites

`video.key.crypt` utilise `VKEY1` avec PBKDF2, distinct du `tycmscrypt:v1:` de
[CryptHelper](CRYPTO.md) ; les formats ne sont pas interchangeables. Ce fichier
est prioritaire. Un `video.key` présent à côté doit correspondre exactement à
la clé déchiffrée avant suppression. En conversion, le service écrit un fichier
temporaire, vérifie son déchiffrement, puis retire la clé claire. Préparer une
bibliothèque en lecture seule avec ses clés déjà chiffrées et sans doublon clair.

Conserver durablement le mot de passe HLS : le changer sans rechiffrer les
fichiers rend les vidéos illisibles. Un ancien fichier exige son mot de passe
d'origine avant rechiffrement. `==B64` transporte la clé encodée, que le lecteur
décode en 16 octets ; elle reste accessible au navigateur qui lit le flux. Ce
transport ne remplace aucune ACL et n'empêche pas le téléchargement du flux.

La CSP admet le worker et les sources média `blob:` sans élargir `script-src` ;
voir [Architecture](ARCHITECTURE.md#politique-de-sécurité-http). Ne pas retirer
l'isolation de l'aperçu fidèle pour y forcer la lecture HLS.

### Balise sec_video dans le HTML PHP

Le bootstrap public monte uniquement ce composant global à partir du HTML
présent au démarrage, après le montage des racines Vue éventuelles :

```html
<sec_video src="cours:video/playlist.m3u8" space-id="7"></sec_video>
```

`src` et `space-id` sont les seuls attributs transmis comme props. La source doit
être non vide, sans espace ni caractère de contrôle et limitée à 2048 caractères ;
le Space doit être un entier décimal strictement positif. Le serveur reste
responsable de la validation complète de la source, du Space et des fichiers.
Dans une template PHP, échapper `src` avec `HtmlTools::escape()` ou utiliser
`SecureVideoHelper::render()`.

Les directives (`v-bind`, `:src`, `@click`, etc.) ne sont pas interprétées ; les
interpolations `{{ ... }}` restent littérales. Aucun autre attribut ni slot HTML
n'est transmis au lecteur. Les autres composants globaux (`tabs`, `ai_chat`, etc.)
ne sont pas activés par ce montage. Le HTML ordinaire doit toujours être nettoyé
et échappé selon son origine : ce mécanisme ne supprime pas les attributs HTML
natifs dangereux. Il n'observe pas les ajouts de HTML après le démarrage ; les
composants écrits dans les fichiers Vue continuent d'utiliser le registre normal.
Les lecteurs sont libérés avant le démontage d'une racine applicative.
