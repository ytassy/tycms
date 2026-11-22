<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\TyCatalog;

use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\TyCatalog\Migration\Mig_1_0_0;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class TyCatalogMigrationTest extends FrameworkTestCase
{
    #[Test]
    public function migrationCreatesTheFrozenSchemaAndGrantsRootPermissions(): void
    {
        $app = $this->createApplication();
        $schema = '';
        $granted = [];

        $db = $this->createMock(Database::class);
        $db->expects(self::once())->method('executeMultiple')
            ->willReturnCallback(static function (string $sql) use (&$schema): array {
                $schema = $sql;
                return [];
            });
        $db->method('execute')->willReturnCallback(
            static function (string $sql, ?array $values) use (&$granted): int {
                if (str_starts_with($sql, 'INSERT INTO acl_permissions')) {
                    $granted[] = $values[1];
                }
                return 1;
            },
        );

        // Le hook $db lit la base de l'Application ; aucune connexion SQL réelle.
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        $result = (new Mig_1_0_0($app))->run();

        foreach (['ty_catalog_catalogs', 'ty_catalog_catalog_links', 'ty_catalog_extensions', 'ty_catalog_versions'] as $table) {
            self::assertStringContainsString('`' . $table . '`', $schema);
        }
        self::assertSame(['VIEW', 'EDIT', 'PUBLISH', 'ADMIN'], $granted);
        self::assertSame($granted, $result['root_permissions']);
    }
}
