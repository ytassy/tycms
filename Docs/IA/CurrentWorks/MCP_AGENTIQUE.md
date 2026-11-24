# Conception du serveur MCP et du CMS agentique

## Statut et reprise du chantier

Ce document fixe la conception du « CMS agentique » de TyCMS : un serveur MCP
(Model Context Protocol) qui expose au client (DSH, Claude Desktop, etc.) la
capacité d'exécuter du code écrit dans le langage de scénario, avec l'identité
d'un **compte de service**. Les choix ci-dessous ont été discutés avec Yann le
24 novembre 2026 et validés ; seuls les éléments marqués « proposition »
restent ouverts.

Le **lot 1 est livré** : les attributs d'action (`ExposeAutomationActions`,
`AutomationAction`, `ActionParam`, `ActionPermission`, `ActionTransform`), le
scanner `WorkflowActionScanner`, le handler générique `WorkflowMethodAction` et
le support du registre sont en place ; `core::getUser` est déclaré par
annotation sur `WFAdminUser` et le reste des actions Core conserve le chemin
impératif. Les tests unitaires associés passent.

Le **lot 2 est livré** : colonne `users.type`, table `api_tokens` (migration),
modèle `ApiToken`, helper `ServiceAccountTokenHelper` (génération, hachage
SHA-256, vérification en temps constant, révocation, résolution), workflow
`WFServiceAccount` réservé à ROOT, contrôleur et routes d'administration
`administrator/api/service-accounts`, et écran Vue « Comptes de service ».
La limite simple par jeton est reportée au branchement du serveur MCP (lots 3-4),
qui consommera la résolution.

Le compte de service est distingué dans l'administration : la liste des
utilisateurs filtre par `type` (`user` par défaut, `service`, ou tous) et
sérialise le type de chaque compte. Un compte de service ne peut recevoir de
mot de passe, ni par l'administration, ni par la récupération : c'est le jeton
d'API qui l'authentifie. La table croisée des attributions reste tous types
pour permettre de lui donner ses rôles. La migration `Mig_1_1_9` est
idempotente et ajoute les index `users(type, space_id)` et
`api_tokens(user_id, revoked_at)`.

Le socle
`plg_automation` (langage, interpréteur, registre d'actions, budgets, journal)
et son chantier [AUTOMATION](AUTOMATION.md) restent la référence. Le présent
document réutilise cette couche d'exécution et ne décrit que ce qui s'y ajoute.

## Objectif

TyCMS devient progressivement actionnable par des agents IA **depuis
l'extérieur**, sans exposer d'API métier ad hoc par cas d'usage. Au lieu de
coder des dizaines d'outils MCP et leur autorisation, on s'appuie sur :

1. le **langage de scénario** déjà interprété, borné et sans `eval`, pour
   composer des lectures et écritures ;
2. le **registre d'actions** et leurs ACL, pour ce que le code a le droit de
   faire ;
3. une **chaîne d'authentification** classique `jeton d'API → compte de
   service → groupes → rôles → permissions`, identique à tout appel API.

Le serveur MCP n'est qu'un transport fin : il reçoit une demande d'outil,
résout l'identité du jeton, exécute le code fourni par l'IA et renvoie la
valeur de sortie (bornée, sans objet PHP).

## Vocabulaire

| Terme | Sens ici |
| --- | --- |
| Serveur MCP | Endpoint HTTP TyCMS qui parle le protocole MCP (JSON-RPC 2.0, transport Streamable HTTP). |
| Client MCP | L'agent consommateur : DSH, Claude Desktop, etc. |
| Compte de service | Compte `users` de type `service`, sans mot de passe ni connexion interactive, porteur de groupes/rôles/permissions. |
| Jeton d'API | Secret par compte de service, haché côté serveur, présenté dans l'en-tête d'authentification du serveur MCP. |
| Exécution de code | Compilation + interprétation d'un programme du langage de scénario, **hors** artefact « scénario » (pas de ligne `automation_scenarios`). |
| Outil MCP | Opération nommée exposée au client, avec schéma d'entrée et de sortie. |

## Décisions validées

### Transport et hébergement

- **Transport Streamable HTTP** (HTTP + JSON-RPC 2.0), pas de stdio : le
  mutualisé ne permet pas de processus long ni de connexion standard.
- **Endpoint** : `POST /ai/mcp`, route technique (sans préfixe de langue),
  authentifiée par jeton, donc `withoutCsrf: true` et exemptée du WAF comme les
  routes qui reçoivent du code (même motif que `/automation/webhook/{id}`).
- Compatible avec les deux clients visés : DSH connecte un serveur MCP en
  `transport: streamable-http` sur une URL ; Claude Desktop supporte aussi ce
  transport.
- Le protocole MCP est implémenté par le framework TyCMS (route normale,
  contrôleur mince, WF d'autorisation). Aucun processus ni serveur séparé.

### Identité et autorisation

- Le serveur MCP exécute avec l'identité d'un **compte de service**, résolu
  depuis un **jeton d'API**.
- La chaîne d'autorisation est : `jeton d'API → compte de service → groupes →
  rôles → permissions`, exactement comme un appel API authentifié. Aucun
  mécanisme ACL propre au MCP ; `ActionContext` et les actions vérifient le
  `Space` réel de la ressource comme d'habitude.
- Le **type de principal** pendant l'exécution est `service` (pas `user`) :
  plus logique et cohérent avec la future intégration des comptes de service
  dans `plg_automation` (`AutomationPrincipalType::SERVICE`). L'ACL se résout
  néanmoins via les groupes/rôles du compte, qui est une ligne `users`.
- Un jeton est associé à un compte de service et à un **Space par défaut**. Un
  outil peut cibler un autre Space sous réserve des permissions du compte.

### Pas de système de scénario

- Le serveur MCP **n'utilise que la couche d'exécution du langage**, pas le
  stockage ni l'éditeur de scénarios : pas de ligne `automation_scenarios`, pas
  de déclencheur, pas de publication, pas de dry-run d'éditeur.
- Le contexte d'exécution (principal + Space) est fourni par le jeton, pas par
  un artefact scénario.

### Transaction et exécution

- **Abandon** de l'idée de transaction par action de WF et de l'annotation
  `#[NeedGlobalPermission]` étendue aux contrôleurs hors automation : trop de
  complexité pour le besoin réel.
- Le code exécuté par le MCP l'est dans un **module d'exécution indépendant**
  (Application isolée, comme le mode `author` — donc une connexion distincte).
- **Une transaction unique** encadre l'exécution : ouverte avant l'interpréteur,
  commit en cas de succès, rollback en cas d'erreur. Pas d'auto-commit par
  action sur ce parcours. On accepte qu'elle tienne la connexion pendant
  l'exécution sur mutualisé (bornée par les budgets, ≤ 45 s).
- La route `POST /ai/mcp` est `#[NoTransaction]` : la transaction utile a lieu
  dans l'application isolée, pas sur la connexion HTTP de la route.

### Actions disponibles (filtre `external`)

- Par défaut, le serveur MCP **refuse les actions d'effet `external`**
  (e-mail, notifications, Telegram/Slack/Discord, Google Sheets/Agenda). Les
  cas d'usage d'un agent sur le CMS sont la lecture et l'écriture de contenu,
  pas l'envoi d'e-mails. Les actions `external` sont **exclues du catalogue
  d'actions que le MCP expose** : l'IA ne peut pas les nommer (fonction
  inconnue à la compilation). Un seul garde-fou global, sans réglage par jeton.
- Les actions `read` et `write` restent soumises à leurs propres ACL et aux
  budgets. Les permissions du compte de service font le reste.

## Architecture cible

```text
Client MCP (DSH / Claude Desktop)
   → POST /ai/mcp  (Transport Streamable HTTP, JSON-RPC 2.0, jeton d'API)
   → Contrôleur MCP : authentification du jeton (haché), résolution du compte
   → WF : contrôle du jeton, du statut du compte, limites simples
   → Exécution de code :
        RunSession(compte de service, Space) → Application isolée
        transaction unique (begin / commit / rollback)
        source → ScenarioCompiler (lexer/parser/validation statique)
        Interpreter + ActionDispatcher (catalogue sans external) + budgets
        frontière de valeurs (scalaires, tableaux, DateValue)
   → persistance d'un enregistrement d'audit (mcp_executions)
   → réponse JSON-RPC (résultat borné ou erreur structurée)
```

Le contrôleur reste mince ; toute décision d'autorisation passe par le WF et
les actions, conformément à [l'architecture](../../ARCHITECTURE.md).

## Comptes de service et jetons d'API

### Périmètre : un mécanisme d'authentification global

Les comptes de service et les jetons d'API sont conçus comme un **mécanisme
d'authentification général** de TyCMS, pas seulement pour le serveur MCP : des
jetons d'API pourront plus tard sécuriser d'autres API externes. On n'implémente
aujourd'hui que le strict nécessaire (type de compte, table `api_tokens`,
résolution du compte) ; l'administration et les usages hors MCP viendront plus
tard. Ce choix ne doit pas enfermer la conception dans un usage MCP.

### Compte de service

- Nouveau statut de compte : `users` reçoit un type `service` (migration).
- Un compte de service ne peut pas se connecter interactivement, n'a pas de mot
  de passe ni de session navigateur, et n'est pas pris en compte par
  l'inscription ou le SSO.
- Il appartient aux mêmes mécanismes de groupes/rôles/permissions qu'un compte
  ordinaire ; ses droits sont donc administrés avec les écrans existants.
- Création et gestion réservées à l'administration (ROOT, à minima).

### Jeton d'API

- Un compte de service porte un ou plusieurs jetons nommés, révélables une fois
  à la création (comme les secrets webhook), révocables individuellement.
- Le secret est généré (`bin2hex(random_bytes(32))`) et **haché en SHA-256**
  côté serveur, en réutilisant le motif de `WebhookSecrets`. Aucun jeton en
  clair n'est stocké ni journalisé.
- Champs proposés par jeton (on reste simple) : `name`, `token_hash`,
  `space_id` par défaut, `expires_at` facultatif, `last_used_at`, `revoked_at`,
  dates.
- **Limites simples** : une seule garde-fou par jeton (par exemple un plafond
  d'appels par minute, sur le modèle des 120/min du webhook) pour écarter
  l'abus basique. Pas de système de quotas élaboré ni de comptage par
  action/taille : TyCMS reste un CMS simple.

## Couche d'exécution de code

Le moteur actuel exécute une version publiée d'un scénario. On ajoute un
parcours dédié, sans toucher `automation_runs` ni l'exécuteur de scénario, pour
limiter le risque de régression :

```php
// WFAutomationCode (nom de travail), appelée par le WF du serveur MCP.
public function runCode(
    string $source,     // programme du langage (avec function main())
    int $userId,        // compte de service
    int $spaceId,       // Space d'exécution
    array $input,       // charge utile ~ $input du langage
): array;               // { execution_id, status, output, message, diagnostics }
```

Points de conception :

- **Application isolée** : réutiliser le motif de `createRunApplication()` pour
  créer une `Application` avec une `RunSession(compte de service, Space)`, sans
  routes (`registerRoutes = false`).
- **Catalogue restreint** : construire le catalogue d'actions depuis le
  registre, en **excluant les actions `external`** (filtre validé ci-dessus).
- **Compilation** : même `ScenarioCompiler` ; une source invalide renvoie les
  diagnostics, sans exécution.
- **Contexte** : `ExecutionContext::create()` et `ActionContext` exigent un
  `scenarioId`/`versionId`/`runId`. On leur passe des valeurs sentinelles en
  mémoire (run réel, scénario/version `0`) — c'est purement interne, aucun
  changement de schéma.
- **Transaction** : `begin` avant l'interpréteur, `commit` en succès, `rollback`
  en erreur ou exception, sur la connexion de l'application isolée.
- **Audit** : persister un enregistrement dans une **table dédiée
  `mcp_executions`** (pas de réutilisation de `automation_runs`, plus simple) :
  statut, entrée/sortie bornées, diagnostic, durée, compteurs de budgets,
  hash de la source, `token_id`/`user_id`/`space_id`, dates. Proposition de
  colonnes au § « Modèle de données ».
- Le principal `loadPrincipal()` (qui force aujourd'hui `type = USER`) est
  étendu pour produire `type = service` pour un compte de service.

## Le serveur MCP

### Outils exposés (version 1)

| Outil | Rôle |
| --- | --- |
| `tycms.run` | Exécute un programme du langage (`{ source, input }`) et retourne la sortie. Outil principal. |
| `tycms.list_actions` | Catalogue des actions disponibles (sans `external`) : identifiants, paramètres typés, descriptions, permissions, effet. |
| `tycms.list_context` | Variables de contexte (`$input`, `$principal`, `$space`, `$run`) et Space/timezone courants. |
| `tycms.reference` | Documentation du langage et des actions (réutilise la référence Markdown du moteur, servie depuis les mêmes catalogues). |
| `tycms.ping` | Vérification de connexion. |

Le schéma d'entrée de `tycms.run` est simple et stable : `source` (chaîne,
bornée) et `input` (map JSON). La richesse métier vient du langage et du
catalogue, pas d'un outil par opération.

### Documentation du serveur MCP

Le serveur doit rendre le client capable d'écrire un code correct. La
documentation est **servie par le serveur lui-même** et produite depuis les
mêmes sources de vérité que l'éditeur de scénarios :

- `tycms.list_actions` retourne le catalogue machinable (issu du registre
  d'actions, donc des annotations à terme).
- `tycms.reference` retourne la référence du langage (instructions, fonctions
  `str`/`arr`/`date`/…, actions, variables de contexte), en réutilisant
  `AutomationReferenceDocument`.
- Chaque action décrit ses paramètres, sa permission et des exemples, afin que
  l'IA compose des appels valides sans aller-retour d'essai/erreur.

À terme, le client MCP devra aussi atteindre la **documentation de domaine et
d'architecture**, distincte de la référence du langage : le modèle de données
du CMS (par exemple « une page se crée avec sa définition puis s'attache à une
entrée de menu en fixant l'URL », ou « une catégorie de cours contient des
cours, qui contiennent des lesson topics, qui contiennent des leçons »), les
droits et les procédures métier. Cette connaissance est aujourd'hui rédigée
pour des humains dans `Docs/*.md` ; elle devra être servie au serveur MCP sous
une forme exploitée par une IA (ressources MCP dédiées ou référence structurée
générée depuis les guides). C'est un lot distinct, postérieur au lot 4, qui
dépend du choix de format ; il n'est pas bloquant pour la v1.

### Exécution d'un appel

1. Le client envoie `tools/call` avec `name = "tycms.run"` et les arguments.
2. Le serveur authentifie le jeton, résout le compte et le Space.
3. Le WF vérifie le statut du compte, la permission d'exécution et la limite
   simple du jeton.
4. Le code est compilé puis interprété dans la transaction unique.
5. La sortie est convertie en contenu de réponse MCP (texte ou JSON sûr).

## Actions par annotations

Objectif : réduire la charge de définition d'action (aujourd'hui
`AutomationActionDefinition` + `AutomationParameterDefinition` + un handler par
action) en annotant directement les méthodes de WF, et faire de ces métadonnées
la **source unique** du catalogue (éditeur de scénario **et** serveur MCP).

### Attributs proposés

| Attribut | Portée | Rôle |
| --- | --- | --- |
| `#[ExposeAutomationActions(namespace: 'core')]` | classe WF | Déclare que la classe expose des actions ; fixe le namespace (`core::…`). |
| `#[AutomationAction(id: 'core::getUser', label: …, description: …, returnType: …, effect: …)]` | méthode | Marque une méthode comme action ; porte l'identifiant, le libellé, le type de retour, l'effet, le timeout, la taille de sortie. |
| `#[ActionParam(name: 'userId', type: 'int', required: true, default: …, label: …, description: …)]` (répétable) | méthode | Décrit un paramètre ; remplace le `Param1/Param2…` positionnel. |
| `#[ActionPermission(Permissions::X, space: 'spaceId')]` (répétable) | méthode | Permission requise à la publication et à l'exécution ; `space` pointe le paramètre portant le Space (`targetSpace: resource`). |
| `#[ActionResult('object', schema: …)]` | méthode | Type/schéma de sortie, réutilisable comme schéma JSON d'outil MCP. |

Namespace proposé : `YTassy\Tycms\CoreExts\Automation\Annotation\`.

### Règles de liaison

- La méthode de WF doit recevoir des scalaires/tableaux (ou des types
  convertibles) et retourner des valeurs sûres ; la couche de liaison valide
  les paramètres depuis les valeurs du langage et purifie la sortie (aucun
  objet PHP).
- L'ACL d'exécution reste dans le WF (inchangé) ; l'annotation déclare en plus
  la **permission de publication** (anti-escalade), qui ne peut pas être
  déduite automatiquement.
- **Échappatoire** : une action qui ne correspond pas à une méthode de WF
  simple (agrégation, modèle d'e-mail, remise en forme) conserve le chemin
  impératif actuel (`AutomationActionDefinition` + handler). Les annotations
  sont un sucre pour le cas courant, pas une migration forcée.
- L'auto-détection se fait par réflexion sur les classes chargées, enregistrées
  via le registre d'actions existant ; les collisions et namespaces réservés
  restent refusés.

### Déplacement des schémas JSON vers les WF

Aujourd'hui les schémas JSON sont référencés par `ControllerParamN` via
`schemaFile`, dans les namespaces des contrôleurs. Conséquence logique de la
logique « le WF est la source » : **les schémas doivent, pour la plupart,
migrer vers les WF**, et les contrôleurs les importer depuis le WF pour valider
les appels HTTP. Le `#[ActionParam]`/`#[ActionResult]` réutilise alors le même
schéma pour l'action. C'est cohérent avec l'idée « une méthode métier, un
schéma, plusieurs points d'entrée ». C'est une migration progressive, **pas un
prérequis** de la v1 MCP : on migre au fil de la conversion des actions.

## Sécurité

- **Jetons** : hachés (SHA-256), comparaison `hash_equals`, révélés une seule
  fois, révocables, avec expiration facultative et `last_used_at`. Aucun jeton
  en clair ni journalisé.
- **Chaîne d'autorisation** identique aux appels API ; pas de contournement.
- **Comptes de service** : pas de connexion interactive, pas d'inscription, pas
  de SSO ; création réservée à l'administration.
- **Anti-escalade** : un compte de service ne peut exécuter que ce que ses
  permissions permettent ; chaque action revérifie l'ACL sur le Space réel.
- **Code non fiable** : le code vient d'une IA (risque de prompt injection) ; le
  langage sans SQL/shell/eval, la frontière de valeurs, les budgets et
  l'exclusion des actions `external` sont la première ligne de défense. Ne
  jamais journaliser ni le code brut, ni les contenus, ni les secrets.
- **Déni de service** : limite simple par jeton, budgets du moteur, taille de
  source et de sortie bornées.
- **Transport** : HTTPS ; le jeton travelle en en-tête, jamais dans l'URL ni le
  corps journalisé.

## Modèle de données

| Objet | Détail proposé |
| --- | --- |
| `users.type` | Colonne ajoutée par migration (`user` par défaut, `service` pour les comptes de service). |
| `api_tokens` (nouvelle) | `id`, `user_id`, `name`, `token_hash`, `space_id`, `expires_at` (nullable), `last_used_at` (nullable), `revoked_at` (nullable), `created`. |
| `mcp_executions` (nouvelle) | `id`, `token_id`, `user_id`, `space_id`, `source_hash`, `input`, `output`, `status`, `error_code`, `error_message`, `duration_ms`, `steps_count`, `created`. |

Les migrations doivent préserver la base de production existante et ses données
(`users.type` reçoit un défaut, aucune ligne existante transformée).
Convention de développement : **un seul fichier de migration par extension** ;
une évolution de schéma s'ajoute au fichier existant au lieu de multiplier les
fichiers (`Mig_1_1_9.php` est le fichier consolidé de `plg_core` pour ce chantier).

## Découpage en lots

| Lot | Contenu | Critère de sortie |
| --- | --- | --- |
| 0 | Ce document ; validation des propositions | Décisions validées |
| 1 | Attributs d'action + auto-détection (`WorkflowActionScanner`) + handler générique `WorkflowMethodAction` ; conversion de `core::getUser` en preuve de concept | Fait : catalogue produit identique, collision/ACL, exécution du handler testée |
| 2 | Comptes de service + jetons d'API (modèle, migration, hachage, révocation, résolution) | Fait : migration `Mig_1_1_9`, modèle, helper, WF ROOT, contrôleur, écran Vue |
| 3 | Façade `runCode()` + table `mcp_executions` + transaction unique + catalogue sans `external` | Test : exécution de code ad hoc auditée et bornée, sans scénario |
| 4 | Endpoint MCP Streamable HTTP (`POST /ai/mcp`) + outils `run`, `list_actions`, `list_context`, `reference`, `ping` | Test d'intégration JSON-RPC : init, tools/list, tools/call, erreurs |
| 5 | Vérification réelle avec DSH (bundle MCP `transport: streamable-http`) et durcissement | Connexion DSH → `tycms.*` fonctionnel |

## Hors périmètre (v1)

- Outils MCP granuleux auto-générés (un outil par action) : piste future à
  faible coût grâce aux annotations.
- Mode « service » du système de scénario (`principal_mode: service`) : le
  serveur MCP est un parcours distinct aujourd'hui, mais les comptes de service
  sont conçus pour être le socle commun des deux plus tard.
- Authentification OAuth MCP : le jeton d'API interne suffit au départ.
- Interface d'administration complète des comptes de service et jetons ; le
  strict nécessaire d'abord.
- Plusieurs Spaces ou permissions par jeton : un Space par défaut par jeton en
  v1.

## Pistes futures (non planifiées)

- **API MCP spécifiques au-delà du langage** : par exemple l'envoi d'une image
  binaire dans les médias, que la frontière de valeurs du langage (scalaires,
  tableaux, dates) ne couvre pas. Ces opérations binaires deviendraient des
  outils MCP dédiés, hors langage, à traiter plus tard.
- Outils MCP granuleux auto-générés depuis les annotations.
- Généralisation des jetons d'API à d'autres usages que MCP.

## Références

- [Architecture](../../ARCHITECTURE.md)
- [Automatisation](AUTOMATION.md)
- [Contrôleurs](../../CONTROLLERS.md)
- [Sécurité](../../PLUGIN_SECURITE.md)
- [Chiffrement](../../CRYPTO.md)
- [Journaux](../../LOGGING.md)