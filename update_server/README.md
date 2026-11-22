# Serveur de distribution

Application PHP autonome pour hébergement mutualisé, sans base SQL ni Composer.
Elle reçoit les ZIP signés depuis la machine de publication, puis fournit les
manifestes, archives et notes de version. L'interface ROOT et l'updater des
installations Tycms prennent en charge le [parcours de mise à jour](../Docs/SYSTEM_UPDATES.md).

## Installation du serveur

Prérequis : PHP 8.4 ou supérieur avec `sodium` et `zip`, HTTPS, stockage local
permettant `flock()` et le renommage atomique de dossiers. Déployer `lib/`,
`public/` et une copie de `configuration.sample.php` nommée `configuration.php`.
**Seul `public/` doit être la racine HTTP.** La configuration et `storage/`
restent hors de cette racine. Aucun secret privé de signature n'est déployé.
Les fichiers locaux `configuration.php` et `storage/` sont ignorés par Git.

Configurer :

- `publish_token_sha256` : empreintes SHA-256 des tokens autorisés ; une liste
  vide interdit toute publication.
- `trusted_public_keys` : identifiant de clé => clé publique Ed25519 en base64,
  fournie par un canal de confiance, jamais extraite du manifeste reçu.
- `storage_path` : dossier privé accessible en écriture à PHP, hors de `public/`.
- `max_package_bytes` : limite par ZIP, 128 Mio par défaut.
- `allow_loopback_http` : laisser `false` sur l'hébergement. L'exception n'est
  utilisable qu'avec le serveur PHP intégré et une connexion locale.
- `admin_user` et `admin_password_hash` : compte unique du [mini-UI
  d'administration](#mini-ui-dadministration-et-approbation). Laisser
  l'empreinte vide désactive l'interface sans bloquer l'API de publication.

Ajuster aussi les limites de l'hébergement : `upload_max_filesize`,
`post_max_size` (plus grand que le ZIP pour le multipart), limite HTTP du
serveur et durées d'exécution. PHP reçoit les uploads avant d'exécuter le
contrôle du token ; ces limites doivent donc être réglées côté hébergement.
La publication valide et copie le ZIP dans une seule requête ; sa durée
maximale effective reste celle autorisée par le mutualisé.

Le `.htaccess` conserve l'en-tête Authorization pour Apache/FastCGI. Vérifier
sa transmission sur l'hébergement ; aucun token dans une URL ou un cookie ne
sert de solution de repli. HTTPS doit être reconnu via `HTTPS=on` ou `1` : les
en-têtes `X-Forwarded-*` fournis par le client ne sont pas utilisés.

### Compte d'administration

Le mini-UI n'a qu'un compte, défini dans `configuration.php`. Générer son
empreinte Argon2id sur la machine de publication, sans jamais écrire le mot de
passe en argument de commande :

```bash
php update_server/tools/admin-password.php
```

L'outil lit le mot de passe sur l'entrée standard (12 caractères au moins) et
n'affiche que la ligne `'admin_password_hash' => '…',` à reporter dans la
configuration serveur. La saisie n'est recopiée ni dans un fichier ni dans une
sortie. Changer le mot de passe : relancer l'outil et remplacer l'empreinte ;
les sessions en cours restent valides jusqu'à leur expiration (8 heures).

## Tokens de publication

Depuis la machine locale, créer un token et l'empreinte à copier dans
`publish_token_sha256` du serveur :

```bash
php update_server/tools/token.php
```

Le token contient 256 bits aléatoires (64 caractères hexadécimaux). Conserver
l'original dans la configuration privée du client ; seul son SHA-256 va sur
le serveur. Le script ne modifie aucun fichier et ne transmet rien.
Retirer une empreinte de la configuration serveur révoque le token associé.
L'API calcule le SHA-256 du Bearer token puis utilise `hash_equals()`.

Pour calculer l'empreinte d'un token existant du même format :

```bash
php update_server/tools/token.php --hash
```

Le token est lu sur l'entrée standard, sans être repris dans la sortie ; la
saisie peut être visible dans le terminal. Ne pas le passer en argument ni
l'inscrire dans une commande conservée par l'historique. Les outils sous
`tools/` restent hors de `public/` et refusent le mode web.

## Signature locale et publication

Voir le [guide des deux commandes](../build/README.md#publication-séparée).
Le build Docker fabrique uniquement le ZIP. La commande PHP locale signe un
manifeste contenant l'identité du ZIP, puis envoie ZIP et manifeste à l'API
avec les notes Markdown figées et `Authorization: Bearer …`, exclusivement en HTTPS. Elle ne lance ni Docker,
ni Composer, ni npm. La clé privée et le token restent hors du dépôt.

Le serveur vérifie la clé reconnue, la signature, la taille, le SHA-256, les
chemins et la version contenue dans le ZIP, sans extraction ni exécution PHP.
Les notes sont également vérifiées avec leur taille et leur SHA-256 signés.
Les uploads ne choisissent jamais les chemins de stockage ni les fichiers PHP
à inclure. Un verrou exclusif non bloquant protège toute mutation d'état ; une
opération concurrente reçoit HTTP 409. Le paquet est copié dans un dossier
privé aléatoire, puis l'ensemble paquet/manifeste/notes est déplacé par un
renommage atomique dans l'état « en attente ». **Un envoi réussi ne rend donc
rien public** : la release attend l'approbation décrite ci-dessous.

Une version ne change jamais de numéro ni de contenu : un envoi identique
reçoit HTTP 409, quel que soit son état. Pour corriger une release, fabriquer
et envoyer une nouvelle version. Une interruption réseau peut survenir après
l'enregistrement effectif : vérifier l'état dans le mini-UI avant de réessayer.
Les échecs ordinaires nettoient leur dossier temporaire ; un arrêt forcé de
PHP peut laisser un `.incoming-*` privé et invisible. Le nettoyage de ces
restes n'est pas automatisé.

## Mini-UI d'administration et approbation

Le mini-UI est servi par la même route que l'API, après authentification :
`https://update.example.org/index.php?action=admin`. Il est réservé au compte
`admin_user` et reste inactif tant que `admin_password_hash` est vide.

```text
en attente ──Approuver Bêta──▶ bêta ──Promouvoir en Prod──▶ prod
           └─Approuver Prod─────────────────────────────────▶ prod
           └─Refuser : suppression physique
```

| État | Servi par défaut | Servi avec `?action=releases&channel=…` | Boutons |
| --- | --- | --- | --- |
| en attente | non | non | Approuver Bêta, Approuver Prod, Refuser |
| bêta | non | `channel=beta` | Promouvoir en Prod |
| prod | oui, par défaut et `channel=prod` | oui | aucun |

- **En attente** : état initial de toute proposition envoyée. Le dossier reste
  sous `storage/pending/` ; le catalogue, le manifeste et le paquet répondent
  HTTP 404 pour cette version.
- **Bêta** : servie seulement aux installations qui demandent la bêta ; elle
  sert à essayer une release sur une instance de confiance avant les clients.
- **Prod** : servie par défaut, donc à toutes les installations.
- **Transitions** : une bêta passe en prod ; un retour prod → bêta ou
  bêta → attente est refusé (HTTP 409). L'identité servie à une installation ne
  change donc jamais d'état en arrière. Une version refusée est supprimée avec
  ses fichiers ; son numéro redevient libre pour un nouvel envoi.
- **Pas de rétention automatique** : une proposition reste affichée jusqu'à
  l'approbation ou le refus. Réexaminer de temps en temps la liste.
- **Notes** : affichées en texte simple (Markdown non interprété), échappées,
  avant toute décision.

Les mutations sont des `POST` portant un jeton CSRF dérivé de la session, puis
redirigées par HTTP 303 : un rechargement ne rejoue jamais l'action. La session
est un jeton aléatoire de 256 bits conservé sous `storage/sessions/`, transmis
par le cookie `HttpOnly`, `Secure`, `SameSite=Strict` : un cookie inventé ne
vaut pas session. Cinq échecs de connexion sur une fenêtre de quinze minutes
renvoient HTTP 429, ce qui ralentit une recherche de mot de passe sans blocage
définitif. Les pages portent une CSP à nonce et n'affichent aucun chemin privé.

En l'absence de session, une erreur d'administration est rendue comme une page
de refus ; les réponses JSON de l'API ne changent pas.

## Protocole HTTP, schéma 1

Les commandes utilisent `public/index.php` et des paramètres de query validés ;
aucune réécriture d'URL spécifique n'est nécessaire.

| Requête | Résultat |
| --- | --- |
| `POST ?action=publish` | Multipart `manifest` (texte de l'enveloppe JSON), `package` (ZIP) et `notes` (Markdown), Bearer token requis. HTTP 201 avec `published` et `sha256` : la version est enregistrée **en attente**, pas encore distribuée. |
| `GET ?action=releases` | Catalogue `schema_version: 1`, `releases` : enveloppes signées en prod uniquement, triées par version croissante. |
| `GET ?action=releases&source=1.0.0&php=8.4.27` | Releases dont la liste inclut exactement la source et dont les bornes PHP conviennent. Fournir ensemble les deux filtres. |
| `GET ?action=releases&channel=prod` | Même catalogue explicite ; valeur par défaut. |
| `GET ?action=releases&channel=beta` | Releases en bêta et en prod. La bêta n'est jamais servie sans cette demande. |
| `GET ?action=manifest&version=1.0.1` | Enveloppe signée de la release. |
| `GET ?action=notes&version=1.0.1` | Markdown original, sans rendu HTML, en téléchargement. HTTP 404 pour une ancienne release sans notes. |
| `GET ?action=package&version=1.0.1` | ZIP public de la release, servi en flux depuis le stockage privé. |

Sans session, `manifest`, `notes` et `package` d'une version en bêta restent
accessibles à qui connaît son numéro : la bêta est publique, elle n'est
simplement pas proposée. Une version en attente n'a aucun accès public.
Le filtre `channel` ne remplace jamais les contrôles de signature ni de
compatibilité ; il choisit seulement l'état interrogé.

Les téléchargements sont publics ; seul l'envoi exige un token. Les erreurs
n'exposent ni chemins ni traces : 400 paramètres/HTTPS, 401 authentification,
404 absence, 405 méthode, 409 doublon/concurrence, 413 taille, 422 signature ou
paquet invalides, 503 configuration/stockage indisponible. Aucun contrôle
ne dépend d'une session ou des ACL du site Tycms.

L'enveloppe possède trois champs : `key_id`, `payload` (base64 des octets JSON
signés) et `signature` (signature Ed25519 détachée, base64). On vérifie la
signature des octets décodés avant d'interpréter le payload : aucune
canonicalisation JSON n'est à reproduire côté consommateur.
Le client génère l'enveloppe avec `JSON_PRETTY_PRINT` et une fin de ligne,
ce qui rend le fichier stocké `manifest.json` lisible. Le payload signé
reste inchangé en base64 ; les anciennes enveloppes compactes restent valides.
Les fichiers déjà publiés ne sont pas réécrits.

Le payload signé contient :

```json
{
  "schema_version": 2,
  "type": "system",
  "channel": "prod",
  "version": "1.0.1",
  "version_string": "1_0_1",
  "source_versions": ["1.0.0"],
  "php_min": "8.4.1",
  "php_max_exclusive": "8.6.0",
  "archive": {
    "filename": "tycms-1_0_1.zip",
    "bytes": 123456,
    "sha256": "<64 caractères hexadécimaux minuscules>"
  },
  "release_notes": {
    "filename": "tycms-1_0_1.md",
    "bytes": 1234,
    "sha256": "<64 caractères hexadécimaux minuscules>"
  }
}
```

`channel` vaut `prod` ou `beta`. Le build écrit toujours `prod` : le canal est
un choix de diffusion décidé à l'approbation sur le serveur, jamais par le poste
qui publie. Le schéma `2` exige ce champ ; un manifeste `schema_version: 1`
reste lu et vaut `prod`, pour que les releases déjà signées continuent d'être
servies sans réécriture.

Versions stables `X.Y.Z` uniquement. La première installation reste toujours
permise. `"*"` couvre toute version antérieure ; une liste vide signifie
installation initiale seule, jamais compatibilité universelle ; une liste
explicite autorise les versions listées. Les sources d'une liste sont uniques
et strictement antérieures à la cible. La borne PHP minimale est incluse, la
maximale exclue. Ces bornes sont déclarées dans le `version.json` racine
en fonction du code et du verrou, puis capturées au build ; le serveur ne résout pas les contraintes
Composer. Le ZIP reste limité à 128 Mio côté client, 100 000 entrées et 1 Gio
de contenu décompressé déclaré lors de son inspection. Le serveur refuse les
liens symboliques, chemins ambigus, configurations locales et releases
inattendues ; cette inspection ne remplace pas le futur extracteur sécurisé.

Les notes sont rédigées dans le `version.md` racine au fil du développement,
puis figées avec le build et authentifiées dans `release_notes` du manifeste.
Elles sont stockées sous `notes.md` avec `manifest.json` et `package.zip` dans
le dossier de la release. Taille maximale : 256 Kio. Les anciennes releases
sans `release_notes` restent lisibles ; les nouvelles publications du client
exigent les trois artefacts ZIP/JSON/Markdown. Un futur plugin pourra parcourir
ces dossiers ; toute interface de rendu Markdown devra traiter ce contenu
comme non fiable et désactiver ou purifier le HTML.

Le catalogue extérieur n'est pas signé : chaque manifeste doit être vérifié
individuellement avec une clé déjà reconnue. Le futur client Tycms devra
également vérifier le ZIP et les compatibilités, et refuser les retours de
version. La confiance initiale de la production `1.0.0` repose sur l'ajout
manuel des propriétés décrites ci-dessous ; la rotation coordonnée des clés
reste à définir avec l'updater. Ajouter ou retirer une
clé du serveur n'actualise pas les clés reconnues par les installations.
Ce schéma distribue les paquets ; le contrat d'exécution et de compatibilité
du futur moteur devra être complété lorsque l'updater sera réalisé.

## Configuration des installations clientes

La base `src/releases/vdev/configuration.base.php` fournit deux propriétés
héritées par le `configuration.php` de chaque installation Tycms, sauf surcharge
locale. Les installations existantes adoptent manuellement l’héritage de
`ConfigurationBase` ; voir le [contrat de configuration](../Docs/ARCHITECTURE.md#configuration-locale-et-valeurs-par-défaut).

- `update_servers` : liste ordonnée des URL HTTPS complètes de l'API, avec
  `https://update.tycms.yanntassy.fr/index.php` en premier, puis `https://update.tycms.fr/index.php`.
- `update_trusted_public_keys` : identifiant de clé vers clé publique Ed25519
  en base64 ; la base embarque la clé de production `release-1`.
- `update_channel` : `prod` (défaut) ou `beta`. Le client interroge alors
  `?action=releases&channel=…` ; un manifeste de bêta reçu sur le canal prod est
  écarté. Réglage réservé aux installations d'essai.

Core dispose d'un écran de [consultation manuelle](../Docs/SYSTEM_UPDATES.md)
réservé à ROOT, avec vérification des signatures et des notes, et un bouton
de téléchargement du ZIP avec contrôle d'intégrité et cache privé réutilisable.
La préparation et l'exécution sont décrites dans ce même guide. Ces propriétés sont indépendantes de
la configuration du serveur de distribution et des secrets de publication.
La clé publique n'est pas secrète et peut être versionnée ; le token et la
clé privée restent sur la machine de publication.

Pour une installation existante, ajouter manuellement ces deux propriétés à
sa configuration depuis une copie de confiance du modèle. Le modèle seul
ne modifie ni la configuration locale ni les ZIP déjà publiés. Une clé
reçue d'un serveur de mise à jour n'est jamais ajoutée automatiquement aux
clés reconnues. Une configuration existante sans ces propriétés doit rester
utilisable ; l'écran signale que sa configuration est manquante.
La coordination d'une rotation de clés reste à concevoir.

Le comportement du client de consultation est le suivant :

- Consulter les serveurs dans l'ordre, en HTTPS avec validation du certificat
  et sans suivi des redirections. Une panne réseau, un délai dépassé ou une
  erreur HTTP permet d'essayer le serveur suivant.
- Un catalogue valide sans mise à jour compatible termine la recherche ;
  il ne déclenche pas un changement de serveur.
- Une signature invalide, une clé inconnue, un manifeste non conforme ou des
  notes dont la taille ou l'empreinte ne correspond pas arrêtent la vérification
  avec une erreur explicite, sans poursuivre sur un autre serveur.
- Les notes et ZIP téléchargés en secours doivent conserver leur identité
  signée : un serveur de secours ne peut substituer une autre version ou
  une autre archive. Une intégrité ou une structure ZIP invalide arrête
  le téléchargement sans essayer un autre serveur.

Les deux domaines actuels partagent le même hébergement et le même stockage :
ils ne constituent pas une protection contre une panne de cet hébergement.

## Vérifications

```bash
php update_server/tests/run.php
```

Le test utilise un serveur PHP éphémère lié à `127.0.0.1`, des clés et des ZIP
factices sous `/tmp`, nettoyés ensuite. Il n'utilise ni SQL, ni secrets réels,
ni données du site. Il couvre la publication par le vrai client PHP, les
signatures, tokens, filtres, téléchargements, chemins, doublons et concurrence.
Les paramètres HTTPS, upload et Authorization du mutualisé restent à vérifier
sur l'hébergement avec Yann. Le test couvre aussi le mini-UI : connexion,
cookie et CSRF, approbation bêta puis promotion en prod, refus d'une version
en prod, suppression physique d'une proposition, déconnexion et compte
désactivé.

## Validation du déploiement avant les clés définitives

Pour les premiers essais sur l’hébergement, utiliser une paire de clés et un
token provisoires avec un `storage_path` dédié à la validation. Ne pas mélanger
ces paquets au catalogue définitif. Après les essais, configurer le stockage
et les clés de production avant d’utiliser les mises à jour réelles.
Les anciennes clés ne doivent pas être retirées d’un catalogue conservant
des releases signées avec elles : la lecture vérifie les signatures stockées.
