# Cartographie initiale de sécurité

Cette carte résume l'inventaire, les guides et les lectures ciblées du code.
Le cadrage détaillé est dans [le rapport du lot 01](LOT-01.md), [les entrées](ENTREES.md),
[le modèle de menace](MENACES.md) et [la couverture](COUVERTURE.md).
La révision de départ figure dans [l'index](README.md) ; les empreintes de
[PERIMETRE.csv](PERIMETRE.csv) identifient l'arbre courant du lot 01.

## Actifs et frontières de confiance

| Surface | Actifs et entrées | Frontière à vérifier | Lots |
| --- | --- | --- | --- |
| Entrée web principale | `src/public/index.php`, bootstrap `releases/vdev`, requêtes et hôtes | Internet → route → runner → contrôleur → WF | 01, 03 |
| Identités et sessions | Comptes, récupération, SSO/OAuth, cookies, session PHP ou SQL | Invité → compte ; compte → identité externe ; session révoquée → refus | 05, 06, 07 |
| Spaces, objets et administration | Pages, menus, langues, utilisateurs, rôles/groupes, configurations | Objet demandé → Space réel ; permission détenue → permission déléguée | 04, 08 |
| Stockage SQL et formulaires | DB/ORM, validations, migrations, champs sérialisés | Entrées HTTP et internes → requête/écriture → sortie API | 04, 08, 13 |
| Contenu éditorial et frontend | Vue/Pinia, HTML, CSS/SCSS, Markdown, SVG, Mermaid, aperçus et iframes | Contenu auteur ou IA → DOM public/admin ; iframe → parent | 03, 09 |
| Médias et polices | Fichiers sous `public/medias`, édition texte, HLS, téléchargements | URL/chemin reçu → fichier réel ; fichier publié → navigateur/interpréteur | 10, 11 |
| Services externes | IA et outils locaux, SMTP, OIDC/JWKS, polices, notifications | Application → réseau externe ; réponse distante → action locale | 07, 11, 12 |
| Automatisation | Langage, scénarios, version publiée, auteur/service, webhooks et cron | Entrée externe/interne → principal d'exécution → actions métier | 04, 08, 12 |
| Chiffrement et journaux | Secret applicatif, secrets fournisseurs, tokens, erreurs et logs | Donnée privée → disque/base → réponse/diagnostic | 02, 13 |
| Build et distribution | Composer/npm, bibliothèques copiées, ZIP, manifestes, signatures | Source/dépendance → artefact signé → serveur/client | 02, 14 |
| Mise à jour autonome | Lien secret, moteur updater, configuration, SQL, sauvegardes, état de release | ROOT → opération autonome → fichiers/DB/release active | 13, 15 |
| Déploiement | Apache/PHP, document root, modes DEV, outils Docker, droits disque/SQL | Configuration versionnée → environnement réellement exposé | 16 |

Les extensions système présentes sont Core, Security, MainTemplate, Tools,
Matrix, Test, TyCatalog et Automation. Le plugin installé LMS est un squelette.
Les autres extensions réellement installées sur l'hébergement restent à
confirmer ; le dépôt local ne prouve pas la composition de la production.

## Inventaire technique de départ

- Backend PHP 8.4+, framework HTTP/ORM maison, transactions gérées au runner
  sauf exceptions déclarées et moteurs autonomes.
- Frontend Vue 3/Pinia/Vite ; builds Core, Mermaid et LMS distincts.
- Un lockfile Composer sous `src/releases/vdev` et trois lockfiles npm
  sous `src/ria`, `src/tools/mermaid` et `src/extensions/plugins/Lms/frontend`.
- Code applicatif, outils de build, serveur de distribution et updater à
  examiner séparément. Les bibliothèques statiques publiées sont aussi à inventorier.
- Tests PHP unitaires/intégration et Node déjà présents. Leur existence n'est
  pas une preuve de couverture ou de réussite sur la révision courante.

## Observations de préparation

Les identifiants OBS ci-dessous désignent des **points de départ**, pas des
vulnérabilités classées. Une fiche SEC ne sera créée qu'après analyse du risque.
Une observation peut être reliée à plusieurs fiches sans être recopiée.

| ID | Observation et preuve disponible | Conséquence à examiner | Lots |
| --- | --- | --- | --- |
| OBS-001 | `SessionManager::deleteUserSessions()` supprime les sessions SQL si `session_handler === 'database'`, puis détruit la session courante si elle appartient au compte. Le mode PHP ne supprime pas les autres sessions. Le code et le guide Récupération concordent. | Déterminer le gestionnaire réellement utilisé et les garanties attendues après compromission/changement de mot de passe. | 05, 06 |
| OBS-002 | `WFMyAccount::save()` valide format/unicité de l'e-mail puis appelle `updateProfile`, sans confirmation d'adresse ni nouvelle authentification dans cette méthode. Le guide Mon compte documente cette limite. | Évaluer le scénario d'une session volée et les effets sur récupération, notifications et liaison d'identité ; définir le comportement voulu. | 05, 07 |
| OBS-003 | Le guide Médias décrit des URL publiques. `SecureVideoController::stream()` appelle le helper sans contrôle d'ACL de lecture dans l'action. | Confirmer le besoin de confidentialité et suivre les contrôles du helper avant de qualifier le risque. Un bloc de page masqué ne protège pas le fichier. | 04, 10 |
| OBS-004 | Le guide Chiffrement décrit une clé issue de `Configuration.secret`, à conserver ; les Travaux restants indiquent que rotation et rechiffrement restent à concevoir. | Préparer le traitement d'un secret compromis et la récupération des données, sans changer la clé pendant l'audit. | 13, 15, 16 |
| OBS-005 | Le runner vérifie automatiquement le CSRF pour les routes API non exemptées hors GET/HEAD/OPTIONS. Le code possède aussi des entrées autonomes et des routes exemptées. | Inventorier tous les effets de bord et leurs protections explicites, sans considérer chaque exemption comme une faille. | 03, 12, 15 |
| OBS-006 | `src/updater/index.php` inclut le message de l'exception précédente dans son JSON d'erreur. | Suivre les sources de ces exceptions pour déterminer si un chemin, une requête ou une donnée sensible peut sortir ; aucun secret exposé n'a été démontré. | 13, 15 |
| OBS-007 | Le guide Inscription et les Travaux restants indiquent que la validation du compte par e-mail n'est pas livrée. Le parcours complet n'a pas été relu pendant cette préparation. | Vérifier le code et les effets sur identité, abus d'inscription et éventuelle liaison SSO. | 05, 07 |
| OBS-008 | Le POST non API `/space/choose` modifie directement le Space de session, sans vérification CSRF explicite ni WF dans la méthode. | Suivre cookies, provenance, autorisation et effets avant de qualifier le risque. | 03, 04, 06 |
| OBS-009 | Le menu logout raccorde un GET à la destruction de session sans jeton dans cette méthode. | Examiner déclenchement intersite et impact réel d'une déconnexion forcée. | 03, 06 |
| OBS-010 | La présence d'en-têtes Forwarded suffit à sélectionner le contexte proxy dans `WebRequest` ; l'autorité peut venir de `X-Forwarded-Host`. | Vérifier filtrage serveur, hôtes et construction d'URL/cookies ; configuration réelle inconnue. | 03, 16, 07 |
| OBS-011 | `WebRequest` lit le corps complet avant le routage/WAF et les limites propres au webhook. | Vérifier bornes serveur, parseurs et coût d'allocation ; aucun essai de charge effectué. | 03, 08, 12, 16 |

Yann confirme que la lecture publique des médias/HLS est le comportement voulu
(OBS-003). Cette observation ne demande donc pas de protection de lecture par
ACL ; elle reste un rappel de contrat pour les tests de chemins et de gestion.

### Protections repérées, à éprouver

Le code lu contient un contrôle CSRF central, la révocation SQL des sessions,
des projections explicites des données de Mon compte et des contrôles d'URL/DNS
dans le client IA (`AiGatewayHelper::request()` : adresses publiques, HTTPS,
redirections désactivées, résolution cURL imposée et réponse bornée).
Le projet dispose aussi de politiques CSP/WAF et de tests de signatures/archives.
Ces mécanismes doivent être suivis sur tous les parcours avant de conclure à
leur efficacité ou à leur couverture.

## Écarts entre documentation et code

**DOC-001 — Automatisation (résolu dans l'arbre courant).** L'état du projet, les travaux
restants, l'architecture et le chantier Automatisation décrivent désormais les
déclencheurs `automation.webhook.received` et `automation.schedule.tick`,
`WebhookReceiver`, `Schedule/CronWorker`, les modes d'authentification et de
réponse, ainsi que la route CRON core `GET/POST /cron` et `CronDispatcher`
(jeton `cron_token` en en-tête, query ou corps), ainsi que les actions
`google_sheets::*` et leur compte de service chiffré par Space. Le code reste dans
le périmètre du lot 12 : configuration déployée, ACL, quotas, rejeu,
exemptions CSRF/WAF et reprise des événements/runs bloqués doivent être
audités. L'existence du code et des tests unitaires ne prouve pas que le
chantier est entièrement livré ou éprouvé de bout en bout.
Cette mise à jour documentaire était présente au début du lot 01. Yann confirme
que l'automatisation est activée en production ; sa version et sa configuration
exactes n'ont pas été inspectées.

**DOC-002 — Chemin Formulaires.** La carte du projet cite encore
`src/framework/Lib/Forms/` au lieu de `src/releases/vdev/framework/Lib/Forms/`.
L'audit utilise le chemin réel ; cette ligne sera à corriger dans le guide.

## Hypothèses et inconnues restantes

- Médias/HLS publics, administrateurs de Space et auteurs de scénarios de
  confiance limitée, automatisation activée : décisions confirmées au lot 01.
- Nature exacte des autres données sensibles, délégations configurées et
  exposition de toutes les extensions/routes sur la release hébergée.
- Écart entre release locale et release hébergée, gestionnaire de sessions,
  options de sécurité, architecture proxy et topologie de déploiement.
- Disponibilité d'une base de test dédiée déjà préparée et des fixtures utiles.
- Historique Git local accessible parcouru au lot 02 par recherche heuristique ;
  autres clones/références et binaires non couverts. Inventaire des versions
  directes réalisé, transitives des bundles/images et ZIP final encore incomplets ;
  voir le [rapport](Reports/LOT-02.md).

Ces inconnues n'empêchent pas l'analyse locale. Elles limitent les conclusions
sur l'exploitation réelle et ne doivent pas être comblées par des suppositions.


## Résultats de l’analyse HTTP locale

Les observations OBS-008/009 sont qualifiées par SEC-008/009 ; OBS-010 est
repris dans SEC-010, dont la protection côté hébergeur reste non attestée.
SEC-008, SEC-011 et SEC-012 sont corrigées et vérifiées localement ; les
ACL métier et l'hébergement restent à examiner dans leurs lots.
OBS-011 reste un contrôle de limites serveur et de lecture du corps à poursuivre.
L’[inventaire courant](ROUTES.csv) et le [rapport du lot 03](Reports/LOT-03.md)
remplacent les anciens repères cron pour l’état HTTP, sans changer les contrats métier.

**DOC-003 — Émission des en-têtes des FileResponse.** La branche spécialisée
`ApplicationRouteRunner::sendResponse()` n’émet pas les httpHeaders ajoutés
à l’objet par le plugin Security. Les Response normales passent par la branche
générique qui les émet. L’usage repéré est la référence Markdown téléchargée
d’Automation ; aucune exploitation démontrée. L’écart au contrat transversal
d’en-têtes doit être traité explicitement, sans prétendre que les erreurs
imprimées directement ou les fichiers statiques passent par ce même hook.
