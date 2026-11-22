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

/**
 * Stockage des releases hors de la racine HTTP, sans base SQL.
 *
 * Trois états : « en attente » (déposée par la publication, invisible du public),
 * « bêta » (servie seulement aux installations qui l'acceptent) et « prod »
 * (servie par défaut). Une bêta peut être promue en prod ; un retour en arrière
 * est refusé, pour que l'identité servie à une installation ne change jamais.
 */
final class Repository
{
    public const CHANNEL_PENDING = 'pending';
    public const CHANNEL_BETA = 'beta';
    public const CHANNEL_PROD = 'prod';

    /** Un état approuvé est immuable ; seule la promotion bêta → prod est permise. */
    private const TRANSITIONS = [
        self::CHANNEL_PENDING => [self::CHANNEL_BETA, self::CHANNEL_PROD],
        self::CHANNEL_BETA => [self::CHANNEL_PROD],
        self::CHANNEL_PROD => [],
    ];

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
        $this->directory($this->root . '/' . self::CHANNEL_PENDING);
        $this->directory($this->root . '/' . self::CHANNEL_BETA);
        $this->directory($this->root . '/' . self::CHANNEL_PROD);
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

    /**
     * Déposer une release en attente. Aucun contrôle n'est relâché : le manifeste
     * signé, le ZIP et les notes déjà validés sont contrôlés avant le stockage.
     * La version reste invisible des clients jusqu'à son approbation.
     */
    public function publish(string $uploadedFile, string $envelope, ?string $notes = null): array
    {
        [$manifest, $stage, $lock] = $this->stage($uploadedFile, $envelope, $notes);

        // Le dossier en attente n'apparaît qu'une fois complet ; un échec ne laisse rien.
        try {
            if (!rename($stage, $this->releaseDirectory($manifest['version'], self::CHANNEL_PENDING))) {
                throw new RuntimeException('Enregistrement de la proposition impossible.');
            }

            return $manifest;
        } finally {
            $this->cleanup($stage, $lock);
        }
    }

    /**
     * Approuver une proposition pour un canal définitif.
     * Les transitions possibles sont « en attente → bêta/prod » puis « bêta → prod ».
     */
    public function approve(string $version, string $channel): array
    {
        $version = self::canonical($version);
        if (!in_array($channel, [self::CHANNEL_BETA, self::CHANNEL_PROD], true)) {
            throw new HttpError(400, 'Canal d’approbation invalide.');
        }
        $lock = $this->lock();
        try {
            $current = $this->channelOf($version);
            if ($current === null) {
                throw new HttpError(404, 'Version introuvable.');
            }
            if ($current === $channel) {
                throw new HttpError(409, 'Cette version est déjà dans ce canal.');
            }
            // Une version approuvée ne revient jamais dans un état antérieur.
            if (!in_array($channel, self::TRANSITIONS[$current] ?? [], true)) {
                throw new HttpError(409, 'Transition de canal refusée.');
            }

            // Retrouver la release dans l'état courant et la déplacer en une seule opération.
            $source = $this->releaseDirectory($version, $current);
            $target = $this->releaseDirectory($version, $channel);
            $this->release($version, $current);
            if (file_exists($target) || is_link($target)) {
                throw new HttpError(409, 'Cette version est déjà publiée.');
            }
            if (!rename($source, $target)) {
                throw new RuntimeException('Activation de la publication impossible.');
            }

            return $this->release($version, $channel)['manifest'];
        } finally {
            $this->cleanup(null, $lock);
        }
    }

    /** Refuser une proposition : suppression physique, limitée à l'état « en attente ». */
    public function discard(string $version): void
    {
        $version = self::canonical($version);
        $lock = $this->lock();
        try {
            $current = $this->channelOf($version);
            if ($current === null) {
                throw new HttpError(404, 'Version introuvable.');
            }
            if ($current !== self::CHANNEL_PENDING) {
                throw new HttpError(409, 'Seule une version en attente peut être refusée.');
            }
            $directory = $this->releaseDirectory($version, self::CHANNEL_PENDING);
            foreach (['package.zip', 'manifest.json', 'notes.md'] as $file) {
                $path = $directory . '/' . $file;
                if (is_file($path) && !unlink($path) && file_exists($path)) {
                    throw new RuntimeException('Suppression de la proposition impossible.');
                }
            }
            if (!rmdir($directory) && is_dir($directory)) {
                throw new RuntimeException('Suppression de la proposition impossible.');
            }
        } finally {
            $this->cleanup(null, $lock);
        }
    }

    /** Version stricte X.Y.Z : aucun chemin n'est construit à partir d'une chaîne libre. */
    private static function canonical(mixed $version): string
    {
        return Protocol::version($version);
    }

    /** Versions en attente, de la plus récente à la plus ancienne, pour l'administration. */
    public function pending(): array
    {
        $found = [];
        foreach ($this->entries(self::CHANNEL_PENDING) as $version) {
            $found[$version] = ['channel' => self::CHANNEL_PENDING] + $this->release($version, self::CHANNEL_PENDING);
        }
        uksort($found, 'version_compare');

        return array_values($found);
    }

    /** Versions approuvées, avec leur canal, pour l'administration. */
    public function approved(): array
    {
        $found = [];
        foreach ([self::CHANNEL_BETA, self::CHANNEL_PROD] as $channel) {
            foreach ($this->entries($channel) as $version) {
                $found[$version] = ['channel' => $channel] + $this->release($version, $channel);
            }
        }
        uksort($found, 'version_compare');

        return array_values($found);
    }

    /** Racine privée du stockage ; sert au dossier de sessions, jamais à la publication. */
    public function storageRoot(): string
    {
        return $this->root;
    }

    /** Canal d'une version, ou null si elle n'existe dans aucun état. */
    public function channelOf(string $version): ?string
    {
        $version = self::canonical($version);
        foreach ([self::CHANNEL_PROD, self::CHANNEL_BETA, self::CHANNEL_PENDING] as $channel) {
            if (realpath($this->releaseDirectory($version, $channel)) === $this->releaseDirectory($version, $channel)) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * Préparer une copie complète et vérifiée dans un dossier privé temporaire.
     * Le verrou reste tenu par l'appelant jusqu'au déplacement final du dossier.
     */
    private function stage(string $uploadedFile, string $envelope, ?string $notes): array
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

        $lock = $this->lock();
        $stage = null;
        try {
            // Une version ne change jamais de numéro ni de contenu : un doublon est refusé.
            foreach ([self::CHANNEL_PENDING, self::CHANNEL_BETA, self::CHANNEL_PROD] as $channel) {
                $target = $this->releaseDirectory($manifest['version'], $channel);
                if (file_exists($target) || is_link($target)) {
                    throw new HttpError(409, 'Cette version est déjà publiée.');
                }
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

            return [$manifest, $stage, $lock];
        } catch (Throwable $error) {
            $this->cleanup($stage, $lock);
            throw $error;
        }
    }

    /** Verrou exclusif unique pour toute mutation de l'état des releases. */
    private function lock(): mixed
    {
        $lockPath = $this->root . '/publication.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Verrou invalide.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Verrou inaccessible.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new HttpError(409, 'Une publication est déjà en cours.');
        }

        return $lock;
    }

    /** Libérer le verrou et supprimer seulement notre dossier temporaire éventuel. */
    private function cleanup(?string $stage, mixed $lock): void
    {
        if ($stage !== null) {
            foreach (['package.zip', 'manifest.json', 'notes.md'] as $file) {
                if (is_file($stage . '/' . $file)) {
                    unlink($stage . '/' . $file);
                }
            }
            if (is_dir($stage)) {
                rmdir($stage);
            }
        }
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Dossier d'une version dans l'état donné ; le nom de version reste validé. */
    private function releaseDirectory(string $version, string $channel): string
    {
        // Le nom de répertoire vient de canonical() : jamais de « . » constructible par l'appelant.
        return $this->root . '/' . $channel . '/v' . str_replace('.', '_', self::canonical($version));
    }

    /** Versions valides présentes dans l'état donné. */
    private function entries(string $channel): array
    {
        $versions = [];
        foreach (new \DirectoryIterator($this->root . '/' . $channel) as $entry) {
            if ($entry->isDot() || !$entry->isDir() || $entry->isLink()
                || preg_match('/\Av([0-9]+)_([0-9]+)_([0-9]+)\z/', $entry->getFilename(), $parts) !== 1) {
                continue;
            }
            $versions[] = $parts[1] . '.' . $parts[2] . '.' . $parts[3];
        }

        return $versions;
    }

    public function release(string $version, string $channel = self::CHANNEL_PROD): array
    {
        $directory = $this->releaseDirectory($version, $channel);
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

        return ['manifest' => $manifest, 'envelope' => $envelope, 'created_at' => filemtime($directory),
            'package' => $directory . '/package.zip', 'notes' => $notes];
    }

    public function releases(?string $source, ?string $php, ?string $channel = null): array
    {
        if (($source === null) !== ($php === null)) {
            throw new HttpError(400, 'Fournir ensemble source et php.');
        }
        // Sans demande explicite, seules les releases de prod sont proposées.
        $channel ??= Protocol::CHANNEL_PROD;
        if (!in_array($channel, [Protocol::CHANNEL_PROD, Protocol::CHANNEL_BETA], true)) {
            throw new HttpError(400, 'Canal invalide.');
        }
        if ($source !== null) {
            Protocol::version($source);
            Protocol::version($php);
        }
        // La bêta n'est ajoutée qu'à la demande : un client qui n'en parle pas ne la voit jamais.
        $states = $channel === Protocol::CHANNEL_BETA
            ? [self::CHANNEL_BETA, self::CHANNEL_PROD]
            : [self::CHANNEL_PROD];
        $found = [];
        foreach ($states as $state) {
            foreach ($this->entries($state) as $version) {
                $release = $this->release($version, $state);
                if ($source === null || Protocol::compatible($release['manifest'], $source, $php)) {
                    $found[$version] = json_decode($release['envelope'], true, 16, JSON_THROW_ON_ERROR);
                }
            }
        }
        uksort($found, 'version_compare');

        return array_values($found);
    }
}
