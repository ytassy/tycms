# Environnement Docker de développement

Prérequis : Docker Engine avec le plugin Docker Compose v2. Les scripts peuvent
être lancés depuis root, avec sudo, ou depuis un compte ayant accès à Docker.
Si un processus d’IDE ou d’agent est antérieur à l’ajout au groupe Docker,
relancer la session ou utiliser `newgrp docker -c '<commande>'`. Sur Ubuntu
26.04, `newgrp` est fourni par le paquet `util-linux-extra`.
Les scripts récupèrent l'UID et le GID de l'utilisateur hôte ; avec `sudo`, ils
utilisent `SUDO_UID` et `SUDO_GID`. Les processus Apache/PHP, Composer et Node
emploient cette identité pour écrire dans les dossiers partagés. Apache démarre
en root puis réduit les droits de ses processus de requête. Un fichier privé
créé en `0600` reste ainsi accessible au même utilisateur depuis l'hôte.
Le lancement directement depuis root exige de définir explicitement les deux
variables `TYCMS_DEV_UID` et `TYCMS_DEV_GID` non nulles.

L'image Apache installe les dépendances Composer au démarrage puis vérifie les
accès aux dossiers et fichiers nécessaires avec cette identité. Un dossier monté
accessible mais possédé par un autre compte, par exemple `src/var/extensions`
créé par une commande lancée en root, arrête ce démarrage avec le chemin en
cause au lieu de faire échouer plus tard les tests ou les mises à jour ; corriger
alors le propriétaire en 0600/0700 de l'utilisateur de développement ou lancer
`start.sh` avec sudo. Les verrous de migration restent sous `tmp/migrations`. Les polices et la table
`font_installations` doivent persister ensemble ; voir `Docs/FONTS.md`. MariaDB
et Mailpit conservent leurs propres identités et volumes.

Ce fonctionnement vise Docker Engine sous Linux sans remappage des utilisateurs.
Avec Docker rootless, un remappage d'UID ou Docker Desktop, la correspondance des
identités et les droits des montages nécessitent une vérification propre à l'hôte.

Depuis la racine du dépôt :

```bash
./docker/dev/start.sh
./docker/dev/stop.sh
```

Pour appeler directement `docker compose` depuis `docker/dev`, charger d'abord
l'identité dans le terminal : `source ./identity.sh`. Les scripts `start.sh`,
`stop.sh`, `php.sh` et `npm.sh` le font eux-mêmes.

`start.sh` crée automatiquement `.env` et sa clé de chiffrement, construit
l’image Apache/PHP 8.4, puis démarre Vite dans un conteneur Node 24 par défaut.
Aucune installation locale de PHP, Composer, Node ou npm n’est nécessaire.
Les démarrages suivants réinstallent les dépendances depuis les lockfiles,
pour prendre en compte un `git pull`. Avec `APP_DEV=false`, les assets sont
compilés avant le démarrage du site.
Apache installe les dépendances Composer (y compris PHPUnit) et prépare ses
dossiers avant de servir le site. Le script attend MariaDB et la disponibilité
du port Apache, ainsi que la réponse du client Vite en mode dev, avant de terminer. Le premier lancement nécessite Internet.

| Service | Adresse par défaut |
| --- | --- |
| Application | http://localhost:8088 |
| phpMyAdmin | http://localhost:8081 |
| Adminer | http://localhost:8082 |
| Mailpit | http://localhost:8025 |
| Vite (mode dev) | http://localhost:3000/assets_core_vdev/plg_core/ |
| MariaDB depuis Ubuntu | 127.0.0.1:3307 |
| SMTP depuis Ubuntu | 127.0.0.1:1025 |

Dans phpMyAdmin/Adminer : serveur `mariadb`, base et utilisateur `tycms`,
mot de passe `tycms_dev` par défaut. Le compte root utilise `root_dev`.
Les ports et identifiants sont personnalisables dans `.env`, créé depuis
`.env.example`. Les identifiants MariaDB ne sont appliqués qu’à l’initialisation
d’un dossier de données vide ; changer `.env` ne modifie pas un compte existant.

Adminer utilise une image dérivée d’`adminer:6.2.0`, avec une version fixée pour
garder les mises à jour explicites. Son Dockerfile copie CodeMirror 5.65.20,
versionné dans `adminer/codemirror`, et le plugin d’édition JSON.
Le thème local `adminer/adminer.css` est monté en lecture seule dans le conteneur.
Ses modifications sont prises en compte au rechargement du navigateur.

Pour tes personnalisations, utiliser `adminer/style.css` et `adminer/script.js`.
Le CSS est chargé après le thème et CodeMirror ; le JavaScript est chargé sur
toutes les pages avec le nonce CSP d’Adminer et propose un bloc `DOMContentLoaded`.
Les deux fichiers sont montés en lecture seule : les modifier puis recharger la
page suffit, sans reconstruire l’image. Leur URL change avec la date de modification
pour éviter de conserver une ancienne version en cache.

### Édition du JSON dans Adminer

À l’ouverture du formulaire d’édition d’une ligne, les colonnes `text`,
`mediumtext` et `longtext` contenant un JSON valide utilisent CodeMirror :
coloration JSON et numéros de ligne. La hauteur s’adapte à tout le contenu,
sans défilement interne, y compris pendant la saisie.
Le document est automatiquement indenté
avec les fonctions JSON d’Adminer, qui préservent les grands nombres ; cette
mise en forme est envoyée avec le formulaire lors de l’enregistrement.
Le texte ordinaire, le JSON invalide et les valeurs SQL
`NULL` gardent le champ standard. La détection a lieu à l’ouverture du formulaire.

Le plugin `adminer/json-editor.php` utilise les hooks d’Adminer 6 et son nonce CSP.
CodeMirror est servi par le conteneur : le navigateur n’a pas besoin d’accéder
à Internet et aucun build frontend n’est nécessaire. Après modification du
plugin ou de son Dockerfile, reconstruire depuis `docker/dev` :

```bash
docker compose up -d --build --no-deps adminer
```

`start.sh` construit également cette image au démarrage.

### Connexion automatique aux outils SQL

Dans `.env`, `DB_ADMIN_AUTO_LOGIN=true` ouvre phpMyAdmin et Adminer directement
en `root`, avec `DB_ROOT_PASSWORD`. La valeur `false` (défaut si le paramètre
est absent) conserve les formulaires habituels. Les booléens sont insensibles
à la casse (`True` est accepté). Adminer préremplit son formulaire et le soumet
automatiquement avec le jeton de session valide ; JavaScript est nécessaire
pour cette soumission (sinon, cliquer sur Connexion). Le mot de passe root est
donc présent dans le formulaire HTML lorsque le mode est actif.
Adminer amorce sa session à l'ouverture d'une URL sans utilisateur ; les actions
et les connexions explicites restent traitées normalement. Une déconnexion
Adminer ramène donc à une URL qui reconnecte automatiquement en root.

Après modification, depuis `docker/dev` :

```bash
docker compose up -d --no-deps phpmyadmin adminer
```

Ce réglage donne l'accès root à toute personne pouvant ouvrir ces interfaces,
notamment si `DOCKER_HOST_IP=0.0.0.0` expose les ports au réseau.

## Configuration et données

Apache sert exclusivement `src/public` avec les modules rewrite et headers.
Le code source est monté depuis `src`. La configuration utilisée pour `vdev`
est directement `src/releases/vdev/configuration.php` : modifier ce fichier
pour les essais. Son constructeur reprend les paramètres MariaDB, le mode DEV
et la clé persistante depuis les variables Docker. Conserver cette clé dans
`.env` pour pouvoir relire les secrets chiffrés en base. Ce fichier est suivi
par Git : ne pas y inscrire de secrets réels. Il hérite des valeurs communes
de `configuration.base.php` et appelle le constructeur parent après les variables
Docker pour normaliser la configuration en mémoire. Le build exclut le fichier
local et fournit la base ainsi que `configuration.sample.php`, un exemple
commenté à copier pour la production. Voir le
[contrat de configuration](../../Docs/ARCHITECTURE.md#configuration-locale-et-valeurs-par-défaut).

L'ancienne copie `containers_datas/config/configuration.php`, si elle existe,
n'est plus utilisée ni supprimée. Reporter manuellement les éventuelles valeurs
personnalisées utiles dans la configuration de `vdev`. L'updater utilise ce
même fichier et peut modifier sa propriété `offline`.

Les données MariaDB, les messages Mailpit, les logs et les fichiers temporaires
sont stockés sous `containers_datas`, déjà ignoré par Git. Les médias restent
dans `src/public/medias` pour conserver les fichiers existants du projet.
`stop.sh` retire les conteneurs et le réseau, en conservant ces données.
Apache écrit ses propres journaux dans `docker compose logs apache`.

La base créée est vide : importer une sauvegarde avec phpMyAdmin ou Adminer
pour retrouver le site existant. Sans sauvegarde, le framework utilise son
parcours d’initialisation habituel et les comptes de configuration du fichier
Docker. Le domaine des Spaces importés doit correspondre au domaine local.

## E-mails et frontend

Dans les paramètres SMTP du Space : activer les e-mails, hôte `mailpit`,
port `1025`, authentification désactivée et chiffrement vide. L’autorisation
globale `mailonline` est déjà activée dans la configuration Docker. Mailpit
capture les messages ; consulter http://localhost:8025.

### Vite Core et une extension en développement

`APP_DEV=true` conserve PHP en développement. `DEV_CORE_VITE=true` (défaut)
lance son Vite sur 3000 ; `DEV_CORE_VITE=false` utilise le Core compilé,
préparé au démarrage avec le runtime DEV. Le port 3000 du Core reste fixe.

Une extension au plus peut avoir son propre serveur Vite :

```dotenv
APP_DEV=true
DEV_CORE_VITE=true
DEV_EXTENSION=plugins/Lms
DEV_EXTENSION_VITE_PORT=3001
```

Relancer `./docker/dev/start.sh` après un changement de sélection. Le script
valide le dossier et le lockfile avant de créer les montages, arrête les Vite
avant toute réinstallation, puis recrée les services concernés. Le service
`extension-vite` écoute en interne sur 3001 et publie le port choisi.
`DEV_EXTENSION=` désactive ce service. Les autres extensions chargées gardent
leurs fichiers compilés ; aucune recompilation du Core n’est nécessaire.

Ouvrir le site sur `http://localhost:<HTTP_PORT>`. MainTemplate génère l’Import
Map, les clients HMR et les balises de chargement de chaque frontend. Les
origines Vite déclarées sont autorisées par la CSP uniquement en DEV, avec
leurs WebSockets, CSS, images et polices. Les runtimes Vue/Pinia sont compilés
une fois et servis par PHP, afin que les deux Vite partagent la même instance.
Les changements d’API commune ou de registres peuvent exiger un rechargement
complet. Voir le [contrat des extensions](../../Docs/EXTENSIONS.md).

Vite autorise l’origine CORS `http://localhost:<HTTP_PORT>` automatiquement.
Pour ouvrir le site sur un autre domaine local (ou `127.0.0.1`), définir son
origine exacte dans `.env`, par exemple :

```dotenv
VITE_BACKEND_ORIGIN=http://tycms.fr:8088
```

Les deux Vite gardent des URL navigateur `localhost`. Le fonctionnement depuis
une autre machine ou une page HTTPS nécessiterait des origines adaptées ; ce
contrat de développement cible le navigateur local.

Construire une extension, sélectionnée ou non, depuis la racine du dépôt :

```bash
./docker/dev/extension-npm.sh plugins/Lms ci
./docker/dev/extension-npm.sh plugins/Lms run build
```

Le script utilise son Vite s’il tourne, sinon le service ponctuel
`extension-frontend`. Il refuse `ci`, `install`, `update` et `uninstall` sur
l’extension dont le Vite tourne : arrêter ce service pour changer les
dépendances. Pour une nouvelle extension sans lockfile, utiliser d’abord
`install --package-lock-only`. Chaque extension a ses propres dépendances
Linux sous `containers_datas/extensions/<type>/<Nom>/node_modules`, isolées
sans changer la sélection du `.env`. Le build publie sous
`src/public/assets/<identifiant>/build/`, avec son manifeste dans
`src/public/assets/<identifiant>/.vite/manifest.json`. Les médias statiques
à côté sont conservés.

### Mode compilé et volumes Node

Pour utiliser les assets compilés, mettre `APP_DEV=false` dans `.env` puis
relancer `start.sh`. Il arrête les deux Vite avant de construire les assets Core. Les extensions
doivent être compilées séparément ; leurs manifestes sont requis au rendu.
`stop.sh` arrête aussi les deux Vite, quel que soit le mode courant.

Les services `frontend` (build ponctuel) et `vite` utilisent la même image
`node:24-bookworm-slim`, définie une seule fois dans le Compose. Ils partagent :

| Dossier Ubuntu | Chemin dans les conteneurs Node | Usage |
| --- | --- | --- |
| `src/ria` | `/workspace/src/ria` | Sources et configurations Vite ; sorties dans les assets publics. |
| `src/tools` | `/workspace/src/tools` | Lecteur des chemins et configuration ESM commune, en lecture seule. |
| `src/releases/vdev/version.php` | `/workspace/src/releases/vdev/version.php` | Métadonnées de version en lecture seule ; aucun dossier de configuration PHP n'est monté dans Node. |
| `src/public/assets` | `/workspace/src/public/assets` | Assets hors extensions Core, notamment les polices téléchargées dans `fonts/`. |
| `src/public/assets_core_vdev` | `/workspace/src/public/assets_core_vdev` | Assets des extensions Core ; publication du build dans `plg_core/js` et `plg_core/css`, visible par Apache. |
| `docker/dev/containers_datas/node_modules` | `/workspace/src/ria/node_modules` | Dépendances Linux isolées des dépendances de l’hôte. |
| `docker/dev/containers_datas/npm` | `/workspace/npm-cache` | Cache npm persistant. |

Le service d’extension ne monte que son `frontend`, les outils partagés en
lecture seule et les sorties publiques. Chaque Vite limite les sources servies
par HTTP à son frontend et refuse les fichiers PHP, secrets et fichiers Git.

Le lecteur commun calcule les chemins de publication depuis `version.php`.
Les montages d'assets et le contrôle de santé de cet environnement restent
configurés pour `vdev`. Le fichier de version est accessible à Node mais n'est
pas ajouté aux chemins que Vite peut servir par HTTP. Redémarrer Vite après
une modification de ce fichier. Vite sert les sources en mode dev ; c’est
`npm run build` qui publie les fichiers compilés dans `src`.
Ne pas lancer de build ponctuel pendant que Vite utilise les mêmes dépendances.
En mode compilé, pour reconstruire sans redémarrer Apache :

```bash
docker compose run --rm --no-deps frontend
```

## Commandes utiles

Les scripts `npm.sh` et `php.sh` transmettent leurs arguments sans transformation,
conservent stdin et le code de sortie, et fonctionnent aussi sans terminal
(appels d’un agent ou pipes). Ils peuvent être lancés avec sudo si nécessaire.

Depuis `docker/dev`, les chemins sont relatifs à `src/ria` pour npm et à
`src` pour PHP, même si le script est appelé depuis un autre dossier :

```bash
./npm.sh --version
./npm.sh run test:language
./npm.sh run build
./php.sh --version
./php.sh -l releases/vdev/core_extensions/Security/Plugin.php
./php.sh releases/vdev/vendor/bin/phpunit --configuration releases/vdev/phpunit.xml --testsuite unit
```

Le script npm utilise le conteneur Vite s’il tourne, sinon un conteneur Node
ponctuel avec les mêmes volumes. Le script PHP utilise Apache s’il tourne,
sinon un conteneur ponctuel issu de la même image. Lancer `start.sh` une première
fois pour disposer de l’image, du `.env` et des dépendances. Les scripts ne
démarrent pas automatiquement MariaDB quand les services sont arrêtés.

### Base de tests PHP

Les tests unitaires ne demandent pas de base. Les tests d’intégration utilisent
la même instance MariaDB que le site, avec le schéma fixe `tycms_test_main`
et le compte `tycms_test_runner`, limité à ce schéma. `start.sh` les crée
ou actualise le compte sans effacer les tables, y compris sur un volume SQL
existant. `TEST_DB_PASSWORD` dans `.env` règle le mot de passe (défaut de dev :
`tycms_test_dev`). Les noms du compte et du schéma de test ne doivent pas
être utilisés par le site.

Apache reçoit les quatre variables `TYCMS_TEST_DB_*` du banc, avec l’hôte
`mariadb`. Les tests chargent `src/tests/Support/Configuration.php`, qui hérite
de `configuration.base.php`, puis leurs réglages isolés ; ils ne chargent ni
`src/releases/vdev/configuration.php` ni la configuration Docker
du site. Médias, logs, temporaires et verrous restent sous `src/tests/.runtime`.

Après `start.sh`, préparer explicitement les fixtures puis lancer la suite :

```bash
./php.sh tools/test_db_prepare.php --reset
./php.sh releases/vdev/vendor/bin/phpunit --configuration releases/vdev/phpunit.xml --testsuite integration
```

La première commande remet à zéro uniquement les tables de la base de test.
Ne pas lancer deux suites d’intégration simultanées sur cette même base.

Depuis `docker/dev` après le premier lancement :

```bash
docker compose logs -f
docker compose --profile dev logs -f vite
docker compose exec --user "$TYCMS_DEV_UID:$TYCMS_DEV_GID" -e HOME=/tmp -e COMPOSER_HOME=/tmp/tycms-composer apache composer --working-dir=releases/vdev install
./php.sh releases/vdev/vendor/bin/phpunit --configuration releases/vdev/phpunit.xml --testsuite unit
docker compose config --quiet
```

Les tests d’intégration demandent une base et un compte dédiés : suivre
`src/tests/Integration/README.md`, sans employer la base de développement.
