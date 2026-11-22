<?php

declare(strict_types=1);

namespace Tycms\Updater;

use JsonException;
use RuntimeException;

/** État privé de l'opération, indépendant des classes de toute release. */
final class OperationStore
{
    private const MAX_STATE_BYTES = 65536;

    private readonly string $root;
    private readonly string $releaseStatePath;
    private readonly string $progressPath;
    private readonly string $lockPath;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real) || is_link($root)) {
            throw new RuntimeException('Installation inaccessible.');
        }

        $this->root = $real;
        $this->assertDirectory($real . '/var');
        $this->assertDirectory($real . '/var/updates');
        $this->assertDirectory($real . '/public');

        $this->releaseStatePath = $real . '/var/releases-states.json';
        $this->progressPath = $real . '/var/last_update_data.json';
        $this->lockPath = $real . '/var/updates/.prepare.lock';
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return array{state:array, operation:array, progress:array} */
    public function snapshot(string $entrypoint): array
    {
        if (preg_match('/\Aupdate_[a-f0-9]{64}\.php\z/', $entrypoint) !== 1) {
            throw new RuntimeException('Opération indisponible.');
        }

        $state = $this->readJson($this->releaseStatePath);
        $operation = $state['active_operation'] ?? null;
        if (($state['schema_version'] ?? null) !== 1 || !is_array($operation)
            || ($operation['status'] ?? null) !== 'prepared'
            || ($operation['entrypoint'] ?? null) !== $entrypoint
            || !is_string($state['active_release'] ?? null)
            || preg_match('/\A[A-Za-z0-9_-]+\z/', $state['active_release']) !== 1
            || !is_string($operation['id'] ?? null)
            || preg_match('/\A[a-f0-9]{32}\z/', $operation['id']) !== 1
            || !is_string($operation['source_version'] ?? null)
            || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $operation['source_version']) !== 1
            || !is_string($operation['version'] ?? null)
            || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $operation['version']) !== 1
            || !is_bool($operation['development'] ?? null)
            || !is_string($operation['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $operation['sha256']) !== 1
            || !is_string($operation['package_id'] ?? null)
            || $operation['package_id'] !== 'v' . str_replace('.', '_', $operation['version']) . '-' . $operation['sha256']) {
            throw new RuntimeException('Opération indisponible.');
        }

        $progress = $this->readJson($this->progressPath);
        if ($progress !== [] && ($progress['operation_id'] ?? null) !== $operation['id']) {
            // Une coupure après la révocation de l'ancienne opération peut
            // laisser son état terminal jusqu'à la première copie suivante.
            if (in_array($progress['phase'] ?? null, ['cancelled', 'rolled_back'], true)) {
                $progress = [];
            } else {
                throw new RuntimeException('État de mise à jour incohérent.');
            }
        }

        return ['state' => $state, 'operation' => $operation, 'progress' => $progress];
    }

    /** Verrou partagé avec la préparation et le reset Core. */
    public function exclusive(callable $callback): mixed
    {
        if (is_link($this->lockPath) || (file_exists($this->lockPath) && !is_file($this->lockPath))) {
            throw new RuntimeException('Verrou de mise à jour invalide.');
        }

        $handle = @fopen($this->lockPath, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Impossible de verrouiller la mise à jour.');
        }

        try {
            if (!@chmod($this->lockPath, 0600) || !flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Une opération de mise à jour est déjà en cours.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $progress */
    public function saveProgress(array $progress): void
    {
        $this->writeJson($this->progressPath, $progress);
    }

    /** @param array<string,mixed> $state */
    public function saveReleaseState(array $state): void
    {
        $this->writeJson($this->releaseStatePath, $state);
    }

    private function assertDirectory(string $path): void
    {
        if (is_link($path) || !is_dir($path) || realpath($path) !== $path) {
            throw new RuntimeException('Dossier de mise à jour invalide.');
        }
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (is_link($path) || !is_file($path) || ($size = @filesize($path)) === false
            || $size > self::MAX_STATE_BYTES) {
            throw new RuntimeException('État de mise à jour illisible.');
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('État de mise à jour illisible.');
        }

        try {
            $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('État de mise à jour invalide.');
        }

        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            throw new RuntimeException('État de mise à jour invalide.');
        }

        return $data;
    }

    /** Écrire sur le même système de fichiers, puis publier par renommage. */
    private function writeJson(string $path, array $data): void
    {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('État de mise à jour invalide.');
        }

        $encoded = json_encode($data === [] ? new \stdClass() : $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($encoded) > self::MAX_STATE_BYTES) {
            throw new RuntimeException('État de mise à jour trop volumineux.');
        }

        $temporary = dirname($path) . '/.state-' . bin2hex(random_bytes(16));
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Impossible de préparer l’état de mise à jour.');
        }

        try {
            if (!@chmod($temporary, 0600) || fwrite($handle, $encoded) !== strlen($encoded)
                || !fflush($handle)) {
                throw new RuntimeException('Écriture de l’état de mise à jour incomplète.');
            }
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($temporary);
            throw $error;
        }
        fclose($handle);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Impossible de publier l’état de mise à jour.');
        }
    }
}
