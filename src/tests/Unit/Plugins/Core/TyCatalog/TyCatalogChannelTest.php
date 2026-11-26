<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\TyCatalog;

use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\TyCatalog\Migration\Mig_1_0_5;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogExtension;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogVersion;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

/** Canal de diffusion des versions d'extension : colonne, valeurs, filtrage. */
final class TyCatalogChannelTest extends FrameworkTestCase
{
    #[Test]
    public function migrationAddsTheChannelColumnWithReleaseDefault(): void
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

        $result = (new Mig_1_0_5($app))->run();
        $sql = implode("\n", $statements);

        self::assertStringContainsString('`channel`', $sql);
        // Les versions déjà publiées doivent rester servies sans intervention.
        self::assertStringContainsString("DEFAULT 'release'", $sql);
        self::assertSame(['ty_catalog_versions.channel'], $result['columns']);
    }

    #[Test]
    public function aNewVersionDefaultsToTheReleaseChannel(): void
    {
        $app = $this->createApplication();
        $version = new CatalogVersion($app);

        self::assertSame(CatalogVersion::CHANNEL_RELEASE, $version->channel);
        self::assertSame(['release', 'beta'], CatalogVersion::CHANNELS);
    }

    #[Test]
    public function thePublicListServesOnlyReleaseByDefault(): void
    {
        $app = $this->createApplication();
        $params = [];

        $db = $this->createStub(Database::class);
        $db->method('selectOrm')
            ->willReturnCallback(static function (string $class, string $sql, array $values) use (&$params): array {
                $params[] = $values;
                return [];
            });
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        $extension = new CatalogExtension($app);
        $extension->id = 7;
        $helper = new \YTassy\Tycms\CoreExts\TyCatalog\Helper\TyCatalogPublicHelper($app);
        $helper->versions($extension, 1, 10);

        self::assertCount(1, $params);
        // Aucune mention de la bêta : un client qui n'en parle pas ne la reçoit pas.
        self::assertNotContains('beta', $params[0]);
        self::assertContains('release', $params[0]);
    }

    #[Test]
    public function thePublicListAddsBetaWhenExplicitlyRequested(): void
    {
        $app = $this->createApplication();
        $params = [];

        $db = $this->createStub(Database::class);
        $db->method('selectOrm')
            ->willReturnCallback(static function (string $class, string $sql, array $values) use (&$params): array {
                $params[] = $values;
                return [];
            });
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        $extension = new CatalogExtension($app);
        $extension->id = 7;
        $helper = new \YTassy\Tycms\CoreExts\TyCatalog\Helper\TyCatalogPublicHelper($app);
        $helper->versions($extension, 1, 10, CatalogVersion::CHANNEL_BETA);

        self::assertCount(1, $params);
        // En bêta, la release reste servie : un client bêta doit pouvoir revenir au stable.
        self::assertContains('beta', $params[0]);
        self::assertContains('release', $params[0]);
    }
}
