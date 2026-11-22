# Vérifier une évolution

Choisir les contrôles selon le contrat modifié. Les commandes ci-dessous partent
de la **racine du dépôt** et utilisent l'environnement [Docker](../../docker/dev/README.md).
Les chemins des arguments PHP sont relatifs à `src` dans le conteneur ; les
chemins npm sont relatifs à `src/ria`. Les wrappers conservent stdin et le
code de sortie, avec ou sans terminal.

## Choisir les contrôles utiles

| Modification | Contrôle adapté |
| --- | --- |
| Documentation seule | Cohérence avec le code touché, existence des chemins/liens et contrôle du diff. Aucun build ni accès SQL nécessaire. |
| PHP | Syntaxe des fichiers modifiés et tests unitaires du contrat concerné. |
| Routage, ACL, transaction, session ou données croisées | Tests ciblés puis intégration si le comportement demande réellement MySQL et le démarrage de l'application. |
| JS / store / règles d'arbre | Tests Node existants du domaine, puis build si le bundle a changé. |
| Vue / styles | Build Vite ; essai visuel ciblé avec Yann si nécessaire. |
| Dépendance ou configuration de build | Build correspondant ; garder le build Mermaid distinct du build Vite. |

Ne pas créer des tests qui recopient une modification triviale et réversible.
Pour une règle métier ou de sécurité, couvrir les refus et les périmètres
interdits autant que la réussite. Une fois les contrôles adaptés passés, ne
pas relancer toute la suite sans nouveau changement ou doute concret.

## Syntaxe PHP et tests unitaires

```bash
./docker/dev/php.sh -l releases/vdev/core_extensions/Core/Plugin.php
./docker/dev/php.sh releases/vdev/vendor/bin/phpunit --configuration releases/vdev/phpunit.xml tests/Unit/Plugins/Core/PageDocumentTest.php
./docker/dev/test.sh --testsuite unit
```

Les tests restent partagés sous `src/tests` ; Composer et PHPUnit sont dans
`src/releases/vdev`. Après une modification de l’autoload, le régénérer depuis
la release avec `composer --working-dir=src/releases/vdev dump-autoload`.
Le bootstrap des tests définit `APP_ROOT` sur `src`, charge `version.php`,
reproduit `ASSETS_CORE_PATH`, puis charge `tests/Support/Configuration.php`.
Cette classe `Configuration` hérite de `configuration.base.php` dans `vdev`,
jamais de la configuration locale.

La suite `unit` est le défaut de `src/releases/vdev/phpunit.xml` et ne dépend pas de MySQL.
Adapter le fichier ciblé à la modification. PHP 8.4 est requis, notamment pour
les propriétés à hooks ; un PHP plus ancien sur l'hôte ne suffit pas à valider
le code. Les tests qui créent `Application` sans démarrage doivent enregistrer
les types de formulaire utilisés ; voir [Formulaires](../FORMS.md#types-de-champs-dextension).

## Frontend et build

```bash
./docker/dev/npm.sh run test:language
./docker/dev/npm.sh exec -- node --test tests/pageEditorModel.test.js tests/pageClipboard.test.js
./docker/dev/npm.sh run build
```

Les suites Node sont dans `src/ria/tests` ; toutes ne passent pas par un
script npm unique. Lire le fichier ou `src/ria/package.json` avant de choisir
la commande. Les chemins d'exemple ci-dessus correspondent aux langues et à
l'éditeur ; ne pas les lancer pour un domaine indépendant.

Le build Vite publie les bundles Core sous
`src/public/assets_core_v<APP_VERSION_STRING>/plg_core/`, à partir du `version.php`
de `vdev`. Le lecteur commun et les bases des aperçus sont couverts par
`src/ria/tests/coreAssets.test.js` et les suites d'aperçu. Redémarrer Vite après
un changement de version.
Il peut modifier les assets générés : inspecter le diff et conserver les
artefacts attendus selon le périmètre. Le build peut signaler des ressources
de polices non résolues et l'import à la fois statique et dynamique de
`PageCanvasItems` ; distinguer ces avertissements d'une erreur bloquante et
rapporter les nouveaux avertissements.

Les bibliothèques d’extension se vérifient séparément avec
`./docker/dev/extension-npm.sh plugins/Lms run build`. Les tests du contrat sont
`src/ria/tests/extensionRegistry.test.js`, `FrontendAssetsTest.php` et
`FrontendManifestTest.php`. Le Core fournit aussi son runtime partagé et son
manifeste ; le build système sélectionne leurs sources et contrôle leurs
sorties. Confier à Yann l’affichage et le HMR simultané dans le navigateur.

Mermaid possède son build tiers dans `src/tools/mermaid` : suivre son
[README](../../src/tools/mermaid/README.md) et le contrat des
[shortcodes](../SHORTCODES.md#composants-vue-et-chargement-public).

## Distribution et publication

Pour les modifications du protocole, des signatures ou de l’API de publication :

```bash
php update_server/tests/run.php
```

PHP local doit disposer de `curl`, `sodium` et `zip`. La suite démarre un serveur
PHP éphémère limité à `127.0.0.1` et utilise uniquement des clés et ZIP factices
sous `/tmp`, nettoyés ensuite. Aucun accès SQL ni envoi vers un serveur réel.
Le [guide du serveur](../../update_server/README.md#vérifications) décrit le
périmètre ; les paramètres propres au mutualisé sont vérifiés avec Yann.

## Paquets d’extension

```bash
php build_extensions/test.php
bash -n build_extensions/build.sh
```

La suite crée une extension PHP fictive sous `/tmp`, vérifie les métadonnées,
l’exclusion des fichiers locaux, le ZIP et les signatures avec des clés
éphémères, sans exécuter le PHP de l’extension. Elle couvre les fichiers
manquants, chemins sortants, collisions, liens et altérations. Pour modifier le
pipeline Docker/npm, compléter par un build LMS avec une configuration de
signature temporaire hors du dépôt et vérifier ses quatre artefacts avec
`build_extensions/verify.php`. Ne pas générer de clé de production pour un test.
Voir les commandes complètes dans le [README](../../build_extensions/README.md).

## Intégration : base dédiée uniquement

Une base de production existe, y compris pour l'installation hébergée sur
`tests.yanntassy.fr` ; le nom d'hôte ne permet pas de la considérer comme
jetable. Ne jamais y lancer les tests, les commandes de préparation/reset ou
les essais de mise à jour. Pour une évolution du schéma ou des données,
vérifier aussi la migration depuis l'état déjà livré sur une base isolée ;
un test de création sur base vierge ne couvre pas cette compatibilité.

Lire le [guide d'intégration](../../src/tests/Integration/README.md) avant toute
préparation. `start.sh` provisionne le schéma `tycms_test_main` et son
compte limité `tycms_test_runner` sans effacer les tables. Les variables
`TYCMS_TEST_DB_*` fournies au conteneur PHP définissent le contexte isolé.

```bash
# Seulement si une préparation ou remise à zéro de la base de test est nécessaire.
./docker/dev/php.sh tools/test_db_prepare.php --reset

# Sur les fixtures dédiées, déjà préparées.
./docker/dev/php.sh releases/vdev/vendor/bin/phpunit --configuration releases/vdev/phpunit.xml --testsuite integration
```

La première commande est **destructive pour la base de test** et sa racine
runtime. Elle ne doit jamais viser la base du site. Ne pas la lancer par
habitude si une base dédiée utilisable existe déjà. `composer test:db:prepare`
contient lui aussi `--reset` ; ce n'est pas une vérification en lecture seule.

Le banc utilise cette même classe de test et sa base, jamais
`src/releases/vdev/configuration.php`.
Les noms de base et d'utilisateur doivent respecter `tycms_test_*` ;
l'hôte est local ou le service exact `mariadb`. Le compte SQL doit avoir des
droits limités au schéma de test. Médias, logs, temporaires et verrous restent
sous `src/tests/.runtime/<nom-base>/`.

Ne pas faire tourner deux suites qui modifient le même schéma en parallèle.
Un worker séparé exige sa propre base et son runtime. Un rollback externe
n'isole pas les migrations MySQL et les transactions internes des routes :
conserver les fixtures et le verrou de migration du banc.

## Contrôle du diff

```bash
git diff --check
```

Certains fichiers du dépôt utilisent CRLF. Préserver leurs fins de ligne lors
d'une modification ciblée. Si Git signale chaque ligne CRLF comme un espace
final, utiliser aussi le contrôle adapté :

```bash
git -c core.whitespace=cr-at-eol diff --check
```

Inspecter `git diff --stat` et `git diff` : pas de secret, configuration locale,
fichier runtime ni modification étrangère au chantier. Les fichiers nouveaux
non suivis ne figurent pas dans `git diff` ; les relire également.

## Essais manuels et compte rendu

Confier à Yann les essais visuels simples : un écran, un raccourci, une modale,
la sauvegarde/réouverture ou une navigation publique précise. Éviter de mettre
en place un banc navigateur complexe pour ce qu'il peut vérifier directement.
Les parcours externes encore à exercer sont listés dans les
[travaux restants](TRAVAUX_RESTANTS.md#validations-manuelles-utiles-selon-le-chantier).

Rapporter les commandes réellement exécutées, leur résultat et les limites
restantes. Un test unitaire ne valide pas le SMTP réel, un fournisseur IA,
le retour intersite OIDC ou un rendu navigateur. Conserver les résultats dans
la réponse ou la PR ; ce fichier reste un mode d'emploi, sans journal daté.
