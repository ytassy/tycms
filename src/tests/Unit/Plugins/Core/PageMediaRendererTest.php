<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use YTassy\Tycms\Tests\Support\PageFixtures;

use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Helper\ShortCode\ShortCodeHelper;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class PageMediaRendererTest extends FrameworkTestCase
{
    public function testBothComponentsUseThePublicRendererAndHiddenBunnyDoesNotGenerateAToken(): void
    {
        $app = $this->createApplication();
        $shortcodes = $this->createMock(ShortCodeHelper::class);
        $shortcodes->expects(self::once())->method('configuration')->with(7)
            ->willReturn((object) ['mermaid_default_theme' => 'forest']);
        $shortcodes->expects(self::never())->method('bunnyUrl');
        (new ReflectionProperty(Application::class, 'sharedExtensionsClasses'))->setValue($app, [
            ShortCodeHelper::class => $shortcodes,
        ]);
        PageFixtures::initialize($app);
        $page = new Page($app);
        $page->id = 12;
        $page->space_id = 7;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = PageFixtures::normalize(PageFixtures::decode('{"items":[{"type":"core.row","columns":[{"items":[
            {"type":"core.video","props":{"source":"url","src":"https://youtu.be/Abc_def-123?autoplay=1"}},
            {"type":"core.mermaid","props":{"diagram":"flowchart LR A-->B","options":{"theme":"dark"}}},
            {"type":"core.video","props":{"source":"bunny","library_id":"123","video_id":"abc-def","common":{"visible":false}}}
        ]}]}]}', false, 512, JSON_THROW_ON_ERROR));
        $before = serialize($page->data);

        $html = (new PageRenderer($app))->render($page, 'fr')->getHtml();

        self::assertStringContainsString('src="https://www.youtube.com/embed/Abc_def-123"', $html);
        self::assertStringContainsString('data-shortcode-diagram="1"', $html);
        self::assertStringContainsString('data-mermaid-theme="dark"', $html);
        self::assertStringNotContainsString('mediadelivery.net', $html);
        self::assertSame($before, serialize($page->data));
    }
}
