<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\ExtensionCatalog;

use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\CatalogSourceHelper;
use YTassy\Tycms\CoreExts\Core\Models\CatalogSource;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class CatalogSourceHelperTest extends FrameworkTestCase
{
    #[Test]
    public function licenseIsEncryptedAndNeverStoredInClear(): void
    {
        $app = $this->createApplication();
        $app->getConf()->secret = 'test-secret-for-extension-catalog';
        $inserted = [];

        $db = $this->createMock(Database::class);
        $db->expects(self::once())->method('execute')
            ->willReturnCallback(static function (string $sql, ?array $values) use (&$inserted): int {
                $inserted = $values;
                return 1;
            });
        $db->method('getLastInsertId')->willReturn(42);

        $saved = new CatalogSource($app);
        $saved->id = 42;
        $saved->name = 'Catalogue';
        $saved->base_url = 'https://example.org/tycms-catalog/official';
        $saved->key_id = 'extensions-store-1';
        $saved->public_key = str_repeat('A', 44);
        $saved->license_encrypted = null;
        $saved->enabled = true;
        $saved->created = new \DateTimeImmutable();

        $db->method('selectOrmOneOrNull')->willReturn($saved);
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        $helper = new CatalogSourceHelper($app);
        $result = $helper->save(null, [
            'name' => 'Catalogue',
            'base_url' => 'https://example.org/tycms-catalog/official',
            'key_id' => 'extensions-store-1',
            'public_key' => str_repeat('A', 44),
            'enabled' => true,
            'license' => 'ma-licence-secrete',
            'clear_license' => false,
        ], 1);

        self::assertSame(42, $result->id);
        self::assertArrayHasKey(4, $inserted);
        self::assertNotSame('ma-licence-secrete', $inserted[4]);
        self::assertStringStartsWith('tycmscrypt:v1:', $inserted[4]);

        $check = new CatalogSource($app);
        $check->license_encrypted = $inserted[4];
        self::assertSame('ma-licence-secrete', $helper->license($check));
    }
}
