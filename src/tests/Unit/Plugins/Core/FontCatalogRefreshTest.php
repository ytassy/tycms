<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use RuntimeException;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Fonts\FontCatalog;
use YTassy\Tycms\CoreExts\Core\Helper\Fonts\FontDownload;
use YTassy\Tycms\CoreExts\Core\Helper\Fonts\FontHelper;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class FontCatalogRefreshTest extends FrameworkTestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tycms-catalog-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) return;
        foreach (new \FilesystemIterator($this->root) as $file) unlink($file->getPathname());
        rmdir($this->root);
    }

    public function testRefreshPublishesAPersistentCatalogWithoutInstallingFonts(): void
    {
        $helper = $this->helper();
        $result = $helper->refreshCatalog();
        self::assertSame(1, $result['count']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['updatedAt']);
        self::assertSame([FontCatalog::METADATA_URL, FontCatalog::TREE_URL], $helper->requests);
        self::assertFileExists($this->root . '/.catalog.json');
        self::assertSame([], glob($this->root . '/google_*'));
        self::assertSame([], glob($this->root . '/.catalog-*.tmp'));

        $fresh = $this->helper();
        self::assertSame(str_repeat('b', 40), $fresh->catalog()['revision']);
        self::assertSame(['google_newfont'], array_keys($fresh->catalog()['families']));
        self::assertSame([], $fresh->requests);
    }

    public function testNetworkFailureKeepsThePreviousFileAndCatalog(): void
    {
        $helper = $this->helper();
        $helper->refreshCatalog();
        $previous = file_get_contents($this->root . '/.catalog.json');
        $helper->fail = true;
        try {
            $helper->refreshCatalog();
            self::fail('Une panne réseau doit empêcher la publication.');
        } catch (RuntimeException) {
            self::assertSame($previous, file_get_contents($this->root . '/.catalog.json'));
            self::assertSame('New Font', $this->helper()->catalog()['families']['google_newfont']['family']);
        }
    }

    public function testInvalidOrTruncatedSourcesCannotReplaceThePreviousCatalog(): void
    {
        $helper = $this->helper();
        $helper->refreshCatalog();
        $previous = file_get_contents($this->root . '/.catalog.json');
        $valid = $helper->replies;
        $tree = json_decode($valid[FontCatalog::TREE_URL], true);
        $truncated = [...$tree, 'truncated' => true];
        $badLicense = $tree;
        $badLicense['tree'][0]['sha'] = 'invalid';
        $badPath = $tree;
        $badPath['tree'][0]['path'] = 'ofl/newfont/../../OFL.txt';
        foreach (['<html>erreur</html>', json_encode($truncated), json_encode($badLicense), json_encode($badPath)] as $response) {
            $helper->replies = [...$valid, FontCatalog::TREE_URL => $response];
            try {
                $helper->refreshCatalog();
                self::fail('Une source invalide doit être refusée.');
            } catch (RuntimeException) {
                self::assertSame($previous, file_get_contents($this->root . '/.catalog.json'));
            }
        }
    }

    public function testInstalledFontsKeepTheirMetadataAndOriginalLicenseRevision(): void
    {
        $helper = $this->helper();
        $previous = $helper->catalog();
        $helper->records = ['google_nunito' => ['version' => str_repeat('a', 24), 'enabled' => false]];
        $result = $helper->refreshCatalog();
        $catalog = $helper->catalog();
        self::assertSame(2, $result['count']);
        self::assertSame($previous['families']['google_nunito']['licenses'], $catalog['families']['google_nunito']['licenses']);
        self::assertSame($previous['revision'], $catalog['families']['google_nunito']['revision']);
        self::assertSame(str_repeat('b', 40), $catalog['revision']);
        $helper->refreshCatalog();
        self::assertSame($previous['revision'], $helper->catalog()['families']['google_nunito']['revision']);
        self::assertFalse($helper->records['google_nunito']['enabled']);
    }

    public function testAnExistingLibraryLockPreventsRefreshBeforeAnyDownload(): void
    {
        $helper = $this->helper();
        mkdir($this->root);
        $lock = fopen($this->root . '/.install.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $helper->refreshCatalog();
            self::fail('Le verrou doit interdire une actualisation concurrente.');
        } catch (RuntimeException) {
            self::assertSame([], $helper->requests);
            self::assertFileDoesNotExist($this->root . '/.catalog.json');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testAStoredCatalogSymlinkCannotBeReadOrReplaced(): void
    {
        $helper = $this->helper();
        mkdir($this->root);
        file_put_contents($this->root . '/outside.json', 'intact');
        symlink($this->root . '/outside.json', $this->root . '/.catalog.json');
        try {
            $helper->refreshCatalog();
            self::fail('Un lien symbolique ne doit pas être accepté.');
        } catch (RuntimeException) {
            self::assertSame('intact', file_get_contents($this->root . '/outside.json'));
            self::assertSame([], $helper->requests);
        }
    }

    private function helper(): FontHelper
    {
        return new class($this->createApplication(), $this->root) extends FontHelper {
            public array $records = [];
            public array $requests = [];
            public array $replies;
            public bool $fail = false;

            public function __construct(Application $app, private string $root)
            {
                parent::__construct($app);
                $this->replies = [
                    FontCatalog::METADATA_URL => json_encode(['familyMetadataList' => [[
                        'family' => 'New Font', 'category' => 'Sans Serif', 'isOpenSource' => true,
                        'fonts' => ['400' => [], '400i' => []],
                    ]]]),
                    FontCatalog::TREE_URL => json_encode(['sha' => str_repeat('b', 40), 'truncated' => false, 'tree' => [[
                        'path' => 'ofl/newfont/OFL.txt', 'type' => 'blob', 'sha' => str_repeat('c', 40),
                    ]]]),
                ];
            }
            protected function storageRoot(): string { return $this->root; }
            protected function installationRecords(): array { return $this->records; }
            protected function download(string $url, int $limit, int $timeout): string
            {
                FontDownload::assertUrl($url);
                $this->requests[] = $url;
                if ($this->fail) throw new RuntimeException('Réseau indisponible.');
                return $this->replies[$url];
            }
        };
    }
}
