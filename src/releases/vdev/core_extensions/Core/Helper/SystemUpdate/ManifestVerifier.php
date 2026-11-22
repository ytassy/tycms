<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate;

use RuntimeException;

/** Consommateur du schéma 1, autonome dans la release et sans dépendre du serveur de distribution. */
final class ManifestVerifier
{
    public const MAX_MANIFEST_BYTES = 65536;
    public const MAX_NOTES_BYTES = 262144;

    public static function version(mixed $version): string
    {
        if (!is_string($version) || strlen($version) > 48
            || preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/', $version) !== 1) {
            throw new RuntimeException('Version stable X.Y.Z attendue.');
        }

        return $version;
    }

    public static function publicKey(mixed $key): string
    {
        $decoded = is_string($key) ? base64_decode($key, true) : false;
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || base64_encode($decoded) !== $key) {
            throw new RuntimeException('Clé publique de mise à jour invalide.');
        }

        return $decoded;
    }

    public static function verify(mixed $envelope, array $keys): array
    {
        // Vérifier les octets signés avant toute interprétation du contenu métier.
        if (!is_array($envelope) || count($envelope) !== 3
            || array_diff(array_keys($envelope), ['key_id', 'payload', 'signature']) !== []
            || !is_string($envelope['key_id'] ?? null)
            || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $envelope['key_id']) !== 1
            || !isset($keys[$envelope['key_id']])
            || !is_string($envelope['payload'] ?? null) || !is_string($envelope['signature'] ?? null)
            || strlen(json_encode($envelope, JSON_THROW_ON_ERROR)) > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('Enveloppe de mise à jour invalide ou clé inconnue.');
        }
        $payload = base64_decode($envelope['payload'], true);
        $signature = base64_decode($envelope['signature'], true);
        if ($payload === false || $signature === false
            || base64_encode($payload) !== $envelope['payload']
            || base64_encode($signature) !== $envelope['signature']
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $payload, self::publicKey($keys[$envelope['key_id']]))) {
            throw new RuntimeException('Signature de mise à jour invalide.');
        }

        $manifest = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['schema_version'] ?? null) !== 1
            || ($manifest['type'] ?? null) !== 'system') {
            throw new RuntimeException('Manifeste de mise à jour invalide.');
        }
        $version = self::version($manifest['version'] ?? null);
        $suffix = str_replace('.', '_', $version);
        $sources = $manifest['source_versions'] ?? null;
        // La première installation reste toujours permise. « * » couvre en plus
        // toute version antérieure ; une liste vide n'autorise aucune mise à jour.
        if (($manifest['version_string'] ?? null) !== $suffix
            || ($sources !== '*' && (!is_array($sources) || !array_is_list($sources) || count($sources) > 256))) {
            throw new RuntimeException('Identité ou compatibilités de mise à jour invalides.');
        }
        if ($sources !== '*') {
            foreach ($sources as $source) {
                if (!version_compare(self::version($source), $version, '<')) {
                    throw new RuntimeException('Version source de mise à jour invalide.');
                }
            }
            if (count(array_unique($sources)) !== count($sources)) {
                throw new RuntimeException('Compatibilités de mise à jour invalides.');
            }
        }
        if (!version_compare(self::version($manifest['php_min'] ?? null),
            self::version($manifest['php_max_exclusive'] ?? null), '<')) {
            throw new RuntimeException('Compatibilités de mise à jour invalides.');
        }
        self::artifact($manifest['archive'] ?? null, "tycms-$suffix.zip", 134217728);
        if (array_key_exists('release_notes', $manifest)) {
            self::artifact($manifest['release_notes'], "tycms-$suffix.md", self::MAX_NOTES_BYTES);
        }

        return $manifest;
    }

    private static function artifact(mixed $artifact, string $filename, int $limit): void
    {
        if (!is_array($artifact) || ($artifact['filename'] ?? null) !== $filename
            || !is_int($artifact['bytes'] ?? null) || $artifact['bytes'] <= 0 || $artifact['bytes'] > $limit
            || !is_string($artifact['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $artifact['sha256']) !== 1) {
            throw new RuntimeException('Identité de fichier de mise à jour invalide.');
        }
    }
}
