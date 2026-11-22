<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\ExtensionCatalog;

use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\ExtensionInstaller;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class ExtensionInstallerFilesystemTest extends FrameworkTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/tycms-installer-paths-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    #[Test]
    public function targetsAreDerivedFromSignedMetadataOnly(): void
    {
        $root = realpath(APP_ROOT);
        self::assertIsString($root);
        $installer = new ExtensionInstaller($this->createApplication());

        self::assertSame(
            $root . '/extensions/plugins/Demo',
            $this->invoke($installer, 'extensionTarget', [['type' => 'plugin', 'folder' => 'Demo']]),
        );
        self::assertSame(
            $root . '/public/assets/plg_demo',
            $this->invoke($installer, 'assetsTarget', ['plg_demo']),
        );
    }

    #[Test]
    public function existingAndSymbolicTargetsAreRejected(): void
    {
        $installer = new ExtensionInstaller($this->createApplication());
        $missing = $this->path('missing');
        $file = $this->path('existing');
        self::assertNotFalse(file_put_contents($file, 'x'));
        $link = $this->path('link');

        // Deux chemins absents sont acceptés.
        $this->invoke($installer, 'assertTargetsAbsent', [$missing, $this->path('missing-two')]);

        $this->assertThrows(fn () => $this->invoke($installer, 'assertTargetsAbsent', [$file, $missing]));

        if (@symlink($file, $link)) {
            $this->assertThrows(fn () => $this->invoke($installer, 'assertTargetsAbsent', [$link, $missing]));
        }
    }

    #[Test]
    public function updateRequiresExistingExtensionAndRejectsInvalidAssets(): void
    {
        $installer = new ExtensionInstaller($this->createApplication());
        $missing = $this->path('missing-extension');
        $missingAssets = $this->path('missing-assets');

        $this->assertThrows(fn () => $this->invoke($installer, 'assertUpdateTargets', [$missing, $missingAssets]));

        $extension = $this->path('extension');
        self::assertTrue(mkdir($extension, 0700));
        $this->invoke($installer, 'assertUpdateTargets', [$extension, $missingAssets]);

        $assetsFile = $this->path('assets-file');
        self::assertNotFalse(file_put_contents($assetsFile, 'x'));
        $this->assertThrows(fn () => $this->invoke($installer, 'assertUpdateTargets', [$extension, $assetsFile]));

        $link = $this->path('extension-link');
        if (@symlink($extension, $link)) {
            $this->assertThrows(fn () => $this->invoke($installer, 'assertUpdateTargets', [$link, $missingAssets]));
        }
    }

    #[Test]
    public function directoryRejectsFilesAndSymbolicLinks(): void
    {
        $installer = new ExtensionInstaller($this->createApplication());
        $file = $this->path('directory-file');
        self::assertNotFalse(file_put_contents($file, 'x'));

        $this->assertThrows(fn () => $this->invoke($installer, 'directory', [$file, 0700]));

        $target = $this->path('directory-target');
        self::assertTrue(mkdir($target, 0700));
        $link = $this->path('directory-link');
        if (@symlink($target, $link)) {
            $this->assertThrows(fn () => $this->invoke($installer, 'directory', [$link, 0700]));
        }

        $created = $this->path('directory-created');
        self::assertSame($created, $this->invoke($installer, 'directory', [$created, 0700]));
        self::assertDirectoryExists($created);
    }

    #[Test]
    public function privateDirectoryRefusesReadOnlyParent(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Les permissions ne sont pas restrictives pour root.');
        }

        $installer = new ExtensionInstaller($this->createApplication());
        $parent = $this->path('readonly');
        self::assertTrue(mkdir($parent, 0700));
        self::assertTrue(chmod($parent, 0500));

        try {
            $this->assertThrows(fn () => $this->invoke($installer, 'privateDirectory', [$parent . '/child', 0700]));
        } finally {
            @chmod($parent, 0700);
        }
    }

    private function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }

    private function invoke(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }

    private function assertThrows(callable $callback): void
    {
        try {
            $callback();
            self::fail('Une RuntimeException était attendue.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (is_dir($path)) {
            @chmod($path, 0700);
            foreach (new \FilesystemIterator($path) as $entry) {
                $this->remove($entry->getPathname());
            }
            @rmdir($path);
        }
    }
}
