<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\ExtensionCatalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use YTassy\Tycms\Extensions\BaseExtension;

final class ExtensionUninstallHookTest extends TestCase
{
    #[Test]
    public function baseExtensionExposesTheOptionalUninstallHook(): void
    {
        $method = new ReflectionMethod(BaseExtension::class, 'uninstallExtension');

        self::assertTrue($method->isPublic());
        self::assertSame(0, $method->getNumberOfRequiredParameters());
        self::assertSame('void', (string) $method->getReturnType());
    }
}
