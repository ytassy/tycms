<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\ExtensionCatalog;

use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\ExtensionInstaller;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;
use ZipArchive;

final class ExtensionInstallerTest extends FrameworkTestCase
{
    #[Test]
    public function extractionCreatesParentsWithoutDirectoryEntries(): void
    {
        $root = $this->temporaryDirectory();
        $archive = $root . '/package.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        // Les fichiers précèdent volontairement leurs dossiers et le ZIP ne
        // contient aucune entrée de dossier.
        $zip->addFromString('assets/build/index.js', 'console.log("ok");');
        $zip->addFromString('extension/Version.php', '<?php // plugin');
        $zip->addFromString('extension/Nested/File.php', '<?php // nested');
        $zip->close();

        $destination = $root . '/work';
        $installer = new ExtensionInstaller($this->createApplication());
        $extract = new ReflectionMethod(ExtensionInstaller::class, 'extract');
        $extract->setAccessible(true);
        $extract->invoke($installer, $archive, $destination);

        self::assertSame('console.log("ok");', file_get_contents($destination . '/assets/build/index.js'));
        self::assertSame('<?php // plugin', file_get_contents($destination . '/extension/Version.php'));
        self::assertSame('<?php // nested', file_get_contents($destination . '/extension/Nested/File.php'));

        $this->remove($root);
    }

    #[Test]
    public function extractionRejectsTraversalAndUnknownRoots(): void
    {
        $root = $this->temporaryDirectory();
        $installer = new ExtensionInstaller($this->createApplication());
        $extract = new ReflectionMethod(ExtensionInstaller::class, 'extract');
        $extract->setAccessible(true);

        foreach (['extension/../evil.php', 'other/file.php'] as $entry) {
            $archive = $root . '/' . md5($entry) . '.zip';
            $zip = new ZipArchive();
            self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $zip->addFromString($entry, '<?php // interdit');
            $zip->close();

            try {
                $extract->invoke($installer, $archive, $root . '/work-' . md5($entry));
                self::fail('Entrée ZIP interdite extraite : ' . $entry);
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }

        $this->remove($root);
    }

    private function temporaryDirectory(): string
    {
        $root = sys_get_temp_dir() . '/tycms-installer-' . bin2hex(random_bytes(8));
        if (!mkdir($root, 0700)) {
            self::fail('Dossier temporaire de test impossible.');
        }

        return realpath($root) ?: $root;
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path) as $entry) {
                $this->remove($entry->getPathname());
            }
            @rmdir($path);
        }
    }
}
