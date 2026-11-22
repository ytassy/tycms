<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Reference;

/** Rendu Markdown de la référence du langage et des actions. */
final class AutomationReferenceDocument
{
    /**
     * @param list<array{namespace: string, id: string, signature: string, description: string, effect: string}> $builtins
     * @param list<array<string, mixed>> $actions
     * @param list<array<string, mixed>> $context
     * @param list<array<string, mixed>> $triggers
     * @param list<array{id: string, signature: string, description: string}> $statements
     */
    public static function markdown(
        array $builtins,
        array $actions,
        array $context,
        array $triggers,
        array $statements = [],
    ): string {
        $lines = [
            '# Automatisation — référence',
            '',
            'Document généré par TyCMS. Les signatures, les paramètres et les permissions proviennent du moteur.',
            '',
        ];

        // Les instructions de contrôle sont des mots-clés, pas des fonctions :
        // elles sont présentées avant le catalogue des namespaces.
        if ($statements !== []) {
            $lines[] = '## Contrôle du flux';
            $lines[] = '';

            foreach ($statements as $statement) {
                $lines[] = '- `' . $statement['signature'] . '` — '
                    . self::cell($statement['description']);
            }

            $lines[] = '';
        }

        $lines[] = '## Fonctions standard';
        $lines[] = '';

        $namespace = null;

        foreach ($builtins as $builtin) {
            if ($builtin['namespace'] !== $namespace) {
                $namespace = $builtin['namespace'];
                $lines[] = '### ' . $namespace;
                $lines[] = '';
            }

            $effect = $builtin['effect'] !== 'pure' ? ' _(effet : `' . $builtin['effect'] . '`)_' : '';
            $lines[] = '- `' . $builtin['signature'] . '` — ' . self::cell($builtin['description']) . $effect;
        }

        $lines[] = '';
        $lines[] = '## Actions';
        $lines[] = '';

        foreach ($actions as $action) {
            $lines[] = '### `' . $action['id'] . '` — ' . self::cell((string) $action['label']);
            $lines[] = '';
            $lines[] = self::cell((string) $action['description']);
            $lines[] = '';
            $lines[] = '- Effet : `' . $action['effect'] . '`';
            $lines[] = '- Retour : `' . $action['return_type'] . '`';

            if ($action['required_permissions'] !== []) {
                $lines[] = '- Permissions : ' . implode(', ', array_map(
                    static fn (string $permission): string => '`' . $permission . '`',
                    $action['required_permissions'],
                ));
            }

            if ($action['parameters'] !== []) {
                $lines[] = '';
                $lines[] = '| Paramètre | Type | Requis | Défaut | Description |';
                $lines[] = '| --- | --- | --- | --- | --- |';

                foreach ($action['parameters'] as $parameter) {
                    $lines[] = '| `' . $parameter['name'] . '` | `' . $parameter['type'] . '` | '
                        . ($parameter['required'] ? 'oui' : 'non') . ' | '
                        . self::defaultCell($parameter['default']) . ' | '
                        . self::cell((string) ($parameter['description'] ?: $parameter['label'])) . ' |';
                }
            }

            $lines[] = '';
        }

        $lines[] = '## Variables de contexte';
        $lines[] = '';
        $lines[] = '`$input` dépend du déclencheur ; sa charge utile est décrite dans la section « Déclencheurs ».';
        $lines[] = '';

        foreach ($context as $variable) {
            $lines[] = '### `' . $variable['name'] . '`';
            $lines[] = '';
            $lines[] = self::cell((string) $variable['description']);
            $lines[] = '';
            $lines[] = 'Lecture seule : oui.';

            if ($variable['fields'] !== []) {
                $lines[] = '';
                $lines[] = '| Champ | Type | Requis | Description |';
                $lines[] = '| --- | --- | --- | --- |';

                foreach ($variable['fields'] as $field) {
                    $lines[] = '| `' . $field['name'] . '` | `' . $field['type'] . '` | '
                        . ($field['required'] ? 'oui' : 'non') . ' | '
                        . self::cell((string) $field['description']) . ' |';
                }
            }

            if (is_array($variable['example'] ?? null)) {
                $lines[] = '';
                $lines[] = '```json';
                array_push($lines, ...explode("\n", self::json($variable['example'])));
                $lines[] = '```';
            }

            $lines[] = '';
        }

        $lines[] = '## Déclencheurs';
        $lines[] = '';

        foreach ($triggers as $trigger) {
            $lines[] = '### `' . $trigger['id'] . '` — ' . self::cell((string) $trigger['label']);
            $lines[] = '';
            $lines[] = self::cell((string) $trigger['description']);
            $lines[] = '';
            $lines[] = 'Identité par défaut : `' . $trigger['default_principal_mode'] . '`.';

            if ($trigger['payload_fields'] !== []) {
                $lines[] = '';
                $lines[] = '| Champ de `$input` | Type | Requis | Description |';
                $lines[] = '| --- | --- | --- | --- |';

                foreach ($trigger['payload_fields'] as $field) {
                    $lines[] = '| `' . $field['name'] . '` | `' . $field['type'] . '` | '
                        . ($field['required'] ? 'oui' : 'non') . ' | '
                        . self::cell((string) $field['description']) . ' |';
                }
            }

            if (is_array($trigger['payload_example'] ?? null)) {
                $lines[] = '';
                $lines[] = '```json';
                array_push($lines, ...explode("\n", self::json($trigger['payload_example'])));
                $lines[] = '```';
            }

            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    private static function cell(string $value): string
    {
        return trim(str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $value));
    }

    private static function defaultCell(mixed $default): string
    {
        if ($default === null) {
            return '';
        }

        if (is_string($default)) {
            return $default === '' ? '`""`' : '`' . self::cell($default) . '`';
        }

        return '`' . self::cell(self::json($default)) . '`';
    }

    private static function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
