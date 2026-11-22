<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate;

use JsonException;
use RuntimeException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;

/** Cache privé de paquets authentifiés ; ne prépare ni release ni opération de mise à jour. */
class SystemUpdatePackageHelper extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    public function download(string $source, string $php, string $version, string $sha256, bool $devPreview = false): array
    {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('L’extension PHP zip est nécessaire pour contrôler les paquets système.');
        }
        $verified = $this->coreSystemUpdateHelper->manifestForDownload($source, $php, $version, $sha256, $devPreview);
        $manifest = $verified['manifest'];
        $root = $this->storageRoot();
        $id = 'v' . $manifest['version_string'] . '-' . $manifest['archive']['sha256'];
        $target = $root . '/' . $id;
        $stage = null;
        $stream = null;

        // Un verrou commun refuse immédiatement les doubles téléchargements et protège le cache.
        $lockPath = $root . '/.download.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new RuntimeException('Verrou du cache de mise à jour invalide.');
        }
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Impossible de verrouiller le cache des paquets système.');
        }
        try {
            if (!@chmod($lockPath, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Un téléchargement est déjà en cours ou le cache ne peut pas être verrouillé.');
            }

            // La présence d’un ancien paquet ne dispense d’aucun contrôle de signature ou d’intégrité.
            if (file_exists($target) || is_link($target)) {
                $this->verifyCache($target, $manifest, $verified['keys']);
                return $this->receipt($id, $manifest, true, $verified['failures']);
            }
            $free = @disk_free_space($root);
            if ($free !== false && $free < $manifest['archive']['bytes'] + 1048576) {
                throw new RuntimeException('Espace disque insuffisant pour télécharger ce paquet.');
            }
            $stage = $root . '/.incoming-' . bin2hex(random_bytes(16));
            if (!@mkdir($stage, 0700)) {
                throw new RuntimeException('Impossible de créer le dossier privé de téléchargement.');
            }
            $part = $stage . '/package.part';
            $stream = @fopen($part, 'x+b');
            if ($stream === false || !@chmod($part, 0600)) {
                throw new RuntimeException('Impossible de créer le fichier de téléchargement privé.');
            }

            $deadline = microtime(true) + 120;
            $downloaded = false;
            foreach ($verified['servers'] as $server) {
                if (!ftruncate($stream, 0) || !rewind($stream)) {
                    throw new RuntimeException('Impossible de préparer le fichier temporaire.');
                }
                $remaining = (int) (($deadline - microtime(true)) * 1000);
                if ($remaining <= 0) {
                    throw new RuntimeException('Délai global de téléchargement dépassé.');
                }
                try {
                    $this->transfer($server . '?action=package&version=' . rawurlencode($version),
                        $stream, $manifest['archive']['bytes'], min(60000, $remaining));
                    $downloaded = true;
                    break;
                } catch (ServerUnavailableException) {
                    $verified['failures'][] = ['server' => $server, 'reason' => 'Téléchargement du ZIP indisponible ou interrompu.'];
                }
            }
            if (!$downloaded) {
                throw new RuntimeException('Aucun serveur ne fournit le ZIP attendu. Réessayez ultérieurement.');
            }
            if (!fflush($stream)) {
                throw new RuntimeException('Impossible de finaliser le fichier téléchargé.');
            }
            fclose($stream);
            $stream = null;
            $this->verifyArchive($part, $manifest);

            // Rendre visible l’ensemble ZIP/manifeste seulement après tous les contrôles.
            $metadata = $stage . '/manifest.json';
            $handle = @fopen($metadata, 'xb');
            if ($handle === false) {
                throw new RuntimeException('Impossible de conserver le manifeste signé.');
            }
            try {
                if (!@chmod($metadata, 0600) || fwrite($handle, $verified['envelope']) !== strlen($verified['envelope'])
                    || !fflush($handle)) {
                    throw new RuntimeException('Écriture du manifeste signé incomplète.');
                }
            } finally {
                fclose($handle);
            }
            if (!@rename($part, $stage . '/package.zip') || !@rename($stage, $target)) {
                throw new RuntimeException('Impossible de finaliser le cache du paquet vérifié.');
            }
            $stage = null;

            return $this->receipt($id, $manifest, false, $verified['failures']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            // Nettoyer seulement les fichiers de cette tentative, sans toucher à un paquet valide.
            if ($stage !== null) {
                foreach (['package.part', 'package.zip', 'manifest.json'] as $file) {
                    if (is_file($stage . '/' . $file)) {
                        @unlink($stage . '/' . $file);
                    }
                }
                @rmdir($stage);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Revérifier un paquet déjà téléchargé, sans déclencher de transfert du ZIP. */
    public function verifiedPackage(string $source, string $php, string $version, string $sha256, bool $devPreview = false): array
    {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('L’extension PHP zip est nécessaire pour préparer les paquets système.');
        }
        $verified = $this->coreSystemUpdateHelper->manifestForDownload($source, $php, $version, $sha256, $devPreview);
        $manifest = $verified['manifest'];
        $id = 'v' . $manifest['version_string'] . '-' . $manifest['archive']['sha256'];
        $directory = $this->storageRoot() . '/' . $id;
        $this->verifyCache($directory, $manifest, $verified['keys']);

        return ['package_id' => $id, 'directory' => $directory, 'manifest' => $manifest];
    }

    protected function installationRoot(): string
    {
        return APP_ROOT;
    }

    private function storageRoot(): string
    {
        $root = realpath($this->installationRoot());
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Racine de l’installation inaccessible.');
        }
        // Chaque composant est créé hors de public/ ; aucun lien symbolique n’est accepté.
        foreach (['var', 'updates', 'packages'] as $component) {
            $root .= '/' . $component;
            if (is_link($root) || (!is_dir($root) && !@mkdir($root, 0700)) || realpath($root) !== $root) {
                throw new RuntimeException('Le cache privé des paquets est inaccessible ou contient un lien symbolique.');
            }
        }

        return $root;
    }

    private function verifyCache(string $directory, array $expected, array $keys): void
    {
        if (is_link($directory) || !is_dir($directory) || realpath($directory) !== $directory) {
            throw new RuntimeException('Dossier du paquet en cache invalide.');
        }
        $path = $directory . '/manifest.json';
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Manifeste du paquet en cache invalide.');
        }
        $envelope = @file_get_contents($path, false, null, 0, ManifestVerifier::MAX_MANIFEST_BYTES + 1);
        if (!is_string($envelope) || strlen($envelope) > ManifestVerifier::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('Manifeste du paquet en cache illisible ou trop volumineux.');
        }
        try {
            $manifest = ManifestVerifier::verify(json_decode($envelope, true, 16, JSON_THROW_ON_ERROR), $keys);
        } catch (JsonException|RuntimeException $exception) {
            throw new RuntimeException('Signature du paquet en cache invalide. Le cache doit être vérifié avant de réessayer.', 0, $exception);
        }
        if ($manifest['version'] !== $expected['version'] || $manifest['archive'] !== $expected['archive']) {
            throw new RuntimeException('Le paquet en cache ne correspond pas au manifeste actuel.');
        }
        $this->verifyArchive($directory . '/package.zip', $expected);
    }

    private function verifyArchive(string $path, array $manifest): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path) || filesize($path) !== $manifest['archive']['bytes']) {
            throw new RuntimeException('Taille du ZIP invalide : téléchargement arrêté.');
        }
        $sha256 = @hash_file('sha256', $path);
        if (!is_string($sha256) || !hash_equals($manifest['archive']['sha256'], $sha256)) {
            throw new RuntimeException('Empreinte du ZIP invalide : téléchargement arrêté.');
        }
        ArchiveInspector::inspect($path, $manifest['version']);
    }

    private function receipt(string $id, array $manifest, bool $reused, array $failures): array
    {
        return ['package_id' => $id, 'version' => $manifest['version'], 'bytes' => $manifest['archive']['bytes'],
            'sha256' => $manifest['archive']['sha256'], 'reused' => $reused, 'failures' => $failures];
    }

    /** Transférer en flux sans charger le ZIP en mémoire et sans dépasser sa taille signée. */
    protected function transfer(string $url, mixed $stream, int $expectedBytes, int $timeoutMs): void
    {
        $written = 0;
        $oversize = false;
        $writeError = false;
        $handle = curl_init($url);
        if (!curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs), CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_HTTPHEADER => ['Accept: application/zip'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($stream, $expectedBytes, &$written, &$oversize, &$writeError): int {
                // Une réponse HTTP en erreur n’est jamais écrite dans le paquet.
                if (curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                    return strlen($chunk);
                }
                if ($written + strlen($chunk) > $expectedBytes) {
                    $oversize = true;
                    return 0;
                }
                $count = fwrite($stream, $chunk);
                if ($count !== strlen($chunk)) {
                    $writeError = true;
                    return 0;
                }
                $written += $count;
                return $count;
            },
        ])) {
            throw new RuntimeException('Configuration sécurisée de cURL impossible.');
        }
        try {
            $success = curl_exec($handle);
            if ($oversize || $writeError) {
                throw new RuntimeException($oversize
                    ? 'Le ZIP dépasse sa taille signée : téléchargement arrêté.'
                    : 'Écriture du ZIP impossible : téléchargement arrêté.');
            }
            if ($success === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new ServerUnavailableException();
            }
        } finally {
            unset($handle);
        }
    }
}
