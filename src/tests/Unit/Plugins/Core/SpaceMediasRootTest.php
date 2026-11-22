<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SpaceMediasRootTest extends FrameworkTestCase
{
    private ?string $testRoot = null;

    protected function tearDown(): void
    {
        if ($this->testRoot !== null) {
            $spaceDir = $this->testRoot . '/fixture';
            if (is_dir($spaceDir)) rmdir($spaceDir);
            if (is_dir($this->testRoot)) rmdir($this->testRoot);
        }
    }

    #[Test]
    public function aSpaceUsesTheMediaRootOfItsOwnApplication(): void
    {
        $firstApp = $this->createApplication();
        $firstApp->getConf()->medias_root = 'E:/first-media-root/';
        $secondApp = $this->createApplication();
        $secondApp->getConf()->medias_root = 'E:/second-media-root';

        $first = new Space($firstApp);
        $first->medias_dir = 'fixture';
        $second = new Space($secondApp);
        $second->medias_dir = 'fixture';

        self::assertSame('E:/first-media-root/fixture', $first->getMediasDir());
        self::assertSame('E:/second-media-root' . DIRECTORY_SEPARATOR . 'fixture', $second->getMediasDir());
    }

    #[Test]
    public function aLegacyConfigurationKeepsTheExistingPublicDirectory(): void
    {
        $app = $this->createApplication();
        unset($app->getConf()->medias_root);
        $space = new Space($app);
        $space->medias_dir = 'fixture';

        self::assertSame(APP_ROOT . '/public/medias' . DIRECTORY_SEPARATOR . 'fixture', $space->getMediasDir());
    }

    #[Test]
    public function aClearedTestMediaRootIsRecreatedOnLoad(): void
    {
        $app = $this->createApplication();
        $this->testRoot = dirname(__DIR__, 3) . '/.runtime/media-root-' . bin2hex(random_bytes(8));
        $app->getConf()->medias_root = $this->testRoot;
        $space = new Space($app);
        $space->id = 1;
        $space->medias_dir = 'fixture';

        $database = $this->createMock(Database::class);
        $database->expects(self::exactly(2))->method('selectOrm')
            ->with(Space::class, 'SELECT * FROM spaces')->willReturn([$space]);
        (new ReflectionProperty(Application::class, 'database'))->setValue($app, $database);

        $helper = new SpaceHelper($app);
        self::assertDirectoryExists($this->testRoot . '/fixture');

        rmdir($this->testRoot . '/fixture');
        rmdir($this->testRoot);
        $helper->load();
        self::assertDirectoryExists($this->testRoot . '/fixture');
    }

    #[Test]
    public function anEmptyConfiguredRootIsRejected(): void
    {
        $app = $this->createApplication();
        $app->getConf()->medias_root = '';
        $space = new Space($app);
        $space->medias_dir = 'fixture';

        $this->expectException(TechException::class);
        $space->getMediasDir();
    }
}
