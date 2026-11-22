<?php

declare(strict_types=1);

namespace Tycms\Distribution;

use RuntimeException;
use ZipArchive;

/** Contrat autonome commun au serveur de distribution et au client de publication. */
final class Protocol
{
    public const MAX_MANIFEST_BYTES = 65536;
    public const MAX_NOTES_BYTES = 262144;

    public static function version(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 48
            || preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/', $value) !== 1) {
            throw new RuntimeException('Version stable X.Y.Z attendue.');
        }

        return $value;
    }

    public static function decodeKey(mixed $encoded, int $length): string
    {
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($key === false || strlen($key) !== $length || base64_encode($key) !== $encoded) {
            throw new RuntimeException('Clé ou signature invalide.');
        }

        return $key;
    }

    /** Les octets du payload sont signés tels quels, sans canonicalisation JSON implicite. */
    public static function sign(array $manifest, string $keyId, string $secret): string
    {
        self::validate($manifest);
        self::keyId($keyId);
        $payload = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($payload, $secret);

        // Rendre manifest.json lisible sans modifier les octets du payload signé.
        return json_encode(['key_id' => $keyId, 'payload' => base64_encode($payload),
            'signature' => base64_encode($signature)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    public static function keyId(mixed $id): string
    {
        if (!is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $id) !== 1) {
            throw new RuntimeException('Identifiant de clé invalide.');
        }

        return $id;
    }

    /** Ne jamais accepter une clé reçue dans le manifeste lui-même. */
    public static function verify(string $envelope, array $trustedKeys): array
    {
        if (strlen($envelope) > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('Manifeste trop volumineux.');
        }
        $data = json_decode($envelope, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || count($data) !== 3
            || array_diff(array_keys($data), ['key_id', 'payload', 'signature']) !== []) {
            throw new RuntimeException('Enveloppe de signature invalide.');
        }
        $id = self::keyId($data['key_id']);
        $public = self::decodeKey($trustedKeys[$id] ?? null, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $signature = self::decodeKey($data['signature'], SODIUM_CRYPTO_SIGN_BYTES);
        $payload = is_string($data['payload']) ? base64_decode($data['payload'], true) : false;
        if ($payload === false || base64_encode($payload) !== $data['payload']
            || !sodium_crypto_sign_verify_detached($signature, $payload, $public)) {
            throw new RuntimeException('Signature du manifeste invalide.');
        }
        $manifest = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new RuntimeException('Manifeste invalide.');
        }
        self::validate($manifest);

        return $manifest;
    }

    public static function validate(array $manifest): void
    {
        if (($manifest['schema_version'] ?? null) !== 1 || ($manifest['type'] ?? null) !== 'system') {
            throw new RuntimeException('Schéma de manifeste inconnu.');
        }
        $metadata = self::releaseMetadata($manifest);
        $suffix = $metadata['version_string'];
        $archive = $manifest['archive'] ?? null;
        if (!is_array($archive) || ($archive['filename'] ?? null) !== "tycms-$suffix.zip"
            || !is_int($archive['bytes'] ?? null) || $archive['bytes'] <= 0
            || !is_string($archive['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $archive['sha256']) !== 1) {
            throw new RuntimeException('Identité de l’archive invalide.');
        }
        self::validateNotes($manifest);
    }

    /** Même validation pour version.json, le build figé et le manifeste signé. */
    public static function releaseMetadata(array $data): array
    {
        $version = self::version($data['version'] ?? null);
        $suffix = str_replace('.', '_', $version);
        if (($data['version_string'] ?? null) !== $suffix) {
            throw new RuntimeException('Suffixe de version incohérent.');
        }

        // La première installation reste toujours permise. « * » couvre en plus
        // toute version antérieure ; une liste vide n'autorise aucune mise à jour.
        $sources = $data['source_versions'] ?? null;
        if ($sources !== '*' && (!is_array($sources) || !array_is_list($sources) || count($sources) > 256)) {
            throw new RuntimeException('Liste explicite des versions sources ou marqueur « * » attendu.');
        }
        if ($sources !== '*') {
            foreach ($sources as $source) {
                self::version($source);
                if (!version_compare($source, $version, '<')) {
                    throw new RuntimeException('Chaque version source doit précéder la cible.');
                }
            }
            if (count(array_unique($sources)) !== count($sources)) {
                throw new RuntimeException('Version source dupliquée.');
            }
        }
        $minimum = self::version($data['php_min'] ?? null);
        $maximum = self::version($data['php_max_exclusive'] ?? null);
        if (!version_compare($minimum, $maximum, '<')) {
            throw new RuntimeException('Bornes PHP incohérentes.');
        }

        return ['version' => $version, 'version_string' => $suffix,
            'source_versions' => $sources, 'php_min' => $minimum, 'php_max_exclusive' => $maximum];
    }

    /** Les notes sont facultatives pour les anciennes releases déjà publiées. */
    public static function validateNotes(array $manifest): void
    {
        if (!array_key_exists('release_notes', $manifest)) {
            return;
        }
        $notes = $manifest['release_notes'];
        if (!is_array($notes) || ($notes['filename'] ?? null) !== 'tycms-' . $manifest['version_string'] . '.md'
            || !is_int($notes['bytes'] ?? null) || $notes['bytes'] <= 0 || $notes['bytes'] > self::MAX_NOTES_BYTES
            || !is_string($notes['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $notes['sha256']) !== 1) {
            throw new RuntimeException('Identité des notes de version invalide.');
        }
    }

    public static function compatible(array $manifest, string $source, string $php): bool
    {
        // « * » couvre la première installation et toute version antérieure.
        // La cible doit rester strictement supérieure à la source, même avec « * ».
        return version_compare($manifest['version'], $source, '>')
            && (($manifest['source_versions'] ?? null) === '*'
                || in_array($source, $manifest['source_versions'] ?? [], true))
            && version_compare($php, $manifest['php_min'], '>=')
            && version_compare($php, $manifest['php_max_exclusive'], '<');
    }

    /** Inspecter le ZIP sans jamais extraire ni exécuter ses fichiers PHP. */
    public static function inspectArchive(string $file, string $version): void
    {
        $suffix = str_replace('.', '_', self::version($version));
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Archive ZIP invalide.');
        }
        try {
            if ($zip->numFiles > 100000) {
                throw new RuntimeException('Trop de fichiers dans l’archive.');
            }
            $seen = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                $normalized = rtrim($name, '/');
                $parts = explode('/', $normalized);
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                $total += $stat['size'];
                if ($name === '' || strlen($name) > 1024 || str_contains($name, '\\')
                    || preg_match('/[\x00-\x1f\x7f:]/', $name) || in_array('', $parts, true)
                    || in_array('..', $parts, true) || in_array('.', $parts, true)
                    || isset($seen[$normalized]) || (($attributes >> 16) & 0170000) === 0120000
                    || $total > 1073741824 || ($stat['encryption_method'] ?? 0) !== 0) {
                    throw new RuntimeException('Chemin ou contenu ZIP interdit.');
                }
                $seen[$normalized] = true;
                if ($parts[0] === 'releases' && isset($parts[1]) && $parts[1] !== 'v' . $suffix) {
                    throw new RuntimeException('Release inattendue dans le ZIP.');
                }
                if (!in_array($parts[0], ['releases', 'public', 'extensions', 'updater', 'var', 'logs', 'tmp'], true)
                    || in_array('configuration.php', $parts, true)
                    || in_array('node_modules', $parts, true) || in_array('.git', $parts, true)) {
                    throw new RuntimeException('Fichier local ou racine inconnue dans le ZIP.');
                }
            }

            $root = 'releases/v' . $suffix;
            foreach ([$root . '/index.php', $root . '/version.php', $root . '/configuration.base.php',
                $root . '/composer.json', $root . '/composer.lock', $root . '/vendor/autoload.php',
                'public/index.php', 'public/.htaccess', 'updater/index.php', 'var/releases-states.json'] as $required) {
                if (!isset($seen[$required])) {
                    throw new RuntimeException('Archive système incomplète.');
                }
            }
            $stat = $zip->statName($root . '/version.php');
            if ($stat['size'] > 8192) {
                throw new RuntimeException('Fichier de version invalide.');
            }
            $php = $zip->getFromName($root . '/version.php', 8193);
            if (!is_string($php) || strlen($php) > 8192) {
                throw new RuntimeException('Fichier de version illisible.');
            }
            foreach (['APP_VERSION' => $version, 'APP_VERSION_STRING' => $suffix] as $constant => $expected) {
                preg_match_all("/^[\\t ]*define\\([\\t ]*'" . $constant . "'[\\t ]*,[\\t ]*'([^']+)'[\\t ]*\\)[\\t ]*;/m", $php, $matches);
                if (count($matches[1]) !== 1 || $matches[1][0] !== $expected) {
                    throw new RuntimeException('Version du ZIP différente du manifeste.');
                }
            }
        } finally {
            $zip->close();
        }
    }
}
