<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Helper;

use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogVersion;

/**
 * Calcule les versions à supprimer et les archives à retirer, sans accès SQL ni
 * disque. La dernière version publiée reste toujours conservée, avec son ZIP
 * local : un brouillon plus récent ne peut pas vider le catalogue distribué.
 */
final class TyCatalogRetention
{
    /**
     * @param list<array{id:int,status:string,last_activity:string,storage_type:string,archive_removed:bool}> $versions
     * @return array{delete_version_ids:list<int>,remove_archive_ids:list<int>}
     */
    public static function plan(array $versions, int $keepVersions, int $keepArchives): array
    {
        $keepVersions = max(1, min(100, $keepVersions));
        $keepArchives = max(1, min(100, $keepArchives));

        usort($versions, static function (array $first, array $second): int {
            return strcmp($second['last_activity'], $first['last_activity']) ?: $second['id'] <=> $first['id'];
        });

        $keepIds = [];
        foreach (array_slice($versions, 0, $keepVersions) as $version) {
            $keepIds[$version['id']] = true;
        }

        // La dernière version publiée est protégée même si un brouillon plus
        // récent occupe la fenêtre. La limite peut donc être dépassée de 1.
        $latestPublished = null;
        foreach ($versions as $version) {
            if ($version['status'] === CatalogVersion::STATUS_PUBLISHED) {
                $latestPublished = $version;
                break;
            }
        }
        if ($latestPublished !== null) {
            $keepIds[$latestPublished['id']] = true;
        }

        $kept = array_values(array_filter(
            $versions,
            static fn (array $version): bool => isset($keepIds[$version['id']]),
        ));
        $deleteIds = array_values(array_map(
            static fn (array $version): int => $version['id'],
            array_filter($versions, static fn (array $version): bool => !isset($keepIds[$version['id']])),
        ));

        $local = array_values(array_filter(
            $kept,
            static fn (array $version): bool => $version['storage_type'] === CatalogVersion::STORAGE_LOCAL
                && $version['archive_removed'] === false,
        ));
        $archiveKeepIds = [];
        foreach (array_slice($local, 0, $keepArchives) as $version) {
            $archiveKeepIds[$version['id']] = true;
        }
        if ($latestPublished !== null && $latestPublished['storage_type'] === CatalogVersion::STORAGE_LOCAL
            && $latestPublished['archive_removed'] === false) {
            $archiveKeepIds[$latestPublished['id']] = true;
        }

        $removeArchiveIds = array_values(array_map(
            static fn (array $version): int => $version['id'],
            array_filter($local, static fn (array $version): bool => !isset($archiveKeepIds[$version['id']])),
        ));

        return ['delete_version_ids' => $deleteIds, 'remove_archive_ids' => $removeArchiveIds];
    }
}
