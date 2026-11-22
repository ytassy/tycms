<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Controller;

use DateTimeZone;
use InvalidArgumentException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\TyCatalog\Models\Catalog;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogExtension;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogLink;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogVersion;
use YTassy\Tycms\CoreExts\TyCatalog\Traits\TraitTyCatalogHelpers;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\FileResponse;
use YTassy\Tycms\Response\JsonResponse;
use YTassy\Tycms\Response\RedirectResponse;
use YTassy\Tycms\Response\Response;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;

/**
 * API publique de distribution d'un catalogue activé.
 *
 * Le protocole est indépendant de la session, du Space courant et de la langue.
 * Les brouillons, versions retirées et archives supprimées ne sont jamais
 * exposés.
 */
#[Controller(name: 'tycms_catalog_public_', isApi: true, path: 'tycms-catalog', withoutCsrf: true)]
final class TyCatalogPublicController extends BaseController
{
    use TraitTyCatalogHelpers;

    private const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';
    private const IDENTIFIER_PATTERN = '[a-z0-9_]+';
    private const VERSION_PATTERN = '(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)';

    #[ControllerMethod(name: 'catalog_descriptor', path: '{slug}', where: ['slug' => self::SLUG_PATTERN])]
    public function descriptor(string $slug): Response
    {
        $catalog = $this->tyCatalogPublicHelper->catalog($slug);
        if ($catalog === null) {
            return $this->error(404, 'not_found', 'Catalogue introuvable.');
        }

        return new JsonResponse([
            'schema_version' => 1,
            'name' => $catalog->name,
            'description' => $catalog->description,
        ]);
    }

    #[ControllerMethod(name: 'catalog_links', path: '{slug}/catalogs', where: ['slug' => self::SLUG_PATTERN])]
    public function catalogs(string $slug): Response
    {
        $catalog = $this->tyCatalogPublicHelper->catalog($slug);
        if ($catalog === null) {
            return $this->error(404, 'not_found', 'Catalogue introuvable.');
        }
        $pagination = $this->pagination();
        if ($pagination instanceof JsonResponse) {
            return $pagination;
        }
        [$page, $limit] = $pagination;

        $result = $this->tyCatalogPublicHelper->links($catalog, $page, $limit);

        return new JsonResponse([
            'schema_version' => 1,
            'items' => array_map($this->linkItem(...), $result['items']),
            'page' => $page,
            'limit' => $limit,
            'total' => $result['total'],
        ]);
    }

    #[ControllerMethod(name: 'catalog_extensions', path: '{slug}/extensions', where: ['slug' => self::SLUG_PATTERN])]
    public function extensions(string $slug): Response
    {
        $catalog = $this->tyCatalogPublicHelper->catalog($slug);
        if ($catalog === null) {
            return $this->error(404, 'not_found', 'Catalogue introuvable.');
        }
        $pagination = $this->pagination();
        if ($pagination instanceof JsonResponse) {
            return $pagination;
        }
        [$page, $limit] = $pagination;

        $result = $this->tyCatalogPublicHelper->extensions($catalog, $page, $limit);

        return new JsonResponse([
            'schema_version' => 1,
            'items' => array_map($this->extensionItem(...), $result['items']),
            'page' => $page,
            'limit' => $limit,
            'total' => $result['total'],
        ]);
    }

    #[ControllerMethod(
        name: 'catalog_extension',
        path: '{slug}/extensions/{extensionId}',
        where: ['slug' => self::SLUG_PATTERN, 'extensionId' => self::IDENTIFIER_PATTERN],
    )]
    public function extension(string $slug, string $extensionId): Response
    {
        $catalog = $this->tyCatalogPublicHelper->catalog($slug);
        if ($catalog === null) {
            return $this->error(404, 'not_found', 'Catalogue introuvable.');
        }
        $extension = $this->tyCatalogPublicHelper->extension($catalog, $extensionId);
        if ($extension === null) {
            return $this->error(404, 'not_found', 'Extension introuvable.');
        }

        return new JsonResponse($this->extensionItem($extension));
    }

    #[ControllerMethod(
        name: 'catalog_extension_versions',
        path: '{slug}/extensions/{extensionId}/versions',
        where: ['slug' => self::SLUG_PATTERN, 'extensionId' => self::IDENTIFIER_PATTERN],
    )]
    public function versions(string $slug, string $extensionId): Response
    {
        $catalog = $this->tyCatalogPublicHelper->catalog($slug);
        if ($catalog === null) {
            return $this->error(404, 'not_found', 'Catalogue introuvable.');
        }
        $extension = $this->tyCatalogPublicHelper->extension($catalog, $extensionId);
        if ($extension === null) {
            return $this->error(404, 'not_found', 'Extension introuvable.');
        }
        $pagination = $this->pagination();
        if ($pagination instanceof JsonResponse) {
            return $pagination;
        }
        [$page, $limit] = $pagination;

        $result = $this->tyCatalogPublicHelper->versions($extension, $page, $limit);

        return new JsonResponse([
            'schema_version' => 1,
            'items' => array_map($this->versionItem(...), $result['items']),
            'page' => $page,
            'limit' => $limit,
            'total' => $result['total'],
        ]);
    }

    #[ControllerMethod(
        name: 'catalog_extension_version',
        path: '{slug}/extensions/{extensionId}/versions/{version}',
        where: [
            'slug' => self::SLUG_PATTERN,
            'extensionId' => self::IDENTIFIER_PATTERN,
            'version' => self::VERSION_PATTERN,
        ],
    )]
    public function version(string $slug, string $extensionId, string $version): Response
    {
        $catalog = $this->tyCatalogPublicHelper->catalog($slug);
        if ($catalog === null) {
            return $this->error(404, 'not_found', 'Catalogue introuvable.');
        }
        $found = $this->tyCatalogPublicHelper->version($catalog, $extensionId, $version);
        if ($found === null) {
            return $this->error(404, 'not_found', 'Version introuvable.');
        }

        $envelope = $this->tyCatalogPublicHelper->envelope($found);
        if ($envelope === null) {
            return $this->error(404, 'not_found', 'Version introuvable.');
        }

        // Pour une référence externe, exposer directement l'URL finale : le
        // client ne suit pas de redirection et n'envoie pas la licence au CDN.
        $archiveUrl = $this->versionUrl($slug, $extensionId, $found->version, 'download');
        if ($found->storage_type === CatalogVersion::STORAGE_EXTERNAL) {
            $external = $this->tyCatalogPublicHelper->externalArchiveUrl($found);
            if ($external !== null) {
                $archiveUrl = $external;
            }
        }

        return new JsonResponse([
            'schema_version' => 1,
            'extension' => $extensionId,
            'version' => $found->version,
            'envelope' => $envelope,
            'transport' => [
                'archive' => [
                    'url' => $archiveUrl,
                    'bytes' => $found->archive_bytes,
                    'sha256' => $found->archive_sha256,
                ],
                'release_notes' => [
                    'url' => $this->versionUrl($slug, $extensionId, $found->version, 'notes'),
                    'bytes' => strlen($found->release_notes),
                    'sha256' => hash('sha256', $found->release_notes),
                ],
            ],
        ]);
    }

    #[ControllerMethod(
        name: 'catalog_extension_version_download',
        path: '{slug}/extensions/{extensionId}/versions/{version}/download',
        where: [
            'slug' => self::SLUG_PATTERN,
            'extensionId' => self::IDENTIFIER_PATTERN,
            'version' => self::VERSION_PATTERN,
        ],
    )]
    public function download(string $slug, string $extensionId, string $version): Response
    {
        $found = $this->distributableVersion($slug, $extensionId, $version);
        if ($found instanceof JsonResponse) {
            return $found;
        }

        $path = $this->tyCatalogPublicHelper->localArchivePath($found);
        if ($path !== null) {
            return new FileResponse(
                $path,
                null,
                $extensionId . '-' . $found->version . '.zip',
                false,
                'application/zip',
            );
        }

        $url = $this->tyCatalogPublicHelper->externalArchiveUrl($found);
        if ($url === null) {
            return $this->error(404, 'not_found', 'Archive introuvable.');
        }

        try {
            return RedirectResponse::external($url, 302);
        } catch (InvalidArgumentException) {
            return $this->error(404, 'not_found', 'Archive introuvable.');
        }
    }

    #[ControllerMethod(
        name: 'catalog_extension_version_notes',
        path: '{slug}/extensions/{extensionId}/versions/{version}/notes',
        where: [
            'slug' => self::SLUG_PATTERN,
            'extensionId' => self::IDENTIFIER_PATTERN,
            'version' => self::VERSION_PATTERN,
        ],
    )]
    public function notes(string $slug, string $extensionId, string $version): Response
    {
        $found = $this->distributableVersion($slug, $extensionId, $version);
        if ($found instanceof JsonResponse) {
            return $found;
        }

        return FileResponse::fromContent(
            $found->release_notes,
            $extensionId . '-' . $found->version . '.md',
            'text/markdown',
        );
    }

    private function distributableVersion(string $slug, string $extensionId, string $version): CatalogVersion|JsonResponse
    {
        $catalog = $this->tyCatalogPublicHelper->catalog($slug);
        if ($catalog === null) {
            return $this->error(404, 'not_found', 'Catalogue introuvable.');
        }
        $found = $this->tyCatalogPublicHelper->version($catalog, $extensionId, $version);

        return $found ?? $this->error(404, 'not_found', 'Version introuvable.');
    }

    /** @return array{0:int,1:int}|JsonResponse */
    private function pagination(): array|JsonResponse
    {
        $page = $this->request->query('page', '1');
        $limit = $this->request->query('limit', '50');

        if (!is_string($page) || preg_match('/\A[1-9][0-9]*\z/', $page) !== 1) {
            return $this->error(400, 'invalid_request', 'page doit être un entier positif.');
        }
        if (!is_string($limit) || preg_match('/\A[1-9][0-9]*\z/', $limit) !== 1) {
            return $this->error(400, 'invalid_request', 'limit doit être un entier positif.');
        }

        return [(int) $page, min(100, (int) $limit)];
    }

    /** @return array<string, mixed> */
    private function linkItem(CatalogLink $link): array
    {
        return ['name' => $link->name, 'url' => $link->target_url, 'description' => $link->description];
    }

    /** @return array<string, mixed> */
    private function extensionItem(CatalogExtension $extension): array
    {
        return [
            'identifier' => $extension->extension_identifier,
            'type' => $extension->type,
            'folder' => $extension->folder,
            'name' => $extension->display_name,
            'description' => $extension->description,
            'image_url' => $extension->image_url,
        ];
    }

    /** @return array<string, mixed> */
    private function versionItem(CatalogVersion $version): array
    {
        return ['version' => $version->version, 'published_at' => $this->date($version->published_at)];
    }

    private function date(?\DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function versionUrl(string $slug, string $identifier, string $version, string $suffix): string
    {
        return $this->baseUrl()
            . '/tycms-catalog/' . rawurlencode($slug)
            . '/extensions/' . rawurlencode($identifier)
            . '/versions/' . rawurlencode($version)
            . '/' . $suffix;
    }

    private function baseUrl(): string
    {
        $authority = $this->request->host;
        if (($this->request->is_https && $this->request->port !== 443)
            || (!$this->request->is_https && $this->request->port !== 80)) {
            $authority .= ':' . $this->request->port;
        }

        return ($this->request->is_https ? 'https' : 'http') . '://' . $authority;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'schema_version' => 1,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
