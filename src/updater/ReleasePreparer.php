<?php

declare(strict_types=1);

namespace Tycms\Updater;

use RuntimeException;
use ZipArchive;

require_once __DIR__ . '/SourceConfiguration.php';

/** Prépare les fichiers de la release cible en une seule requête. */
final class ReleasePreparer
{
    private const MAX_ZIP_BYTES = 134217728;
    public const OWNER_FILE = '.tycms-update-owner';

    public function __construct(private readonly OperationStore $store)
    {
    }

    /** La progression est enregistrée avant chaque opération susceptible d'être interrompue. */
    public function prepare(array $snapshot): array
    {
        $operation = $snapshot['operation'];
        $progress = $snapshot['progress'];
        $root = $this->store->root();
        $sourceName = $snapshot['state']['active_release'];
        $releaseName = 'v' . str_replace('.', '_', $operation['version']);
        $assetName = 'assets_core_' . $releaseName;
        $work = $root . '/var/updates/' . $operation['id'];
        $stage = $work . '/stage';
        $release = $root . '/releases/' . $releaseName;
        $assets = $root . '/public/' . $assetName;
        $packageDirectory = $root . '/var/updates/packages/' . $operation['package_id'];
        $zipPath = $packageDirectory . '/package.zip';

        if ((!$operation['development'] && $sourceName !== 'v' . str_replace('.', '_', $operation['source_version']))
            || $sourceName === $releaseName) {
            throw new RuntimeException('La release source ne correspond pas à l’opération.');
        }
        foreach ([$root . '/releases', $root . '/releases/' . $sourceName,
            $root . '/var/updates/packages', $packageDirectory] as $path) {
            $this->existingDirectory($path);
        }
        $sourceConfig = SourceConfiguration::path($root, $sourceName);
        $this->regularFile($sourceConfig);
        $this->regularFile($zipPath);
        $size = filesize($zipPath);
        $digest = hash_file('sha256', $zipPath);
        if ($size === false || $size > self::MAX_ZIP_BYTES || !is_string($digest)
            || !hash_equals($operation['sha256'], $digest)) {
            throw new RuntimeException('Le paquet préparé a changé.');
        }

        if ($progress === []) {
            if (file_exists($release) || is_link($release) || file_exists($assets) || is_link($assets)
                || file_exists($work) || is_link($work)) {
                throw new RuntimeException('La cible ou son espace de préparation existe déjà.');
            }
            $progress = ['operation_id' => $operation['id'], 'phase' => 'copying', 'source_release' => $sourceName, 'owns_targets' => true];
            $this->store->saveProgress($progress);
        }
        if (($progress['source_release'] ?? null) !== $sourceName
            || !in_array($progress['phase'] ?? null, ['copying', 'publishing', 'ready'], true)) {
            throw new RuntimeException('Préparation indisponible à cette étape.');
        }
        if ($progress['phase'] === 'ready') return $progress;

        if ($progress['phase'] === 'copying') {
            // Les dossiers de travail sont propres à l'opération et peuvent être repris après interruption.
            $this->directory($work);
            $this->directory($stage);
            $this->directory($stage . '/release');
            $this->directory($stage . '/assets', false);
            $zip = new ZipArchive();
            if (@$zip->open($zipPath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('Le paquet préparé est illisible.');
            }
            try {
                $entries = $this->entries($zip, $releaseName, $assetName);
                foreach ($entries as [$index, $name, $destination, $entrySize, $crc]) {
                    $this->copyEntry($zip, $index, $name, $stage . '/' . $destination, $entrySize, $crc);
                }
            } finally {
                $zip->close();
            }

            // Copier la configuration existante sans l'exécuter ni modifier la source.
            $targetConfig = $stage . '/release/configuration.php';
            if (!file_exists($targetConfig) && !is_link($targetConfig)) {
                $temporary = $stage . '/release/.configuration-' . bin2hex(random_bytes(16));
                if (!@copy($sourceConfig, $temporary) || !@chmod($temporary, 0600)
                    || !@rename($temporary, $targetConfig)) {
                    @unlink($temporary);
                    throw new RuntimeException('Impossible de copier la configuration locale.');
                }
            }
            $this->regularFile($targetConfig);
            $sourceHash = hash_file('sha256', $sourceConfig);
            $targetHash = hash_file('sha256', $targetConfig);
            if (!is_string($sourceHash) || !is_string($targetHash) || !hash_equals($sourceHash, $targetHash)) {
                throw new RuntimeException('La configuration locale a changé pendant la préparation.');
            }
            foreach ([$stage . '/release', $stage . '/assets'] as $folder) {
                $marker = $folder . '/' . self::OWNER_FILE;
                if (is_link($marker) || (file_exists($marker) && (!is_file($marker) || file_get_contents($marker) !== $operation['id']))) {
                    throw new RuntimeException('Marque de préparation invalide.');
                }
                if (!file_exists($marker) && file_put_contents($marker, $operation['id'], LOCK_EX) !== strlen($operation['id'])) {
                    throw new RuntimeException('Impossible de marquer les fichiers préparés.');
                }
            }
            $progress['phase'] = 'publishing';
            $this->store->saveProgress($progress);
        }

        // Les deux dossiers sont publiés séparément ; leur marque évite de confondre une cible préexistante avec la nôtre.
        $this->publish($stage . '/release', $release, $operation['id']);
        $this->publish($stage . '/assets', $assets, $operation['id']);
        $progress['phase'] = 'ready';
        $this->store->saveProgress($progress);
        return $progress;
    }

    private function existingDirectory(string $path): void
    {
        if (is_link($path) || !is_dir($path) || realpath($path) !== $path) {
            throw new RuntimeException('Dossier de mise à jour inaccessible.');
        }
    }

    /** @return list<array{int,string,string,int,int}> */
    private function entries(ZipArchive $zip, string $releaseName, string $assetName): array
    {
        if ($zip->numFiles > 100000) {
            throw new RuntimeException('Paquet trop volumineux.');
        }
        $entries = [];
        $seen = [];
        $total = 0;
        $nameBytes = 0;
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index);
            if ($stat === false || !$zip->getExternalAttributesIndex($index, $system, $attributes)) {
                throw new RuntimeException('Entrée ZIP illisible.');
            }
            if (!is_int($stat['size']) || $stat['size'] < 0 || $stat['size'] > 1073741824) {
                throw new RuntimeException('Taille ZIP invalide.');
            }
            $name = $stat['name'];
            $trimmed = rtrim($name, '/');
            $parts = explode('/', $trimmed);
            $nameBytes += strlen($name);
            $total += $stat['size'];
            $mode = ($attributes >> 16) & 0170000;
            if ($name === '' || strlen($name) > 1024 || str_contains($name, '\\')
                || preg_match('/[\x00-\x1f\x7f:]/', $name) || in_array('', $parts, true)
                || in_array('.', $parts, true) || in_array('..', $parts, true)
                || isset($seen[$trimmed]) || !in_array($mode, [0, 0100000, 0040000], true)
                || $total > 1073741824 || $nameBytes > 16777216 || count($parts) > 64
                || ($stat['encryption_method'] ?? 0) !== 0) {
                throw new RuntimeException('Entrée ZIP interdite.');
            }
            $seen[$trimmed] = true;
            if (str_ends_with($name, '/')) {
                if ($mode === 0100000) throw new RuntimeException('Type ZIP invalide.');
                continue;
            }
            if ($mode === 0040000) throw new RuntimeException('Type ZIP invalide.');

            $releasePrefix = 'releases/' . $releaseName . '/';
            $assetPrefix = 'public/' . $assetName . '/';
            if (str_starts_with($name, $releasePrefix)) {
                $destination = 'release/' . substr($name, strlen($releasePrefix));
            } elseif (str_starts_with($name, $assetPrefix)) {
                $destination = 'assets/' . substr($name, strlen($assetPrefix));
            } else {
                continue;
            }
            if (in_array($destination, ['release/configuration.php', 'release/' . self::OWNER_FILE, 'assets/' . self::OWNER_FILE], true)) {
                throw new RuntimeException('Le paquet contient une configuration locale.');
            }
            $entries[] = [$index, $name, $destination, $stat['size'], $stat['crc']];
        }
        foreach (['release/index.php', 'release/version.php', 'release/vendor/autoload.php'] as $required) {
            if (!in_array($required, array_column($entries, 2), true)) {
                throw new RuntimeException('Release cible incomplète.');
            }
        }
        if (!array_filter($entries, static fn (array $entry): bool => str_starts_with($entry[2], 'assets/'))) {
            throw new RuntimeException('Assets de la release cible absents.');
        }
        return $entries;
    }

    private function copyEntry(ZipArchive $zip, int $index, string $name, string $destination,
        int $expectedSize, int $expectedCrc): void
    {
        $parent = dirname($destination);
        if (!is_dir($parent)) {
            $this->mkdirParents($parent);
        }
        $this->directory($parent, str_contains($destination, '/release/'));
        if (is_link($destination)) {
            throw new RuntimeException('Lien symbolique interdit pendant la copie.');
        }
        if (file_exists($destination)) {
            $crc = @hash_file('crc32b', $destination);
            if (is_file($destination) && filesize($destination) === $expectedSize
                && is_string($crc) && hash_equals(sprintf('%08x', $expectedCrc), $crc)) {
                return;
            }
            if (!is_file($destination) || !@unlink($destination)) {
                throw new RuntimeException('Copie ZIP partielle impossible à reprendre.');
            }
        }
        $input = $zip->getStream($name);
        $output = @fopen($destination, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            throw new RuntimeException('Impossible de copier un fichier de la release.');
        }
        $crc = hash_init('crc32b');
        $size = 0;
        try {
            if (!@chmod($destination, 0600)) throw new RuntimeException('Permissions de copie invalides.');
            while (!feof($input)) {
                $bytes = fread($input, 65536);
                if ($bytes === false || ($bytes === '' && !feof($input))) {
                    throw new RuntimeException('Lecture ZIP incomplète.');
                }
                $size += strlen($bytes);
                if ($size > $expectedSize || fwrite($output, $bytes) !== strlen($bytes)) {
                    throw new RuntimeException('Copie ZIP incomplète.');
                }
                hash_update($crc, $bytes);
            }
            if ($size !== $expectedSize || hash_final($crc) !== sprintf('%08x', $expectedCrc)
                || !fflush($output)) {
                throw new RuntimeException('Intégrité du fichier ZIP invalide.');
            }
        } catch (\Throwable $error) {
            fclose($input);
            fclose($output);
            @unlink($destination);
            throw $error;
        }
        fclose($input);
        fclose($output);
        if (str_contains($destination, '/assets/') && !@chmod($destination, 0644)) {
            throw new RuntimeException('Permissions des assets invalides.');
        }
    }

    private function mkdirParents(string $path): void
    {
        if (is_dir($path)) {
            $this->directory($path, false);
            return;
        }
        $this->mkdirParents(dirname($path));
        $this->directory($path, !str_contains($path, '/assets/'));
    }

    private function publish(string $source, string $destination, string $operationId): void
    {
        if (is_link($source) || is_link($destination)) throw new RuntimeException('Lien symbolique interdit pendant la publication.');
        if (!file_exists($source) && is_dir($destination)) {
            self::assertOwner($destination, $operationId);
            return;
        }
        self::assertOwner($source, $operationId);
        if (file_exists($destination) || !@rename($source, $destination)) {
            throw new RuntimeException('Publication de la release incomplète.' . 
            "\n$source => $destination");
        }
    }

    public static function assertOwner(string $path, string $operationId): void
    {
        $marker = $path . '/' . self::OWNER_FILE;
        if (is_link($path) || !is_dir($path) || realpath($path) !== $path
            || is_link($marker) || !is_file($marker) || file_get_contents($marker) !== $operationId) {
            throw new RuntimeException('Les fichiers préparés ne correspondent pas à cette opération.');
        }
    }

    private function regularFile(string $path): void
    {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Fichier de mise à jour invalide.');
        }
    }

    private function directory(string $path, bool $private = true): void
    {
        if (is_link($path) || (!is_dir($path) && !@mkdir($path, $private ? 0700 : 0755))
            || realpath($path) !== $path) {
            throw new RuntimeException('Dossier de mise à jour invalide.');
        }
    }
}
