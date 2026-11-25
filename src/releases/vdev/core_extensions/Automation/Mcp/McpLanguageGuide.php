<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Mcp;

/**
 * Documentation autosuffisante du langage et du contexte d'exécution MCP.
 *
 * Un client MCP (agent IA) n'a accès ni à `Docs/`, ni au code, ni à l'éditeur
 * de scénarios : il ne dispose que des outils du serveur. Ce guide est donc la
 * seule description du langage qu'il puisse consulter, et il doit suffire à
 * écrire un programme correct du premier coup.
 *
 * Les textes ci-dessous suivent le contrat de `Docs/IA/CurrentWorks/AUTOMATION.md`
 * (lexique, grammaire, types, limites). Toute évolution de ce contrat doit être
 * répercutée ici : la fidélité de ce guide fait partie de l'interface du MCP.
 */
final class McpLanguageGuide
{
    /**
     * Architecture d'exécution, côté agent.
     *
     * On décrit ce qui change le code à écrire — identité, Space, transaction,
     * effets exclus, budgets — sans exposer de détail interne inutile.
     *
     * @return array<string, mixed>
     */
    public static function architecture(): array
    {
        return [
            'product' => 'TyCMS, CMS multi-Space (sites) piloté par agents via MCP.',
            'overview' => [
                'Vous parlez à un CMS (CMS = système de gestion de contenu : il gère des sites web, '
                    . 'leurs pages, leurs utilisateurs). Le serveur ne vous propose pas un outil par '
                    . 'opération métier : il vous donne un petit langage de programmation, et VOUS '
                    . 'écrivez un programme qui appelle les opérations disponibles.',
                'Un programme est une suite de fonctions, dont une fonction main() que le serveur '
                    . 'appelle. Il renvoie une valeur, que vous recevez comme résultat de l’outil '
                    . 'tycms.run. C’est tout.',
                'Les opérations disponibles s’appellent des ACTIONS. Elles forment un catalogue fermé : '
                    . 'vous ne pouvez appeler que celles qui existent. Appelez l’outil '
                    . 'tycms.list_actions pour obtenir la liste complète, avec les paramètres et un '
                    . 'exemple d’appel de chacune. Le présent document décrit le cadre ; '
                    . 'tycms.list_actions dit ce que vous pouvez faire concrètement.',
                'Un SPACE est un site géré par le CMS. Les données (utilisateurs, pages, contenus) sont '
                    . 'rattachées à un Space. Votre jeton est rattaché à un Space par défaut, exposé '
                    . 'dans $space. Vous ne voyez que les données que vos droits autorisent.',
                'Un COMPTE DE SERVICE est l’identité avec laquelle le programme s’exécute. Ce n’est pas '
                    . 'un utilisateur humain connecté : c’est un compte technique qui possède ses '
                    . 'propres droits. Vous n’avez pas à vous connecter ; le jeton le fait.',
            ],
            'howToProceed' => [
                '1. Appelez tycms.list_actions pour connaître les actions disponibles et leurs paramètres.',
                '2. Écrivez un programme avec function main() qui appelle ces actions.',
                '3. Envoyez-le avec l’outil tycms.run.',
                '4. Lisez le résultat. En cas d’erreur, le champ diagnostics donne la ligne, la colonne '
                    . 'et un code (par exemple BAD_ARITY ou UNKNOWN_FUNCTION) : corrigez en conséquence.',
            ],
            'execution' => [
                'Le programme est compilé puis interprété par TyCMS ; ce n’est pas PHP, '
                    . 'JavaScript ni SQL. Aucun accès au système de fichiers, au shell ou à eval.',
                'Le programme s’exécute avec l’identité du compte de service résolu depuis '
                    . 'le jeton d’API : $principal en porte l’identité.',
                'Les droits sont ceux de ce compte : jeton → compte → groupes → rôles → '
                    . 'permissions. Toute action qui exige un droit absent est refusée, même '
                    . 'si la syntaxe est valide.',
                'Le Space courant est $space ; il délimite les données visibles. Une action '
                    . 'qui cible une ressource vérifie les droits sur le Space réel de cette '
                    . 'ressource, pas seulement sur le Space courant.',
                'Chaque action vérifie elle-même ses droits : un refus arrive à l’exécution, '
                    . 'pas à la compilation.',
                'L’exécution est encadrée par une transaction unique : un succès valide les '
                    . 'écritures, un échec (fail ou erreur) les annule. Un programme ne doit '
                    . 'donc pas supposer qu’un effet partiel survit.',
                'Certaines actions produisent un EFFET EXTERNE : elles sortent du CMS (envoi '
                    . 'd’e-mail, message Telegram/Slack/Discord, écriture dans Google Sheets ou '
                    . 'Google Agenda). Le serveur MCP les refuse : elles sont absentes du catalogue, '
                    . 'et les nommer fait échouer la compilation en fonction inconnue. Travaillez '
                    . 'avec les actions internes au CMS.',
                'La sortie de main() est bornée (64 Kio par défaut) et doit rester faite de '
                    . 'valeurs simples : null, booléen, nombre, chaîne, tableau, date.',
            ],
            'budgets' => [
                'source' => '64 Kio',
                'exercice' => '10 s par défaut, 45 s maximum',
                'iterations' => '10 000 par boucle, 50 000 cumulées',
                'callDepth' => '32 niveaux',
                'actionCalls' => '200 par défaut',
                'output' => '64 Kio',
            ],
        ];
    }

    /**
     * Syntaxe du langage, sous sa forme réellement acceptée par le moteur.
     *
     * Les points les plus coûteux sont mis en avant : arguments strictement
     * positionnels, accord uniquement avec -> et ?->, fonctions qualifiées par
     * `::`, pas de variable globale.
     *
     * @return array<string, mixed>
     */
    public static function syntax(): array
    {
        return [
            'entryPoint' => 'Le programme contient exactement une fonction main() sans paramètre. '
                . 'Aucune instruction n’est autorisée au niveau racine : tout le code exécuté '
                . 'vit dans une fonction.',
            'whatIsAFunction' => 'Une fonction est un bloc de code nommé, délimité par des accolades '
                . '{ }. On la déclare par function nom($parametre) { ... }, et elle se termine par '
                . 'return valeur; qui renvoie la valeur à l’appelant. Le nom main est réservé à la '
                . 'fonction d’entrée ; les autres noms sont libres.',
            'contents' => 'Concrètement, votre programme ressemble exactement à ceci : function main() '
                . '{ \n    // votre code\n    return "résultat";\n }. C’est le squelette de base ; '
                . 'les exemples complets en fin de document en montrent des variantes.',
            'semicolons' => 'RÈGLE DE PONCTUATION. Toute instruction simple se termine par un '
                . 'point-virgule « ; » : une affectation, un return, un break ou continue, un stop '
                . 'ou fail, et tout appel d’action ou de fonction utilisé comme instruction. Le '
                . '« ; » est OBLIGATOIRE même sur la dernière instruction avant l’accolade '
                . 'fermante. Seules les structures à bloc (if, elseif, else, foreach, for, while, '
                . 'function) ne prennent pas de « ; » : elles se terminent par « } ». À l’intérieur '
                . 'des parenthèses d’un for, les trois parties sont aussi séparées par « ; ».',
            'semicolonExamples' => [
                'correct' => 'function main() { $x = 1; return $x; }',
                'incorrect' => 'function main() { $x = 1 return $x; }  // erreur : « ; » manquant '
                    . 'après l’affectation',
                'incorrectLast' => 'function main() { return "ok" }  // erreur : « ; » manquant avant '
                    . 'l’accolade fermante',
            ],
            'comments' => ['// en fin de ligne', '/* bloc, non imbriqué */'],
            'identifiers' => 'Lettres, chiffres et _, commencent par une lettre ou _ ; sensibles à la casse.',
            'variables' => 'Préfixe $ obligatoire : $user, $total. Affectation : $x = expression;',
            'callStyle' => 'Les arguments sont STRICTEMENT POSITIONNELS. La forme "nom" => valeur '
                . 'n’existe pas en argument : elle n’est valide que dans une map. On écrit '
                . 'core::listUsers(1, 20, "", "user"), jamais core::listUsers("page" => 1).',
            'qualifiedCalls' => 'Les actions et les fonctions standard s’appellent avec :: : '
                . 'str::upper($nom), core::getUser(3). Une fonction écrite par vous s’appelle par '
                . 'son nom nu : displayName($user).',
            'fieldAccess' => 'Accès aux données avec -> : $user->firstName. Avec ?->, une valeur '
                . 'absente donne null au lieu d’une erreur : $input?->teamId.',
            'indexing' => 'Accès par clé avec [] : $users[0], $ligne["email"].',
            'controlFlow' => [
                'if (condition) { } elseif (condition) { } else { }',
                'foreach ($liste as $element) { }',
                'foreach ($map as $cle => $valeur) { }',
                'for ($i = 0; $i < 10; $i = $i + 1) { }',
                'while (condition) { }',
                'break; et continue;',
            ],
            'returns' => [
                'return expression; termine main() avec succès et fournit la sortie.',
                'return; termine une fonction sans valeur.',
                'stop; ou stop("message"); arrête le programme avec succès.',
                'fail("message"); arrête le programme en échec : la transaction est annulée.',
            ],
            'literals' => [
                'liste' => '[1, 2, 3]',
                'map' => '["clé" => valeur, "autre" => valeur]',
                'avertissement' => 'Un tableau ne mélange jamais éléments positionnels et '
                    . 'éléments à clé : [1, "a" => 2] est refusé. [] est une liste vide.',
                'chaînes' => 'Simples (\'...\') ou doubles ("...") indifféremment. Pas d’interpolation : '
                    . 'utiliser la concaténation "Bonjour " . $nom. Échappements \\, \", \', \n, \r, \t, \$, \u{XXXX}.',
                'nombres' => 'Entiers 42, flottants 3.14 ou 1e3. Pas d’hexadécimal.',
            ],
            'operators' => [
                'arithmétique' => '+ - * / % **',
                'concaténation' => '.',
                'comparaison' => '== != < <= > >= ; test d’appartenance : in',
                'logiques' => 'and or not',
                'nul' => '?? ; ternaire : condition ? a : b',
            ],
            'types' => [
                'null' => 'null',
                'bool' => 'true, false — seul type accepté par if/while',
                'int' => 'Entier 64 bits',
                'float' => '3.14',
                'string' => 'UTF-8 ; longueur en caractères avec str::length',
                'array' => 'Liste ou map, ordre d’insertion conservé',
                'date' => 'Créée par date::parse / date::now, formée par date::format',
            ],
            'functions' => 'Vous pouvez déclarer vos propres fonctions au niveau racine : '
                . 'function displayName($user) { return $user->firstName . " " . $user->lastName; } '
                . 'Elles sont hoistées et ne lisent pas les variables locales de main().',
            'contextVariables' => '$input (charge utile non fiable, une map), $principal (compte '
                . 'd’exécution), $space (Space courant), $run (identifiants du run). Lecture seule.',
            'resultShapes' => [
                'principe' => 'Une action renvoie une valeur simple : nombre, chaîne, booléen, ou un '
                    . 'tableau. Un tableau peut être une liste ([...], accès par position $t[0]) ou '
                    . 'une MAP, c’est-à-dire un tableau à clés nommées (["total" => 3]), dont on lit '
                    . 'un champ avec -> : $t->total.',
                'exempleUser' => 'core::getUser(1) renvoie une MAP : '
                    . 'id, spaceId, firstName, lastName, email, timezone, blocked, groups, roles. '
                    . 'On lit $user->firstName.',
                'exempleListUsers' => 'core::listUsers(1, 20, "", "user") renvoie une MAP : users '
                    . '(une liste d’utilisateurs), total, page, perPage. On lit $result->users, '
                    . 'et on boucle dessus avec foreach ($result->users as $user).',
                'avertissement' => 'La forme exacte de chaque action est donnée par '
                    . 'tycms.list_actions dans son champ returnType et son exemple d’appel. En cas '
                    . 'de doute, écrivez un premier programme qui renvoie simplement le résultat '
                    . 'brut pour voir sa forme.',
            ],
            'examples' => [
                [
                    'title' => 'Lister des utilisateurs',
                    'code' => "function main() {\n"
                        . "    \$users = core::listUsers(1, 20, \"\", \"user\");\n"
                        . "    return \$users;\n"
                        . '}',
                ],
                [
                    'title' => 'Lire une charge utile et construire une sortie',
                    'code' => "function main() {\n"
                        . "    \$email = \$input?->email ?? \"\";\n"
                        . "    \$label = str::upper(str::trim(\$email));\n"
                        . "    return [\"label\" => \$label, \"when\" => date::format(date::now(), \"Y-m-d H:i\")];\n"
                        . '}',
                ],
                [
                    'title' => 'Boucler sur une liste et sur une map',
                    'code' => "function main() {\n"
                        . "    \$noms = [];\n"
                        . "    \$users = core::listUsers(1, 100, \"\", \"all\");\n"
                        . "    foreach (\$users->users as \$user) {\n"
                        . "        \$noms = arr::push(\$noms, \$user->firstName . \" \" . \$user->lastName);\n"
                        . "    }\n"
                        . "    return [\"count\" => arr::length(\$noms), \"names\" => \$noms];\n"
                        . '}',
                ],
            ],
        ];
    }

    /**
     * Rendu Markdown du seul guide (architecture et syntaxe).
     *
     * Sert la version lisible par un humain ou un agent, sans le catalogue des
     * fonctions et actions, qui est propre au serveur MCP.
     */
    public static function markdown(): string
    {
        $architecture = self::architecture();
        $syntax = self::syntax();

        $lines = [
            '# TyCMS — guide d’écriture pour agent MCP',
            '',
            'Document servi par le serveur MCP TyCMS. Il ne suppose aucune connaissance '
                . 'préalable : lisez-le de haut en bas avant d’écrire un premier programme.',
            '',
            '## En bref',
            '',
            $architecture['overview'][0],
            '',
            $architecture['overview'][1],
            '',
            '## Le vocabulaire du serveur',
            '',
        ];

        foreach (array_slice($architecture['overview'], 2) as $item) {
            $lines[] = '- ' . $item;
        }

        $lines[] = '';
        $lines[] = '## Par où commencer';
        $lines[] = '';

        foreach ($architecture['howToProceed'] as $item) {
            $lines[] = $item;
        }

        $lines[] = '';
        $lines[] = '## Architecture d’exécution';
        $lines[] = '';

        foreach ($architecture['execution'] as $item) {
            $lines[] = '- ' . $item;
        }

        $lines[] = '';
        $lines[] = '### Budgets';
        $lines[] = '';
        $lines[] = '| Ressource | Limite |';
        $lines[] = '| --- | --- |';
        $lines[] = '| Taille de la source | ' . $architecture['budgets']['source'] . ' |';
        $lines[] = '| Durée d’exécution | ' . $architecture['budgets']['exercice'] . ' |';
        $lines[] = '| Boucles | ' . $architecture['budgets']['iterations'] . ' |';
        $lines[] = '| Profondeur d’appels | ' . $architecture['budgets']['callDepth'] . ' |';
        $lines[] = '| Appels d’action | ' . $architecture['budgets']['actionCalls'] . ' |';
        $lines[] = '| Sortie | ' . $architecture['budgets']['output'] . ' |';

        $lines[] = '';
        $lines[] = '## Syntaxe du langage';
        $lines[] = '';
        $lines[] = '### Point d’entrée';
        $lines[] = '';
        $lines[] = $syntax['entryPoint'];
        $lines[] = '';
        $lines[] = '### Ce qu’est une fonction';
        $lines[] = '';
        $lines[] = $syntax['whatIsAFunction'];
        $lines[] = '';
        $lines[] = '```text';
        $lines[] = 'function main() {';
        $lines[] = '    // votre code';
        $lines[] = '    return "résultat";';
        $lines[] = '}';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = $syntax['contents'];
        $lines[] = '';
        $lines[] = '### Le point-virgule';
        $lines[] = '';
        $lines[] = $syntax['semicolons'];
        $lines[] = '';
        $lines[] = 'Correct :';
        $lines[] = '';
        $lines[] = '```text';
        $lines[] = $syntax['semicolonExamples']['correct'];
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'Incorrect :';
        $lines[] = '';
        $lines[] = '```text';
        $lines[] = $syntax['semicolonExamples']['incorrect'];
        $lines[] = $syntax['semicolonExamples']['incorrectLast'];
        $lines[] = '```';

        foreach (['comments' => 'Commentaires', 'identifiers' => 'Identifiants', 'variables' => 'Variables',
            'callStyle' => 'Appel des actions', 'qualifiedCalls' => 'Fonctions qualifiées',
            'fieldAccess' => 'Accès aux champs', 'indexing' => 'Indexation'] as $key => $title) {
            $lines[] = '';
            $lines[] = '### ' . $title;
            $lines[] = '';

            if (is_array($syntax[$key])) {
                foreach ($syntax[$key] as $item) {
                    $lines[] = '- `' . $item . '`';
                }
            } else {
                $lines[] = $syntax[$key];
            }
        }

        $lines[] = '';
        $lines[] = '### Contrôle du flux';
        $lines[] = '';

        foreach ($syntax['controlFlow'] as $item) {
            $lines[] = '- `' . $item . '`';
        }

        $lines[] = '';
        $lines[] = '### Arrêt et valeur de retour';
        $lines[] = '';

        foreach ($syntax['returns'] as $item) {
            $lines[] = '- ' . $item;
        }

        $lines[] = '';
        $lines[] = '### Littéraux';
        $lines[] = '';
        $lines[] = '- **liste** : `' . $syntax['literals']['liste'] . '`';
        $lines[] = '- **map** : `' . $syntax['literals']['map'] . '`';
        $lines[] = '- **chaînes** : ' . $syntax['literals']['chaînes'];
        $lines[] = '- **nombres** : ' . $syntax['literals']['nombres'];
        $lines[] = '- **avertissement** : ' . $syntax['literals']['avertissement'];

        $lines[] = '';
        $lines[] = '### Opérateurs';
        $lines[] = '';

        foreach ($syntax['operators'] as $label => $item) {
            $lines[] = '- **' . $label . '** : `' . $item . '`';
        }

        $lines[] = '';
        $lines[] = '### Types';
        $lines[] = '';
        $lines[] = '| Type | Valeur |';
        $lines[] = '| --- | --- |';

        foreach ($syntax['types'] as $type => $description) {
            $lines[] = '| `' . $type . '` | ' . $description . ' |';
        }

        $lines[] = '';
        $lines[] = '### Fonctions écrites par vous';
        $lines[] = '';
        $lines[] = '```text';
        $lines[] = 'function displayName($user) {';
        $lines[] = '    return $user->firstName . " " . $user->lastName;';
        $lines[] = '}';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = $syntax['functions'];
        $lines[] = '';
        $lines[] = '### Variables de contexte';
        $lines[] = '';
        $lines[] = $syntax['contextVariables'];
        $lines[] = '';
        $lines[] = '## Ce que renvoie une action';
        $lines[] = '';
        $lines[] = $syntax['resultShapes']['principe'];
        $lines[] = '';
        $lines[] = '- ' . $syntax['resultShapes']['exempleUser'];
        $lines[] = '- ' . $syntax['resultShapes']['exempleListUsers'];
        $lines[] = '';
        $lines[] = '> ' . $syntax['resultShapes']['avertissement'];
        $lines[] = '';
        $lines[] = '## Exemples complets et exécutables';
        $lines[] = '';

        foreach ($syntax['examples'] as $index => $example) {
            $lines[] = '### ' . ($index + 1) . '. ' . $example['title'];
            $lines[] = '';
            $lines[] = '```text';
            array_push($lines, ...explode("\n", $example['code']));
            $lines[] = '```';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
