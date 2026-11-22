<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use YTassy\Tycms\Tests\Support\PageFixtures;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Helper\ShortCode\ShortCodeHelper;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class ShortCodePreviewTest extends FrameworkTestCase
{
    public static function pageTypes(): iterable
    {
        yield 'HTML direct' => [1];
        yield 'composants' => [2];
    }

    #[DataProvider('pageTypes')]
    public function testPublicAndFaithfulRendererStillConvertsShortcodesInThePageSpace(int $type): void
    {
        $app = $this->createApplication();
        $source = '<p>[[USER_name]]</p>';
        $page = new Page($app);
        $page->id = 12;
        $page->space_id = 7;
        $page->type = $type;
        $page->raw_html = $source;
        $page->raw_css = null;
        $page->data = PageFixtures::normalize(PageFixtures::decode('{"items":[{"type":"core.row","columns":[{"items":[{"type":"core.html","props":{"content":"<p>[[USER_name]]</p>"}}]}]}]}', false, 512, JSON_THROW_ON_ERROR));

        // Le rendu fidèle et le site public passent tous deux par ce renderer.
        $shortcodes = $this->createMock(ShortCodeHelper::class);
        $shortcodes->expects(self::once())->method('applyShortCodes')
            ->with($source, 7)->willReturn('<p>Ada</p>');
        PageFixtures::initialize($app, [ShortCodeHelper::class => $shortcodes]);
        $renderer = new PageRenderer($app);
        $html = $renderer->render($page, 'fr')->getHtml();

        self::assertStringContainsString('<p>Ada</p>', $html);
        self::assertStringNotContainsString('[[USER_name]]', $html);
        self::assertSame($source, $page->raw_html);
        self::assertSame($source, $page->data->items[0]->zones->columns[0]->items[0]->props->content);
    }
}
