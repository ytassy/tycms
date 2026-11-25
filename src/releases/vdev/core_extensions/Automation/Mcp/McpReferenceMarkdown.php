<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Mcp;

/**
 * Rendu Markdown de la référence MCP complète.
 *
 * L'agent reçoit le même contenu par `tycms.reference` en JSON ; ce rendu sert
 * la lecture humaine et une copie téléchargeable, avec les exemples d'appel
 * positionnels générés depuis le registre.
 */
final class McpReferenceMarkdown
{
    /**
     * @param array<string, mixed> $reference Sortie de `McpToolCatalog::reference()`.
     */
    public static function render(array $reference): string
    {
        $lines = [
            '# TyCMS — référence du serveur MCP',
            '',
            'Document généré par le serveur MCP. Les signatures, paramètres et '
                . 'permissions proviennent du registre d’actions.',
            '',
            McpLanguageGuide::markdown(),
            '',
            McpDomainGuide::markdown(),
            '',
            '## Fonctions standard',
            '',
            'Les signatures ci-dessous sont descriptives : les arguments se passent '
                . 'toujours positionnellement et dans l’ordre indiqué.',
            '',
        ];

        $namespace = null;

        foreach ($reference['functions'] ?? [] as $function) {
            if (($function['namespace'] ?? '') !== $namespace) {
                $namespace = $function['namespace'];
                $lines[] = '### `' . $namespace . '`';
                $lines[] = '';
            }

            $lines[] = '- `' . $function['signature'] . '` — ' . self::cell($function['description']);
        }

        $lines[] = '';
        $lines[] = '## Actions métier';
        $lines[] = '';
        $lines[] = 'Les actions à effet `external` sont absentes : le serveur refuse de les dispatcher.';
        $lines[] = '';

        foreach ($reference['actions'] ?? [] as $action) {
            $lines[] = '### ' . $action['id'];
            $lines[] = '';
            $lines[] = self::cell((string) $action['description']);
            $lines[] = '';
            $lines[] = '- Effet : `' . $action['effect'] . '`';
            $lines[] = '- Retour : `' . $action['returnType'] . '`';
            $lines[] = '- Appel : `' . $action['call'] . '`';

            if (($action['requiredPermissions'] ?? []) !== []) {
                $lines[] = '- Permissions : ' . implode(', ', array_map(
                    static fn (string $permission): string => '`' . $permission . '`',
                    $action['requiredPermissions'],
                ));
            }

            if (($action['parameters'] ?? []) !== []) {
                $lines[] = '';
                $lines[] = '| Position | Paramètre | Type | Requis | Défaut | Description |';
                $lines[] = '| --- | --- | --- | --- | --- | --- |';

                foreach ($action['parameters'] as $index => $parameter) {
                    $lines[] = '| ' . ($index + 1) . ' | `' . $parameter['name'] . '` | `'
                        . $parameter['type'] . '` | ' . ($parameter['required'] ? 'oui' : 'non') . ' | '
                        . self::defaultCell($parameter['default']) . ' | '
                        . self::cell((string) ($parameter['description'] ?: $parameter['label'])) . ' |';
                }
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
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

        return '`' . self::cell(json_encode($default, JSON_UNESCAPED_UNICODE) ?: '') . '`';
    }
}
