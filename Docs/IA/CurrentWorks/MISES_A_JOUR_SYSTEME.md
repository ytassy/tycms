# Conception des mises à jour du système Tycms

## Statut et périmètre

Cette note conserve la conception discutée avec Yann pour une réalisation
progressive. Le bootstrap de release `vdev` et la fabrication du ZIP autonome
sont en place, ainsi que le serveur PHP de distribution et la commande de
publication locale signée. Le serveur de distribution est déployé sur le
mutualisé ; le modèle de configuration fournit les serveurs ordonnés et la
clé publique de production. Core propose une consultation manuelle réservée
à ROOT, avec signatures, compatibilités, notes et simulation de source en
release `dev`, qui propose la dernière version publiée pour tester sans bloquer
sur la source, ainsi qu’un téléchargement contrôlé dans un cache privé et une préparation
ROOT : extraction sécurisée, installation du moteur et de ses assets et lien
secret associé à une opération persistante. Le moteur autonome est en reconstruction :
préparation, maintenance, sauvegarde facultative, migrations, activation et
récupération sont disponibles comme actions distinctes. Les décisions retenues sont
distinguées des propositions qui restent à préciser ; cette note ne remplace
pas les contrats actuels de [l'architecture](../../ARCHITECTURE.md).

Une installation de production issue de `tycms-1_0_0.zip` est déployée sur
[tests.yanntassy.fr](https://tests.yanntassy.fr/) ; Yann en confirme le bon
fonctionnement. Sa base et ses données doivent être préservées. Le chantier
porte désormais aussi sur la mise à jour d'une installation existante, avec
compatibilité et migrations depuis l'état livré en `1.0.0`, sans supposer une
base vierge. Les essais de mise à jour et de restauration restent isolés de
cette production, conformément à l'[architecture](../../ARCHITECTURE.md#persistance-et-préparation-de-la-production).

Deux modes de production sont envisagés :

- **Priorité : hébergement mutualisé sans SSH**, avec une mise à jour pilotée
  depuis une interface web, sur le principe de Joomla ou WordPress.
- **Plus tard : image Docker de production**, remplacée par l'infrastructure.
  La mise à jour du code à l'intérieur du conteneur n'est pas le chantier
  prioritaire.

La première version met à jour uniquement le système : `framework`,
`core_extensions`, `vendor`, les fichiers Composer et les assets Core versionnés
sous `public/assets_core_v<version_string>`.
Les plugins, modules et templates installés dans `extensions` sont préservés.
Les configurations, secrets, médias et autres données locales sont conservés.
La compatibilité des extensions locales avec le système cible reste à examiner.

## Décisions retenues

### Distribution et préparation des versions

Le serveur de mise à jour fournit les versions accessibles depuis une version
source, leurs prérequis, les paquets et les informations permettant d'en
vérifier l'authenticité. Le site consulte ce serveur ; celui-ci n'a pas besoin
d'un accès entrant à l'installation.

Les paquets sont préparés avant publication avec leurs dépendances de
production issues du `composer.lock` et leurs assets compilés. L'hébergement
cible n'a pas besoin d'exécuter Composer, Node ou une commande SSH.

Le manifeste signé de schéma 1 décrit la cible, la liste explicite des versions
sources, les bornes PHP et l’identité du ZIP. Le [protocole de distribution](../../../update_server/README.md#protocole-http-schéma-1)
est implémenté ; le contrat d’exécution et de compatibilité du futur moteur
sera complété lors de sa réalisation. Un simple HTTPS ou une empreinte reçue
avec le paquet ne remplace pas une signature vérifiée avec une clé publique
déjà reconnue par l’installation.

### Authentification de publication

Le serveur de distribution sera hébergé sur un mutualisé PHP. La publication
explicite depuis le build utilisera un token Bearer en HTTPS. Les empreintes
SHA-256 des tokens autorisés seront stockées dans
`update_server/configuration.php`, hors de `public/` et ignoré par Git ;
la liste vide n’autorise aucun envoi. La comparaison utilise `hash_equals()`.
Les tokens ne sont placés ni dans les URL ni dans les journaux applicatifs.

Le [guide du serveur](../../../update_server/README.md#tokens-de-publication)
décrit le script CLI `update_server/tools/token.php`, disponible pour générer
un token aléatoire de 256 bits et son empreinte, ou calculer l’empreinte d’un
token existant de même format. L’API de publication authentifiée est implémentée.
La signature locale est retenue : après fabrication, une étape séparée signe
le manifeste contenant l’empreinte du ZIP, puis transmet le ZIP et le manifeste
signé à l’API authentifiée. La clé privée reste sur la machine de publication,
hors du dépôt et inaccessible aux étapes Composer/npm ; elle n’est transmise
ni au serveur ni aux installations. Le serveur et les installations utilisent
une clé publique reconnue pour vérifier les signatures. Le token de publication
ne remplace pas cette vérification.

Le manifeste utilise une signature Ed25519 détachée via Sodium, avec payload
JSON encodé en base64 ; la vérification porte sur ses octets exacts.
La commande locale et l’API multipart sont décrites dans les guides du
[build](../../../build/README.md#publication-séparée) et du serveur.
La base `configuration.base.php` embarque la clé publique de production
dans `update_trusted_public_keys` et les URL ordonnées dans `update_servers`.
Pour l’installation `1.0.0` existante, l’héritage de `ConfigurationBase` est à
mettre en place manuellement dans `configuration.php`, en conservant les secrets
et les réglages locaux selon le [contrat de configuration](../../ARCHITECTURE.md#configuration-locale-et-valeurs-par-défaut).
Le client essaie le serveur suivant en cas de panne réseau ou d'erreur
HTTP, mais arrête avec une erreur si la signature ou l'intégrité est invalide.
Une réponse valide sans mise à jour compatible termine la recherche.
Le [contrat de configuration et de secours](../../../update_server/README.md#configuration-des-installations-clientes)
décrit ces règles. Le [guide de consultation Core](../../SYSTEM_UPDATES.md)
décrit l'écran, les limites réseau et la simulation de version source en DEV.
Le ZIP peut être téléchargé et contrôlé dans `var/updates/packages/`, avec
conservation et réutilisation après vérification. L’action distincte « Préparer la mise à jour » extrait le paquet, installe le
moteur et ses assets et fournit le lien secret, selon le
[contrat de préparation](../../SYSTEM_UPDATES.md#préparation-du-paquet-et-du-lien-secret).
La rotation coordonnée
des clés reste à définir. Une clé reçue avec un paquet ne devient jamais
automatiquement une clé de confiance.

### Fabrication des paquets

Les chemins suivants sont relatifs à la racine du dépôt, hors de `src` :

| Emplacement | Rôle |
| --- | --- |
| `version.json` | Version, sources compatibles et bornes PHP de la release à fabriquer. |
| `version.md` | Nouveautés et changements utiles aux utilisateurs finaux, sans détails techniques. |
| `build/` | Scripts PHP de préparation, d'archivage et, ensuite, de publication. |
| `build/temp/` | Copie de travail jetable ; ignorée par Git. |
| `build/dist/` | Archives et autres résultats générés ; ignorés par Git. |
| `docker/build/` | Définition du conteneur de fabrication : PHP, Composer, Node et outils d'archivage avec versions fixées. |
| `update_server/` | Serveur PHP autonome de publication, catalogue et téléchargement, déployable indépendamment de l’application. |
| `docker/prod/` | Éventuelle fabrication des images Docker de production, processus distinct et reporté. |

Le [guide de fabrication](../../../build/README.md) décrit la commande
`./build/build.sh`, ses prérequis et ses contrôles. Les scripts PHP
et le conteneur sont implémentés, ainsi que `update_server/` et la publication
PHP locale. Le serveur de distribution est déployé sur le mutualisé ;
`docker/prod/` reste reporté.

Le fichier racine `version.json` contient par exemple :

```json
{
  "version": "1.0.0",
  "version_string": "1_0_0",
  "source_versions": "*",
  "php_min": "8.4.1",
  "php_max_exclusive": "8.6.0"
}
```

Le build valide les cinq champs avant de construire un chemin ou d’installer
les dépendances. La première installation reste toujours permise ; `"*"`
couvre en plus toute version antérieure, `[]` réserve la release à une
première installation, et une liste explicite autorise les versions listées.
Les sources d’une liste sont uniques et antérieures à la cible. Les bornes PHP
sont respectivement incluse et exclue. `version.md` est obligatoire et limité à 256 Kio. Le format des préversions reste à définir avant
de les accepter. La version de production est inscrite dans le `version.php`
de la copie de travail ; le `version.php` de développement reste inchangé.

Le script PHP prépare d’abord les sources dans un conteneur sans réseau,
avec le dépôt en lecture seule. Un second conteneur compile et assemble
uniquement cette copie ; il ne monte ni le dépôt ni ses secrets. L'orchestration suit les étapes suivantes :

1. Préparer une copie propre sous `build/temp/`, en conservant les chemins
   relatifs nécessaires aux outils actuels. Sélectionner explicitement les
   fichiers utiles parmi ceux suivis par Git, dans leur état courant : aucun
   secret, configuration locale, média, cache ou dépendance existante ne doit
   entrer dans cette copie. Les liens symboliques sont refusés. Chaque build
   utilise un dossier neuf sous `build/temp/`, conservé pour inspection.
2. Adapter uniquement cette copie à la version cible. Préparer les assets
   statiques sous le nom versionné, sans réutiliser d'anciens bundles compilés.
3. Installer les dépendances PHP depuis `composer.lock` sans dépendances de
   développement, sans scripts ni plugins Composer, puis produire un autoload
   optimisé conservant la résolution PSR-4 des extensions locales. Ne pas
   activer une classmap autoritaire. Appliquer ensuite les sélections
   `extra.google/apiclient-services` et `extra.aws/aws-sdk-php` en supprimant
   les services non retenus, puis régénérer l'autoload, sans exécuter de script
   Composer.
4. Installer les dépendances Node depuis les fichiers de verrouillage de la
   RIA et de Mermaid, puis exécuter leurs builds de production. Les outils de
   compilation placés dans les dépendances de développement sont nécessaires
   pendant cette étape ; `node_modules` n'entre pas dans le paquet.
5. Assembler la release et ses assets publics, puis vérifier la version, les
   dépendances, les fichiers nécessaires et l'absence de fichiers locaux.
6. Produire les trois fichiers côte à côte dans `build/dist/` :
   `tycms-1_0_0.zip`, `tycms-1_0_0.build.json` et `tycms-1_0_0.md` pour cet
   exemple. Les métadonnées et notes sont capturées pendant la préparation,
   avant compilation ; le JSON final contient les tailles et SHA-256 du ZIP
   et du Markdown. Le JSON et les notes restent hors du ZIP système.

La fabrication et la publication utilisent deux commandes distinctes lancées
par Yann : `./docker/build/build.sh` fabrique le ZIP avec Docker ; une commande
PHP locale séparée (`build/publish.php`) signe et envoie un ZIP
existant vers l’API authentifiée. L’envoi ne reconstruit jamais le paquet et
ne lance pas Docker. La clé privée et le token sont lus depuis une
configuration locale privée, hors du dépôt ; ils ne font partie ni de la
copie de travail ni de l’archive. La commande utilise uniquement le JSON et
le Markdown situés à côté du ZIP ; elle ne relit pas les fichiers racines,
qui peuvent déjà décrire la version suivante. Il n’existe plus de paramètres
de compatibilité dans la commande ou la configuration privée. Les trois
fichiers doivent être conservés ensemble ; refaire le build pour un ancien
ZIP sans ses fichiers compagnons. Les notes et leurs empreintes sont aussi
incluses dans le manifeste signé et stockées avec la release.
Le serveur refuse tout remplacement d’une version publiée, vérifie le ZIP
sans extraction, puis rend visibles paquet, manifeste et notes par renommage atomique
sous verrou. Le détail des contrôles est dans le guide du serveur.

Les compilations précèdent l’assemblage final de `releases/vdev` sous
`releases/v<version_string>`, afin de respecter le chemin actuellement attendu
par le lecteur Node. Le code de développement et ses assets ne sont pas modifiés.
Aucun nettoyage automatique des copies de travail n’est exécuté.

La signature sera exécutée localement dans une étape distincte des étapes
Composer/npm ; aucun secret de signature n’est inclus dans les paquets ou
transmis au serveur de distribution. Le format et le protocole sont décrits
dans le guide du serveur. Le serveur prévoit une racine HTTP `public/` ; sa configuration et
ses outils d’administration restent hors de cette racine.

### Contenu du ZIP système

Le paquet est une distribution autonome pour une première installation : il
contient la release, ses assets, le bootstrap public, l'updater et tous les
fichiers et dossiers initiaux nécessaires, notamment `var/`. Les dépendances
et les assets sont prêts à l'emploi ; aucune compilation n'est requise sur
l'hébergement cible. Seul `configuration.php` reste à créer manuellement.

Lors d'une première installation, l'administrateur copie
`configuration.sample.php` vers `releases/v<version_string>/configuration.php`.
Cet exemple hérite de `ConfigurationBase` et documente toutes les options en
commentaire. Avant le premier démarrage, il décommente et renseigne notamment
la connexion à la base, un secret long et aléatoire et les identifiants du
premier administrateur, sans conserver les valeurs d'exemple. La racine HTTP
reste `public/`. Une mini-interface de configuration au premier lancement
est prévue ultérieurement ; elle n'est pas réalisée à cette étape.

La préparation d'une mise à jour sélectionne les fichiers à installer : elle
ne copie jamais `public/index.php` ni `public/.htaccess` et ne remplace pas
l'état `var/` ni les données locales du site par ceux de l'archive.
L'extraction d'une mise à jour n'active pas la version cible.

```text
tycms-1_0_0.zip
├── releases/
│   └── v1_0_0/
│       ├── index.php
│       ├── version.php
│       ├── configuration.base.php
│       ├── configuration.sample.php
│       ├── composer.json
│       ├── composer.lock
│       ├── framework/
│       ├── core_extensions/       # Sans l’extension DEV Test
│       └── vendor/                # Dépendances de production et autoload
├── extensions/
│   ├── plugins/                  # Vide
│   ├── modules/                  # Vide
│   └── templates/                # Vide
├── var/
│   ├── releases-states.json      # État initial généré pour v1_0_0
│   └── last_update_data.json     # Objet vide, aucune opération passée
├── logs/                         # Vide, chemin par défaut de configuration
├── tmp/                          # Vide, chemin par défaut de configuration
├── updater/                      # Socle du moteur autonome
└── public/
    ├── index.php                 # Inclus, jamais copié lors d'une MAJ
    ├── .htaccess                 # Inclus, jamais copié lors d'une MAJ
    ├── assets/                   # Structure initiale, sans données locales
    │   └── fonts/                # Vide
    ├── medias/                   # Vide
    ├── assets_updater/           # Interface Vue et dépendances locales
    └── assets_core_v1_0_0/
        ├── plg_core/
        │   ├── css/               # CSS compilé
        │   ├── js/                # Entrée et tous les chunks Vite
        │   ├── fonts/
        │   ├── videojs/
        │   ├── page-canvas-size.js
        │   └── mermaid/           # Runtime, preview, bundles, chunks, styles,
        │                         # .htaccess et licences nécessaires
        └── tpl_main/              # Images, scripts, polices/icônes et styles
```

Les fichiers de protection et les licences nécessaires à l'exécution et à la
redistribution sont conservés. Les listes ci-dessus décrivent les familles
attendues ; l'inventaire exact sera vérifié lors de l'implémentation du build.

Les dossiers vides sont inclus comme entrées de répertoire du ZIP, sans
fichiers `.gitkeep`. Le build génère `var/releases-states.json` avec
`schema_version: 1`, `active_release: "v1_0_0"` pour cet exemple,
`available_update: null` et `active_operation: null`, ainsi que
`var/last_update_data.json` contenant `{}`. Il ne copie pas les états du site
de développement. Les autres fichiers initiaux nécessaires sont inventoriés
lors de l'implémentation du build.

Sont exclus de la distribution :

- `configuration.php`, secrets et identifiants ; l'updater prépare la
  configuration de la cible depuis celle du site existant.
- Les extensions installées et les données locales de `var/`, `public/assets/`,
  `public/medias/`, `logs/` et `tmp/` : seuls les structures vides et les
  fichiers initiaux nécessaires sont livrés, sans caches, sessions, journaux,
  sauvegardes ni opérations de mise à jour existantes.
- Le serveur `update_server/`, déployé séparément. Le moteur `updater/` et
  ses futurs assets sont en revanche inclus dans le ZIP système.
- Sources Vue/SCSS de la RIA (les composants `.vue` de l’updater restent livrés), outils de build, Docker, documentation du dépôt, tests,
  PHPUnit, fichiers Git, caches et dépendances de développement.
- L'extension Core `Test`, dont l'exclusion est validée ; elle est chargée
  uniquement en mode DEV dans `Application`. La configuration de production
  doit conserver `dev = false`.

Les fichiers racines `version.json` et `version.md` servent d’entrées au
build ; ils ne sont pas déployés dans l’installation système. La version livrée reste portée par `version.php`.
Le manifeste signé et l’empreinte sont distribués par l’API du serveur selon
son contrat de schéma 1 ; ils ne sont pas ajoutés au ZIP.

Le nom `tycms-1_0_0.zip` et l'exclusion de l'extension Core `Test` sont
retenus. La configuration manuelle et l'état initial ci-dessus définissent
la première installation à cette étape ; son assistant de configuration
reste à réaliser ultérieurement.

### Organisation de production

La production peut avoir une arborescence différente du dépôt de développement.
Chaque version est préparée à côté de la version active, sans écraser son code.
L'arborescence proposée par Yann sert de base :

```text
site/
├── releases/
│   ├── v1_0_0/
│   │   ├── framework/
│   │   ├── core_extensions/
│   │   ├── vendor/
│   │   ├── composer.json
│   │   ├── composer.lock
│   │   ├── index.php
│   │   └── configuration.php
│   └── v1_0_1/
│       └── ...                     # Dont sa configuration préparée
├── extensions/
├── updater/
├── var/
│   ├── releases-states.json        # État global, schéma 1
│   ├── last_update_data.json       # Détails de la dernière opération (à réaliser)
│   └── updates/
└── public/
    ├── index.php
    ├── assets_core_v1_0_0/
    ├── assets_core_v1_0_1/
    ├── assets_updater/
    ├── assets/
    ├── medias/
    └── update_<secret>.php
```

Seul `public` est la racine HTTP. Le point d'entrée public choisit la version
active, puis appelle son `index.php`, qui charge son autoload et sa
configuration. Le choix du code et de ses assets repose sur la même version,
lue une fois pour la requête. Un identifiant de version ne doit pas devenir
un chemin d'inclusion arbitraire fourni par le navigateur.

La racine de l'installation, celle de la release, les extensions locales et
l'URL des assets système doivent être distinguées.

La release `vdev` définit `ASSETS_CORE_PATH` dans son `index.php`, après le
chargement de `version.php` et avant toute page de maintenance. `APP_VERSION`
est commun au framework et aux extensions Core ; `APP_VERSION_STRING` fournit
le suffixe d’URL (`dev` pour `/assets_core_vdev`). Le lecteur Node commun
`src/tools/core-assets.mjs` extrait cette définition littérale sans exécuter PHP ;
les formats invalides ou ambigus arrêtent la compilation. Vite et le build
Mermaid en déduisent leurs dossiers de sortie. Le JavaScript utilise la base
Vite et le SCSS reçoit la même base pour les polices. La copie des ressources
statiques et l'assemblage du paquet de production sont réalisés par le build
isolé. Les anciens assets restent disponibles pour les pages déjà ouvertes ; leur durée de conservation reste à fixer.

### Configuration propre à chaque version

Chaque release possède son **propre `configuration.php`**. Pendant la
préparation, l'updater copie la configuration de départ à l'identique. Les
nouvelles propriétés reçoivent leurs valeurs par défaut à l'instanciation de
`Application`.
Il ne la remplace pas par une configuration vierge et ne régénère pas
`Configuration.secret`.

L'Updater ne change que la valeur littérale de `Configuration::$offline`
pour la maintenance. Une forme non reconnue bloque cette étape. Les valeurs
existantes issues de `__DIR__` ou `__FILE__` sont conservées telles quelles ;
une évolution future de ces chemins exige un contrat distinct.

### Moteur autonome téléchargé à chaque opération

La version courante télécharge et authentifie le ZIP système cible avant
d'en préparer les fichiers. Elle installe complètement l'updater et ses assets
issus de ce ZIP avant de créer son lien d'accès. Ce moteur est une application
autonome PHP, utilisable pendant la maintenance ou si le site ne démarre
plus. Il doit rester compatible avec le PHP disponible sur le site de départ.

Il n'a donc pas besoin d'un mécanisme distinct d'auto-mise à jour : une
opération suivante pourra télécharger un autre moteur. Pendant une opération,
le moteur utilisé reste figé, même après activation du nouveau système.

`updater/` et `public/assets_updater/` sont les emplacements retenus. Leur
installation doit être préparée sans rendre exécutable une copie partielle.
Dans le dépôt, `src/updater/index.php` valide le lien et fournit le socle HTTP,
sans charger l'application principale. `src/public/assets_updater/` contient
les assets locaux de l'interface. La préparation crée un lien
secret public ; une exception Apache ciblée autorise son nom de 64 caractères
hexadécimaux. Un verrou de préparation et `active_operation` empêchent le
remplacement d’un moteur engagé. Si `Configuration.dev` est vrai, les copies
vont dans `updater_test/` et `public/assets_updater_test/`, ignorés par Git,
mais le lien inclut toujours `updater/index.php` pour développer le moteur local.
Le [guide système](../../SYSTEM_UPDATES.md#exécution-depuis-le-lien-secret)
décrit les transitions et la récupération.

### Accès par lien secret, sans dépendance aux ACL du site

Dans l'application principale, la préparation et le nettoyage sont réservés
à **ROOT sur l'espace racine**, contrôle déjà exposé par `coreAclHelper->isRoot()`.

Une fois lancé, **l'updater ne vérifie pas les droits ROOT, les comptes ni les
sessions de Tycms**. Ce choix explicite évite de dépendre d'un code ou
d'un schéma de base qui peut changer pendant la mise à jour.

Le fichier `public/update_<secret>.php` donne accès à l'opération par possession
du lien. Il n'y a pas de nouvelle connexion utilisateur ni de contrôle ACL
dans l'updater. Le nom constitue une clé temporaire, à générer avec un aléa
cryptographique, par exemple `bin2hex(random_bytes(32))`.

Les limites retenues pour ce modèle sont les suivantes :

- Le lien concerne une opération déterminée côté serveur : version source,
  version cible et paquets authentifiés. Il ne permet pas de choisir librement
  un serveur de téléchargement, un fichier PHP ou un chemin disque.
- Le lien doit rester secret, notamment vis-à-vis des ressources externes et
  des messages d'erreur. Sa possession donne accès aux commandes de
  l'opération, y compris maintenance et restauration si elles sont disponibles.
- L'accès est désactivé à la clôture ou à l'abandon de l'opération, même si le
  fichier attend encore sa suppression physique. Une interruption n'est pas
  assimilée à un abandon : elle doit permettre une reprise.

Ce choix ne rend pas publics les sauvegardes, les secrets ou les fichiers de
travail. Les protections des actions HTTP et les modalités précises de
désactivation seront traitées dans l'updater autonome, sans réintroduire une
dépendance aux sessions ou aux tables d'ACL de Tycms.

## Parcours fonctionnel de référence

### Depuis la version active

1. Un ROOT ouvre l'interface de mise à jour de Tycms.
2. L'interface présente la prochaine version compatible proposée par le serveur.
3. L'utilisateur choisit la cible.
4. L'application télécharge et authentifie le ZIP système cible, prépare les
   fichiers sans remplacer le bootstrap public, puis installe complètement
   l'updater et ses assets. Elle conserve le paquet et son identité vérifiée
   pour l'opération ; l’organisation de cette préparation est décrite dans le
   [guide système](../../SYSTEM_UPDATES.md#préparation-du-paquet-et-du-lien-secret).
5. Elle crée l'opération et `public/update_<secret>.php`, puis fournit son lien.
6. La partie pilotée par l'ancienne application s'arrête ici.

### Depuis l'updater autonome

1. L'utilisateur ouvre le lien et retrouve l'interface propre à l'updater.
2. Le moteur reprend le paquet authentifié préparé par la version courante,
   vérifie son intégrité et complète la préparation de `releases/v1_0_1`,
   de ses assets publics et de sa configuration, sans modifier son propre
   moteur pendant l'opération.
3. L'utilisateur pilote les étapes depuis l'interface. Chaque étape, y compris
   copie, sauvegarde et restauration, tient dans une requête API ; aucun découpage
   ni mécanisme spécial pour les grosses bases ou les timeouts n'est prévu.
4. Le site est mis en maintenance. La sauvegarde interne de la base est facultative
   mais vivement conseillée ; l'utilisateur peut avoir sa propre sauvegarde.
   L'interface indique clairement la responsabilité et les conséquences de son omission.
5. L'état enregistre le lancement des migrations avant leur exécution. Après leur
   réussite et le contrôle de démarrage, la release cible est activée puis remise en ligne.

L'état persistant permet de retrouver l'opération. Une erreur HTTP ne prouve pas
que le traitement serveur s'est arrêté : aucune migration lancée ne doit être
relancée automatiquement. Les limites de durée et de taille restent à la charge
utilisateur ; le moteur affiche les erreurs qu'il peut constater.

### Sauvegarde, migrations et récupération

Une bibliothèque PHP autonome, propre à Tycms, découvre dynamiquement toute
la base configurée, y compris les tables des extensions, et sauvegarde tables,
données, vues, déclencheurs, procédures et fonctions stockées et événements.
Elle restaure son propre format, sans options fonctionnelles ni import général
arbitraire. Les clés étrangères et CHECK sont ajoutées en fin de restauration ;
les clés primaires et uniques restent dans les créations de tables. Une sauvegarde interne incomplète n'est jamais présentée comme valide.
Les droits et les écritures concurrentes doivent être pris en compte. Le contrat
et les limites sont décrits dans le [guide MariaDB](../../MARIADB_BACKUP.md).

Sur une base existante, le démarrage normal ne lance plus de migration.
`Application::runMigrationsOrThrow()` expose l'exécution explicite sans session
ni routes ; la première installation reste automatique si la table `migrations`
est absente. Une première installation interrompue demandera la remise à zéro
de la base ; son futur script d'installation est hors du présent périmètre.
L'exécution du code cible doit éviter de charger dans la même
requête les classes ou dépendances incompatibles de l'ancien système et
du nouveau, ou de l'updater et du système cible.

En cas d'échec, le site reste en maintenance. Si une sauvegarde interne valide
existe, l'utilisateur peut déclencher sa restauration. Sinon il restaure lui-même
sa sauvegarde externe, puis confirme dans l'updater le retour à l'ancienne release.
La confirmation n'établit pas techniquement que la restauration externe a réussi.
Revenir au seul ancien code ne suffit pas après une migration SQL. Une interruption HTTP ne prouve pas l'arrêt du traitement.

### Nettoyage depuis Tycms

Avant l’installation effective, le bouton ROOT « Réinitialiser la mise à jour »
est disponible en DEV et production : il abandonne la préparation, révoque les
liens secrets, supprime les paquets, fichiers préparés et dossiers d’opération
avec leurs sauvegardes locales, et remet à zéro les états d’opération sans
changer la release active. Les sources de l’updater
sont conservées en DEV. Les verrous, états autorisés et limites de ce reset
sont décrits dans le [guide système](../../SYSTEM_UPDATES.md#réinitialisation-depuis-core).
Après activation réussie, le nettoyage ROOT supprime le paquet, le lien, le
moteur, le dossier d’opération avec sa sauvegarde locale et les versions
numériques inactives avec leurs assets. La release active et les dossiers de
développement sont conservés. Le reset retire aussi les restes d’anciennes
opérations non nettoyées sous `var/updates`. Après annulation ou restauration,
l'updater nettoie et libère l'opération pour permettre une nouvelle tentative.

Après activation, l'écran de mise à jour de Tycms propose ce nettoyage.
Un rappel global rouge réservé aux ROOT reste à réaliser. La clôture révoque l'accès temporaire.

Les fichiers de l'opération et les versions numériques inactives doivent être
identifiés précisément avant leur suppression.

## Points à préciser lors des prochaines étapes

| Sujet | Base proposée / choix restant |
| --- | --- |
| État des releases | `var/releases-states.json` porte l'opération et la release active ; `var/last_update_data.json` porte les étapes du moteur. Le lien de 1.0.0 impose `prepared` jusqu'à la clôture. Un historique multi-opérations reste à définir. |
| Maintenance | La propriété littérale `offline` est basculée dans la source et la cible. Les requêtes déjà engagées doivent terminer leurs écritures avant la sauvegarde. |
| Sauvegarde | Facultative et vivement conseillée ; bibliothèque propre à Tycms, sans options. Conservation à définir. |
| Restauration | Interne si une sauvegarde existe ; sinon restauration externe puis confirmation du retour à la source. Réalisée et vérifiée sur une base isolée. |
| Isolation et reprise | Une requête par étape, sous verrou ; aucune relance aveugle des migrations après interruption. |
| Distribution | Serveur PHP déployé, publication locale et manifestes Ed25519 implémentés. Clé publique initiale et serveurs ordonnés fournis dans le modèle, à reporter manuellement dans les installations existantes. Consultation Core manuelle réservée à ROOT avec signatures, compatibilités, notes et simulation en DEV ; téléchargement en flux et contrôle du ZIP avec cache privé réutilisable, puis extraction sécurisée et installation du moteur/assets par une action distincte. Nettoyage du cache par reset ROOT disponible ; rotation des clés et compatibilité du moteur à traiter. |
| Configuration | Copie littérale ; seule la propriété `offline` est basculée pour la maintenance. La gestion des valeurs par défaut nouvelles dans `Application` est prévue séparément. |
| HTTP de l'updater | Lien minimal, validation dans le moteur ; GET pour l'interface et POST pour les API protégées. CSP adaptée à SFC-loader, validation visuelle à effectuer. |
| Première installation et déploiement | Build PHP isolé sous `build/temp/`, version racine dans `version.json` et conteneur sous `docker/build/` retenus. ZIP autonome avec bootstrap public, updater, structures locales vides et état initial `var/` généré pour la version livrée. Première installation avec création manuelle de `configuration.php` depuis le modèle ; mini-interface de configuration reportée. Lors d'une MAJ, ne remplacer ni le bootstrap public ni l'état et les données locales. Fabrication implémentée et ZIP déployé en production, avec fonctionnement confirmé par Yann ; les évolutions suivantes doivent préserver cette installation et sa base. |
| Conservation | Définir la durée de conservation des anciens assets, des moteurs, des releases et des sauvegardes. |

Ces questions sont à traiter au moment utile avec Yann ; elles ne justifient
pas de remplacer les choix déjà retenus par des mécanismes supplémentaires.

## Adaptations identifiées dans le code actuel

- [Point d'entrée](../../../src/public/index.php) : il lit désormais
  l’état JSON et délègue au `index.php` de la release après validation du chemin.
- [Application](../../../src/releases/vdev/framework/Application.php) : `APP_ROOT` désigne la
  racine d’installation et les extensions locales sont recherchées sous
  `APP_ROOT/extensions`. Certains chemins persistants restent à adapter. Les
  migrations ne sont exécutées au démarrage que si la table `migrations` est
  absente ; l'updater devra appeler l'API explicite de la release cible.
- [Migrations](../../../src/releases/vdev/framework/DB/Migrations/Migration.php) : le journal
  enregistre les réussites après exécution. La reprise après une modification
  SQL non encore enregistrée doit être conçue. Le verrou doit être partagé
  entre versions et processus utilisant la même base.
- [Composer](../../../src/releases/vdev/composer.json) : l’autoload de `vdev`
  vise les extensions partagées sous `src/extensions` ; la fabrication du paquet
  de production sans Composer sur le mutualisé est assurée par le build isolé.
- [Vite](../../../src/ria/vite.config.ts) et
  [MainTemplate](../../../src/releases/vdev/core_extensions/MainTemplate/base.php) : les
  chemins de build et les URL frontend dérivent de `APP_VERSION_STRING` ;
  les montages Docker de développement restent fixés sur `vdev`. Les
  URL déjà enregistrées, notamment les images système par défaut, demandent
  également une stratégie.
- [Configuration type](../../../src/releases/vdev/configuration.base.php) : les chemins
  de médias, polices, logs, temporaires et verrous utilisent des chemins locaux
  qu'il faudra adapter au déploiement versionné.
- [Règles Apache](../../../src/public/.htaccess) : elles autorisent
  `index.php` et les scripts racines `update_[a-f0-9]{64}.php`. Reporter cette
  exception sur les installations existantes sans remplacer leurs règles locales.
- [ACL](../../../src/releases/vdev/core_extensions/Core/Helper/Acl/AclHelper.php) : `isRoot()`
  correspond déjà à la permission ROOT sur l'espace racine.

## Lots de réalisation

1. **Socle** : point d'entrée réduit au `require_once`, validation du lien dans
   l'updater, GET d'interface, API POST protégée, état et verrou ; build des assets.
   Le socle et les actions des lots réalisés sont en place.
2. **Interface** : Vue et SFC-loader sans compilation, Bootstrap et dépendances
   locales, état, étapes et erreurs ; CSP adaptée à la compilation dynamique.
   Interface disponible ; validation visuelle sur le navigateur à effectuer.
3. **Bibliothèque MariaDB** : découverte dynamique de toute la base et sauvegarde /
   restauration des objets et données, sans options ; réalisée et vérifiée sur base
   isolée et intégrée au parcours de mise à jour.
4. **Préparation et maintenance** : copie en une requête, configuration,
   maintenance dans une requête distincte et annulation avant modification de
   la base ; réalisées. Les étapes suivantes restent indépendantes.
5. **Sauvegarde et migrations** : sauvegarde optionnelle vivement conseillée,
   avertissement explicite et migrations en une requête avec état préalable ;
   réalisées.
6. **Activation et récupération** : activation, remise en ligne, restauration
   interne ou restauration externe par l'utilisateur suivie d'une confirmation
   de retour à la source ; clôture et nettoyage : réalisés.
7. **Vérification et documentation** : transitions et échecs représentatifs sur
   une installation isolée, guides actualisés et essais visuels simples avec Yann.

Chaque étape métier tient dans une requête API. Pas de découpage ni de solution
spéciale pour les timeouts ou les grosses bases ; l'utilisateur en assume la limite.
Les essais de mise à jour et de restauration ne visent jamais la production.
