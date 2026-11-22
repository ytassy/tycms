<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\PageComponents\Diagram;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\DrawioSvg;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\PageFixtures;

final class PageDiagramTest extends TestCase
{
    private const MODEL = '<mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/></root></mxGraphModel>';

    private static function svg(string $source = self::MODEL, string $drawing = '<rect width="120" height="60" fill="#2563EB"/>'): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="60" content="'
            . htmlspecialchars($source, ENT_QUOTES | ENT_XML1, 'UTF-8') . '">' . $drawing . '</svg>';
    }

    private static function compressed(string $source): string
    {
        return base64_encode(gzdeflate(rawurlencode($source)));
    }

    public function testEditableSvgWithRichTextAndCompressedPagesIsKeptAsText(): void
    {
        $source = '<mxfile><diagram name="Page-1">' . self::compressed(self::MODEL) . '</diagram></mxfile>';
        $svg = self::svg($source, '<foreignObject width="120" height="60"><div xmlns="http://www.w3.org/1999/xhtml"'
            . ' style="display:flex;align-items:center"><b>Schéma été</b></div></foreignObject>');
        DrawioSvg::validate($svg, strlen($svg));

        $component = new Diagram(PageFixtures::application());
        $html = $component->render((object) ['props' => (object) ['svg' => $svg, 'alt' => '"<Titre>']], PageFixtures::renderContext());
        self::assertStringStartsWith('<img ', $html);
        self::assertStringContainsString('data:image/svg+xml;base64,' . base64_encode($svg), $html);
        self::assertStringContainsString('alt="&quot;&lt;Titre&gt;"', $html);
        self::assertStringNotContainsString('<svg', $html);
    }

    public function testEmbeddedVectorImageAndLocalGradientRemainSupported(): void
    {
        $image = 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0L10 10"/></svg>');
        $source = str_replace('<mxCell id="1" parent="0"/>', '<mxCell id="1" parent="0" style="image=' . $image . ';"/>', self::MODEL);
        DrawioSvg::validate(self::svg($source, '<defs><linearGradient id="g"><stop stop-color="#fff"/></linearGradient></defs>'
            . '<rect fill="url(#g)"/><image href="' . htmlspecialchars($image, ENT_XML1) . '"/>'), 250_000);
        $this->addToAssertionCount(1);
    }

    public static function forbiddenSvg(): iterable
    {
        yield 'script' => [self::svg(drawing: '<script>alert(1)</script>')];
        yield 'event' => [self::svg(drawing: '<rect onload="alert(1)"/>')];
        yield 'external resource' => [self::svg(drawing: '<use href="https://example.org/shape.svg#x"/>')];
        yield 'external CSS' => [self::svg(drawing: '<style>@import "https://example.org/a.css";</style>')];
        yield 'CSS escape' => [self::svg(drawing: '<rect style="fill:u\\72l(https://example.org)"/>')];
        yield 'PNG' => [self::svg(drawing: '<image href="data:image/png;base64,iVBORw0KGgo="/>')];
        yield 'JPEG' => [self::svg(drawing: '<image href="data:image/jpeg;base64,/9g="/>')];
        yield 'disguised PNG' => [self::svg(drawing: '<image href="data:image/gif;base64,iVBORw0KGgo="/>')];
        yield 'hidden source image' => [self::svg('<mxfile><diagram>' . self::compressed(str_replace('<root>',
            '<root><mxCell style="image=data:image/png,AAAA;"/>', self::MODEL)) . '</diagram></mxfile>')];
        yield 'source HTML image' => [self::svg(str_replace('<root>',
            '<root><mxCell value="&lt;img src=&quot;data:image/jpeg;base64,/9g=&quot;/&gt;"/>', self::MODEL))];
        yield 'source background' => [self::svg(str_replace('<mxGraphModel>',
            '<mxGraphModel backgroundImage="{&quot;src&quot;:&quot;https://example.org/image.png&quot;}">', self::MODEL))];
        yield 'source stencil image' => [self::svg(str_replace('<root>', '<root><mxCell style="shape=stencil('
            . self::compressed('<shape><foreground><image src="data:image/png,AAAA"/></foreground></shape>') . ');"/>', self::MODEL))];
        yield 'entity' => ['<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . self::svg()];
        yield 'instruction' => ['<?xml-stylesheet href="https://example.org/a.css"?>' . self::svg()];
        yield 'missing source' => ['<svg xmlns="http://www.w3.org/2000/svg"/>'];
        yield 'wrong source' => [self::svg('<html/>')];
        yield 'decompression bomb' => [self::svg('<mxfile><diagram>' . self::compressed(str_repeat('x', 5_000_001)) . '</diagram></mxfile>')];
    }

    #[DataProvider('forbiddenSvg')]
    public function testUnsafeRasterAndHiddenImagesAreRejected(string $svg): void
    {
        $this->expectException(ExceptionResponse::class);
        DrawioSvg::validate($svg, 250_000);
    }

    public function testDiagramWeightIncludesTheEditableSource(): void
    {
        $svg = self::svg();
        $this->expectException(ExceptionResponse::class);
        DrawioSvg::validate($svg, strlen($svg) - 1);
    }
}
