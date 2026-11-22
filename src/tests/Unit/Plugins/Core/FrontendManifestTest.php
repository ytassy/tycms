<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YTassy\Tycms\CoreExts\Core\Helper\Frontend\FrontendManifest;

final class FrontendManifestTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/tycms-frontend-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/.vite', 0700, true);
        foreach (['index.js', 'shared.js', 'index.css', 'shared.css'] as $file) file_put_contents($this->directory . '/' . $file, '');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/.vite/*') as $file) unlink($file);
        rmdir($this->directory . '/.vite');
        foreach (glob($this->directory . '/*') as $file) unlink($file);
        rmdir($this->directory);
    }

    public function testEntryCollectsDependencyStylesAndTerminatesOnCycles(): void
    {
        $this->writeManifest([
            'src/main.js' => ['file' => 'index.js', 'css' => ['index.css'], 'imports' => ['shared']],
            'shared' => ['file' => 'shared.js', 'css' => ['shared.css'], 'imports' => ['src/main.js']],
        ]);
        $styles = [];
        self::assertSame('/assets/plg_test/index.js', (new FrontendManifest($this->directory))->entry('src/main.js', '/assets/plg_test/', $styles));
        self::assertSame(['/assets/plg_test/shared.css', '/assets/plg_test/index.css'], $styles);
    }

    #[DataProvider('invalidEntries')]
    public function testRejectsUnsafeOrMalformedManifestEntries(array $entry): void
    {
        $this->writeManifest(['src/main.js' => $entry]);
        $styles = [];
        $this->expectException(RuntimeException::class);
        (new FrontendManifest($this->directory))->entry('src/main.js', '/assets/plg_test/', $styles);
    }

    public static function invalidEntries(): iterable
    {
        yield 'parent' => [['file' => '../index.js']];
        yield 'URL' => [['file' => 'https://evil.test/index.js']];
        yield 'absolu' => [['file' => '/index.js']];
        yield 'antislash' => [['file' => '..\\index.js']];
        yield 'injection HTML' => [['file' => 'index.js"><script>']];
        yield 'CSS parent' => [['file' => 'index.js', 'css' => ['../index.css']]];
        yield 'imports malformés' => [['file' => 'index.js', 'imports' => 'index.js']];
        yield 'import absent' => [['file' => 'index.js', 'imports' => ['missing']]];
        yield 'fichier absent' => [['file' => 'missing.js']];
    }

    public function testRejectsSymlinkOutsideTheAssetDirectory(): void
    {
        symlink(__FILE__, $this->directory . '/outside.js');
        $this->writeManifest(['src/main.js' => ['file' => 'outside.js']]);
        $styles = [];
        $this->expectException(RuntimeException::class);
        (new FrontendManifest($this->directory))->entry('src/main.js', '/assets/plg_test/', $styles);
    }

    public function testLibraryLoadsStylesOfDeferredComponentsWithoutLoadingTheirJavascript(): void
    {
        $this->writeManifest([
            'src/main.js' => ['file' => 'index.js', 'dynamicImports' => ['deferred']],
            'deferred' => ['file' => 'shared.js', 'css' => ['shared.css']],
        ]);
        $styles = [];
        (new FrontendManifest($this->directory))->entry('src/main.js', '/assets/plg_test/', $styles, true);
        self::assertSame(['/assets/plg_test/shared.css'], $styles);
    }

    private function writeManifest(array $manifest): void
    {
        file_put_contents($this->directory . '/.vite/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }
}
