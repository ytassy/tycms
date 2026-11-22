<?php

declare(strict_types=1);

namespace YTassy\Tycms\Extensions;

use RuntimeException;
use ZipArchive;

/**
 * Vérificateur autonome des paquets d'extension signés.
 *
 * Il ne dépend ni de Vue, ni de l'administration, ni du dossier de build. Le
 * client Core et le serveur TyCatalog l'utilisent pour vérifier les mêmes
 * octets ; la clé de confiance est toujours fournie séparément.
 */
class ExtensionPackage
{
    public const MAX_ZIP_BYTES = 134217728;
    public const MAX_NOTES_BYTES = 262144;
    protected const DOMAIN = "TyCMS extension package v1\0";

    public static function manifest(array $data): array
    {
        if (count($data) !== 6 || ($data['schema_version'] ?? null) !== 1 || ($data['artifact_type'] ?? null) !== 'tycms-extension'
            || !is_array($data['metadata'] ?? null) || !array_key_exists('frontend_entry', $data)) {
            throw new RuntimeException('Manifeste de build d’extension invalide.');
        }
        $metadata = ExtensionMetadata::validate($data['metadata']);
        $base = ExtensionMetadata::artifactName($metadata);
        $entry = $data['frontend_entry'];
        if ($entry !== null && $entry !== 'src/main.js') throw new RuntimeException('Entrée frontend inconnue.');
        foreach (['archive' => [$base . '.zip', self::MAX_ZIP_BYTES], 'release_notes' => [$base . '.md', self::MAX_NOTES_BYTES]] as $field => [$filename, $limit]) {
            $item = $data[$field] ?? null;
            if (!is_array($item) || count($item) !== 3 || ($item['filename'] ?? null) !== $filename
                || !is_int($item['bytes'] ?? null) || $item['bytes'] < 1 || $item['bytes'] > $limit
                || !is_string($item['sha256'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/', $item['sha256'])) {
                throw new RuntimeException('Identité d’artefact invalide : ' . $field);
            }
        }
        return ['schema_version' => 1, 'artifact_type' => 'tycms-extension', 'metadata' => $metadata,
            'frontend_entry' => $entry, 'archive' => $data['archive'], 'release_notes' => $data['release_notes']];
    }

    public static function keyId(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\Aextensions-[a-zA-Z0-9_-]{1,53}\z/', $value)) {
            throw new RuntimeException('Identifiant de clé extensions-… attendu.');
        }
        return $value;
    }

    public static function decode(mixed $encoded, int $length): string
    {
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($bytes === false || strlen($bytes) !== $length || base64_encode($bytes) !== $encoded) {
            throw new RuntimeException('Clé ou signature d’extension invalide.');
        }
        return $bytes;
    }

    /**
     * Vérifie l'enveloppe et retourne le manifeste signé.
     *
     * @param array<string, string> $trustedKeys Clés publiques base64 par key_id.
     */
    public static function verify(string $envelope, array $trustedKeys): array
    {
        if (strlen($envelope) > 131072) throw new RuntimeException('Signature trop volumineuse.');
        $data = json_decode($envelope, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || count($data) !== 4 || ($data['format'] ?? null) !== 'tycms-extension-signature-v1') {
            throw new RuntimeException('Format de signature d’extension invalide.');
        }
        $keyId = self::keyId($data['key_id'] ?? null);
        $public = self::decode($trustedKeys[$keyId] ?? null, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $signature = self::decode($data['signature'] ?? null, SODIUM_CRYPTO_SIGN_BYTES);
        $payload = is_string($data['payload'] ?? null) ? base64_decode($data['payload'], true) : false;
        if ($payload === false || base64_encode($payload) !== $data['payload']
            || !sodium_crypto_sign_verify_detached($signature, self::DOMAIN . $payload, $public)) {
            throw new RuntimeException('Signature d’extension invalide.');
        }
        $manifest = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) throw new RuntimeException('Manifeste signé invalide.');
        return self::manifest($manifest);
    }

    public static function safePath(string $path): void
    {
        if (strlen($path) > 512 || !preg_match('~\A[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\z~', $path)) {
            throw new RuntimeException('Chemin de paquet invalide.');
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '.' || $part === '..' || ($part[0] === '.' && $part !== '.vite')) {
                throw new RuntimeException('Segment de paquet interdit.');
            }
        }
    }

    /** Inspecter les octets du ZIP, sans extraction ni exécution du PHP de l’extension. */
    public static function inspectArchive(string $file, array $manifest): void
    {
        $manifest = self::manifest($manifest);
        if (!is_file($file)) throw new RuntimeException('Archive ZIP requise introuvable : ' . $file);
        if (filesize($file) !== $manifest['archive']['bytes']
            || !hash_equals($manifest['archive']['sha256'], hash_file('sha256', $file))) {
            throw new RuntimeException('Taille ou empreinte ZIP différente des métadonnées : ' . $file);
        }
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) throw new RuntimeException('ZIP invalide ou illisible : ' . $file);
        try {
            if ($zip->numFiles > 20000) throw new RuntimeException('Trop de fichiers dans le paquet.');
            $files = [];
            $seen = [];
            $parents = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) throw new RuntimeException('Entrée ZIP illisible.');
                $name = $stat['name'];
                $path = rtrim($name, '/');
                self::safePath($path);
                if (!str_starts_with($path, 'extension/') && !str_starts_with($path, 'assets/')) throw new RuntimeException('Racine ZIP inattendue.');
                $lower = strtolower($path);
                if (isset($seen[$lower]) || preg_match('~(?:^|/)(?:frontend|node_modules|tests|vendor)(?:/|$)|\.(?:vue|ts|tsx|map|secret|pem|key)$~i', $path)) {
                    throw new RuntimeException('Entrée ZIP répétée ou interdite.');
                }
                if (str_starts_with($path, 'assets/') && preg_match('~\.(?:php[0-9]*|phtml|phar|cgi|pl|sh)(?:\.|$)~i', $path)) {
                    throw new RuntimeException('Fichier exécutable interdit dans les assets publics.');
                }
                $zip->getExternalAttributesIndex($i, $system, $attributes);
                $mode = ($attributes >> 16) & 0170000;
                if (!in_array($mode, [0, 0100000, 0040000], true) || ($stat['encryption_method'] ?? 0) !== 0) {
                    throw new RuntimeException('Lien, fichier spécial ou chiffrement ZIP interdit.');
                }
                $directory = str_ends_with($name, '/');
                $segments = explode('/', $lower);
                foreach (array_slice($segments, 0, -1) as $index => $part) {
                    $parent = implode('/', array_slice($segments, 0, $index + 1));
                    if (isset($seen[$parent]) && $seen[$parent] === 'file') throw new RuntimeException('Collision fichier/dossier.');
                    $parents[$parent] = true;
                }
                if (!$directory && isset($parents[$lower])) throw new RuntimeException('Collision dossier/fichier.');
                $seen[$lower] = $directory ? 'directory' : 'file';
                $total += $stat['size'];
                if ($total > 268435456 || $stat['size'] > 67108864) throw new RuntimeException('Taille décompressée excessive.');
                if (!$directory) $files[$path] = $stat;
            }
            $metadata = $manifest['metadata'];
            $entry = ['plugin' => 'Plugin.php', 'module' => 'Module.php', 'template' => 'Template.php'][$metadata['type']];
            foreach (['extension/version.json', 'extension/version.md', 'extension/' . $entry] as $required) {
                if (!isset($files[$required])) throw new RuntimeException('Fichier requis absent : ' . $required);
            }
            if ($files['extension/version.json']['size'] > ExtensionMetadata::MAX_BYTES
                || $files['extension/version.md']['size'] > self::MAX_NOTES_BYTES) throw new RuntimeException('Métadonnées internes trop volumineuses.');
            $internal = json_decode($zip->getFromName('extension/version.json'), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($internal) || ExtensionMetadata::validate($internal) !== $metadata) throw new RuntimeException('Version interne différente du manifeste.');
            $notes = $zip->getFromName('extension/version.md');
            if (strlen($notes) !== $manifest['release_notes']['bytes'] || !hash_equals($manifest['release_notes']['sha256'], hash('sha256', $notes))) {
                throw new RuntimeException('Notes internes différentes du manifeste.');
            }
            if ($manifest['frontend_entry'] !== null) self::inspectFrontend($zip, $files, $manifest['frontend_entry']);
        } finally {
            $zip->close();
        }
    }

    private static function inspectFrontend(ZipArchive $zip, array $files, string $entry): void
    {
        $path = 'assets/.vite/manifest.json';
        if (!isset($files[$path])) throw new RuntimeException('Manifeste Vite requis absent du ZIP : ' . $path);
        if ($files[$path]['size'] > 2097152) throw new RuntimeException('Manifeste Vite trop volumineux : ' . $path);
        $vite = json_decode($zip->getFromName($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($vite) || !isset($vite[$entry])) throw new RuntimeException('Entrée Vite absente de ' . $path . ' : ' . $entry);
        foreach ($vite as $item) {
            if (!is_array($item) || !is_string($item['file'] ?? null)) throw new RuntimeException('Entrée Vite invalide.');
            foreach (['css', 'assets', 'imports', 'dynamicImports'] as $field) {
                if (!is_array($item[$field] ?? [])) throw new RuntimeException('Dépendances Vite invalides.');
            }
            foreach (array_merge([$item['file']], $item['css'] ?? [], $item['assets'] ?? []) as $file) {
                if (!is_string($file)) throw new RuntimeException('Chemin Vite invalide.');
                self::safePath($file);
                if (!isset($files['assets/' . $file])) throw new RuntimeException('Asset Vite requis absent du ZIP : assets/' . $file);
            }
            foreach (array_merge($item['imports'] ?? [], $item['dynamicImports'] ?? []) as $key) {
                if (!is_string($key)) throw new RuntimeException('Nom de chunk Vite invalide dans ' . $path);
                if (!isset($vite[$key])) throw new RuntimeException('Chunk Vite absent de ' . $path . ' : ' . $key);
            }
        }
    }
}
