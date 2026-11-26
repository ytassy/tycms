# Construire et signer une extension

Ce dossier fabrique les ZIP de production des extensions installées sous
`src/extensions/plugins`, `modules` ou `templates`. Les artefacts signés peuvent
être déposés dans TyCatalog depuis son administration si l’extension est
active ; aucune commande n’envoie automatiquement de fichiers en ligne et
l’installateur n’est pas encore implémenté.

`build.sh` propose un menu, compile l’extension choisie dans une copie isolée,
puis signe automatiquement ses artefacts. Il réutilise l’image `tycms-build`
définie par `docker/build/Dockerfile` : PHP 8.4, Node 24 et Composer. La clé
privée est lue par PHP **sur l’hôte, après l’arrêt du conteneur de compilation**.
Elle n’est jamais fournie aux scripts npm.

## Prérequis

- Docker Engine accessible par l’utilisateur courant et Bash avec `flock`.
- Git pour le dépôt ; le Git de l’image sert à inventorier les sources.
- PHP local 8.4 ou supérieur avec les extensions `sodium` et `zip`, pour la
  génération des clés, la signature et la vérification.
- Un accès Internet pour construire l’image et installer les dépendances npm
  verrouillées. Les builds suivants peuvent utiliser les caches Docker.

Vérifier PHP et Docker :

```bash
php --version
php -r 'foreach (["sodium", "zip"] as $extension) { echo $extension, ": ", extension_loaded($extension) ? "OK" : "MANQUANT", PHP_EOL; }'
docker info
```

Exécuter les commandes ci-dessous depuis la racine du dépôt. Aucun PHP,
Composer ou Node de production n’est lancé et aucune base n’est modifiée.

## 1. Générer la paire de clés du Store

**Une paire de clés par Store, commune à toutes les extensions de ce Store.**
La clé publique n’est pas commune à tous les développeurs. Les clés TyCMS
`release-…` restent distinctes des clés d’extension `extensions-…`.

Créer une paire une seule fois, dans un dossier privé hors du dépôt :

```bash
install -d -m 700 "$HOME/.config/tycms"
php build_extensions/signing-key.php "$HOME/.config/tycms/extensions-store-1.secret"
```

Deux fichiers sont créés sans écraser d’existant, avec les permissions `0600` :

```text
~/.config/tycms/extensions-store-1.secret       clé privée, à conserver et sauvegarder
~/.config/tycms/extensions-store-1.secret.pub   clé publique, à partager
```

La commande affiche uniquement la clé publique. La clé privée ne doit entrer
ni dans Git, ni dans le ZIP, ni sur le futur serveur du Store. Ne pas créer une
nouvelle paire à chaque build. Si les permissions privées ont changé :

```bash
chmod 600 "$HOME/.config/tycms/extensions-store-1.secret"
```

## 2. Configurer la signature locale

```bash
cp build_extensions/signing.sample.php build_extensions/signing.local.php
chmod 600 build_extensions/signing.local.php
```

Modifier `build_extensions/signing.local.php` pour renseigner le chemin de la
clé. Par exemple :

```php
<?php

return [
    'key_id' => 'extensions-store-1',
    'private_key_file' => getenv('HOME') . '/.config/tycms/extensions-store-1.secret',
];
```

Ce fichier local est ignoré par Git et absent des montages de compilation.
L’identifiant commence par `extensions-`. Le nom du fichier privé commence
également par `extensions-` et se termine par `.secret`. Le contenu doit être
une véritable clé différente de celle employée pour les mises à jour TyCMS.

Vérifier la configuration sans lancer de build :

```bash
php build_extensions/sign.php --check
```

Pour utiliser une configuration conservée ailleurs :

```bash
TYCMS_EXTENSION_SIGNING_CONFIG="$HOME/.config/tycms/extensions-signing.php" ./build_extensions/build.sh
```

Un développeur avec deux Stores peut utiliser deux copies locales du dépôt et
deux `signing.local.php`, chacune désignant sa propre paire. Par exemple
`extensions-store-a-1.secret` et `extensions-store-b-1.secret`. Le nom du
fichier de configuration reste identique dans chaque copie.

## 3. Préparer les métadonnées de l’extension

Chaque extension compilable possède `version.json` et `version.md` à côté de
son point d’entrée PHP. Exemple pour `src/extensions/plugins/Lms/version.json` :

```json
{
    "schema_version": 1,
    "id": "plg_lms",
    "type": "plugin",
    "folder": "Lms",
    "version": "0.1.0",
    "source_versions": "*",
    "cms_min": "1.1.3",
    "cms_max_exclusive": "1.2.0",
    "php_min": "8.4.1",
    "php_max_exclusive": "8.6.0"
}
```

- `folder` est le nom technique du dossier PHP et du segment de namespace.
- `id`, `type` et `folder` doivent correspondre ; les identifiants système
  réservés sont refusés.
- `version` est la version stable `X.Y.Z` de l’extension. Le suffixe de fichier
  `0_1_0` est calculé, sans champ supplémentaire à maintenir.
- `source_versions` ne restreint que les mises à jour : la première
  installation reste toujours permise. `"*"` est la valeur recommandée par
  défaut : première installation et toute mise à jour depuis une version
  antérieure, les migrations suivant naturellement le versionnement. `[]`
  réserve l’extension à une première installation uniquement ; une liste
  explicite autorise la première installation et les versions listées. Une
  version listée doit précéder la cible. Une incompatibilité réelle se règle
  par une nouvelle extension, pas par une matrice de versions sources.
- Les bornes CMS/PHP sont inclusives au minimum et exclusives au maximum.
  L’auteur doit les choisir selon les versions effectivement compatibles.
- `version.md` contient les changements et limites utiles à l’utilisateur.

`BaseExtension::getVersion()` peut lire ce fichier directement ; LMS utilise
ce contrat. Le numéro de `frontend/package.json` n’est pas la source de vérité
du ZIP. Les migrations conservent leurs propres identifiants.

## 4. Lancer le build

Avec sélection interactive et confirmation des métadonnées :

```bash
./build_extensions/build.sh
```

Le menu liste les extensions dotées d’un `version.json` valide, sans exécuter
leurs classes PHP. Les autres dossiers sont signalés avec la raison du refus.
`0` ou une fin d’entrée annulent l’opération. Un choix numérique invalide
produit une erreur et ne lance pas la compilation.

Avec une extension explicite, sans menu ni confirmation supplémentaire :

```bash
./build_extensions/build.sh plugins/Lms
```

Cette sélection ne change pas `DEV_EXTENSION` et ne touche pas aux Vite locaux.
Les fichiers suivis par Git et les nouveaux fichiers non ignorés sont copiés
avec leur contenu courant ; un commit n’est pas requis pour essayer un build.
Les fichiers ignorés restent exclus. Les chemins de paquet utilisent des noms
ASCII relatifs, sans segment parent ni lien symbolique.

La préparation s’effectue sans réseau, avec le dépôt en lecture seule. Le
conteneur npm reçoit ensuite seulement la copie de travail et les outils de
build contrôlés. Il ne reçoit ni le dépôt, ni `signing.local.php`, ni les
anciennes distributions, ni la clé privée. Le frontend est reconstruit depuis
son lockfile par `npm ci`, puis `npm run build` ; les anciennes sorties locales
ne sont pas réutilisées.

La syntaxe PHP est contrôlée sans exécuter le code de l’extension ou ses
migrations. Après les contrôles du ZIP, la commande hôte signe le manifeste
avec Ed25519. La signature couvre identité, version, compatibilités, taille et
SHA-256 du ZIP ainsi que les notes. Son format et son domaine cryptographique
sont distincts de ceux des mises à jour TyCMS.

## 5. Récupérer les artefacts

Pour LMS `0.1.0`, `build_extensions/dist/` contient :

```text
plg_lms-0_1_0.zip
plg_lms-0_1_0.md
plg_lms-0_1_0.extension-build.json
plg_lms-0_1_0.extension-signature.json
```

Les fichiers temporaires et les distributions sont ignorés par Git. Un build
de la même version remplace ses artefacts locaux ; ceux des autres extensions
restent présents. La signature est activée en dernier : un ensemble interrompu
ou altéré ne doit jamais être consommé sans vérification. La copie sous
`build_extensions/temp/` est supprimée après un build réussi ; en cas d'échec,
elle reste disponible pour inspection.

Le ZIP contient uniquement :

```text
extension/
  version.json
  version.md
  Plugin.php                    # Module.php ou Template.php selon le type
  PageComponents/…
assets/
  .vite/manifest.json
  build/…
  images/…
```

Une extension PHP seule peut ne pas avoir d’assets. Les sources Vue/TypeScript,
le dossier `frontend`, les dépendances npm, les tests, outils, secrets usuels,
sourcemaps et fichiers locaux ne sont pas livrés. Les médias statiques versionnés
sont conservés. L’installateur devra associer ces deux racines aux destinations
déduites des métadonnées validées, sans accepter de chemin libre.

Le client `tycatalog-publish.php` envoie un paquet signé à l’API distante avec un
jeton Bearer. Il choisit aussi le **canal de diffusion** : `release` par défaut, ou `beta` pour n'être servi qu'aux installations qui le demandent. Une bêta peut ensuite être promue en release, jamais l'inverse.

En mode interactif, après le dépôt, le menu propose : `1` publier en bêta, `2` laisser en brouillon (défaut), `3` publier en release. En ligne de commande, `--channel=beta` accompagne `--publish` ou `--publish-version`, et `--promote=N` promeut une bêta déjà publiée.

Sans argument, il propose un menu :

```bash
php build_extensions/tycatalog-publish.php
```

Copier `tycatalog-publish.sample.php` vers `tycatalog-publish.local.php` (ignoré
par Git) pour fixer l’URL, le catalogue, le dossier `dist/` et le fichier de
jeton. Les options directes (`--url`, `--catalog`, `--zip`, `--notes`,
`--signature`, `--publish`, `--channel`, `--promote`) restent disponibles pour
les scripts.

## 6. Vérifier le ZIP signé

Utiliser une clé publique déjà reconnue, jamais une clé choisie dans le ZIP :

```bash
php build_extensions/verify.php \
  build_extensions/dist/plg_lms-0_1_0.zip \
  "$HOME/.config/tycms/extensions-store-1.secret.pub" \
  extensions-store-1
```

La vérification contrôle la signature, le ZIP, son contenu, les métadonnées
internes, le manifeste Vite et les notes externes. Une altération, une mauvaise
clé, un fichier absent ou un nom de paquet différent provoquent un échec.

## Erreurs et contrôles

Le script s’arrête avec un code non nul et indique l’étape en échec. Les erreurs
de fichier mentionnent le chemin concerné. En particulier :

- `version.json` absent/invalide : le créer ou corriger les champs indiqués ;
- `version.md` absent/vide : renseigner les notes avant de construire ;
- `Plugin.php`, `Module.php` ou `Template.php` absent : rétablir le point d’entrée ;
- `package-lock.json` absent : le générer dans le frontend, puis relancer ;
- configuration/clé absente : suivre les étapes 1 et 2 ;
- permissions privées incorrectes : appliquer `chmod 600` à la clé ;
- échec npm ou PHP : corriger la commande, le dossier et l’erreur indiqués ;
- build déjà en cours : attendre la libération du verrou, sans lancer de
  nettoyage manuel des dossiers temporaires pendant son exécution.

Le chemin de clé peut apparaître dans une erreur, jamais son contenu. En cas
d’échec avant la livraison, aucune nouvelle signature valide n’est activée.
Une ancienne distribution déjà signée peut rester présente : vérifier les
messages de la commande et la version avant de la diffuser.

Contrôles locaux avec clés fictives et dossiers temporaires isolés :

```bash
php build_extensions/test.php
bash -n build_extensions/build.sh
```

Ce premier contrat ne résout pas les dépendances entre extensions et n’installe
pas de dépendances Composer propres à une extension. La distribution publique,
l’installation, les migrations de mise à jour et la rotation des clés feront
l’objet de travaux séparés.
