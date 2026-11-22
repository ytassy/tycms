<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\TyCatalog;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use YTassy\Tycms\CoreExts\TyCatalog\Helper\TyCatalogStorage;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class TyCatalogStorageTest extends FrameworkTestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->removeDirectory($this->root);
        }
    }

    #[Test]
    public function versionDirectoriesAreDerivedAndTraversalIsRejected(): void
    {
        $app = $this->createApplication();
        $this->root = sys_get_temp_dir() . '/tycms-tycatalog-' . bin2hex(random_bytes(8));
        $app->getConf()->ty_catalog_storage_root = $this->root;

        $storage = new TyCatalogStorage($app);
        $directory = $storage->createVersionDirectory(12, 'plg_demo', '0.1.0');

        self::assertStringStartsWith('catalogs/12/plg_demo/0.1.0/', $directory['relative']);
        self::assertDirectoryExists($directory['absolute']);
        self::assertStringStartsWith(realpath($this->root), realpath($directory['absolute']));

        $this->expectException(RuntimeException::class);
        $storage->createVersionDirectory(12, '../secret', '0.1.0');
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
