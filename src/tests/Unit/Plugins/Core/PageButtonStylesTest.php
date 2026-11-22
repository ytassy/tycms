<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageButtonStyles;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\Response\ExceptionResponse;

final class PageButtonStylesTest extends TestCase
{
    public function testCompilesScopedBootstrapOverridesForNestedButton(): void
    {
        $document = PageFixtures::normalize(PageFixtures::decode(json_encode(['items' => [[
            'id' => 'row_1', 'type' => 'core.row', 'columns' => [[
                'id' => 'col_1', 'items' => [[
                    'id' => 'button_1', 'type' => 'core.button', 'props' => [
                        'variant' => 'outline-success', 'size' => 'lg',
                        'button_style' => [
                            'color' => '#123456', 'hover_background_color' => '#ABCDEF80',
                            'border_width' => '2px', 'border_radius' => '25%',
                        ],
                    ],
                ]],
            ]],
        ]]], JSON_THROW_ON_ERROR)));

        $button = $document->items[0]->zones->columns[0]->items[0];
        self::assertSame('btn btn-outline-success btn-lg', PageButtonStyles::classes($button->props));
        self::assertSame('btn btn-primary', PageButtonStyles::classes((object) []));
        self::assertSame('#page-42 [data-page-node="button_1"] .btn{--bs-btn-color:#123456;'
            . '--bs-btn-hover-bg:#ABCDEF80;--bs-btn-border-width:2px;--bs-btn-border-radius:25%}',
            PageButtonStyles::compile($document, 42));

        $renderer = new PageRenderer(PageFixtures::application());
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 42; $page->space_id = 1; $page->type = 2; $page->raw_css = null;
        $button->props->href = '/contact';
        $button->props->text = 'Voir';
        $page->data = $document;
        $html = $renderer->render($page, 'fr')->getHtml();
        self::assertStringContainsString('<a class="btn btn-outline-success btn-lg" href="/contact">Voir</a>', $html);
    }

    public function testRejectsUnsupportedVariantAndUnsafeStyle(): void
    {
        $rejected = 0;
        foreach ([(object) ['variant' => 'primary other'], (object) ['size' => 'xl'],
            (object) ['button_style' => (object) ['hover_color' => '#fff;display:none']],
            (object) ['button_style' => (object) ['unexpected' => 'value']]] as $props) {
            try {
                PageButtonStyles::validate($props);
                self::fail('Invalid button option was accepted.');
            } catch (ExceptionResponse) {
                $rejected++;
            }
        }
        self::assertSame(4, $rejected);
    }
}
