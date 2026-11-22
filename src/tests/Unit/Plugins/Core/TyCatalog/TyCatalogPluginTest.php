<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\TyCatalog;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\TyCatalog\Permissions;
use YTassy\Tycms\CoreExts\TyCatalog\Plugin;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class TyCatalogPluginTest extends FrameworkTestCase
{
    #[Test]
    public function pluginDeclaresItsMigrationAndPermissions(): void
    {
        $plugin = new Plugin($this->createApplication());

        self::assertSame('TyCatalog', $plugin->getName());
        self::assertSame(['1.0.0', '1.0.1', '1.0.2', '1.0.3', '1.0.4'], $plugin->getMigrations());
        self::assertInstanceOf(Permissions::class, $plugin->coreOnRegisterAclPermissions());
        self::assertCount(3, $plugin->getControllers());
    }
}
