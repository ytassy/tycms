# Conception de TyCatalog et du client de catalogues Core

## Statut et reprise du chantier

Ce document conserve le périmètre validé avec Yann, le découpage en lots et la
conception du lot 1. Il doit permettre à un autre agent de reprendre le travail
sans dépendre de la conversation. Ce sont des travaux en cours : les contrats
livrés restent dans [Extensions](../../EXTENSIONS.md) et
[Architecture](../../ARCHITECTURE.md).

Le lot 1 est figé : données, protocole, confiance, publication et règles de
retrait sont arrêtés ci-dessous. Le schéma serveur (quatre tables), les modèles,
les permissions, l’administration des catalogues et de leurs références amies,
le dépôt et la publication des versions signées, l’API publique de
distribution, ainsi que le client Core (sources, clés de confiance, licence,
consultation et installation neuve) sont implémentés. Les mises à jour restent à
réaliser. L’identifiant est `plg_ty_catalog`. Il est chargé par défaut en DEV et
désactivé en production ; un booléen explicite dans
`Configuration.core_extensions_enabled` prime.

Le lot 2 (socle ACL d’instance) est livré. Le lot 3 (administration TyCatalog)
est livré et les endpoints publics de distribution sont disponibles.
L’installation neuve des paquets est livrée ; seules les mises à jour restent à
concevoir.

Consignes de reprise : lire `AGENTS.md`, l'index des guides, cette note et le
code visé. Poser les questions de comportement ou d'architecture à Yann.
Ne pas créer de tests unitaires pour recopier des interrupteurs simples comme
l'activation DEV/prod. Réserver les contrôles approfondis aux vrais contrats
de sécurité ; proposer à Yann les essais visuels simples. Préserver la base
de production, y compris celle de `tests.yanntassy.fr`.

## Décisions validées

### Responsabilités

- `plg_core` est le client : enregistrer des catalogues externes en base,
  consulter leurs extensions et versions, télécharger, installer et mettre à
  jour. TyCatalog n'est pas requis sur une installation cliente.
- Les catalogues externes enregistrés et les extensions installées sont gérés
  au niveau de l'instance. Installation et mise à jour sont réservées à ROOT,
  car le code des extensions est partagé entre les Spaces.
- `plg_ty_catalog` est le serveur : héberger ses propres catalogues, gérer
  leurs fiches et versions, recevoir des paquets et les distribuer.
- Les sources externes utilisées pour installer sont gérées par Core.
  TyCatalog peut publier un annuaire de catalogues « amis » pour la découverte ;
  cet annuaire ne constitue pas une liste de sources d'installation approuvées.
  Agrégation d'extensions, miroir et republication automatique de serveurs
  externes sont hors du premier périmètre.
- Chaque catalogue hébergé appartient à un Space. Les permissions
  d'administration sont globales au Space propriétaire, avec héritage des
  Spaces parents ; la délégation par catalogue n'est pas retenue.
- ROOT peut lister les catalogues de tous les Spaces, éditer n'importe lequel
  et changer son Space propriétaire depuis le formulaire. Les autres comptes
  restent limités à leur Space courant. Un déplacement conserve le catalogue,
  ses versions, ses jetons et ses références ; seules les permissions du
  Space propriétaire changent.
- Une même extension dans deux catalogues possède des fiches et des versions
  indépendantes. Pas de fiche centrale ni d'association plusieurs-à-plusieurs
  dans cette première version.

### Découverte de catalogues

Le catalogue officiel des installations standard est prévu à l’adresse
`https://www.tycms.fr/tycms-catalog/official` (slug public `official`). Sa clé
publique de confiance sera livrée avec la release et adoptée explicitement par
ROOT ; elle ne se déduit jamais du catalogue ni d’un paquet reçu. Cette source
par défaut relève du client Core (lot 6), pas du serveur TyCatalog.

Un catalogue fournit deux listes : ses extensions et des références vers
d'autres catalogues. L'endpoint public
`GET /tycms-catalog/<nom-du-catalogue>/catalogs` permet au Core d'afficher des
catalogues à découvrir depuis une première source connue. Les liens peuvent
désigner des catalogues hébergés sur le même serveur ou sur un serveur tiers.

Chaque catalogue choisit son annuaire : seuls ses amis explicitement déclarés,
locaux ou externes, sont retournés. Il n'y a pas d'exposition automatique de
tous les catalogues locaux actifs. La liste n'expose pas non plus toutes les
sources enregistrées dans Core, leurs licences ou leurs paramètres privés.

La découverte ne déclenche ni ajout en base aux sources d'installation, ni
adoption d'une clé publique, ni installation. ROOT choisit les sources et leurs
clés de confiance. Une relation d'amitié entre deux catalogues n'est pas une
délégation de confiance cryptographique. Le Core ne parcourt pas récursivement
tous les annuaires : des cycles A → B → A doivent rester sans effet automatique.
La licence d'un catalogue n'est jamais envoyée à ses amis.

### Accès et licence

L'API de consultation et de téléchargement de TyCatalog est publique : pas de
compte ni de licence requis. Les ACL protègent l'administration, les dépôts et
la publication. Une URL de paquet distante peut être un CDN ou un autre serveur.

Core conserve une clé de licence optionnelle par catalogue externe et l'envoie
dans ses requêtes à ce catalogue. Il s'agit d'une valeur opaque pour permettre
à un développeur de proposer son propre serveur et son système de licence.

TyCatalog ne stocke, ne valide et n'émet aucune licence. Il ne gère ni droits
commerciaux, ni expiration, ni révocation, ni facturation. Une licence reçue
n'accorde aucun droit administratif. Le lot de distribution ne doit pas
introduire de système de licence côté TyCatalog.

### Paquets et publication

- Les catalogues sont accessibles sous
  `https://<domaine>/tycms-catalog/<nom-du-catalogue>`.
- Extensions et versions sont déclarées en base. Les octets des ZIP restent
  dans un stockage de fichiers, local ou externe, sans BLOB SQL.
- L'API de dépôt de fichiers et la référence d'une URL distante rejoignent
  le même processus : préparation, vérification puis publication explicite.
  Un upload ne publie jamais immédiatement une version.
- Une version publiée est immuable. Changer le contenu demande une nouvelle
  version ; retirer une version de la distribution reste possible.
- Le chemin local complet de stockage est configurable par ROOT uniquement,
  hors racine HTTP. Aucun chemin fourni par un déposant ne décide de la
  destination d'écriture.
- Réutiliser les signatures Ed25519 des builds d'extensions. La clé privée
  reste sur le poste de publication, hors du serveur, du dépôt et des paquets.
- Core conserve la provenance et la confiance d'une extension installée.
  Un catalogue concurrent ne peut pas remplacer silencieusement cette origine.

## Lot 1 — Conception des données

Les noms SQL, champs et relations ci-dessous sont figés pour le lot 3 ; aucune
migration n'est créée dans ce lot de conception. Les dates et références de
comptes suivent les conventions ORM existantes. Ne pas inventer un deuxième
moteur ACL.

### Client dans Core

| Table proposée | Données et contraintes |
| --- | --- |
| `extension_catalog_sources` | `id`, nom, URL de base, `key_id`, clé publique Ed25519, licence chiffrée nullable, activation, dates. URL canonique unique par instance. Pas de `space_id` : source d'installation commune au site. La migration `1.1.5` insère la source officielle sans écraser une ligne existante. |
| `extension_installations` | `extension_id` unique, type et dossier, source d'origine (identifiant, nom et URL instantanés), version, `key_id`, SHA-256 de l'archive, statut `installing`/`installed`/`failed`, auteur et dates. Une ligne est réservée avant le téléchargement ; un échec supprime les fichiers posés mais conserve la provenance. |

La source possède une clé de confiance explicite, renseignée par ROOT. Une clé
renvoyée par le serveur ou placée dans le ZIP ne devient jamais fiable
automatiquement. Une modification d'URL ou de clé d'une source utilisée doit
être traitée comme un changement de confiance explicite, sans modifier
silencieusement les installations qui y sont rattachées.

La licence est chiffrée avec `coreCryptHelper`, selon
[Chiffrement](../../CRYPTO.md). L'interface masque sa valeur et permet son
remplacement ou son effacement, sans renvoyer le secret enregistré au navigateur.
Ne pas stocker de licence en clair dans les caches ou journaux.

Les extensions trouvées sur un serveur ne sont pas des extensions installées.
La consultation ne charge jamais le PHP reçu. L'installation neuve télécharge,
vérifie et extrait le paquet avant de poser le code et les assets ; elle refuse
un identifiant ou un dossier déjà présent. Les migrations passent par une
nouvelle instance `Application`, sans mode maintenance. Un échec ne défait pas
le SQL déjà exécuté : il retire les fichiers posés et laisse le statut `failed`.

### Serveur dans TyCatalog

| Table proposée | Données et contraintes |
| --- | --- |
| `ty_catalog_catalogs` | `id`, `space_id`, slug public, nom, description, activation, `keep_versions`/`keep_archives` (rétention par extension), `key_id`, clé publique de vérification, dates. Pas de licence ni de clé privée. |
| `ty_catalog_catalog_links` | Amis déclarés : `id`, `catalog_id` propriétaire, nom public, URL de base cible, description et ordre. Unicité `(catalog_id, target_url)` canonique. Références de découverte uniquement, sans licence ni autorisation d'installation. |
| `ty_catalog_extensions` | `id`, `catalog_id`, identifiant technique, type, dossier, nom affiché, description Markdown basique, `image_url` HTTPS optionnelle, dates. Unicité `(catalog_id, extension_identifier)`. Le nom, la description et l’image sont des métadonnées de présentation non signées. |
| `ty_catalog_versions` | `id`, `extension_id`, version, état de publication, enveloppe de signature originale, métadonnées validées, notes, taille et SHA-256 du ZIP, type de stockage, référence locale ou URL externe, dates et auteur de publication/retrait, `archive_removed_at`. Unicité `(extension_id, version)`. |
| `ty_catalog_tokens` | `id`, `catalog_id`, libellé, `token_hash` SHA-256, droits `deposit`/`publish`, activation, expiration, dernier usage, auteur, date de création et de révocation. Unicité du hash ; le jeton en clair n'est jamais stocké. |

Le Space réel d'une version est déduit par version → extension → catalogue.
Les WF relisent ces liens en base ; un `space_id` envoyé par le navigateur ne
constitue jamais une autorisation. Les relations SQL doivent empêcher les
versions orphelines. La suppression et la conservation des versions retirées
restent à préciser avant de coder une suppression définitive.

La table `ty_catalog_tokens` ajoute une publication distante par jeton, hors du
périmètre initial mais demandée explicitement. Un jeton est limité à un
catalogue et à des droits explicites ; il ne remplace ni la signature du paquet,
ni la clé de confiance, ni une licence.

Le type, le dossier et l'identifiant d'extension doivent être cohérents avec
`ExtensionMetadata`. Les colonnes dérivées des métadonnées signées servent à
la recherche et au filtrage ; elles ne remplacent pas la signature conservée.
Ne pas éditer manuellement une compatibilité pour contredire le paquet signé.

Pour le routage, proposition : slug ASCII minuscule composé de lettres,
chiffres et tirets, unique dans l'instance. Cette unicité évite une collision
entre deux Spaces servis par le même domaine, puisque l'URL ne contient pas
d'identifiant de Space. Une unicité par Space nécessiterait une règle explicite
de résolution par domaine ; ne pas la déduire de la session cliente.

Une référence locale est relative à la racine privée configurée. L'API publique
ne révèle pas les chemins du système. Une version possède soit un paquet local,
soit une URL distante ; les références ne servent pas de chemins arbitraires.

## Lot 1 — Protocole d'échange (figé)

Ce protocole est figé et sert de contrat aux lots 4 à 6 ; il n'est pas encore
exposé par une route du dépôt. Il est indépendant de Vue, de la session de
l'administrateur et du format interne des API d'administration Core. Un serveur
tiers doit pouvoir l'implémenter sans embarquer TyCMS.

### Lecture et téléchargement

Pour une URL de base `https://example.org/tycms-catalog/catalog1` :

| Méthode et chemin relatif | Réponse attendue |
| --- | --- |
| `GET /` (URL de base, sans imposer une redirection) | Descripteur du catalogue, version du protocole, nom et description. |
| `GET /catalogs?page=1&limit=50` | Amis explicitement déclarés pour ce catalogue, locaux ou externes : nom, URL de base et description ; même pagination que les extensions. |
| `GET /extensions?page=1&limit=50` | Fiches publiées avec pagination. |
| `GET /extensions/<extension_id>` | Fiche de l'extension. |
| `GET /extensions/<extension_id>/versions?page=1&limit=50` | Versions publiées ; ordre numérique décroissant, la plus récente d'abord. |
| `GET /extensions/<extension_id>/versions/<version>` | Enveloppe signée du paquet et informations de transport du ZIP et des notes. |
| `GET /extensions/<extension_id>/versions/<version>/download` | ZIP local ou redirection contrôlée vers le ZIP externe. |
| `GET /extensions/<extension_id>/versions/<version>/notes` | Notes Markdown servies depuis la base, pour les versions distribuables. |

Les réponses JSON portent `schema_version: 1`. Les listes exposent `items`,
`page`, `limit`, `total`. `page` vaut 1 par défaut et `limit` 50 ; `limit` est
ramené à 100 au maximum. Une valeur non entière ou inférieure à 1 produit un
400. Le tri est figé : catalogues amis dans l'ordre déclaré, extensions par
identifiant, versions par comparaison numérique décroissante.

DTO figés :

- descripteur : `schema_version`, `name`, `description` ;
- catalogue ami : `name`, `url`, `description` ;
- extension : `identifier`, `type`, `folder`, `name`, `description` (Markdown basique), `image_url` optionnelle ;
- version listée : `version`, `published_at` ;
- version détaillée : `schema_version`, `extension`, `version`, `envelope`,
  `transport.archive` et `transport.release_notes` avec `url`, `bytes` et
  `sha256`. `envelope` conserve les chaînes base64 `payload` et `signature`
  d'origine ; la signature porte sur les octets décodés de `payload` et le
  serveur ne réencode jamais le manifeste signé ;
- erreur : `schema_version` et `error.code`/`error.message`.

Les références retournées dans les listes ne suffisent pas à autoriser une
installation : le client vérifie toujours le manifeste signé de la version.
Les URL de `transport` ne sont pas signées ; changer d'hébergement ne change
jamais les octets attendus.

L'URL `/catalogs` reste utilisable sans paramètres ; elle applique les valeurs
de pagination par défaut. La liste est produite depuis les références locales,
sans consulter les serveurs amis pour répondre. Elle ne garantit donc pas leur
disponibilité instantanée. Core déduplique les propositions par URL canonique et
distingue les sources déjà enregistrées des catalogues simplement découverts.
Avant d'interroger une URL découverte, appliquer les mêmes contrôles réseau
que pour une source saisie manuellement. Ne pas suivre automatiquement un lien
dans le seul but de remplir l'annuaire.

Une version retirée n'est plus proposée ni téléchargeable par l'API publique ;
elle reste consultable administrativement et conserve son identité réservée.
Une version dont l'archive a été supprimée par la rétention
(`archive_removed_at`) n'est plus proposée ni téléchargeable publiquement. Les
GET ne modifient pas l'état métier. Le préfixe de protocole ne dépend pas de la
langue publique et ne doit pas être redirigé vers une page de connexion.
Vérifier son interaction avec la sélection de Space et de langue existante.

Codes HTTP figés : 400 requête invalide, 404 ressource publique absente ou non
publiée, 409 conflit de publication dans l'administration, 413 dépôt trop
volumineux. Core doit aussi rendre lisibles les 401/403 d'un serveur tiers,
sans tenter d'interpréter commercialement une licence.

### Transport de la licence par Core

Proposition : en-tête HTTP `X-TyCMS-License: <valeur>` lorsque la source a une
licence ; en-tête absent sinon. Il reste distinct de toute authentification
administrative et ne figure jamais dans l'URL ou les métadonnées publiques.
Refuser les retours à la ligne et borner sa longueur à la validation.

Le client envoie cet en-tête aux endpoints appartenant au catalogue configuré,
pas à toutes les URL fournies dans une réponse. Un téléchargement dont la cible
est hors de cette base, notamment sur un CDN, ne reçoit pas la licence. Une
redirection ne doit pas la transmettre implicitement à un autre destinataire.
Un serveur tiers qui veut protéger un ZIP externe fournit un lien temporaire
ou sert le fichier lui-même. TyCatalog ignore cet en-tête dans la première version.

### Authenticité, URL distante et confiance

`build_extensions/Protocol.php` définit déjà le format
`tycms-extension-signature-v1` et le domaine `TyCMS extension package v1\0`.
L'enveloppe contient `format`, `key_id`, `payload` et `signature`. Le payload
contient `schema_version`, `artifact_type`, `metadata`, `frontend_entry`,
`archive` et `release_notes`. Conserver les octets originaux du payload :
la vérification ne doit pas reconstruire un JSON approximativement équivalent.

La signature couvre identité, version, compatibilités, nom, taille et SHA-256
du ZIP et des notes. Elle ne couvre aucune URL de transport. Le protocole
du catalogue fournit donc ces URL séparément, sans changer le format signé
existant. Changer d'hébergement ne permet jamais de changer les octets attendus.

Avec la clé privée hors serveur, les listes de catalogue ne sont pas signées
dynamiquement dans cette v1. HTTPS protège leur transport ;
chaque paquet proposé possède sa signature vérifiable indépendamment. Cela ne
prouve pas qu'une liste reçue est exhaustive ou contient la dernière version.
Core ne doit pas proposer automatiquement de rétrogradation et doit comparer
la version installée et les sources compatibles du manifeste signé.

Les téléchargements Core et les vérifications de ZIP distants par TyCatalog
doivent avoir des bornes de temps, de taille et de redirections, vérifier TLS,
et empêcher les requêtes vers des adresses internes en production, y compris
après résolution DNS ou redirection. Toute exception DEV reste explicite.
Ces protections réseau sont nécessaires même pour une URL donnée par un
administrateur ; elles ne remplacent pas le contrôle cryptographique du ZIP.

Avant de charger du PHP : vérifier signature, identité attendue, provenance,
compatibilités CMS/PHP et version source, empreinte, structure ZIP, chemins,
liens, collisions et assets. Une signature valide authentifie un éditeur ;
elle ne prouve pas que son code est sans danger.

Une paire de clés par catalogue est retenue comme correspondance avec le
« Store » du build actuel. L'amorçage de confiance est manuel et ROOT ; rotation
automatique, délégation à plusieurs éditeurs et index signé sont hors v1.
Le vérificateur partagé est livré par la release dans
`framework/Extensions/ExtensionPackage.php` ; le wrapper de build
`build_extensions/Protocol.php` en hérite pour conserver une seule règle
cryptographique.

## Lot 1 — Publication et droits

États retenus : `draft` → `published` → `withdrawn`. Le brouillon peut être
corrigé ; seules les versions publiées sont distribuées. Après publication,
signature, métadonnées, notes et contenu du paquet sont immuables. Le retrait
ne libère pas le numéro de version. Une version retirée peut être réactivée si
les octets et la signature sont identiques. Le déplacement d'un ZIP identique
met à jour uniquement l'URL de transport, de façon auditée, sans changer la
version ni les octets attendus.

### Canal de diffusion

Une version publiée porte un `channel` : `release` (défaut) ou `beta`. Le canal
se choisit **à la publication** et ne revient jamais en arrière : le passage
bêta → release existe (`promote`), l'inverse est refusé. Une version bêta est
servie seulement à un client qui la demande (`?channel=beta`) ; celui-ci reçoit
alors la bêta **et** les release, pour pouvoir revenir à une version stable sans
changer de catalogue. Sans paramètre, seules les release sont servies.

La réactivation d'une version retirée **conserve son canal** : réactiver une
bêta ne la publie pas pour tous. Une migration (`Mig_1_0_5`) ajoute la colonne
avec `release` par défaut, donc les versions déjà publiées restent servies comme
avant.

La rétention est réglée par catalogue (`keep_versions`, `keep_archives`, défaut
4, bornes 1–100). Elle s'applique par extension, brouillons compris, après
chaque publication ou réactivation et depuis **Nettoyer maintenant**. Au-delà
de X versions, la ligne et les fichiers locaux sont supprimés et le numéro est
libéré. Au-delà de Y ZIP locaux, l'archive est supprimée et
`archive_removed_at` marque la version ; les métadonnées restent. Les
références externes ne sont pas touchées. La dernière version publiée et son
ZIP local sont toujours conservés.

Le dépôt transmet le ZIP, les notes et l'enveloppe signée produits par le build.
Le manifeste de build peut accompagner le dépôt, mais seul son contenu lié à
l'enveloppe vérifiée fait autorité. Le serveur vérifie avec la clé du catalogue,
contrôle les fichiers et refuse un conflit d'identité ou de version.

Pour une URL distante, ne pas se contenter de déclarations ni d'une requête
HEAD : contrôler les octets dans un espace temporaire privé avant publication.
Le client recommence ses vérifications au téléchargement, puisque le fichier
externe peut changer ou disparaître après sa publication.

La racine privée de stockage est un réglage global à l'instance, éditable par
ROOT seulement, même si les catalogues sont rattachés à des Spaces. Dériver les
destinations côté serveur, vérifier leur confinement réel et éviter tout écrasement
concurrent. Le déplacement sur disque et la transaction SQL ne sont pas
atomiques ensemble : prévoir préparation temporaire et nettoyage des orphelins.

Les permissions d'administration sont globales au Space propriétaire, avec
héritage des Spaces parents. La délégation par catalogue n'est pas retenue pour
cette version : réutiliser les rôles et groupes existants, sans nouveau moteur
ACL ni attribution par objet. Distinguer la création/gestion de catalogues,
l'édition des fiches, le dépôt et la publication/retrait. Un droit d'upload seul
ne doit pas permettre de publier ou de changer la clé de confiance. La clé
publique de confiance et le chemin global de stockage restent sous contrôle ROOT.

Les WF relisent le Space réel du catalogue avant toute écriture. L'API
d'upload administrative utilise la session et le CSRF existants ; une
authentification de publication machine-à-machine serait un chantier explicite,
sans réutiliser la licence.

### Publication distante par jeton

La publication par jeton Bearer est une extension explicite du périmètre
initial. ROOT crée le jeton dans l’interface du catalogue, bouton **Jetons** ;
le secret n’est affiché qu’une fois et seul son SHA-256 est stocké. Le jeton est
limité à un catalogue, porte les droits `deposit` et/ou `publish`, possède une
expiration optionnelle, un dernier usage et une révocation. La liste et la
révocation se font dans le même écran, sans accès CLI au serveur.

Routes sans session ni CSRF, authentifiées par `Authorization: Bearer` :

- `POST /tycms-catalog-api/v1/deposit` (multipart) ;
- `POST /tycms-catalog-api/v1/reference` (JSON) ;
- `POST /tycms-catalog-api/v1/publish`, `/withdraw`, `/reactivate`.

En production, HTTPS est exigé ; le jeton n'est jamais journalisé. Le dépôt
applique exactement les mêmes contrôles de signature, ZIP, notes et stockage que
l'administration. Le droit `deposit` ne permet pas de publier ; `publish` couvre
la publication, le retrait et la réactivation.

Client distant :

```bash
php build_extensions/tycatalog-publish.php
```

Copier `build_extensions/tycatalog-publish.sample.php` vers
`build_extensions/tycatalog-publish.local.php` (ignoré par Git) pour fixer
l’URL, le catalogue, le dossier `dist/` et le jeton. Appliquer `chmod 600` à ce
fichier. Sans argument, le client propose les actions et la liste des paquets.
Les options directes restent disponibles pour les scripts. Le jeton se lit dans
`TYCMS_TYCATALOG_TOKEN`, la clé `token` de la configuration locale ou l’option
`--token`. Le client refuse une URL non HTTPS hors localhost, ne suit pas les
redirections et ne transmet le jeton qu'en en-tête.

## Lots de développement et conditions de sortie

| Lot | Livrable et vérifications utiles | État |
| --- | --- | --- |
| 1 — Contrats | Note de conception, responsabilités, données, échanges, confiance, publication et questions ouvertes. Pas de PHP ni de migration. | Figé : données, protocole, DTO, pagination, licence, retrait et transport arrêtés. |
| 2 — ACL Core | Contrôle par ID ajouté à `hasPermissionOn()`, contrôle de délégation permission par permission et lecture/remplacement des attributions locales dans les helpers existants. Héritage conservé ; droits d'instance exclus des droits globaux. | Socle implémenté ; TyCatalog utilise des permissions globales au Space, sans délégation par catalogue. |
| 3 — Administration TyCatalog | Migrations et modèles catalogue/extension/version, références de catalogues amis, CRUD protégé, configuration globale ROOT du stockage. Vérifier contraintes et migrations sur base isolée ; essais d'interface avec Yann. | Implémenté : schéma, modèles, permissions, CRUD catalogues/références, interface et clés ROOT. Vérification sur base isolée et essais d'interface à faire. |
| 4 — Publication | Dépôt local ou référence externe, vérification des signatures/ZIP, brouillons, publication immuable et rétention configurable des versions/ZIP. Vérifier altération, chemins sortants, fichiers dangereux, conflits et interruptions ; aucune exécution du PHP déposé. | Implémenté : dépôt local/externe, vérification partagée, brouillon, publication, retrait, réactivation, rétention des versions/ZIP et publication distante par jeton. Vérifications d'intégration et essais à faire. |
| 5 — Distribution | API publique documentée, annuaire `/catalogs`, pagination, versions publiées et téléchargement local/externe. Ne pas proposer de téléchargement si `archive_removed_at` est renseigné. Aucune gestion de licence dans TyCatalog. Vérifier non-exposition des brouillons, secrets et chemins privés. | Implémenté : descripteur, annuaire, fiches, versions, enveloppe, notes, téléchargement local/redirection externe et pagination. Vérifications d'intégration et essais à faire. |
| 6 — Client Core | Sources en base et administration ROOT, découverte de catalogues amis sans approbation automatique, clés de confiance, licence optionnelle chiffrée/envoyée, consultation et comparaison des versions. Vérifier les destinations réseau et la non-divulgation de licence ; essai simple avec serveur TyCatalog. | Implémenté : sources, confiance, licence chiffrée, consultation/vérification des versions et comparaison locale. Installation livrée au lot 7. |
| 7 — Installation | Provenance persistante, téléchargement contrôlé, extraction privée, compatibilités, pose du code/assets et migrations. Concevoir verrouillage, activation et récupération avant écriture dans les dossiers découverts par le chargeur. | Implémenté pour une installation neuve : provenance, archive vérifiée, extraction privée, pose du code/assets, migrations par nouvelle `Application`, verrou et retrait des fichiers en cas d'échec. Retrait/relance d'une installation interrompue et essais réels à faire. |
| 8 — Mise à jour | Versions sources permises : `"*"` recommandé (première installation + toute version antérieure), `[]` (première installation uniquement), ou liste explicite (première installation + versions listées) ; migrations, préservation des données, récupération après interruption et interface ROOT. Ne pas promettre de retour arrière automatique des migrations non réversibles. | Implémenté : dernière version strictement supérieure, `source_versions` de la cible, préparation en deux requêtes, sauvegarde/restauration des fichiers, migrations par `Migration`, reprise/restauration dans l’interface. Essais d’intégration réels et OPcache à vérifier. |

Les critères de sécurité justifient des tests ciblés ; éviter une batterie de
tests qui ne fait que recopier des champs ou valeurs par défaut. Les premiers
lots ne doivent pas implémenter l'installation ou le système de licences.

## Décisions figées au lot 1

- Tables et relations retenues telles que décrites : `ty_catalog_catalogs`,
  `ty_catalog_catalog_links`, `ty_catalog_extensions`, `ty_catalog_versions`
  côté serveur ; `extension_catalog_sources`, `extension_installations` côté Core.
- Slug public unique dans l'instance, sans résolution par Space.
- Une paire de clés Ed25519 par catalogue ; amorçage de confiance manuel par ROOT.
- DTO, erreurs et pagination figés dans la section de protocole.
- Licence envoyée par l'en-tête `X-TyCMS-License`, uniquement aux endpoints du
  catalogue configuré, jamais sur une redirection ou un CDN.
- Retrait distributif, numéro réservé, réactivation possible à l'identique.
- Changement d'hébergement par l'URL de transport seule, audité.
- Permissions globales au Space propriétaire, avec héritage ; pas de délégation
  ni d'attribution par catalogue dans cette version.

Hors v1 : rotation des clés, dépendances entre extensions, canaux de versions,
agrégation/miroir, gestion de licence côté TyCatalog et mécanismes de
publication machine-à-machine autres que le jeton Bearer par catalogue. La
suppression physique d'un catalogue ou d'une version n'est pas exposée tant que
sa conservation n'est pas définie ; le retrait suffit à retirer un paquet de la
distribution.

## Références et écarts à traiter lors de l'implémentation

- [Extensions](../../EXTENSIONS.md), [architecture](../../ARCHITECTURE.md),
  [rôles](../../ROLES.md), [chiffrement](../../CRYPTO.md),
  [contrôleurs](../../CONTROLLERS.md), [vérification](../VERIFICATION.md).
- [Build d'extensions](../../../build_extensions/README.md),
  [protocole de paquet](../../../build_extensions/Protocol.php) et
  [métadonnées](../../../src/releases/vdev/framework/Extensions/ExtensionMetadata.php).
- [Squelette TyCatalog](../../../src/releases/vdev/core_extensions/TyCatalog/Plugin.php)
  et [AclHelper](../../../src/releases/vdev/core_extensions/Core/Helper/Acl/AclHelper.php).
- Le socle ACL d'instance reste disponible pour d'autres ressources, mais
  TyCatalog n'utilise pas `hasPermissionOn()` : ses permissions sont globales
  au Space propriétaire, sans attribution par catalogue. Le
  [guide des rôles](../../ROLES.md#droits-sur-une-instance-dobjet) conserve le
  contrat du socle pour les futurs objets qui en auront besoin.
- L'API d'administration des catalogues et des références amies est exposée ;
  la publication des versions signées, la rétention, les jetons et la
  distribution publique sont également livrés.
- Le validateur `ExtensionMetadata` réserve déjà `plg_ty_catalog`. Le refus des
  doublons du chargeur ne remplace pas une validation d'identité avant
  publication/installation.
- Le README du build emploie encore « Store » et mentionne l'absence de serveur
  de catalogue. Il décrit correctement le build livré ; harmoniser le vocabulaire
  et les capacités lorsque les lots correspondants seront effectivement livrés.
