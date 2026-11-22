<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\TyCatalog;

use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\TyCatalog\Migration\Mig_1_0_3;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class TyCatalogRetentionMigrationTest extends FrameworkTestCase
{
    #[Test]
    public function migrationAddsRetentionSettingsAndArchiveRemovalMarker(): void
    {
        $app = $this->createApplication();
        $statements = [];

        $db = $this->createMock(Database::class);
        $db->expects(self::exactly(2))->method('execute')
            ->willReturnCallback(static function (string $sql) use (&$statements): int {
                $statements[] = $sql;
                return 1;
            });
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        $result = (new Mig_1_0_3($app))->run();

        self::assertStringContainsString('`keep_versions`', implode("\n", $statements));
        self::assertStringContainsString('`keep_archives`', implode("\n", $statements));
        self::assertStringContainsString('`archive_removed_at`', implode("\n", $statements));
        self::assertSame([
            'ty_catalog_catalogs.keep_versions',
            'ty_catalog_catalogs.keep_archives',
            'ty_catalog_versions.archive_removed_at',
        ], $result['columns']);
    }
}
