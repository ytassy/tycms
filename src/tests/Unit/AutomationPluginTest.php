<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\Plugin;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class AutomationPluginTest extends FrameworkTestCase
{
    #[Test]
    public function skeletonExposesCoreMetadataWithoutBusinessContract(): void
    {
        $plugin = new Plugin($this->createApplication());

        self::assertSame('Automatisation', $plugin->getName());
        self::assertSame(APP_VERSION, $plugin->getVersion());
        self::assertSame(['1.0.0', '1.0.1', '1.0.2'], $plugin->getMigrations());
        self::assertNull($plugin->getFrontendEntry());
        self::assertNotSame('', $plugin->getIconClass());
    }
}
