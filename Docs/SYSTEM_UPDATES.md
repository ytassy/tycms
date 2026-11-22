# Consultation et préparation des mises à jour système

L'administration Core propose l'onglet **Mises à jour** de l'écran **Système**,
réservé à ROOT sur l'espace racine. Le bouton **Vérifier les mises à jour** consulte le
catalogue et les notes. Un bouton distinct **Télécharger et vérifier le ZIP**
conserve le paquet authentifié dans un cache privé. Le bouton **Préparer la mise à jour**
extrait ce paquet, installe le moteur autonome et fournit son lien secret.
La préparation enregistre une opération sans changer la release active, la
configuration ou la base de données.

## Accès et déclenchement

Le menu vérifie `ROOT` sur le Space `1`. Les méthodes de `WFSystemUpdate`
vérifient aussi `coreAclHelper->isRoot()` avant de lire la configuration ou
d'effectuer un appel réseau, indépendamment du Space courant.

| Route | Rôle |
| --- | --- |
| `GET /administrator/api/system-updates` | Version installée, PHP, serveurs configurés, disponibilité de la simulation et erreur éventuelle de configuration. Aucun appel distant. |
| `POST /administrator/api/system-updates/check` | Vérification explicite, protégée par le CSRF commun des API. Paramètre facultatif `sourceVersion`, réservé à la release `dev`. |
| `POST /administrator/api/system-updates/reset` | Réinitialisation ROOT, protégée par le CSRF commun, sans chemin ni paramètre fourni par le navigateur. |
| `POST /administrator/api/system-updates/cleanup` | Nettoyage ROOT après clôture : retire les fichiers de l'opération, sa sauvegarde locale et les versions numériques inactives avec leurs assets. |
| `POST /administrator/api/system-updates/prepare` | Préparation ROOT protégée par le CSRF commun. Même identité `version`, `sha256` et éventuelle `sourceVersion` que le téléchargement. |
| `POST /administrator/api/system-updates/download` | Téléchargement explicite, protégé par le CSRF commun et ROOT dans le WF. Paramètres `version`, `sha256` et, uniquement en `dev`, `sourceVersion`. |

Les appels passent par `admin_system_updates.js` et le client API commun.
L'ouverture de l'onglet détaillé ne lance aucune recherche distante. Le tableau de bord
d'administration lance une recherche à chaque ouverture pour ROOT et affiche
aussi si un nettoyage est disponible. En release `dev`, il utilise la source
simulée `1.0.0` et identifie le résultat comme un essai. Cette recherche ne
modifie pas le résultat ni les actions de l'onglet détaillé. Un nouvel essai
efface le résultat précédent, y compris en cas d'échec ; aucun résultat
de consultation n'est enregistré sur disque ou partagé entre requêtes. Les
paquets téléchargés sont en revanche conservés dans le cache décrit ci-dessous.

Le canal de journaux `system.update` conserve les demandes ROOT de
téléchargement, préparation, reset et nettoyage, ainsi que les causes internes
des échecs. Les entrées INFO décrivent une demande, sans garantir son succès.
Aucune URL ni lien secret d'updater n'est enregistré ; voir [Journaux](LOGGING.md).

## Configuration et confiance

La classe `ConfigurationBase` de `configuration.base.php` fournit `update_servers`
et `update_trusted_public_keys`. Le fichier local `configuration.php` les hérite
ou les surcharge explicitement. Les installations existantes doivent adopter
manuellement cet héritage ; voir le [contrat de configuration](ARCHITECTURE.md#configuration-locale-et-valeurs-par-défaut).
Une configuration
absente ou invalide produit un message dans l'écran ; l'application conserve
son fonctionnement habituel. PHP doit disposer de `curl` et `sodium`.

En Docker de développement, le fichier effectif est
`src/releases/vdev/configuration.php`. Son constructeur utilise les variables
d'environnement Docker pour la base et la clé, puis appelle le constructeur
parent pour normaliser la configuration. Les serveurs et la clé publique sont
hérités de la base, sauf surcharge locale.

Les destinations proviennent exclusivement de la configuration serveur (base
livrée et surcharges locales), jamais du navigateur ou du catalogue. Elles doivent être en HTTPS, sans identifiants,
query ni fragment. Le certificat et le nom d'hôte sont vérifiés ; aucune
redirection n'est suivie. Aucun token de publication, cookie ou clé privée
n'est envoyé par le client Tycms.

Le [protocole de distribution](../update_server/README.md#protocole-http-schéma-1)
définit les enveloppes et leurs signatures. Le consommateur
`Helper/SystemUpdate/ManifestVerifier.php` est livré dans Core ; il ne dépend
pas des fichiers du serveur de distribution, absent des installations Tycms.
La signature des octets décodés est vérifiée avec une clé déjà reconnue
avant d'interpréter le payload. Une clé fournie par un manifeste n'est jamais
ajoutée à la confiance locale.

## Sélection et serveurs de secours

Le client demande le catalogue complet et vérifie **toutes** ses enveloppes.
Le serveur de distribution ne sert par défaut que les versions en prod : une
version en attente d'approbation et une version en bêta n'y figurent pas. Le
catalogue interrogé dépend du réglage local `update_channel` :

- `prod` (défaut) : le client demande `?action=releases&channel=prod` et
  **écarte** toute release dont le manifeste signé indique la bêta ;
- `beta` : il demande `?action=releases&channel=beta` et accepte prod et bêta.

Le canal vient exclusivement de `configuration.php`, jamais d'un paramètre de
requête ni d'un champ de formulaire. Un manifeste `schema_version: 1` est lu
comme une release de prod ; le schéma `2` porte le canal, obligatoire.
Sur une release stable, il filtre ensuite localement : une cible doit être strictement supérieure à la
source, accepter la source via `source_versions` et accepter le PHP réel :
borne minimale incluse, borne maximale exclue. La première installation reste
toujours permise ; `"*"` couvre en plus toute version antérieure. Une liste de
sources vide ne propose donc aucune mise à jour. La dernière version compatible
est la plus grande version supérieure admissible ; les autres versions
compatibles sont signalées, sans action d'installation.

- Une panne réseau ou une réponse HTTP différente de `200` permet d'essayer
  le serveur suivant. Les indisponibilités précédentes sont affichées dans
  un résultat obtenu depuis un serveur de secours.
- Un catalogue valide vide ou sans cible compatible termine la recherche.
- Un JSON, schéma, manifeste ou signature invalide, une clé inconnue, une
  version dupliquée ou une réponse trop volumineuse arrêtent la vérification
  avec une erreur, sans consulter un autre serveur.
- Les notes de la dernière version compatible sont téléchargées et comparées à leur
  taille et leur SHA-256 signés. Leur texte doit être UTF-8 et reste affiché
  comme texte, sans rendu HTML ni exécution du Markdown.
- Si les notes sont indisponibles, les serveurs suivants peuvent fournir les
  mêmes octets authentifiés. Des notes altérées arrêtent la vérification.
  Si tous les téléchargements de notes échouent, la version reste proposée
  avec un avertissement et sans notes. Une ancienne release sans notes reste
  consultable.

La consultation accepte au plus huit serveurs et 256 releases. Le catalogue
est limité à 1 Mio, une enveloppe à 64 Kio, les notes à 256 Kio et l'archive
déclarée à 128 Mio. La connexion est limitée à trois secondes, chaque
requête à huit secondes et l'ensemble à vingt secondes. Les URL actuelles
partagent le même hébergement ; elles ne couvrent pas une panne de celui-ci.

## Développement et version livrée

Sur une release stable, seule `APP_VERSION` détermine la source : fournir
`sourceVersion` est refusé. En release `dev`, l'écran demande explicitement
une version stable de simulation, préremplie à `1.0.0`. Le PHP utilisé reste
celui du serveur local et le résultat est signalé comme simulé.

Pour tester l'UI sans publier de fausse release, `dev` propose uniquement la
dernière version réellement publiée, même si elle est égale ou antérieure à
la source simulée ou n'inclut pas cette source. Le mode d'essai est signalé
dans l'écran ; le bouton de téléchargement se trouve avant les notes.
Le téléchargement du ZIP est réel et peut ainsi tester une release d'installation
initiale comme `1.0.0`. Le téléchargement seul reste sans extraction ni installation. La préparation
est une action distincte décrite ci-dessous. Les bornes
PHP, la signature, l'identité, la taille, le SHA-256 et la structure du ZIP
restent contrôlés ; une version incompatible avec PHP est affichée mais son
téléchargement est refusé.

Ce mode vient exclusivement de `APP_VERSION === 'dev'`, vérifié dans le WF
et dans le client technique. Un paramètre du navigateur ou `Configuration.dev`
ne peut pas l'activer sur une release stable. Le serveur de distribution
reste inchangé : aucun paramètre `dev` ni redéploiement n'est nécessaire.

`version.json` définit la prochaine distribution `1.1.0`, compatible depuis
`1.0.0` ; les constantes du développement restent `dev`. Cette évolution ne
modifie aucune migration existante. Le processus de mise à jour, y compris
ses essais réels depuis DEV, sera traité séparément ; la simulation actuelle
ne décide pas de l'activation ni de l'exécution future des migrations.

## Téléchargement et cache privé

Le navigateur envoie la version affichée et l'empreinte SHA-256 attendue,
jamais une URL ou un chemin disque. Au clic, le serveur récupère à nouveau
le manifeste signé depuis les serveurs configurés, puis revérifie la clé,
la signature, la source et les bornes PHP réelles. Seul le contrôle de source
est levé dans le mode d'essai de la release `dev` décrit ci-dessus. Un changement de version
ou d'empreinte est refusé ; l'utilisateur doit refaire la recherche. Une cible
compatible déjà affichée peut rester téléchargeable si une version plus récente
a été publiée entre-temps : le clic conserve son identité attendue.

PHP doit aussi disposer de `zip`. Son absence désactive seulement le bouton
de téléchargement. Les accès techniques sont dans `SystemUpdatePackageHelper`,
exposé par `TraitCoreAdditionalHelpers` ; ROOT est contrôlé dans `WFSystemUpdate`
avant tout appel au helper, y compris en simulation.

Le ZIP est transféré en flux dans un dossier privé aléatoire
`var/updates/packages/.incoming-<aléa>/`, sans chargement complet en mémoire.
La limite signée, au plus 128 Mio, est appliquée pendant le transfert. Une
coupure ou une erreur HTTP permet d'essayer le serveur suivant, en remettant
le fichier partiel à zéro et en conservant l'identité signée du paquet.
HTTPS et le certificat sont vérifiés ; les redirections ne sont pas suivies.
Chaque connexion est limitée à cinq secondes, chaque transfert à soixante
secondes et l'ensemble des transferts du ZIP à cent vingt secondes, après la
vérification du manifeste (vingt secondes au plus). Le transfert reste une
seule requête applicative : les limites HTTP/PHP de l'hébergement s'appliquent
aussi et aucune reprise par plage d'octets n'est proposée.

La taille exacte et le SHA-256 sont contrôlés avant une inspection par
`ArchiveInspector`, sans extraction ni exécution PHP. Celle-ci refuse notamment :

- les traversées de chemin, séparateurs ambigus, doublons, liens symboliques,
  fichiers spéciaux, entrées chiffrées et collisions fichier/dossier ;
- les racines inconnues, releases inattendues, configurations locales et fichiers
  `.git` ou `node_modules` ;
- l'absence de fichiers système requis ou une version interne différente du
  manifeste, lue comme texte sans inclure le PHP embarqué ;
- plus de 100 000 entrées, plus de 1 Gio décompressé déclaré, un chemin dépassant
  1 024 octets ou 64 composants, ou plus de 16 Mio de noms cumulés.

Cette inspection structurelle précède aussi l’extraction sécurisée lors de la préparation.
Une taille, empreinte ou structure invalide arrête la tentative sans basculer
sur un autre serveur. Les erreurs ordinaires suppriment son dossier temporaire.
Une interruption forcée de PHP peut laisser un `.incoming-*` privé, jamais
considéré comme un paquet disponible ; le reset ROOT permet de le supprimer.

Après validation, `package.zip` et le `manifest.json` signé sont rendus visibles
ensemble par renommage atomique sous
`var/updates/packages/v<version_string>-<sha256>/`. Les dossiers créés sont en
`0700` et les fichiers en `0600`. Chaque composant du chemin de cache doit
être un dossier réel hors de `public/`, sans lien symbolique. Un verrou exclusif
non bloquant empêche les téléchargements concurrents dans cette installation.

Un paquet déjà présent est réutilisé uniquement après récupération d'un
manifeste actuel valide, vérification du manifeste conservé, de l'identité,
de la taille, du SHA-256 et de la structure du ZIP. Un cache altéré produit
une erreur : il n'est pas écrasé silencieusement. Les paquets sont conservés
jusqu’à leur suppression par le reset ROOT ; le téléchargement seul ne crée
aucun état d’opération et ne change pas la release active. Le reçu API contient un identifiant de paquet, sa version,
sa taille, son empreinte, l'indication de réutilisation et les serveurs en panne,
sans chemin absolu ni secret. Il n'est affiché qu'après réussite de tous les
contrôles et est effacé de l'interface à la prochaine recherche.

## Préparation du paquet et du lien secret

La préparation utilise `SystemUpdatePreparationHelper`, exposé par
`TraitCoreAdditionalHelpers`, après le contrôle ROOT dans le WF. Pour une
nouvelle opération, le serveur récupère à nouveau le manifeste signé et vérifie les compatibilités, puis
revérifie le manifeste conservé, la taille, le SHA-256 et la structure du ZIP
local. Un paquet absent doit être téléchargé avec l’action précédente : la
préparation ne transfère pas de ZIP. Les chemins ne viennent jamais du navigateur.

L’extraction se fait en flux dans un dossier privé neuf
`var/updates/.prepare-<aléa>/files`, sans `extractTo()` ni exécution du PHP
embarqué. L’inspection refuse les traversées, liens et collisions avant toute
extraction ; chaque taille réelle et le CRC sont ensuite contrôlés. La limite
réelle cumulée est de 1 Gio. L’espace libre est vérifié pour l’extraction et
la seconde copie du moteur et de ses assets. Les fichiers extraits restent en
`0600` et les dossiers privés en `0700`.

Une fois l’extraction terminée, le dossier est publié par renommage dans
`var/updates/packages/v<version_string>-<sha256>/files`. L’intégralité du ZIP
y est conservée, y compris son bootstrap et son état initial, mais ces deux
fichiers ne remplacent jamais ceux du site. La release cible, sa configuration
et ses assets Core ne sont pas encore installés dans l’arborescence active.

Le moteur `files/updater` et les éventuels assets
`files/public/assets_updater` sont copiés dans des dossiers de préparation,
puis remplacent respectivement `updater/` et `public/assets_updater/` par
renommage de dossiers complets. Les anciens dossiers sont déplacés sous le
dossier privé de préparation pendant cette publication. Les assets publics
ont des dossiers en `0755` et des fichiers en `0644`.

**Si `Configuration.dev` est vrai**, les destinations deviennent
`updater_test/` et `public/assets_updater_test/`, ignorées par Git. Cela ne
change pas les contrôles du ZIP ni les règles de simulation, qui restent liées
à `APP_VERSION === 'dev'`. Le lien public inclut toujours
`updater/index.php`, afin de permettre le développement du moteur local sans
l’écraser avec celui du paquet. En DEV, les fichiers copiés dans les dossiers
`*_test` servent donc à contrôler la préparation, pas à exécuter le moteur.

### Opération persistante et concurrence

Un verrou exclusif non bloquant `var/updates/.prepare.lock` protège la
préparation sur l’installation. Avant de publier les fichiers, une écriture
atomique de `var/releases-states.json` réserve `active_operation` :

- `id` : identifiant aléatoire de 128 bits ;
- `status` : `preparing`, puis `prepared` après publication complète ;
- `source_version`, `version`, `sha256`, `package_id` : identité de l’opération ;
- `development` : destinations de test choisies depuis la configuration ;
- `entrypoint` : nom secret du fichier public, conservé dans cet état privé.

Les autres propriétés de l’état, notamment `active_release` et
`available_update`, sont préservées. `last_update_data.json` reste inchangé.
Une nouvelle demande portant sur la même identité et le même mode réaffiche
le lien d’une opération déjà `prepared`, sans remplacer son moteur. Après un
rechargement de l’écran, refaire la recherche et le téléchargement (cache
réutilisé), puis cliquer sur la préparation permet de retrouver ce même lien.
Toute autre opération existante bloque une nouvelle préparation.

Un échec avant la réservation nettoie les fichiers privés de la tentative.
Un échec ou une interruption après réservation conserve l’opération et ses
fichiers de récupération, sans autoriser une nouvelle installation du moteur.
La poursuite automatique de la préparation reste à réaliser. Le bouton
« Réinitialiser la mise à jour » permet en revanche de l’abandonner et de
nettoyer ses fichiers, y compris après une interruption.
La préparation reste une requête HTTP, soumise aux limites PHP de l’hébergement.

### Point d’entrée temporaire

Le fichier `public/update_<secret>.php` est un script PHP généré, avec un
secret de 256 bits (`bin2hex(random_bytes(32))`). Il se limite à inclure le
chemin fixe `updater/index.php` sans charger l'application principale. Le moteur
valide l'état et l'identité de l'opération, ainsi que le nom du fichier ; une
opération absente, invalide ou révoquée renvoie 404. La version 1.0.0 impose
`status = prepared` pendant l'opération ; les futures étapes seront consignées
dans `var/last_update_data.json`.

Le moteur envoie `Cache-Control: no-store`, `Referrer-Policy: no-referrer` et
`X-Content-Type-Options: nosniff`. La réponse API contenant le lien est également
non stockable ; l'interface affiche le lien comme texte et ouvre le moteur avec
`noopener noreferrer`. Ne pas diffuser ce lien : sa possession constitue l'accès
à l'opération. Aucun envoi par e-mail n'est encore proposé.

La règle Apache autorise seulement `index.php` et
`update_[a-f0-9]{64}.php` à la racine publique. Les autres PHP restent interdits.
Sur une installation déjà déployée, **reporter cette exception dans le
`.htaccess` existant**, puisque les mises à jour ne remplacent pas ce fichier :

```apache
RewriteCond %{THE_REQUEST} \s/+(.+?\.php)([\s?]|$) [NC]
RewriteCond %1 !^(index|update_[a-f0-9]{64})\.php$
RewriteRule ^ - [F,L]
```

## Exécution depuis le lien secret

L'updater est en reconstruction. Le fichier `public/update_<secret>.php` généré
contient uniquement un `require_once` vers `updater/index.php`. Le moteur valide
le nom du lien et l'opération active dans l'état privé, sans session ni ACL de
Tycms ; la révocation de l'opération interdit l'accès même si le fichier existe.
En GET, le moteur fournit une interface autonome Vue ; en POST, ses actions
exigent un jeton et un cookie `SameSite=Strict` propres au chemin. L'interface
sépare explicitement préparation des fichiers, maintenance, sauvegarde,
migrations, activation et remise en ligne. Les actions de récupération sont
aussi disponibles après le lancement des migrations.

La liste marque les étapes terminées d'une coche ; une sauvegarde omise est
signalée comme telle, sans coche. L'API conserve cette distinction après le
lancement des migrations avec `backup: completed`, `backup: partial` ou
`backup: skipped`. Une sauvegarde partielle est signalée sans coche.

La préparation est une requête distincte : sous verrou, elle revérifie le
SHA-256 du ZIP authentifié, extrait uniquement la release cible et ses assets,
contrôle les chemins, types, tailles et CRC, puis copie la configuration source
sans l'exécuter. Une cible déjà présente est refusée. Les dossiers sont publiés
séparément avec une marque associée à l'opération ; le bootstrap, la release
active, les extensions et les données locales ne changent pas. La progression
privée permet de reprendre une copie ou une publication interrompue, toujours
par une nouvelle requête explicite. Une erreur HTTP ne prouve pas l'arrêt du
traitement : actualiser l'état avant d'agir à nouveau.

L'activation de maintenance est une autre requête : elle contrôle que la
release active et sa configuration sont toujours celles préparées, conserve
l'état initial et bascule la propriété littérale `Configuration::$offline`
d'abord dans la cible puis dans la source. Le fichier est remplacé atomiquement
et son cache PHP invalidé. En Docker DEV, la configuration source est celle
de `vdev` et Apache utilise l'identité de l'utilisateur hôte, voir le
[guide Docker](../docker/dev/README.md#configuration-et-données). Une valeur calculée ou une configuration qui ne peut
pas être réécrite bloque l'étape. Les requêtes déjà engagées peuvent encore
écrire : attendre leur fin avant une future sauvegarde.

Avant toute modification SQL, l'annulation restaure la valeur initiale de
maintenance, supprime les seuls dossiers cibles portant la marque de l'opération
et ses fichiers de préparation, révoque le lien et libère l'opération. Une
annulation interrompue est reprenable. Le ZIP en cache reste disponible ; son
extraction est retirée pour permettre une nouvelle préparation. Une sauvegarde
terminée et ses métadonnées privées sont conservées après annulation.

Après maintenance, la sauvegarde de la base constitue une requête distincte.
Elle est vivement conseillée mais facultative : l'utilisateur peut choisir de
continuer sans elle après un avertissement explicite. Les accès et limites de
la bibliothèque sont décrits dans le [guide MariaDB](MARIADB_BACKUP.md). Les
sauvegardes privées `var/updates/<id>/backup/backup.jsonl` et `backup.sql`
sont accompagnées de `metadata.json` : opération, release source, cible, tailles, SHA-256, nombre de
tables et empreinte de l'identité de la base, sans mot de passe. L'état privé
conserve aussi ces informations. L'interface affiche les chemins sur le serveur
après la sauvegarde ; le SQL permet un import manuel selon le guide MariaDB.
Si les droits ne permettent pas de garantir une sauvegarde complète, les deux
fichiers sont marqués partiels, les droits manquants sont affichés et la
restauration automatique est interdite par le serveur et par la bibliothèque.
Avant les migrations, l'interface demande de confirmer la disponibilité d'une
sauvegarde externe complète. Sa présence n'est pas vérifiée par le serveur.
Les sauvegardes plus anciennes peuvent ne contenir que le JSONL. Après une erreur ou interruption, l'utilisateur
peut relancer explicitement la sauvegarde ou choisir de l'omettre ; le verrou
empêche toute action concurrente tant que la requête initiale fonctionne.

Les migrations sont une autre requête, acceptée seulement après sauvegarde
terminée ou omission explicite. Source et cible doivent toujours être en
maintenance et leurs configurations identiques. Si une sauvegarde existe, sa
taille, son empreinte, ses métadonnées et l'identité de la base cible sont
vérifiées. Le moteur enregistre `migrating` avant de charger exclusivement le
code cible et d'appeler `Application::runMigrationsOrThrow()`, sans session ni
route. `RESET_DATABASE` doit être désactivé. Les sorties et les détails des
exceptions des extensions ne sont pas exposés au navigateur.

Une réussite passe à `migrated` ; une erreur à `migration_failed`. Une coupure
peut laisser `migrating` même si PHP a continué à travailler : aucune relance
ni annulation simple ne sont autorisées. Le site reste en maintenance.

L'activation est une requête distincte. Elle vérifie le journal des migrations,
puis initialise le code cible sans session HTTP avec propagation des erreurs ;
ce contrôle charge les extensions et leurs événements de démarrage. Il ne teste
pas tous les parcours ou une requête HTTP réelle. Si le contrôle réussit, l'état
des releases bascule atomiquement sur la cible, toujours en maintenance. En cas
d'échec ou d'interruption, la phase enregistrée permet une nouvelle tentative
explicite ou une récupération, sous le même verrou.

Une autre requête termine l'opération en rétablissant dans la cible l'état de
maintenance initial : un site déjà hors ligne reste hors ligne. La clôture
révoque le lien secret et rend disponible le nettoyage ROOT, qui supprime le
paquet, le lien, le moteur et le dossier d’opération avec sa sauvegarde locale,
et conserve les releases. Les états intermédiaires rendent la finalisation
reprenable après interruption ; le statut indique si la dernière réponse n'a
pas pu être reçue.

Après le lancement des migrations et avant la demande de finalisation, l'utilisateur peut
restaurer le JSONL interne en une requête. L'identité de la base, la maintenance,
les métadonnées et l'intégrité du JSONL sont contrôlées ; le SQL manuel n'est
pas nécessaire à cette restauration. L'état `restore_running` précède toute
modification ; une erreur laisse le site en maintenance et la base peut être
partiellement restaurée. Une nouvelle tentative est explicite. Après succès,
une action distincte revient à la release source, rétablit son état de
maintenance initial, nettoie les fichiers préparés et révoque le lien.

L'utilisateur peut aussi restaurer lui-même une sauvegarde externe puis
confirmer son retour à la source. Cette confirmation ne vérifie pas la base :
elle engage sa responsabilité. Le verrou empêche une récupération concurrente,
mais une erreur HTTP ne prouve pas que la requête initiale soit terminée ;
actualiser l'état et attendre avant toute intervention extérieure. Les étapes de
restauration et de retour peuvent être interrompues ; leur reprise est explicite.

La release source 1.0.0 déjà déployée ne vérifie pas la progression de l'updater
avant son reset ROOT : ne pas utiliser son bouton de réinitialisation après avoir
commencé la préparation depuis le lien, mais utiliser l'annulation de l'updater.

Les assets locaux sous `public/assets_updater/` comprennent Vue runtime,
Bootstrap CSS et vue3-sfc-loader, avec leurs licences. Les composants `.vue`
sont chargés et compilés dans le navigateur, sans Vite ni CDN ; ils sont publics
et ne doivent contenir aucun secret. Le mode DEV utilise toujours les assets
locaux `assets_updater`, comme le moteur `updater` local ; les copies `*_test`
servent uniquement à vérifier la préparation du paquet.

La CSP autorise les scripts du site et `unsafe-eval`, nécessaire à la compilation
dynamique des composants, ainsi que les connexions au site. Les styles statiques
sont locaux et les styles injectés par le chargeur reçoivent un nonce. Le
chargeur refuse les composants hors du dossier d'assets. Les réponses restent
`no-store` et `no-referrer` ; le contrôle d'origine et le verrou partagé avec
préparation et reset sont appliqués dans l'updater. Les étapes restantes figurent
dans le [chantier](IA/CurrentWorks/MISES_A_JOUR_SYSTEME.md#lots-de-réalisation).

## Nettoyage après clôture

Le bouton **Nettoyer l’opération terminée** est proposé à ROOT lorsque
`active_operation` vaut `completed`, `rolled_back`, `cancelled` ou `cleaning`.
`SystemUpdatePreparationHelper::cleanup()` prend les mêmes verrous que la
préparation et le reset, vérifie l’identité de l’opération et sa progression,
puis valide son dossier avant d’écrire le statut `cleaning` et de supprimer
quoi que ce soit.

Le nettoyage retire le point d’entrée, le paquet en cache, le moteur et ses
assets, puis le dossier `var/updates/<id>` avec son espace de travail et sa
sauvegarde locale. Il supprime aussi les dossiers de versions numériques autres
que la release active dans `releases/` et `public/assets_core_v*/`, y compris
leurs configurations ; cette suppression s'applique en production et en DEV.
Les dossiers `releases/vdev` et `public/assets_core_vdev` sont toujours conservés.
La release active doit correspondre à la release qui exécute le nettoyage et
son dossier doit être réel ; les noms inconnus restent intacts et un lien ou un
fichier à la place d'une ancienne version bloque le nettoyage avant suppression.
Le mode DEV doit correspondre à celui de l'opération. Les autres données du site
restent intactes. Comme pour le reset, un dossier d’opération contenant autre chose
que `backup` ou `stage` bloque le nettoyage avant toute suppression. Une
interruption reste reprenable par ce même bouton grâce au statut `cleaning`.

## Réinitialisation depuis Core

Le bouton **Réinitialiser la mise à jour** est disponible pour ROOT sur
l’espace racine, en DEV comme en production, y compris sans consultation du
catalogue ou si les serveurs de distribution sont mal configurés. La modale
de confirmation commune précède le POST ; le WF contrôle ROOT avant tout
accès au disque. Aucun appel réseau ni changement SQL n’est nécessaire.

`SystemUpdatePreparationHelper::reset()` prend les verrous exclusifs non
bloquants de préparation et de téléchargement, dans cet ordre. Si l’un est
occupé, la demande est refusée avant toute suppression ou modification JSON.
Les fichiers `.prepare.lock` et `packages/.download.lock`, ainsi que leurs
dossiers parents, restent en place : supprimer un verrou permettrait à deux
requêtes de verrouiller deux inodes différents.

La réinitialisation porte exclusivement sur :

- les paquets `packages/v<version_string>-<sha256>` et les transferts
  `.incoming-<aléa>` interrompus, avec leurs fichiers extraits ;
- les dossiers d’opération `var/updates/<id>` et leur sauvegarde privée,
  y compris les restes d’anciennes opérations non nettoyées ;
- les préparations `.prepare-<aléa>` interrompues sous `var/updates`, avec les
  copies et anciens moteurs qu’elles contiennent ;
- les scripts racines `public/update_<64 caractères hexadécimaux>.php` et les
  fichiers temporaires `var/.state-<aléa>` ;
- `updater/` et `public/assets_updater/` en production ; seulement
  `updater_test/` et `public/assets_updater_test/` si `Configuration.dev` est vrai.

Les dossiers d’opération `var/updates/<id>/` ne peuvent contenir que les
sous-dossiers `backup` et `stage` ; tout autre élément empêche le reset ou le
nettoyage avant modification des JSON. Leur suppression est complète, sauvegarde
comprise. Le nettoyage d’une opération close retire déjà ce dossier ; le reset
traite les restes d’anciennes opérations. Les parents du stockage sont vérifiés
sans lien symbolique. Lors d’une suppression récursive, un lien symbolique est
supprimé lui-même, jamais suivi. Tout élément non reconnu sous `var/updates` ou
`packages` fait refuser le reset ou le nettoyage avant modification des JSON :
ce garde-fou empêche de supprimer une structure inconnue. Les releases,
configurations, extensions, médias, bootstrap et règles Apache sont conservés.
Les sources de l’updater et de ses assets sont préservées en DEV.

Une sauvegarde locale supprimée par le reset ou le nettoyage n’est plus
restaurable depuis l’interface : copier `backup.sql` au préalable si une
restauration manuelle reste nécessaire.

Seuls l’absence d’opération et les états `preparing`, `prepared` ou
`resetting` autorisent ce reset. Un état ultérieur ou inconnu est refusé ;
la progression autonome doit encore être vide. Les futures étapes de migration et de
restauration devront être récupérées depuis le lien secret. Un changement du mode DEV
depuis la préparation est aussi refusé afin de ne pas supprimer le mauvais
moteur.

Avant toute suppression, l’écriture atomique de `active_operation.status`
à `resetting` révoque les liens secrets existants. En cas d’échec ou de
limite HTTP/PHP, cet état persiste et le même bouton peut reprendre le
nettoyage. Après réussite, `last_update_data.json` vaut `{}`, puis
`available_update` et `active_operation` sont remis à `null` par écriture
atomique. `schema_version`, `active_release` et les autres propriétés de
l’état restent inchangés. L’interface efface le résultat de recherche, le reçu
de téléchargement et le lien préparé dès le lancement du reset.

## Vérification

```bash
./docker/dev/test.sh tests/Unit/Plugins/Core/SystemUpdateTest.php
./docker/dev/test.sh tests/Unit/Plugins/Core/SystemUpdatePackageTest.php
./docker/dev/test.sh tests/Unit/Plugins/Core/SystemUpdateResetTest.php
./docker/dev/npm.sh run build
```

Les tests utilisent des clés et des réponses factices, sans SQL ni serveur
distant. Ils couvrent ROOT, la simulation limitée à `dev`, les signatures,
les compatibilités, les notes et la distinction entre panne et contenu
invalide. Les tests de paquets couvrent en plus le cache, les interruptions,
la concurrence, les ZIP dangereux et le maintien des protections en mode
d'essai DEV. Un processus isolé vérifie aussi qu'une release stable refuse ce
mode. Les tests de reset et de nettoyage couvrent la conservation de la release
et des données, la suppression des dossiers d’opération et de leurs sauvegardes,
les destinations DEV/production, les liens symboliques, les verrous, les refus
d’états inconnus et la reprise après une suppression interrompue. Les essais
visuels et le transfert réel
sur l'hébergement se font avec Yann dans le menu d'administration.
