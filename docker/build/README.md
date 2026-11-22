# Environnement de fabrication

Lancer `./docker/build/build.sh` depuis la racine du dépôt. Les prérequis,
l'étape de préparation et le contenu livré sont décrits dans le
[guide de fabrication](../../build/README.md).

Le Dockerfile réunit PHP CLI 8.4, Composer 2 et Node 24. Les trois images sont
figées par empreinte ; mettre à jour ces références explicitement pour intégrer
leurs correctifs. Les paquets Debian et les bibliothèques système sont installés
à la construction : le build ne promet pas une reproduction binaire identique.
L'extension ZIP de PHP fabrique l'archive.

Le wrapper construit l’image `tycms-build` avec pour seul contexte
`docker/build/`. Un premier conteneur prépare les sources sous `build/temp/`,
avec le dépôt en lecture seule et sans réseau. Le second compile et assemble ;
seuls la copie de travail, le dossier de sortie et les scripts PHP (avec le validateur commun `Protocol.php`) sont montés. Le dépôt et ses secrets ne sont pas accessibles aux commandes
Composer/npm. Les conteneurs s’exécutent avec l’UID/GID local. Aucun identifiant de publication
n'est transmis. L'accès réseau sert à installer les dépendances verrouillées.
La copie de travail est supprimée après un build réussi ; en cas d'échec, elle
reste disponible pour inspection.

Cet environnement est distinct de `docker/dev/` et des éventuelles images
Docker de production, dont la fabrication reste reportée.

Le build des extensions réutilise cette même image avec son propre point
d’entrée PHP. `./build_extensions/build.sh` prépare une extension, compile son
frontend et signe ses artefacts sur l’hôte, après arrêt du conteneur. Ses
sources, son verrou, son dossier temporaire et sa distribution sont distincts
de ceux du build système. Aucune clé n’est montée dans le conteneur npm.
Voir le [guide des extensions](../../build_extensions/README.md).
