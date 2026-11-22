<?php

declare(strict_types=1);

namespace Tycms\Updater;

use RuntimeException;

/** Résoudre la configuration de la release source. */
final class SourceConfiguration
{
    public static function path(string $root, string $release): string
    {
        $releases = $root . '/releases';
        $directory = $releases . '/' . $release;
        if (is_link($releases) || realpath($releases) !== $releases
            || is_link($directory) || realpath($directory) !== $directory) {
            throw new RuntimeException('Release source inaccessible.');
        }
        return $directory . '/configuration.php';
    }
}
