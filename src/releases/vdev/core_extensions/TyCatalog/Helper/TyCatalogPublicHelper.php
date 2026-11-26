<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Helper;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\TyCatalog\Models\Catalog;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogExtension;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogLink;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogVersion;
use YTassy\Tycms\CoreExts\TyCatalog\Traits\TraitTyCatalogHelpers;

/**
 * Lectures publiques d'un catalogue activé.
 *
 * Aucune session, aucun Space courant et aucune donnée privée ne participent
 * aux réponses. Seules les versions publiées dont l'archive existe encore sont
 * distribuées.
 */
final class TyCatalogPublicHelper extends BaseHelper
{
    use TraitTyCatalogHelpers;

    public function catalog(string $slug): ?Catalog
    {
        return $this->db->selectOrmOneOrNull(
            Catalog::class,
            'SELECT * FROM ty_catalog_catalogs WHERE slug = % AND enabled = 1',
            [$slug],
        );
    }

    /** @return array{items:list<CatalogLink>,total:int} */
    public function links(Catalog $catalog, int $page, int $limit): array
    {
        $totalRow = $this->db->selectOneOrNull(
            'SELECT COUNT(*) AS total FROM ty_catalog_catalog_links WHERE catalog_id = %',
            [$catalog->id],
        );
        $total = (int) ($totalRow?->total ?? 0);

        $links = $this->db->selectOrm(
            CatalogLink::class,
            'SELECT * FROM ty_catalog_catalog_links
             WHERE catalog_id = %
             ORDER BY position, id
             LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit),
            [$catalog->id],
        );

        return ['items' => $links, 'total' => $total];
    }

    /** @return array{items:list<CatalogExtension>,total:int} */
    public function extensions(Catalog $catalog, int $page, int $limit): array
    {
        $distributable = "EXISTS (
            SELECT 1 FROM ty_catalog_versions version
            WHERE version.extension_id = extension.id
              AND version.status = 'published'
              AND version.archive_removed_at IS NULL
        )";

        $totalRow = $this->db->selectOneOrNull(
            'SELECT COUNT(*) AS total FROM ty_catalog_extensions extension
             WHERE extension.catalog_id = % AND ' . $distributable,
            [$catalog->id],
        );
        $total = (int) ($totalRow?->total ?? 0);

        $extensions = $this->db->selectOrm(
            CatalogExtension::class,
            'SELECT extension.* FROM ty_catalog_extensions extension
             WHERE extension.catalog_id = % AND ' . $distributable . '
             ORDER BY extension.extension_identifier
             LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit),
            [$catalog->id],
        );

        return ['items' => $extensions, 'total' => $total];
    }

    public function extension(Catalog $catalog, string $identifier): ?CatalogExtension
    {
        $extension = $this->db->selectOrmOneOrNull(
            CatalogExtension::class,
            'SELECT extension.* FROM ty_catalog_extensions extension
             WHERE extension.catalog_id = % AND extension.extension_identifier = %',
            [$catalog->id, $identifier],
        );
        if ($extension === null) {
            return null;
        }

        $present = $this->db->selectOneOrNull(
            'SELECT 1 AS present FROM ty_catalog_versions
             WHERE extension_id = % AND status = % AND archive_removed_at IS NULL
             LIMIT 1',
            [$extension->id, CatalogVersion::STATUS_PUBLISHED],
        );

        return $present === null ? null : $extension;
    }

    /**
     * Versions publiées d'une extension, filtrées par canal.
     *
     * Par défaut, seules les versions release sont servies. Le canal bêta
     * ajoute les versions en bêta **et** les release : un client en bêta doit
     * pouvoir revenir à une version stable sans changer de catalogue.
     *
     * @return array{items:list<CatalogVersion>,total:int}
     */
    public function versions(CatalogExtension $extension, int $page, int $limit,
        string $channel = CatalogVersion::CHANNEL_RELEASE): array
    {
        $channels = self::channelsFor($channel);
        $versions = $this->db->selectOrm(
            CatalogVersion::class,
            'SELECT * FROM ty_catalog_versions
             WHERE extension_id = % AND status = % AND archive_removed_at IS NULL
               AND channel IN (' . implode(', ', array_fill(0, count($channels), '%')) . ')',
            [$extension->id, CatalogVersion::STATUS_PUBLISHED, ...$channels],
        );

        usort($versions, static function (CatalogVersion $first, CatalogVersion $second): int {
            return version_compare($second->version, $first->version) ?: $second->id <=> $first->id;
        });

        return [
            'items' => array_slice($versions, ($page - 1) * $limit, $limit),
            'total' => count($versions),
        ];
    }

    public function version(Catalog $catalog, string $identifier, string $version,
        string $channel = CatalogVersion::CHANNEL_RELEASE): ?CatalogVersion
    {
        $channels = self::channelsFor($channel);

        return $this->db->selectOrmOneOrNull(
            CatalogVersion::class,
            'SELECT version.*
             FROM ty_catalog_versions version
             INNER JOIN ty_catalog_extensions extension ON extension.id = version.extension_id
             WHERE extension.catalog_id = %
               AND extension.extension_identifier = %
               AND version.version = %
               AND version.status = %
               AND version.archive_removed_at IS NULL
               AND version.channel IN (' . implode(', ', array_fill(0, count($channels), '%')) . ')',
            [$catalog->id, $identifier, $version, CatalogVersion::STATUS_PUBLISHED, ...$channels],
        );
    }

    /**
     * Canaux servis pour une demande.
     *
     * @return list<string>
     */
    private static function channelsFor(string $channel): array
    {
        return $channel === CatalogVersion::CHANNEL_BETA
            ? [CatalogVersion::CHANNEL_BETA, CatalogVersion::CHANNEL_RELEASE]
            : [CatalogVersion::CHANNEL_RELEASE];
    }

    /** Chemin privé de l'archive locale, ou null si elle est absente ou retirée. */
    public function localArchivePath(CatalogVersion $version): ?string
    {
        if ($version->storage_type !== CatalogVersion::STORAGE_LOCAL || $version->archive_removed_at !== null) {
            return null;
        }

        $root = $this->tyCatalogStorage->existingRoot();
        if ($root === null) {
            return null;
        }
        $relative = trim($version->storage_reference, '/');
        if ($relative === '') {
            return null;
        }
        $directory = realpath($root . '/' . $relative);
        if ($directory === false || !str_starts_with($directory, $root . '/') || !is_dir($directory)) {
            return null;
        }

        $archive = $directory . '/archive.zip';

        return is_file($archive) && !is_link($archive) ? $archive : null;
    }

    public function externalArchiveUrl(CatalogVersion $version): ?string
    {
        if ($version->storage_type !== CatalogVersion::STORAGE_EXTERNAL) {
            return null;
        }

        $reference = json_decode($version->storage_reference, true);

        return is_array($reference) && is_string($reference['archive'] ?? null) && $reference['archive'] !== ''
            ? $reference['archive']
            : null;
    }

    /** @return array<string, mixed>|null */
    public function envelope(CatalogVersion $version): ?array
    {
        $envelope = json_decode($version->signature_envelope, true, 16);

        return is_array($envelope) ? $envelope : null;
    }
}
