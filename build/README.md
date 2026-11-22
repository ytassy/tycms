# Fabrication des paquets

Le contrat de distribution est décrit dans le
[chantier de mise à jour](../Docs/IA/CurrentWorks/MISES_A_JOUR_SYSTEME.md).

## Utilisation

Depuis la racine du dépôt, avec Bash, Docker et un accès réseau aux registres
de dépendances (PHP, Composer, Git et Node sont fournis par l’image) :

```bash
./build/build.sh
```

Sans argument, cette commande affiche `version.json` et demande confirmation
avant de lancer le build Docker isolé. Répondre `o` ou `oui` pour démarrer ;
Entrée, une autre réponse ou une fin d'entrée annulent sans lancer Docker.
Elle fonctionne aussi depuis un autre dossier. Modifier `version.json` avant
de la lancer pour changer la version ou les compatibilités.

`../version.json` définit la version stable `X.Y.Z`, son suffixe `X_Y_Z`,
les `source_versions` explicites ou le marqueur `*` et les bornes `php_min` /
`php_max_exclusive`. Tous ces champs sont obligatoires et validés avant les
commandes Composer/npm.
`../version.md` contient uniquement les nouveautés et changements utiles aux
utilisateurs finaux ; les détails techniques restent dans les guides.

Chaque build produit **trois fichiers côte à côte** dans `dist/` :

```text
tycms-1_0_0.zip
tycms-1_0_0.build.json
tycms-1_0_0.md
```

Le `.build.json` contient les métadonnées capturées et les tailles/empreintes
SHA-256 du ZIP et du Markdown. Ces deux sources racines sont copiées pendant
la préparation ; aucune modification ultérieure du dépôt ne change les
artefacts. Les notes (1 à 262 144 octets) restent hors du ZIP système.
Les fichiers JSON/Markdown ne sont pas des signatures ; la signature intervient
uniquement lors de la publication locale. Conserver et déplacer les trois
fichiers ensemble. Les anciens ZIP sans leurs fichiers compagnons doivent
être reconstruits avant publication, sans modifier une version déjà publiée.

Pour préparer et inspecter seulement les sources, sans installer les
dépendances des projets ni compiler leurs assets :

```bash
./build/build.sh --prepare-only
```

L'option `--prepare-only` est transmise directement au build, sans question.
Le point d'entrée `./docker/build/build.sh` reste disponible sans interaction
pour les automatisations. `./build/build.sh` vide `build/temp/` avant chaque
exécution, puis conserve la nouvelle copie, y compris en cas d'échec et avec
`--prepare-only`. Le point d'entrée Docker direct ne nettoie pas ce dossier.
Les deux commandes utilisent le même verrou et refusent un build concurrent.

Le script PHP sélectionne les fichiers suivis par Git et copie leur contenu
courant, y compris les modifications non commitées. Ajouter les nouvelles
sources à l'index Git avant de fabriquer une release. Les configurations,
données locales, dépendances existantes, les anciens
bundles sont exclus. Les liens symboliques sont refusés.

## Publication séparée

La fabrication et la proposition au serveur sont deux commandes distinctes :

```bash
./build/build.sh
php build/publish.php
```

`publish.php` **propose** la version : le serveur la range en attente
d'approbation et ne la distribue pas encore. La réussite affiche
`Proposition enregistrée : X.Y.Z, en attente d'approbation`. Un
administrateur doit ensuite l'approuver en bêta ou en prod depuis le
[mini-UI du serveur](../update_server/README.md#mini-ui-dadministration-et-approbation).
Avant cette approbation, le catalogue reste sans changement pour tous les
sites installés.

Sans ZIP en argument, la publication liste les ZIP `tycms-X_Y_Z.zip` disponibles
dans `build/dist/`, du plus récent au plus ancien. Choisir un numéro, puis
confirmer la version et l'URL du serveur affichées avec `o` ou `oui`.
`0` au menu, une réponse négative ou une fin d'entrée annulent l'envoi.
Même avec un seul ZIP, la sélection reste explicite. Les liens symboliques
ne sont pas proposés ; un compagnon manquant ou altéré bloque ensuite l'envoi.
Le dossier des ZIP est déterminé depuis le script, indépendamment du dossier
courant du terminal.

Pour un envoi direct sans questions, fournir explicitement le ZIP :

```bash
php build/publish.php build/dist/tycms-1_0_1.zip
```

Cet exemple suppose qu'un build `1.0.1` et ses compagnons existent déjà.
La seconde commande utilise PHP local 8.4 ou supérieur avec `curl`, `sodium`
et `zip`, sans Docker. Elle ne reconstruit pas le paquet et ne modifie pas le
ZIP d'origine ; une copie temporaire privée fige les octets signés et envoyés.

### Configuration initiale de la publication

1. Créer un dossier privé **hors du dépôt**, par exemple `~/.config/tycms/`,
   avec des permissions limitant son accès à l'utilisateur.
2. Générer une paire de clés Ed25519, une seule fois :

   ```bash
   php build/signing-key.php ~/.config/tycms/release-1.secret
   ```

   Le script crée la clé privée et `release-1.secret.pub` avec des permissions
   `0600`, sans écraser de fichiers existants. Il affiche uniquement la clé
   publique ; conserver et sauvegarder la clé privée dans un emplacement sûr.
3. Copier `build/publication.sample.php` vers `~/.config/tycms/publication.php`
   et renseigner l'URL HTTPS `…/index.php`, le token original, `key_id`, le
   chemin de clé privée. Limiter également
   les permissions de cette configuration à `0600`.
4. Installer côté serveur la clé **publique** dans `trusted_public_keys` sous
   le même `key_id`, et l'empreinte du token dans `publish_token_sha256`.
   La clé privée ne doit pas être copiée sur le serveur.

Sans ZIP en argument et sans configuration explicite, la commande propose les
fichiers `~/.config/tycms*/publication.php`. Le fichier
`~/.config/tycms/publication.php` figure toujours en premier : Entrée le choisit.
S'il est absent, le menu le signale et sa sélection produit une erreur, sans
basculer vers une autre cible. `0` ou une fin d'entrée annulent la publication.
Seul le fichier choisi est chargé ; la version et le serveur sont ensuite
affichés pour confirmation. Le script de build n'utilise pas ces configurations.

Si `XDG_CONFIG_HOME` est défini, il remplace `~/.config` pour cette recherche.
Avec un ZIP explicite, la commande utilise directement
`~/.config/tycms/publication.php`, sans menu. Un autre emplacement peut être
fourni via `TYCMS_PUBLICATION_CONFIG` ou `--config=/chemin/prive.php`, prioritaire
sur cette variable ; ces choix explicites évitent aussi le menu. Les fichiers de
configuration et de clé privée situés dans le dépôt sont refusés ; les
secrets ne sont jamais passés en arguments ni transmis aux conteneurs.

### Premier essai sur le serveur déployé

Ces commandes se lancent depuis la racine du dépôt, sur la machine de
publication. Pour les essais, utiliser une clé et un token provisoires ainsi
qu'un stockage serveur dédié, séparé du catalogue de production.

Le token autorise l'envoi ; seule son empreinte SHA-256 est conservée sur le
serveur. La clé privée signe les manifestes localement ; la clé publique
permet au serveur et aux consommateurs de vérifier leur signature.

1. Créer le dossier privé hors du dépôt et générer la paire Ed25519 :

   ```bash
   install -d -m 700 ~/.config/tycms-validation
   php build/signing-key.php ~/.config/tycms-validation/validation-1.secret
   ```

   Le script crée `validation-1.secret` et `validation-1.secret.pub` en
   `0600`, sans écraser d'existant. Il affiche la clé publique à copier sur
   le serveur. La clé privée reste sur la machine de publication.

2. Générer le token et son empreinte :

   ```bash
   php update_server/tools/token.php
   ```

   Conserver le token secret pour l'étape 4 et copier uniquement son
   empreinte SHA-256 sur le serveur. Ne pas transmettre les secrets dans
   une conversation ni les placer dans une commande de terminal.

3. Sur le serveur distant, compléter `configuration.php`, à côté de `lib/`
   et `public/`, avec les valeurs obtenues :

   ```php
   <?php

   return [
       'publish_token_sha256' => [
           'REMPLACER_PAR_EMPREINTE_SHA256',
       ],
       'trusted_public_keys' => [
           'validation-1' => 'REMPLACER_PAR_CLE_PUBLIQUE_BASE64',
       ],
       'storage_path' => __DIR__ . '/storage-validation',
       'max_package_bytes' => 128 * 1024 * 1024,
       'allow_loopback_http' => false,
   ];
   ```

   Créer `storage-validation` hors de `public/` et autoriser PHP à y écrire,
   sans permissions `777`. Seul `public/` doit être exposé sur le Web. Deux
   domaines desservant cette même installation partagent sa configuration
   et son stockage.

4. Préparer la configuration locale privée :

   ```bash
   install -m 600 build/publication.sample.php ~/.config/tycms-validation/publication.php
   ```

   Éditer ce fichier localement pour remplacer les valeurs d'exemple :

   ```php
   <?php

   return [
       'server_url' => 'https://update.tycms.fr/index.php',
       'token' => 'REMPLACER_PAR_TOKEN_SECRET',
       'key_id' => 'validation-1',
       'private_key_file' => '/home/yann/.config/tycms-validation/validation-1.secret',
       'timeout_seconds' => 300,
       'allow_loopback_http' => false,
   ];
   ```

   Adapter `/home/yann` au compte local. PHP ne développe pas `~` dans cette
   valeur : utiliser un chemin absolu. Le `key_id` doit correspondre exactement
   à celui du serveur. L'autre domaine disponible est
   `https://update.tycms.yanntassy.fr/index.php` ; choisir celui utilisé pour
   l'envoi sans configurer de redirection.

5. Publier les trois artefacts d'un build existant, par exemple `1.0.0` :

   ```bash
   php build/publish.php build/dist/tycms-1_0_0.zip \
     --config="$HOME/.config/tycms-validation/publication.php"
   ```

   Le client lit aussi `tycms-1_0_0.build.json` et `tycms-1_0_0.md` dans le
   même dossier. Il signe le manifeste et envoie les fichiers avec le token.
   Une réussite affiche `Proposition enregistrée : 1.0.0, en attente d'approbation`
   et l'empreinte du ZIP. Approuver la version dans l'interface
   d'administration du serveur de validation pour que les installations de
   test la voient. Cette commande ne met à jour aucun site Tycms.

6. Consulter le catalogue et le manifeste depuis chacun des domaines :

   ```bash
   curl --fail-with-body 'https://update.tycms.fr/index.php?action=releases'
   curl --fail-with-body 'https://update.tycms.fr/index.php?action=manifest&version=1.0.0'
   curl --fail-with-body 'https://update.tycms.yanntassy.fr/index.php?action=releases'
   curl --fail-with-body 'https://update.tycms.yanntassy.fr/index.php?action=manifest&version=1.0.0'
   ```

   Ces lectures doivent répondre HTTP 200 et présenter la même release.
   Afficher le manifeste ne vérifie pas sa signature : celle-ci doit être
   contrôlée avec la clé publique locale reconnue, puis les tailles et
   empreintes du ZIP et des notes comparées aux valeurs signées. Répéter
   l'envoi de la même version doit produire HTTP 409, sans remplacement.

Après validation, préparer les clés, le token et le stockage définitifs selon
le [guide du serveur](../update_server/README.md#validation-du-déploiement-avant-les-clés-définitives).
Ne pas mélanger les paquets d'essai au catalogue de production.

### Compatibilités et notes capturées au build

Exemple pour une version `1.0.2` autorisant une mise à jour directe depuis
`1.0.0` et `1.0.1`, dans `version.json` :

```json
{
  "version": "1.0.2",
  "version_string": "1_0_2",
  "source_versions": ["1.0.0", "1.0.1"],
  "php_min": "8.4.1",
  "php_max_exclusive": "8.6.0"
}
```

Les sources doivent être uniques et antérieures à la cible. `"*"` couvre la
première installation et toute mise à jour depuis une version antérieure ;
`[]` réserve la release à une première installation uniquement ; une liste
explicite autorise la première installation et les versions listées. La borne
PHP minimale est incluse et la maximale exclue. Pour le verrou actuel,
PHP doit être au moins `8.4.1` et strictement inférieur à `8.6.0` ; ajuster
ces valeurs avec les dépendances et les migrations de la release.

L’envoi lit uniquement le `.build.json` et le `.md` situés à côté du ZIP.
Il valide les métadonnées avant signature et compare les tailles/empreintes
du ZIP et des notes. Un compagnon absent, un ZIP ou un Markdown altéré ou
appartenant à un autre build bloque l’envoi. Le JSON est une copie non signée
à conserver sans modification ; son contenu sera authentifié à la publication. Il ne relit jamais
`version.json` ou `version.md` du dépôt, qui peuvent déjà décrire la version
suivante. Les options de sources et d’installation initiale ont été supprimées ;
les compatibilités ne se configurent plus dans la configuration privée.

L'envoi est limité à HTTPS, avec validation TLS et sans suivi des redirections.
Un `allow_loopback_http` explicite ne permet HTTP que vers `127.0.0.1` ou
`[::1]` pour les tests locaux. Une version déjà publiée est refusée (HTTP 409),
même si le ZIP est identique. En cas d'interruption réseau, vérifier le
manifeste distant avant toute nouvelle tentative. Voir le
[protocole du serveur](../update_server/README.md#protocole-http-schéma-1).

## Isolation et assemblage

Chaque exécution crée un dossier neuf `temp/build-<version>-<aléa>/` :

- `src/` conserve les chemins relatifs attendus par les outils ; `vdev` porte
  temporairement la version de production, sans modifier le développement.
- `package/` contient uniquement les fichiers à distribuer, sous
  `releases/v<version_string>/`, avec les dépendances PHP de production,
  les assets Vite et Mermaid reconstruits, le bootstrap public et l'updater.
- `build-version.json` et `version.md` capturent les sources racines avant compilation.

Un premier conteneur prépare les sources avec le dépôt en lecture seule,
sans réseau ni exécution de Composer/npm. Un second conteneur reçoit uniquement
cette copie et les scripts de build, sans montage du dépôt ni de ses secrets.
Les résultats dans `temp/` et `dist/` sont ignorés par Git. La copie de travail
est supprimée après un build réussi ; en cas d'échec, elle reste disponible
pour inspection et sera supprimée au prochain lancement de `./build/build.sh`.
Les sorties sont remplacées individuellement sous verrou, le JSON en dernier.
Une interruption peut laisser un ensemble incohérent : l’envoi le refuse
grâce aux empreintes et il faut refaire le build.

Composer utilise le verrou, sans scripts ni plugins, et produit un autoload
optimisé sans classmap autoritaire pour permettre les extensions locales.
Après l'installation, le build applique lui-même les sélections
`extra.google/apiclient-services` et `extra.aws/aws-sdk-php` de
`composer.json` : les services Google et AWS non retenus sont supprimés puis
l'autoload est régénéré, sans exécuter de script Composer. Le SDK AWS garde ses
services partagés d'authentification et un contrôle instancie S3 sans réseau. Les deux projets Node utilisent `npm ci` avec leurs outils de
développement ; ni leurs sources ni `node_modules` ne sont livrés. Leurs scripts
de build s'exécutent dans la copie isolée, sans secrets de signature ou de
publication.
Le build frontend Core génère son manifeste, l’entrée ESM `@tycms/api` et
le runtime partagé de production. Ces fichiers sont requis dans le ZIP ;
les sources `ria/runtime`, la configuration de runtime et `tools/shared-esm.mjs`
sont incluses dans la copie de compilation. Tous les fichiers suivis sous
`public/assets_core_vdev/plg_core/` sont livrés, sauf le sous-dossier généré
`.vite/` et les `.gitignore` ; les bundles ignorés par Git sont reconstruits
pendant la compilation. Les bibliothèques des extensions
installées restent exclues du ZIP système et se construisent séparément.
Les fichiers suivis sous `public/assets_updater/` sont livrés avec l'updater,
y compris ses bibliothèques statiques et leurs sous-dossiers. Les exclusions de
sécurité communes restent appliquées ; les fichiers requis de l'interface sont
contrôlés avant l'archivage.

Le ZIP inclut les structures locales vides et un état initial `var/` généré
pour la release livrée. Seul `configuration.php` reste à créer manuellement
dans la release : copier `configuration.sample.php` vers `configuration.php`,
puis décommenter et renseigner les réglages importants indiqués dans l'exemple.
Celui-ci hérite de `ConfigurationBase` et documente aussi toutes les autres
options facultatives. Ne pas modifier la base livrée ; suivre le
[contrat de configuration](../Docs/ARCHITECTURE.md#configuration-locale-et-valeurs-par-défaut).
L'assistant de configuration est reporté.
Lors d'une mise à jour, l'état initial et les structures du ZIP ne doivent
écraser ni l'état et les données du site existant, ni son bootstrap public.
L'updater inclus pilote les étapes de mise à jour et de récupération depuis le
lien préparé par Core. Le parcours complet reste à vérifier sur une installation
isolée ; son contrat est dans `Docs/SYSTEM_UPDATES.md`.

## Vérification

Ces contrôles ciblés peuvent utiliser PHP CLI sur l’hôte ; le lancement normal
du build ne le nécessite pas.

```bash
php build/test.php
php -l build/build.php
php -l build/functions.php
bash -n docker/build/build.sh
bash -n build/build.sh
```

Le build contrôle le verrou Composer, les exigences de plateforme, l'autoload,
l'absence de PHPUnit, les fichiers d'exécution requis et la cohérence du ZIP
avant son remplacement dans `dist/`. Il ne teste pas la connexion SQL ni le
parcours d'installation d'un hébergement ; vérifier le démarrage sur une
installation isolée avec sa configuration propre avant une publication.
