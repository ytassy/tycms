<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\TyCatalog;

use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\TyCatalog\Helper\TyCatalogPublicHelper;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogExtension;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogVersion;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class TyCatalogPublicTest extends FrameworkTestCase
{
    #[Test]
    public function versionsAreSortedNumericallyAndPaginated(): void
    {
        $app = $this->createApplication();
        $versions = [];
        foreach ([[1, '0.1.0'], [2, '0.10.0'], [3, '0.2.0']] as [$id, $version]) {
            $item = new CatalogVersion($app);
            $item->id = $id;
            $item->version = $version;
            $versions[] = $item;
        }

        $db = $this->createMock(Database::class);
        $db->expects(self::exactly(2))->method('selectOrm')->willReturn($versions);
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        $extension = new CatalogExtension($app);
        $extension->id = 7;
        $helper = new TyCatalogPublicHelper($app);

        $firstPage = $helper->versions($extension, 1, 2);
        self::assertSame(['0.10.0', '0.2.0'], array_map(
            static fn (CatalogVersion $version): string => $version->version,
            $firstPage['items'],
        ));
        self::assertSame(3, $firstPage['total']);

        $secondPage = $helper->versions($extension, 2, 2);
        self::assertSame(['0.1.0'], array_map(
            static fn (CatalogVersion $version): string => $version->version,
            $secondPage['items'],
        ));
    }

    #[Test]
    public function externalArchiveUrlIsExtractedFromTheReference(): void
    {
        $app = $this->createApplication();
        $version = new CatalogVersion($app);
        $version->storage_type = CatalogVersion::STORAGE_EXTERNAL;
        $version->storage_reference = json_encode([
            'archive' => 'https://cdn.example.org/plg_lms-0_1_0.zip',
            'release_notes' => 'https://cdn.example.org/plg_lms-0_1_0.md',
        ], JSON_THROW_ON_ERROR);

        self::assertSame(
            'https://cdn.example.org/plg_lms-0_1_0.zip',
            (new TyCatalogPublicHelper($app))->externalArchiveUrl($version),
        );
    }
}
