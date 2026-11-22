<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Extension;

use RuntimeException;
use Throwable;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Extensions\ExtensionMetadata;

/**
 * État privé d'une mise à jour d'extension entre la préparation des fichiers
 * et la finalisation des migrations.
 *
 * Le fichier ne contient ni licence ni secret. Il est créé sous
 * `var/extensions`, avec un nom dérivé d'un identifiant validé.
 */
final class ExtensionUpdateState extends BaseHelper
{
    private const SCHEMA = 1;
    private const IDENTIFIER_PATTERN = '/\A[a-z0-9_]{1,64}\z/';
    private const OPERATION_PATTERN = '/\A[a-f0-9]{32}\z/';
    private const MAX_BYTES = 65536;

    /** État courant, ou null si aucune mise à jour n'est préparée. */
    public function read(string $identifier): ?array
    {
        $this->assertIdentifier($identifier);
        $directory = $this->existingDirectory();
        if ($directory === null) {
            return null;
        }

        $path = $directory . '/update-' . $identifier . '.json';
        if (is_link($path) || !is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($contents) || strlen($contents) > self::MAX_BYTES) {
            throw new RuntimeException('État de mise à jour illisible ou trop volumineux.');
        }

        try {
            $state = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('État de mise à jour invalide.');
        }

        if (!is_array($state) || ($state['schema_version'] ?? null) !== self::SCHEMA
            || ($state['extension_id'] ?? null) !== $identifier
            || !is_string($state['operation_id'] ?? null)
            || preg_match(self::OPERATION_PATTERN, $state['operation_id']) !== 1
            || !is_string($state['from_version'] ?? null)
            || !is_string($state['to_version'] ?? null)
            || !is_string($state['type'] ?? null)
            || !is_string($state['folder'] ?? null)
            || !is_string($state['source_name'] ?? null)
            || mb_strlen($state['source_name']) > 150
            || !is_string($state['source_url'] ?? null)
            || mb_strlen($state['source_url']) > 500
            || !is_string($state['key_id'] ?? null)
            || preg_match('/\Aextensions-[a-zA-Z0-9_-]{1,53}\z/', $state['key_id']) !== 1
            || !(($state['source_id'] ?? null) === null || is_int($state['source_id']))
            || !is_string($state['archive_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $state['archive_sha256']) !== 1
            || !is_string($state['backup_directory'] ?? null)
            || !(($state['warning'] ?? null) === null || is_string($state['warning']))
            || !is_string($state['created'] ?? null)
            || ($state['status'] ?? null) !== 'files_updated') {
            throw new RuntimeException('État de mise à jour invalide.');
        }

        ExtensionMetadata::version($state['from_version']);
        ExtensionMetadata::version($state['to_version']);
        if (!in_array($state['type'], ['plugin', 'module', 'template'], true)
            || !preg_match('/\A[A-Z][A-Za-z0-9]{0,63}\z/', $state['folder'])) {
            throw new RuntimeException('État de mise à jour invalide.');
        }

        $expected = $this->operationDirectory($state['operation_id']);
        if ($state['backup_directory'] !== $expected
            || is_link($expected) || !is_dir($expected) || realpath($expected) !== $expected) {
            throw new RuntimeException('Sauvegarde de mise à jour introuvable ou invalide.');
        }

        return $state;
    }

    /** Vue minimale destinée à l'interface de consultation. */
    public function pending(string $identifier): ?array
    {
        $state = $this->read($identifier);
        if ($state === null) {
            return null;
        }

        return [
            'operation_id' => $state['operation_id'],
            'from_version' => $state['from_version'],
            'to_version' => $state['to_version'],
            'status' => $state['status'],
        ];
    }

    /** @param array<string, mixed> $state */
    public function write(string $identifier, array $state): void
    {
        $this->assertIdentifier($identifier);
        if (($state['extension_id'] ?? null) !== $identifier
            || !is_string($state['operation_id'] ?? null)
            || preg_match(self::OPERATION_PATTERN, $state['operation_id']) !== 1) {
            throw new RuntimeException('État de mise à jour incohérent.');
        }

        $directory = $this->directory();
        $path = $directory . '/update-' . $identifier . '.json';
        if (is_link($path)) {
            throw new RuntimeException('État de mise à jour redirigé par un lien symbolique.');
        }

        $temporary = $directory . '/.state-' . bin2hex(random_bytes(16));
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Impossible de créer l’état de mise à jour.');
        }

        try {
            $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (!@chmod($temporary, 0600) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Écriture de l’état de mise à jour incomplète.');
            }
        } catch (Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        } finally {
            fclose($handle);
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Impossible de publier l’état de mise à jour.');
        }
    }

    public function delete(string $identifier): void
    {
        $this->assertIdentifier($identifier);
        $directory = $this->existingDirectory();
        if ($directory === null) {
            return;
        }

        $path = $directory . '/update-' . $identifier . '.json';
        if (is_link($path)) {
            throw new RuntimeException('État de mise à jour redirigé par un lien symbolique.');
        }
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Impossible de supprimer l’état de mise à jour.');
        }
    }

    /** Dossier de sauvegarde d'une opération, sans création. */
    public function operationDirectory(string $operationId): string
    {
        if (preg_match(self::OPERATION_PATTERN, $operationId) !== 1) {
            throw new RuntimeException('Identifiant d’opération de mise à jour invalide.');
        }

        return ($this->existingDirectory() ?? $this->directory()) . '/.update-' . $operationId;
    }

    /** Dossier privé existant, sans création (lectures). */
    public function existingDirectory(): ?string
    {
        $root = $this->root();
        $directory = $root . '/var/extensions';
        if (is_link($directory) || !is_dir($directory)) {
            return null;
        }
        $real = realpath($directory);
        if ($real === false || $real !== $directory) {
            return null;
        }

        return $directory;
    }

    /** Dossier privé créé si nécessaire (écritures). */
    public function directory(): string
    {
        $root = $this->root();
        $var = $this->privateDirectory($root . '/var', 0700);

        return $this->privateDirectory($var . '/extensions', 0700);
    }

    private function root(): string
    {
        $root = realpath(APP_ROOT);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Racine de l’installation inaccessible.');
        }

        return $root;
    }

    private function privateDirectory(string $path, int $mode): string
    {
        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
            throw new RuntimeException('Dossier privé de mise à jour invalide.');
        }
        if (!is_dir($path) && !@mkdir($path, $mode)) {
            throw new RuntimeException('Impossible de créer le dossier privé de mise à jour.');
        }
        if (realpath($path) !== $path) {
            throw new RuntimeException('Dossier privé de mise à jour redirigé par un lien symbolique.');
        }

        return $path;
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw new RuntimeException('Identifiant d’extension invalide.');
        }
    }
}
