<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use RuntimeException;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Fonts\FontDownload;
use YTassy\Tycms\CoreExts\Core\Helper\Fonts\FontHelper;
use YTassy\Tycms\CoreExts\MainTemplate\Template;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class FontHelperTest extends FrameworkTestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tycms-font-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->root);
        }
    }

    public function testHistoricalFontIdsUseSystemStacksWithoutExternalCss(): void
    {
        $helper = $this->helper();
        self::assertSame($helper->stack('arial'), $helper->stack('nunito'));
        self::assertSame($helper->stack('arial'), $helper->stack('system'));
        self::assertSame($helper->stack('georgia'), $helper->stack('bona_nova'));
        self::assertSame('', $helper->css(['arial', 'georgia', 'nunito', 'bona_nova']));
        self::assertSame(['arial', 'verdana', 'georgia', 'times_new_roman', 'courier_new'], array_column($helper->options(), 'value'));
    }

    public function testMainEditorConvertsLegacyChoicesAndPreservesInstalledFamilies(): void
    {
        $template = new Template($this->createApplication());
        $legacy = $template->prepareConfigForEditor(1, [
            'theme_font_family' => 'nunito', 'theme_heading_font_family' => 'bona_nova',
        ]);
        self::assertSame('arial', $legacy['theme_font_family']);
        self::assertSame('georgia', $legacy['theme_heading_font_family']);
        $custom = $template->prepareConfigForEditor(2, [
            'theme_font_family' => 'system', 'theme_heading_font_family' => 'google_custom',
        ]);
        self::assertSame('arial', $custom['theme_font_family']);
        self::assertSame('google_custom', $custom['theme_heading_font_family']);
    }

    public function testRejectsUntrustedSourcesIncludingRedirectDestinations(): void
    {
        foreach ([
            'http://fonts.gstatic.com/s/a/a.woff2',
            'https://fonts.gstatic.com.evil.test/s/a/a.woff2',
            'https://fonts.gstatic.com@127.0.0.1/s/a/a.woff2',
            'https://fonts.gstatic.com/s/../a.woff2',
            'https://fonts.gstatic.com/s/a/a.woff2?redirect=http://localhost',
            'https://raw.githubusercontent.com/evil/fonts/main/ofl/a/OFL.txt',
            'https://fonts.google.com/metadata/fonts?url=http://localhost',
            'https://api.github.com/repos/evil/fonts/git/trees/main?recursive=1',
        ] as $url) {
            try {
                FontDownload::assertUrl($url);
                self::fail('Une source externe non autorisée doit être rejetée.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRemoteCssOnlyProducesValidatedFontDescriptors(): void
    {
        $css = 'body{background:url(https://evil.test/track)}' . $this->css();
        $faces = FontDownload::parseCss($css);
        self::assertCount(1, $faces);
        self::assertSame('U+0000-00FF', $faces[0]['range']);
        self::assertSame('400', $faces[0]['weight']);
        $this->expectException(RuntimeException::class);
        FontDownload::parseCss(str_replace('https://fonts.gstatic.com/s/unit/font.woff2', 'https://127.0.0.1/font.woff2', $css));
    }

    public function testInstallsAtomicallyWithLicenceAndOnlyLocalCssAndIsIdempotent(): void
    {
        $helper = $this->helper();
        $font = $helper->install('google_unitfont');
        self::assertTrue($font['enabled']);
        self::assertCount(3, $helper->requests);
        self::assertSame('"Unit Font", sans-serif', $helper->stack('google_unitfont'));
        $directory = $this->root . '/google_unitfont/' . $font['version'];
        $css = file_get_contents($directory . '/font.css');
        self::assertStringContainsString('/assets/fonts/google_unitfont/', $css);
        self::assertStringNotContainsString('https:', $css);
        self::assertSame('Copyright Unit Font. Licence test.', file_get_contents($directory . '/OFL.txt'));
        self::assertCount(0, glob($this->root . '/.install-*'));
        self::assertSame($font, $helper->install('google_unitfont'));
        self::assertCount(3, $helper->requests);
    }

    public function testPreviouslyPublishedCorruptedFileCannotProduceSuccess(): void
    {
        $helper = $this->helper();
        $font = $helper->install('google_unitfont');
        $css = $this->root . '/google_unitfont/' . $font['version'] . '/font.css';
        file_put_contents($css, 'Fichier altéré');
        $this->expectException(RuntimeException::class);
        $helper->install('google_unitfont');
    }

    public function testDisabledFontKeepsRenderingAndCannotBeReenabledByReinstallation(): void
    {
        $helper = $this->helper();
        $helper->install('google_unitfont');
        $helper->setEnabled('google_unitfont', false);
        self::assertFalse($helper->installed()['google_unitfont']['enabled']);
        self::assertTrue(array_column($helper->options(), null, 'value')['google_unitfont']['disabled']);
        self::assertStringContainsString('/assets/fonts/google_unitfont/', $helper->css(['google_unitfont']));
        self::assertSame('"Unit Font", sans-serif', $helper->stack('google_unitfont'));
        self::assertFalse($helper->install('google_unitfont')['enabled']);
        $helper->delete('google_unitfont');
        self::assertSame([], $helper->installed());
        self::assertDirectoryDoesNotExist($this->root . '/google_unitfont');
    }

    public function testInactiveFontCannotBeNewlySelectedButCanBeKept(): void
    {
        $helper = $this->helper();
        $helper->install('google_unitfont');
        $helper->setEnabled('google_unitfont', false);
        $helper->assertSelectable('google_unitfont', 'google_unitfont');
        self::assertTrue(true);
        $this->expectException(RuntimeException::class);
        $helper->assertSelectable('google_unitfont', 'system');
    }

    public function testPhysicalDeletionRequiresPriorDeactivation(): void
    {
        $helper = $this->helper();
        $helper->install('google_unitfont');
        $this->expectException(RuntimeException::class);
        $helper->delete('google_unitfont');
    }

    public function testBadLicenceAbortsWithoutPublishingFiles(): void
    {
        $helper = $this->helper();
        $helper->licence = 'Licence altérée';
        try {
            $helper->install('google_unitfont');
            self::fail('La licence doit correspondre au catalogue.');
        } catch (RuntimeException) {
            self::assertSame([], $helper->installed());
            self::assertDirectoryDoesNotExist($this->root . '/google_unitfont');
            self::assertSame([], glob($this->root . '/.install-*'));
        }
    }

    public function testInvalidBinaryAbortsWithoutPublishingFiles(): void
    {
        $helper = $this->helper();
        $helper->binary = '<html>erreur</html>';
        try {
            $helper->install('google_unitfont');
            self::fail('Un fichier HTML ne doit jamais devenir une police.');
        } catch (RuntimeException) {
            self::assertSame([], $helper->installed());
            self::assertSame([], glob($this->root . '/.install-*'));
        }
    }

    public function testCannotInstallOutsideCatalogOrThroughASymlink(): void
    {
        $helper = $this->helper();
        try {
            $helper->install('../outside');
            self::fail('Une famille inconnue doit être refusée.');
        } catch (RuntimeException) {
            self::assertSame([], $helper->requests);
        }
        mkdir($this->root);
        symlink(sys_get_temp_dir(), $this->root . '/google_unitfont');
        $this->expectException(RuntimeException::class);
        $helper->install('google_unitfont');
    }

    public function testConcurrentInstallationDoesNotDownload(): void
    {
        $helper = $this->helper();
        mkdir($this->root);
        $lock = fopen($this->root . '/.install.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $helper->install('google_unitfont');
            self::fail('Le verrou doit empêcher un second téléchargement.');
        } catch (RuntimeException) {
            self::assertSame([], $helper->requests);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function css(): string
    {
        return '@font-face{font-family:"Unit Font";font-style:normal;font-weight:400;src:url(https://fonts.gstatic.com/s/unit/font.woff2) format("woff2");unicode-range:U+0000-00FF;}';
    }

    private function helper(): FontHelper
    {
        return new class($this->createApplication(), $this->root, $this->css()) extends FontHelper {
            public array $requests = [];
            public string $licence = 'Copyright Unit Font. Licence test.';
            public string $binary;
            private array $records = [];

            public function __construct(Application $app, private string $root, private string $fontCss)
            {
                parent::__construct($app);
                $this->binary = 'wOF2' . pack('N', 0x00010000) . pack('N', 64) . str_repeat("\0", 52);
            }

            public function catalog(): array
            {
                $text = 'Copyright Unit Font. Licence test.';
                return ['revision' => str_repeat('a', 40), 'families' => ['google_unitfont' => [
                    'family' => 'Unit Font', 'category' => 'Sans Serif', 'variants' => ['400'], 'path' => 'ofl/unitfont',
                    'licenses' => [['name' => 'OFL.txt', 'sha' => sha1('blob ' . strlen($text) . "\0" . $text)]],
                ]]];
            }

            protected function storageRoot(): string { return $this->root; }
            protected function installationRecords(): array { return $this->records; }
            protected function registerInstallation(string $id, string $version): void
            {
                $this->records[$id] = ['version' => $version, 'enabled' => $this->records[$id]['enabled'] ?? true];
            }
            protected function updateEnabledRecord(string $id, bool $enabled): void { $this->records[$id]['enabled'] = $enabled; }
            protected function deleteRecord(string $id): void { unset($this->records[$id]); }
            protected function download(string $url, int $limit, int $timeout): string
            {
                FontDownload::assertUrl($url);
                $this->requests[] = $url;
                return str_contains($url, 'raw.githubusercontent.com') ? $this->licence
                    : (str_contains($url, 'fonts.googleapis.com') ? $this->fontCss : $this->binary);
            }
        };
    }
}
