# Conception du moteur d'automatisation et du langage de scénarios

## Statut et reprise du chantier

Ce document conserve la conception validée avec Yann le 15 novembre 2026.
Le socle de l'extension Core, les lots 1-2, les cœurs des lots 3-4, le buffer
d'événements et l'interface d'édition existent :
lexer, AST, parser, validation statique, interpréteur, fonctions intégrées
pures, budgets, journal en mémoire, six tables de persistance, permissions,
registres d'actions/déclencheurs, `RunSession`, hook `onAfterRouteExecuted`,
repository de persistance, exécuteur de run synchrone, WF de run manuel et
journalisation des runs/steps. Un smoke test a créé un scénario, publié une
version, exécuté un run et relu la sortie et son étape. Les actions Core
(`sendMailTemplate` et `sendMail`, `notify`, `sendTelegram`/`sendSlack`/`sendDiscord`, lecture
utilisateurs, rôles et groupes), les notifications d'échec et de scénario
configurées par Space avec secrets chiffrés, les profils nommés et l'endpoint
`POST administrator/api/automation/run` sont branchés. Le buffer d'événements,
le drain après commit, l'émission de `core.user.created`, le déclencheur de clic
sur un bouton de page, l'API générique `emit`/`triggerNow` pour les extensions
productrices et l'interface d'administration (liste, éditeur, vérification,
publication, historique) sont en place. Les déclencheurs webhook
(bearer/query/HMAC, réponse générique ou résultat), planifié, la génération et
la rotation des secrets, le calculateur de créneaux et le worker cron sont
également branchés, ainsi que la maintenance manuelle (reprise, runs bloqués,
relance, rétention). Les tests d'intégration webhook/CRON/maintenance couvrent
les modes d'authentification, les erreurs, le mode `result`, les collisions de
créneaux et les ACL de maintenance. Restent l'exercice réel des modes
author/service, l'essai HelloAsso et l'audit de sécurité. L'extension est
active par défaut ; un `false` explicite dans `core_extensions_enabled` permet
de la désactiver.

Le code du langage se trouve sous
`src/releases/vdev/core_extensions/Automation/Language` : `Lexer`, `Parser`,
`Validator`, `ScenarioCompiler`, `Limits`, `Diagnostic`, AST sous `Ast/` et
signatures sous `Registry/`. Le runtime se trouve sous
`.../Automation/Runtime` : `Interpreter`, `ValueTools`, `Environment`,
`ExecutionContext`, `ExecutionBudget`, `ExecutionJournal`, `AutomationRunExecutor`,
`Builtin/`, `Action/`, `Clock/`, `Random/` et `Identity/`. La persistance est
dans `Helper/AutomationRepository`, l'outbox dans
`ExtensionApi/AutomationEventBuffer`, la résolution des déclencheurs dans
`Runtime/AutomationEventDispatcher`, le parcours métier et l'administration dans
`WF/WFAutomation`, les endpoints dans `Controller/AutomationController` et les
notifications dans `Notification/`. Les catalogues documentaires sont
`Language/Registry/BuiltinFunctionReference` et
`Language/Registry/ContextVariableCatalog` ; le rendu Markdown est dans
`Reference/AutomationReferenceDocument`. L'API des producteurs est
`ExtensionApi/AutomationTrigger` ; le bouton de page est réparti entre
`Core/WF/WFPageButton`, `PageButtonApiController`,
`Helper/Page/PageButtonHelper`, `PageButtonToken`, `PageComponents/Button`,
`ButtonForm.vue` et le composant public `AutomationButton.vue`.
L'écran d'administration est dans
`src/ria/src/components/administrator/automation/`, avec `ScenarioList`,
`ScenarioEditor`, `RunHistory` et `Diagnostics`. Les déclencheurs Core sont dans
`Core/Automation/`, à côté des actions. Les tests sont dans
`src/tests/Unit/Plugins/Core/Automation/` et
`src/ria/tests/automationFormat.test.js`.

Le nom de travail est « automatisation » ; l'artefact édité est un
« scénario ». Le mot « workflow » reste réservé à la couche WF métier décrite
dans [Architecture](../../ARCHITECTURE.md#wf--workflow-métier-et-sécurité).
Les identifiants techniques prévus sont `plg_automation`,
`core_extensions/Automation` et le namespace public
`YTassy\Tycms\CoreExts\Core\ExtensionApi\Automation`.

Les éléments marqués « proposition » demandent validation avant implémentation.
Ne pas présenter cette note comme un contrat livré. Les contrats validés
devront rejoindre les guides techniques au moment de leur livraison.

Consignes de reprise : lire `AGENTS.md`, l'index des guides, cette note, puis
le code visé. Poser les questions de comportement ou d'architecture à Yann.
Préserver la base de production, y compris celle de `tests.yanntassy.fr`.

## Décisions validées

### Périmètre

- Système maison, sans reproduire N8N, sans éditeur graphique en v1.
- Langage de scénarios textuel, interprété sur un AST. Aucune génération de
  PHP, aucun `eval`.
- Parser écrit à la main : lexer + descente récursive avec précédence.
  Pas de `phplrt`, pas de Parle, pas de lecteur de grammaire à l'exécution.
- Extension Core `plg_automation`, pas un plugin installable : les ZIP
  d'extension ne transportent pas de dépendances Composer et la résolution de
  dépendances n'est pas implémentée ; d'autres extensions pourront dépendre de
  l'automatisation.
- Le socle est actif par défaut, comme Tools et Matrix. Un `false` explicite
  dans `core_extensions_enabled['plg_automation']` le désactive. L'activation
  crée les tables et expose les routes ; elle doit donc être accompagnée des
  vérifications de mise en production.
- Un déclencheur par scénario en v1 ; le point d'entrée est toujours
  `function main()`.
- Un scénario appartient à un Space ; les droits d'édition et de publication
  sont distincts des droits d'exécution.
- Les actions sont des cas d'usage métier qui réutilisent les WF et helpers
  existants ; elles ne font pas de SQL brut et n'accèdent pas directement aux
  tables.

### Runtime (lot 2)

- Portée par bloc : une nouvelle variable reste dans le bloc courant ; une
  affectation met à jour la variable existante la plus proche.
- Un helper ne voit pas les variables locales de `main` : il reçoit ses
  paramètres et lit uniquement le contexte en lecture seule.
- Conditions strictement booléennes ; arithmétique sur nombres uniquement ;
  concaténation avec `.` sur scalaires uniquement.
- Frontière de valeurs : scalaires, tableaux et `DateValue` ; tout objet PHP,
  ressource ou callable retourné par une fonction ou une action est refusé.
- Budgets : étapes, itérations par boucle et cumulées, profondeur d'appel,
  appels d'action, durée, taille des chaînes et des tableaux.
- Journal en mémoire borné : fonctions, built-ins, actions, logs, arrêts et
  erreurs, avec numéro de séquence et intervalle source.
- Horloge, aléatoire et générateur d'identifiants injectés ; les tests utilisent
  des implémentations fixes pour un résultat déterministe.
- Les actions passent par `ActionDispatcher` ; le runtime ne connaît ni base de
  données, ni WF, ni secret. Le branchement réel appartient au lot 4.

### Pourquoi pas de lecteur de grammaire

L'EBNF de ce document est la **spécification**, pas une entrée du moteur. Le
parser l'implémente en dur. Ce choix est volontaire :

- la grammaire est un contrat produit, pas une donnée utilisateur ;
- un lecteur de grammaire serait un second langage à maintenir, avec ses
  erreurs, ses performances et sa surface d'attaque ;
- un parser écrit à la main produit de meilleurs messages, une reprise sur
  erreur au niveau des instructions et des positions précises ;
- il n'y a aucun besoin d'ajouter de la syntaxe par extension : les extensions
  ajoutent des fonctions, jamais de la grammaire ;
- il n'y a aucun risque de compilation de grammaire à chaud, donc aucun chemin
  de génération de code contrôlable par un tiers ;
- le parser ne tourne qu'à l'enregistrement ou à la publication, pas à chaque
  exécution : le runtime charge l'AST déjà validé.

La conformité est garantie par des tests : jetons, AST, précédence, évaluation,
cas d'erreur et fuzzing du parser en développement.

### Pourquoi un langage plutôt qu'un graphe

Un graphe de nœuds demande un canevas, une disposition, des connexions, un
éditeur de liens et une sérialisation. Un langage textuel donne immédiatement
la versionnabilité, le diff, la copie, la génération assistée et les tests.
L'IR JSON reste la source de vérité stockée ; un éditeur graphique pourra
produire le même IR plus tard sans changer le moteur.

## Le langage

### Programme et point d'entrée

Un programme est une suite de déclarations de fonctions, sans instruction au
niveau racine. Il contient exactement une fonction `main`, sans paramètre.

- `main` est un nom réservé, déclaré au niveau racine, jamais appelable depuis
  le code du scénario.
- Les fonctions sont hoistées : `main` peut appeler un helper déclaré après
  elle.
- `return` dans `main` termine le run avec succès et fournit éventuellement une
  valeur de sortie, bornée et enregistrée dans le journal.
- `return` dans un helper revient à l'appelant.
- `stop` termine le run avec succès depuis n'importe où ; il sert à un arrêt
  anticipé depuis un helper.
- `fail("message")` termine le run en échec depuis n'importe où.

Il n'y a aucune variable globale de scénario. Les helpers reçoivent leurs
données en paramètres ; ils ne lisent pas les variables locales de `main`.

### Lexique

- Identifiants : `[A-Za-z_][A-Za-z0-9_]*`, sensibles à la casse, ASCII en v1.
- Variables : `$` suivi d'un identifiant.
- Fonctions qualifiées : `namespace::nom`, par exemple `str::upper`,
  `core::sendMailTemplate`.
- Fonctions utilisateur : identifiant nu, par exemple `displayName`.
- Commentaires : `//` jusqu'en fin de ligne, `/* ... */` non imbriqués.
- Nombres : décimaux, flottants avec `.`, exposant `e`/`E`. Pas d'hexadécimal,
  pas de séparateur `_`.
- Chaînes : simple ou double quote, sauts de ligne autorisés, pas
  d'interpolation. Échappements : `\\`, `\"`, `\'`, `\n`, `\r`, `\t`, `\$`,
  `\u{XXXX}`.
- Mots-clés : `if elseif else foreach for while as return break continue
  function true false null and or not in stop fail`.
- Mots réservés pour l'avenir : `let set const try catch throw switch case
  default class new import use namespace public private static global echo
  print exit die include require eval`.

### Types et valeurs

| Type | Littéral | Notes |
| --- | --- | --- |
| `null` | `null` | Absence de valeur, distinct d'une variable non définie |
| `bool` | `true`, `false` | Seul type accepté par `if`/`while`/`for` |
| `int` | `42` | 64 bits, pas de big integer |
| `float` | `3.14`, `1e3` | Pas de tolérance d'égalité ; éviter pour les montants |
| `string` | `"..."`, `'...'` | UTF-8, longueur en caractères via `str::length` |
| `array` | `[1, 2]`, `["k" => 1]` | Liste ou map, ordre d'insertion conservé, valeur |
| `date` | via `date::parse`, `date::now` | Fuseau horaire porté par la valeur |

Règles :

- `array` est une valeur : l'affectation et le passage en argument copient
  (copy-on-write). `arr::push` retourne un nouveau tableau. Pas de mutation par
  référence en v1.
- Un littéral est une liste s'il n'a aucun `=>`, une map si tous ses éléments
  ont un `=>`. Mélanger les deux est une erreur statique. `[]` est une liste
  vide.
- `==` compare des valeurs : même type, ou `int`/`float` entre eux ; tableaux
  en profondeur ; dates par instant. Aucune coercition chaîne/nombre.
- Les comparaisons `< <= > >=` n'acceptent que nombre/nombre, chaîne/chaîne
  (ordre binaire), date/date. Elles ne s'enchaînent pas : `a < b < c` est une
  erreur de syntaxe.
- `.` accepte `string|int|float|bool` et convertit selon des règles
  documentées (`true` → `"true"`, flottants avec point, indépendant de la
  locale). `null`, `array` et `date` provoquent une erreur : utiliser `?? ""`
  ou `date::format`.
- `in` teste une valeur dans un tableau avec `==`. Pour tester une clé,
  utiliser `arr::has`.
- `??` ne se déclenche que sur `null`, pas sur une clé absente. Une clé
  absente avec `->` est une erreur ; avec `?->` elle vaut `null`.
- Aucun objet, aucune méthode, aucune classe, aucune ressource, aucune
  fonction comme valeur.

### Portées et contexte

- Portée par bloc : chaque `{}` crée une portée enfant. Une affectation met à
  jour la variable existante la plus proche ; sinon elle définit la variable
  dans la portée courante.
- La variable de boucle est limitée au corps de la boucle.
- Lire une variable non définie est une erreur d'exécution.
- Variables de contexte, en lecture seule, disponibles partout, y compris dans
  les fonctions :
  - `$input` : charge utile du déclencheur, non fiable, toujours une map ;
  - `$principal` : identité d'exécution fiable (`id`, `type`, `firstName`,
    `lastName`, `email`) ;
  - `$space` : `id`, `name`, `timezone` ;
  - `$run` : `id`, `scenarioId`, `versionId`, `startedAt`, `trigger`.
- Affecter à une variable de contexte est une erreur statique. Un paramètre de
  fonction ne peut pas porter ces noms.

La documentation de ces variables est déclarée côté serveur dans
`ContextVariableCatalog` : nom, type, obligation, description et exemple de
chaque champ. `$input` est décrit par le déclencheur sélectionné avec
`payloadFields` et `payloadExample`. L'interface et la référence téléchargeable
consomment ces catalogues ; le schéma de `$input` reste documentaire en v1 et
ne valide pas les événements au runtime.

### Expressions et précédence

Du plus fort au plus faible :

| Niveau | Opérateurs | Associativité |
| --- | --- | --- |
| 1 | `f(...)`, `ns::f(...)`, `$a[...]`, `$a->k`, `$a?->k` | postfixe |
| 2 | `-x`, `+x` | préfixe |
| 3 | `**` | droite |
| 4 | `* / %` | gauche |
| 5 | `+ -` | gauche |
| 6 | `.` (concaténation) | gauche |
| 7 | `< <= > >= in` | non associative |
| 8 | `== !=` | non associative |
| 9 | `not` | préfixe |
| 10 | `and` | gauche, court-circuit |
| 11 | `or` | gauche, court-circuit |
| 12 | `??` | gauche, court-circuit |
| 13 | `cond ? a : b` | droite |

Conséquences utiles : `"total : " . $a + $b` se lit
`"total : " . ($a + $b)`, et `not $a == $b` se lit `not ($a == $b)`.

### Instructions

- `if (bool) { ... } elseif (bool) { ... } else { ... }`
- `foreach ($array as $value) { ... }` et
  `foreach ($map as $key => $value) { ... }`
- `for ($i = 0; $i < 10; $i = $i + 1) { ... }`
- `while (bool) { ... }` — borné par le budget d'exécution
- `break;`, `continue;` — boucle la plus proche
- `return expr;`, `return;` — uniquement dans une fonction
- `stop;`, `stop("message");` — arrêt réussi anticipé
- `fail("message");` — arrêt en échec
- `$x = expression;`
- `expression;` — appel d'action ou de fonction

`exit` n'est pas conservé : il ne dit pas si l'arrêt est un succès ou une
erreur. `stop` et `fail` sont explicites.

### Fonctions utilisateur

Modèle volontairement simple :

- fonctions nommées, déclarées au niveau racine, hoistées ;
- paramètres par valeur, variables locales, `return` d'une valeur ;
- appel d'autres fonctions, récursion autorisée et bornée ;
- lecture des variables de contexte ;
- pas de closure, pas de fonction anonyme, pas de fonction comme valeur, pas de
  fonction imbriquée ;
- pas de paramètre par défaut, pas de variadique, pas d'argument nommé en v1 ;
- pas de capture des variables de `main` : un helper reçoit ce dont il a besoin
  en paramètres ;
- doublon de nom refusé ; 64 fonctions et 16 paramètres maximum ;
- `main` est réservé et non appelable depuis le code.

Exemple valide :

```text
function fullName($user) {
    return $user->firstName . " " . $user->lastName;
}

function invite($user, $sender) {
    core::sendMailTemplate("meeting-invite", $user->email, [
        "firstName"  => $user->firstName,
        "senderName" => $sender->firstName
    ]);
}

function main() {
    $teamInfo = workteam::getTeamInfos($input->teamId);
    $label = fullName($input->user);
    return ["label" => $label];
}
```

Exemple invalide : un helper qui lit une variable de `main`.

```text
function label() {
    return $teamInfo->name; // erreur : $teamInfo est local à main
}
```

### EBNF

```ebnf
program        = { functionDecl } EOF ;
functionDecl   = "function" IDENT "(" [ paramList ] ")" block ;
paramList      = variable { "," variable } ;

statement      = block
               | ifStmt
               | foreachStmt
               | forStmt
               | whileStmt
               | returnStmt
               | breakStmt
               | continueStmt
               | stopStmt
               | failStmt
               | assignmentStmt
               | exprStmt ;

block          = "{" { statement } "}" ;

ifStmt         = "if" "(" expression ")" statement
                 { "elseif" "(" expression ")" statement }
                 [ "else" statement ] ;

foreachStmt    = "foreach" "(" expression "as" foreachTarget ")" statement ;
foreachTarget  = variable [ "=>" variable ] ;

forStmt        = "for" "(" [ simpleAssign ] ";" [ expression ] ";" [ simpleAssign ] ")" statement ;
whileStmt      = "while" "(" expression ")" statement ;

returnStmt     = "return" [ expression ] ";" ;
breakStmt      = "break" ";" ;
continueStmt   = "continue" ";" ;
stopStmt       = "stop" [ "(" expression ")" ] ";" ;
failStmt       = "fail" "(" expression ")" ";" ;

assignmentStmt = simpleAssign ";" ;
simpleAssign   = variable "=" expression ;
exprStmt       = expression ";" ;

expression     = ternary ;
ternary        = coalesce [ "?" expression ":" expression ] ;
coalesce       = orExpr { "??" orExpr } ;
orExpr         = andExpr { "or" andExpr } ;
andExpr        = notExpr { "and" notExpr } ;
notExpr        = "not" notExpr | equality ;
equality       = comparison [ ( "==" | "!=" ) comparison ] ;
comparison     = concat [ ( "<" | "<=" | ">" | ">=" | "in" ) concat ] ;
concat         = additive { "." additive } ;
additive       = multiplicative { ( "+" | "-" ) multiplicative } ;
multiplicative = unary { ( "*" | "/" | "%" ) unary } ;
unary          = ( "-" | "+" ) unary | power ;
power          = postfix [ "**" unary ] ;

postfix        = primary { indexSuffix | accessSuffix } ;
indexSuffix    = "[" expression "]" ;
accessSuffix   = ( "->" | "?->" ) IDENT ;

primary        = literal
               | variable
               | userCall
               | qualifiedCall
               | "(" expression ")"
               | arrayLiteral ;

userCall       = IDENT "(" [ argumentList ] ")" ;
qualifiedCall  = IDENT "::" IDENT "(" [ argumentList ] ")" ;
argumentList   = expression { "," expression } [ "," ] ;

arrayLiteral   = "[" [ arrayItem { "," arrayItem } [ "," ] ] "]" ;
arrayItem      = expression [ "=>" expression ] ;

variable       = "$" IDENT ;
literal        = NUMBER | STRING | "true" | "false" | "null" ;

IDENT          = ( letter | "_" ) { letter | digit | "_" } ;
NUMBER         = digit { digit } [ "." digit { digit } ]
                 [ ( "e" | "E" ) [ "+" | "-" ] digit { digit } ] ;
STRING         = '"' { char | escape } '"'
               | "'" { char | escape } "'" ;
```

### Validation statique

À l'enregistrement, en plus du parsing :

- `UNKNOWN_FUNCTION` : namespace ou fonction inconnue, avec suggestion ;
- `BAD_ARITY` : nombre d'arguments incompatible ;
- `DUPLICATE_FUNCTION` : deux fonctions utilisateur du même nom ;
- `MISSING_MAIN`, `DUPLICATE_MAIN`, `MAIN_WITH_PARAMS`, `MAIN_CALLED_BY_CODE`,
  `MAIN_RESERVED` ;
- `BREAK_OUTSIDE_LOOP`, `CONTINUE_OUTSIDE_LOOP` ;
- `READONLY_VARIABLE` : affectation à `$input`, `$principal`, `$space`,
  `$run` ;
- `MIXED_ARRAY_LITERAL` : mélange liste/map ;
- `LIMIT_EXCEEDED` : source, tokens, profondeur ou taille dépassés.

À l'exécution, chaque erreur porte le run, l'étape, la fonction, la ligne et la
colonne, avec l'extrait de code. Aucune erreur silencieuse.

## Bibliothèque de base

Toutes les fonctions intégrées sont qualifiées. Les signatures utilisent `?`
pour un paramètre optionnel.

### `str`

| Fonction | Retour |
| --- | --- |
| `str::length(string)` | `int` (caractères) |
| `str::byteLength(string)` | `int` |
| `str::lower(string)`, `str::upper(string)` | `string` |
| `str::trim(string, ?string $chars)`, `str::trimLeft`, `str::trimRight` | `string` |
| `str::startsWith(string, string)`, `str::endsWith`, `str::contains` | `bool` |
| `str::indexOf(string, string)` | `int` (-1 si absent) |
| `str::compare(string, string)` | `int` (-1, 0, 1, ordre binaire) |
| `str::replace(string $subject, string $search, string $replace)` | `string` |
| `str::split(string $subject, string $separator)` | `array<string>` |
| `str::join(array $items, string $separator)` | `string` |
| `str::substr(string, int $start, ?int $length)` | `string` |
| `str::repeat(string, int)` | `string` |
| `str::padLeft(string, int, ?string $pad)`, `str::padRight` | `string` |
| `str::truncate(string, int, ?string $suffix)` | `string` |
| `str::capitalize(string)` | `string` |
| `str::slug(string)` | `string` |
| `str::isBlank(string)` | `bool` |
| `str::escapeHtml(string)`, `str::escapeAttr(string)` | `string` |

### `arr`

| Fonction | Retour |
| --- | --- |
| `arr::length(array)`, `arr::isEmpty(array)` | `int`, `bool` |
| `arr::keys(array)`, `arr::values(array)` | `array` |
| `arr::has(array, string\|int $key)` | `bool` |
| `arr::get(array, string\|int $key, mixed $default = null)` | `mixed` |
| `arr::set(array, string\|int $key, mixed $value)` | `array` |
| `arr::remove(array, string\|int $key)` | `array` |
| `arr::push(array, mixed)`, `arr::prepend(array, mixed)` | `array` |
| `arr::concat(array, array)` | `array` (listes, réindexées) |
| `arr::merge(array, array)` | `array` (maps, le second gagne) |
| `arr::slice(array, int $offset, ?int $length)` | `array` |
| `arr::first(array)`, `arr::last(array)` | `mixed` (null si vide) |
| `arr::contains(array, mixed)`, `arr::indexOf(array, mixed)` | `bool`, `int` |
| `arr::unique(array)`, `arr::reverse(array)` | `array` |
| `arr::column(array $list, string\|int $key)` | `array` |
| `arr::sortBy(array $list, string\|int $key, ?string $direction = "asc")` | `array` |
| `arr::fromPairs(array)`, `arr::toPairs(array)` | `array` |
| `arr::range(int $start, int $end, ?int $step = 1)` | `array` |

Pas de `map`/`filter`/`reduce` en v1 : sans fonction comme valeur, ils seraient
ambigus. `foreach` couvre le besoin.

`arr::sortBy` est stable. Les clés absentes sont traitées comme `null` :
nulls en premier en ordre croissant, en dernier en ordre décroissant.

### `math`

`math::abs`, `math::min(...)`, `math::max(...)`, `math::round(n, ?precision)`,
`math::floor`, `math::ceil`, `math::clamp(n, min, max)`, `math::sum(array)`,
`math::avg(array)`, `math::pow`, `math::sqrt`, `math::sign`,
`math::randomInt(min, max)`, `math::randomFloat()`.

### `date`

`date::now()`, `date::today()`, `date::parse(string, ?timezone)`,
`date::format(date, pattern, ?timezone)`, `date::toIso(date)`,
`date::toTimestamp(date)`, `date::fromTimestamp(int, ?timezone)`,
`date::addSeconds`, `date::addMinutes`, `date::addHours`, `date::addDays`,
`date::addWeeks`, `date::addMonths`, `date::addYears`, `date::startOfDay`,
`date::endOfDay`, `date::startOfWeek`, `date::startOfMonth`,
`date::endOfMonth`, `date::startOfYear`, `date::endOfYear`,
`date::diffInSeconds(a, b)`, `date::diffInDays(a, b)`, `date::isBefore`,
`date::isAfter`, `date::isSameDay`, `date::year`, `date::month`, `date::day`,
`date::hour`, `date::minute`, `date::second`, `date::weekday`,
`date::setTimezone`, `date::timezone`.

Fuseau par défaut : celui du Space. Les patrons de `date::format` sont un
sous-ensemble documenté de `date()` PHP.

### `json`

`json::encode(mixed, ?bool $pretty = false)`,
`json::decode(string, ?int $maxDepth = 32)`, `json::isValid(string)`.

`json::decode` ne produit que des valeurs sûres (scalaires, tableaux), jamais
des objets.

### `type`

`type::of(mixed)`, `type::isNull`, `type::isBool`, `type::isInt`,
`type::isFloat`, `type::isNumber`, `type::isString`, `type::isArray`,
`type::isDate`, `type::toString`, `type::toInt`, `type::toFloat`,
`type::toBool`.

Les conversions sont strictes : pas de coercition silencieuse, une valeur
incompatible provoque une erreur.

### `util`

`util::log(string $message, ?array $context = null)` — écrit dans le journal,
avec masquage des secrets.
`util::uuid()` — enregistré pour le rejeu.

### `res`

`res::confirm(string)`, `res::info(string)`, `res::warning(string)`,
`res::danger(string)` et `res::error(string)` retournent une map au format
d'`ApiResponse` : `error`, `is_message`, `message_level` et `message`.

Le bouton de page en mode « Résultat du scénario » utilise cette map pour
choisir la modale et son niveau. Une chaîne retournée directement est traitée
comme une confirmation. Hors de ce mode, la map reste dans l'historique du run
et n'est pas affichée au visiteur.

```javascript
function main() {
    // ...
    return res::warning("Le rendez-vous est complet. Choisissez un autre créneau.");
}
```

### Actions Core

Actions impures, à titre d'exemple :

`core::sendMailTemplate(template, to, ?vars)`, `core::sendMail(to, subject, htmlBody)`,
`core::notify(message, ?title, ?channels)`,
`core::sendTelegram(message, ?title, ?target)`, `core::sendSlack(...)`,
`core::sendDiscord(...)`, `core::getUser(userId)`,
`core::listUsers(?page, ?perPage, ?search, ?type)`, `core::addRole(userId, roleId, spaceId)`,
`core::removeRole(userId, roleId, spaceId)`, `core::addToGroup(userId, groupId)`,
`core::removeFromGroup(userId, groupId)`, `core::sessionRead(key, ?path, ?default)`,
`core::sessionWrite(key, value)`, `core::sessionRemove(key)`.

**Priorité du registre sur les signatures de secours.** `BuiltinFunctionCatalog`
porte des signatures de secours pour les actions Core, utilisées uniquement
lorsqu'aucun registre n'est disponible (validation statique sans Application).
Dès qu'un registre existe, `AutomationFunctionCatalog::find()` renvoie la
définition du registre : son arité, ses paramètres et son effet font foi. Sans
cette priorité, une signature de secours obsolète masquerait une évolution de
l'action et produirait un `BAD_ARITY` fantôme — cas réellement observé sur
`core::listUsers` après l'ajout du paramètre `type`. Ces signatures de secours
doivent donc être maintenues alignées, et un test de non-régression vérifie
qu'une définition à quatre paramètres produit bien `maxArity = 4`.

Chacune déclare ses permissions, vérifie l'ACL sur le Space réel du principal
et nettoie ses entrées. `core::sendMailTemplate` prend un modèle et des
variables ; `core::sendMail` purifie son corps HTML avec le profil e-mail avant
l'envoi et l'insère dans l'habillage du Space. Le HTML fourni à `core::sendMail`
est toujours purifié ; aucun envoi ne transmet du HTML brut.

Les actions de session portent un état court entre deux requêtes d'un même
visiteur, par exemple les étapes d'une inscription ou d'un assistant
multi-pages. Comme elles modifient un contexte partagé, elles exigent
`PUBLISH`, la même permission que les actions à effet externe.

**Nommage des clés.** Le scénario fournit une clé de travail courte : au plus
48 caractères, minuscules non accentuées, chiffres, tirets ou soulignés, sans
point. `BrowserSessionPropertyHelper` en compose une clé de session
`automation_{spaceId}_{clé}`. L'espace de noms du Space précède donc toujours
le préfixe `automation_` : un scénario ne peut ni écraser un identifiant
interne (`user_id`, `csrf_token`, `current_space_id`) ni lire les données d'un
autre Space partageant le domaine. Aucune action ne permet de cibler
directement une clé interne.

**Valeurs.** Les types du langage sont conservés (`null`, booléen, entier,
flottant, chaîne, map), une date étant rangée dans le format ISO de
`date::toIso` pour que `date::parse` la relise. Un tableau doit être une map à
clés non vides et non une liste : la valeur rangée reste une structure
clé/valeur, jamais un objet sérialisé. Une valeur est bornée à 16 384 octets
sérialisés ; le dépassement produit `LIMIT_EXCEEDED`. La session ne contient
donc ni objet PHP, ni valeur non bornée, ce qui protège le stockage partagé
par l'authentification et évite toute dé-sérialisation dangereuse.

**Lecture, écriture et effacement.** `sessionRead(key, ?default, ?path)` est
`read` : elle reste permise en simulation et retombe sur `default` si la clé ou
le chemin (`contact.email`) est absent. La valeur par défaut précède le chemin,
le langage n'ayant pas encore d'arguments nommés : elle reste ainsi atteignable
en appel positionnel. `sessionWrite(key, value)` est `write` et retourne
`true` ; `sessionRemove(key)` retourne `true` si la clé existait, ce qui rend les
deux actions rejouables sans condition.

**Session visée.** La lecture porte sur la session de l'Application
d'exécution : un scénario en mode auteur relit ainsi un état rangé par un
scénario du même Space. L'écriture et l'effacement sont en revanche réservés à
la session rangée en base (`DatabaseSession`), celle d'un visiteur du site.
Une `RunSession` (mode `author`, webhook, planification) est une session
mémoire propre au run ; y écrire persisterait des données de session dans la
trace du run. La modification est alors refusée avec `ACTION_UNAVAILABLE`, sans
aucun effet. Côté Base, une écriture faite après l'envoi de la réponse (clic
sur un bouton de page hors mode `result`) est déjà refusée par la session
elle-même. Dans les deux cas, une écriture n'est pas relue par `sessionRead`
pendant la même requête : la valeur est visible à la requête suivante du
visiteur.

Les canaux de notification d'échec et de scénario sont configurés séparément
dans le Space. `core::notify` utilise les canaux de scénario activés, ou la
liste `channels` ; `core::sendTelegram`, `core::sendSlack` et
`core::sendDiscord` utilisent le canal par défaut de leur provider ou un profil
nommé `target`. Les profils sont stockés dans `scenario_targets`, un JSON
chiffré au repos : le scénario ne contient que la clé du profil, jamais le jeton
ou le webhook. Les actions renvoient `true` si tous les canaux demandés ont
réussi, `false` sinon ; un échec est journalisé et ne fait pas échouer le run à
lui seul. La permission `PUBLISH` est requise ; pour un déclencheur dont
l'utilisateur n'a pas cette permission, le scénario doit s'exécuter en mode
`author`.

## Registre d'actions et déclencheurs

### Principe

Les extensions ne modifient pas la grammaire. Elles enregistrent :

- des **déclencheurs** via un fournisseur dédié ;
- des **actions** via un registre dédié.

Le modèle suit `IPageComponentProvider` et `PageComponentRegistry` : registre
explicite, identifiants qualifiés, propriétaire réel, refus des collisions,
chargement paresseux.

### Déclencheurs

Identifiants `vendor.entity.action`, par exemple `core.user.created`,
`blog.article.published`, `core.page.button.clicked`.

En v1, `AutomationTriggerDefinition` porte l'identifiant stable, le libellé, la
description, le mode de principal par défaut, les champs documentés de la
charge utile (`payloadFields`) et un exemple (`payloadExample`). Le propriétaire
réel est tenu par le registre. Les filtres avant démarrage et une clé de
déduplication configurable restent à définir : le moteur déduplique déjà chaque
couple scénario/événement par `event_id`. `core.user.created` déclare les champs
`user.id`, `user.spaceId`, `user.firstName`, `user.lastName` et `user.email`.

Le buffer d'événements porte un identifiant UUID, la charge utile validée, le
principal, la corrélation, la causalité et la profondeur. Les extensions
émettent via `AutomationEventBuffer::emit()` ; le mécanisme générique
`callExtensionEvent` reste réservé aux hooks sans payload typé.

`core.user.created` est émis dans le Space du compte, avec l'identité de
l'utilisateur qui crée le compte. Une inscription publique n'a pas de principal
utilisateur : le mode `trigger` est ignoré, tandis qu'un scénario en mode
`author` s'exécute avec son auteur publié. La charge utile contient l'identité
minimale du compte ; les rôles et groupes se lisent ensuite avec
`core::getUser`.

Le déclencheur « clic sur un bouton de page » utilise une requête `POST` d'API
avec CSRF, limitation de débit et charge utile non fiable. L'identité vient de
la session et du principal d'exécution, jamais du payload. Le composant Vue
public appelle l'API puis affiche la modale ; le détail est dans la section
« Bouton de page ».

En v1, un déclencheur par scénario. Les métadonnées réservent un champ `entry`
dont la valeur par défaut est `"main"`, pour permettre plus tard plusieurs
déclencheurs associés à des fonctions nommées.

### Webhook et planification

Deux déclencheurs sont fournis par `plg_automation` lui-même. Ils imposent le
mode `author` : aucun utilisateur n'est connecté et le mode `service` n'existe
pas encore. Le publieur doit donc posséder `RUN_AS_AUTHOR`. La configuration
reste dans `automation_triggers.config` ; les jetons bearer/query sont hachés
en SHA-256 dans `secret_hash` et le secret HMAC est chiffré dans `config`.

**`automation.webhook.received`** expose
`/automation/webhook/{scenarioId}`. Le scénario doit être actif, publié et son
déclencheur activé. Trois modes sont disponibles :

| Mode | Méthodes | Authentification |
| --- | --- | --- |
| `bearer` | POST JSON ou formulaire | `Authorization: Bearer <jeton>` |
| `query` | GET ou POST JSON, formulaire ou query | `tycms_token` en query ou dans le corps |
| `hmac` | POST | `X-Tycms-Timestamp` et `X-Tycms-Signature: sha256=…` |

`tycms_token` est un nom réservé, toujours retiré de `$input`. En mode `query`,
un jeton présent à la fois dans l'URL et dans le corps est refusé, et un POST
sans corps peut prendre sa charge utile dans la query. Le corps ou
la query doit produire une map, dans la limite de 256 Kio. HMAC signe
`timestamp . "." . corps brut` avec une fenêtre de cinq minutes.

Les réponses sont `401` (authentification), `404` (scénario ou trigger
introuvable), `405` (méthode incompatible avec le mode), `409` (scénario non
publié, trigger désactivé ou run impossible), `413` (charge trop volumineuse),
`422` (charge invalide), `429` (quota de 120 appels par minute et par scénario)
et `503` (émission momentanément impossible). Aucun jeton, secret ni corps
n'est journalisé.

`response_mode` choisit le corps de la réponse :

- `accepted` (défaut) : `{"accepted":true}`. L'événement est accepté et le run
  reste dans l'historique ; la sortie n'est pas renvoyée.
- `result` : le run est exécuté dans la requête et la réponse contient
  `accepted`, `run_id`, `status`, `output` et `message`. Le statut HTTP reste
  `200` même si le run échoue : l'appel a bien été traité et un nouveau retry
  créerait un second run. Un run impossible à démarrer répond `409`.

```json
{
    "accepted": true,
    "run_id": 49,
    "status": "success",
    "output": {"message_level": "WARNING", "message": "coucou"},
    "message": null
}
```

Le mode `result` expose la sortie du scénario à l'appelant authentifié et
attend la fin du run ; il est réservé aux intégrations de confiance. Dans les
deux modes, le drain d'événements s'exécute avant le retour de la réponse : un
webhook peut donc attendre la fin du run synchrone. Le déport de ce drain vers
le worker, avec reprise des événements restés `pending` ou `processing`,
appartient au lot 7.

`POST administrator/api/automation/webhook-secret` génère ou renouvelle le
secret et exige `PUBLISH`. La réponse n'est affichée qu'une fois dans l'éditeur :
jeton bearer, URL complète signée en mode query, ou secret HMAC. `getScenario`
ne renvoie ensuite que `secret_configured`.

`getScenario` expose `trigger_config` (mode d'authentification et mode de
réponse), `trigger_enabled`, `auth_mode`, `webhook_url`, `secret_configured`,
`space_timezone` et `schedule_next_run`. Le secret lui-même n'est jamais relu.

**`automation.schedule.tick`** est exécuté par le worker. Les presets sont
« toutes les 15 minutes », « toutes les heures », « quotidien à HH:MM » et
« hebdomadaire le jour choisi à HH:MM ». Les jours suivent la norme ISO, de 1
(lundi) à 7 (dimanche). Le fuseau est celui du Space. La prochaine exécution est
calculée par `ScheduleCalculator`, partagé entre l'éditeur et le worker ; un
créneau manqué n'est pas rattrapé. Une heure locale inexistante lors du
changement d'heure est ignorée ; une heure ambiguë est résolue par PHP.

`plg_automation` implémente `ICronProvider` (avec `TraitCronProvider`) et
expose `onCronMinute(CronTick $tick)`, appelé par la route core `GET/POST /cron`
(voir [CRON](../../CRON.md)). Le jeton `cron_token` et la
sécurité de la route appartiennent à Core. Le worker sélectionne les triggers
planifiés actifs dont le scénario est publié, exécute chaque scénario dû dans sa
propre transaction avec `triggerNow` et la clé
`schedule:{scenarioId}:{slot}`, puis retourne un bilan. La clé unique des runs
empêche une seconde exécution du même créneau.

Le tick est borné à **45 secondes** et **100 scénarios démarrés** pour rester
sous la limite PHP de l'hébergement mutualisé. Les créneaux dus qui ne sont pas
démarrés sont comptés `deferred`, journalisés dans `AUTOMATION_CRON_BUDGET` et
ne sont pas rattrapés automatiquement. Le lot 7 ajoutera la reprise manuelle
des événements bloqués, le marquage des runs bloqués et le nettoyage.

### Actions

`plg_automation` ne code aucune action métier. Il fournit le contrat, le
registre et l'exécution. Chaque extension déclare ses actions via
`IAutomationProvider` et un registre explicite. `plg_core` déclare les actions
`core::*` (mail, utilisateurs, rôles, groupes) ; Blog, LMS et Association
déclarent les leurs.

Une définition d'action porte :

- identifiant `namespace::nom`, propriétaire, version ;
- handler implémentant `IAutomationAction` ;
- paramètres typés et type de retour ;
- classe d'effet : `pure`, `read`, `write`, `external` ;
- permissions requises et portée du contrôle (`scenario` ou `resource`) ;
- timeout, taille de sortie maximale, idempotence ;
- libellé, description, catégorie et formulaire Vue facultatif.

Le handler reçoit un `ActionContext` explicite : principal, Space, run,
scénario, version et services partagés. Il vérifie lui-même l'ACL sur le Space
réel de la ressource. Les actions sont des cas d'usage dédiés : elles peuvent
réutiliser les helpers, sans obligation de passer par un WF HTTP.

Le registre refuse les collisions, les namespaces réservés et les handlers
invalides. Les built-ins de calcul restent `pure` ; `util::log` écrit dans le
journal ; les actions sont `write` ou `external`.

Namespaces réservés : `str`, `arr`, `math`, `date`, `json`, `type`, `util`,
`core`.

### Google Sheets

`plg_core` fournit dix actions `google_sheets::*` pour un compte de service
configuré par Space : `appendRow`, `appendRows`, `insertRow`, `readRange`,
`readSheet`, `updateRange`, `listSheets`, `findRows`, `upsertRow` et
`clearRange`. `insertRow` prend une position à partir de 1, insère une ligne
entière via `InsertDimension` (compatible avec les tableaux) et décale les
lignes suivantes ; les chaînes y restent littérales, sans formule. Le JSON
du compte de service est stocké chiffré et affiché aux administrateurs
autorisés. `sheet_id` accepte l'ID numérique ou le titre de l'onglet ; il est
facultatif lorsque `range` identifie la plage. Les écritures sont en `RAW` par
défaut pour éviter l'interprétation de formules issues de données externes.
Les actions exigent `PUBLISH` à la publication du scénario.

La configuration et les exemples sont dans [Google Sheets](../../GOOGLE_SHEETS.md).

### Google Agenda

Le même compte de service et le même champ de configuration que Google Sheets
alimentent cinq actions `google_calendar::*` : `listEvents`, `getEvent`,
`createEvent`, `updateEvent` et `deleteEvent`. L’agenda ciblé doit être partagé
avec `client_email` du compte de service. `calendar_id` accepte `primary`, une
adresse e-mail ou l’identifiant d’un agenda partagé.

Les dates acceptent une date seule `AAAA-MM-JJ` pour une journée entière, ou
une date ISO 8601 avec fuseau. Pour une journée entière, la date de fin est
exclusive. `send_updates` vaut `none` par défaut ; les invitations dépendent des
droits du compte de service. Les actions de lecture sont `READ`, les créations,
modifications et suppressions sont `EXTERNAL`, toutes exigent `PUBLISH`.

La configuration et les exemples sont dans [Google Agenda](../../GOOGLE_CALENDAR.md).

## Exécution

### Enregistrement et publication

- Le scénario est parsé et validé à l'enregistrement ou à la publication.
- La source, l'AST/IR, la version de schéma et la version de grammaire sont
  stockés.
- Les runs sont épinglés à une version publiée : modifier un scénario ne change
  pas un run en cours.
- Les états sont brouillon, publié, archivé.
- L'IR est validé aussi à la lecture : un client pourrait poster un AST forgé
  sans passer par le parser.
- Un brouillon invalide peut être enregistré ; seule une version valide peut
  être publiée.

### Déclenchement synchrone après commit

1. Le code métier écrit une ligne `automation_events` dans sa transaction via
   `AutomationEventBuffer::emit()`.
2. Le runner commit, puis appelle
   `onAfterRouteExecuted(Route $route, bool $transactionCommitted)`.
3. Le buffer d'événements de la requête draine les `event_id` émis. Une route
   `#[NoTransaction]` terminée sans exception est traitée comme validée.
4. Pour chaque événement, les déclencheurs actifs sont résolus, les runs créés
   et exécutés **en synchrone** dans la même requête.
5. Le run et ses étapes sont persistés ; le résultat est disponible dans
   l'historique.
6. En cas d'échec, le run est marqué `failed`, une notification est envoyée,
   et aucun retry automatique n'a lieu.

Chaque événement est réservé par une mise à jour conditionnelle
`pending -> processing` ; deux drains concurrents ne l'exécutent pas deux fois.
Le couple scénario/événement est dédupliqué par `dedup_key = event_id`, protégé
par l'index unique des runs.

Un événement émis pendant un run devient un enfant : il hérite de la
corrélation, porte la causalité de l'événement parent et augmente la profondeur
d'un cran. La profondeur est bornée à 8 par `AutomationEventBuffer::MAX_DEPTH`
et un drain traite au plus 100 événements. Au-delà, les lignes restent
`pending` pour le futur worker.

Un scénario sans principal utilisateur en mode `trigger`, sans auteur en mode
`author` ou en mode `service` est ignoré et journalisé ; aucun run ni aucune
notification n'est créé. Un scénario dont l'exécution échoue produit en
revanche un run `failed` et sa notification.

Un déclencheur qui n'est plus fourni par une extension active est refusé :
l'événement est marqué `failed` avec `AUTOMATION_TRIGGER_UNAVAILABLE`. Une
extension désactivée ne peut donc pas continuer à déclencher des scénarios par
une ligne `automation_triggers` oubliée.

Le hook `onCronMinute` du worker, appelé via la route core `/cron` chaque
minute, exécute les déclencheurs planifiés arrivés à échéance. La reprise d'un événement resté `pending` ou
`processing` après un crash, le marquage d'un run bloqué et son nettoyage
restent au lot 7 ; le worker actuel ne rattrape pas ces cas.

### Déclenchement par une extension productrice

Une extension déclare ses triggers via `IAutomationProvider`. Elle reste
propriétaire de sa route, de ses ACL et de sa validation métier ; le moteur ne
connaît ni le bouton de page, ni le formulaire, ni Workgroup.

`AutomationTrigger` expose deux modes :

- `emit()` écrit un événement dans la transaction métier ; le drain après
  commit exécute les scénarios, persiste l'historique et notifie les échecs.
  Une clé de scénario cible facultative limite l'exécution à ce scénario ;
  sans clé, tous les scénarios publiés du trigger s'exécutent.
- `triggerNow()` exécute immédiatement les scénarios publiés du trigger dans la
  transaction de l'appelant. Il ne démarre aucune transaction, retourne les
  résultats et ne notifie pas automatiquement les échecs. Le producteur décide
  de commiter ou d'annuler.

`triggerNow()` accepte l'acteur, une clé de scénario facultative, une clé de
déduplication et le payload. La profondeur des appels synchrones imbriqués est
bornée à 8. Les runs et étapes sont dans la transaction : un rollback les
annule, mais les journaux techniques du Log général conservent la trace
(`AUTOMATION_TRIGGER_SYNC_*`). L'appelant peut journaliser ou notifier après
coup.

```php
$result = $this->automationTrigger->triggerNow(
    'workgroup.group.updated',
    ['group' => ['id' => $groupId, 'state' => $state], 'actor' => ['id' => $userId]],
    $userId,
    $scenarioKey,
    'workgroup:' . $groupId . ':' . $operationId,
);

if ($result->hasRuns() && !$result->isSuccessful()) {
    ExceptionResponse::throwWarning('Le scénario du groupe n’a pas pu être exécuté.');
}
```

Le mode `trigger` reste atomique : les actions du scénario utilisent la
connexion du producteur. Le mode `author` utilise une Application isolée pour
les droits de l'auteur, donc une autre connexion : ses écritures ne sont pas
annulées par le rollback du producteur. Un scénario synchrone qui envoie un
e-mail ou une notification ne peut pas annuler cet effet ; le producteur peut
séparer les scénarios : un `triggerNow` pour les écritures atomiques, un
`emit` pour les effets externes.

`GET administrator/api/automation/scenario-choices?spaceId=…&triggerId=…`
permet aux UI productrices de lister les scénarios publiés abonnés à leur
trigger. La permission `VIEW` sur le Space est requise. L'éditeur de page
l'utilise pour le bouton ; une future extension de formulaire ou Workgroup
l'utilisera avec son propre trigger, sans modification du moteur.

### Bouton de page

Le trigger `core.page.button.clicked` est déclaré par `plg_core`, comme le
bouton de page lui-même. Le moteur ne connaît pas ce trigger particulier.

Le bouton porte `button_action = link|automation` et, en mode automation,
`automation_scenario_key`. Il porte aussi `success_message_mode` :
`default` affiche le message générique, `custom` utilise `success_message`
(localisé, 300 caractères maximum), `result` affiche la réponse du scénario et
`none` n'affiche aucune confirmation. Un texte personnalisé vide retombe sur le
message par défaut ; `none` sert aux actions dont le retour visuel n'a pas de
sens. L'éditeur de page liste les scénarios via `scenario-choices` et exige
`VIEW` pour voir cette liste. Le scénario doit être publié avec le trigger
bouton de page et appartenir au même Space.

Les modes `default`, `custom` et `none` utilisent `emit` : le scénario
s'exécute après le commit, avec l'historique d'événement et les notifications
d'échec. Le mode `result` utilise `triggerNow` pour récupérer la sortie dans la
requête : le scénario s'exécute pendant le clic, le visiteur attend la fin, et
les notifications d'échec automatiques ne s'appliquent pas. Le run reste dans
l'historique, sans ligne d'événement d'outbox.

En mode `result`, une chaîne non vide devient une confirmation. Une map
produite par `res::confirm`, `res::info`, `res::warning`, `res::danger` ou
`res::error` choisit la modale et son message. Le texte est tronqué à 500
caractères ; tout autre résultat retombe sur le message par défaut.

Le rendu public produit un composant Vue `core/automation-button`, uniquement
pour les boutons d'automatisation. Il appelle `POST /page/button` avec page,
nœud, locale et jeton HMAC ; le CSRF est vérifié dans l'en-tête. Le contrôleur
Core recharge la page et le nœud, vérifie le scénario publié, le mode de
principal et une limitation de débit (10/minute par session et par bouton,
100/heure par Space et trigger, événements et runs synchrones confondus), puis
émet l'événement via `AutomationTrigger::emit` en ciblant la clé du scénario du
bouton. En mode `result`, il appelle `triggerNow` à la place.

L'API retourne `message`, `level` et `reload`. Le composant affiche la modale
standard (`showModaleConfirm`, `showModaleInfo`, `showModaleWarning`,
`showModaleDanger` ou `showModaleError`). Quand `reload_on_close` est activé,
la fermeture de la modale recharge la page ; en mode `none`, le rechargement
est immédiat. Le texte est relu dans le document de page côté serveur, jamais
depuis le navigateur.

Le mode `trigger` exige un visiteur connecté ; ses permissions d'action
s'appliquent. Le mode `author` accepte un visiteur anonyme et exécute avec les
droits de l'auteur publié ; `$input.actor` porte le visiteur. `RUN` n'est pas
requis : le bouton est un déclencheur, pas un run manuel. Le bouton n'accorde
aucun droit et ne peut déclencher qu'un scénario déjà publié. Les modes
`service` et les scénarios non publiés sont refusés. L'aperçu de page rend le
bouton inactif ; seul le rendu public monte le composant et permet le clic.

### Identités d'exécution

- Déclencheur utilisateur (`trigger`) : l'Application et la session de la
  requête courante ; le principal est l'utilisateur qui déclenche.
- Auteur (`author`) : nouvelle instance d'`Application` isolée, avec une
  `RunSession` en mémoire portant l'utilisateur auteur et le Space du scénario.
- Service (`service`) : prévu pour plus tard.
- Un déclencheur système sans principal utilisateur en mode `trigger` est
  refusé (`AUTOMATION_PRINCIPAL_UNAVAILABLE`).

La nouvelle Application clone la configuration courante, sans `RESET_DATABASE`,
et garde les extensions nécessaires aux actions. Elle est initialisée sans
enregistrement de routes (`registerRoutes = false`) pour éviter les dépendances
de langue, de menu et de WAF du Space. Le contrôleur/service de run est interne,
appelé directement, jamais enregistré comme route publique : le WAF, le CSRF et
la session HTTP ne s'appliquent pas à l'exécution interne.

### Transactions et effets

- Aucune transaction englobante sur tout le run.
- Chaque action qui écrit gère sa propre transaction ; un échec d'action
  n'annule pas les effets déjà appliqués.
- Les effets externes sont exécutés pendant le run, avec timeout et quotas.
  Aucun job différé en v1.
- Le run n'est jamais rejoué automatiquement.
- La notification d'échec est best-effort, après persistance du statut.
- Le run manuel passe par `POST administrator/api/automation/run`, route
  `#[NoTransaction]` : le WF vérifie `RUN`, puis l'exécuteur persiste le run et
  ses étapes. Le mode `author` utilise l'Application isolée sans routes.

### Journal et déterminisme

- Journal par run et par étape : entrée, sortie, erreur, horodatage, action,
  identité, span. Les secrets sont masqués.
- Horloge, aléatoire et identifiants injectés.
- Le rejeu n'est pas une fonctionnalité v1 ; les valeurs sont enregistrées pour
  le diagnostic.

### Interface d'administration

L'écran « Automatisations » est une page Core de l'administration, affichée
seulement si l'extension est chargée et si l'utilisateur possède
`plg_automation__VIEW`. Il liste les scénarios des Spaces visibles, avec leur
déclencheur, leur version publiée et leur dernier run.

Permissions :

- `VIEW` : voir la liste, ouvrir un scénario, consulter l'historique.
- `EDIT` : créer, enregistrer un brouillon et lancer une vérification.
- `PUBLISH` : publier et archiver. Un éditeur qui possède aussi `PUBLISH` peut
  modifier le mode ou le déclencheur d'un scénario déjà publié ; un éditeur seul
  ne le peut pas.
- `RUN` : exécuter la version publiée depuis l'écran.
- `RUN_AS_AUTHOR` : publier un scénario dont le mode est `author`.
- `ADMIN` : configurer les comptes de service Google, les canaux de
  notification et la rétention. Elle n'est pas exposée à l'édition des rôles ;
  ROOT la reçoit par la migration `1.0.1` et peut l'attribuer à un autre rôle.
  Elle remplace `PUBLISH` pour l'édition de la configuration.

L'éditeur charge la source du brouillon, ou celle de la version publiée s'il
n'existe pas de brouillon distinct. Enregistrer ne crée une nouvelle version
que si la source a changé ; une modification des métadonnées seules ne gonfle
pas l'historique. La publication compile le brouillon enregistré, vérifie les
permissions de chaque action réellement appelée, fixe l'auteur au publieur en
mode `author`, puis pointe `published_version_id` sur ce brouillon. Un run
manuel exécute la version publiée, jamais les modifications en cours.

Pour un webhook, l'éditeur affiche le mode d'authentification, le mode de
réponse (`accepted` ou `result`), l'URL, la méthode, le type de contenu, un
exemple de charge utile et l'état du secret. Le bouton de génération ou de
rotation n'affiche le secret qu'une fois dans une modale, puis seul l'état
« configuré » reste visible. Pour une planification,
il propose le preset, l'heure, le jour, le fuseau du Space en lecture seule et
la prochaine exécution calculée par le serveur. Les deux déclencheurs forcent
le mode Auteur et désactivent l'option « Utilisateur qui déclenche ». La
modification de ces réglages sur un scénario publié exige `PUBLISH`.

Sous l'éditeur, le catalogue des actions affiche leur effet, leur type de
retour, leurs paramètres typés (libellé, obligation, valeur par défaut,
description) et les permissions requises. Un second panneau commence par les
instructions `stop` et `fail`, puis liste les fonctions standard par namespace
avec leur signature, leur effet et leur description ;
`BuiltinFunctionReference` est contrôlé par test contre le catalogue d'exécution.
Un troisième panneau repliable propose des exemples de code statiques et la
documentation serveur des variables `$input`, `$principal`, `$space` et `$run`.
La référence complète (instructions de contrôle, fonctions, actions, variables
et charges utiles des déclencheurs) est téléchargeable en Markdown via
`GET administrator/api/automation/reference`.

La vérification (« dry-run ») compile et exécute la source courante sans créer
de run ni d'étape. Les fonctions intégrées et les actions `read` restent
disponibles, avec leur ACL ; les actions `write` et `external` sont refusées par
un diagnostic `ACTION_UNAVAILABLE`. L'entrée est une map JSON validée.

La configuration de l'extension sépare les canaux d'échec et les canaux de
scénario. Ces derniers alimentent `core::notify` et les actions par provider ;
les profils nommés sont édités comme un JSON chiffré, affiché aux
administrateurs autorisés pour permettre son édition.

Archiver un scénario le retire de la liste et des déclencheurs mais conserve ses
versions, runs et étapes. Les endpoints sont sous
`administrator/api/automation` : `directory`, `read`, `create`, `save`,
`publish`, `archive`, `runs`, `run/detail`, `dry-run`, `reference`,
`scenario-choices`, `webhook-secret`, `maintenance`,
`maintenance/recover-event`, `maintenance/fail-event`,
`maintenance/fail-run`, `rerun` et `run`. Le contrôleur est exempté du WAF parce qu'il
reçoit du code et des charges utiles de scénario ; le CSRF, la validation de
schéma et les contrôles du WF restent actifs. La route publique
`/automation/webhook/{scenarioId}` est sans CSRF et s'authentifie par le secret
du scénario. La route CRON est fournie par Core : `GET/POST /cron`, jeton
`cron_token` (voir [CRON](../../CRON.md)).

### Maintenance manuelle

Le panneau « Maintenance » de l'écran Automatisations est réservé à `RUN_MANAGE`
et agit sur un Space à la fois. Il liste les événements `pending` anciens, les
événements `processing` bloqués et les runs `queued`/`running` bloqués. Le cron
ne les reprend pas automatiquement.

Actions :

- **Reprendre un événement** : réservation atomique `processing -> pending` ou
  `pending -> processing`, puis exécution synchrone dans la requête. Un second
  clic concurrent reçoit « déjà repris » ou « déjà en cours ». Un événement trop
  récent, déjà terminé ou ayant atteint la limite de tentatives est refusé.
- **Marquer un événement en échec** : termine la ligne sans créer de run.
- **Marquer un run en échec** : réservé aux runs bloqués depuis le seuil ; la
  mise à jour conditionnelle évite un double traitement et la notification
  d’échec configurée est envoyée une seule fois.
- **Relancer un run en échec ou arrêté** : réexécute la **version publiée
  actuelle** avec l’entrée du run d’origine. Le brouillon n’est jamais exécuté.
  La permission `RUN_MANAGE` est requise pour rejouer l’entrée d’un run.
- **Nettoyer maintenant** : supprime les événements `processed`/`failed` plus
  vieux que la rétention d'événements et les runs terminés plus vieux que la
  rétention de runs. Les étapes suivent la cascade des runs. Les statuts
  actifs ne sont jamais supprimés.

Seuils : événement `pending` après 5 minutes, événement `processing` après
10 minutes, run bloqué après 10 minutes, trois tentatives maximum par
événement. Les deux rétentions se règlent dans la configuration de l'extension
du Space (valeurs par défaut : 30 jours pour les événements, 90 jours pour les
runs et étapes). Le nettoyage est irréversible ; les runs conservés peuvent
perdre leur `event_id` lorsque l'événement correspondant est purgé plus tôt.

## Sécurité

- **Identités distinctes** : auteur, principal du déclencheur, exécutant. Les
  droits sont revérifiés à l'exécution.
- **Permissions Automation** : `VIEW`, `EDIT`, `PUBLISH`, `RUN`,
  `RUN_MANAGE`, `RUN_AS_AUTHOR`, `ADMIN`. `RUN_AS_AUTHOR` autorise à publier un
  scénario en mode `author` ; il n'y a pas d'approbation `SPACE_ADMIN`
  séparée. `ADMIN` est réservée à ROOT pour les secrets et la configuration
  sensible.
- **Anti-escalade** : le publieur doit posséder les permissions de toutes les
  actions du scénario. En mode `author`, `execution_user_id` est le publieur ;
  on ne publie pas au nom d'un autre utilisateur.
- **ACL** : chaque action vérifie le droit sur le Space réel de la ressource.
- **Pas de SQL/shell/eval/include** : aucun nœud de ce type.
- **Actions dédiées** : cas d'usage simples, avec `ActionContext` explicite ;
  les helpers peuvent être réutilisés, les WF HTTP ne sont pas imposés.
- **Frontière de valeurs** : scalaires, tableaux, `DateValue` ; aucun objet
  PHP, ressource ou callback.
- **Aucune méthode** : `->` et `?->` ne lisent que des champs de valeurs sûres.
- **Secrets** : coffre chiffré, références par identifiant, jamais dans la
  source, l'AST ou le journal. Les profils de notification ne sont référencés
  que par leur clé ; les actions ne reçoivent ni jeton ni webhook. Les jetons
  webhook bearer/query sont hachés, le secret HMAC est chiffré et aucun des
  deux n'est journalisé.
- **Sortie webhook** : le mode `result` renvoie le statut et `output` du run à
  l'appelant authentifié. Il peut contenir des données du scénario ; il est
  réservé aux intégrations de confiance. Le mode `accepted` reste le défaut.
- **Routes publiques** : webhook et cron sont exemptés de CSRF et du WAF, mais
  exigent respectivement un jeton de scénario et le jeton d'installation. Le
  webhook vérifie l'état publié avant toute émission et ne journalise aucun
  secret ni corps. La route cron ne traite que les scénarios planifiés dus et
  ouvre une transaction par scénario.
- **Maintenance** : les reprises, mises en échec et nettoyages exigent
  `RUN_MANAGE` sur le Space réel. Les réservations sont atomiques ; le
  nettoyage ne supprime aucun statut actif et reste irréversible côté données.
- **SSRF** : allowlist, IP privées bloquées, timeouts, redirections
  désactivées, réponse bornée.
- **Injection** : échappement par sink. `core::sendMailTemplate` utilise un
  modèle et des variables ; `core::sendMail` applique le profil e-mail
  (`EmailHtmlSanitizer`) à son corps HTML avant l'envoi.
- **Journaux** : codes stables, Space réel, masquage, rétention.
- **Déduplication** : `event_id` unique, `dedup_key` par scénario, profondeur
  de chaîne bornée.
- **Quotas** : source, tokens, profondeur, étapes, boucles, appels, durée,
  tailles et quotas par action. Le webhook applique 120 appels par minute et
  par scénario en comptant les événements récents ; ce contrôle est best-effort
  sous concurrence.

## Limites et hébergement

L'hébergement cible annoncé est Infomaniak : **640 Mo de mémoire** et
**60 secondes de temps d'exécution**. Le budget du moteur doit rester sous ces
valeurs pour échouer proprement avant PHP.

| Budget | Défaut | Plafond | Pourquoi |
| --- | --- | --- | --- |
| Taille de source | 64 Kio | 128 Kio | Environ 1 500 lignes ; au-delà, découper |
| Tokens | 20 000 | 40 000 | Protège le parser |
| Profondeur d'AST | 64 | 128 | Protège la récursion |
| Profondeur de blocs | 32 | 64 | Lisibilité et pile |
| Itérations par boucle | 10 000 | 50 000 | Empêche la boucle infinie |
| Itérations cumulées | 50 000 | 200 000 | Protège le CPU |
| Étapes d'exécution | 100 000 | 400 000 | Couvre les boucles avec marge |
| Profondeur d'appel | 32 | 64 | Empêche la récursion non bornée |
| Fonctions / paramètres | 64 / 16 | 128 / 32 | Taille statique |
| Chaîne / tableau | 1 Mio / 10 000 | 4 Mio / 50 000 | Mémoire |
| Profondeur JSON | 32 | 64 | JSON hostile |
| Durée d'un run | 10 s | 45 s | Marge sous les 60 s Infomaniak |
| Mémoire d'un run | 128 Mo | 256 Mo | Marge sous les 640 Mo |
| Appels d'action | 200 | 1 000 | Coût, spam, quotas externes |
| Sortie de `main` | 64 Kio | 256 Kio | Journal et réponse |
| Journal | 1 000 entrées / 256 Kio | 5 000 / 1 Mio | Stockage |

Règles :

- le budget moteur doit être inférieur à `max_execution_time` et à
  `memory_limit`, pour produire une erreur `LIMIT_EXCEEDED` propre ;
- les quotas d'action sont par action : un envoi de mail et une lecture de user
  n'ont pas le même coût ;
- les Space peuvent réduire les budgets ; les plafonds durs ne sont pas
  dépassables ;
- une limite atteinte marque le run en échec et peut laisser des effets
  partiels : la déduplication, les transactions par action et la notification
  d'échec sont la réponse ; il n'y a pas de rejeu automatique en v1.

## Modèle de données

| Table | Rôle | Points clés |
| --- | --- | --- |
| `automation_scenarios` | Identité stable | `space_id`, `key` unique par Space, nom, `principal_mode` (`trigger`/`author`/`service`), `execution_user_id`, versions publiée/brouillon, statut |
| `automation_triggers` | Déclencheur | un par scénario en v1, `trigger_id`, `entry_function`, config JSON (`auth_mode`/`response_mode` webhook, preset/heure/jour planifié), `secret_hash` webhook, `enabled` |
| `automation_versions` | Versions immuables | source, AST JSON, `source_hash`, statut de compilation, diagnostics, versions de schéma/grammaire, auteur, publication |
| `automation_events` | Outbox de domaine | `event_id` unique, `trigger_id`, payload JSON, principal, `correlation_id`, `depth`, `causation_id`, statut |
| `automation_runs` | Run | version épinglée, `event_id`, `dedup_key`, principal, `depth`, `parent_run_id`, statut, timings, sortie/erreur |
| `automation_run_steps` | Journal | séquence, type, identifiant, statut, entrée/sortie masquées, span |

Il n'y a pas de `automation_jobs` en v1. Les événements et les runs sont bornés
par les quotas et purgés selon la rétention du Space. Les pointeurs de version
du scénario n'ont pas de FK pour éviter le cycle ; le WF les contrôle. Les runs
conservent l'ID du principal même si le compte est supprimé, avec un libellé
d'audit.

### Colonnes principales

**`automation_scenarios`** : `id`, `space_id`, `key`, `name`, `description`,
`status`, `principal_mode`, `execution_user_id`, `published_version_id`,
`draft_version_id`, `created_by`, `created`, `updated`, `archived_at`.

**`automation_triggers`** : `id`, `scenario_id`, `space_id`, `trigger_id`,
`entry_function`, `config`, `secret_hash`, `enabled`, `created`, `updated`.
Unique `(scenario_id)` en v1.

**`automation_versions`** : `id`, `scenario_id`, `version_number`, `status`,
`compile_status`, `source`, `source_hash`, `ast`, `schema_version`,
`grammar_version`, `diagnostics`, `created_by`, `created`, `published_by`,
`published_at`. Unique `(scenario_id, version_number)`.

**`automation_events`** : `id`, `event_id`, `space_id`, `trigger_id`,
`target_scenario_key`, `payload`, `principal_type`, `principal_id`,
`correlation_id`, `depth`, `causation_id`, `occurred_at`, `status`, `attempts`,
`last_error`, `processed_at`, `created`.
Unique `event_id`, index `(status, id)`.

**`automation_runs`** : `id`, `space_id`, `scenario_id`, `version_id`,
`trigger_id`, `event_id`, `dedup_key`, `principal_type`, `principal_id`,
`principal_label`, `input`, `status`, `depth`, `parent_run_id`, `queued_at`,
`started_at`, `finished_at`, `duration_ms`, `steps_count`, `loop_iterations`,
`action_calls`, `output`, `message`, `error_code`, `error_message`, `created`.
Unique `(scenario_id, dedup_key)`.

**`automation_run_steps`** : `id`, `run_id`, `sequence`, `kind`, `identifier`,
`status`, `started_at`, `duration_ms`, `input`, `output`, `error_code`,
`error_message`, `span_start_line`, `span_start_column`, `span_end_line`,
`span_end_column`. Unique `(run_id, sequence)`.

## API d'extension

```text
YTassy\Tycms\CoreExts\Automation\ExtensionApi\IAutomationProvider
    coreOnRegisterAutomationActions(AutomationActionRegistry $registry): void
    coreOnRegisterAutomationTriggers(AutomationTriggerRegistry $registry): void
```

Les registres :

- valident l'identifiant qualifié, le propriétaire et les collisions ;
- vérifient que le handler implémente `IAutomationAction` ;
- chargent les handlers paresseusement ;
- refusent les namespaces réservés.

`ActionContext` porte le principal, le Space, le run, le scénario, la version et
les services. Les actions sont déclarées par leur extension ; `plg_core` déclare
`core::*`.

## Découpage en lots

| Lot | Contenu | Critère de sortie |
| --- | --- | --- |
| 0 | Cette note, EBNF, décisions, socle de l'extension Core | Fait |
| 1 | Lexer, parser, AST, validation statique, tests | Fait : tests dédiés, erreurs positionnées, reprise au niveau des instructions |
| 2 | Interpréteur, built-ins, budgets, journal mémoire | Fait : interpréteur, built-ins purs, portées, budgets, journal en mémoire, frontière de valeurs, tests |
| 3 | Tables, versions, registres, hook après commit, `RunSession`, run manuel synchrone | Fait (socle) : schéma, permissions, registres, hook, exécuteur, persistance runs/steps, WF manuel et smoke test |
| 4 | Actions Core (`sendMail`/`sendMailTemplate`, users, rôles), notifications d'échec puis de scénario, profils nommés, endpoint manuel, config chiffrée | Fait (actions, notifications, endpoint) ; UI et exercice author/service à venir |
| 5 | Éditeur textuel, dry-run, historique | Fait : liste, création, brouillon, vérification en lecture seule, publication, exécution, historique des runs, catalogues serveur et référence Markdown téléchargeable |
| 6 | Buffer d'événements, déclencheurs domaine, bouton de page, API productrice, webhook, planifié | Buffer, drain, `core.user.created`, bouton de page, `emit`/`triggerNow`, `scenario-choices`, webhook, planification et worker cron faits ; tests d'intégration et exercice réel à venir |
| 7 | Maintenance manuelle, reprise des événements bloqués, runs interrompus, rétention, durcissement | Fait : panneau Maintenance, réservations atomiques, runs marqués en échec avec notification, rétention configurable, tests d'intégration ; audit de sécurité et essai réel à poursuivre |

## Pistes futures non planifiées

Ces idées sont notées pour ne pas les perdre. Elles ne sont pas planifiées et
ne constituent pas un engagement de livraison.

### Lot 9 — Observabilité : reporté

Le tableau de bord CRON (dernier tick, heartbeat, alertes d’absence, métriques)
est reporté. Pour l’instant, une alerte Telegram quotidienne suffit à vérifier
qu’un CRON quotidien s’est exécuté. Elle ne prouve pas l’exécution de chaque
hook ni ne remplace un heartbeat.

### Lot 10 — Fiabilité fonctionnelle : reporté

Clé d’idempotence webhook, filtres de payload avant exécution, déduplication
configurable et file de jobs différés restent des idées. On reste simple pour
l’instant.

### Lot 11 — Extensions fonctionnelles : reporté

- **Plusieurs déclencheurs par scénario** : pas urgent.
- **Formulaire simplifié** : un mode guidé pour composer un scénario courant
  (déclencheur, quelques actions, champs et conditions) sans écrire toute la
  source. Il générerait la source ou un sous-ensemble validé, tout en gardant
  l’éditeur textuel pour les cas avancés. À ne pas confondre avec un éditeur
  graphique complet.
- **UI de références de secrets pour les actions** : un registre de secrets
  nommés par Space (`stripe_key`, `helloasso_secret`, etc.), chiffrés et
  sélectionnables dans les paramètres d’action. La source ne contiendrait
  qu’une référence ; la rotation et l’audit seraient centralisés. Les secrets
  de notification et le compte Google existants restent des réglages dédiés.
- **Actions Google Sheets supplémentaires** (`addSheet`, `createSpreadsheet`,
  `formatRow`, opérations de masse) : reportées, non prioritaires.
- **Gmail et Google Drive** : reportés.

### Lot 12 — Performance et exploitation : reporté

Mémoire par run, quotas par Space, concurrence du worker, priorisation,
archivage et rétention avancée restent à étudier plus tard.

## Questions ouvertes

- Quotas réels d'envoi de mails et limites des API externes ?
- Politique d'allowlist HTTP et de protection SSRF.
- Rétention des runs, steps et événements (piste du lot 12).
- Mesure de la mémoire par run : estimation par tailles ou échantillonnage
  `memory_get_usage` (piste du lot 12) ?

## Hors périmètre

- Parité fonctionnelle avec N8N.
- Éditeur graphique en v1.
- PHP, SQL, shell, `eval`, `include` dans un scénario.
- Syntaxe ajoutée par une extension.
- Closures, fonctions anonymes, fonctions de première classe.
- Plusieurs points d'entrée en v1.
- Garantie « exactement une fois ».
- Reprise automatique des runs échoués.
- File de jobs différés.
- Principal de service en v1.
- Approbation `SPACE_ADMIN` d'un mode `author` : remplacée par la permission
  `RUN_AS_AUTHOR`.
- Débogage visuel pas-à-pas en v1.

## Références

- [Architecture](../../ARCHITECTURE.md)
- [Extensions](../../EXTENSIONS.md)
- [Composants de page](../../PAGE_COMPONENTS.md)
- [E-mails](../../MAIL.md)
- [Chiffrement](../../CRYPTO.md)
- [Assistance IA](../../AI.md)
