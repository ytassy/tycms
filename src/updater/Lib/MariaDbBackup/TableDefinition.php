<?php

declare(strict_types=1);

namespace Tycms\Updater\Lib\MariaDbBackup;

use RuntimeException;

/** Sépare les contraintes différées dans le CREATE TABLE normalisé par MariaDB. */
final class TableDefinition
{
    /** @return array{create:string, constraints:list<string>} */
    public static function split(string $sql, string $table): array
    {
        $open = self::findTopLevel($sql, '(');
        if ($open === null) throw new RuntimeException('Définition de table non reconnue.');
        $close = self::matchingParenthesis($sql, $open);
        $body = substr($sql, $open + 1, $close - $open - 1);
        $parts = self::splitParts($body);
        $kept = [];
        $constraints = [];
        foreach ($parts as $part) {
            $item = trim($part);
            if ($item === '') throw new RuntimeException('Définition de table invalide.');
            if (preg_match('/\A(?:CONSTRAINT\s+(?:`(?:``|[^`])+`|[a-zA-Z0-9_$]+)\s+)?(?:FOREIGN\s+KEY\b|CHECK\s*\()/i', $item) === 1) {
                $constraints[] = 'ALTER TABLE ' . self::quote($table) . ' ADD ' . $item;
                continue;
            }
            // MariaDB conserve certains CHECK sur la ligne de la colonne.
            if (preg_match('/\A`((?:``|[^`])+)`/', $item, $match) === 1) {
                $position = self::findTopLevel($item, 'CHECK');
                if ($position !== null) {
                    $paren = self::findTopLevel(substr($item, $position + 5), '(');
                    if ($paren === null) throw new RuntimeException('Contrainte CHECK invalide.');
                    $paren += $position + 5;
                    $end = self::matchingParenthesis($item, $paren);
                    $column = str_replace('``', '`', $match[1]);
                    $constraints[] = 'ALTER TABLE ' . self::quote($table) . ' ADD CONSTRAINT '
                        . self::quote($column) . ' ' . substr($item, $position, $end - $position + 1);
                    $item = rtrim(substr($item, 0, $position)) . substr($item, $end + 1);
                    if (self::findTopLevel($item, 'CHECK') !== null) {
                        throw new RuntimeException('Plusieurs CHECK de colonne ne sont pas pris en charge.');
                    }
                }
            }
            $kept[] = $item;
        }
        if ($kept === []) throw new RuntimeException('Définition de table vide.');
        return ['create' => substr($sql, 0, $open + 1) . "\n  " . implode(",\n  ", $kept)
            . "\n" . substr($sql, $close), 'constraints' => $constraints];
    }

    private static function splitParts(string $body): array
    {
        $result = [];
        while (($position = self::findTopLevel($body, ',')) !== null) {
            $result[] = substr($body, 0, $position);
            $body = substr($body, $position + 1);
        }
        $result[] = $body;
        return $result;
    }

    private static function matchingParenthesis(string $text, int $open): int
    {
        $position = self::findTopLevel(substr($text, $open + 1), ')');
        if ($position === null) throw new RuntimeException('Parenthèse SQL non refermée.');
        return $open + 1 + $position;
    }

    /** Cherche un séparateur hors des chaînes, commentaires et parenthèses imbriquées. */
    private static function findTopLevel(string $text, string $needle): ?int
    {
        $length = strlen($text);
        $depth = 0;
        $quote = null;
        $block = false;
        $line = false;
        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];
            $next = $text[$i + 1] ?? '';
            if ($line) { if ($char === "\n") $line = false; continue; }
            if ($block) { if ($char === '*' && $next === '/') { $block = false; ++$i; } continue; }
            if ($quote !== null) {
                if ($char === '\\' && $quote !== '`' && $next !== '') { ++$i; continue; }
                if ($char === $quote) { if ($next === $quote) ++$i; else $quote = null; }
                continue;
            }
            if ($char === '/' && $next === '*') { $block = true; ++$i; continue; }
            if ($char === '#' || ($char === '-' && $next === '-' && isset($text[$i + 2]) && ctype_space($text[$i + 2]))) { $line = true; continue; }
            if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; continue; }
            if ($depth === 0 && strncasecmp(substr($text, $i, strlen($needle)), $needle, strlen($needle)) === 0) {
                if ($needle !== 'CHECK' || (($i === 0 || !preg_match('/[a-zA-Z0-9_$]/', $text[$i - 1]))
                    && !preg_match('/[a-zA-Z0-9_$]/', $text[$i + 5] ?? ' '))) return $i;
            }
            if ($char === '(') ++$depth;
            elseif ($char === ')') {
                if ($depth === 0) throw new RuntimeException('Parenthèse SQL inattendue.');
                --$depth;
            }
        }
        if ($depth !== 0 || $quote !== null || $block) throw new RuntimeException('Définition SQL incomplète.');
        return null;
    }

    private static function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
