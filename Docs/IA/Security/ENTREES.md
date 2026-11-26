# Inventaire des entrées et des contrôles

## Lecture de l'inventaire

[ROUTES.csv](ROUTES.csv) contient les **déclarations statiques**, une ligne par
méthode HTTP et action. Les sources et lignes désignent l'arbre courant décrit
dans [le rapport du lot 03](Reports/LOT-03.md). L’instantané du lot 01
est conservé dans [les preuves](Evidence/LOT-03/routes-before.csv). La colonne
`ligne` courante désigne le début de la méthode PHP. Les colonnes
d’autorisation et de Space sont des contrats à éprouver, pas des garanties vérifiées pour chaque appel.

L’actualisation du lot 03 utilise [la réflexion PHP](Evidence/LOT-03/extract-routes.php)
sur les déclarations et leurs arguments. Seul l’autoload est chargé : aucun
constructeur de contrôleur ou d’attribut, aucune configuration locale ni
initialisation d’Application. Les cibles et contrats métier de l’inventaire
sont conservés et complétés par lecture des nouveaux appels. Les appels
indirects, hérités, depuis une vue ou une extension doivent être suivis dans les lots concernés.
La colonne `effet_famille` décrit les effets possibles de la famille, pas un
effet de bord démontré pour chacune de ses routes. Un GET n'est pas présumé pur.

## Déclarations et enregistrement

| Ensemble | Déclarations méthode/verbe | Enregistrement repéré |
| --- | --- | --- |
| Core | 141 | Contrôleurs retournés par `Core/Plugin::getControllers()` ; socle obligatoire. |
| MainTemplate | 2 | CSS et essai d'e-mail, enregistrés par la template obligatoire. |
| Automation | 21 | Chargement optionnel ; activée en production selon Yann. |
| Test | 3 | Extension chargée uniquement lorsque `Configuration.dev` est vrai. |
| Matrix | 148 | Contrôleur absent du tableau retourné par son plugin ; déclarations dormantes. |
| **Total** | **315** | 36 classes annotées, dont `HomeController` sans action annotée. |

Les 164 déclarations hors Matrix/Test représentent 150 API et 14 routes non
API, soit 57 GET et 107 POST. Leurs noms sont uniques dans cet ensemble.
Le [delta](Evidence/LOT-03/routes-delta.json) retire POST `/automation/cron`,
ajoute GET/POST `/cron` et six routes de maintenance/relance Automation.
Cette vérification ne prouve pas l'absence de collision avec les routes
dynamiques ou avec une extension installée différente sur le serveur.

## Contrôles transverses repérés

- `Router::cleanAndRegisterRoutes()` lit les contrôleurs des extensions
  chargées ; `registerController()` compose les chemins et verbes, puis
  `resolve()` extrait paramètres et attributs. Les entrées `ControllerParam`
  peuvent renvoyer à un schéma serveur ; cette validation ne vaut pas ACL.
- Le runner vérifie `X-CSRF-Token` pour une API non exemptée hors GET/HEAD/OPTIONS.
  Cela couvre 101 POST parmi les 164 déclarations candidates.
- `ControllerManager::onControllerCall()` traite l'attribut ACL de méthode.
  Une seule déclaration de cet ensemble porte un tel attribut : les autres
  dépendent des contrôles métier, à suivre dans les WF et autres cibles.
- 22 déclarations portent `NoTransaction`. Les autres sont encadrées par le
  runner ; les fichiers statiques et moteurs autonomes restent en dehors.
- 53 déclarations portent une exemption WAF de classe ou de méthode. Les autres
  dépendent du mode configuré et de l'exemption ROOT. Le WAF peut être désactivé
  et ne fournit ni autorisation ni CSRF ni limite globale de lecture du corps.

## Familles métier

Les comptes de routes et les appels précis sont dans le CSV. Ce tableau donne
les frontières à suivre au-delà du contrôleur ; il ne conclut pas que les
permissions listées suffisent pour toutes les opérations.

| Famille / préfixe | Cible et autorisation à vérifier | Space / effets à suivre | Lots |
| --- | --- | --- | --- |
| `/auth/api/*` | `WFAuthentication`, jeton serveur du composant, politique de compte/inscription | Space désigné par configuration validée ; compte, adhésion, session | 05, 06, 04 |
| `/account/api/*` | `WFMyAccount`, `WFSso`, `WFOAuthIntegration` ; propriétaire de session | Compte global et politique de Space ; profil, mot de passe, liens et jetons | 05, 06, 07 |
| `/login-fb`, `/sso/*`, `/integrations/oauth/callback` | Contrôleur puis WF pour SSO/OAuth ; CSRF ou state propre au flux | Session, fournisseur relu et identité externe ; redirection et connexion | 03, 05, 07 |
| `/forgot-password`, `/reset-password/{token}` | `WFPasswordReset` et CSRF explicite pour les POST | Compte lié au jeton et Space principal ; e-mail, mot de passe et révocation | 05, 06, 11 |
| `/space/choose` et `/space/api/switch/*` | Parcours historique direct versus `WFSpaceSwitch` et jeton de composant | Choix de Space, règle MEMBER/inscription et mutation de session | 03, 04, 06 |
| `/administrator` | Vue RIA et bootstrap ; chaque API doit autoriser ses données | Space courant ; ne pas confondre visibilité du menu et accès serveur | 04, 09 |
| `/administrator/api/users/*`, `/user-assignments/*` | `WFAdminUser`, droits de lecture, administration et attribution | Compte cible, Space de chaque rôle/groupe, protections ROOT | 04, 05, 08 |
| `/administrator/api/roles/*`, `/user-groups/*` | `WFRole`, `WFUserGroup` ; permissions dédiées et délégation | Space réel, héritage parent/groupe et prérequis de permissions | 04, 08 |
| `/administrator/api/spaces/*` | `WFSpace` ; SPACE_EDIT/SPACE_ADMIN et droits spécifiques | Space relu ; configuration, dossier média et SMTP | 04, 10, 11 |
| `/administrator/api/menus/*` | `WFMenu` ; MENU_READ/MENU_CRUD | Menu/item relus et Space propriétaire ; arbre et liens | 04, 08 |
| `/administrator/api/pages/*` | `WFPage` ; PAGE_READ/PAGE_CRUD, cas ROOT/AI_USE | Page relue ou Space de création ; contenu, brouillons, canevas et publication | 04, 09, 08 |
| `/administrator/api/medias/*` | `WFMedia` ; MEDIA_READ/MEDIA_CRUD | Space et racine réelle ; listing, upload, édition et suppression | 10, 04 |
| `/administrator/api/fonts/*` | `WFFont` ; FONT_ADD/FONT_MANAGE | Polices globales et sélection par Space ; réseau, disque et SQL | 10, 11, 04 |
| `/administrator/api/languages/*` | `WFLanguage` ; LANG_OVERRIDE_CRUD et droits de sélection | Space, langue et héritage ; surcharges et texte riche | 04, 08, 09 |
| `/administrator/api/ext/*` | `WFExtensions` ; contrat de l'extension, ROOT pour Security | Space cible et extension chargée ; configuration potentiellement sensible | 04, 03, 13 |
| `/administrator/api/email-templates/*` et essai MainTemplate | `WFEmailTemplate`, `WFExtensions` ; droits dédiés | Space/surcharge/template ; rendu et SMTP réel | 11, 09, 04 |
| Administration IA et `/api/ai/*` | `WFAi` ; SPACE_ADMIN, AI_USE, AI_GENERATE_IMAGE selon opération | Fournisseur/Space, batch et compte ; réseau et coûts | 11, 04, 13 |
| Administration SSO | `WFSsoProvider` ; SSO_CRUD | Space du fournisseur relu ; endpoints et secrets | 07, 04, 13 |
| `/administrator/api/logs/*` | `WFLogs::assertRoot()` | ROOT sur Space 1 ; accès aux fichiers privés et curseurs bornés | 13, 04 |
| Mises à jour et `/administrator/api/infos_system` | `WFSystemUpdate`, `WFSystemInfo` ; ROOT | Installation globale ; réseau, ZIP, préparation et informations système | 14, 15, 13 |
| `/administrator/api/automation/*` | `WFAutomation` ; permissions du scénario et des actions | Space réel, version, auteur, principal ; code, secrets, exécution et résultats | 12, 04, 08 |
| `/page/button` | `WFPageButton` ; CSRF puis HMAC lié page/nœud et contrôle de page | Page publiée dans le Space courant, scénario cible et principal | 12, 03, 04 |
| `/automation/webhook/{scenarioId}` | `WebhookReceiver` ; bearer/query/HMAC selon configuration | Scénario et trigger relus ; événement, principal et réponse résultat | 12, 13, 03 |
| `/cron` (GET/POST) | `CronDispatcher` ; jeton `cron_token` en en-tête, query, corps ou mot de passe HTTP Basic | Hooks des extensions ; automatisation planifiée, transactions par scénario | 12, 13, 03 |
| `/pages/{id}/css`, `/templates/main/css`, `/secure-video/stream` | Helpers et contrôles de statut, chemin/source et format | Lecture publique ; la protection de gestion ne rend pas l'URL privée | 09, 10, 13 |

Les préfixes de ce tableau sont des regroupements : utiliser le CSV pour les
URL littérales, notamment `medias`/`menus` et les routes d'administration IA/SSO.

## Exceptions CSRF et méthodes à effet possible

| Entrée | Protection repérée / limite | Suite |
| --- | --- | --- |
| POST de récupération et POST `/sso/start` | Vérification CSRF explicite dans le contrôleur, hors protection automatique des API. | Vérifier le flux complet et ses refus aux lots 03/05/07. |
| POST `/space/choose` | Jeton de session dans le formulaire, vérifié avant helper/mutation ; refus et réussite testés localement. | [SEC-008](Findings/Resolved/SEC-008.md) ; ACL et effets inter-Space au lot 04. |
| GET SSO/OAuth callback | `state` et contrôle de flux délégués au WF ; hors CSRF automatique. | Rejeu, changement de session et identité au lot 07. |
| GET de menu logout | Destruction de session reproduite sur URL de menu factice ; pas de preuve d’intention. | [SEC-009](Findings/Confirmed/SEC-009.md), choix fonctionnel en attente. |
| GET/POST webhook | `withoutCsrf`, exemption WAF ; authentification spécifique du trigger. | Examiner les trois modes, secrets en URL, rejouements et mode résultat au lot 12. |
| GET/POST `/cron` | `withoutCsrf`, exemption WAF, `NoTransaction` ; jeton/Basic vérifié par CronDispatcher avant hooks. | Authentification unitaire vérifiée au lot 03 ; concurrence, rejeu, principal et budgets aux lots 12/13. |

## Routes dynamiques construites par les menus

`Core::coreOnRegisterMenuTypes()` déclare les types ci-dessous.
`MenuRouteHelper` compose les URL depuis la langue, le menu, les parents et
l'alias de l'item ; il raccorde le Space courant et les données de menu à la route.
Il crée également une route d'accueil de secours vers `HomePlaceholderController`.

| Type | Méthode / cible | Contrôles et effets à suivre |
| --- | --- | --- |
| `page` | GET → `PageController::page()` | Page publiée du même Space que le menu ; visibilité de menu/langue et rendu. |
| `logout` | GET → `UserController::logout()` | Mutation de session ; OBS-009. |
| `admin` | GET → `AdminController::home()` | Vue admin ; autorisations des données/API indépendantes du menu. |
| Accueil sans page configurée | GET → `HomePlaceholderController::home()` | Vue de secours ; chemin public localisé. |
| Ancien chemin public | Redirection GET construite par le parcours de langue | Vérifier construction de destination et absence de mutation indue. |

`text` et `separator` n'ont pas de contrôleur ; ils ne créent pas de route.
Les fournisseurs de types de menu des extensions peuvent compléter ce circuit.
Dans le dépôt courant, les autres implémentations repérées retournent une
liste vide. Les valeurs SQL des menus ne sont pas inventoriées dans ce lot.

## Entrées autonomes, fichiers et outils

| Entrée | Authentification / contrôles à suivre | Effets et lots |
| --- | --- | --- |
| `src/public/index.php` puis release active | Schéma d'état, identifiant et chemin réel bornés avant inclusion PHP ; erreurs précoces distinctes. | Bootstrap/configuration, lots 03/15/16. |
| `/update_<secret>.php` → `src/updater/index.php` | Nom secret lié à l'état d'opération ; snapshot ; cookie et en-tête CSRF propres aux POST ; origine vérifiée si fournie. | Préparation, maintenance, backup, migrations, activation et récupération ; lots 13/15. |
| `update_server/public/index.php?action=releases|manifest|package|notes` | GET ; HTTPS ; validation des filtres/version ; données de release vérifiées au repository. | Lecture/distribution publique, lot 14. |
| Même entrée, `action=publish` | POST ; bearer de publication ; multipart et tailles ; repository/signature. | Publication distante, lot 14. |
| `src/public/assets*`, `public/medias` | Servis par Apache, hors runner/CSRF/WAF ; `.htaccess`, MIME et permissions réels à vérifier. | Ressources publiques, fichiers actifs et bibliothèques embarquées ; lots 02/09/10/16. |
| Commandes `build`, `build_extensions`, `update_server/tools` | Confiance opérateur, paramètres CLI, fichiers de configuration et clés hors paquets. | Fabrication, signature, publication ; lots 02/14. |
| `src/tools`, scripts Docker et préparation de tests | Contexte local/DEV ; ne doivent pas devenir des entrées web de production. | Secrets, SQL, resets, ports exposés ; lots 02/14/16. |
| Adminer, phpMyAdmin, Mailpit et Vite de `docker/dev` | Outils de développement ; contrôle de bind, comptes et exposition requis. | À séparer de la production, lot 16. |

L'updater autorise explicitement GET/POST et une liste d'actions POST. La liste
et ses transitions doivent être relues au lot 15, y compris `status`, `prepare`,
`maintenance`, `backup`, `skip_backup`, `migrate`, `activate`, `online`, `restore`,
`rollback`, `external_restore` et `cancel`. Aucun lien secret réel n'est exporté.

## Points d'entrée internes et limites de taille

L'automatisation entre aussi par `AutomationTrigger::emit()`/`triggerNow()`,
le buffer d'événements et le hook après route. `RunSession`, l'exécuteur et le
registre d'actions reconstruisent un contexte : vérifier les droits actuels
dans les WF/actions même lorsqu'aucune route HTTP ne les a filtrés.
Les migrations et hooks d'extensions s'exécutent avec les droits de l'application.

Les limites repérées sont exportées par famille dans `ROUTES.csv`. Valeurs de
départ : page 1 000 000 octets (plafond 5 000 000), SVG 250 000, upload média
25 Mio ; webhook 262 144 octets et 120 événements/minute ; fournisseur
Automation du cron : 500 déclencheurs, sans verrou global Core ;
langage 65 536 octets, 100 000 étapes, 200 actions et budget nominal 10 secondes.
Le clic bouton possède aussi des limites de session 10/minute et 100/heure.

Ces valeurs n'ont pas été éprouvées. Une limite vérifiée après lecture du
corps ou entre deux appels externes ne garantit pas un plafond global en mémoire
ou en durée. Les réglages PHP/Apache hébergés restent inconnus. Les schémas et
quotas spécifiques devront être suivis opération par opération aux lots 03/08/12.

## File de jobs différés (surfaces ajoutées après l'instantané du lot 03)

Ces entrées sont postérieures à [ROUTES.csv](ROUTES.csv) ; elles seront reprises
à la régénération du prochain lot. Les contrôles listés sont des intentions
lues dans le code, pas des garanties éprouvées.

| Entrée | Contrôle actuel | À éprouver |
| --- | --- | --- |
| `GET/POST /queue-worker` | Jeton `job_worker_token` en Bearer, query ou corps ; une seule source ; `hash_equals` ; `503` si le secret n'est pas configuré ; hors CSRF et hors WAF ; `#[NoTransaction]`. | Jeton en query recopié dans les journaux d'accès ; GET imposé par l'hébergeur ; rejeu rapide et concurrence (verrou `GET_LOCK`) ; aucune fuite du jeton dans une réponse ou un journal. |
| `POST /api/jobs/ping` | Anonyme, sans paramètre, sans session ; renvoie des compteurs ; reste sous WAF ; `Sec-Fetch-Site: cross-site` refusé quand l'en-tête est présent ; verrou non bloquant et lot borné (10 jobs, 5 s). | Abus par appels répétés : aucun jeton par conception, la borne réelle est le verrou et le budget. Un visiteur ne doit ni créer, ni réordonner, ni relire un job. Mesurer le coût d'un démarrage PHP par appel. |
| `administrator/api/jobs/*` : `list`, `state`, `detail`, `documentation` (GET) ; `retry`, `cancel`, `purge`, `run` (POST) | Écran ROOT ; `#[Acl(ROOT, space 1)]` sur les lectures ; CSRF automatique sur les POST ; `assertRoot()` dans `WFJobs`. | `run` exécute réellement des jobs : vérifier qu'aucun autre rôle n'y accède. Payload lisible mais jamais modifiable. Purge bornée (365 jours, 500 lignes). Actions journalisées `JOB_ADMIN_*`. |
| Compte système `users.id = 2` | Type `service`, sans mot de passe, rattaché au groupe 3 (rôle ROOT) ; suppression refusée pour les identifiants 1–999 ; recréé au premier besoin. | Escalade par principal `system` : tout producteur de job exécute du code avec les droits ROOT. La frontière est l'installation des extensions, pas l'enfilement. Vérifier que l'ID 2 n'est jamais réattribué à un compte réel et que le provisionnement reste idempotent. |
| Payload de job | JSON ≤ 256 Ko, stocké en `mediumtext`, affiché en lecture seule dans l'écran ROOT. | Aucun secret ne doit y transiter malgré la règle documentée (jetons, mots de passe). Type résolu par registre, jamais par nom de classe stocké. Un handler rejoué doit rester idempotent. |
| Dates | `SET time_zone = '+00:00'` à la connexion ; `DateTools` pour les écritures PHP ; exception documentée pour les colonnes `date` (jour calendaire). | Aucune comparaison d'une heure SQL avec `time()` en PHP ; lignes historiques écrites avant l'alignement, potentiellement en heure locale. |
