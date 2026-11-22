<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Registry;

/**
 * Documentation statique des instructions de contrôle du langage.
 *
 * `stop` et `fail` sont des mots-clés du langage, pas des fonctions du
 * catalogue d'exécution : ils ne suivent donc pas la syntaxe `namespace::nom`.
 */
final class BuiltinStatementReference
{
    /**
     * @var list<array{id: string, signature: string, description: string}>
     */
    private const STATEMENTS = [
        [
            'id' => 'stop',
            'signature' => 'stop(?string $message = null)',
            'description' => 'Arrête immédiatement le scénario avec succès. Le message facultatif devient le message du run.',
        ],
        [
            'id' => 'fail',
            'signature' => 'fail(string $message)',
            'description' => 'Arrête immédiatement le scénario en échec, enregistre le message et déclenche la notification d’échec configurée.',
        ],
    ];

    /**
     * @return list<array{id: string, signature: string, description: string}>
     */
    public static function all(): array
    {
        return self::STATEMENTS;
    }

    private function __construct()
    {
    }
}
