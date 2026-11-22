<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate;

use RuntimeException;
use ZipArchive;

/** Contrôle structurel du ZIP système ; aucune extraction ni exécution du PHP embarqué. */
final class ArchiveInspector
{
    public static function inspect(string $path, string $version): void
    {
        $suffix = str_replace('.', '_', ManifestVerifier::version($version));
        $zip = new ZipArchive();
        if (@$zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Archive ZIP système invalide.');
        }
        try {
            if ($zip->numFiles > 100000) {
                throw new RuntimeException('Trop de fichiers dans le ZIP système.');
            }
            $files = [];
            $total = 0;
            $nameBytes = 0;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);
                if ($stat === false || !$zip->getExternalAttributesIndex($index, $system, $attributes)) {
                    throw new RuntimeException('Entrée ZIP illisible.');
                }
                $name = $stat['name'];
                $normalized = rtrim($name, '/');
                $parts = explode('/', $normalized);
                if (!is_int($stat['size']) || $stat['size'] < 0 || $stat['size'] > 1073741824) {
                    throw new RuntimeException('Taille décompressée déclarée invalide dans le ZIP.');
                }
                $total += $stat['size'];
                $nameBytes += strlen($name);
                $mode = ($attributes >> 16) & 0170000;
                if ($name === '' || strlen($name) > 1024 || str_contains($name, '\\') || str_contains($name, '//')
                    || preg_match('/[\x00-\x1f\x7f:]/', $name) || in_array('', $parts, true)
                    || in_array('..', $parts, true) || in_array('.', $parts, true)
                    || array_key_exists($normalized, $files) || !in_array($mode, [0, 0100000, 0040000], true)
                    || $total > 1073741824 || ($stat['encryption_method'] ?? 0) !== 0
                    || $nameBytes > 16777216 || count($parts) > 64
                    || ($mode === 0040000 && !str_ends_with($name, '/'))
                    || ($mode === 0100000 && str_ends_with($name, '/'))) {
                    throw new RuntimeException('Chemin, lien ou contenu ZIP interdit.');
                }
                $files[$normalized] = !str_ends_with($name, '/');
                if (($parts[0] === 'releases' && isset($parts[1]) && $parts[1] !== 'v' . $suffix)
                    || !in_array($parts[0], ['releases', 'public', 'extensions', 'updater', 'var', 'logs', 'tmp'], true)
                    || in_array('configuration.php', $parts, true)
                    || in_array('node_modules', $parts, true) || in_array('.git', $parts, true)) {
                    throw new RuntimeException('Release inattendue ou fichier local interdit dans le ZIP.');
                }
            }

            // Refuser un fichier utilisé comme dossier, y compris lorsque les entrées sont désordonnées.
            foreach ($files as $name => $_) {
                $parent = dirname($name);
                while ($parent !== '.') {
                    if (($files[$parent] ?? false) === true) {
                        throw new RuntimeException('Collision entre un fichier et un dossier dans le ZIP.');
                    }
                    $parent = dirname($parent);
                }
            }
            $root = 'releases/v' . $suffix;
            foreach ([$root . '/index.php', $root . '/version.php', $root . '/configuration.base.php',
                $root . '/composer.json', $root . '/composer.lock', $root . '/vendor/autoload.php',
                'public/index.php', 'public/.htaccess', 'updater/index.php', 'var/releases-states.json'] as $required) {
                if (($files[$required] ?? false) !== true) {
                    throw new RuntimeException('Archive système incomplète.');
                }
            }
            $stat = $zip->statName($root . '/version.php');
            if ($stat === false || $stat['size'] > 8192) {
                throw new RuntimeException('Fichier de version ZIP invalide.');
            }
            $php = $zip->getFromName($root . '/version.php', 8193);
            if (!is_string($php) || strlen($php) > 8192) {
                throw new RuntimeException('Fichier de version ZIP illisible.');
            }
            foreach (['APP_VERSION' => $version, 'APP_VERSION_STRING' => $suffix] as $constant => $expected) {
                preg_match_all("/^[\\t ]*define\\([\\t ]*'" . $constant . "'[\\t ]*,[\\t ]*'([^']+)'[\\t ]*\\)[\\t ]*;/m", $php, $matches);
                if (count($matches[1]) !== 1 || $matches[1][0] !== $expected) {
                    throw new RuntimeException('Version du ZIP différente du manifeste signé.');
                }
            }
        } finally {
            $zip->close();
        }
    }
}
