<?php

declare(strict_types=1);

namespace Tycms\Distribution;

use RuntimeException;
use Throwable;

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

/** Stockage immuable des releases hors de la racine HTTP, sans base SQL. */
final class Repository
{
    private string $root;

    public function __construct(private array $config, string $publicRoot)
    {
        $path = $config['storage_path'] ?? '';
        if (!is_string($path) || $path === '' || is_link($path)) {
            throw new RuntimeException('Stockage non configuré.');
        }
        if (!is_dir($path) && !mkdir($path, 0700, true)) {
            throw new RuntimeException('Stockage inaccessible.');
        }
        $this->root = realpath($path) ?: throw new RuntimeException('Stockage inaccessible.');
        $public = realpath($publicRoot);
        if ($public === false || $this->root === $public || str_starts_with($this->root, $public . '/')) {
            throw new RuntimeException('Le stockage doit rester hors de public/.');
        }
        $this->directory($this->root . '/releases');
    }

    private function directory(string $path): void
    {
        if (is_link($path) || (!is_dir($path) && !mkdir($path, 0700)) || realpath($path) !== $path) {
            throw new RuntimeException('Dossier de distribution invalide.');
        }
    }

    public function authenticate(mixed $authorization): void
    {
        if (!is_string($authorization) || preg_match('/\ABearer ([a-f0-9]{64})\z/', $authorization, $matches) !== 1) {
            throw new HttpError(401, 'Authentification requise.');
        }
        $hash = hash('sha256', $matches[1]);
        $allowed = false;
        foreach ($this->config['publish_token_sha256'] ?? [] as $candidate) {
            if (is_string($candidate) && preg_match('/\A[a-f0-9]{64}\z/', $candidate) === 1) {
                $allowed = hash_equals($candidate, $hash) || $allowed;
            }
        }
        if (!$allowed) {
            throw new HttpError(401, 'Authentification requise.');
        }
    }

    public function verify(string $envelope): array
    {
        return Protocol::verify($envelope, $this->config['trusted_public_keys'] ?? []);
    }

    public function publish(string $uploadedFile, string $envelope, ?string $notes = null): array
    {
        try {
            $manifest = $this->verify($envelope);
        } catch (Throwable) {
            throw new HttpError(422, 'Manifeste ou signature invalide.');
        }
        if (isset($manifest['release_notes'])) {
            if ($notes === null || strlen($notes) !== $manifest['release_notes']['bytes']
                || !hash_equals($manifest['release_notes']['sha256'], hash('sha256', $notes))) {
                throw new HttpError(422, 'Notes de version absentes ou incohérentes.');
            }
        } elseif ($notes !== null) {
            throw new HttpError(422, 'Notes non déclarées dans le manifeste.');
        }
        $size = filesize($uploadedFile);
        $maximum = $this->config['max_package_bytes'] ?? 134217728;
        if (!is_int($maximum) || $maximum <= 0) {
            throw new RuntimeException('Limite de taille invalide.');
        }
        if ($size === false || $size > $maximum || $manifest['archive']['bytes'] > $maximum) {
            throw new HttpError(413, 'Paquet trop volumineux.');
        }
        if ($size !== $manifest['archive']['bytes']) {
            throw new HttpError(422, 'Taille du paquet incohérente.');
        }

        // Le verrou couvre tout remplacement d'état ; le dossier n'apparaît qu'une fois complet.
        $lockPath = $this->root . '/publication.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Verrou invalide.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Verrou inaccessible.');
        }
        $stage = null;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new HttpError(409, 'Une publication est déjà en cours.');
            }
            $target = $this->releaseDirectory($manifest['version']);
            if (file_exists($target) || is_link($target)) {
                throw new HttpError(409, 'Cette version est déjà publiée.');
            }
            $stage = $this->root . '/.incoming-' . bin2hex(random_bytes(16));
            $this->directory($stage);
            $source = fopen($uploadedFile, 'rb');
            $destination = fopen($stage . '/package.zip', 'xb');
            if ($source === false || $destination === false) {
                throw new RuntimeException('Copie du paquet impossible.');
            }
            try {
                $copied = stream_copy_to_stream($source, $destination, $size + 1);
            } finally {
                fclose($source);
                fclose($destination);
            }
            if ($copied !== $size || !hash_equals($manifest['archive']['sha256'], hash_file('sha256', $stage . '/package.zip'))) {
                throw new HttpError(422, 'Empreinte du paquet incohérente.');
            }
            try {
                Protocol::inspectArchive($stage . '/package.zip', $manifest['version']);
            } catch (Throwable) {
                throw new HttpError(422, 'Archive système invalide.');
            }
            if ($notes !== null && file_put_contents($stage . '/notes.md', $notes) !== strlen($notes)) {
                throw new RuntimeException('Écriture des notes impossible.');
            }
            if (file_put_contents($stage . '/manifest.json', $envelope) !== strlen($envelope)) {
                throw new RuntimeException('Écriture du manifeste impossible.');
            }
            if (!rename($stage, $target)) {
                throw new RuntimeException('Activation de la publication impossible.');
            }
            $stage = null;

            return $manifest;
        } finally {
            // Nettoyer seulement notre propre dossier temporaire, jamais une release existante.
            if ($stage !== null) {
                foreach (['package.zip', 'manifest.json', 'notes.md'] as $file) {
                    if (is_file($stage . '/' . $file)) {
                        unlink($stage . '/' . $file);
                    }
                }
                rmdir($stage);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function releaseDirectory(string $version): string
    {
        return $this->root . '/releases/v' . str_replace('.', '_', Protocol::version($version));
    }

    public function release(string $version): array
    {
        $directory = $this->releaseDirectory($version);
        if (realpath($directory) !== $directory || !is_file($directory . '/manifest.json')
            || is_link($directory . '/manifest.json') || !is_file($directory . '/package.zip')
            || is_link($directory . '/package.zip')) {
            throw new HttpError(404, 'Version introuvable.');
        }
        $envelope = file_get_contents($directory . '/manifest.json', false, null, 0, Protocol::MAX_MANIFEST_BYTES + 1);
        $manifest = $this->verify($envelope);
        if ($manifest['version'] !== $version || filesize($directory . '/package.zip') !== $manifest['archive']['bytes']) {
            throw new RuntimeException('Release stockée incohérente.');
        }

        $notes = null;
        if (isset($manifest['release_notes'])) {
            $notesPath = $directory . '/notes.md';
            if (!is_file($notesPath) || is_link($notesPath) || filesize($notesPath) !== $manifest['release_notes']['bytes']) {
                throw new RuntimeException('Notes stockées incohérentes.');
            }
            $notes = file_get_contents($notesPath, false, null, 0, Protocol::MAX_NOTES_BYTES + 1);
            if (!is_string($notes) || !hash_equals($manifest['release_notes']['sha256'], hash('sha256', $notes))) {
                throw new RuntimeException('Empreinte des notes stockées incohérente.');
            }
        }

        return ['manifest' => $manifest, 'envelope' => $envelope,
            'package' => $directory . '/package.zip', 'notes' => $notes];
    }

    public function releases(?string $source, ?string $php): array
    {
        if (($source === null) !== ($php === null)) {
            throw new HttpError(400, 'Fournir ensemble source et php.');
        }
        if ($source !== null) {
            Protocol::version($source);
            Protocol::version($php);
        }
        $found = [];
        foreach (new \DirectoryIterator($this->root . '/releases') as $entry) {
            if ($entry->isDot() || !$entry->isDir() || $entry->isLink()
                || !preg_match('/\Av([0-9]+)_([0-9]+)_([0-9]+)\z/', $entry->getFilename(), $parts)) {
                continue;
            }
            $version = $parts[1] . '.' . $parts[2] . '.' . $parts[3];
            $release = $this->release($version);
            if ($source === null || Protocol::compatible($release['manifest'], $source, $php)) {
                $found[$version] = json_decode($release['envelope'], true, 16, JSON_THROW_ON_ERROR);
            }
        }
        uksort($found, 'version_compare');

        return array_values($found);
    }
}
