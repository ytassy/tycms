<?php

declare(strict_types=1);

namespace Tycms\Updater;

use RuntimeException;

/** Bascule uniquement la valeur littérale de la propriété Configuration::$offline. */
final class Maintenance
{
    public static function setOffline(string $path, bool $offline): void
    {
        [$source, $tokens, $offsets, $value] = self::readProperty($path);
        $wanted = $offline ? 'true' : 'false';
        if (strtolower($tokens[$value][1]) === $wanted) {
            self::invalidateCache($path);
            return;
        }
        $updated = substr_replace($source, $wanted, $offsets[$value], strlen($tokens[$value][1]));
        $temporary = dirname($path) . '/.configuration-' . bin2hex(random_bytes(16));
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('Impossible de préparer la maintenance.');
        try {
            if (!@chmod($temporary, 0600) || fwrite($handle, $updated) !== strlen($updated)
                || !fflush($handle)) {
                throw new RuntimeException('Écriture de maintenance incomplète.');
            }
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($temporary);
            throw $error;
        }
        fclose($handle);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Impossible de publier la maintenance.');
        }
        self::invalidateCache($path);
    }

    public static function isOffline(string $path): bool
    {
        [, $tokens, , $value] = self::readProperty($path);
        return strtolower($tokens[$value][1]) === 'true';
    }

    /** Lire la propriété sans exécuter la configuration de la release. */
    private static function readProperty(string $path): array
    {
        if (is_link($path) || !is_file($path) || ($size = @filesize($path)) === false
            || $size > 1048576) {
            throw new RuntimeException('Configuration locale inaccessible.');
        }
        $source = @file_get_contents($path);
        if (!is_string($source)) throw new RuntimeException('Configuration locale illisible.');

        // Un remplacement par tokens évite de modifier une chaîne ou un commentaire PHP.
        $tokens = token_get_all($source, TOKEN_PARSE);
        $offsets = [];
        $offset = 0;
        foreach ($tokens as $token) {
            $offsets[] = $offset;
            $offset += strlen(is_array($token) ? $token[1] : $token);
        }
        $matches = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$offline') continue;
            $before = self::significant($tokens, $index, -1);
            if ($before === null) continue;
            $previous = $tokens[$before];
            if (is_array($previous) && $previous[0] === T_STRING && strtolower($previous[1]) === 'bool') {
                $before = self::significant($tokens, $before, -1);
                $previous = $before === null ? null : $tokens[$before];
            }
            if (!is_array($previous) || !in_array($previous[0], [T_PUBLIC, T_VAR], true)) continue;

            $equals = self::significant($tokens, $index, 1);
            $value = $equals === null ? null : self::significant($tokens, $equals, 1);
            $end = $value === null ? null : self::significant($tokens, $value, 1);
            if ($equals === null || $tokens[$equals] !== '=' || $value === null
                || !is_array($tokens[$value]) || $tokens[$value][0] !== T_STRING
                || !in_array(strtolower($tokens[$value][1]), ['true', 'false'], true)
                || $end === null || $tokens[$end] !== ';') {
                throw new RuntimeException('La propriété offline doit être un booléen littéral.');
            }
            $matches[] = $value;
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('La configuration doit déclarer une seule propriété offline.');
        }

        return [$source, $tokens, $offsets, $matches[0]];
    }

    private static function significant(array $tokens, int $index, int $direction): ?int
    {
        for ($i = $index + $direction; isset($tokens[$i]); $i += $direction) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    private static function invalidateCache(string $path): void
    {
        if (function_exists('opcache_get_status') && @opcache_get_status(false) !== false
            && !@opcache_invalidate($path, true)) {
            throw new RuntimeException('Impossible d’invalider le cache PHP de la configuration.');
        }
    }
}
