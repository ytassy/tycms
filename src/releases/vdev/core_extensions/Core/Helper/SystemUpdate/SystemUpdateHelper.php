<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate;

use JsonException;
use RuntimeException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/** Consultation bornée et sans écriture : ni ZIP, ni activation, ni persistance d'opération. */
class SystemUpdateHelper extends BaseHelper
{
    private const MAX_CATALOGUE_BYTES = 1048576;
    private const MAX_RELEASES = 256;
    private float $deadline = 0;

    public function configuration(): array
    {
        if (!extension_loaded('curl') || !extension_loaded('sodium')) {
            throw new RuntimeException('Les extensions PHP curl et sodium sont nécessaires pour vérifier les mises à jour.');
        }
        $configuration = $this->app->getConf();
        $servers = $configuration->update_servers ?? null;
        $keys = $configuration->update_trusted_public_keys ?? null;
        if (!is_array($servers) || !array_is_list($servers) || $servers === [] || count($servers) > 8
            || !is_array($keys) || $keys === []) {
            throw new RuntimeException('Configurer update_servers (1 à 8 serveurs) et update_trusted_public_keys dans configuration.php.');
        }

        // Les destinations viennent exclusivement du fichier local, jamais de la requête HTTP.
        foreach ($servers as $server) {
            $parts = is_string($server) ? parse_url($server) : false;
            if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || preg_match('/[\x00-\x20\x7f]/', $server)) {
                throw new RuntimeException('Chaque serveur de mise à jour doit être une URL HTTPS sans identifiants, paramètres ni fragment.');
            }
        }
        foreach ($keys as $id => $key) {
            if (!is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $id) !== 1) {
                throw new RuntimeException('Identifiant de clé de mise à jour invalide.');
            }
            ManifestVerifier::publicKey($key);
        }

        // Le canal est un réglage local, jamais un paramètre du navigateur : une
        // installation ne doit pas pouvoir être basculée en bêta par une URL.
        $channel = $configuration->update_channel ?? ManifestVerifier::CHANNEL_PROD;
        if (!in_array($channel, [ManifestVerifier::CHANNEL_PROD, ManifestVerifier::CHANNEL_BETA], true)) {
            throw new RuntimeException('update_channel accepte uniquement « prod » ou « beta ».');
        }

        return ['servers' => array_values(array_unique($servers)), 'keys' => $keys, 'channel' => $channel];
    }

    public function check(string $source, string $php, bool $devPreview = false): array
    {
        $this->assertDevPreview($devPreview);
        ManifestVerifier::version($source);
        $configuration = $this->configuration();
        $this->deadline = microtime(true) + 20;
        $failures = [];
        foreach ($configuration['servers'] as $index => $server) {
            try {
                // Vérifier tout le catalogue du canal demandé, même les releases écartées ensuite.
                $body = $this->fetch($server . '?action=releases&channel=' . rawurlencode($configuration['channel']),
                    self::MAX_CATALOGUE_BYTES);
            } catch (ServerUnavailableException) {
                $failures[] = ['server' => $server, 'reason' => 'Serveur indisponible ou réponse HTTP en erreur.'];
                continue;
            }
            $manifests = $this->catalogue($body, $configuration['keys']);
            // Défense en profondeur : ignorer une bêta reçue par erreur sur un canal prod.
            if ($configuration['channel'] === ManifestVerifier::CHANNEL_PROD) {
                $manifests = array_values(array_filter($manifests, static fn(array $manifest): bool
                    => ManifestVerifier::channel($manifest) === ManifestVerifier::CHANNEL_PROD));
            }
            $compatible = array_values(array_filter($manifests, static fn(array $manifest): bool =>
                version_compare($manifest['version'], $source, '>')
                && self::acceptsSource($manifest, $source)
                && version_compare($php, $manifest['php_min'], '>=')
                && version_compare($php, $manifest['php_max_exclusive'], '<')));
            usort($compatible, static fn(array $left, array $right): int => version_compare($right['version'], $left['version']));

            // En DEV seulement, proposer la dernière vraie release sans inventer de compatibilité source.
            if ($devPreview) {
                usort($manifests, static fn(array $left, array $right): int => version_compare($right['version'], $left['version']));
            }
            $update = $devPreview ? ($manifests[0] ?? null) : ($compatible[0] ?? null);
            $notes = null;
            if ($update !== null && isset($update['release_notes'])) {
                $notes = $this->notes($update, array_slice($configuration['servers'], $index), $failures);
            }

            return ['source_version' => $source, 'php_version' => $php, 'server' => $server,
                'failures' => $failures, 'available_update' => $update, 'notes' => $notes,
                'compatible_versions' => array_column($compatible, 'version'),
                'dev_preview' => $devPreview, 'php_compatible' => $update !== null
                    && version_compare($php, $update['php_min'], '>=')
                    && version_compare($php, $update['php_max_exclusive'], '<')];
        }

        throw new RuntimeException('Aucun serveur de mise à jour ne répond. Réessayez ultérieurement.');
    }

    public function manifestForDownload(string $source, string $php, string $version, string $sha256, bool $devPreview = false): array
    {
        $this->assertDevPreview($devPreview);
        ManifestVerifier::version($source);
        ManifestVerifier::version($version);
        if (preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1) {
            throw new RuntimeException('Empreinte du paquet attendu invalide.');
        }
        $configuration = $this->configuration();
        $this->deadline = microtime(true) + 20;
        $failures = [];
        foreach ($configuration['servers'] as $index => $server) {
            try {
                $envelope = $this->fetch($server . '?action=manifest&version=' . rawurlencode($version), ManifestVerifier::MAX_MANIFEST_BYTES);
            } catch (ServerUnavailableException) {
                $failures[] = ['server' => $server, 'reason' => 'Manifeste indisponible sur ce serveur.'];
                continue;
            }
            try {
                $manifest = ManifestVerifier::verify(json_decode($envelope, true, 16, JSON_THROW_ON_ERROR), $configuration['keys']);
            } catch (JsonException|RuntimeException $exception) {
                throw new RuntimeException('Manifeste ou signature invalide : téléchargement arrêté.', 0, $exception);
            }

            // Le navigateur désigne une cible attendue, mais ne fournit ni son URL ni son manifeste de confiance.
            if ($manifest['version'] !== $version || !hash_equals($sha256, $manifest['archive']['sha256'])) {
                throw new RuntimeException('Le paquet proposé a changé. Vérifiez à nouveau les mises à jour avant de télécharger.');
            }
            $sourceCompatible = version_compare($version, $source, '>') && self::acceptsSource($manifest, $source);
            if ((!$devPreview && !$sourceCompatible)
                || !version_compare($php, $manifest['php_min'], '>=')
                || !version_compare($php, $manifest['php_max_exclusive'], '<')) {
                throw new RuntimeException('Cette version ne peut pas mettre à jour la version source avec le PHP actuel.');
            }

            return ['manifest' => $manifest, 'envelope' => $envelope,
                'servers' => array_slice($configuration['servers'], $index), 'keys' => $configuration['keys'],
                'failures' => $failures];
        }

        throw new RuntimeException('Aucun serveur ne fournit le manifeste de cette version. Réessayez ultérieurement.');
    }

    /** La première installation reste toujours permise ; « * » couvre en plus toute version antérieure. */
    private static function acceptsSource(array $manifest, string $source): bool
    {
        return ($manifest['source_versions'] ?? null) === '*'
            || in_array($source, $manifest['source_versions'] ?? [], true);
    }

    private function assertDevPreview(bool $devPreview): void
    {
        // Aucun paramètre du navigateur ni Configuration.dev ne peut activer ce mode sur une release stable.
        if ($devPreview && (!defined('APP_VERSION') || APP_VERSION !== 'dev')) {
            throw new RuntimeException('Le mode d’essai des paquets est réservé à la release dev.');
        }
    }

    private function catalogue(string $body, array $keys): array
    {
        try {
            $catalogue = json_decode($body, true, 20, JSON_THROW_ON_ERROR);
            if (!is_array($catalogue) || ($catalogue['schema_version'] ?? null) !== 1
                || !is_array($catalogue['releases'] ?? null) || !array_is_list($catalogue['releases'])
                || count($catalogue['releases']) > self::MAX_RELEASES) {
                throw new RuntimeException('Catalogue invalide.');
            }
            $manifests = [];
            foreach ($catalogue['releases'] as $envelope) {
                $manifest = ManifestVerifier::verify($envelope, $keys);
                if (isset($manifests[$manifest['version']])) {
                    throw new RuntimeException('Version dupliquée.');
                }
                $manifests[$manifest['version']] = $manifest;
            }

            return array_values($manifests);
        } catch (JsonException|RuntimeException $exception) {
            throw new RuntimeException('Catalogue, manifeste ou signature invalide : vérification arrêtée, sans consulter un autre serveur.', 0, $exception);
        }
    }

    private function notes(array $manifest, array $servers, array &$failures): ?string
    {
        foreach ($servers as $server) {
            try {
                $notes = $this->fetch($server . '?action=notes&version=' . rawurlencode($manifest['version']), ManifestVerifier::MAX_NOTES_BYTES);
            } catch (ServerUnavailableException) {
                $failures[] = ['server' => $server, 'reason' => 'Notes de version indisponibles sur ce serveur.'];
                continue;
            }
            if (strlen($notes) !== $manifest['release_notes']['bytes']
                || !hash_equals($manifest['release_notes']['sha256'], hash('sha256', $notes))
                || !mb_check_encoding($notes, 'UTF-8')) {
                throw new RuntimeException('Notes de version altérées ou invalides : vérification arrêtée.');
            }

            return $notes;
        }

        // Une panne des notes n'efface pas l'identité authentifiée de la mise à jour proposée.
        return null;
    }

    protected function fetch(string $url, int $maximumBytes): string
    {
        $body = '';
        $oversize = false;
        $remaining = (int) (($this->deadline - microtime(true)) * 1000);
        if ($remaining <= 0) {
            throw new RuntimeException('Délai global de vérification dépassé. Réessayez ultérieurement.');
        }
        $handle = curl_init($url);
        if (!curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remaining), CURLOPT_TIMEOUT_MS => min(8000, $remaining),
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/markdown'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$oversize, $maximumBytes): int {
                if (strlen($body) + strlen($chunk) > $maximumBytes) {
                    $oversize = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ])) {
            throw new RuntimeException('Configuration sécurisée de cURL impossible.');
        }
        try {
            $success = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($oversize) {
                throw new RuntimeException('Réponse du serveur de mise à jour trop volumineuse : vérification arrêtée.');
            }
            if ($success === false || $status !== 200) {
                throw new ServerUnavailableException();
            }

            return $body;
        } finally {
            unset($handle);
        }
    }
}
