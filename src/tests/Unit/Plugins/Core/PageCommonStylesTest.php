<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use YTassy\Tycms\Tests\Support\PageFixtures;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageCommonStyles;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\Response\ExceptionResponse;

final class PageCommonStylesTest extends TestCase
{
    public static function invalidLinkColors(): iterable
    {
        foreach (['#fff;display:none', 'url(https://example.com)', 'var(--brand, red)', "#fff\n", 123, null] as $value) yield [$value];
    }

    #[DataProvider('invalidLinkColors')]
    public function testRejectsInvalidLinkColors(mixed $value): void
    {
        $this->expectException(ExceptionResponse::class);
        PageCommonStyles::validate((object) ['common' => (object) ['style' => (object) ['link_color' => $value]]]);
    }

    public function testLinkColorsAreScopedAndCanBeOverriddenByChildrenWithoutTargetingButtons(): void
    {
        $document = PageFixtures::normalize(PageFixtures::decode(json_encode(['items' => [[
            'id' => 'row', 'type' => 'core.row', 'props' => ['common' => ['style' => ['link_color' => '#fff']]],
            'columns' => [['id' => 'col', 'props' => ['common' => ['style' => ['link_color' => '#abc']]], 'items' => [[
                'id' => 'text', 'type' => 'core.rich-text', 'props' => ['content' => '<p><a href="/fr/">Lien</a></p>'],
            ]]]],
        ]]], JSON_THROW_ON_ERROR)));

        $css = PageCommonStyles::compile($document, 42);
        self::assertStringContainsString('#page-42 [data-page-node="row"]{--page-link-color:#fff}', $css);
        self::assertStringContainsString('#page-42 [data-page-node="col"]{--page-link-color:#abc}', $css);
        self::assertStringContainsString('#page-42 [data-page-node="row"] a:not(.btn):not([role="button"]){color:var(--page-link-color)}', $css);
        self::assertStringContainsString('#page-42 [data-page-node="col"] a:not(.btn):not([role="button"]){color:var(--page-link-color)}', $css);
        self::assertStringNotContainsString('data-page-node="text"', $css);

        $document->items[0]->props->common->style->link_color = '';
        unset($document->items[0]->zones->columns[0]->props->common->style->link_color);
        self::assertSame('', PageCommonStyles::compile($document, 42));
    }

    public function testLinkColorCannotReferenceAnUnknownTemplateVariable(): void
    {
        $document = PageFixtures::decode('{"items":[{"type":"core.row","columns":[{"props":{"common":{"style":{"link_color":"var(--unknown)"}}},"items":[]}]}]}');
        $this->expectException(ExceptionResponse::class);
        PageFixtures::normalize($document);
    }

    public function testCompilesStylesForItemsAndColumnsWithinPageScope(): void
    {
        $document = PageFixtures::normalize(PageFixtures::decode(json_encode(['items' => [[
            'id' => 'row_1', 'type' => 'core.row', 'props' => ['common' => ['style' => ['margin_top' => '0']]], 'columns' => [[
                'id' => 'col_1', 'props' => ['common' => ['style' => ['padding_left' => '12px']]],
                'items' => [[
                    'id' => 'title_1', 'type' => 'core.heading',
                    'props' => ['level' => 2, 'common' => ['style' => [
                        'margin_top' => '-2rem', 'margin_right' => '0', 'padding_left' => '0',
                        'border_bottom' => '1px', 'border_color' => '#abc',
                        'background_image' => '/medias/cover.jpg', 'background_size' => 'cover',
                        'position' => 'relative', 'offset_top' => '4px',
                    ]]],
                ]],
            ]],
        ]]], JSON_THROW_ON_ERROR)));

        $css = PageCommonStyles::compile($document, 42);
        self::assertStringContainsString('#page-42 [data-page-node="row_1"]{margin-top:0}', $css);
        self::assertStringContainsString('#page-42 [data-page-node="col_1"]{padding-left:12px}', $css);
        self::assertStringContainsString('margin-top:-2rem', $css);
        self::assertStringContainsString('margin-right:0', $css);
        self::assertStringContainsString('padding-left:0', $css);
        self::assertStringContainsString('border-width:0', $css);
        self::assertStringContainsString('border-bottom-width:1px', $css);
        self::assertStringContainsString('border-style:solid', $css);
        self::assertStringContainsString('background-image:url("/medias/cover.jpg")', $css);
        self::assertStringContainsString('background-repeat:no-repeat', $css);
        self::assertStringContainsString('position:relative', $css);
        self::assertStringContainsString('top:4px', $css);
    }

    public function testRejectsCssInjectionInStructuredStyles(): void
    {
        $this->expectException(ExceptionResponse::class);
        PageCommonStyles::validate((object) ['common' => (object) ['style' => (object) [
            'background_image' => '/image.jpg");body{color:red}',
        ]] ]);
    }

    public function testCompilesPositionAndIndependentImageAndTintOpacity(): void
    {
        $document = PageFixtures::normalize(PageFixtures::decode(json_encode(['items' => [[
            'id' => 'row_1', 'type' => 'core.row', 'props' => ['common' => ['style' => [
                'background_color' => '#000000', 'background_image' => '/cover.jpg',
                'background_position' => '25% 75%', 'background_size' => 'cover',
                'background_image_opacity' => '60', 'background_image_tint' => '#FFFFFF4D',
            ]]], 'columns' => [[ 'id' => 'col_1', 'props' => [], 'items' => [] ]],
        ]]], JSON_THROW_ON_ERROR)));

        $css = PageCommonStyles::compile($document, 42);
        self::assertStringContainsString('[data-page-node="row_1"]{background-color:#000000;position:relative;isolation:isolate}', $css);
        self::assertStringContainsString('[data-page-node="row_1"]::before{', $css);
        self::assertStringContainsString('background-image:url("/cover.jpg")', $css);
        self::assertStringContainsString('opacity:0.6;background-position:25% 75%;background-size:cover', $css);
        self::assertStringContainsString('[data-page-node="row_1"]::after{', $css);
        self::assertStringContainsString('background-color:#FFFFFF4D', $css);
    }

    public function testRejectsOutOfRangeBackgroundPercent(): void
    {
        $this->expectException(ExceptionResponse::class);
        PageCommonStyles::validate((object) ['common' => (object) ['style' => (object) [
            'background_position' => '101% 50%',
        ]]]);
    }

    public function testTintAloneUsesLayeredBackground(): void
    {
        $document = PageFixtures::normalize(PageFixtures::decode(json_encode(['items' => [[
            'id' => 'row_1', 'type' => 'core.row', 'props' => ['common' => ['style' => [
                'background_image' => '/cover.jpg', 'background_image_tint' => '#00000080',
            ]]], 'columns' => [['id' => 'col_1', 'props' => [], 'items' => []]],
        ]]], JSON_THROW_ON_ERROR)));

        $css = PageCommonStyles::compile($document, 42);
        self::assertStringContainsString('[data-page-node="row_1"]::before{', $css);
        self::assertStringContainsString('opacity:1', $css);
        self::assertStringContainsString('[data-page-node="row_1"]::after{', $css);
        self::assertStringContainsString('background-color:#00000080', $css);
    }

    public function testRejectsInvalidTintColor(): void
    {
        $this->expectException(ExceptionResponse::class);
        PageCommonStyles::validate((object) ['common' => (object) ['style' => (object) [
            'background_image_tint' => '#FFFFFF;display:none',
        ]]]);
    }

    public function testCompilesCommonTextAlignmentAndRejectsInvalidValue(): void
    {
        $document = PageFixtures::normalize(PageFixtures::decode(json_encode(['items' => [[
            'id' => 'row_1', 'type' => 'core.row', 'props' => ['common' => ['style' => ['text_align' => 'center']]],
            'columns' => [['id' => 'col_1', 'props' => ['common' => ['style' => ['text_align' => 'right']]], 'items' => []]],
        ]]], JSON_THROW_ON_ERROR)));
        $css = PageCommonStyles::compile($document, 42);
        self::assertStringContainsString('[data-page-node="row_1"]{text-align:center}', $css);
        self::assertStringContainsString('[data-page-node="col_1"]{text-align:right}', $css);
        self::assertSame('', PageCommonStyles::compile(PageFixtures::normalize(PageFixtures::decode(json_encode(['items' => [[
            'id' => 'row_2', 'type' => 'core.row', 'columns' => [['id' => 'col_2', 'items' => []]],
        ]]], JSON_THROW_ON_ERROR))), 42));

        $this->expectException(ExceptionResponse::class);
        PageCommonStyles::validate((object) ['common' => (object) ['style' => (object) ['text_align' => 'center;display:none']]]);
    }
}
