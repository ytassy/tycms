<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\TyCatalog;

use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\TyCatalog\Helper\TyCatalogTokenHelper;
use YTassy\Tycms\CoreExts\TyCatalog\Models\Catalog;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class TyCatalogTokenTest extends FrameworkTestCase
{
    #[Test]
    public function tokenIsReturnedOnceAndOnlyItsHashIsStored(): void
    {
        $app = $this->createApplication();
        $inserted = [];
        $db = $this->createMock(Database::class);
        $db->expects(self::once())->method('execute')
            ->willReturnCallback(static function (string $sql, ?array $values) use (&$inserted): int {
                $inserted = ['sql' => $sql, 'values' => $values];
                return 1;
            });
        $db->method('getLastInsertId')->willReturn(42);
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        $catalog = new Catalog($app);
        $catalog->id = 5;
        $result = (new TyCatalogTokenHelper($app))->create($catalog, 'CI', ['publish', 'deposit'], 1, 30);

        self::assertMatchesRegularExpression('/\Atycat_[a-f0-9]{64}\z/', $result['token']);
        self::assertSame(42, $result['id']);
        self::assertSame(['deposit', 'publish'], $result['abilities']);
        self::assertSame(hash('sha256', $result['token']), $inserted['values'][2]);
        self::assertSame('deposit,publish', $inserted['values'][3]);
        self::assertNotNull($result['expires_at']);
    }
}
