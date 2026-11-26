<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Extension;

use RuntimeException;
use Throwable;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Models\CatalogSource;
use YTassy\Tycms\Extensions\ExtensionPackage;
use YTassy\Tycms\Http\SafeDownload;

/**
 * Consultation d'un catalogue externe par le client Core.
 *
 * Le client vérifie toujours l'enveloppe signée avec la clé de confiance de la
 * source avant d'annoncer une compatibilité ou une mise à jour. Aucun paquet
 * n'est encore téléchargé ni installé ici.
 */
final class CatalogSourceClient extends BaseHelper
{
    private const JSON_LIMIT = 5_000_000;
    private const JSON_TIMEOUT = 30;

    private SafeDownload $download {
        get { return $this->getSharedExtensionsClass(SafeDownload::class); }
    }

    private CatalogSourceHelper $sources {
        get { return $this->getSharedExtensionsClass(CatalogSourceHelper::class); }
    }

    private ExtensionUpdateState $updateState {
        get { return $this->getSharedExtensionsClass(ExtensionUpdateState::class); }
    }

    /** @return array<string, mixed> */
    public function consult(CatalogSource $source, int $page = 1, int $limit = 100): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $base = rtrim($source->base_url, '/');
        $headers = $this->headers($source);

        $descriptor = $this->json($base, $headers);
        $friends = $this->json($base . '/catalogs?page=1&limit=100', $headers);
        $extensions = $this->json(
            $base . '/extensions?page=' . $page . '&limit=' . $limit,
            $headers,
        );

        $items = [];
        foreach (($extensions['items'] ?? []) as $item) {
            if (!is_array($item) || !is_string($item['identifier'] ?? null)) {
                continue;
            }
            $items[] = $this->consultExtension($base, $source, $item, $headers);
        }

        return [
            'source' => $this->sources->view($source),
            'catalog' => [
                'name' => is_string($descriptor['name'] ?? null) ? $descriptor['name'] : '',
                'description' => is_string($descriptor['description'] ?? null) ? $descriptor['description'] : '',
            ],
            'friends' => $this->friends($friends['items'] ?? []),
            'extensions' => $items,
            'page' => (int) ($extensions['page'] ?? $page),
            'limit' => (int) ($extensions['limit'] ?? $limit),
            'total' => (int) ($extensions['total'] ?? count($items)),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function consultExtension(string $base, CatalogSource $source, array $item, array $headers): array
    {
        $identifier = (string) $item['identifier'];
        $local = $this->localVersion($identifier);
        $result = [
            'identifier' => $identifier,
            'type' => is_string($item['type'] ?? null) ? $item['type'] : '',
            'folder' => is_string($item['folder'] ?? null) ? $item['folder'] : '',
            'name' => is_string($item['name'] ?? null) ? $item['name'] : $identifier,
            'description' => is_string($item['description'] ?? null) ? $item['description'] : '',
            'image_url' => is_string($item['image_url'] ?? null) ? $item['image_url'] : null,
            'local_version' => $local,
            'latest_version' => null,
            'compatible' => null,
            'source_compatible' => $local === null,
            'update_available' => false,
            'pending_update' => null,
            'status' => 'no_version',
            'error' => null,
        ];

        try {
            if ($local !== null) {
                $result['pending_update'] = $this->updateState->pending($identifier);
            }
            $versions = $this->json(
                $this->versionsUrl($source, $identifier),
                $headers,
            );
            $latest = is_array($versions['items'][0] ?? null) ? ($versions['items'][0]['version'] ?? null) : null;
            if (!is_string($latest) || $latest === '') {
                return $result;
            }
            $result['latest_version'] = $latest;

            $detail = $this->json(
                $this->versionUrl($source, $identifier, $latest),
                $headers,
            );
            $metadata = $this->verifiedMetadata($detail, $source, $identifier, $latest);

            $compatible = CatalogCompatibility::compatible($metadata, APP_VERSION, PHP_VERSION);
            $sourceAllowed = $local === null
                ? true
                : CatalogCompatibility::sourceAllows($metadata['source_versions'] ?? null, $local);

            $result['compatible'] = $compatible;
            $result['source_compatible'] = $sourceAllowed;

            if ($compatible === false) {
                $result['status'] = 'incompatible';
            } elseif ($local === null) {
                $result['status'] = 'not_installed';
            } elseif (version_compare($latest, $local, '>') === false) {
                $result['status'] = 'up_to_date';
            } elseif (!$sourceAllowed) {
                $result['status'] = 'update_blocked';
            } else {
                $result['status'] = 'update_available';
                $result['update_available'] = true;
            }
        } catch (Throwable $exception) {
            $result['status'] = 'error';
            $result['error'] = $exception->getMessage();
        }

        return $result;
    }

    /**
     * Dernière version publiée, sans téléchargement d'archive.
     *
     * Une installation ne peut viser que cette version : une requête forgée ne
     * doit pas imposer une version plus ancienne.
     */
    public function latestVersion(CatalogSource $source, string $identifier): string
    {
        $versions = $this->json(
            $this->versionsUrl($source, $identifier),
            $this->headers($source),
        );
        $latest = is_array($versions['items'][0] ?? null) ? ($versions['items'][0]['version'] ?? null) : null;
        if (!is_string($latest) || $latest === '') {
            throw new RuntimeException('Aucune version publiée pour cette extension.');
        }

        return $latest;
    }

    /**
     * Détail d'une version vérifiée, avec l'URL d'archive à télécharger.
     *
     * L'enveloppe est contrôlée avec la clé de la source. Les tailles et
     * empreintes du transport doivent correspondre au manifeste signé.
     *
     * @return array{metadata:array<string,mixed>,envelope:string,manifest:array<string,mixed>,
     *     archive:array{url:string,bytes:int,sha256:string},compatible:?bool}
     */
    public function verifiedVersion(CatalogSource $source, string $identifier, string $version): array
    {
        $base = rtrim($source->base_url, '/');
        $detail = $this->json(
            $this->versionUrl($source, $identifier, $version),
            $this->headers($source),
        );
        $verified = $this->verifyDetail($detail, $source, $identifier, $version);
        $manifest = $verified['manifest'];

        $transport = $detail['transport']['archive'] ?? null;
        if (!is_array($transport)
            || !is_string($transport['url'] ?? null) || $transport['url'] === ''
            || !is_int($transport['bytes'] ?? null) || $transport['bytes'] < 1
            || $transport['bytes'] > ExtensionPackage::MAX_ZIP_BYTES
            || !is_string($transport['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $transport['sha256']) !== 1) {
            throw new RuntimeException('Transport d’archive invalide.');
        }
        if ($transport['bytes'] !== $manifest['archive']['bytes']
            || !hash_equals($manifest['archive']['sha256'], $transport['sha256'])) {
            throw new RuntimeException('Taille ou empreinte d’archive différente du manifeste signé.');
        }

        return [
            'metadata' => $verified['metadata'],
            'envelope' => $verified['envelope'],
            'manifest' => $manifest,
            'archive' => [
                'url' => $transport['url'],
                'bytes' => $transport['bytes'],
                'sha256' => $transport['sha256'],
            ],
            'compatible' => CatalogCompatibility::compatible(
                $verified['metadata'],
                APP_VERSION,
                PHP_VERSION,
            ),
        ];
    }

    /**
     * En-têtes du téléchargement d'archive.
     *
     * La licence ne part que vers l'origine de la source. Une URL de CDN
     * externe reçoit uniquement le type attendu.
     *
     * @return list<string>
     */
    public function archiveHeaders(CatalogSource $source, string $archiveUrl): array
    {
        $headers = ['Accept: application/zip'];
        if (CatalogSourceUrl::sameOrigin($source->base_url, $archiveUrl)) {
            $license = $this->sources->license($source);
            if ($license !== null) {
                $headers[] = 'X-TyCMS-License: ' . $license;
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $detail
     * @return array{metadata:array<string,mixed>,envelope:string,manifest:array<string,mixed>}
     */
    private function verifyDetail(array $detail, CatalogSource $source, string $identifier, string $version): array
    {
        $envelope = $detail['envelope'] ?? null;
        $envelopeJson = is_array($envelope)
            ? json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : (is_string($envelope) ? $envelope : null);
        if ($envelopeJson === null) {
            throw new RuntimeException('Enveloppe de signature absente.');
        }

        $manifest = ExtensionPackage::verify(
            $envelopeJson,
            [$source->key_id => $source->public_key],
        );
        $metadata = $manifest['metadata'];
        if (($metadata['id'] ?? null) !== $identifier || ($metadata['version'] ?? null) !== $version) {
            throw new RuntimeException('Identité ou version différente du manifeste signé.');
        }

        return ['metadata' => $metadata, 'envelope' => $envelopeJson, 'manifest' => $manifest];
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function verifiedMetadata(array $detail, CatalogSource $source, string $identifier, string $version): array
    {
        return $this->verifyDetail($detail, $source, $identifier, $version)['metadata'];
    }

    private function localVersion(string $identifier): ?string
    {
        $extension = $this->app->getExtensions()[$identifier] ?? null;
        if ($extension === null) {
            return null;
        }

        try {
            return $extension->getVersion();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $headers @return array<string, mixed> */
    private function json(string $url, array $headers): array
    {
        $body = $this->download->get($url, self::JSON_LIMIT, self::JSON_TIMEOUT, $headers);
        $decoded = json_decode($body, true, 32);
        if (!is_array($decoded) || ($decoded['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('Réponse de catalogue invalide.');
        }
        if (isset($decoded['error'])) {
            $message = is_array($decoded['error']) && is_string($decoded['error']['message'] ?? null)
                ? $decoded['error']['message']
                : 'Le catalogue a refusé la demande.';
            throw new RuntimeException($message);
        }

        return $decoded;
    }

    /** @return list<string> */
    /**
     * Canal demandé à cette source.
     *
     * Une valeur inconnue retombe sur `release` : une base modifiée à la main ne
     * doit pas faire échouer une consultation, ni faire recevoir une bêta à une
     * instance qui ne l'a pas demandée.
     */
    private function channel(CatalogSource $source): string
    {
        return $source->channel === 'beta' ? 'beta' : 'release';
    }

    private function versionsUrl(CatalogSource $source, string $identifier): string
    {
        return rtrim($source->base_url, '/')
            . '/extensions/' . rawurlencode($identifier)
            . '/versions?page=1&limit=1&channel=' . $this->channel($source);
    }

    private function versionUrl(CatalogSource $source, string $identifier, string $version): string
    {
        return rtrim($source->base_url, '/')
            . '/extensions/' . rawurlencode($identifier)
            . '/versions/' . rawurlencode($version)
            . '?channel=' . $this->channel($source);
    }

    private function headers(CatalogSource $source): array
    {
        $headers = ['Accept: application/json'];
        $license = $this->sources->license($source);
        if ($license !== null) {
            $headers[] = 'X-TyCMS-License: ' . $license;
        }

        return $headers;
    }

    /**
     * @param mixed $items
     * @return list<array<string, mixed>>
     */
    private function friends(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $friends = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || !is_string($item['url'] ?? null)) {
                continue;
            }
            $friends[] = [
                'name' => $item['name'],
                'url' => $item['url'],
                'description' => is_string($item['description'] ?? null) ? $item['description'] : '',
            ];
        }

        return $friends;
    }
}
