<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Lib\Scss;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Lib\Scss\ScssCompiler;

final class ScssCompilerTest extends TestCase
{
    private const IMPORT_ROOT = __DIR__ . '/../../../Fixtures/Scss/SafeImporter/root';

    #[Test]
    public function itCompilesScssImportsFromTheAuthorizedRoot(): void
    {
        $css = ScssCompiler::compileScss(
            '@import "palette"; body { color: $primary; }',
            self::IMPORT_ROOT,
        );

        self::assertNotNull($css, (string) ScssCompiler::getLastError()?->getMessage());
        self::assertSame('body{color:#123456}', $css);
    }

    #[Test]
    public function itRejectsImportsEscapingTheAuthorizedRoot(): void
    {
        $css = ScssCompiler::compileScss(
            '@import "../outside"; body { color: $outside; }',
            self::IMPORT_ROOT,
        );

        self::assertNull($css);
        self::assertNotNull(ScssCompiler::getLastError());
    }

    #[Test]
    public function itPrefersImportOnlyScssFilesForImports(): void
    {
        $css = ScssCompiler::compileScss(
            '@import "import_only"; body { color: $importOnly; }',
            self::IMPORT_ROOT,
        );

        self::assertSame('body{color:#654321}', $css);
    }

    #[Test]
    public function itDisablesImportsWhenNoRootIsConfigured(): void
    {
        self::assertNull(ScssCompiler::compileScss('@import "palette";'));
    }
}
