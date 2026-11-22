<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Fonts\FontHelper;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class FontLibraryIntegrationTest extends TestCase
{
    public function testInstallationStateAndPhysicalDeletionUseTheSharedDatabaseRegistry(): void
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));
        $app = new Application($environment->configuration());
        $app->initialiseOrThrow(new Request(host: TestFixtures::SITE_HOST));
        $root = $environment->runtimeRoot() . '/fonts-' . bin2hex(random_bytes(6));
        $helper = new class($app, $root) extends FontHelper {
            public function __construct(Application $app, private string $root) { parent::__construct($app); }
            protected function storageRoot(): string { return $this->root; }
            public function catalog(): array
            {
                return ['revision' => str_repeat('a', 40), 'families' => ['google_integrationtest' => [
                    'family' => 'Integration Test', 'category' => 'Sans Serif', 'variants' => ['400'], 'path' => 'ofl/integrationtest',
                    'licenses' => [['name' => 'OFL.txt', 'sha' => sha1("blob 17\0Licence de test.\n")]],
                ]]];
            }
            protected function download(string $url, int $limit, int $timeout): string
            {
                if (str_contains($url, 'raw.githubusercontent.com')) return "Licence de test.\n";
                if (str_contains($url, 'fonts.googleapis.com')) {
                    return '@font-face{font-style:normal;font-weight:400;src:url(https://fonts.gstatic.com/s/test/a.woff2)}';
                }
                return 'wOF2' . pack('N', 0x00010000) . pack('N', 64) . str_repeat("\0", 52);
            }
        };
        try {
            $first = $helper->install('google_integrationtest');
            self::assertTrue($first['enabled']);
            $helper->setEnabled('google_integrationtest', false);
            self::assertFalse($helper->installed()['google_integrationtest']['enabled']);
            self::assertSame($first['version'], $helper->install('google_integrationtest')['version']);
            self::assertFalse($helper->installed()['google_integrationtest']['enabled']);
            $helper->setEnabled('google_integrationtest', true);
            self::assertTrue($helper->installed()['google_integrationtest']['enabled']);
            $helper->setEnabled('google_integrationtest', false);
            $helper->delete('google_integrationtest');
            self::assertSame([], $helper->installed());
            self::assertDirectoryDoesNotExist($root . '/google_integrationtest');
        } finally {
            $app->getDatabase()->execute('DELETE FROM font_installations WHERE font_key = %', ['google_integrationtest']);
            if (is_dir($root)) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($root);
            }
        }
    }
}
