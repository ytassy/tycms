# Bibliothèque de sauvegarde MariaDB

`src/updater/Lib/MariaDbBackup/DatabaseBackup.php` est une bibliothèque autonome
du moteur de mise à jour. L'updater l'utilise pour la sauvegarde facultative et
la restauration interne depuis son interface.
Elle prend une connexion PDO MySQL **dédiée**, déjà connectée à la base MariaDB
sélectionnée ; elle modifie les paramètres de cette session et ne charge pas
la configuration ou l'autoload d'une release.

```php
require_once 'updater/Lib/MariaDbBackup/DatabaseBackup.php';

$backup = new \Tycms\Updater\Lib\MariaDbBackup\DatabaseBackup($pdo);
$receipt = $backup->backup($privatePath);
// Conserver size et sha256 dans un état privé de confiance, séparé du fichier.
$backup->restore($privatePath, $receipt['size'], $receipt['sha256']);
```

## Sauvegarde

Le fichier est créé exclusivement dans un dossier privé existant, de droits
`0700` ou plus restrictifs, hors de la racine HTTP ; la responsabilité du
choix et du confinement de ce dossier appartient à l'appelant. Le fichier est
créé en `0600`, sans écraser un fichier existant. Toute erreur supprime le
fichier incomplet. Les octets sont écrits progressivement, synchronisés et
fermés avant de renvoyer la taille et l'empreinte SHA-256. Une ligne SQL très
volumineuse doit néanmoins tenir en mémoire pour son encodage.

Le format interne JSONL versionné est réservé à cette bibliothèque : les
identifiants, définitions SQL et valeurs sont encodés en base64 ; ce n'est pas
un importeur de dumps SQL arbitraires. L'inventaire est dynamique pour toute la
base configurée, extensions comprises : tables, données, vues, déclencheurs,
fonctions, procédures et événements, sous réserve des droits détaillés ci-dessous.
Les colonnes invisibles sont explicitement
exportées ; les colonnes générées sont recalculées au retour. Les définitions
des objets conservent leur `DEFINER`, mode SQL, jeu client, collation de
connexion et fuseau des événements. La définition et les données sont lues
avec un instantané transactionnel et une connexion en UTC.

Seules les tables InnoDB sont acceptées. Les séquences, tables à historique système et types de tables
inconnus sont refusés, ainsi que les définitions de table ou CHECK que le
séparateur ne sait pas interpréter. Les clés étrangères et CHECK sont retirées
des créations de tables et inscrites sous forme d'`ALTER TABLE` en fin d'archive.
Clés primaires et uniques restent dans les créations de tables.

L'updater produit également `backup.sql` à partir de ce même fichier JSONL,
après vérification de sa taille et de son empreinte. Ce second fichier privé
est créé en `0600` dans le même dossier ; sa taille et son SHA-256 sont
conservés séparément. La sauvegarde n'est considérée comme terminée qu'après
la création des deux fichiers.

La maintenance de l'application ne suffit pas à arrêter les requêtes déjà
engagées, les tâches externes et les événements MariaDB. L'opérateur doit
empêcher les écritures concurrentes pertinentes et tout DDL pendant la
sauvegarde : une transaction InnoDB ne protège pas le schéma contre un DDL
concurrent. Les données non couvertes par cette base ne sont pas sauvegardées.
Aucune reprise ou adaptation aux limites de durée HTTP n'est prévue.

## Droits requis

Pour considérer une sauvegarde comme complète lorsque MariaDB peut masquer
des objets, le compte effectif doit posséder directement, au niveau global ou
du schéma, `SELECT`, `SHOW VIEW`, `TRIGGER`, `EVENT`, `ALTER`, `CREATE`, `DROP`,
`INSERT`, `CREATE VIEW`, `CREATE ROUTINE`, `ALTER ROUTINE` et `REFERENCES`.
Il doit aussi avoir `SHOW CREATE ROUTINE` ou le droit `SELECT` sur `mysql.proc`.
Les privilèges accordés uniquement par rôle ne sont pas actuellement reconnus
par ce contrôle conservateur. Le droit `SELECT` sur le schéma reste obligatoire.
Si les autres droits manquent, la sauvegarde est marquée partielle dans son
en-tête et son résultat. Les vues sans `SHOW VIEW`, déclencheurs sans `TRIGGER`,
événements sans `EVENT` et routines sans droit de lecture de leurs définitions
sont omis ; ces objets peuvent exister sans être visibles. Les autres droits
manquants peuvent empêcher la restauration même si l'export les contient.
Une définition visible mais illisible fait échouer l'export. L'export SQL porte
un avertissement si la sauvegarde est partielle. Il ne constitue pas une
restauration complète : conserver une sauvegarde externe complète. La
restauration automatique refuse une archive partielle avant tout effacement,
même si les droits du compte ont ensuite été étendus.
La restauration d'un `DEFINER` différent du compte effectif exige `SET USER` ou
`SUPER` global. Aucun privilège n'est ajouté automatiquement. Selon la version
de MariaDB et la configuration de l'hébergement, d'autres limitations peuvent
encore empêcher la recréation d'un objet.

## Restauration

### Import manuel SQL

Pour restaurer manuellement `backup.sql`, utiliser le client en ligne de
commande MariaDB avec une base préalablement vidée portant **le même nom que
la base d'origine**. Par exemple :

```sh
mariadb -u UTILISATEUR -p NOM_BASE < /chemin/vers/backup.sql
```

Le compte doit disposer des droits nécessaires à la création des objets et à
leurs `DEFINER` ; aucun utilisateur ni privilège n'est recréé. Le script vérifie
le nom et l'absence d'objets visibles avant de commencer, mais la visibilité
des objets dépend des droits du compte : vérifier soi-même la base cible et
son contenu. Ne pas utiliser `--force`, qui contournerait l'arrêt sur erreur.
Le script ne vide pas la base ; il crée des tables de substitution pour les
vues dépendantes puis les remplace par les vues définitives. Contraintes CHECK
et clés étrangères sont ajoutées après les données et les objets.

Le fichier peut contenir plusieurs jeux de caractères pour préserver les
définitions d'origine. Son import a été vérifié avec le client MariaDB ; la
compatibilité avec d'autres outils d'import n'est pas garantie. Une interruption
peut laisser une base partiellement reconstruite : la vider à nouveau avant un
nouvel import. Protéger ce fichier comme le JSONL, car il contient les données
et définitions de la base.

### Restauration par la bibliothèque

L'appelant fournit la taille et l'empreinte conservées séparément dans un état
privé fiable. La bibliothèque vérifie le fichier entier, son format, sa fin,
son schéma cible, ses objets et les droits **avant de supprimer quoi que ce
soit**. L'empreinte détecte une altération : elle n'authentifie pas une archive
si un tiers peut modifier aussi l'état de confiance. Le fichier doit rester
privé et ne doit jamais provenir d'une entrée arbitraire du navigateur.

La restauration vide le schéma courant puis recrée les tables et recharge les
données. Elle rétablit les routines, vues, déclencheurs et événements, puis
applique les CHECK et clés étrangères en dernier, avec les contrôles de clés
étrangères activés. Les tables à références croisées existent ainsi toutes
avant l'ajout de ces contraintes. Les vues dépendant d'autres vues sont
recréées après celles dont elles ont besoin. Le schéma doit porter exactement
le nom enregistré dans l'archive.

La restauration refuse aussi un schéma courant contenant des types de tables
non pris en charge, avant d'effacer les objets.

Le DDL MariaDB n'étant pas transactionnel, une erreur ou interruption après
le début de la restauration peut laisser la base partiellement reconstruite :
le site doit rester en maintenance et la restauration doit recommencer depuis
le début. Une empreinte correcte ne prouve pas que les données constituent un
instantané cohérent si des écritures ou DDL concurrents ont eu lieu. L'updater conserve séparément l'état et la preuve d'intégrité, contrôle la
maintenance et ne présente pas au navigateur les détails sensibles des erreurs.
