# Tests d'intégration

Une base MySQL/MariaDB dédiée est nécessaire. Le banc n'utilise jamais
`src/releases/vdev/configuration.php`. Copiez `src/tests/test_db.local.php.example` vers
`src/tests/test_db.local.php` (ignoré par Git) et renseignez un compte SQL qui
n'a des droits que sur une base `tycms_test_*`, sur l'hôte local
ou le service Docker nommé exactement `mariadb`.
Les variables `TYCMS_TEST_DB_HOST`, `TYCMS_TEST_DB_NAME`,
`TYCMS_TEST_DB_USER` et `TYCMS_TEST_DB_PASSWORD` peuvent remplacer
ce fichier. Si l'une est définie, les quatre sont obligatoires.

Depuis la racine du dépôt :

```powershell
composer --working-dir=src/releases/vdev test:db:prepare
php -d xdebug.mode=off src/releases/vdev/vendor/bin/phpunit --configuration src/releases/vdev/phpunit.xml --testsuite integration
```

La préparation exige explicitement `--reset` dans la commande sous-jacente,
supprime les tables et vues **de cette base de test uniquement**, relance les
vraies migrations et installe les fixtures. Elle efface aussi la racine de
fichiers `src/tests/.runtime/<nom-base>/`, ignorée par Git. N'exécutez pas
deux suites qui modifient cette base en parallèle ; attribuez une base distincte
à chaque worker si nécessaire. La suite unitaire reste indépendante de MySQL :

```powershell
php src/releases/vdev/vendor/bin/phpunit --configuration src/releases/vdev/phpunit.xml --testsuite unit
```

Les fixtures conservent les données de migration : Space racine 1, Space site 2,
menus, pages et administrateur. Elles donnent `fr` au Space racine et `fr`/`en`
au Space site. Le préparateur affecte l'hôte
`site.integration.test` au Space 2. Les IDs utilisés dans les tests sont définis
dans `TestFixtures`. Les tests peuvent nettoyer leurs seules données métier entre
scénarios ; les migrations et changements de schéma demandent une nouvelle
préparation explicite.

## Exécution dans Docker

Depuis la racine, lancer `./docker/dev/start.sh` (avec sudo si nécessaire).
Le démarrage crée le schéma `tycms_test_main` et un compte
`tycms_test_runner` limité à ce schéma, sur la même instance MariaDB
que le site. Cette étape ne réinitialise aucune table. Les quatre variables
`TYCMS_TEST_DB_*` sont fournies au conteneur PHP et prennent priorité
sur un éventuel `test_db.local.php`. Le mot de passe est défini par
`TEST_DB_PASSWORD` dans `docker/dev/.env`.

```bash
./docker/dev/php.sh tools/test_db_prepare.php --reset
./docker/dev/php.sh releases/vdev/vendor/bin/phpunit --configuration releases/vdev/phpunit.xml --testsuite integration
./docker/dev/php.sh releases/vdev/vendor/bin/phpunit --configuration releases/vdev/phpunit.xml --testsuite unit
```

Les chemins des arguments PHP sont relatifs à `src` dans le conteneur.
La préparation reste explicite et la suite unitaire reste sans SQL.
Le runtime est monté dans `src/tests/.runtime`, déjà ignoré par Git.
L’hôte exact `mariadb` est ajouté aux hôtes de boucle locale autorisés ;
les autres noms distants restent refusés.
