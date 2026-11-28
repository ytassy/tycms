# Journaux applicatifs

Le moteur est dans `src/releases/vdev/framework/Logging`. Il fonctionne avec
la configuration PHP, avant SQL et sans session ni extension. Core expose
sa consultation dans « Administration du site → Journaux », uniquement pour
la permission ROOT sur le Space racine 1.

Les e-mails sortants disposent d'un journal distinct dans la table `mail_logs`,
avec ses propres statuts, sa rétention et son écran ROOT. Il conserve des
adresses et des corps après masquage des secrets, ce que ces journaux
applicatifs ne font jamais. Ses règles sont décrites dans [E-mails](MAIL.md).
Une panne de ce journal produit `MAIL_LOG_FAILED` ou `MAIL_LOG_UPDATE_FAILED`
dans le canal `mail`.

## Configuration et stockage

`Configuration.log_path` donne la racine privée, par défaut `APP_ROOT/logs`.
Le service gère uniquement son sous-dossier `tycms/`, réparti en `info/`,
`warning/` et `error/`. Les anciens fichiers étrangers ne sont pas importés,
lus ou purgés. Aucune table de logs ni migration SQL n'est nécessaire.

Dans la classe `Configuration` du fichier local `configuration.php` :

```php
public array $log_levels = [
    'INFO' => ['retention_days' => 90, 'max_bytes' => 50_000_000],
    'WARNING' => ['retention_days' => 90, 'max_bytes' => 50_000_000],
    'ERROR' => ['retention_days' => 90, 'max_bytes' => 50_000_000],
];
public int $log_max_file_bytes = 10_000_000;
```

Les défauts hérités sont identiques à cet exemple. Une surcharge de tableau
doit garder exactement les trois niveaux et leurs deux champs. Chaque niveau
possède son propre âge et son propre quota, actif et archives compris. Une
entrée ERROR est écrite uniquement dans ERROR. Un afflux d'INFO ne consomme
aucun quota WARNING ou ERROR. Avec les défauts, les données occupent au plus
150 Mo cumulés, hors métadonnées bornées.

Les segments JSONL UTF-8 ont des noms générés `tycms-YYYYMMDD-NNNNNN.jsonl`.
Un nouveau jour UTC ou une taille limite atteinte ouvre un nouveau segment ;
les précédents constituent les archives, sans copie ni compression. La
purge supprime d'abord les plus anciens et réserve la place de l'entrée avant
son écriture. La durée est un maximum : le quota peut réduire l'historique.
La purge d'âge travaille par jour UTC, en supprimant le jour limite inclus.

Bornes de configuration : durée de 1 à 3650 jours, segment de 16 000 à
50 000 000 octets, quota de chaque niveau au moins égal au segment et au
plus égal à 1 000 000 000 octets. Le stockage limite aussi chaque niveau
à moins de 512 segments et borne le listing des dossiers. Ce plafond peut
réduire davantage l'historique avec de très petits segments ou une longue
durée. Une configuration invalide suspend l'écriture gérée sans bloquer le
site ; l'écran indique l'indisponibilité.

## API d'écriture pour les classes métier

`BaseApplicationItem` fournit un accès typé `Logger $logger` et quatre
méthodes protégées, disponibles pour ses classes dérivées :

```php
$this->logInfo('Le traitement a démarré.');
$this->logWarning('Configuration indisponible.', ['space_id' => $spaceId]);
$this->logError('La préparation a échoué.', ['space_id' => $spaceId], 'PREPARATION_FAILED', 'application');
$this->logException($exception);
```

Signature des trois premières : `message`, `context = []`, `code` par défaut
`APPLICATION_INFO`, `APPLICATION_WARNING` ou `APPLICATION_ERROR`, puis
`channel = 'application'`. Le service est aussi accessible par
`Application::getLogger()` pour l'infrastructure.

Les messages passés aux méthodes simples sont des libellés contrôlés par le
code. Ne pas concaténer une entrée utilisateur, un jeton, une adresse, une URL
ou un message externe : utiliser `logException()` pour une exception.
Le contexte n'accepte que les IDs entiers positifs `space_id`, `user_id`,
`provider_id`, `policy_space_id` et des valeurs techniques courtes pour
`route`, `method`, `mode`, `kind`, `source`, `pattern`, `auth_method`,
`google_error`, `google_reason`, `google_operation`, `event_type`, `channel`,
`listener`, `extension`, `hook`. Les autres champs sont écartés ; aucun objet
métier ou objet Request n'est sérialisé. Les diagnostics réseau acceptent aussi
`http_status` entier de 100 à 599, `curl_errno` entier de 1 à 999 et `deleted`
entier de 0 à 10 000 000 pour un compteur de purge ; les chaînes et valeurs
hors bornes sont écartées. Pour Google,
`google_error` est un code interne stable, `google_reason` la raison technique
bornée et `google_operation` le nom court de l'action ; le message brut du
fournisseur n'est jamais copié dans le journal. Exception ciblée :
`first_name` est accepté uniquement dans l'entrée INFO `USER_LOGIN_SUCCEEDED`
du canal `authentication`, après normalisation et limitation à 100 caractères.

`LogEventException` permet à un WF de porter une raison stable avec son
libellé contrôlé, son canal, son contexte, son niveau et sa cause `previous`.
Elle hérite de `RuntimeException` ; elle ne change aucune autorisation.
Les refus SSO/OAuth utilisent notamment `SSO_MEMBER_REQUIRED`,
`SSO_ACCOUNT_BLOCKED`, `SSO_AUTO_LINK_DENIED`, `SSO_EMAIL_UNVERIFIED`,
`SSO_STATE_EXPIRED` et les échecs d'échange de code ou de contrôle OIDC.
Le message public reste générique.

## Journal d’événements et notifications

Le journal métier `event_log` n’est pas un journal applicatif : il est décrit
dans [Journal d’événements et notifications](NOTIFICATIONS.md). Ses pannes et
décisions techniques s’écrivent ici, dans le canal `event` :

- `EVENT_LOG_WRITE_FAILED` : écriture du journal impossible ; l’écouteur est
  critique et fait échouer le métier ;
- `EVENT_LISTENER_FAILED` : un écouteur non critique a échoué ;
- `EVENT_DEPTH_EXCEEDED` : chaîne d’événements au-delà de huit niveaux ;
- `EVENT_LOG_PURGE` : purge horaire du journal, avec le Space dans le contexte
  et le nombre de lignes dans le message ;
- codes du fan-out et des livraisons, à livrer avec les lots 2 à 4 :
  `NOTIFICATION_AUDIENCE_TOO_LARGE`, `NOTIFICATION_FANOUT_ENQUEUE_FAILED`,
  `NOTIFICATION_FANOUT_SKIPPED`, `NOTIFICATION_FANOUT_DONE`,
  `NOTIFICATION_FANOUT_FAILED`, `NOTIFICATION_EVENT_UNAVAILABLE`,
  `NOTIFICATION_DEFINITION_UNAVAILABLE`, `NOTIFICATION_CHANNEL_UNAVAILABLE`,
  `NOTIFICATION_CHANNEL_SECRET_INVALID`, `NOTIFICATION_CHANNEL_FORBIDDEN`,
  `NOTIFICATION_CHANNEL_PURGED`, `NOTIFICATION_CHANNEL_PURGE_FAILED`,
  `NOTIFICATION_DELIVERY_ENQUEUE_FAILED`, `NOTIFICATION_DELIVERY_FAILED`,
  `NOTIFICATION_PURGE`.

Le contexte porte le Space, le type d’événement, le canal ou l’écouteur selon
le cas ; jamais la charge utile, un texte rendu ou un secret.

## Prévoir les logs pendant le développement

Pour chaque fonctionnalité ajoutée ou modifiée, identifier les événements qui
seront utiles pour comprendre un incident en production. Privilégier les
appels externes en échec, les causes techniques masquées par une réponse
publique générique et quelques actions importantes. Ne pas journaliser chaque
lecture, chaque passage dans un WF ou les validations ordinaires de formulaire.

- INFO décrit une étape significative ; une demande doit être libellée comme
  telle. Une réussite transactionnelle ne peut être annoncée qu'après commit.
- WARNING signale un refus ou un résultat dégradé pris en charge.
- ERROR signale un échec technique nécessitant un diagnostic.

Utiliser un canal métier et un code stable filtrables dans l'écran ROOT.
Prendre le Space réel et les identifiants déjà autorisés, sans ajouter de
lecture SQL pour enrichir le journal. `user_id` désigne le compte acteur ; ne
pas le remplacer par celui d'une cible administrative.

Lorsqu'une exception technique est interceptée pour produire une réponse
utilisateur, conserver sa cause dans `ExceptionResponse`, éventuellement
avec une `LogEventException` portant un code et un libellé contrôlés. Le runner
la capture. Si l'exception est absorbée et qu'aucune cause ne remonte, appeler
`logException()` ; éviter une seconde entrée manuelle pour le même incident.

Ne jamais copier `getMessage()` d'une bibliothèque ou d'un fournisseur dans
un log simple. Les clés, jetons, mots de passe, adresses e-mail, URL, prompts,
conversations, images et corps de réponses distantes restent hors du journal.
Un code d'erreur distant peut être traduit par une liste fermée de libellés
contrôlés, comme dans le diagnostic IA ; un code inconnu reste générique.

## Exceptions et raccordements

Les captures sont branchées sur les frontières d'initialisation de
`Application`, la résolution/exécution des routes, le runner et l'envoi de
réponse. Les métadonnées déjà disponibles sont utilisées sans nouvelle
requête SQL. Le retour SSO fixe le Space du flux et le compte examiné pour
éviter de diagnostiquer dans le mauvais Space.

`logException()` parcourt `getPrevious()` **et** la propriété `exception` des
`ExceptionResponse`, même imbriquées, sans cycle. Une même exception ou cause
déjà enregistrée n'est pas écrite deux fois dans la requête. Une
`ExceptionResponse` sans cause ne devient pas une erreur technique.

Chaque cause garde sa classe et sa localisation, avec au plus dix causes et
six localisations par cause. Les arguments, messages de bibliothèques,
chemins absolus et noms secrets des scripts updater ne sont pas enregistrés.
Le libellé d'une `LogEventException` contrôlée remplace le libellé technique
générique. L'entrée est bornée à 16 000 octets et porte `truncated` si la
chaîne, les piles ou le message ont dû être réduits.

Chaque passage d’une session invitée à un utilisateur connecté est journalisé
une fois après le commit de la route : INFO, canal `authentication`, code
`USER_LOGIN_SUCCEEDED`. Le contexte contient l’ID du compte, son prénom local
(`first_name`), le Space et `auth_method=local` ou `sso` pour les parcours Core,
sans email ni jeton. Le prénom est aussi affiché dans le message, par exemple
« Connexion utilisateur réussie : Élodie. ». Il provient du compte validé ou
créé, sans nouvelle lecture SQL ; les contrôles et séparateurs Unicode sont
normalisés sur une ligne. Un prénom absent conserve le message générique.
Les autres événements ne conservent pas ce champ.
Cela inclut la connexion après inscription ; une simple requête d’un compte
déjà connecté, une liaison SSO explicite ou une autorisation OAuth n’ajoute
pas cet événement.

Un handler de fin couvre les fatales PHP après son démarrage, avec une
réserve mémoire et le seul code `PHP_FATAL`. Les erreurs avant configuration
ou autoload, les arrêts forcés et certaines pénuries de mémoire restent dans
les logs PHP/serveur de l'hébergement. Le WAF émet `WAF_EVENT` dans INFO en
observation, WARNING en blocage et ERROR sur analyse incomplète ou en erreur,
avec ses seules métadonnées techniques.

## Diagnostics des workflows Core

| Canal | Événements ciblés |
| --- | --- |
| `security.http` | Refus d'une destination interne ambiguë : `HTTP_REDIRECT_TARGET_REJECTED`. Refus du jeton du formulaire historique de choix de Space : `SPACE_CHOICE_CSRF_INVALID`. WARNING sans URL, jeton ni identifiant cible soumis ; contexte courant du logger conservé. Les paramètres manquants ou de mauvais type suivent la réponse 400 ordinaire. |
| `ai` | Erreurs réseau, HTTP et formats de réponses ; batch distant en échec ou annulé. Voir [le diagnostic IA](AI.md#diagnostiquer-les-erreurs). |
| `system.update` | Demandes ROOT de téléchargement, préparation, reset et nettoyage en INFO (`UPDATE_*_REQUESTED`) ; échecs correspondants et recherche en WARNING (`UPDATE_*_FAILED`), configuration invalide en ERROR (`UPDATE_CONFIGURATION_INVALID`). Aucune version fournie, URL ou lien secret n'est enregistré. |
| `mail` | Récupération : `PASSWORD_RESET_MAIL_FAILED` / `PASSWORD_RESET_MAIL_NOT_SENT`. Bienvenue : `WELCOME_MAIL_FAILED` / `WELCOME_MAIL_NOT_SENT`, avec la clé de modèle contrôlée dans `kind`. Essais SMTP et habillage : `SMTP_TEST_FAILED` / `SMTP_TEST_NOT_SENT`, `TEMPLATE_TEST_MAIL_FAILED` / `TEMPLATE_TEST_MAIL_NOT_SENT`. Exceptions en ERROR avec leurs causes internes ; retour `false` en WARNING, envoi désactivé inclus. Aucun destinataire, contenu, jeton ou réglage SMTP n'est ajouté. |
| `fonts` | `FONT_INSTALL_FAILED` et `FONT_CATALOG_REFRESH_FAILED` en WARNING avec les causes internes et le Space concerné ; aucun nom soumis ni URL de téléchargement. |
| `media` | Échecs de stockage qualifiés par `MediaHelper`, conservés par `WFMedia` et les validations de sélection : `MEDIA_ROOT_UNAVAILABLE`, `MEDIA_DIRECTORY_CREATE_FAILED`, `MEDIA_UPLOAD_WRITE_FAILED`, `MEDIA_REPLACE_PREPARE_FAILED`, `MEDIA_REPLACE_FAILED`, `MEDIA_RENAME_FAILED`, `MEDIA_MOVE_FAILED`, `MEDIA_DELETE_FAILED`, `MEDIA_EDIT_WRITE_FAILED`, en ERROR. `MEDIA_RESTORE_FAILED` signale séparément une compensation qui a échoué. Seul le Space est ajouté, sans chemin ni nom ou contenu de fichier. |
| `space` | Création/réservation du dossier média impossible : `SPACE_MEDIA_DIRECTORY_CREATE_FAILED` / `SPACE_MEDIA_DESTINATION_FAILED` en WARNING. Renommage/restauration impossible : `SPACE_MEDIA_RENAME_FAILED` / `SPACE_MEDIA_RESTORE_FAILED` en ERROR. `SPACE_MEDIA_RENAMED` en INFO est émis uniquement après le commit explicite du WF. |
| `registration` | `ENROLLMENT_MEMBER_REQUIRED` en WARNING si les attributions après inscription/création n'accordent pas MEMBER ; règle commune aux parcours local, SSO et administratif. Aucun ID de compte cible non encore validé n'est ajouté. |
| `extensions` | `EXTENSION_CONFIG_PREPARATION_FAILED` en WARNING conserve les causes du hook de préparation, le Space et l'identifiant d'extension dans `source`, sans configuration soumise. |

Les erreurs de formulaire attendues restent silencieuses. Ces traces ciblées
ne constituent pas un audit exhaustif des modifications administratives.

## Concurrence, confidentialité et pannes

Un verrou stable protège la purge, la sélection d'un segment et son écriture.
L'attente maximale est de 50 ms. Une ligne finale interrompue est tronquée
à la dernière ligne complète avant reprise ; `LOG_TAIL_REPAIRED` le signale.
Les autres lignes corrompues sont signalées et ignorées à la lecture.

Les dossiers gérés sont protégés en `0700`, les fichiers en `0600`.
Les dossiers publics connus sont refusés, y compris les racines de médias et
de polices déplacées. Les liens et fichiers non réguliers sont refusés ;
l'identité du fichier ouvert est comparée à celle du chemin et les liens
physiques multiples sont refusés. Le déploiement doit aussi garder cette
racine hors de tout alias HTTP externe au contrat du framework.

Une panne de stockage ou un verrou occupé ne modifie ni résultat métier ni
transaction. Un signal minimal `LOG_WRITE_FAILED` avec la référence de requête
part vers `error_log()`, au plus une fois par instance de logger, sans
récursion ni données de l'incident. Ce secours n'est pas géré par la rotation
et n'est pas importé dans l'écran. Les journaux de diagnostic restent présents
si une transaction SQL est annulée ; ne pas y annoncer un succès avant commit.

La rétention s'exécute lors d'écritures et de consultations ROOT. Sans requête,
aucune purge n'est déclenchée : une échéance stricte demande un ordonnanceur
extérieur. Ces fichiers ne constituent pas un audit infalsifiable. Une panne
SQL peut empêcher l'accès à l'écran ROOT, même si le logger peut encore écrire.

## Consultation ROOT et limites de lecture

`AdminLogsController` délègue à `WFLogs`, qui vérifie `isRoot()` avant tout
accès aux fichiers, puis à `LogReaderHelper` et au lecteur Framework.
Changer de Space ne change pas ce contrôle, qui porte toujours sur le Space 1.
`SPACE_ADMIN`, `SSO_CRUD` et les rôles ordinaires ne donnent aucun accès.

| Route | Contrat |
| --- | --- |
| `GET /administrator/api/logs/status` | État et volume du stockage, nombre de segments, période disponible et réglages par niveau. |
| `POST /administrator/api/logs/read` | `filters` JSON, `cursor` facultatif ; lecture paginée sans transaction automatique, CSRF commun requis. |

Le POST garde le curseur, qui contient un instantané borné des positions,
hors des URL et de leurs limites de longueur. Il s'agit d'une consultation ;
aucun export ni suppression manuelle n'est proposé. Les filtres sont les
niveaux sélectionnés, les jours UTC `from`/`to`, le canal, le code, l'ID du
Space et la référence de requête exacts. Aucune regex utilisateur n'est exécutée.

Le lecteur fusionne les trois niveaux par date, en parcourant les fichiers
à rebours par blocs. Une page contient au plus 100 entrées, 1 Mo de données
et 4 Mo de blocs parcourus, cumulés sur les niveaux. Un filtre sans résultat
peut exiger de poursuivre : le curseur progresse même dans ce cas. Les
entrées arrivées après le début de la consultation attendent Actualiser.

Le curseur est signé avec une clé dérivée distincte de `Configuration.secret`,
lié aux filtres et valable quinze minutes. Il contient uniquement des noms
gérés, tailles, identités et positions revalidés côté serveur ; il est borné
à 500 Ko. Un segment purgé ou remplacé impose de rafraîchir l'historique.
Le lecteur vérifie aussi l'empreinte du début du segment pour détecter la
réutilisation d'un nom après purge.

Les réponses sont privées et `no-store`. Vue affiche les données comme du
texte, sans `v-html`. Le store ne conserve qu'une page en mémoire, ne persiste
rien dans localStorage et est vidé en quittant l'écran. Le composant commun
`text_copy` copie une entrée. Un essai visuel avec Yann reste nécessaire sur
l'hébergement cible, notamment pour ses droits de dossier.
