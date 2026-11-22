<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\TyCatalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\TyCatalog\Helper\TyCatalogRetention;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogVersion;

final class TyCatalogRetentionTest extends TestCase
{
    #[Test]
    public function oldestVersionsAreDeletedPerWindowIncludingDrafts(): void
    {
        $plan = TyCatalogRetention::plan([
            $this->version(1, '2026-01-01 10:00:00', CatalogVersion::STATUS_PUBLISHED),
            $this->version(2, '2026-01-02 10:00:00', CatalogVersion::STATUS_DRAFT),
            $this->version(3, '2026-01-03 10:00:00', CatalogVersion::STATUS_PUBLISHED),
            $this->version(4, '2026-01-04 10:00:00', CatalogVersion::STATUS_WITHDRAWN),
            $this->version(5, '2026-01-05 10:00:00', CatalogVersion::STATUS_PUBLISHED),
        ], 3, 3);

        self::assertSame([2, 1], $plan['delete_version_ids']);
    }

    #[Test]
    public function latestPublishedVersionIsProtectedFromANewerDraft(): void
    {
        $plan = TyCatalogRetention::plan([
            $this->version(10, '2026-01-05 10:00:00', CatalogVersion::STATUS_DRAFT),
            $this->version(9, '2026-01-04 10:00:00', CatalogVersion::STATUS_PUBLISHED, storeLocal: true),
            $this->version(8, '2026-01-03 10:00:00', CatalogVersion::STATUS_PUBLISHED, storeLocal: true),
        ], 1, 1);

        // Le brouillon occupe la fenêtre, mais la dernière version publiée et son ZIP restent.
        self::assertSame([8], $plan['delete_version_ids']);
        self::assertSame([], $plan['remove_archive_ids']);
    }

    #[Test]
    public function onlyLocalArchivesOutsideTheWindowAreRemoved(): void
    {
        $plan = TyCatalogRetention::plan([
            $this->version(5, '2026-01-05 10:00:00', CatalogVersion::STATUS_PUBLISHED, storeLocal: true),
            $this->version(4, '2026-01-04 10:00:00', CatalogVersion::STATUS_PUBLISHED, storeLocal: true),
            $this->version(3, '2026-01-03 10:00:00', CatalogVersion::STATUS_PUBLISHED, storeLocal: false),
            $this->version(2, '2026-01-02 10:00:00', CatalogVersion::STATUS_PUBLISHED, storeLocal: true),
        ], 4, 1);

        // Le ZIP de la version publiée la plus récente est toujours conservé.
        self::assertSame([4, 2], $plan['remove_archive_ids']);
        self::assertSame([], $plan['delete_version_ids']);
    }

    #[Test]
    public function alreadyRemovedArchivesAreNotCountedAgain(): void
    {
        $plan = TyCatalogRetention::plan([
            $this->version(5, '2026-01-05 10:00:00', CatalogVersion::STATUS_PUBLISHED, storeLocal: true),
            $this->version(4, '2026-01-04 10:00:00', CatalogVersion::STATUS_PUBLISHED, storeLocal: true, archiveRemoved: true),
        ], 4, 1);

        self::assertSame([], $plan['remove_archive_ids']);
    }

    /** @return array{id:int,status:string,last_activity:string,storage_type:string,archive_removed:bool} */
    private function version(
        int $id,
        string $lastActivity,
        string $status,
        bool $storeLocal = true,
        bool $archiveRemoved = false,
    ): array {
        return [
            'id' => $id,
            'status' => $status,
            'last_activity' => $lastActivity,
            'storage_type' => $storeLocal ? CatalogVersion::STORAGE_LOCAL : CatalogVersion::STORAGE_EXTERNAL,
            'archive_removed' => $archiveRemoved,
        ];
    }
}
