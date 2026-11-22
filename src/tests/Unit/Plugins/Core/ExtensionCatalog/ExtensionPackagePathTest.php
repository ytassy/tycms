<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\ExtensionCatalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YTassy\Tycms\Extensions\ExtensionPackage;

final class ExtensionPackagePathTest extends TestCase
{
    #[Test]
    public function normalPackagePathsAreAccepted(): void
    {
        foreach ([
            'extension/Plugin.php',
            'extension/Sub_Dir/file-name.php',
            'assets/build/index.js',
            'assets/.vite/manifest.json',
            'extension/.vite/chunk.js',
        ] as $path) {
            ExtensionPackage::safePath($path);
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function traversalAbsoluteAndHiddenSegmentsAreRejected(): void
    {
        foreach ([
            '',
            '../evil.php',
            'extension/../evil.php',
            'extension/./evil.php',
            'extension//evil.php',
            '/etc/passwd',
            'C:\\evil.php',
            'extension\\evil.php',
            'extension/.git/config',
            'extension/.env',
            'extension/foo/.hidden',
            str_repeat('a', 513),
        ] as $path) {
            try {
                ExtensionPackage::safePath($path);
                self::fail('Chemin de paquet accepté à tort : ' . $path);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
