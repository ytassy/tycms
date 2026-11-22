<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\ExtensionCatalog;

use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\ExtensionUninstaller;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class ExtensionUninstallerFilesystemTest extends FrameworkTestCase
{
    #[Test]
    public function targetsAreDerivedAndValidated(): void
    {
        $root = realpath(APP_ROOT);
        self::assertIsString($root);
        $uninstaller = new ExtensionUninstaller($this->createApplication());

        self::assertSame(
            $root . '/extensions/plugins/Demo',
            $this->invoke($uninstaller, 'extensionTarget', ['plugin', 'Demo']),
        );
        self::assertSame(
            $root . '/public/assets/plg_demo',
            $this->invoke($uninstaller, 'assetsTarget', ['plg_demo']),
        );

        foreach ([['core', 'Demo'], ['plugin', '../Demo'], ['plugin', 'demo']] as [$type, $folder]) {
            try {
                $this->invoke($uninstaller, 'extensionTarget', [$type, $folder]);
                self::fail('Type ou dossier de désinstallation accepté à tort.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function identifierTraversalIsRejectedBeforeFilesystemAccess(): void
    {
        $uninstaller = new ExtensionUninstaller($this->createApplication());

        $this->expectException(RuntimeException::class);
        $uninstaller->uninstall('../evil');
    }

    private function invoke(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}
