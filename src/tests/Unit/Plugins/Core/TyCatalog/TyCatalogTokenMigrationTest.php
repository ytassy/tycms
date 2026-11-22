<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\TyCatalog;

use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\TyCatalog\Migration\Mig_1_0_2;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class TyCatalogTokenMigrationTest extends FrameworkTestCase
{
    #[Test]
    public function migrationCreatesTheTokenTableWithoutGrantingRuntimePermissions(): void
    {
        $app = $this->createApplication();
        $schema = '';

        $db = $this->createMock(Database::class);
        $db->expects(self::once())->method('executeMultiple')
            ->willReturnCallback(static function (string $sql) use (&$schema): array {
                $schema = $sql;
                return [];
            });
        $db->expects(self::never())->method('execute');
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        $result = (new Mig_1_0_2($app))->run();

        self::assertStringContainsString('`ty_catalog_tokens`', $schema);
        self::assertStringContainsString('`token_hash` char(64) NOT NULL', $schema);
        self::assertSame(['ty_catalog_tokens'], $result['tables']);
    }
}
