# Extension LMS

Squelette PHP et bibliothèque Vue ESM, sans tables ni routes métier.
« Liste des cours » est disponible dans la catégorie LMS de l’éditeur, avec
un canevas Vue sans données et sans rendu public. Ce canevas réutilise le mixin
et une directive du Core pour vérifier l’intégration.

La page `lms/home` est enregistrée pour une future `RiaView` ; aucun écran de
formation n’est encore exposé. Les choix métier sont à définir avant les migrations.

Développement : `DEV_EXTENSION=plugins/Lms` dans `docker/dev/.env`, puis
`./docker/dev/start.sh`. Le Core charge alors le frontend sur le Vite dédié.

Build indépendant :

```bash
./docker/dev/extension-npm.sh plugins/Lms ci
./docker/dev/extension-npm.sh plugins/Lms run build
```

Les bundles sont dans `src/public/assets/plg_lms/build` et leur manifeste dans
`src/public/assets/plg_lms/.vite`. Les médias statiques à côté sont conservés. Les autres extensions
peuvent être compilées par le même script sans modifier la sélection DEV.
Voir le [contrat des extensions](../../../../Docs/EXTENSIONS.md).

Pour produire le ZIP de production signé, préparer la clé et la configuration
selon le [guide de build](../../../../build_extensions/README.md), puis lancer :

```bash
./build_extensions/build.sh plugins/Lms
```

`version.json` est la source de vérité de la version du LMS ; `version.md`
contient les notes embarquées dans le paquet. Le build ignore le frontend de
développement dans le ZIP et fournit les seuls bundles nécessaires à l’exécution.
